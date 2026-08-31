<?php
namespace BeRocket\EngagementEngine;

include_once __DIR__ . "/messaging.php";
include_once __DIR__ . "/post_base.php";
include_once __DIR__ . "/post_filter.php";
include_once __DIR__ . "/post_filter_group.php";
include_once __DIR__ . "/post_label.php";
include_once __DIR__ . "/post_limit.php";
include_once __DIR__ . "/post_tab.php";
include_once __DIR__ . "/post_location.php";

use BeRocket\EngagementEngine\Messaging as Messaging;
use function BeRocket\utm;

class LockedFeatures extends Messaging {
	private array $post_name_to_plugin;
	private array $plugin_posts;
	public function __construct() {
		$this->post_name_to_plugin = [
			"br_product_filter"     => "filters",
			"br_filters_group"      => "filters",
			"br_labels"             => "labels",
			"br_minmax_limitation"  => "minmax",
			"br_product_tab"        => "tabs",
			"br_tabs_location"      => "tabs",
		];
		$this->plugin_posts = [
			"filters" => [
				"br_product_filter" => "PostFilter",
				"br_filters_group"  => "PostFilterGroup",
			],
			"labels" => [
				"br_labels" => "PostLabel",
			],
			"minmax" => [
				"br_minmax_limitation" => "PostLimit",
			],
			"tabs" => [
				"br_product_tab" => "PostTab",
				"br_tabs_location" => "PostLocation",
			],
		];

		add_action( 'wp_ajax_hide_premium_features', [ $this, 'hide_premium_features' ]);
	}

	public function init() {
		$add_styles = false;
		$hidden = get_option( BR_EE_HIDDEN );

		if ( $this->is_settings() ) {
			$plugin = $this->get_plugin_sku_by_page();
			if ( ! $plugin ) {
				return;
			}

			if ( empty( $hidden[ $plugin ]['settings']['hide_till'] ) or
			     $hidden[ $plugin ]['settings']['hide_till'] < time()
			) {
				$this->init_settings();
				$add_styles = true;
			}
		}

		foreach ( $this->post_name_to_plugin  as $post_name => $plugin ) {
			if ( $this->is_post_page( $post_name ) ) {
				if ( empty( $hidden[ $plugin ][ $this->plugin_posts[ $plugin ][ $post_name ] ]['hide_till'] ) or
				     $hidden[ $plugin ][ $this->plugin_posts[ $plugin ][ $post_name ] ]['hide_till'] < time() ) {
					$this->init_post( $plugin, $post_name );
					$add_styles = true;
				}
				break;
			}
		}

		if ( $add_styles ) {
			$this->add_styles();
		}
	}

	public function init_settings() {
		$plugin = $this->get_plugin_sku_by_page();
		$data   = DataStore::get_current();

		if ( ! empty( $data['locked_features'][ $plugin ]['main'] ) ) {
			$locked_features = $data['locked_features'][ $plugin ]['main'];
			if ( is_array( $locked_features ) ) {
				$added_hooks = [];

				foreach ( $locked_features as $feature ) {
					foreach ( ['before', 'after'] as $location ) {
						foreach ( ['', '_inline'] as $type ) {
							if ( ! empty( $feature[ $location ] ) ) {
								add_filter(
									'brfr_' . $this->get_plugin_name_by_page() . '_settings_item_' .
									$feature[ $location ] . $type . '_' . $location,
									[ $this, 'output_locked_features' . $type ], 10, 4
								);
							}
						}
					}

					if ( ! empty( $feature['hook'] ) and ! empty( $feature['function'] ) and
					     ( empty( $added_hooks[ $feature['hook'] ] ) or
					       ! is_array( $added_hooks[ $feature['hook'] ] ) or
					       ! in_array( $feature['function'], $added_hooks[ $feature['hook'] ] )
						 ) and
					     method_exists( $this, $feature['function'] )
					) {
						$hook_order = $feature['hook_order'] ?? 10;
						$hook_vars  = $feature['hook_vars'] ?? 1;

						add_filter( $feature['hook'], [ $this, $feature['function'] ], $hook_order, $hook_vars );

						$added_hooks[ $feature['hook'] ][] = $feature['function'];
					}
				}

				$this->init_hide_locked_features( $plugin, 'settings' );
			}
		}
	}

	public function init_post( $plugin, $post_name ) {
		$data = DataStore::get_current();

		if ( ! empty( $data['locked_features'] ) ) {
			foreach ( $this->plugin_posts as $plugin_name => $plugin_posts ) {
				if ( $plugin == $plugin_name ) {
					foreach ( $plugin_posts as $post_page => $plugin_post ) {
						if ( $post_name == $post_page ) {
							$class_name = "BeRocket\\EngagementEngine\\{$plugin_post}";

							if ( class_exists( $class_name ) ) {
								new $class_name( $this );
								$this->init_hide_locked_features( $plugin, $plugin_post );
								return true;
							}

							break;
						}
					}
					break;
				}
			}
		}

		return false;
	}

