{script src="js/addons/euph_profile_types/func.js"}
<script>
(function (_, $) {
    _.tr({
        'euph_profile_types.invoice_details_taken': '{__("euph_profile_types.invoice_details_taken")|escape:"javascript"}'
    });
    _.euph_profile_types = {
        reload: {if $addons.euph_profile_types.reload_on_change == "Y"}true{else}false{/if}
    };
}(Tygh, Tygh.$));
</script>
{if $euph_pt_invoice_prefill}
    <script type="application/json" id="euph_pt_invoice_prefill">{$euph_pt_invoice_prefill|json_encode nofilter}</script>
{/if}
