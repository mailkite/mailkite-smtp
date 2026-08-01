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
		add_action( 'admin_post_mailkite_smtp_import', [ $this, 'handle_import' ] );
		add_action( 'admin_post_mailkite_smtp_export', [ $this, 'handle_export' ] );
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
		];
		$input  = [];
		foreach ( $fields as $field ) {
			if ( ! isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in Options::sanitize().
				continue;
			}
			$blank_keeps_saved = in_array( $field, [ 'api_key', 'smtp_password' ], true );
			if ( $blank_keeps_saved && '' === $_POST[ $field ] ) {
				continue; // Empty secret field means "keep the stored one".
			}
			$input[ $field ] = wp_unslash( $_POST[ $field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		foreach ( [ 'smtp_auth', 'log_enabled', 'log_redact_auth' ] as $bool_field ) {
			$input[ $bool_field ] = isset( $_POST[ $bool_field ] );
		}

		Options::update( $input );
		$this->redirect( 'settings', 'saved' );
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
		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'tab' => $tab, 'notice' => $notice ], admin_url( 'admin.php' ) ) );
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
		];
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( add_query_arg( [ 'page' => self::SLUG, 'tab' => $key ], admin_url( 'admin.php' ) ) ),
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
			'imported'      => [ 'success', __( 'Settings imported — review below, then send a test email.', 'mailkite-smtp' ) ],
			'import_failed' => [ 'error', __( 'Nothing importable found (only generic-SMTP configurations can be imported).', 'mailkite-smtp' ) ],
			'test_sent'     => [ 'success', __( 'Test email sent — check the inbox (and the Email Log tab).', 'mailkite-smtp' ) ],
			'test_failed'   => [ 'error', __( 'Test email failed — see the Email Log tab for the error.', 'mailkite-smtp' ) ],
			'test_invalid'  => [ 'error', __( 'Enter a valid recipient address.', 'mailkite-smtp' ) ],
			'resent'        => [ 'success', __( 'Email resent.', 'mailkite-smtp' ) ],
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

		$detected = \MailKite\Smtp\Migrate\Importer::detect();
		if ( $detected && 'php' === $mailer ) :
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
									'<a href="https://app.mailkite.dev/?utm_source=wp-plugin&amp;utm_medium=plugin" target="_blank" rel="noopener">app.mailkite.dev</a>'
								);
								?>
							</p>
						<?php endif; ?>
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
							<?php foreach ( [ 'tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', 'none' => __( 'None', 'mailkite-smtp' ) ] as $value => $label ) : ?>
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
		<?php
	}

	/**
	 * Email Log tab.
	 */
	private function render_log(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom log table, admin read.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, mail_to, subject, mailer, status, error, redacted, body IS NULL AS no_body FROM %i ORDER BY id DESC LIMIT 100', LogTable::name() ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em">
			<input type="hidden" name="action" value="mailkite_smtp_export" />
			<?php wp_nonce_field( 'mailkite_smtp_export' ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Export CSV', 'mailkite-smtp' ); ?></button>
		</form>
		<?php ?>
		<table class="widefat striped" style="margin-top:1em">
			<thead><tr>
				<th><?php esc_html_e( 'Date (UTC)', 'mailkite-smtp' ); ?></th>
				<th><?php esc_html_e( 'To', 'mailkite-smtp' ); ?></th>
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
					<td><?php echo esc_html( $row->mail_to ); ?></td>
					<td>
						<?php echo esc_html( $row->subject ); ?>
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
						<?php if ( ! $row->no_body ) : ?>
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
