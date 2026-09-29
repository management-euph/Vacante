{**
 * Travel Core — the destination picker: what we sell from a supplier.
 *
 * Shared by Novoton (country › resort), Eurosite (country › city) and Sphinx
 * (country › region › city). The add-on's page includes this inside its
 * mainbox capture with the data it built (see Services\DestinationPicker):
 *
 *   $dest.id            DOM prefix, e.g. "novoton"
 *   $dest.save_url      the form posts here (dest[...] fields, or dest_json)
 *   $dest.body_url      '' when every country's body is on the page; else a
 *                       country's body loads from here when it opens
 *   $dest.search_url    '' = search the page only; else also ask the server
 *   $dest.per_page      0 = no pager
 *   $dest.leaf_limit    0 = show every item; else "Show more" past it
 *   $dest.words / words_json   the add-on's words over Travel Core's
 *   $dest.modes         [{value, label, hint, sells, badge}]
 *   $dest.tiles, notices, intro, filters, facet, chips, sorts, bulk
 *   $dest.countries     see destination_country.tpl
 *   $dest.summary       {rows: [{label, value, total}], note}
 *   $dest.outside       {n, ids, groups: [{label, n}]}, $dest.outside_url
 *
 * Param dest_top: markup of the add-on's own shown under the intro (Eurosite's
 * catalog line and its warnings).
 *
 * Save never changes a product; "Disable products outside the whitelist" is
 * its own confirmed step (its own form, outside the main one). The Save
 * buttons live in the summary and in a bar that appears once something is
 * unsaved, not in the page's top row, which holds the add-on's page links.
 *}

{style src="addons/travel_core/destination-picker.css"}

