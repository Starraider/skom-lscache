<?php

declare(strict_types=1);

namespace Skom\Lscache\Backend\Probe;

interface CacheProbeClientInterface
{
    public function probe(string $targetUrl): CacheProbeResult;
}
