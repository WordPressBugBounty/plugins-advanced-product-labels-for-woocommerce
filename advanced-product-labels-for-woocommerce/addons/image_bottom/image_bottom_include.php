<?php
class BeRocket_products_label_image_bottom_class {
    function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'load_scripts'));
        add_filter( 'brfr_data_berocket_advanced_label_editor', array( $this, 'additional_settings' ), 1000 );
        add_filter( 'brfr_data_products_label', array( $this, 'plugin_settings' ), 1000 );
    }

    public function load_scripts() {
        wp_enqueue_style( 'berocket_image_bottom_css', plugins_url( 'assets/frontend.css', __FILE__ ) );
        wp_enqueue_script( 'berocket_image_bottom', plugins_url( 'assets/frontend.js', __FILE__ ), array( 'jquery' ) );
        $BeRocket_products_label = BeRocket_products_label::getInstance();
        $options = $BeRocket_products_label->get_option();
        $localized = wp_localize_script(
            'berocket_image_bottom',
            'bapl_image_btm',
            array(
                'parent'    => (empty($options['img_bottom_parent']) ? '.product' : esc_html($options['img_bottom_parent'])),
                'find'      => (empty($options['img_bottom_find']) ? 'img' : esc_html($options['img_bottom_find'])),
            )
        );
    }
    public function additional_settings($data) {
        ?>
<script>
function bapl_image_label_bottom_options() {
    var disabled = jQuery('.berocket_label_type_select').val() != 'image';
    if( disabled && ( jQuery('select[name="br_labels[position]"]').val() == null || jQuery('select[name="br_labels[position]"]').val() == 'right brbottom' || jQuery('select[name="br_labels[position]"]').val() == 'left brbottom' ) ) {
        jQuery('select[name="br_labels[position]"]').val('left');
    }
    jQuery('select[name="br_labels[position]"] option[value="right brbottom"]').prop('disabled', disabled);
    jQuery('select[name="br_labels[position]"] option[value="left brbottom"]').prop('disabled', disabled);
}
jQuery(document).on('change', '.berocket_label_type_select', bapl_image_label_bottom_options);
jQuery(document).ready(bapl_image_label_bottom_options);
</script>
        <?php
        $data['Position']['position']['options'][] = array('value' => 'right brbottom',  'text'   => __('Right Bottom', 'BeRocket_products_label_domain'));
        $data['Position']['position']['options'][] = array('value' => 'left brbottom',   'text'   => __('Left Bottom', 'BeRocket_products_label_domain'));
        return $data;
    }
    public function plugin_settings($data) {
        $data['Advanced']['img_bottom_parent'] = array(
            "type"     => "text",
            "label"    => __('Image Parent Selector', 'BeRocket_products_label_domain'),
            "name"     => "img_bottom_parent",
            "value"    => '.product',
            'tr_class' => 'bapl_imgbtm_selectors'
        );
        $data['Advanced']['img_bottom_find'] = array(
            "type"     => "text",
            "label"    => __('Image Main Selector', 'BeRocket_products_label_domain'),
            "name"     => "img_bottom_find",
            "value"    => 'img',
            'tr_class' => 'bapl_imgbtm_selectors'
        );
        return $data;
    }
}
new BeRocket_products_label_image_bottom_class(); 
