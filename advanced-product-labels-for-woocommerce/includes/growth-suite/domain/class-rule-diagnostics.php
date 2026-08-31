<?php
/**
 * Read-only static and catalog-backed diagnostics for canonical label rules.
 */

require_once __DIR__ . '/class-rule-model.php';

if ( ! class_exists( 'BeRocket_Growth_Suite_Rule_Diagnostics' ) ) {
	class BeRocket_Growth_Suite_Rule_Diagnostics {
		/** @var BeRocket_Growth_Suite_Rule_Model */
		private $model;

		/** @var array<string,object> */
		private $static_analyzers = array();

		public function __construct( BeRocket_Growth_Suite_Rule_Model $model ) {
			$this->model = $model;
		}

		/**
		 * Register one sound static analyzer for a stable condition type.
		 *
		 * @param mixed $analyzer Analyzer extension.
		 */
		public function register_static_analyzer( $analyzer ): bool {
			if (
				! is_object( $analyzer )
				|| ! is_callable( array( $analyzer, 'type_id' ) )
				|| ! is_callable( array( $analyzer, 'semantic_fingerprint' ) )
				|| ! is_callable( array( $analyzer, 'compare' ) )
				|| ! is_callable( array( $analyzer, 'constant_result' ) )
			) {
				return false;
			}

			try {
				$type = call_user_func( array( $analyzer, 'type_id' ) );
			} catch ( Throwable $error ) {
				unset( $error );
				return false;
			}

			if ( ! is_string( $type ) || ! $this->is_stable_type( $type ) || isset( $this->static_analyzers[ $type ] ) ) {
				return false;
			}

			$this->static_analyzers[ $type ] = $analyzer;
			return true;
		}

		/**
		 * Analyze one canonical schema-v2 AST without evaluating a runtime context.
		 *
		 * @param array<string,mixed> $model Canonical rule model.
		 * @return array<string,mixed>
		 */
		public function analyze_rule( array $model ): array {
			$diagnostics = array();
			$seen        = array();
			$complete    = true;
			$rule_hash   = null;

			try {
				$rule_hash = $this->model->semantic_hash( $model );
			} catch ( Throwable $error ) {
				unset( $error );
				$complete = false;
			}

			$rule_id = isset( $model['rule_id'] ) && is_string( $model['rule_id'] ) ? $model['rule_id'] : null;
			$root    = isset( $model['root'] ) && is_array( $model['root'] ) ? $model['root'] : null;
			if ( null === $root ) {
				$complete = false;
			} else {
				$this->analyze_group( $root, $rule_id, $diagnostics, $seen, $complete );
			}

			return array(
				'rule_hash'          => $rule_hash,
				'snapshot_hash'      => null,
				'coverage_certainty' => $complete ? 'confirmed' : 'unknown',
				'diagnostics'        => $diagnostics,
			);
		}

		/**
		 * Analyze observed catalog matches within one bounded snapshot/context.
		 *
		 * @param array<int,array<string,mixed>> $matches  Observed match rows.
		 * @param array<string,mixed>            $snapshot Snapshot evidence.
		 * @return array<string,mixed>
		 */
		public function analyze_catalog( array $matches, array $snapshot ): array {
			$certainty     = $this->catalog_certainty( $snapshot );
			$snapshot_hash = $this->valid_hash( $snapshot['snapshot_hash'] ?? null )
				? $snapshot['snapshot_hash']
				: null;
			$context_hash  = $this->valid_hash( $snapshot['context_hash'] ?? null )
				? $snapshot['context_hash']
				: null;
			$groups        = array();

			foreach ( $matches as $row ) {
				if ( ! is_array( $row ) ) {
					$certainty = 'unknown';
					continue;
				}

				if ( true !== ( $row['matched'] ?? false ) ) {
					continue;
				}

				if ( ! $this->valid_match_row( $row ) || null === $context_hash || $context_hash !== $row['context_hash'] ) {
					$certainty = 'unknown';
					continue;
				}

				$key = $this->catalog_scope_key( $row );
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array();
				}
				$groups[ $key ][] = $row;
			}

			ksort( $groups, SORT_STRING );
			$duplicates = array();
			$overlaps   = array();
			$priorities = array();
			foreach ( $groups as $rows ) {
				$duplicates = array_merge(
					$duplicates,
					$this->catalog_duplicate_matches( $rows, $certainty, $snapshot_hash )
				);
				$overlaps   = array_merge(
					$overlaps,
					$this->catalog_overlaps( $rows, $certainty, $snapshot_hash )
				);
				$priorities = array_merge(
					$priorities,
					$this->catalog_priority_collisions( $rows, $certainty, $snapshot_hash )
				);
			}

			return array(
				'rule_hash'          => null,
				'snapshot_hash'      => $snapshot_hash,
				'coverage_certainty' => $certainty,
				'diagnostics'        => array_merge( $duplicates, $overlaps, $priorities ),
			);
		}

		/**
		 * @param array<string,mixed>              $group       Canonical group.
		 * @param string|null                      $rule_id     Persisted rule ID.
		 * @param array<int,array<string,mixed>>   $diagnostics Collected diagnostics.
		 * @param array<string,bool>                $seen        De-duplication map.
		 * @param bool                              $complete    Static coverage flag.
		 */
		private function analyze_group(
			array $group,
			?string $rule_id,
			array &$diagnostics,
			array &$seen,
			bool &$complete
		): void {
			$children = isset( $group['children'] ) && is_array( $group['children'] )
				? $group['children']
				: array();

			$this->find_sibling_duplicates( $group, $children, $rule_id, $diagnostics, $seen, $complete );
			$this->find_and_path_contradictions( $group, $rule_id, $diagnostics, $seen );
			$this->find_unreachable_children( $group, $children, $rule_id, $diagnostics, $seen );

			foreach ( $children as $child ) {
				if ( is_array( $child ) && 'group' === ( $child['kind'] ?? null ) ) {
					$this->analyze_group( $child, $rule_id, $diagnostics, $seen, $complete );
				}
			}
		}

		/**
		 * @param array<string,mixed>              $group       Parent group.
		 * @param array<int,mixed>                 $children    Direct children.
		 * @param string|null                      $rule_id     Persisted rule ID.
		 * @param array<int,array<string,mixed>>   $diagnostics Collected diagnostics.
		 * @param array<string,bool>                $seen        De-duplication map.
		 * @param bool                              $complete    Static coverage flag.
		 */
		private function find_sibling_duplicates(
			array $group,
			array $children,
			?string $rule_id,
			array &$diagnostics,
			array &$seen,
			bool &$complete
		): void {
			$fingerprints = array();
			foreach ( $children as $child ) {
				if ( ! is_array( $child ) || 'condition' !== ( $child['kind'] ?? null ) ) {
					continue;
				}

				$type        = isset( $child['type'] ) && is_string( $child['type'] ) ? $child['type'] : '';
				$fingerprint = $this->condition_fingerprint( $child, $type );
				if ( null === $fingerprint ) {
					$complete = false;
					$this->add_condition_unknown( $child, $type, $rule_id, $diagnostics, $seen );
					continue;
				}

				$key = $type . ':' . $fingerprint;
				if ( ! isset( $fingerprints[ $key ] ) ) {
					$fingerprints[ $key ] = array();
				}
				$fingerprints[ $key ][] = $this->node_id( $child );
			}

			foreach ( $fingerprints as $node_ids ) {
				if ( count( $node_ids ) < 2 ) {
					continue;
				}

				$signature = 'duplicate_leaf:' . implode( ':', $node_ids );
				if ( isset( $seen[ $signature ] ) ) {
					continue;
				}
				$seen[ $signature ] = true;
				$diagnostics[]      = $this->diagnostic(
					'duplicate_leaf',
					'confirmed',
					$this->rule_scope( $rule_id, $node_ids ),
					array( 'group_id' => $this->node_id( $group ) )
				);
			}
		}

		/**
		 * @param array<string,mixed>              $group       Canonical group.
		 * @param string|null                      $rule_id     Persisted rule ID.
		 * @param array<int,array<string,mixed>>   $diagnostics Collected diagnostics.
		 * @param array<string,bool>                $seen        De-duplication map.
		 */
		private function find_and_path_contradictions(
			array $group,
			?string $rule_id,
			array &$diagnostics,
			array &$seen
		): void {
			if ( 'and' !== ( $group['operator'] ?? null ) ) {
				return;
			}

			$conditions = $this->and_path_conditions( $group );
			$count      = count( $conditions );
			for ( $left_index = 0; $left_index < $count; ++$left_index ) {
				for ( $right_index = $left_index + 1; $right_index < $count; ++$right_index ) {
					$left  = $conditions[ $left_index ];
					$right = $conditions[ $right_index ];
					$type  = isset( $left['type'] ) && is_string( $left['type'] ) ? $left['type'] : '';
					if ( ( $right['type'] ?? null ) !== $type || ! isset( $this->static_analyzers[ $type ] ) ) {
						continue;
					}

					$comparison = $this->compare_conditions( $this->static_analyzers[ $type ], $left, $right );
					if (
						'contradiction' !== ( $comparison['relation'] ?? null )
						|| 'confirmed' !== ( $comparison['certainty'] ?? null )
					) {
						continue;
					}

					$node_ids  = array( $this->node_id( $left ), $this->node_id( $right ) );
					$signature = 'contradiction:' . implode( ':', $node_ids );
					if ( isset( $seen[ $signature ] ) ) {
						continue;
					}
					$seen[ $signature ] = true;
					$diagnostics[]      = $this->diagnostic(
						'contradiction',
						'confirmed',
						$this->rule_scope( $rule_id, $node_ids ),
						array( 'group_id' => $this->node_id( $group ) )
					);
				}
			}
		}

		/**
		 * @param array<string,mixed>              $group       Parent group.
		 * @param array<int,mixed>                 $children    Direct children.
		 * @param string|null                      $rule_id     Persisted rule ID.
		 * @param array<int,array<string,mixed>>   $diagnostics Collected diagnostics.
		 * @param array<string,bool>                $seen        De-duplication map.
		 */
		private function find_unreachable_children(
			array $group,
			array $children,
			?string $rule_id,
			array &$diagnostics,
			array &$seen
		): void {
			$operator = isset( $group['operator'] ) && is_string( $group['operator'] ) ? $group['operator'] : '';
			$blocker  = null;
			foreach ( $children as $child ) {
				if ( ! is_array( $child ) ) {
					continue;
				}

				if ( null !== $blocker ) {
					$node_id   = $this->node_id( $child );
					$signature = 'unreachable_branch:' . $node_id;
					if ( ! isset( $seen[ $signature ] ) ) {
						$seen[ $signature ] = true;
						$diagnostics[]      = $this->diagnostic(
							'unreachable_branch',
							'confirmed',
							$this->rule_scope( $rule_id, array( $node_id ) ),
							array(
								'group_id'           => $this->node_id( $group ),
								'blocked_by_node_id' => $blocker,
							)
						);
					}
					continue;
				}

				$constant = $this->node_constant_result( $child );
				if (
					true === $constant['known']
					&& (
						( 'or' === $operator && true === $constant['matched'] )
						|| ( 'and' === $operator && false === $constant['matched'] )
					)
				) {
					$blocker = $this->node_id( $child );
				}
			}
		}

		/**
		 * @param array<string,mixed> $condition Canonical condition.
		 */
		private function condition_fingerprint( array $condition, string $type ): ?string {
			if ( ! isset( $this->static_analyzers[ $type ] ) ) {
				return null;
			}

			try {
				$fingerprint = call_user_func(
					array( $this->static_analyzers[ $type ], 'semantic_fingerprint' ),
					$condition
				);
			} catch ( Throwable $error ) {
				unset( $error );
				return null;
			}

			return is_string( $fingerprint ) && '' !== $fingerprint ? $fingerprint : null;
		}

		/**
		 * @param object              $analyzer Static analyzer.
		 * @param array<string,mixed> $left     Left condition.
		 * @param array<string,mixed> $right    Right condition.
		 * @return array<string,mixed>
		 */
		private function compare_conditions( $analyzer, array $left, array $right ): array {
			try {
				$result = call_user_func( array( $analyzer, 'compare' ), $left, $right );
			} catch ( Throwable $error ) {
				unset( $error );
				return array();
			}

			return is_array( $result ) ? $result : array();
		}

		/**
		 * @param array<string,mixed> $node Canonical node.
		 * @return array{known:bool,matched:bool}
		 */
		private function node_constant_result( array $node ): array {
			if ( 'condition' === ( $node['kind'] ?? null ) ) {
				$type = isset( $node['type'] ) && is_string( $node['type'] ) ? $node['type'] : '';
				if ( ! isset( $this->static_analyzers[ $type ] ) ) {
					return array(
						'known'   => false,
						'matched' => false,
					);
				}

				try {
					$result = call_user_func( array( $this->static_analyzers[ $type ], 'constant_result' ), $node );
				} catch ( Throwable $error ) {
					unset( $error );
					return array(
						'known'   => false,
						'matched' => false,
					);
				}

				return is_array( $result )
					&& true === ( $result['known'] ?? false )
					&& 'confirmed' === ( $result['certainty'] ?? null )
					&& isset( $result['matched'] )
					&& is_bool( $result['matched'] )
					? array(
						'known'   => true,
						'matched' => $result['matched'],
					)
					: array(
						'known'   => false,
						'matched' => false,
					);
			}

			if ( 'group' !== ( $node['kind'] ?? null ) ) {
				return array(
					'known'   => false,
					'matched' => false,
				);
			}

			$operator = isset( $node['operator'] ) && is_string( $node['operator'] ) ? $node['operator'] : '';
			$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
			if ( empty( $children ) || ( 'and' !== $operator && 'or' !== $operator ) ) {
				return array(
					'known'   => false,
					'matched' => false,
				);
			}

			$all_known = true;
			foreach ( $children as $child ) {
				if ( ! is_array( $child ) ) {
					$all_known = false;
					continue;
				}

				$result = $this->node_constant_result( $child );
				if ( ! $result['known'] ) {
					$all_known = false;
					continue;
				}

				if ( 'and' === $operator && ! $result['matched'] ) {
					return array(
						'known'   => true,
						'matched' => false,
					);
				}
				if ( 'or' === $operator && $result['matched'] ) {
					return array(
						'known'   => true,
						'matched' => true,
					);
				}
			}

			return $all_known
				? array(
					'known'   => true,
					'matched' => 'and' === $operator,
				)
				: array(
					'known'   => false,
					'matched' => false,
				);
		}

		/**
		 * @param array<string,mixed> $group AND group.
		 * @return array<int,array<string,mixed>>
		 */
		private function and_path_conditions( array $group ): array {
			$conditions = array();
			$children   = isset( $group['children'] ) && is_array( $group['children'] )
				? $group['children']
				: array();
			foreach ( $children as $child ) {
				if ( ! is_array( $child ) ) {
					continue;
				}
				if ( 'condition' === ( $child['kind'] ?? null ) ) {
					$conditions[] = $child;
				} elseif ( 'group' === ( $child['kind'] ?? null ) && 'and' === ( $child['operator'] ?? null ) ) {
					$conditions = array_merge( $conditions, $this->and_path_conditions( $child ) );
				}
			}

			return $conditions;
		}

		/**
		 * @param array<string,mixed>              $condition   Canonical condition.
		 * @param string                           $type        Condition type.
		 * @param string|null                      $rule_id     Persisted rule ID.
		 * @param array<int,array<string,mixed>>   $diagnostics Collected diagnostics.
		 * @param array<string,bool>                $seen        De-duplication map.
		 */
		private function add_condition_unknown(
			array $condition,
			string $type,
			?string $rule_id,
			array &$diagnostics,
			array &$seen
		): void {
			$node_id   = $this->node_id( $condition );
			$signature = 'condition_analysis_unknown:' . $node_id;
			if ( isset( $seen[ $signature ] ) ) {
				return;
			}

			$seen[ $signature ] = true;
			$diagnostics[]      = $this->diagnostic(
				'condition_analysis_unknown',
				'unknown',
				$this->rule_scope( $rule_id, array( $node_id ) ),
				array( 'condition_type' => $type )
			);
		}

		/**
		 * @param array<int,array<string,mixed>> $rows          Scope rows.
		 * @param string                         $certainty     Evidence certainty.
		 * @param string|null                    $snapshot_hash Snapshot hash.
		 * @return array<int,array<string,mixed>>
		 */
		private function catalog_duplicate_matches( array $rows, string $certainty, ?string $snapshot_hash ): array {
			$by_label = array();
			foreach ( $rows as $row ) {
				$label_id = $row['label_id'];
				if ( ! isset( $by_label[ $label_id ] ) ) {
					$by_label[ $label_id ] = array();
				}
				$by_label[ $label_id ][ $row['candidate_source_id'] ] = true;
			}

			ksort( $by_label, SORT_NUMERIC );
			$result = array();
			foreach ( $by_label as $label_id => $sources ) {
				if ( count( $sources ) < 2 ) {
					continue;
				}
				$source_ids = array_keys( $sources );
				sort( $source_ids, SORT_STRING );
				$result[] = $this->diagnostic(
					'duplicate_label_match',
					$certainty,
					$this->catalog_scope( $rows[0], array( (int) $label_id ), $snapshot_hash ),
					array( 'candidate_source_ids' => $source_ids )
				);
			}

			return $result;
		}

		/**
		 * @param array<int,array<string,mixed>> $rows          Scope rows.
		 * @param string                         $certainty     Evidence certainty.
		 * @param string|null                    $snapshot_hash Snapshot hash.
		 * @return array<int,array<string,mixed>>
		 */
		private function catalog_overlaps( array $rows, string $certainty, ?string $snapshot_hash ): array {
			$label_ids = array_values( array_unique( array_column( $rows, 'label_id' ) ) );
			sort( $label_ids, SORT_NUMERIC );
			if ( count( $label_ids ) < 2 ) {
				return array();
			}

			return array(
				$this->diagnostic(
					'overlap',
					$certainty,
					$this->catalog_scope( $rows[0], $label_ids, $snapshot_hash ),
					array()
				),
			);
		}

		/**
		 * @param array<int,array<string,mixed>> $rows          Scope rows.
		 * @param string                         $certainty     Evidence certainty.
		 * @param string|null                    $snapshot_hash Snapshot hash.
		 * @return array<int,array<string,mixed>>
		 */
		private function catalog_priority_collisions( array $rows, string $certainty, ?string $snapshot_hash ): array {
			$by_priority = array();
			foreach ( $rows as $row ) {
				$priority = $row['berocket_post_order'];
				if ( ! isset( $by_priority[ $priority ] ) ) {
					$by_priority[ $priority ] = array();
				}
				$by_priority[ $priority ][ $row['label_id'] ] = true;
			}

			ksort( $by_priority, SORT_NUMERIC );
			$result = array();
			foreach ( $by_priority as $priority => $labels ) {
				if ( count( $labels ) < 2 ) {
					continue;
				}
				$label_ids = array_map( 'intval', array_keys( $labels ) );
				sort( $label_ids, SORT_NUMERIC );
				$result[] = $this->diagnostic(
					'priority_collision',
					$certainty,
					$this->catalog_scope( $rows[0], $label_ids, $snapshot_hash ),
					array( 'berocket_post_order' => (int) $priority )
				);
			}

			return $result;
		}

		/** @param array<string,mixed> $snapshot Snapshot evidence. */
		private function catalog_certainty( array $snapshot ): string {
			$status     = isset( $snapshot['status'] ) && is_string( $snapshot['status'] ) ? $snapshot['status'] : '';
			$stale      = $snapshot['stale'] ?? null;
			$dimensions = $snapshot['dynamic_dimensions'] ?? null;
			if (
				! in_array( $status, array( 'completed', 'partial', 'failed' ), true )
				|| ! is_bool( $stale )
				|| ! is_array( $dimensions )
				|| ! $this->valid_hash( $snapshot['snapshot_hash'] ?? null )
				|| ! $this->valid_hash( $snapshot['context_hash'] ?? null )
			) {
				return 'unknown';
			}

			if ( $stale || 'failed' === $status ) {
				return 'unknown';
			}
			if ( 'partial' === $status || ! empty( $dimensions ) ) {
				return 'possible';
			}

			return 'confirmed';
		}

		/** @param array<string,mixed> $row Catalog match row. */
		private function valid_match_row( array $row ): bool {
			return isset( $row['label_id'] )
				&& is_int( $row['label_id'] )
				&& 0 < $row['label_id']
				&& isset( $row['product_id'] )
				&& is_int( $row['product_id'] )
				&& 0 < $row['product_id']
				&& isset( $row['candidate_source_id'] )
				&& is_string( $row['candidate_source_id'] )
				&& '' !== $row['candidate_source_id']
				&& isset( $row['placement'] )
				&& is_string( $row['placement'] )
				&& '' !== $row['placement']
				&& $this->valid_hash( $row['context_hash'] ?? null )
				&& isset( $row['berocket_post_order'] )
				&& is_int( $row['berocket_post_order'] );
		}

		/** @param array<string,mixed> $row Catalog match row. */
		private function catalog_scope_key( array $row ): string {
			return $row['product_id'] . "\0" . $row['context_hash'] . "\0" . $row['placement'];
		}

		/**
		 * @param string                   $code       Stable diagnostic code.
		 * @param string                   $certainty Certainty level.
		 * @param array<string,mixed>      $scope      Diagnostic scope.
		 * @param array<string,mixed>      $parameters Bounded parameters.
		 * @return array<string,mixed>
		 */
		private function diagnostic( string $code, string $certainty, array $scope, array $parameters ): array {
			return array(
				'code'       => $code,
				'certainty'  => $certainty,
				'scope'      => $scope,
				'parameters' => $parameters,
			);
		}

		/**
		 * @param string|null       $rule_id  Persisted rule ID.
		 * @param array<int,string> $node_ids Canonical node IDs.
		 * @return array<string,mixed>
		 */
		private function rule_scope( ?string $rule_id, array $node_ids ): array {
			return array(
				'rule_id'       => $rule_id,
				'node_ids'      => $node_ids,
				'label_ids'     => array(),
				'product_id'    => null,
				'placement'     => null,
				'context_hash'  => null,
				'snapshot_hash' => null,
			);
		}

		/**
		 * @param array<string,mixed> $row           Catalog scope row.
		 * @param array<int,int>       $label_ids     Matching label IDs.
		 * @param string|null          $snapshot_hash Snapshot hash.
		 * @return array<string,mixed>
		 */
		private function catalog_scope( array $row, array $label_ids, ?string $snapshot_hash ): array {
			return array(
				'rule_id'       => null,
				'node_ids'      => array(),
				'label_ids'     => $label_ids,
				'product_id'    => $row['product_id'],
				'placement'     => $row['placement'],
				'context_hash'  => $row['context_hash'],
				'snapshot_hash' => $snapshot_hash,
			);
		}

		/** @param array<string,mixed> $node Canonical node. */
		private function node_id( array $node ): string {
			return isset( $node['id'] ) && is_string( $node['id'] ) ? $node['id'] : '';
		}

		/** @param mixed $value Hash candidate. */
		private function valid_hash( $value ): bool {
			return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/', $value );
		}

		private function is_stable_type( string $type ): bool {
			return 1 === preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $type );
		}
	}
}
