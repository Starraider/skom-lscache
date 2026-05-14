<?php

declare(strict_types=1);

namespace Skom\Lscache\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Skom\Lscache\Purge\Audit\PurgeAuditRequestContext;
use Skom\Lscache\Purge\PurgeHeaderSerializer;
use Skom\Lscache\Purge\PurgePayload;
use Skom\Lscache\Purge\PurgeQueue;
use Skom\Lscache\Purge\TagNamespaceResolverInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

class LoopbackEndpointMiddleware implements MiddlewareInterface
{
    private FrontendInterface $loopbackCache;

    public function __construct(
        private readonly PurgeQueue $purgeQueue,
        private readonly PurgeAuditRequestContext $auditRequestContext,
        private readonly PurgeHeaderSerializer $purgeHeaderSerializer,
        private readonly TagNamespaceResolverInterface $tagNamespaceResolver,
        CacheManager $cacheManager,
    ) {
        $this->loopbackCache = $cacheManager->getCache('skom_lscache_loopback');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== '/_lscache/purge') {
            return $handler->handle($request);
        }

        if ($request->getMethod() !== 'POST') {
            return $this->createTextResponse('Method Not Allowed', 405);
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $this->createTextResponse('Bad Request: Site not resolved', 400);
        }

        $settings = $site->getSettings();
        if (!(bool)$settings->get('lscache.enabled', true)) {
            return $this->createTextResponse('Not Found', 404);
        }

        $secret = (string)$settings->get('lscache.loopback.secret', '');
        if ($secret === '') {
            return $this->createTextResponse('Forbidden: Endpoint not configured', 403);
        }

        $allowedIps = array_values(array_filter(array_map(
            static fn (mixed $address): string => trim((string)$address),
            $this->resolveAllowedRemoteAddrs($settings),
        )));
        $remoteAddr = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        if ($allowedIps !== [] && !in_array($remoteAddr, $allowedIps, true)) {
            return $this->createTextResponse('Forbidden: IP not allowed', 403);
        }

        $rawBody = (string)$request->getBody();
        $body = json_decode($rawBody, true);
        if (!is_array($body) || !isset($body['site'], $body['payload'], $body['requestId'], $body['timestamp'])) {
            return $this->createTextResponse('Bad Request: Invalid JSON or missing fields', 400);
        }

        if ($body['site'] !== $site->getIdentifier()) {
            return $this->createTextResponse('Bad Request: Site mismatch', 400);
        }

        $timestamp = (int)$body['timestamp'];
        $now = time();
        if (abs($now - $timestamp) > 300) {
            return $this->createTextResponse('Bad Request: Stale timestamp', 400);
        }

        $signatureHeader = $request->getHeaderLine('X-Skom-Lscache-Signature');
        if (!str_starts_with($signatureHeader, 'sha256=')) {
            return $this->createTextResponse('Unauthorized: Missing or invalid signature format', 401);
        }
        $providedHmac = substr($signatureHeader, 7);
        $expectedHmac = hash_hmac('sha256', $rawBody, $secret);

        if (!hash_equals($expectedHmac, $providedHmac)) {
            return $this->createTextResponse('Unauthorized: Invalid signature', 401);
        }

        $rateLimit = (int)$settings->get('lscache.loopback.rateLimit', 120);
        $minute = (int)floor($now / 60);
        $cacheKey = preg_replace('/[^a-zA-Z0-9_%\\-]/', '_', 'ratelimit_' . $site->getIdentifier() . '_' . $minute);
        
        $currentCount = (int)$this->loopbackCache->get($cacheKey);
        if ($currentCount >= $rateLimit) {
            return $this->createTextResponse('Too Many Requests', 429);
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

        // Drain the PurgeQueue and serialize into X-LiteSpeed-Purge headers.
        // The SkomLscacheMiddleware is positioned after this middleware in the stack
        // and never runs on loopback requests, so we must emit purge headers here.
        $serialized = $this->purgeHeaderSerializer->serialize(
            $this->purgeQueue->drain(),
            fn (string $siteIdentifier): string => $this->tagNamespaceResolver->resolveForSite($siteIdentifier),
        );

        $response = new Response('php://temp', 200, ['Content-Type' => 'application/json']);
        foreach ($serialized['headers'] as $headerValue) {
            $response = $response->withAddedHeader('X-LiteSpeed-Purge', $headerValue);
        }

        $responseBody = json_encode([
            'requestId' => $body['requestId'],
            'outcome' => $serialized['outcome'],
            'tagCount' => $serialized['tagCount'],
        ]);
        $response->getBody()->write((string)$responseBody);

        return $response;
    }

    /**
     * @return list<mixed>
     */
    private function resolveAllowedRemoteAddrs(SiteSettings $settings): array
    {
        $allSettings = $settings->getAll();
        $lscacheSettings = $allSettings['lscache'] ?? [];
        $loopbackSettings = is_array($lscacheSettings) ? ($lscacheSettings['loopback'] ?? []) : [];
        $configured = is_array($loopbackSettings) ? ($loopbackSettings['allowedRemoteAddrs'] ?? null) : null;
        if (is_array($configured)) {
            return array_values($configured);
        }

        return (array)$settings->get('lscache.loopback.allowedRemoteAddrs', ['127.0.0.1', '::1']);
    }

    private function createTextResponse(string $body, int $statusCode): Response
    {
        $response = new Response('php://temp', $statusCode, ['Content-Type' => 'text/plain; charset=utf-8']);
        $response->getBody()->write($body);

        return $response;
    }
}
