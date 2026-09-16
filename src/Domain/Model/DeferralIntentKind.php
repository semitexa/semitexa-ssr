<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Model;

/**
 * The two ways a slot's declared intent and its template can disagree.
 *
 * Neither is an error. Rendering a region inline is a legitimate choice, and
 * so is deferring one that has no resource behind it yet. What is not
 * legitimate is not knowing which one you picked — the declaration and the
 * call live in different files, and nothing until now compared them.
 */
enum DeferralIntentKind: string
{
    /**
     * `deferred: true` on the resource, and no template defers the slot.
     *
     * The region renders inline, synchronously, exactly as if the flag were
     * absent. Measured on a real consumer 2026-09-16: all 50 of its slot
     * resources carried the flag and not one page called
     * `layout_slot_deferred`, so every reader of those resources believed the
     * page streamed, and none of them did.
     */
    case DeclaredButNeverDeferred = 'declared_but_never_deferred';

    /**
     * A template defers a slot that no resource declares deferred.
     *
     * `layout_slot_deferred()` silently falls back to rendering the slot
     * inline, so the page works and the skeleton never appears. The author
     * asked for a placeholder and got none.
     */
    case DeferredCallWithoutDeclaration = 'deferred_call_without_declaration';
}
