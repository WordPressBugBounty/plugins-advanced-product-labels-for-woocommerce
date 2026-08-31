<?php
/**
 * Contract implemented only by entitlement providers that are actually
 * present in the running edition.
 */

if ( ! interface_exists( 'BeRocket_Growth_Suite_Entitlement_Provider_Interface' ) ) {
	interface BeRocket_Growth_Suite_Entitlement_Provider_Interface {
		public function is_entitled( string $feature, string $required_entitlement ): bool;

		public function get_provider_id(): string;
	}
}
