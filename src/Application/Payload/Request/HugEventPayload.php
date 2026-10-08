<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * HUG, the inbound door: `POST /__semitexa_hug` carries every UI event and
 * action from the browser to the server. Its sibling `GET /__semitexa_hug`
 * ({@see SsrFallbackPayload}) is the pull fallback for deferred slots; KISS
 * (`/__semitexa_kiss`) is the one stream back. Those two doors are the whole
 * browser<->server transport by design — a feature that needs another verb
 * extends HUG instead of adding a route.
 *
 * The envelope body is canonical JSON and is validated by
 * UiEventEnvelope::fromArray() inside the handler against the raw request
 * body — NOT through the usual hydrator-setter convention. Reason: setter-
 * based hydration silently drops unknown keys, which would let a client
 * smuggle forbidden handler-selection fields past the disallow list. Reading
 * the raw JSON in the handler keeps the smuggling check loud.
 *
 * Hard rule (framework-layer §11):
 *   Server-side metadata validated through signed context is the only source
 *   of handler identity. The signed context may reference a render id,
 *   manifest id, component instance id, or future server-side metadata
 *   record. The backend resolves the actual handler from server-side
 *   metadata. The frontend must never provide handler identity.
 */
#[AsPublicPayload(
    path: '/__semitexa_hug',
    methods: ['POST'],
    name: 'ssr.hug.event',
    responseWith: ResourceResponse::class,
    consumes: ['application/json', 'multipart/form-data'],
    produces: ['application/json'],
)]
final class HugEventPayload
{
}
