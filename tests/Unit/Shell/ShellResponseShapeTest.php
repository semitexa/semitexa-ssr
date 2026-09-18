<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Shell;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Request;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse;
use Semitexa\Ssr\Application\Service\Shell\ShellEnvelope;
use Semitexa\Ssr\Application\Service\Shell\ShellRequest;

/**
 * The two shapes of one page, at the seam where the choice is made.
 *
 * The rule these tests hold the mechanism to: the DOCUMENT is the whole truth.
 * A direct hit, a bookmark, a crawler and a visitor with no JavaScript get
 * exactly what they got before the shell existed, from the same route, the
 * same handler and the same template. The chrome-less shape is derived from
 * that document and never rendered its own way.
 */
final class ShellResponseShapeTest extends TestCase
{
    private const PAGE = '<!doctype html><html><head><title>Orders</title>'
        . '<link rel="stylesheet" href="/assets/app.css"></head>'
        . '<body><nav>chrome that never changes</nav>'
        . '<main data-shell-region="main"><h1>Orders</h1></main>'
        . '<script src="/assets/app.js"></script></body></html>';

    protected function tearDown(): void
    {
        ShellRequest::forceForTesting(null);
        CurrentRequestStore::clear();
    }

    private function respond(string $html): \Semitexa\Core\HttpResponse
    {
        $response = new HtmlResponse();
        $response->setContent($html);

        return $response->toCoreResponse();
    }

    private function served(string $uri): Request
    {
        return new Request(
            method: 'GET',
            uri: $uri,
            headers: [],
            query: [],
            post: [],
            server: ['request_uri' => $uri],
            cookies: [],
        );
    }

    #[Test]
    public function anOrdinaryRequestGetsTheDocumentUntouched(): void
    {
        ShellRequest::forceForTesting(false);

        $response = $this->respond(self::PAGE);

        self::assertSame(self::PAGE, $response->getContent());
        self::assertStringContainsString('text/html', $response->getHeaders()['Content-Type']);
    }

