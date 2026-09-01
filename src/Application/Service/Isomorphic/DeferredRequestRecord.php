<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Isomorphic;

/**
 * One deferred-request entry, decoded from its {@see \Swoole\Table} row.
 *
 * The row is nine string/int columns, four of which hold JSON. Every reader used to redo
 * that decoding inline — `json_decode((string) $row['slots'], true) ?: []` — against values
 * the table hands back as `mixed`, so each field cost a cast PHPStan could not verify and
 * each caller was free to disagree with the next about what a missing column means.
 *
 * The shape was also declared twice by hand, in a `@var` above the read and a `@return` on
 * {@see DeferredRequestRegistry::consume()}, and both had drifted: they listed seven of the
 * nine columns, omitting `components` and `request_snapshot`. Nothing broke at runtime —
 * the columns exist in {@see DeferredRequestRegistry::createSharedTable()} — but static
 * analysis believed those reads were impossible and reported their guards as dead code,
 * which is how a stale hand-written shape hides a real one when it finally appears.
 *
 * So: one class, one decode, and the shape stated once by the constructor rather than
 * three times in prose.
 */
final readonly class DeferredRequestRecord
{
    /**
     * @param array<string, mixed>      $pageContext
     * @param array<string>             $slots
     * @param array<string, mixed>      $components
     * @param array<string>             $delivered
     * @param array<string, mixed>|null $requestSnapshot
     */
    public function __construct(
        public string $pageHandle,
        public array $pageContext,
        public string $bindToken,
        public string $locale,
        public array $slots,
        public array $components,
        public array $delivered,
        public ?array $requestSnapshot,
        public int $createdAt,
    ) {
    }

    /**
     * Decode one raw table row.
     *
     * Every column is treated as absent-tolerant on purpose: a Swoole Table row read during
     * a concurrent write can come back partially populated, and a deferred render that
     * degrades to an empty slot list is far better than one that fatals mid-response.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            pageHandle:      trim(self::str($row, 'page_handle')),
            pageContext:     self::jsonMap($row, 'page_context'),
            bindToken:       trim(self::str($row, 'bind_token')),
            locale:          trim(self::str($row, 'locale')),
            slots:           self::jsonStringList($row, 'slots'),
            components:      self::jsonMap($row, 'components'),
            delivered:       self::jsonStringList($row, 'delivered'),
            requestSnapshot: self::jsonMapOrNull($row, 'request_snapshot'),
            createdAt:       is_numeric($row['created_at'] ?? null) ? (int) $row['created_at'] : 0,
        );
    }

    public function isExpired(int $now, int $ttlSeconds): bool
    {
        return ($now - $this->createdAt) > $ttlSeconds;
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $column): string
    {
        $value = $row[$column] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function jsonMap(array $row, string $column): array
    {
        return self::jsonMapOrNull($row, $column) ?? [];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private static function jsonMapOrNull(array $row, string $column): ?array
    {
        $raw = self::str($row, $column);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string>
     */
    private static function jsonStringList(array $row, string $column): array
    {
        $raw = self::str($row, $column);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
