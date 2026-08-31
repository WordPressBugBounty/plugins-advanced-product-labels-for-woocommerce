<?php
/**
 * Optional isolated gateway contract consumed by the T089 simulation REST transport.
 *
 * No production provider is registered by T043 for the user-facing
 * Conditions-editor matching-SKU control.
 */

if ( ! interface_exists( 'BeRocket_Growth_Suite_Simulation_Job_Gateway_Interface' ) ) {
	interface BeRocket_Growth_Suite_Simulation_Job_Gateway_Interface {
		/**
		 * Schedule or return the idempotent bounded simulation job.
		 *
		 * The implementation owns rate/concurrency limits, durable state and the
		 * Action Scheduler lifecycle. The REST controller never emulates them.
		 *
		 * @param array<string,mixed> $command Validated owner-scoped command.
		 * @return array<string,mixed>
		 */
		public function start( array $command ): array;

		/**
		 * Return one job only when it belongs to the requesting user and site.
		 *
		 * @return array<string,mixed>
		 */
		public function get_job( string $job_id, int $user_id, int $site_id ): array;

		/**
		 * Return an owner-scoped bounded page of historical matching rows.
		 *
		 * @return array<string,mixed>
		 */
		public function get_matches( string $job_id, int $user_id, int $site_id, int $page, int $per_page ): array;
	}
}
