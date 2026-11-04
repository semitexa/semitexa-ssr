<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Isomorphic;

use Semitexa\Ssr\Domain\Exception\DeferredRenderingException;

/**
 * What of a page's context can wait in the shared table for its deferred stream:
 * scalars, nulls and arrays of them. Anything else stays out, as an object cannot
 * cross to the worker that serves the stream.
 */
final class DeferredContextValues
{
    private const int MAX_DEPTH = 32;

    /**
     * @param array<mixed> $context
     *
     * @return array<mixed>
     */
    public static function sanitize(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            $normalized = self::sanitizeValue($value);
            if ($normalized === self::unsupportedMarker()) {
                continue;
            }

            $sanitized[$key] = $normalized;
        }

        return $sanitized;
    }

    public static function validate(mixed $value, int $depth = 0): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new DeferredRenderingException('Page context exceeds maximum nesting depth.');
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::validate($item, $depth + 1);
            }
            return;
        }

        if (is_null($value) || is_scalar($value)) {
            return;
        }

        throw new DeferredRenderingException('Page context contains non-serializable values.');
    }

    private static function sanitizeValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (!is_array($value)) {
            return self::unsupportedMarker();
        }

        return self::sanitize($value);
    }

    private static function unsupportedMarker(): object
    {
        /** @var \stdClass|null $marker */
        static $marker = null;

        return $marker ??= new \stdClass();
    }
}
