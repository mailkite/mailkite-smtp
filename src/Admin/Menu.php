<?php
/**
 * Admin menu, settings form, log list, test email (MVP PHP UI; React wizard is Phase 3).
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Admin;

use MailKite\Smtp\Log\LogTable;
use MailKite\Smtp\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Renders wp-admin → MailKite SMTP with Settings / Email Log / Send Test tabs.
 */
final class Menu {

	private const CAPABILITY = 'manage_options';
	private const SLUG       = 'mailkite-smtp';

	/**
	 * Hook everything.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_post_mailkite_smtp_save', [ $this, 'handle_save' ] );
		add_action( 'admin_post_mailkite_smtp_test', [ $this, 'handle_test' ] );
		add_action( 'admin_post_mailkite_smtp_resend', [ $this, 'handle_resend' ] );
		add_action( 'admin_post_mailkite_smtp_reply', [ $this, 'handle_reply' ] );
		add_action( 'admin_post_mailkite_smtp_import', [ $this, 'handle_import' ] );
		add_action( 'admin_post_mailkite_smtp_export', [ $this, 'handle_export' ] );
		add_action( 'admin_post_mailkite_smtp_rotate_inbound', [ $this, 'handle_rotate_inbound' ] );
		add_action( 'admin_post_mailkite_smtp_inbound_connect', [ $this, 'handle_inbound_connect' ] );
		add_action( 'admin_post_mailkite_smtp_inbound_disconnect', [ $this, 'handle_inbound_disconnect' ] );
		add_action( 'admin_post_mailkite_smtp_export_settings', [ $this, 'handle_export_settings' ] );
		add_action( 'admin_post_mailkite_smtp_import_settings', [ $this, 'handle_import_settings' ] );
		add_action( 'admin_post_mailkite_smtp_health_check', [ $this, 'handle_health_check' ] );
	}

	/**
	 * Register the top-level admin page.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'MailKite SMTP', 'mailkite-smtp' ),
			__( 'MailKite SMTP', 'mailkite-smtp' ),
			self::CAPABILITY,
			self::SLUG,
			[ $this, 'render' ],
			'dashicons-email-alt'
		);
	}

	/**
	 * Route to the active tab.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mailkite-smtp' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab switch is read-only.

		echo '<div class="wrap"><h1>' . esc_html__( 'MailKite SMTP', 'mailkite-smtp' ) . '</h1>';
		$this->render_tabs( $tab );
		$this->render_notice();

		if ( 'log' === $tab ) {
			$this->render_log();
		} elseif ( 'test' === $tab ) {
			$this->render_test();
		} elseif ( 'inbound' === $tab ) {
			$this->render_inbound();

		} elseif ( 'health' === $tab ) {
			$this->render_health();
		} else {
			$this->render_settings();
		}
		echo '</div>';
	}

	/**
	 * Save settings (admin-post).
	 */
	public function handle_save(): void {
		$this->guard( 'mailkite_smtp_save' );

		$fields = [
			'inbound_hmac_secret',
			'mailer',
			'api_key',
			'force_from_email',
			'force_from_name',
			'smtp_host',
			'smtp_port',
			'smtp_encryption',
			'smtp_username',
			'smtp_password',
			'log_retention',
			'alert_email',
			'alert_webhook',
			'sendgrid_key',
			'brevo_key',
			'mailgun_key',
			'mailgun_domain',
			'mailgun_region',
			'track_opens',
			'track_clicks',
		];
		$input  = [];
		foreach ( $fields as $field ) {
			if ( ! isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in Options::sanitize().
				continue;
			}
			$blank_keeps_saved = in_array( $field, [ 'api_key', 'smtp_password', 'sendgrid_key', 'brevo_key', 'mailgun_key', 'inbound_hmac_secret' ], true );
			if ( $blank_keeps_saved && '' === $_POST[ $field ] ) {
				continue; // Empty secret field means "keep the stored one".
			}
			$input[ $field ] = wp_unslash( $_POST[ $field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		// Checkboxes are absent from POST when unchecked, so only apply the set
		// belonging to the form that was actually submitted (settings vs. the
		// inbound/health tabs, which reuse this handler via _back_tab).
		$tab      = isset( $_POST['_back_tab'] ) ? sanitize_key( wp_unslash( $_POST['_back_tab'] ) ) : 'settings';
		$bool_map = [
			'settings' => [ 'smtp_auth', 'log_enabled', 'log_redact_auth', 'fallback_enabled', 'alerts_enabled' ],
			'inbound'  => [], // inbound_enabled is managed by the connect/disconnect actions, never this form.
			'health'   => [ 'summary_enabled' ],
		];
		foreach ( $bool_map[ $tab ] ?? $bool_map['settings'] as $bool_field ) {
			$input[ $bool_field ] = isset( $_POST[ $bool_field ] );
		}
		if ( isset( $_POST['inbound_forward'] ) ) {
			$input['inbound_forward'] = wp_unslash( $_POST['inbound_forward'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in Options::sanitize().
		}
		if ( isset( $_POST['routing_rules'] ) && is_array( $_POST['routing_rules'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- per-rule sanitize in Options::sanitize().
			$input['routing_rules'] = array_values( wp_unslash( $_POST['routing_rules'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		Options::update( $input );

		if ( ! empty( $input['inbound_enabled'] ) && '' === (string) Options::get( 'inbound_secret' ) ) {
			\MailKite\Smtp\Inbound::rotate_secret();
		}

		$this->redirect( in_array( $tab, [ 'inbound', 'health' ], true ) ? $tab : 'settings', 'saved' );
	}

	/**
	 * Rotate the inbound webhook secret (admin-post).
	 */
	public function handle_rotate_inbound(): void {
		$this->guard( 'mailkite_smtp_rotate_inbound' );
		\MailKite\Smtp\Inbound::rotate_secret();
		$this->redirect( 'inbound', 'saved' );
	}

	/**
	 * One-click inbound: install the webhook + signing secret on the chosen domain (admin-post).
	 */
	public function handle_inbound_connect(): void {
		$this->guard( 'mailkite_smtp_inbound_connect' );

		$domain_id = isset( $_POST['inbound_domain_id'] ) ? sanitize_text_field( wp_unslash( $_POST['inbound_domain_id'] ) ) : '';
		$result    = \MailKite\Smtp\Inbound::connect( $domain_id );

		$this->redirect( 'inbound', true === $result ? 'inbound_on' : 'inbound_failed' );
	}

	/**
	 * Turn inbound off: remove the webhook from the domain (admin-post).
	 */
	public function handle_inbound_disconnect(): void {
		$this->guard( 'mailkite_smtp_inbound_disconnect' );
		\MailKite\Smtp\Inbound::disconnect();
		$this->redirect( 'inbound', 'inbound_off' );
	}

	/**
	 * Run the DNS health check on demand (admin-post).
	 */
	public function handle_health_check(): void {
		$this->guard( 'mailkite_smtp_health_check' );
		update_option( 'mailkite_smtp_health', \MailKite\Smtp\Health::check(), false );
		$this->redirect( 'health', 'saved' );
	}

	/**
	 * Download settings as JSON, secrets excluded (admin-post).
	 */
	public function handle_export_settings(): void {
		$this->guard( 'mailkite_smtp_export_settings' );

		$settings = Options::all();
		unset( $settings['api_key'], $settings['smtp_password'], $settings['sendgrid_key'], $settings['brevo_key'], $settings['mailgun_key'], $settings['inbound_secret'], $settings['inbound_hmac_secret'] );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=mailkite-smtp-settings.json' );
		echo wp_json_encode( $settings, JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	/**
	 * Import settings from pasted JSON (admin-post).
	 */
	public function handle_import_settings(): void {
		$this->guard( 'mailkite_smtp_import_settings' );

		$json = isset( $_POST['settings_json'] ) ? wp_unslash( $_POST['settings_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed as JSON, sanitized in Options::sanitize().
		$data = json_decode( (string) $json, true );
		if ( is_array( $data ) ) {
			unset( $data['api_key'], $data['smtp_password'], $data['sendgrid_key'], $data['brevo_key'], $data['mailgun_key'], $data['inbound_secret'], $data['inbound_hmac_secret'] );
			Options::update( $data );
			$this->redirect( 'settings', 'saved' );
		}
		$this->redirect( 'settings', 'import_failed' );
	}

	/**
	 * Send a test email (admin-post).
	 */
	public function handle_test(): void {
		$this->guard( 'mailkite_smtp_test' );

		$to = isset( $_POST['test_to'] ) ? sanitize_email( wp_unslash( $_POST['test_to'] ) ) : '';
		if ( ! $to ) {
			$this->redirect( 'test', 'test_invalid' );
		}

		$sent = wp_mail(
			$to,
			__( 'MailKite SMTP test email', 'mailkite-smtp' ),
			sprintf(
				/* translators: 1: site URL, 2: mailer name. */
				__( "This is a test email from %1\$s sent via the '%2\$s' mailer. If you are reading this, delivery works.", 'mailkite-smtp' ),
				home_url(),
				(string) Options::get( 'mailer' )
			)
		);

		$this->redirect( 'test', $sent ? 'test_sent' : 'test_failed' );
	}

	/**
	 * Import settings from another SMTP plugin (admin-post).
	 */
	public function handle_import(): void {
		$this->guard( 'mailkite_smtp_import' );

		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$done   = $source && \MailKite\Smtp\Migrate\Importer::import( $source );

		$this->redirect( 'settings', $done ? 'imported' : 'import_failed' );
	}

	/**
	 * Export the email log as CSV (admin-post).
	 */
	public function handle_export(): void {
		$this->guard( 'mailkite_smtp_export' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table, admin export.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT created_at, mail_to, subject, mailer, status, error, redacted FROM %i ORDER BY id DESC', LogTable::name() ), ARRAY_A );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=mailkite-smtp-log-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV response.
		fputcsv( $out, [ 'created_at_utc', 'to', 'subject', 'mailer', 'status', 'error', 'redacted' ] );
		foreach ( (array) $rows as $row ) {
			fputcsv( $out, array_values( $row ) );
		}
		exit;
	}

	/**
	 * Resend a logged email (admin-post).
	 */
	public function handle_resend(): void {
		$this->guard( 'mailkite_smtp_resend' );

		global $wpdb;
		$id  = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', LogTable::name(), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table.

		if ( ! $row || null === $row->body ) {
			$this->redirect( 'log', 'resend_failed' );
		}

		$headers = json_decode( (string) $row->headers, true );
		$sent    = wp_mail( explode( ', ', $row->mail_to ), $row->subject, $row->body, is_array( $headers ) ? $headers : [] );
		$this->redirect( 'log', $sent ? 'resent' : 'resend_failed' );
	}

	/**
	 * Reply to a received message (admin-post). Threads via In-Reply-To.
	 */
	public function handle_reply(): void {
		$this->guard( 'mailkite_smtp_reply' );

		global $wpdb;
		$id   = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
		$body = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', LogTable::name(), $id ) );

		if ( ! $row || 'inbound' !== $row->mailer || ! $row->from_addr || '' === $body ) {
			$this->redirect( 'log', 'reply_failed' );
		}

		$from    = $this->reply_from_address( (string) $row->mail_to );
		$subject = (string) $row->subject;
		$headers = [ 'From: ' . $from ];
		if ( ! empty( $row->thread_id ) ) {
			// MailKite threads on this value (its resolved conversation root); the mailer
			// promotes it to the API's inReplyTo, and other clients read the header.
			$headers[] = 'In-Reply-To: ' . $row->thread_id;
			$headers[] = 'References: ' . $row->thread_id;
		}

		$sent = wp_mail(
			(string) $row->from_addr,
			str_starts_with( strtolower( $subject ), 're:' ) ? $subject : 'Re: ' . $subject,
			$body,
			$headers
		);

		$this->redirect( 'log', $sent ? 'replied' : 'reply_failed' );
	}

	/**
	 * Nonce + capability guard for admin-post handlers.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mailkite-smtp' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to a tab with a notice code.
	 *
	 * @param string $tab    Target tab.
	 * @param string $notice Notice code.
	 * @return never
	 */
	private function redirect( string $tab, string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'   => self::SLUG,
					'tab'    => $tab,
					'notice' => $notice,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Tab navigation.
	 *
	 * @param string $active Active tab key.
	 */
	private function render_tabs( string $active ): void {
		$tabs = [
			'settings' => __( 'Settings', 'mailkite-smtp' ),
			'log'      => __( 'Email Log', 'mailkite-smtp' ),
			'test'     => __( 'Send Test', 'mailkite-smtp' ),
			'inbound'  => __( 'Inbound', 'mailkite-smtp' ),
			'health'   => __( 'Domain Health', 'mailkite-smtp' ),
		];
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url(
					add_query_arg(
						[
							'page' => self::SLUG,
							'tab'  => $key,
						],
						admin_url( 'admin.php' )
					)
				),
				$key === $active ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * Admin notice for ?notice= codes.
	 */
	private function render_notice(): void {
		$code = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! $code ) {
			return;
		}
		$map = [
			'saved'         => [ 'success', __( 'Settings saved.', 'mailkite-smtp' ) ],
			'provisioned'   => [ 'success', __( 'MailKite account created and connected! Check your inbox and click the verification link — sending stays blocked until the email is verified.', 'mailkite-smtp' ) ],
			'connected'     => [ 'success', __( 'MailKite account connected.', 'mailkite-smtp' ) ],
			'account_exists' => [ 'error', __( 'That email already has a MailKite account — use "Connect existing account" below, or paste its API key.', 'mailkite-smtp' ) ],
			'provision_invalid' => [ 'error', __( 'Enter a valid email address.', 'mailkite-smtp' ) ],
			'provision_failed' => [ 'error', __( 'Could not create the account — try again in a minute.', 'mailkite-smtp' ) ],
			'rate_limited'  => [ 'error', __( 'Too many attempts — try again later.', 'mailkite-smtp' ) ],
			'oauth_failed'  => [ 'error', __( 'Connecting to MailKite failed — try again, or paste an API key instead.', 'mailkite-smtp' ) ],
			'inbound_on'    => [ 'success', __( 'Inbound is on — the webhook and signature verification were set up on your MailKite domain automatically.', 'mailkite-smtp' ) ],
			'inbound_off'   => [ 'success', __( 'Inbound turned off and the webhook removed.', 'mailkite-smtp' ) ],
			'inbound_failed' => [ 'error', __( 'Could not set up the webhook on MailKite — check the domain is verified and try again.', 'mailkite-smtp' ) ],
			'imported'      => [ 'success', __( 'Settings imported — review below, then send a test email.', 'mailkite-smtp' ) ],
			'import_failed' => [ 'error', __( 'Nothing importable found (only generic-SMTP configurations can be imported).', 'mailkite-smtp' ) ],
			'test_sent'     => [ 'success', __( 'Test email sent — check the inbox (and the Email Log tab).', 'mailkite-smtp' ) ],
			'test_failed'   => [ 'error', __( 'Test email failed — see the Email Log tab for the error.', 'mailkite-smtp' ) ],
			'test_invalid'  => [ 'error', __( 'Enter a valid recipient address.', 'mailkite-smtp' ) ],
			'resent'        => [ 'success', __( 'Email resent.', 'mailkite-smtp' ) ],
			'replied'       => [ 'success', __( 'Reply sent — it appears in the log as an outgoing message in the same conversation.', 'mailkite-smtp' ) ],
			'reply_failed'  => [ 'error', __( 'The reply could not be sent — check the log entry for the error.', 'mailkite-smtp' ) ],
			'resend_failed' => [ 'error', __( 'Could not resend (redacted or failed).', 'mailkite-smtp' ) ],
		];
		if ( isset( $map[ $code ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $code ][0] ), esc_html( $map[ $code ][1] ) );
		}
	}

	/**
	 * Settings tab.
	 */
	private function render_settings(): void {
		$s          = Options::all();
		$mailer     = (string) $s['mailer'];
		$key_set    = '' !== (string) $s['api_key'];
		$key_locked = defined( 'MAILKITE_API_KEY' ) && MAILKITE_API_KEY;

		$status = \MailKite\Smtp\Admin\Connect::account_status();
		if ( $key_set ) :
			?>
			<div class="notice <?php echo ( $status && $status['emailVerified'] ) ? 'notice-success' : 'notice-warning'; ?>" style="padding:10px 12px">
				<p style="margin:0">
					<strong><?php esc_html_e( 'MailKite account:', 'mailkite-smtp' ); ?></strong>
					<?php if ( $status ) : ?>
						<?php echo esc_html( $status['email'] ); ?> (<?php echo esc_html( $status['plan'] ); ?>)
						<?php if ( $status['emailVerified'] ) : ?>
							— <span style="color:#00a32a">✓ <?php esc_html_e( 'email verified', 'mailkite-smtp' ); ?></span>
						<?php else : ?>
							— <span style="color:#b45309">⚠ <?php esc_html_e( 'email not verified yet — sending is blocked. Click the link in your inbox.', 'mailkite-smtp' ); ?></span>
						<?php endif; ?>
					<?php else : ?>
						<?php esc_html_e( 'API key saved — could not reach MailKite to confirm account status.', 'mailkite-smtp' ); ?>
					<?php endif; ?>
				</p>
				<div style="margin-top:6px">
					<?php if ( ! $status['emailVerified'] ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
							<input type="hidden" name="action" value="mailkite_smtp_recheck" />
							<?php wp_nonce_field( 'mailkite_smtp_recheck' ); ?>
							<button type="submit" class="button button-small"><?php esc_html_e( 'Re-check verification', 'mailkite-smtp' ); ?></button>
						</form>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
						<input type="hidden" name="action" value="mailkite_smtp_disconnect" />
						<?php wp_nonce_field( 'mailkite_smtp_disconnect' ); ?>
						<button type="submit" class="button-link" style="color:#b32d2e"><?php esc_html_e( 'Disconnect', 'mailkite-smtp' ); ?></button>
					</form>
				</div>
			</div>
			<?php
		elseif ( ! $key_set ) :
			$admin_email = (string) get_option( 'admin_email' );
			?>
			<div class="notice notice-info" style="padding:12px">
				<p style="margin-top:0"><strong><?php esc_html_e( 'Get connected to MailKite — without leaving WordPress', 'mailkite-smtp' ); ?></strong></p>
				<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;align-items:center;gap:8px;margin:0">
						<input type="hidden" name="action" value="mailkite_smtp_provision" />
						<?php wp_nonce_field( 'mailkite_smtp_provision' ); ?>
						<input type="email" name="account_email" value="<?php echo esc_attr( $admin_email ); ?>" class="regular-text" style="max-width:16em" />
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Create free account', 'mailkite-smtp' ); ?></button>
					</form>
					<span class="description"><?php esc_html_e( 'or', 'mailkite-smtp' ); ?></span>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
						<input type="hidden" name="action" value="mailkite_smtp_oauth_start" />
						<?php wp_nonce_field( 'mailkite_smtp_oauth_start' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Connect existing account', 'mailkite-smtp' ); ?></button>
					</form>
				</div>
				<p class="description" style="margin-bottom:0"><?php esc_html_e( 'Create: a free account for this email — an API key is set up instantly, and you verify the address from your inbox. Connect: sign in on mailkite.dev (password or Google) and the key is fetched for you. Or paste a key manually below.', 'mailkite-smtp' ); ?></p>
			</div>
			<?php
		endif;

		$detected     = \MailKite\Smtp\Migrate\Importer::detect();
		$unconfigured = ! $key_set && '' === (string) $s['smtp_host'];
		if ( $detected && $unconfigured ) :
			?>
			<div class="notice notice-info" style="padding:12px">
				<p style="margin-top:0"><strong><?php esc_html_e( 'Import your existing SMTP settings', 'mailkite-smtp' ); ?></strong> —
				<?php esc_html_e( 'we found a configuration from another SMTP plugin on this site. Import it with one click (the original plugin is not modified):', 'mailkite-smtp' ); ?></p>
				<?php foreach ( $detected as $slug => $label ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
						<input type="hidden" name="action" value="mailkite_smtp_import" />
						<input type="hidden" name="source" value="<?php echo esc_attr( $slug ); ?>" />
						<?php wp_nonce_field( 'mailkite_smtp_import' ); ?>
						<button type="submit" class="button">
							<?php
							/* translators: %s: source plugin name. */
							printf( esc_html__( 'Import from %s', 'mailkite-smtp' ), esc_html( $label ) );
							?>
						</button>
					</form>
				<?php endforeach; ?>
			</div>
			<?php
		endif;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mailkite_smtp_save" />
			<?php wp_nonce_field( 'mailkite_smtp_save' ); ?>

			<h2><?php esc_html_e( 'Mailer', 'mailkite-smtp' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Send emails via', 'mailkite-smtp' ); ?></th>
					<td>
						<label><input type="radio" name="mailer" value="mailkite" <?php checked( $mailer, 'mailkite' ); ?> />
							<strong><?php esc_html_e( 'MailKite (recommended)', 'mailkite-smtp' ); ?></strong>
							— <?php esc_html_e( 'free tier, 2-minute setup, inbound email included', 'mailkite-smtp' ); ?></label><br/>
						<label><input type="radio" name="mailer" value="sendgrid" <?php checked( $mailer, 'sendgrid' ); ?> />
							<?php esc_html_e( 'SendGrid (your API key)', 'mailkite-smtp' ); ?></label><br/>
						<label><input type="radio" name="mailer" value="brevo" <?php checked( $mailer, 'brevo' ); ?> />
							<?php esc_html_e( 'Brevo (your API key)', 'mailkite-smtp' ); ?></label><br/>
						<label><input type="radio" name="mailer" value="mailgun" <?php checked( $mailer, 'mailgun' ); ?> />
							<?php esc_html_e( 'Mailgun (your API key)', 'mailkite-smtp' ); ?></label><br/>
						<label><input type="radio" name="mailer" value="smtp" <?php checked( $mailer, 'smtp' ); ?> />
							<?php esc_html_e( 'Other SMTP server (bring your own credentials)', 'mailkite-smtp' ); ?></label><br/>
						<label><input type="radio" name="mailer" value="php" <?php checked( $mailer, 'php' ); ?> />
							<?php esc_html_e( 'PHP mail() — WordPress default (unreliable on most hosts)', 'mailkite-smtp' ); ?></label>
					</td>
				</tr>
			</table>

			<div data-mk-section="mailkite">
			<h2><?php esc_html_e( 'MailKite', 'mailkite-smtp' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mk-api-key"><?php esc_html_e( 'API key', 'mailkite-smtp' ); ?></label></th>
					<td>
						<?php if ( $key_locked ) : ?>
							<em><?php esc_html_e( 'Set via the MAILKITE_API_KEY constant in wp-config.php.', 'mailkite-smtp' ); ?></em>
						<?php else : ?>
							<input type="password" class="regular-text" id="mk-api-key" name="api_key" value="" autocomplete="new-password"
								placeholder="<?php echo esc_attr( $key_set ? __( '•••••••• (saved — enter to replace)', 'mailkite-smtp' ) : 'mk_...' ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to MailKite dashboard. */
									esc_html__( 'No account yet? Create one free at %s — a guided in-admin setup is coming in the next release.', 'mailkite-smtp' ),
									'<a href="https://app.mailkite.dev/?ref=xmbf3bd0&amp;channel=wordpress-plugin&amp;utm_source=wp-plugin&amp;utm_medium=plugin" target="_blank" rel="noopener">app.mailkite.dev</a>'
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tracking', 'mailkite-smtp' ); ?></th>
					<td>
						<?php
						$track_options = [
							'default' => __( 'Domain default', 'mailkite-smtp' ),
							'on'      => __( 'On', 'mailkite-smtp' ),
							'off'     => __( 'Off', 'mailkite-smtp' ),
						];
						foreach ( [
							'track_opens'  => __( 'Opens', 'mailkite-smtp' ),
							'track_clicks' => __( 'Clicks', 'mailkite-smtp' ),
						] as $field => $label ) :
							?>
							<label style="margin-right:1.5em"><?php echo esc_html( $label ); ?>
								<select name="<?php echo esc_attr( $field ); ?>">
									<?php foreach ( $track_options as $value => $text ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s[ $field ], $value ); ?>><?php echo esc_html( $text ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>
			</div>

			<div data-mk-section="sendgrid">
			<h2>SendGrid</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mk-sg-key"><?php esc_html_e( 'API key', 'mailkite-smtp' ); ?></label></th>
					<td><input type="password" class="regular-text" id="mk-sg-key" name="sendgrid_key" value="" autocomplete="new-password"
						placeholder="<?php echo esc_attr( '' !== (string) $s['sendgrid_key'] ? __( '•••••••• (saved — enter to replace)', 'mailkite-smtp' ) : 'SG.' ); ?>" /></td>
				</tr>
			</table>
			</div>

			<div data-mk-section="brevo">
			<h2>Brevo</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mk-brevo-key"><?php esc_html_e( 'API key', 'mailkite-smtp' ); ?></label></th>
					<td><input type="password" class="regular-text" id="mk-brevo-key" name="brevo_key" value="" autocomplete="new-password"
						placeholder="<?php echo esc_attr( '' !== (string) $s['brevo_key'] ? __( '•••••••• (saved — enter to replace)', 'mailkite-smtp' ) : 'xkeysib-' ); ?>" /></td>
				</tr>
			</table>
			</div>

			<div data-mk-section="mailgun">
			<h2>Mailgun</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mk-mg-key"><?php esc_html_e( 'API key', 'mailkite-smtp' ); ?></label></th>
					<td><input type="password" class="regular-text" id="mk-mg-key" name="mailgun_key" value="" autocomplete="new-password"
						placeholder="<?php echo esc_attr( '' !== (string) $s['mailgun_key'] ? __( '•••••••• (saved — enter to replace)', 'mailkite-smtp' ) : '' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="mk-mg-domain"><?php esc_html_e( 'Sending domain', 'mailkite-smtp' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="mk-mg-domain" name="mailgun_domain" value="<?php echo esc_attr( (string) $s['mailgun_domain'] ); ?>" placeholder="mg.example.com" />
						<select name="mailgun_region">
							<option value="us" <?php selected( $s['mailgun_region'], 'us' ); ?>>US</option>
							<option value="eu" <?php selected( $s['mailgun_region'], 'eu' ); ?>>EU</option>
						</select>
					</td>
				</tr>
			</table>
			</div>

			<div data-mk-section="smtp">
			<h2><?php esc_html_e( 'SMTP server', 'mailkite-smtp' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mk-smtp-host"><?php esc_html_e( 'Host', 'mailkite-smtp' ); ?></label></th>
					<td><input type="text" class="regular-text" id="mk-smtp-host" name="smtp_host" value="<?php echo esc_attr( (string) $s['smtp_host'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="mk-smtp-port"><?php esc_html_e( 'Port', 'mailkite-smtp' ); ?></label></th>
					<td><input type="number" min="1" max="65535" id="mk-smtp-port" name="smtp_port" value="<?php echo esc_attr( (string) $s['smtp_port'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Encryption', 'mailkite-smtp' ); ?></th>
					<td>
						<select name="smtp_encryption">
							<?php
							foreach ( [
								'tls'  => 'TLS (STARTTLS)',
								'ssl'  => 'SSL',
								'none' => __( 'None', 'mailkite-smtp' ),
							] as $value => $label ) :
								?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['smtp_encryption'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<label style="margin-left:1em"><input type="checkbox" name="smtp_auth" <?php checked( (bool) $s['smtp_auth'] ); ?> /> <?php esc_html_e( 'Use authentication', 'mailkite-smtp' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mk-smtp-user"><?php esc_html_e( 'Username', 'mailkite-smtp' ); ?></label></th>
					<td><input type="text" class="regular-text" id="mk-smtp-user" name="smtp_username" value="<?php echo esc_attr( (string) $s['smtp_username'] ); ?>" autocomplete="off" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="mk-smtp-pass"><?php esc_html_e( 'Password', 'mailkite-smtp' ); ?></label></th>
					<td><input type="password" class="regular-text" id="mk-smtp-pass" name="smtp_password" value="" autocomplete="new-password"
						placeholder="<?php echo esc_attr( '' !== (string) $s['smtp_password'] ? __( '•••••••• (saved — enter to replace)', 'mailkite-smtp' ) : '' ); ?>" /></td>
				</tr>
			</table>
			</div>

			<script>
			( function () {
				var radios = document.querySelectorAll( 'input[name="mailer"]' );
				function sync() {
					var picked = document.querySelector( 'input[name="mailer"]:checked' );
					var mailer = picked ? picked.value : 'php';
					document.querySelectorAll( '[data-mk-section]' ).forEach( function ( el ) {
						el.style.display = el.getAttribute( 'data-mk-section' ) === mailer ? '' : 'none';
					} );
				}
				radios.forEach( function ( r ) { r.addEventListener( 'change', sync ); } );
				sync();
			} )();
			</script>

			<h2><?php esc_html_e( 'From address', 'mailkite-smtp' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mk-from-email"><?php esc_html_e( 'Force from email', 'mailkite-smtp' ); ?></label></th>
					<td><input type="email" class="regular-text" id="mk-from-email" name="force_from_email" value="<?php echo esc_attr( (string) $s['force_from_email'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Overrides the sender other plugins set. Use an address on your verified sending domain.', 'mailkite-smtp' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="mk-from-name"><?php esc_html_e( 'Force from name', 'mailkite-smtp' ); ?></label></th>
					<td><input type="text" class="regular-text" id="mk-from-name" name="force_from_name" value="<?php echo esc_attr( (string) $s['force_from_name'] ); ?>" /></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Reliability', 'mailkite-smtp' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Failover', 'mailkite-smtp' ); ?></th>
					<td>
						<label><input type="checkbox" name="fallback_enabled" <?php checked( (bool) $s['fallback_enabled'] ); ?> />
						<?php esc_html_e( 'If MailKite fails, automatically retry via your SMTP server (or PHP mail) so the email still goes out', 'mailkite-smtp' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Failure alerts', 'mailkite-smtp' ); ?></th>
					<td>
						<label><input type="checkbox" name="alerts_enabled" <?php checked( (bool) $s['alerts_enabled'] ); ?> />
						<?php esc_html_e( 'Email me immediately when a send fails (max one alert per error per 15 minutes)', 'mailkite-smtp' ); ?></label><br/>
						<input type="email" class="regular-text" name="alert_email" value="<?php echo esc_attr( (string) $s['alert_email'] ); ?>"
							placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" style="margin-top:6px" />
						<p class="description"><?php esc_html_e( 'Leave empty to use the site admin email.', 'mailkite-smtp' ); ?></p>
						<input type="url" class="regular-text" name="alert_webhook" value="<?php echo esc_attr( (string) $s['alert_webhook'] ); ?>"
							placeholder="https://hooks.slack.com/services/…" style="margin-top:6px" />
						<p class="description"><?php esc_html_e( 'Optional: also POST alerts to a Slack, Discord, or generic webhook URL.', 'mailkite-smtp' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Routing rules', 'mailkite-smtp' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Send matching emails through a different mailer — e.g. WooCommerce receipts via MailKite, everything with "Newsletter" in the subject via your own SES SMTP. First matching rule wins.', 'mailkite-smtp' ); ?></p>
			<table class="widefat striped" style="max-width:760px" id="mk-rules">
				<thead><tr>
					<th><?php esc_html_e( 'When', 'mailkite-smtp' ); ?></th>
					<th><?php esc_html_e( 'contains', 'mailkite-smtp' ); ?></th>
					<th><?php esc_html_e( 'send via', 'mailkite-smtp' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php
				$rules   = (array) $s['routing_rules'];
				$rules[] = [
					'field'  => 'subject',
					'match'  => '',
					'mailer' => 'mailkite',
				]; // One blank row to fill in.
				foreach ( $rules as $i => $rule ) :
					?>
					<tr>
						<td>
							<select name="routing_rules[<?php echo (int) $i; ?>][field]">
								<option value="subject" <?php selected( $rule['field'], 'subject' ); ?>><?php esc_html_e( 'Subject', 'mailkite-smtp' ); ?></option>
								<option value="to" <?php selected( $rule['field'], 'to' ); ?>><?php esc_html_e( 'Recipient', 'mailkite-smtp' ); ?></option>
							</select>
						</td>
						<td><input type="text" name="routing_rules[<?php echo (int) $i; ?>][match]" value="<?php echo esc_attr( (string) $rule['match'] ); ?>" /></td>
						<td>
							<select name="routing_rules[<?php echo (int) $i; ?>][mailer]">
								<?php foreach ( \MailKite\Smtp\Options::MAILERS as $m ) : ?>
									<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $rule['mailer'], $m ); ?>><?php echo esc_html( $m ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><button type="button" class="button-link-delete mk-rule-del" aria-label="<?php esc_attr_e( 'Remove rule', 'mailkite-smtp' ); ?>">×</button></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button" id="mk-rule-add"><?php esc_html_e( 'Add rule', 'mailkite-smtp' ); ?></button>
			<span class="description"><?php esc_html_e( 'Rules with an empty "contains" are ignored.', 'mailkite-smtp' ); ?></span></p>
			<script>
			( function () {
				document.getElementById( 'mk-rule-add' ).addEventListener( 'click', function () {
					var tbody = document.querySelector( '#mk-rules tbody' );
					var row   = tbody.rows[ tbody.rows.length - 1 ].cloneNode( true );
					var index = tbody.rows.length;
					row.querySelectorAll( 'select,input' ).forEach( function ( el ) {
						el.name = el.name.replace( /\[\d+\]/, '[' + index + ']' );
						if ( 'text' === el.type ) { el.value = ''; }
					} );
					tbody.appendChild( row );
				} );
				document.getElementById( 'mk-rules' ).addEventListener( 'click', function ( e ) {
					if ( e.target.classList.contains( 'mk-rule-del' ) ) {
						var tbody = document.querySelector( '#mk-rules tbody' );
						if ( tbody.rows.length > 1 ) { e.target.closest( 'tr' ).remove(); } else { e.target.closest( 'tr' ).querySelector( 'input[type=text]' ).value = ''; }
					}
				} );
			} )();
			</script>

			<h2><?php esc_html_e( 'Email log', 'mailkite-smtp' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Logging', 'mailkite-smtp' ); ?></th>
					<td>
						<label><input type="checkbox" name="log_enabled" <?php checked( (bool) $s['log_enabled'] ); ?> /> <?php esc_html_e( 'Log sent emails (free, always)', 'mailkite-smtp' ); ?></label><br/>
						<label><input type="checkbox" name="log_redact_auth" <?php checked( (bool) $s['log_redact_auth'] ); ?> /> <?php esc_html_e( 'Do not store bodies of password-reset and verification emails (recommended)', 'mailkite-smtp' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mk-retention"><?php esc_html_e( 'Keep logs for', 'mailkite-smtp' ); ?></label></th>
					<td>
						<input type="number" min="1" id="mk-retention" name="log_retention" value="<?php echo esc_attr( (string) $s['log_retention'] ); ?>" style="width:5em" />
						<?php esc_html_e( 'days (older entries are purged daily)', 'mailkite-smtp' ); ?>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Settings', 'mailkite-smtp' ) ); ?>
		</form>

		<hr/>
		<h2><?php esc_html_e( 'Backup & migrate', 'mailkite-smtp' ); ?></h2>
		<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
				<input type="hidden" name="action" value="mailkite_smtp_export_settings" />
				<?php wp_nonce_field( 'mailkite_smtp_export_settings' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Export settings (JSON, no secrets)', 'mailkite-smtp' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;align-items:center;gap:8px;margin:0">
				<input type="hidden" name="action" value="mailkite_smtp_import_settings" />
				<?php wp_nonce_field( 'mailkite_smtp_import_settings' ); ?>
				<input type="text" name="settings_json" class="regular-text" placeholder='{"mailer":"mailkite", …}' />
				<button type="submit" class="button"><?php esc_html_e( 'Import JSON', 'mailkite-smtp' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Inbound tab: one-click automated setup — the plugin installs the webhook on
	 * the connected MailKite account itself (URL + signing secret), no copy-paste.
	 */
	private function render_inbound(): void {
		$s         = Options::all();
		$connected = (bool) $s['inbound_enabled'] && '' !== (string) $s['inbound_domain_id'];
		$key_set   = '' !== (string) $s['api_key'];
		?>
		<p style="margin-top:1em"><?php esc_html_e( 'Receive email into WordPress: every message to your MailKite domain fires a hook your plugins can consume — and can be forwarded to an inbox.', 'mailkite-smtp' ); ?></p>
		<?php
		if ( ! $key_set ) :
			?>
			<div class="notice notice-info" style="padding:12px"><p style="margin:0">
				<?php esc_html_e( 'Connect a MailKite account first —', 'mailkite-smtp' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mailkite-smtp' ) ); ?>"><?php esc_html_e( 'go to Settings', 'mailkite-smtp' ); ?></a>
			</p></div>
			<?php
		elseif ( $connected ) :
			?>
			<div class="notice notice-success" style="padding:12px">
				<p style="margin-top:0">
					<strong>✓ <?php esc_html_e( 'Inbound is on', 'mailkite-smtp' ); ?></strong> —
					<?php
					/* translators: %s: domain name. */
					printf( esc_html__( 'email to %s is delivered to this site.', 'mailkite-smtp' ), '<code>' . esc_html( (string) $s['inbound_domain'] ) . '</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline.
					?>
					<span class="description"><?php esc_html_e( 'Deliveries are HMAC-signature verified automatically.', 'mailkite-smtp' ); ?></span>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
					<input type="hidden" name="action" value="mailkite_smtp_inbound_disconnect" />
					<?php wp_nonce_field( 'mailkite_smtp_inbound_disconnect' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Turn off inbound', 'mailkite-smtp' ); ?></button>
				</form>
			</div>
			<?php
		else :
			$domains = \MailKite\Smtp\Inbound::list_domains();
			if ( null === $domains ) :
				?>
				<div class="notice notice-warning" style="padding:12px"><p style="margin:0"><?php esc_html_e( 'Could not reach MailKite to list your domains — try again shortly.', 'mailkite-smtp' ); ?></p></div>
				<?php
			elseif ( ! $domains ) :
				?>
				<div class="notice notice-info" style="padding:12px"><p style="margin:0">
					<?php esc_html_e( 'Your MailKite account has no domains yet. Add and verify one first:', 'mailkite-smtp' ); ?>
					<a href="https://app.mailkite.dev" target="_blank" rel="noopener">app.mailkite.dev</a>
				</p></div>
				<?php
			else :
				// Preselect the most likely domain: forced-from domain, else the site host, else the first.
				$from      = (string) $s['force_from_email'];
				$from_dom  = is_email( $from ) ? substr( $from, strpos( $from, '@' ) + 1 ) : '';
				$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
				$preselect = $domains[0]['id'];
				foreach ( $domains as $d ) {
					if ( $d['domain'] === $from_dom || $d['domain'] === $site_host ) {
						$preselect = $d['id'];
						break;
					}
				}
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
					<input type="hidden" name="action" value="mailkite_smtp_inbound_connect" />
					<?php wp_nonce_field( 'mailkite_smtp_inbound_connect' ); ?>
					<label for="mk-inb-domain"><?php esc_html_e( 'Receive email for', 'mailkite-smtp' ); ?></label>
					<select name="inbound_domain_id" id="mk-inb-domain">
						<?php foreach ( $domains as $d ) : ?>
							<option value="<?php echo esc_attr( $d['id'] ); ?>" <?php selected( $preselect, $d['id'] ); ?>><?php echo esc_html( $d['domain'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Turn on inbound', 'mailkite-smtp' ); ?></button>
					<span class="description"><?php esc_html_e( 'Installs the webhook and signature verification automatically.', 'mailkite-smtp' ); ?></span>
				</form>
				<?php
			endif;
		endif;
		?>
		<h2 style="margin-top:1.5em"><?php esc_html_e( 'What inbound email gives you', 'mailkite-smtp' ); ?></h2>
		<div style="display:flex;gap:16px;flex-wrap:wrap;max-width:1100px">

			<div class="card" style="flex:1 1 300px;margin:0;padding:16px;max-width:none">
				<h3 style="margin-top:0">1. <?php esc_html_e( 'Turn an email into a WordPress action', 'mailkite-smtp' ); ?></h3>
				<p><?php esc_html_e( 'Every message that arrives fires a hook your plugins and theme can act on: open a support ticket, attach a customer’s reply to their WooCommerce order, post to a forum, or hand it to an AI agent. No other SMTP plugin can do this.', 'mailkite-smtp' ); ?></p>
				<pre style="background:#f6f7f7;padding:12px;overflow:auto;font-size:12px">add_action( 'mailkite_smtp_inbound', function ( $message, $payload ) {
	// $message: from, to, subject, text, html, attachments …
	error_log( 'Email from ' . $message['from'] );
}, 10, 2 );</pre>
			</div>

			<div class="card" style="flex:1 1 300px;margin:0;padding:16px;max-width:none">
				<h3 style="margin-top:0">2. <?php esc_html_e( 'Nothing vanishes', 'mailkite-smtp' ); ?></h3>
				<p><?php esc_html_e( 'Your site sends from a no-reply address and people reply anyway. Bounces and out-of-office notices come back too. Without inbound those are lost silently — with it they land in the Email Log, right next to the message they answer.', 'mailkite-smtp' ); ?></p>
				<p><a href="<?php echo esc_url( add_query_arg( [ 'page' => self::SLUG, 'tab' => 'log' ], admin_url( 'admin.php' ) ) ); ?>" class="button button-small"><?php esc_html_e( 'Open the Email Log', 'mailkite-smtp' ); ?></a></p>
			</div>

			<div class="card" style="flex:1 1 300px;margin:0;padding:16px;max-width:none">
				<h3 style="margin-top:0">3. <?php esc_html_e( 'Or just forward it to your inbox', 'mailkite-smtp' ); ?></h3>
				<p><?php esc_html_e( 'No code, no new place to check: send a copy of everything that arrives to an address you already read, such as your Gmail.', 'mailkite-smtp' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mailkite_smtp_save" />
					<input type="hidden" name="_back_tab" value="inbound" />
					<?php wp_nonce_field( 'mailkite_smtp_save' ); ?>
					<p>
						<label for="mk-inb-fwd" class="screen-reader-text"><?php esc_html_e( 'Forward a copy to', 'mailkite-smtp' ); ?></label>
						<input type="email" class="regular-text" id="mk-inb-fwd" name="inbound_forward" style="max-width:100%"
							value="<?php echo esc_attr( (string) $s['inbound_forward'] ); ?>" placeholder="you@example.com" />
					</p>
					<button type="submit" class="button"><?php esc_html_e( 'Save forwarding address', 'mailkite-smtp' ); ?></button>
				</form>
			</div>

		</div>
		<?php
	}

	/**
	 * Domain Health tab: SPF/DMARC cards + on-demand re-check.
	 */
	private function render_health(): void {
		$health = \MailKite\Smtp\Health::latest();
		?>
		<h2>
			<?php
			/* translators: %s: sending domain. */
			printf( esc_html__( 'DNS for %s', 'mailkite-smtp' ), esc_html( $health['domain'] ) );
			?>
		</h2>
		<table class="widefat striped" style="max-width:640px">
			<tbody>
				<?php
				foreach ( [
					'spf'   => 'SPF',
					'dmarc' => 'DMARC',
				] as $key => $label ) :
					$ok = ! empty( $health[ $key ] );
					?>
					<tr>
						<td><strong><?php echo esc_html( $label ); ?></strong></td>
						<td>
							<?php if ( $ok ) : ?>
								<span style="color:#00a32a">✓ <?php esc_html_e( 'record found', 'mailkite-smtp' ); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e">✗ <?php esc_html_e( 'missing — emails may land in spam', 'mailkite-smtp' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<td><?php esc_html_e( 'Last checked (UTC)', 'mailkite-smtp' ); ?></td>
					<td><?php echo esc_html( $health['checked_at'] ); ?></td>
				</tr>
			</tbody>
		</table>
		<p class="description" style="margin-top:8px"><?php esc_html_e( 'Re-checked automatically every week; you get an email alert if a record that used to exist disappears. DKIM is verified by your provider (MailKite shows it under Domains).', 'mailkite-smtp' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mailkite_smtp_health_check" />
			<?php wp_nonce_field( 'mailkite_smtp_health_check' ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Re-check now', 'mailkite-smtp' ); ?></button>
		</form>
		<h2 style="margin-top:1.5em"><?php esc_html_e( 'Weekly summary', 'mailkite-smtp' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mailkite_smtp_save" />
			<input type="hidden" name="_back_tab" value="health" />
			<?php wp_nonce_field( 'mailkite_smtp_save' ); ?>
			<label><input type="checkbox" name="summary_enabled" <?php checked( (bool) Options::get( 'summary_enabled' ) ); ?> />
			<?php esc_html_e( 'Email me a weekly delivery summary (sent/failed counts, top errors, DNS status)', 'mailkite-smtp' ); ?></label>
			<?php submit_button( __( 'Save', 'mailkite-smtp' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Email Log tab.
	 */
	private function render_log(): void {
		$view = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		if ( $view ) {
			$this->render_log_detail( $view );

			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table, admin read.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, mail_to, from_addr, subject, mailer, status, error, redacted, body IS NULL AS no_body FROM %i ORDER BY id DESC LIMIT 100', LogTable::name() ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em">
			<input type="hidden" name="action" value="mailkite_smtp_export" />
			<?php wp_nonce_field( 'mailkite_smtp_export' ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Export CSV', 'mailkite-smtp' ); ?></button>
		</form>
		<table class="widefat striped" style="margin-top:1em">
			<thead><tr>
				<th><?php esc_html_e( 'Date (UTC)', 'mailkite-smtp' ); ?></th>
				<th><?php esc_html_e( 'From / To', 'mailkite-smtp' ); ?></th>
				<th><?php esc_html_e( 'Subject', 'mailkite-smtp' ); ?></th>
				<th><?php esc_html_e( 'Mailer', 'mailkite-smtp' ); ?></th>
				<th><?php esc_html_e( 'Status', 'mailkite-smtp' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No emails logged yet. Send a test from the Send Test tab.', 'mailkite-smtp' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( (array) $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row->created_at ); ?></td>
					<td>
						<?php if ( 'inbound' === $row->mailer && $row->from_addr ) : ?>
							<span title="<?php esc_attr_e( 'Sender', 'mailkite-smtp' ); ?>">&larr; <?php echo esc_html( (string) $row->from_addr ); ?></span>
						<?php else : ?>
							<?php echo esc_html( $row->mail_to ); ?>
						<?php endif; ?>
					</td>
					<td>
						<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::SLUG, 'tab' => 'log', 'view' => (int) $row->id ], admin_url( 'admin.php' ) ) ); ?>">
							<?php echo esc_html( $row->subject ); ?>
						</a>
						<?php if ( $row->redacted ) : ?>
							<span class="dashicons dashicons-lock" title="<?php esc_attr_e( 'Body redacted (authentication email)', 'mailkite-smtp' ); ?>" style="color:#999"></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $row->mailer ); ?></td>
					<td>
						<?php if ( 'failed' === $row->status ) : ?>
							<span style="color:#b32d2e" title="<?php echo esc_attr( (string) $row->error ); ?>">✗ <?php esc_html_e( 'failed', 'mailkite-smtp' ); ?></span>
						<?php elseif ( 'sent' === $row->status ) : ?>
							<span style="color:#00a32a">✓ <?php esc_html_e( 'sent', 'mailkite-smtp' ); ?></span>
						<?php else : ?>
							<?php echo esc_html( $row->status ); ?>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( 'inbound' === $row->mailer && $row->from_addr ) : ?>
							<a class="button button-small" href="<?php echo esc_url( add_query_arg( [ 'page' => self::SLUG, 'tab' => 'log', 'view' => (int) $row->id, 'reply' => 1 ], admin_url( 'admin.php' ) ) ); ?>#reply"><?php esc_html_e( 'Reply', 'mailkite-smtp' ); ?></a>
						<?php endif; ?>
						<?php if ( ! $row->no_body && 'inbound' !== $row->mailer ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<input type="hidden" name="action" value="mailkite_smtp_resend" />
								<input type="hidden" name="log_id" value="<?php echo esc_attr( (string) $row->id ); ?>" />
								<?php wp_nonce_field( 'mailkite_smtp_resend' ); ?>
								<button type="submit" class="button button-small"><?php esc_html_e( 'Resend', 'mailkite-smtp' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * One logged email in full: metadata + readable body. This is where incoming
	 * (inbound) mail is read; outbound entries show the same view.
	 *
	 * @param int $id Log row id.
	 */
	private function render_log_detail( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table, admin read.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', LogTable::name(), $id ) );

		echo '<p style="margin-top:1em"><a href="' . esc_url( add_query_arg( [ 'page' => self::SLUG, 'tab' => 'log' ], admin_url( 'admin.php' ) ) ) . '">&larr; ' . esc_html__( 'Back to the log', 'mailkite-smtp' ) . '</a></p>';

		if ( ! $row ) {
			echo '<p>' . esc_html__( 'Log entry not found (it may have been purged).', 'mailkite-smtp' ) . '</p>';

			return;
		}
		?>
		<table class="widefat striped" style="max-width:860px">
			<tbody>
				<tr><td style="width:9em"><strong><?php esc_html_e( 'Direction', 'mailkite-smtp' ); ?></strong></td>
					<td><?php echo 'inbound' === $row->mailer ? esc_html__( 'Received (inbound)', 'mailkite-smtp' ) : esc_html__( 'Sent (outbound)', 'mailkite-smtp' ) . ' — ' . esc_html( $row->mailer ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Date (UTC)', 'mailkite-smtp' ); ?></strong></td><td><?php echo esc_html( $row->created_at ); ?></td></tr>
				<?php if ( $row->from_addr ) : ?>
					<tr><td><strong><?php esc_html_e( 'From', 'mailkite-smtp' ); ?></strong></td><td><?php echo esc_html( (string) $row->from_addr ); ?></td></tr>
				<?php endif; ?>
				<tr><td><strong><?php esc_html_e( 'To', 'mailkite-smtp' ); ?></strong></td><td><?php echo esc_html( $row->mail_to ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Subject', 'mailkite-smtp' ); ?></strong></td><td><?php echo esc_html( $row->subject ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Status', 'mailkite-smtp' ); ?></strong></td><td><?php echo esc_html( $row->status ); ?></td></tr>
				<?php if ( $row->error ) : ?>
					<tr><td><strong><?php esc_html_e( 'Detail', 'mailkite-smtp' ); ?></strong></td><td><?php echo esc_html( (string) $row->error ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<h2 style="margin-top:1em"><?php esc_html_e( 'Message', 'mailkite-smtp' ); ?></h2>
		<?php if ( $row->redacted ) : ?>
			<p class="description"><?php esc_html_e( 'Body not stored — this looked like an authentication email (password reset / verification), and storing those is off by default for your users’ safety.', 'mailkite-smtp' ); ?></p>
		<?php elseif ( null === $row->body || '' === $row->body ) : ?>
			<p class="description"><?php esc_html_e( 'No body stored for this entry.', 'mailkite-smtp' ); ?></p>
		<?php else : ?>
			<pre style="background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:16px;max-width:860px;max-height:32em;overflow:auto;white-space:pre-wrap"><?php echo esc_html( (string) $row->body ); ?></pre>
		<?php endif; ?>

		<?php
		// The rest of this conversation. thread_id is MailKite's resolved conversation
		// root, so a reply we sent and the message it answers share one — both sides of
		// the exchange show here, oldest first.
		$thread = [];
		if ( ! empty( $row->thread_id ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table, admin read.
			$thread = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, created_at, mail_to, from_addr, subject, mailer, status FROM %i WHERE thread_id = %s AND id <> %d ORDER BY id ASC LIMIT 50',
					LogTable::name(),
					(string) $row->thread_id,
					(int) $row->id
				)
			);
		}
		if ( $thread ) :
			?>
			<h2><?php esc_html_e( 'Conversation', 'mailkite-smtp' ); ?></h2>
			<table class="widefat striped" style="max-width:860px">
				<tbody>
				<?php foreach ( $thread as $t ) : ?>
					<tr>
						<td style="width:11em"><?php echo esc_html( $t->created_at ); ?></td>
						<td style="width:6em"><?php echo 'inbound' === $t->mailer ? esc_html__( 'received', 'mailkite-smtp' ) : esc_html__( 'sent', 'mailkite-smtp' ); ?></td>
						<td><?php echo esc_html( 'inbound' === $t->mailer ? (string) $t->from_addr : (string) $t->mail_to ); ?></td>
						<td><a href="<?php echo esc_url( add_query_arg( [ 'page' => self::SLUG, 'tab' => 'log', 'view' => (int) $t->id ], admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( (string) $t->subject ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		endif;

		// Replying is only meaningful for mail we received, and only when we know who
		// sent it. The From address is forced to the address the message was delivered
		// TO — this site's own verified domain — never a user-chosen value.
		if ( 'inbound' === $row->mailer && $row->from_addr ) :
			$reply_from = $this->reply_from_address( (string) $row->mail_to );
			?>
			<h2 id="reply"><?php esc_html_e( 'Reply', 'mailkite-smtp' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:860px">
				<input type="hidden" name="action" value="mailkite_smtp_reply" />
				<input type="hidden" name="log_id" value="<?php echo esc_attr( (string) $row->id ); ?>" />
				<?php wp_nonce_field( 'mailkite_smtp_reply' ); ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: from address, 2: recipient address. */
						esc_html__( 'From %1$s to %2$s', 'mailkite-smtp' ),
						'<code>' . esc_html( $reply_from ) . '</code>',
						'<code>' . esc_html( (string) $row->from_addr ) . '</code>'
					);
					?>
				</p>
				<textarea name="body" rows="6" class="large-text" required></textarea>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Send reply', 'mailkite-smtp' ); ?></button></p>
			</form>
			<?php
		endif;
	}

	/**
	 * The address a reply goes out as: the address the inbound message was delivered
	 * to (this site's own domain), falling back to the forced from-address.
	 *
	 * @param string $delivered_to The stored recipient value, possibly "Name <addr>, addr2".
	 */
	private function reply_from_address( string $delivered_to ): string {
		$first = trim( (string) explode( ',', $delivered_to )[0] );
		if ( preg_match( '/<([^>]+)>/', $first, $m ) ) {
			$first = trim( $m[1] );
		}

		return is_email( $first ) ? $first : (string) Options::get( 'force_from_email' );
	}

	/**
	 * Send Test tab.
	 */
	private function render_test(): void {
		$user = wp_get_current_user();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em">
			<input type="hidden" name="action" value="mailkite_smtp_test" />
			<?php wp_nonce_field( 'mailkite_smtp_test' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mk-test-to"><?php esc_html_e( 'Send test email to', 'mailkite-smtp' ); ?></label></th>
					<td><input type="email" class="regular-text" id="mk-test-to" name="test_to" value="<?php echo esc_attr( $user->user_email ); ?>" required /></td>
				</tr>
			</table>
			<?php submit_button( __( 'Send Test Email', 'mailkite-smtp' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}
}
