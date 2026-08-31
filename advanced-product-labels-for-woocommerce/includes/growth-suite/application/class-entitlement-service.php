<?php
/**
 * Central Free/Paid feature boundary.
 */

if ( ! class_exists( 'BeRocket_Growth_Suite_Entitlement_Service' ) ) {
	class BeRocket_Growth_Suite_Entitlement_Service {
		const FREE = 'free';
		const PAID = 'paid';

		/** @var array<string,string> */
		private $features;

		/** @var array<string,BeRocket_Growth_Suite_Entitlement_Provider_Interface> */
		private $providers = array();

		/**
		 * @param array<string,string> $features Explicit feature map override.
		 */
		public function __construct( array $features = array() ) {
			$this->features = $features ? $features : self::default_features();
		}

		/** @return array<string,string> */
		public static function default_features(): array {
			return array(
				'canonical_rules'      => self::FREE,
				'live_preview'         => self::FREE,
				'integration_pack'     => self::FREE,
				'import_export'        => self::FREE,
				'schedule'             => self::PAID,
				'user_role'            => self::PAID,
				'responsive_controls'  => self::PAID,
				'template_library'     => self::PAID,
				'analytics'            => self::PAID,
				'experiments'          => self::PAID,
				'performance_evidence' => self::PAID,
			);
		}

		public function register_provider( BeRocket_Growth_Suite_Entitlement_Provider_Interface $provider ): bool {
			$provider_id = (string) $provider->get_provider_id();
			if ( '' === $provider_id || isset( $this->providers[ $provider_id ] ) ) {
				return false;
			}

			$this->providers[ $provider_id ] = $provider;
			return true;
		}

		public function get_required_entitlement( string $feature ): ?string {
			return isset( $this->features[ $feature ] ) ? $this->features[ $feature ] : null;
		}

		/** @return array<string,mixed> */
		public function get_state( string $feature ): array {
			$required = $this->get_required_entitlement( $feature );
			if ( null === $required ) {
				return array(
					'feature'     => $feature,
					'entitlement' => null,
					'available'   => false,
					'reason_code' => 'unknown_feature',
				);
			}

			if ( self::FREE === $required ) {
				return array(
					'feature'     => $feature,
					'entitlement' => self::FREE,
					'available'   => true,
					'reason_code' => 'free_feature',
				);
			}

			foreach ( $this->providers as $provider ) {
				try {
					if ( $provider->is_entitled( $feature, $required ) ) {
						return array(
							'feature'     => $feature,
							'entitlement' => self::PAID,
							'available'   => true,
							'reason_code' => 'paid_entitled',
							'provider'    => $provider->get_provider_id(),
						);
					}
				} catch ( Throwable $error ) {
					// Provider failure is intentionally fail-closed.
					continue;
				}
			}

			return array(
				'feature'     => $feature,
				'entitlement' => self::PAID,
				'available'   => false,
				'reason_code' => 'paid_runtime_unavailable',
			);
		}

		public function is_available( string $feature ): bool {
			$state = $this->get_state( $feature );
			return true === $state['available'];
		}
	}
}
