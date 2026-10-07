<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

/**
 * Adjusts a component's caller props for the page request that renders it —
 * Platform UI restores `#[UiUrl]` props from the query string this way.
 *
 * Runs on page renders only (GET, not a transport door): a re-render after an
 * interaction keeps the props its handler chose. Must treat the query as
 * attacker input: validate, and leave a prop alone when its value is invalid.
 */
interface ComponentPropsOverlayInterface
{
    /**
     * @param array<array-key, mixed> $props the caller's props
     * @param array<array-key, mixed> $query the page request's query parameters
     * @return array<array-key, mixed>
     */
    public function overlay(string $componentName, array $props, array $query): array;
}
