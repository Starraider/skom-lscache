<?php

declare(strict_types=1);

namespace Skom\Lscache\Backend\Controller;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

trait SiteSelectorMenuTrait
{
    /**
     * @param array<string, Site> $sites
     */
    private function registerSiteSelectorMenu(
        ModuleTemplate $view,
        array $sites,
        string $selectedSiteIdentifier,
        ServerRequestInterface $request,
        UriBuilder $uriBuilder,
        LanguageService $languageService,
    ): void {
        if ($sites === []) {
            return;
        }

        $componentFactory = GeneralUtility::makeInstance(ComponentFactory::class);
        $siteSelector = $componentFactory->createDropDownButton()
            ->setLabel($this->translate($languageService, 'dashboard.siteSelector.heading'))
            ->setShowLabelText(true)
            ->setShowActiveLabelText(true);

        $currentParams = $request->getQueryParams();

        foreach ($sites as $site) {
            $identifier = $site->getIdentifier();
            $siteLabel = trim((string)($site->getConfiguration()['websiteTitle'] ?? ''));
            if ($siteLabel === '') {
                $siteLabel = $identifier;
            }

            // Keep existing query params (like pagination, filters) but change the site
            $params = array_merge($currentParams, ['site' => $identifier]);
            $url = (string)$uriBuilder->buildUriFromRequest($request, $params);

            $siteSelector->addItem(
                $componentFactory->createDropDownRadio()
                    ->setHref($url)
                    ->setLabel($siteLabel)
                    ->setTitle($siteLabel)
                    ->setActive($identifier === $selectedSiteIdentifier),
            );
        }

        $view->getDocHeaderComponent()
            ->getButtonBar()
            ->addButton($siteSelector, ButtonBar::BUTTON_POSITION_LEFT, 0);
    }
}
