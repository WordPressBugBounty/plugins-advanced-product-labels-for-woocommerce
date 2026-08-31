<?php
namespace BeRocket\EngagementEngine;

include_once __DIR__ . "/data_store.php";

use function BeRocket\utm;

class PostBase {
	private bool $is_paid;
	private bool $is_premium;
	public string $post_name;
	public string $plugin_sku;
	public string $plugin_name;
	public object $o_locked_features;

	public function __construct( $o_locked_features ) {
		$this->is_paid = $this->is_paid();
		$this->is_premium = $this->is_premium();
		$this->o_locked_features = $o_locked_features;

		$locked_features = $this->get_locked_features_for_post();
		if ( ! empty( $locked_features ) ) {
			$added_hooks = [];
			foreach ( $locked_features as $locked_feature ) {
				if ( ! empty( $locked_feature['hook'] ) and
					 $this->is_locked( $locked_feature ) and
				     ( empty( $added_hooks[ $locked_feature['hook'] ] ) or
				       ! is_array( $added_hooks[ $locked_feature['hook'] ] ) or
				       ! in_array( $locked_feature['function'], $added_hooks[ $locked_feature['hook'] ] )
				     )
				) {
					$hook_order = $locked_feature['hook_order'] ?? 10;
					$hook_vars  = $locked_feature['hook_vars'] ?? 1;

					add_filter(
						$locked_feature['hook'],
						[
							method_exists( $this, $locked_feature['function'] ) ? $this : $this->o_locked_features,
							$locked_feature['function']
						],
						$hook_order,
						$hook_vars
					);

					$added_hooks[ $locked_feature['hook'] ][] = $locked_feature['function'];
				}
			}
		}
	}

	public function general_feature() {
		$locked_features = $this->get_locked_features_for_post();
		if ( ! empty( $locked_features ) ) {
			foreach ( $locked_features as $feature ) {
				if ( ! empty( $feature['function'] ) and ! empty( $feature['hook'] ) and
				     $feature['function'] == 'general_feature' and
				     current_filter() == $feature['hook'] and
				     $this->is_locked( $feature )
				) {
					$feature_name = esc_attr( $feature['name'] ?? '' );
					$license      = esc_attr( $feature['license'] ?? '' );
					$this->add_tooltip( $feature );
					echo '
					<div class="berocket_group_' . $feature_name . ' braapf_attribute_setup_flex locked_feature_tr">
						<div class="braapf_full_select_full">
							<a id="locked_feature_' . $feature_name . '" class="locked_feature_value" aria-expanded="false">
					            <input type="checkbox" disabled="disabled" value="" />
					            <span class="dashicons dashicons-lock di_badge_' . $license . '"></span>
					            ' . esc_html( $feature['label'] ?? '' ) . '
					        </a>
						</div>
					</div>';
				}
			}
		}
	}

	public function conditions_select_after() {
		$locked_features = $this->get_locked_features_for_post();
		if ( ! empty( $locked_features ) ) {
			foreach ( $locked_features as $feature ) {
				if ( ! empty( $feature['function'] ) and ! empty( $feature['hook'] ) and
				     $feature['function'] == 'conditions_select_after' and
				     current_filter() == $feature['hook'] and
				     $this->is_locked( $feature )
				) {
					echo '<option disabled="disabled">' . esc_html( $feature['label'] ?? '' ) . '</option>';
				}
			}
		}
	}

	public function output_locked_features( $page_content, $item, $tab_name, $tab_content ): string {
		$data   = DataStore::get_current();
		$plugin = $this->plugin_sku;
		$plugin_name = $this->plugin_name;
		if ( empty( $item['name'] ) ) {
			$item_name = $item['section'] ?? '';
		} else {
			$item_name = ( is_array( $item['name'] ) ? implode( '_', $item['name'] ) : $item['name'] );
			$item_name = $item_name ?? ( $item['section'] ?? '' );
		}
		$hook = ( substr( current_filter(), -7 ) === '_before') ? 'before' : 'after';

		$locked_features = $this->get_locked_features_for_post();
		if ( ! empty( $locked_features ) ) {

			if ( is_array( $locked_features ) ) {
				foreach ( $locked_features as $feature ) {
					if ( ! empty( $feature['section'] ) and
					     ! empty( $feature['location'] ) and
					     ! empty( $feature[ $hook ] ) and
						 $tab_name == $feature['section'] and
					     'main' == $feature['location'] and
					     $item_name == $feature[ $hook ] and
					     $this->is_locked( $feature )
					) {
						$this->add_tooltip( $feature, [ 'plugin_name' => $plugin ] );
						$page_content .= $this->o_locked_features->output( $feature, $item );
					}
				}
			}
		}

		return $page_content;
	}

	public function get_locked_features_for_post(): array {
		$data = DataStore::get_current();

		return $data['locked_features'][ $this->plugin_sku ]['posts'][ $this->post_name ] ?? [];
	}

	/* TOOLS */

	protected function is_paid() {
		$cap = apply_filters( 'brfr_get_plugin_version_capability_'. $this->plugin_name, 0 );
		if ( ! empty( $cap ) and $cap >= 10 )
			return true;

		return false;
	}

	protected function is_premium() {
		$cap = apply_filters( 'brfr_get_plugin_version_capability_'. $this->plugin_name, 0 );
		if ( ! empty( $cap ) and $cap >= 10 and $cap < 100 )
			return true;

		return false;
	}

	protected function is_locked( $locked_feature ) {
		return ( ! $this->is_paid or $this->is_premium and $locked_feature['license'] == 'business' );
	}

	protected function add_tooltip( $feature, $utm = [] ): void {
		$license     = sanitize_key( $feature['license'] ?? '' );
		$feature_name = preg_replace( '/[^A-Za-z0-9_\-+]/', '', sanitize_text_field( $feature['name'] ?? '' ) );
		$lic_icon    = ( ( 'business' == $license ) ? 's-crown' : 's-diamond' );
		$lic_up      = esc_html( strtoupper( $license ) );
		$demo_class  = ( ! empty( $feature['demo'] ) ? 'license_buttons_with_demo' : '' );
		$demo_button = ( ! empty( $feature['demo'] ) ? '<a href="' . esc_url( $feature['demo'] ) . '" target="_blank" rel="noopener noreferrer" class="demo_button">DEMO</a>' : '' );

		$link = esc_url( utm( $feature['link'] ?? '', array_replace([
			'source'   => 'plugin',
			'medium'   => 'custom_post',
			'campaign' => 'locked_feature',
			'content'  => $utm['content'] ?? $feature_name,
			'term'     => $this->plugin_sku,
		], $utm ) ) );

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
					<a href='{$link}' target='_blank' rel='noopener noreferrer' class='upgrade_button'>UPGRADE</a>
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
}
