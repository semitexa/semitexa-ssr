<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Layout;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Layout\PageBodyEndStore;
use Semitexa\Ssr\Application\Service\Layout\PageDocumentFinalizer;
use Semitexa\Ssr\Domain\Contract\PageDocumentContributorInterface;

final class PageBodyEndStoreTest extends TestCase
{
    private const PAGE = '<!DOCTYPE html><html><head><title>t</title></head><body><p>hi</p></body></html>';

    protected function setUp(): void
    {
        PageBodyEndStore::reset();
    }

    protected function tearDown(): void
    {
        PageBodyEndStore::reset();
    }

    #[Test]
    public function it_places_every_fragment_before_the_last_body_close_once(): void
    {
        PageBodyEndStore::bind([self::contributor('<i>a</i>'), self::contributor(''), self::contributor('<i>b</i>')]);

        $once = PageDocumentFinalizer::finalize('<html><body><template></body></template>x</body></html>');

        self::assertStringEndsWith(PageBodyEndStore::MARKER . "\n<i>a</i><i>b</i></body></html>", $once);
        self::assertStringContainsString('<template></body></template>x', $once);
        self::assertSame($once, PageDocumentFinalizer::finalize($once), 'a finalized page is not finalized twice');
    }

    #[Test]
    public function it_leaves_fragments_and_unbound_requests_alone(): void
    {
        self::assertSame(self::PAGE, PageBodyEndStore::inject(self::PAGE), 'nothing bound, nothing added');

        PageBodyEndStore::bind([self::contributor('<i>a</i>')]);
        self::assertSame('<div>partial</div>', PageBodyEndStore::inject('<div>partial</div>'));
    }

    #[Test]
    public function a_failing_contributor_costs_its_fragment_not_the_page(): void
    {
        $broken = new class implements PageDocumentContributorInterface {
            public function bodyEnd(): string
            {
                throw new \RuntimeException('boom');
            }
        };
        PageBodyEndStore::bind([$broken, self::contributor('<i>ok</i>')]);

        self::assertStringContainsString("<i>ok</i></body>", PageBodyEndStore::inject(self::PAGE));
    }

    private static function contributor(string $html): PageDocumentContributorInterface
    {
        return new class ($html) implements PageDocumentContributorInterface {
            public function __construct(private readonly string $html)
            {
            }

            public function bodyEnd(): string
            {
                return $this->html;
            }
        };
    }
}
