<?php
/**
 * Lossless authority, condition-adapter and downgrade-guard facade.
 *
 * This boundary prepares immutable write candidates. It never calls WordPress
 * persistence APIs and cannot authorize a Save/Publish operation.
 */

if ( ! class_exists( 'BeRocket_Growth_Suite_Legacy_Rule_Adapter' ) ) {
	class BeRocket_Growth_Suite_Legacy_Rule_Adapter {
		const GUARD_TYPE            = 'brapl_rule_model_v2_guard';
		const GUARD_SCHEMA_VERSION  = 1;
		const MAX_LEGACY_OR_GROUPS  = 50;
		const MAX_LEGACY_AND_LEAVES = 50;
		const MAX_LEGACY_LEAVES     = 100;

		/** @var BeRocket_Growth_Suite_Rule_Normalizer */
		private $normalizer;

		/** @var BeRocket_Growth_Suite_Rule_Model */
		private $model;

		/** @var array<string,object> */
		private $condition_adapters = array();

		/** @var array<string,int> */
		private $adapter_versions = array();

		public function __construct(
			BeRocket_Growth_Suite_Rule_Normalizer $normalizer,
			BeRocket_Growth_Suite_Rule_Model $model
		) {
			$this->normalizer = $normalizer;
			$this->model      = $model;
		}

		/**
		 * Register one versioned adapter that proves both directions.
		 *
		 * @param mixed $adapter Condition-owned adapter.
		 */
		public function register_condition_adapter( $adapter ): bool {
			if (
				! is_object( $adapter )
				|| ! is_callable( array( $adapter, 'type_id' ) )
				|| ! is_callable( array( $adapter, 'schema' ) )
				|| ! is_callable( array( $adapter, 'inspect_saved_leaf' ) )
				|| ! is_callable( array( $adapter, 'project_legacy_leaf' ) )
			) {
				return false;
			}

			try {
				$type_id = $adapter->type_id();
				$schema  = $adapter->schema();
			} catch ( Throwable $throwable ) {
				unset( $throwable );
				return false;
			}

			if (
				! is_string( $type_id )
				|| 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $type_id )
				|| isset( $this->condition_adapters[ $type_id ] )
				|| ! is_array( $schema )
				|| ! isset( $schema['saved_schema_version'] )
				|| ! is_int( $schema['saved_schema_version'] )
				|| 1 > $schema['saved_schema_version']
			) {
				return false;
			}

			$this->condition_adapters[ $type_id ] = $adapter;
			$this->adapter_versions[ $type_id ]   = $schema['saved_schema_version'];
			return true;
		}

		public function has_condition_adapter( string $type_id ): bool {
			return isset( $this->condition_adapters[ $type_id ] );
		}

		/**
		 * Read the authoritative rule without materializing IDs or defaults.
		 *
		 * @param mixed $payload  Saved label payload.
		 * @param mixed $label_id Label ID for ephemeral legacy diagnostics only.
		 * @return array<string,mixed>
		 */
		public function read( $payload, $label_id = 0 ): array {
			if ( is_array( $payload ) && array_key_exists( 'rule_model', $payload ) ) {
				$inspection = $this->model->inspect( $payload['rule_model'] );
				$root       = null;
				if (
					$inspection['valid']
					&& $inspection['supported']
					&& $inspection['renderable']
					&& is_array( $inspection['model'] )
					&& isset( $inspection['model']['root'] )
					&& is_array( $inspection['model']['root'] )
				) {
					$root = $inspection['model']['root'];
				}

				return array(
					'authority'     => 'rule_model',
					'valid'         => $inspection['valid'],
					'supported'     => $inspection['supported'],
					'renderable'    => $inspection['renderable'],
					'should_write'  => false,
					'saved_payload' => $payload,
					'model'         => $payload['rule_model'],
					'root'          => $root,
					'errors'        => $this->prefix_errors( $inspection['errors'], '$.rule_model' ),
				);
			}

			$data       = is_array( $payload ) && array_key_exists( 'data', $payload )
				? $payload['data']
				: array();
			$normalized = $this->normalizer->from_legacy( $data, $label_id );

			return array(
				'authority'     => 'legacy',
				'valid'         => $normalized['valid'],
				'supported'     => true,
				'renderable'    => true,
				'should_write'  => false,
				'saved_payload' => $payload,
				'model'         => null,
				'root'          => $normalized['root'],
				'errors'        => $this->prefix_errors( $normalized['errors'], '$.data' ),
			);
		}

		/**
		 * Prepare an explicit legacy-to-v2 authority switch.
		 *
		 * Every leaf must prove an exact read/write round-trip. A failed proof
		 * leaves the source payload as the only returned payload.
		 *
		 * @param mixed $payload  Authoritative legacy label payload.
		 * @param mixed $label_id Label ID for diagnostic paths only.
		 * @return array<string,mixed>
		 */
		public function prepare_legacy_conversion( $payload, $label_id = 0 ): array {
			$result = $this->write_result( $payload );

			if ( ! is_array( $payload ) ) {
				$result['errors'][] = $this->error( 'label_payload_not_array', '$' );
				return $result;
			}

			if ( array_key_exists( 'rule_model', $payload ) ) {
				$result['errors'][] = $this->error( 'legacy_authority_required', '$.rule_model' );
				return $result;
			}

			$data       = array_key_exists( 'data', $payload ) ? $payload['data'] : array();
			$normalized = $this->normalizer->from_legacy( $data, $label_id );
			if ( ! $normalized['valid'] || ! $normalized['lossless'] ) {
				$result['errors'] = $this->prefix_errors( $normalized['errors'], '$.data' );
				return $result;
			}

			$adapted = $this->adapt_legacy_root( $normalized['root'] );
			if ( ! $adapted['valid'] ) {
				$result['errors'] = $adapted['errors'];
				return $result;
			}

			$prepared = $this->prepare_v2_write_candidate(
				$payload,
				array(
					'schema_version' => BeRocket_Growth_Suite_Rule_Model::SCHEMA_VERSION,
					'root'           => $adapted['root'],
				),
				true
			);

			if (
				! $prepared['valid']
				|| $prepared['used_guard']
				|| ! is_array( $prepared['payload'] )
				|| ! array_key_exists( 'data', $prepared['payload'] )
				|| $data !== $prepared['payload']['data']
			) {
				$result['errors'] = $prepared['errors'];
				if ( empty( $result['errors'] ) ) {
					$result['errors'][] = $this->error( 'condition_adapter_not_lossless', '$.data' );
				}
				return $result;
			}

			$prepared['converted'] = true;
			return $prepared;
		}

		/**
		 * Prepare a validated schema-v2 label write and its legacy projection.
		 *
		 * @param mixed $saved_payload Existing authoritative label payload.
		 * @param mixed $candidate     Candidate `rule_model` subtree.
		 * @return array<string,mixed>
		 */
		public function prepare_v2_write( $saved_payload, $candidate ): array {
			return $this->prepare_v2_write_candidate( $saved_payload, $candidate, false );
		}

		/**
		 * @param mixed $saved_payload           Existing authoritative label payload.
		 * @param mixed $candidate               Candidate `rule_model` subtree.
		 * @param bool  $legacy_conversion_proven Internal exact-conversion gate.
		 * @return array<string,mixed>
		 */
		private function prepare_v2_write_candidate( $saved_payload, $candidate, bool $legacy_conversion_proven ): array {
			$result = $this->write_result( $saved_payload );

			if ( ! is_array( $saved_payload ) ) {
				$result['errors'][] = $this->error( 'label_payload_not_array', '$' );
				return $result;
			}

			if ( ! array_key_exists( 'rule_model', $saved_payload ) && ! $legacy_conversion_proven ) {
				$result['errors'][] = $this->error( 'legacy_authority_required', '$.rule_model' );
				return $result;
			}

			if ( array_key_exists( 'rule_model', $saved_payload ) ) {
				$source_inspection = $this->model->inspect( $saved_payload['rule_model'] );
				$source_errors     = $this->prefix_errors( $source_inspection['errors'], '$.rule_model' );
				$future_compat     = is_array( $saved_payload['rule_model'] )
					&& isset( $saved_payload['rule_model']['compatibility'] )
					&& is_array( $saved_payload['rule_model']['compatibility'] )
					&& isset( $saved_payload['rule_model']['compatibility']['schema_version'] )
					&& 1 !== $saved_payload['rule_model']['compatibility']['schema_version'];

				if (
					! $source_inspection['valid']
					|| ! $source_inspection['supported']
					|| ! $source_inspection['renderable']
					|| $future_compat
				) {
					if ( $future_compat && empty( $source_errors ) ) {
						$source_errors[] = $this->error( 'invalid_compatibility', '$.rule_model.compatibility.schema_version' );
					}
					$result['errors'] = $source_errors;
					return $result;
				}
			}

			$validation = $this->model->validate_for_write( $candidate );
			if ( ! $validation['valid'] || ! $validation['supported'] || ! $validation['renderable'] ) {
				$result['errors'] = $this->prefix_errors( $validation['errors'], '$.rule_model' );
				return $result;
			}

			$projection = $this->project_v2_root( $validation['model']['root'] );
			$used_guard = ! $projection['lossless'];
			$data       = $used_guard ? $this->guard_data() : $projection['data'];
			$model      = $validation['model'];
			if ( ! $used_guard && isset( $projection['root'] ) && is_array( $projection['root'] ) ) {
				$model['root'] = $projection['root'];
			}
			$snapshot = $used_guard
				? $this->last_lossless_data( $saved_payload )
				: $projection['data'];

			$model['compatibility'] = array( 'schema_version' => 1 );
			if ( is_array( $snapshot ) ) {
				$model['compatibility']['last_lossless_data'] = $snapshot;
			}

			$final_validation = $this->model->validate_for_write( $model );
			if (
				! $final_validation['valid']
				&& isset( $model['compatibility']['last_lossless_data'] )
				&& $this->has_error_code( $final_validation['errors'], 'rule_model_too_large' )
			) {
				unset( $model['compatibility']['last_lossless_data'] );
				$final_validation = $this->model->validate_for_write( $model );
			}

			if ( ! $final_validation['valid'] || ! $final_validation['supported'] || ! $final_validation['renderable'] ) {
				$result['errors'] = $this->prefix_errors( $final_validation['errors'], '$.rule_model' );
				return $result;
			}

			$payload               = $saved_payload;
			$payload['rule_model'] = $final_validation['model'];
			$payload['data']       = $data;

			$result['valid']        = true;
			$result['used_guard']   = $used_guard;
			$result['should_write'] = true;
			$result['payload']      = $payload;
			$result['model']        = $final_validation['model'];
			$result['errors']       = $projection['errors'];
			return $result;
		}

		/**
		 * @param array<string,mixed> $root Legacy projection from the normalizer.
		 * @return array<string,mixed>
		 */
		private function adapt_legacy_root( array $root ): array {
			$adapted_root = array(
				'kind'     => 'group',
				'operator' => 'or',
				'children' => array(),
			);
			$errors       = array();

			foreach ( $root['children'] as $group_position => $group ) {
				$group_key     = $group['metadata']['legacy_key'] ?? $group_position;
				$adapted_group = array(
					'kind'     => 'group',
					'operator' => 'and',
					'children' => array(),
					'metadata' => array( 'legacy_key' => $group_key ),
				);

				foreach ( $group['children'] as $condition_position => $condition ) {
					$condition_key = $condition['metadata']['legacy_key'] ?? $condition_position;
					$path          = '$.data' . $this->key_path( $group_key ) . $this->key_path( $condition_key );
					$adapted       = $this->adapt_legacy_condition( $condition, $path );
					if ( ! $adapted['valid'] ) {
						$errors = array_merge( $errors, $adapted['errors'] );
						continue;
					}

					$adapted['condition']['metadata']['legacy_key'] = $condition_key;
					$adapted_group['children'][]                    = $adapted['condition'];
				}

				$adapted_root['children'][] = $adapted_group;
			}

			return array(
				'valid'  => empty( $errors ),
				'root'   => $adapted_root,
				'errors' => $errors,
			);
		}

		/**
		 * @param array<string,mixed> $condition Normalized opaque legacy condition.
		 * @return array<string,mixed>
		 */
		private function adapt_legacy_condition( array $condition, string $path ): array {
			$type = isset( $condition['type'] ) && is_string( $condition['type'] ) ? $condition['type'] : '';
			if ( ! isset( $this->condition_adapters[ $type ] ) ) {
				return $this->failed_adaptation( 'condition_adapter_required', $path );
			}

			$payload = $condition['payload'] ?? null;
			$adapter = $this->condition_adapters[ $type ];
			$inspect = $this->inspect_with_adapter( $adapter, $payload );
			if ( ! $this->valid_adapter_inspection( $inspect, $type ) ) {
				$code       = is_array( $inspect ) && isset( $inspect['reason_code'] ) && is_string( $inspect['reason_code'] )
					? $inspect['reason_code']
					: 'condition_adapter_not_lossless';
				$field_path = is_array( $inspect ) && isset( $inspect['field_path'] ) && is_string( $inspect['field_path'] )
					? $this->append_adapter_path( $path, $inspect['field_path'] )
					: $path;
				return $this->failed_adaptation( $code, $field_path );
			}

			$canonical = $inspect['condition'];
			$projected = $this->project_with_adapter( $adapter, $canonical, $payload, $path );
			if ( ! $projected['lossless'] || $payload !== $projected['payload'] ) {
				return $this->failed_adaptation( 'condition_adapter_not_lossless', $path );
			}

			if ( ! isset( $canonical['metadata'] ) || ! is_array( $canonical['metadata'] ) ) {
				$canonical['metadata'] = array();
			}
			$canonical['metadata']['legacy_payload'] = $payload;

			return array(
				'valid'     => true,
				'condition' => $canonical,
				'errors'    => array(),
			);
		}

		/**
		 * @param array<string,mixed> $root Valid schema-v2 root.
		 * @return array<string,mixed>
		 */
		private function project_v2_root( array $root ): array {
			$shape_error = $this->legacy_shape_error( $root );
			if ( null !== $shape_error ) {
				return array(
					'lossless' => false,
					'data'     => null,
					'root'     => null,
					'errors'   => array( $shape_error ),
				);
			}

			$projectable    = $root;
			$persisted_root = $root;
			$errors         = array();
			foreach ( $projectable['children'] as $group_position => &$group ) {
				foreach ( $group['children'] as $condition_position => &$condition ) {
					$path = '$.rule_model.root.children[' . $group_position . '].children[' . $condition_position . ']';
					$type = isset( $condition['type'] ) && is_string( $condition['type'] ) ? $condition['type'] : '';
					if ( ! isset( $this->condition_adapters[ $type ] ) ) {
						$errors[] = $this->error( 'condition_adapter_required', $path );
						continue;
					}

					if (
						isset( $condition['metadata'] )
						&& is_array( $condition['metadata'] )
						&& isset( $condition['metadata']['legacy_payload'] )
						&& is_array( $condition['metadata']['legacy_payload'] )
					) {
						$source_payload = $condition['metadata']['legacy_payload'];
					} else {
						$source_payload = isset( $condition['payload'] ) && is_array( $condition['payload'] )
							? $condition['payload']
							: null;
					}
					$projected = $this->project_with_adapter(
						$this->condition_adapters[ $type ],
						$condition,
						$source_payload,
						$path
					);
					if ( ! $projected['lossless'] ) {
						$errors = array_merge( $errors, $projected['errors'] );
						continue;
					}

					$condition['payload'] = $projected['payload'];
					if ( ! isset( $condition['metadata'] ) || ! is_array( $condition['metadata'] ) ) {
						$condition['metadata'] = array();
					}
					$condition['metadata']['legacy_payload'] = $projected['payload'];
					if (
						! isset( $persisted_root['children'][ $group_position ]['children'][ $condition_position ]['metadata'] )
						|| ! is_array( $persisted_root['children'][ $group_position ]['children'][ $condition_position ]['metadata'] )
					) {
						$persisted_root['children'][ $group_position ]['children'][ $condition_position ]['metadata'] = array();
					}
					$persisted_root['children'][ $group_position ]['children'][ $condition_position ]['metadata']['legacy_payload'] = $projected['payload'];
				}
				unset( $condition );
			}
			unset( $group );

			if ( ! empty( $errors ) ) {
				return array(
					'lossless' => false,
					'data'     => null,
					'root'     => null,
					'errors'   => $this->unique_errors( $errors ),
				);
			}

			$projection = $this->normalizer->to_legacy( $projectable );
			return array(
				'lossless' => $projection['lossless'],
				'data'     => $projection['data'],
				'root'     => $projection['lossless'] ? $persisted_root : null,
				'errors'   => $this->prefix_errors( $projection['errors'], '$.rule_model' ),
			);
		}

		/**
		 * @param array<string,mixed> $root Valid schema-v2 root.
		 * @return array{code:string,path:string}|null
		 */
		private function legacy_shape_error( array $root ): ?array {
			if (
				'group' !== ( $root['kind'] ?? null )
				|| 'or' !== ( $root['operator'] ?? null )
				|| ! isset( $root['children'] )
				|| ! is_array( $root['children'] )
				|| self::MAX_LEGACY_OR_GROUPS < count( $root['children'] )
			) {
				return $this->error( 'legacy_projection_not_lossless', '$.rule_model.root' );
			}

			$leaf_count = 0;
			foreach ( $root['children'] as $group ) {
				if (
					! is_array( $group )
					|| 'group' !== ( $group['kind'] ?? null )
					|| 'and' !== ( $group['operator'] ?? null )
					|| ! isset( $group['children'] )
					|| ! is_array( $group['children'] )
					|| empty( $group['children'] )
					|| self::MAX_LEGACY_AND_LEAVES < count( $group['children'] )
				) {
					return $this->error( 'legacy_projection_not_lossless', '$.rule_model.root' );
				}

				foreach ( $group['children'] as $condition ) {
					if ( ! is_array( $condition ) || 'condition' !== ( $condition['kind'] ?? null ) ) {
						return $this->error( 'legacy_projection_not_lossless', '$.rule_model.root' );
					}
					++$leaf_count;
				}
			}

			return self::MAX_LEGACY_LEAVES < $leaf_count
				? $this->error( 'legacy_projection_not_lossless', '$.rule_model.root' )
				: null;
		}

		/**
		 * @param object              $adapter        Registered adapter.
		 * @param array<string,mixed> $condition      Canonical condition.
		 * @param mixed               $source_payload Preserved source payload.
		 * @return array<string,mixed>
		 */
		private function project_with_adapter( $adapter, array $condition, $source_payload, string $path ): array {
			try {
				$projection = $adapter->project_legacy_leaf( $condition, $source_payload );
			} catch ( Throwable $throwable ) {
				unset( $throwable );
				return $this->failed_projection( 'condition_adapter_not_lossless', $path );
			}

			if (
				! is_array( $projection )
				|| empty( $projection['lossless'] )
				|| ! isset( $projection['payload'] )
				|| ! is_array( $projection['payload'] )
			) {
				return $this->failed_projection( 'condition_adapter_not_lossless', $path );
			}

			$inspection = $this->inspect_with_adapter( $adapter, $projection['payload'] );
			$type       = isset( $condition['type'] ) && is_string( $condition['type'] ) ? $condition['type'] : '';
			if (
				! $this->valid_adapter_inspection( $inspection, $type )
				|| $this->condition_semantics( $condition ) !== $this->condition_semantics( $inspection['condition'] )
			) {
				return $this->failed_projection( 'condition_adapter_not_lossless', $path );
			}

			return array(
				'lossless' => true,
				'payload'  => $projection['payload'],
				'errors'   => array(),
			);
		}

		/**
		 * @param object $adapter Registered adapter.
		 * @param mixed  $payload Saved leaf.
		 * @return mixed
		 */
		private function inspect_with_adapter( $adapter, $payload ) {
			try {
				return $adapter->inspect_saved_leaf( $payload );
			} catch ( Throwable $throwable ) {
				unset( $throwable );
				return null;
			}
		}

		/** @param mixed $inspection */
		private function valid_adapter_inspection( $inspection, string $type ): bool {
			return is_array( $inspection )
				&& ! empty( $inspection['valid'] )
				&& ! empty( $inspection['supported'] )
				&& isset( $inspection['source_schema_version'] )
				&& is_int( $inspection['source_schema_version'] )
				&& isset( $this->adapter_versions[ $type ] )
				&& $this->adapter_versions[ $type ] === $inspection['source_schema_version']
				&& isset( $inspection['condition'] )
				&& is_array( $inspection['condition'] )
				&& 'condition' === ( $inspection['condition']['kind'] ?? null )
				&& ( $inspection['condition']['type'] ?? null ) === $type
				&& isset( $inspection['condition']['operator'] )
				&& is_string( $inspection['condition']['operator'] )
				&& array_key_exists( 'value', $inspection['condition'] );
		}

		/**
		 * @param array<string,mixed> $condition Canonical condition.
		 * @return array<string,mixed>
		 */
		private function condition_semantics( array $condition ): array {
			return array(
				'kind'     => $condition['kind'] ?? null,
				'type'     => $condition['type'] ?? null,
				'operator' => $condition['operator'] ?? null,
				'value'    => $condition['value'] ?? null,
			);
		}

		/** @return array<int,array<int,array<string,mixed>>> */
		private function guard_data(): array {
			return array(
				array(
					array(
						'type'           => self::GUARD_TYPE,
						'schema_version' => self::GUARD_SCHEMA_VERSION,
					),
				),
			);
		}

		/**
		 * @param array<string,mixed> $payload Existing label payload.
		 * @return array<mixed>|null
		 */
		private function last_lossless_data( array $payload ): ?array {
			if (
				isset( $payload['rule_model'] )
				&& is_array( $payload['rule_model'] )
				&& isset( $payload['rule_model']['compatibility'] )
				&& is_array( $payload['rule_model']['compatibility'] )
				&& 1 === ( $payload['rule_model']['compatibility']['schema_version'] ?? null )
				&& isset( $payload['rule_model']['compatibility']['last_lossless_data'] )
				&& is_array( $payload['rule_model']['compatibility']['last_lossless_data'] )
			) {
				return $payload['rule_model']['compatibility']['last_lossless_data'];
			}

			if ( ! array_key_exists( 'rule_model', $payload ) && isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
				$normalized = $this->normalizer->from_legacy( $payload['data'] );
				if ( $normalized['valid'] && $normalized['lossless'] ) {
					return $payload['data'];
				}
			}

			return null;
		}

		/**
		 * @param mixed $source_payload Existing label payload.
		 * @return array<string,mixed>
		 */
		private function write_result( $source_payload ): array {
			return array(
				'valid'          => false,
				'converted'      => false,
				'used_guard'     => false,
				'should_write'   => false,
				'source_payload' => $source_payload,
				'payload'        => $source_payload,
				'model'          => null,
				'errors'         => array(),
			);
		}

		/** @return array<string,mixed> */
		private function failed_adaptation( string $code, string $path ): array {
			return array(
				'valid'     => false,
				'condition' => null,
				'errors'    => array( $this->error( $code, $path ) ),
			);
		}

		/** @return array<string,mixed> */
		private function failed_projection( string $code, string $path ): array {
			return array(
				'lossless' => false,
				'payload'  => null,
				'errors'   => array( $this->error( $code, $path ) ),
			);
		}

		/**
		 * Prefix a model-local JSONPath with its aggregate field path.
		 *
		 * @param mixed $errors Child errors.
		 * @return array<int,array{code:string,path:string}>
		 */
		private function prefix_errors( $errors, string $prefix ): array {
			if ( ! is_array( $errors ) ) {
				return array();
			}

			$prefixed = array();
			foreach ( $errors as $error ) {
				if ( ! is_array( $error ) || ! isset( $error['code'], $error['path'] ) ) {
					continue;
				}
				$path       = is_string( $error['path'] ) ? $error['path'] : '$';
				$prefixed[] = $this->error(
					(string) $error['code'],
					$prefix . ( 0 === strpos( $path, '$' ) ? substr( $path, 1 ) : $path )
				);
			}

			return $this->unique_errors( $prefixed );
		}

		private function append_adapter_path( string $base, string $adapter_path ): string {
			return $base . ( 0 === strpos( $adapter_path, '$' ) ? substr( $adapter_path, 1 ) : $adapter_path );
		}

		/** @param int|string $key */
		private function key_path( $key ): string {
			if ( is_int( $key ) ) {
				return '[' . $key . ']';
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Dependency-free JSONPath fragment.
			$encoded = json_encode( (string) $key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			return '[' . ( false === $encoded ? '""' : $encoded ) . ']';
		}

		/** @param mixed $errors */
		private function has_error_code( $errors, string $code ): bool {
			if ( ! is_array( $errors ) ) {
				return false;
			}

			foreach ( $errors as $error ) {
				if ( is_array( $error ) && ( $error['code'] ?? null ) === $code ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * @param array<int,array{code:string,path:string}> $errors Errors.
		 * @return array<int,array{code:string,path:string}>
		 */
		private function unique_errors( array $errors ): array {
			$unique = array();
			foreach ( $errors as $error ) {
				if ( ! in_array( $error, $unique, true ) ) {
					$unique[] = $error;
				}
			}
			return $unique;
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
