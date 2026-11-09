{*
    Booking page header: the progress bar, full width, above both columns.
    Three steps, as tour operators show them:
      Offer (done) → Booking details (current) → Payment.
    There is no Confirmation step: CS-Cart's own page after payment is the
    confirmation.

    A new booking's title ("Complete Booking") stays the page's H1 for
    screen readers and search engines, but is not shown: the bar already
    says where the guest is.

    Only a PAST step is clickable: "Offer" goes back to the offer the guest
    picked (same stay and guests), to change the room or the dates. The
    current step is marked with aria-current; the future step is plain text,
    because clicking ahead to payment before the guest details are in would
    only produce validation errors. The bar is not shown when editing a cart
    line (not a step of a new booking): the header says what is being
    changed instead, "Edit booking details #22" over "ADMIRAL, Golden Sands,
    Varna · 09 Nov 2026 – 15 Nov 2026" (the sidebar's own hotel, place and
    dates).

    Parameters:
      bs_edit_mode   (bool, default false) editing a cart line: no bar, the
                     context header.
      bs_booking_ref (string) edit mode: the booking's number in the title.
      bs_title       (string) the page H1; defaults to "Complete Booking"
                     (edit mode: "Edit booking details #ref", else
                     $page_title).
      bs_search_url  (string, dispatch URL) where "Offer" leads; defaults
                     to the sidebar's "Change your selection" URL. No URL →
                     the step is shown done but not linked.
*}
{if $bs_edit_mode|default:false && $bs_booking_ref|default:""}{$bs_default_title = __("travel_core.edit_booking_title", ["[id]" => $bs_booking_ref])}{elseif $bs_edit_mode|default:false}{$bs_default_title = $page_title|default:""}{else}{$bs_default_title = __("travel_core.complete_booking_title")}{/if}
{$bs_heading = $bs_title|default:$bs_default_title}
{$bs_back = $bs_search_url|default:$travel_booking_sidebar.change_url|default:""}
<div class="travel-booking-head{if $bs_edit_mode|default:false} travel-booking-head--no-steps{/if}">
    {if $bs_heading}
        <h1 class="travel-booking-head__title{if !$bs_edit_mode|default:false} travel-sr-only{/if}">{$bs_heading|escape:html}</h1>
    {/if}
    {$bs_tbs = $travel_booking_sidebar|default:[]}
    {if $bs_edit_mode|default:false && $bs_tbs.name|default:""}
        <p class="travel-booking-head__context">{$bs_tbs.name|escape:html}{if $bs_tbs.location_line|default:""}, {$bs_tbs.location_line|escape:html}{/if}{if $bs_tbs.check_in|default:""} &middot; {$bs_tbs.check_in|escape:html} &ndash; {$bs_tbs.check_out|default:""|escape:html}{/if}</p>
    {/if}
    {if !$bs_edit_mode|default:false}
    <nav class="travel-steps-nav" aria-label="{__("travel_core.booking_progress")|escape:html}">
    <ol class="travel-steps">
        <li class="travel-steps__item travel-steps__item--done">
            {if $bs_back}
                <a class="travel-steps__link" href="{$bs_back|fn_url}" title="{__("travel_core.step_offer_back")|escape:html}">
                    <span class="travel-steps__badge" aria-hidden="true">&#10003;</span>
                    <span class="travel-steps__label">{__("travel_core.step_offer")}</span>
                    <span class="travel-sr-only"> — {__("travel_core.step_offer_back")}</span>
                </a>
            {else}
                <span class="travel-steps__badge" aria-hidden="true">&#10003;</span>
                <span class="travel-steps__label">{__("travel_core.step_offer")}</span>
            {/if}
        </li>
        <li class="travel-steps__item travel-steps__item--active" aria-current="step">
            <span class="travel-steps__badge" aria-hidden="true">2</span>
            <span class="travel-steps__label">{__("travel_core.step_details")}</span>
            <span class="travel-sr-only"> — {__("travel_core.step_current")}</span>
        </li>
        <li class="travel-steps__item travel-steps__item--upcoming" title="{__("travel_core.step_locked_hint")|escape:html}">
            <span class="travel-steps__badge" aria-hidden="true">3</span>
            <span class="travel-steps__label">{__("travel_core.step_payment")}</span>
            <span class="travel-sr-only"> — {__("travel_core.step_locked_hint")}</span>
        </li>
    </ol>
    </nav>
    {/if}
</div>
