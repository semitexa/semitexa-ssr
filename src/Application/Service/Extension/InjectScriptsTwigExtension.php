<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Extension;

use Semitexa\Core\Environment;
use Semitexa\Ssr\Attribute\AsTwigExtension;
use Twig\Markup;

#[AsTwigExtension]
final class InjectScriptsTwigExtension
{
    public function registerFunctions(): void
    {
        TwigExtensionRegistry::registerFunction(
            'inject_scripts',
            [$this, 'renderInjectedScripts'],
            ['is_safe' => ['html']],
        );
    }

    public function renderInjectedScripts(string $position = 'body'): string|Markup
    {
        $key = match ($position) {
            'head' => 'INJECT_HEAD',
            'body' => 'INJECT_BODY',
            default => null,
        };

        if ($key === null) {
            return '';
        }

        $value = Environment::getEnvValue($key);

        if ($value === null || $value === '') {
            return '';
        }

        return new Markup($value, 'UTF-8');
    }
}
