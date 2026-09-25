<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Seo;

/**
 * What Sphinx gives Travel Core's shared SEO Templates page and engine
 * (fn_sphinx_holidays_seo_defaults / _seo_placeholders / _seo_page in
 * func.php return these).
 */
final class SeoTemplateDefinitions
{
    /**
     * Built-in templates and ticks. The single source for the seed routine
     * and the engine's fallback when no template was saved; a "__<lang>" key
     * is that language's own default (_travel_core_seo_template_for).
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'seo_overwrite_mode' => 'override_all',
            'seo_product_name' => '{{name}}',
            'seo_page_title' => '{{name}} {{classification}}* - {{city}}, {{country}}',
            'seo_meta_description' => 'Book {{name}} in {{city}}, {{country}}. {{classification}}-star {{property_type}} with {{facilities}}.',
            'seo_meta_keywords' => '{{name}}, {{city}}, {{country}}, {{property_type}}, {{classification}} star',
            'seo_name_slug' => '{{name}}-{{city}}-{{country}}',
            'seo_full_description' => '',
            'seo_meta_description__ro' => 'Rezervă {{name}} în {{city}}, {{country}}. {{property_type}} de {{classification}} stele cu {{facilities}}.',
            'seo_meta_keywords__ro' => '{{name}}, {{city}}, {{country}}, {{property_type}}, {{classification}} stele',
            'seo_field_product_name' => 'Y',
            'seo_field_page_title' => 'Y',
            'seo_field_meta_description' => 'Y',
            'seo_field_meta_keywords' => 'Y',
            'seo_field_name_slug' => 'Y',
            'seo_field_full_description' => 'Y',
        ];
    }

    /**
     * The placeholders SphinxProductFactory::buildPlaceholders() fills, as the
     * page lists them (Travel Core labels each bare key).
     *
     * @return array<string, list<string>>
     */
    public static function placeholders(): array
    {
        return [
            'hotel' => ['name', 'classification', 'stars_emoji', 'property_type', 'rating', 'facilities', 'boards', 'description', 'image_url'],
            'location' => ['city', 'country', 'region', 'address', 'latitude', 'longitude'],
            'contact' => ['phone', 'email', 'website'],
            'other' => ['year'],
        ];
    }

    /**
     * This add-on's tab in the SEO Templates provider row.
     *
     * @return array{name: string, dispatch: string}
     */
    public static function page(): array
    {
        return ['name' => 'Sphinx', 'dispatch' => 'sphinx_seo_templates.manage'];
    }
}
