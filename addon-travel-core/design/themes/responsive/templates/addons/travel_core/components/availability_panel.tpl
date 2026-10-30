{*
    Travel Core: the hotel page's availability panel
    (docs/novoton-availability-gaps-plan.md §7–8), above the search on both
    hotel page templates: components/booking_form_mount.tpl includes it
    first, outside the shell the inline search swaps results into.

    For a hotel that cannot be booked now: why (prices for the next season
    not published yet, from when they are, not offered at the moment, no
    offers for over a year, no longer available), last season's months, a
    "notify me" form (one email when booking opens, the consent text of §8;
    travel_availability.notify) and up to three similar hotels that can be
    booked now. The search stays available under it.

    $travel_availability_panel: AvailabilityPanelView, assigned by the
    dispatch_before_display hook (cart_hooks.php); null for a bookable hotel
    and while Travel Core's "Show availability panels on hotel pages" is
    off. Styles: booking-pages.css (.travel-avail-*), Design A's colours in
    design-a.css.
*}

{if $travel_availability_panel}
    {$travel_avail = $travel_availability_panel}
    <section class="travel-avail travel-avail--{$travel_avail.state|escape:html}" aria-labelledby="travel-avail-title-{$travel_avail.product_id}">
        <div class="travel-avail__head">
            <span class="travel-avail__icon">
                {if $travel_avail.state == "between_seasons"}
                    {include file="addons/travel_core/components/design_a_icon.tpl" icon="clock" size=22}
                {elseif $travel_avail.state == "left_feed" || $travel_avail.state == "closed"}
                    {include file="addons/travel_core/components/design_a_icon.tpl" icon="calx" size=22}
                {else}
                    {include file="addons/travel_core/components/design_a_icon.tpl" icon="info" size=22}
                {/if}
            </span>
            <div class="travel-avail__text">
                <p class="travel-avail__title" id="travel-avail-title-{$travel_avail.product_id}">{__($travel_avail.message, ["[date]" => $travel_avail.date])}</p>
                {if $travel_avail.season_from && $travel_avail.season_to}
                    <p class="travel-avail__season">{__("travel_core.availability_panel_last_season", ["[from]" => $travel_avail.season_from, "[to]" => $travel_avail.season_to])}</p>
                {elseif $travel_avail.season_from}
                    <p class="travel-avail__season">{__("travel_core.availability_panel_last_season_month", ["[month]" => $travel_avail.season_from])}</p>
                {/if}
            </div>
        </div>

        {if $travel_avail.notify}
            {* A native disclosure: the form opens under its button without
               any script, by keyboard too. The controller answers with a
               notice on this page. *}
            <details class="travel-avail-notify">
                <summary class="travel-avail-notify__toggle">{__("travel_core.notify_me_`$travel_avail.notify`")}</summary>
                <form class="travel-avail-notify__form" method="post" action="{""|fn_url}" name="travel_availability_notify_form">
                    <input type="hidden" name="dispatch" value="travel_availability.notify">
                    <input type="hidden" name="security_hash" value="{$security_hash}">
                    <input type="hidden" name="product_id" value="{$travel_avail.product_id}">
                    {* Honeypot: humans never see it, bots fill every field *}
                    <input type="text" name="website" value="" class="travel-avail-notify__trap" tabindex="-1" autocomplete="off" aria-hidden="true">
                    <label class="travel-avail-notify__label" for="travel-avail-email-{$travel_avail.product_id}">{__("travel_core.notify_email_placeholder")}</label>
                    <div class="travel-avail-notify__row">
                        <input type="email" id="travel-avail-email-{$travel_avail.product_id}" name="email" class="travel-avail-notify__email" required maxlength="255" autocomplete="email" placeholder="{__("travel_core.notify_email_placeholder")|escape:html}">
                        <button type="submit" class="travel-avail-notify__submit">{__("travel_core.notify_submit")}</button>
                    </div>
                    <label class="travel-avail-notify__consent">
                        <input type="checkbox" name="consent" value="Y" required>
                        <span>{__("travel_core.notify_consent")}{if $travel_avail.privacy_url} <a href="{$travel_avail.privacy_url|escape:html}" target="_blank" rel="noopener">{__("travel_core.notify_consent_privacy")}</a>{/if}</span>
                    </label>
                </form>
            </details>
        {/if}

        {if $travel_avail.similar}
            <div class="travel-avail-similar">
                <h3 class="travel-avail-similar__title">{__("travel_core.availability_panel_similar")}</h3>
                <ul class="travel-avail-similar__list">
                    {foreach $travel_avail.similar as $travel_avail_hotel}
                        <li class="travel-avail-similar__item">
                            <a class="travel-avail-similar__link" href="{"products.view?product_id=`$travel_avail_hotel.product_id`"|fn_url}">
                                <span class="travel-avail-similar__photo">
                                    {if $travel_avail_hotel.image}
                                        {* Width only: a thumbnail keeps the photo's proportions,
                                           the tile crops it (CS-Cart pads a fixed box with white). *}
                                        {include file="common/image.tpl" images=$travel_avail_hotel.image image_width=192 no_ids=true}
                                    {/if}
                                </span>
                                <span class="travel-avail-similar__body">
                                    <span class="travel-avail-similar__name">{$travel_avail_hotel.name|escape:html}</span>
                                    {if $travel_avail_hotel.stars > 0}
                                        <span class="travel-avail-similar__stars" role="img" aria-label="{__("travel_core.stars_rating", ["[rating]" => $travel_avail_hotel.stars])|escape:html}">{"★"|str_repeat:$travel_avail_hotel.stars}</span>
                                    {/if}
                                    {if $travel_avail_hotel.price}
                                        <span class="travel-avail-similar__price">{__("travel_core.pdpa_from")} <strong>{$travel_avail_hotel.price|escape:html}</strong> {__("travel_core.pdpa_per_night")}</span>
                                    {/if}
                                </span>
                            </a>
                        </li>
                    {/foreach}
                </ul>
            </div>
        {/if}
    </section>
{/if}
