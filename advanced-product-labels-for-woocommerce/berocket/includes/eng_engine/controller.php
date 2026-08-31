<?php
namespace BeRocket\EngagementEngine;

include_once __DIR__ . '/classes/data_store.php';

\define('BR_EE_OPTION', DataStore::DATA_OPTION);
\define('BR_EE_HIDDEN', 'berocket_ee_lf_hidden');
\define('BR_EE_LIST', ['locked_features', 'smart_triggers', 'notices', 'news', 'banners']);

include_once __DIR__ . '/classes/data_loader.php';
include_once __DIR__ . '/classes/messaging.php';
include_once __DIR__ . '/classes/locked_features.php';

class EngagementEngine {
	public function __construct() {
		// for test ( new DataLoader() )->test_data();

		add_action( 'admin_init', array( $this, 'load_data' ) );
		add_action( 'admin_init', array( $this, 'init' ) );
	}

	public function load_data() {
		$timer = get_option( DataStore::LOADED_AT_OPTION, 1 );

		if ( ! DataStore::is_current_timestamp( $timer ) ) {
			update_option( DataStore::LOADED_AT_OPTION, time() );
			( new DataLoader() )->load();
		}
	}

	public function init() {
		if ( current_user_can( 'manage_options' ) ) {
			$lf = new LockedFeatures();
			if ( ! wp_doing_ajax() ) {
				$lf->init();
			}
		}
	}
}

new EngagementEngine();
