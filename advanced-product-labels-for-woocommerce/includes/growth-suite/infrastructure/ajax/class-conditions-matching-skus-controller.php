<?php
/**
 * WordPress adapter for the explicit, bounded Conditions metabox check.
 */

if ( ! class_exists( 'BeRocket_Growth_Suite_Conditions_Matching_Skus_Controller' ) ) {
	class BeRocket_Growth_Suite_Conditions_Matching_Skus_Controller {
		const ACTION           = 'brapl_conditions_matching_skus';
		const NONCE_ACTION     = 'br_labels_check';
		const NONCE_FIELD      = 'br_labels_nonce';
		const MAX_BODY_BYTES   = 65536;
		const MAX_JSON_DEPTH   = 20;
		const MAX_JSON_MEMBERS = 5000;

		/** @var BeRocket_Growth_Suite_Conditions_Matching_Skus_Service|null */
		private $service = null;

		public function register_hooks(): void {
			if ( function_exists( 'add_action' ) ) {
				add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
			}
		}

		/** Handle one authenticated admin request. */
		public function handle(): void {
			if ( ! $this->valid_nonce() ) {
				$this->error( 'invalid_nonce', 403 );
				return;
			}
			$label_id = $this->positive_post_value( 'label_id' );
			if ( null === $label_id || ! $this->can_edit_label( $label_id ) ) {
				$this->error( 'forbidden', 403 );
				return;
			}
			$conditions = $this->conditions_payload();
			if ( null === $conditions ) {
				$this->error( 'invalid_conditions_payload', 400 );
				return;
			}
			$cursor = $this->cursor_value();
			if ( false === $cursor ) {
				$this->error( 'invalid_cursor', 400 );
				return;
			}

			$result = $this->service()->page( $conditions, $cursor );
			if ( function_exists( 'nocache_headers' ) ) {
				nocache_headers();
			}
			wp_send_json_success( $result );
		}

		private function service(): BeRocket_Growth_Suite_Conditions_Matching_Skus_Service {
			if ( null === $this->service ) {
				$this->service = new BeRocket_Growth_Suite_Conditions_Matching_Skus_Service(
					array( $this, 'catalog_page' ),
					array( $this, 'matches_conditions' )
				);
			}

			return $this->service;
		}

		/**
		 * Derive one visible public-catalog page on the server. The additional ID
		 * only detects a following page; the service never evaluates it.
		 *
		 * @return array{items:array<int,array<string,mixed>>,next_cursor:string|null}
		 */
		public function catalog_page( ?string $cursor, int $limit ): array {
			if ( ! class_exists( 'WP_Query' ) || $limit < 1 ) {
				return array(
					'items'       => array(),
					'next_cursor' => null,
				);
			}
			$after_id = null === $cursor ? 0 : (int) $cursor;
			$filter   = static function ( $where, $query ) use ( $after_id ) {
				if ( ! is_object( $query ) || ! method_exists( $query, 'get' ) || ! $query->get( 'brapl_matching_skus_page' ) || $after_id < 1 ) {
					return $where;
				}
				global $wpdb;
				if ( ! isset( $wpdb->posts ) || ! method_exists( $wpdb, 'prepare' ) ) {
					return $where;
				}

				return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
			};
			add_filter( 'posts_where', $filter, 10, 2 );
			try {
				$query = new WP_Query(
					array(
						'post_type'                => 'product',
						'post_status'              => 'publish',
						'posts_per_page'           => $limit + 1,
						'orderby'                  => 'ID',
						'order'                    => 'ASC',
						'fields'                   => 'ids',
						'no_found_rows'            => true,
						'ignore_sticky_posts'      => true,
						'has_password'             => false,
						'update_post_meta_cache'   => true,
						'update_post_term_cache'   => true,
						'brapl_matching_skus_page' => true,
					)
				);
			} finally {
				remove_filter( 'posts_where', $filter, 10 );
			}

			$ids      = isset( $query->posts ) && is_array( $query->posts ) ? $query->posts : array();
			$has_more = $limit < count( $ids );
			$page_ids = array_slice( $ids, 0, $limit );
			$last_id  = empty( $page_ids ) ? null : (int) end( $page_ids );
			if ( function_exists( '_prime_post_caches' ) && ! empty( $page_ids ) ) {
				_prime_post_caches( $page_ids, true, true );
			}
			if ( function_exists( 'update_object_term_cache' ) && ! empty( $page_ids ) ) {
				update_object_term_cache( $page_ids, 'product' );
			}

			$items = array();
			foreach ( $page_ids as $product_id ) {
				$candidate = $this->catalog_candidate( (int) $product_id );
				if ( null !== $candidate ) {
					$items[] = $candidate;
				}
			}

			return array(
				'items'       => $items,
				'next_cursor' => $has_more && null !== $last_id ? (string) $last_id : null,
			);
		}

		/**
		 * @return array<string,mixed>|null
		 */
		private function catalog_candidate( int $product_id ): ?array {
			if ( $product_id < 1 || ! function_exists( 'wc_get_product' ) ) {
				return null;
			}
			$product = wc_get_product( $product_id );
			if ( ! is_object( $product ) ) {
				return null;
			}
			if ( method_exists( $product, 'get_catalog_visibility' ) && 'hidden' === $product->get_catalog_visibility() ) {
				return null;
			}

			$name = method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';
			$sku  = method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '';

			return array(
				'cursor'       => (string) $product_id,
				'product_id'   => $product_id,
				'sku'          => $sku,
				'name'         => $name,
				'product'      => $product,
				'product_post' => function_exists( 'br_wc_get_product_post' ) ? br_wc_get_product_post( $product ) : get_post( $product_id ),
			);
		}

		/**
		 * @param array<string,mixed> $candidate  Server-derived product candidate.
		 * @param array<string,mixed> $conditions Unsaved legacy Conditions payload.
		 */
		public function matches_conditions( array $candidate, array $conditions ): bool {
			if ( ! class_exists( 'BeRocket_conditions_advanced_labels' ) || ! isset( $candidate['product'], $candidate['product_id'], $candidate['product_post'] ) ) {
				return false;
			}
			$additional = array(
				'product'      => $candidate['product'],
				'product_id'   => $candidate['product_id'],
				'product_post' => $candidate['product_post'],
				'post_id'      => $candidate['product_id'],
			);
			if ( function_exists( 'apply_filters' ) ) {
				$additional = apply_filters( 'berocket_apl_condition_check_data', $additional );
			}

			return true === BeRocket_conditions_advanced_labels::check(
				$conditions,
				'berocket_advanced_label_editor',
				$additional
			);
		}

		private function valid_nonce(): bool {
			return function_exists( 'check_ajax_referer' ) && false !== check_ajax_referer( self::NONCE_ACTION, self::NONCE_FIELD, false );
		}

		private function can_edit_label( int $label_id ): bool {
			return function_exists( 'get_post_type' )
				&& 'br_labels' === get_post_type( $label_id )
				&& function_exists( 'current_user_can' )
				&& current_user_can( 'edit_post', $label_id );
		}

		/** @return array<string,mixed>|null */
		private function conditions_payload(): ?array {
			$value = $this->post_scalar( 'conditions_json' );
			if ( null === $value || self::MAX_BODY_BYTES < strlen( $value ) ) {
				return null;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_decode -- JSON is the explicit draft-only request format.
			$decoded = json_decode( $value, true );

			return is_array( $decoded ) && $this->valid_json_tree( $decoded ) ? $decoded : null;
		}

		/** @return string|null|false */
		private function cursor_value() {
			$value = $this->post_scalar( 'cursor' );
			if ( null === $value || '' === $value ) {
				return null;
			}

			return 1 === preg_match( '/\A[1-9][0-9]*\z/', $value ) ? $value : false;
		}

		private function positive_post_value( string $key ): ?int {
			$value = $this->post_scalar( $key );
			if ( null === $value || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $value ) ) {
				return null;
			}

			return (int) $value;
		}

		private function post_scalar( string $key ): ?string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle() verifies the exact admin nonce before parsing draft fields.
			if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
				return null;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle() verifies the exact admin nonce before parsing draft fields.
			$value = function_exists( 'wp_unslash' ) ? wp_unslash( $_POST[ $key ] ) : $_POST[ $key ];

			return $value;
		}

		/** @param mixed $value */
		private function valid_json_tree( $value, int $depth = 1, int &$members = 0 ): bool {
			if ( self::MAX_JSON_DEPTH < $depth || self::MAX_JSON_MEMBERS < $members ) {
				return false;
			}
			if ( ! is_array( $value ) ) {
				return is_scalar( $value ) || null === $value;
			}
			foreach ( $value as $key => $item ) {
				++$members;
				if ( ! is_int( $key ) && 128 < strlen( $key ) ) {
					return false;
				}
				if ( ! $this->valid_json_tree( $item, $depth + 1, $members ) ) {
					return false;
				}
			}

			return true;
		}

		private function error( string $code, int $status ): void {
			$message = function_exists( '__' )
				? __( 'Matching SKU check could not be completed.', 'BeRocket_products_label_domain' )
				: 'Matching SKU check could not be completed.';
			wp_send_json_error(
				array(
					'code'    => $code,
					'message' => $message,
				),
				$status
			);
		}
	}
}
