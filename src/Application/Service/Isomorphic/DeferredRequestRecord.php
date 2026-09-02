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
     * @param list<array<string, mixed>> $components
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
        public ?int $createdAt,
    ) {
    }

    /**
     * Decode one raw table row.
     *
     * Content columns are absent-tolerant on purpose: a Swoole Table row read during a
     * concurrent write can come back partially populated, and a deferred render that
     * degrades to an empty slot list is far better than one that fatals mid-response.
     *
     * ⚠️ `created_at` is NOT in that category, and the old tolerance there was inherited
     * rather than chosen: an unreadable value became 0, {@see isExpired()} read that as long
     * expired, and {@see DeferredRequestRegistry::consume()} then DELETED the row - so a
     * transient partial read destroyed a live deferred request instead of degrading it.
     * An unknown creation time is now null and {@see isExpired()} refuses to judge it, so
     * the row survives to be read again. A genuinely stale row still expires on the next
     * read that can see its timestamp.
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
            components:      self::jsonRecordList($row, 'components'),
            delivered:       self::jsonStringList($row, 'delivered'),
            requestSnapshot: self::jsonMapOrNull($row, 'request_snapshot'),
            createdAt:       is_numeric($row['created_at'] ?? null) ? (int) $row['created_at'] : null,
        );
    }

    /**
     * False when the creation time is unknown, deliberately. The caller deletes on true, so
     * answering "expired" for a row we simply could not read would turn an unlucky read into
     * data loss.
     */
    public function isExpired(int $now, int $ttlSeconds): bool
    {
        if ($this->createdAt === null) {
            return false;
        }

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
     * Component instances arrive as a LIST of records, not a map - callers index them by
     * position and read instance_id/name/props off each. Declaring it as a map made PHPStan
     * reject `components[0]` in the tests that had been reading it correctly all along.
     *
     * @param array<string, mixed> $row
     *
     * @return list<array<string, mixed>>
     */
    private static function jsonRecordList(array $row, string $column): array
    {
        $decoded = self::jsonMapOrNull($row, $column);
        if ($decoded === null) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
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
        if (!is_array($decoded)) {
            return null;
        }
        // json_decode gives array<mixed, mixed>; JSON object keys are always strings.
        /** @var array<string, mixed> $decoded */

        return $decoded;
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
