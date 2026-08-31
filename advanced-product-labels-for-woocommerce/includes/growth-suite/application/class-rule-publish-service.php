<?php
/**
 * Atomic rule-model preparation for the existing label Save/Publish path.
 */

if ( ! class_exists( 'BeRocket_Growth_Suite_Rule_Publish_Service' ) ) {
	class BeRocket_Growth_Suite_Rule_Publish_Service {
		/** @var BeRocket_Growth_Suite_Legacy_Rule_Adapter */
		private $facade;

		public function __construct( BeRocket_Growth_Suite_Legacy_Rule_Adapter $facade ) {
			$this->facade = $facade;
		}

		/** @param object $adapter Versioned bidirectional condition adapter. */
		public function register_condition_adapter( $adapter ): bool {
			return $this->facade->register_condition_adapter( $adapter );
		}

		/**
		 * Prepare one complete `br_labels` meta value without writing it.
		 *
		 * A candidate is explicit only when the existing form payload contains a
		 * `rule_model` field. Browser forms submit that field as canonical JSON so
		 * scalar types and the bounded nested structure survive WordPress form
		 * encoding. Tests/internal callers may supply the decoded array directly.
		 *
		 * @param mixed $saved_payload     Existing raw `br_labels` meta value.
		 * @param mixed $submitted_payload Existing form payload after compatibility filters.
		 * @param mixed $label_id          Label identifier for diagnostic paths.
		 * @param bool  $saved_exists      Whether the meta row already exists.
		 * @return array<string,mixed>
		 */
		public function prepare( $saved_payload, $submitted_payload, $label_id = 0, bool $saved_exists = true ): array {
			$saved_has_model = is_array( $saved_payload ) && array_key_exists( 'rule_model', $saved_payload );
			$candidate       = $this->extract_candidate( $submitted_payload );

			if ( ! $saved_has_model && ! $candidate['present'] ) {
				return $this->success( $submitted_payload, false );
			}

			if ( ! is_array( $submitted_payload ) ) {
				return $this->failure(
					$saved_payload,
					array( $this->error( 'label_payload_not_array', '$' ) )
				);
			}

			if ( $saved_exists && ! is_array( $saved_payload ) ) {
				return $this->failure(
					$saved_payload,
					array( $this->error( 'label_payload_not_array', '$' ) )
				);
			}

			$source = $saved_exists ? $saved_payload : array();
			if ( ! $candidate['present'] ) {
				return $this->preserve_saved_authority( $source, $submitted_payload, $label_id );
			}

			if ( ! $candidate['valid'] ) {
				return $this->failure( $source, $candidate['errors'] );
			}

			if ( ! $saved_has_model ) {
				$conversion = $this->facade->prepare_legacy_conversion( $source, $label_id );
				if ( empty( $conversion['valid'] ) || empty( $conversion['should_write'] ) ) {
					return $this->failure( $source, $conversion['errors'] ?? array() );
				}
				$source = $conversion['payload'];
			}

			$prepared = $this->facade->prepare_v2_write( $source, $candidate['model'] );
			if ( empty( $prepared['valid'] ) || empty( $prepared['should_write'] ) ) {
				return $this->failure( $source, $prepared['errors'] ?? array() );
			}

			$payload               = $submitted_payload;
			$payload['rule_model'] = $prepared['payload']['rule_model'];
			$payload['data']       = $prepared['payload']['data'];

			$result                   = $this->success( $payload, true );
			$result['converted']      = ! $saved_has_model;
			$result['used_guard']     = ! empty( $prepared['used_guard'] );
			$result['warnings']       = isset( $prepared['errors'] ) && is_array( $prepared['errors'] )
				? $prepared['errors']
				: array();
			$result['source_payload'] = $saved_payload;
			return $result;
		}

		/**
		 * @param array<string,mixed> $saved_payload
		 * @param array<string,mixed> $submitted_payload
		 * @param mixed               $label_id
		 * @return array<string,mixed>
		 */
		private function preserve_saved_authority( array $saved_payload, array $submitted_payload, $label_id ): array {
			$inspection = $this->facade->read( $saved_payload, $label_id );
			if (
				empty( $inspection['valid'] )
				|| empty( $inspection['supported'] )
				|| empty( $inspection['renderable'] )
			) {
				return $this->failure( $saved_payload, $inspection['errors'] ?? array() );
			}

			if ( ! isset( $saved_payload['data'] ) || ! is_array( $saved_payload['data'] ) ) {
				return $this->failure(
					$saved_payload,
					array( $this->error( 'invalid_compatibility', '$.data' ) )
				);
			}

			$submitted_payload['rule_model'] = $saved_payload['rule_model'];
			$submitted_payload['data']       = $saved_payload['data'];

			$result                    = $this->success( $submitted_payload, true );
			$result['preserved_model'] = true;
			$result['source_payload']  = $saved_payload;
			return $result;
		}

		/**
		 * @param mixed $submitted_payload
		 * @return array{present:bool,valid:bool,model:mixed,errors:array<int,array{code:string,path:string}>}
		 */
		private function extract_candidate( $submitted_payload ): array {
			$result = array(
				'present' => is_array( $submitted_payload ) && array_key_exists( 'rule_model', $submitted_payload ),
				'valid'   => false,
				'model'   => null,
				'errors'  => array(),
			);

			if ( ! $result['present'] ) {
				return $result;
			}

			$raw = $submitted_payload['rule_model'];
			if ( is_array( $raw ) ) {
				$result['valid'] = true;
				$result['model'] = $raw;
				return $result;
			}

			if ( ! is_string( $raw ) || '' === $raw ) {
				$result['errors'][] = $this->error( 'invalid_rule_model', '$.rule_model' );
				return $result;
			}

			if ( BeRocket_Growth_Suite_Rule_Model::MAX_MODEL_BYTES < strlen( $raw ) ) {
				$result['errors'][] = $this->error( 'rule_model_too_large', '$.rule_model' );
				return $result;
			}

			$model = json_decode( $raw, true, BeRocket_Growth_Suite_Rule_Model::MAX_DEPTH + 8 );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $model ) ) {
				$result['errors'][] = $this->error( 'invalid_rule_model', '$.rule_model' );
				return $result;
			}

			$result['valid'] = true;
			$result['model'] = $model;
			return $result;
		}

		/**
		 * @param mixed $payload Prepared payload.
		 * @return array<string,mixed>
		 */
		private function success( $payload, bool $active ): array {
			return array(
				'active'          => $active,
				'valid'           => true,
				'should_write'    => true,
				'converted'       => false,
				'used_guard'      => false,
				'preserved_model' => false,
				'source_payload'  => null,
				'payload'         => $payload,
				'errors'          => array(),
				'warnings'        => array(),
			);
		}

		/**
		 * @param mixed $source_payload
		 * @param mixed $errors
		 * @return array<string,mixed>
		 */
		private function failure( $source_payload, $errors ): array {
			$errors = is_array( $errors ) ? $errors : array();
			if ( empty( $errors ) ) {
				$errors[] = $this->error( 'invalid_rule_model', '$.rule_model' );
			}

			return array(
				'active'          => true,
				'valid'           => false,
				'should_write'    => false,
				'converted'       => false,
				'used_guard'      => false,
				'preserved_model' => false,
				'source_payload'  => $source_payload,
				'payload'         => $source_payload,
				'errors'          => $errors,
				'warnings'        => array(),
			);
		}

		/** @return array{code:string,path:string} */
		private function error( string $code, string $path ): array {
			return array(
				'code' => $code,
				'path' => $path,
			);
		}
	}
}
