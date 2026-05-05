<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

/**
 * Immutable inventory of cache-relevant content element types found on a page.
 */
final readonly class PageContentInventory
{
    /**
     * @param list<string> $pluginSignatures Plugin signature values found on page content records.
     * @param list<string> $ctypes Content element CType values found on page content records.
     */
    public function __construct(
        public array $pluginSignatures,
        public array $ctypes,
    ) {}

    /**
     * Creates an inventory for pages where no cache-relevant content records were found.
     */
    public static function empty(): self
    {
        return new self([], []);
    }
}
