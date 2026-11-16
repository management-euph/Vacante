{*
 * Travel Core: booking form (after-description position) + JSON-LD (hotels
 * and circuits) + debug panel for travel product pages.
 *
 * The booking form renders here only when the shared travel_core setting
 * booking_form_position is 'after_description'; the default before-tabs
 * position is handled by product_tabs.pre.tpl. Mount markup lives in
 * components/booking_form_mount.tpl.
 *}

<!-- [travel_core] product_detail_bottom.post.tpl LOADED -->

{if $addons.travel_core.booking_form_position == 'after_description'}
    {include file="addons/travel_core/components/booking_form_mount.tpl"}
{/if}

{* ── DEBUG PANEL (only with ?travel_debug=1) ── *}
{if $travel_debug_enabled && $travel_debug_output}
<div id="travel-debug-panel" style="background:#1a1a2e;color:#0f0;font-family:monospace;font-size:12px;padding:15px;margin:10px 0;border:2px solid #e94560;border-radius:8px;max-height:500px;overflow-y:auto;white-space:pre-wrap;position:relative;z-index:9999;">
    <div style="color:#e94560;font-weight:bold;font-size:14px;margin-bottom:10px;">TRAVEL CORE DEBUG PANEL</div>
    <div style="color:#eee;margin-bottom:5px;">Smarty hooks: <span style="color:#0f0">product_tabs.pre + product_detail_bottom.post RENDERING</span></div>
    <div style="color:#eee;margin-bottom:5px;">travel_booking_product_id: <span style="color:#0f0">{$travel_booking_product_id|default:'NOT SET'}</span></div>
    <div style="color:#eee;margin-bottom:5px;">travel_booking_product_code: <span style="color:#0f0">{$travel_booking_product_code|default:'NOT SET'}</span></div>
    <div style="color:#eee;margin-bottom:5px;">travel_booking_scripts: <span style="color:#0f0">{if $travel_booking_scripts}SET ({$travel_booking_scripts|count} scripts){else}NOT SET{/if}</span></div>
    <div style="color:#eee;margin-bottom:5px;">travel_hotel_schema_json: <span style="color:#0f0">{if $travel_hotel_schema_json}SET ({$travel_hotel_schema_json|strlen} chars){else}NOT SET{/if}</span></div>
    <hr style="border-color:#333;margin:10px 0;">
    <details>
        <summary style="color:#e94560;cursor:pointer;">Full Debug Data (click to expand)</summary>
        <pre style="color:#0f0;margin-top:10px;">{$travel_debug_output|escape:'html'}</pre>
    </details>
</div>
<script src="{$config.current_location}/js/addons/travel_core/debug.js?v=1" defer></script>
{/if}

{* ── JSON-LD Structured Data ──
   A hotel's, or a circuit's (TouristTrip): a page is one or the other. Both
   are encoded with JSON_HEX_TAG (JsonLdEscapingTest, CircuitSchemaTest). *}
{if $travel_hotel_schema_json || $travel_circuit_schema_json}
<script type="application/ld+json">
{if $travel_hotel_schema_json}{$travel_hotel_schema_json nofilter}{else}{$travel_circuit_schema_json nofilter}{/if}
</script>
{/if}
