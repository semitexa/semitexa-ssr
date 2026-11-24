<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\SseRequestGuard;
use Swoole\Http\Request;

/**
 * Direct tests for the extracted admission control.
 *
 * The authorization / rejection cases these gates already had live on in
 * {@see AsyncResourceSseServerTest}, which now calls this class instead of
 * reflecting into a private static. What is new here is coverage of the two
 * gates that were previously unreachable from a test at all — same-origin and
 * client-IP resolution — because they took a `Swoole\Http\Request` and there was
 * no seam to hand one to.
 */
final class SseRequestGuardTest extends TestCase
{
    #[Test]
    #[DataProvider('sameOriginCases')]
    public function same_origin_gate_fails_closed(array $headers, bool $expected, string $because): void
    {
        self::assertSame($expected, (new SseRequestGuard())->isSameOriginRequest(self::request($headers)), $because);
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: bool, 2: string}>
     */
    public static function sameOriginCases(): iterable
    {
        yield 'origin matches host' => [
            ['host' => 'app.test', 'origin' => 'https://app.test'],
            true,
            'the ordinary browser EventSource case',
        ];

        yield 'referer matches host' => [
            ['host' => 'app.test', 'referer' => 'https://app.test/dashboard'],
            true,
            'Referer is accepted when Origin is absent',
        ];

        yield 'host carries the port and origin repeats it' => [
            ['host' => 'app.test:9502', 'origin' => 'https://app.test:9502'],
            true,
            'the local dev case — host:port must compare equal',
        ];

        yield 'header case is irrelevant' => [
            ['HOST' => 'App.Test', 'Origin' => 'https://APP.TEST'],
            true,
            'header names and host values are both normalized',
        ];

        yield 'no host header' => [
            ['origin' => 'https://app.test'],
            false,
            'fails closed — there is nothing to compare against',
        ];

        yield 'neither origin nor referer' => [
            ['host' => 'app.test'],
            false,
            'fails closed — a browser EventSource always sends Origin, so this is untrusted',
        ];

        yield 'origin is a different host' => [
            ['host' => 'app.test', 'origin' => 'https://evil.test'],
            false,
            'the actual cross-origin refusal',
        ];

        yield 'origin is unparseable' => [
            ['host' => 'app.test', 'origin' => 'not-a-url'],
            false,
            'fails closed rather than admitting on a parse failure',
        ];

        yield 'origin matches but referer does not' => [
            ['host' => 'app.test', 'origin' => 'https://app.test', 'referer' => 'https://evil.test/x'],
            false,
            'every present header must match — one good header does not excuse a bad one',
        ];

        yield 'a mismatched port is cross-origin' => [
            ['host' => 'app.test:9502', 'origin' => 'https://app.test:9999'],
            false,
            'port is part of the origin',
        ];
    }

    #[Test]
    public function a_port_stripping_proxy_does_not_lock_everyone_out(): void
    {
        // The gate accepts a host-only match as the second arm of its comparison,
        // so an Origin carrying a port still matches a Host the proxy stripped.
        self::assertTrue((new SseRequestGuard())->isSameOriginRequest(
            self::request(['host' => 'app.test', 'origin' => 'https://app.test:9502']),
        ));
    }

    #[Test]
    #[DataProvider('bearerCases')]
    public function safe_bearer_shape_is_strict(mixed $candidate, bool $expected): void
    {
        self::assertSame($expected, (new SseRequestGuard())->isSafeBearerSessionId($candidate));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: bool}>
     */
    public static function bearerCases(): iterable
    {
        yield 'canonical mint' => ['sse_' . str_repeat('a', 32), true];
        yield 'full hex range' => ['sse_0123456789abcdef0123456789abcdef', true];
        yield 'uppercase hex' => ['sse_' . str_repeat('A', 32), false];
        yield 'too short' => ['sse_' . str_repeat('a', 31), false];
        yield 'too long' => ['sse_' . str_repeat('a', 33), false];
        yield 'missing prefix' => [str_repeat('a', 32), false];
        yield 'trailing newline' => ["sse_" . str_repeat('a', 32) . "\n", false];
        yield 'leading whitespace' => [' sse_' . str_repeat('a', 32), false];
        yield 'empty string' => ['', false];
        yield 'not a string' => [12345, false];
        yield 'null' => [null, false];
    }