    #[Test]
    public function aShellRequestGetsTheRegionsAndNotTheChrome(): void
    {
        ShellRequest::forceForTesting(true);

        $response = $this->respond(self::PAGE);
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['shell']);
        self::assertSame('Orders', $payload['title']);
        self::assertSame('<main data-shell-region="main"><h1>Orders</h1></main>', $payload['regions']['main']);
        self::assertStringNotContainsString('chrome that never changes', $response->getContent());
        self::assertSame([['href' => '/assets/app.css', 'attrs' => []]], $payload['assets']['css']);
        self::assertSame([['src' => '/assets/app.js', 'type' => '', 'attrs' => []]], $payload['assets']['js']);
        self::assertSame('', $payload['deferredManifest'], 'this page defers nothing');
        self::assertStringContainsString('application/json', $response->getHeaders()['Content-Type']);
    }

    #[Test]
    public function theEnvelopeReportsTheAddressTheVisitorIsAt(): void
    {
        // The request reaching a handler has been rebased onto the path the
        // ROUTER matched, and the locale layer strips a URL prefix to build
        // it. Reporting that one answered `/ka/gallery` with `url: /gallery`;
        // the client pushState's that value, and under LOCALE_URL_PREFIX=true
        // an unprefixed path IS the default language — so the page stayed
        // Georgian and the reload, or the link the visitor shared, came back
        // in another language. Silent until someone reloads.
        ShellRequest::forceForTesting(true);
        CurrentRequestStore::set($this->served('/ka/gallery?sort=price_asc')->withPath('/gallery'));

        $payload = json_decode($this->respond(self::PAGE)->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('/ka/gallery?sort=price_asc', $payload['url']);
    }

    #[Test]
    public function anUnprefixedRequestStillReportsItself(): void
    {
        ShellRequest::forceForTesting(true);
        CurrentRequestStore::set($this->served('/gallery'));

        $payload = json_decode($this->respond(self::PAGE)->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('/gallery', $payload['url']);
    }

    #[Test]
    public function bothShapesDeclareThatTheUrlVariesOnTheHeader(): void
    {
        // The document's Vary is the load-bearing one: it is the response that
        // gets cached, and a cache that does not know the header is part of
        // the key will serve the chrome-less JSON to a real navigation.
        //
        // Accept is named too, because this URL answers THREE bodies: the
        // document, the shell envelope on the header above, and the framework's
        // page JSON on Accept. A cache told about only one of the two knobs
        // treats the other's variants as interchangeable.
        $expected = ShellRequest::HEADER . ', Accept';

        ShellRequest::forceForTesting(false);
        self::assertSame($expected, $this->respond(self::PAGE)->getHeaders()['Vary'] ?? null);

        ShellRequest::forceForTesting(true);
        self::assertSame($expected, $this->respond(self::PAGE)->getHeaders()['Vary'] ?? null);
    }

    #[Test]
    public function anExistingVaryIsAppendedToRatherThanOverwritten(): void
    {
        ShellRequest::forceForTesting(false);

        $response = new HtmlResponse();
        $response->setContent(self::PAGE);
        $response->setHeader('Vary', 'Accept-Encoding');

        self::assertSame(
            'Accept-Encoding, ' . ShellRequest::HEADER . ', Accept',
            $response->toCoreResponse()->getHeaders()['Vary']
        );
    }

    #[Test]
    public function theHeaderIsNotDeclaredTwice(): void
    {
        ShellRequest::forceForTesting(true);

        $response = new HtmlResponse();
        $response->setContent(self::PAGE);
        $response->setHeader('Vary', 'accept, ' . ShellRequest::HEADER);

        // Matched without regard to case, which is what a header name means,
        // and neither name is added a second time.
        self::assertSame('accept, ' . ShellRequest::HEADER, $response->toCoreResponse()->getHeaders()['Vary']);
    }

    #[Test]
    public function aLowerCaseVaryIsMergedRatherThanShadowedBySecondHeader(): void
    {
        ShellRequest::forceForTesting(false);

        // Header names are case-insensitive and this response's array is not,
        // so a `vary` set by anything upstream was missed and a second `Vary`
        // entry was added beside it. Swoole's header() is case-insensitive in
        // turn: the later one wins and the response ships without `Cookie`, so
        // a shared cache serves one visitor's page to another.
        $response = new HtmlResponse();
        $response->setContent(self::PAGE);
        $response->setHeader('vary', 'Cookie');

        $headers = $response->toCoreResponse()->getHeaders();
        $names = array_filter(array_keys($headers), static fn (string $n): bool => strcasecmp($n, 'Vary') === 0);

        self::assertCount(1, $names, 'one Vary, however it is spelled');
        self::assertSame('Cookie, ' . ShellRequest::HEADER . ', Accept', $headers[array_values($names)[0]]);
    }

    #[Test]
    public function aPageThatMarksNoRegionGetsItsDocumentEvenOnAShellRequest(): void
    {
        // There is nothing to swap. Answering with an empty envelope would
        // have the client replace a working page with nothing at all.
        ShellRequest::forceForTesting(true);

        $plain = '<!doctype html><html><head><title>Plain</title></head><body><p>no regions</p></body></html>';

        self::assertSame($plain, $this->respond($plain)->getContent());
    }

    #[Test]
    public function anUnencodableEnvelopeStillFallsBackToValidJson(): void
    {
        // The fallback is the branch a client hits when it has already lost:
        // it must still PARSE, or the client cannot even read that the swap
        // failed. Hand-assembled with addslashes it did not — an apostrophe in
        // the URL became `\'`, which JSON has no such escape for, so the
        // fallback was a second unreadable body.
        $envelope = new ShellEnvelope(
            url: "/orders/o'brien?q=" . "\xB1\x31\x8F",
            title: 'ignored by the fallback',
            regions: ['main' => "\xB1\x31\x8F"],
            assets: ['css' => [], 'js' => []],
        );

        $json = $envelope->toJson();
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded, 'the fallback body must parse: ' . $json);
        self::assertTrue($decoded['shell']);
        self::assertSame([], $decoded['regions'], 'nothing to swap is the honest answer here');
    }

    #[Test]
    public function anEmptyResponseIsLeftAlone(): void
    {
        ShellRequest::forceForTesting(true);

        $response = new HtmlResponse();
        $response->disableAutoRender();

        self::assertSame('', $response->toCoreResponse()->getContent());
    }
}
