<?php

declare(strict_types=1);

namespace Skom\Lscache\Backend\PageStatus;

final class PageStatusAnalysisException extends \RuntimeException
{
    private function __construct(
        private readonly string $translationKey,
    ) {
        parent::__construct($translationKey);
    }

    public static function invalidPageUid(): self
    {
        return new self('pageStatus.error.invalidPageUid');
    }

    public static function pageNotFound(): self
    {
        return new self('pageStatus.error.pageNotFound');
    }

    public static function siteNotResolved(): self
    {
        return new self('pageStatus.error.siteNotResolved');
    }

    public static function pageOutsideSelectedSite(): self
    {
        return new self('pageStatus.error.pageOutsideSelectedSite');
    }

    public static function siteNotCacheEnabled(): self
    {
        return new self('pageStatus.error.siteNotCacheEnabled');
    }

    public static function ttlNotConfigured(): self
    {
        return new self('pageStatus.error.ttlNotConfigured');
    }

    public function getTranslationKey(): string
    {
        return $this->translationKey;
    }
}
