<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Shell;

/**
 * What a chrome-less response carries.
 *
 * A record rather than an array shape: the renderer, the client and the tests
 * all read the same five fields, and a hand-written `array{}` that drifts
 * hides the branch nobody took.
 *
 * `url` is here because the SERVER decides it. A redirect, a canonicalised
 * path, a locale prefix — the client asked for one address and may be looking
 * at another, and the address bar must show what the server actually served or
 * the next reload lands somewhere else.
 */
final readonly class ShellEnvelope
{
    public const CONTENT_TYPE = 'application/json; charset=UTF-8';

    /**
     * @param array<string, string>                                            $regions name => outer HTML
     * @param array{css: list<string>, js: list<array{src: string, type: string}>} $assets
     */
    public function __construct(
        public string $url,
        public string $title,
        public array $regions,
        public array $assets,
    ) {}

    /**
     * @return array{shell: true, url: string, title: string, regions: array<string, string>, assets: array{css: list<string>, js: list<array{src: string, type: string}>}}
     */
    public function toArray(): array
    {
        return [
            // Named in the payload as well as the content type: a client that
            // receives this by accident — a cache that ignored Vary, a proxy
            // that rewrote Accept — can tell what it is holding instead of
            // rendering braces into the page.
            'shell' => true,
            'url' => $this->url,
            'title' => $this->title,
            'regions' => $this->regions,
            'assets' => $this->assets,
        ];
    }

    public function toJson(): string
    {
        try {
            return (string) json_encode(
                $this->toArray(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            // A page whose markup will not encode is a page the client cannot
            // swap. Say so in the envelope's own shape rather than sending a
            // broken body: the client falls back to a real navigation, which
            // is exactly what it should do when it cannot apply a fragment.
            return '{"shell":true,"url":"' . addslashes($this->url) . '","title":"","regions":{},"assets":{"css":[],"js":[]}}';
        }
    }
}
