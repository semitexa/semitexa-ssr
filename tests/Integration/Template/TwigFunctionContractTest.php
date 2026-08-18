<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Integration\Template;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateCatalog;

/**
 * The template contract: every Twig function a `.twig` file may call.
 *
 * This list is public API in the most literal sense — every template in every
 * module and every marketing site calls into it, and Twig resolves those names
 * at *render* time. A dropped name is therefore not a test failure or a boot
 * failure; it is a 500 on whichever page happens to use it, discovered by
 * whoever opens that page first. Nothing else in the suite would notice.
 *
 * ep-slay-template-catalog moves these twenty out of the 382-line
 * `ModuleTemplateCatalog::registerFunctions()` and into `#[AsTwigExtension]`
 * classes — the discovery seam that method already drains. This test is
 * deliberately indifferent to *where* a function comes from: it boots the real
 * catalog and asks the real Twig environment, so it holds identically before,
 * during and after that move. That is the whole point of pinning here rather
 * than on the source.
 *
 * A failure means a name vanished. Restore it or, if the removal is intended,
 * delete it from this list in the same commit — and check the templates first.
 */
final class TwigFunctionContractTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function contractFunctions(): array
    {
        return array_map(
            static fn (string $name): array => [$name],
            [
                // layout + components
                'layout_slot',
                'layout_slot_deferred',
                'component',
                'slot',
                'component_event_attrs',
                // SEO / document head
                'page_title',
                'meta',
                'semantic_head',
                // assets
                'asset',
                'asset_head',
                'asset_body',
                'asset_require',
                // urls
                'url',
                'current_url',
                'current_absolute_url',
                'locale_url',
                'locale_switch_url',
                // i18n
                'trans',
                'trans_choice',
                'locale',
                // contributed by existing #[AsTwigExtension] classes — listed so
                // this stays the single written-down template surface, and so a
                // package silently losing its extension shows up here
                'enum',
                'enum_cases',
                'primitive',
                'icon',
                'inject_scripts',
                'ui_part',
                'ui_part_props',
                'ui_component_instance',
                'ui_component_instance_for',
                'ui_event_manifest',
                'ui_collab_manifest',
                'ui_field_rules',
                'ui_form_submit_fields',
                'ui_form_field_submit_marker',
                'ui_form_resolve_submit_fields',
                'ui_form_resolve_submit_action',
                'ui_form_strip_submit_markers',
                'ui_component_events',
                'ui_form_issue_submit_csrf',
                'ui_page_sse_session',
                'ui_page_sse_session_meta',
                'theme_asset',
                'theme_info',
                'theme_layout',
                'theme_skin_css',
                'theme_template',
            ],
        );
    }

    /**
     * Names contributed by packages an app may or may not install.
     *
     * The always-on list above can demand every name unconditionally because the
     * packages behind it are part of every app. These are not: `semitexa/demo`
     * and `semitexa/showcase-kit` are optional, and the surface they add appears
     * only where they are required. Declaring them in the list above would fail
     * the per-name check in every app without them — declaring them nowhere let
     * six functions register unwritten-down, which is what the release clone
     * (which does install the demo) caught.
     *
     * Keyed by the extension that owns each name, so the per-name check can ask
     * whether the owner is installed before demanding its functions, and so a
     * name that outlives its extension is visible here rather than mysterious.
     *
     * @return array<string, list<string>>
     */
    public static function optionalContractFunctions(): array
    {
        return [
            // Written as strings, not ::class — these classes are absent from the
            // apps where the packages are not installed, and a ::class constant
            // would make static analysis of this package chase a class it cannot
            // see.
            'Semitexa\\Demo\\Application\\Service\\Twig\\CodeHighlightTwigExtension' => [
                'highlight_php',
                'highlight_php_lines',
                'highlight_snippet',
            ],
            'Semitexa\\Demo\\Application\\Service\\Twig\\DemoTitleIconTwigExtension' => [
                'demo_title_icon',
            ],
            'App\\Modules\\ShowcaseKit\\Application\\Service\\ShowcaseKitAssetsTwigExtension' => [
                'require_module',
            ],
            'App\\Modules\\ShowcaseKit\\Application\\Service\\ShowcaseKitCodeTwigExtension' => [
                'sk_code_block',
                'sk_code_tabs',
            ],
        ];
    }

    /**
     * @return list<array{string, string}>
     */
    public static function optionalContractRows(): array
    {
        $rows = [];
        foreach (self::optionalContractFunctions() as $owner => $names) {
            foreach ($names as $name) {
                $rows[] = [$name, $owner];
            }
        }

        return $rows;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('contractFunctions')]
    #[Test]
    public function the_template_contract_still_offers(string $function): void
    {
        // assertInstanceOf, NOT assertNotFalse: Twig answers an unknown name
        // with null, and assertNotFalse(null) passes — which made an earlier
        // version of this test assert nothing at all for all 46 names. Caught
        // only by deliberately renaming a function and seeing the suite stay
        // green. Demand the real object.
        self::assertInstanceOf(
            \Twig\TwigFunction::class,
            self::bootedTwig()->getFunction($function),
            "Twig function '{$function}' is no longer registered. Every .twig calling it now fails at "
            . 'render time, not at boot — restore it, or remove it from this contract in the same commit.',
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('optionalContractRows')]
    #[Test]
    public function the_optional_contract_still_offers(string $function, string $owner): void
    {
        // Same demand as the check above, conditioned on the owner being here.
        // Skipping when it is not keeps this honest in both directions: the app
        // that installs the package still has its functions pinned, and the app
        // that does not is not asked for a surface it never claimed.
        if (!class_exists($owner)) {
            self::markTestSkipped("{$owner} is not installed in this app; '{$function}' is not expected.");
        }

        self::assertInstanceOf(
            \Twig\TwigFunction::class,
            self::bootedTwig()->getFunction($function),
            "Twig function '{$function}' is no longer registered, but {$owner} is installed. Every .twig "
            . 'calling it now fails at render time — restore it, or drop it from this contract in the same commit.',
        );
    }

    #[Test]
    public function the_contract_list_matches_what_is_actually_registered(): void
    {
        // The counterpart to the per-name checks: those catch a *removal*, this
        // catches an *addition* nobody wrote down. A new template function is
        // fine — it just has to be declared here so the contract stays the one
        // place that says what templates may call.
        $declared = array_map(static fn (array $row): string => $row[0], self::contractFunctions());
        foreach (self::optionalContractFunctions() as $names) {
            foreach ($names as $name) {
                $declared[] = $name;
            }
        }

        $undeclared = [];
        foreach (self::frameworkFunctionNames() as $name) {
            if (!in_array($name, $declared, true)) {
                $undeclared[] = $name;
            }
        }
        sort($undeclared);

        self::assertSame(
            [],
            $undeclared,
            'Twig functions are registered that this contract does not list. Add them here (or to the '
            . 'extension that owns them) so the template surface stays written down.',
        );
    }

    /**
     * Function names this framework adds, with stock Twig subtracted.
     *
     * Twig ships its own (`parent`, `block`, `date`, `include`, …) and those are
     * not ours to declare; diffing against a bare environment keeps the contract
     * about the framework's surface rather than Twig's release notes.
     *
     * @return list<string>
     */
    private static function frameworkFunctionNames(): array
    {
        $stock = array_keys(
            (new \Twig\Environment(new \Twig\Loader\ArrayLoader()))->getFunctions(),
        );

        return array_values(array_diff(array_keys(self::bootedTwig()->getFunctions()), $stock));
    }

    private static function bootedTwig(): \Twig\Environment
    {
        static $twig = null;
        if ($twig !== null) {
            return $twig;
        }

        $classDiscovery = new ClassDiscovery();
        $classDiscovery->initialize();
        // The catalog drains TwigExtensionRegistry as part of building its
        // environment, and the registry discovers #[AsTwigExtension] classes —
        // so this wiring is what makes extension-provided functions visible here.
        TwigExtensionRegistry::setClassDiscovery($classDiscovery);

        $moduleRegistry = new ModuleRegistry();
        $moduleRegistry->initialize();

        $catalog = new ModuleTemplateCatalog();
        $catalog->setModuleRegistry($moduleRegistry);

        return $twig = $catalog->getTwig();
    }
}
