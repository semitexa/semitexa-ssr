<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\I18n;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Locale\Configuration\LocaleConfig;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;
use Semitexa\Ssr\Application\Service\I18n\SettingsLocalePackProvider;

/**
 * SettingsLocalePackProvider overlays the current tenant's `locale` settings
 * onto the global pack. SettingsStore is already #[TenantScoped], so getAll()
 * returns the ambient tenant's values — this pins the overlay + guard rules.
 */
final class SettingsLocalePackProviderTest extends TestCase
{
    private function base(): LocaleConfig
    {
        return new LocaleConfig(
            defaultLocale: 'en',
            fallbackLocale: 'en',
            supportedLocales: ['en', 'uk', 'de'],
            resolverPriority: ['header'],
            urlPrefixEnabled: true,
        );
    }

    private function provider(array $pack): SettingsLocalePackProvider
    {
        $p = new SettingsLocalePackProvider();
        (new \ReflectionProperty(SettingsLocalePackProvider::class, 'settings'))
            ->setValue($p, new StubSettingsStore(['locale' => $pack]));

        return $p;
    }

    #[Test]
    public function a_tenant_pack_overlays_default_fallback_and_supported(): void
    {
        $out = $this->provider(['default' => 'pl', 'fallback' => 'pl', 'supported' => ['pl', 'en']])
            ->resolvedPack($this->base());

        self::assertSame('pl', $out->defaultLocale);
        self::assertSame('pl', $out->fallbackLocale);
        self::assertSame(['pl', 'en'], $out->supportedLocales);
        // Resolution mechanics stay from the base (global) config.
        self::assertSame(['header'], $out->resolverPriority);
        self::assertTrue($out->urlPrefixEnabled);
    }

    #[Test]
    public function unset_fields_fall_through_to_the_base(): void
    {
        // Only 'supported' set; default/fallback inherit the base.
        $out = $this->provider(['supported' => ['de', 'en']])->resolvedPack($this->base());

        self::assertSame(['de', 'en'], $out->supportedLocales);
        self::assertSame('en', $out->defaultLocale);  // base default, and it IS in the set
        self::assertSame('en', $out->fallbackLocale);
    }

    #[Test]
    public function a_default_outside_the_tenant_set_is_repaired_not_served(): void
    {
        // Tenant restricts to fr/de but leaves default unset → base default 'en'
        // is not in {fr,de} → must fall to the first supported, never serve 'en'.
        $out = $this->provider(['supported' => ['fr', 'de']])->resolvedPack($this->base());

        self::assertContains($out->defaultLocale, ['fr', 'de']);
        self::assertNotSame('en', $out->defaultLocale, 'A default the tenant does not offer must not be served.');
    }

    #[Test]
    public function an_empty_pack_returns_the_base_unchanged(): void
    {
        $out = $this->provider([])->resolvedPack($this->base());

        self::assertSame($this->base()->supportedLocales, $out->supportedLocales);
        self::assertSame('en', $out->defaultLocale);
    }
}

final class StubSettingsStore implements SettingsStoreInterface
{
    /** @param array<string, array<string, mixed>> $byModule */
    public function __construct(private readonly array $byModule) {}

    public function getAll(string $moduleKey): array
    {
        return $this->byModule[$moduleKey] ?? [];
    }

    public function get(string $moduleKey, string $key): mixed
    {
        return $this->byModule[$moduleKey][$key] ?? null;
    }

    public function getForUser(string $moduleKey, string $key, string $userId): mixed
    {
        return $this->get($moduleKey, $key);
    }

    public function getAllForUser(string $moduleKey, string $userId): array
    {
        return $this->getAll($moduleKey);
    }

    public function set(string $moduleKey, string $key, mixed $value): void
    {
    }

    public function setForUser(string $moduleKey, string $key, mixed $value, string $userId): void
    {
    }

    public function claim(string $moduleKey, string $key, mixed $expected, mixed $next): bool
    {
        return true;
    }

    public function remove(string $moduleKey, string $key): void
    {
    }

    public function removeForUser(string $moduleKey, string $key, string $userId): void
    {
    }

    public function has(string $moduleKey, string $key): bool
    {
        return isset($this->byModule[$moduleKey][$key]);
    }

    public function hasForUser(string $moduleKey, string $key, string $userId): bool
    {
        return $this->has($moduleKey, $key);
    }
}
