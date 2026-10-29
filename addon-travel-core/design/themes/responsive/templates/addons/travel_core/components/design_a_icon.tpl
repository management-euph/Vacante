{*
    One stroke icon of the Design A room cards (design_a_results.tpl),
    decorative: the text next to it says what it means.
    Params: icon = bed | users | check | alert | clock | card | meal | info | sort; size (px, default 16)
*}
{$travel_pdpa_icon_size = $size|default:16}
<svg class="travel-pdpa-ico" width="{$travel_pdpa_icon_size}" height="{$travel_pdpa_icon_size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
{if $icon == "bed"}<path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20M6 8v9"/>
{elseif $icon == "users"}<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
{elseif $icon == "check"}<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>
{elseif $icon == "alert"}<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
{elseif $icon == "clock"}<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
{elseif $icon == "card"}<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>
{elseif $icon == "meal"}<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2M7 2v20M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3zm0 0v7"/>
{elseif $icon == "sort"}<path d="m21 16-4 4-4-4M17 20V4M3 8l4-4 4 4M7 4v16"/>
{else}<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>
{/if}
</svg>
