{**
 * Travel Core — per-language SEO template fields (shared component).
 *
 * Included by components/seo_templates_page.tpl INSIDE the page's <form>.
 * Renders one tab per storefront language with the six template fields,
 * posted as seo_lang[<lang>][<key>] and saved to "<key>__<lang>" settings by
 * fn_travel_core_seo_save_lang_templates().
 *
 * The "Apply" tick of each field is GLOBAL (it gates every language): the
 * first language's tick carries the name seo[seo_field_*]; the copies in the
 * other tabs have no name and seo-templates.js keeps them in step.
 *
 * Expects in scope (fn_travel_core_seo_page_assign):
 *   $seo_values       – overwrite mode + seo_field_* ticks
 *   $seo_languages    – lang_code => language name
 *   $seo_lang_values  – lang_code => [template_key => effective value]
 *   $seo_page         – defaults (built-in templates per language), sample_name
 *}

{$seo_tpl_fields = [
    ['key' => 'seo_product_name',     'type' => 'input',    'ideal' => '80',  'max' => '255', 'rows' => '',  'label' => 'travel_core.seo_product_name',     'desc' => 'travel_core.seo_product_name_desc'],
    ['key' => 'seo_page_title',       'type' => 'input',    'ideal' => '60',  'max' => '70',  'rows' => '',  'label' => 'travel_core.seo_page_title',       'desc' => 'travel_core.seo_page_title_desc'],
    ['key' => 'seo_meta_description', 'type' => 'textarea', 'ideal' => '160', 'max' => '200', 'rows' => '3', 'label' => 'travel_core.seo_meta_description', 'desc' => 'travel_core.seo_meta_description_desc'],
    ['key' => 'seo_meta_keywords',    'type' => 'input',    'ideal' => '200', 'max' => '255', 'rows' => '',  'label' => 'travel_core.seo_meta_keywords',    'desc' => 'travel_core.seo_meta_keywords_desc'],
    ['key' => 'seo_name_slug',        'type' => 'input',    'ideal' => '',    'max' => '',    'rows' => '',  'label' => 'travel_core.seo_name_slug',        'desc' => 'travel_core.seo_name_slug_desc'],
    ['key' => 'seo_full_description', 'type' => 'textarea', 'ideal' => '',    'max' => '',    'rows' => '4', 'label' => 'travel_core.seo_full_description', 'desc' => 'travel_core.seo_full_description_desc']
]}

<p class="muted">{__("travel_core.seo_per_language_hint")}</p>

<ul class="nav nav-tabs">
    {foreach $seo_languages as $seo_lc => $seo_lang_name}
        <li data-seo-lang-tab="{$seo_lc}" class="{if $seo_lang_name@first}active{/if}"><a href="#seo_lang_{$seo_lc}">{$seo_lang_name} ({$seo_lc|upper})</a></li>
    {/foreach}
</ul>

{foreach $seo_languages as $seo_lc => $seo_lang_name}
    {$seo_first_lang = $seo_lang_name@first}
    <div id="seo_lang_{$seo_lc}" data-seo-lang-pane="{$seo_lc}" data-seo-lang-name="{$seo_lang_name}"{if !$seo_first_lang} hidden{/if}>

        {foreach $seo_tpl_fields as $f}
            {$toggle_key = "seo_field_"|cat:($f.key|replace:'seo_':'')}
            <div class="control-group{if $seo_values.$toggle_key == 'N'} seo-field-off{/if}" data-seo-field="{$f.key}" data-seo-toggle-key="{$toggle_key}">
                <label class="control-label" for="{$seo_lc}_{$f.key}">{__($f.label)}</label>
                <div class="controls">
                    {if $f.type == 'textarea'}
                        <textarea id="{$seo_lc}_{$f.key}"
                                  name="seo_lang[{$seo_lc}][{$f.key}]"
                                  class="seo-template-input"
                                  data-seo-key="{$f.key}"
                                  data-seo-default="{$seo_page.defaults[$seo_lc][$f.key]|escape:html}"
                                  {if $f.ideal}data-seo-ideal="{$f.ideal}" data-seo-max="{$f.max}"{/if}
                                  rows="{$f.rows}">{$seo_lang_values[$seo_lc][$f.key]|escape:html}</textarea>
                    {else}
                        <input type="text"
                               id="{$seo_lc}_{$f.key}"
                               name="seo_lang[{$seo_lc}][{$f.key}]"
                               class="seo-template-input"
                               data-seo-key="{$f.key}"
                               data-seo-default="{$seo_page.defaults[$seo_lc][$f.key]|escape:html}"
                               {if $f.ideal}data-seo-ideal="{$f.ideal}" data-seo-max="{$f.max}"{/if}
                               value="{$seo_lang_values[$seo_lc][$f.key]|escape:html}" />
                    {/if}
                    <p class="help-block">{__($f.desc)}</p>
                    <div class="seo-field-meta">
                        <label class="checkbox inline" title="{__("travel_core.seo_apply_field_hint")}">
                            <input type="checkbox"
                                   {if $seo_first_lang}name="seo[{$toggle_key}]" value="Y"{/if}
                                   data-seo-toggle="{$toggle_key}"
                                   {if $seo_values.$toggle_key != 'N'}checked="checked"{/if} />
                            {__("travel_core.seo_apply_field")}
                        </label>
                        <span class="label" data-seo-counter hidden></span>
                        <a href="#" data-seo-restore>{__("travel_core.seo_restore_default")}</a>
                    </div>
                    <div class="seo-field-problems" data-seo-problems></div>
                </div>
            </div>
        {/foreach}

        <div class="seo-preview" data-seo-preview>
            <h4>{__("travel_core.seo_preview_label")} <small class="muted">· {$seo_lang_name}</small></h4>
            {if $seo_page.sample_name}
                <p class="muted">{__("travel_core.seo_preview_sample", ["[name]" => $seo_page.sample_name])}</p>
                <div class="seo-preview-url" data-seo-preview-url></div>
                <div class="seo-preview-title" data-seo-preview-title></div>
                <div class="seo-preview-desc" data-seo-preview-desc></div>
                <p class="muted seo-preview-name">{__("travel_core.seo_product_name")}: <span data-seo-preview-name></span></p>
            {else}
                <p class="muted">{__("travel_core.seo_preview_none")}</p>
            {/if}
        </div>
    </div>
{/foreach}
