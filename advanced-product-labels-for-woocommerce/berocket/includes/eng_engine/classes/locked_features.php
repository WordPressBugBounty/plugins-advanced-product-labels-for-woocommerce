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
		$data   = get_option( BR_EE_OPTION );

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

					if ( ! empty( $feature['hook'] ) and
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
		$data = get_option( BR_EE_OPTION );
		if ( ! empty( $data['locked_features'] ) ) {
			foreach ( $this->plugin_posts as $plugin_name => $plugin_posts ) {
				if ( $plugin == $plugin_name ) {
					foreach ( $plugin_posts as $post_page => $plugin_post ) {
						if ( $post_name == $post_page ) {
							$class_name = "BeRocket\\EngagementEngine\\{$plugin_post}";
							new $class_name( $this );
							$this->init_hide_locked_features( $plugin, $plugin_post );
							break;
						}
					}
					break;
				}
			}
		}
	}

	public function output_locked_features( $page_content, $item, $tab_name, $tab_content ): string {
		$data   = get_option( BR_EE_OPTION );
		$plugin = $this->get_plugin_sku_by_page();
		$plugin_name = $this->get_plugin_name_by_page();
		$item_name = ( is_array( $item['name'] ) ? implode( '_', $item['name'] ) : $item['name'] );
		$item_name = $item_name ?? $item['section'];
		$plugin_version_capability = apply_filters( 'brfr_get_plugin_version_capability_' . $plugin_name, 0 );

		if ( ! empty( $data['locked_features'][ $plugin ]['main'] ) ) {
			$locked_features = $data['locked_features'][ $plugin ]['main'];

			if ( is_array( $locked_features ) ) {
				foreach ( $locked_features as $feature ) {
					if ( $tab_name == $feature['section'] and
					     'main' == $feature['location'] and
					     in_array( $item_name, [ $feature['before'], $feature['after'] ] ) and
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
		$data   = get_option( BR_EE_OPTION );
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
					if ( $tab_name == $feature['section'] and
					     'inline' == $feature['location'] and
					     in_array( $item_name, [ $feature['before'], $feature['after'] ] ) and
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
		$data   = get_option( BR_EE_OPTION );
		$plugin = $this->get_plugin_sku_by_page();
		$plugin_name = $this->get_plugin_name_by_page();
		$plugin_version_capability = apply_filters( 'brfr_get_plugin_version_capability_' . $plugin_name, 0 );

		if ( ! empty( $data['locked_features'][ $plugin ]['main'] ) ) {
			$locked_features = $data['locked_features'][ $plugin ]['main'];

			if ( is_array( $locked_features ) ) {
				foreach ( $locked_features as $feature ) {
					if ( in_array( 'selected_filters_template_custom', [ $feature['before'], $feature['after'] ] ) and
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
		// do something, need to know what data looks like

		return $locked_features;
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
		$lic_icon    = ( ( 'business' == $feature['license'] ) ? 's-crown' : 's-diamond' );
		$lic_up      = strtoupper( $feature['license'] );
		$demo_class  = ( $feature['demo'] ? 'license_buttons_with_demo' : '' );
		$demo_button = ( $feature['demo'] ? '<a href="' . $feature['demo'] . '" target="_blank" class="demo_button">DEMO</a>' : '' );

		$tooltip_text = "
			<div class='locked_features_tooltip'>
				<div class='locked_features_tooltip_type type_{$feature['license']}'>
					<img class='license_icon' src='https://apicdn.berocket.com/{$lic_icon}.png' 
						 alt='{$feature['license']}' /> 
					{$lic_up} FEATURE
				</div>
				<div class='license_text'>{$feature['tooltip_text']}</div>
				<div class='license_buttons {$demo_class}'>
					{$demo_button}
					<a href='" . utm( $feature['link'], [
									'medium'   => 'settings',
									'campaign' => 'locked_feature',
									'content'  => $feature['name'],
									'term'     => $options['plugin_name'],
								] ) . "' target='_blank' class='upgrade_button'>UPGRADE</a>
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
		), $tooltip_text, '#locked_feature_' . $feature['name'] );
	}

	public function output( $feature, $item, $options = [] ): string {
		switch ( $feature['type'] ) {
			case 'checkbox':
				if ( $feature['location'] == 'inline' )
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
		return '
			<tr class="locked_feature_tr">
				<th scope="row">' . __( $feature['label'], 'BeRocket_domain' ) . '</th>
			    <td' . ( $item['td_class'] ?? '' ) . '>
			        <a id="locked_feature_' . $feature['name'] . '" class="locked_feature_value">
			            ' . ( $feature['hide_input'] ? '' : '<input type="checkbox" disabled value="" />' )
		                . ( $feature['hide_lock'] ? '' : '<span class="dashicons dashicons-lock di_badge_' .
		                                                   $feature['license'] . '"></span>' ) . '
			            ' . ( $feature['badge'] ? '<img alt="' . $feature['license'] .
		                                          ' feature" src="https://apicdn.berocket.com/' .
		                                          $feature['license'] . '-label.png"/>' : '' ) . '
			            ' . ( $feature['text'] ?? '' ) . '
			        </a>
				</td>
			</tr>';
	}

	private function output_inline_checkbox( $feature ): string {
		$custom_css = ( $feature['name'] == 'hide_value_button' ) ? 'margin-top: 10px;' : '';
		return '
		<label class="br_field_settlabel_checkbox locked_feature_tr">
			<a id="locked_feature_' . $feature['name'] . '" class="locked_feature_value" style="' . $custom_css . '">' .
		       ( $feature['hide_input'] ? '' : '<input type="checkbox" disabled value="" />' ) .
		       ( $feature['hide_lock'] ? '' : '<span class="dashicons dashicons-lock di_badge_' .
                                                   $feature['license'] . '"></span>' ) .
		       ( $feature['badge'] ? '<img alt="' . $feature['license'] .
                                          ' feature" src="https://apicdn.berocket.com/' .
                                          $feature['license'] . '-label.png"/>' : '' ) .
		       '<span class="br_label_for">' . __( $feature['label'], 'BeRocket_domain' ) . '</span>
	        </a>
		</label>';
	}

	private function output_elements_design_box( $feature, $options = [] ): string {
		return '<div class="braapf_style_sfa_inline locked_feature_tr" style="position: relative;">
			<section class="premium-only">
				<a target="_blank" href="' . utm( $feature['link'], [
												'medium'   => 'settings',
												'campaign' => 'locked_feature',
												'content'  => $feature['name'],
												'term'     => $options['plugin_name'] ?? '',
											] ) . '">
					<span>
						<i class="fa fa-star" aria-hidden="true"></i>
						Go Premium
						<i class="fa fa-star" aria-hidden="true"></i>
					</span>
				</a>
			</section>
	        <label>
	        	<img alt="' . $feature['label'] . '" src="' . $feature['image'] . '">
	        	<h3>' . $feature['label'] . '</h3>
	        </label>
		</div>
		';
	}

	private function output_selected_filters_template( $feature, $options = [] ): array {
		return [ $feature['name'], '<div class="braapf_style_sfa_inline locked_feature_tr" style="position: relative;">
			<section class="premium-only">
				<a target="_blank" href="' . utm( $feature['link'], [
												'medium'   => 'settings',
												'campaign' => 'locked_feature',
												'content'  => $feature['name'],
												'term'     => $options['plugin_name'] ?? '',
											] ) . '">
					<span>
						<i class="fa fa-star" aria-hidden="true"></i>
						Go Premium
						<i class="fa fa-star" aria-hidden="true"></i>
					</span>
				</a>
			</section>
	        <label>
	        	<img alt="' . $feature['label'] . '" src="' . $feature['image'] . '">
	        	<h3>' . $feature['label'] . '</h3>
	        </label>
		</div>' ];
	}

	public function add_settings_tab( $tabs_info ) {
		$data   = get_option( BR_EE_OPTION );
		$plugin = $this->get_plugin_sku_by_page();
		$plugin_name = $this->get_plugin_name_by_page();
		$plugin_version_capability = apply_filters( 'brfr_get_plugin_version_capability_' . $plugin_name, 0 );

		if ( ! empty( $data['locked_features'][ $plugin ]['main'] ) ) {
			$locked_features = $data['locked_features'][ $plugin ]['main'];

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

						if ( $feature['after_tab'] and
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
		$plugin = $this->get_plugin_sku_by_page();
		$plugin_name = $this->get_plugin_name_by_page();
		$plugin_version_capability = apply_filters( 'brfr_get_plugin_version_capability_' . $plugin_name, 0 );

		if ( ! empty( $data['locked_features'][ $plugin ]['main'] ) ) {
			$locked_features = $data['locked_features'][ $plugin ]['main'];

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
		$data   = get_option( BR_EE_OPTION );
		$plugin = $this->get_plugin_sku_by_page();
		$plugin_name = $this->get_plugin_name_by_page();
		$plugin_version_capability = apply_filters( 'brfr_get_plugin_version_capability_' . $plugin_name, 0 );

		if ( ! empty( $data['locked_features'][ $plugin ]['main'] ) ) {
			$locked_features = $data['locked_features'][ $plugin ]['main'];

			if ( is_array( $locked_features ) ) {
				foreach ( $locked_features as $feature ) {
					if ( $feature['hook'] == current_filter() and
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
		$data = get_option( BR_EE_OPTION );
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
