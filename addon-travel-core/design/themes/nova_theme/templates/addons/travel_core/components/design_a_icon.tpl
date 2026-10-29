{*
    One stroke icon of the Design A room cards (design_a_results.tpl),
    decorative: the text next to it says what it means.
    Params: icon = users | check | alert | clock | card | meal | info | sort | zap | key | tag | pct | calx | x | chev; size (px, default 16)
*}
{$travel_pdpa_icon_size = $size|default:16}
<svg class="travel-pdpa-ico" width="{$travel_pdpa_icon_size}" height="{$travel_pdpa_icon_size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
{if $icon == "users"}<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
{elseif $icon == "check"}<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>
{elseif $icon == "alert"}<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
{elseif $icon == "clock"}<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
{elseif $icon == "card"}<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>
{elseif $icon == "meal"}<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2M7 2v20M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3zm0 0v7"/>
{elseif $icon == "sort"}<path d="m21 16-4 4-4-4M17 20V4M3 8l4-4 4 4M7 4v16"/>
{elseif $icon == "zap"}<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>
{elseif $icon == "key"}<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>
{elseif $icon == "tag"}<path d="M12.59 2.59A2 2 0 0 0 11.17 2H4a2 2 0 0 0-2 2v7.17a2 2 0 0 0 .59 1.42l8.7 8.7a2.43 2.43 0 0 0 3.42 0l6.58-6.58a2.43 2.43 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r="1"/>
{elseif $icon == "pct"}<path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/><path d="m15 9-6 6M9 9h.01M15 15h.01"/>
{elseif $icon == "calx"}<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M14 14l-4 4M10 14l4 4"/>
{elseif $icon == "x"}<path d="M18 6 6 18M6 6l12 12"/>
{elseif $icon == "chev"}<path d="m6 9 6 6 6-6"/>
{else}<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>
{/if}
</svg>
