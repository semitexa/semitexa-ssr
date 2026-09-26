<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Isomorphic;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredTemplateRegistry;
use Semitexa\Ssr\Application\Service\Twig\FrontendTwigCompatibilityIssue;
use Semitexa\Ssr\Configuration\IsomorphicConfig;
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
    private const REGISTRY_STATE = ['publishedPaths', 'initialized', 'refused'];

    private string $previousEnv = '';

    /** @var array<string, mixed> the registry as the previous test left it */
    private array $registrySnapshot = [];

    protected function setUp(): void
    {
        $this->previousEnv = (string) getenv('APP_ENV');
        foreach (self::REGISTRY_STATE as $property) {
            $this->registrySnapshot[$property] = (new \ReflectionProperty(DeferredTemplateRegistry::class, $property))->getValue();
        }
    }

    protected function tearDown(): void
    {
        // Restore, not reset(): a registry some earlier test initialised must
        // look the same to later tests whether or not this one ran.
        foreach ($this->registrySnapshot as $property => $value) {
            (new \ReflectionProperty(DeferredTemplateRegistry::class, $property))->setValue(null, $value);
        }
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
     * A refusal is remembered while the refused file is unchanged.
     *
     * initialize() throws before it marks itself initialized, so every HTML
     * request reaching a deferred slot called it again: re-reading every
     * deferred template and re-parsing the bad one, which Twig retains —
     * MEASURED at ~10 KB of worker memory per request. The request must
     * still fail with the same message, just without redoing the work.
     */
    #[Test]
    public function a_refused_template_fails_again_without_being_rechecked(): void
    {
        $file = $this->refusedTemplateFile();

        try {
            DeferredTemplateRegistry::initialize(new IsomorphicConfig(enabled: true));
            self::fail('an unchanged refused template must keep failing');
        } catch (DeferredRenderingException $e) {
            self::assertSame('Deferred template probe.html.twig uses 1 construct(s)', $e->getMessage());
        } finally {
            @unlink($file);
        }

        self::assertFalse(DeferredTemplateRegistry::isInitialized());
    }

    /** Fixing the template is enough: once the file changes it is checked again. */
    #[Test]
    public function an_edited_refused_template_is_checked_again(): void
    {
        $file = $this->refusedTemplateFile();
        file_put_contents($file, '<div>{{ title }}</div>');
        touch($file, 1_700_000_001);

        $rethrow = new \ReflectionMethod(DeferredTemplateRegistry::class, 'rethrowIfStillRefused');
        try {
            $rethrow->invoke(null);
        } finally {
            @unlink($file);
        }

        self::assertNull((new \ReflectionProperty(DeferredTemplateRegistry::class, 'refused'))->getValue());
    }

    /**
     * A same-size fix inside one mtime tick is still a fix: the refusal is
     * keyed by content, so stat() metadata alone cannot keep it failing.
     */
    #[Test]
    public function a_same_size_same_mtime_fix_is_checked_again(): void
    {
        $file = $this->refusedTemplateFile();
        $fixed = str_pad('{{ title }}', (int) filesize($file), ' ');
        file_put_contents($file, $fixed);
        touch($file, 1_700_000_000);
        clearstatcache(true, $file);
        self::assertSame(1_700_000_000, filemtime($file));
        self::assertSame(strlen("{{ trans('x') }}"), filesize($file));

        $rethrow = new \ReflectionMethod(DeferredTemplateRegistry::class, 'rethrowIfStillRefused');
        try {
            $rethrow->invoke(null);
        } finally {
            @unlink($file);
        }

        self::assertNull((new \ReflectionProperty(DeferredTemplateRegistry::class, 'refused'))->getValue());
    }

    /**
     * A refused template that still exists but cannot be read stays refused:
     * initialize() skips an unreadable file, so clearing the refusal would let
     * it succeed without the template ever being checked. The test runs as
     * root, where chmod does not stop a read, so a stream wrapper stands in
     * for a file that stats as regular yet refuses to open.
     */
    #[Test]
    public function an_unreadable_refused_template_stays_refused(): void
    {
        $unreadable = new class {
            /** @var resource|null set by PHP for every stream wrapper instance */
            public $context;

            public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
            {
                return false;
            }

            /** @return array<string, int> */
            public function url_stat(string $path, int $flags): array
            {
                return ['mode' => 0100000, 'size' => 16];
            }
        };
        stream_wrapper_register('semitexaunreadable', $unreadable::class);
        (new \ReflectionMethod(DeferredTemplateRegistry::class, 'rememberRefusal'))->invoke(
            null,
            'probe.html.twig',
            'semitexaunreadable://probe.html.twig',
            "{{ trans('x') }}",
            new DeferredRenderingException('Deferred template probe.html.twig uses 1 construct(s)'),
        );

        $rethrow = new \ReflectionMethod(DeferredTemplateRegistry::class, 'rethrowIfStillRefused');
        try {
            $rethrow->invoke(null);
            self::fail('an unreadable refused template must keep failing');
        } catch (DeferredRenderingException $e) {
            self::assertSame('Deferred template probe.html.twig uses 1 construct(s)', $e->getMessage());
        } finally {
            stream_wrapper_unregister('semitexaunreadable');
        }

        self::assertNotNull((new \ReflectionProperty(DeferredTemplateRegistry::class, 'refused'))->getValue());
    }

    /**
     * A refused template whose status cannot be read at all (url_stat fails,
     * as when a parent directory loses search permission) is not a deleted
     * one: is_file() is false either way, so only a directory listing that
     * lacks the file may clear the refusal.
     */
    #[Test]
    public function a_refused_template_whose_status_cannot_be_read_stays_refused(): void
    {
        $unstatable = new class {
            /** @var resource|null set by PHP for every stream wrapper instance */
            public $context;

            public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
            {
                return false;
            }

            public function url_stat(string $path, int $flags): false
            {
                return false;
            }
        };
        stream_wrapper_register('semitexaunstatable', $unstatable::class);
        (new \ReflectionMethod(DeferredTemplateRegistry::class, 'rememberRefusal'))->invoke(
            null,
            'probe.html.twig',
            'semitexaunstatable://dir/probe.html.twig',
            "{{ trans('x') }}",
            new DeferredRenderingException('Deferred template probe.html.twig uses 1 construct(s)'),
        );

        $rethrow = new \ReflectionMethod(DeferredTemplateRegistry::class, 'rethrowIfStillRefused');
        try {
            $rethrow->invoke(null);
            self::fail('a refused template of unknown status must keep failing');
        } catch (DeferredRenderingException $e) {
            self::assertSame('Deferred template probe.html.twig uses 1 construct(s)', $e->getMessage());
        } finally {
            stream_wrapper_unregister('semitexaunstatable');
        }

        self::assertNotNull((new \ReflectionProperty(DeferredTemplateRegistry::class, 'refused'))->getValue());
    }

    /** A deleted refused template clears the refusal: initialize() resolves it afresh. */
    #[Test]
    public function a_deleted_refused_template_is_forgotten(): void
    {
        $file = $this->refusedTemplateFile();
        unlink($file);

        (new \ReflectionMethod(DeferredTemplateRegistry::class, 'rethrowIfStillRefused'))->invoke(null);

        self::assertNull((new \ReflectionProperty(DeferredTemplateRegistry::class, 'refused'))->getValue());
    }

    /** The boot sweep must be the one that records the refusal. */
    #[Test]
    public function the_boot_sweep_remembers_what_it_refused(): void
    {
        $reflected = new \ReflectionMethod(DeferredTemplateRegistry::class, 'initialize');
        $file = (array) file((string) $reflected->getFileName());
        $body = implode('', array_slice(
            $file,
            $reflected->getStartLine() - 1,
            $reflected->getEndLine() - $reflected->getStartLine() + 1,
        ));

        self::assertStringContainsString('self::rethrowIfStillRefused()', $body);
        self::assertStringContainsString('self::rememberRefusal(', $body);
    }

    /** Records a refusal of a real file, as initialize() would have. */
    private function refusedTemplateFile(): string
    {
        $file = sys_get_temp_dir() . '/semitexa-refused-' . uniqid('', true) . '.twig';
        $content = "{{ trans('x') }}";
        file_put_contents($file, $content);
        touch($file, 1_700_000_000);
        clearstatcache(true, $file);

        (new \ReflectionMethod(DeferredTemplateRegistry::class, 'rememberRefusal'))->invoke(
            null,
            'probe.html.twig',
            $file,
            $content,
            new DeferredRenderingException('Deferred template probe.html.twig uses 1 construct(s)'),
        );

        return $file;
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

