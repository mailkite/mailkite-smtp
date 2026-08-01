<?php
/**
 * Sending-domain DNS health: SPF + DMARC presence, weekly drift alerts.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the sending domain's SPF and DMARC records. A weekly cron re-checks
 * and emails an alert when a previously-passing record disappears (drift) —
 * the silent way deliverability dies.
 */
final class Health {

	private const RESULTS_OPTION = 'mailkite_smtp_health';

	/**
	 * The domain we send as: forced from-address domain, else the site host.
	 */
	public static function sending_domain(): string {
		$from = (string) Options::get( 'force_from_email' );
		if ( is_email( $from ) ) {
			return substr( $from, strpos( $from, '@' ) + 1 );
		}
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Run the DNS checks now.
	 *
	 * @return array{domain: string, spf: bool, dmarc: bool, checked_at: string}
	 */
	public static function check(): array {
		$domain = self::sending_domain();

		return [
			'domain'     => $domain,
			'spf'        => self::has_txt( $domain, 'v=spf1' ),
			'dmarc'      => self::has_txt( '_dmarc.' . $domain, 'v=DMARC1' ),
			'checked_at' => gmdate( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * Weekly cron: re-check, alert on drift, store the new baseline.
	 */
	public static function cron_check(): void {
		$previous = get_option( self::RESULTS_OPTION );
		$current  = self::check();
		update_option( self::RESULTS_OPTION, $current, false );

		if ( ! is_array( $previous ) || $previous['domain'] !== $current['domain'] ) {
			return; // First run or domain changed — new baseline, nothing to compare.
		}

		$broken = [];
		foreach ( [
			'spf'   => 'SPF',
			'dmarc' => 'DMARC',
		] as $key => $label ) {
			if ( ! empty( $previous[ $key ] ) && empty( $current[ $key ] ) ) {
				$broken[] = $label;
			}
		}
		if ( ! $broken ) {
			return;
		}

		$to = (string) Options::get( 'alert_email' );
		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}
		wp_mail(
			$to,
			/* translators: %s: sending domain. */
			sprintf( __( '[%s] Email DNS records broke', 'mailkite-smtp' ), $current['domain'] ),
			sprintf(
				/* translators: 1: record names, 2: domain, 3: admin URL. */
				__( "The following DNS records for %2\$s were present last week and are now missing: %1\$s.\n\nYour emails may start landing in spam. Review: %3\$s", 'mailkite-smtp' ),
				implode( ', ', $broken ),
				$current['domain'],
				admin_url( 'admin.php?page=mailkite-smtp&tab=health' )
			)
		);
	}

	/**
	 * Latest stored results (running a live check when none stored).
	 *
	 * @return array{domain: string, spf: bool, dmarc: bool, checked_at: string}
	 */
	public static function latest(): array {
		$stored = get_option( self::RESULTS_OPTION );
		if ( is_array( $stored ) && self::sending_domain() === $stored['domain'] ) {
			return $stored;
		}
		$current = self::check();
		update_option( self::RESULTS_OPTION, $current, false );

		return $current;
	}

	/**
	 * Whether any TXT record at $host contains $needle.
	 *
	 * @param string $host   DNS name to query.
	 * @param string $needle Substring to look for.
	 */
	private static function has_txt( string $host, string $needle ): bool {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return true; // Can't check on this host — do not raise false alarms.
		}
		$records = @dns_get_record( $host, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failures are expected and handled.
		foreach ( (array) $records as $record ) {
			$txt = $record['txt'] ?? implode( '', (array) ( $record['entries'] ?? [] ) );
			if ( false !== stripos( (string) $txt, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
