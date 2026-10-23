<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Extension;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Feature\CurrentFeatureFlags;
use Semitexa\Core\Feature\FeatureFlagsInterface;
use Semitexa\Core\Feature\InMemoryFeatureFlags;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Ssr\Application\Service\Extension\FeatureFlagTwigExtension;

final class FeatureFlagTwigExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        CoroutineLocal::resetCliStore();
    }

    /** A mail a worker renders: every flag was off there, one declared on by default too. */
    #[Test]
    public function outside_a_request_the_container_s_flags_answer(): void
    {
        CoroutineLocal::resetCliStore();
        $extension = self::extension(new InMemoryFeatureFlags(['search.semantic' => true]));

        self::assertTrue($extension->isEnabled('search.semantic'));
        self::assertFalse($extension->isEnabled('checkout.new-flow'));
    }

    #[Test]
    public function in_a_request_its_own_flags_answer(): void
    {
        $extension = self::extension(new InMemoryFeatureFlags(['search.semantic' => true]));
        CurrentFeatureFlags::set(new InMemoryFeatureFlags(['checkout.new-flow' => true]));

        self::assertTrue($extension->isEnabled('checkout.new-flow'));
        self::assertFalse($extension->isEnabled('search.semantic'), 'the request\'s flags, not the fallback');
    }

    /** A site's mail, rendered by a worker with no tenant: answered with every site's setting. */
    #[Test]
    public function a_named_subject_is_asked_for_and_the_request_s_flags_wait(): void
    {
        $perSite = new class implements FeatureFlagsInterface {
            public ?string $tenant = null;
            public function isEnabled(string $flag): bool { return $this->tenant !== 'acme'; }
            public function forSubject(?string $tenantId, ?string $userId): FeatureFlagsInterface
            {
                $pinned = clone $this;
                $pinned->tenant = $tenantId;

                return $pinned;
            }
        };
        $extension = self::extension($perSite);
        CurrentFeatureFlags::set(new InMemoryFeatureFlags(['promo.banner' => true]));

        self::assertFalse(CurrentFeatureFlags::forSubjectDuring('acme', null, static fn (): bool => $extension->isEnabled('promo.banner')), 'acme turned it off');
        self::assertTrue($extension->isEnabled('promo.banner'), 'the request\'s own flags are back');
        self::assertNull(CurrentFeatureFlags::subject());
    }

    #[Test]
    public function a_container_that_cannot_answer_leaves_every_flag_off(): void
    {
        CoroutineLocal::resetCliStore();

        self::assertFalse(self::extension(null)->isEnabled('search.semantic'));
        self::assertFalse((new FeatureFlagTwigExtension())->isEnabled('search.semantic'), 'built without a container');
    }

    private static function extension(?FeatureFlagsInterface $flags): FeatureFlagTwigExtension
    {
        $container = new class ($flags) implements ContainerInterface {
            public function __construct(private ?FeatureFlagsInterface $flags) {}
            public function get(string $id): mixed
            {
                return $this->flags ?? throw new \RuntimeException('no flags here');
            }
            public function has(string $id): bool { return $this->flags !== null; }
        };
        $extension = new FeatureFlagTwigExtension();
        (new \ReflectionProperty($extension, 'container'))->setValue($extension, $container);

        return $extension;
    }
}
