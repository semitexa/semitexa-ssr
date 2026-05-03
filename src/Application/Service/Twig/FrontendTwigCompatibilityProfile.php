<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Twig;

use Twig\Node\Expression\Binary\AndBinary;
use Twig\Node\Expression\Binary\EqualBinary;
use Twig\Node\Expression\Binary\GreaterBinary;
use Twig\Node\Expression\Binary\GreaterEqualBinary;
use Twig\Node\Expression\Binary\InBinary;
use Twig\Node\Expression\Binary\LessBinary;
use Twig\Node\Expression\Binary\LessEqualBinary;
use Twig\Node\Expression\Binary\NotEqualBinary;
use Twig\Node\Expression\Binary\NotInBinary;
use Twig\Node\Expression\Binary\OrBinary;
use Twig\Node\Expression\Filter\RawFilter;
use Twig\Node\Expression\Unary\NotUnary;

final class FrontendTwigCompatibilityProfile
{
    /** @var array<class-string, true> */
    private array $allowedBinaryNodeClasses;

    /** @var array<class-string, true> */
    private array $allowedUnaryNodeClasses;

    public function __construct()
    {
        $this->allowedBinaryNodeClasses = [
            AndBinary::class => true,
            OrBinary::class => true,
            EqualBinary::class => true,
            NotEqualBinary::class => true,
            GreaterBinary::class => true,
            GreaterEqualBinary::class => true,
            LessBinary::class => true,
            LessEqualBinary::class => true,
            InBinary::class => true,
            NotInBinary::class => true,
        ];

        $this->allowedUnaryNodeClasses = [
            NotUnary::class => true,
        ];

    }

    public static function createDefault(): self
    {
        return new self();
    }

    public function supportsBinaryNode(object $node): bool
    {
        return isset($this->allowedBinaryNodeClasses[$node::class]);
    }

    public function supportsUnaryNode(object $node): bool
    {
        return isset($this->allowedUnaryNodeClasses[$node::class]);
    }

    public function supportsFunction(string $name): bool
    {
        return false;
    }

    public function supportsFilterName(string $name): bool
    {
        return false;
    }

    public function supportsTest(string $name): bool
    {
        return false;
    }
}
