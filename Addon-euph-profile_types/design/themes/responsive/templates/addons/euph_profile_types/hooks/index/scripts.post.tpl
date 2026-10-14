{script src="js/addons/euph_profile_types/func.js"}
<script>
(function (_, $) {
    _.tr({
        'euph_profile_types.invoice_details_taken': '{__("euph_profile_types.invoice_details_taken")|escape:"javascript"}'
    });
    _.euph_profile_types = {
        reload: {if $addons.euph_profile_types.reload_on_change == "Y"}true{else}false{/if},
        pills: {if $addons.euph_profile_types.choice_view == "pills"}true{else}false{/if},
        {* Invoice details taken from the profile: fn_euph_profile_types_js_value(). *}
        invoice_prefill: {if $euph_pt_invoice_prefill}{$euph_pt_invoice_prefill nofilter}{else}null{/if}
    };
}(Tygh, Tygh.$));
</script>
