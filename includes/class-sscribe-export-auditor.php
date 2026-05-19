<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SScribe_Export_Auditor {

	private readonly SScribe_Audit_Trail $audit_trail;

	private readonly SScribe_Logger_Interface $logger;

	public function __construct(
		?SScribe_Audit_Trail $audit_trail = null,
		?SScribe_Logger_Interface $logger = null
	) {
		$this->audit_trail = $audit_trail ?? new SScribe_Audit_Trail();
		$this->logger      = $logger ?? SScribe_Logger::instance();
	}

	public function log( string $action, array $context = array() ): void {
		$user_id      = get_current_user_id();
		$current_user = wp_get_current_user();
		$username     = ( $current_user && $current_user->exists() )
			? $current_user->user_login
			: 'unknown';

		$log_entry = array(
			'action'    => $action,
			'user_id'   => $user_id,
			'username'  => $username,
			'ip'        => SScribe_Helpers::get_client_ip(),
			'timestamp' => current_time( 'mysql' ),
			'context'   => $context,
		);

		$this->logger->debug( "[AUDIT] {$action}", $log_entry );

		$event_type = $this->map_action_to_event( $action );
		if ( null !== $event_type ) {
			$this->audit_trail->log( $event_type, $context );
		}
	}

	private function map_action_to_event( string $action ): ?string {
		$map = array(
			'export_started'    => SScribe_Audit_Trail::EVENT_EXPORT_STARTED,
			'export_completed'  => SScribe_Audit_Trail::EVENT_EXPORT_COMPLETED,
			'export_failed'     => SScribe_Audit_Trail::EVENT_EXPORT_FAILED,
			'export_cancelled'  => SScribe_Audit_Trail::EVENT_EXPORT_CANCELLED,
			'download'          => SScribe_Audit_Trail::EVENT_DOWNLOAD,
			'download_denied'   => SScribe_Audit_Trail::EVENT_DOWNLOAD_DENIED,
			'delete_export'     => SScribe_Audit_Trail::EVENT_DELETE,
			'session_cleared'   => SScribe_Audit_Trail::EVENT_SESSION_CLEARED,
			'preflight_check'   => SScribe_Audit_Trail::EVENT_PREFLIGHT_CHECK,
			'rate_limited'      => SScribe_Audit_Trail::EVENT_RATE_LIMITED,
			'permission_denied' => SScribe_Audit_Trail::EVENT_PERMISSION_DENIED,
			'invalid_nonce'     => SScribe_Audit_Trail::EVENT_INVALID_NONCE,
			'session_hijack'    => SScribe_Audit_Trail::EVENT_SESSION_HIJACK_ATTEMPT,
		);

		return $map[ $action ] ?? null;
	}
}
