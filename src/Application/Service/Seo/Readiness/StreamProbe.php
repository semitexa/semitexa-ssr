<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\Readiness;

/**
 * Plain GET over PHP streams: no cookies, no redirects followed, a short
 * timeout. That is what an agent reading the site gets, which is the point.
 *
 * Only http(s) is fetched: a sitemap <loc> of /dev/zero or php://stdin would
 * otherwise read a local resource. Anything but the audited site's own origin
 * (scheme, host AND port: the site may well be localhost in dev, its Redis on
 * localhost:6379 is not the site) must resolve to global addresses only, so a
 * planted link cannot make the audit probe loopback, link-local metadata or
 * private-network services. The address is checked before the fetch, which
 * resolves again: DNS rebinding is out of reach of this check.
 */
final class StreamProbe
{
    /**
     * @param string|null $siteOrigin the audited site's origin URL; null = every URL is foreign
     */
    public function __construct(
        private readonly float $timeoutSeconds = 10.0,
        private readonly ?string $siteOrigin = null,
    ) {}

    public function __invoke(string $url): ProbeResponse
    {
        if (!$this->mayFetch($url)) {
            return ProbeResponse::unreachable();
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: */*\r\nUser-Agent: semitexa-site-readiness\r\n",
                'follow_location' => 0,
                'ignore_errors' => true,
                'timeout' => $this->timeoutSeconds,
            ],
        ]);

        // Cleared first: after a failed connection the last headers would
        // still be the previous request's, and a dead URL would inherit them.
        // (Not the $http_response_header local: it is deprecated as of 8.5.)
        http_clear_last_response_headers();
        $body = @file_get_contents($url, false, $context);
        $headers = http_get_last_response_headers() ?? [];
        if ($body === false || $headers === []) {
            return ProbeResponse::unreachable();
        }

        $status = 0;
        $contentType = '';
        $location = '';
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            } elseif (stripos($line, 'content-type:') === 0) {
                $contentType = trim(substr($line, 13));
            } elseif (stripos($line, 'location:') === 0) {
                $location = trim(substr($line, 9));
            }
        }

        return new ProbeResponse($status, $contentType, $body, $location);
    }

    public function mayFetch(string $url): bool
    {
        $origin = self::origin($url);
        if ($origin === null) {
            return false;
        }
        if ($this->siteOrigin !== null && $origin === self::origin($this->siteOrigin)) {
            return true;
        }

        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } else {
            $addresses = gethostbynamel($host) ?: [];
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                $addresses[] = (string) ($record['ipv6'] ?? '');
            }
        }
        foreach ($addresses as $address) {
            // GLOBAL_RANGE also refuses CGNAT that NO_PRIV|NO_RES let through; it accepts NAT64/6to4, refused below.
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false || self::translatesIpv4($address)) {
                return false;
            }
        }

        // Unresolvable: nothing to fetch, and nothing proven safe.
        return $addresses !== [];
    }

    /** NAT64 (64:ff9b::/96) and 6to4 (2002::/16) carry an IPv4 address, loopback included: nothing an audit fetches. */
    private static function translatesIpv4(string $address): bool
    {
        $packed = (string) inet_pton($address);

        return strlen($packed) === 16
            && (str_starts_with($packed, "\x00\x64\xff\x9b" . str_repeat("\x00", 8)) || str_starts_with($packed, "\x20\x02"));
    }

    /** "scheme://host:port" with the default port filled in, or null for anything but http(s). */
    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return null;
        }

        return $scheme . '://' . $host . ':' . ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }
}
