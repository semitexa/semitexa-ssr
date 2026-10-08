<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\CollectionFrameDiff;

/**
 * tk-ls-keyed-grid: a mutation re-run is sent as what changed in the page the
 * subscription was last sent — only to a client that applies patches, only
 * for a feed that names its key — and not at all when nothing changed.
 */
final class CollectionFrameDiffTest extends TestCase
{
    /** @param list<array<string, mixed>> $rows */
    private static function frame(array $rows, array $meta = []): array
    {
        return ['_type' => 'ui.collection.data', 'data' => $rows, 'meta' => $meta + ['key' => 'id', 'pagination' => ['page' => 1]]];
    }

    private static function rows(): array
    {
        return [['id' => 'a', 'name' => 'Apple'], ['id' => 'b', 'name' => 'Banana'], ['id' => 'c', 'name' => 'Cherry']];
    }

    #[Test]
    public function a_re_run_is_sent_as_the_rows_that_changed(): void
    {
        $diff = new CollectionFrameDiff();
        $diff->accept('s1', true);
        self::assertSame(self::frame(self::rows()), $diff->next('s1', self::frame(self::rows()), false), 'the first frame is whole');

        $rows = self::rows();
        $rows[1]['name'] = 'Blueberry';
        unset($rows[2]);
        $rows[] = ['id' => 'd', 'name' => 'Date'];
        $out = $diff->next('s1', self::frame(array_values($rows)), true);

        self::assertSame('ui.collection.patch', $out['_type']);
        self::assertSame(['key' => 'id', 'upsert' => [['id' => 'b', 'name' => 'Blueberry'], ['id' => 'd', 'name' => 'Date']], 'remove' => ['c'], 'order' => ['a', 'b', 'd']], $out['patch']);
        self::assertArrayNotHasKey('meta', $out, 'unchanged meta is not sent again');

        self::assertNull($diff->next('s1', self::frame(array_values($rows)), true), 'nothing changed: nothing is sent');

        $out = $diff->next('s1', self::frame(array_values($rows), ['pagination' => ['page' => 1, 'total' => 3]]), true);
        self::assertSame(['pagination' => ['page' => 1, 'total' => 3], 'key' => 'id'], $out['meta']);
        self::assertSame([], $out['patch']['upsert']);
    }

    #[Test]
    public function a_view_change_or_a_client_that_did_not_ask_gets_the_whole_frame(): void
    {
        $diff = new CollectionFrameDiff();
        $diff->accept('s1', true);
        $diff->next('s1', self::frame(self::rows()), false);
        $changed = self::frame([['id' => 'a', 'name' => 'Avocado'], ...array_slice(self::rows(), 1)]);
        self::assertSame($changed, $diff->next('s1', $changed, false), 'a new view is a new page');

        $diff->accept('s2', false);
        $diff->next('s2', self::frame(self::rows()), false);
        self::assertSame($changed, $diff->next('s2', $changed, true), 'a calendar or a page of its own keeps whole frames');

        $diff->forget('s1');
        self::assertSame($changed, $diff->next('s1', $changed, true), 'forgotten: no longer patched');
    }

    #[Test]
    public function rows_that_cannot_be_keyed_are_sent_whole(): void
    {
        $diff = new CollectionFrameDiff();
        $diff->accept('s1', true);
        $unkeyed = ['_type' => 'ui.collection.data', 'data' => self::rows(), 'meta' => []];
        $diff->next('s1', $unkeyed, false);
        self::assertSame($unkeyed, $diff->next('s1', $unkeyed, true), 'the feed names no key');

        $twins = self::frame([['id' => 'a'], ['id' => 'a']]);
        $diff->next('s1', $twins, false);
        self::assertSame($twins, $diff->next('s1', $twins, true), 'two rows under one key');

        $error = ['_type' => 'ui.collection.error', 'error' => 'x'];
        self::assertSame($error, $diff->next('s1', $error, true));
    }

    #[Test]
    public function a_patch_that_would_not_be_smaller_is_not_sent(): void
    {
        $diff = new CollectionFrameDiff();
        $diff->accept('s1', true);
        $diff->next('s1', self::frame([['id' => 'a', 'n' => 1]]), false);
        $whole = self::frame([['id' => 'a', 'n' => 2]]);

        self::assertSame($whole, $diff->next('s1', $whole, true), 'one row changed out of one: the frame itself is smaller');
    }
}
