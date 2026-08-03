<?php
/**
 * The in-WordPress inbox: message list, reader, and reply.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Mailbox;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a user's own mailbox — in wp-admin (menu: Inbox) and on the front end
 * via `[mailkite_inbox]`. Every read uses THAT user's app password, so the screen
 * can only ever show mail the credential itself is scoped to.
 */
final class Inbox {

	/**
	 * Hook the admin page, shortcode, and reply handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_shortcode( 'mailkite_inbox', [ $this, 'shortcode' ] );
		add_action( 'admin_post_mailkite_smtp_mailbox_reply', [ $this, 'handle_reply' ] );
	}

	/**
	 * "Inbox" menu for any logged-in user who holds an address.
	 */
	public function add_menu(): void {
		if ( '' === Manager::address( get_current_user_id() ) ) {
			return;
		}
		add_menu_page(
			__( 'Inbox', 'mailkite-smtp' ),
			__( 'Inbox', 'mailkite-smtp' ),
			'read',
			'mailkite-inbox',
			[ $this, 'render_admin_page' ],
			'dashicons-email',
			26
		);
	}

	/**
	 * wp-admin → Inbox.
	 */
	public function render_admin_page(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Inbox', 'mailkite-smtp' ) . '</h1>';
		// render() assembles its own markup with every dynamic value escaped at the point
		// of use (esc_html/esc_attr/esc_url, and wp_kses_post for the message body). It is
		// NOT passed through wp_kses_post here: that filter allows post content only, so it
		// would strip the reply form's inputs — including the nonce.
		echo $this->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at assembly, see above.
		echo '</div>';
	}

