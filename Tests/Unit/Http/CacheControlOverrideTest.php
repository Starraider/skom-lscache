<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Unit\Http;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Skom\Lscache\Http\CacheControlOverride;

final class CacheControlOverrideTest extends TestCase
{
    #[Test]
    public function acceptsPositiveTtl(): void
    {
        self::assertSame(120, (new CacheControlOverride(120))->ttl);
    }

    #[Test]
    public function rejectsZeroTtl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CacheControlOverride(0);
    }

    #[Test]
    public function rejectsNegativeTtl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CacheControlOverride(-1);
    }
}
