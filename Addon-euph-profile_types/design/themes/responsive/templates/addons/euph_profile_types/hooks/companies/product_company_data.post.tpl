{*
    Multi-Vendor, product page: the vendor type next to the vendor's name
    ("Use the name of the profile type in the vendor information").
*}
{if $addons.euph_profile_types.vendor_type_in_product == "Y"}
    {$euph_pt_vendor_type = $company_id|default:$product.company_id|fn_euph_profile_types_get_company_type_name}
    {if $euph_pt_vendor_type}
        <span class="euph-pt-vendor-type">{$euph_pt_vendor_type}</span>
    {/if}
{/if}
