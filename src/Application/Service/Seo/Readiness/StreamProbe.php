<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\Readiness;

/**
 * Plain GET over PHP streams: no cookies, no redirects followed, a short
 * timeout. That is what an agent reading the site gets, which is the point.
 */
final class StreamProbe
{
    public function __construct(
        private readonly float $timeoutSeconds = 10.0,
    ) {}

    public function __invoke(string $url): ProbeResponse
    {
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
}
