var baplf_ajax_load_init;
(function ($){
    var baplf_ajax_last_type_same = false;
    baplf_ajax_load_init = function() {
        if( $('.bapl_ajax_replace').length ) {
            var products = [];
            $('.bapl_ajax_replace').each(function() {
                products.push($(this).data('id'));
            });
            $.post(bapl_ajax_load_data.ajax_url, {action:'bapl_ajax_load', products:products}, function(results) {
                baplf_ajax_last_type_same = true;
                $.each(results, function(product_id, labels) {
                    if( $('.bapl_ajax_replace[data-id="'+product_id+'"]').length ) {
                        $.each(labels, function(position, label) {
                            var $element = $(label);
                            $('.bapl_ajax_replace.bapl_ajax_all[data-id="'+product_id+'"]').before($element);
                            $('.bapl_ajax_replace.bapl_ajax_'+position+'[data-id="'+product_id+'"]').before($element);
                        });
                        $('.bapl_ajax_replace[data-id="'+product_id+'"]').remove();
                    }
                });
                $(document).trigger('bapl_new_label');
            }, 'json');
        }
    }
    $(document).on('berocket_ajax_products_loaded berocket_ajax_products_infinite_loaded', baplf_ajax_load_init);
    $(document).ready( baplf_ajax_load_init );
    $(document).ajaxComplete( function() {
        if( ! baplf_ajax_last_type_same ) {
            setTimeout(baplf_ajax_load_init, 100);
        } else {
            baplf_ajax_last_type_same = false;
        }
    });
})(jQuery);