{$_d = $dest}
<div class="travel-dest travel-cron" id="{$_d.id}-dest" data-travel-dest data-dest-words="{$_d.words_json|escape:html}">

    {if $_d.tiles}
        <div class="travel-cron-tiles travel-dest__tiles">
            {foreach $_d.tiles as $_tile}
                <div class="travel-cron-tile{if $_tile.warn} travel-cron-tile--warn{/if}">
                    <div class="travel-cron-tile__label">{$_tile.label}</div>
                    <div class="travel-cron-tile__value"{if $_tile.total} data-dest-total="{$_tile.total}"{/if}>{$_tile.value}</div>
                    {if $_tile.note}<div class="travel-cron-tile__note">{$_tile.note}</div>{/if}
                </div>
            {/foreach}
        </div>
    {/if}

    {foreach $_d.notices as $_notice}
        <div class="alert alert-{$_notice.kind|default:"warning"} travel-dest__notice">
            <span>{$_notice.text}</span>
            {if $_notice.button}<button type="button" class="btn btn-small" data-dest-show-new aria-pressed="false">{$_notice.button}</button>{/if}
        </div>
    {/foreach}

    {if $_d.intro}<p class="muted travel-dest__intro">{$_d.intro}</p>{/if}
    {if $dest_top}{$dest_top}{/if}

    <div class="travel-dest__cols">
        <form action="{$_d.save_url}" method="post" id="{$_d.id}-dest-form" class="travel-dest__main" data-dest-form
              data-body-url="{$_d.body_url}" data-search-url="{$_d.search_url}"
              data-per-page="{$_d.per_page|default:0}" data-leaf-limit="{$_d.leaf_limit|default:0}">
            <input type="hidden" name="security_hash" value="{$security_hash}">
            <input type="hidden" name="dest_json" value="" data-dest-json disabled>

            <div class="travel-dest__toolbar">
                <div class="travel-dest__search">
                    <label for="{$_d.id}-dest-search" class="travel-dest-sr">{$_d.words.search}</label>
                    <input type="search" id="{$_d.id}-dest-search" data-dest-search placeholder="{$_d.words.search|escape:html}" autocomplete="off"
                           aria-controls="{$_d.id}-dest-results" aria-expanded="false">
                    <div id="{$_d.id}-dest-results" class="travel-dest__results" data-dest-results role="listbox" hidden></div>
                </div>
                {if $_d.facet}
                    <label class="travel-dest__check">
                        <span class="travel-dest-sr">{$_d.facet.label}</span>
                        <select data-dest-facet class="input-medium travel-dest__facet">
                            <option value="">{$_d.facet.label}</option>
                            {foreach $_d.facet.options as $_opt}
                                <option value="{$_opt.value|escape:html}">{$_opt.label|escape:html}</option>
                            {/foreach}
                        </select>
                    </label>
                {/if}
                <label class="travel-dest__check"><input type="checkbox" data-dest-only-sold> {__("travel_core.dest_only_sold")}</label>
                {foreach $_d.filters as $_filter}
                    <label class="travel-dest__check"><input type="checkbox" data-dest-filter="{$_filter.key}"> {$_filter.label}</label>
                {/foreach}
                <span class="muted" data-dest-count aria-live="polite"></span>
            </div>

            {foreach $_d.countries as $_country}
                {include file="addons/travel_core/components/destination_country.tpl" dest=$_d country=$_country}
            {/foreach}

            <div class="travel-dest__fold" data-dest-fold hidden>
                <span data-dest-fold-text></span>
                <button type="button" class="btn btn-small" data-dest-fold-show>{__("travel_core.dest_fold_show")}</button>
                <button type="button" class="btn btn-small" data-dest-fold-hide hidden>{__("travel_core.dest_fold_hide")}</button>
            </div>
            <div class="travel-dest__empty" data-dest-none hidden>{$_d.words.no_match}</div>

            {if $_d.per_page}
                <div class="travel-dest__pager" data-dest-pager hidden>
                    <button type="button" class="btn btn-small" data-dest-page="first" aria-label="{__("travel_core.dest_page_first")|escape:html}">&laquo;</button>
                    <button type="button" class="btn btn-small" data-dest-page="prev" aria-label="{__("travel_core.dest_page_prev")|escape:html}">&lsaquo;</button>
                    <span class="travel-dest__pageinfo" data-dest-page-info></span>
                    <button type="button" class="btn btn-small" data-dest-page="next" aria-label="{__("travel_core.dest_page_next")|escape:html}">&rsaquo;</button>
                    <button type="button" class="btn btn-small" data-dest-page="last" aria-label="{__("travel_core.dest_page_last")|escape:html}">&raquo;</button>
                </div>
            {/if}
        </form>

        <aside class="travel-dest__summary" aria-labelledby="{$_d.id}-dest-summary-title">
            <h4 id="{$_d.id}-dest-summary-title">{__("travel_core.dest_summary_title")}</h4>
            {foreach $_d.summary.rows as $_row}
                <div class="travel-dest__srow"><span>{$_row.label}</span><strong{if $_row.total} data-dest-total="{$_row.total}"{/if}>{$_row.value}</strong></div>
            {/foreach}
            <div class="travel-dest__slist" data-dest-summary-list></div>
            <div class="travel-dest__pending is-clean" data-dest-pending aria-live="polite"></div>
            <button type="submit" form="{$_d.id}-dest-form" class="btn btn-primary travel-dest__wide">{__("travel_core.dest_save")}</button>
            <button type="button" class="btn travel-dest__wide" data-dest-undo>{__("travel_core.dest_undo")}</button>
            {if $_d.summary.note}<p class="muted travel-dest__note">{$_d.summary.note}</p>{/if}

            <div class="travel-dest__outside">
                <div class="travel-dest__srow"><span>{__("travel_core.dest_outside_label")}</span><strong{if $_d.outside.n > 0} class="travel-cron-hint--warn"{/if}>{$_d.outside.n}</strong></div>
                {if $_d.outside.n > 0 && $_d.outside_url}
                    <button type="button" class="btn travel-dest__wide" data-dest-outside-open aria-expanded="false" aria-controls="{$_d.id}-dest-outside">{__("travel_core.dest_outside_button")}</button>
                    <form action="{$_d.outside_url}" method="post" id="{$_d.id}-dest-outside" class="travel-dest__confirm" data-dest-outside-confirm hidden>
                        <input type="hidden" name="security_hash" value="{$security_hash}">
                        <input type="hidden" name="product_ids" value="{$_d.outside.ids}">
                        <p>{__("travel_core.dest_outside_confirm", ["[n]" => $_d.outside.n])}</p>
                        <ul class="travel-dest__outlist">
                            {foreach $_d.outside.groups as $_og}
                                <li>{$_og.label|escape:html} <span class="muted">— {__("travel_core.dest_n_products", ["[n]" => $_og.n])}</span></li>
                            {/foreach}
                        </ul>
                        <button type="submit" class="btn btn-primary">{__("travel_core.dest_outside_do", ["[n]" => $_d.outside.n])}</button>
                        <button type="button" class="btn" data-dest-outside-cancel>{__("cancel")}</button>
                    </form>
                {/if}
                <p class="muted travel-dest__note">{__("travel_core.dest_outside_note")}</p>
            </div>
        </aside>
    </div>

    <div class="travel-dest__savebar" data-dest-savebar hidden>
        <span class="travel-dest__savebar-text" data-dest-pending></span>
        <button type="button" class="btn" data-dest-undo>{__("travel_core.dest_undo_short")}</button>
        <button type="submit" form="{$_d.id}-dest-form" class="btn btn-primary">{__("travel_core.dest_save")}</button>
    </div>
</div>

{* Inside the caller's mainbox capture: admin AJAX navigation runs only the mainbox's scripts. *}
{script src="js/addons/travel_core/destination-picker.js"}
