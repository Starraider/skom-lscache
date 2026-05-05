<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

use RuntimeException;

/**
 * Exception used internally when cacheability logic cannot resolve required request attributes.
 */
final class MissingRequestContext extends RuntimeException
{
    /**
     * Creates an exception for a missing PSR-7 request attribute.
     *
     * @param string $attributeName Required request attribute name that was not present or had the wrong type.
     */
    public static function forAttribute(string $attributeName): self
    {
        return new self(sprintf('Missing required request attribute "%s".', $attributeName));
    }
}
