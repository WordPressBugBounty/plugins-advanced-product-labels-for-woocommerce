<?php
class BeRocket_products_label_ajax_load_class {
    function __construct() {
        if( ! is_admin() ) {
            if( empty($_REQUEST['action']) || $_REQUEST['action'] !== 'bapl_ajax_load' ) {
                add_action( 'brapl_construct_end', array($this, 'replace_labels'), 10, 1);
            }
            $this->init();
            add_filter('bapf_paid_the_title_labels', array($this, 'replace_title_label'), 10);
        }
        add_action('wp_ajax_bapl_ajax_load', array($this, 'ajax_load') );
        add_action('wp_ajax_nopriv_bapl_ajax_load', array($this, 'ajax_load') );
    }
    function init() {
        $BeRocket_products_label = BeRocket_products_label::getInstance();
        wp_register_script( 'bapl_ajax_load', plugins_url( 'js/frontend.js',  __FILE__ ), array( 'jquery' ), $BeRocket_products_label->info['version'] );
        wp_localize_script( 'bapl_ajax_load', 'bapl_ajax_load_data', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
        ) );
        wp_enqueue_script ( 'bapl_ajax_load' );
    }
    function replace_labels($instance) {
        remove_action('berocket_apl_set_label', array($instance, 'set_label'), 10, 2);
        add_action('berocket_apl_set_label', array($this, 'set_label'), 10, 2);
        if( ! empty($instance->active_addons['timers']) ) {
            remove_action('bapl_show_all_large_timers', array($instance->active_addons['timers'], 'show_all_large_timers'), 10, 2);
            add_action('bapl_show_all_large_timers', array($this, 'show_all_large_timers'), 10, 2);
        }
    }
    function replace_title_label() {
        add_filter('the_title', array($this, 'product_title'), 100, 2);
        return false;
    }
    function set_label($type = TRUE, $product_id = '') {
        if ( empty( $product_id ) ) {
            global $product;
        } else {
            $product = wc_get_product( $product_id );
        }
        if( $type === true ) {
            $type = 'all';
        }
        if( ! empty($product) ) {
            echo '<div class="bapl_ajax_replace bapl_ajax_' . trim($type) . '" style="display:none;" data-id="' . $product->get_id() . '"></div>';
        }
    }
    function show_all_large_timers($product_id = '') {
        if ( empty( $product_id ) ) {
            global $product;
        } else {
            $product = wc_get_product( $product_id );
        }
        if( ! empty($product) ) {
            echo '<div class="bapl_ajax_replace bapl_ajax_timer" style="display:none;" data-id="' . $product->get_id() . '"></div>';
        }
    }
    function product_title($title, $id = false) {
        if ( is_admin() || is_product() || $id === false ) {
            return $title;
        }

        global $product;
        if( ! empty($product) && is_a($product, 'wc_product') && $product->get_id() == $id ) {
            $title = '<div class="bapl_ajax_replace bapl_ajax_tleft" style="display:none;" data-id="' . $product->get_id() . '"></div>'
            .$title. '<div class="bapl_ajax_replace bapl_ajax_tright" style="display:none;" data-id="' . $product->get_id() . '"></div>';
        }
        return $title;
    }
    function ajax_load() {
        if( empty($_POST['products']) || ! is_array($_POST['products']) ) {
            echo json_encode(array());
            wp_die();
        }
        $BeRocket_products_label = BeRocket_products_label::getInstance();
        $products = $_POST['products'];
        foreach($products as &$product_id) {
            $product_id = intval($product_id);
        }
        $products = array_unique($products);
        $labels = array();
        foreach($products as $product_id) {
            $label = array('image' => '', 'label' => '');
            ob_start();
            do_action('berocket_apl_set_label', 'image', $product_id);
            $label['image'] = ob_get_clean();
            ob_start();
            do_action('berocket_apl_set_label', 'label', $product_id);
            $label['label'] = ob_get_clean();
            ob_start();
            do_action('bapl_show_all_large_timers', $product_id);
            $label['timer'] = ob_get_clean();
            $product = wc_get_product($product_id);
            $labels_array = $BeRocket_products_label->get_product_labels_ids( array(), $product );
            $in_title_labels = array('left' => array(), 'right' => array());
            foreach ( $labels_array as $label_id ) {
                $br_label = $BeRocket_products_label->custom_post->get_option($label_id);
                if ( $br_label['type'] != 'in_title' ) continue;
                $br_label = apply_filters( 'berocket_label_adjust_options', $br_label );
                $in_title_labels[$br_label['position']][] = $BeRocket_products_label->show_label_on_product($br_label, $product, $label_id, 'return');
            }
            $label['tleft'] = empty( $in_title_labels['left'] ) ? '' : implode( $in_title_labels['left'] );
            $label['tright'] = empty( $in_title_labels['right'] ) ? '' : implode( $in_title_labels['right'] );

            $labels[$product_id] = $label;
        }
        echo json_encode($labels);
        wp_die();
    }
}
new BeRocket_products_label_ajax_load_class(); 
