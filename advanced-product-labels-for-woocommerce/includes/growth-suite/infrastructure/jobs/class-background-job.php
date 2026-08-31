<?php
/**
 * Shared bounded/resumable base for non-interactive Growth Suite jobs.
 *
 * Consumers own durable storage, handlers, retention, retry and concurrency
 * policy. The scheduler receives only a minimal versioned job reference.
 */

if ( ! class_exists( 'BeRocket_Growth_Suite_Background_Job' ) ) {
	class BeRocket_Growth_Suite_Background_Job {
		const SCHEMA_VERSION = 1;
		const GROUP          = 'brapl-growth-suite';
		const ACTION         = 'berocket_growth_suite_background_job';

		/** @var object */
		private $action_scheduler;

		/** @var object */
		private $wp_cron;

		/** @var object */
		private $store;

		/** @var object */
		private $authorizer;

		/** @var object */
		private $logger;

		/**
		 * The injected adapters deliberately keep this base independent from
		 * Action Scheduler/WP-Cron globals and any concrete job persistence.
		 *
		 * @param object $action_scheduler Adapter with availability/enqueue/unschedule.
		 * @param object $wp_cron          Adapter with enqueue/unschedule.
		 * @param object $store            Durable consumer job-record adapter.
		 * @param object $authorizer       Stored-owner capability checker.
		 * @param object $logger           Stable-code/count logger.
		 */
		public function __construct( $action_scheduler, $wp_cron, $store, $authorizer, $logger ) {
			foreach ( array( $action_scheduler, $wp_cron, $store, $authorizer, $logger ) as $dependency ) {
				if ( ! is_object( $dependency ) ) {
					throw new InvalidArgumentException( 'Background-job dependencies must be objects.' );
				}
			}

			$this->action_scheduler = $action_scheduler;
			$this->wp_cron          = $wp_cron;
			$this->store            = $store;
			$this->authorizer       = $authorizer;
			$this->logger           = $logger;
		}

		/**
		 * Schedule a new durable job or return its existing idempotent record.
		 *
		 * @param array<string,mixed> $job Durable consumer record.
		 * @return array<string,mixed>
		 */
		public function schedule( array $job ): array {
			if ( ! $this->valid_job( $job ) ) {
				$this->log( 'invalid_background_job', $job );

				return $this->failure( 'invalid_background_job' );
			}

			$existing = $this->existing_job( $job );
			if ( is_array( $existing ) ) {
				return array(
					'ok'         => true,
					'idempotent' => true,
					'job'        => $existing,
				);
			}

			return $this->dispatch( $job );
		}

		/**
		 * Resume a delayed non-terminal record without a second idempotency lookup.
		 *
		 * @param array<string,mixed> $job Durable consumer record.
		 * @return array<string,mixed>
		 */
		public function resume( array $job ): array {
			if ( ! $this->valid_job( $job ) ) {
				$this->log( 'invalid_background_job', $job );

				return $this->failure( 'invalid_background_job' );
			}
			if ( $this->is_terminal( $job['status'] ?? null ) ) {
				return array(
					'ok'         => true,
					'idempotent' => true,
					'job'        => $job,
				);
			}

			return $this->dispatch( $job );
		}

		/**
		 * Recheck the durable owner/capability boundary at execution time.
		 *
		 * @param array<string,mixed> $job Durable consumer record.
		 */
		public function can_execute( array $job ): bool {
			if ( ! $this->valid_job( $job ) || ! method_exists( $this->authorizer, 'allows' ) ) {
				$this->log( 'background_job_forbidden', $job );

				return false;
			}

			try {
				$allowed = true === $this->authorizer->allows(
					$job['owner_user_id'],
					$job['site_id'],
					$job['capability']
				);
			} catch ( Throwable $error ) {
				unset( $error );
				$allowed = false;
			}
			if ( ! $allowed ) {
				$this->log( 'background_job_forbidden', $job );
			}

			return $allowed;
		}

		/**
		 * Cancel one job and unschedule its future invocation where applicable.
		 *
		 * @param array<string,mixed> $job Durable consumer record.
		 * @return array<string,mixed>
		 */
		public function cancel( array $job ): array {
			if ( ! $this->valid_job( $job ) ) {
				$this->log( 'invalid_background_job', $job );

				return $this->failure( 'invalid_background_job' );
			}
			if ( $this->is_terminal( $job['status'] ?? null ) ) {
				return array(
					'ok'         => true,
					'idempotent' => true,
					'job'        => $job,
				);
			}

			if ( 'action_scheduler' === ( $job['scheduler'] ?? null ) && 'delayed' !== ( $job['status'] ?? null ) ) {
				if ( ! $this->unschedule_action_scheduler( $job ) ) {
					$this->log( 'background_unschedule_failed', $job );

					return $this->failure( 'background_unschedule_failed' );
				}
			} elseif ( 'wp_cron' === ( $job['scheduler'] ?? null ) ) {
				if ( ! $this->unschedule_wp_cron( $job ) ) {
					$this->log( 'background_unschedule_failed', $job );

					return $this->failure( 'background_unschedule_failed' );
				}
			}

			if ( ! $this->mark_cancelled( $job['job_id'] ) ) {
				$this->log( 'background_cancel_failed', $job );

				return $this->failure( 'background_cancel_failed' );
			}

			$job['status'] = 'cancelled';
			$this->log( 'background_job_cancelled', $job );

			return array(
				'ok'         => true,
				'idempotent' => false,
				'job'        => $job,
			);
		}

		/**
		 * Apply the approved deactivation or uninstall lifecycle policy.
		 *
		 * @param array<string,mixed> $job Durable consumer record.
		 * @return array<string,mixed>
		 */
		public function cleanup( array $job, string $lifecycle ): array {
			if ( ! in_array( $lifecycle, array( 'deactivation', 'uninstall' ), true ) ) {
				return $this->failure( 'invalid_background_cleanup' );
			}

			$cancelled = $this->cancel( $job );
			if ( true !== ( $cancelled['ok'] ?? null ) ) {
				return $cancelled;
			}
			if ( 'deactivation' === $lifecycle ) {
				return $cancelled;
			}
			if ( ! $this->delete_job_and_results( $cancelled['job']['job_id'] ) ) {
				$this->log( 'background_uninstall_cleanup_failed', $cancelled['job'] );

				return $this->failure( 'background_uninstall_cleanup_failed' );
			}

			$this->log( 'background_job_uninstalled', $cancelled['job'] );

			return array(
				'ok'         => true,
				'idempotent' => $cancelled['idempotent'],
				'job'        => $cancelled['job'],
			);
		}

		/**
		 * @param array<string,mixed> $job Durable consumer record.
		 * @return array<string,mixed>
		 */
		public function scheduler_arguments( array $job ): array {
			return array(
				'schema_version' => $job['schema_version'],
				'handler'        => $job['handler'],
				'job_id'         => $job['job_id'],
			);
		}

		/**
		 * @param array<string,mixed> $job Durable consumer record.
		 * @return array<string,mixed>
		 */
		private function dispatch( array $job ): array {
			$availability = $this->action_scheduler_availability();
			if ( 'pending' === $availability ) {
				$job['status']    = 'delayed';
				$job['scheduler'] = 'action_scheduler';
				if ( ! $this->save_scheduled( $job ) ) {
					$this->log( 'background_store_failed', $job );

					return $this->failure( 'background_store_failed' );
				}
				$this->log( 'action_scheduler_pending', $job );

				return array(
					'ok'         => true,
					'idempotent' => false,
					'code'       => 'action_scheduler_pending',
					'job'        => $job,
				);
			}

			if ( 'ready' === $availability ) {
				return $this->queue_action_scheduler( $job );
			}

			return $this->queue_wp_cron( $job );
		}

		/**
		 * @param array<string,mixed> $job Durable consumer record.
		 * @return array<string,mixed>
		 */
		private function queue_action_scheduler( array $job ): array {
			$job['status']    = 'queued';
			$job['scheduler'] = 'action_scheduler';
			if ( ! $this->save_scheduled( $job ) ) {
				$this->log( 'background_store_failed', $job );

				return $this->failure( 'background_store_failed' );
			}
			if ( ! method_exists( $this->action_scheduler, 'enqueue' ) ) {
				return $this->schedule_failure( $job );
			}
			try {
				$scheduled = true === $this->action_scheduler->enqueue(
					self::ACTION,
					$this->scheduler_arguments( $job ),
					self::GROUP
				);
			} catch ( Throwable $error ) {
				unset( $error );
				$scheduled = false;
			}

			return $scheduled ? $this->scheduled_result( $job ) : $this->schedule_failure( $job );
		}

		/**
		 * @param array<string,mixed> $job Durable consumer record.
		 * @return array<string,mixed>
		 */
		private function queue_wp_cron( array $job ): array {
			$job['status']    = 'queued';
			$job['scheduler'] = 'wp_cron';
			if ( ! $this->save_scheduled( $job ) ) {
				$this->log( 'background_store_failed', $job );

				return $this->failure( 'background_store_failed' );
			}
			if ( ! method_exists( $this->wp_cron, 'enqueue' ) ) {
				return $this->schedule_failure( $job );
			}
			try {
				$scheduled = true === $this->wp_cron->enqueue(
					self::ACTION,
					$this->scheduler_arguments( $job )
				);
			} catch ( Throwable $error ) {
				unset( $error );
				$scheduled = false;
			}

			return $scheduled ? $this->scheduled_result( $job ) : $this->schedule_failure( $job );
		}

		/**
		 * @param array<string,mixed> $job Durable consumer record.
		 * @return array<string,mixed>
		 */
		private function scheduled_result( array $job ): array {
			$this->log( 'background_job_queued', $job );

			return array(
				'ok'         => true,
				'idempotent' => false,
				'job'        => $job,
			);
		}

		/**
		 * @param array<string,mixed> $job Durable consumer record.
		 * @return array<string,mixed>
		 */
		private function schedule_failure( array $job ): array {
			$job['status'] = 'failed';
			$this->save_scheduled( $job );
			$this->log( 'background_schedule_failed', $job );

			return array(
				'ok'         => false,
				'idempotent' => false,
				'code'       => 'background_schedule_failed',
				'job'        => $job,
			);
		}

		/**
		 * @param array<string,mixed> $job Durable consumer record.
		 * @return array<string,mixed>|null
		 */
		private function existing_job( array $job ): ?array {
			if ( ! method_exists( $this->store, 'find_by_idempotency' ) ) {
				return null;
			}
			try {
				$existing = $this->store->find_by_idempotency(
					$job['handler'],
					$job['site_id'],
					$job['idempotency_key']
				);
			} catch ( Throwable $error ) {
				unset( $error );
				$existing = null;
			}

			return is_array( $existing ) && $this->valid_job( $existing ) ? $existing : null;
		}

		/** @param array<string,mixed> $job Durable consumer record. */
		private function save_scheduled( array $job ): bool {
			if ( ! method_exists( $this->store, 'save_scheduled' ) ) {
				return false;
			}
			try {
				return true === $this->store->save_scheduled( $job );
			} catch ( Throwable $error ) {
				unset( $error );

				return false;
			}
		}

		private function mark_cancelled( string $job_id ): bool {
			if ( ! method_exists( $this->store, 'mark_cancelled' ) ) {
				return false;
			}
			try {
				return true === $this->store->mark_cancelled( $job_id );
			} catch ( Throwable $error ) {
				unset( $error );

				return false;
			}
		}

		private function delete_job_and_results( string $job_id ): bool {
			if ( ! method_exists( $this->store, 'delete_job_and_results' ) ) {
				return false;
			}
			try {
				return true === $this->store->delete_job_and_results( $job_id );
			} catch ( Throwable $error ) {
				unset( $error );

				return false;
			}
		}

		/** @param array<string,mixed> $job Durable consumer record. */
		private function unschedule_action_scheduler( array $job ): bool {
			if ( ! method_exists( $this->action_scheduler, 'unschedule' ) ) {
				return false;
			}
			try {
				return true === $this->action_scheduler->unschedule(
					self::ACTION,
					$this->scheduler_arguments( $job ),
					self::GROUP
				);
			} catch ( Throwable $error ) {
				unset( $error );

				return false;
			}
		}

		/** @param array<string,mixed> $job Durable consumer record. */
		private function unschedule_wp_cron( array $job ): bool {
			if ( ! method_exists( $this->wp_cron, 'unschedule' ) ) {
				return false;
			}
			try {
				return true === $this->wp_cron->unschedule( self::ACTION, $this->scheduler_arguments( $job ) );
			} catch ( Throwable $error ) {
				unset( $error );

				return false;
			}
		}

		private function action_scheduler_availability(): string {
			if ( ! method_exists( $this->action_scheduler, 'availability' ) ) {
				return 'unavailable';
			}
			try {
				$availability = $this->action_scheduler->availability();
			} catch ( Throwable $error ) {
				unset( $error );
				$availability = 'unavailable';
			}

			return in_array( $availability, array( 'ready', 'pending', 'unavailable' ), true )
				? $availability
				: 'unavailable';
		}

		/** @param array<string,mixed> $job Durable consumer record. */
		private function valid_job( array $job ): bool {
			$allowed = array(
				'schema_version',
				'job_id',
				'handler',
				'idempotency_key',
				'owner_user_id',
				'site_id',
				'capability',
				'checkpoint',
				'policy',
				'status',
				'scheduler',
			);
			if (
				array_diff( array_keys( $job ), $allowed )
				|| $this->contains_sensitive_key( $job )
				|| self::SCHEMA_VERSION !== ( $job['schema_version'] ?? null )
				|| ! $this->valid_uuid( $job['job_id'] ?? null )
				|| ! $this->valid_identifier( $job['handler'] ?? null )
				|| ! $this->valid_idempotency_key( $job['idempotency_key'] ?? null )
				|| ! $this->positive_int( $job['owner_user_id'] ?? null )
				|| ! $this->positive_int( $job['site_id'] ?? null )
				|| ! $this->valid_identifier( $job['capability'] ?? null )
				|| ! $this->valid_checkpoint( $job['checkpoint'] ?? null )
				|| ! $this->valid_policy( $job['policy'] ?? null )
			) {
				return false;
			}
			if ( isset( $job['status'] ) && ! in_array( $job['status'], array( 'queued', 'delayed', 'running', 'completed', 'failed', 'cancelled' ), true ) ) {
				return false;
			}
			if ( isset( $job['scheduler'] ) && ! in_array( $job['scheduler'], array( 'action_scheduler', 'wp_cron' ), true ) ) {
				return false;
			}

			return true;
		}

		/** @param mixed $policy Consumer-defined bounded execution policy. */
		private function valid_policy( $policy ): bool {
			if ( ! is_array( $policy ) || array_diff( array_keys( $policy ), array( 'max_batch_size', 'max_seconds' ) ) ) {
				return false;
			}

			return $this->positive_int( $policy['max_batch_size'] ?? null )
				&& $this->positive_int( $policy['max_seconds'] ?? null );
		}

		/** @param mixed $checkpoint Bounded opaque consumer checkpoint. */
		private function valid_checkpoint( $checkpoint ): bool {
			return is_array( $checkpoint )
				&& 4096 >= $this->json_bytes( $checkpoint )
				&& 5 >= $this->json_depth( $checkpoint )
				&& ! $this->contains_sensitive_key( $checkpoint );
		}

		/** @param mixed $value Nested job value. */
		private function contains_sensitive_key( $value ): bool {
			if ( ! is_array( $value ) ) {
				return false;
			}
			foreach ( $value as $key => $item ) {
				if (
					is_string( $key )
					&& in_array( strtolower( $key ), array( 'email', 'password', 'secret', 'token', 'nonce', 'cookie', 'authorization', 'license_key', 'api_key' ), true )
				) {
					return true;
				}
				if ( $this->contains_sensitive_key( $item ) ) {
					return true;
				}
			}

			return false;
		}

		/** @param mixed $value Candidate UUID. */
		private function valid_uuid( $value ): bool {
			return is_string( $value )
				&& 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value );
		}

		/** @param mixed $value Stable English identifier. */
		private function valid_identifier( $value ): bool {
			return is_string( $value ) && 1 === preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $value );
		}

		/** @param mixed $value Request idempotency key. */
		private function valid_idempotency_key( $value ): bool {
			return is_string( $value )
				&& 16 <= strlen( $value )
				&& 128 >= strlen( $value )
				&& 1 === preg_match( '/\A[A-Za-z0-9._:-]+\z/', $value );
		}

		/** @param mixed $value Positive integer. */
		private function positive_int( $value ): bool {
			return is_int( $value ) && 0 < $value;
		}

		/** @param mixed $value JSON-like tree. */
		private function json_depth( $value, int $depth = 1 ): int {
			if ( ! is_array( $value ) || array() === $value ) {
				return $depth;
			}
			$maximum = $depth;
			foreach ( $value as $item ) {
				$maximum = max( $maximum, $this->json_depth( $item, $depth + 1 ) );
				if ( 5 < $maximum ) {
					break;
				}
			}

			return $maximum;
		}

		/** @param mixed $value JSON-like tree. */
		private function json_bytes( $value ): int {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Dependency-free infrastructure validation.
			$json = json_encode( $value, JSON_UNESCAPED_SLASHES );

			return false === $json ? 4097 : strlen( $json );
		}

		/** @param mixed $status Job state. */
		private function is_terminal( $status ): bool {
			return in_array( $status, array( 'completed', 'failed', 'cancelled' ), true );
		}

		/** @return array<string,mixed> */
		private function failure( string $code ): array {
			return array(
				'ok'         => false,
				'idempotent' => false,
				'code'       => $code,
			);
		}

		/** @param array<string,mixed> $job Durable consumer record. */
		private function log( string $code, array $job ): void {
			if ( ! method_exists( $this->logger, 'log' ) ) {
				return;
			}
			$event = array(
				'code'    => $code,
				'job_id'  => $this->valid_uuid( $job['job_id'] ?? null ) ? $job['job_id'] : '',
				'handler' => $this->valid_identifier( $job['handler'] ?? null ) ? $job['handler'] : '',
				'count'   => 1,
			);
			try {
				$this->logger->log( $event );
			} catch ( Throwable $error ) {
				unset( $error );
			}
		}
	}
}
