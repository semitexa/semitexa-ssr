<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\Sitemap;

use Attribute;

/**
 * Keeps a route out of sitemap.xml without changing what it serves.
 *
 * The route-based provider contributes every public GET route that renders HTML, and
 * 'public' in Semitexa means only 'no session required' — which is equally true of the
 * front page and of /password/reset. Sign-in pages, invitation landings and one-time-token
 * URLs are therefore advertised to search engines by default, and until now an application
 * had no way to subtract one: the generator collects from providers and never filters.
 *
 * The alternatives are worse. Declaring `produces: ['application/json']` drops a route from
 * the sitemap through the HTML-likeness check, but it lies about the response. A `noindex`
 * meta tag asks a crawler not to index a page it has already been told to fetch. This says
 * the honest thing — the page is real and public, it is simply not a landing page — in the
 * one place that decides.
 *
 * ```php
 * #[AsPublicPayload(path: '/password/reset', methods: ['GET'])]
 * #[NotInSitemap]
 * final class PasswordResetPayload {}
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class NotInSitemap
{
}
