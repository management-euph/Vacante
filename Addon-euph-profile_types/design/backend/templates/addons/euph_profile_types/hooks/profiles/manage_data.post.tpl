{if $euph_pt_type_names}
    <td class="nowrap" data-th="{__("euph_profile_types.profile_type")}">
        {if $user.user_type == "C" && $user.euph_profile_type}
            <span class="euph-pt-chip">{$euph_pt_type_names[$user.euph_profile_type]|default:$user.euph_profile_type}</span>
        {/if}
    </td>
{/if}
