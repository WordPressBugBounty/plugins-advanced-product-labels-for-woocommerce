<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BRAPL_Divi5_Integration {
    private static $initialized = false;

    public static function init() {
        if ( self::$initialized ) {
            return;
        }

        self::$initialized = true;
        add_action( 'divi_module_library_modules_dependency_tree', array( __CLASS__, 'register_modules' ) );
        add_action( 'divi_visual_builder_assets_before_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_brapl_divi5_preview', array( __CLASS__, 'preview_ajax' ) );
    }

    public static function is_divi5_enabled() {
        return function_exists( 'et_builder_d5_enabled' ) && et_builder_d5_enabled();
    }

    public static function register_modules( $dependency_tree ) {
        if ( self::is_divi5_enabled() && class_exists( 'ET\Builder\Packages\ModuleLibrary\ModuleRegistration' ) ) {
            require_once __DIR__ . '/loader.php';
            brapl_divi5_register_modules( $dependency_tree );
        }
    }

    public static function enqueue_assets() {
        if ( ! self::is_divi5_enabled() || ! class_exists( 'ET\Builder\VisualBuilder\Assets\PackageBuildManager' ) ) {
            return;
        }

        $asset_path = dirname( __DIR__ ) . '/visual-builder/build/woocommerce-advanced-products-labels-divi5.js';
        if ( ! file_exists( $asset_path ) ) {
            return;
        }

        \ET\Builder\VisualBuilder\Assets\PackageBuildManager::register_package_build(
            array(
                'name'    => 'woocommerce-advanced-products-labels-divi5-visual-builder',
                'version' => BeRocket_products_label_version . '-' . filemtime( $asset_path ),
                'script'  => array(
                    'src'                => add_query_arg(
                        array(
                            'brapl_ajax_url' => rawurlencode( admin_url( 'admin-ajax.php' ) ),
                            'brapl_action'   => 'brapl_divi5_preview',
                            'brapl_nonce'    => wp_create_nonce( 'brapl_divi5_preview' ),
                            'brapl_build'    => filemtime( $asset_path ),
                        ),
                        plugins_url( 'visual-builder/build/woocommerce-advanced-products-labels-divi5.js', dirname( __DIR__ ) . '/divi5.php' )
                    ),
                    'deps'               => array( 'divi-module-library', 'divi-vendor-wp-hooks' ),
                    'enqueue_top_window' => false,
                    'enqueue_app_window' => true,
                ),
            )
        );
    }

    public static function preview_ajax() {
        if ( ! check_ajax_referer( 'brapl_divi5_preview', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'BeRocket_products_label_domain' ) ), 403 );
        }

        if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) && ! current_user_can( 'edit_theme_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to preview this module.', 'BeRocket_products_label_domain' ) ), 403 );
        }

        $module_name = empty( $_POST['module'] ) ? '' : sanitize_text_field( wp_unslash( $_POST['module'] ) );
        $attrs_json  = empty( $_POST['attrs'] ) ? '{}' : wp_unslash( $_POST['attrs'] );
        $attrs       = json_decode( $attrs_json, true );

        if ( ! is_array( $attrs ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid module attributes.', 'BeRocket_products_label_domain' ) ), 400 );
        }

        require_once __DIR__ . '/modules.php';
        require_once __DIR__ . '/ModuleRenderer.php';

        $module = brapl_divi5_get_modules( $module_name );
        if ( empty( $module ) ) {
            wp_send_json_error( array( 'message' => __( 'Unknown Divi module.', 'BeRocket_products_label_domain' ) ), 404 );
        }

        $renderer = new BRAPL_Divi5_Module_Renderer( $module );
        wp_send_json_success(
            array(
                'html' => $renderer->render_module( $attrs ),
            )
        );
    }
}
