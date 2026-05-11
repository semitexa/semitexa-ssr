<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

/**
 * Server-side, opaque-to-client signed context substrate.
 *
 * Step-1 scope: sign(claims) → base64url blob; verify(blob) → claims | null.
 * Replay/nonce/TTL store, multi-key rotation, and per-route binding live in
 * later steps. The blob layout is intentionally minimal so it can evolve
 * without breaking foundation callers.
 *
 * Blob layout (versioned):
 *   sc1.<base64url(json(claims))>.<base64url(hmac-sha256(payload))>
 *
 * The "sc1" prefix lets us roll the format forward; "payload" is the literal
 * "sc1." + base64url(json(claims)) string so the MAC covers both the version
 * marker and the canonical claim payload.
 */
final class SignedContext
{
    public const VERSION = 'sc1';
    public const DEFAULT_TTL_SECONDS = 300;

    /**
     * @param array<string, mixed> $claims
     */
    public static function sign(array $claims, ?int $ttlSeconds = null): string
    {
        $ttl = $ttlSeconds ?? self::DEFAULT_TTL_SECONDS;
        if ($ttl <= 0) {
            throw new \InvalidArgumentException('Signed context TTL must be positive.');
        }

        $issuedAt = time();
        $claims['iat'] = $issuedAt;
        $claims['exp'] = $issuedAt + $ttl;

        $json = json_encode(
            self::canonicalize($claims),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $payload = self::VERSION . '.' . self::base64UrlEncode($json);
        $mac = hash_hmac('sha256', $payload, SignedContextSecret::resolve(), true);

        return $payload . '.' . self::base64UrlEncode($mac);
    }

    /**
     * Verify a signed-context blob.
     *
     * Returns the decoded claims on success, or null on any failure
     * (bad format, bad signature, expired TTL). Never throws on the
     * happy or unhappy path — callers decide how to respond.
     *
     * @return array<string, mixed>|null
     */
    public static function verify(string $blob, ?int $now = null): ?array
    {
        $parts = explode('.', $blob, 3);
        if (count($parts) !== 3) {
            return null;
        }
        [$version, $claimsB64, $macB64] = $parts;

        if ($version !== self::VERSION) {
            return null;
        }

        $payload = $version . '.' . $claimsB64;

        try {
            $secret = SignedContextSecret::resolve();
        } catch (\LogicException) {
            return null;
        }

        $expectedMac = hash_hmac('sha256', $payload, $secret, true);

        $providedMac = self::base64UrlDecode($macB64);
        if ($providedMac === null || !hash_equals($expectedMac, $providedMac)) {
            return null;
        }

        $json = self::base64UrlDecode($claimsB64);
        if ($json === null) {
            return null;
        }

        try {
            /** @var mixed $claims */
            $claims = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($claims)) {
            return null;
        }

        $expiresAt = $claims['exp'] ?? null;
        if (!is_int($expiresAt)) {
            return null;
        }

        if (($now ?? time()) > $expiresAt) {
            return null;
        }

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Recursively ksort associative arrays so that signature is stable
     * regardless of caller-supplied key order. List arrays are preserved.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