	public function output_locked_features( $page_content, $item, $tab_name, $tab_content ): string {
		if ( empty( $item ) or empty( $tab_name ) )
			return $page_content;

		$data   = DataStore::get_current();
		$plugin = $this->get_plugin_sku_by_page();
		$plugin_name = $this->get_plugin_name_by_page();

		if ( empty( $item['name'] ) ) {
			$item_name = $item['section'];
		} else {
			$item_name = ( is_array( $item['name'] ) ? implode( '_', $item['name'] ) : $item['name'] );
		}

		$plugin_version_capability = apply_filters( 'brfr_get_plugin_version_capability_' . $plugin_name, 0 );

		if ( ! empty( $data['locked_features'][ $plugin ]['main'] ) ) {
			$locked_features = $data['locked_features'][ $plugin ]['main'];

			if ( is_array( $locked_features ) ) {
				foreach ( $locked_features as $feature ) {
					if ( ! empty( $feature['section'] ) and
					     $tab_name == $feature['section'] and
					     ! empty( $feature['location'] ) and
					     'main' == $feature['location'] and
					     ( ! empty( $feature['before'] ) or ! empty( $feature['after'] ) ) and
					     in_array( $item_name, [ $feature['before'] ?? '-1', $feature['after'] ?? '-1' ] ) and
					     (
					      ( empty( $plugin_version_capability ) or $plugin_version_capability < 10 )
					      or
					      ( 'business' == $feature['license'] and
					        ( $plugin_version_capability > 10 and $plugin_version_capability < 100 )
					      )
					     )
					) {
						$this->add_tooltip( $feature, [ 'plugin_name' => $plugin ] );
						$page_content .= $this->output( $feature, $item );
					}
				}
			}
		}

		return $page_content;
	}

	public function output_locked_features_inline( $page_content, $item, $tab_name, $tab_content ): string {
		if ( empty( $item ) or empty( $tab_name ) )
			return $page_content;

		$data   = DataStore::get_current();
		$plugin = $this->get_plugin_sku_by_page();
		$plugin_name = $this->get_plugin_name_by_page();
		if ( $plugin == 'loadmore' ) {
			$item_name = ( is_array( $item['name'] ) ? (
				$item['name'][1] ?? implode( '_', $item['name'] )
			) : $item['name'] );
		} else {
			$item_name = ( is_array( $item['name'] ) ? implode( '_', $item['name'] ) : $item['name'] );
		}
		$plugin_version_capability = apply_filters( 'brfr_get_plugin_version_capability_' . $plugin_name, 0 );

		if ( ! empty( $data['locked_features'][ $plugin ]['main'] ) ) {
			$locked_features = $data['locked_features'][ $plugin ]['main'];
			if ( is_array( $locked_features ) ) {
				foreach ( $locked_features as $feature ) {
					if ( ! empty( $feature['section'] ) and
						 $tab_name == $feature['section'] and
						 ! empty( $feature['location'] ) and
						 'inline' == $feature['location'] and
					     in_array( $item_name, [ $feature['before'] ?? '-1', $feature['after'] ?? '-1' ] ) and
					     (
						     ( empty( $plugin_version_capability ) or $plugin_version_capability < 10 )
						     or
						     ( 'business' == $feature['license'] and
						       ( $plugin_version_capability > 10 and $plugin_version_capability < 100 )
						     )
					     )
					) {
						$this->add_tooltip( $feature, [ 'plugin_name' => $plugin ] );
						$page_content .= $this->output( $feature, $item, [ 'plugin_name' => $plugin ] );
					}
				}
			}
		}

		return $page_content;
	}

	public function output_locked_features_selected_filters_template( $templates ): array {
		$data   = DataStore::get_current();
		$plugin = $this->get_plugin_sku_by_page();
		$plugin_name = $this->get_plugin_name_by_page();
		$plugin_version_capability = apply_filters( 'brfr_get_plugin_version_capability_' . $plugin_name, 0 );

		if ( ! empty( $data['locked_features'][ $plugin ]['main'] ) ) {
			$locked_features = $data['locked_features'][ $plugin ]['main'];

			if ( is_array( $locked_features ) ) {
				foreach ( $locked_features as $feature ) {
					if ( in_array( 'selected_filters_template_custom', [ $feature['before'] ?? '-1', $feature['after'] ?? '-1' ] ) and
					     (
						     ( empty( $plugin_version_capability ) or $plugin_version_capability < 10 )
						     or
						     ( 'business' == $feature['license'] and
						       ( $plugin_version_capability > 10 and $plugin_version_capability < 100 )
						     )
					     )
					) {
						list( $template_name, $template_html ) = $this->output_selected_filters_template( $feature, [ 'plugin_name' => $plugin ] );
						$templates['selected_filters+elements']['html'][ $template_name ] = $template_html;
					}
				}
			}
		}

		return $templates;
	}

	public function validate_data( $locked_features = [] ): array {
		if ( ! is_array( $locked_features ) ) {
			return [];
		}

		$validated = [];
		foreach ( $this->get_allowed_plugin_skus() as $plugin ) {
			if ( empty( $locked_features[ $plugin ] ) || ! is_array( $locked_features[ $plugin ] ) ) {
				continue;
			}

			$plugin_data = [];
			if ( ! empty( $locked_features[ $plugin ]['main'] ) && is_array( $locked_features[ $plugin ]['main'] ) ) {
				$plugin_data['main'] = $this->validate_feature_list( $locked_features[ $plugin ]['main'] );
			}

			if ( ! empty( $locked_features[ $plugin ]['posts'] ) && is_array( $locked_features[ $plugin ]['posts'] ) ) {
				foreach ( $this->get_allowed_post_names_for_plugin( $plugin ) as $post_name ) {
					if ( ! empty( $locked_features[ $plugin ]['posts'][ $post_name ] ) && is_array( $locked_features[ $plugin ]['posts'][ $post_name ] ) ) {
						$plugin_data['posts'][ $post_name ] = $this->validate_feature_list( $locked_features[ $plugin ]['posts'][ $post_name ] );
					}
				}
			}

			if ( ! empty( $plugin_data ) ) {
				$validated[ $plugin ] = $plugin_data;
			}
		}

		return $validated;
	}

