<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

use Psr\Http\Message\ServerRequestInterface;

interface PageContentInventoryProvider
{
    public function getForRequest(ServerRequestInterface $request): PageContentInventory;
}
