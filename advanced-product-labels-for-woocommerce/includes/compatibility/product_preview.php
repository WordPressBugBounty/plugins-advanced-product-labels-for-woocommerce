<?php
class BeRocket_Labels_compat_product_preview {
    function __construct() {
        add_action( 'berocket_pp_popup_inside_image', array(__CLASS__, 'show_labels') );
        add_action( 'berocket_pp_popup_inside_thumbnails', array(__CLASS__, 'show_labels') );
        add_action( 'BeRocket_preview_after_general_settings', array(__CLASS__, 'settings'), 10, 2 );
        //add_action( 'br_before_preview_box', array(__CLASS__, 'before_preview'));
        //add_action( 'br_after_preview_box', array(__CLASS__, 'after_preview'));
    }
    public static function show_labels($options) {
        if( empty($options['hide_berocket_labels']) ) {
            $BeRocket_products_label = BeRocket_products_label::getInstance();
            $BeRocket_products_label->set_all_label();
        }
    }
    public static function settings($name, $options) {
        echo '
        <tr>
            <th>' . __( 'Hide BeRocket Advanced Labels', 'BeRocket_products_label_domain' ) . '</th>
            <td>
                <input type="checkbox" name="' . $name . '[hide_berocket_labels]"' . (empty($options['hide_berocket_labels']) ? '' : 'checked') . '>
            </td>
        </tr>';
    }
    public static function before_preview() {
        $BeRocket_products_label = BeRocket_products_label::getInstance();
        $BeRocket_products_label->add_remove_product_hook('remove');
        $BeRocket_products_label->add_remove_shop_hook('remove');
    }
    public static function after_preview() {
        $BeRocket_products_label = BeRocket_products_label::getInstance();
        $BeRocket_products_label->add_remove_product_hook();
        $BeRocket_products_label->add_remove_shop_hook();
    }
}
new BeRocket_Labels_compat_product_preview();
