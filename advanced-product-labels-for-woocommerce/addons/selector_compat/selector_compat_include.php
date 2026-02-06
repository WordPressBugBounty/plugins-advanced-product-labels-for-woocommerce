<?php
class BeRocket_products_label_selector_compat_addon_include {
    public $products = array();
    function __construct() {
        add_filter('the_posts', array($this, 'get_products'), 1000, 2);
        add_action('wp_footer', array($this, 'footer'));
        add_filter( 'wp_head', array( $this, 'load_scripts' ), 15 );
        add_filter('brfr_data_products_label', array($this, 'settings'), 1000);
    }

    public function load_scripts() {
        $BeRocket_products_label = BeRocket_products_label::getInstance();
        $option = $BeRocket_products_label->get_option();
        wp_enqueue_script( 'berocket_label_selector_compat_scripts', plugins_url( 'js/frontend.js', __FILE__ ), array( 'jquery' ), BeRocket_products_label_version );

        wp_localize_script( 'berocket_label_selector_compat_scripts', 'brlabelsSelectors',
            array(
                'post_id'   => ( empty($option['selector_post_id']) ? '.post-%ID%'      : $option['selector_post_id']),
                'image'     => ( empty($option['selector_image'])   ? '.wp-post-image'  : $option['selector_image']),
                'title'     => ( empty($option['selector_title'])   ? '.title'          : $option['selector_title']),
            )
        );
    }
    function get_products($posts, $query) {
        if ( is_admin() || ! $query instanceof WP_Query ) {
            return $posts;
        }
        $post_type = $query->get( 'post_type' );
        $ids = array();
        if ( ( is_string( $post_type ) && 'product' === $post_type ) ) {
            foreach ( $posts as $p ) {
                $ids[] = is_object( $p ) ? (int) $p->ID : (int) $p;
            }
        } elseif ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) {
            foreach ( $posts as $p ) {
                $post_type = get_post_type( $p );
                if ( $post_type === 'product' ) {
                    $ids[] = is_object( $p ) ? (int) $p->ID : (int) $p;
                }
            }
        }
        $this->products = array_merge($this->products, $ids);
        return $posts;
    }
    function footer() {
        global $product;
        $products = $this->products;
        if( count($products) > 0 ) {
            echo '<div class="bapl_replacements" style="display:none;">';
            foreach($products as $product_id) {
                echo '<div class="bapl_replace" data-id="'.$product_id.'">';
                do_action('berocket_apl_set_label', true, $product_id);
                echo '</div>';
            }
            echo '</div>';
        }
    }
    function settings($data) {
        $data['Advanced']['selector_post_id'] = array(
            "type"     => "text",
            "label"    => __('Product selector', 'BeRocket_products_label_domain'),
            "label_for"=> __('%ID% will be replacced with product ID it must be specific selector for product block', 'BeRocket_products_label_domain'),
            "name"     => "selector_post_id",
            "value"    => ".post-%ID%",
            'tr_class' => 'bapl_selectors_compat'
        );
        $data['Advanced']['selector_image'] = array(
            "type"     => "text",
            "label"    => __('Main image selector', 'BeRocket_products_label_domain'),
            "name"     => "selector_image",
            "value"    => ".wp-post-image",
            'tr_class' => 'bapl_selectors_compat'
        );
        $data['Advanced']['selector_title'] = array(
            "type"     => "text",
            "label"    => __('Title selector', 'BeRocket_products_label_domain'),
            "name"     => "selector_title",
            "value"    => ".title",
            'tr_class' => 'bapl_selectors_compat'
        );
        return $data;
    }
}
new BeRocket_products_label_selector_compat_addon_include();