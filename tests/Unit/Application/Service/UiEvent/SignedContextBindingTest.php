<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Service\UiEvent;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Queue\QueuedOrigin;
use Semitexa\Core\Session\Session;
use Semitexa\Core\Session\SessionHandlerInterface;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContextBinding;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseSessionState;
use Semitexa\Ssr\Context\IsomorphicContextStore;

/**
 * tk-la-signed-context-binding: a signed UI context belongs to the session and
 * tenant it was minted for. Copied into another user's request it is refused —
 * the HMAC alone only proved the server minted it, not for whom.
 */
final class SignedContextBindingTest extends TestCase
{
    private const SESSION_A = '0123456789abcdef0123456789abcdef';
    private const SESSION_B = 'fedcba9876543210fedcba9876543210';

    /** @var array<string, string|false> */
    private array $previousEnv = [];

    /** @var array{session: \Semitexa\Core\Session\SessionInterface|string|null, t: string}|null */
    private ?array $previousBinding = null;

    protected function setUp(): void
    {
        foreach (['APP_SECRET', 'APP_SECRET_PREVIOUS', 'APP_ENV'] as $name) {
            $this->previousEnv[$name] = getenv($name);
        }
        $this->previousBinding = SignedContextBinding::snapshot();
        putenv('APP_SECRET=signed-context-binding-test');
        putenv('APP_ENV=dev');
    }

