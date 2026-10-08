<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Exception;

/**
 * A template asked for a component no #[AsComponent] declares.
 *
 * Thrown in development only. The name in `component('…')` is a reference to
 * the declaration's name, and the two drift apart on a typo or a rename; the
 * renderer used to answer with an HTML comment, so the page simply had a hole
 * that only view-source showed. In production the comment stays and the miss is
 * logged — a visitor should not pay for it with an error page.
 */
final class UnknownComponentException extends \LogicException
{
    /** @param list<string> $known every registered component name */
    public static function named(string $name, array $known): self
    {
        $suggestion = self::closest($name, $known);

        return new self(sprintf(
            "No component is registered as '%s'%s. The name in component('…') must be the name an #[AsComponent(name: …)] declares.",
            $name,
            $suggestion === null ? '' : sprintf(" — did you mean '%s'?", $suggestion),
        ));
    }

    /**
     * The registered name a typo most likely meant: the nearest by edit
     * distance, if it is near enough to be a typo rather than another name.
     *
     * @param list<string> $known
     */
    public static function closest(string $name, array $known): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($known as $candidate) {
            $distance = levenshtein($name, $candidate);
            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best !== null && $bestDistance <= max(2, intdiv(strlen($name), 4)) ? $best : null;
    }
}
