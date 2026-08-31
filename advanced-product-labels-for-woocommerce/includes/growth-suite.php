<?php
/**
 * Deterministic, idempotent Growth Suite foundation bootstrap.
 */

if ( ! function_exists( 'berocket_growth_suite' ) ) {
	function berocket_growth_suite(): BeRocket_Growth_Suite_Entitlement_Service {
		static $service = null;

		if ( null !== $service ) {
			return $service;
		}

		require_once __DIR__ . '/growth-suite/domain/class-label-schema.php';
		require_once __DIR__ . '/growth-suite/domain/class-rule-model.php';
		require_once __DIR__ . '/growth-suite/domain/class-rule-normalizer.php';
		require_once __DIR__ . '/growth-suite/domain/class-rule-evaluator.php';
		require_once __DIR__ . '/growth-suite/domain/class-rule-diagnostics.php';
		require_once __DIR__ . '/growth-suite/compatibility/class-legacy-label-adapter.php';
		require_once __DIR__ . '/growth-suite/compatibility/class-legacy-rule-adapter.php';
		require_once __DIR__ . '/growth-suite/application/interface-entitlement-provider.php';
		require_once __DIR__ . '/growth-suite/application/class-entitlement-service.php';
		require_once __DIR__ . '/growth-suite/application/class-conditions-matching-skus-service.php';
		require_once __DIR__ . '/growth-suite/application/class-simulation-service.php';
		require_once __DIR__ . '/growth-suite/application/class-rule-publish-service.php';
		require_once __DIR__ . '/growth-suite/infrastructure/jobs/class-background-job.php';
		require_once __DIR__ . '/growth-suite/infrastructure/jobs/interface-catalog-source.php';
		require_once __DIR__ . '/growth-suite/infrastructure/jobs/class-simulation-job.php';
		require_once __DIR__ . '/growth-suite/infrastructure/rest/interface-simulation-job-gateway.php';
		require_once __DIR__ . '/growth-suite/infrastructure/rest/class-simulation-controller.php';
		require_once __DIR__ . '/growth-suite/infrastructure/ajax/class-conditions-matching-skus-controller.php';

		$service = new BeRocket_Growth_Suite_Entitlement_Service();

		if ( function_exists( 'do_action' ) ) {
			do_action( 'berocket_growth_suite_loaded', $service );
		}

		return $service;
	}
}

if ( ! function_exists( 'berocket_growth_suite_conditions_matching_skus_controller' ) ) {
	function berocket_growth_suite_conditions_matching_skus_controller(): BeRocket_Growth_Suite_Conditions_Matching_Skus_Controller {
		static $controller = null;

		berocket_growth_suite();
		if ( null === $controller ) {
			$controller = new BeRocket_Growth_Suite_Conditions_Matching_Skus_Controller();
		}

		return $controller;
	}
}

if ( ! function_exists( 'berocket_growth_suite_register_conditions_matching_skus_ajax' ) ) {
	function berocket_growth_suite_register_conditions_matching_skus_ajax(): void {
		berocket_growth_suite_conditions_matching_skus_controller()->register_hooks();
	}
}

if ( ! function_exists( 'berocket_growth_suite_enqueue_conditions_matching_skus_assets' ) ) {
	/** Enqueue the Free Conditions-metabox enhancer on the existing editor hook. */
	function berocket_growth_suite_enqueue_conditions_matching_skus_assets(): void {
		if ( ! function_exists( 'wp_enqueue_script' ) ) {
			return;
		}
		$plugin_root  = dirname( __DIR__ );
		$asset_file   = $plugin_root . '/assets/build/index.asset.php';
		$asset        = is_file( $asset_file ) ? require $asset_file : array();
		$plugin_file  = defined( 'BeRocket_products_label_file' )
			? constant( 'BeRocket_products_label_file' )
			: $plugin_root . '/woocommerce-advanced-products-labels.php';
		$plugin_file  = is_string( $plugin_file ) ? $plugin_file : $plugin_root . '/woocommerce-advanced-products-labels.php';
		$dependencies = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] )
			? $asset['dependencies']
			: array( 'wp-element', 'wp-i18n' );
		$version      = isset( $asset['version'] ) && is_string( $asset['version'] )
			? $asset['version']
			: ( defined( 'BeRocket_products_label_version' ) ? BeRocket_products_label_version : '1' );

		wp_enqueue_script(
			'brapl-growth-suite-conditions',
			plugins_url( 'assets/build/index.js', $plugin_file ),
			$dependencies,
			$version,
			true
		);
		foreach ( array( 'style-index.css', 'index.css' ) as $stylesheet ) {
			if ( ! is_file( $plugin_root . '/assets/build/' . $stylesheet ) ) {
				continue;
			}
			wp_enqueue_style(
				'brapl-growth-suite-conditions',
				plugins_url( 'assets/build/' . $stylesheet, $plugin_file ),
				array(),
				$version
			);
			break;
		}
	}
}