	/**
	 * `[mailkite_inbox]` — the same screen on the front end.
	 *
	 * @return string
	 */
	public function shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please sign in to read your mail.', 'mailkite-smtp' ) . '</p>';
		}

		return '<div class="mailkite-inbox">' . $this->render() . '</div>';
	}

	/**
	 * List or reader, depending on ?uid.
	 *
	 * @return string HTML (already escaped).
	 */
	private function render(): string {
		$user_id = get_current_user_id();
		$address = Manager::address( $user_id );
		if ( '' === $address ) {
			return '<p>' . esc_html__( 'You do not have a mailbox yet.', 'mailkite-smtp' ) . '</p>';
		}
		$secret = Manager::secret( $user_id );
		if ( '' === $secret ) {
			return '<p>' . esc_html__( 'Your mailbox credential is missing — regenerate it from your profile.', 'mailkite-smtp' ) . '</p>';
		}

		$notice = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only message passed back by our own redirect.
		if ( isset( $_GET['mailkite_error'] ) ) {
			$notice = '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['mailkite_error'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		if ( isset( $_GET['mailkite_sent'] ) ) {
			$notice = '<div class="notice notice-success"><p>' . esc_html__( 'Reply sent.', 'mailkite-smtp' ) . '</p></div>';
		}

		$uid = isset( $_GET['uid'] ) ? absint( $_GET['uid'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.

		return $notice . ( $uid ? $this->render_message( $secret, $address, $uid ) : $this->render_list( $secret, $address ) );
	}

	/**
	 * The message list.
	 *
	 * @param string $secret  App password.
	 * @param string $address Mailbox address.
	 * @return string
	 */
	private function render_list( string $secret, string $address ): string {
		$result = Client::list_messages( $secret, $address );
		if ( is_wp_error( $result ) ) {
			return '<p>' . esc_html( $result->get_error_message() ) . '</p>';
		}
		$messages = (array) ( $result['messages'] ?? [] );

		$html  = '<p>' . sprintf(
			/* translators: %s: the user's email address. */
			esc_html__( 'Mail for %s', 'mailkite-smtp' ),
			'<code>' . esc_html( $address ) . '</code>'
		) . '</p>';
		$html .= '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'From', 'mailkite-smtp' ) . '</th>'
			. '<th>' . esc_html__( 'Subject', 'mailkite-smtp' ) . '</th>'
			. '<th>' . esc_html__( 'Received', 'mailkite-smtp' ) . '</th>'
			. '</tr></thead><tbody>';

		if ( ! $messages ) {
			$html .= '<tr><td colspan="3">' . esc_html__( 'No mail yet.', 'mailkite-smtp' ) . '</td></tr>';
		}
		foreach ( $messages as $m ) {
			$unread  = ! str_contains( (string) ( $m['flags'] ?? '' ), 'Seen' );
			$subject = (string) ( $m['subject'] ?? '' );
			$html   .= '<tr>'
				. '<td>' . esc_html( (string) ( $m['from_addr'] ?? '' ) ) . '</td>'
				. '<td><a href="' . esc_url( add_query_arg( 'uid', (int) $m['uid'] ) ) . '">'
					. ( $unread ? '<strong>' : '' )
					. esc_html( '' !== $subject ? $subject : __( '(no subject)', 'mailkite-smtp' ) )
					. ( $unread ? '</strong>' : '' )
				. '</a></td>'
				. '<td>' . esc_html( $this->local_time( (string) ( $m['internaldate'] ?? '' ) ) ) . '</td>'
				. '</tr>';
		}

		return $html . '</tbody></table>';
	}

	/**
	 * One message, and the reply box.
	 *
	 * @param string $secret  App password.
	 * @param string $address Mailbox address.
	 * @param int    $uid     Message uid.
	 * @return string
	 */
	private function render_message( string $secret, string $address, int $uid ): string {
		$raw = Client::raw( $secret, $address, $uid );
		if ( is_wp_error( $raw ) ) {
			return '<p>' . esc_html( $raw->get_error_message() ) . '</p>';
		}
		$parsed  = Mime::parse( $raw );
		$headers = $parsed['headers'];
		$from    = (string) ( $headers['from'] ?? '' );
		$subject = (string) ( $headers['subject'] ?? __( '(no subject)', 'mailkite-smtp' ) );

		// Opening a message marks it read, exactly as an IMAP client would.
		Client::set_flags( $secret, $address, $uid, 'Seen' );

		$back  = remove_query_arg( 'uid' );
		$html  = '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to the inbox', 'mailkite-smtp' ) . '</a></p>';
		$html .= '<table class="widefat striped" style="max-width:860px"><tbody>'
			. '<tr><td style="width:7em"><strong>' . esc_html__( 'From', 'mailkite-smtp' ) . '</strong></td><td>' . esc_html( $from ) . '</td></tr>'
			. '<tr><td><strong>' . esc_html__( 'To', 'mailkite-smtp' ) . '</strong></td><td>' . esc_html( (string) ( $headers['to'] ?? $address ) ) . '</td></tr>'
			. '<tr><td><strong>' . esc_html__( 'Subject', 'mailkite-smtp' ) . '</strong></td><td>' . esc_html( $subject ) . '</td></tr>'
			. '<tr><td><strong>' . esc_html__( 'Date', 'mailkite-smtp' ) . '</strong></td><td>' . esc_html( (string) ( $headers['date'] ?? '' ) ) . '</td></tr>';
		if ( $parsed['attachments'] ) {
			$html .= '<tr><td><strong>' . esc_html__( 'Attachments', 'mailkite-smtp' ) . '</strong></td><td>' . esc_html( implode( ', ', $parsed['attachments'] ) ) . '</td></tr>';
		}
		$html .= '</tbody></table>';

		if ( '' !== $parsed['text'] ) {
			$html .= '<pre style="background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:16px;max-width:860px;white-space:pre-wrap">'
				. esc_html( $parsed['text'] ) . '</pre>';
		} elseif ( '' !== $parsed['html'] ) {
			$html .= '<div style="background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:16px;max-width:860px">'
				. wp_kses_post( $parsed['html'] ) . '</div>';
		} else {
			$html .= '<p>' . esc_html__( '(no readable body)', 'mailkite-smtp' ) . '</p>';
		}

		// Reply — sent through the plugin's normal mailer with From forced to this user.
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:860px;margin-top:1em">'
			. '<input type="hidden" name="action" value="mailkite_smtp_mailbox_reply" />'
			. '<input type="hidden" name="uid" value="' . esc_attr( (string) $uid ) . '" />'
			. '<input type="hidden" name="to" value="' . esc_attr( $from ) . '" />'
			. '<input type="hidden" name="subject" value="' . esc_attr( $subject ) . '" />'
			. '<input type="hidden" name="message_id" value="' . esc_attr( (string) ( $headers['message-id'] ?? '' ) ) . '" />'
			. '<input type="hidden" name="redirect_to" value="' . esc_attr( $back ) . '" />'
			. wp_nonce_field( 'mailkite_smtp_mailbox_reply', '_wpnonce', true, false )
			. '<h2>' . esc_html__( 'Reply', 'mailkite-smtp' ) . '</h2>'
			. '<textarea name="body" rows="6" class="large-text" required></textarea>'
			. '<p><button type="submit" class="button button-primary">' . esc_html__( 'Send reply', 'mailkite-smtp' ) . '</button></p>'
			. '</form>';

		return $html;
	}

	/**
	 * Send a reply as the user's own address (admin-post).
	 */
	public function handle_reply(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Permission denied.', 'mailkite-smtp' ) );
		}
		check_admin_referer( 'mailkite_smtp_mailbox_reply' );

		$user_id = get_current_user_id();
		$address = Manager::address( $user_id );
		$to      = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$body    = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
		$msg_id  = isset( $_POST['message_id'] ) ? sanitize_text_field( wp_unslash( $_POST['message_id'] ) ) : '';
		$back    = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url( 'admin.php?page=mailkite-inbox' );

		if ( '' === $address ) {
			$this->redirect( $back, __( 'You do not have a mailbox.', 'mailkite-smtp' ) );
		}
		if ( '' === $to || '' === $body ) {
			$this->redirect( $back, __( 'A recipient and a message are required.', 'mailkite-smtp' ) );
		}
		if ( ! Manager::consume_send_quota( $user_id ) ) {
			$this->redirect( $back, __( 'You have reached your daily send limit.', 'mailkite-smtp' ) );
		}

		$headers = [ 'From: ' . $address ]; // Forced — a user can only send as their own address.
		if ( '' !== $msg_id ) {
			$headers[] = 'In-Reply-To: ' . $msg_id;
			$headers[] = 'References: ' . $msg_id;
		}
		$sent = wp_mail(
			$to,
			str_starts_with( strtolower( $subject ), 're:' ) ? $subject : 'Re: ' . $subject,
			$body,
			$headers
		);

		$this->redirect( $sent ? add_query_arg( 'mailkite_sent', '1', $back ) : $back, $sent ? '' : __( 'The reply could not be sent — check the email log.', 'mailkite-smtp' ) );
	}

	/**
	 * Redirect back with an optional error.
	 *
	 * @param string $url   Target.
	 * @param string $error Message, or '' on success.
	 * @return never
	 */
	private function redirect( string $url, string $error ): void {
		if ( '' !== $error ) {
			$url = add_query_arg( 'mailkite_error', rawurlencode( $error ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render an ISO timestamp in the site's timezone and format.
	 *
	 * @param string $iso ISO-8601 timestamp.
	 */
	private function local_time( string $iso ): string {
		$ts = strtotime( $iso );

		return $ts ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : $iso;
	}
}
