<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Isomorphic;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredInlineWarning;

/**
 * A page whose deferred context never fits said so on every request: the demo
 * home page, about 300 warnings a second under dev:bench. Once per handle now.
 */
final class DeferredInlineWarningTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    private mixed $previousLogger = null;

    protected function setUp(): void
    {
        DeferredInlineWarning::reset();
        $this->previousLogger = (new \ReflectionProperty(StaticLoggerBridge::class, 'logger'))->getValue();
        $lines = &$this->lines;
        StaticLoggerBridge::set(new class ($lines) implements LoggerInterface {
            /** @param list<string> $lines */
            public function __construct(private array &$lines)
            {
            }

            public function error(string $message, array $context = []): void
            {
            }

            public function critical(string $message, array $context = []): void
            {
            }

            public function warning(string $message, array $context = []): void
            {
                $this->lines[] = (string) ($context['handle'] ?? '');
            }

            public function info(string $message, array $context = []): void
            {
            }

            public function notice(string $message, array $context = []): void
            {
            }

            public function debug(string $message, array $context = []): void
            {
            }
        });
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(StaticLoggerBridge::class, 'logger'))->setValue(null, $this->previousLogger);
        DeferredInlineWarning::reset();
    }

    #[Test]
    public function a_page_that_never_fits_is_reported_once_not_on_every_request(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            DeferredInlineWarning::once('demo_home');
        }
        DeferredInlineWarning::once('demo_blog');

        self::assertSame(['demo_home', 'demo_blog'], $this->lines);
    }
}
