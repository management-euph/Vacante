{script src="js/addons/euph_profile_types/func.js"}
<script>
(function (_, $) {
    _.euph_profile_types = {
        reload: {if $addons.euph_profile_types.reload_on_change == "Y"}true{else}false{/if},
        pills: {if $addons.euph_profile_types.choice_view == "pills"}true{else}false{/if}
    };
}(Tygh, Tygh.$));
</script>
