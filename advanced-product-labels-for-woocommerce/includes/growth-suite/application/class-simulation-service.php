<?php
/**
 * Read-only one-product simulation and reason-provider extension host.
 */

require_once __DIR__ . '/../domain/class-rule-model.php';

if ( ! class_exists( 'BeRocket_Growth_Suite_Simulation_Service' ) ) {
	class BeRocket_Growth_Suite_Simulation_Service {
		/** @var object */
		private $evaluator;

		/** @var array<string,object> */
		private $reason_providers = array();

		/** @var BeRocket_Growth_Suite_Rule_Model */
		private $model;

		/**
		 * @param mixed                                  $evaluator Shared evaluator exposing evaluate().
		 * @param BeRocket_Growth_Suite_Rule_Model|null $model     Persisted schema-v2 inspector.
		 */
		public function __construct( $evaluator, ?BeRocket_Growth_Suite_Rule_Model $model = null ) {
			if ( ! is_object( $evaluator ) || ! is_callable( array( $evaluator, 'evaluate' ) ) ) {
				throw new InvalidArgumentException( 'A callable rule evaluator is required.' );
			}

			$this->evaluator = $evaluator;
			$this->model     = null === $model ? new BeRocket_Growth_Suite_Rule_Model() : $model;
		}

		/**
		 * @param mixed $provider Provider exposing condition_type() and explain().
		 */
		public function register_reason_provider( $provider ): bool {
			if (
				! is_object( $provider )
				|| ! is_callable( array( $provider, 'condition_type' ) )
				|| ! is_callable( array( $provider, 'explain' ) )
			) {
				return false;
			}

			try {
				$type = call_user_func( array( $provider, 'condition_type' ) );
			} catch ( Throwable $error ) {
				return false;
			}

			if ( ! is_string( $type ) || ! $this->is_stable_type( $type ) || isset( $this->reason_providers[ $type ] ) ) {
				return false;
			}

			$this->reason_providers[ $type ] = $provider;
			return true;
		}

		public function has_reason_provider( string $type ): bool {
			return isset( $this->reason_providers[ $type ] );
		}

		/**
		 * Simulate one product by delegating to the shared evaluator.
		 *
		 * @param array<string,mixed> $rule    Canonical rule.
		 * @param array<string,mixed> $context Explicit simulation context.
		 * @return array<string,mixed>
		 */
		public function simulate( array $rule, array $context ): array {
			if (
				isset( $context['product_ids'] )
				|| ! isset( $context['product_id'] )
				|| ! is_int( $context['product_id'] )
				|| $context['product_id'] <= 0
			) {
				return array(
					'matched'     => false,
					'reason_code' => 'simulation_single_product_required',
				);
			}

			$resolved = $this->resolve_rule( $rule );
			if ( ! $resolved['valid'] ) {
				return $this->invalid_simulation_result(
					'invalid_rule',
					$resolved['errors'],
					$context,
					$resolved['model_version'],
					null
				);
			}

			try {
				$evaluation = call_user_func(
					array( $this->evaluator, 'evaluate' ),
					$resolved['rule'],
					$context
				);
			} catch ( Throwable $error ) {
				unset( $error );
				return $this->invalid_simulation_result(
					'invalid_rule',
					array( $this->error( 'evaluator_failed', '$' ) ),
					$context,
					$resolved['model_version'],
					$resolved['rule_hash']
				);
			}

			if ( ! is_array( $evaluation ) ) {
				return $this->invalid_simulation_result(
					'invalid_rule',
					array( $this->error( 'invalid_evaluator_result', '$' ) ),
					$context,
					$resolved['model_version'],
					$resolved['rule_hash']
				);
			}

			if (
				$resolved['schema_v2']
				&& ! $this->is_canonical_schema_v2_evaluation( $evaluation, $resolved['rule_hash'] )
			) {
				return $this->invalid_simulation_result(
					'invalid_rule',
					array( $this->error( 'invalid_evaluator_result', '$' ) ),
					$context,
					$resolved['model_version'],
					$resolved['rule_hash']
				);
			}

			$result            = $evaluation;
			$result['matched'] = isset( $evaluation['matched'] ) && true === $evaluation['matched'];
			$result['context'] = $context;
			$result['reasons'] = isset( $evaluation['root'] ) && is_array( $evaluation['root'] )
				? $this->collect_reasons( $evaluation['root'], $context )
				: array();

			return $result;
		}

		/**
		 * Resolve a direct canonical model or the authoritative rule_model from a
		 * persisted label payload. Generic legacy/root input remains delegated to
		 * the existing evaluator boundary.
		 *
		 * @param array<string,mixed> $rule Simulation input.
		 * @return array<string,mixed>
		 */
		private function resolve_rule( array $rule ): array {
			$persisted_payload = array_key_exists( 'rule_model', $rule );
			$candidate         = $persisted_payload ? $rule['rule_model'] : $rule;

			if ( ! $persisted_payload ) {
				return array(
					'valid'         => true,
					'schema_v2'     => false,
					'model_version' => null,
					'rule_hash'     => null,
					'rule'          => $rule,
					'errors'        => array(),
				);
			}

			$model_version = is_array( $candidate )
				&& isset( $candidate['schema_version'] )
				&& is_int( $candidate['schema_version'] )
				? $candidate['schema_version']
				: null;
			$inspection    = $this->model->inspect( $candidate );
			if (
				true !== $inspection['valid']
				|| true !== $inspection['supported']
				|| true !== $inspection['renderable']
				|| ! isset( $inspection['model'] )
				|| ! is_array( $inspection['model'] )
			) {
				return array(
					'valid'         => false,
					'schema_v2'     => true,
					'model_version' => $model_version,
					'rule_hash'     => null,
					'rule'          => array(),
					'errors'        => isset( $inspection['errors'] ) && is_array( $inspection['errors'] )
						? $inspection['errors']
						: array( $this->error( 'invalid_rule_model', '$' ) ),
				);
			}

			try {
				$rule_hash = $this->model->semantic_hash( $inspection['model'] );
			} catch ( Throwable $error ) {
				unset( $error );
				return array(
					'valid'         => false,
					'schema_v2'     => true,
					'model_version' => $model_version,
					'rule_hash'     => null,
					'rule'          => array(),
					'errors'        => array( $this->error( 'invalid_rule_model', '$' ) ),
				);
			}

			return array(
				'valid'         => true,
				'schema_v2'     => true,
				'model_version' => 2,
				'rule_hash'     => $rule_hash,
				'rule'          => $inspection['model'],
				'errors'        => array(),
			);
		}

		/**
		 * @param array<string,mixed> $evaluation Evaluator result.
		 */
		private function is_canonical_schema_v2_evaluation( array $evaluation, ?string $rule_hash ): bool {
			return isset( $evaluation['matched'] )
				&& is_bool( $evaluation['matched'] )
				&& 2 === ( $evaluation['model_version'] ?? null )
				&& ( $evaluation['rule_hash'] ?? null ) === $rule_hash
				&& isset( $evaluation['root'] )
				&& is_array( $evaluation['root'] )
				&& isset( $evaluation['root']['matched'] )
				&& is_bool( $evaluation['root']['matched'] )
				&& $evaluation['matched'] === $evaluation['root']['matched']
				&& ! array_key_exists( 'result', $evaluation );
		}

		/**
		 * @param string                                   $reason_code   Stable reason code.
		 * @param array<int,array<string,string>>          $errors        Bounded validation errors.
		 * @param array<string,mixed>                      $context       Simulation context.
		 * @param int|null                                 $model_version Source model version.
		 * @param string|null                              $rule_hash     Canonical hash when available.
		 * @return array<string,mixed>
		 */
		private function invalid_simulation_result(
			string $reason_code,
			array $errors,
			array $context,
			?int $model_version,
			?string $rule_hash
		): array {
			$parameters = array( 'errors' => $errors );
			$root       = array(
				'matched'     => false,
				'node_id'     => '',
				'reason_code' => $reason_code,
				'parameters'  => $parameters,
				'children'    => array(),
			);

			return array(
				'matched'       => false,
				'model_version' => $model_version,
				'rule_hash'     => $rule_hash,
				'root'          => $root,
				'context'       => $context,
				'reasons'       => array(
					array(
						'reason_code' => $reason_code,
						'parameters'  => $parameters,
					),
				),
				'reason_code'   => $reason_code,
				'errors'        => $errors,
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
		 * @param array<string,mixed> $node    Evaluator result node.
		 * @param array<string,mixed> $context Explicit simulation context.
		 * @return array<int,array<string,mixed>>
		 */
		private function collect_reasons( array $node, array $context ): array {
			$reasons = array();

			if ( isset( $node['reason_code'] ) && is_string( $node['reason_code'] ) ) {
				$reasons[] = $this->explain_node( $node, $context );
			}

			if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
				foreach ( $node['children'] as $child ) {
					if ( is_array( $child ) ) {
						$reasons = array_merge( $reasons, $this->collect_reasons( $child, $context ) );
					}
				}
			}

			return $reasons;
		}

		/**
		 * @param array<string,mixed> $node    Evaluator result node.
		 * @param array<string,mixed> $context Explicit simulation context.
		 * @return array<string,mixed>
		 */
		private function explain_node( array $node, array $context ): array {
			$reason = array(
				'reason_code' => $node['reason_code'],
				'parameters'  => isset( $node['parameters'] ) && is_array( $node['parameters'] )
					? $node['parameters']
					: array(),
			);

			$type = isset( $node['condition_type'] ) && is_string( $node['condition_type'] )
				? $node['condition_type']
				: '';

			if ( ! isset( $this->reason_providers[ $type ] ) ) {
				return $reason;
			}

			try {
				$provided = call_user_func(
					array( $this->reason_providers[ $type ], 'explain' ),
					$node,
					$context
				);
			} catch ( Throwable $error ) {
				return $reason;
			}

			if (
				! is_array( $provided )
				|| ! isset( $provided['reason_code'] )
				|| ! is_string( $provided['reason_code'] )
			) {
				return $reason;
			}

			$reason['reason_code'] = $provided['reason_code'];
			$reason['parameters']  = isset( $provided['parameters'] ) && is_array( $provided['parameters'] )
				? $provided['parameters']
				: array();

			return $reason;
		}

		private function is_stable_type( string $type ): bool {
			return 1 === preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $type );
		}
	}
}
