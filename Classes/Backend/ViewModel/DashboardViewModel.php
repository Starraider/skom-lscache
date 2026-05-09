<?php

declare(strict_types=1);

namespace Skom\Lscache\Backend\ViewModel;

final readonly class DashboardViewModel
{
    /**
     * @param list<array{identifier: string, label: string, baseUrl: string, selected: bool}> $siteOptions
     * @param list<array{key: string, label: string, description: string, value: int, iconIdentifier: string, statusClass: string}> $summaryCards
     * @param list<array{siteIdentifier: string, label: string, count: int, selected: bool}> $siteBreakdown
     * @param list<array{
     *     uid: int,
     *     crdate: int,
     *     requestId: string,
     *     triggerRef: ?string,
     *     tagCount: int,
     *     source: string,
     *     sourceLabel: string,
     *     outcome: string,
     *     outcomeLabel: string,
     *     outcomeIconIdentifier: string,
     *     outcomeStatusClass: string
     * }> $recentRows
     */
    public function __construct(
        private string $selectedSiteIdentifier,
        private string $selectedSiteLabel,
        private string $selectedSiteBaseUrl,
        private string $dashboardUrl,
        private string $probeEndpointUrl,
        private string $probeTargetUrl,
        private array $siteOptions,
        private array $summaryCards,
        private array $siteBreakdown,
        private array $recentRows,
    ) {}

    public function getSelectedSiteIdentifier(): string
    {
        return $this->selectedSiteIdentifier;
    }

    public function getSelectedSiteLabel(): string
    {
        return $this->selectedSiteLabel;
    }

    public function getSelectedSiteBaseUrl(): string
    {
        return $this->selectedSiteBaseUrl;
    }

    public function getDashboardUrl(): string
    {
        return $this->dashboardUrl;
    }

    public function getProbeEndpointUrl(): string
    {
        return $this->probeEndpointUrl;
    }

    public function getProbeTargetUrl(): string
    {
        return $this->probeTargetUrl;
    }

    /**
     * @return list<array{identifier: string, label: string, baseUrl: string, selected: bool}>
     */
    public function getSiteOptions(): array
    {
        return $this->siteOptions;
    }

    /**
     * @return list<array{key: string, label: string, description: string, value: int, iconIdentifier: string, statusClass: string}>
     */
    public function getSummaryCards(): array
    {
        return $this->summaryCards;
    }

    /**
     * @return list<array{siteIdentifier: string, label: string, count: int, selected: bool}>
     */
    public function getSiteBreakdown(): array
    {
        return $this->siteBreakdown;
    }

    /**
     * @return list<array{
     *     uid: int,
     *     crdate: int,
     *     requestId: string,
     *     triggerRef: ?string,
     *     tagCount: int,
     *     source: string,
     *     sourceLabel: string,
     *     outcome: string,
     *     outcomeLabel: string,
     *     outcomeIconIdentifier: string,
     *     outcomeStatusClass: string
     * }>
     */
    public function getRecentRows(): array
    {
        return $this->recentRows;
    }

    public function hasSiteOptions(): bool
    {
        return $this->siteOptions !== [];
    }
}
