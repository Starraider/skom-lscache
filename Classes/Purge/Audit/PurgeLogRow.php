<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge\Audit;

use UnexpectedValueException;
use Skom\Lscache\Purge\Enum\PurgeSource;

/**
 * @internal
 */
final readonly class PurgeLogRow
{
    /**
     * @param list<string> $tags
     * @param list<string> $urls
     */
    public function __construct(
        public int $uid,
        public int $crdate,
        public string $siteIdentifier,
        public PurgeSource $source,
        public ?string $triggerRef,
        public array $tags,
        public array $urls,
        public int $tagCount,
        public PurgeLogOutcome $outcome,
        public int $latencyMs,
        public string $requestId,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            (int)($row['uid'] ?? 0),
            (int)($row['crdate'] ?? 0),
            self::normalizeSiteIdentifier($row['site_identifier'] ?? ''),
            PurgeSource::from((string)($row['source'] ?? '')),
            self::normalizeNullableString($row['trigger_ref'] ?? null),
            self::decodeStringList($row['tags'] ?? '[]', 'tags'),
            self::decodeStringList($row['urls'] ?? '[]', 'urls'),
            (int)($row['tag_count'] ?? 0),
            PurgeLogOutcome::from((string)($row['outcome'] ?? '')),
            (int)($row['latency_ms'] ?? 0),
            (string)($row['request_id'] ?? ''),
        );
    }

    private static function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value);

        return $value !== '' ? $value : null;
    }

    private static function normalizeSiteIdentifier(mixed $value): string
    {
        $siteIdentifier = trim((string)$value);

        return $siteIdentifier !== '' ? $siteIdentifier : 'unknown';
    }

    /**
     * @return list<string>
     */
    private static function decodeStringList(mixed $value, string $fieldName): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException(sprintf('Audit log field "%s" must be stored as JSON.', $fieldName), 1744822598);
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new UnexpectedValueException(sprintf('Audit log field "%s" must decode to a list.', $fieldName), 843310405);
        }

        $stringValues = [];
        foreach ($decoded as $index => $item) {
            if (!is_string($item)) {
                throw new UnexpectedValueException(sprintf('Audit log field "%s" item %d must be a string.', $fieldName, $index), 1663679537);
            }
            $stringValues[] = $item;
        }

        return $stringValues;
    }
}
