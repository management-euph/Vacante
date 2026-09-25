{**
 * Travel Core — the SEO Templates page, shared by every provider add-on.
 *
 * A provider's views/<controller>/manage.tpl only includes this file with its
 * form id; everything else comes from fn_travel_core_seo_page_assign():
 *   $seo_page.providers      tab row: every add-on with an SEO Templates page
 *   $seo_page.groups         the provider's placeholders, grouped
 *   $seo_page.modifiers      the modifiers the engine knows
 *   $seo_page.config_json    data for seo-templates.js (keys, sample, labels)
 *   $seo_page.save_dispatch  e.g. sphinx_seo_templates.save
 *   $seo_page.apply_url      "Apply templates now" (saves first, then applies)
 *
 * CS-Cart admin styles only: nav-tabs, well, btn, label, alert, sidebar-row.
 *
 * Params: seo_form_id (string), seo_placeholders_title (string)
 *}

{capture name="sidebar"}
<div class="sidebar-row seo-sidebar">
    <h6>{$seo_placeholders_title}</h6>
    <p class="muted seo-target" data-seo-target>{__("travel_core.seo_target_none")}</p>
    {foreach $seo_page.groups as $seo_group}
        <p class="seo-ph-group">{$seo_group.label}</p>
        <ul class="unstyled seo-ph-list">
            {foreach $seo_group.items as $seo_ph}
                <li>
                    <button type="button"
                            class="btn btn-mini seo-ph"
                            data-seo-placeholder="{$seo_ph.key}"
                            title="{__("travel_core.seo_click_to_insert")}">{ldelim}{ldelim}{$seo_ph.key}{rdelim}{rdelim}</button>
                    <span class="muted">{$seo_ph.label}</span>
                </li>
            {/foreach}
        </ul>
    {/foreach}
</div>
<div class="sidebar-row seo-sidebar">
    <h6>{__("travel_core.seo_modifiers_title")}</h6>
    <p class="muted">{__("travel_core.seo_modifiers_hint")}</p>
    <div class="seo-mod-list">
        {foreach $seo_page.modifiers as $seo_mod}
            <button type="button"
                    class="btn btn-mini seo-mod"
                    data-seo-modifier="{$seo_mod}"
                    title="{__("travel_core.seo_click_to_insert_modifier")}">|{$seo_mod}</button>
        {/foreach}
    </div>
</div>
<div class="sidebar-row seo-sidebar">
    <div class="alert alert-info">
        <strong>{__("travel_core.seo_tips_title")}</strong>
        <ul>
            <li>{__("travel_core.seo_tip_1")}</li>
            <li>{__("travel_core.seo_tip_2")}</li>
            <li>{__("travel_core.seo_tip_3")}</li>
            <li>{__("travel_core.seo_tip_4")}</li>
        </ul>
    </div>
</div>
{/capture}

{capture name="mainbox"}

{* Inside the capture: admin AJAX navigation only returns what is captured. *}
{style src="addons/travel_core/seo-templates.css"}

{if $seo_page.show_providers}
    <ul class="nav nav-tabs seo-provider-tabs">
        {foreach $seo_page.providers as $seo_provider}
            <li class="{if $seo_provider.current}active{/if}"><a href="{$seo_provider.url}">{$seo_provider.name}</a></li>
        {/foreach}
    </ul>
{/if}

<form method="post"
      action="{""|fn_url}"
      name="{$seo_form_id}"
      id="{$seo_form_id}"
      class="form-horizontal form-edit cm-check-changes seo-templates-form"
      data-seo-config="{$seo_page.config_json|escape:html}">
    <input type="hidden" name="security_hash" value="{$security_hash}" />

    <p class="muted">{__("travel_core.seo_templates_hint")}</p>

    <div class="well well-small seo-apply">
        <label for="{$seo_form_id}_mode" class="seo-apply-label">{__("travel_core.seo_overwrite_mode")}</label>
        <select name="seo[seo_overwrite_mode]" id="{$seo_form_id}_mode" class="input-xlarge">
            <option value="override_all"{if $seo_values.seo_overwrite_mode == 'override_all'} selected="selected"{/if}>{__("travel_core.seo_override_all")}</option>
            <option value="fill_if_empty"{if $seo_values.seo_overwrite_mode == 'fill_if_empty'} selected="selected"{/if}>{__("travel_core.seo_fill_if_empty")}</option>
        </select>
        {* formaction: one form, two actions (a nested form is invalid HTML).
           cm-confirm asks first; cm-comet shows CS-Cart's progress bar. *}
        <button type="submit"
                class="btn cm-confirm cm-comet"
                formaction="{$seo_page.apply_url}"
                formmethod="post"
                data-ca-confirm-text="{__("travel_core.seo_bulk_apply_confirm")|escape:html}">
            <i class="icon-refresh"></i> {__("travel_core.seo_bulk_apply_button")}
        </button>
        <p class="muted seo-apply-desc">{__("travel_core.seo_bulk_apply_desc")}</p>
    </div>

    {include file="addons/travel_core/components/seo_lang_fields.tpl"}
</form>

{script src="js/addons/travel_core/seo-templates.js"}

{/capture}

{capture name="buttons"}
    <button type="submit"
            form="{$seo_form_id}"
            name="dispatch[{$seo_page.save_dispatch}]"
            class="btn btn-primary">
        {__("save")}
    </button>
{/capture}

{include file="common/mainbox.tpl"
    title=$seo_page.title
    content=$smarty.capture.mainbox
    sidebar=$smarty.capture.sidebar
    buttons=$smarty.capture.buttons
}