    protected function tearDown(): void
    {
        SignedContextBinding::restore($this->previousBinding);
        foreach ($this->previousEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    #[Test]
    public function a_context_verifies_for_the_session_and_tenant_it_was_minted_for(): void
    {
        SignedContextBinding::bind(self::SESSION_A, 'acme');
        $ctx = SignedContext::sign(['c' => 'demo', 'i' => 'uci_1']);

        $claims = SignedContext::verify($ctx);
        self::assertNotNull($claims);
        self::assertSame('acme', $claims['tn']);
        self::assertStringNotContainsString(self::SESSION_A, (string) json_encode($claims), 'the session id never travels in the clear');
    }

    /**
     * Core stores a guest's session only once something asked for its id or
     * wrote to it (idea-anonymous-error-responses-mint-session-cookies). A
     * page that asks for a live stream asks for the id: the contexts minted on
     * the stream are bound to the session the browser presents there.
     */
    #[Test]
    public function a_page_that_opens_a_stream_asks_for_its_session_id(): void
    {
        $reads = static function (\Closure $declareStream): int {
            $session = new class implements SessionHandlerInterface {
                public function read(string $sessionId): array
                {
                    return [];
                }

                public function write(string $sessionId, array $data, int $lifetimeSeconds = 3600): void
                {
                }

                public function destroy(string $sessionId): void
                {
                }
            };
            $counting = new IdReadsCountingSession(new Session(self::SESSION_A, $session, 'semitexa_session'));
            SignedContextBinding::bind($counting, 'acme');
            $declareStream();

            return $counting->idReads;
        };

        UiSseSessionState::reset();
        self::assertSame(1, $reads(static fn () => UiSseSessionState::mintIfAbsent()), 'a live page: ui_page_sse_session_meta()');
        self::assertSame(0, $reads(static fn () => UiSseSessionState::mintIfAbsent()), 'the id is minted once a request, and asked for once');
        UiSseSessionState::reset();

        // Not where the deferred stream id is minted: every HTML page of an app
        // with a deferred component gets one before it renders, a 404 included
        // (OnlyAPageThatStreamsKeepsItsGuestSessionTest has the page that is sent a stream).
        $before = IsomorphicContextStore::getSessionId();
        try {
            self::assertSame(0, $reads(static fn () => IsomorphicContextStore::setSessionId('sse_' . str_repeat('a', 32))), 'an id is not yet a stream');
        } finally {
            IsomorphicContextStore::setSessionId($before);
        }
        self::assertSame(1, $reads(static fn () => SignedContextBinding::keepSession()), 'what a page that is sent the manifest calls');

        SignedContextBinding::bind(null, '');
        SignedContextBinding::keepSession();
        SignedContextBinding::clear();
        SignedContextBinding::keepSession();
        $this->addToAssertionCount(1);
    }

    /**
     * A Queued handler's reply renders in the worker, where nothing is bound:
     * the contexts in it verified for any session. The queuing request's
     * binding now travels in the message, as a digest.
     */
    #[Test]
    public function a_context_minted_in_a_queued_job_belongs_to_the_visitor_who_queued_it(): void
    {
        SignedContextBinding::bind(self::SESSION_A, 'acme');
        $origin = QueuedOrigin::capture();
        self::assertSame(['sb', 'tn'], array_keys($origin[SignedContext::QUEUED_ORIGIN] ?? []), 'the binding is exported into the message');
        self::assertSame('acme', $origin[SignedContext::QUEUED_ORIGIN]['tn']);
        self::assertStringNotContainsString(self::SESSION_A, (string) json_encode($origin), 'the session id never travels in the message');

        SignedContextBinding::clear();
        $ctx = QueuedOrigin::run($origin, static fn (): string => SignedContext::sign(['c' => 'demo', 'i' => 'uci_1']));
        $outside = (array) json_decode((string) base64_decode(strtr(explode('.', SignedContext::sign(['c' => 'demo']))[1], '-_', '+/')), true);
        self::assertSame('demo', $outside['c'] ?? null, 'the claims decoded, so the absent keys below mean something');
        self::assertArrayNotHasKey('sb', $outside, 'outside the job nothing is bound');
        self::assertArrayNotHasKey('tn', $outside);

        SignedContextBinding::bind(self::SESSION_A, 'acme');
        self::assertNotNull(SignedContext::verify($ctx));
        SignedContextBinding::bind(self::SESSION_B, 'acme');
        self::assertNull(SignedContext::verify($ctx));
        SignedContextBinding::bind(self::SESSION_A, 'globex');
        self::assertNull(SignedContext::verify($ctx));
    }

    /** A job queued under the old secret, its reply signed under the new one. */
    #[Test]
    public function a_queued_job_s_context_survives_a_secret_rotation(): void
    {
        SignedContextBinding::bind(self::SESSION_A, 'acme');
        $origin = QueuedOrigin::capture();
        SignedContextBinding::clear();

        putenv('APP_SECRET=signed-context-binding-test-rotated');
        putenv('APP_SECRET_PREVIOUS=signed-context-binding-test');
        $ctx = QueuedOrigin::run($origin, static fn (): string => SignedContext::sign(['c' => 'demo', 'i' => 'uci_1']));
        SignedContextBinding::bind(self::SESSION_A, 'acme');
        self::assertNotNull(SignedContext::verify($ctx));
        SignedContextBinding::bind(self::SESSION_B, 'acme');
        self::assertNull(SignedContext::verify($ctx));
    }

    /**
     * Only a missing secret means "nothing to bind". Any other failure was
     * answered the same way, and the job's reply went out bound to nobody.
     */
    #[Test]
    public function a_binding_that_fails_to_read_is_not_taken_for_none(): void
    {
        $session = $this->createMock(\Semitexa\Core\Session\SessionInterface::class);
        $session->method('getId')->willThrowException(new \RuntimeException('session store is down'));
        SignedContextBinding::bind($session, 'acme');

        $this->expectExceptionObject(new \RuntimeException('session store is down'));
        SignedContext::queuedBinding();
    }

    #[Test]
    public function another_session_cannot_replay_it(): void
    {
        SignedContextBinding::bind(self::SESSION_A, 'acme');
        $ctx = SignedContext::sign(['c' => 'demo', 'i' => 'uci_1']);

        SignedContextBinding::bind(self::SESSION_B, 'acme');
        self::assertNull(SignedContext::verify($ctx));
    }

    #[Test]
    public function another_tenant_cannot_replay_it(): void
    {
        SignedContextBinding::bind(self::SESSION_A, 'acme');
        $ctx = SignedContext::sign(['c' => 'demo', 'i' => 'uci_1']);

        SignedContextBinding::bind(self::SESSION_A, 'globex');
        self::assertNull(SignedContext::verify($ctx));
    }

    #[Test]
    public function a_bound_context_is_refused_where_nothing_is_bound(): void
    {
        SignedContextBinding::bind(self::SESSION_A, 'acme');
        $ctx = SignedContext::sign(['c' => 'demo']);

        SignedContextBinding::clear();
        self::assertNull(SignedContext::verify($ctx), 'a check that cannot be made must not pass');
    }

    #[Test]
    public function an_unbound_context_minted_outside_a_request_still_verifies(): void
    {
        $ctx = SignedContext::sign(['c' => 'demo']);

        SignedContextBinding::bind(self::SESSION_A, 'acme');
        self::assertNotNull(SignedContext::verify($ctx));
    }

    #[Test]
    public function a_context_minted_after_sign_in_verifies_on_the_next_request(): void
    {
        $handler = new class implements SessionHandlerInterface {
            public function read(string $sessionId): array { return []; }
            public function write(string $sessionId, array $data, int $lifetimeSeconds = 3600): void {}
            public function destroy(string $sessionId): void {}
        };
        $session = new Session(self::SESSION_A, $handler, 'semitexa_session');
        SignedContextBinding::bind($session, 'acme');

        // Sign-in: the id is regenerated, then the page renders its components.
        $session->regenerate();
        $ctx = SignedContext::sign(['c' => 'demo', 'i' => 'uci_1']);
        $session->save();

        // The next request presents the cookie with the new id.
        SignedContextBinding::bind($session->getSessionIdForCookie(), 'acme');
        self::assertNotNull(SignedContext::verify($ctx), 'bound to the id the browser presents next, not the one it arrived with');

        SignedContextBinding::bind(self::SESSION_A, 'acme');
        self::assertNull(SignedContext::verify($ctx), 'the pre-sign-in session cannot present it');
    }
}

/** Counts getId(): the one call that tells core a session is in use by its id. */
final class IdReadsCountingSession implements SessionInterface
{
    public int $idReads = 0;

    public function __construct(private readonly SessionInterface $inner)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inner->get($key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->inner->set($key, $value);
    }

    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }

    public function remove(string $key): void
    {
        $this->inner->remove($key);
    }

    public function clear(): void
    {
        $this->inner->clear();
    }

    public function getId(): string
    {
        $this->idReads++;

        return $this->inner->getId();
    }

    public function regenerate(): void
    {
        $this->inner->regenerate();
    }

    public function flash(string $key, mixed $value): void
    {
        $this->inner->flash($key, $value);
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->inner->getFlash($key, $default);
    }

    public function getPayload(string $payloadClass): object
    {
        return $this->inner->getPayload($payloadClass);
    }

    public function setPayload(object $payload): void
    {
        $this->inner->setPayload($payload);
    }

    public function save(): void
    {
        $this->inner->save();
    }
}
