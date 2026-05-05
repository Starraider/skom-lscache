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
    private const string APCU_NOTICE_KEY = 'skom_lscache_non_ols_notice_logged';

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
        if ($this->isOpenLiteSpeed($request)) {
            return;
        }

        if (self::$nonOlsNoticeLogged || !$this->markNoticeAsFirstOccurrence()) {
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

        if (function_exists('apcu_delete')) {
            apcu_delete(self::APCU_NOTICE_KEY);
        }
    }

    private function markNoticeAsFirstOccurrence(): bool
    {
        if (!$this->isApcuUsable()) {
            return true;
        }

        return apcu_add(self::APCU_NOTICE_KEY, true);
    }

    private function isApcuUsable(): bool
    {
        if (!function_exists('apcu_add')) {
            return false;
        }

        if (!(bool)ini_get('apc.enabled')) {
            return false;
        }

        if (PHP_SAPI === 'cli' && !(bool)ini_get('apc.enable_cli')) {
            return false;
        }

        return true;
    }
}
