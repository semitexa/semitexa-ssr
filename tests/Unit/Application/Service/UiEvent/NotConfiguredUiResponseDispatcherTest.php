<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Service\UiEvent;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Ssr\Application\Service\UiEvent\NotConfiguredUiResponseDispatcher;
use Semitexa\Ssr\Application\Service\UiEvent\UiEventEnvelope;
use Semitexa\Ssr\Application\Service\UiEvent\UiResponseDispatcherInterface;
use Semitexa\Ssr\Application\Service\UiEvent\UiResponseDispatchResult;

/**
 * Pins the framework-default dispatcher's contract:
 *   - implements UiResponseDispatcherInterface
 *   - bound via #[SatisfiesServiceContract] so the registry picks it up
 *     as the framework baseline (downstream packages still win via
 *     module-extends ordering)
 *   - returns a stable "no concrete dispatcher" envelope
 *   - never throws, never inspects the envelope/claims for side effects
 */
final class NotConfiguredUiResponseDispatcherTest extends TestCase
{
    private function envelope(): UiEventEnvelope
    {
        return new UiEventEnvelope(
            schemaVersion: UiEventEnvelope::SCHEMA_VERSION,
            eventId: 'evt_test',
            correlationId: 'corr_test',
            semanticEvent: 'click',
            signedContext: 'opaque-blob',
            timestamp: '2026-05-18T00:00:00Z',
        );
    }

    #[Test]
    public function implements_the_canonical_dispatcher_interface(): void
    {
        self::assertInstanceOf(
            UiResponseDispatcherInterface::class,
            new NotConfiguredUiResponseDispatcher(),
        );
    }

    #[Test]
    public function is_registered_as_the_default_service_contract_binding(): void
    {
        $attrs = (new \ReflectionClass(NotConfiguredUiResponseDispatcher::class))
            ->getAttributes(SatisfiesServiceContract::class);
        self::assertNotEmpty(
            $attrs,
            'NotConfiguredUiResponseDispatcher must carry #[SatisfiesServiceContract] so the framework registry binds it as the default.',
        );
        /** @var SatisfiesServiceContract $contract */
        $contract = $attrs[0]->newInstance();
        self::assertSame(
            UiResponseDispatcherInterface::class,
            ltrim($contract->of, '\\'),
            'The contract must satisfy exactly UiResponseDispatcherInterface.',
        );
    }

    #[Test]
    public function returns_the_canonical_not_configured_result(): void
    {
        $result = (new NotConfiguredUiResponseDispatcher())
            ->dispatch($this->envelope(), ['some' => 'claim']);

        self::assertSame(202, $result->statusCode);
        self::assertSame('accepted', $result->status);
        self::assertSame('foundation', $result->phase);
        self::assertSame('dispatcher_not_configured', $result->reason);
        self::assertSame(
            'UI event endpoint is active, but no UI response dispatcher is installed.',
            $result->message,
        );
        self::assertSame([], $result->body, 'Default dispatcher must surface no free-form body fields.');
    }

    #[Test]
    public function not_configured_factory_matches_dispatcher_output(): void
    {
        // Both the dispatcher's runtime result and the named-constructor
        // helper must be byte-identical — downstream consumers detect
        // "no dispatcher installed" by reason code, and the two paths
        // (default binding vs. an explicit factory call) MUST NOT
        // diverge.
        $fromDispatcher = (new NotConfiguredUiResponseDispatcher())
            ->dispatch($this->envelope(), []);
        $fromFactory = UiResponseDispatchResult::notConfigured();

        self::assertEquals($fromFactory, $fromDispatcher);
    }
}
