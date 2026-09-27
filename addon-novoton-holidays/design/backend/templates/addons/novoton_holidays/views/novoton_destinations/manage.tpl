{** Novoton -> Destinations: what we sell from Novoton.

   The Sphinx whitelist page's layout (figures, a country list you open, the
   summary on the right) with Novoton's two levels: country -> resort. Each
   country is Not sold / All resorts / Only selected; the modes are radio
   buttons, so the form saves without JavaScript too. destinations.js adds
   the search, the filters and the "not saved" counter. Save never changes a
   product; "Disable products outside the whitelist" is its own confirmed
   step (its own form, outside the main one). **}

{capture name="mainbox"}
{$_d = $novoton_destinations}
{$_t = $_d.totals}

<div class="novoton-dest travel-cron">

    <div class="travel-cron-tiles novoton-tiles">
        <div class="travel-cron-tile">
            <div class="travel-cron-tile__label">{__("novoton_holidays.dest_tile_countries")}</div>
            <div class="travel-cron-tile__value" data-dest-total="countries">{$_t.countries}</div>
        </div>
        <div class="travel-cron-tile">
            <div class="travel-cron-tile__label">{__("novoton_holidays.dest_tile_resorts")}</div>
            <div class="travel-cron-tile__value" data-dest-total="resorts">{$_t.resorts}</div>
        </div>
        <div class="travel-cron-tile">
            <div class="travel-cron-tile__label">{__("novoton_holidays.dest_tile_hotels")}</div>
            <div class="travel-cron-tile__value" data-dest-total="hotels">{$_t.hotels}</div>
            <div class="travel-cron-tile__note">{__("novoton_holidays.dest_tile_hotels_note", ["[n]" => $_t.priced])}</div>
        </div>
        <div class="travel-cron-tile{if $_t.new > 0} travel-cron-tile--warn{/if}">
            <div class="travel-cron-tile__label">{__("novoton_holidays.dest_tile_new")}</div>
            <div class="travel-cron-tile__value">{$_t.new}</div>
        </div>
    </div>

    {if !$_d.configured}
        <div class="alert alert-info novoton-dest__notice">
            <strong>{__("novoton_holidays.dest_not_configured_title")}</strong>
            {__("novoton_holidays.dest_not_configured_body")}
        </div>
    {/if}
    {if $_t.new > 0}
        <div class="alert alert-warning novoton-dest__notice">
            {__("novoton_holidays.dest_new_alert", ["[n]" => $_t.new])}
            <button type="button" class="btn btn-small" id="dest-show-new" aria-pressed="false">{__("novoton_holidays.dest_show_new")}</button>
        </div>
    {/if}
    {if $_t.gone > 0}
        <div class="alert alert-warning novoton-dest__notice">{__("novoton_holidays.dest_gone_alert", ["[n]" => $_t.gone])}</div>
    {/if}

    <p class="muted novoton-dest__intro">{__("novoton_holidays.dest_intro")}</p>

    <div class="novoton-dest__cols">
        <form action="{"novoton_destinations.save"|fn_url}" method="post" id="novoton-dest-form" class="novoton-dest__main"
              data-txt-pending="{__("novoton_holidays.dest_pending")|escape:html}"
              data-txt-none="{__("novoton_holidays.dest_no_pending")|escape:html}"
              data-txt-all="{__("novoton_holidays.dest_badge_all")|escape:html}"
              data-txt-off="{__("novoton_holidays.dest_badge_off")|escape:html}"
              data-txt-some="{__("novoton_holidays.dest_badge_some")|escape:html}"
              data-txt-country="{__("novoton_holidays.dest_search_country")|escape:html}"
              data-txt-resort="{__("novoton_holidays.dest_search_resort")|escape:html}"
              data-txt-sold="{__("novoton_holidays.dest_search_sold")|escape:html}"
              data-txt-not-sold="{__("novoton_holidays.dest_search_not_sold")|escape:html}"
              data-txt-no-match="{__("novoton_holidays.dest_search_no_match")|escape:html}">
            <input type="hidden" name="security_hash" value="{$security_hash}">

            <div class="novoton-dest__toolbar">
                <div class="novoton-dest__search">
                    <label for="dest-search" class="novoton-sr-only">{__("novoton_holidays.dest_search")}</label>
                    <input type="search" id="dest-search" placeholder="{__("novoton_holidays.dest_search")|escape:html}" autocomplete="off"
                           aria-controls="dest-search-results" aria-expanded="false">
                    <div id="dest-search-results" class="novoton-dest__results" role="listbox" hidden></div>
                </div>
                <label class="novoton-dest__check"><input type="checkbox" id="dest-only-sold"> {__("novoton_holidays.dest_only_sold")}</label>
                <span class="muted" id="dest-visible-count" aria-live="polite"></span>
            </div>

            {foreach from=$_d.countries item=c}
                <section class="novoton-dest-country" data-country="{$c.country|escape:html}" data-label="{$c.label|escape:html}" data-name="{$c.label|lower|escape:html} {$c.country|lower|escape:html}">
                    <div class="novoton-dest-country__head">
                        <button type="button" class="novoton-dest-country__toggle" aria-expanded="false" aria-controls="dest-body-{$c@index}">
                            <span class="novoton-dest-country__arrow" aria-hidden="true">▶</span>
                            <strong>{$c.label|escape:html}</strong>
                        </button>
                        <span class="novoton-dest-badge novoton-dest-badge--{$c.mode}" data-badge>
                            {if $c.mode == "all"}{__("novoton_holidays.dest_badge_all")}{elseif $c.mode == "off"}{__("novoton_holidays.dest_badge_off")}{else}{__("novoton_holidays.dest_badge_some", ["[sold]" => $c.sold, "[total]" => $c.resorts])}{/if}
                        </span>
                        {if $c.new > 0}<span class="novoton-dest-badge novoton-dest-badge--new">{__("novoton_holidays.dest_badge_new_n", ["[n]" => $c.new])}</span>{/if}
                        <span class="muted novoton-dest-country__meta">{__("novoton_holidays.dest_country_meta", ["[resorts]" => $c.resorts, "[hotels]" => $c.hotels])}</span>
                        <fieldset class="novoton-dest-mode">
                            <legend class="novoton-sr-only">{__("novoton_holidays.dest_mode_legend", ["[country]" => $c.label])}</legend>
                            {foreach from=["off", "all", "specific"] item=m}
                                <label class="novoton-dest-mode__opt">
                                    <input type="radio" name="destinations[{$c.country|escape:html}][mode]" value="{$m}"{if $c.mode == $m} checked{/if}>
                                    <span>{__("novoton_holidays.dest_mode_`$m`")}</span>
                                </label>
                            {/foreach}
                        </fieldset>
                    </div>

                    <div class="novoton-dest-country__body" id="dest-body-{$c@index}" hidden>
                        <div class="novoton-dest-country__hint">
                            <span class="muted" data-hint="off">{__("novoton_holidays.dest_hint_off")}</span>
                            <span class="muted" data-hint="all">{__("novoton_holidays.dest_hint_all")}</span>
                            <span class="muted" data-hint="specific">{__("novoton_holidays.dest_hint_specific")}</span>
                            <span class="novoton-dest-country__bulk" data-hint="specific">
                                <button type="button" class="btn btn-small" data-select-shown>{__("novoton_holidays.dest_select_shown")}</button>
                                <button type="button" class="btn btn-small" data-clear-shown>{__("novoton_holidays.dest_clear_shown")}</button>
                            </span>
                        </div>
                        {if $c.resorts}
                            <div class="novoton-dest-grid">
                                {foreach from=$c.resorts item=r}
                                    <label class="novoton-dest-resort{if $r.gone} is-gone{/if}" data-resort="{$r.label|lower|escape:html} {$r.name|lower|escape:html}" data-name="{$r.label|escape:html}"
                                           data-hotels="{$r.hotels}" data-priced="{$r.priced}" data-live="{$r.live}"{if $r.new} data-new="1"{/if}{if $r.gone} data-gone="1"{/if}>
                                        <input type="checkbox" name="destinations[{$c.country|escape:html}][resorts][]" value="{$r.name|escape:html}"{if $r.selected} checked{/if}>
                                        <span class="novoton-dest-resort__text">
                                            <span class="novoton-dest-resort__name">
                                                {$r.label|escape:html}
                                                {if $r.new}<span class="novoton-dest-badge novoton-dest-badge--new">{__("novoton_holidays.dest_badge_new")}</span>{/if}
                                                {if $r.gone}<span class="novoton-dest-badge novoton-dest-badge--off">{__("novoton_holidays.dest_badge_gone")}</span>{/if}
                                                <span class="novoton-dest-resort__dot" title="{__("novoton_holidays.dash_resorts_not_saved")|escape:html}" hidden></span>
                                            </span>
                                            <span class="novoton-dest-resort__meta">
                                                {__("novoton_holidays.dash_n_hotels", [$r.hotels])} · {__("novoton_holidays.dest_n_priced", ["[n]" => $r.priced])}{if $r.live > 0} · <strong>{__("novoton_holidays.dest_n_live", ["[n]" => $r.live])}</strong>{/if}
                                            </span>
                                        </span>
                                    </label>
                                {/foreach}
                            </div>
                        {else}
                            <p class="muted">{__("novoton_holidays.dest_no_hotels", ["[country]" => $c.label])}</p>
                        {/if}
                    </div>
                </section>
            {/foreach}

            <div id="dest-no-results" class="novoton-dest__empty" hidden>{__("novoton_holidays.dest_search_no_match")}</div>
        </form>

        <aside class="novoton-dest__summary" aria-labelledby="dest-summary-title">
            <h4 id="dest-summary-title">{__("novoton_holidays.dest_summary_title")}</h4>
            <div class="novoton-dest__srow"><span>{__("novoton_holidays.dest_tile_countries")}</span><strong data-dest-total="countries">{$_t.countries}</strong></div>
            <div class="novoton-dest__srow"><span>{__("novoton_holidays.dest_tile_resorts")}</span><strong data-dest-total="resorts">{$_t.resorts}</strong></div>
            <div class="novoton-dest__srow"><span>{__("novoton_holidays.dest_tile_hotels")}</span><strong data-dest-total="hotels">{$_t.hotels}</strong></div>
            <div class="novoton-dest__slist" id="dest-summary-list"></div>
            <div class="novoton-dest__pending" id="dest-pending" aria-live="polite"></div>
            <button type="submit" form="novoton-dest-form" class="btn btn-primary novoton-dest__wide">{__("novoton_holidays.dest_save")}</button>
            <button type="button" class="btn novoton-dest__wide" id="dest-undo">{__("novoton_holidays.dest_undo")}</button>

            <div class="novoton-dest__outside">
                <div class="novoton-dest__srow"><span>{__("novoton_holidays.dest_outside_label")}</span><strong{if $_t.outside > 0} class="travel-cron-hint--warn"{/if}>{$_t.outside}</strong></div>
                {if $_d.configured && $_t.outside > 0}
                    <button type="button" class="btn novoton-dest__wide" id="dest-outside-open" aria-expanded="false" aria-controls="dest-outside-confirm">{__("novoton_holidays.dest_outside_button")}</button>
                    <form action="{"novoton_destinations.disable_outside"|fn_url}" method="post" id="dest-outside-confirm" class="novoton-dest__confirm" hidden>
                        <input type="hidden" name="security_hash" value="{$security_hash}">
                        <p>{__("novoton_holidays.dest_outside_confirm", ["[n]" => $_t.outside])}</p>
                        <ul class="novoton-dest__outlist">
                            {foreach from=$_d.outside item=p name=out}
                                <li>
                                    <input type="hidden" name="product_ids[]" value="{$p.product_id}">
                                    <a href="{"products.update?product_id=`$p.product_id`"|fn_url}" target="_blank" rel="noopener">{$p.hotel_name|escape:html}</a>
                                    <span class="muted">· {$p.resort|escape:html}, {$p.country|escape:html}</span>
                                </li>
                            {/foreach}
                        </ul>
                        <button type="submit" class="btn btn-primary">{__("novoton_holidays.dest_outside_do", ["[n]" => $_t.outside])}</button>
                        <button type="button" class="btn" id="dest-outside-cancel">{__("cancel")}</button>
                    </form>
                {/if}
                <p class="muted novoton-dest__note">{__("novoton_holidays.dest_outside_note")}</p>
            </div>
        </aside>
    </div>
</div>

{* Inside the capture: admin AJAX navigation runs only the mainbox's scripts. *}
{script src="js/addons/novoton_holidays/destinations.js"}

{/capture}

{capture name="buttons"}
    <button type="submit" form="novoton-dest-form" class="btn btn-primary">{__("novoton_holidays.dest_save")}</button>
{/capture}

{include file="common/mainbox.tpl"
    title=__("novoton_holidays.dest_page_title")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
