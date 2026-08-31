<?php
/**
 * Read-only legacy OR-of-AND normalization and lossless reverse projection.
 *
 * Adapter orchestration, downgrade guards and persistence belong to the
 * compatibility facade. This class only preserves and classifies data.
 */

if ( ! class_exists( 'BeRocket_Growth_Suite_Rule_Normalizer' ) ) {
	class BeRocket_Growth_Suite_Rule_Normalizer {
		/**
		 * Project authoritative legacy data to an in-memory canonical root.
		 *
		 * @param mixed $data     Legacy `br_labels[data]` value.
		 * @param mixed $label_id Label ID used only for ephemeral diagnostics.
		 * @return array<string,mixed>
		 */
		public function from_legacy( $data, $label_id = 0 ): array {
			$label_id = (int) $label_id;
			$result   = array(
				'valid'        => true,
				'lossless'     => true,
				'should_write' => false,
				'source_data'  => $data,
				'root'         => $this->empty_root( $label_id ),
				'errors'       => array(),
			);

			if ( ! is_array( $data ) ) {
				$result['valid']    = false;
				$result['lossless'] = false;
				$result['errors'][] = $this->error( 'legacy_data_not_array', '$' );
				return $result;
			}

			$group_position = 0;
			foreach ( $data as $group_key => $legacy_group ) {
				$group_path = '$' . $this->legacy_key_path( $group_key );
				$group      = array(
					'id'       => 'legacy:' . $label_id . ':g:' . $group_position,
					'kind'     => 'group',
					'operator' => 'and',
					'children' => array(),
					'metadata' => array( 'legacy_key' => $group_key ),
				);

				if ( ! is_array( $legacy_group ) ) {
					$group['children'][] = $this->invalid_leaf(
						$label_id,
						$group_position,
						0,
						0,
						$legacy_group
					);
					$this->add_error( $result['errors'], 'legacy_group_not_array', $group_path );
				} elseif ( array() === $legacy_group ) {
					$this->add_error( $result['errors'], 'empty_legacy_group', $group_path );
				} else {
					$condition_position = 0;
					foreach ( $legacy_group as $condition_key => $legacy_condition ) {
						$condition_path = $group_path . $this->legacy_key_path( $condition_key );
						if ( ! is_array( $legacy_condition ) ) {
							$group['children'][] = $this->invalid_leaf(
								$label_id,
								$group_position,
								$condition_position,
								$condition_key,
								$legacy_condition
							);
							$this->add_error( $result['errors'], 'legacy_condition_not_array', $condition_path );
						} elseif ( ! isset( $legacy_condition['type'] ) || ! is_scalar( $legacy_condition['type'] ) ) {
							$group['children'][] = $this->invalid_leaf(
								$label_id,
								$group_position,
								$condition_position,
								$condition_key,
								$legacy_condition
							);
							$this->add_error( $result['errors'], 'legacy_condition_type_invalid', $condition_path . '.type' );
						} else {
							$group['children'][] = array(
								'id'       => 'legacy:' . $label_id . ':g:' . $group_position . ':c:' . $condition_position,
								'kind'     => 'condition',
								'type'     => (string) $legacy_condition['type'],
								'payload'  => $legacy_condition,
								'metadata' => array( 'legacy_key' => $condition_key ),
							);
						}
						++$condition_position;
					}
				}

				$result['root']['children'][] = $group;
				++$group_position;
			}

			if ( ! empty( $result['errors'] ) ) {
				$result['valid']    = false;
				$result['lossless'] = false;
			}

			return $result;
		}

		/**
		 * Reverse one direct legacy projection without adapters or guard writes.
		 *
		 * @param mixed $root Canonical root candidate.
		 * @return array<string,mixed>
		 */
		public function to_legacy( $root ): array {
			$errors = array();
			$data   = array();

			if (
				! is_array( $root )
				|| 'group' !== ( $root['kind'] ?? null )
				|| 'or' !== ( $root['operator'] ?? null )
				|| ! isset( $root['children'] )
				|| ! is_array( $root['children'] )
			) {
				return $this->failed_projection( 'legacy_projection_not_lossless', '$.root' );
			}

			foreach ( array_values( $root['children'] ) as $group_position => $group ) {
				$group_path = '$.root.children[' . $group_position . ']';
				if (
					! is_array( $group )
					|| 'group' !== ( $group['kind'] ?? null )
					|| 'and' !== ( $group['operator'] ?? null )
					|| ! isset( $group['children'] )
					|| ! is_array( $group['children'] )
					|| array() === $group['children']
				) {
					$this->add_error( $errors, 'legacy_projection_not_lossless', $group_path );
					continue;
				}

				$group_key_result = $this->projection_key( $group, $group_position, $group_path );
				if ( ! $group_key_result['valid'] ) {
					$this->add_error( $errors, 'legacy_projection_not_lossless', $group_key_result['path'] );
					continue;
				}

				$group_key = $group_key_result['key'];
				if ( array_key_exists( $group_key, $data ) ) {
					$this->add_error( $errors, 'legacy_key_collision', $group_key_result['path'] );
					continue;
				}

				$legacy_group = array();
				foreach ( array_values( $group['children'] ) as $condition_position => $condition ) {
					$condition_path = $group_path . '.children[' . $condition_position . ']';
					if ( ! is_array( $condition ) || 'condition' !== ( $condition['kind'] ?? null ) ) {
						$this->add_error( $errors, 'legacy_projection_not_lossless', $condition_path );
						continue;
					}

					if ( ! array_key_exists( 'payload', $condition ) ) {
						$this->add_error( $errors, 'condition_adapter_required', $condition_path );
						continue;
					}

					$payload = $condition['payload'];
					if ( ! is_array( $payload ) ) {
						$this->add_error( $errors, 'legacy_projection_not_lossless', $condition_path );
						continue;
					}

					if (
						! isset( $condition['type'] )
						|| ! is_string( $condition['type'] )
						|| ! isset( $payload['type'] )
						|| ! is_scalar( $payload['type'] )
						|| (string) $payload['type'] !== $condition['type']
					) {
						$this->add_error( $errors, 'legacy_projection_not_lossless', $condition_path . '.type' );
						continue;
					}

					$condition_key_result = $this->projection_key( $condition, $condition_position, $condition_path );
					if ( ! $condition_key_result['valid'] ) {
						$this->add_error( $errors, 'legacy_projection_not_lossless', $condition_key_result['path'] );
						continue;
					}

					$condition_key = $condition_key_result['key'];
					if ( array_key_exists( $condition_key, $legacy_group ) ) {
						$this->add_error( $errors, 'legacy_key_collision', $condition_key_result['path'] );
						continue;
					}

					$legacy_group[ $condition_key ] = $payload;
				}

				$data[ $group_key ] = $legacy_group;
			}

			if ( ! empty( $errors ) ) {
				return array(
					'lossless' => false,
					'data'     => null,
					'errors'   => $errors,
				);
			}

			return array(
				'lossless' => true,
				'data'     => $data,
				'errors'   => array(),
			);
		}

		/** @return array<string,mixed> */
		private function empty_root( int $label_id ): array {
			return array(
				'id'            => 'legacy:' . $label_id . ':root',
				'kind'          => 'group',
				'operator'      => 'or',
				'children'      => array(),
				'ephemeral_ids' => true,
				'empty_matches' => true,
			);
		}

		/**
		 * @param int|string $legacy_key Original legacy array key.
		 * @param mixed      $payload    Preserved malformed payload.
		 * @return array<string,mixed>
		 */
		private function invalid_leaf(
			int $label_id,
			int $group_position,
			int $condition_position,
			$legacy_key,
			$payload
		): array {
			return array(
				'id'          => 'legacy:' . $label_id . ':g:' . $group_position . ':c:' . $condition_position,
				'kind'        => 'condition',
				'type'        => '__invalid_legacy_condition',
				'payload'     => $payload,
				'unavailable' => true,
				'reason_code' => 'condition_unavailable',
				'metadata'    => array( 'legacy_key' => $legacy_key ),
			);
		}

		/**
		 * Resolve a preserved source key or a deterministic positional fallback.
		 *
		 * @param array<string,mixed> $node     Canonical node.
		 * @param int                 $fallback Positional fallback.
		 * @return array{valid:bool,key:int|string,path:string}
		 */
		private function projection_key( array $node, int $fallback, string $node_path ): array {
			$path = $node_path . '.metadata.legacy_key';
			if ( ! array_key_exists( 'metadata', $node ) ) {
				return array(
					'valid' => true,
					'key'   => $fallback,
					'path'  => $path,
				);
			}

			if ( ! is_array( $node['metadata'] ) ) {
				return array(
					'valid' => false,
					'key'   => $fallback,
					'path'  => $path,
				);
			}

			if ( ! array_key_exists( 'legacy_key', $node['metadata'] ) ) {
				return array(
					'valid' => true,
					'key'   => $fallback,
					'path'  => $path,
				);
			}

			$key = $node['metadata']['legacy_key'];
			if ( ! is_int( $key ) && ! is_string( $key ) ) {
				return array(
					'valid' => false,
					'key'   => $fallback,
					'path'  => $path,
				);
			}

			return array(
				'valid' => true,
				'key'   => $key,
				'path'  => $path,
			);
		}

		/**
		 * @param int|string $key Legacy PHP array key.
		 */
		private function legacy_key_path( $key ): string {
			if ( is_int( $key ) ) {
				return '[' . $key . ']';
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Stable dependency-free JSONPath fragment.
			$encoded = json_encode( (string) $key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			return '[' . ( false === $encoded ? '""' : $encoded ) . ']';
		}

		/**
		 * @return array<string,mixed>
		 */
		private function failed_projection( string $code, string $path ): array {
			return array(
				'lossless' => false,
				'data'     => null,
				'errors'   => array( $this->error( $code, $path ) ),
			);
		}

		/** @return array{code:string,path:string} */
		private function error( string $code, string $path ): array {
			return array(
				'code' => $code,
				'path' => $path,
			);
		}

		/**
		 * @param array<int,array{code:string,path:string}> $errors Current errors.
		 */
		private function add_error( array &$errors, string $code, string $path ): void {
			$error = $this->error( $code, $path );
			if ( ! in_array( $error, $errors, true ) ) {
				$errors[] = $error;
			}
		}
	}
}
