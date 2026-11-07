{*
    One stroke icon of the Design A room cards (design_a_results.tpl) and the list card (list_card.tpl),
    decorative: the text next to it says what it means.
    Params: icon = users | check | nocheck | alert | clock | card | meal | bed | route | cal | plane | bus | info | sort | zap | key | tag | pct | calx | x | chev | minus | plus | pin; size (px, default 16)
*}
{$travel_pdpa_icon_size = $size|default:16}
<svg class="travel-pdpa-ico" width="{$travel_pdpa_icon_size}" height="{$travel_pdpa_icon_size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
{if $icon == "users"}<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
{elseif $icon == "check"}<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>
{elseif $icon == "alert"}<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
{elseif $icon == "clock"}<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
{elseif $icon == "card"}<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>
{elseif $icon == "nocheck"}<circle cx="12" cy="12" r="10"/><path d="M8 12h8"/>
{elseif $icon == "bed"}<path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20M6 8v9"/>
{elseif $icon == "route"}<circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/>
{elseif $icon == "cal"}<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>
{elseif $icon == "plane"}<path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/>
{elseif $icon == "bus"}<path d="M8 6v6M15 6v6M2 12h19.6M18 18h3s.5-1.7.8-2.8c.1-.4.2-.8.2-1.2 0-.4-.1-.8-.2-1.2l-1.4-5C20.1 6.8 19.1 6 18 6H4a2 2 0 0 0-2 2v10h3"/><circle cx="7" cy="18" r="2"/><path d="M9 18h5"/><circle cx="16" cy="18" r="2"/>
{elseif $icon == "meal"}<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2M7 2v20M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3zm0 0v7"/>
{elseif $icon == "sort"}<path d="m21 16-4 4-4-4M17 20V4M3 8l4-4 4 4M7 4v16"/>
{elseif $icon == "zap"}<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>
{elseif $icon == "key"}<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>
{elseif $icon == "tag"}<path d="M12.59 2.59A2 2 0 0 0 11.17 2H4a2 2 0 0 0-2 2v7.17a2 2 0 0 0 .59 1.42l8.7 8.7a2.43 2.43 0 0 0 3.42 0l6.58-6.58a2.43 2.43 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r="1"/>
{elseif $icon == "pct"}<path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/><path d="m15 9-6 6M9 9h.01M15 15h.01"/>
{elseif $icon == "calx"}<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M14 14l-4 4M10 14l4 4"/>
{elseif $icon == "x"}<path d="M18 6 6 18M6 6l12 12"/>
{elseif $icon == "chev"}<path d="m6 9 6 6 6-6"/>
{elseif $icon == "minus"}<path d="M5 12h14"/>
{elseif $icon == "plus"}<path d="M12 5v14M5 12h14"/>
{elseif $icon == "pin"}<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>
{else}<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>
{/if}
</svg>
