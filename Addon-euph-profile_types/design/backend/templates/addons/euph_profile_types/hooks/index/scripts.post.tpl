{script src="js/addons/euph_profile_types/func.js"}
<script>
(function (_, $) {
    _.tr({
        'euph_profile_types.copied': '{__("euph_profile_types.copied")|escape:"javascript"}'
    });
    _.euph_profile_types = {
        reload: true,
        {* The Profile fields list's types and checkboxes: fn_euph_profile_types_js_value(). *}
        list: {if $euph_pt_list_config}{$euph_pt_list_config nofilter}{else}null{/if}
    };
}(Tygh, Tygh.$));
</script>
