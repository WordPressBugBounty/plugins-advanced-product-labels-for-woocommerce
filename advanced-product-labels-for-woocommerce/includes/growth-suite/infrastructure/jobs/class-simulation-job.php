<?php
/**
 * Resumable read-only execution state for bounded catalog simulation.
 */

require_once __DIR__ . '/interface-catalog-source.php';
require_once __DIR__ . '/../../domain/class-rule-model.php';
require_once __DIR__ . '/../../application/class-simulation-service.php';

if ( ! class_exists( 'BeRocket_Growth_Suite_Simulation_Job' ) ) {
	class BeRocket_Growth_Suite_Simulation_Job {
		const SCHEMA_VERSION     = 1;
		const DEFAULT_BATCH_SIZE = 50;
		const MAX_BATCH_SIZE     = 100;
		const MAX_CONTEXT_BYTES  = 65536;

		/** @var BeRocket_Growth_Suite_Simulation_Service */
		private $simulation;

		/** @var BeRocket_Growth_Suite_Catalog_Source_Interface */
		private $catalog;

		/** @var BeRocket_Growth_Suite_Rule_Model */
		private $model;

		/** @var callable|null */
		private $uuid_factory;

		/**
		 * @param BeRocket_Growth_Suite_Simulation_Service $simulation Synchronous one-product service.
		 * @param BeRocket_Growth_Suite_Catalog_Source_Interface $catalog Public catalog source.
		 * @param BeRocket_Growth_Suite_Rule_Model|null $model Persisted model inspector.
		 * @param callable|null $uuid_factory Optional deterministic job-ID source.
		 */
		public function __construct(
			BeRocket_Growth_Suite_Simulation_Service $simulation,
			BeRocket_Growth_Suite_Catalog_Source_Interface $catalog,
			?BeRocket_Growth_Suite_Rule_Model $model = null,
			$uuid_factory = null
		) {
			if ( null !== $uuid_factory && ! is_callable( $uuid_factory ) ) {
				throw new InvalidArgumentException( 'The job UUID factory must be callable.' );
			}

			$this->simulation   = $simulation;
			$this->catalog      = $catalog;
			$this->model        = null === $model ? new BeRocket_Growth_Suite_Rule_Model() : $model;
			$this->uuid_factory = $uuid_factory;
		}

		/**
		 * Freeze one immutable rule/context/catalog boundary.
		 *
		 * This isolated state object does not authorize a production scheduler or
		 * Conditions-editor mount. It returns versioned state suitable for a
		 * separately approved non-interactive owner.
		 *
		 * @param array<string,mixed> $rule_payload Persisted label payload.
		 * @param array<string,mixed> $context Canonical context without product IDs.
		 * @param array<string,mixed> $boundary Public archive/category/single boundary.
		 * @param int $batch_size Maximum products processed by one invocation.
		 * @return array<string,mixed>
		 */
		public function create(
			array $rule_payload,
			array $context,
			array $boundary,
			int $batch_size = self::DEFAULT_BATCH_SIZE
		): array {
			$errors = array_merge(
				$this->validate_boundary( $boundary ),
				$this->validate_context( $context, $boundary )
			);
			if ( 1 > $batch_size || self::MAX_BATCH_SIZE < $batch_size ) {
				$errors[] = $this->error( 'invalid_batch_size', '$.batch_size' );
			}

			$model = $this->inspect_rule_model( $rule_payload );
			if ( ! $model['valid'] ) {
				$errors = array_merge( $errors, $model['errors'] );
			}

			if ( ! empty( $errors ) ) {
				return $this->create_failure( $errors );
			}

			$context_hash  = $this->canonical_hash( $context );
			$boundary_hash = $this->canonical_hash( $boundary );
			if ( null === $context_hash || null === $boundary_hash ) {
				return $this->create_failure(
					array( $this->error( 'invalid_simulation_context', '$.context' ) )
				);
			}

			try {
				$catalog_snapshot = $this->catalog->create_snapshot( $boundary, $context );
			} catch ( Throwable $error ) {
				unset( $error );
				return $this->create_failure(
					array( $this->error( 'catalog_snapshot_failed', '$.catalog_snapshot' ) )
				);
			}

			if ( ! $this->valid_catalog_snapshot( $catalog_snapshot ) ) {
				return $this->create_failure(
					array( $this->error( 'invalid_catalog_snapshot', '$.catalog_snapshot' ) )
				);
			}

			$snapshot_hash = $this->canonical_hash(
				array(
					'rule_hash'             => $model['rule_hash'],
					'context_hash'          => $context_hash,
					'boundary_hash'         => $boundary_hash,
					'catalog_snapshot_hash' => $catalog_snapshot['snapshot_hash'],
				)
			);
			$job_id        = $this->new_uuid();
			if ( null === $snapshot_hash || null === $job_id ) {
				return $this->create_failure(
					array( $this->error( 'job_identity_failed', '$.job_id' ) )
				);
			}

			return array(
				'created' => true,
				'job'     => array(
					'schema_version'   => self::SCHEMA_VERSION,
					'job_id'           => $job_id,
					'idempotency_key'  => $snapshot_hash,
					'status'           => 'queued',
					'count_state'      => 'partial',
					'rule_hash'        => $model['rule_hash'],
					'context_hash'     => $context_hash,
					'snapshot_hash'    => $snapshot_hash,
					'catalog_snapshot' => $catalog_snapshot,
					'rule_payload'     => array( 'rule_model' => $model['model'] ),
					'context'          => $context,
					'boundary'         => $boundary,
					'batch_size'       => $batch_size,
					'cursor'           => 0,
					'scanned'          => 0,
					'matched'          => 0,
					'warning_count'    => 0,
					'stale'            => false,
					'stale_reasons'    => array(),
				),
				'errors'  => array(),
			);
		}

		/**
		 * Process at most one bounded, strictly ascending candidate page.
		 *
		 * @param array<string,mixed> $job Versioned execution state.
		 * @return array<string,mixed>
		 */
		public function run_batch( array $job ): array {
			if ( ! $this->valid_job_state( $job ) ) {
				return array(
					'job'         => $this->failed_state( $job, 'invalid_job_state' ),
					'items'       => array(),
					'warnings'    => array(),
					'next_cursor' => null,
				);
			}

			if ( in_array( $job['status'], array( 'completed', 'failed', 'cancelled', 'stale' ), true ) ) {
				return $this->empty_batch_result( $job );
			}

			$job = $this->refresh_staleness( $job, $job['rule_payload'], $job['context'] );
			if ( 'stale' === $job['status'] ) {
				return $this->empty_batch_result( $job );
			}

			try {
				$page = $this->catalog->fetch_page(
					$job['catalog_snapshot'],
					$job['boundary'],
					$job['cursor'],
					$job['batch_size']
				);
			} catch ( Throwable $error ) {
				unset( $error );
				$job = $this->failed_state( $job, 'catalog_page_failed' );

				return $this->empty_batch_result( $job );
			}

			if ( ! $this->valid_catalog_page( $page, $job ) ) {
				$job = $this->failed_state( $job, 'invalid_catalog_page' );

				return $this->empty_batch_result( $job );
			}

			$items        = array();
			$warnings     = array();
			$last_product = $job['cursor'];
			$scanned      = 0;
			$matched      = 0;
			foreach ( $page['items'] as $product ) {
				++$scanned;
				$last_product = $product['product_id'];
				$warning      = $this->eligibility_warning( $product, $job['boundary'] );
				if ( null !== $warning ) {
					$warnings[] = array(
						'code'       => $warning,
						'product_id' => $product['product_id'],
					);
					continue;
				}

				$context                          = $job['context'];
				$context['product_id']            = $product['product_id'];
				$context['product_snapshot_hash'] = $product['product_snapshot_hash'];
				$simulation                       = $this->simulation->simulate( $job['rule_payload'], $context );
				if ( ! $this->valid_simulation_result( $simulation, $job['rule_hash'] ) ) {
					$job                   = $this->failed_state( $job, 'simulation_failed' );
					$job['scanned']       += $scanned;
					$job['matched']       += $matched;
					$job['warning_count'] += count( $warnings );

					return array(
						'job'         => $job,
						'items'       => $items,
						'warnings'    => $warnings,
						'next_cursor' => null,
					);
				}

				if ( ! $simulation['matched'] ) {
					continue;
				}

				++$matched;
				$items[] = array(
					'product_id'     => $product['product_id'],
					'sku'            => $product['sku'],
					'matched'        => true,
					'reason_summary' => $simulation['reasons'],
				);
			}

			$job['cursor']         = $last_product;
			$job['scanned']       += $scanned;
			$job['matched']       += $matched;
			$job['warning_count'] += count( $warnings );
			$complete              = true === $page['complete'];

			if ( $complete ) {
				if ( $job['scanned'] !== $job['catalog_snapshot']['candidate_count'] ) {
					$job = $this->mark_stale( $job, array( 'catalog_changed' ) );
				} else {
					$job['status']      = 'completed';
					$job['count_state'] = 'completed';
					$job['total_count'] = $job['matched'];
				}
			} else {
				$job['status']      = 'running';
				$job['count_state'] = 'partial';
				unset( $job['total_count'] );
			}

			return array(
				'job'         => $job,
				'items'       => $items,
				'warnings'    => $warnings,
				'next_cursor' => 'running' === $job['status'] ? $page['next_cursor'] : null,
			);
		}

		/**
		 * Compare historical state with the current rule/context/catalog boundary.
		 *
		 * @param array<string,mixed> $job Historical job state.
		 * @param array<string,mixed> $rule_payload Current persisted label payload.
		 * @param array<string,mixed> $context Current canonical context.
		 * @return array<string,mixed>
		 */
		public function refresh_staleness( array $job, array $rule_payload, array $context ): array {
			if ( ! $this->valid_job_state( $job ) || in_array( $job['status'], array( 'failed', 'cancelled' ), true ) ) {
				return $job;
			}

			$reasons = array();
			$model   = $this->inspect_rule_model( $rule_payload );
			if ( ! $model['valid'] || $model['rule_hash'] !== $job['rule_hash'] ) {
				$reasons[] = 'rule_changed';
			}

			$context_hash = $this->canonical_hash( $context );
			if ( null === $context_hash || $context_hash !== $job['context_hash'] ) {
				$reasons[] = 'context_changed';
			}

			try {
				$current = $this->catalog->is_current( $job['catalog_snapshot'], $job['boundary'], $context );
			} catch ( Throwable $error ) {
				unset( $error );
				$current = false;
			}
			if ( ! $current ) {
				$reasons[] = 'catalog_changed';
			}

			return empty( $reasons ) ? $job : $this->mark_stale( $job, $reasons );
		}

		/**
		 * @param array<string,mixed> $job Current job state.
		 * @return array<string,mixed>
		 */
		public function cancel( array $job ): array {
			if ( ! $this->valid_job_state( $job ) || in_array( $job['status'], array( 'completed', 'failed', 'cancelled', 'stale' ), true ) ) {
				return $job;
			}

			$job['status']      = 'cancelled';
			$job['count_state'] = 'partial';
			unset( $job['total_count'] );

			return $job;
		}

		/**
		 * @param array<string,mixed> $payload Persisted label payload.
		 * @return array<string,mixed>
		 */
		private function inspect_rule_model( array $payload ): array {
			if ( ! array_key_exists( 'rule_model', $payload ) ) {
				return array(
					'valid'     => false,
					'model'     => null,
					'rule_hash' => null,
					'errors'    => array( $this->error( 'rule_model_required', '$.rule_model' ) ),
				);
			}

			$inspection = $this->model->inspect( $payload['rule_model'] );
			if (
				true !== $inspection['valid']
				|| true !== $inspection['supported']
				|| true !== $inspection['renderable']
				|| ! isset( $inspection['model'] )
				|| ! is_array( $inspection['model'] )
			) {
				return array(
					'valid'     => false,
					'model'     => null,
					'rule_hash' => null,
					'errors'    => $this->prefix_errors( $inspection['errors'], '$.rule_model' ),
				);
			}

			try {
				$rule_hash = $this->model->semantic_hash( $inspection['model'] );
			} catch ( Throwable $error ) {
				unset( $error );
				return array(
					'valid'     => false,
					'model'     => null,
					'rule_hash' => null,
					'errors'    => array( $this->error( 'invalid_rule_model', '$.rule_model' ) ),
				);
			}

			return array(
				'valid'     => true,
				'model'     => $inspection['model'],
				'rule_hash' => $rule_hash,
				'errors'    => array(),
			);
		}

		/**
		 * @param array<string,mixed> $boundary Public catalog boundary.
		 * @return array<int,array{code:string,path:string}>
		 */
		private function validate_boundary( array $boundary ): array {
			$errors = array();
			$type   = isset( $boundary['type'] ) && is_string( $boundary['type'] ) ? $boundary['type'] : '';
			if ( ! in_array( $type, array( 'archive', 'category', 'single' ), true ) ) {
				$errors[] = $this->error( 'invalid_catalog_boundary', '$.boundary.type' );
			}
			if (
				! isset( $boundary['placement'] )
				|| ! is_string( $boundary['placement'] )
				|| 1 !== preg_match( '/\A[a-z][a-z0-9_-]{0,63}\z/', $boundary['placement'] )
			) {
				$errors[] = $this->error( 'invalid_catalog_boundary', '$.boundary.placement' );
			}
			if ( ! isset( $boundary['include_variations'] ) || ! is_bool( $boundary['include_variations'] ) ) {
				$errors[] = $this->error( 'invalid_catalog_boundary', '$.boundary.include_variations' );
			}
			if ( 'category' === $type && ( ! isset( $boundary['category_id'] ) || ! is_int( $boundary['category_id'] ) || 1 > $boundary['category_id'] ) ) {
				$errors[] = $this->error( 'invalid_catalog_boundary', '$.boundary.category_id' );
			}
			if ( 'single' === $type && ( ! isset( $boundary['product_id'] ) || ! is_int( $boundary['product_id'] ) || 1 > $boundary['product_id'] ) ) {
				$errors[] = $this->error( 'invalid_catalog_boundary', '$.boundary.product_id' );
			}

			return $errors;
		}

		/**
		 * @param array<string,mixed> $context Canonical simulation context.
		 * @param array<string,mixed> $boundary Public catalog boundary.
		 * @return array<int,array{code:string,path:string}>
		 */
		private function validate_context( array $context, array $boundary ): array {
			$errors = array();
			if ( isset( $context['product_id'] ) || isset( $context['product_ids'] ) ) {
				$errors[] = $this->error( 'catalog_context_must_not_fix_product', '$.context.product_id' );
			}
			if (
				! isset( $context['request'] )
				|| ! is_array( $context['request'] )
				|| ! isset( $context['request']['context'] )
				|| ( $boundary['type'] ?? null ) !== $context['request']['context']
			) {
				$errors[] = $this->error( 'invalid_simulation_context', '$.context.request.context' );
			}
			if ( ! isset( $context['device'] ) || ! in_array( $context['device'], array( 'desktop', 'tablet', 'mobile' ), true ) ) {
				$errors[] = $this->error( 'invalid_simulation_context', '$.context.device' );
			}
			if (
				! isset( $context['flags'] )
				|| ! is_array( $context['flags'] )
				|| true !== ( $context['flags']['simulation'] ?? null )
			) {
				$errors[] = $this->error( 'invalid_simulation_context', '$.context.flags.simulation' );
			}
			$bytes = $this->json_bytes( $context );
			if ( false === $bytes || self::MAX_CONTEXT_BYTES < $bytes || $this->contains_sensitive_key( $context ) ) {
				$errors[] = $this->error( 'invalid_simulation_context', '$.context' );
			}

			return $errors;
		}

		/** @param array<string,mixed> $snapshot Source snapshot. */
		private function valid_catalog_snapshot( array $snapshot ): bool {
			return $this->valid_hash( $snapshot['snapshot_hash'] ?? null )
				&& isset( $snapshot['candidate_count'] )
				&& is_int( $snapshot['candidate_count'] )
				&& 0 <= $snapshot['candidate_count']
				&& isset( $snapshot['max_product_id'] )
				&& is_int( $snapshot['max_product_id'] )
				&& 0 <= $snapshot['max_product_id']
				&& ( 0 === $snapshot['candidate_count'] || 0 < $snapshot['max_product_id'] );
		}

		/**
		 * @param array<string,mixed> $page Source page.
		 * @param array<string,mixed> $job Current job.
		 */
		private function valid_catalog_page( array $page, array $job ): bool {
			if (
				( $page['snapshot_hash'] ?? null ) !== $job['catalog_snapshot']['snapshot_hash']
				|| ! isset( $page['items'] )
				|| ! is_array( $page['items'] )
				|| ! $this->is_list( $page['items'] )
				|| $job['batch_size'] < count( $page['items'] )
				|| ! isset( $page['complete'] )
				|| ! is_bool( $page['complete'] )
			) {
				return false;
			}

			$previous = $job['cursor'];
			foreach ( $page['items'] as $product ) {
				if ( ! is_array( $product ) || ! $this->valid_product_record( $product ) ) {
					return false;
				}
				if ( $previous >= $product['product_id'] || $job['catalog_snapshot']['max_product_id'] < $product['product_id'] ) {
					return false;
				}
				$previous = $product['product_id'];
			}

			if ( true === $page['complete'] ) {
				return null === ( $page['next_cursor'] ?? null );
			}

			return ! empty( $page['items'] )
				&& isset( $page['next_cursor'] )
				&& is_int( $page['next_cursor'] )
				&& $previous === $page['next_cursor'];
		}

		/** @param array<string,mixed> $product Product record. */
		private function valid_product_record( array $product ): bool {
			return isset( $product['product_id'] )
				&& is_int( $product['product_id'] )
				&& 0 < $product['product_id']
				&& isset( $product['sku'] )
				&& is_string( $product['sku'] )
				&& 512 >= strlen( $product['sku'] )
				&& isset( $product['product_type'] )
				&& in_array( $product['product_type'], array( 'product', 'variation' ), true )
				&& isset( $product['status'] )
				&& is_string( $product['status'] )
				&& isset( $product['password_protected'] )
				&& is_bool( $product['password_protected'] )
				&& isset( $product['readable'] )
				&& is_bool( $product['readable'] )
				&& isset( $product['exposed'] )
				&& is_bool( $product['exposed'] )
				&& $this->valid_hash( $product['product_snapshot_hash'] ?? null );
		}

		/**
		 * @param array<string,mixed> $product Product record.
		 * @param array<string,mixed> $boundary Catalog boundary.
		 */
		private function eligibility_warning( array $product, array $boundary ): ?string {
			if ( 'variation' === $product['product_type'] && ( ! $boundary['include_variations'] || ! $product['exposed'] ) ) {
				return 'variation_not_in_context';
			}
			if ( 'publish' !== $product['status'] || $product['password_protected'] || ! $product['readable'] || ! $product['exposed'] ) {
				return 'product_not_public';
			}

			return null;
		}

		/**
		 * Validate that the simulation result belongs to this immutable job snapshot.
		 *
		 * @param array<string,mixed> $result             Simulation result.
		 * @param string              $expected_rule_hash Frozen rule hash.
		 */
		private function valid_simulation_result( array $result, string $expected_rule_hash ): bool {
			return isset( $result['matched'] )
				&& is_bool( $result['matched'] )
				&& 2 === ( $result['model_version'] ?? null )
				&& ( $result['rule_hash'] ?? null ) === $expected_rule_hash
				&& isset( $result['root'] )
				&& is_array( $result['root'] )
				&& isset( $result['reasons'] )
				&& is_array( $result['reasons'] )
				&& 'invalid_rule' !== ( $result['reason_code'] ?? null );
		}

		/** @param array<string,mixed> $job Job state. */
		private function valid_job_state( array $job ): bool {
			return self::SCHEMA_VERSION === ( $job['schema_version'] ?? null )
				&& isset( $job['job_id'] )
				&& is_string( $job['job_id'] )
				&& $this->valid_hash( $job['snapshot_hash'] ?? null )
				&& $this->valid_hash( $job['rule_hash'] ?? null )
				&& $this->valid_hash( $job['context_hash'] ?? null )
				&& isset( $job['status'] )
				&& in_array( $job['status'], array( 'queued', 'running', 'completed', 'failed', 'cancelled', 'stale' ), true )
				&& isset( $job['cursor'] )
				&& is_int( $job['cursor'] )
				&& 0 <= $job['cursor']
				&& isset( $job['batch_size'] )
				&& is_int( $job['batch_size'] )
				&& 1 <= $job['batch_size']
				&& self::MAX_BATCH_SIZE >= $job['batch_size']
				&& isset( $job['catalog_snapshot'], $job['rule_payload'], $job['context'], $job['boundary'] )
				&& is_array( $job['catalog_snapshot'] )
				&& is_array( $job['rule_payload'] )
				&& is_array( $job['context'] )
				&& is_array( $job['boundary'] );
		}

		/**
		 * @param array<string,mixed> $job Job state.
		 * @param array<int,string> $reasons Stable stale reasons.
		 * @return array<string,mixed>
		 */
		private function mark_stale( array $job, array $reasons ): array {
			$job['status']        = 'stale';
			$job['count_state']   = 'partial';
			$job['stale']         = true;
			$job['stale_reasons'] = array_values( array_unique( $reasons ) );
			$job['error_code']    = 'snapshot_stale';
			unset( $job['total_count'] );

			return $job;
		}

		/**
		 * @param array<string,mixed> $job Job state.
		 * @return array<string,mixed>
		 */
		private function failed_state( array $job, string $code ): array {
			$job['status']      = 'failed';
			$job['count_state'] = 'partial';
			$job['error_code']  = $code;
			unset( $job['total_count'] );

			return $job;
		}

		/**
		 * @param array<string,mixed> $job Job state.
		 * @return array<string,mixed>
		 */
		private function empty_batch_result( array $job ): array {
			return array(
				'job'         => $job,
				'items'       => array(),
				'warnings'    => array(),
				'next_cursor' => 'running' === ( $job['status'] ?? null ) ? $job['cursor'] : null,
			);
		}

		/**
		 * @param array<int,array{code:string,path:string}> $errors Creation errors.
		 * @return array<string,mixed>
		 */
		private function create_failure( array $errors ): array {
			return array(
				'created' => false,
				'job'     => null,
				'errors'  => $errors,
			);
		}

		/**
		 * @param array<int,array<string,mixed>> $errors Model errors.
		 * @return array<int,array{code:string,path:string}>
		 */
		private function prefix_errors( array $errors, string $prefix ): array {
			$result = array();
			foreach ( $errors as $error ) {
				if ( ! is_array( $error ) || ! isset( $error['code'], $error['path'] ) ) {
					continue;
				}
				$result[] = $this->error( (string) $error['code'], $prefix . substr( (string) $error['path'], 1 ) );
			}

			return $result;
		}

		/** @param mixed $value Context subtree. */
		private function contains_sensitive_key( $value ): bool {
			if ( ! is_array( $value ) ) {
				return false;
			}

			$forbidden = array( 'user_id', 'email', 'ip', 'ip_address', 'token', 'nonce', 'authorization', 'cookie' );
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && in_array( strtolower( $key ), $forbidden, true ) ) {
					return true;
				}
				if ( $this->contains_sensitive_key( $item ) ) {
					return true;
				}
			}

			return false;
		}

		/** @param mixed $value Value to hash canonically. */
		private function canonical_hash( $value ): ?string {
			$sorted = $this->sort_object_keys( $value );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The domain layer must remain usable before WordPress functions load.
			$json = json_encode( $sorted, JSON_UNESCAPED_SLASHES );

			return false === $json ? null : hash( 'sha256', $json );
		}

		/**
		 * @param mixed $value Value to sort.
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

		/** @param array<mixed> $value Array candidate. */
		private function is_list( array $value ): bool {
			return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
		}

		/** @param mixed $value Hash candidate. */
		private function valid_hash( $value ): bool {
			return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/', $value );
		}

		/**
		 * @param mixed $value JSON candidate.
		 * @return int|false
		 */
		private function json_bytes( $value ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The domain layer must remain usable before WordPress functions load.
			$json = json_encode( $value, JSON_UNESCAPED_SLASHES );

			return false === $json ? false : strlen( $json );
		}

		private function new_uuid(): ?string {
			try {
				if ( null !== $this->uuid_factory ) {
					$uuid = call_user_func( $this->uuid_factory );
				} elseif ( function_exists( 'wp_generate_uuid4' ) ) {
					$uuid = wp_generate_uuid4();
				} else {
					$bytes    = random_bytes( 16 );
					$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
					$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
					$hex      = bin2hex( $bytes );
					$uuid     = substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
				}
			} catch ( Throwable $error ) {
				unset( $error );
				return null;
			}

			return is_string( $uuid ) && 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid )
				? $uuid
				: null;
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
