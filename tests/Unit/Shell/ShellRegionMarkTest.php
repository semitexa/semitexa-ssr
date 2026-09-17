<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Shell;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Extension\ShellTwigExtension;
use Semitexa\Ssr\Application\Service\Shell\ShellRequest;

/**
 * The one mark a layout makes.
 *
 * `shell_region()` is the entire consumer-facing surface of the app shell, so
 * it has to be the kind of thing that is hard to get wrong: an attribute on
 * the element the layout already has, escaped, and inert when handed nothing.
 */
final class ShellRegionMarkTest extends TestCase
{
    private ShellTwigExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new ShellTwigExtension();
    }

    protected function tearDown(): void
    {
        ShellRequest::forceForTesting(null);
    }

    #[Test]
    public function theMarkIsAnAttributeReadyToSitInsideAnOpeningTag(): void
    {
        self::assertSame(' data-shell-region="main"', $this->extension->region('main'));
    }

    #[Test]
    public function aNameThatTriesToLeaveTheAttributeCannot(): void
    {
        $mark = $this->extension->region('"><script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $mark);
        self::assertStringContainsString('&quot;&gt;', $mark);
    }

    #[Test]
    public function anEmptyNameMarksNothingAtAll(): void
    {
        // A layout that computes its region name and comes up empty must not
        // emit a nameless region: the client would have nothing to key on and
        // the extractor would hand back a region nobody asked for.
        self::assertSame('', $this->extension->region(''));
        self::assertSame('', $this->extension->region('   '));
    }

    #[Test]
    public function aTemplateCanAskWhichShapeIsBeingRendered(): void
    {
        ShellRequest::forceForTesting(true);
        self::assertTrue($this->extension->isShellRequest());

        ShellRequest::forceForTesting(false);
        self::assertFalse($this->extension->isShellRequest());
    }

    #[Test]
    public function withNoRequestAtAllItIsNotAShellRequest(): void
    {
        // CLI renders, tests, a warm-up call: no request means the document
        // shape, which is the one that is always correct.
        ShellRequest::forceForTesting(null);

        self::assertFalse(ShellRequest::isShellRequest());
    }
}
