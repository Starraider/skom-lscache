<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

final readonly class PageContentInventory
{
    /**
     * @param list<string> $pluginSignatures
     * @param list<string> $ctypes
     */
    public function __construct(
        public array $pluginSignatures,
        public array $ctypes,
    ) {}

    public static function empty(): self
    {
        return new self([], []);
    }
}
