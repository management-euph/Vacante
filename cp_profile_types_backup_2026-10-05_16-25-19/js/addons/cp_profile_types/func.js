(function(_, $){
    $(document).on('click', 'input.cm-change-profile-type', function() {
        fn_cp_change_profile_type($(this));
    });
    $(document).on('change', 'select.cm-change-profile-type', function() {
        fn_cp_change_profile_type($(this));
    });
})(Tygh, Tygh.$);

function fn_cp_change_profile_type(elm) {
    var url = elm.data('caTargetUrl');
    if (typeof url == 'undefined') {
        return;
    }
    var target_id = elm.data('caTargetId');
    if (typeof target_id != 'undefined') {
        $.ceAjax('request', url, {
            result_ids: target_id,
            full_render: true,
            data: {
                cp_profile_type: elm.val()
            }
        });
    } else {
        $.redirect(url + '&cp_profile_type=' + elm.val());
    }
}