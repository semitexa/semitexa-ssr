<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Service\UiEvent;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContextBinding;

/**
 * tk-la-signed-context-binding: a signed UI context belongs to the session and
 * tenant it was minted for. Copied into another user's request it is refused —
 * the HMAC alone only proved the server minted it, not for whom.
 */
final class SignedContextBindingTest extends TestCase
{
    private const SESSION_A = '0123456789abcdef0123456789abcdef';
    private const SESSION_B = 'fedcba9876543210fedcba9876543210';

    protected function setUp(): void
    {
        putenv('APP_SECRET=signed-context-binding-test');
        putenv('APP_ENV=dev');
    }

    protected function tearDown(): void
    {
        CoroutineLocal::resetCliStore();
        putenv('APP_SECRET');
        putenv('APP_ENV');
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
}
