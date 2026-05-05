<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Test;
use Skom\Lscache\Http\DbalPageContentInventoryProvider;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class DbalPageContentInventoryProviderTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCsvDataSet(__DIR__ . '/../Configuration/Fixtures/pages.csv');
        $this->seedPagesAndContent();
    }

    #[Test]
    public function collectsVisibleInventoryFromTheResolvedPage(): void
    {
        $provider = $this->get(DbalPageContentInventoryProvider::class);

        $inventory = $provider->getForRequest(
            $this->createRequestForPage(10),
        );

        self::assertSame([], $inventory->pluginSignatures);
        self::assertSame(['textmedia'], $inventory->ctypes);
    }

    #[Test]
    public function resolvesInventoryFromContentFromPid(): void
    {
        $provider = $this->get(DbalPageContentInventoryProvider::class);

        $inventory = $provider->getForRequest(
            $this->createRequestForPage(11),
        );

        self::assertSame([], $inventory->pluginSignatures);
        self::assertSame(['list'], $inventory->ctypes);
    }

    private function createRequestForPage(int $pageId): ServerRequest
    {
        return (new ServerRequest(new Uri('https://example.test/page-' . $pageId), 'GET'))
            ->withAttribute('routing', new PageArguments($pageId, '0', []));
    }

    private function seedPagesAndContent(): void
    {
        $pagesConnection = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        $pagesConnection->insert('pages', [
            'uid' => 10,
            'pid' => 1,
            'title' => 'Visible inventory page',
            'slug' => '/visible-inventory',
            'doktype' => 1,
            'perms_everybody' => 15,
            'deleted' => 0,
            'hidden' => 0,
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            'content_from_pid' => 0,
        ]);
        $pagesConnection->insert('pages', [
            'uid' => 11,
            'pid' => 1,
            'title' => 'Mount page',
            'slug' => '/mount-page',
            'doktype' => 1,
            'perms_everybody' => 15,
            'deleted' => 0,
            'hidden' => 0,
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            'content_from_pid' => 12,
        ]);
        $pagesConnection->insert('pages', [
            'uid' => 12,
            'pid' => 1,
            'title' => 'Content source page',
            'slug' => '/content-source',
            'doktype' => 1,
            'perms_everybody' => 15,
            'deleted' => 0,
            'hidden' => 0,
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            'content_from_pid' => 0,
        ]);

        $contentConnection = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content');
        $contentConnection->insert('tt_content', [
            'uid' => 100,
            'pid' => 10,
            'CType' => 'textmedia',
            'colPos' => 0,
            'header' => 'Visible content',
            'deleted' => 0,
            'hidden' => 0,
            'sys_language_uid' => 0,
            'tstamp' => 0,
            'crdate' => 0,
        ]);
        $contentConnection->insert('tt_content', [
            'uid' => 101,
            'pid' => 10,
            'CType' => 'menu_pages',
            'colPos' => 0,
            'header' => 'Hidden content',
            'deleted' => 0,
            'hidden' => 1,
            'sys_language_uid' => 0,
            'tstamp' => 0,
            'crdate' => 0,
        ]);
        $contentConnection->insert('tt_content', [
            'uid' => 102,
            'pid' => 12,
            'CType' => 'list',
            'colPos' => 0,
            'header' => 'Mounted content',
            'deleted' => 0,
            'hidden' => 0,
            'sys_language_uid' => 0,
            'tstamp' => 0,
            'crdate' => 0,
        ]);
    }
}
