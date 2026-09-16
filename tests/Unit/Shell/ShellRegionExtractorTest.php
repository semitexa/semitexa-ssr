<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Shell;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Shell\ShellRegionExtractor;

/**
 * Reading the per-page regions back out of a finished document.
 *
 * This is the half that makes "one renderer, two shapes" true rather than
 * aspirational: if extraction is wrong the fragment and the document disagree,
 * and they disagree in the shape people exercise LESS — which is the one that
 * gets debugged last.
 */
final class ShellRegionExtractorTest extends TestCase
{
    private ShellRegionExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new ShellRegionExtractor();
    }

    #[Test]
    public function aRegionComesBackWithItsOwnElement(): void
    {
        $html = '<body><nav>chrome</nav><main data-shell-region="main"><h1>Page</h1></main></body>';

        $regions = $this->extractor->extract($html);

        self::assertSame(['main' => '<main data-shell-region="main"><h1>Page</h1></main>'], $regions);
    }

    #[Test]
    public function theChromeIsWhatIsLeftBehind(): void
    {
        // The measured point of the whole mechanism: on the console this came
        // from, 93% of every navigation was the same sidebar being sent to
        // replace itself.
        $html = '<body><nav>' . str_repeat('sidebar ', 100) . '</nav><main data-shell-region="main">page</main></body>';

        $extracted = implode('', $this->extractor->extract($html));

        self::assertStringNotContainsString('sidebar', $extracted);
        self::assertLessThan(strlen($html) / 4, strlen($extracted));
    }

    #[Test]
    public function nestedElementsOfTheSameTagDoNotEndTheRegionEarly(): void
    {
        // A regex closes the region at the first </div>. The page then arrives
        // truncated, and only on pages deep enough to nest — which is every
        // real page and no test fixture.
        $html = '<div data-shell-region="main"><div class="card"><div>deep</div></div>tail</div><footer>chrome</footer>';

        $regions = $this->extractor->extract($html);

        self::assertSame(
            '<div data-shell-region="main"><div class="card"><div>deep</div></div>tail</div>',
            $regions['main'],
        );
    }

    #[Test]
    public function severalRegionsComeBackKeyedByName(): void
    {
        $html = '<header data-shell-region="head">H</header><main data-shell-region="main">M</main>';

        self::assertSame(['head', 'main'], array_keys($this->extractor->extract($html)));
    }

    #[Test]
    public function aTagWhoseNameMerelyStartsTheSameIsNotNesting(): void
    {
        // `<sectionish>` is not `<section>`. Counting it as a nested open
        // leaves the region unbalanced and the whole page unswappable.
        $html = '<section data-shell-region="main"><sectionish>x</sectionish></section><p>after</p>';

        $regions = $this->extractor->extract($html);

        self::assertSame('<section data-shell-region="main"><sectionish>x</sectionish></section>', $regions['main']);
    }

    #[Test]
    public function anUnbalancedRegionIsSkippedRatherThanShipped(): void
    {
        // Half an element inserted into a live document corrupts everything
        // after it. Dropping the region gives the client nothing to apply,
        // which makes the navigation fall back to the browser — visibly wrong
        // beats invisibly wrong.
        $html = '<div data-shell-region="broken"><span>no closer';

        self::assertSame([], $this->extractor->extract($html));
    }

    #[Test]
    public function aDocumentWithNoRegionsYieldsNothing(): void
    {
        self::assertSame([], $this->extractor->extract('<html><body><p>plain page</p></body></html>'));
    }

    #[Test]
    public function theTitleComesBackDecoded(): void
    {
        $html = '<html><head><title>Orders &amp; invoices</title></head><body></body></html>';

        self::assertSame('Orders & invoices', $this->extractor->title($html));
    }

    #[Test]
    public function aPageWithNoTitleReportsAnEmptyOne(): void
    {
        self::assertSame('', $this->extractor->title('<html><body></body></html>'));
    }

    #[Test]
    public function linkedAssetsAreListedAndInlineOnesAreNot(): void
    {
        $html = <<<'HTML'
        <link rel="stylesheet" href="/assets/app.css?v=1">
        <link rel="preload" href="/assets/font.woff2" as="font">
        <style>.x{color:red}</style>
        <script src="/assets/app.js?v=2" defer></script>
        <script type="application/json" data-ssr-deferred-manifest>{}</script>
        HTML;

        self::assertSame(
            [
                'css' => ['/assets/app.css?v=1'],
                'js' => [['src' => '/assets/app.js?v=2', 'type' => '']],
            ],
            $this->extractor->assets($html),
        );
    }

    #[Test]
    public function theSameAssetIsListedOnce(): void
    {
        $html = '<script src="/a.js"></script><script src="/a.js"></script>';

        self::assertSame([['src' => '/a.js', 'type' => '']], $this->extractor->assets($html)['js']);
    }

    #[Test]
    public function aScriptCarriesTheTypeTheServerGaveIt(): void
    {
        // A module added as a classic script breaks the first time it imports
        // anything, and a classic script added as a module changes scope and
        // timing. The URL says nothing about which it is, so the server does.
        $html = '<script src="/runtime.js" type="module"></script><script src="/legacy.js" defer></script>';

        self::assertSame(
            [
                ['src' => '/runtime.js', 'type' => 'module'],
                ['src' => '/legacy.js', 'type' => ''],
            ],
            $this->extractor->assets($html)['js'],
        );
    }
}
