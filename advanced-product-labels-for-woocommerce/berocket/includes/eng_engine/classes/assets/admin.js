jQuery(document).ready(function (){
    jQuery("#ee_hide_premium").on("click", function (e){
        e.preventDefault();
        jQuery(this).after('<span class="spinner is-active"></span>');
        jQuery.ajax({
            url: ajaxurl, // WordPress global in admin
            type: "POST",
            data: {
                action: "hide_premium_features",
                nonce: PremiumAjax.nonce,
                element: PremiumAjax.element,
                plugin: PremiumAjax.plugin
            },
            success: function (response) {
                if (response.success) {
                    jQuery('#brfr_ee_hide_locked_features').slideUp(300);
                    jQuery('#braapf_widget_type_premium_1_div').remove();
                    jQuery('#braapf_widget_type_premium_2_div').remove();
                    jQuery('#braapf_widget_type_premium_3_div').remove();
                    jQuery('.locked_feature_tr').remove();
                    if ( jQuery('.br_framework_settings ul.side a.premium-preview.active').length > 0 ) {
                        jQuery('.br_framework_settings ul.side li:first > a').click();
                        jQuery('#brfr_ee_hide_locked_features').remove();
                    }
                    jQuery('.br_framework_settings ul.side a.premium-preview').parent().remove();
                    jQuery('select#braapf_filter_type option:disabled').remove();
                    jQuery('select.berocket_label_content_type option:disabled').remove();
                    jQuery('select.br_cond_type option:disabled').remove();
                    jQuery('select.berocket_label_type_select option:disabled').remove();
                } else {
                    alert(response.data?.message || "Error");
                }
            }
        });
    });
});