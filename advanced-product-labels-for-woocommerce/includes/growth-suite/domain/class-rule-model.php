<?php
/**
 * Canonical schema-v2 rule-model inspection, write validation and hashing.
 *
 * This dependency-free boundary never persists data. Invalid saved data is
 * returned unchanged, and generated identifiers are exposed only after a
 * completely valid write candidate has passed validation.
 */

if ( ! class_exists( 'BeRocket_Growth_Suite_Rule_Model' ) ) {
	class BeRocket_Growth_Suite_Rule_Model {
		const SCHEMA_VERSION     = 2;
		const MAX_DEPTH          = 5;
		const MAX_NODES          = 100;
		const MAX_CHILDREN       = 50;
		const MAX_METADATA_BYTES = 4096;
		const MAX_VALUE_BYTES    = 16384;
		const MAX_MODEL_BYTES    = 262144;

		/** @var callable|null */
		private $uuid_factory;

		/**
		 * @param callable|null $uuid_factory Optional deterministic UUIDv4 source.
		 */
		public function __construct( $uuid_factory = null ) {
			if ( null !== $uuid_factory && ! is_callable( $uuid_factory ) ) {
				throw new InvalidArgumentException( 'The UUID factory must be callable.' );
			}

			$this->uuid_factory = $uuid_factory;
		}

		/**
		 * Inspect already-saved input without generating IDs or changing fields.
		 *
		 * @param mixed $model Saved rule-model subtree.
		 * @return array<string,mixed>
		 */
		public function inspect( $model ): array {
			return $this->validate( $model, false );
		}

		/**
		 * Validate a write atomically and generate only absent identifiers.
		 *
		 * @param mixed $model Candidate rule-model subtree.
		 * @return array<string,mixed>
		 */
		public function validate_for_write( $model ): array {
			return $this->validate( $model, true );
		}

		/**
		 * Return a deterministic lowercase SHA-256 semantic hash.
		 *
		 * @param mixed $model Supported, valid, fully identified rule model.
		 */
		public function semantic_hash( $model ): string {
			$inspection = $this->inspect( $model );
			if ( ! $inspection['valid'] || ! $inspection['supported'] || ! $inspection['renderable'] ) {
				throw new InvalidArgumentException( 'A supported valid rule model is required.' );
			}

			$semantic = $this->without_non_semantic_fields( $model );
			$semantic = $this->sort_object_keys( $semantic );
			$json     = $this->encode_json( $semantic );
			if ( false === $json ) {
				throw new InvalidArgumentException( 'The rule model must be JSON encodable.' );
			}

			return hash( 'sha256', $json );
		}

		/**
		 * @param mixed $model     Rule model.
		 * @param bool  $for_write Whether this is an explicit write candidate.
		 * @return array<string,mixed>
		 */
		private function validate( $model, bool $for_write ): array {
			$result = $this->result( $model );

			if ( ! is_array( $model ) ) {
				$result['supported'] = false;
				return $this->with_error( $result, 'unsupported_schema_version', '$.schema_version' );
			}

			if ( ! array_key_exists( 'schema_version', $model ) || ! is_int( $model['schema_version'] ) ) {
				$result['supported'] = false;
				return $this->with_error( $result, 'unsupported_schema_version', '$.schema_version' );
			}

			if ( self::SCHEMA_VERSION !== $model['schema_version'] ) {
				$result['supported']  = false;
				$result['renderable'] = false;
				$result['valid']      = ! $for_write;
				$result['errors'][]   = $this->error( 'unsupported_schema_version', '$.schema_version' );
				return $result;
			}

			$working      = $model;
			$errors       = array();
			$used_ids     = array();
			$reserved_ids = $this->collect_reserved_ids( $model );
			$node_count   = 0;
			$generated    = false;
			$model_bytes  = $this->json_bytes( $model );

			if ( false === $model_bytes || self::MAX_MODEL_BYTES < $model_bytes ) {
				$this->add_error( $errors, 'rule_model_too_large', '$' );
			}

			$this->validate_compatibility( $working, $errors, $for_write );
			$this->validate_rule_id( $working, $errors, $used_ids, $reserved_ids, $generated, $for_write );

			if ( ! array_key_exists( 'root', $working ) ) {
				$this->add_error( $errors, 'missing_root', '$.root' );
			} elseif ( ! is_array( $working['root'] ) ) {
				$this->add_error( $errors, 'invalid_node_kind', '$.root.kind' );
			} else {
				$this->validate_node(
					$working['root'],
					'$.root',
					1,
					true,
					$errors,
					$used_ids,
					$reserved_ids,
					$node_count,
					$generated,
					$for_write
				);
			}

			if ( self::MAX_NODES < $node_count ) {
				$this->add_error( $errors, 'max_nodes_exceeded', '$.root' );
			}

			if ( ! empty( $errors ) ) {
				$result['valid']      = false;
				$result['renderable'] = false;
				$result['errors']     = $errors;
				return $result;
			}

			$result['model']        = $working;
			$result['should_write'] = $for_write && $generated;
			return $result;
		}

		/**
		 * @param array<string,mixed>                   $model       Working model.
		 * @param array<int,array{code:string,path:string}> $errors Validation errors.
		 */
		private function validate_compatibility( array $model, array &$errors, bool $for_write ): void {
			if ( ! array_key_exists( 'compatibility', $model ) ) {
				return;
			}

			$compatibility = $model['compatibility'];
			if ( ! is_array( $compatibility ) || ! isset( $compatibility['schema_version'] ) || ! is_int( $compatibility['schema_version'] ) ) {
				$this->add_error( $errors, 'invalid_compatibility', '$.compatibility.schema_version' );
				return;
			}

			if ( 1 !== $compatibility['schema_version'] ) {
				if ( $for_write ) {
					$this->add_error( $errors, 'invalid_compatibility', '$.compatibility.schema_version' );
				}
				return;
			}

			$allowed_keys = array( 'schema_version', 'last_lossless_data' );
			foreach ( array_keys( $compatibility ) as $key ) {
				if ( ! in_array( $key, $allowed_keys, true ) ) {
					$this->add_error( $errors, 'invalid_compatibility', '$.compatibility.' . $key );
				}
			}

			if (
				array_key_exists( 'last_lossless_data', $compatibility )
				&& ! is_array( $compatibility['last_lossless_data'] )
			) {
				$this->add_error( $errors, 'invalid_compatibility', '$.compatibility.last_lossless_data' );
			}
		}

		/**
		 * @param array<string,mixed>                   $model     Working model.
		 * @param array<int,array{code:string,path:string}> $errors   Validation errors.
		 * @param array<string,bool>                    $used_ids  Seen identifiers.
		 * @param array<string,bool>                    $reserved_ids Supplied identifiers.
		 */
		private function validate_rule_id(
			array &$model,
			array &$errors,
			array &$used_ids,
			array $reserved_ids,
			bool &$generated,
			bool $for_write
		): void {
			if ( ! array_key_exists( 'rule_id', $model ) ) {
				if ( $for_write ) {
					$model['rule_id'] = $this->new_unique_uuid( $used_ids, $reserved_ids );
					$generated        = true;
				} else {
					$this->add_error( $errors, 'invalid_rule_id', '$.rule_id' );
				}
				return;
			}

			if ( ! is_string( $model['rule_id'] ) || ! $this->is_uuid_v4( $model['rule_id'] ) ) {
				$this->add_error( $errors, 'invalid_rule_id', '$.rule_id' );
				return;
			}

			$used_ids[ $model['rule_id'] ] = true;
		}

		/**
		 * @param array<string,mixed>                   $node       Working node.
		 * @param array<int,array{code:string,path:string}> $errors Validation errors.
		 * @param array<string,bool>                    $used_ids   Seen identifiers.
		 * @param array<string,bool>                    $reserved_ids Supplied identifiers.
		 */
		private function validate_node(
			array &$node,
			string $path,
			int $depth,
			bool $is_root,
			array &$errors,
			array &$used_ids,
			array $reserved_ids,
			int &$node_count,
			bool &$generated,
			bool $for_write
		): void {
			++$node_count;

			if ( self::MAX_DEPTH < $depth ) {
				$this->add_error( $errors, 'max_depth_exceeded', $path );
				return;
			}

			$this->validate_node_id( $node, $path, $errors, $used_ids, $reserved_ids, $generated, $for_write );
			$this->validate_metadata( $node, $path, $errors );

			$kind = isset( $node['kind'] ) && is_string( $node['kind'] ) ? $node['kind'] : '';
			if ( 'group' !== $kind && 'condition' !== $kind ) {
				$this->add_error( $errors, 'invalid_node_kind', $path . '.kind' );
				return;
			}

			if ( $is_root && 'group' !== $kind ) {
				$this->add_error( $errors, 'invalid_node_kind', $path . '.kind' );
				return;
			}

			if ( 'condition' === $kind ) {
				$this->validate_condition( $node, $path, $errors );
				return;
			}

			$this->validate_group(
				$node,
				$path,
				$depth,
				$is_root,
				$errors,
				$used_ids,
				$reserved_ids,
				$node_count,
				$generated,
				$for_write
			);
		}

		/**
		 * @param array<string,mixed>                   $node       Working node.
		 * @param array<int,array{code:string,path:string}> $errors Validation errors.
		 * @param array<string,bool>                    $used_ids   Seen identifiers.
		 * @param array<string,bool>                    $reserved_ids Supplied identifiers.
		 */
		private function validate_node_id(
			array &$node,
			string $path,
			array &$errors,
			array &$used_ids,
			array $reserved_ids,
			bool &$generated,
			bool $for_write
		): void {
			if ( ! array_key_exists( 'id', $node ) ) {
				if ( $for_write ) {
					$node['id'] = $this->new_unique_uuid( $used_ids, $reserved_ids );
					$generated  = true;
				} else {
					$this->add_error( $errors, 'invalid_node_id', $path . '.id' );
				}
				return;
			}

			if ( ! is_string( $node['id'] ) || ! $this->is_uuid_v4( $node['id'] ) || isset( $used_ids[ $node['id'] ] ) ) {
				$this->add_error( $errors, 'invalid_node_id', $path . '.id' );
				return;
			}

			$used_ids[ $node['id'] ] = true;
		}

		/**
		 * @param array<string,mixed>                   $node   Node.
		 * @param array<int,array{code:string,path:string}> $errors Validation errors.
		 */
		private function validate_metadata( array $node, string $path, array &$errors ): void {
			if ( ! array_key_exists( 'metadata', $node ) ) {
				return;
			}

			if ( ! is_array( $node['metadata'] ) ) {
				$this->add_error( $errors, 'invalid_metadata', $path . '.metadata' );
				return;
			}

			$bytes = $this->json_bytes( $node['metadata'] );
			if ( false === $bytes ) {
				$this->add_error( $errors, 'invalid_metadata', $path . '.metadata' );
			} elseif ( self::MAX_METADATA_BYTES < $bytes ) {
				$this->add_error( $errors, 'metadata_too_large', $path . '.metadata' );
			}
		}

		/**
		 * @param array<string,mixed>                   $node   Condition node.
		 * @param array<int,array{code:string,path:string}> $errors Validation errors.
		 */
		private function validate_condition( array $node, string $path, array &$errors ): void {
			$type = isset( $node['type'] ) && is_string( $node['type'] ) ? $node['type'] : '';
			if ( 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $type ) ) {
				$this->add_error( $errors, 'invalid_condition_type', $path . '.type' );
			}

			if ( ! isset( $node['operator'] ) || ! is_string( $node['operator'] ) || '' === $node['operator'] ) {
				$this->add_error( $errors, 'invalid_condition_operator', $path . '.operator' );
			}

			if ( ! array_key_exists( 'value', $node ) ) {
				$this->add_error( $errors, 'invalid_condition_value', $path . '.value' );
				return;
			}

			$bytes = $this->json_bytes( $node['value'] );
			if ( false === $bytes ) {
				$this->add_error( $errors, 'invalid_condition_value', $path . '.value' );
			} elseif ( self::MAX_VALUE_BYTES < $bytes ) {
				$this->add_error( $errors, 'condition_value_too_large', $path . '.value' );
			}
		}

		/**
		 * @param array<string,mixed>                   $node       Group node.
		 * @param array<int,array{code:string,path:string}> $errors Validation errors.
		 * @param array<string,bool>                    $used_ids   Seen identifiers.
		 * @param array<string,bool>                    $reserved_ids Supplied identifiers.
		 */
		private function validate_group(
			array &$node,
			string $path,
			int $depth,
			bool $is_root,
			array &$errors,
			array &$used_ids,
			array $reserved_ids,
			int &$node_count,
			bool &$generated,
			bool $for_write
		): void {
			$operator = isset( $node['operator'] ) && is_string( $node['operator'] ) ? $node['operator'] : '';
			if ( 'and' !== $operator && 'or' !== $operator ) {
				$this->add_error( $errors, 'invalid_group_operator', $path . '.operator' );
			}

			if ( ! isset( $node['children'] ) || ! is_array( $node['children'] ) || ! $this->is_list( $node['children'] ) ) {
				$this->add_error( $errors, 'invalid_node_kind', $path . '.children' );
				return;
			}

			if ( ! $is_root && empty( $node['children'] ) ) {
				$this->add_error( $errors, 'empty_non_root_group', $path . '.children' );
			}

			if ( self::MAX_CHILDREN < count( $node['children'] ) ) {
				$this->add_error( $errors, 'max_children_exceeded', $path . '.children' );
			}

			foreach ( $node['children'] as $index => &$child ) {
				$child_path = $path . '.children[' . $index . ']';
				if ( ! is_array( $child ) ) {
					$this->add_error( $errors, 'invalid_node_kind', $child_path . '.kind' );
					continue;
				}

				$this->validate_node(
					$child,
					$child_path,
					$depth + 1,
					false,
					$errors,
					$used_ids,
					$reserved_ids,
					$node_count,
					$generated,
					$for_write
				);
			}
			unset( $child );
		}

		private function is_uuid_v4( string $uuid ): bool {
			return 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid );
		}

		/**
		 * @param array<string,bool> $used_ids     Seen identifiers.
		 * @param array<string,bool> $reserved_ids Supplied identifiers.
		 */
		private function new_unique_uuid( array &$used_ids, array $reserved_ids ): string {
			for ( $attempt = 0; $attempt < 32; ++$attempt ) {
				$uuid = null !== $this->uuid_factory
					? call_user_func( $this->uuid_factory )
					: $this->generate_uuid_v4();

				if (
					is_string( $uuid )
					&& $this->is_uuid_v4( $uuid )
					&& ! isset( $used_ids[ $uuid ] )
					&& ! isset( $reserved_ids[ $uuid ] )
				) {
					$used_ids[ $uuid ] = true;
					return $uuid;
				}
			}

			throw new RuntimeException( 'Unable to generate a unique UUIDv4.' );
		}

		/**
		 * Collect supplied identifiers before generation so a factory cannot claim
		 * an identifier that belongs to a later node.
		 *
		 * @param array<string,mixed> $model Candidate model.
		 * @return array<string,bool>
		 */
		private function collect_reserved_ids( array $model ): array {
			$reserved = array();
			if ( isset( $model['rule_id'] ) && is_string( $model['rule_id'] ) && $this->is_uuid_v4( $model['rule_id'] ) ) {
				$reserved[ $model['rule_id'] ] = true;
			}

			$pending = isset( $model['root'] ) && is_array( $model['root'] )
				? array( $model['root'] )
				: array();

			while ( ! empty( $pending ) ) {
				$node = array_pop( $pending );
				if ( ! is_array( $node ) ) {
					continue;
				}

				if ( isset( $node['id'] ) && is_string( $node['id'] ) && $this->is_uuid_v4( $node['id'] ) ) {
					$reserved[ $node['id'] ] = true;
				}

				if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
					foreach ( $node['children'] as $child ) {
						if ( is_array( $child ) ) {
							$pending[] = $child;
						}
					}
				}
			}

			return $reserved;
		}

		private function generate_uuid_v4(): string {
			$bytes    = random_bytes( 16 );
			$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
			$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
			$hex      = bin2hex( $bytes );

			return substr( $hex, 0, 8 ) . '-'
				. substr( $hex, 8, 4 ) . '-'
				. substr( $hex, 12, 4 ) . '-'
				. substr( $hex, 16, 4 ) . '-'
				. substr( $hex, 20, 12 );
		}

		/**
		 * @param mixed $value Value to encode.
		 * @return string|false
		 */
		private function encode_json( $value ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Dependency-free deterministic contract.
			return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		/**
		 * @param mixed $value Value to measure.
		 * @return int|false
		 */
		private function json_bytes( $value ) {
			$json = $this->encode_json( $value );
			return false === $json ? false : strlen( $json );
		}

		/**
		 * @param mixed $value        Semantic candidate.
		 * @param bool  $is_top_level Whether this is the model envelope.
		 * @return mixed
		 */
		private function without_non_semantic_fields( $value, bool $is_top_level = true ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}

			$result = array();
			foreach ( $value as $key => $item ) {
				if ( $is_top_level && 'compatibility' === $key ) {
					continue;
				}

				$result[ $key ] = $is_top_level && 'root' === $key && is_array( $item )
					? $this->without_node_metadata( $item )
					: $this->without_non_semantic_fields( $item, false );
			}
			return $result;
		}

		/**
		 * Remove metadata only from nodes reached through the canonical tree.
		 * Canonical-looking arrays inside condition values remain semantic.
		 *
		 * @param array<string,mixed> $node Canonical node.
		 * @return array<string,mixed>
		 */
		private function without_node_metadata( array $node ): array {
			$result = array();
			foreach ( $node as $key => $item ) {
				if ( 'metadata' === $key ) {
					continue;
				}

				if ( 'group' === ( $node['kind'] ?? null ) && 'children' === $key && is_array( $item ) ) {
					$children = array();
					foreach ( $item as $child_key => $child ) {
						$children[ $child_key ] = is_array( $child )
							? $this->without_node_metadata( $child )
							: $child;
					}
					$result[ $key ] = $children;
					continue;
				}

				$result[ $key ] = $this->without_non_semantic_fields( $item, false );
			}

			return $result;
		}

		/**
		 * @param mixed $value Semantic value.
		 * @return mixed
		 */
		private function sort_object_keys( $value ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}

			if ( $this->is_list( $value ) ) {
				return array_map( array( $this, 'sort_object_keys' ), $value );
			}

			ksort( $value, SORT_STRING );
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->sort_object_keys( $item );
			}
			return $value;
		}

		/** @param array<mixed> $value */
		private function is_list( array $value ): bool {
			return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
		}

		/**
		 * @param mixed $model Original model.
		 * @return array<string,mixed>
		 */
		private function result( $model ): array {
			return array(
				'valid'        => true,
				'supported'    => true,
				'renderable'   => true,
				'should_write' => false,
				'model'        => $model,
				'errors'       => array(),
			);
		}

		/**
		 * @param array<string,mixed> $result Current result.
		 * @return array<string,mixed>
		 */
		private function with_error( array $result, string $code, string $path ): array {
			$result['valid']      = false;
			$result['renderable'] = false;
			$result['errors'][]   = $this->error( $code, $path );
			return $result;
		}

		/** @return array{code:string,path:string} */
		private function error( string $code, string $path ): array {
			return array(
				'code' => $code,
				'path' => $path,
			);
		}

		/**
		 * @param array<int,array{code:string,path:string}> $errors Validation errors.
		 */
		private function add_error( array &$errors, string $code, string $path ): void {
			$error = $this->error( $code, $path );
			if ( ! in_array( $error, $errors, true ) ) {
				$errors[] = $error;
			}
		}
	}
}
