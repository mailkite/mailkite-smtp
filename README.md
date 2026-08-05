# MailKite SMTP – SMTP and Email Log Plugin for Any SMTP Provider

Fix WordPress email delivery. Send via [MailKite](https://mailkite.dev) (free tier,
2-minute setup, inbound email included) or any SMTP server — with a free email log,
automatic failover, resend, inbound email, and privacy-safe redaction of authentication
emails.

**Free forever. No Pro tier. GPLv2. PRs welcome.**

## Install

Not on wp.org yet. Download the zip from the
[latest release](https://github.com/mailkite/mailkite-smtp/releases/latest), then in
WordPress: **Plugins → Add New → Upload Plugin**.

```sh
# or with WP-CLI
wp plugin install https://github.com/mailkite/mailkite-smtp/releases/latest/download/mailkite-smtp.zip --activate
```

Requires WordPress 6.2+ and PHP 8.1+. Setup guide:
[mailkite.dev/docs/integrations/wordpress](https://mailkite.dev/docs/integrations/wordpress).

## Why another SMTP plugin?

- Every feature is free — including the email log, resend, and failover, which other
  plugins paywall.
- **It can receive mail, not just send it.** Inbound arrives over a webhook the plugin
  registers on your MailKite account for you, is stored in WordPress, and can be read and
  replied to in-thread from wp-admin.
- **Failover tells the truth.** If a send falls back to another mailer, the log says so
  rather than reporting a success you did not get.
- **Redaction by default**: password-reset and verification email bodies are never stored.
  A leaked database or log page can't leak reset links.
- Secrets (API key, SMTP password) are AES-256-GCM encrypted at rest.
- Clean interception: the `pre_wp_mail` filter (WP 5.7+) — no `wp_mail()` redefinition, no
  PHPMailer subclassing. Core's own `wp_mail_failed` / `wp_mail_succeeded` still fire, so
  other plugins' error handling keeps working.

Want a real mailbox per WordPress user, with IMAP access and an inbox screen? That's the
companion plugin, [MailKite Mailboxes](https://github.com/mailkite/mailkite-mailboxes).

## Development

Requires PHP 8.1+, WordPress 6.2+.

```sh
composer install
composer run lint     # PHPCS (WordPress Coding Standards)
composer run stan     # PHPStan level 6
bin/build-zip.sh      # -> dist/mailkite-smtp.zip
```

`bin/build-zip.sh` exports only the files that ship and refuses to build when the plugin
header version and the readme `Stable tag` disagree.

**Run Plugin Check against `dist/`, never the repo** — the repo carries `composer.json`,
`vendor/` and dotfiles that Plugin Check reports as blocking errors and that never ship.

For a throwaway site, `npx @wp-playground/cli@latest server --auto-mount` is quickest, but
note **Playground cannot receive inbound webhooks** (it 302s cookie-less requests), so
testing inbound needs a real WordPress and a tunnel. Set `MAILKITE_SMTP_PUBLIC_URL` when
the site is behind one, so the webhook registers the tunnel's URL rather than `localhost`.

See [PLAN.md](PLAN.md) for the roadmap and [INBOX-PLAN.md](INBOX-PLAN.md) for the inbound
and mailbox design.
