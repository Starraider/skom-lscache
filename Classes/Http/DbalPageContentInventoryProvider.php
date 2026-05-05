<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

use Psr\Http\Message\ServerRequestInterface;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Routing\PageArguments;

final readonly class DbalPageContentInventoryProvider implements PageContentInventoryProvider
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
    ) {}

    public function getForRequest(ServerRequestInterface $request): PageContentInventory
    {
        $routing = $request->getAttribute('routing');
        if (!$routing instanceof PageArguments) {
            return PageContentInventory::empty();
        }

        $pid = $this->resolveContentPid($routing->getPageId());
        if ($pid <= 0) {
            return PageContentInventory::empty();
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->getRestrictions()->add(new FrontendRestrictionContainer($this->context));

        $rows = $queryBuilder
            ->select('CType', 'list_type')
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
