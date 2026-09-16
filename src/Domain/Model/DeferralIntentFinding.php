<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Model;

/** One disagreement between what a slot declares and what its template does. */
final readonly class DeferralIntentFinding
{
    public function __construct(
        public DeferralIntentKind $kind,
        public string $slot,
        /** Where the claim was made: the resource class, or the template path. */
        public string $origin,
        public string $consequence,
    ) {}

    /** @return array{kind: string, slot: string, origin: string, consequence: string} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'slot' => $this->slot,
            'origin' => $this->origin,
            'consequence' => $this->consequence,
        ];
    }
}
