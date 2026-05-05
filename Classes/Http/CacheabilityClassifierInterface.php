<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Contract for services that decide whether a TYPO3 response may receive public LSCache headers.
 */
interface CacheabilityClassifierInterface
{
    /**
     * Classifies a finished downstream response for the given request.
     *
     * @param ServerRequestInterface $request Current PSR-7 request with TYPO3 routing, site, and user attributes.
     * @param ResponseInterface $response Downstream response returned by the next middleware or request handler.
     *
     * @api
     */
    public function classify(ServerRequestInterface $request, ResponseInterface $response): CacheabilityDecision;
}
