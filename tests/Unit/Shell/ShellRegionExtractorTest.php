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

        // Non-empty first. With no regions extracted, `$extracted` is '' and
        // both assertions below pass — the test would accept losing every
        // region on the page as a success.
        self::assertNotSame('', $extracted, 'the region itself must come back');
        self::assertStringContainsString('page', $extracted, 'and it must be the marked one');
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
    public function aRegionInsideARegionIsNotLost(): void
    {
        // The scan used to resume PAST the region it had just taken, so a
        // marked element nested in another was never visited and the envelope
        // quietly promised fewer regions than the document marked.
        $html = '<main data-shell-region="main"><div data-shell-region="grid">rows</div></main>';

        $regions = $this->extractor->extract($html);

        self::assertSame(
            [
                'main' => '<main data-shell-region="main"><div data-shell-region="grid">rows</div></main>',
                'grid' => '<div data-shell-region="grid">rows</div>',
            ],
            $regions
        );
    }

    #[Test]
    public function aClosingTagInsideAScriptDoesNotEndTheRegion(): void
    {
        // A string in a script is not markup. Counted as a close, the region
        // ended mid-script and the client was handed a fragment that will not
        // parse — with a 200 and no error anywhere.
        $html = '<div data-shell-region="main"><script>var t = "</div>";</script><p>after</p></div>';

        $regions = $this->extractor->extract($html);

        self::assertSame(
            ['main' => '<div data-shell-region="main"><script>var t = "</div>";</script><p>after</p></div>'],
            $regions
        );
    }

    #[Test]
    public function aRegionCommentedOutIsNotARegion(): void
    {
        $html = '<body><!-- <div data-shell-region="old">gone</div> --><main data-shell-region="main">x</main></body>';

        self::assertSame(['main' => '<main data-shell-region="main">x</main>'], $this->extractor->extract($html));
    }

    #[Test]
    public function assetUrlsComeBackAsUrlsAndNotAsSerialisedAttributes(): void
    {
        // The client assigns these to src/href from JavaScript, where nothing
        // un-escapes them: `&amp;` would be requested literally.
        $assets = $this->extractor->assets(
            '<link rel="stylesheet" href="/css/app.css?v=1&amp;theme=dark">'
            . '<script src="/js/app.js?v=1&amp;b=2"></script>'
        );

        self::assertSame('/css/app.css?v=1&theme=dark', $assets['css'][0]['href']);
        self::assertSame('/js/app.js?v=1&b=2', $assets['js'][0]['src']);
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
                'css' => [['href' => '/assets/app.css?v=1', 'attrs' => []]],
                'js' => [['src' => '/assets/app.js?v=2', 'type' => '', 'attrs' => ['defer' => '']]],
            ],
            $this->extractor->assets($html),
        );
    }

    #[Test]
    public function theSameAssetIsListedOnce(): void
    {
        $html = '<script src="/a.js"></script><script src="/a.js"></script>';

        self::assertSame([['src' => '/a.js', 'type' => '', 'attrs' => []]], $this->extractor->assets($html)['js']);
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
                ['src' => '/runtime.js', 'type' => 'module', 'attrs' => []],
                ['src' => '/legacy.js', 'type' => '', 'attrs' => ['defer' => '']],
            ],
            $this->extractor->assets($html)['js'],
        );
    }

    #[Test]
    public function anAssetKeepsTheAttributesThatDecideWhatItIs(): void
    {
        // The client RE-CREATES the tag, so anything the envelope does not
        // name is lost: an integrity hash that was required, a module that
        // must not run for module-aware browsers, a stylesheet meant for
        // print only. The envelope used to carry src and type and nothing
        // else.
        $html = '<link rel="stylesheet" href="/print.css" media="print" integrity="sha384-css">'
            . '<script src="/legacy.js" nomodule integrity="sha384-js" crossorigin="anonymous" async></script>';

        $assets = $this->extractor->assets($html);

        self::assertSame(['media' => 'print', 'integrity' => 'sha384-css'], $assets['css'][0]['attrs']);
        self::assertSame(
            ['integrity' => 'sha384-js', 'crossorigin' => 'anonymous', 'async' => '', 'nomodule' => ''],
            $assets['js'][0]['attrs'],
        );
    }

    #[Test]
    public function theNonceIsNotCarriedForwardWithAnAsset(): void
    {
        // It belongs to the response this document came from. The client
        // stamps the LIVE document's nonce; copying the old one would hand the
        // browser a value its own policy never issued.
        $html = '<script src="/a.js" nonce="from-the-old-response"></script>';

        self::assertSame([], $this->extractor->assets($html)['js'][0]['attrs']);
    }

    #[Test]
    public function aRawTextElementCanCarryARegion(): void
    {
        // The mask used to blank raw-text elements WHOLE, taking their opening
        // tags with them — so an element that marks a region was invisible to
        // the scan and silently absent from the envelope, with no error
        // anywhere to say a marked region had not been shipped. Blanking only
        // the CONTENTS keeps the same guarantee (tag-like text inside is still
        // not a tag) and lets the element be found.
        $html = '<textarea data-shell-region="draft">a </textarea> b</textarea><p>after</p>';

        self::assertSame(
            ['draft' => '<textarea data-shell-region="draft">a </textarea>'],
            $this->extractor->extract($html)
        );
    }

    #[Test]
    public function tagLikeTextInsideAScriptStillDoesNotCloseTheRegion(): void
    {
        // The guarantee the mask exists for, asserted after the change to it:
        // a closing tag SPELLED in a script string is text, and counting it as
        // a close ends the region mid-script and hands the client a fragment
        // that will not parse.
        $html = '<div data-shell-region="main"><textarea>"</div>"</textarea><p>after</p></div>';

        self::assertSame(
            ['main' => '<div data-shell-region="main"><textarea>"</div>"</textarea><p>after</p></div>'],
            $this->extractor->extract($html)
        );
    }

    #[Test]
    public function theDeferredManifestTravelsWithTheEnvelope(): void
    {
        // It sits at body end, outside every marked region, so a swap that
        // carried only regions left the arriving skeletons bound to the
        // previous page's request — waiting for frames that would never come.
        $html = '<main data-shell-region="main">x</main>'
            . '<script type="application/json" data-ssr-deferred-manifest>{"requestId":"r-2"}</script>';

        self::assertSame('{"requestId":"r-2"}', $this->extractor->deferredManifest($html));
        self::assertSame('', $this->extractor->deferredManifest('<main data-shell-region="main">x</main>'));
    }
}
