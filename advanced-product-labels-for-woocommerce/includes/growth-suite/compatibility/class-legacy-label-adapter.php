<?php
/**
 * Read-only projection of legacy label rules.
 */

if ( ! class_exists( 'BeRocket_Growth_Suite_Legacy_Label_Adapter' ) ) {
	class BeRocket_Growth_Suite_Legacy_Label_Adapter {
		/** @var BeRocket_Growth_Suite_Label_Schema */
		private $schema;

		public function __construct( BeRocket_Growth_Suite_Label_Schema $schema ) {
			$this->schema = $schema;
		}

		/**
		 * Project a payload to an in-memory model without changing saved data.
		 *
		 * @param mixed $payload  Saved label meta.
		 * @param int   $label_id Label ID used only for ephemeral diagnostics.
		 * @return array<string,mixed>
		 */
		public function read( $payload, $label_id = 0 ) {
			$inspection = $this->schema->inspect( $payload );
			$data       = is_array( $payload ) && isset( $payload['data'] ) ? $payload['data'] : array();

			return array(
				'inspection'     => $inspection,
				'saved_payload'  => $payload,
				'canonical_rule' => $this->project_rules( $data, (int) $label_id ),
				'should_write'   => false,
			);
		}

		/**
		 * Compatibility round-trip is deliberately an identity operation.
		 *
		 * @param mixed $payload Saved payload.
		 * @return mixed
		 */
		public function round_trip( $payload ) {
			return $payload;
		}

		/**
		 * @param mixed $data Saved legacy rule payload.
		 * @return array<string,mixed>
		 */
		private function project_rules( $data, int $label_id ): array {
			$root = array(
				'id'            => 'legacy:' . $label_id . ':root',
				'kind'          => 'group',
				'operator'      => 'or',
				'children'      => array(),
				'ephemeral_ids' => true,
				'empty_matches' => true,
			);

			if ( ! is_array( $data ) || array() === $data ) {
				return $root;
			}

			foreach ( array_values( $data ) as $group_index => $legacy_group ) {
				$group = array(
					'id'       => 'legacy:' . $label_id . ':g:' . $group_index,
					'kind'     => 'group',
					'operator' => 'and',
					'children' => array(),
				);

				if ( ! is_array( $legacy_group ) ) {
					$group['children'][] = $this->invalid_leaf( $label_id, $group_index, 0, $legacy_group );
					$root['children'][]  = $group;
					continue;
				}

				foreach ( array_values( $legacy_group ) as $condition_index => $legacy_condition ) {
					if ( ! is_array( $legacy_condition ) || ! isset( $legacy_condition['type'] ) || ! is_scalar( $legacy_condition['type'] ) ) {
						$group['children'][] = $this->invalid_leaf( $label_id, $group_index, $condition_index, $legacy_condition );
						continue;
					}

					$group['children'][] = array(
						'id'      => 'legacy:' . $label_id . ':g:' . $group_index . ':c:' . $condition_index,
						'kind'    => 'condition',
						'type'    => (string) $legacy_condition['type'],
						'payload' => $legacy_condition,
					);
				}

				$root['children'][] = $group;
			}

			return $root;
		}

		/**
		 * @param mixed $payload Preserved malformed payload.
		 * @return array<string,mixed>
		 */
		private function invalid_leaf( int $label_id, int $group_index, int $condition_index, $payload ): array {
			return array(
				'id'          => 'legacy:' . $label_id . ':g:' . $group_index . ':c:' . $condition_index,
				'kind'        => 'condition',
				'type'        => '__invalid_legacy_condition',
				'payload'     => $payload,
				'unavailable' => true,
				'reason_code' => 'condition_unavailable',
			);
		}
	}
}
