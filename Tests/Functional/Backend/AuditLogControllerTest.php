<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Backend;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\Test;
use Skom\Lscache\Backend\Controller\AuditLogController;
use Skom\Lscache\Purge\Audit\PurgeLogOutcome;
use Skom\Lscache\Purge\Enum\PurgeSource;
use TYPO3\CMS\Core\Database\ConnectionPool;

require_once __DIR__ . '/AbstractBackendModuleTestCase.php';

final class AuditLogControllerTest extends AbstractBackendModuleTestCase
{
    #[Test]
    public function appliesCombinedFiltersAndPaginatesRows(): void
    {
        $this->seedRowsForFilterAndPagination();
        $this->createBackendUser('de');

        /** @var AuditLogController $controller */
        $controller = $this->get(AuditLogController::class);
        $response = $controller->handleRequest($this->createBackendRequest(
            [
                'site' => ['main'],
                'source' => [PurgeSource::DATAHANDLER->value],
                'outcome' => [PurgeLogOutcome::FLUSHED->value],
                'datePreset' => 'last7d',
                'page' => 1,
                'perPage' => 25,
            ],
            '/typo3/module/web/lscache/audit-log',
            'web_SkomLscacheCache.audit-log',
        ));
        $html = (string)$response->getBody();
        $xpath = $this->createXPath($html);

        self::assertSame(25, (int)$xpath->evaluate('count(//*[@data-audit-row])'));
        self::assertSame('main-match-001', trim((string)$xpath->evaluate('string((//*[@data-audit-row])[1]/@data-audit-row)')));
        self::assertSame('main-match-025', trim((string)$xpath->evaluate('string((//*[@data-audit-row])[25]/@data-audit-row)')));
        self::assertSame('2', trim((string)$xpath->evaluate('string(//*[@data-audit-total-pages]/@data-audit-total-pages)')));
        self::assertSame(1, (int)$xpath->evaluate('count(//*[@data-audit-pagination-next])'));
        self::assertSame(0, (int)$xpath->evaluate('count(//*[@data-audit-pagination-prev])'));
        self::assertSame(1, (int)$xpath->evaluate('count(//*[@data-audit-per-page]//option[@value="25" and @selected])'));
        self::assertSame(1, (int)$xpath->evaluate('count(//*[@data-audit-filter-site]//option[@value="main" and @selected])'));
    }

    #[Test]
    public function rendersEmptyStateAndResetActionWhenNoRowsMatch(): void
    {
        $this->seedRowsForFilterAndPagination();
        $this->createBackendUser('de');

        /** @var AuditLogController $controller */
        $controller = $this->get(AuditLogController::class);
        $response = $controller->handleRequest($this->createBackendRequest(
            [
                'site' => ['secondary'],
                'source' => [PurgeSource::CLI->value],
                'outcome' => [PurgeLogOutcome::FAILED->value],
                'datePreset' => 'last24h',
            ],
            '/typo3/module/web/lscache/audit-log',
            'web_SkomLscacheCache.audit-log',
        ));
        $html = (string)$response->getBody();

        self::assertStringContainsString('Keine Purges entsprechen diesen Filtern.', $html);
        self::assertStringContainsString('data-audit-reset-link', $html);
    }

    #[Test]
    public function filtersUnknownSiteBucketForLegacyEmptySiteIdentifiers(): void
    {
        $this->seedRowsForFilterAndPagination();
        $this->createBackendUser('de');

        /** @var AuditLogController $controller */
        $controller = $this->get(AuditLogController::class);
        $response = $controller->handleRequest($this->createBackendRequest(
            [
                'site' => ['unknown'],
                'datePreset' => 'last7d',
            ],
            '/typo3/module/web/lscache/audit-log',
            'web_SkomLscacheCache.audit-log',
        ));
        $html = (string)$response->getBody();
        $xpath = $this->createXPath($html);

        self::assertSame(1, (int)$xpath->evaluate('count(//*[@data-audit-row])'));
        self::assertSame('unknown-legacy-empty-site', trim((string)$xpath->evaluate('string((//*[@data-audit-row])[1]/@data-audit-row)')));
        self::assertSame(1, (int)$xpath->evaluate('count(//*[@data-audit-filter-site]//option[@value="unknown" and @selected])'));
    }

    #[Test]
    public function malformedFiltersFailClosed(): void
    {
        $this->seedRowsForFilterAndPagination();
        $this->createBackendUser('de');

        /** @var AuditLogController $controller */
        $controller = $this->get(AuditLogController::class);
        $response = $controller->handleRequest($this->createBackendRequest(
            [
                'source' => ['bogus'],
                'outcome' => [PurgeLogOutcome::FLUSHED->value],
                'datePreset' => 'custom',
                'customFrom' => 'not-a-date',
                'customTo' => 'also-not-a-date',
            ],
            '/typo3/module/web/lscache/audit-log',
            'web_SkomLscacheCache.audit-log',
        ));
        $html = (string)$response->getBody();
        $xpath = $this->createXPath($html);

        self::assertSame(0, (int)$xpath->evaluate('count(//*[@data-audit-row])'));
        self::assertStringContainsString('Keine Purges entsprechen diesen Filtern.', $html);
    }

