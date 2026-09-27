{*
    Booking page header — the page title and the progress bar on ONE row
    (title left, steps centered), above both columns (sidebar + form). It
    replaces the breadcrumb + full-width bar stack, so the form starts
    higher: Search (done) → Guests (current) → Payment → Confirmation.

    Only a PAST step is clickable: "Search" goes back to the guest's own
    results (same dates and guests). The current step is marked with
    aria-current; future steps are plain text — clicking ahead to payment
    before the guest details are in would only produce validation errors.
    The bar is not shown when editing a cart line (not a step of a new
    booking); the title still is.

    Parameters:
      bs_edit_mode   (bool, default false) hide the bar in edit mode.
      bs_title       (string) the page H1; defaults to $page_title.
      bs_search_url  (string, dispatch URL) where "Search" leads; defaults
                     to the sidebar's "Change your selection" URL. No URL →
                     the step is shown done but not linked.
*}
{$bs_heading = $bs_title|default:$page_title|default:""}
{$bs_back = $bs_search_url|default:$travel_booking_sidebar.change_url|default:""}
<div class="travel-booking-head{if $bs_edit_mode|default:false} travel-booking-head--no-steps{/if}">
    {if $bs_heading}
        <h1 class="travel-booking-head__title">{$bs_heading|escape:html}</h1>
    {/if}
    {if !$bs_edit_mode|default:false}
    <nav class="travel-steps-nav" aria-label="{__("travel_core.booking_progress")|escape:html}">
    <ol class="travel-steps">
        <li class="travel-steps__item travel-steps__item--done">
            {if $bs_back}
                <a class="travel-steps__link" href="{$bs_back|fn_url}" title="{__("travel_core.step_search_back")|escape:html}">
                    <span class="travel-steps__badge" aria-hidden="true">&#10003;</span>
                    <span class="travel-steps__label">{__("travel_core.step_search")}</span>
                    <span class="travel-sr-only"> — {__("travel_core.step_search_back")}</span>
                </a>
            {else}
                <span class="travel-steps__badge" aria-hidden="true">&#10003;</span>
                <span class="travel-steps__label">{__("travel_core.step_search")}</span>
            {/if}
        </li>
        <li class="travel-steps__item travel-steps__item--active" aria-current="step">
            <span class="travel-steps__badge" aria-hidden="true">2</span>
            <span class="travel-steps__label">{__("travel_core.step_guests")}</span>
            <span class="travel-sr-only"> — {__("travel_core.step_current")}</span>
        </li>
        <li class="travel-steps__item travel-steps__item--upcoming" title="{__("travel_core.step_locked_hint")|escape:html}">
            <span class="travel-steps__badge" aria-hidden="true">3</span>
            <span class="travel-steps__label">{__("travel_core.step_payment")}</span>
            <span class="travel-sr-only"> — {__("travel_core.step_locked_hint")}</span>
        </li>
        <li class="travel-steps__item travel-steps__item--upcoming" title="{__("travel_core.step_locked_hint")|escape:html}">
            <span class="travel-steps__badge" aria-hidden="true">4</span>
            <span class="travel-steps__label">{__("travel_core.step_confirmation")}</span>
            <span class="travel-sr-only"> — {__("travel_core.step_locked_hint")}</span>
        </li>
    </ol>
    </nav>
    {/if}
</div>
