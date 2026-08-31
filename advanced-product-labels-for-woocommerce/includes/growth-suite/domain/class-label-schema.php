<?php
/**
 * Versioned label payload inspection and validation.
 *
 * This class never persists data. Callers must make an explicit authorized
 * write after checking the returned result.
 */

if ( ! class_exists( 'BeRocket_Growth_Suite_Label_Schema' ) ) {
	class BeRocket_Growth_Suite_Label_Schema {
		const CURRENT_VERSION = 2;

		/**
		 * Inspect saved data without materializing defaults or changing fields.
		 *
		 * @param mixed $payload Saved meta value.
		 * @return array<string,mixed>
		 */
		public function inspect( $payload ): array {
			$result = array(
				'valid'                => true,
				'supported'            => true,
				'renderable'           => true,
				'schema_version'       => 1,
				'has_explicit_version' => false,
				'payload'              => $payload,
				'should_write'         => false,
				'errors'               => array(),
			);

			if ( ! is_array( $payload ) ) {
				return $this->with_error( $result, 'payload_not_array', '$' );
			}

			if ( array_key_exists( 'schema_version', $payload ) ) {
				$result['has_explicit_version'] = true;
				if ( ! is_int( $payload['schema_version'] ) ) {
					return $this->with_error( $result, 'invalid_schema_version', '$.schema_version' );
				}
				$result['schema_version'] = $payload['schema_version'];
			}

			if ( 1 !== $result['schema_version'] && self::CURRENT_VERSION !== $result['schema_version'] ) {
				$result['supported']  = false;
				$result['renderable'] = false;
				$result['errors'][]   = array(
					'code' => 'unsupported_schema_version',
					'path' => '$.schema_version',
				);
				return $result;
			}

			if ( array_key_exists( 'responsive', $payload ) ) {
				$result = $this->validate_versioned_subtree( $result, $payload['responsive'], '$.responsive', 1 );
			}
			if ( array_key_exists( 'rule_model', $payload ) ) {
				$result = $this->validate_versioned_subtree( $result, $payload['rule_model'], '$.rule_model', 2 );
			}
			if ( array_key_exists( 'template_ref', $payload ) ) {
				$result = $this->validate_versioned_subtree( $result, $payload['template_ref'], '$.template_ref', 1 );
			}

			return $result;
		}

		/**
		 * Validate an explicit write. Unknown fields are intentionally preserved.
		 *
		 * @param mixed $payload Candidate payload.
		 * @return array<string,mixed>
		 */
		public function validate_for_write( $payload ): array {
			$result = $this->inspect( $payload );
			if ( ! $result['supported'] ) {
				$result['valid'] = false;
			}

			return $result;
		}

		/**
		 * @param array<string,mixed> $result  Current inspection result.
		 * @param mixed               $subtree Candidate subtree.
		 * @return array<string,mixed>
		 */
		private function validate_versioned_subtree( array $result, $subtree, string $path, int $expected_version ): array {
			if ( ! is_array( $subtree ) ) {
				return $this->with_error( $result, 'subtree_not_array', $path );
			}

			if ( ! isset( $subtree['schema_version'] ) || ! is_int( $subtree['schema_version'] ) ) {
				return $this->with_error( $result, 'missing_subtree_schema_version', $path . '.schema_version' );
			}

			if ( $expected_version !== $subtree['schema_version'] ) {
				return $this->with_error( $result, 'unsupported_subtree_schema_version', $path . '.schema_version' );
			}

			return $result;
		}

		/**
		 * @param array<string,mixed> $result Current inspection result.
		 * @return array<string,mixed>
		 */
		private function with_error( array $result, string $code, string $path ): array {
			$result['valid']      = false;
			$result['renderable'] = false;
			$result['errors'][]   = array(
				'code' => $code,
				'path' => $path,
			);

			return $result;
		}
	}
}
