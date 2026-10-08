{hook name="companies:product_company_data"}
{if  empty($addons.abt__unitheme2) && empty($addons.abt__youpitheme)}
    {if !empty($product.profile_type_name)}
        <label class="ty-control-group__label">{$product.profile_type_name}:</label>
    {else}
        <label class="ty-control-group__label">{__("vendor")}:</label>
    {/if}
    <span class="ty-control-group__item ty-company-name"><a href="{"companies.products?company_id=`$company_id`"|fn_url}">{if $company_name}{$company_name}{else}{$company_id|fn_get_company_name}{/if}</a></span>
{/if}
{/hook}
