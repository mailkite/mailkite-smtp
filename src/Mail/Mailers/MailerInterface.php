<?php
/**
 * Mailer contract.
 *
 * @package MailKite\Smtp
 */

namespace MailKite\Smtp\Mail\Mailers;

use MailKite\Smtp\Mail\Email;
use WP_Error;

defined( 'ABSPATH' ) || exit;

interface MailerInterface {

	/**
	 * Deliver the email.
	 *
	 * @param Email $email Normalized email.
	 * @return true|WP_Error True on acceptance by the provider, WP_Error on failure.
	 */
	public function send( Email $email );
}
