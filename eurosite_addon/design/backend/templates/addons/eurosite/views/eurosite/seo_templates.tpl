{**
 * Eurosite — SEO Templates admin page.
 *
 * Travel Core's shared page: the form, language tabs, placeholder sidebar,
 * click-to-insert and preview all live in components/seo_templates_page.tpl.
 * The controller (eurosite.php, mode seo_templates) assigns $seo_page.
 *}
{include file="addons/travel_core/components/seo_templates_page.tpl"
    seo_form_id="eurosite_seo_form"
    seo_placeholders_title=__("eurosite.seo_placeholders_title", ["[default]" => "Eurosite placeholders"])
}
