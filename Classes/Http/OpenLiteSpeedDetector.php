<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Log\Channel;

/**
 * Detects OpenLiteSpeed from request server parameters and logs graceful degradation once per worker.
 */
class OpenLiteSpeedDetector
{
    private static bool $nonOlsNoticeLogged = false;

    /**
     * @param LoggerInterface $logger Channel logger used for non-OLS degradation notices.
     */
    public function __construct(
        #[Channel('skom.lscache')]
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Checks whether the current request appears to run behind LiteSpeed/OpenLiteSpeed.
     *
     * @param ServerRequestInterface $request Current PSR-7 request with server parameters.
     */
    public function isOpenLiteSpeed(ServerRequestInterface $request): bool
    {
        $serverSoftware = strtolower((string)($request->getServerParams()['SERVER_SOFTWARE'] ?? ''));

        return str_contains($serverSoftware, 'openlitespeed')
            || str_contains($serverSoftware, 'litespeed');
    }

    /**
     * Logs a single notice when LSCache headers are emitted on a non-LiteSpeed runtime.
     *
     * @param ServerRequestInterface $request Current PSR-7 request with server parameters.
     */
    public function logGracefulDegradationIfMissing(ServerRequestInterface $request): void
    {
        if ($this->isOpenLiteSpeed($request) || self::$nonOlsNoticeLogged) {
            return;
        }

        self::$nonOlsNoticeLogged = true;
        $this->logger->notice('OpenLiteSpeed not detected; LSCache response headers are emitted for graceful degradation.', [
            'outcome' => 'non-ols-degraded',
        ]);
    }

    /**
     * Resets the one-time notice guard for isolated unit tests.
     */
    public static function resetNoticeForTests(): void
    {
        self::$nonOlsNoticeLogged = false;
    }
}
