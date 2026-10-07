<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType;

/**
 * Keyed list streams (ep-platform-live-state · tk-ls-keyed-grid): what a
 * collection subscription was last sent, so a re-run sends only what changed.
 *
 * A write re-runs every feed that watches it, and a re-run used to send the
 * whole page again — every row, however little changed — and the grid rebuilt
 * every row from it. {@see next()} answers a mutation re-run with
 * `ui.collection.patch` instead:
 *
 *     {patch: {key, upsert: [rows], remove: [ids], order: [ids]}, meta?}
 *
 * against the page this subscription last got. The client applies it to that
 * page. A full frame still goes out when there is nothing to diff against (the
 * first frame, a view change, a page whose rows have no key) and when the
 * patch would not be smaller; nothing goes out when nothing changed.
 *
 * Only for a subscription whose client said it applies patches ({@see accept()})
 * and a feed that names its rows' key (`meta.key`), every row distinct by it.
 *
 * Per worker, like the subscription's re-run context it sits beside: the
 * stream is pinned to its owning worker, so every frame of one subscription is
 * diffed here.
 */
final class CollectionFrameDiff
{
    /** Subscriptions remembered per worker; the oldest is forgotten past this. */
    private const CAPACITY = 2048;

    /** @var array<string, array{key: string, rows: array<string, string>, order: list<string>, meta: string}> */
    private array $last = [];

    /** @var array<string, true> subscriptions whose client applies patches */
    private array $accepting = [];

    /**
     * Whether this subscription's client said it applies patches. Only one
     * that did is ever sent one: any other consumer of the same feed (a
     * calendar, a page of its own) keeps getting whole frames.
     */
    public function accept(string $streamingId, bool $accepts): void
    {
        unset($this->last[$streamingId]);
        if ($accepts) {
            $this->accepting[$streamingId] = true;
        } else {
            unset($this->accepting[$streamingId]);
        }
    }

    public function forget(string $streamingId): void
    {
        unset($this->last[$streamingId], $this->accepting[$streamingId]);
    }

    /**
     * The frame to write for this subscription's fresh frame — the frame
     * itself, a patch against the last one, or null when nothing changed.
     *
     * @param array<string, mixed> $frame a framed envelope: `_type` + `{data, meta}`
     * @return array<string, mixed>|null
     */
    public function next(string $streamingId, array $frame, bool $mayPatch): ?array
    {
        if (!isset($this->accepting[$streamingId])) {
            return $frame;
        }
        $page = $this->page($frame);
        if ($page === null) {
            $this->forget($streamingId);

            return $frame;
        }

        $previous = $mayPatch ? ($this->last[$streamingId] ?? null) : null;
        $this->remember($streamingId, $page);
        if ($previous === null || $previous['key'] !== $page['key']) {
            return $frame;
        }

        /** @var list<mixed> $data page() keyed it, so the frame's data is a list */
        $data = $frame['data'];
        $upsert = [];
        foreach ($page['order'] as $index => $id) {
            if (($previous['rows'][$id] ?? null) !== $page['rows'][$id]) {
                $upsert[] = $data[$index];
            }
        }
        $remove = array_values(array_diff($previous['order'], $page['order']));
        $metaChanged = $previous['meta'] !== $page['meta'];
        if ($upsert === [] && $remove === [] && $previous['order'] === $page['order'] && !$metaChanged) {
            return null;
        }

        $patch = ['_type' => UiSseEventType::UiCollectionPatch->value, 'patch' => [
            'key' => $page['key'],
            'upsert' => $upsert,
            'remove' => $remove,
            'order' => $page['order'],
        ]];
        if ($metaChanged) {
            $patch['meta'] = $frame['meta'] ?? [];
        }

        return strlen((string) json_encode($patch)) < strlen((string) json_encode($frame)) ? $patch : $frame;
    }

    /**
     * The page a collection frame carries, keyed — or null when it is not a
     * collection data frame or its rows cannot be keyed.
     *
     * @param array<string, mixed> $frame
     * @return array{key: string, rows: array<string, string>, order: list<string>, meta: string}|null
     */
    private function page(array $frame): ?array
    {
        if (($frame['_type'] ?? null) !== UiSseEventType::UiCollectionData->value || !is_array($frame['data'] ?? null) || !array_is_list($frame['data'])) {
            return null;
        }
        $meta = is_array($frame['meta'] ?? null) ? $frame['meta'] : [];
        if (!is_string($meta['key'] ?? null) || $meta['key'] === '') {
            return null; // the feed names no key: nothing to diff by
        }
        $key = $meta['key'];

        $rows = [];
        $order = [];
        foreach ($frame['data'] as $row) {
            $id = is_array($row) ? ($row[$key] ?? null) : null;
            if (!is_scalar($id) || isset($rows[(string) $id])) {
                return null; // a row without a key, or two with one: nothing to diff by
            }
            $rows[(string) $id] = md5((string) json_encode($row));
            $order[] = (string) $id;
        }

        return ['key' => $key, 'rows' => $rows, 'order' => $order, 'meta' => md5((string) json_encode($meta))];
    }

    /** @param array{key: string, rows: array<string, string>, order: list<string>, meta: string} $page */
    private function remember(string $streamingId, array $page): void
    {
        unset($this->last[$streamingId]);
        if (count($this->last) >= self::CAPACITY) {
            unset($this->accepting[(string) array_key_first($this->last)]);
            array_shift($this->last);
        }
        $this->last[$streamingId] = $page;
    }
}
