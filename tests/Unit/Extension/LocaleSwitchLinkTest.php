<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Extension;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClassConstant;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Cookie\CookieJar;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Lifecycle\LocalePhase;
use Semitexa\Core\Lifecycle\RequestLifecycleContext;
use Semitexa\Core\Request;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Locale\Application\Service\LocaleBootstrapper;
use Semitexa\Locale\Configuration\LocaleConfig;
use Semitexa\Locale\Context\LocaleContextStore;
use Semitexa\Locale\Context\LocaleManager;
use Semitexa\Ssr\Application\Service\Extension\LocaleTwigExtension;
use Swoole\Coroutine;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;

/**
 * The language switcher against the locale phase that answers its links.
 *
 * With `locale=uk` stored, `locale_switch_url('en')` linked to the bare path,
 * and the phase sent a bare path with that cookie back to `/uk/...`: the
 * default language could not be reached through the framework's own switcher.
 * Each half was tested alone and passed; the trap was between them, so this
 * test clicks the link the helper renders and follows what the phase answers.
 */
final class LocaleSwitchLinkTest extends TestCase
{
    private const SUPPORTED = ['en', 'uk', 'de'];

    #[Test]
    public function the_default_language_is_reached_from_a_page_in_the_stored_one(): void
    {
        $visit = $this->click('/uk/app/bookings', 'en', ['locale' => 'uk']);

        self::assertSame('/app/bookings', $visit['url'], 'hops: ' . implode(' -> ', $visit['hops']));
        self::assertSame('en', $visit['cookies']['locale'] ?? null);
        self::assertSame('en', $visit['locale']);
    }

    #[Test]
    public function another_language_is_reached_and_becomes_the_stored_one(): void
    {
        $visit = $this->click('/uk/app/bookings', 'de', ['locale' => 'uk']);

        self::assertSame('/de/app/bookings', $visit['url'], 'hops: ' . implode(' -> ', $visit['hops']));
        self::assertSame('de', $visit['cookies']['locale'] ?? null);
        self::assertSame('de', $visit['locale']);

        // And the bare link in yesterday's email now opens in the language
        // chosen today, not the one chosen before it.
        $bookmark = $this->browse('/app/bookings', $visit['cookies']);
        self::assertSame('/de/app/bookings', $bookmark['url']);
    }

    #[Test]
    public function from_the_default_language_to_another_and_back(): void
    {
        $there = $this->click('/app/bookings', 'uk', ['locale' => 'en']);
        self::assertSame('/uk/app/bookings', $there['url']);
        self::assertSame('uk', $there['cookies']['locale'] ?? null);

        $back = $this->click($there['url'], 'en', $there['cookies']);
        self::assertSame('/app/bookings', $back['url'], 'hops: ' . implode(' -> ', $back['hops']));
        self::assertSame('en', $back['cookies']['locale'] ?? null);
    }

    /** A visitor with no stored preference is given none: the switch is only a navigation. */
    #[Test]
    public function a_visitor_without_the_cookie_switches_without_getting_one(): void
    {
        $visit = $this->click('/app/bookings', 'uk', []);

        self::assertSame('/uk/app/bookings', $visit['url']);
        self::assertSame([], $visit['cookies']);

        $back = $this->click($visit['url'], 'en', []);
        self::assertSame('/app/bookings', $back['url']);
    }

    /**
     * The request's own path is where the link comes from, so a path that a
     * browser would read as another host must not survive the round trip.
     */
    #[Test]
    public function a_hostile_current_path_yields_a_link_and_a_redirect_on_this_site(): void
    {
        $link = $this->switchLink('/uk//evil.example/x', 'en');
        self::assertSame('/evil.example/x?_locale=en', $link);

        $visit = $this->browse($link, ['locale' => 'uk']);
        self::assertSame('/evil.example/x', $visit['url']);
        // The marker is answered with a redirect: at least that hop is checked,
        // and each is a path on this site: one slash, then not another.
        self::assertNotSame([], $visit['hops']);
        foreach ($visit['hops'] as $hop) {
            self::assertMatchesRegularExpression('#\A/(?!/)#', $hop);
        }
    }

    #[Test]
    public function an_ordinary_locale_link_is_not_a_choice(): void
    {
        $url = null;
        $this->inRequest('/uk/app', static function () use (&$url): void {
            $url = (new LocaleTwigExtension())->localeUrl('/app', 'en');
        });

        self::assertSame('/app', $url);
    }

    #[Test]
    public function without_url_prefixes_and_for_an_unknown_language_the_link_is_plain(): void
    {
        self::assertSame('/app', $this->switchLink('/app', 'uk', false));
        self::assertSame('/pl/app', $this->switchLink('/uk/app', 'pl'), 'as before: a language the site does not list gets no marker');
    }

