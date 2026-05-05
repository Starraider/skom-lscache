<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge;

interface TagNamespaceResolverInterface
{
    public function resolveForSite(string $siteIdentifier): string;
}
