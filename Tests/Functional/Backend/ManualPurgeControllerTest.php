<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Backend;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\Test;
use Skom\Lscache\Backend\Controller\AuditLogController;
use Skom\Lscache\Backend\Controller\ManualPurgeController;
use Skom\Lscache\Purge\Audit\PurgeLogOutcome;
use Skom\Lscache\Purge\Audit\PurgeLogRepository;
use Skom\Lscache\Purge\Enum\PurgeSource;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;

require_once __DIR__ . '/AbstractBackendModuleTestCase.php';

final class ManualPurgeControllerTest extends AbstractBackendModuleTestCase
{
    #[Test]
    public function rendersAllManualPurgeScopesAndSiteScopedTagSuggestions(): void
    {
        $this->insertAuditRow('main', '11111111-1111-4111-8111-111111111111', time() - 60, PurgeLogOutcome::ENQUEUED, PurgeSource::MODULE, ['main_page_42', 'main_site']);
        $this->createBackendUser('de');

        /** @var ManualPurgeController $controller */
        $controller = $this->get(ManualPurgeController::class);
        $response = $controller->handleRequest($this->createBackendRequest(
            ['site' => 'main'],
            '/typo3/module/web/lscache/manual-purge',
            'web_SkomLscacheCache.manual-purge',
        ));

        $html = (string)$response->getBody();
        $xpath = $this->createXPath($html);

        self::assertStringContainsString('Einzelne Seite purgen', $html);
        self::assertStringContainsString('Tag-Set purgen', $html);
        self::assertStringContainsString('Gesamte Site purgen', $html);
        self::assertSame(1, (int)$xpath->evaluate('count(//*[@id="manual-purge-page-uid"])'));
        self::assertSame(1, (int)$xpath->evaluate('count(//*[@id="manual-purge-tags-input"])'));
        self::assertSame(1, (int)$xpath->evaluate('count(//*[@id="manual-purge-site-confirm"])'));
        self::assertSame(1, (int)$xpath->evaluate('count(//code[text()="main_page_42"])'));
    }

    #[Test]
    public function pagePurgeUsesExpectedTagSourceTriggerRefAndRequestIdAuditFilter(): void
    {
        $this->seedPage(42, '/manual-purge-page');
        $this->createBackendUser('de');

        $formProtection = $this->get(FormProtectionFactory::class)->createForType('backend');
        $formToken = $formProtection->generateToken('web_SkomLscacheCache.manual-purge', 'execute');

        /** @var ManualPurgeController $controller */
        $controller = $this->get(ManualPurgeController::class);
        $response = $controller->submitRequest(
            $this->createBackendRequest(
                [],
                '/typo3/module/web/lscache/manual-purge-execute',
                'web_SkomLscacheCache.manual-purge-execute',
                'POST',
            )->withParsedBody([
                '__RequestToken' => $formToken,
                'scope' => 'page',
                'site' => 'main',
                'pageUid' => '42',
            ]),
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('manual-purge', $response->getHeaderLine('Location'));
        self::assertStringContainsString('site=main', $response->getHeaderLine('Location'));

        /** @var PurgeLogRepository $repository */
        $repository = $this->get(PurgeLogRepository::class);
        $rows = $repository->findRecentBySite('main', 1, 0);

        self::assertCount(1, $rows);
        self::assertSame(PurgeSource::MODULE, $rows[0]->source);
        self::assertSame(['main_page_42'], $rows[0]->tags);
        self::assertSame('module:manual-purge:page:42:be_user:editor', $rows[0]->triggerRef);
        self::assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $rows[0]->requestId);

        /** @var AuditLogController $auditLogController */
        $auditLogController = $this->get(AuditLogController::class);
        $auditResponse = $auditLogController->handleRequest($this->createBackendRequest(
            [
                'site' => ['main'],
                'requestId' => $rows[0]->requestId,
                'datePreset' => 'last7d',
            ],
            '/typo3/module/web/lscache/audit-log',
            'web_SkomLscacheCache.audit-log',
        ));

        $auditHtml = (string)$auditResponse->getBody();
        self::assertStringContainsString('data-audit-row="' . $rows[0]->requestId . '"', $auditHtml);
    }

