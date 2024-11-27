<?php

class ET_Builder_Module_brlabel extends ET_Builder_Module {

	public $slug       = 'et_pb_brlabel';
	public $vb_support = 'on';

	protected $module_credits = array(
		'module_uri' => '',
		'author'     => '',
		'author_uri' => '',
	);
    function init() {
        $this->name       = __( 'BeRocket Product Label', 'BeRocket_products_label_domain' );
		$this->folder_name = 'et_pb_berocket_modules';
		$this->main_css_element = '%%order_class%%';

        $this->fields_defaults = array(
            'product'               => array('current'),
            'type'                  => array( 'type', 'add_default_setting' ),
        );
		$this->advanced_fields = array(
			'fonts'         => false,
			'link_options'  => false,
			'visibility'    => false,
			'text'          => false,
			'transform'     => false,
			'animation'     => false,
			'background'    => false,
			'borders'       => false,
			'box_shadow'    => false,
			'button'        => false,
			'filters'       => false,
			'margin_padding'=> false,
			'max_width'     => false,
		);
    }

    function get_fields() {
        $fields = array(
            'product' => array(
                'label'            => esc_html__( 'Product', 'et_builder' ),
                'type'             => 'select_product',
                'description'      => esc_html__( 'Here you can select the Product.', 'et_builder' ),
                'searchable'       => true,
                'displayRecent'    => false,
                'default'          => 'current',
                'post_type'        => 'product',
                'computed_affects' => array(
                    '__product',
                ),
            ),
            'type' => array(
                "label"           => esc_html__( 'Label Type', 'BeRocket_products_label_domain' ),
                'type'            => 'select',
                'options'         => array(
                    'image'         => esc_html__( 'On Image', 'BeRocket_products_label_domain' ),
                    'label'         => esc_html__( 'Label', 'BeRocket_products_label_domain' ),
                    'all'           => esc_html__( 'All', 'BeRocket_products_label_domain' ),
                )
            ),
        );

        return $fields;
    }

    function render( $atts, $content = null, $function_name = '' ) {
        $atts = BREX_LabelExtension::convert_on_off($atts);
        if( ! empty($atts['product']) ) {
            if( $atts['product'] == 'latest' ) {
                global $wpdb;
                $atts['product'] = $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' ORDER BY ID DESC LIMIT 1");
            } elseif( $atts['product'] == 'current' ) {
                $atts['product'] = '';
            }
        } else {
            $atts['product'] = '';
        }
        if( empty($atts['type']) || ( $atts['type'] != 'image' && $atts['type'] != 'label' ) ) {
            $atts['type'] = TRUE;
        }
        ob_start();
        do_action('berocket_apl_set_label', $atts['type'], $atts['product']);
        return ob_get_clean();
    }
}

new ET_Builder_Module_brlabel;
