<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

use Semitexa\Core\Queue\QueuedOrigin;

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

    /** Its name in {@see QueuedOrigin}. */
    public const QUEUED_ORIGIN = 'ssr.signed_context';

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
        // running, or a job queued by one): replaying it from another session
        // or tenant is refused.
        $key = SignedContextSecret::signingKey();
        $bound = self::boundTo($key);
        if ($bound !== null) {
            $claims['sb'] = $bound['sb'];
            $claims['tn'] = $bound['tn'];
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

        if (!self::belongsToCurrentRequest($claims, $keys)) {
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
     * The session digest may be under any verifying key, not only the one
     * that signed: a queued job's digest is made where it was queued
     * (QueuedOrigin), and across a secret rotation the worker signs with
     * the new key while the digest was made with the old one.
     *
     * @param array<array-key, mixed> $claims
     * @param iterable<string> $keys
     */
    private static function belongsToCurrentRequest(array $claims, iterable $keys): bool
    {
        if (!array_key_exists('sb', $claims) && !array_key_exists('tn', $claims)) {
            return true;
        }
        $binding = SignedContextBinding::current();
        if ($binding === null) {
            return false;
        }

        if (!is_string($claims['sb'] ?? null) || ($claims['tn'] ?? null) !== $binding['t']) {
            return false;
        }
        foreach ($keys as $key) {
            if (hash_equals(self::sessionDigest($binding['s'], $key), $claims['sb'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a context minted now is bound to, for {@see QueuedOrigin}: a
     * Queued handler's reply renders in the worker, where nothing is bound,
     * and the contexts in it were valid for any session. The digest, not the
     * session id, travels in the message. It is the session as it is when
     * the job is queued: a sign-in later in the same request renews the id,
     * and the reply's contexts are then refused until a reload. Null when
     * nothing is bound or no secret is set (then nothing is signed either).
     *
     * @return array{sb: string, tn: string}|null
     */
    public static function queuedBinding(): ?array
    {
        try {
            return self::boundTo(SignedContextSecret::signingKey());
        } catch (\LogicException) {
            // No APP_SECRET: nothing is signed either, so there is nothing to
            // bind. Anything else goes up to QueuedOrigin::capture(), which
            // logs it: answered with null, the job's reply minted contexts
            // any session could present, and nobody heard of it.
            return null;
        }
    }

    /** @return array{sb: string, tn: string}|null */
    private static function boundTo(string $key): ?array
    {
        $binding = SignedContextBinding::current();
        if ($binding !== null) {
            return ['sb' => self::sessionDigest($binding['s'], $key), 'tn' => $binding['t']];
        }
        // A job's reply, for the visitor of the request that queued it.
        $origin = QueuedOrigin::get(self::QUEUED_ORIGIN);

        return is_string($origin['sb'] ?? null) && is_string($origin['tn'] ?? null)
            ? ['sb' => $origin['sb'], 'tn' => $origin['tn']]
            : null;
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
