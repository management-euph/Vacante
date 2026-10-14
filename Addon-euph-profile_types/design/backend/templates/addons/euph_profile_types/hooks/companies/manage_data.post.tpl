{if $euph_pt_type_names}
    <td class="nowrap" data-th="{__("euph_profile_types.vendor_type")}">
        {if $company.euph_profile_type}
            <span class="euph-pt-chip">{$euph_pt_type_names[$company.euph_profile_type]|default:$company.euph_profile_type}</span>
        {/if}
    </td>
{/if}
