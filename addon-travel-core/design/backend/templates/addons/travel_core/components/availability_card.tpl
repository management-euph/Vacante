{**
 * Dashboard card: a supplier's hotels per availability state, as Travel
 * Core's weekly review left them (report only). Shared by the Novoton,
 * Eurosite and Sphinx dashboards; links to Travel -> Hotel availability.
 * Params: card = AvailabilityCard::build():
 *   provider, label, mode, total, reviewed_at
 *   counts [{state, hotels, pill}]   only the states with hotels
 *}
{$_card = $card}
<section class="travel-cron-card" id="travel-avail-card-{$_card.provider}" aria-labelledby="travel-avail-card-{$_card.provider}-title">
    <div class="travel-cron-card__head">
        <div>
            <h3 id="travel-avail-card-{$_card.provider}-title">{__("travel_core.hotel_availability")}</h3>
            <p class="muted">{__("travel_core.availability_card_intro", ["[supplier]" => $_card.label|escape:html])} · {__("travel_core.availability_mode_`$_card.mode`")}</p>
        </div>
        <a class="btn" href="{"travel_hotel_availability.manage?provider=`$_card.provider`"|fn_url}">{__("travel_core.availability_open_page")}</a>
    </div>
    {if $_card.total > 0}
        <ul class="travel-avail-counts">
            {foreach $_card.counts as $_count}
                <li>
                    <a href="{"travel_hotel_availability.manage?provider=`$_card.provider`&state=`$_count.state`"|fn_url}">
                        <span class="travel-cron-pill travel-cron-pill--{$_count.pill}">{__("travel_core.availability_state_`$_count.state`")}</span>
                        <strong>{$_count.hotels}</strong>
                    </a>
                </li>
            {/foreach}
        </ul>
        <p class="travel-cron-hint">
            {if $_card.reviewed_at}
                {__("travel_core.availability_last_review", ["[date]" => $_card.reviewed_at|date_format:"%d.%m.%Y %H:%M"])}
            {else}
                {__("travel_core.availability_card_not_reviewed")}
            {/if}
        </p>
    {else}
        <p class="muted">{__("travel_core.availability_card_none", ["[supplier]" => $_card.label|escape:html])}</p>
    {/if}
</section>
