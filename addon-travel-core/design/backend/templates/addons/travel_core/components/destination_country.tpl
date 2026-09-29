{**
 * One country of the destination picker (destination_picker.tpl).
 *
 * $country (Services\DestinationPicker::finish()):
 *   key, label, code, search      the post key, the name, a code shown next to it
 *   mode                          the saved mode's value
 *   badge, badge_class, meta      "3 of 12" / ALL / NOT SOLD, "12 resorts · 900 hotels"
 *   new, empty, lazy, open        new items; nothing synced; body loads on open; open now
 *   flags                         {own: 3}: data-flag-own, for the toolbar filters
 *   badges                        [{text, class, title}] next to the name (★ 3 own)
 *   facet                         e.g. the continent, for the facet select
 *   attrs                         data-total, data-saved-sold, … (statAttributes)
 *   dom                           id-safe key
 *   groups                        see destination_country_body.tpl
 *}
{$_c = $country}
<section class="travel-dest-country" data-country="{$_c.key|escape:html}" data-label="{$_c.label|escape:html}" data-search="{$_c.search|escape:html}"
         data-new="{$_c.new|default:0}"{if $_c.facet} data-facet="{$_c.facet|escape:html}"{/if}{if $_c.empty} data-empty="1"{/if}{if $_c.lazy} data-lazy="1"{/if}{foreach $_c.flags as $_fk => $_fv} data-flag-{$_fk}="{$_fv}"{/foreach}{foreach $_c.attrs as $_ak => $_av} data-{$_ak}="{$_av}"{/foreach}>
    <div class="travel-dest-country__head">
        <button type="button" class="travel-dest-country__toggle" aria-expanded="{if $_c.open}true{else}false{/if}" aria-controls="{$dest.id}-dest-body-{$_c.dom}">
            <span class="travel-dest-country__arrow" aria-hidden="true">{if $_c.open}▼{else}▶{/if}</span>
            <strong>{$_c.label|escape:html}</strong>
        </button>
        {if $_c.code}<code class="travel-dest-code">{$_c.code|escape:html}</code>{/if}
        {foreach $_c.badges as $_b}<span class="travel-dest-flag travel-dest-flag--{$_b.class}"{if $_b.title} title="{$_b.title|escape:html}"{/if}>{$_b.text|escape:html}</span>{/foreach}
        <span class="travel-dest-badge travel-dest-badge--{$_c.badge_class}" data-dest-badge>{$_c.badge|escape:html}</span>
        {if $_c.new > 0}<span class="travel-dest-badge travel-dest-badge--new">{__("travel_core.dest_badge_new_n", ["[n]" => $_c.new])}</span>{/if}
        <span class="muted travel-dest-country__meta">{$_c.meta|escape:html}</span>
        <fieldset class="travel-dest-mode">
            <legend class="travel-dest-sr">{__("travel_core.dest_mode_legend", ["[country]" => $_c.label|escape:html])}</legend>
            {foreach $dest.modes as $_m}
                {* A mode that requires a flag (Eurosite: Own cities) is offered where it sells something. *}
                {if $_m.requires == "" || $_c.flags[$_m.requires]|default:0 > 0 || $_c.mode == $_m.value}
                    <label class="travel-dest-mode__opt">
                        <input type="radio" name="dest[{$_c.key|escape:html}][mode]" value="{$_m.value}" data-sells="{$_m.sells}" data-badge="{$_m.badge|escape:html}"{if $_c.mode == $_m.value} checked{/if}>
                        <span>{$_m.label}</span>
                    </label>
                {/if}
            {/foreach}
        </fieldset>
    </div>

    <div class="travel-dest-country__body" id="{$dest.id}-dest-body-{$_c.dom}" data-dest-body{if !$_c.open} hidden{/if}>
        {if !$_c.lazy}
            {include file="addons/travel_core/components/destination_country_body.tpl" dest=$dest country=$_c}
        {/if}
    </div>
</section>
