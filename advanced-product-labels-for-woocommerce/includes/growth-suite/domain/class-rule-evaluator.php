<?php
/**
 * Canonical recursive rule evaluator shared by storefront and simulator.
 *
 * Condition-specific business logic belongs to registered adapters. Read-only
 * legacy projections retain the verified WordPress condition-filter boundary;
 * unavailable schema-v2 conditions remain opaque and fail closed.
 */

require_once __DIR__ . '/class-rule-model.php';

if ( ! class_exists( 'BeRocket_Growth_Suite_Rule_Evaluator' ) ) {
	class BeRocket_Growth_Suite_Rule_Evaluator {
		/** @var array<string,object> */
		private $condition_adapters = array();

		/** @var BeRocket_Growth_Suite_Rule_Model */
		private $model;

		public function __construct( ?BeRocket_Growth_Suite_Rule_Model $model = null ) {
			$this->model = null === $model ? new BeRocket_Growth_Suite_Rule_Model() : $model;
		}

		/**
		 * Register one adapter for a stable condition type.
		 *
		 * @param mixed $adapter Adapter exposing type_id() and evaluate().
		 */
		public function register_condition_adapter( $adapter ): bool {
			if (
				! is_object( $adapter )
				|| ! is_callable( array( $adapter, 'type_id' ) )
				|| ! is_callable( array( $adapter, 'evaluate' ) )
			) {
				return false;
			}

			try {
				$type = call_user_func( array( $adapter, 'type_id' ) );
			} catch ( Throwable $error ) {
				unset( $error );
				return false;
			}

			if ( ! is_string( $type ) || ! $this->is_stable_type( $type ) || isset( $this->condition_adapters[ $type ] ) ) {
				return false;
			}

			$this->condition_adapters[ $type ] = $adapter;
			return true;
		}

		public function has_condition_adapter( string $type ): bool {
			return isset( $this->condition_adapters[ $type ] );
		}

		/**
		 * Evaluate one schema-v2 condition through its registered adapter.
		 *
		 * @param array<string,mixed> $condition Canonical condition node.
		 * @param array<string,mixed> $context   Explicit immutable context.
		 * @return array<string,mixed>
		 */
		public function evaluate_condition( array $condition, array $context ): array {
			$result = $this->evaluate_registered_condition( $condition, $context );

			return null === $result
				? $this->unavailable_condition_result( $condition )
				: $result;
		}

		/**
		 * Evaluate a canonical rule envelope/root against one canonical context.
		 *
		 * @param array<string,mixed> $rule    Canonical rule/root.
		 * @param array<string,mixed> $context Explicit immutable context.
		 * @return array<string,mixed>
		 */
		public function evaluate( array $rule, array $context ): array {
			$root        = isset( $rule['root'] ) && is_array( $rule['root'] ) ? $rule['root'] : $rule;
			$legacy_mode = $this->is_legacy_projection( $rule, $root );
			$tree        = $this->evaluate_node( $root, $context, true, $legacy_mode );

			return array(
				'matched'       => true === ( $tree['matched'] ?? false ),
				'model_version' => $legacy_mode ? 1 : 2,
				'rule_hash'     => $this->rule_hash( $rule, $root, $legacy_mode ),
				'root'          => $tree,
			);
		}

		/**
		 * @param array<string,mixed> $node        Canonical node.
		 * @param array<string,mixed> $context     Explicit immutable context.
		 * @param bool                $is_root     Whether this node is the rule root.
		 * @param bool                $legacy_mode Whether source authority is legacy.
		 * @return array<string,mixed>
		 */
		private function evaluate_node( array $node, array $context, bool $is_root, bool $legacy_mode ): array {
			if ( isset( $node['kind'] ) && 'condition' === $node['kind'] ) {
				return $legacy_mode
					? $this->evaluate_legacy_condition( $node, $context )
					: $this->evaluate_condition( $node, $context );
			}

			$node_id  = isset( $node['id'] ) && is_string( $node['id'] ) ? $node['id'] : '';
			$operator = isset( $node['operator'] ) && is_string( $node['operator'] ) ? $node['operator'] : '';
			$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();

			if ( 'group' !== ( $node['kind'] ?? '' ) || ( 'and' !== $operator && 'or' !== $operator ) ) {
				return array(
					'matched'     => false,
					'node_id'     => $node_id,
					'operator'    => $operator,
					'reason_code' => 'invalid_rule',
					'parameters'  => array(),
					'children'    => array(),
				);
			}

			if ( empty( $children ) ) {
				$result = array(
					'matched'  => $is_root,
					'node_id'  => $node_id,
					'operator' => $operator,
					'children' => array(),
				);

				if ( ! $is_root ) {
					$result['reason_code'] = 'invalid_rule';
					$result['parameters']  = array();
				}

				return $result;
			}

			$matched         = 'and' === $operator;
			$short_circuited = false;
			$reason_children = array();

			foreach ( $children as $child ) {
				if ( $short_circuited ) {
					$reason_children[] = is_array( $child )
						? $this->short_circuit_placeholder( $child )
						: $this->short_circuit_placeholder( array() );
					continue;
				}

				$child_result      = is_array( $child )
					? $this->evaluate_node( $child, $context, false, $legacy_mode )
					: array(
						'matched'     => false,
						'node_id'     => '',
						'reason_code' => 'invalid_rule',
						'parameters'  => array(),
					);
				$reason_children[] = $child_result;

				if ( 'and' === $operator && ! $child_result['matched'] ) {
					$matched         = false;
					$short_circuited = true;
				} elseif ( 'or' === $operator && $child_result['matched'] ) {
					$matched         = true;
					$short_circuited = true;
				}
			}

			return array(
				'matched'  => $matched,
				'node_id'  => $node_id,
				'operator' => $operator,
				'children' => $reason_children,
			);
		}

		/**
		 * Evaluate a legacy-projected leaf without broadening the v2 fallback.
		 *
		 * A registered saved-leaf adapter owns exactly one evaluation. Otherwise
		 * the verified WordPress filter remains the only business-logic boundary.
		 *
		 * @param array<string,mixed> $condition Legacy-projected condition.
		 * @param array<string,mixed> $context   Canonical evaluation context.
		 * @return array<string,mixed>
		 */
		private function evaluate_legacy_condition( array $condition, array $context ): array {
			$type = isset( $condition['type'] ) && is_string( $condition['type'] ) ? $condition['type'] : '';
			if ( $this->is_stable_type( $type ) && $this->has_condition_adapter( $type ) ) {
				return $this->evaluate_condition( $condition, $context );
			}

			if (
				! $this->is_stable_type( $type )
				|| ! isset( $condition['payload'] )
				|| ! is_array( $condition['payload'] )
				|| ! function_exists( 'apply_filters' )
			) {
				return $this->unavailable_condition_result( $condition );
			}

			try {
				$matched = (bool) apply_filters(
					'berocket_advanced_label_editor_check_type_' . $type,
					false,
					$condition['payload'],
					$context
				);
			} catch ( Throwable $error ) {
				unset( $error );
				return $this->unavailable_condition_result( $condition );
			}

			if ( ! $matched ) {
				return $this->unavailable_condition_result( $condition );
			}

			return array(
				'matched'        => true,
				'node_id'        => $this->node_id( $condition ),
				'condition_type' => $type,
				'reason_code'    => 'legacy_condition_matched',
				'parameters'     => array( 'condition_type' => $type ),
			);
		}

		/**
		 * Evaluate a condition only through a registered adapter.
		 *
		 * @param array<string,mixed> $condition Canonical/legacy condition.
		 * @param array<string,mixed> $context   Canonical context.
		 * @return array<string,mixed>|null
		 */
		private function evaluate_registered_condition( array $condition, array $context ): ?array {
			$type = isset( $condition['type'] ) && is_string( $condition['type'] ) ? $condition['type'] : '';
			if ( ! $this->is_stable_type( $type ) || ! isset( $this->condition_adapters[ $type ] ) ) {
				return null;
			}

			try {
				$adapter_result = $this->evaluate_with_adapter(
					$this->condition_adapters[ $type ],
					$condition,
					$context
				);
			} catch ( Throwable $error ) {
				unset( $error );
				return null;
			}

			if (
				! is_array( $adapter_result )
				|| ! isset( $adapter_result['reason_code'] )
				|| ! is_string( $adapter_result['reason_code'] )
			) {
				return null;
			}

			return array(
				'matched'        => isset( $adapter_result['matched'] ) && true === $adapter_result['matched'],
				'node_id'        => $this->node_id( $condition ),
				'condition_type' => $type,
				'reason_code'    => $adapter_result['reason_code'],
				'parameters'     => isset( $adapter_result['parameters'] ) && is_array( $adapter_result['parameters'] )
					? $adapter_result['parameters']
					: array(),
			);
		}

		/**
		 * Evaluate either a canonical value or an adapter-owned saved leaf.
		 *
		 * @param object              $adapter   Registered condition adapter.
		 * @param array<string,mixed> $condition Source condition node.
		 * @param array<string,mixed> $context   Explicit immutable context.
		 * @return mixed
		 */
		private function evaluate_with_adapter( $adapter, array $condition, array $context ) {
			if ( array_key_exists( 'payload', $condition ) ) {
				if ( ! is_callable( array( $adapter, 'inspect_saved_leaf' ) ) ) {
					return null;
				}

				$inspection = call_user_func( array( $adapter, 'inspect_saved_leaf' ), $condition['payload'] );
				if ( ! is_array( $inspection ) || ! isset( $inspection['supported'] ) || true !== $inspection['supported'] ) {
					return null;
				}

				if ( is_callable( array( $adapter, 'evaluate_saved_leaf' ) ) ) {
					return call_user_func(
						array( $adapter, 'evaluate_saved_leaf' ),
						$condition['payload'],
						$context
					);
				}

				if (
					true === ( $inspection['valid'] ?? false )
					&& isset( $inspection['condition'] )
					&& is_array( $inspection['condition'] )
					&& array_key_exists( 'value', $inspection['condition'] )
				) {
					return call_user_func(
						array( $adapter, 'evaluate' ),
						$inspection['condition']['value'],
						$context
					);
				}

				return null;
			}

			$value = array_key_exists( 'value', $condition ) ? $condition['value'] : array();

			return call_user_func( array( $adapter, 'evaluate' ), $value, $context );
		}

		/**
		 * Build a complete structural result for one skipped subtree.
		 *
		 * @param array<string,mixed> $node Source node.
		 * @return array<string,mixed>
		 */
		private function short_circuit_placeholder( array $node ): array {
			$result = array(
				'node_id'     => $this->node_id( $node ),
				'reason_code' => 'not_evaluated_short_circuit',
				'parameters'  => array(),
			);

			if ( 'group' === ( $node['kind'] ?? null ) ) {
				$result['operator'] = isset( $node['operator'] ) && is_string( $node['operator'] )
					? $node['operator']
					: '';
				$result['children'] = array();
				if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
					foreach ( $node['children'] as $child ) {
						$result['children'][] = is_array( $child )
							? $this->short_circuit_placeholder( $child )
							: $this->short_circuit_placeholder( array() );
					}
				}
			} elseif ( isset( $node['type'] ) && is_string( $node['type'] ) ) {
				$result['condition_type'] = $node['type'];
			}

			return $result;
		}

		/**
		 * @param array<string,mixed> $condition Source condition node.
		 * @return array<string,mixed>
		 */
		private function unavailable_condition_result( array $condition ): array {
			$type       = isset( $condition['type'] ) && is_string( $condition['type'] ) ? $condition['type'] : '';
			$parameters = array( 'condition_type' => $type );
			$version    = $this->source_schema_version( $condition );

			if ( null !== $version ) {
				$parameters['source_schema_version'] = $version;
			}

			return array(
				'matched'        => false,
				'node_id'        => $this->node_id( $condition ),
				'condition_type' => $type,
				'reason_code'    => 'condition_unavailable',
				'parameters'     => $parameters,
			);
		}

		/** @param array<string,mixed> $node Source node. */
		private function node_id( array $node ): string {
			return isset( $node['id'] ) && is_string( $node['id'] ) ? $node['id'] : '';
		}

		/**
		 * @param array<string,mixed> $condition Source condition node.
		 */
		private function source_schema_version( array $condition ): ?int {
			if (
				isset( $condition['metadata'] )
				&& is_array( $condition['metadata'] )
				&& isset( $condition['metadata']['source_schema_version'] )
				&& is_int( $condition['metadata']['source_schema_version'] )
			) {
				return $condition['metadata']['source_schema_version'];
			}

			if (
				isset( $condition['payload'] )
				&& is_array( $condition['payload'] )
				&& isset( $condition['payload']['schema_version'] )
				&& is_int( $condition['payload']['schema_version'] )
			) {
				return $condition['payload']['schema_version'];
			}

			return isset( $condition['schema_version'] ) && is_int( $condition['schema_version'] )
				? $condition['schema_version']
				: null;
		}

		/**
		 * @param array<string,mixed> $rule Root/rule envelope.
		 * @param array<string,mixed> $root Resolved root.
		 */
		private function is_legacy_projection( array $rule, array $root ): bool {
			if ( isset( $rule['schema_version'] ) || isset( $rule['rule_id'] ) ) {
				return false;
			}

			if ( true === ( $root['ephemeral_ids'] ?? false ) ) {
				return true;
			}

			$id = isset( $root['id'] ) && is_string( $root['id'] ) ? $root['id'] : '';
			return 0 === strpos( $id, 'legacy:' );
		}

		/**
		 * Produce a deterministic semantic hash for v2 and read-only legacy input.
		 *
		 * @param array<string,mixed> $rule        Rule/root input.
		 * @param array<string,mixed> $root        Resolved root.
		 * @param bool                $legacy_mode Whether source authority is legacy.
		 */
		private function rule_hash( array $rule, array $root, bool $legacy_mode ): string {
			if ( ! $legacy_mode && isset( $rule['schema_version'], $rule['rule_id'], $rule['root'] ) ) {
				try {
					return $this->model->semantic_hash( $rule );
				} catch ( Throwable $error ) {
					unset( $error );
				}
			}

			$semantic = $legacy_mode
				? array(
					'model_version' => 1,
					'root'          => $this->without_non_semantic_fields( $root, true ),
				)
				: array(
					'model_version' => 2,
					'root'          => $this->without_non_semantic_fields( $root, false ),
				);
			$semantic = $this->sort_object_keys( $semantic );
			$json     = $this->encode_json( $semantic );

			return false === $json
				? hash( 'sha256', 'invalid_unencodable_rule' )
				: hash( 'sha256', $json );
		}

		/**
		 * @param mixed $value       Semantic value.
		 * @param bool  $legacy_mode Whether ephemeral IDs must be excluded.
		 * @return mixed
		 */
		private function without_non_semantic_fields( $value, bool $legacy_mode ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}

			$result = array();
			foreach ( $value as $key => $item ) {
				if ( 'metadata' === $key || 'compatibility' === $key ) {
					continue;
				}

				if ( $legacy_mode && in_array( $key, array( 'id', 'ephemeral_ids' ), true ) ) {
					continue;
				}

				$result[ $key ] = $this->without_non_semantic_fields( $item, $legacy_mode );
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
		 * @param mixed $value JSON value.
		 * @return string|false
		 */
		private function encode_json( $value ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Dependency-free deterministic contract.
			return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		private function is_stable_type( string $type ): bool {
			return 1 === preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $type );
		}
	}
}
