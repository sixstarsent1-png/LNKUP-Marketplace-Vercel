<?php
/**
 * In-app inbox channel. Writes to the lnkup-marketplace inbox table so web,
 * mobile and the existing /notifications/inbox routes all see the same row.
 * One logical row per message, regardless of how many devices the user has.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Channel_Inbox {

	public static function client_id( int $message_id ): string {
		return 'comms-' . $message_id;
	}

	/**
	 * @param array $message  Message row.
	 * @param array $def      Trigger definition.
	 * @param array $rendered Rendered template.
	 * @param array $links    deep_link, web_url.
	 * @param array $ctx      Built context.
	 * @return array{status:string,error:string}
	 */
	public static function send( array $message, array $def, array $rendered, array $links, array $ctx ): array {
		$severity = array(
			'critical'    => 'urgent',
			'high'        => 'urgent',
			'normal'      => 'info',
			'promotional' => 'info',
		);
		$role     = (string) $message['recipient_role'];
		$category = (string) $message['category'];
		$result   = LNKup_Inbox_Service::upsert(
			(int) $message['recipient_user_id'],
			array(
				'client_id'       => self::client_id( (int) $message['id'] ),
				'type'            => (string) $def['type'],
				'title'           => (string) $rendered['push_title'],
				'message'         => (string) ( $rendered['inbox_body'] ?: $rendered['push_body'] ),
				'href'            => (string) $links['web_url'],
				'role'            => in_array( $role, array( 'vendor', 'organizer', 'partner' ), true ) ? $role : '',
				'severity'        => $severity[ (string) $message['priority'] ] ?? 'info',
				'action_required' => in_array( (string) $message['priority'], array( 'critical', 'high' ), true ) && in_array( $category, array( 'applications', 'payments', 'documents', 'event_ops' ), true ),
				'payload'         => array(
					'notificationId' => (int) $message['id'],
					'category'       => $category,
					'tab'            => LNKup_Comms_Registry::tab_for_type( (string) $def['type'], $role ),
					'deepLink'       => (string) $links['deep_link'],
					'applicationId'  => (string) ( (int) $ctx['application_id'] ?: '' ),
					'eventId'        => (string) ( (int) $ctx['event_id'] ?: '' ),
					'opportunityId'  => (string) ( (int) $ctx['opportunity_id'] ?: '' ),
					'priority'       => (string) $message['priority'],
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return array( 'status' => 'failed', 'error' => $result->get_error_code() );
		}
		return array( 'status' => 'delivered', 'error' => '' );
	}
}
