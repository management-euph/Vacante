{include file="common/subheader.tpl" title=__("customer_information")}

{if $cp_profile_fields && $location == "I"}
    {assign var="profile_fields" value=$cp_profile_fields}
{else}
    {assign var="profile_fields" value=$location|fn_get_profile_fields}
{/if}

{$contact_fields = $profile_fields.C}

<div class="ty-profiles-info">
    {if $user_data.cp_profile_type && $cp_profile_types[$user_data.cp_profile_type] && $location == "I"}
        <div id="tygh_order_cp_profile_type" class="">
            <h5 class="ty-profiles-info__title">{__("cp_profile_type")}</h5>
            <div class="ty-profiles-info__field">
                {$cp_profile_types[$user_data.cp_profile_type]}
            </div>
            <br />
        </div>
    {/if}
    {if $profile_fields.B}
        <div id="tygh_order_billing_adress" class="ty-profiles-info__item ty-profiles-info__billing">
            <h5 class="ty-profiles-info__title">{__("billing_address")}</h5>
            <div class="ty-profiles-info__field">{include file="views/profiles/components/profile_fields_info.tpl" fields=$profile_fields.B title=__("billing_address")}</div>
        </div>
    {/if}
    {if $profile_fields.S}
        <div id="tygh_order_shipping_adress" class="ty-profiles-info__item ty-profiles-info__shipping">
            <h5 class="ty-profiles-info__title">{__("shipping_address")}</h5>
            <div class="ty-profiles-info__field">{include file="views/profiles/components/profile_fields_info.tpl" fields=$profile_fields.S title=__("shipping_address")}</div>
        </div>
    {/if}
    {if $contact_fields}
        <div class="ty-profiles-info__item">
            {capture name="contact_information"}
                {include file="views/profiles/components/profile_fields_info.tpl" fields=$contact_fields title=__("contact_information")}
            {/capture}
            {if $smarty.capture.contact_information|trim != ""}
                <h5 class="ty-profiles-info__title">{__("contact_information")}</h5>
                <div class="ty-profiles-info__field">{$smarty.capture.contact_information nofilter}</div>
            {/if}
        </div>
    {/if}
</div>