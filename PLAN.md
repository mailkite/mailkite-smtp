# MailKite SMTP — Build Plan & Phased Checklist

> Repo: `~/code/mailkite/mailkite-smtp` · public: github.com/mailkite/mailkite-smtp (GPLv2)
> Product spec: `web-monorepo/docs/projects/wordpress-plugin.md`
> Strategy: `web-monorepo/docs/partnerships/wordpress-own-plugin-vs-gosmtp.md`
>
> Shape: **free, open-source SMTP aggregator** — MailKite as recommended one-click
> provider, BYO providers fully functional, everything free, no Pro tier ever.
> Name (v0.2.0): **"MailKite SMTP – Multi-Provider SMTP with Failover, Email Logs &
> Inbound Email"** — slug `mailkite-smtp` (permanent at wp.org submission).

## Status: feature-complete for launch (2026-08-01) — v0.2.0

All phases built and verified on WordPress Playground except the items in
**§Deferred** (blocked on human input or external accounts) and the React wizard
(§Decision D3). QA: PHPCS (WPCS 3) clean, PHPStan level 6 clean, `php -l` clean.

### Shipped — send path
- [x] `pre_wp_mail` interception; `wp_mail_failed`/`wp_mail_succeeded` parity
- [x] Mailers: **MailKite API** (tracking toggles, ≤2 MB attachments inline, larger
      auto-uploaded via `/v1/attachments` and attached by URL), **SendGrid**, **Brevo**,
      **Mailgun** (US/EU, multipart incl. attachments), **generic SMTP**, PHP mail
- [x] **Automatic failover**: any API-mailer failure retries via SMTP/PHP mail; the log
      row records the full chain ("Brevo: no API key — fell back… Then: …")
- [x] **Routing rules**: subject/recipient contains → mailer (first match wins), with
      repeater UI; rules can also force smtp/php transport
- [x] Force from-address/name; one-click **migration importer** (WP Mail SMTP,
      Easy WP SMTP modern+legacy, FluentSMTP, Post SMTP)

### Shipped — observe & protect
- [x] Email log: statuses, failure reasons, resend, search-free MVP list, CSV export,
      retention setting + daily purge cron, **auth-email redaction default-on**
      (Post SMTP CVE-2025-11833 lesson), secrets **AES-256-GCM encrypted at rest**
- [x] **Instant failure alerts**: email (rate-limited 15 min/error, recursion-guarded)
      + Slack/Discord/generic **webhook**
- [x] **Domain Health tab**: SPF + DMARC checks, weekly re-check cron with
      **drift alerts**; weekly **summary email** (counts, top errors, DNS status)
- [x] **Site Health** tests (mailer configured; recent failures)

### Shipped — inbound (the moat)
- [x] Token-authenticated webhook endpoint (`/wp-json/mailkite-smtp/v1/inbound`),
      constant-time compare, secret rotation UI
- [x] `do_action( 'mailkite_smtp_inbound', $message, $payload )` + docs in-UI
- [x] Optional forward-a-copy to any address; inbound rows in the email log

### Shipped — operations & compliance
- [x] WP-CLI `wp mailkite status|test|log|purge`
- [x] Settings export/import JSON (secrets excluded); `MAILKITE_API_KEY` /
      `MAILKITE_DEFAULT_MAILER` wp-config constants (host plugin-set provisioning)
- [x] Activation redirect (bulk-safe); deactivation/uninstall clean up crons, options,
      table
- [x] wp.org assets generated: `assets-wporg/` icon 128/256 + banners 772/1544 (GD,
      kite mark on slate)
- [x] readme.txt per current spec (Tested up to 7.0), external-services disclosure,
      FAQ, screenshots list
- [x] QA: phpcs.xml (WPCS 3, justified exclusions), phpstan.neon (lvl 6, WP + WP-CLI
      stubs), composer scripts, GitHub Actions CI (lint matrix 8.1/8.3, PHPCS,
      PHPStan, official Plugin Check action)

## Decisions taken (autonomous, revisit any time)
- **D1** BYO API mailers = SendGrid, Brevo, Mailgun. Gmail/Workspace OAuth needs a
  Google Cloud OAuth app (human); SES-API needs SigV4 (SES works today via SMTP creds).
- **D2** Inbound auth = per-site random token in URL, constant-time compare, rotation
  button. Upgrade path: MailKite webhook HMAC signatures once the scheme is documented
  in the SDK spec (`getWebhookSecret` exists).
- **D3** Admin UI stays native PHP (wizard-style sections + toggling). React
  `@wordpress/components` port remains a polish milestone, not a launch gate — every
  flow it would deliver already works.
- **D4** Retitled at 0.2.0 (failover + inbound + multi-provider all shipped, so the
  name's claims are true).
- **D5** Suppression-list view deferred: needs a suppressions endpoint in the MailKite
  API (not in `sdks/spec/api.json` yet).

## Deferred — needs Gabe / external accounts
- [ ] **wp.org submission** (locks slug; needs `bucabay` wp.org account): upload zip at
      wordpress.org/plugins/developers/add/ after a real-mail smoke test
- [x] **Real-transport smoke test**: live MailKite send confirmed working
      (Gabe, 2026-08-02). Still nice-to-have: one BYO-provider send and
      `wp mailkite` on a real wp-cli install — not launch gates
- [ ] **Mint the affiliate ref code** (8-char, dashboard affiliate page) → replace UTM
      link in `src/Admin/Menu.php` and use in future wizard signup
- [ ] **SDK spec additions** (api-ssot project): `/api/keys*`, `/api/billing/usage`,
      webhook-signature scheme, suppressions endpoint (unblocks in-admin signup, quota
      meter, D2, D5)
- [ ] Gmail/Workspace OAuth app credentials (D1); screenshots from a real styled site
      if the generated ones aren't wanted
- [ ] Decide: keep `.blueprint-dev.json` local-only or document in README (currently
      committed — harmless)

## Later milestones
- [ ] React wizard + dashboard (D3) with in-admin signup (after SDK spec items)
- [ ] Slack/Discord/Telegram *native* alert channels beyond webhook; alert digests
- [ ] Suppression sync UI (D5); multisite network-admin settings screen
- [ ] Launch content cluster (mailkite-seo skill) the week of wp.org approval;
      host plugin-set pitches (strategy doc §9) once listed
