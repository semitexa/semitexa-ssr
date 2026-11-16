<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Isomorphic;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Environment;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredTemplateRegistry;
use Semitexa\Ssr\Application\Service\Server\Lifecycle\InitializeDeferredTemplateRegistryListener;
use Semitexa\Ssr\Domain\Exception\DeferredRenderingException;

/**
 * tk-template-slot-kills-worker-boot: a deferred template the client cannot
 * render is refused in dev, and that refusal used to leave the worker-start
 * listener, which stops the worker from starting: one slot template took the
 * whole dev server down. The worker now starts, the log says why at boot, and
 * a request that reaches a deferred slot still fails with the same message.
 *
 * The refusal is set up the way the registry records one, so the test needs no
 * module registry: initialize() meets it first and refuses again.
 */
final class RefusedTemplateDoesNotStopTheWorkerTest extends TestCase
{
    private const REGISTRY_STATE = ['publishedPaths', 'initialized', 'refused'];
    private const MESSAGE = 'Deferred template @probe/slot.html.twig uses 1 construct(s) the client renderer does not support';

    /** @var array<string, mixed> */
    private array $registrySnapshot = [];
    private string $previousIsomorphic = '';
    private string $template = '';
    /** @var list<array{string, array<string, mixed>}> */
    private array $errors = [];

    protected function setUp(): void
    {
        foreach (self::REGISTRY_STATE as $property) {
            $this->registrySnapshot[$property] = (new \ReflectionProperty(DeferredTemplateRegistry::class, $property))->getValue();
        }
        $this->previousIsomorphic = (string) getenv('SSR_ISOMORPHIC_ENABLED');
        putenv('SSR_ISOMORPHIC_ENABLED=true');

        $this->template = tempnam(sys_get_temp_dir(), 'refused-slot-') ?: self::fail('no temp file');
        $content = "{% import 'macros.html.twig' as m %}{{ m.badge() }}";
        file_put_contents($this->template, $content);
        (new \ReflectionProperty(DeferredTemplateRegistry::class, 'initialized'))->setValue(null, false);
        (new \ReflectionProperty(DeferredTemplateRegistry::class, 'refused'))->setValue(null, [
            'template' => '@probe/slot.html.twig',
            'path' => $this->template,
            'hash' => hash('sha256', $content),
            'message' => self::MESSAGE,
        ]);

        $errors = &$this->errors;
        StaticLoggerBridge::set(new class ($errors) implements LoggerInterface {
            /** @param list<array{string, array<string, mixed>}> $errors */
            public function __construct(private array &$errors) {}

            public function error(string $message, array $context = []): void
            {
                $this->errors[] = [$message, $context];
            }

            public function critical(string $message, array $context = []): void {}

            public function warning(string $message, array $context = []): void {}

            public function info(string $message, array $context = []): void {}

            public function notice(string $message, array $context = []): void {}

            public function debug(string $message, array $context = []): void {}
        });
    }

    protected function tearDown(): void
    {
        StaticLoggerBridge::reset();
        foreach ($this->registrySnapshot as $property => $value) {
            (new \ReflectionProperty(DeferredTemplateRegistry::class, $property))->setValue(null, $value);
        }
        if ($this->previousIsomorphic === '') {
            putenv('SSR_ISOMORPHIC_ENABLED');
        } else {
            putenv('SSR_ISOMORPHIC_ENABLED=' . $this->previousIsomorphic);
        }
        if ($this->template !== '' && is_file($this->template)) {
            unlink($this->template);
        }
    }

    #[Test]
    public function the_worker_starts_and_the_boot_log_names_the_refusal(): void
    {
        (new InitializeDeferredTemplateRegistryListener())->handle(new ServerLifecycleContext(null, 1, Environment::create()));

        self::assertSame([[
            'A deferred template was refused at boot; the pages that defer to it fail until it is fixed',
            ['_channel' => 'ssr', 'reason' => self::MESSAGE],
        ]], $this->normalised($this->errors));
    }

    #[Test]
    public function a_request_that_reaches_a_deferred_slot_still_fails_with_the_same_message(): void
    {
        (new InitializeDeferredTemplateRegistryListener())->handle(new ServerLifecycleContext(null, 1, Environment::create()));

        $this->expectException(DeferredRenderingException::class);
        $this->expectExceptionMessage(self::MESSAGE);

        DeferredTemplateRegistry::initialize();
    }

    /**
     * The bridge adds the channel to the context; only the keys this test is about.
     *
     * @param list<array{string, array<string, mixed>}> $errors
     * @return list<array{string, array<string, mixed>}>
     */
    private function normalised(array $errors): array
    {
        return array_map(static function (array $entry): array {
            [$message, $context] = $entry;
            ksort($context);

            return [$message, array_intersect_key($context, ['_channel' => true, 'reason' => true])];
        }, $errors);
    }
}
