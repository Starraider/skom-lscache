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
            $firstResponse = $this->requestFactory->request($targetUrl, 'HEAD', $this->requestOptions(), null);
            $firstResult = CacheProbeResult::fromResponse($firstResponse, $targetUrl);

            if ($firstResult->getState() !== CacheProbeResult::STATE_MISS) {
                return $firstResult;
            }

            try {
                $secondResponse = $this->requestFactory->request($targetUrl, 'HEAD', $this->requestOptions(), null);
                return CacheProbeResult::fromResponse($secondResponse, $targetUrl);
            } catch (Throwable) {
                return $firstResult;
            }
        } catch (Throwable $throwable) {
            return CacheProbeResult::fromThrowable($targetUrl, $throwable);
        }
    }

    /**
     * @return array{
     *     headers: array{User-Agent: string},
     *     timeout: int,
     *     connect_timeout: int,
     *     allow_redirects: bool,
     *     http_errors: bool
     * }
     */
    private function requestOptions(): array
    {
        return [
            'headers' => [
                'User-Agent' => 'skom/lscache-probe',
            ],
            'timeout' => 3,
            'connect_timeout' => 3,
            'allow_redirects' => false,
            'http_errors' => false,
        ];
    }
}
