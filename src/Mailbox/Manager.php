<?php
/**
 * User mailboxes: claiming addresses and minting the per-user app password.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Mailbox;

use MailKite\Smtp\Crypto;
use MailKite\Smtp\Options;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * One WordPress user ↔ one real email address on the site's MailKite domain.
 *
 * Claiming mints a MailKite **app password** scoped to that single local part with
 * both protocols (`imap` + `api`), so the same secret is the user's IMAP password in
 * Apple Mail/Thunderbird AND the bearer their in-WordPress inbox reads with. The site's
 * account key is used only to mint and revoke — never to read someone's mail.
 *
 * State lives in usermeta so it survives independently of the plugin's options blob.
 */
final class Manager {

	private const META_ADDRESS = 'mailkite_smtp_address';
	private const META_PW_ID   = 'mailkite_smtp_app_password_id';
	private const META_SECRET  = 'mailkite_smtp_app_password';
	private const META_SENT    = 'mailkite_smtp_sent_today'; // [ 'day' => 'Y-m-d', 'n' => int ].

	/**
	 * Whether the feature is switched on and pointed at a domain.
	 */
	public static function enabled(): bool {
		return (bool) Options::get( 'mailboxes_enabled' ) && '' !== self::domain() && '' !== (string) Options::get( 'api_key' );
	}

	/**
	 * The domain mailboxes are created on.
	 */
	public static function domain(): string {
		return strtolower( trim( (string) Options::get( 'mailbox_domain' ) ) );
	}