	private function validate_feature_list( array $features ): array {
		$validated = [];

		foreach ( $features as $feature ) {
			$feature = $this->validate_feature( $feature );
			if ( ! empty( $feature ) ) {
				$validated[] = $feature;
			}
		}

		return $validated;
	}

	private function validate_feature( $feature ): array {
		if ( ! is_array( $feature ) ) {
			return [];
		}

		$validated = [];
		foreach ( [ 'label', 'text', 'tooltip_text', 'section', 'before', 'after', 'after_tab', 'template', 'condition', 'icon' ] as $field ) {
			if ( isset( $feature[ $field ] ) && is_scalar( $feature[ $field ] ) ) {
				$validated[ $field ] = sanitize_text_field( (string) $feature[ $field ] );
			}
		}

		foreach ( [ 'name', 'badge', 'hide_input', 'hide_lock' ] as $field ) {
			if ( isset( $feature[ $field ] ) && is_scalar( $feature[ $field ] ) ) {
				$validated[ $field ] = $this->sanitize_feature_identifier( (string) $feature[ $field ] );
			}
		}

		if ( ! empty( $feature['type'] ) && is_scalar( $feature['type'] ) ) {
			$type = sanitize_text_field( (string) $feature['type'] );
			if ( in_array( $type, $this->get_allowed_feature_types(), true ) ) {
				$validated['type'] = $type;
			}
		}

		if ( ! empty( $feature['license'] ) && is_scalar( $feature['license'] ) ) {
			$license = sanitize_key( (string) $feature['license'] );
			if ( in_array( $license, $this->get_allowed_feature_licenses(), true ) ) {
				$validated['license'] = $license;
			}
		}

		if ( ! empty( $feature['location'] ) && is_scalar( $feature['location'] ) ) {
			$location = sanitize_key( (string) $feature['location'] );
			if ( in_array( $location, $this->get_allowed_feature_locations(), true ) ) {
				$validated['location'] = $location;
			}
		}

		if ( ! empty( $feature['function'] ) && is_scalar( $feature['function'] ) ) {
			$function = sanitize_key( (string) $feature['function'] );
			if ( ! in_array( $function, $this->get_allowed_feature_functions(), true ) ) {
				return [];
			}
			$validated['function'] = $function;
		}

		if ( ! empty( $feature['hook'] ) && is_scalar( $feature['hook'] ) ) {
			$hook = $this->sanitize_feature_identifier( (string) $feature['hook'] );
			if ( ! in_array( $hook, $this->get_allowed_feature_hooks(), true ) ) {
				return [];
			}
			$validated['hook'] = $hook;
		}

		if ( ! empty( $feature['function'] ) && empty( $validated['function'] ) ) {
			return [];
		}

		if ( ! empty( $feature['hook'] ) && empty( $validated['hook'] ) ) {
			return [];
		}

		foreach ( [ 'link', 'demo' ] as $field ) {
			if ( ! empty( $feature[ $field ] ) && is_scalar( $feature[ $field ] ) ) {
				$url = $this->sanitize_feature_url( (string) $feature[ $field ], [ 'berocket.com' ] );
				if ( $url ) {
					$validated[ $field ] = $url;
				}
			}
		}

		if ( ! empty( $feature['image'] ) && is_scalar( $feature['image'] ) ) {
			$image = $this->sanitize_feature_url( (string) $feature['image'], [ 'apicdn.berocket.com' ] );
			if ( $image ) {
				$validated['image'] = $image;
			}
		}

		foreach ( [ 'hook_order', 'hook_vars' ] as $field ) {
			if ( isset( $feature[ $field ] ) ) {
				$value = absint( $feature[ $field ] );
				if ( $value > 0 ) {
					$validated[ $field ] = min( $value, 'hook_vars' === $field ? 10 : 10000 );
				}
			}
		}

		return $validated;
	}

	private function sanitize_feature_identifier( string $value ): string {
		return preg_replace( '/[^A-Za-z0-9_\-+]/', '', sanitize_text_field( $value ) );
	}

	private function sanitize_feature_url( string $url, array $allowed_hosts ): string {
		$url = esc_url_raw( $url );
		if ( ! $url || ! $this->is_allowed_feature_url( $url, $allowed_hosts ) ) {
			return '';
		}

		return $url;
	}

	private function is_allowed_feature_url( string $url, array $allowed_hosts ): bool {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		if ( ! $host ) {
			return false;
		}

		foreach ( $allowed_hosts as $allowed_host ) {
			$allowed_host = strtolower( $allowed_host );
			if ( $host === $allowed_host || substr( $host, -1 * ( strlen( $allowed_host ) + 1 ) ) === '.' . $allowed_host ) {
				return true;
			}
		}

		return false;
	}

	private function get_allowed_plugin_skus(): array {
		return [ 'filters', 'labels', 'loadmore', 'minmax', 'tabs', 'watermarks', 'gridlist' ];
	}

	private function get_allowed_post_names_for_plugin( string $plugin ): array {
		return $this->plugin_posts[ $plugin ] ?? [];
	}

