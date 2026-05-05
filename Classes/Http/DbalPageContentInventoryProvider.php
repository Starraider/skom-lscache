<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

use Psr\Http\Message\ServerRequestInterface;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Routing\PageArguments;

/**
 * Loads page content metadata from TYPO3 database tables for cacheability checks.
 */
final readonly class DbalPageContentInventoryProvider implements PageContentInventoryProvider
{
    /**
     * @param ConnectionPool $connectionPool TYPO3 database connection factory.
     * @param Context $context TYPO3 context used by frontend visibility restrictions.
     */
    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
    ) {}

    /**
     * Resolves visible page content inventory for the page represented by the request.
     *
     * @param ServerRequestInterface $request Current PSR-7 request with a `routing` PageArguments attribute.
     */
    public function getForRequest(ServerRequestInterface $request): PageContentInventory
    {
        $routing = $request->getAttribute('routing');
        if (!$routing instanceof PageArguments) {
            throw MissingRequestContext::forAttribute('routing');
        }

        $pid = $this->resolveContentPid($routing->getPageId());
        if ($pid <= 0) {
            return PageContentInventory::empty();
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->getRestrictions()->add(new FrontendRestrictionContainer($this->context));

        $selectFields = ['CType'];
        if ($this->tableHasColumn('tt_content', 'list_type')) {
            $selectFields[] = 'list_type';
        }

        $rows = $queryBuilder
            ->select(...$selectFields)
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $ctypes = [];
        $pluginSignatures = [];
        foreach ($rows as $row) {
            $ctype = trim((string)($row['CType'] ?? ''));
            if ($ctype !== '') {
                $ctypes[] = $ctype;
            }
            $listType = trim((string)($row['list_type'] ?? ''));
            if ($listType !== '') {
                $pluginSignatures[] = $listType;
            }
        }

        return new PageContentInventory(
            array_values(array_unique($pluginSignatures)),
            array_values(array_unique($ctypes)),
        );
    }

    /**
     * Checks whether a database table exposes a column in the current TYPO3 schema.
     *
     * @param string $tableName Database table name to inspect.
     * @param string $columnName Column name to look up.
     */
    private function tableHasColumn(string $tableName, string $columnName): bool
    {
        /** @var array<string, array<string, bool>> $columnPresenceCache */
        static $columnPresenceCache = [];
        if (isset($columnPresenceCache[$tableName][$columnName])) {
            return $columnPresenceCache[$tableName][$columnName];
        }

        $connection = $this->connectionPool->getConnectionForTable($tableName);
        $columns = $connection->createSchemaManager()->listTableColumns($tableName);

        $hasColumn = array_key_exists($columnName, $columns);
        $columnPresenceCache[$tableName][$columnName] = $hasColumn;

        return $hasColumn;
    }

    /**
     * Resolves the page UID that should provide content for the current page.
     *
     * @param int $pageId Routed page UID.
     */
    private function resolveContentPid(int $pageId): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->getRestrictions()->add(new FrontendRestrictionContainer($this->context));

        $row = $queryBuilder
            ->select('uid', 'content_from_pid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return 0;
        }

        $contentFromPid = (int)($row['content_from_pid'] ?? 0);
        if ($contentFromPid > 0) {
            return $contentFromPid;
        }

        return (int)($row['uid'] ?? 0);
    }
}
