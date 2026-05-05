<?php

declare(strict_types=1);

namespace Skom\Lscache\Http\Event;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Skom\Lscache\Http\CacheControlOverride;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

/**
 * PSR-14 event that lets listeners replace the default LSCache max-age for a response.
 */
final class CacheControlOverrideEvent
{
    private ?CacheControlOverride $override = null;

    /**
     * @param ServerRequestInterface $request Current PSR-7 request being processed.
     * @param ResponseInterface $response Downstream response before LSCache headers are written.
     * @param SiteSettings $siteSettings TYPO3 site settings for the current site.
     * @param int $defaultTtl Positive default TTL resolved from site settings.
     *
     * @api
     */
    public function __construct(
        private readonly ServerRequestInterface $request,
        private readonly ResponseInterface $response,
        private readonly SiteSettings $siteSettings,
        private readonly int $defaultTtl,
    ) {}

    /**
     * Returns the request being classified for LSCache headers.
     */
    public function getRequest(): ServerRequestInterface
    {
        return $this->request;
    }

    /**
     * Returns the downstream response before LSCache headers are added.
     */
    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }

    /**
     * Returns the site settings used to resolve LSCache defaults.
     */
    public function getSiteSettings(): SiteSettings
    {
        return $this->siteSettings;
    }

    /**
     * Returns the positive default TTL from `lscache.defaultTtl`.
     */
    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    /**
     * Returns the listener-provided override, or null when no listener changed the TTL.
     */
    public function getOverride(): ?CacheControlOverride
    {
        return $this->override;
    }

    /**
     * Sets a validated TTL override value object.
     *
     * @param CacheControlOverride $override Positive TTL override supplied by a listener.
     */
    public function setOverride(CacheControlOverride $override): void
    {
        $this->override = $override;
    }

    /**
     * Creates and sets a validated TTL override from a raw integer.
     *
     * @param int $ttl Positive cache lifetime in seconds.
     */
    public function setOverrideTtl(int $ttl): void
    {
        $this->override = new CacheControlOverride($ttl);
    }
}
