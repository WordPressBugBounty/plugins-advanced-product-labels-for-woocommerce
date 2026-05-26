<?php
namespace BeRocket\EngagementEngine;

class PostLabel extends PostBase {
	public string $post_name = 'PostLabel';
	public string $plugin_sku = 'labels';
	public string $plugin_name = 'products_label';

	public function __construct( $o_locked_features ) {
		parent::__construct( $o_locked_features );

		$data = get_option( BR_EE_OPTION );
		if ( ! empty( $data['locked_features'][ $this->plugin_sku ]['posts'][ $this->post_name ] ) ) {
			$locked_features = $data['locked_features'][ $this->plugin_sku ]['posts'][ $this->post_name ];
			foreach ( $locked_features as $feature ) {
				foreach ( ['before', 'after'] as $location ) {
					foreach ( ['', '_inline'] as $type ) {
						if ( ! empty( $feature[ $location ] ) ) {
							add_filter(
								'brfr_products_label_settings_item_' .
								$feature[ $location ] . $type . '_' . $location,
								[ $this, 'output_locked_features' . $type ], 10, 4
							);
						}
					}
				}
			}
		}
	}

	public function output_locked_features_inline( $page_content, $item, $tab_name, $tab_content ): string {
		$data   = get_option( BR_EE_OPTION );
		$plugin = 'labels';
		$plugin_name = 'products_label';
		$item_name = ( is_array( $item['name'] ) ? implode( '_', $item['name'] ) : $item['name'] );
		$plugin_version_capability = apply_filters( 'brfr_get_plugin_version_capability_' . $plugin_name, 0 );
		$hook = ( substr( current_filter(), -7 ) === '_before') ? 'before' : 'after';

		if ( ! empty( $data['locked_features'][ $plugin ]['posts'][ $this->post_name ] ) ) {
			$locked_features = $data['locked_features'][ $plugin ]['posts'][ $this->post_name ];

			if ( is_array( $locked_features ) ) {
				foreach ( $locked_features as $feature ) {
					if ( $tab_name == $feature['section'] and
					     'inline' == $feature['location'] and
					     $item_name == $feature[ $hook ] and
					     $this->is_locked( $feature )
					) {
						$this->add_tooltip( $feature, [ 'plugin_name' => $plugin ] );
						$page_content .= $this->o_locked_features->output( $feature, $item, [ 'plugin_name' => $plugin ] );
					}
				}
			}
		}

		return $page_content;
	}

	public function premium_select_option( $tabs_data ): array {
		$data   = get_option( BR_EE_OPTION );
		$plugin = 'labels';

		if ( ! empty( $data['locked_features'][ $plugin ]['posts'][ $this->post_name ] ) ) {
			$locked_features = $data['locked_features'][ $plugin ]['posts'][ $this->post_name ];

			if ( is_array( $locked_features ) ) {
				foreach ( $locked_features as $feature ) {
					if ( $feature['function'] == 'premium_select_option' and
					     current_filter() == $feature['hook'] and
					     $this->is_locked( $feature )
					) {
						$tabs_data[ $feature['section'] ][ $feature['name'] ]['options'][] = array(
							'value' => '',
							'text'  => __($feature['label'], 'BeRocket_products_label_domain'),
							'extra' => ' disabled="disabled" ',
						);
					}
				}
			}
		}

		return $tabs_data;
	}
}