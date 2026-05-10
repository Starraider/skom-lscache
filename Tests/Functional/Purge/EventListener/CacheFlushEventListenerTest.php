<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Purge\EventListener;

use PHPUnit\Framework\Attributes\Test;
use Skom\Lscache\Purge\Audit\PurgeLogRepository;
use Skom\Lscache\Purge\EventListener\CacheFlushEventListener;
use Skom\Lscache\Purge\LoopbackDispatcher;
use Skom\Lscache\Purge\PurgeServiceInterface;
use TYPO3\CMS\Core\Cache\Event\CacheFlushEvent;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for AC 8: CacheFlushEventListener multi-site isolation
 * and opt-in guard behaviour.
 */
final class CacheFlushEventListenerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCsvDataSet(__DIR__ . '/../../Configuration/Fixtures/pages.csv');
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function subject(): CacheFlushEventListener
    {
        return new CacheFlushEventListener(
            $this->get(SiteFinder::class),
            $this->get(PurgeServiceInterface::class),
            $this->get(LoopbackDispatcher::class),
            $this->get(PurgeLogRepository::class),
        );
    }

    private function auditCount(string $siteIdentifier): int
    {
        return $this->get(PurgeLogRepository::class)->countBySite($siteIdentifier);
    }

    /**
     * Write a site config under the functional instance path.
     *
     * @param bool   $enabled       lscache.enabled
     * @param bool   $mirrorEnabled lscache.mirror.cacheFlushEvent
     * @param string $identifier    site folder name / identifier
     * @param int    $rootPageId    root page uid (must exist in pages.csv)
     */
    private function writeSiteConfig(
        string $identifier,
        bool $enabled,
        bool $mirrorEnabled,
        int $rootPageId = 1,
        string $base = 'https://example.test/',
        string $secret = 'test-secret',
    ): void {
        $sitePath = $this->instancePath . '/typo3conf/sites/' . $identifier;
        if (!is_dir($sitePath)) {
            mkdir($sitePath, 0777, true);
        }

        $enabledStr = $enabled ? 'true' : 'false';
        $mirrorStr = $mirrorEnabled ? 'true' : 'false';
        $secretLine = $secret !== '' ? "      secret: '{$secret}'" : '';

        file_put_contents(
            $sitePath . '/config.yaml',
            <<<YAML
base: '{$base}'
dependencies:
  - skom/lscache/default
languages:
  -
    title: Deutsch
    enabled: true
    languageId: 0
    base: /
    locale: de_DE.utf8
    flag: de
    hreflang: de-DE
    websiteTitle: ''
rootPageId: {$rootPageId}
settings:
  lscache:
    defaultTtl: 300
    enabled: {$enabledStr}
    mirror:
      cacheFlushEvent: {$mirrorStr}
    loopback:
      rateLimit: 120
{$secretLine}
websiteTitle: ''
YAML,
        );
    }

    private function refreshSiteFinder(): void
    {
        $this->get(SiteFinder::class)->siteConfigurationChanged();
    }

    // ─── AC 8: happy path — siteA enabled, siteB disabled ────────────────────

    #[Test]
    public function mirrorEnabledSiteGetsAuditRowWhileDisabledSiteDoesNot(): void
    {
        $this->writeSiteConfig('siteA', true, true);
        $this->writeSiteConfig('siteB', false, true);
        $this->refreshSiteFinder();

        $countABefore = $this->auditCount('siteA');
        $countBBefore = $this->auditCount('siteB');

        // CacheFlushEvent with ['pages'] → siteA should be purged
        ($this->subject())(new CacheFlushEvent(['pages']));

        // siteA: exactly one audit row added
        self::assertSame($countABefore + 1, $this->auditCount('siteA'), 'Site A should gain one audit row');
        // siteB: no change (lscache.enabled = false)
        self::assertSame($countBBefore, $this->auditCount('siteB'), 'Site B must not gain an audit row');
    }

    // ─── AC 1: mirror off → zero side effects ────────────────────────────────

    #[Test]
    public function mirrorDisabledLeavesAuditLogUnchanged(): void
    {
        $this->writeSiteConfig('siteA', true, false); // mirror OFF
        $this->refreshSiteFinder();

        $countBefore = $this->auditCount('siteA');

        ($this->subject())(new CacheFlushEvent(['pages']));

        self::assertSame($countBefore, $this->auditCount('siteA'));
    }

    // ─── AC 5: unspecified groups (empty list) → no purge ────────────────────

    #[Test]
    public function emptyGroupsLeavesAuditLogUnchangedEvenWhenMirrorIsOn(): void
    {
        $this->writeSiteConfig('siteA', true, true);
        $this->refreshSiteFinder();

        $countBefore = $this->auditCount('siteA');

        ($this->subject())(new CacheFlushEvent([])); // explicit empty groups (no 'pages')

        self::assertSame($countBefore, $this->auditCount('siteA'));
    }

    // ─── AC 5: system-only group → no purge ──────────────────────────────────

    #[Test]
    public function systemOnlyGroupLeavesAuditLogUnchanged(): void
    {
        $this->writeSiteConfig('siteA', true, true);
        $this->refreshSiteFinder();

        $countBefore = $this->auditCount('siteA');

        ($this->subject())(new CacheFlushEvent(['system']));

        self::assertSame($countBefore, $this->auditCount('siteA'));
    }

    // ─── AC 6: trigger_ref format ────────────────────────────────────────────

    #[Test]
    public function auditRowCarriesCorrectTriggerRef(): void
    {
        $this->writeSiteConfig('siteA', true, true);
        $this->refreshSiteFinder();

        ($this->subject())(new CacheFlushEvent(['pages', 'system']));

        $requestId = $this->get(PurgeLogRepository::class)->findNewestRequestIdByTrigger(
            'siteA',
            \Skom\Lscache\Purge\Enum\PurgeSource::CACHE_FLUSH_EVENT,
            'cache-flush-event:pages,system',
        );

        self::assertNotNull($requestId, 'A request ID must exist for the trigger cache-flush-event:pages,system');
    }

    // ─── AC 4: missing secret → fail-fast via configuration validator ────────

    #[Test]
    public function missingSecretWithMirrorEnabledFailsFast(): void
    {
        $this->writeSiteConfig('siteA', true, true, 1, 'https://example.test/', ''); // secret = ''
        $this->refreshSiteFinder();

        $this->expectException(\Skom\Lscache\Configuration\InvalidSiteSettingException::class);
        ($this->subject())(new CacheFlushEvent(['pages']));
    }
}
