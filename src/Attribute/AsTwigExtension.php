<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

#[Capability(
    id: 'ssr.twig-extension',
    summary: 'Registers a class as a Twig extension by discovery, with no boot-time wiring.',
    useWhen: 'Templates need a new function or filter.',
    avoidWhen: 'The logic belongs in a handler or resource; template helpers that carry business rules are hard to test.',
    replaces: [
        'registering the extension manually during server boot',
    ],
)]
/**
 * Marks a class whose `registerFunctions()` / `registerFilters()` the catalog
 * calls once at boot.
 *
 * An extension that needs a COLLABORATOR must also carry `#[AsService]` and
 * take it through an `#[InjectAs*]` property. Discovery finds the class by this
 * attribute but the catalog builds it through the container, and the container
 * only knows classes it was told about — without `#[AsService]` the extension
 * is built with `new`, injection never runs, and the first call to its function
 * fails inside a template with an uninitialised-property error that names Twig
 * rather than the wiring.
 *
 * Most extensions need nothing and are right to declare nothing: the functions
 * they expose reach request state through a per-coroutine store rather than a
 * dependency.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AsTwigExtension
{
    public function __construct() {}
}
