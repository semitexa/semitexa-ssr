<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Theme;

use Semitexa\Core\Tenant\Layer\ThemeValue;
use Semitexa\Core\Util\ProjectRoot;

final class ThemeManager
{
    private static ?self $instance = null;

    private ThemeValue $currentTheme;
    private array $themePaths = [];

    private function __construct()
    {
        $this->currentTheme = ThemeValue::default();
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function getTheme(): ThemeValue
    {
        return $this->currentTheme;
    }

    public function setTheme(ThemeValue $theme): void
    {
        $this->currentTheme = $theme;
    }

    public function resolveTemplatePath(string $template): string
    {
        $theme = $this->currentTheme->theme;

        if ($theme === 'default') {
            return $template;
        }

        $themeTemplate = $this->getThemeTemplatePath($theme, $template);
        
        if ($themeTemplate !== null && file_exists($themeTemplate)) {
            return $themeTemplate;
        }

        return $template;
    }

    private function getThemeTemplatePath(string $theme, string $template): ?string
    {
        $basePath = ProjectRoot::get() . '/src/modules';
        
        $parts = explode('::', $template, 2);
        
        if (count($parts) === 2) {
            [$module, $path] = $parts;
            return "{$basePath}/{$module}/Application/View/themes/{$theme}/{$path}";
        }

        return null;
    }

    public function getThemeAssetsPath(string $theme): string
    {
        return ProjectRoot::get() . "/public/themes/{$theme}";
    }

    public static function get(): ?self
    {
        return self::$instance;
    }

    public static function getOrFail(): self
    {
        return self::$instance ?? self::getInstance();
    }
}
