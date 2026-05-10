<?php

declare(strict_types=1);

namespace Skom\Lscache\Backend\Probe;

use Psr\Http\Message\ResponseInterface;
use Throwable;
use TYPO3\CMS\Core\Localization\LanguageService;

final readonly class CacheProbeResult
{
    public const STATE_HIT = 'hit';
    public const STATE_MISS = 'miss';
    public const STATE_NOT_CACHEABLE = 'not-cacheable';
    public const STATE_NO_LSCACHE_HEADER = 'no-lscache-header';
    public const STATE_TIMEOUT = 'timeout';
    public const STATE_HTTP_ERROR = 'http-error';

    private const HEADER_NAMES = [
        'X-LiteSpeed-Cache',
        'X-LSADC-Cache',
        'X-QC-Cache',
    ];

    private const STATE_ICON_MAP = [
        self::STATE_HIT => 'actions-check-circle',
        self::STATE_MISS => 'actions-exclamation-circle',
        self::STATE_NOT_CACHEABLE => 'actions-exclamation-triangle',
        self::STATE_NO_LSCACHE_HEADER => 'actions-exclamation',
        self::STATE_TIMEOUT => 'actions-refresh',
        self::STATE_HTTP_ERROR => 'actions-close',
    ];

    private const STATE_CLASS_MAP = [
        self::STATE_HIT => 'success',
        self::STATE_MISS => 'warning',
        self::STATE_NOT_CACHEABLE => 'warning',
        self::STATE_NO_LSCACHE_HEADER => 'secondary',
        self::STATE_TIMEOUT => 'danger',
        self::STATE_HTTP_ERROR => 'danger',
    ];

    private function __construct(
        private string $state,
        private string $targetUrl,
        private ?int $statusCode = null,
        private ?string $headerName = null,
        private ?string $headerValue = null,
        private ?string $errorType = null,
    ) {}

    public static function fromResponse(ResponseInterface $response, string $targetUrl): self
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 500) {
            return new self(self::STATE_HTTP_ERROR, $targetUrl, $statusCode);
        }

        $header = self::detectCacheHeader($response);
        if (self::hasNotCacheableSignals($response, $statusCode)) {
            return new self(
                self::STATE_NOT_CACHEABLE,
                $targetUrl,
                $statusCode,
                $header['name'] ?? null,
                $header['value'] ?? null,
            );
        }

        if ($header !== null) {
            $classification = self::classifyHeaderValue($statusCode, $header['value']);
            if ($classification !== null) {
                return new self(
                    $classification,
                    $targetUrl,
                    $statusCode,
                    $header['name'],
                    $header['value'],
                );
            }

            return new self(
                self::STATE_NOT_CACHEABLE,
                $targetUrl,
                $statusCode,
                $header['name'],
                $header['value'],
            );
        }

        return new self(self::STATE_NO_LSCACHE_HEADER, $targetUrl, $statusCode);
    }

    public static function fromThrowable(string $targetUrl, Throwable $throwable): self
    {
        if (self::isTimeoutThrowable($throwable)) {
            return new self(self::STATE_TIMEOUT, $targetUrl, null, null, null, 'timeout');
        }

        return new self(self::STATE_HTTP_ERROR, $targetUrl, null, null, null, 'request-failed');
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getTargetUrl(): string
    {
        return $this->targetUrl;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function toArray(LanguageService $languageService): array
    {
        return [
            'state' => $this->state,
            'title' => $this->translate($languageService, 'probe.state.' . $this->state . '.title'),
            'message' => $this->translate($languageService, 'probe.state.' . $this->state . '.message'),
            'details' => $this->buildDetails($languageService),
            'iconIdentifier' => self::STATE_ICON_MAP[$this->state] ?? 'actions-info-circle',
            'statusClass' => self::STATE_CLASS_MAP[$this->state] ?? 'secondary',
            'targetUrl' => $this->targetUrl,
            'statusCode' => $this->statusCode,
            'headerName' => $this->headerName,
            'headerValue' => $this->headerValue,
            'errorType' => $this->errorType,
        ];
    }

    private function buildDetails(LanguageService $languageService): string
    {
        return match ($this->state) {
            self::STATE_HIT,
            self::STATE_MISS => $this->headerName !== null
                ? sprintf(
                    $this->translate($languageService, 'probe.details.header'),
                    $this->headerName,
                    $this->headerValue ?? '',
                )
                : ($this->statusCode !== null
                    ? sprintf($this->translate($languageService, 'probe.details.statusCode'), $this->statusCode)
                    : ''),
            self::STATE_NOT_CACHEABLE => $this->headerName !== null
                ? sprintf(
                    $this->translate($languageService, 'probe.details.header'),
                    $this->headerName,
                    $this->headerValue ?? '',
                )
                : ($this->statusCode !== null
                    ? sprintf($this->translate($languageService, 'probe.details.statusCode'), $this->statusCode)
                    : $this->translate($languageService, 'probe.details.requestFailed')),
            self::STATE_NO_LSCACHE_HEADER => $this->translate($languageService, 'probe.details.noHeader'),
            self::STATE_TIMEOUT => $this->translate($languageService, 'probe.details.timeout'),
            self::STATE_HTTP_ERROR => $this->statusCode !== null
                ? sprintf($this->translate($languageService, 'probe.details.statusCode'), $this->statusCode)
                : $this->translate($languageService, 'probe.details.requestFailed'),
            default => '',
        };
    }

    private function translate(LanguageService $languageService, string $key): string
    {
        return $languageService->sL('skom_lscache.messages:' . $key);
    }

    /**
     * @return array{name: string, value: string}|null
     */
    private static function detectCacheHeader(ResponseInterface $response): ?array
    {
        foreach (self::HEADER_NAMES as $headerName) {
            $headerValue = trim($response->getHeaderLine($headerName));
            if ($headerValue !== '') {
                return [
                    'name' => $headerName,
                    'value' => $headerValue,
                ];
            }
        }

        return null;
    }

    private static function classifyHeaderValue(int $statusCode, string $headerValue): ?string
    {
        $tokens = self::splitHeaderValue($headerValue);

        if (in_array('hit', $tokens, true)) {
            return self::STATE_HIT;
        }

        if (self::isNotCacheableStatus($statusCode) || self::hasNotCacheableTokens($tokens)) {
            return self::STATE_NOT_CACHEABLE;
        }

        if (in_array('miss', $tokens, true)) {
            return self::STATE_MISS;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function splitHeaderValue(string $headerValue): array
    {
        $tokens = preg_split('/[\\s,;]+/', strtolower(trim($headerValue))) ?: [];

        return array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
    }

    /**
     * @param list<string> $tokens
     */
    private static function hasNotCacheableTokens(array $tokens): bool
    {
        return array_intersect($tokens, [
            'bypass',
            'no-cache',
            'no-store',
            'nocache',
            'private',
            'uncacheable',
            'disabled',
        ]) !== [];
    }

    private static function hasNotCacheableSignals(ResponseInterface $response, int $statusCode): bool
    {
        if (self::isNotCacheableStatus($statusCode)) {
            return true;
        }

        $cacheControl = strtolower($response->getHeaderLine('Cache-Control'));
        if ($cacheControl !== '' && (
            str_contains($cacheControl, 'no-cache')
            || str_contains($cacheControl, 'no-store')
            || str_contains($cacheControl, 'private')
        )) {
            return true;
        }

        $pragma = strtolower($response->getHeaderLine('Pragma'));
        if ($pragma !== '' && str_contains($pragma, 'no-cache')) {
            return true;
        }

        return $response->hasHeader('Set-Cookie');
    }

    private static function isNotCacheableStatus(int $statusCode): bool
    {
        return in_array($statusCode, [301, 302, 303, 307, 308, 401, 403], true);
    }

    private static function isTimeoutThrowable(Throwable $throwable): bool
    {
        $message = strtolower($throwable->getMessage());
        if ($throwable->getCode() === 28 || str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return true;
        }

        $previous = $throwable->getPrevious();
        return $previous instanceof Throwable && self::isTimeoutThrowable($previous);
    }
}
