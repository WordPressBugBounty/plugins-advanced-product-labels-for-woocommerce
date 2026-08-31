<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function brapl_divi5_get_modules( $module_name = '' ) {
    $modules = array(
        'brapl/label' => array(
            'name'         => 'brapl/label',
            'module_dir'   => 'label',
            'module_class' => 'et_pb_brlabel',
            'type'         => 'label',
            'defaults'     => array(
                'product' => 'current',
                'type'    => 'all',
            ),
        ),
    );

    if ( '' !== $module_name ) {
        return isset( $modules[ $module_name ] ) ? $modules[ $module_name ] : array();
    }

    return $modules;
}
