<?php

declare(strict_types=1);

namespace Skom\Lscache\Backend\Probe;

use Throwable;
use TYPO3\CMS\Core\Http\RequestFactory;

final readonly class CacheProbeClient implements CacheProbeClientInterface
{
    public function __construct(
        private RequestFactory $requestFactory,
    ) {}

    public function probe(string $targetUrl): CacheProbeResult
    {
        try {
            $response = $this->requestFactory->request(
                $targetUrl,
                'HEAD',
                [
                    'headers' => [
                        'User-Agent' => 'skom/lscache-probe',
                    ],
                    'timeout' => 3,
                    'connect_timeout' => 3,
                    'allow_redirects' => false,
                    'http_errors' => false,
                ],
                null,
            );
            return CacheProbeResult::fromResponse($response, $targetUrl);
        } catch (Throwable $throwable) {
            return CacheProbeResult::fromThrowable($targetUrl, $throwable);
        }
    }
}