	/**
	 * Local parts nobody may claim: the admin list plus anything already routed.
	 *
	 * @return string[]
	 */
	public static function reserved(): array {
		$raw  = (string) Options::get( 'mailbox_reserved' );
		$list = preg_split( '/[\s,]+/', strtolower( $raw ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];

		/**
		 * Filters the reserved local parts users may never claim.
		 *
		 * @param string[] $list Reserved local parts.
		 */
		return array_unique( apply_filters( 'mailkite_smtp_reserved_addresses', $list ) );
	}

	/**
	 * Whether this user's role may hold a mailbox.
	 *
	 * @param WP_User $user The user.
	 */
	public static function role_allowed( WP_User $user ): bool {
		$allowed = (array) Options::get( 'mailbox_roles' );

		return (bool) array_intersect( $allowed, (array) $user->roles );
	}

	/**
	 * Normalize a requested local part: lowercase, safe characters only.
	 *
	 * @param string $raw User input (or a username).
	 */
	public static function normalize_local( string $raw ): string {
		$local = strtolower( remove_accents( $raw ) );
		$local = preg_replace( '/[^a-z0-9._-]+/', '-', $local ) ?? '';
		$local = trim( $local, '.-_' );

		return substr( $local, 0, 64 );
	}

	/**
	 * The address this user holds, or '' when they hold none.
	 *
	 * @param int $user_id User id.
	 */
	public static function address( int $user_id ): string {
		return (string) get_user_meta( $user_id, self::META_ADDRESS, true );
	}

	/**
	 * The user's app-password secret (decrypted), or '' when absent.
	 *
	 * @param int $user_id User id.
	 */
	public static function secret( int $user_id ): string {
		return Crypto::decrypt( (string) get_user_meta( $user_id, self::META_SECRET, true ) );
	}

	/**
	 * Is this local part already taken by another WordPress user on this site?
	 *
	 * @param string $local   Normalized local part.
	 * @param int    $exclude User id to ignore (the claimant).
	 */
	public static function taken( string $local, int $exclude = 0 ): bool {
		$address = $local . '@' . self::domain();
		$users   = get_users(
			[
				'meta_key'   => self::META_ADDRESS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed lookup on a small key space.
				'meta_value' => $address,           // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ID',
				'number'     => 2,
			]
		);

		foreach ( $users as $id ) {
			if ( (int) $id !== $exclude ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Claim an address for a user and mint their app password.
	 *
	 * @param int    $user_id User id.
	 * @param string $raw     Requested local part (defaults to the username).
	 * @return string|WP_Error The full address on success.
	 */
	public static function claim( int $user_id, string $raw = '' ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'no_user', __( 'Unknown user.', 'mailkite-smtp' ) );
		}
		if ( ! self::enabled() ) {
			return new WP_Error( 'disabled', __( 'Mailboxes are not enabled on this site.', 'mailkite-smtp' ) );
		}
		if ( ! self::role_allowed( $user ) ) {
			return new WP_Error( 'role', __( 'Your role cannot have a mailbox on this site.', 'mailkite-smtp' ) );
		}
		if ( '' !== self::address( $user_id ) ) {
			return new WP_Error( 'exists', __( 'You already have an address.', 'mailkite-smtp' ) );
		}

		$local = self::normalize_local( '' !== $raw ? $raw : $user->user_login );
		if ( '' === $local ) {
			return new WP_Error( 'invalid', __( 'Choose an address using letters, numbers, dots, dashes or underscores.', 'mailkite-smtp' ) );
		}
		if ( in_array( $local, self::reserved(), true ) ) {
			return new WP_Error( 'reserved', __( 'That address is reserved.', 'mailkite-smtp' ) );
		}
		if ( self::taken( $local, $user_id ) ) {
			return new WP_Error( 'taken', __( 'That address is already taken.', 'mailkite-smtp' ) );
		}

		$created = Client::create_app_password( self::domain(), $local, sprintf( 'WordPress: %s', $user->user_login ) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$address = $local . '@' . self::domain();
		update_user_meta( $user_id, self::META_ADDRESS, $address );
		update_user_meta( $user_id, self::META_PW_ID, (string) $created['id'] );
		update_user_meta( $user_id, self::META_SECRET, Crypto::encrypt( (string) $created['secret'] ) );

		/**
		 * A WordPress user just claimed a MailKite address.
		 *
		 * @param int    $user_id The user.
		 * @param string $address The full address.
		 */
		do_action( 'mailkite_smtp_mailbox_claimed', $user_id, $address );

		return $address;
	}

	/**
	 * Release a user's address and revoke their credential.
	 *
	 * @param int $user_id User id.
	 */
	public static function release( int $user_id ): void {
		$pw_id = (string) get_user_meta( $user_id, self::META_PW_ID, true );
		if ( '' !== $pw_id ) {
			Client::delete_app_password( $pw_id );
		}
		$address = self::address( $user_id );
		delete_user_meta( $user_id, self::META_ADDRESS );
		delete_user_meta( $user_id, self::META_PW_ID );
		delete_user_meta( $user_id, self::META_SECRET );

		/**
		 * A user's mailbox was released (revoked, or the user was deleted).
		 *
		 * @param int    $user_id The user.
		 * @param string $address The address they held.
		 */
		do_action( 'mailkite_smtp_mailbox_released', $user_id, $address );
	}

	/**
	 * Mint a fresh secret for the user's existing address (old one dies immediately).
	 *
	 * @param int $user_id User id.
	 * @return string|WP_Error The new secret.
	 */
	public static function regenerate( int $user_id ) {
		$address = self::address( $user_id );
		if ( '' === $address ) {
			return new WP_Error( 'none', __( 'No address to regenerate.', 'mailkite-smtp' ) );
		}
		$user  = get_user_by( 'id', $user_id );
		$local = substr( $address, 0, (int) strpos( $address, '@' ) );

		$created = Client::create_app_password( self::domain(), $local, sprintf( 'WordPress: %s', $user ? $user->user_login : $user_id ) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$old = (string) get_user_meta( $user_id, self::META_PW_ID, true );
		if ( '' !== $old ) {
			Client::delete_app_password( $old );
		}
		update_user_meta( $user_id, self::META_PW_ID, (string) $created['id'] );
		update_user_meta( $user_id, self::META_SECRET, Crypto::encrypt( (string) $created['secret'] ) );

		return (string) $created['secret'];
	}

	/**
	 * Auto-assign {username}@domain on first opportunity, when the admin enabled it.
	 *
	 * @param int $user_id User id.
	 */
	public static function maybe_auto_assign( int $user_id ): void {
		if ( ! Options::get( 'mailbox_auto_assign' ) || ! self::enabled() || '' !== self::address( $user_id ) ) {
			return;
		}
		$user = get_user_by( 'id', $user_id );
		if ( $user instanceof WP_User && self::role_allowed( $user ) ) {
			self::claim( $user_id ); // Failures are silent by design — never block a page load.
		}
	}

	/**
	 * Count one sent message against the per-user daily cap.
	 *
	 * @param int $user_id User id.
	 * @return bool False when the cap is already reached (caller must not send).
	 */
	public static function consume_send_quota( int $user_id ): bool {
		$limit = (int) Options::get( 'mailbox_send_limit' );
		if ( $limit <= 0 ) {
			return true; // 0 = unlimited.
		}
		$today = gmdate( 'Y-m-d' );
		$state = (array) get_user_meta( $user_id, self::META_SENT, true );
		$count = ( ( $state['day'] ?? '' ) === $today ) ? (int) ( $state['n'] ?? 0 ) : 0;
		if ( $count >= $limit ) {
			return false;
		}
		update_user_meta(
			$user_id,
			self::META_SENT,
			[
				'day' => $today,
				'n'   => $count + 1,
			]
		);

		return true;
	}

	/**
	 * Every user holding an address on this site (admin overview).
	 *
	 * @return array<int, array{user_id: int, login: string, address: string}>
	 */
	public static function all_holders(): array {
		$users = get_users(
			[
				'meta_key' => self::META_ADDRESS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin-only overview.
				'fields'   => [ 'ID', 'user_login' ],
				'number'   => 200,
			]
		);
		$rows = [];
		foreach ( $users as $user ) {
			$rows[] = [
				'user_id' => (int) $user->ID,
				'login'   => (string) $user->user_login,
				'address' => self::address( (int) $user->ID ),
			];
		}

		return $rows;
	}
}
