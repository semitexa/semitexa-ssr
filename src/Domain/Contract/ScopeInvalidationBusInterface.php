<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

/**
 * Publisher-side seam for the Track R cross-instance push origin (P3).
 *
 * A single, narrow capability: publish a DATA-LESS scope-invalidation signal
 * to a fully-qualified channel (`ui.invalidate.{tenant}.{scopeKey}`). The
 * channel name encodes everything the subscriber (R3) needs; there is no
 * payload, no row data, no fan-out, and — by design — NO subscribe surface
 * here (the blocking SUBSCRIBE / reverse-index lookup is R3, not P3).
 *
 * Exists as a contract so the publisher ({@see \Semitexa\Ssr\Application\Service\Async\ResourceInvalidationPublisher})
 * can be exercised with a capturing test double without a live Redis.
 */
interface ScopeInvalidationBusInterface
{
    /**
     * Publish a data-less invalidation signal on the given channel.
     * Idempotent and lossy-tolerant: N duplicate signals cause N harmless
     * re-queries; a dropped signal is repaired by the next mutation's signal.
     */
    public function publish(string $channel): void;
}
