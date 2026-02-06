<?php
class BeRocket_products_label_labels_for_variations_class {
    function __construct() {
        add_filter( 'wp_head', array( $this, 'load_scripts' ), 15 );

        add_action( "wp_ajax_variation_label", array ( $this, 'variation_label' ) );
        add_action( "wp_ajax_nopriv_variation_label", array ( $this, 'variation_label' ) );

        add_filter( "berocket_apl_condition_check_data", array( $this, 'add_variation' ) );
        add_filter( "berocket_advanced_label_editor_check_type_product", array( $this, 'check_cond_variation' ), 100, 3 );
        add_filter( "berocket_advanced_label_editor_check_type_attribute", array( $this, 'check_cond_attr_variation' ), 100, 3 );
        add_filter( "berocket_advanced_label_editor_check_type_stockstatus", array( $this, 'check_cond_stockstatus_variation' ), 100, 3 );
        add_filter( "berocket_advanced_label_editor_check_type_stockquantity", array( $this, 'check_cond_stockquantity_variation' ), 100, 3 );
        add_filter( "berocket_advanced_label_editor_type_attribute", array( $this, 'cond_attr_variation' ), 100, 3 );
        add_filter( "berocket_advanced_label_editor_type_stockstatus", array( $this, 'cond_stockstatus_variation' ), 100, 3 );
        add_filter( "berocket_advanced_label_editor_type_stockquantity", array( $this, 'cond_stockquantity_variation' ), 100, 3 );
        add_filter( "berocket_apl_better_labels_html", array( $this, "better_labels_html"), 10, 6 );
    }

