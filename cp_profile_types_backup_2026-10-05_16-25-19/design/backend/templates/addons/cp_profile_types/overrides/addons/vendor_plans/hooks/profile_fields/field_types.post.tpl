{if in_array($profile_type, $vendors_type_profiles) && $addons.vendor_plans.status == "A"}
    <option value="{$smarty.const.PROFILE_FIELD_TYPE_VENDOR_PLAN}" {if $field.field_type == $smarty.const.PROFILE_FIELD_TYPE_VENDOR_PLAN}selected="selected"{/if}>{__("vendor_plan")}</option>
{/if}