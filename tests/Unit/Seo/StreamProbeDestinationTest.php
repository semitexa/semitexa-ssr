<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Seo\Readiness\StreamProbe;

/**
 * The probe fetches http(s) only, and a foreign host only at a public address:
 * the audited site itself may be localhost, a link it serves may not.
 */
final class StreamProbeDestinationTest extends TestCase
{
    #[Test]
    public function only_http_and_https_are_fetched(): void
    {
        $probe = new StreamProbe(siteOrigin: 'https://site.test');

        self::assertFalse($probe->mayFetch('/dev/zero'));
        self::assertFalse($probe->mayFetch('file:///etc/passwd'));
        self::assertFalse($probe->mayFetch('php://stdin'));
        self::assertFalse($probe->mayFetch('ftp://site.test/x'));
        self::assertSame(0, $probe('php://stdin')->status);
    }

    #[Test]
    public function the_audited_site_may_be_loopback(): void
    {
        $probe = new StreamProbe(siteOrigin: 'http://127.0.0.1:9507');

        self::assertTrue($probe->mayFetch('http://127.0.0.1:9507/sitemap.xml'));
        self::assertTrue((new StreamProbe(siteOrigin: 'http://LocalHost'))->mayFetch('http://localhost:80/'));
        self::assertTrue((new StreamProbe(siteOrigin: 'http://[::1]:9507'))->mayFetch('http://[::1]:9507/robots.txt'));
    }

    #[Test]
    public function only_the_sites_own_port_and_scheme_are_exempt(): void
    {
        $probe = new StreamProbe(siteOrigin: 'http://localhost:9507');

        self::assertFalse($probe->mayFetch('http://localhost:6379/'));
        self::assertFalse($probe->mayFetch('https://localhost:9507/'));
    }

    #[Test]
    public function a_foreign_host_must_be_public(): void
    {
        $probe = new StreamProbe(siteOrigin: 'https://site.test');

        self::assertFalse($probe->mayFetch('http://127.0.0.1:6379/'));
        self::assertFalse($probe->mayFetch('http://169.254.169.254/latest/meta-data/'));
        self::assertFalse($probe->mayFetch('http://10.0.0.5/'));
        self::assertFalse($probe->mayFetch('http://192.168.1.1/'));
        self::assertFalse($probe->mayFetch('http://[::1]/'));
        self::assertFalse($probe->mayFetch('http://100.64.0.1/'));
        self::assertFalse($probe->mayFetch('http://[64:ff9b::7f00:1]/'));
        self::assertFalse($probe->mayFetch('http://[2002:7f00:1::]/'));
        self::assertTrue($probe->mayFetch('https://93.184.215.14/'));
    }
}
