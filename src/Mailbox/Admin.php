<?php
/**
 * Mailboxes admin: the settings tab and the per-user credentials screen.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Mailbox;

use MailKite\Smtp\Inbound;
use MailKite\Smtp\Options;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Admin surfaces for user mailboxes:
 *  - Settings → Mailboxes: the switches, the domain, the reserved list, who holds what;
 *  - Profile → Your email address: the user's own address, IMAP settings and API bearer.
 */
final class Admin {

	/**
	 * Hook everything.
	 */
	public function register(): void {
		add_action( 'admin_post_mailkite_smtp_mailbox_claim', [ $this, 'handle_claim' ] );
		add_action( 'admin_post_mailkite_smtp_mailbox_release', [ $this, 'handle_release' ] );
		add_action( 'admin_post_mailkite_smtp_mailbox_regenerate', [ $this, 'handle_regenerate' ] );

		add_action( 'show_user_profile', [ $this, 'render_profile' ] );
		add_action( 'edit_user_profile', [ $this, 'render_profile' ] );

		// A deleted user must not keep a live mail credential.
		add_action( 'delete_user', [ Manager::class, 'release' ] );

		// Lazy auto-assignment: no bulk provisioning, no surprise API storm.
		add_action( 'admin_init', [ $this, 'maybe_auto_assign' ] );
	}

	/**
	 * Auto-assign the current user's address when the admin enabled that.
	 */
	public function maybe_auto_assign(): void {
		if ( is_user_logged_in() ) {
			Manager::maybe_auto_assign( get_current_user_id() );
		}
	}

