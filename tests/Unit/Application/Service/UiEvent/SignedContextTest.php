<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Service\UiEvent;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;

final class SignedContextTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->envBackup = [
            'APP_SECRET' => getenv('APP_SECRET'),
            'APP_ENV' => getenv('APP_ENV'),
            'APP_NAME' => getenv('APP_NAME'),
            'APP_HOST' => getenv('APP_HOST'),
            'APP_PORT' => getenv('APP_PORT'),
            'APP_SECRET_PREVIOUS' => getenv('APP_SECRET_PREVIOUS'),
        ];

        $_ENV['APP_SECRET'] = 'test-secret-' . bin2hex(random_bytes(8));
        $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'];
        putenv('APP_SECRET=' . $_ENV['APP_SECRET']);
        $_ENV['APP_ENV'] = 'test';
        putenv('APP_ENV=test');
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv($key . '=' . $value);
            }
        }
    }

    #[Test]
    public function signs_and_verifies_round_trip(): void
    {
        $claims = ['componentInstanceId' => 'cmp_01', 'event' => 'click', 'nonce' => 'abc'];
        $blob = SignedContext::sign($claims, 60);

        self::assertStringStartsWith('sc1.', $blob);
        $verified = SignedContext::verify($blob);
        self::assertIsArray($verified);
        self::assertSame('cmp_01', $verified['componentInstanceId']);
        self::assertSame('click', $verified['event']);
        self::assertArrayHasKey('iat', $verified);
        self::assertArrayHasKey('exp', $verified);
    }

    #[Test]
    public function rejects_tampered_blob(): void
    {
        $blob = SignedContext::sign(['x' => 1], 60);
        $tampered = $blob . 'A';
        self::assertNull(SignedContext::verify($tampered));
    }

    #[Test]
    public function rejects_wrong_version_prefix(): void
    {
        $blob = SignedContext::sign(['x' => 1], 60);
        $bad = 'sc9.' . substr($blob, 4);
        self::assertNull(SignedContext::verify($bad));
    }

    #[Test]
    public function rejects_expired_blob(): void
    {
        $blob = SignedContext::sign(['x' => 1], 60);
        $future = time() + 7200;
        self::assertNull(SignedContext::verify($blob, $future));
    }

    #[Test]
    public function expired_names_only_an_authentic_context_past_its_ttl(): void
    {
        $blob = SignedContext::sign(['x' => 1], 60);
        $future = time() + 7200;

        self::assertTrue(SignedContext::expired($blob, $future));
        self::assertFalse(SignedContext::expired($blob), 'a live context is not expired');
        // A forged or malformed blob stays "failed", never "expired": the
        // distinction must not tell anyone more than verify() would.
        self::assertFalse(SignedContext::expired($blob . 'TAMPER', $future));
        self::assertFalse(SignedContext::expired('sc1.AAAA', $future));
        self::assertFalse(SignedContext::expired('', $future));
    }

    #[Test]
    public function rejects_garbage_input(): void
    {
        self::assertNull(SignedContext::verify(''));
        self::assertNull(SignedContext::verify('not-a-blob'));
        self::assertNull(SignedContext::verify('sc1.AAAA'));
    }

    #[Test]
    public function signature_stable_under_key_reordering(): void
    {
        // Signing the same logical claims in different key order should produce
        // identical blob payloads (modulo timestamps); both must verify.
        $a = SignedContext::sign(['a' => 1, 'b' => 2, 'c' => 3], 60);
        $b = SignedContext::sign(['c' => 3, 'a' => 1, 'b' => 2], 60);

        $aClaims = SignedContext::verify($a);
        $bClaims = SignedContext::verify($b);

        self::assertNotNull($aClaims);
        self::assertNotNull($bClaims);

        // strip iat/exp which depend on time
        unset($aClaims['iat'], $aClaims['exp'], $bClaims['iat'], $bClaims['exp']);
        self::assertSame($aClaims, $bClaims);
    }

    /**
     * APP_SECRET_PREVIOUS was never read: rotating the secret, as the doctor
     * advises, failed every click on every page already open.
     */
    #[Test]
    public function a_context_signed_before_a_rotation_still_verifies(): void
    {
        $old = $_ENV['APP_SECRET'];
        $blob = SignedContext::sign(['c' => 'demo']);

        $this->setSecret('rotated-' . bin2hex(random_bytes(8)));
        self::assertNull(SignedContext::verify($blob), 'without the old secret listed it is refused');

        $this->setEnv('APP_SECRET_PREVIOUS', $old);
        self::assertSame('demo', SignedContext::verify($blob)['c'] ?? null);
    }

    /** New contexts are signed with the purpose's own key, not the raw secret. */
    #[Test]
    public function a_new_context_is_signed_with_the_derived_key(): void
    {
        [$version, $claims, $mac] = explode('.', SignedContext::sign(['c' => 'demo']));
        $expected = hash_hmac('sha256', $version . '.' . $claims, \Semitexa\Core\Security\AppSecret::derive((string) $_ENV['APP_SECRET'], 'ssr.signed-context'), true);

        self::assertSame(rtrim(strtr(base64_encode($expected), '+/', '-_'), '='), $mac);
    }

    /** A page opened before this release carries a context signed with the raw secret. */
    #[Test]
    public function a_context_signed_with_the_raw_secret_still_verifies(): void
    {
        $payload = SignedContext::VERSION . '.' . rtrim(strtr(base64_encode((string) json_encode(['c' => 'demo', 'exp' => time() + 60, 'iat' => time()])), '+/', '-_'), '=');
        $blob = $payload . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, (string) $_ENV['APP_SECRET'], true)), '+/', '-_'), '=');

        self::assertSame('demo', SignedContext::verify($blob)['c'] ?? null);
    }

    private function setSecret(string $secret): void
    {
        $this->setEnv('APP_SECRET', $secret);
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }
}
