<?php

declare(strict_types=1);

namespace Skom\Lscache\Backend\Audit;

use DateTimeImmutable;

/**
 * @internal
 */
final readonly class AuditLogFilter
{
    private const array DATE_PRESETS = [
        'lastHour',
        'last24h',
        'last7d',
        'custom',
    ];

    /**
     * @var list<int>
     */
    private const array PER_PAGE_OPTIONS = [25, 50, 100, 200];

    /**
     * @param list<string> $siteIdentifiers
     * @param list<string> $sourceValues
     * @param list<string> $outcomeValues
     */
    public function __construct(
        private array $siteIdentifiers,
        private array $sourceValues,
        private array $outcomeValues,
        private ?string $requestId,
        private string $datePreset,
        private ?DateTimeImmutable $from,
        private ?DateTimeImmutable $to,
        private ?string $customFrom,
        private ?string $customTo,
        private bool $invalidDateRange,
        private bool $invalidRequestId,
        private int $page,
        private int $perPage,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload, ?DateTimeImmutable $now = null): self
    {
        $now ??= new DateTimeImmutable();

        $siteIdentifiers = self::normalizeStringList($payload['site'] ?? []);
        $sourceValues = self::normalizeStringList($payload['source'] ?? []);
        $outcomeValues = self::normalizeStringList($payload['outcome'] ?? []);
        [$requestId, $invalidRequestId] = self::normalizeRequestId($payload['requestId'] ?? null);

        $datePreset = self::normalizeDatePreset((string)($payload['datePreset'] ?? 'last24h'));
        $customFrom = self::normalizeNullableString($payload['customFrom'] ?? null);
        $customTo = self::normalizeNullableString($payload['customTo'] ?? null);

        [$from, $to, $invalidDateRange] = self::resolveDateRange($datePreset, $customFrom, $customTo, $now);

        $page = self::normalizePositiveInteger($payload['page'] ?? 1, 1);
        $perPage = self::normalizePerPage($payload['perPage'] ?? 50);

        return new self(
            $siteIdentifiers,
            $sourceValues,
            $outcomeValues,
            $requestId,
            $datePreset,
            $from,
            $to,
            $customFrom,
            $customTo,
            $invalidDateRange,
            $invalidRequestId,
            $page,
            $perPage,
        );
    }

    /**
     * @return list<string>
     */
    public function getSiteIdentifiers(): array
    {
        return $this->siteIdentifiers;
    }

    /**
     * @return list<string>
     */
    public function getSourceValues(): array
    {
        return $this->sourceValues;
    }

    /**
     * @return list<string>
     */
    public function getOutcomeValues(): array
    {
        return $this->outcomeValues;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getDatePreset(): string
    {
        return $this->datePreset;
    }

    public function getFrom(): ?DateTimeImmutable
    {
        return $this->from;
    }

    public function getTo(): ?DateTimeImmutable
    {
        return $this->to;
    }

    public function getCustomFrom(): ?string
    {
        return $this->customFrom;
    }

    public function getCustomTo(): ?string
    {
        return $this->customTo;
    }

    public function hasInvalidDateRange(): bool
    {
        return $this->invalidDateRange;
    }

    public function hasInvalidRequestId(): bool
    {
        return $this->invalidRequestId;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    public function getOffset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * @return list<int>
     */
    public function getPerPageOptions(): array
    {
        return self::PER_PAGE_OPTIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function toQueryParameters(bool $includePagination = true): array
    {
        $query = [
            'site' => $this->siteIdentifiers,
            'source' => $this->sourceValues,
            'outcome' => $this->outcomeValues,
            'datePreset' => $this->datePreset,
        ];

        if ($this->requestId !== null) {
            $query['requestId'] = $this->requestId;
        }

        if ($this->datePreset === 'custom') {
            $query['customFrom'] = $this->customFrom ?? '';
            $query['customTo'] = $this->customTo ?? '';
        }

        if ($includePagination) {
            $query['page'] = $this->page;
            $query['perPage'] = $this->perPage;
        }

        return $query;
    }

    /**
     * @param mixed $value
     */
    private static function normalizePerPage(mixed $value): int
    {
        $perPage = self::normalizePositiveInteger($value, 50);

        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 50;
    }

    private static function normalizeDatePreset(string $value): string
    {
        $normalized = strtolower(trim($value));

        $mapped = match ($normalized) {
            'lasthour', 'last_hour', 'last-hour' => 'lastHour',
            'last24h', 'last_24h', 'last-24h' => 'last24h',
            'last7d', 'last_7d', 'last-7d' => 'last7d',
            'custom' => 'custom',
            default => 'last24h',
        };

        if (!in_array($mapped, self::DATE_PRESETS, true)) {
            return 'last24h';
        }

        return $mapped;
    }

    private static function normalizePositiveInteger(mixed $value, int $default): int
    {
        if (is_numeric($value)) {
            $integerValue = (int)$value;
            if ($integerValue > 0) {
                return $integerValue;
            }
        }

        return $default;
    }

    private static function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string)$value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param mixed $value
     * @return array{0: ?string, 1: bool}
     */
    private static function normalizeRequestId(mixed $value): array
    {
        $normalized = self::normalizeNullableString($value);
        if ($normalized === null) {
            return [null, false];
        }

        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $normalized) !== 1) {
            return [null, true];
        }

        return [strtolower($normalized), false];
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function normalizeStringList(mixed $value): array
    {
        $items = [];
        if (is_array($value)) {
            $items = $value;
        } elseif ($value !== null && $value !== '') {
            $items = [$value];
        }

        $normalized = [];
        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $normalized[$item] = true;
            }
        }

        return array_keys($normalized);
    }

    /**
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable, 2: bool}
     */
    private static function resolveDateRange(
        string $datePreset,
        ?string $customFrom,
        ?string $customTo,
        DateTimeImmutable $now,
    ): array {
        return match ($datePreset) {
            'lastHour' => [$now->modify('-1 hour'), $now, false],
            'last24h' => [$now->modify('-24 hours'), $now, false],
            'last7d' => [$now->modify('-7 days'), $now, false],
            'custom' => self::resolveCustomDateRange($customFrom, $customTo),
            default => [null, null, false],
        };
    }

    /**
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable, 2: bool}
     */
    private static function resolveCustomDateRange(?string $customFrom, ?string $customTo): array
    {
        $from = self::parseDateTime($customFrom);
        $to = self::parseDateTime($customTo);
        $invalid = ($customFrom !== null && !$from instanceof DateTimeImmutable)
            || ($customTo !== null && !$to instanceof DateTimeImmutable)
            || ($from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable && $from > $to);

        return [$from, $to, $invalid];
    }

    private static function parseDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
