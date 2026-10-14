{*
    The order's profile type: the first block of the order sidebar.
*}
{if $euph_pt_order_type}
    <div class="sidebar-row euph-pt-order-type">
        <h6>{__("euph_profile_types.profile_type")}</h6>
        <div class="profile-info">
            <i class="icon-group"></i>
            <strong class="euph-pt-order-type__name">{$euph_pt_order_type.name}</strong>
            <span class="euph-pt-code">{$euph_pt_order_type.code}</span>
        </div>
        {if $euph_pt_order_type_options|count > 1}
            <a class="cm-combination euph-pt-order-type__toggle" id="sw_euph_pt_order_type_change">{__("euph_profile_types.change_order_type")}</a>
            <form action="{""|fn_url}" method="post" name="euph_pt_order_type_form" id="euph_pt_order_type_change" class="hidden euph-pt-order-type__form">
                <input type="hidden" name="order_id" value="{$order_info.order_id}" />
                <select name="euph_profile_type" class="input-medium">
                    {foreach $euph_pt_order_type_options as $code => $name}
                        <option value="{$code}" {if $code == $euph_pt_order_type.code}selected="selected"{/if}>{$name}</option>
                    {/foreach}
                </select>
                {include file="buttons/button.tpl" but_text=__("save") but_name="dispatch[euph_profile_types.set_order_type]" but_role="action" but_meta="btn btn-small"}
                <p class="muted description">{__("euph_profile_types.change_order_type_hint")}</p>
            </form>
        {/if}
    </div>
    <hr class="profile-info-delim" />
{/if}
