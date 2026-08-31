<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BRAPL_Divi5_Module_Renderer {
    protected $module_name;
    protected $module_type;
    protected $defaults;

    public function __construct( $args ) {
        $this->module_name = $args['name'];
        $this->module_type = $args['type'];
        $this->defaults    = $args['defaults'];
    }

    public function render_module( $attrs, $content = '' ) {
        $atts = $this->attrs_to_atts( $attrs );

        if ( 'label' === $this->module_type ) {
            return $this->render_label( $atts );
        }

        return '';
    }

    public function attrs_to_atts( $attrs ) {
        $atts = $this->defaults;

        foreach ( array_keys( $this->defaults ) as $key ) {
            $value = $this->get_attr_value( $attrs, $key );
            if ( null !== $value ) {
                $atts[ $key ] = $value;
            }
        }

        return self::convert_on_off( $atts );
    }

    protected function render_label( $atts ) {
        $product = '';

        if ( ! empty( $atts['product'] ) ) {
            if ( 'latest' === $atts['product'] ) {
                global $wpdb;
                $product = $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' ORDER BY ID DESC LIMIT 1" );
            } elseif ( 'current' !== $atts['product'] && 'dynamic' !== $atts['product'] ) {
                $product = absint( $atts['product'] );
            }
        }

        if ( empty( $atts['type'] ) || ( 'image' !== $atts['type'] && 'label' !== $atts['type'] ) ) {
            $atts['type'] = true;
        }

        ob_start();
        do_action( 'berocket_apl_set_label', $atts['type'], $product );
        return ob_get_clean();
    }

    protected function get_attr_value( $attrs, $key ) {
        if ( 'product' === $key ) {
            $product = $this->get_product_attr_value( $attrs );
            if ( null !== $product ) {
                return $product;
            }
        }

        if ( isset( $attrs[ $key ]['innerContent']['desktop']['value'] ) ) {
            return $this->normalize_attr_value( $attrs[ $key ]['innerContent']['desktop']['value'] );
        }

        if ( isset( $attrs[ $key ] ) && ! is_array( $attrs[ $key ] ) ) {
            return $attrs[ $key ];
        }

        return null;
    }

    protected function get_product_attr_value( $attrs ) {
        if ( isset( $attrs['content']['advanced']['product']['desktop']['value'] ) ) {
            return $this->normalize_attr_value( $attrs['content']['advanced']['product']['desktop']['value'] );
        }

        if ( isset( $attrs['content']['advanced']['product'] ) && ! is_array( $attrs['content']['advanced']['product'] ) ) {
            return $attrs['content']['advanced']['product'];
        }

        return null;
    }

    protected function normalize_attr_value( $value ) {
        if ( is_array( $value ) ) {
            foreach ( array( 'value', 'number', 'amount' ) as $value_key ) {
                if ( isset( $value[ $value_key ] ) && '' !== $value[ $value_key ] && ! is_array( $value[ $value_key ] ) ) {
                    return $value[ $value_key ];
                }
            }

            return '';
        }

        return $value;
    }

    public static function convert_on_off( $atts ) {
        foreach ( $atts as &$attr ) {
            if ( 'on' === $attr || 'off' === $attr ) {
                $attr = ( 'on' === $attr );
            }
        }

        return $atts;
    }
}
