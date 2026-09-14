<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Isomorphic;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredTemplateRegistry;
use Semitexa\Ssr\Application\Service\Twig\FrontendTwigCompatibilityIssue;
use Semitexa\Ssr\Domain\Exception\DeferredRenderingException;

/**
 * Publishing a deferred template is the framework saying "the client can render
 * this", so it is where that claim is checked.
 *
 * The failure it guards is silent by construction: a deferred slot is rendered
 * twice — by Twig on the server and by semitexa-twig.js on the client — and the
 * client subset has no functions, no filters beyond |raw and no ternary.
 * Anything outside it renders as an EMPTY STRING with no error, so the
 * divergence is invisible until someone notices missing text.
 *
 * ai:verify already runs lint:deferred-twig when a template changes, which
 * covers anyone working in this workspace. It does not cover a consumer project
 * editing its own template and never running the linter.
 *
 * DEV THROWS, PRODUCTION DOES NOT, and that asymmetry is the decision: a
 * developer wants to be stopped, while an end user should not get a 500 for a
 * template that merely degrades — and a template already in production has
 * already shipped.
 *
 * The code defaults APP_ENV to 'prod' when nothing sets it, and that default is
 * deliberately NOT tested here: Environment reads the workspace .env, which
 * declares dev, so putenv() cannot produce the unset case. A test for it would
 * have to isolate Environment, and would be testing that rather than this.
 */
final class DeferredTemplatePublishGuardTest extends TestCase
{
    private string $previousEnv = '';

    protected function setUp(): void
    {
        $this->previousEnv = (string) getenv('APP_ENV');
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv === '') {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->previousEnv);
        }
    }

    /**
     * @param list<FrontendTwigCompatibilityIssue> $issues
     */
    private function report(array $issues): void
    {
        $method = new \ReflectionMethod(DeferredTemplateRegistry::class, 'reportIncompatibleTemplate');
        $method->invoke(null, 'probe.html.twig', $issues);
    }

    private function issue(): FrontendTwigCompatibilityIssue
    {
        return new FrontendTwigCompatibilityIssue(
            templateName: 'probe.html.twig',
            templatePath: '/tmp/probe.html.twig',
            line: 3,
            construct: 'filter',
            name: 'upper',
            message: 'The client renderer supports no filter but |raw.',
        );
    }

    /** Nothing wrong, nothing said, in either environment. */
    #[Test]
    public function a_supported_template_publishes_quietly(): void
    {
        putenv('APP_ENV=dev');
        $this->report([]);

        $this->expectNotToPerformAssertions();
    }

    /** An unsupported construct stops a developer where it is cheap to fix. */
    #[Test]
    public function an_unsupported_construct_throws_in_dev(): void
    {
        putenv('APP_ENV=dev');

        $this->expectException(DeferredRenderingException::class);
        $this->expectExceptionMessageMatches('/empty string/');
        $this->expectExceptionMessageMatches('/probe\.html\.twig:3 filter "upper"/');

        $this->report([$this->issue()]);
    }

    /**
     * The same issues publish in production rather than 500-ing a visitor: a
     * template already in production has already shipped, and degrading is
     * better than failing.
     */
    #[Test]
    public function the_same_issues_still_publish_in_production(): void
    {
        putenv('APP_ENV=prod');
        $this->report([$this->issue()]);

        $this->expectNotToPerformAssertions();
    }

    /**
     * A checker that cannot run must never stop a page publishing.
     *
     * Detection needs a booted module registry, and whether one exists here
     * depends on what else the suite has run — so this asserts only the
     * absorbing, which is deterministic. That detection WORKS is covered by
     * DeferredTemplateCompatibilityValidatorTest and by lint:deferred-twig;
     * pinning it again through this seam would buy an order-dependent test.
     */
    #[Test]
    public function a_checker_that_cannot_run_does_not_block_publishing(): void
    {
        putenv('APP_ENV=dev');

        $method = new \ReflectionMethod(DeferredTemplateRegistry::class, 'assertClientCanRender');

        try {
            $method->invoke(null, 'probe.html.twig', '/tmp/probe.html.twig', '<div>{{ title }}</div>');
        } catch (DeferredRenderingException $e) {
            self::fail('a supported template must never be refused: ' . $e->getMessage());
        }

        $this->expectNotToPerformAssertions();
    }

    /**
     * BOTH publish paths must call the guard, and this is checked structurally.
     *
     * That is not the first choice, and the reason is measured rather than
     * assumed: driving initialize() or ensurePublishedPath() for real needs a
     * booted module template catalog, and whether a unit test has one depends
     * on what else the suite ran — an earlier attempt at an end-to-end version
     * of this passed only because of test ordering.
     *
     * The failure it guards is exactly the one that already happened once: the
     * guard was added to the wrong method, the suite stayed green, and nothing
     * said a word. A structural assertion is a poor test of behaviour and a
     * good test of "this call is still there", which is the thing that went
     * missing.
     *
     * @param 'initialize'|'publishSlot' $method
     */
    #[Test]
    #[DataProvider('publishPaths')]
    public function every_publish_path_checks_the_template(string $method): void
    {
        $reflected = new \ReflectionMethod(DeferredTemplateRegistry::class, $method);
        $file = (array) file((string) $reflected->getFileName());
        $body = implode('', array_slice(
            $file,
            $reflected->getStartLine() - 1,
            $reflected->getEndLine() - $reflected->getStartLine() + 1,
        ));

        self::assertStringContainsString(
            'self::assertClientCanRender(',
            $body,
            "{$method}() publishes a template without checking the client can render it",
        );
    }

    /** @return array<string, array{string}> */
    public static function publishPaths(): array
    {
        return [
            'boot sweep' => ['initialize'],
            'lazy publish' => ['publishSlot'],
        ];
    }
}
