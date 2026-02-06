function bapl_image_bottom_init() {
    jQuery('.berocket_better_labels_image').each(function() {
        var product = jQuery(this).parents(bapl_image_btm.parent).first().find('.baplIMGsize').first();
        if( product.length == 0 ) {
            product = jQuery(this).parents(bapl_image_btm.parent).first().find(bapl_image_btm.find).first();
        }
        if( product.length > 0 ) {
            product.addClass('baplIMGsize');
            jQuery(this).css('height', product.outerHeight()).css('bottom', 'initial!important');
        }
    });
}
jQuery(document).ready(function () {
    bapl_image_bottom_init();
    setTimeout(bapl_image_bottom_init, 200);
});
jQuery(document).on('load', 'img', bapl_image_bottom_init);
jQuery(document).ajaxComplete(function () {
    bapl_image_bottom_init();
    setTimeout(bapl_image_bottom_init, 200);
});
jQuery(window).on('resize', bapl_image_bottom_init);
