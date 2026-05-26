<?php
namespace BeRocket\EngagementEngine;

class PostLimit extends PostBase {
	public string $post_name = 'PostLimit';
	public string $plugin_sku = 'minmax';
	public string $plugin_name = 'MM_Quantity';

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
								'brfr_MM_Quantity_settings_item_' .
								$feature[ $location ] . $type . '_' . $location,
								[ $this, 'output_locked_features' . $type ], 10, 4
							);
						}
					}
				}
			}
		}
	}

	public function minmax_limitation_inputs_after( $html ) {
		$data = get_option( BR_EE_OPTION );
		if ( ! empty( $data['locked_features']['minmax']['posts']['PostLimit'] ) ) {
			$locked_features = $data['locked_features']['minmax']['posts']['PostLimit'];
			foreach ( $locked_features as $feature ) {
				if ( $feature['function'] == 'minmax_limitation_inputs_after' and
				     current_filter() == $feature['hook'] and
				     $this->is_locked( $feature )
				) {
					$this->add_tooltip( $feature );
					$html .= $this->o_locked_features->output( $feature, [] );
				}
			}
		}

		return $html;
	}

	public function add_settings_tab( $tabs_info ) {
		$data   = get_option( BR_EE_OPTION );
		$plugin = $this->plugin_sku;
		$plugin_name = $this->plugin_name;
		$plugin_version_capability = apply_filters( 'brfr_get_plugin_version_capability_' . $plugin_name, 0 );

		if ( ! empty( $data['locked_features'][ $plugin ]['posts'][ $this->post_name ] ) ) {
			$locked_features = $data['locked_features'][ $plugin ]['posts'][ $this->post_name ];

			if ( is_array( $locked_features ) ) {
				foreach ( $locked_features as $feature ) {
					if ( $feature['function'] == 'add_settings_tab' and
					     current_filter() == $feature['hook'] and
					     (
						     ( empty( $plugin_version_capability ) or $plugin_version_capability < 10 )
						     or
						     ( 'business' == $feature['license'] and
						       ( $plugin_version_capability > 10 and $plugin_version_capability < 100 )
						     )
					     )
					) {
						$new_item = [
							$feature['label'] => array(
								'icon'     => $feature['icon'],
								'name'     => __( $feature['label'], 'BeRocket_domain' ),
								'priority' => $feature['license'] . "-preview",
							)
						];

						if ( ! empty( $feature['after_tab'] ) and
						     false !== ( $position = array_search( $feature['after_tab'], array_keys( $tabs_info ), true ) )
						) {
							$tabs_info = array_slice( $tabs_info, 0, $position + 1, true )
							             + $new_item
							             + array_slice( $tabs_info, $position + 1, null, true );
						} else {
							$tabs_info += $new_item;
						}
					}
				}
			}
		}

		return $tabs_info;
	}

	public function data_add_option( $settings_data ) {
		$data   = get_option( BR_EE_OPTION );
		$plugin = $this->plugin_sku;
		$plugin_name = $this->plugin_name;
		$plugin_version_capability = apply_filters( 'brfr_get_plugin_version_capability_' . $plugin_name, 0 );

		if ( ! empty( $data['locked_features'][ $plugin ]['posts'][ $this->post_name ] ) ) {
			$locked_features = $data['locked_features'][ $plugin ]['posts'][ $this->post_name ];

			if ( is_array( $locked_features ) ) {
				foreach ( $locked_features as $feature ) {
					if ( $feature['function'] == 'data_add_option' and
					     current_filter() == $feature['hook'] and
					     (
						     ( empty( $plugin_version_capability ) or $plugin_version_capability < 10 )
						     or
						     ( 'business' == $feature['license'] and
						       ( $plugin_version_capability > 10 and $plugin_version_capability < 100 )
						     )
					     )
					) {
						$new_item = [
							$feature['section'] => [
								$feature['name'] => array(
									'section' => $feature['name'],
								)
							]
						];

						add_filter('brfr_berocket_minmax_custom_post_' . $feature['name'], function () {
							return '<th></th><td> </td>';
						});

						$settings_data += $new_item;
					}
				}
			}
		}

		return $settings_data;
	}
}