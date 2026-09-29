{**
 * A country's body in the destination picker: what each mode means, the
 * chips, the filter and the items. On the page for Novoton; for Eurosite and
 * Sphinx the add-on's controller renders it alone when the country opens
 * (destination-picker.js fetches it as {html}).
 *
 * $country.groups: [{key, label, search, meta, open, whole, items}]. A group
 * with key '' has no head (Novoton, Eurosite: the items straight under the
 * country); a keyed group is a Sphinx region, whose box sells the whole of it.
 *
 * An item: {value, label, code, search, selected, new, gone, flags, badges,
 * meta, meta_muted, hotels, priced, instant, live}.
 *}
{$_c = $country}
<input type="hidden" name="dest[{$_c.key|escape:html}][loaded]" value="1">

{if $_c.empty}
    <p class="muted travel-dest__emptytext">{$_c.empty_text}</p>
{else}
    <div class="travel-dest-country__hint" data-dest-hint-row>
        {foreach $dest.modes as $_m}
            <span class="muted" data-hint="{$_m.value}"{if $_c.mode != $_m.value} hidden{/if}>{$_m.hint}</span>
        {/foreach}
        <span class="travel-dest-country__bulk" data-hint="ticked"{if $_c.rule != "ticked"} hidden{/if}>
            {foreach $dest.bulk as $_bulk}
                {if $_c.flags[$_bulk.flag]|default:0 > 0}
                    <button type="button" class="btn btn-small" data-dest-select-flag="{$_bulk.flag}">{$_bulk.label}</button>
                {/if}
            {/foreach}
            <button type="button" class="btn btn-small" data-dest-select-shown>{__("travel_core.dest_select_shown")}</button>
            <button type="button" class="btn btn-small" data-dest-clear-shown>{__("travel_core.dest_clear_shown")}</button>
        </span>
    </div>

    <div class="travel-dest-tools">
        <div class="travel-dest-chips" role="group" aria-label="{__("travel_core.dest_chips_label")|escape:html}">
            <button type="button" class="travel-dest-chip" data-dest-chip="all" aria-pressed="true">{__("travel_core.dest_chip_all")} <b data-chip-n></b></button>
            {foreach $dest.chips as $_chip}
                {if $_c.flags[$_chip.flag]|default:0 > 0}
                    <button type="button" class="travel-dest-chip" data-dest-chip="flag:{$_chip.flag}" aria-pressed="false">{$_chip.label} <b data-chip-n></b></button>
                {/if}
            {/foreach}
            <button type="button" class="travel-dest-chip" data-dest-chip="sold" aria-pressed="false">{__("travel_core.dest_chip_sold")} <b data-chip-n></b></button>
            <button type="button" class="travel-dest-chip" data-dest-chip="unsold" aria-pressed="false">{__("travel_core.dest_chip_unsold")} <b data-chip-n></b></button>
            <button type="button" class="travel-dest-chip" data-dest-chip="new" aria-pressed="false"{if !$_c.new} hidden{/if}>{__("travel_core.dest_chip_new")} <b data-chip-n></b></button>
        </div>
        <input type="search" class="travel-dest-filter" data-dest-leaf-filter placeholder="{$dest.words.filter|escape:html}" aria-label="{$dest.words.filter|escape:html}" autocomplete="off">
        {if $dest.sorts}
            <select class="travel-dest-sort" data-dest-sort aria-label="{__("travel_core.dest_sort")|escape:html}">
                {foreach $dest.sorts as $_sort}<option value="{$_sort.value}">{$_sort.label}</option>{/foreach}
            </select>
        {/if}
        {if $_c.stats.groups|default:0 > 0}
            <span class="travel-dest-country__bulk">
                <button type="button" class="btn btn-small" data-dest-expand>{__("travel_core.dest_expand")}</button>
                <button type="button" class="btn btn-small" data-dest-collapse>{__("travel_core.dest_collapse")}</button>
            </span>
        {/if}
    </div>

    {foreach $_c.groups as $_g}
        {$_keyed = $_g.key !== ""}
        <div class="travel-dest-group"{if $_keyed} data-group="{$_g.key|escape:html}" data-label="{$_g.label|escape:html}" data-search="{$_g.search|escape:html}"{/if}>
            {if $_keyed}
                {$_gid = "`$dest.id`-dest-g-`$_g.dom`"}
                <div class="travel-dest-group__head">
                    <button type="button" class="travel-dest-group__fold" data-dest-fold-group aria-expanded="{if $_g.open}true{else}false{/if}"
                            aria-label="{__("travel_core.dest_fold_group", ["[group]" => $_g.label|escape:html])|escape:html}">{if $_g.open}−{else}+{/if}</button>
                    <input type="checkbox" id="{$_gid}" name="dest[{$_c.key|escape:html}][groups][]" value="{$_g.key|escape:html}" data-dest-group-cb{if $_g.whole} checked{/if}>
                    <label for="{$_gid}" class="travel-dest-group__name">{$_g.label|escape:html}</label>
                    <span class="travel-dest-type travel-dest-type--group">{$dest.words.group_type}</span>
                    <span class="travel-dest-badge travel-dest-badge--s" data-group-badge></span>
                    <span class="muted travel-dest-group__meta">{$_g.meta|escape:html}</span>
                </div>
            {/if}
            <div class="travel-dest-grid" data-dest-grid{if $_keyed && !$_g.open} hidden{/if}>
                {foreach $_g.items as $_i}
                    <label class="travel-dest-item{if $_i.gone} is-gone{/if}" data-label="{$_i.label|escape:html}" data-search="{$_i.search|escape:html}"
                           data-hotels="{$_i.hotels|default:0}" data-priced="{$_i.priced|default:0}" data-instant="{$_i.instant|default:0}" data-live="{$_i.live|default:0}"{if $_i.new} data-new="1"{/if}{if $_i.gone} data-gone="1"{/if}{foreach $_i.flags as $_fk => $_fv}{if $_fv} data-flag-{$_fk}="1"{/if}{/foreach}>
                        <input type="checkbox" name="dest[{$_c.key|escape:html}][items][]" value="{$_i.value|escape:html}"{if $_i.selected} checked{/if}>
                        <span class="travel-dest-item__text">
                            <span class="travel-dest-item__name">
                                <span class="travel-dest-item__label">{$_i.label|escape:html}</span>
                                {if $_i.code}<code class="travel-dest-code">{$_i.code|escape:html}</code>{/if}
                                {foreach $_i.badges as $_b}<span class="travel-dest-flag travel-dest-flag--{$_b.class}">{$_b.text|escape:html}</span>{/foreach}
                                {if $_i.new}<span class="travel-dest-badge travel-dest-badge--new travel-dest-badge--s">{__("travel_core.dest_badge_new")}</span>{/if}
                                {if $_i.gone}<span class="travel-dest-badge travel-dest-badge--off travel-dest-badge--s">{$dest.words.gone}</span>{/if}
                                <span class="travel-dest-item__dot" data-dest-dot title="{__("travel_core.dest_not_saved")|escape:html}" hidden></span>
                                {if $_i.live > 0}<span class="travel-dest-live" title="{__("travel_core.dest_live_title")|escape:html}">{__("travel_core.dest_n_live", ["[n]" => $_i.live])}</span>{/if}
                            </span>
                            <span class="travel-dest-item__meta{if $_i.meta_muted} is-muted{/if}">{$_i.meta|escape:html}</span>
                        </span>
                    </label>
                {/foreach}
            </div>
        </div>
    {/foreach}

    <p class="muted travel-dest__nomatch" data-dest-nomatch hidden>{__("travel_core.dest_no_item_match")}</p>
    {if $dest.leaf_limit}
        <div class="travel-dest-more" data-dest-more hidden>
            <span class="muted" data-dest-more-text></span>
            <button type="button" class="btn btn-small"></button>
        </div>
    {/if}
{/if}
