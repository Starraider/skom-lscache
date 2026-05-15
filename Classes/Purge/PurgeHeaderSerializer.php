<?php

declare(strict_types=1);

namespace Skom\Lscache\Purge;

final readonly class PurgeHeaderSerializer
{
    private const TAG_CAP = 64;
    private const MAX_HEADER_VALUE_BYTES = 3500;

    /**
     * @return array{headers: list<string>, tagCount: int, outcome: 'flushed'|'truncated'|'skipped'}
     */
    public function serialize(PurgePayload $payload, callable $namespaceResolver): array
    {
        $tags = $payload->tags();
        $outcome = 'flushed';

        if (count($tags) > self::TAG_CAP) {
            $tags = array_slice($tags, 0, self::TAG_CAP);
            $outcome = 'truncated';
        }

        $tokens = [];
        foreach ($tags as $tag) {
            $tokens[] = 'tag=' . $tag;
        }

        foreach ($payload->fullSiteFlushes() as $siteIdentifier) {
            if ($siteIdentifier === '*') {
                $tokens[] = '*';
                continue;
            }

            $namespace = trim((string)$namespaceResolver($siteIdentifier));
            if ($namespace === '') {
                continue;
            }

            $tokens[] = 'tag=' . $namespace . '_*';
        }

        foreach ($payload->urls() as $url) {
            $tokens[] = 'url=' . $url;
        }

        if ($tokens === []) {
            return [
                'headers' => [],
                'tagCount' => count($tags),
                'outcome' => 'skipped',
            ];
        }

        $headers = [];
        $current = '';
        foreach ($tokens as $token) {
            if (strlen($token) > self::MAX_HEADER_VALUE_BYTES) {
                $outcome = 'truncated';
                continue;
            }

            if ($current === '') {
                $current = $token;
                continue;
            }

            $candidate = $current . ',' . $token;
            if (strlen($candidate) > self::MAX_HEADER_VALUE_BYTES) {
                $headers[] = $current;
                $current = $token;
                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $headers[] = $current;
        }

        return [
            'headers' => $headers,
            'tagCount' => count($tags),
            'outcome' => $outcome,
        ];
    }
}
