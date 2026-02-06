function berocket_sleep(milliseconds) {
    var start = new Date().getTime();
    for (var i = 0; i < 1e7; i++) {
        if ((new Date().getTime() - start) > milliseconds){
            break;
        }
    }
}

var br_tooltip_number = 1;
function berocket_regenerate_tooltip() {
	jQuery('.tippy-box').parent().remove();
    jQuery('.br_alabel .br_tooltip').each(function() {
        if( jQuery(this).is('.br_tooltip_ready') ) {
            var elementWithTippy = jQuery(this).parents('.br_alabel').find('span');
            if( elementWithTippy.length > 0 ) {
                if( typeof(elementWithTippy[0]._tippy) != 'undefined' ) {
                    elementWithTippy[0]._tippy.destroy();
                }
                jQuery(this).removeClass('br_tooltip_ready');
            }
        }
        if( ! jQuery(this).is('.br_tooltip_ready') ) {
            jQuery(this).addClass('br_tooltip_'+br_tooltip_number).addClass('br_tooltip_ready');
            jQuery(this).parents('.br_alabel').first().addClass('br_alabel_tooltip_'+br_tooltip_number);
            tippy('.br_alabel_tooltip_'+br_tooltip_number+' > span', {
            	content: jQuery('.br_tooltip_'+br_tooltip_number).html(),
            	allowHTML: true,
                onClickOutside(instance, event) {
                    if ( instance.props.hideOnClick === true ) {
                        berocket_sleep(instance.props.delay[1]);
                    }
                },
            });
            jQuery(this).parents('.br_alabel').find('*').attr('title', '');
            br_tooltip_number++;
        }
    });

    jQuery('.br_alabel > span[data-tippy-trigger="click"], .br_alabel > span[data-tippy-trigger="mouseenter"]').on('click', function(e) {
        if( ! jQuery(this).is('.br_alabel_linked') ) {
            e.preventDefault();	
            e.stopImmediatePropagation();
            e.stopPropagation();
        }
    });

    jQuery(document).on('mousedown', '.br_alabel [data-tippy-trigger="click"], .br_alabel [data-tippy-trigger="mouseenter"]', function(e){
        e.stopPropagation();
        e.preventDefault();
    });
}
var brl_mscale_state = false;
var berocket_labels_mobile_scale_reinit_id = false;
function berocket_labels_mobile_scale() {
    var is_mobile = (jQuery(window).width() <= 768);
    if( (is_mobile && brl_mscale_state != 'mobile') || ( ! is_mobile && brl_mscale_state == 'mobile') || brl_mscale_state === false ) {
        if( is_mobile ) {
            brl_mscale_state = 'mobile';
        } else {
            brl_mscale_state = 'desktop';
        }
        jQuery('.br_alabel').each(function() {
            berocket_labels_mobile_scale_single(jQuery(this), is_mobile)
        });
        if( jQuery('.br_alabel_msc_rqr').length ) {
            if( berocket_labels_mobile_scale_reinit_id !== false ) {
                clearTimeout(berocket_labels_mobile_scale_reinit_id);
                berocket_labels_mobile_scale_reinit_id = false;
            }
            setTimeout(berocket_labels_mobile_scale_reinit, 1000);
        }
    }
}
function berocket_labels_mobile_scale_single($element, is_mobile) {
    if( typeof($element.data('mobilescale')) != 'undefined' && $element.data('mobilescale') ) {
        if( $element.is(":visible") ) {
            var scale = $element.data('mobilescale');
            if( ! $element.hasClass('br_alabel_msc') ) {
                var width = $element.width();
                var height = $element.height();
                $element.data('scw', width);
                $element.data('sch', height);
                $element.css('display', 'flex');
                $element.css('flexWrap', 'wrap');
                $element.css('alignContent', 'center');
                $element.css('justifyContent', 'center');
                $element.addClass('br_alabel_msc');
            } else {
                var width = $element.data('scw');
                var height = $element.data('sch');
            }
            if( is_mobile ) {
                width = width * scale;
                height = height * scale;
            }
            $element.css('width', width+'px');
            $element.css('height', height+'px');
            if( is_mobile ) {
                $element.children('span').css('transform', 'scale('+scale+')');
                $element.children('span').css('flex', 'none');
            } else {
                $element.children('span').css('transform', '');
                $element.children('span').css('flex', '');
            }
        } else {
            $element.addClass('br_alabel_msc_rqr');
        }
    }
}
function berocket_labels_mobile_scale_reinit() {
    var is_mobile = (jQuery(window).width() <= 768);
    jQuery('.br_alabel_msc_rqr').each(function() {
        jQuery(this).removeClass('br_alabel_msc_rqr');
        berocket_labels_mobile_scale_single(jQuery(this), is_mobile);
    });
    if( jQuery('.br_alabel_msc_rqr').length ) {
        if( berocket_labels_mobile_scale_reinit_id !== false ) {
            clearTimeout(berocket_labels_mobile_scale_reinit_id);
            berocket_labels_mobile_scale_reinit_id = false;
        }
        berocket_labels_mobile_scale_reinit_id = setTimeout(berocket_labels_mobile_scale_reinit, 1000);
    }
}
function berocket_labels_mobile_scale_reset() {
    brl_mscale_state = false;
    berocket_labels_mobile_scale();
}
function bapl_init_labels_script() {
    berocket_regenerate_tooltip();
    berocket_labels_mobile_scale_reset();
}
(function ($){
    $(document).ready( function () { bapl_init_labels_script(); setTimeout(bapl_init_labels_script, 130); } );
    $(document).on('berocket_ajax_products_loaded berocket_ajax_products_infinite_loaded', bapl_init_labels_script);
    $(window).on('resize', berocket_labels_mobile_scale);
    $(document).on('bapl_new_label', bapl_init_labels_script);
    $(document).on('bapl_product_galery_appear', bapl_init_labels_script);
    $(document).ajaxComplete( function() {
        setTimeout(bapl_init_labels_script, 130);
    });
})(jQuery);

