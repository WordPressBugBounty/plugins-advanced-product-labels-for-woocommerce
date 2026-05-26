<?php
namespace BeRocket\EngagementEngine;

class PostLocation extends PostBase {
	public string $post_name = 'PostLocation';
	public string $plugin_sku = 'tabs';
	public string $plugin_name = 'tab_manager';

	public function __construct( $o_locked_features ) {
		parent::__construct( $o_locked_features );

		$data = get_option( BR_EE_OPTION );
		if ( ! empty( $data['locked_features'][ $this->plugin_sku ]['posts'][ $this->post_name ] ) ) {
			$locked_features = $data['locked_features'][ $this->plugin_sku ]['posts'][ $this->post_name ];
			foreach ( $locked_features as $feature ) {
				foreach ( [ 'before', 'after' ] as $location ) {
					foreach ( [ '', '_inline' ] as $type ) {
						if ( ! empty( $feature[ $location ] ) ) {
							add_filter(
								'brfr_tab_manager_settings_item_' .
								$feature[ $location ] . $type . '_' . $location,
								[ $this, 'output_locked_features' . $type ], 10, 4
							);
						}
					}
				}
			}
		}
	}
}