    #[Test]
    public function exportsCsvWithTenThousandRowCap(): void
    {
        $this->seedRowsForCsvExport();
        $this->createBackendUser('de');

        /** @var AuditLogController $controller */
        $controller = $this->get(AuditLogController::class);
        $response = $controller->exportCsv($this->createBackendRequest(
            [
                'site' => ['main'],
                'source' => [PurgeSource::DATAHANDLER->value],
                'outcome' => [PurgeLogOutcome::FLUSHED->value],
                'datePreset' => 'last7d',
            ],
            '/typo3/module/web/lscache/audit-log/export',
            'web_SkomLscacheCache.audit-log-export',
        ));

        self::assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment;', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('1', $response->getHeaderLine('X-Skom-Lscache-Truncated'));

        $lines = array_values(array_filter(explode("\n", trim((string)$response->getBody()))));
        self::assertCount(10002, $lines);
        self::assertStringContainsString('WARNING', $lines[0]);
        self::assertStringContainsString('10000', $lines[0]);
        self::assertStringContainsString('request_id', $lines[1]);
        self::assertStringContainsString('main-export-00001', $lines[2]);
        self::assertStringContainsString('main-export-10000', $lines[10001]);
    }

    private function seedRowsForFilterAndPagination(): void
    {
        $now = time();

        for ($index = 1; $index <= 40; $index++) {
            $this->insertAuditRow(
                'main',
                sprintf('main-match-%03d', $index),
                $now - $index,
                PurgeLogOutcome::FLUSHED,
                PurgeSource::DATAHANDLER,
                ['tag_main_' . $index, 'tag_extra_' . $index],
                ['https://example.test/main/' . $index],
                'pages:' . $index,
            );
        }

        $this->insertAuditRow('main', 'main-non-match-source', $now - 50, PurgeLogOutcome::FLUSHED, PurgeSource::API, ['api_tag'], [], 'pages:50');
        $this->insertAuditRow('main', 'main-non-match-outcome', $now - 60, PurgeLogOutcome::FAILED, PurgeSource::DATAHANDLER, ['failed_tag'], [], 'pages:60');
        $this->insertAuditRow('secondary', 'secondary-non-match-site', $now - 70, PurgeLogOutcome::FLUSHED, PurgeSource::DATAHANDLER, ['secondary_tag'], [], 'pages:70');
        $this->insertAuditRow('main', 'main-too-old', $now - (9 * 24 * 60 * 60), PurgeLogOutcome::FLUSHED, PurgeSource::DATAHANDLER, ['old_tag'], [], 'pages:80');
        $this->insertAuditRow('', 'unknown-legacy-empty-site', $now - 90, PurgeLogOutcome::SKIPPED, PurgeSource::MODULE, ['unknown_tag'], [], null);

        for ($index = 1; $index <= 60; $index++) {
            $this->insertAuditRow(
                $index % 2 === 0 ? 'secondary' : 'main',
                sprintf('mixed-non-match-%03d', $index),
                $now - 100 - $index,
                $index % 3 === 0 ? PurgeLogOutcome::SKIPPED : PurgeLogOutcome::ENQUEUED,
                $index % 2 === 0 ? PurgeSource::CLI : PurgeSource::API,
                ['mixed_tag_' . $index],
                ['https://example.test/mixed/' . $index],
                'pages:' . (1000 + $index),
            );
        }
    }

    private function seedRowsForCsvExport(): void
    {
        $now = time();
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('tx_skomlscache_purge_log');

        $connection->beginTransaction();
        try {
            for ($index = 1; $index <= 10005; $index++) {
                $connection->insert('tx_skomlscache_purge_log', [
                    'pid' => 0,
                    'crdate' => $now - $index,
                    'site_identifier' => 'main',
                    'source' => PurgeSource::DATAHANDLER->value,
                    'trigger_ref' => 'pages:' . $index,
                    'tags' => json_encode(['tag_main_' . $index], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'urls' => json_encode(['https://example.test/export/' . $index], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'tag_count' => 1,
                    'outcome' => PurgeLogOutcome::FLUSHED->value,
                    'latency_ms' => 0,
                    'request_id' => sprintf('main-export-%05d', $index),
                ]);
            }

            $connection->insert('tx_skomlscache_purge_log', [
                'pid' => 0,
                'crdate' => $now - 1,
                'site_identifier' => 'secondary',
                'source' => PurgeSource::DATAHANDLER->value,
                'trigger_ref' => 'pages:90000',
                'tags' => json_encode(['secondary_tag'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'urls' => json_encode([], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'tag_count' => 1,
                'outcome' => PurgeLogOutcome::FLUSHED->value,
                'latency_ms' => 0,
                'request_id' => 'secondary-export-non-match',
            ]);

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
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