	private function get_allowed_feature_types(): array {
		return [ 'checkbox', 'elements design box', 'tab', 'checkbox+', 'checkbox+color', 'checkbox+image', 'slider+' ];
	}

	private function get_allowed_feature_licenses(): array {
		return [ 'premium', 'business' ];
	}

	private function get_allowed_feature_locations(): array {
		return [ 'main', 'inline' ];
	}

	private function get_allowed_feature_functions(): array {
		return [
			'add_settings_tab',
			'br_filter_settings_style_template_after',
			'conditions_select_after',
			'data_add_option',
			'filter_settings_style_templates',
			'general_feature',
			'minmax_limitation_inputs_after',
			'output_locked_feature_by_hook',
			'output_locked_features_filter_by_field',
			'output_locked_features_selected_filters_template',
			'output_locked_features_widget_type_search_field',
			'premium_select_option',
			'turn_on_filter_customization',
		];
	}

	private function get_allowed_feature_hooks(): array {
		return [
			'berocket_aapf_filters_group_settings',
			'berocket_aapf_group_filters_conditions_select_after',
			'berocket_aapf_single_filter_conditions_select_after',
			'berocket_advanced_label_editor_conditions_select_after',
			'berocket_filter_option_filter_by_after',
			'berocket_minmax_custom_post_conditions_select_after',
			'berocket_minmax_limitation_inputs_after',
			'berocket_tab_manager_location_editor_conditions_select_after',
			'braapf_advanced_single_filter_additional',
			'braapf_advanced_single_filter_attribute_setup',
			'braapf_new_widget_edit_page_all_steps',
			'braapf_new_widget_edit_page_widget_types',
			'braapf_single_filter_additional',
			'br_filter_settings_style_template_after',
			'br_filter_settings_style_templates',
			'brfr_data_BeRocket_LMP',
			'brfr_data_berocket_advanced_label_editor',
			'brfr_data_berocket_minmax_custom_post',
			'brfr_data_list_grid',
			'brfr_data_tab_manager',
			'brfr_filters_settings_item_selected_filters_template_custom',
			'brfr_MM_Quantity_settings_item_cart_max_price_after',
			'brfr_tabs_info_BeRocket_LMP',
			'brfr_tabs_info_berocket_minmax_custom_post',
			'brfr_tabs_info_list_grid',
			'brfr_tabs_info_tab_manager',
		];
	}

	public function get_plugin_sku_by_page( $page = '' ): string {
		if ( ! $page and ! empty( $_GET['page'] ) )
			$page = $_GET['page'];

		if ( ! $page )
			return '';

		$plugin_sku = '';
		switch ( $page ) {
			case 'br-product-filters':
				$plugin_sku = 'filters';
				break;
			case 'br_products_label':
				$plugin_sku = 'labels';
				break;
			case 'br_load_more_products':
				$plugin_sku = 'loadmore';
				break;
			case 'br-mm-quantity':
				$plugin_sku = 'minmax';
				break;
			case 'br_tab_manager':
				$plugin_sku = 'tabs';
				break;
			case 'br-image_watermark':
				$plugin_sku = 'watermarks';
				break;
			case 'br-list_grid':
				$plugin_sku = 'gridlist';
				break;
		}

		return $plugin_sku;
	}

	public function get_plugin_name_by_page( $page = '' ): string {
		if ( ! $page and ! empty( $_GET['page'] ) )
			$page = $_GET['page'];

		if ( ! $page )
			return '';

		$plugin_name = '';
		switch ( $page ) {
			case 'br-product-filters':
				$plugin_name = 'ajax_filters';
				break;
			case 'br_products_label':
				$plugin_name = 'products_label';
				break;
			case 'br_load_more_products':
				$plugin_name = 'BeRocket_LMP';
				break;
			case 'br-mm-quantity':
				$plugin_name = 'MM_Quantity';
				break;
			case 'br_tab_manager':
				$plugin_name = 'tab_manager';
				break;
			case 'br-image_watermark':
				$plugin_name = 'image_watermark';
				break;
			case 'br-list_grid':
				$plugin_name = 'list_grid';
				break;
		}

		return $plugin_name;
	}

	public function get_plugin_name_by_sku( $sku = '' ): string {
		if ( ! $sku )
			return '';

		$plugin_name = '';
		switch ( $sku ) {
			case 'filters':
				$plugin_name = 'ajax_filters';
				break;
			case 'labels':
				$plugin_name = 'products_label';
				break;
			case 'loadmore':
				$plugin_name = 'BeRocket_LMP';
				break;
			case 'minmax':
				$plugin_name = 'MM_Quantity';
				break;
			case 'tabs':
				$plugin_name = 'tab_manager';
				break;
			case 'watermarks':
				$plugin_name = 'image_watermark';
				break;
		}

		return $plugin_name;
	}

