<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Layout;

use Semitexa\Ssr\Domain\Model\DeferralIntentFinding;
use Semitexa\Ssr\Domain\Model\DeferralIntentKind;

/**
 * Compares what a slot DECLARES with what its page actually does.
 *
 * A slot is only deferred when both halves agree: the resource carries
 * `deferred: true` AND a template calls `layout_slot_deferred()` for it.
 * `layout_slot()` renders a deferred-declared slot inline without a word, and
 * `layout_slot_deferred()` on an undeclared slot falls back to rendering it
 * inline, also without a word. The two halves live in different files and
 * nothing compared them, so both mistakes read as working code.
 *
 * MATCHED BY SLOT NAME, not by page handle, and the limitation is worth
 * stating: a name declared deferred for one page and deferred-called from
 * another counts as satisfied. Handle-accurate matching would mean resolving
 * which template renders which handle — real work, for a sharper answer to a
 * question that is almost always about a name used once. The looser rule
 * under-reports; it never invents a finding.
 *
 * A call whose slot name is computed entirely at runtime is invisible to this:
 * the audit reads literals, including one inside a `|default('…')`, and a name
 * assembled from variables leaves nothing to read. Under-reporting again.
 *
 * The audit itself takes plain data so it can be tested without discovery, a
 * Twig environment or a filesystem.
 */
final class DeferralIntentAudit
{
    /** The call, up to its first `)`. Arguments are mined for quoted names separately. */
    private const DEFERRED_CALL = '/layout_slot_deferred\s*\(([^)]*)/';

    /** A slot name written as a literal, anywhere in those arguments. */
    private const QUOTED_NAME = '/[\'"]([A-Za-z0-9_.\-]+)[\'"]/';

    /** Twig blocks whose contents are shown to a reader, not executed. */
    private const NOT_CODE = [
        '/\{%-?\s*verbatim\s*-?%\}.*?\{%-?\s*endverbatim\s*-?%\}/s',
        '/\{#.*?#\}/s',
    ];

    /**
     * @param array<string, string> $declaredDeferred slot name => where it was declared (resource class)
     * @param array<string, string> $templateSources  template path => its source
     *
     * @return list<DeferralIntentFinding>
     */
    public function audit(array $declaredDeferred, array $templateSources): array
    {
        $deferredCalls = $this->deferredCalls($templateSources);

        // The registry lowercases every slot key on registration, so a
        // declaration and a call that differ only in case are the same slot.
        $declared = [];
        foreach ($declaredDeferred as $slot => $origin) {
            $declared[strtolower((string) $slot)] = $origin;
        }

        $findings = [];

        foreach ($declared as $slot => $origin) {
            if (isset($deferredCalls[$slot])) {
                continue;
            }

            $findings[] = new DeferralIntentFinding(
                kind: DeferralIntentKind::DeclaredButNeverDeferred,
                slot: (string) $slot,
                origin: $origin,
                consequence: 'renders inline and synchronously; deferred: true changes nothing here. '
                    . 'Call layout_slot_deferred(\'' . $slot . '\') in the page template, or drop the flag.',
            );
        }

        foreach ($deferredCalls as $slot => $templatePath) {
            if (isset($declared[$slot])) {
                continue;
            }

            $findings[] = new DeferralIntentFinding(
                kind: DeferralIntentKind::DeferredCallWithoutDeclaration,
                slot: (string) $slot,
                origin: $templatePath,
                consequence: 'falls back to rendering the slot inline; no placeholder is emitted and no SSE frame '
                    . 'will ever arrive. Declare deferred: true on the slot resource, or use layout_slot().',
            );
        }

        usort(
            $findings,
            static fn (DeferralIntentFinding $a, DeferralIntentFinding $b): int
                => [$a->kind->value, $a->slot] <=> [$b->kind->value, $b->slot]
        );

        return $findings;
    }

    /**
     * @param array<string, string> $templateSources
     *
     * @return array<string, string> slot name => first template that defers it
     */
    private function deferredCalls(array $templateSources): array
    {
        $calls = [];

        foreach ($templateSources as $path => $source) {
            if (!str_contains($source, 'layout_slot_deferred')) {
                continue;
            }

            $code = $this->withoutProse($source);

            if (!preg_match_all(self::DEFERRED_CALL, $code, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[1] as [$arguments, $offset]) {
                if (!preg_match_all(self::QUOTED_NAME, (string) $arguments, $names)) {
                    continue;
                }

                $line = substr_count($code, "\n", 0, (int) $offset) + 1;

                foreach ($names[1] as $slot) {
                    $calls[strtolower($slot)] ??= $path . ':' . $line;
                }
            }
        }

        return $calls;
    }

    /**
     * Blank out what a template SHOWS rather than runs.
     *
     * A page documenting `layout_slot_deferred('x')` inside `{% verbatim %}`
     * was read as deferring the slot — which is how the first run of this
     * audit named a documentation snippet as the origin of a real finding. The
     * blocks are replaced by spaces of the same length so line numbers survive.
     */
    private function withoutProse(string $source): string
    {
        foreach (self::NOT_CODE as $pattern) {
            $source = (string) preg_replace_callback(
                $pattern,
                static fn (array $m): string => preg_replace('/[^\n]/', ' ', $m[0]) ?? '',
                $source
            );
        }

        return $source;
    }
}
