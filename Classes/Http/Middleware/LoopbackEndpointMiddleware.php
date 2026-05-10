<?php

declare(strict_types=1);

namespace Skom\Lscache\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Skom\Lscache\Purge\Audit\PurgeAuditRequestContext;
use Skom\Lscache\Purge\PurgePayload;
use Skom\Lscache\Purge\PurgeQueue;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Site\Entity\Site;

class LoopbackEndpointMiddleware implements MiddlewareInterface
{
    private FrontendInterface $loopbackCache;

    public function __construct(
        private readonly PurgeQueue $purgeQueue,
        private readonly PurgeAuditRequestContext $auditRequestContext,
        CacheManager $cacheManager
    ) {
        $this->loopbackCache = $cacheManager->getCache('skom_lscache_loopback');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== '/_lscache/purge') {
            return $handler->handle($request);
        }

        if ($request->getMethod() !== 'POST') {
            return new Response('Method Not Allowed', 405);
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return new Response('Bad Request: Site not resolved', 400);
        }

        $settings = $site->getSettings();
        if (!(bool)$settings->get('lscache.enabled', true)) {
            return new Response('Not Found', 404);
        }

        $secret = (string)$settings->get('lscache.loopback.secret', '');
        if ($secret === '') {
            return new Response('Forbidden: Endpoint not configured', 403);
        }

        $allowedIps = (array)$settings->get('lscache.loopback.allowedRemoteAddrs', ['127.0.0.1', '::1']);
        $remoteAddr = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        if (!in_array($remoteAddr, $allowedIps, true)) {
            return new Response('Forbidden: IP not allowed', 403);
        }

        $rawBody = (string)$request->getBody();
        $body = json_decode($rawBody, true);
        if (!is_array($body) || !isset($body['site'], $body['payload'], $body['requestId'], $body['timestamp'])) {
            return new Response('Bad Request: Invalid JSON or missing fields', 400);
        }

        if ($body['site'] !== $site->getIdentifier()) {
            return new Response('Bad Request: Site mismatch', 400);
        }

        $timestamp = (int)$body['timestamp'];
        $now = time();
        if (abs($now - $timestamp) > 300) {
            return new Response('Bad Request: Stale timestamp', 400);
        }

        $signatureHeader = $request->getHeaderLine('X-Skom-Lscache-Signature');
        if (!str_starts_with($signatureHeader, 'sha256=')) {
            return new Response('Unauthorized: Missing or invalid signature format', 401);
        }
        $providedHmac = substr($signatureHeader, 7);
        $expectedHmac = hash_hmac('sha256', $rawBody, $secret);

        if (!hash_equals($expectedHmac, $providedHmac)) {
            return new Response('Unauthorized: Invalid signature', 401);
        }

        $rateLimit = (int)$settings->get('lscache.loopback.rateLimit', 120);
        $minute = (int)floor($now / 60);
        $cacheKey = preg_replace('/[^a-zA-Z0-9_%\\-]/', '_', 'ratelimit_' . $site->getIdentifier() . '_' . $minute);
        
        $currentCount = (int)$this->loopbackCache->get($cacheKey);
        if ($currentCount >= $rateLimit) {
            return new Response('Too Many Requests', 429);
        }
        $this->loopbackCache->set($cacheKey, $currentCount + 1, [], 60);

        $payload = PurgePayload::fromArray($body['payload']);
        $tags = $payload->tags();
        if ($tags !== []) {
            $this->purgeQueue->enqueueTags($tags);
        }
        foreach ($payload->urls() as $url) {
            $this->purgeQueue->enqueueUrl($url);
        }
        foreach ($payload->fullSiteFlushes() as $siteFlush) {
            if ($siteFlush === '*') {
                $this->purgeQueue->enqueueEverythingFlush();
            } else {
                $this->purgeQueue->enqueueFullSiteFlush($siteFlush);
            }
        }

        $this->auditRequestContext->recordRequestId($body['requestId']);

        $response = new Response('php://temp', 200, ['Content-Type' => 'application/json']);
        $responseBody = json_encode([
            'requestId' => $body['requestId'],
            'outcome' => 'loopback-dispatched',
            'tagCount' => count($tags),
        ]);
        $response->getBody()->write((string)$responseBody);
        
        return $response;
    }
}