	private function add_styles(): void {
		add_action('admin_head', function () {
			echo '<style>
			.locked_features_tooltip {min-width: 260px; max-width: 100%; padding: 0 10px}
			.locked_features_tooltip_type{line-height: 1;font-weight: 600;display: flex;width: fit-content;margin: 15px auto 15px;border-radius: 5px;padding: 6px 10px 4px;}
			.locked_features_tooltip_type.type_business{border: 2px solid #e2b76d; color: #d8ae66; padding-bottom: 6px;}
			.locked_features_tooltip_type.type_premium{background: linear-gradient(45deg,#8b61fe,#fd5293); color: white}
			.locked_features_tooltip_type .license_icon{width:24px; margin-right: 6px}
			.locked_features_tooltip .license_text{min-width:200px;font-size:18px;font-weight: 500;color: #3539b3;text-align: center;}
			.locked_features_tooltip .license_buttons{margin: 20px 0 15px; text-align: center;}
			.locked_features_tooltip .license_buttons_with_demo{margin: 15px 0 10px;box-sizing: border-box;display:flex;justify-content: space-between;}
			.locked_features_tooltip .license_buttons .demo_button{border-radius:5px;font-weight: 600;padding: 6px 10px; border: 2px solid #dfdefe; color: #7f7ce4;text-decoration: none}
			.locked_features_tooltip .license_buttons .upgrade_button{box-sizing: border-box;border-radius:5px;font-weight: 600;padding: 6px 10px; background: #dfdefe; border: 2px solid #dfdefe; color: #7f7ce4; text-decoration: none}
			.locked_feature_value{color:inherit;display:inline-block;}
			.locked_feature_value input{opacity: 1;border: 1px solid #cfd0d2; margin-right: 10px !important;}
			.locked_feature_value img{line-height: 1;height: 24px;position: relative;padding-left: 4px;top: 6px;padding-right: 4px;}
			.locked_feature_value .di_badge_premium {background: linear-gradient(to right, #9e28ff, #ff24b4);color: white;border-radius: 5px;display: inline-block;padding: 3px;position: relative;top: -2px; margin-right: 6px;}
			.locked_feature_value .di_badge_business {background: linear-gradient(to right, #f0ce93, #ecc83e);color: white;border-radius: 5px;display: inline-block;padding: 3px;position: relative;top: -2px; margin-right: 6px;}
			.braapf_style_sfa_inline section.premium-only {right: 0;bottom: -8px;position: absolute;top: 0;left: 0;z-index: 500;}
			.braapf_style_sfa_inline section.premium-only:after {content: "\f023";font-size: 24px;color: #4fd1cd;font-weight: 900;font-family: "Font Awesome 5 Free";font-style: normal;font-variant: normal;text-rendering: auto;line-height: 1;position: absolute;left: 11px;top: 8px;z-index: 200;}
			.braapf_style_sfa_inline section.premium-only a {display: block;opacity: 0;position: absolute;text-align: center;top: 0;left: 0;right: 0;bottom: 0;text-decoration: none;z-index: 199;padding-top: 40%;font-size: 18px;line-height: 1em;color: gold;transition: all 0.2s ease-out 0s;text-align: center;background-color: #2c3b48;border-radius: 10px;}
			.braapf_style_sfa_inline section.premium-only a span i {font-size: 0.6em;top: -2px;position: relative;}
			.braapf_style_sfa_inline section.premium-only:hover a {opacity: 0.9;}
			.braapf_style_sfa_inline section.premium-only:hover::after {color: gold;}
			#settings .berocket_sbs_step .braapf_widget_type_premium_1:hover:after,#settings .berocket_sbs_step .braapf_widget_type_premium_2:hover:after{content: ""; display: block;position: absolute; top:0;right:0;bottom:-14px;left:0;background-color: #2c3b48;opacity:0.9;border-radius: 5px; z-index:200;}
			#settings .berocket_sbs_step .braapf_widget_type_premium_1:hover:before,#settings .berocket_sbs_step .braapf_widget_type_premium_2:hover:before{content: "⭐ PREMIUM ⭐";color:gold;font-size: 2em;display:block;position: absolute;text-align:center;top:40%;right:0;bottom:0;left:0; z-index:201;}
			#settings .berocket_sbs_step .braapf_widget_type_business_1:hover:after,#settings .berocket_sbs_step .braapf_widget_type_business_2:hover:after{content: ""; display: block;position: absolute; top:0;right:0;bottom:-14px;left:0;background-color: #2c3b48;opacity:0.9;border-radius: 5px; z-index:200;}
			#settings .berocket_sbs_step .braapf_widget_type_business_1:hover:before,#settings .berocket_sbs_step .braapf_widget_type_business_2:hover:before{content: "⭐ BUSINESS ⭐";color:gold;font-size: 2em;display:block;position: absolute;text-align:center;top:40%;right:0;bottom:0;left:0; z-index:201;}
			#settings .berocket_sbs_step .braapf_style > div {position: relative;}
			#settings .berocket_sbs_step .braapf_style > div .premium-only {display: block;color: gold;font-size: 2em;position: absolute;text-align: center;top: 0;right: 0;bottom: 0;left: 0;z-index: 201;}
			#settings .berocket_sbs_step .braapf_style > div .premium-only a {position: absolute;opacity: 0;z-index: 200;top:0;right:0;bottom:-8px;left:0;color: gold;text-decoration: none;font-size: 18px;background-color: transparent;padding-top: 33%;font-weight: 600;text-transform: uppercase;}
			#settings .berocket_sbs_step .braapf_style > div .premium-only:hover a {opacity: 0.9;}
			#settings .berocket_sbs_step .braapf_style > div .premium-only a:before {background-color: #2c3b48;opacity:0.9;border-radius: 5px;z-index:200;content: "";display: block;position: absolute;top:0;right:0;bottom:0;left:0;}
			#settings .berocket_sbs_step .braapf_style > div .premium-only a span{position: relative;z-index:201;}
			#settings .berocket_sbs_step .braapf_style > div .premium-only + label {margin-right: 10px;}
			#settings .berocket_sbs_step .braapf_style > div .premium-only:after {content: "\f023";font-size: 24px;color: #4fd1cd;font-weight: 900;font-family: "Font Awesome 5 Free";font-style: normal;font-variant: normal;text-rendering: auto;line-height: 1;position: absolute;left: 11px;top: 8px;z-index: 200;}
			#settings .berocket_sbs_step .braapf_style > div .premium-only:hover::after {color: gold;}
			.title #ee_hide_premium + .spinner{margin-top: 15px;margin-right: 20px;}
			.berocket_sbs_advanced .style_customization_buttons {padding: 20px 0 10px;}
			@media (hover:none), (hover:on-demand) {
				/* custom css for "touch targets" */
				#settings .berocket_sbs_step .braapf_widget_type_premium_1:after,#settings .berocket_sbs_step .braapf_widget_type_premium_2:after{content: ""; display: block;position: absolute; top:0;right:0;bottom:-14px;left:0;background-color: #2c3b48;opacity:0.8;border-radius: 5px; z-index:200;}
				#settings .berocket_sbs_step .braapf_widget_type_premium_1:before,#settings .berocket_sbs_step .braapf_widget_type_premium_2:before{content: "⭐ PREMIUM ⭐";color:gold;font-size: 1.8em;display:block;position: absolute;text-align:center;top:40%;right:0;bottom:0;left:0; z-index:201;}
				#settings .berocket_sbs_step .braapf_widget_type_business_1:after,#settings .berocket_sbs_step .braapf_widget_type_business_2:after{content: ""; display: block;position: absolute; top:0;right:0;bottom:-14px;left:0;background-color: #2c3b48;opacity:0.9;border-radius: 5px; z-index:200;}
				#settings .berocket_sbs_step .braapf_widget_type_business_1:before,#settings .berocket_sbs_step .braapf_widget_type_business_2:before{content: "⭐ BUSINESS ⭐";color:gold;font-size: 2em;display:block;position: absolute;text-align:center;top:40%;right:0;bottom:0;left:0; z-index:201;}
			}
			@media (max-width: 1500px) {
				#settings .berocket_sbs_step .braapf_widget_type_premium_0:before,#settings .berocket_sbs_step .braapf_widget_type_premium_1:before{font-size: 1.4em;}
			}
			</style>';
		});
	}

	private function add_tooltip( $feature, $options = [] ): void {
		$license     = sanitize_key( $feature['license'] ?? '' );
		$feature_name = $this->sanitize_feature_identifier( $feature['name'] ?? '' );
		$lic_icon    = ( ( 'business' == $license ) ? 's-crown' : 's-diamond' );
		$lic_up      = esc_html( strtoupper( $license ) );
		$demo_class  = ( ! empty( $feature['demo'] ) ? 'license_buttons_with_demo' : '' );
		$demo_button = ( ! empty( $feature['demo'] ) ? '<a href="' . esc_url( $feature['demo'] ) . '" target="_blank" class="demo_button" rel="noopener noreferrer">DEMO</a>' : '' );
		$link        = esc_url( utm( $feature['link'] ?? '', [
			'medium'   => 'settings',
			'campaign' => 'locked_feature',
			'content'  => $feature_name,
			'term'     => $options['plugin_name'] ?? '-1',
		] ) );

		$tooltip_text = "
			<div class='locked_features_tooltip'>
				<div class='locked_features_tooltip_type type_" . esc_attr( $license ) . "'>
					<img class='license_icon' src='https://apicdn.berocket.com/" . esc_attr( $lic_icon ) . ".png'
						 alt='" . esc_attr( $license ) . "' />
					{$lic_up} FEATURE
				</div>
				<div class='license_text'>" . esc_html( $feature['tooltip_text'] ?? '' ) . "</div>
				<div class='license_buttons " . esc_attr( $demo_class ) . "'>
					{$demo_button}
					<a href='{$link}' target='_blank' class='upgrade_button' rel='noopener noreferrer'>UPGRADE</a>
				</div>
			</div>
			";
		\BeRocket_tooltip_display::add_tooltip( array(
			'appendTo'    => 'document.body',
			'arrow'       => true,
			'interactive' => true,
			'animation'   => 'shift-toward',
			'placement'   => 'bottom-start',
			'theme'       => "light",
			'allowHTML'   => true,
		), $tooltip_text, '#locked_feature_' . $feature_name );
	}

	public function output( $feature, $item, $options = [] ): string {
		switch ( $feature['type'] ) {
			case 'checkbox':
				if ( ! empty( $feature['location'] ) and $feature['location'] == 'inline' )
					return $this->output_inline_checkbox( $feature );
				else
					return $this->output_checkbox( $feature, $item );
			case 'elements design box':
				return $this->output_elements_design_box( $feature, $options );
			default:
				return $this->output_checkbox( $feature, $item );
		}
	}

	private function output_checkbox( $feature, $item ): string {
		$feature_name = esc_attr( $feature['name'] ?? '' );
		$license      = esc_attr( $feature['license'] ?? '' );

		return '
			<tr class="locked_feature_tr">
				<th scope="row">' . esc_html__( $feature['label'] ?? '', 'BeRocket_domain' ) . '</th>
			    <td' . ( $item['td_class'] ?? '' ) . '>
			        <a id="locked_feature_' . $feature_name . '" class="locked_feature_value">
			            ' . ( ! empty( $feature['hide_input'] ) ? '' : '<input type="checkbox" disabled value="" />' )
		                . ( ! empty( $feature['hide_lock'] ) ? '' : '<span class="dashicons dashicons-lock di_badge_' .
		                                                   $license . '"></span>' ) . '
			            ' . ( ! empty( $feature['badge'] ) ? '<img alt="' . $license .
		                                          ' feature" src="https://apicdn.berocket.com/' .
		                                          $license . '-label.png"/>' : '' ) . '
			            ' . esc_html( $feature['text'] ?? '' ) . '
			        </a>
				</td>
			</tr>';
	}

	private function output_inline_checkbox( $feature ): string {
		$feature_name = esc_attr( $feature['name'] ?? '' );
		$license      = esc_attr( $feature['license'] ?? '' );
		$custom_css   = ( ( $feature['name'] ?? '' ) == 'hide_value_button' ) ? 'margin-top: 10px;' : '';
		return '
		<label class="br_field_settlabel_checkbox locked_feature_tr">
			<a id="locked_feature_' . $feature_name . '" class="locked_feature_value" style="' . esc_attr( $custom_css ) . '">' .
		       ( ! empty( $feature['hide_input'] ) ? '' : '<input type="checkbox" disabled value="" />' ) .
		       ( ! empty( $feature['hide_lock'] ) ? '' : '<span class="dashicons dashicons-lock di_badge_' .
                                                   $license . '"></span>' ) .
		       ( ! empty( $feature['badge'] ) ? '<img alt="' . $license .
                                          ' feature" src="https://apicdn.berocket.com/' .
                                          $license . '-label.png"/>' : '' ) .
		       '<span class="br_label_for">' . esc_html__( $feature['label'] ?? '', 'BeRocket_domain' ) . '</span>
	        </a>
		</label>';
	}

	private function output_elements_design_box( $feature, $options = [] ): string {
		$feature_name = $this->sanitize_feature_identifier( $feature['name'] ?? '' );
		$link = esc_url( utm( $feature['link'] ?? '', [
			'medium'   => 'settings',
			'campaign' => 'locked_feature',
			'content'  => $feature_name,
			'term'     => $options['plugin_name'] ?? '',
		] ) );

		return '<div class="braapf_style_sfa_inline locked_feature_tr" style="position: relative;">
			<section class="premium-only">
				<a target="_blank" rel="noopener noreferrer" href="' . $link . '">
					<span>
						<i class="fa fa-star" aria-hidden="true"></i>
						Go Premium
						<i class="fa fa-star" aria-hidden="true"></i>
					</span>
				</a>
			</section>
			<label>
				<img alt="' . esc_attr( $feature['label'] ?? '' ) . '" src="' . esc_url( $feature['image'] ?? '' ) . '">
				<h3>' . esc_html( $feature['label'] ?? '' ) . '</h3>
			</label>
		</div>
		';
	}

	private function output_selected_filters_template( $feature, $options = [] ): array {
		$feature_name = $this->sanitize_feature_identifier( $feature['name'] ?? '' );
		$link = esc_url( utm( $feature['link'] ?? '', [
			'medium'   => 'settings',
			'campaign' => 'locked_feature',
			'content'  => $feature_name,
			'term'     => $options['plugin_name'] ?? '',
		] ) );

		return [ $feature_name, '<div class="braapf_style_sfa_inline locked_feature_tr" style="position: relative;">
			<section class="premium-only">
				<a target="_blank" rel="noopener noreferrer" href="' . $link . '">
					<span>
						<i class="fa fa-star" aria-hidden="true"></i>
						Go Premium
						<i class="fa fa-star" aria-hidden="true"></i>
					</span>
				</a>
			</section>
			<label>
				<img alt="' . esc_attr( $feature['label'] ?? '' ) . '" src="' . esc_url( $feature['image'] ?? '' ) . '">
				<h3>' . esc_html( $feature['label'] ?? '' ) . '</h3>
			</label>
		</div>' ];
	}

	public function add_settings_tab( $tabs_info ) {
		$data   = DataStore::get_current();
		$plugin = $this->get_plugin_sku_by_page();
		$plugin_name = $this->get_plugin_name_by_page();
		$plugin_version_capability = apply_filters( 'brfr_get_plugin_version_capability_' . $plugin_name, 0 );

		if ( ! empty( $data['locked_features'][ $plugin ]['main'] ) ) {
			$locked_features = $data['locked_features'][ $plugin ]['main'];

			if ( is_array( $locked_features ) ) {
				foreach ( $locked_features as $feature ) {
					if ( ! empty( $feature['function'] ) and
					     'add_settings_tab' == $feature['function'] and
					     ! empty( $feature['hook'] ) and
					     current_filter() == $feature['hook'] and
					     (
						     ( empty( $plugin_version_capability ) or $plugin_version_capability < 10 )
						     or
						     ( 'business' == $feature['license'] and
						       ( $plugin_version_capability > 10 and $plugin_version_capability < 100 )
						     )
					     )
					) {
						$label = sanitize_text_field( $feature['label'] ?? '' );
						$license = sanitize_key( $feature['license'] ?? '' );
						$new_item = [
							$label => array(
								'icon'     => sanitize_key( $feature['icon'] ?? '' ),
								'name'     => __( $label, 'BeRocket_domain' ),
								'priority' => $license . "-preview",
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
		$data   = DataStore::get_current();
		$plugin = $this->get_plugin_sku_by_page();
		$plugin_name = $this->get_plugin_name_by_page();
		$plugin_version_capability = apply_filters( 'brfr_get_plugin_version_capability_' . $plugin_name, 0 );

		if ( ! empty( $data['locked_features'][ $plugin ]['main'] ) ) {
			$locked_features = $data['locked_features'][ $plugin ]['main'];

			if ( is_array( $locked_features ) ) {
				foreach ( $locked_features as $feature ) {
					if ( ! empty( $feature['function'] ) and
					     ! empty( $feature['section'] ) and
					     'data_add_option' == $feature['function'] and
					     ! empty( $feature['hook'] ) and
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

						add_filter('brfr_' . $plugin_name . '_' . $feature['name'], function () {
							return '<th></th><td> </td>';
						});

						$settings_data += $new_item;
					}
				}
			}
		}

		return $settings_data;
	}

	public function output_locked_feature_by_hook( $page_content, $item, $tab_name, $tab_content ): string {
		$data   = DataStore::get_current();
		$plugin = $this->get_plugin_sku_by_page();
		$plugin_name = $this->get_plugin_name_by_page();
		$plugin_version_capability = apply_filters( 'brfr_get_plugin_version_capability_' . $plugin_name, 0 );

		if ( ! empty( $data['locked_features'][ $plugin ]['main'] ) ) {
			$locked_features = $data['locked_features'][ $plugin ]['main'];

			if ( is_array( $locked_features ) ) {
				foreach ( $locked_features as $feature ) {
					if ( ! empty( $feature['hook'] ) and $feature['hook'] == current_filter() and
					     (
						     ( empty( $plugin_version_capability ) or $plugin_version_capability < 10 )
						     or
						     ( 'business' == $feature['license'] and
						       ( $plugin_version_capability > 10 and $plugin_version_capability < 100 )
						     )
					     )
					) {
						$this->add_tooltip( $feature, [ 'plugin_name' => $plugin ] );
						$page_content .= $this->output( $feature, $item );
					}
				}
			}
		}

		return $page_content;
	}

	private function init_hide_locked_features( $plugin, $element ) {
		$cap = apply_filters( 'brfr_get_plugin_version_capability_'. $this->get_plugin_name_by_sku( $plugin ), 0 );
		if ( ! empty( $cap ) and $cap > 5 )
			return;

		if ( $this->is_settings() ) {
			add_action('brfr_settings_tab_title', function ( $title ) use ($plugin, $element) {
				return $title .
					'<div id="brfr_ee_hide_locked_features" style="position: absolute;top: 0;right: 0;font-size: 15px;color: rgb(89, 89, 89);">
						' . __( 'Hide premium feature previews for 2 weeks', 'BeRocket_domain' ) . '
						<input id="ee_hide_premium" class="button tiny-button" type="button" value="' .
				       __( 'Hide', 'BeRocket_domain' ) . '" style="margin: 10px 12px 10px 4px;">
					</div>';
			});
			add_filter('admin_body_class', function ( $classes ) {
				$classes .= ' berocket_settings_page';
				return $classes;
			});
		} else {
			add_action( 'add_meta_boxes', [ $this, 'add_sidebar_metabox' ] );
		}

		add_action( 'admin_enqueue_scripts', function () use ($plugin, $element) {

			wp_enqueue_script(
				'premium-admin',
				plugin_dir_url(__FILE__) . 'assets/admin.js',
				['jquery'],
				'1.0',
				true
			);

			wp_localize_script( 'premium-admin', 'PremiumAjax', [
				'nonce'   => wp_create_nonce( 'hide_premium_features_nonce' ),
				'element' => $element,
				'plugin'  => $plugin,
			]);
		});
	}

	public function add_sidebar_metabox() {
		$data = DataStore::get_current();
		if ( ! empty( $data['locked_features'] ) ) {
			foreach ( $this->post_name_to_plugin as $post_name => $plugin ) {
				if ( $this->is_post_page( $post_name ) ) {
					add_meta_box(
						'brfr_ee_hide_locked_features',
						__( 'Premium Features', 'BeRocket_domain' ),
						[ $this, 'display_locked_features' ],
						$post_name,
						'side',
						'core'
					);
					break;
				}
			}
		}
	}

	public function display_locked_features( $post ) {
		echo '<p>' . __( 'Don’t want to see premium feature previews right now? Hide them for 2 weeks.', 'BeRocket_domain' ) . '</p>';
		echo '<input id="ee_hide_premium" class="button" type="button" value="' . __( 'Hide Previews', 'BeRocket_domain' ) . '">';
	}

	public function hide_premium_features() {
		$element = $_POST['element'] ? sanitize_text_field( $_POST['element'] ) : '';
		$plugin  = $_POST['plugin'] ? sanitize_text_field( $_POST['plugin'] ) : '';
		// 1. Check nonce
		check_ajax_referer( 'hide_premium_features_nonce', 'nonce' );

		// 2. Validate
		if ( ! current_user_can( 'manage_options' ) or ! $element or ! $plugin ) {
			wp_send_json_error([
				'message' => __( 'Insufficient permissions', 'BeRocket_domain' )
			], 403);
		}

		// 3. Do the logic
		$hidden  = get_option( BR_EE_HIDDEN );

		$hidden[ $plugin ][ $element ]['hide_till'] = strtotime('+14 days');
		update_option( BR_EE_HIDDEN, $hidden );

		wp_send_json_success([
			'message' => __( 'Updated successfully', 'BeRocket_domain' )
		]);
	}
}
