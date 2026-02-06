<?php
if( class_exists('BeRocket_admin_bar_plugin_data') ) {
    class BeRocket_apl_admin_bar_debug extends BeRocket_admin_bar_plugin_data {
        public $product_data = array();
        public $label_data = array();
        function __construct() {
            $BeRocket_products_label = BeRocket_products_label::getInstance();
            $this->slug = $BeRocket_products_label->info['plugin_name'];
            $this->name = $BeRocket_products_label->info['norm_name'];
            add_action('bapl_show_label_on_product_false', array($this, 'label_not_displayed'), 10, 4);
            add_filter('berocket_apl_show_label_on_product_html', array($this, 'label_html'), 10, 4);
            parent::__construct();
        }
        function label_not_displayed($error, $br_label, $product, $label_id) {
            if( $product === 'demo' ) return;
            $product_id = $product->get_id();
            $this->set_label_status($label_id, $product_id, $error);
        }
        function label_html($html, $br_label, $product, $label_id) {
            if( $product === 'demo' ) return $html;
            $product_id = $product->get_id();
            $this->set_label_status($label_id, $product_id, 'displayed');
            return $html;
        }
        function set_label_status($label_id, $product_id, $status) {
            if( ! isset($this->product_data[$product_id]) ) {
                $this->product_data[$product_id] = array();
            }
            $this->product_data[$product_id][$label_id] = $status;
            if( empty($this->label_data[$status]) ) {
                $this->label_data[$status] = array();
            }
            if( empty($this->label_data[$status][$label_id]) ) {
                $this->label_data[$status][$label_id] = 0;
            }
            $this->label_data[$status][$label_id]++;
        }
		function get_html() {
            $html = '';
            if( empty($this->label_data) ) {
                $BeRocket_products_label = BeRocket_products_label::getInstance();
                $labels_ids = $BeRocket_products_label->get_labels_ids();
                $html .= '<div>';
                if( count($labels_ids) > 0 ) {
                    $html .= 'Site has ' . count($labels_ids) . ' label, but page do not use selected selectors. Please change it in plugin settings';
                } else {
                    $html .= 'Site do not have labels';
                }
                $html .= '</div>';
            } else {
                foreach($this->label_data as $status => $labels) {
                    $html .= '<div><h3>'.esc_html(ucfirst(trim(str_replace('_', ' ', $status)))).'</h3><ul>';
                    foreach( $labels as $label_id => $count ) {
                        $title = get_the_title($label_id);
                        $html .= '<li title="'.esc_html($title).'"><a href="'.admin_url('post.php?post='.$label_id.'&action=edit').'" target="_blank">'.esc_html($label_id.'('.$count.')').'</a></li>';
                    }
                    $html .= '</ul></div>';
                }
            }
			return $html;
		}
		function get_css() {
			$html = '<style>
            #wp-admin-bar-berocket_debug_bar .ab-submenu .ab-item .berocket_admin_bar_plugin_block_products_label h3{font-weight:bold;color:#0085ba;font-size: 1.25em;text-align:center;}

            #wp-admin-bar-berocket_debug_bar .ab-submenu .ab-item .berocket_admin_bar_plugin_block_products_label {width:100%;}

			#wp-admin-bar-berocket_debug_bar .ab-submenu .ab-item .berocket_admin_bar_plugin_block_products_label ul {display: flex; flex-wrap: wrap; max-width: 300px;}
            #wp-admin-bar-berocket_debug_bar .ab-submenu .ab-item .berocket_admin_bar_plugin_block_products_label ul li {display:inline-block!important;}
			#wp-admin-bar-berocket_debug_bar .ab-submenu .ab-item .berocket_admin_bar_plugin_block_products_label ul li a {height:initial;margin:0;padding:2px;}
			</style>';
			return $html;
		}
    }
    new BeRocket_apl_admin_bar_debug();
}