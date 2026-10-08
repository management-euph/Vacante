{if $order_info.cp_profile_type && $cp_profile_types[$order_info.cp_profile_type]}
<div class="sidebar-row">
    <h6>{__("cp_profile_type")}</h6>
    <div class="profile-info">
        <i class="icon-group"></i>
        {$cp_profile_types[$order_info.cp_profile_type]}
    </div>
</div>
<hr class="profile-info-delim" />
{/if}