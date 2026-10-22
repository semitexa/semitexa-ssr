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

        // Bound to the session and tenant it was minted for (when a request is
        // running): replaying it from another session or tenant is refused.
        $key = SignedContextSecret::signingKey();
        $binding = SignedContextBinding::current();
        if ($binding !== null) {
            $claims['sb'] = self::sessionDigest($binding['s'], $key);
            $claims['tn'] = $binding['t'];
        }

        $json = json_encode(
            self::canonicalize($claims),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $payload = self::VERSION . '.' . self::base64UrlEncode($json);
        $mac = hash_hmac('sha256', $payload, $key, true);

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
        $claims = self::authentic($blob);
        if ($claims === null || ($now ?? time()) > $claims['exp']) {
            return null;
        }

        return $claims;
    }

    /**
     * Whether a context failed ONLY because its TTL ran out: the signature
     * holds, it belongs to this session and tenant, and `exp` has passed.
     *
     * Lets a caller tell a page left open too long from a forged or foreign
     * blob, which stays plain "failed" — so this reveals nothing to anyone
     * who could not already verify the context before it expired.
     */
    public static function expired(string $blob, ?int $now = null): bool
    {
        $claims = self::authentic($blob);

        return $claims !== null && ($now ?? time()) > $claims['exp'];
    }

    /**
     * Signature, format and binding checked; expiry NOT checked.
     *
     * @return (array<string, mixed>&array{exp: int})|null
     */
    private static function authentic(string $blob): ?array
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
            $keys = SignedContextSecret::verifyingKeys();
        } catch (\LogicException) {
            return null;
        }

        $providedMac = self::base64UrlDecode($macB64);
        if ($providedMac === null) {
            return null;
        }
        $key = null;
        foreach ($keys as $candidate) {
            if (hash_equals(hash_hmac('sha256', $payload, $candidate, true), $providedMac)) {
                $key = $candidate;
                break;
            }
        }
        if ($key === null) {
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

        if (!is_int($claims['exp'] ?? null)) {
            return null;
        }

        if (!self::belongsToCurrentRequest($claims, $key)) {
            return null;
        }

        /** @var array<string, mixed>&array{exp: int} $claims */
        return $claims;
    }

    /**
     * A bound context is valid only for the same session and tenant. Where
     * nothing is bound (CLI, a bare test) a bound context cannot be checked
     * and is refused; an unbound one (minted outside a request) passes.
     *
     * @param array<array-key, mixed> $claims
     */
    private static function belongsToCurrentRequest(array $claims, string $key): bool
    {
        if (!array_key_exists('sb', $claims) && !array_key_exists('tn', $claims)) {
            return true;
        }
        $binding = SignedContextBinding::current();
        if ($binding === null) {
            return false;
        }

        return is_string($claims['sb'] ?? null)
            && hash_equals(self::sessionDigest($binding['s'], $key), $claims['sb'])
            && ($claims['tn'] ?? null) === $binding['t'];
    }

    /**
     * The session id never travels in the clear: a digest of it, keyed like
     * the signature that covers it.
     */
    private static function sessionDigest(string $sessionId, string $key): string
    {
        return substr(hash_hmac('sha256', 'session:' . $sessionId, $key), 0, 32);
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