    #[Test]
    public function fullSitePurgeRequiresConfirmationAndQueuesWhenConfirmed(): void
    {
        $this->createBackendUser('de');

        /** @var ManualPurgeController $controller */
        $controller = $this->get(ManualPurgeController::class);
        /** @var PurgeLogRepository $repository */
        $repository = $this->get(PurgeLogRepository::class);

        $formProtection = $this->get(FormProtectionFactory::class)->createForType('backend');
        $formTokenWithoutConfirmation = $formProtection->generateToken('web_SkomLscacheCache.manual-purge', 'execute');

        $missingConfirmationResponse = $controller->submitRequest(
            $this->createBackendRequest(
                [],
                '/typo3/module/web/lscache/manual-purge-execute',
                'web_SkomLscacheCache.manual-purge-execute',
                'POST',
            )->withParsedBody([
                '__RequestToken' => $formTokenWithoutConfirmation,
                'scope' => 'site',
                'site' => 'main',
            ]),
        );

        self::assertSame(400, $missingConfirmationResponse->getStatusCode());
        self::assertSame(0, $repository->countBySite('main'));

        $formProtection = $this->get(FormProtectionFactory::class)->createForType('backend');
        $formTokenWithConfirmation = $formProtection->generateToken('web_SkomLscacheCache.manual-purge', 'execute');

        $confirmedResponse = $controller->submitRequest(
            $this->createBackendRequest(
                [],
                '/typo3/module/web/lscache/manual-purge-execute',
                'web_SkomLscacheCache.manual-purge-execute',
                'POST',
            )->withParsedBody([
                '__RequestToken' => $formTokenWithConfirmation,
                'scope' => 'site',
                'site' => 'main',
                'confirmSitePurge' => '1',
            ]),
        );

        self::assertSame(303, $confirmedResponse->getStatusCode());

        $rows = $repository->findRecentBySite('main', 1, 0);
        self::assertCount(1, $rows);
        self::assertSame(PurgeSource::MODULE, $rows[0]->source);
        self::assertSame([], $rows[0]->tags);
        self::assertSame('module:manual-purge:site:be_user:editor', $rows[0]->triggerRef);
    }

    #[Test]
    public function forgedCrossSiteTagsAreRejectedWithHttp400AndNoAuditRow(): void
    {
        $this->createBackendUser('de');

        $formProtection = $this->get(FormProtectionFactory::class)->createForType('backend');
        $formToken = $formProtection->generateToken('web_SkomLscacheCache.manual-purge', 'execute');

        /** @var ManualPurgeController $controller */
        $controller = $this->get(ManualPurgeController::class);
        $response = $controller->submitRequest(
            $this->createBackendRequest(
                [],
                '/typo3/module/web/lscache/manual-purge-execute',
                'web_SkomLscacheCache.manual-purge-execute',
                'POST',
            )->withParsedBody([
                '__RequestToken' => $formToken,
                'scope' => 'tags',
                'site' => 'main',
                'tags' => "secondary_page_1\nsecondary_site",
            ]),
        );

        self::assertSame(400, $response->getStatusCode());

        /** @var PurgeLogRepository $repository */
        $repository = $this->get(PurgeLogRepository::class);
        self::assertSame(0, $repository->countBySite('main'));
    }

    private function seedPage(int $pageUid, string $slug): void
    {
        $now = time();
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', [
            'uid' => $pageUid,
            'pid' => 1,
            'title' => 'Manual Purge Page ' . $pageUid,
            'slug' => $slug,
            'doktype' => 1,
            'perms_everybody' => 15,
            'deleted' => 0,
            'hidden' => 0,
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            'content_from_pid' => 0,
            'tstamp' => $now,
            'crdate' => $now,
        ]);
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
