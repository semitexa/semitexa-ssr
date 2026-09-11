<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Asset;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Asset\AssetCollector;

/**
 * The two halves of the page have to be able to notice each other.
 *
 * `asset()` answers a template with a URL and never touches the collector, so a
 * hand-written `<link rel="stylesheet" href="{{ asset(...) }}">` for a file the
 * manifest also declares scope=global produced TWO links and no complaint.
 * MEASURED on a consumer: platform-ui/css/full.css fetched twice with the same
 * fingerprint on every page load, one of ten render-blocking sheets Lighthouse
 * priced at 1,480 ms of a 2.6 s First Contentful Paint. It went unnoticed for
 * as long as it did because nothing could see both halves at once.
 *
 * These cases pin the seam that lets them: what asset() handed out, what the
 * renderer emitted, and the fact that the answer does not depend on which came
 * first in the template.
 */
final class DuplicateStylesheetWarningTest extends TestCase
{
    private const URL = '/assets/platform-ui/css/full.css?v=221b07146bba';

    #[Test]
    public function the_collector_sees_a_url_a_template_took_by_hand(): void
    {
        $collector = new AssetCollector();

        self::assertFalse($collector->wasHandedOutDirectly(self::URL));

        $collector->noteDirectUrl(self::URL);

        self::assertTrue($collector->wasHandedOutDirectly(self::URL));
    }

    #[Test]
    public function the_collector_sees_a_stylesheet_the_renderer_emitted(): void
    {
        $collector = new AssetCollector();

        self::assertFalse($collector->wasEmittedAsCss(self::URL));

        $collector->noteEmittedCss(self::URL);

        self::assertTrue($collector->wasEmittedAsCss(self::URL));
    }

    /**
     * The fingerprint is dropped before comparing. The same file reached
     * through two paths carries the same one today — a difference would be a
     * SECOND defect, and a comparison that missed the duplicate because of it
     * would hide the first.
     */
    #[Test]
    public function the_same_file_matches_whatever_its_cache_buster_says(): void
    {
        $collector = new AssetCollector();
        $collector->noteDirectUrl('/assets/platform-ui/css/full.css?v=aaaaaaaa');

        self::assertTrue($collector->wasHandedOutDirectly('/assets/platform-ui/css/full.css?v=bbbbbbbb'));
        self::assertTrue($collector->wasHandedOutDirectly('/assets/platform-ui/css/full.css'));
    }

    /** A different file is a different file, fingerprint or no fingerprint. */
    #[Test]
    public function a_different_stylesheet_is_not_mistaken_for_it(): void
    {
        $collector = new AssetCollector();
        $collector->noteDirectUrl(self::URL);

        self::assertFalse($collector->wasHandedOutDirectly('/assets/platform-ui/css/icons.css?v=7ffaf5cabdd4'));
        self::assertFalse($collector->wasHandedOutDirectly('/assets/Identity/css/full.css?v=221b07146bba'));
    }

    /**
     * The two notes are separate registers on purpose: asset() is also how an
     * <img>, a favicon and a font get their URL, so a noted URL is not evidence
     * that a stylesheet was linked. Conflating them would have the renderer
     * warn about an image.
     */
    #[Test]
    public function taking_a_url_by_hand_is_not_the_same_as_linking_a_stylesheet(): void
    {
        $collector = new AssetCollector();
        $collector->noteDirectUrl('/assets/Home/img/favicon.svg?v=1');

        self::assertTrue($collector->wasHandedOutDirectly('/assets/Home/img/favicon.svg?v=1'));
        self::assertFalse($collector->wasEmittedAsCss('/assets/Home/img/favicon.svg?v=1'));
    }

    /**
     * Order-independence is the point. Whichever half runs second is the one
     * that can report, so a template that writes its link BEFORE asset_head()
     * and one that writes it after must both be catchable.
     */
    #[Test]
    public function either_order_leaves_the_other_half_able_to_tell(): void
    {
        $linkFirst = new AssetCollector();
        $linkFirst->noteDirectUrl(self::URL);
        self::assertTrue($linkFirst->wasHandedOutDirectly(self::URL), 'the renderer can tell, running second');

        $headFirst = new AssetCollector();
        $headFirst->noteEmittedCss(self::URL);
        self::assertTrue($headFirst->wasEmittedAsCss(self::URL), 'asset() can tell, running second');
    }
}
