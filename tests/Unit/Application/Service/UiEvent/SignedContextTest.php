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
}