    #[Test]
    public function client_ip_is_lowercased_and_trimmed(): void
    {
        $request = self::request([]);
        $request->server = ['remote_addr' => '  2001:DB8::1  '];

        self::assertSame('2001:db8::1', (new SseRequestGuard())->resolveClientIp($request));
    }

    #[Test]
    public function client_ip_is_empty_when_the_server_array_has_no_address(): void
    {
        self::assertSame('', (new SseRequestGuard())->resolveClientIp(self::request([])));
    }

    /**
     * Behind a reverse proxy every visitor arrives from the proxy's address. The
     * per-IP SSE cap keyed on that let a few idle anonymous streams answer 429
     * to everyone (security audit, 24.11): the client a trusted proxy forwarded
     * is the key now.
     */
    #[Test]
    public function behind_a_trusted_proxy_the_forwarded_client_is_the_key(): void
    {
        $this->withTrustedProxies('10.0.0.1', function (): void {
            $first = self::request(['x-forwarded-for' => '203.0.113.7']);
            $first->server = ['remote_addr' => '10.0.0.1'];
            $second = self::request(['x-forwarded-for' => '198.51.100.23']);
            $second->server = ['remote_addr' => '10.0.0.1'];

            self::assertSame('203.0.113.7', (new SseRequestGuard())->resolveClientIp($first));
            self::assertSame('198.51.100.23', (new SseRequestGuard())->resolveClientIp($second), 'two clients of one proxy are two keys');
        });
    }

    /** A client cannot choose its own bucket: without a trusted peer X-Forwarded-For is not read. */
    #[Test]
    public function without_a_trusted_proxy_x_forwarded_for_is_ignored(): void
    {
        $this->withTrustedProxies(null, function (): void {
            $request = self::request(['x-forwarded-for' => '1.2.3.4']);
            $request->server = ['remote_addr' => '203.0.113.9'];

            self::assertSame('203.0.113.9', (new SseRequestGuard())->resolveClientIp($request));
        });
    }

    /** A proxy on this host needs no TRUSTED_PROXIES: core trusts loopback as it is. */
    #[Test]
    public function a_proxy_on_loopback_forwards_the_client_without_trusted_proxies(): void
    {
        $this->withTrustedProxies(null, function (): void {
            $request = self::request(['x-forwarded-for' => '203.0.113.7']);
            $request->server = ['remote_addr' => '127.0.0.1'];

            self::assertSame('203.0.113.7', (new SseRequestGuard())->resolveClientIp($request));
        });
    }

    /**
     * TRUSTED_PROXIES is process state: set for the test, put back as it was. "None"
     * is an empty value, not an unset one: unset, Environment::getEnvValue() falls
     * back to the project's .env, which may list a proxy (review semitexa-ssr#199).
     */
    private function withTrustedProxies(?string $value, \Closure $test): void
    {
        $before = getenv('TRUSTED_PROXIES');
        putenv('TRUSTED_PROXIES=' . ($value ?? ''));
        try {
            $test();
        } finally {
            $before === false ? putenv('TRUSTED_PROXIES') : putenv('TRUSTED_PROXIES=' . $before);
        }
    }

    #[Test]
    public function auth_error_outranks_a_cross_origin_refusal(): void
    {
        // A caller failing both gates is told it is unauthorized, not that it is
        // cross-origin — the 401 is the actionable one.
        self::assertSame(
            ['status' => 401, 'message' => 'nope'],
            (new SseRequestGuard())->resolveRejection(sameOrigin: false, authError: 'nope'),
        );
    }

    #[Test]
    public function cross_origin_alone_is_a_message_less_403(): void
    {
        self::assertSame(
            ['status' => 403, 'message' => ''],
            (new SseRequestGuard())->resolveRejection(sameOrigin: false, authError: null),
        );
    }

    #[Test]
    public function passing_both_gates_admits(): void
    {
        self::assertNull((new SseRequestGuard())->resolveRejection(sameOrigin: true, authError: null));
    }

    /**
     * @param array<string, string> $headers
     */
    private static function request(array $headers): Request
    {
        $request = new Request();
        $request->header = $headers;
        $request->server = [];

        return $request;
    }
}
