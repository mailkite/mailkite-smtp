# MailKite SMTP — Build Plan & Phased Checklist

> Repo: `~/code/mailkite/mailkite-smtp` (standalone, public GitHub later; GPLv2)
> Product spec: `web-monorepo/docs/projects/wordpress-plugin.md`
> Strategy: `web-monorepo/docs/partnerships/wordpress-own-plugin-vs-gosmtp.md`
>
> Shape: **free, open-source SMTP aggregator** — MailKite as recommended one-click
> provider, BYO providers fully functional, logs free, no Pro tier ever.

## The pattern (cross-referenced 2026-08-01)

Verified against FluentSMTP, WP Mail SMTP, and Post SMTP source:

- **Interception:** FluentSMTP *redefines* `wp_mail()` (conflict-prone: first plugin
  wins); WP Mail SMTP swaps in a `MailCatcher` subclass of PHPMailer. **We use the
  `pre_wp_mail` filter (WP 5.7+)** — no function redefinition, no PHPMailer subclass
  for API mailers; returning non-null short-circuits `wp_mail`, and we fire
  `wp_mail_failed` / `wp_mail_succeeded` so other plugins' handling keeps working.
  Generic-SMTP mode configures PHPMailer via `phpmailer_init` and lets core send.
- **Provider handlers:** FluentSMTP's `BaseHandler` + per-provider `Handler::postSend()`
  POSTing JSON (e.g. Postmark) — same architecture as our `MailerInterface` +
  `MailKiteMailer`. Ours maps 1:1 onto `POST /v1/send`
  (`from`,`to`,`subject`,`html`,`text`,`cc`,`bcc`,`replyTo`,`headers`,`attachments[{filename,content|url,contentType}]`).
- **Free/Pro split intel:** WP Mail SMTP paywalls logs/failover/alerts — its #1 review
  complaint. All free here, permanently (also a wp.org rule: trialware is banned).
- **Security lesson:** Post SMTP CVE-2025-11833 (9.8, exploited in the wild): its log
  table exposed password-reset emails. → our logger **redacts auth-email bodies by
  default** and gates log access behind `manage_options`.

## Environment

- Local dev: PHP 8.5 + Node 25, no Docker → **WordPress Playground CLI / wp-now**
  (`npx @wp-playground/cli server --auto-mount`) for a throwaway WP.
- Floors: `Requires PHP: 8.1`, `Requires at least: 6.2` (pre_wp_mail needs 5.7; 6.2
  gives us modern REST + components).

---

## Phased checklist

### Phase 0 — Foundations
- [x] Repo init (`main`), GPLv2 LICENSE, .gitignore/.distignore/.editorconfig
- [x] Plugin skeleton: header, PSR-4-style autoloader (no runtime composer deps)
- [x] readme.txt (wp.org format) + README.md (GitHub)
- [ ] QA tooling: PHPCS + WPCS 3.x, PHPStan lvl 6, Plugin Check (PCP) locally
- [ ] GitHub repo public + CI (lint, stan, PCP on PR)

### Phase 1 — Core send path (P0)
- [x] `Options` wrapper (sanitized, defaults, `MAILKITE_*` wp-config constant overrides)
- [x] `Email` DTO: parse wp_mail args (to/cc/bcc/reply-to/content-type/from headers,
      string-or-array tolerance, attachments)
- [x] `pre_wp_mail` interceptor → mailer routing (`mailkite` | `smtp` | `php`)
- [x] `MailKiteMailer`: POST /v1/send, Bearer scoped key, WP_Error mapping,
      attachments ≤ 10 MB inline base64 (url/uploadAttachment later)
- [x] Generic SMTP mailer via `phpmailer_init` (host/port/encryption/auth)
- [x] Force from-address / from-name (`wp_mail_from*` filters)
- [x] `wp_mail_failed` / `wp_mail_succeeded` parity on the short-circuit path
- [x] Test-email sender (REST + admin form)
- [ ] Attachment `url` mode via `POST /v1/attachments` for large files

### Phase 2 — Email log (P0)
- [x] Custom table (dbDelta on activation), status/mailer/error columns
- [x] Redaction default-on: auth-pattern subjects store headers only, no body
- [x] Log admin list: search, status filter, detail, delete; capability-gated
- [x] Resend from log
- [ ] Retention setting + daily cron purge
- [ ] CSV export

### Phase 3 — Admin UI & wizard (P0 — the conversion surface)
- [x] Minimal settings page (PHP form, nonce + caps) — functional MVP
- [x] REST: `mailkite-smtp/v1` settings/test/logs endpoints (`permission_callback`)
- [ ] `@wordpress/scripts` build; React page with `@wordpress/components`
- [ ] Full-screen first-run wizard: provider picker (MailKite recommended card + BYO
      grid) → connect → domain/DNS → test → done
- [ ] **In-admin MailKite signup**: `POST /api/auth/signup` (+`ref` attribution) →
      verify → `POST /api/domains` → DNS records UI → `/api/domains/{id}/verify` poll
      → scoped key via `/api/keys/scoped` → test send  ⚠ needs `/api/keys*` +
      `/api/billing/usage` added to `sdks/spec/api.json` first
- [ ] Dashboard: connection health, 24h sent/failed, quota bar (`/api/billing/usage`),
      SPF/DKIM/DMARC card
- [ ] One-click settings migration importer (WP Mail SMTP, FluentSMTP, Post SMTP,
      Easy WP SMTP)

### Phase 4 — wp.org launch (M1/M2)
- [ ] Plugin Check clean pass; readme short description ≤ 150 chars; screenshots
      (wizard, logs, dashboard); banner/icon assets
- [ ] Submit to wordpress.org (slug fixed at submission — decide final name first);
      review queue 2–6 wks
- [ ] `10up/action-wordpress-plugin-deploy` tag→SVN pipeline + asset action
- [ ] Launch content cluster live same week (mailkite-seo skill; per-plugin fix guides)

### Phase 5 — P1 features
- [ ] BYO mailers: Gmail/Workspace OAuth, Brevo, SendGrid, Mailgun, SES
- [ ] Backup connection + automatic failover (free — everyone else paywalls it)
- [ ] **Retitle once failover + inbound ship** (claims must match shipped features):
      "MailKite SMTP – Multi-Provider SMTP with Failover, Email Logs & Inbound Email".
      Until then the name stays "MailKite SMTP – SMTP and Email Log Plugin for Any
      SMTP Provider" (decided 2026-08-01; slug `mailkite-smtp` is permanent).
- [ ] Instant failure alerts: email/Slack/Discord/Telegram (instant by default)
- [ ] Inbound tab: routes UI + `do_action('mailkite_inbound', $message)` + docs
- [ ] Domain health weekly re-check + drift alert
- [ ] WP-CLI: `wp mailkite test|log list|connect` (category gap)
- [ ] Weekly summary email

### Phase 6 — P2 / scale & host deals
- [ ] Smart routing rules (by source plugin / subject / recipient)
- [ ] Open/click tracking toggles (MailKite connections)
- [ ] Bounce/complaint suppression view (webhook-fed) — category first
- [ ] Site Health (`site_status_tests`) deliverability checks
- [ ] Multisite network settings + `MAILKITE_API_KEY`/`MAILKITE_DEFAULT_MAILER`
      constants (zero-UI config → host plugin-set preinstalls)
- [ ] Import/export settings JSON

Checked boxes = built in the initial scaffold (this session), pending live-WP smoke test.
