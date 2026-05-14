<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Backend;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\Test;
use Skom\Lscache\Backend\Controller\DashboardController;
use Skom\Lscache\Purge\Audit\PurgeLogOutcome;
use Skom\Lscache\Purge\Enum\PurgeSource;
use TYPO3\CMS\Core\Site\SiteFinder;

require_once __DIR__ . '/AbstractBackendModuleTestCase.php';

final class DashboardControllerTest extends AbstractBackendModuleTestCase
{
    #[Test]
    public function rendersLocalizedAggregatesBreakdownRecentRowsAndProbeForm(): void
    {
        $this->seedDashboardRows();
        $this->createBackendUser('de');

        /** @var DashboardController $controller */
        $controller = $this->get(DashboardController::class);
        $response = $controller->handleRequest($this->createBackendRequest(['site' => 'main']));
        $html = (string)$response->getBody();

        self::assertStringContainsString('Site-Auswahl', $html);
        self::assertStringContainsString('Cache-Hit-Prüfung', $html);
        self::assertStringContainsString('Cache-Status prüfen', $html);
        self::assertStringContainsString('value="https://example.test/"', $html);

        $xpath = $this->createXPath($html);

        self::assertSame('1', trim((string)$xpath->evaluate("string(//*[@data-summary-card-value='last24h'])")));
        self::assertSame('2', trim((string)$xpath->evaluate("string(//*[@data-summary-card-value='last7d'])")));
        self::assertSame('5', trim((string)$xpath->evaluate("string(//*[@data-summary-card-value='last30d'])")));

        self::assertMatchesRegularExpression(
            '/data-site-breakdown-row="main"[^>]*class="[^"]*table-primary[^"]*"[^>]*>.*?<td class="text-end fw-semibold">\s*6\s*<\/td>/s',
            $html,
        );
        self::assertMatchesRegularExpression(
            '/data-site-breakdown-row="secondary"[^>]*>.*?<td class="text-end fw-semibold">\s*1\s*<\/td>/s',
            $html,
        );
        self::assertMatchesRegularExpression(
            '/data-site-breakdown-row="unknown"[^>]*>.*?<td class="text-end fw-semibold">\s*1\s*<\/td>/s',
            $html,
        );

        self::assertSame(5, (int)$xpath->evaluate("count(//*[@data-recent-row])"));
        self::assertSame(0, (int)$xpath->evaluate("count(//*[@data-recent-row='request-6'])"));
        self::assertSame(
            1,
            (int)$xpath->evaluate('count(//button[contains(@class, "dropdown-toggle") and contains(., "Main Site")])'),
        );
        self::assertSame(
            1,
            (int)$xpath->evaluate(
                'count(//a[contains(@class, "dropdown-item") and contains(@href, "site=secondary") and contains(., "Secondary Site")])',
            ),
        );

        self::assertStringContainsString('Gepusht', $html);
        self::assertStringContainsString('Backend-Modul', $html);
        self::assertStringContainsString('Unbekannt', $html);
    }

    #[Test]
    public function rendersDocHeaderSiteSelectorWithOnlyOneConfiguredSite(): void
    {
        $secondarySiteConfiguration = $this->instancePath . '/typo3conf/sites/secondary/config.yaml';
        if (is_file($secondarySiteConfiguration)) {
            unlink($secondarySiteConfiguration);
        }
        $this->get(SiteFinder::class)->siteConfigurationChanged();
        $this->createBackendUser('de');

        /** @var DashboardController $controller */
        $controller = $this->get(DashboardController::class);
        $response = $controller->handleRequest($this->createBackendRequest(['site' => 'main']));
        $xpath = $this->createXPath((string)$response->getBody());

        self::assertSame(
            1,
            (int)$xpath->evaluate('count(//button[contains(@class, "dropdown-toggle") and contains(., "Main Site")])'),
        );
        self::assertSame(
            1,
            (int)$xpath->evaluate(
                'count(//a[contains(@class, "dropdown-item") and contains(@href, "site=main") and contains(., "Main Site")])',
            ),
        );
    }

    private function seedDashboardRows(): void
    {
        $now = time();

        $this->insertAuditRow('main', 'request-1', $now - 3600, PurgeLogOutcome::FLUSHED, PurgeSource::API, ['main_page_1'], ['https://example.test/1'], 'trigger-1');
        $this->insertAuditRow('main', 'request-2', $now - (2 * 24 * 60 * 60), PurgeLogOutcome::TRUNCATED, PurgeSource::MODULE, ['main_page_2'], ['https://example.test/2'], 'trigger-2');
        $this->insertAuditRow('main', 'request-3', $now - (8 * 24 * 60 * 60), PurgeLogOutcome::ENQUEUED, PurgeSource::API, ['main_page_3'], [], 'trigger-3');
        $this->insertAuditRow('main', 'request-4', $now - (20 * 24 * 60 * 60), PurgeLogOutcome::FAILED, PurgeSource::MODULE, ['main_page_4'], [], 'trigger-4');
        $this->insertAuditRow('main', 'request-5', $now - (25 * 24 * 60 * 60), PurgeLogOutcome::SKIPPED, PurgeSource::API, ['main_page_5'], [], 'trigger-5');
        $this->insertAuditRow('main', 'request-6', $now - (40 * 24 * 60 * 60), PurgeLogOutcome::ENQUEUED, PurgeSource::API, ['main_page_6'], [], 'trigger-6');
        $this->insertAuditRow('secondary', 'request-secondary', $now - (3 * 60 * 60), PurgeLogOutcome::FLUSHED, PurgeSource::MODULE, ['secondary_page_1'], [], 'trigger-secondary');
        $this->insertAuditRow('unknown', 'request-unknown', $now - (4 * 60 * 60), PurgeLogOutcome::FAILED, PurgeSource::API, ['unknown_page_1'], [], 'trigger-unknown');
    }

    private function createXPath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
