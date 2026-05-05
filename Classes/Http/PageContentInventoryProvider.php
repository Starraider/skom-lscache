<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Provides cache-relevant content inventory for a resolved frontend page request.
 */
interface PageContentInventoryProvider
{
    /**
     * Resolves plugin signatures and CTypes for the page represented by the request.
     *
     * @param ServerRequestInterface $request Current PSR-7 request with a TYPO3 `routing` attribute.
     */
    public function getForRequest(ServerRequestInterface $request): PageContentInventory;
}
