<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use Skom\Lscache\Backend\Controller\AuditLogController;
use Skom\Lscache\Backend\Controller\DashboardController;
use Skom\Lscache\Backend\Controller\ManualPurgeController;
use Skom\Lscache\Backend\Controller\PageStatusController;
use Skom\Lscache\Backend\Controller\ProbeController;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

require_once __DIR__ . '/AbstractBackendModuleTestCase.php';

final class ModuleRegistrationTest extends AbstractBackendModuleTestCase
{
    #[Test]
    public function moduleIsRegisteredWithExpectedRoutesAndLabels(): void
    {
        $backendUser = $this->createBackendUser('de');

        /** @var ModuleProvider $moduleProvider */
        $moduleProvider = $this->get(ModuleProvider::class);
        $module = $moduleProvider->getModule('web_SkomLscacheCache', $backendUser);

        self::assertInstanceOf(ModuleInterface::class, $module);
        self::assertSame('content', $module->getParentIdentifier());
        self::assertSame('/module/web/lscache', $module->getPath());
        self::assertSame('module-skom-lscache', $module->getIconIdentifier());
        self::assertSame('user', $module->getAccess());
        self::assertSame([], $module->getAliases());
        self::assertSame('LLL:EXT:skom_lscache/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab', $module->getTitle());
        self::assertSame('LLL:EXT:skom_lscache/Resources/Private/Language/locallang_mod.xlf:mlang_labels_tabdescr', $module->getDescription());
        self::assertSame('LLL:EXT:skom_lscache/Resources/Private/Language/locallang_mod.xlf:mlang_labels_tablabel', $module->getShortDescription());

        $routes = $module->getDefaultRouteOptions();
        self::assertArrayHasKey('_default', $routes);
        self::assertArrayHasKey('audit-log', $routes);
        self::assertArrayHasKey('audit-log-export', $routes);
        self::assertArrayHasKey('page-status', $routes);
        self::assertArrayHasKey('manual-purge', $routes);
        self::assertArrayHasKey('manual-purge-execute', $routes);
        self::assertArrayHasKey('probe', $routes);
        self::assertSame(DashboardController::class . '::handleRequest', $routes['_default']['target']);
        self::assertSame(AuditLogController::class . '::handleRequest', $routes['audit-log']['target']);
        self::assertSame(AuditLogController::class . '::exportCsv', $routes['audit-log-export']['target']);
        self::assertSame(PageStatusController::class . '::handleRequest', $routes['page-status']['target']);
        self::assertSame(ManualPurgeController::class . '::handleRequest', $routes['manual-purge']['target']);
        self::assertSame(ManualPurgeController::class . '::submitRequest', $routes['manual-purge-execute']['target']);
        self::assertSame(ProbeController::class . '::handleRequest', $routes['probe']['target']);
        self::assertSame(['GET'], $routes['audit-log-export']['methods']);
        self::assertSame(['GET'], $routes['manual-purge']['methods']);
        self::assertSame(['POST'], $routes['manual-purge-execute']['methods']);
        self::assertSame(['POST'], $routes['probe']['methods']);

        /** @var LanguageServiceFactory $languageServiceFactory */
        $languageServiceFactory = $this->get(LanguageServiceFactory::class);
        $english = $languageServiceFactory->create('en');
        $german = $languageServiceFactory->create('de');

        self::assertSame('LiteSpeed Cache', $english->sL('skom_lscache.mod:mlang_tabs_tab'));
        self::assertSame('LiteSpeed Cache dashboard', $english->sL('skom_lscache.mod:mlang_labels_tablabel'));
        self::assertSame('Purge-Aktivität prüfen und Cache-Hit-Status kontrollieren.', $german->sL('skom_lscache.mod:mlang_labels_tabdescr'));
        self::assertSame('LiteSpeed-Cache-Dashboard', $german->sL('skom_lscache.mod:mlang_labels_tablabel'));
    }
}
