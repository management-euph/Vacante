<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the per-language SEO template contract end to end.
 *
 * Templates used to be ONE language-less string set per provider, and the
 * engine wrote the SAME rendered text into every storefront language. Now:
 * template strings carry a per-language dimension ("<key>__<lang>" settings
 * with a stored-shared → built-in-language → built-in-base fallback chain),
 * every write path renders per language, and both providers' admin pages
 * edit one field set per storefront language through a SHARED component.
 * (Behaviour itself is covered by ApplySeoFieldsTest — this test keeps the
 * wiring from silently regressing to the single-language shape.)
 */
final class SeoTemplatesPerLanguageTest extends TestCase
{
    private static function addonRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 7);
    }

    public function testEngineCarriesTheLanguageDimension(): void
    {
        $src = (string) file_get_contents(self::addonRoot() . '/functions/seo.php');

        self::assertStringContainsString(
            'function fn_travel_core_apply_seo_fields(string $addonName, array $placeholders, int $productId = 0, ?string $hotelId = null, string $langCode = \'\'): array',
            $src,
        );
        // Resolution chain lives in one helper — stored values are read for
        // the LANGUAGE KEY ONLY (no language-less stored fallback; fresh-addon
        // policy), defaults fall back __<lang> → base.
        self::assertStringContainsString('function _travel_core_seo_template_for(', $src);
        self::assertStringContainsString("\$stored = \$settings[\$templateKey . '__' . \$langCode] ?? '';", $src);
        self::assertStringContainsString("[\$templateKey . '__' . \$langCode, \$templateKey]", $src);
        // …and fill-if-empty inspects the RENDERED language's own values.
        self::assertStringContainsString('$productId,
            $langCode,', $src);

        // The per-product localizer + the admin form/save helpers.
        self::assertStringContainsString('function fn_travel_core_seo_localize(', $src);
        self::assertStringContainsString('function fn_travel_core_seo_lang_form_data(', $src);
        self::assertStringContainsString('function fn_travel_core_seo_save_lang_templates(', $src);
        // Bulk apply renders through the localizer (per language), not once.
        self::assertStringContainsString(
            '$wroteAny = fn_travel_core_seo_localize($addonName, $placeholders, $productId, $hotelId, $languages);',
            $src,
        );
    }

    public function testEveryWritePathRendersPerLanguage(): void
    {
        $sites = [
            '/addon-novoton-holidays/app/addons/novoton_holidays/src/Helpers/ProductFactory.php',
            '/addon-novoton-holidays/app/addons/novoton_holidays/src/Cron/Commands/AddProductsCommand.php',
            '/addon-sphinx-holidays/app/addons/sphinx_holidays/src/Helpers/SphinxProductFactory.php',
            '/addon-sphinx-holidays/app/addons/sphinx_holidays/src/Cron/Commands/UpdateProductsCommand.php',
            '/addon-novoton-holidays/app/addons/novoton_holidays/src/Cron/Commands/OffersUpdateCommand.php',
            '/eurosite_addon/app/addons/eurosite/src/Services/EurositeProductFactory.php',
        ];
        foreach ($sites as $rel) {
            self::assertStringContainsString(
                'fn_travel_core_seo_localize(',
                (string) file_get_contents(self::repoRoot() . $rel),
                $rel,
            );
        }
    }

    public function testBothProvidersShipRomanianDefaults(): void
    {
        foreach ([
            '/addon-novoton-holidays/app/addons/novoton_holidays/func.php',
            '/addon-sphinx-holidays/app/addons/sphinx_holidays/src/Seo/SeoTemplateDefinitions.php',
        ] as $rel) {
            $src = (string) file_get_contents(self::repoRoot() . $rel);
            self::assertStringContainsString("'seo_meta_description__ro'", $src, $rel);
            self::assertStringContainsString("'seo_meta_keywords__ro'", $src, $rel);
        }
    }

    /** @return array<string, array{controller: string, view: string}> */
    private static function providerPages(): array
    {
        return [
            'novoton' => [
                'controller' => '/addon-novoton-holidays/app/addons/novoton_holidays/controllers/backend/novoton_seo_templates.php',
                'view'       => '/addon-novoton-holidays/design/backend/templates/addons/novoton_holidays/views/novoton_seo_templates/manage.tpl',
            ],
            'sphinx' => [
                'controller' => '/addon-sphinx-holidays/app/addons/sphinx_holidays/controllers/backend/sphinx_seo_templates.php',
                'view'       => '/addon-sphinx-holidays/design/backend/templates/addons/sphinx_holidays/views/sphinx_seo_templates/manage.tpl',
            ],
            'eurosite' => [
                'controller' => '/eurosite_addon/app/addons/eurosite/controllers/backend/eurosite.php',
                'view'       => '/eurosite_addon/design/backend/templates/addons/eurosite/views/eurosite/seo_templates.tpl',
            ],
        ];
    }

    public function testEveryProviderPageIsTheSharedPage(): void
    {
        $component = (string) file_get_contents(
            self::repoRoot()
            . '/addon-travel-core/design/backend/templates/addons/travel_core/components/seo_lang_fields.tpl',
        );
        self::assertStringContainsString('name="seo_lang[{$seo_lc}][{$f.key}]"', $component);
        self::assertStringContainsString('{foreach $seo_languages as $seo_lc => $seo_lang_name}', $component);
        // The ticks stay GLOBAL — one named set gating every language; the
        // copies in the other language tabs carry no name.
        self::assertStringContainsString('{if $seo_first_lang}name="seo[{$toggle_key}]" value="Y"{/if}', $component);

        $page = (string) file_get_contents(
            self::repoRoot()
            . '/addon-travel-core/design/backend/templates/addons/travel_core/components/seo_templates_page.tpl',
        );
        self::assertStringContainsString('{include file="addons/travel_core/components/seo_lang_fields.tpl"}', $page);
        // The form posts to the save URL: the Save button sits outside it.
        self::assertStringContainsString('action="{$seo_page.save_url}"', $page);

        foreach (self::providerPages() as $name => $files) {
            $tpl = (string) file_get_contents(self::repoRoot() . $files['view']);
            self::assertStringContainsString(
                '{include file="addons/travel_core/components/seo_templates_page.tpl"',
                $tpl,
                $name,
            );
            // The language-less template fields must not return.
            self::assertStringNotContainsString('name="seo[seo_page_title]"', $tpl, $name);

            $controller = (string) file_get_contents(self::repoRoot() . $files['controller']);
            foreach (['fn_travel_core_seo_page_save(', 'fn_travel_core_seo_page_bulk_apply(', 'fn_travel_core_seo_page_assign('] as $call) {
                self::assertStringContainsString($call, $controller, $name . ': ' . $call);
            }
        }

        // The shared save writes the mode and the ticks globally and the six
        // templates per language only — never '' into the language-less keys.
        $seo = (string) file_get_contents(self::addonRoot() . '/functions/seo.php');
        self::assertStringContainsString('foreach (array_keys(_travel_core_seo_field_map()) as $toggleKey) {
        $toSave[$toggleKey] = !empty($submitted[$toggleKey]) ? \'Y\' : \'N\';', $seo);
        self::assertStringContainsString('fn_travel_core_seo_save_lang_templates($addonName, $seoLang);', $seo);
        // …persisted in ?:storage_data: Settings rows no addon.xml declares
        // don't survive a reload on live stores.
        self::assertStringContainsString("return 'travel_core_seo_' . \$addonName;", $seo);
        self::assertStringContainsString('fn_set_storage_data(', $seo);
        self::assertStringContainsString('$settings = fn_travel_core_seo_settings($addonName);', $seo);
        self::assertStringContainsString('$langData = fn_travel_core_seo_lang_form_data($addonName, $defaults);', $seo);
    }
}
