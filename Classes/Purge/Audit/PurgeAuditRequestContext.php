<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\Audit;

/**
 * Request-scoped registry that tracks which purge requests have already been persisted.
 *
 * @internal
 */
final class PurgeAuditRequestContext
{
    /**
     * @var array<string, true>
     */
    private array $requestIds = [];

    public function recordRequestId(string $requestId): void
    {
        $requestId = trim($requestId);
        if ($requestId === '') {
            return;
        }

        $this->requestIds[$requestId] = true;
    }

    /**
     * @return list<string>
     */
    public function peekRequestIds(): array
    {
        return array_keys($this->requestIds);
    }

    /**
     * @return list<string>
     */
    public function consumeRequestIds(): array
    {
        $requestIds = array_keys($this->requestIds);
        $this->requestIds = [];

        return $requestIds;
    }

    public function isEmpty(): bool
    {
        return $this->requestIds === [];
    }
}