	/**
	 * Settings → Mailboxes tab.
	 */
	public function render_settings_tab(): void {
		$s       = Options::all();
		$domains = Inbound::list_domains();
		$roles   = wp_roles()->get_names();
		?>
		<p style="margin-top:1em"><?php esc_html_e( 'Give WordPress users a real email address on your domain. Each mailbox works in Apple Mail, Thunderbird or any IMAP client, and in the Inbox screen here. Everything is off until you switch it on.', 'mailkite-smtp' ); ?></p>

		<?php if ( '' === (string) $s['api_key'] ) : ?>
			<div class="notice notice-info" style="padding:12px"><p style="margin:0">
				<?php esc_html_e( 'Connect a MailKite account first —', 'mailkite-smtp' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mailkite-smtp' ) ); ?>"><?php esc_html_e( 'go to Settings', 'mailkite-smtp' ); ?></a>
			</p></div>
			<?php return; ?>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mailkite_smtp_save" />
			<input type="hidden" name="_back_tab" value="mailboxes" />
			<?php wp_nonce_field( 'mailkite_smtp_save' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'User mailboxes', 'mailkite-smtp' ); ?></th>
					<td><label><input type="checkbox" name="mailboxes_enabled" <?php checked( (bool) $s['mailboxes_enabled'] ); ?> /> <?php esc_html_e( 'Enable mailboxes for this site', 'mailkite-smtp' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="mk-mb-domain"><?php esc_html_e( 'Mailbox domain', 'mailkite-smtp' ); ?></label></th>
					<td>
						<?php if ( is_array( $domains ) && $domains ) : ?>
							<select name="mailbox_domain" id="mk-mb-domain">
								<option value=""><?php esc_html_e( '— choose —', 'mailkite-smtp' ); ?></option>
								<?php foreach ( $domains as $d ) : ?>
									<option value="<?php echo esc_attr( $d['domain'] ); ?>" <?php selected( $s['mailbox_domain'], $d['domain'] ); ?>><?php echo esc_html( $d['domain'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Addresses are created on this domain. Inbound mail for it must reach MailKite (its MX records verified).', 'mailkite-smtp' ); ?></p>
						<?php else : ?>
							<input type="text" name="mailbox_domain" id="mk-mb-domain" class="regular-text" value="<?php echo esc_attr( (string) $s['mailbox_domain'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Could not list your domains right now — type the domain name.', 'mailkite-smtp' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Assignment', 'mailkite-smtp' ); ?></th>
					<td>
						<label><input type="checkbox" name="mailbox_auto_assign" <?php checked( (bool) $s['mailbox_auto_assign'] ); ?> />
							<?php esc_html_e( 'Give every allowed user {username}@domain automatically', 'mailkite-smtp' ); ?></label><br/>
						<label><input type="checkbox" name="mailbox_self_register" <?php checked( (bool) $s['mailbox_self_register'] ); ?> />
							<?php esc_html_e( 'Let users choose their own address', 'mailkite-smtp' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Roles allowed', 'mailkite-smtp' ); ?></th>
					<td>
						<?php foreach ( $roles as $slug => $label ) : ?>
							<label style="margin-right:1em"><input type="checkbox" name="mailbox_roles[]" value="<?php echo esc_attr( $slug ); ?>"
								<?php checked( in_array( $slug, (array) $s['mailbox_roles'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mk-mb-reserved"><?php esc_html_e( 'Reserved addresses', 'mailkite-smtp' ); ?></label></th>
					<td>
						<textarea name="mailbox_reserved" id="mk-mb-reserved" class="large-text code" rows="3"><?php echo esc_textarea( (string) $s['mailbox_reserved'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Local parts nobody may claim (comma or space separated). Role addresses like postmaster and billing belong here.', 'mailkite-smtp' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mk-mb-limit"><?php esc_html_e( 'Send limit', 'mailkite-smtp' ); ?></label></th>
					<td>
						<input type="number" min="0" id="mk-mb-limit" name="mailbox_send_limit" value="<?php echo esc_attr( (string) $s['mailbox_send_limit'] ); ?>" style="width:6em" />
						<?php esc_html_e( 'messages per user per day (0 = unlimited)', 'mailkite-smtp' ); ?>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Mailbox Settings', 'mailkite-smtp' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Who has a mailbox', 'mailkite-smtp' ); ?></h2>
		<?php $holders = Manager::all_holders(); ?>
		<table class="widefat striped" style="max-width:760px">
			<thead><tr>
				<th><?php esc_html_e( 'User', 'mailkite-smtp' ); ?></th>
				<th><?php esc_html_e( 'Address', 'mailkite-smtp' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $holders ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'Nobody yet. Users claim an address from their profile screen.', 'mailkite-smtp' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $holders as $h ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( get_edit_user_link( $h['user_id'] ) ); ?>"><?php echo esc_html( $h['login'] ); ?></a></td>
					<td><code><?php echo esc_html( $h['address'] ); ?></code></td>
					<td style="text-align:right">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="mailkite_smtp_mailbox_release" />
							<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $h['user_id'] ); ?>" />
							<?php wp_nonce_field( 'mailkite_smtp_mailbox_release' ); ?>
							<button type="submit" class="button button-small"><?php esc_html_e( 'Revoke', 'mailkite-smtp' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Profile screen section: the user's address, IMAP settings and API bearer.
	 *
	 * @param WP_User $user The profile being shown.
	 */
	public function render_profile( WP_User $user ): void {
		if ( ! Manager::enabled() ) {
			return;
		}
		$is_self = get_current_user_id() === $user->ID;
		if ( ! $is_self && ! current_user_can( 'edit_users' ) ) {
			return;
		}
		$address = Manager::address( $user->ID );
		?>
		<h2><?php esc_html_e( 'MailKite email address', 'mailkite-smtp' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php if ( '' === $address ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Address', 'mailkite-smtp' ); ?></th>
					<td>
						<?php if ( ! Manager::role_allowed( $user ) ) : ?>
							<em><?php esc_html_e( 'This role cannot have a mailbox on this site.', 'mailkite-smtp' ); ?></em>
						<?php elseif ( Options::get( 'mailbox_self_register' ) || current_user_can( 'edit_users' ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
								<input type="hidden" name="action" value="mailkite_smtp_mailbox_claim" />
								<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user->ID ); ?>" />
								<?php wp_nonce_field( 'mailkite_smtp_mailbox_claim' ); ?>
								<input type="text" name="local" value="<?php echo esc_attr( Manager::normalize_local( $user->user_login ) ); ?>" class="regular-text" style="max-width:12em" />
								<span>@<?php echo esc_html( Manager::domain() ); ?></span>
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Create mailbox', 'mailkite-smtp' ); ?></button>
							</form>
						<?php else : ?>
							<em><?php esc_html_e( 'No mailbox yet — an administrator can create one for you.', 'mailkite-smtp' ); ?></em>
						<?php endif; ?>
					</td>
				</tr>
			<?php else : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Address', 'mailkite-smtp' ); ?></th>
					<td>
						<code style="user-select:all"><?php echo esc_html( $address ); ?></code>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=mailkite-inbox' ) ); ?>" class="button button-small" style="margin-left:8px"><?php esc_html_e( 'Open inbox', 'mailkite-smtp' ); ?></a>
					</td>
				</tr>
				<?php if ( $is_self ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mail app settings', 'mailkite-smtp' ); ?></th>
						<td>
							<p style="margin-top:0"><?php esc_html_e( 'Add this account to Apple Mail, Thunderbird, or any IMAP client:', 'mailkite-smtp' ); ?></p>
							<table class="widefat striped" style="max-width:560px">
								<tbody>
									<tr><td><?php esc_html_e( 'Incoming (IMAP)', 'mailkite-smtp' ); ?></td><td><code>imap.mailkite.dev</code> · <?php esc_html_e( 'port', 'mailkite-smtp' ); ?> 993 · SSL/TLS</td></tr>
									<tr><td><?php esc_html_e( 'Outgoing (SMTP)', 'mailkite-smtp' ); ?></td><td><code>smtp.mailkite.dev</code> · <?php esc_html_e( 'port', 'mailkite-smtp' ); ?> 587 · STARTTLS</td></tr>
									<tr><td><?php esc_html_e( 'Username', 'mailkite-smtp' ); ?></td><td><code><?php echo esc_html( $address ); ?></code></td></tr>
									<tr>
										<td><?php esc_html_e( 'Password', 'mailkite-smtp' ); ?></td>
										<td>
											<code id="mk-mb-secret" data-secret="<?php echo esc_attr( Manager::secret( $user->ID ) ); ?>">••••••••••••</code>
											<button type="button" class="button button-small" id="mk-mb-reveal"><?php esc_html_e( 'Show', 'mailkite-smtp' ); ?></button>
										</td>
									</tr>
								</tbody>
							</table>
							<p class="description"><?php esc_html_e( 'The same password is also an API bearer token for this address — your inbox screen uses it, and so can your own scripts.', 'mailkite-smtp' ); ?></p>
							<script>
							document.getElementById( 'mk-mb-reveal' ).addEventListener( 'click', function () {
								var el = document.getElementById( 'mk-mb-secret' );
								var shown = el.textContent.indexOf( '•' ) === -1;
								el.textContent = shown ? '••••••••••••' : el.dataset.secret;
								this.textContent = shown ? <?php echo wp_json_encode( __( 'Show', 'mailkite-smtp' ) ); ?> : <?php echo wp_json_encode( __( 'Hide', 'mailkite-smtp' ) ); ?>;
							} );
							</script>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Manage', 'mailkite-smtp' ); ?></th>
					<td style="display:flex;gap:8px;flex-wrap:wrap">
						<?php if ( $is_self ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
								<input type="hidden" name="action" value="mailkite_smtp_mailbox_regenerate" />
								<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user->ID ); ?>" />
								<?php wp_nonce_field( 'mailkite_smtp_mailbox_regenerate' ); ?>
								<button type="submit" class="button"><?php esc_html_e( 'Regenerate password', 'mailkite-smtp' ); ?></button>
							</form>
						<?php endif; ?>
						<?php if ( current_user_can( 'edit_users' ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
								<input type="hidden" name="action" value="mailkite_smtp_mailbox_release" />
								<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user->ID ); ?>" />
								<?php wp_nonce_field( 'mailkite_smtp_mailbox_release' ); ?>
								<button type="submit" class="button"><?php esc_html_e( 'Delete mailbox', 'mailkite-smtp' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Claim an address (admin-post): self-service, or an admin acting for a user.
	 */
	public function handle_claim(): void {
		$user_id = $this->guard( 'mailkite_smtp_mailbox_claim' );
		$local   = isset( $_POST['local'] ) ? sanitize_text_field( wp_unslash( $_POST['local'] ) ) : '';

		if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mailkite-smtp' ) );
		}
		if ( get_current_user_id() === $user_id && ! Options::get( 'mailbox_self_register' ) && ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'Choosing your own address is disabled on this site.', 'mailkite-smtp' ) );
		}

		$result = Manager::claim( $user_id, $local );
		$this->back( $user_id, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * Release an address (admin-post): the holder or an admin.
	 */
	public function handle_release(): void {
		$user_id = $this->guard( 'mailkite_smtp_mailbox_release' );
		if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mailkite-smtp' ) );
		}
		Manager::release( $user_id );
		$this->back( $user_id, '' );
	}

	/**
	 * Mint a fresh password for your own mailbox (admin-post).
	 */
	public function handle_regenerate(): void {
		$user_id = $this->guard( 'mailkite_smtp_mailbox_regenerate' );
		if ( get_current_user_id() !== $user_id ) {
			wp_die( esc_html__( 'You can only regenerate your own password.', 'mailkite-smtp' ) );
		}
		$result = Manager::regenerate( $user_id );
		$this->back( $user_id, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * Shared nonce/login guard; returns the target user id.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( string $action ): int {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Permission denied.', 'mailkite-smtp' ) );
		}
		check_admin_referer( $action );

		return isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : get_current_user_id();
	}

	/**
	 * Back to wherever the action came from, with an optional error.
	 *
	 * @param int    $user_id Target user.
	 * @param string $error   Message, or '' on success.
	 * @return never
	 */
	private function back( int $user_id, string $error ): void {
		$referer = wp_get_referer();
		$url     = $referer ? $referer : get_edit_user_link( $user_id );
		if ( '' !== $error ) {
			$url = add_query_arg( 'mailkite_error', rawurlencode( $error ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
