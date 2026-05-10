<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Log\Channel;
use TYPO3\CMS\Core\Site\Entity\Site;

final readonly class LoopbackDispatcher
{
    public function __construct(
        private RequestFactory $requestFactory,
        #[Channel('skom.lscache')]
        private LoggerInterface $logger,
    ) {}

    public function dispatch(PurgePayload $payload, Site $site, string $requestId): void
    {
        $settings = $site->getSettings();
        if (!(bool)$settings->get('lscache.enabled', true)) {
            return;
        }

        $secret = trim((string)$settings->get('lscache.loopback.secret', ''));
        if ($secret === '') {
            $this->logger->warning('loopback-dispatch-failed', [
                'site' => $site->getIdentifier(),
                'requestId' => $requestId,
                'reason' => 'Secret not configured',
            ]);
            return;
        }

        $baseUri = (string)$site->getBase();
        $url = rtrim($baseUri, '/') . '/_lscache/purge';

        $bodyParams = [
            'site' => $site->getIdentifier(),
            'requestId' => $requestId,
            'timestamp' => time(),
            'payload' => $payload->toArray(),
        ];

        try {
            $bodyJson = json_encode($bodyParams, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            $this->logger->error('loopback-dispatch-failed', [
                'site' => $site->getIdentifier(),
                'requestId' => $requestId,
                'reason' => 'JSON encoding failed',
                'exception' => $e->getMessage(),
            ]);
            return;
        }

        $signature = hash_hmac('sha256', $bodyJson, $secret);

        $options = [
            'timeout' => 2.0,
            'allow_redirects' => false,
            'verify' => false,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Skom-Lscache-Signature' => 'sha256=' . $signature,
            ],
            'body' => $bodyJson,
        ];

        try {
            $response = $this->requestFactory->request($url, 'POST', $options);
            
            if ($response->getStatusCode() !== 200) {
                $this->logger->warning('loopback-dispatch-failed', [
                    'site' => $site->getIdentifier(),
                    'requestId' => $requestId,
                    'outcome' => 'loopback-failed',
                    'reason' => 'Non-200 response',
                    'statusCode' => $response->getStatusCode(),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->info('loopback-dispatch-error', [
                'site' => $site->getIdentifier(),
                'requestId' => $requestId,
                'outcome' => 'loopback-failed',
                'reason' => 'HTTP request failed',
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