    /**
     * Render the switcher on `$currentUrl`, click the link for `$target`.
     *
     * @param array<string, string> $cookies
     *
     * @return array{url: string, cookies: array<string, string>, hops: list<string>, locale: string}
     */
    private function click(string $currentUrl, string $target, array $cookies): array
    {
        return $this->browse($this->switchLink($currentUrl, $target), $cookies);
    }

    private function switchLink(string $currentUrl, string $target, bool $urlPrefix = true): string
    {
        $link = '';
        $this->inRequest($currentUrl, static function () use (&$link, $target): void {
            $link = (new LocaleTwigExtension())->localeSwitchUrl($target);
        }, $urlPrefix);

        return $link;
    }

    /**
     * Run `$body` as template code runs: in a coroutine with the Swoole request
     * bound and the locale store filled as the locale phase leaves it. The
     * store lives in the coroutine, so nothing outlives the call.
     */
    private function inRequest(string $path, \Closure $body, bool $urlPrefix = true): void
    {
        $request = $this->createStub(SwooleRequest::class);
        $request->server = ['request_method' => 'GET', 'request_uri' => $path];
        $bound = [$request, $this->createStub(SwooleResponse::class), $this->createStub(Server::class)];

        Coroutine\run(static function () use ($bound, $body, $urlPrefix): void {
            $key = (new ReflectionClassConstant(SwooleBootstrap::class, 'COROUTINE_CONTEXT_KEY'))->getValue();
            $coroutine = Coroutine::getContext();
            self::assertIsString($key);
            self::assertInstanceOf(\ArrayObject::class, $coroutine);
            $coroutine[$key] = $bound;
            LocaleContextStore::setUrlPrefixEnabled($urlPrefix);
            LocaleContextStore::setDefaultLocale('en');
            LocaleContextStore::setSupportedLocales(self::SUPPORTED);
            $body();
        });
    }

    /**
     * A browser: sends the cookies it holds, keeps the ones it is given,
     * follows redirects, and stops on the first URL the phase lets through.
     *
     * @param array<string, string> $cookies
     *
     * @return array{url: string, cookies: array<string, string>, hops: list<string>, locale: string}
     */
    private function browse(string $url, array $cookies): array
    {
        $hops = [$url];
        $locale = '';

        Coroutine\run(function () use (&$url, &$cookies, &$hops, &$locale): void {
            for ($i = 0; $i < 6; $i++) {
                $queryString = (string) parse_url('http://site.test' . $url, PHP_URL_QUERY);
                parse_str($queryString, $query);
                $request = new Request(
                    method: 'GET',
                    uri: $url,
                    headers: ['Host' => 'site.test', 'Accept' => 'text/html,*/*;q=0.8', 'Sec-Fetch-Site' => 'same-origin'],
                    query: $query,
                    post: [],
                    server: ['request_uri' => $url, 'query_string' => $queryString],
                    cookies: $cookies,
                );

                $container = new RequestScopedContainer(new class implements ContainerInterface {
                    public function get(string $id): mixed
                    {
                        throw new \RuntimeException("no entry {$id}");
                    }

                    public function has(string $id): bool
                    {
                        return false;
                    }
                });
                $jar = new CookieJar($request);
                $container->set(CookieJarInterface::class, $jar);
                $manager = new LocaleManager();
                $phase = new LocalePhase($container, new LocaleBootstrapper($manager, new LocaleConfig(
                    supportedLocales: self::SUPPORTED,
                    resolverPriority: ['path'],
                    urlPrefixEnabled: true,
                )));
                $context = new RequestLifecycleContext($request);
                $phase->execute($context);

                foreach ($jar->getSetCookieLines() as $line) {
                    self::assertIsString($line);
                    // The same name under the other Domain variant being
                    // expired: this browser holds one cookie per name.
                    if (str_contains($line, 'Max-Age=0')) {
                        continue;
                    }
                    [$name, $value] = explode('=', explode(';', $line, 2)[0], 2);
                    $cookies[rawurldecode($name)] = rawurldecode($value);
                }

                if (!$context->hasEarlyResponse()) {
                    $locale = $manager->getLocale();

                    return;
                }

                $location = $context->getEarlyResponse()->getHeaders()['Location'] ?? null;
                self::assertIsString($location, 'the phase answered ' . $url . ' with no page and no redirect');
                $url = $location;
                $hops[] = $url;
            }

            self::fail('never settled: ' . implode(' -> ', $hops));
        });

        return ['url' => $url, 'cookies' => $cookies, 'hops' => $hops, 'locale' => $locale];
    }
}
