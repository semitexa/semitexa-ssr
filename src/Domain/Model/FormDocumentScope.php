<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Model;

/**
 * Collaborative Form Data · Phase 1 — the canonical invalidation-scope-key
 * formatter for a single collaborative document.
 *
 * It is the document-feed counterpart of a grid's ORM `#[ResourceKey]`: the
 * stable string that names the `ui.invalidate.{tenant}.{scope}` channel every
 * editor of one document subscribes to (via the feed payload's dynamic watch
 * scopes — {@see \Semitexa\Ssr\Domain\Contract\DynamicallyScopedFeedInterface})
 * and the inbound collaboration handler touches on every field edit / lock /
 * presence change. Both sides MUST format the key identically, so the format
 * lives here ONCE.
 *
 * Two shapes:
 *   - `formdoc:{formKey}:{recordId}` — editing an EXISTING record (the common
 *     case; `formKey` is the stable form identity, `recordId` the row id).
 *   - `form:{instanceId}` — a not-yet-persisted draft (create-new), keyed by an
 *     ephemeral instance id so concurrent "new record" sessions stay isolated.
 *
 * The segments are constrained to a safe alphabet (lower-snake/kebab form key;
 * id alphabet `[A-Za-z0-9][A-Za-z0-9_-]*`, which UUIDv7 and slugs satisfy) and
 * carry NO `.` — the channel delimiter — so a scope can never be mistaken for
 * a tenant boundary in `ResourceInvalidationSubscriber::parseChannel()`.
 */
final class FormDocumentScope
{
    public const RECORD_PREFIX = 'formdoc';
    public const DRAFT_PREFIX  = 'form';

    private const FORM_KEY_PATTERN = '/^[a-z][a-z0-9_-]*$/';
    private const ID_PATTERN       = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/';

    /** The scope key for an existing record: `formdoc:{formKey}:{recordId}`. */
    public static function forRecord(string $formKey, string $recordId): string
    {
        self::assertFormKey($formKey);
        self::assertId($recordId, 'recordId');

        return self::RECORD_PREFIX . ':' . $formKey . ':' . $recordId;
    }

    /** The scope key for a not-yet-persisted draft: `form:{instanceId}`. */
    public static function forDraft(string $instanceId): string
    {
        self::assertId($instanceId, 'instanceId');

        return self::DRAFT_PREFIX . ':' . $instanceId;
    }

    /** Is this a well-formed collaborative-document scope key (either shape)? */
    public static function isValid(string $scope): bool
    {
        $parts = explode(':', $scope);
        if ($parts[0] === self::RECORD_PREFIX) {
            return count($parts) === 3
                && preg_match(self::FORM_KEY_PATTERN, $parts[1]) === 1
                && preg_match(self::ID_PATTERN, $parts[2]) === 1;
        }
        if ($parts[0] === self::DRAFT_PREFIX) {
            return count($parts) === 2 && preg_match(self::ID_PATTERN, $parts[1]) === 1;
        }

        return false;
    }

    private static function assertFormKey(string $formKey): void
    {
        if (preg_match(self::FORM_KEY_PATTERN, $formKey) !== 1) {
            throw new \InvalidArgumentException(
                sprintf('Invalid collaborative form key "%s": expected /%s/.', $formKey, trim(self::FORM_KEY_PATTERN, '/')),
            );
        }
    }

    private static function assertId(string $id, string $label): void
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException(
                sprintf('Invalid collaborative document %s "%s": expected /%s/.', $label, $id, trim(self::ID_PATTERN, '/')),
            );
        }
    }
}
