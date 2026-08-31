<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/modules.php';
require_once __DIR__ . '/ModuleRenderer.php';
require_once __DIR__ . '/Module.php';

function brapl_divi5_register_modules( $dependency_tree ) {
    foreach ( brapl_divi5_get_modules() as $module ) {
        $dependency_tree->add_dependency( new BRAPL_Divi5_Module( $module ) );
    }
}
