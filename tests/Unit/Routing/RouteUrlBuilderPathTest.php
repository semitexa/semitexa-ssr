<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Routing\RouteUrlBuilder;

/**
 * Regression tests for {@see RouteUrlBuilder} path substitution.
 *
 * buildPath() used to run two passes per parameter: one replacing the `{key}`
 * token, then a second replacing the bare `key` anywhere in the path. The second
 * pass was unanchored, so a parameter whose name appeared inside a static segment
 * — or inside a value the first pass had already substituted — was rewritten too:
 * `/id-cards/{id}` with id=7 returned `/7-cards/7`.
 *
 * The optional form was the other half of the same defect: `{slug?}` was matched
 * by neither pass and then stripped wholesale, so an optional parameter could not
 * be supplied at all.
 *
 * buildPath() is private and the public entry point needs AttributeDiscovery plus
 * locale context, so these drive it through reflection — the substitution rule is
 * what is under test, not route lookup.
 */
final class RouteUrlBuilderPathTest extends TestCase
{
    /**
     * @param array<string, mixed> $params
     */
    private function buildPath(string $path, array $params): string
    {
        $method = new \ReflectionMethod(RouteUrlBuilder::class, 'buildPath');

        return (string) $method->invoke(new RouteUrlBuilder(), $path, $params);
    }

    #[Test]
    public function parameter_name_inside_a_static_segment_is_left_alone(): void
    {
        // The original defect, verbatim.
        self::assertSame('/id-cards/7', $this->buildPath('/id-cards/{id}', ['id' => '7']));
    }

    #[Test]
    public function substituted_value_is_not_rewritten_by_a_later_parameter(): void
    {
        // `type` appears in the value substituted for {slug}; a second unanchored
        // pass would have chewed through it.
        self::assertSame(
            '/archive/type-guide/post',
            $this->buildPath('/archive/{slug}/{type}', ['slug' => 'type-guide', 'type' => 'post']),
        );
    }

    #[Test]
    public function optional_parameter_is_filled_when_supplied(): void
    {
        self::assertSame('/posts/hello', $this->buildPath('/posts/{slug?}', ['slug' => 'hello']));
    }

    #[Test]
    public function optional_parameter_is_stripped_when_omitted(): void
    {
        self::assertSame('/posts/', $this->buildPath('/posts/{slug?}', []));
    }

    #[Test]
    public function values_are_url_encoded(): void
    {
        self::assertSame('/search/a+b%26c', $this->buildPath('/search/{q}', ['q' => 'a b&c']));
    }

    /**
     * @param array<string, mixed> $params
     */
    #[Test]
    #[DataProvider('unchangedPaths')]
    public function paths_without_matching_tokens_are_unchanged(string $path, array $params): void
    {
        self::assertSame($path, $this->buildPath($path, $params));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function unchangedPaths(): array
    {
        return [
            'no params at all' => ['/about', []],
            'param not present in path' => ['/about', ['id' => '9']],
            'static segment matching a param name' => ['/identity', ['id' => '9']],
        ];
    }
}
