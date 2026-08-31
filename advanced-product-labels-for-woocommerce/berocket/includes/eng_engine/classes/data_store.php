<?php
namespace BeRocket\EngagementEngine;

final class DataStore {
	public const DATA_OPTION      = 'berocket_ee';
	public const LOADED_AT_OPTION = 'berocket_engagement_engine_load_data_time';
	public const MAX_AGE          = 86400;

	/**
	 * @param mixed $loaded_at Stored engagement refresh timestamp.
	 */
	public static function is_current_timestamp( $loaded_at ): bool {
		if ( ! is_numeric( $loaded_at ) ) {
			return false;
		}

		$loaded_at = (int) $loaded_at;
		$now       = time();

		return $loaded_at >= 1 && $loaded_at <= $now && $now - $loaded_at <= self::MAX_AGE;
	}

	/** @return array<string,mixed> */
	public static function get_current(): array {
		$loaded_at = get_option( self::LOADED_AT_OPTION, 1 );
		if ( ! self::is_current_timestamp( $loaded_at ) ) {
			return [];
		}

		$data = get_option( self::DATA_OPTION );

		return is_array( $data ) ? $data : [];
	}
}