if ( ! function_exists( 'berocket_growth_suite_render_conditions_matching_skus_control' ) ) {
	/** Render the read-only synchronous checker next to the legacy Conditions UI. */
	function berocket_growth_suite_render_conditions_matching_skus_control( int $label_id ): void {
		if ( $label_id < 1 || ! function_exists( 'wp_create_nonce' ) || ! function_exists( 'admin_url' ) ) {
			return;
		}
		berocket_growth_suite_enqueue_conditions_matching_skus_assets();
		$config      = array(
			'action'   => BeRocket_Growth_Suite_Conditions_Matching_Skus_Controller::ACTION,
			'endpoint' => admin_url( 'admin-ajax.php' ),
			'labelId'  => $label_id,
			'nonce'    => wp_create_nonce( BeRocket_Growth_Suite_Conditions_Matching_Skus_Controller::NONCE_ACTION ),
			'strings'  => array(
				'show'            => __( 'Check matching products', 'BeRocket_products_label_domain' ),
				'next'            => __( 'Check next products', 'BeRocket_products_label_domain' ),
				'loading'         => __( 'Checking matching products…', 'BeRocket_products_label_domain' ),
				'failed'          => __( 'Matching product check failed.', 'BeRocket_products_label_domain' ),
				/* translators: %d: checked product candidates count. */
				'checked'         => __( 'Checked %1$d candidates.', 'BeRocket_products_label_domain' ),
				/* translators: %s: comma-separated product names. */
				'found'           => __( 'Found %1$s.', 'BeRocket_products_label_domain' ),
				/* translators: %s: first five product names. */
				'found_one_more'  => __( 'Found %1$s… and 1 other.', 'BeRocket_products_label_domain' ),
				/* translators: 1: first five product names, 2: omitted product count. */
				'found_more'      => __( 'Found %1$s… and %2$d others.', 'BeRocket_products_label_domain' ),
				'partial'         => __( 'Check the next products to continue.', 'BeRocket_products_label_domain' ),
				'no_matches'      => __( 'Found no matching products.', 'BeRocket_products_label_domain' ),
				'unnamed_product' => __( 'Unnamed product', 'BeRocket_products_label_domain' ),
			),
		);
		$config_json = wp_json_encode( $config );
		if ( ! is_string( $config_json ) ) {
			return;
		}

		echo '<section class="brapl-matching-products" data-brapl-matching-skus data-config="' . esc_attr( $config_json ) . '">';
		echo '<button type="button" class="button" data-brapl-matching-skus-show>' . esc_html( $config['strings']['show'] ) . '</button>';
		echo '<div class="brapl-matching-products__result" data-brapl-matching-skus-results role="status" aria-live="polite" data-checked-candidates="0" data-partial="false" hidden></div>';
		echo '</section>';
	}
}

if ( ! function_exists( 'berocket_growth_suite_rule_publish_service' ) ) {
	function berocket_growth_suite_rule_publish_service(): BeRocket_Growth_Suite_Rule_Publish_Service {
		static $service = null;

		berocket_growth_suite();
		if ( null === $service ) {
			$model      = new BeRocket_Growth_Suite_Rule_Model();
			$normalizer = new BeRocket_Growth_Suite_Rule_Normalizer();
			$service    = new BeRocket_Growth_Suite_Rule_Publish_Service(
				new BeRocket_Growth_Suite_Legacy_Rule_Adapter( $normalizer, $model )
			);
		}

		return $service;
	}
}

