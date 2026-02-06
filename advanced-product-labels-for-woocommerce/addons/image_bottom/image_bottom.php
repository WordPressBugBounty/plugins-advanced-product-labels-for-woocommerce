<?php
class BeRocket_products_label_image_bottom_addon extends BeRocket_framework_addon_lib {
    public $addon_file = __FILE__;
    public $plugin_name = 'products_label';
    public $php_file_name   = 'image_bottom_include';
    function get_addon_data() {
        $data = parent::get_addon_data();
        return array_merge($data, array(
            'addon_name'    => 'Image Bottom Position',
        ));
    }
}
new BeRocket_products_label_image_bottom_addon();
