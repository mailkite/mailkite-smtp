=== MailKite SMTP – Multi-Provider SMTP with Failover, Email Logs & Inbound Email ===
Contributors: bucabay
Tags: smtp, email, email log, deliverability, wp mail
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reliable WordPress email: MailKite, SendGrid, Brevo, Mailgun or any SMTP — free logs, automatic failover, instant alerts, inbound email.

== Description ==

WordPress sends email through PHP `mail()` by default — unauthenticated, often blocked, and invisible when it fails. MailKite SMTP replaces it with reliable delivery, a free email log, and features every other SMTP plugin charges for.

**Free forever. No Pro tier. No locked features. Open source.**

= Send through any provider =

* **MailKite** (recommended): free tier, ~2-minute setup, open/click tracking, and the only option with **inbound email**. Sign up at [mailkite.dev](https://mailkite.dev).
* **SendGrid, Brevo, Mailgun** — bring your own API key.
* **Any SMTP server** — host, port, TLS/SSL, credentials.
* **Routing rules**: send WooCommerce receipts via one provider and newsletters via another, by subject or recipient.

= Never lose an email =

* **Automatic failover** — if the API provider fails, the email retries through your SMTP server or PHP mail. Other plugins sell this as a Pro feature; here it is free.
* **Instant failure alerts** — email, Slack, Discord, or any webhook, the moment a send fails (rate-limited, no alert storms).
* **Email log with resend and CSV export**, configurable retention, and one-click resend of failures.
* **Weekly summary** — sent/failed counts, top errors, and DNS status in your inbox.

= Private by design =

* **Auth-email redaction (on by default)**: bodies of password-reset and verification emails are never stored, so a leaked database or log page can never leak reset links.
* Stored credentials are **encrypted at rest** (AES-256-GCM keyed from your wp-config salts).
* No telemetry. No external calls until you connect a provider.

= Receive email in WordPress (unique) =

No other SMTP plugin can receive. Turn inbound on (one click — the webhook and its signature verification are installed on your MailKite domain automatically) and you get three things:

1. **Turn an email into a WordPress action.** Every message fires `do_action( 'mailkite_smtp_inbound', $message, $payload )`, so your plugins and theme can act on it: open a support ticket, attach a customer's reply to their WooCommerce order, post to a forum, hand it to an AI agent.
2. **Nothing vanishes.** Your site sends from a no-reply address and people reply anyway; bounces and out-of-office notices come back too. Without inbound those are lost silently — with it they land in the Email Log next to the message they answer, and you can **reply from WordPress**, in-thread, as your own domain.
3. **Or just forward it.** No code: send a copy of everything to an address you already read.

= For professionals =

* **One-click migration** from WP Mail SMTP, Easy WP SMTP, FluentSMTP, and Post SMTP.
* **WP-CLI**: `wp mailkite status|test|log|purge`.
* **Site Health** integration and a **Domain Health** tab (SPF/DMARC checks with weekly drift alerts).
* Settings export/import (secrets excluded) and `wp-config.php` constants (`MAILKITE_API_KEY`, `MAILKITE_DEFAULT_MAILER`) for automated provisioning.
* Works with WooCommerce, Contact Form 7, WPForms, and anything using `wp_mail()`.

= External services =

Emails are delivered through the provider you select and connect: MailKite (api.mailkite.dev — [terms](https://mailkite.dev/terms), [privacy](https://mailkite.dev/privacy)), SendGrid, Brevo, or Mailgun, each using your own account and API key. No data is sent to any of them until you configure it. Optional failure alerts POST to the Slack/Discord/webhook URL you provide.

== Frequently Asked Questions ==

= Is this plugin really free? =

Yes. Every feature is free and always will be — including logs, failover, and alerts. MailKite (the email service) has free and paid plans, but the plugin works fully with your own SMTP server or SendGrid/Brevo/Mailgun keys and never requires a MailKite account.

= Why are some log entries missing a body? =

Password-reset, login, and verification emails are stored without their body by default, so a compromised database can never leak reset links. Disable in Settings if you accept the risk.

= Does it work with WooCommerce / Contact Form 7 / WPForms? =

Yes — the plugin intercepts `wp_mail()`, which they all use.

= How do I receive email into WordPress? =

Open the Inbound tab, pick your domain, and press "Turn on inbound" — the plugin installs the webhook and its signature verification on your MailKite account for you. Nothing to copy, paste, or configure by hand. Then read arriving mail in the Email Log, handle `mailkite_smtp_inbound` in your code, or set a forwarding address.

= Can I reply to email from WordPress? =

Yes. Received messages get a Reply action in the Email Log. The reply goes out as the address the message was delivered to — your own verified domain, never a spoofed sender — and it threads correctly, so the conversation stays together in the recipient's mail client. Both halves show under Conversation on the message.

== Screenshots ==

1. Settings — choose your mailer; only the relevant section is shown.
2. Email log with statuses, failure reasons, redaction, and resend.
3. Inbound email webhook configuration.
4. Domain Health — SPF/DMARC checks with weekly drift alerts.

== Changelog ==

= 0.4.0 =
* Received mail is now stored in WordPress, so it stays readable after MailKite's retention window ends.
* One inbound webhook serves this plugin and MailKite Mailboxes, writing to one set of tables instead of two.
* Mail belonging to a user's personal mailbox no longer appears in the site-wide Email Log — ownership is enforced in the query, not in the template.
* Retention purges site mail only; personal mailbox mail is never deleted by it.
* A send that falls back to another mailer is labelled as such in the log, instead of reporting plain success.
* Inbound webhooks are registered on the connected MailKite account automatically — no copying URLs by hand.

= 0.3.0 =
* Inbound: reply to received mail from the Email Log, threaded (From is forced to the address it was delivered to).
* Log stores the real sender, conversation id and message id; conversation view groups both sides of an exchange.
* Inbound tab explains what inbound is for, with the developer hook, log link and forwarding in one place.
* User mailboxes moved to their own plugin, **MailKite Mailboxes** — real addresses for WordPress users, an Inbox screen and `[mailkite_inbox]`.

= 0.2.0 =
* SendGrid, Brevo, and Mailgun mailers (bring your own key).
* Automatic failover to SMTP/PHP mail when an API send fails.
* Instant failure alerts: email + Slack/Discord/webhook.
* Routing rules by subject/recipient.
* Inbound email webhook + `mailkite_smtp_inbound` hook + forwarding.
* Domain Health (SPF/DMARC) with weekly drift alerts; weekly summary email.
* Site Health tests, WP-CLI commands, settings export/import.
* Open/click tracking toggles for MailKite sends; large attachments upload by URL.

= 0.1.0 =
* Initial release: MailKite API mailer, generic SMTP mailer, email log with redaction and resend, test emails, force-from, REST API.
