<?php
/**
 * Bounded synchronous evaluation of one public-catalog Conditions page.
 *
 * This application service has no WordPress persistence, cache or transport
 * dependency. The admin adapter supplies a server-derived candidate page and
 * the same legacy evaluator used by the storefront.
 */

if ( ! class_exists( 'BeRocket_Growth_Suite_Conditions_Matching_Skus_Service' ) ) {
	class BeRocket_Growth_Suite_Conditions_Matching_Skus_Service {
		const MAX_CANDIDATES = 100;
		const MAX_SECONDS    = 20;

		/** @var callable */
		private $catalog_page;

		/** @var callable */
		private $matches;

		/** @var callable */
		private $clock;

		/**
		 * @param callable      $catalog_page Receives cursor and maximum candidates.
		 * @param callable      $matches      Receives one candidate and draft Conditions.
		 * @param callable|null $clock        Monotonic-seconds provider for testability.
		 */
		public function __construct( callable $catalog_page, callable $matches, ?callable $clock = null ) {
			$this->catalog_page = $catalog_page;
			$this->matches      = $matches;
			$this->clock        = null === $clock
				? static function (): float {
					return microtime( true );
				}
				: $clock;
		}

		/**
		 * Evaluate one explicit, server-derived catalog page.
		 *
		 * @param array<string,mixed> $conditions Unsaved legacy Conditions data.
		 * @param string|null         $cursor     Opaque adapter-owned resume cursor.
		 * @return array<string,mixed>
		 */
		public function page( array $conditions, ?string $cursor = null ): array {
			$started_at = $this->now();
			try {
				$page = call_user_func( $this->catalog_page, $cursor, self::MAX_CANDIDATES );
			} catch ( Throwable $error ) {
				unset( $error );

				return $this->invalid_page_result();
			}
			if ( ! is_array( $page ) || ! isset( $page['items'] ) || ! is_array( $page['items'] ) ) {
				return $this->invalid_page_result();
			}
			if ( self::MAX_SECONDS <= $this->now() - $started_at ) {
				return $this->partial_result( array(), 0, $cursor, 'time_limit_reached' );
			}

			$matches       = array();
			$checked       = 0;
			$last_cursor   = $cursor;
			$source_cursor = isset( $page['next_cursor'] ) && is_string( $page['next_cursor'] )
				? $page['next_cursor']
				: null;

			foreach ( $page['items'] as $candidate ) {
				if ( self::MAX_CANDIDATES <= $checked ) {
					return $this->partial_result( $matches, $checked, $last_cursor, 'candidate_limit_reached' );
				}
				if ( self::MAX_SECONDS <= $this->now() - $started_at ) {
					return $this->partial_result( $matches, $checked, $last_cursor, 'time_limit_reached' );
				}
				if ( ! is_array( $candidate ) || ! $this->valid_candidate( $candidate ) ) {
					continue;
				}

				++$checked;
				$last_cursor = $candidate['cursor'];
				try {
					$is_match = true === call_user_func( $this->matches, $candidate, $conditions );
				} catch ( Throwable $error ) {
					unset( $error );
					$is_match = false;
				}
				if ( $is_match ) {
					$matches[] = array(
						'product_id' => $candidate['product_id'],
						'sku'        => $candidate['sku'],
						'name'       => $candidate['name'],
					);
				}
				if ( self::MAX_SECONDS <= $this->now() - $started_at ) {
					return $this->partial_result( $matches, $checked, $last_cursor, 'time_limit_reached' );
				}
			}

			if ( null !== $source_cursor ) {
				return $this->partial_result( $matches, $checked, $source_cursor, 'candidate_limit_reached' );
			}

			return array(
				'checked_candidates' => $checked,
				'matches'            => $matches,
				'partial'            => false,
				'next_cursor'        => null,
				'reason_code'        => null,
			);
		}

		/** @return array<string,mixed> */
		private function invalid_page_result(): array {
			return array(
				'checked_candidates' => 0,
				'matches'            => array(),
				'partial'            => false,
				'next_cursor'        => null,
				'reason_code'        => 'invalid_catalog_page',
			);
		}

		/**
		 * @param array<int,array<string,mixed>> $matches Matches safe for the admin response.
		 * @return array<string,mixed>
		 */
		private function partial_result( array $matches, int $checked, ?string $cursor, string $reason_code ): array {
			return array(
				'checked_candidates' => $checked,
				'matches'            => $matches,
				'partial'            => true,
				'next_cursor'        => $cursor,
				'reason_code'        => $reason_code,
			);
		}

		/** @param array<string,mixed> $candidate Server-derived product candidate. */
		private function valid_candidate( array $candidate ): bool {
			return isset( $candidate['cursor'], $candidate['product_id'], $candidate['sku'], $candidate['name'] )
				&& is_string( $candidate['cursor'] )
				&& '' !== $candidate['cursor']
				&& is_int( $candidate['product_id'] )
				&& 0 < $candidate['product_id']
				&& is_string( $candidate['sku'] )
				&& is_string( $candidate['name'] );
		}

		private function now(): float {
			return (float) call_user_func( $this->clock );
		}
	}
}
