<?php
/**
 * Versioned authenticated REST transport for bounded catalog simulations.
 */

require_once __DIR__ . '/interface-simulation-job-gateway.php';
require_once __DIR__ . '/../../domain/class-rule-model.php';

if ( ! class_exists( 'BeRocket_Growth_Suite_Simulation_Controller' ) ) {
	class BeRocket_Growth_Suite_Simulation_Controller {
		const REST_NAMESPACE        = 'berocket-labels/v1';
		const MAX_BODY_BYTES        = 262144;
		const MAX_JSON_DEPTH        = 20;
		const MAX_STRING_BYTES      = 8192;
		const DEFAULT_BATCH_SIZE    = 50;
		const MAX_BATCH_SIZE        = 100;
		const DEFAULT_MATCHES_PAGE  = 1;
		const DEFAULT_MATCHES_LIMIT = 25;
		const MAX_MATCHES_LIMIT     = 100;
		const MAX_MATCHES_PAGE      = 1000000;

		/** @var callable */
		private $gateway_resolver;

		/** @var callable */
		private $permission_callback;

		/** @var callable */
		private $actor_callback;

		/** @var callable */
		private $correlation_factory;

		/**
		 * @param callable|null $gateway_resolver Resolves an optional isolated gateway.
		 * @param callable|null $permission_callback Narrow collection capability check.
		 * @param callable|null $actor_callback Server-derived user/site/role context.
		 * @param callable|null $correlation_factory Correlation UUID source.
		 */
		public function __construct(
			$gateway_resolver = null,
			$permission_callback = null,
			$actor_callback = null,
			$correlation_factory = null
		) {
			foreach ( array( $gateway_resolver, $permission_callback, $actor_callback, $correlation_factory ) as $callback ) {
				if ( null !== $callback && ! is_callable( $callback ) ) {
					throw new InvalidArgumentException( 'Simulation REST dependencies must be callable.' );
				}
			}

			$this->gateway_resolver    = null === $gateway_resolver ? array( $this, 'default_gateway' ) : $gateway_resolver;
			$this->permission_callback = null === $permission_callback ? array( $this, 'default_permission' ) : $permission_callback;
			$this->actor_callback      = null === $actor_callback ? array( $this, 'default_actor' ) : $actor_callback;
			$this->correlation_factory = null === $correlation_factory ? array( $this, 'default_correlation_id' ) : $correlation_factory;
		}

		/** Register only the three T089 routes from the versioned OpenAPI contract. */
		public function register_routes(): void {
			if ( ! function_exists( 'register_rest_route' ) ) {
				return;
			}

			register_rest_route(
				self::REST_NAMESPACE,
				'/simulations',
				array(
					'methods'             => class_exists( 'WP_REST_Server', false ) ? WP_REST_Server::CREATABLE : 'POST',
					'callback'            => array( $this, 'start' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				)
			);
			register_rest_route(
				self::REST_NAMESPACE,
				'/simulations/(?P<jobId>[0-9a-f-]{36})',
				array(
					'methods'             => class_exists( 'WP_REST_Server', false ) ? WP_REST_Server::READABLE : 'GET',
					'callback'            => array( $this, 'status' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				)
			);
			register_rest_route(
				self::REST_NAMESPACE,
				'/simulations/(?P<jobId>[0-9a-f-]{36})/matches',
				array(
					'methods'             => class_exists( 'WP_REST_Server', false ) ? WP_REST_Server::READABLE : 'GET',
					'callback'            => array( $this, 'matches' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				)
			);
		}

		/**
		 * @param mixed $request WordPress REST request.
		 * @return true|WP_Error|false
		 */
		public function permissions_check( $request ) {
			unset( $request );
			try {
				$allowed = true === call_user_func( $this->permission_callback );
			} catch ( Throwable $error ) {
				unset( $error );
				$allowed = false;
			}
			if ( $allowed ) {
				return true;
			}

			if ( class_exists( 'WP_Error', false ) ) {
				$message = function_exists( '__' )
					? __( 'You are not allowed to simulate product labels.', 'BeRocket_products_label_domain' )
					: 'You are not allowed to simulate product labels.';
				return new WP_Error(
					'rest_forbidden',
					$message,
					array( 'status' => 403 )
				);
			}

			return false;
		}

		/**
		 * @param mixed $request WordPress REST request.
		 * @return WP_REST_Response
		 */
		public function start( $request ) {
			$correlation_id = $this->correlation_id();
			$body           = $this->request_body( $request );
			if ( self::MAX_BODY_BYTES < strlen( $body ) ) {
				return $this->error_response( 'simulation_payload_too_large', 413, $correlation_id );
			}

			$params = $this->request_json( $request );
			if ( ! is_array( $params ) || $this->is_list( $params ) ) {
				return $this->error_response( 'invalid_simulation_request', 400, $correlation_id );
			}
			if ( self::MAX_BODY_BYTES < $this->json_bytes( $params ) ) {
				return $this->error_response( 'simulation_payload_too_large', 413, $correlation_id );
			}
			if ( self::MAX_JSON_DEPTH < $this->json_depth( $params ) || $this->contains_long_string( $params ) ) {
				return $this->error_response( 'invalid_simulation_request', 400, $correlation_id );
			}

			$idempotency_key = trim( $this->request_header( $request, 'Idempotency-Key' ) );
			if ( ! $this->valid_idempotency_key( $idempotency_key ) ) {
				return $this->error_response( 'invalid_idempotency_key', 400, $correlation_id );
			}

			$validation = $this->validate_start_params( $params );
			if ( false === $validation['valid'] ) {
				return $this->error_response( $validation['code'], 400, $correlation_id );
			}

			$actor = $this->actor();
			if ( null === $actor ) {
				return $this->error_response( 'simulation_actor_unavailable', 403, $correlation_id );
			}

			$gateway = $this->gateway();
			if ( null === $gateway ) {
				return $this->error_response( 'simulation_job_runtime_unavailable', 503, $correlation_id );
			}

			$context  = $params['context'];
			$boundary = $params['boundary'];
			$command  = array(
				'idempotency_key' => $idempotency_key,
				'user_id'         => $actor['user_id'],
				'site_id'         => $actor['site_id'],
				'rule_payload'    => array( 'rule_model' => $params['rule'] ),
				'context'         => $this->canonical_context( $context, $boundary, $actor ),
				'boundary'        => $this->canonical_boundary( $boundary ),
				'batch_size'      => isset( $params['batchSize'] ) ? $params['batchSize'] : self::DEFAULT_BATCH_SIZE,
			);

			try {
				$result = $gateway->start( $command );
			} catch ( Throwable $error ) {
				unset( $error );
				return $this->error_response( 'simulation_job_runtime_failure', 503, $correlation_id );
			}

			if ( true !== ( $result['ok'] ?? null ) ) {
				return $this->gateway_error( $result, $correlation_id );
			}
			$job = isset( $result['job'] ) && is_array( $result['job'] ) ? $this->serialize_job( $result['job'] ) : null;
			if ( null === $job ) {
				return $this->error_response( 'simulation_gateway_invalid_response', 500, $correlation_id );
			}

			return $this->private_response( $job, 202, $correlation_id );
		}

		/**
		 * @param mixed $request WordPress REST request.
		 * @return WP_REST_Response
		 */
		public function status( $request ) {
			$correlation_id = $this->correlation_id();
			$job_id         = $this->request_param( $request, 'jobId' );
			if ( ! $this->valid_uuid( $job_id ) ) {
				return $this->error_response( 'invalid_job_id', 400, $correlation_id );
			}

			$actor = $this->actor();
			if ( null === $actor ) {
				return $this->error_response( 'simulation_actor_unavailable', 403, $correlation_id );
			}
			$gateway = $this->gateway();
			if ( null === $gateway ) {
				return $this->error_response( 'simulation_job_runtime_unavailable', 503, $correlation_id );
			}

			try {
				$result = $gateway->get_job( $job_id, $actor['user_id'], $actor['site_id'] );
			} catch ( Throwable $error ) {
				unset( $error );
				return $this->error_response( 'simulation_job_runtime_failure', 503, $correlation_id );
			}
			if ( true !== ( $result['ok'] ?? null ) ) {
				return $this->gateway_error( $result, $correlation_id );
			}

			$job = isset( $result['job'] ) && is_array( $result['job'] ) ? $this->serialize_job( $result['job'] ) : null;
			if ( null === $job ) {
				return $this->error_response( 'simulation_gateway_invalid_response', 500, $correlation_id );
			}

			return $this->private_response( $job, 200, $correlation_id );
		}

		/**
		 * @param mixed $request WordPress REST request.
		 * @return WP_REST_Response
		 */
		public function matches( $request ) {
			$correlation_id = $this->correlation_id();
			$job_id         = $this->request_param( $request, 'jobId' );
			$page           = $this->positive_int( $this->request_param( $request, 'page' ), self::DEFAULT_MATCHES_PAGE );
			$per_page       = $this->positive_int( $this->request_param( $request, 'per_page' ), self::DEFAULT_MATCHES_LIMIT );
			if (
				! $this->valid_uuid( $job_id )
				|| null === $page
				|| self::MAX_MATCHES_PAGE < $page
				|| null === $per_page
				|| self::MAX_MATCHES_LIMIT < $per_page
			) {
				return $this->error_response( 'invalid_pagination', 400, $correlation_id );
			}

			$actor = $this->actor();
			if ( null === $actor ) {
				return $this->error_response( 'simulation_actor_unavailable', 403, $correlation_id );
			}
			$gateway = $this->gateway();
			if ( null === $gateway ) {
				return $this->error_response( 'simulation_job_runtime_unavailable', 503, $correlation_id );
			}

			try {
				$result = $gateway->get_matches( $job_id, $actor['user_id'], $actor['site_id'], $page, $per_page );
			} catch ( Throwable $error ) {
				unset( $error );
				return $this->error_response( 'simulation_job_runtime_failure', 503, $correlation_id );
			}
			if ( true !== ( $result['ok'] ?? null ) ) {
				return $this->gateway_error( $result, $correlation_id );
			}

			$matches = isset( $result['matches'] ) && is_array( $result['matches'] )
				? $this->serialize_matches( $result['matches'], $page, $per_page )
				: null;
			if ( null === $matches ) {
				return $this->error_response( 'simulation_gateway_invalid_response', 500, $correlation_id );
			}

			return $this->private_response( $matches, 200, $correlation_id );
		}

		/** @return BeRocket_Growth_Suite_Simulation_Job_Gateway_Interface|null */
		public function default_gateway() {
			$gateway = null;
			if ( function_exists( 'apply_filters' ) ) {
				$gateway = apply_filters( 'berocket_growth_suite_simulation_job_gateway', null );
			}

			return $gateway instanceof BeRocket_Growth_Suite_Simulation_Job_Gateway_Interface ? $gateway : null;
		}

		public function default_permission(): bool {
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers the edit_products collection capability used by the existing label CPT.
			return function_exists( 'current_user_can' ) && current_user_can( 'edit_products' );
		}

		/** @return array<string,mixed> */
		public function default_actor(): array {
			$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			$site_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
			$roles   = array();
			if ( function_exists( 'wp_get_current_user' ) ) {
				$user  = wp_get_current_user();
				$roles = is_array( $user->roles ) ? array_values( $user->roles ) : array();
			}
			$registered_roles = array();
			if ( function_exists( 'wp_roles' ) ) {
				$registry         = wp_roles();
				$registered_roles = is_array( $registry->roles )
					? array_keys( $registry->roles )
					: array();
			}

			return array(
				'user_id'          => $user_id,
				'site_id'          => $site_id,
				'roles'            => $roles,
				'registered_roles' => $registered_roles,
			);
		}

		public function default_correlation_id(): ?string {
			if ( function_exists( 'wp_generate_uuid4' ) ) {
				return wp_generate_uuid4();
			}

			try {
				$bytes    = random_bytes( 16 );
				$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
				$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
				$hex      = bin2hex( $bytes );

				return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
			} catch ( Throwable $error ) {
				unset( $error );
				return null;
			}
		}

		/** @return BeRocket_Growth_Suite_Simulation_Job_Gateway_Interface|null */
		private function gateway() {
			try {
				$gateway = call_user_func( $this->gateway_resolver );
			} catch ( Throwable $error ) {
				unset( $error );
				return null;
			}

			return $gateway instanceof BeRocket_Growth_Suite_Simulation_Job_Gateway_Interface ? $gateway : null;
		}

		/** @return array<string,mixed>|null */
		private function actor(): ?array {
			try {
				$actor = call_user_func( $this->actor_callback );
			} catch ( Throwable $error ) {
				unset( $error );
				return null;
			}
			if ( ! is_array( $actor ) ) {
				return null;
			}
			if (
				! isset( $actor['user_id'], $actor['site_id'], $actor['roles'], $actor['registered_roles'] )
				|| ! is_int( $actor['user_id'] )
				|| 1 > $actor['user_id']
				|| ! is_int( $actor['site_id'] )
				|| 1 > $actor['site_id']
				|| ! $this->valid_role_list( $actor['roles'] )
				|| ! $this->valid_role_list( $actor['registered_roles'] )
			) {
				return null;
			}

			return $actor;
		}

		/**
		 * @param array<string,mixed> $params Decoded request parameters.
		 * @return array{valid:bool,code:string}
		 */
		private function validate_start_params( array $params ): array {
			$allowed = array( 'rule', 'context', 'boundary', 'batchSize' );
			if ( array_diff( array_keys( $params ), $allowed ) ) {
				return array(
					'valid' => false,
					'code'  => 'invalid_simulation_request',
				);
			}
			if (
				! isset( $params['rule'], $params['context'], $params['boundary'] )
				|| ! is_array( $params['rule'] )
				|| ! is_array( $params['context'] )
				|| ! is_array( $params['boundary'] )
			) {
				return array(
					'valid' => false,
					'code'  => 'invalid_simulation_request',
				);
			}
			try {
				$rule_inspection = ( new BeRocket_Growth_Suite_Rule_Model() )->inspect( $params['rule'] );
			} catch ( Throwable $error ) {
				unset( $error );
				$rule_inspection = array( 'valid' => false );
			}
			if (
				true !== ( $rule_inspection['valid'] ?? null )
				|| true !== ( $rule_inspection['supported'] ?? null )
				|| true !== ( $rule_inspection['renderable'] ?? null )
			) {
				return array(
					'valid' => false,
					'code'  => 'invalid_simulation_rule',
				);
			}

			$context = $params['context'];
			if ( isset( $context['roles'] ) || isset( $context['userId'] ) || isset( $context['user_id'] ) ) {
				return array(
					'valid' => false,
					'code'  => 'client_user_context_forbidden',
				);
			}
			if ( array_diff( array_keys( $context ), array( 'context', 'pageId', 'userState', 'device' ) ) ) {
				return array(
					'valid' => false,
					'code'  => 'invalid_simulation_context',
				);
			}
			if (
				! isset( $context['context'], $context['userState'], $context['device'] )
				|| ! in_array( $context['context'], array( 'archive', 'category', 'single' ), true )
				|| ! in_array( $context['userState'], array( 'guest', 'logged_in' ), true )
				|| ! in_array( $context['device'], array( 'desktop', 'tablet', 'mobile' ), true )
			) {
				return array(
					'valid' => false,
					'code'  => 'invalid_simulation_context',
				);
			}

			$boundary = $params['boundary'];
			if ( array_diff( array_keys( $boundary ), array( 'type', 'placement', 'includeVariations', 'categoryId', 'productId' ) ) ) {
				return array(
					'valid' => false,
					'code'  => 'invalid_simulation_boundary',
				);
			}
			if (
				! isset( $boundary['type'], $boundary['placement'], $boundary['includeVariations'] )
				|| $boundary['type'] !== $context['context']
				|| ! is_string( $boundary['placement'] )
				|| 1 !== preg_match( '/\A[a-z][a-z0-9_-]{0,63}\z/', $boundary['placement'] )
				|| ! is_bool( $boundary['includeVariations'] )
			) {
				return array(
					'valid' => false,
					'code'  => 'invalid_simulation_boundary',
				);
			}
			if ( 'category' === $boundary['type'] && ! $this->positive_int_value( $boundary['categoryId'] ?? null ) ) {
				return array(
					'valid' => false,
					'code'  => 'invalid_simulation_boundary',
				);
			}
			if ( 'single' === $boundary['type'] && ! $this->positive_int_value( $boundary['productId'] ?? null ) ) {
				return array(
					'valid' => false,
					'code'  => 'invalid_simulation_boundary',
				);
			}
			if ( isset( $context['pageId'] ) ) {
				if ( ! $this->positive_int_value( $context['pageId'] ) ) {
					return array(
						'valid' => false,
						'code'  => 'invalid_simulation_context',
					);
				}
				$boundary_id = 'category' === $boundary['type']
					? ( $boundary['categoryId'] ?? null )
					: ( 'single' === $boundary['type'] ? ( $boundary['productId'] ?? null ) : $context['pageId'] );
				if ( $context['pageId'] !== $boundary_id ) {
					return array(
						'valid' => false,
						'code'  => 'invalid_simulation_boundary',
					);
				}
			}

			$batch_size = $params['batchSize'] ?? self::DEFAULT_BATCH_SIZE;
			if ( ! is_int( $batch_size ) || 1 > $batch_size || self::MAX_BATCH_SIZE < $batch_size ) {
				return array(
					'valid' => false,
					'code'  => 'invalid_batch_size',
				);
			}

			return array(
				'valid' => true,
				'code'  => '',
			);
		}

		/**
		 * @param array<string,mixed> $context Public API context.
		 * @param array<string,mixed> $boundary Public API catalog boundary.
		 * @param array<string,mixed> $actor Server-derived actor context.
		 * @return array<string,mixed>
		 */
		private function canonical_context( array $context, array $boundary, array $actor ): array {
			$request = array( 'context' => $context['context'] );
			if ( isset( $context['pageId'] ) ) {
				$request['page_id'] = $context['pageId'];
			} elseif ( 'category' === $boundary['type'] ) {
				$request['page_id'] = $boundary['categoryId'];
			} elseif ( 'single' === $boundary['type'] ) {
				$request['page_id'] = $boundary['productId'];
			}

			return array(
				'request' => $request,
				'user'    => array(
					'state'            => $context['userState'],
					'roles'            => 'logged_in' === $context['userState'] ? $actor['roles'] : array(),
					'registered_roles' => $actor['registered_roles'],
				),
				'device'  => $context['device'],
				'flags'   => array(
					'preview'    => false,
					'simulation' => true,
				),
			);
		}

		/**
		 * @param array<string,mixed> $boundary Public API catalog boundary.
		 * @return array<string,mixed>
		 */
		private function canonical_boundary( array $boundary ): array {
			$result = array(
				'type'               => $boundary['type'],
				'placement'          => $boundary['placement'],
				'include_variations' => $boundary['includeVariations'],
			);
			if ( isset( $boundary['categoryId'] ) ) {
				$result['category_id'] = $boundary['categoryId'];
			}
			if ( isset( $boundary['productId'] ) ) {
				$result['product_id'] = $boundary['productId'];
			}

			return $result;
		}

		/**
		 * @param array<string,mixed> $job Internal job state.
		 * @return array<string,mixed>|null
		 */
		private function serialize_job( array $job ): ?array {
			$status      = $job['status'] ?? null;
			$count_state = $job['count_state'] ?? null;
			if (
				! $this->valid_uuid( $job['job_id'] ?? null )
				|| ! in_array( $status, array( 'queued', 'running', 'completed', 'failed', 'cancelled', 'stale' ), true )
				|| ! in_array( $count_state, array( 'partial', 'completed' ), true )
				|| ! $this->non_negative_int( $job['scanned'] ?? null )
				|| ! $this->non_negative_int( $job['matched'] ?? null )
				|| $job['matched'] > $job['scanned']
				|| ! $this->non_negative_int( $job['cursor'] ?? null )
				|| ! $this->valid_hash( $job['snapshot_hash'] ?? null )
				|| ! isset( $job['catalog_snapshot'] )
				|| ! is_array( $job['catalog_snapshot'] )
				|| ! $this->non_negative_int( $job['catalog_snapshot']['candidate_count'] ?? null )
				|| ! isset( $job['stale'] )
				|| ! is_bool( $job['stale'] )
			) {
				return null;
			}

			$candidate_count = $job['catalog_snapshot']['candidate_count'];
			if ( $candidate_count < $job['scanned'] ) {
				return null;
			}
			if (
				( 'completed' === $status
					&& ( 'completed' !== $count_state
						|| $job['stale']
						|| $candidate_count !== $job['scanned']
						|| ! $this->non_negative_int( $job['total_count'] ?? null )
						|| $job['total_count'] !== $job['matched'] ) )
				|| ( 'completed' !== $status && 'partial' !== $count_state )
				|| ( 'stale' === $status ) !== $job['stale']
			) {
				return null;
			}
			$progress = 0.0;
			if ( 0 < $candidate_count ) {
				$progress = min( 1.0, $job['scanned'] / $candidate_count );
			} elseif ( 'completed' === $status ) {
				$progress = 1.0;
			}
			$total = null;
			if ( 'completed' === $status && ! $job['stale'] && $this->non_negative_int( $job['total_count'] ?? null ) ) {
				$total = $job['total_count'];
			}

			return array(
				'jobId'        => $job['job_id'],
				'status'       => $status,
				'countState'   => $count_state,
				'scanned'      => $job['scanned'],
				'matched'      => $job['matched'],
				'total'        => $total,
				'progress'     => $progress,
				'nextCursor'   => 'running' === $status ? $job['cursor'] : null,
				'snapshotHash' => $job['snapshot_hash'],
				'stale'        => $job['stale'],
				'warningCount' => $this->non_negative_int( $job['warning_count'] ?? null ) ? $job['warning_count'] : 0,
				'warnings'     => $this->job_warnings( $job ),
			);
		}

		/**
		 * @param array<string,mixed> $matches Internal match page.
		 * @return array<string,mixed>|null
		 */
		private function serialize_matches( array $matches, int $page, int $per_page ): ?array {
			if (
				! isset( $matches['items'], $matches['count_state'], $matches['snapshot_hash'], $matches['stale'] )
				|| ! array_key_exists( 'total_count', $matches )
				|| ! is_array( $matches['items'] )
				|| ! $this->is_list( $matches['items'] )
				|| $per_page < count( $matches['items'] )
				|| ! in_array( $matches['count_state'], array( 'partial', 'completed' ), true )
				|| ! $this->valid_hash( $matches['snapshot_hash'] )
				|| ! is_bool( $matches['stale'] )
			) {
				return null;
			}

			$items = array();
			foreach ( $matches['items'] as $item ) {
				if (
					! is_array( $item )
					|| ! $this->positive_int_value( $item['product_id'] ?? null )
					|| ! isset( $item['sku'] )
					|| ! is_string( $item['sku'] )
					|| 512 < strlen( $item['sku'] )
					|| true !== ( $item['matched'] ?? null )
					|| ! isset( $item['reason_summary'] )
					|| ! is_array( $item['reason_summary'] )
				) {
					return null;
				}
				$reasons = $this->serialize_reasons( $item['reason_summary'] );
				if ( null === $reasons ) {
					return null;
				}
				$items[] = array(
					'productId' => $item['product_id'],
					'sku'       => $item['sku'],
					'matched'   => true,
					'reasons'   => $reasons,
				);
			}

			$total = $matches['total_count'] ?? null;
			if ( 'completed' !== $matches['count_state'] || $matches['stale'] ) {
				$total = null;
			} elseif ( ! $this->non_negative_int( $total ) ) {
				return null;
			}

			return array(
				'items'        => $items,
				'total'        => $total,
				'page'         => $page,
				'perPage'      => $per_page,
				'countState'   => $matches['count_state'],
				'snapshotHash' => $matches['snapshot_hash'],
				'stale'        => $matches['stale'],
			);
		}

		/**
		 * @param array<int,mixed> $reasons Internal reason summaries.
		 * @return array<int,array<string,mixed>>|null
		 */
		private function serialize_reasons( array $reasons ): ?array {
			if ( ! $this->is_list( $reasons ) || 100 < count( $reasons ) ) {
				return null;
			}
			$result = array();
			foreach ( $reasons as $reason ) {
				if (
					! is_array( $reason )
					|| ! isset( $reason['reason_code'] )
					|| ! is_string( $reason['reason_code'] )
					|| 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $reason['reason_code'] )
				) {
					return null;
				}
				$parameters = isset( $reason['parameters'] ) && is_array( $reason['parameters'] )
					? $this->safe_parameters( $reason['parameters'] )
					: array();
				$result[]   = array(
					'code'       => $reason['reason_code'],
					'parameters' => $parameters,
				);
			}

			return $result;
		}

		/**
		 * @param array<string,mixed> $parameters Reason parameters.
		 * @return array<string,mixed>
		 */
		private function safe_parameters( array $parameters ): array {
			if (
				4096 < $this->json_bytes( $parameters )
				|| 5 < $this->json_depth( $parameters )
				|| $this->contains_long_string( $parameters, 512 )
				|| $this->contains_sensitive_key( $parameters )
			) {
				return array();
			}
			foreach ( $parameters as $value ) {
				if ( is_object( $value ) || is_resource( $value ) ) {
					return array();
				}
			}

			return $parameters;
		}

		/**
		 * @param mixed $value Parameter subtree.
		 */
		private function contains_sensitive_key( $value ): bool {
			if ( ! is_array( $value ) ) {
				return false;
			}
			foreach ( $value as $key => $item ) {
				if (
					is_string( $key )
					&& in_array( strtolower( $key ), array( 'user_id', 'email', 'ip', 'ip_address', 'token', 'nonce', 'cookie', 'authorization' ), true )
				) {
					return true;
				}
				if ( $this->contains_sensitive_key( $item ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * @param array<string,mixed> $job Internal job state.
		 * @return array<int,array<string,mixed>>
		 */
		private function job_warnings( array $job ): array {
			$warnings = array();
			$reasons  = isset( $job['stale_reasons'] ) && is_array( $job['stale_reasons'] ) ? $job['stale_reasons'] : array();
			foreach ( array_slice( $reasons, 0, 10 ) as $reason ) {
				if ( is_string( $reason ) && 1 === preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $reason ) ) {
					$warnings[] = array(
						'code'       => $reason,
						'parameters' => array(),
					);
				}
			}
			if ( isset( $job['error_code'] ) && is_string( $job['error_code'] ) && 1 === preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $job['error_code'] ) ) {
				$warnings[] = array(
					'code'       => $job['error_code'],
					'parameters' => array(),
				);
			}

			return $warnings;
		}

		/**
		 * @param array<string,mixed> $result Gateway failure envelope.
		 * @return WP_REST_Response
		 */
		private function gateway_error( array $result, string $correlation_id ) {
			$status = isset( $result['status'] ) && is_int( $result['status'] ) ? $result['status'] : 500;
			$map    = array(
				400 => 'invalid_simulation_request',
				404 => 'simulation_job_not_found',
				409 => 'simulation_job_conflict',
				429 => 'simulation_job_rate_limited',
				503 => 'simulation_job_runtime_unavailable',
			);
			if ( ! isset( $map[ $status ] ) ) {
				$status = 500;
			}
			$code        = $map[ $status ] ?? 'simulation_gateway_invalid_response';
			$details     = array();
			$retry_after = null;
			if ( 400 === $status && isset( $result['errors'] ) && is_array( $result['errors'] ) ) {
				$details = array( 'errors' => $this->safe_validation_errors( $result['errors'] ) );
			}
			if ( 429 === $status && isset( $result['retry_after'] ) && is_int( $result['retry_after'] ) && 1 <= $result['retry_after'] && 3600 >= $result['retry_after'] ) {
				$retry_after = $result['retry_after'];
			}

			return $this->error_response( $code, $status, $correlation_id, $details, $retry_after );
		}

		/**
		 * @param array<int,mixed> $errors Raw gateway validation errors.
		 * @return array<int,array{code:string,path:string}>
		 */
		private function safe_validation_errors( array $errors ): array {
			$result = array();
			foreach ( array_slice( $errors, 0, 20 ) as $error ) {
				if (
					! is_array( $error )
					|| ! isset( $error['code'], $error['path'] )
					|| ! is_string( $error['code'] )
					|| ! is_string( $error['path'] )
					|| 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $error['code'] )
					|| 256 < strlen( $error['path'] )
				) {
					continue;
				}
				$result[] = array(
					'code' => $error['code'],
					'path' => $error['path'],
				);
			}

			return $result;
		}

		/**
		 * @param array<string,mixed> $details Bounded public error details.
		 * @return WP_REST_Response
		 */
		private function error_response( string $code, int $status, string $correlation_id, array $details = array(), ?int $retry_after = null ) {
			$response = $this->private_response(
				array(
					'code'          => $code,
					'message'       => $this->error_message( $code ),
					'correlationId' => $correlation_id,
					'details'       => $details,
				),
				$status,
				$correlation_id
			);
			if ( null !== $retry_after ) {
				$response->header( 'Retry-After', (string) $retry_after );
			}

			return $response;
		}

		/**
		 * @param mixed $data Response data.
		 * @return WP_REST_Response
		 */
		private function private_response( $data, int $status, string $correlation_id ) {
			$response = new WP_REST_Response( $data, $status );
			$response->header( 'Cache-Control', 'private, no-store' );
			$response->header( 'X-Correlation-ID', $correlation_id );

			return $response;
		}

		private function error_message( string $code ): string {
			switch ( $code ) {
				case 'invalid_idempotency_key':
					return function_exists( '__' ) ? __( 'Provide a valid idempotency key.', 'BeRocket_products_label_domain' ) : 'Provide a valid idempotency key.';
				case 'client_user_context_forbidden':
					return function_exists( '__' ) ? __( 'User roles must be derived by the server.', 'BeRocket_products_label_domain' ) : 'User roles must be derived by the server.';
				case 'invalid_simulation_context':
					return function_exists( '__' ) ? __( 'The simulation context is invalid.', 'BeRocket_products_label_domain' ) : 'The simulation context is invalid.';
				case 'invalid_simulation_rule':
					return function_exists( '__' ) ? __( 'The simulation rule is invalid.', 'BeRocket_products_label_domain' ) : 'The simulation rule is invalid.';
				case 'invalid_simulation_boundary':
					return function_exists( '__' ) ? __( 'The catalog boundary is invalid.', 'BeRocket_products_label_domain' ) : 'The catalog boundary is invalid.';
				case 'invalid_batch_size':
					return function_exists( '__' ) ? __( 'The simulation batch size is invalid.', 'BeRocket_products_label_domain' ) : 'The simulation batch size is invalid.';
				case 'invalid_simulation_request':
					return function_exists( '__' ) ? __( 'The simulation request is invalid.', 'BeRocket_products_label_domain' ) : 'The simulation request is invalid.';
				case 'simulation_payload_too_large':
					return function_exists( '__' ) ? __( 'The simulation request is too large.', 'BeRocket_products_label_domain' ) : 'The simulation request is too large.';
				case 'invalid_job_id':
					return function_exists( '__' ) ? __( 'The simulation job identifier is invalid.', 'BeRocket_products_label_domain' ) : 'The simulation job identifier is invalid.';
				case 'invalid_pagination':
					return function_exists( '__' ) ? __( 'The requested page is invalid.', 'BeRocket_products_label_domain' ) : 'The requested page is invalid.';
				case 'simulation_actor_unavailable':
					return function_exists( '__' ) ? __( 'The current user context is unavailable.', 'BeRocket_products_label_domain' ) : 'The current user context is unavailable.';
				case 'simulation_job_not_found':
					return function_exists( '__' ) ? __( 'The simulation job was not found.', 'BeRocket_products_label_domain' ) : 'The simulation job was not found.';
				case 'simulation_job_conflict':
					return function_exists( '__' ) ? __( 'The simulation job state has changed.', 'BeRocket_products_label_domain' ) : 'The simulation job state has changed.';
				case 'simulation_job_rate_limited':
					return function_exists( '__' ) ? __( 'Too many simulation jobs were requested.', 'BeRocket_products_label_domain' ) : 'Too many simulation jobs were requested.';
				case 'simulation_job_runtime_unavailable':
				case 'simulation_job_runtime_failure':
					return function_exists( '__' ) ? __( 'Catalog simulation is temporarily unavailable.', 'BeRocket_products_label_domain' ) : 'Catalog simulation is temporarily unavailable.';
				case 'simulation_gateway_invalid_response':
					return function_exists( '__' ) ? __( 'Catalog simulation returned an invalid response.', 'BeRocket_products_label_domain' ) : 'Catalog simulation returned an invalid response.';
				default:
					return function_exists( '__' ) ? __( 'Catalog simulation failed.', 'BeRocket_products_label_domain' ) : 'Catalog simulation failed.';
			}
		}

		private function correlation_id(): string {
			try {
				$value = call_user_func( $this->correlation_factory );
			} catch ( Throwable $error ) {
				unset( $error );
				$value = null;
			}

			return $this->valid_uuid( $value ) ? $value : '00000000-0000-4000-8000-000000000000';
		}

		/** @param mixed $request WordPress REST request. */
		private function request_body( $request ): string {
			return is_object( $request ) && method_exists( $request, 'get_body' ) ? (string) $request->get_body() : '';
		}

		/**
		 * @param mixed $request WordPress REST request.
		 * @return mixed
		 */
		private function request_json( $request ) {
			return is_object( $request ) && method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : null;
		}

		/** @param mixed $request WordPress REST request. */
		private function request_header( $request, string $key ): string {
			return is_object( $request ) && method_exists( $request, 'get_header' ) ? (string) $request->get_header( $key ) : '';
		}

		/**
		 * @param mixed $request WordPress REST request.
		 * @return mixed
		 */
		private function request_param( $request, string $key ) {
			return is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( $key ) : null;
		}

		/** @param mixed $value Role list. */
		private function valid_role_list( $value ): bool {
			if ( ! is_array( $value ) || ! $this->is_list( $value ) || 1000 < count( $value ) ) {
				return false;
			}
			foreach ( $value as $role ) {
				if ( ! is_string( $role ) || 1 !== preg_match( '/\A[a-z0-9_-]{1,64}\z/', $role ) ) {
					return false;
				}
			}

			return true;
		}

		private function valid_idempotency_key( string $value ): bool {
			$length = strlen( $value );

			return 16 <= $length && 128 >= $length && 1 === preg_match( '/\A[A-Za-z0-9._:-]+\z/', $value );
		}

		/** @param mixed $value UUID candidate. */
		private function valid_uuid( $value ): bool {
			return is_string( $value ) && 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value );
		}

		/** @param mixed $value Hash candidate. */
		private function valid_hash( $value ): bool {
			return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/', $value );
		}

		/** @param mixed $value Integer candidate. */
		private function positive_int_value( $value ): bool {
			return is_int( $value ) && 0 < $value;
		}

		/** @param mixed $value Integer candidate. */
		private function non_negative_int( $value ): bool {
			return is_int( $value ) && 0 <= $value;
		}

		/** @param mixed $value Request value. */
		private function positive_int( $value, int $fallback ): ?int {
			if ( null === $value || '' === $value ) {
				return $fallback;
			}
			if ( is_int( $value ) ) {
				return 0 < $value ? $value : null;
			}
			if ( is_string( $value ) && 1 === preg_match( '/\A[1-9][0-9]*\z/', $value ) ) {
				$integer = (int) $value;

				return 0 < $integer ? $integer : null;
			}

			return null;
		}

		/** @param mixed $value JSON-like value. */
		private function json_depth( $value, int $depth = 1 ): int {
			if ( ! is_array( $value ) || array() === $value ) {
				return $depth;
			}
			$maximum = $depth;
			foreach ( $value as $item ) {
				$maximum = max( $maximum, $this->json_depth( $item, $depth + 1 ) );
				if ( self::MAX_JSON_DEPTH < $maximum ) {
					break;
				}
			}

			return $maximum;
		}

		/** @param mixed $value JSON-like value. */
		private function contains_long_string( $value, int $maximum = self::MAX_STRING_BYTES ): bool {
			if ( is_string( $value ) ) {
				return $maximum < strlen( $value );
			}
			if ( ! is_array( $value ) ) {
				return is_object( $value ) || is_resource( $value );
			}
			foreach ( $value as $key => $item ) {
				if ( ( is_string( $key ) && $maximum < strlen( $key ) ) || $this->contains_long_string( $item, $maximum ) ) {
					return true;
				}
			}

			return false;
		}

		/** @param mixed $value JSON-like value. */
		private function json_bytes( $value ): int {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Controller tests run before WordPress bootstrap.
			$json = json_encode( $value, JSON_UNESCAPED_SLASHES );

			return false === $json ? self::MAX_BODY_BYTES + 1 : strlen( $json );
		}

		/** @param array<mixed> $value Array candidate. */
		private function is_list( array $value ): bool {
			return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
		}
	}
}
