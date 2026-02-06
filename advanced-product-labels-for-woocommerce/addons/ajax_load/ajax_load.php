<?php
class BeRocket_products_label_ajax_load_addon extends BeRocket_framework_addon_lib {
    public $addon_file = __FILE__;
    public $plugin_name = 'products_label';
	public $php_file_name   = '%plugindir%/paid/addons/ajax_load/ajax_load_include';
    function get_addon_data() {
        $data = parent::get_addon_data();
        return array_merge($data, array(
            'addon_name'    => 'AJAX load',
            'tooltip'       => __( 'Load labels via AJAX after when page is loaded.', 'BeRocket_products_label_domain' ),
            'paid'          => true
        ));
    }
}
new BeRocket_products_label_ajax_load_addon();
