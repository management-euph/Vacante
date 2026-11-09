{*
    The cancellation card's folded line (booking_sidebar.tpl): what
    cancelling would cost today, from the step in force.
      "✓ Anulare gratuită până la 18 Noi 2026" (green), or
      "Dacă anulați azi, plătiți 708,50 € (50%)" (amber).
    Prose terms say what they can: the free date, or the full amount.
    Nothing known, nothing shown: the card's title is enough.

    Parameters:
      cp  (array, required) the sidebar view array, or any array with the
          same keys: cancel_steps, cancel_free_until, cancel_full_amount.
*}
{$_cp_done = false}
{foreach from=$cp.cancel_steps|default:[] item="cp_step"}
    {if $cp_step.is_current && !$_cp_done}
        {$_cp_done = true}
        {if $cp_step.kind == "free"}
            <span class="travel-cancel-fold__preview travel-cancel-fold__preview--free">&#10003; {if $cp_step.to_label}{__("travel_core.free_cancellation_until")} {$cp_step.to_label|escape:html}{else}{__("travel_core.timeline_free")}{/if}</span>
        {elseif $cp_step.amount_label}
            <span class="travel-cancel-fold__preview travel-cancel-fold__preview--cost">{__("travel_core.cancel_today_costs")} {if $cp_step.approx|default:false}&asymp;&nbsp;{/if}{$cp_step.amount_label nofilter}{if $cp_step.percent_label} ({$cp_step.percent_label|escape:html}){/if}</span>
        {/if}
    {/if}
{/foreach}
{if !$_cp_done && $cp.cancel_free_until|default:""}
    <span class="travel-cancel-fold__preview travel-cancel-fold__preview--free">&#10003; {__("travel_core.free_cancellation_until")} {$cp.cancel_free_until|escape:html}</span>
{elseif !$_cp_done && $cp.cancel_full_amount|default:""}
    <span class="travel-cancel-fold__preview travel-cancel-fold__preview--cost">{__("travel_core.cancel_you_will_pay")} {$cp.cancel_full_amount nofilter}</span>
{/if}
