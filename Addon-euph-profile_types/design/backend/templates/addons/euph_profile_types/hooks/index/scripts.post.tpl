{script src="js/addons/euph_profile_types/func.js"}
<script>
(function (_, $) {
    _.tr({
        'euph_profile_types.copied': '{__("euph_profile_types.copied")|escape:"javascript"}'
    });
    _.euph_profile_types = {
        reload: true
    };
}(Tygh, Tygh.$));
</script>
{if $euph_pt_list_config}
    <script type="application/json" id="euph_pt_list_config">{$euph_pt_list_config|json_encode nofilter}</script>
{/if}
