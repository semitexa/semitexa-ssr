<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\Readiness;

/**
 * A named signal and its state. There is deliberately no score anywhere in
 * this report: a single number is what people optimise instead of the site.
 * Every signal carries the request that measured it, so it can be re-checked
 * instead of believed.
 */
final readonly class ReadinessSignal
{
    public const STATE_PRESENT = 'present';
    public const STATE_ABSENT = 'absent';
    public const STATE_INVALID = 'invalid';
    public const STATE_PARTIAL = 'partial';
    /** Present, but the same value the home page carries — most likely a default. */
    public const STATE_DEFAULTED = 'defaulted';
    /** The response did not let an anonymous agent read it (auth, error, redirect). */
    public const STATE_UNREADABLE = 'unreadable';

    public function __construct(
        public string $subject,
        public string $name,
        public string $state,
        public string $detail,
        public string $measuredBy,
    ) {}

    /**
     * @return array{subject: string, name: string, state: string, detail: string, measured_by: string}
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'name' => $this->name,
            'state' => $this->state,
            'detail' => $this->detail,
            'measured_by' => $this->measuredBy,
        ];
    }
}
