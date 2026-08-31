<?php
/**
 * Bounded public-product source used by catalog simulation jobs.
 */

if ( ! interface_exists( 'BeRocket_Growth_Suite_Catalog_Source_Interface' ) ) {
	interface BeRocket_Growth_Suite_Catalog_Source_Interface {
		/**
		 * Freeze the candidate boundary without returning the full catalog.
		 *
		 * @param array<string,mixed> $boundary Public archive/category/single boundary.
		 * @param array<string,mixed> $context  Canonical context without a product.
		 * @return array<string,mixed>
		 */
		public function create_snapshot( array $boundary, array $context ): array;

		/**
		 * Return one strictly ascending product-ID page after the cursor.
		 *
		 * @param array<string,mixed> $snapshot         Source-owned immutable snapshot.
		 * @param array<string,mixed> $boundary         Public catalog boundary.
		 * @param int                 $after_product_id Last processed product ID.
		 * @param int                 $limit            Bounded page size.
		 * @return array<string,mixed>
		 */
		public function fetch_page(
			array $snapshot,
			array $boundary,
			int $after_product_id,
			int $limit
		): array;

		/**
		 * Check whether the fixed candidate boundary is still current.
		 *
		 * @param array<string,mixed> $snapshot Source-owned immutable snapshot.
		 * @param array<string,mixed> $boundary Public catalog boundary.
		 * @param array<string,mixed> $context  Current canonical context.
		 */
		public function is_current( array $snapshot, array $boundary, array $context ): bool;
	}
}
