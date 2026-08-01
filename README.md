# MailKite SMTP – Email Delivery & Logs

Fix WordPress email delivery. Send via [MailKite](https://mailkite.dev) (free tier,
2-minute setup, inbound email included) or any SMTP server — with a free email log,
resend, and privacy-safe redaction of authentication emails.

**Free forever. No Pro tier. GPLv2. PRs welcome.**

## Why another SMTP plugin?

- Every feature is free — including email logs, resend, and (soon) failover, which
  other plugins paywall.
- **Redaction by default**: password-reset and verification email bodies are never
  stored. A leaked database or log page can't leak reset links.
- Secrets (API key, SMTP password) are AES-256-GCM encrypted at rest.
- Clean interception: uses the `pre_wp_mail` filter (WP 5.7+) — no `wp_mail()`
  redefinition, no PHPMailer subclassing.
- Coming next: guided in-admin MailKite onboarding (account → domain → DNS → send
  without leaving wp-admin), BYO providers, failover, inbound email routes.

## Development

Requires PHP 8.1+, WordPress 6.2+.

```sh
# throwaway WP with the plugin mounted (no Docker needed)
npx @wp-playground/cli@latest server --auto-mount
```

See [PLAN.md](PLAN.md) for the roadmap.