    public function load_scripts() {
        if ( !is_product() ) return;

        global $product;
        if ( !is_object( $product) ) $product = wc_get_product( get_the_ID() );
        if ( !$product->is_type( 'variable' ) ) return;

        wp_enqueue_script( 'berocket_label_variation_scripts', plugins_url( 'js/frontend.js', __FILE__ ), array( 'jquery', 'berocket_tippy' ), BeRocket_products_label_version );

        wp_localize_script( 'berocket_label_variation_scripts', 'brlabelsHelper',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
            )
        );
        echo '<style>'.$this->css_styles().'</style>';
    }

    public function css_styles() {
        return '.berocket_better_labels {
            transition: all;
            transition-duration: 0.2s;
            opacity: 1;
        }
        .berocket_hide_variations_load {
            opacity: 0;
        }
        ';
    }

    public function variation_label() {
        $variation = intVal( sanitize_text_field( $_REQUEST['variation_id'] ) );
        do_action( 'berocket_apl_set_label', true, $variation );
        wp_die();
    }

    public function add_variation( $args = array() ) {
        if( ! empty($args['product']) && is_a($args['product'], 'WC_Product_Variation' ) ) {
            $parent = wp_get_post_parent_id($args['product_id']);
            $args['var_product_id'] = $args['product_id'];
            $args['var_product'] = $args['product'];
            $args['product_id'] = $parent;
            $args['product'] = wc_get_product($parent);
        }
        return $args;
    }

    public function check_cond_variation($show_in, $condition, $additional) {
        $show = false;
        if( ! empty($additional['var_product_id']) ) {
            if( isset($condition['product']) && is_array($condition['product']) ) {
                $show = in_array($additional['var_product_id'], $condition['product']);
                if( ! empty($condition['additional_product']) && is_array($condition['additional_product']) ) {
                    $show = $show || in_array($additional['var_product_id'], $condition['additional_product']);
                }
                if( $condition['equal'] == 'not_equal' ) {
                    $show = ! $show;
                }
            }
        }
        if( $condition['equal'] == 'not_equal' ) {
            return $show && $show_in;
        } else {
            return $show || $show_in;
        }
    }

    public function check_cond_attr_variation($show_in, $condition, $additional) {
        if( ! empty($condition['variation']) ) {
            if( ! empty($additional['var_product']) && is_a($additional['var_product'], 'WC_Product_Variation') ) {
                $attributes = $additional['var_product']->get_variation_attributes();
                $show_in = false;
                if( ! empty($attributes['attribute_'.$condition['attribute']]) ) {
                    $term_id = $condition['values'][$condition['attribute']];
                    $term = get_term($term_id, $condition['attribute']);
                    $term_slug = $term->slug;
                    $show_in = $attributes['attribute_'.$condition['attribute']] == $term_slug;
                }
            } elseif( $condition['variation'] == 'variation' ) {
                $show_in = false;
            }
        }
        return $show_in;
    }

    public function check_cond_stockstatus_variation($show_in, $condition, $additional) {
        if( ! empty($condition['variation']) ) {
            if( ! empty($additional['var_product']) && is_a($additional['var_product'], 'WC_Product_Variation') ) {
                if( ! empty($condition['stockstatus']) ) {
                    if($condition['stockstatus'] == 'in_stock') {
                        $condition['in_stock'] = '1';
                    } else {
                        $condition['is_on_backorder'] = '1';
                        if( $condition['stockstatus'] == 'out_of_stock' ) {
                            $condition['out_of_stock'] = '1';
                        }
                    }
                }
                $show = ( ( ! empty($condition['in_stock']) && $additional['var_product']->is_in_stock() && ! $additional['var_product']->is_on_backorder() ) 
                       || ( ! empty($condition['out_of_stock']) && ! $additional['var_product']->is_in_stock() && ! $additional['var_product']->is_on_backorder() )
                       || ( ! empty($condition['is_on_backorder']) && $additional['var_product']->is_on_backorder() ) );
                return $show;
            }
        }
        return $show_in;
    }

    public function check_cond_stockquantity_variation($show_in, $condition, $additional) {
        if( ! empty($condition['variation']) ) {
            if( ! empty($additional['var_product']) && is_a($additional['var_product'], 'WC_Product_Variation') ) {
                $product_stock = intval($additional['var_product']->get_stock_quantity('edit'));
                $backorder = true;
                if( ! empty($condition['backorder']) && $condition['backorder'] != 'any' ) {
                    $backorder = $additional['product']->backorders_allowed();
                    if( $condition['backorder'] == 'no' ) {
                        $backorder = ! $backorder;
                    }
                }
                $show = BeRocket_conditions::supcondition_check($product_stock, $condition['stockquantity'], $condition);
                $show = $show && $backorder;
                return $show;
            }
        }
        return $show_in;
    }

    public function cond_attr_variation($html, $name, $options) {
        $def_options = array('variation' => '');
        $options = array_merge($def_options, $options);
        $html .= '<p><label>Display for variation:</label> <select '.(empty($options['is_example']) ? '' : 'data-').'name="' . $name . '[variation]">';
        $html .= '<option value="">Only Product</option>';
        $html .= '<option value="variation"'.($options['variation'] == 'variation' ? ' selected' : '').'>Only Variation</option>';
        $html .= '<option value="both"'.($options['variation'] == 'both' ? ' selected' : '').'>Both Variation and Product</option>';
        $html .= '</select></p>';
        return $html;
    }

    public function cond_stockstatus_variation($html, $name, $options) {
        $def_options = array('variation' => '');
        $options = array_merge($def_options, $options);
        $html .= '<p><label>Display for variation:</label> <input type="checkbox" value="1" '.( empty($options['variation']) ? '' : 'checked ').(empty($options['is_example']) ? '' : 'data-').'name="' . $name . '[variation]"></p>';
        return $html;
    }

    public function cond_stockquantity_variation($html, $name, $options) {
        $def_options = array('variation' => '');
        $options = array_merge($def_options, $options);
        $html .= '<p><label>Display for variation:</label> <input type="checkbox" value="1" '.( empty($options['variation']) ? '' : 'checked ').(empty($options['is_example']) ? '' : 'data-').'name="' . $name . '[variation]"></p>';
        return $html;
    }

    public function better_labels_html($html, $html_type, $html_positions, $product, $type = true, $product_id = '') {
        if( ! empty($product) && is_a($product, 'WC_Product') && $html_type === $type ) {
            $current_page = get_queried_object_id();
            if( $product->get_id() == $current_page ) {
                $html .= '<i style="display:none!important;" class="brapl_variation_replace" data-type="'.$html_type.'"></i>';
            }
        }
        return $html;
    }
}
new BeRocket_products_label_labels_for_variations_class(); 