if ( ! function_exists( 'berocket_growth_suite_prepare_rule_save' ) ) {
	/**
	 * Prepare the complete label payload for the existing `save_post` transport.
	 *
	 * @param mixed $post_id           Label post ID.
	 * @param mixed $submitted_payload Existing filtered form payload.
	 * @return array<string,mixed>
	 */
	function berocket_growth_suite_prepare_rule_save( $post_id, $submitted_payload ): array {
		$post_id      = (int) $post_id;
		$saved        = get_post_meta( $post_id, 'br_labels', true );
		$saved_exists = function_exists( 'metadata_exists' )
			? metadata_exists( 'post', $post_id, 'br_labels' )
			: '' !== $saved;

		return berocket_growth_suite_rule_publish_service()->prepare(
			$saved,
			$submitted_payload,
			$post_id,
			$saved_exists
		);
	}
}

if ( ! function_exists( 'berocket_growth_suite_preserve_validated_rule_scalar' ) ) {
	function berocket_growth_suite_mark_validated_rule_payload(): void {
		$GLOBALS['berocket_growth_suite_validated_rule_payload'] = true;
	}

	function berocket_growth_suite_clear_validated_rule_payload(): void {
		unset( $GLOBALS['berocket_growth_suite_validated_rule_payload'] );
	}

	/**
	 * Keep canonical scalar types after the rule publish service has validated
	 * and injected one complete rule_model + compatibility data payload.
	 *
	 * The legacy framework sanitizer remains authoritative for every other form
	 * field and for every legacy save where no decoded rule_model array exists.
	 *
	 * @param mixed $filtered          Value returned by an earlier sanitizer filter.
	 * @param mixed $value             Current scalar value.
	 * @param mixed $option_name       Recursive framework field path.
	 * @param mixed $previous_settings Existing saved settings (unused).
	 * @return mixed
	 */
	function berocket_growth_suite_preserve_validated_rule_scalar( $filtered, $value, $option_name, $previous_settings ) {
		unset( $previous_settings );
		if ( null !== $filtered ) {
			return $filtered;
		}

		$validated_payload = ! empty( $GLOBALS['berocket_growth_suite_validated_rule_payload'] );
		$validated_path    = is_array( $option_name )
			&& isset( $option_name[0], $option_name[1] )
			&& 'br_labels' === $option_name[0]
			&& in_array( $option_name[1], array( 'rule_model', 'data' ), true );

		return $validated_payload && $validated_path ? $value : null;
	}
}

if ( ! function_exists( 'berocket_growth_suite_register_paid_provider' ) ) {
	function berocket_growth_suite_register_paid_provider(): bool {
		$provider_file = dirname( __DIR__ ) . '/paid/growth-suite/class-paid-entitlement-provider.php';
		if ( ! is_file( $provider_file ) ) {
			return false;
		}

		require_once $provider_file;
		if ( ! class_exists( 'BeRocket_Growth_Suite_Paid_Entitlement_Provider', false ) ) {
			return false;
		}

		return berocket_growth_suite()->register_provider( new BeRocket_Growth_Suite_Paid_Entitlement_Provider() );
	}
}

if ( ! function_exists( 'berocket_growth_suite_simulation_controller' ) ) {
	function berocket_growth_suite_simulation_controller(): BeRocket_Growth_Suite_Simulation_Controller {
		static $controller = null;

		berocket_growth_suite();
		if ( null === $controller ) {
			$controller = new BeRocket_Growth_Suite_Simulation_Controller();
		}

		return $controller;
	}
}

if ( ! function_exists( 'berocket_growth_suite_register_simulation_routes' ) ) {
	function berocket_growth_suite_register_simulation_routes(): void {
		berocket_growth_suite_simulation_controller()->register_routes();
	}
}

berocket_growth_suite();

if ( function_exists( 'add_action' ) ) {
	add_action( 'rest_api_init', 'berocket_growth_suite_register_simulation_routes' );
	add_action( 'init', 'berocket_growth_suite_register_conditions_matching_skus_ajax' );
	add_action( 'berocket_apl_load_admin_edit_scripts', 'berocket_growth_suite_enqueue_conditions_matching_skus_assets' );
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'berocket_sanitize_array_predefine', 'berocket_growth_suite_preserve_validated_rule_scalar', 10, 4 );
}
