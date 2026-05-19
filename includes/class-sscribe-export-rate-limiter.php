<?php
/**
 * SScribe Export Rate Limiter
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SScribe_Export_Rate_Limiter {

	private const RATE_LIMIT_MAX = 200;

	private const RATE_LIMIT_WINDOW = 60;

	public function check_rate_limit( string $export_capability = 'manage_options' ): bool {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			$transient_key = 'sscribe_rate_' . $user_id;
		} else {
			$remote_ip     = isset( $_SERVER['REMOTE_ADDR'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
				: '0.0.0.0';
			$transient_key = 'sscribe_rate_anon_' . substr( hash( 'sha256', $remote_ip ), 0, 12 );
		}

		$now = time();

		$rate_limit = current_user_can( $export_capability )
			? (int) apply_filters( 'sscribe_rate_limit_admin', 1000 )
			: self::RATE_LIMIT_MAX;

		$data = get_transient( $transient_key );

		if ( false === $data ) {
			$data = array(
				'count'    => 0,
				'reset_at' => $now + self::RATE_LIMIT_WINDOW,
			);
		}

		if ( isset( $data['reset_at'] ) && $data['reset_at'] <= $now ) {
			$data = array(
				'count'    => 0,
				'reset_at' => $now + self::RATE_LIMIT_WINDOW,
			);
		}

		if ( $data['count'] >= $rate_limit ) {
			return false;
		}

		++$data['count'];

		set_transient( $transient_key, $data, self::RATE_LIMIT_WINDOW + 5 );

		return true;
	}
}
