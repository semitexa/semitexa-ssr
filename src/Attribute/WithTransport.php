<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;
use Semitexa\Core\Attribute\TransportType;

#[Capability(
    id: 'ssr.transport',
    summary: 'Declares how a resource reaches the browser - plain HTTP or a live SSE stream - and whether it is deferred.',
    useWhen: 'Content should update after the page has loaded, without the user reloading.',
    avoidWhen: 'The content does not change after load. A live stream then costs an open connection per viewer for nothing.',
    replaces: [
        'a hand-written EventSource or WebSocket client plus a bespoke endpoint',
        'setInterval polling a JSON route',
    ],
    seeAlso: 'ssr.deferred',
)]
#[Attribute(Attribute::TARGET_CLASS)]
final class WithTransport
{
    public function __construct(
        public readonly TransportType $mode = TransportType::Http,
        public readonly bool $deferred = false,
    ) {}
}
