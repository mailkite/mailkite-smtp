=== MailKite SMTP – Email Delivery & Logs ===
Contributors: bucabay
Tags: smtp, email, wp mail, email log, deliverability
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fix WordPress email delivery. Send via MailKite or your own SMTP server, with free email logs, resend, and privacy-safe redaction.

== Description ==

WordPress sends email through PHP `mail()` by default — unauthenticated, often blocked, and invisible when it fails. MailKite SMTP replaces it with reliable delivery and a free email log.

**Free forever. No Pro tier. No locked features.**

* **MailKite** (recommended): free tier, set up in about 2 minutes, inbound email included. Sign up at [mailkite.dev](https://mailkite.dev).
* **Any SMTP server**: bring your own host and credentials.
* **Email log with resend** — free, including failure reasons from the mail server.
* **Privacy-safe logging**: bodies of password-reset and verification emails are not stored (on by default). Stored credentials are encrypted at rest.
* **Force from address/name** to fix plugins that set the wrong sender.
* Works with WooCommerce, Contact Form 7, WPForms, and anything using `wp_mail()`.

= External service =

When you select MailKite as your mailer and connect an API key, emails are delivered via the MailKite API (api.mailkite.dev). No data is sent to MailKite until you connect an account. See the [MailKite terms](https://mailkite.dev/terms) and [privacy policy](https://mailkite.dev/privacy).

== Frequently Asked Questions ==

= Is this plugin really free? =

Yes. Every feature in this plugin is free and always will be. MailKite (the email service) has free and paid plans, but you can also use this plugin with any SMTP server and never touch MailKite.

= Why are some log entries missing a body? =

Password-reset, login, and verification emails are stored without their body by default, so a compromised database or log page can never leak reset links. Disable in Settings if you accept the risk.

== Changelog ==

= 0.1.0 =
* Initial release: MailKite API mailer, generic SMTP mailer, email log with redaction and resend, test emails, force-from, REST API.
