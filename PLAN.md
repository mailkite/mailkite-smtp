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
- [x] QA tooling configured: PHPCS + WPCS 3.x (`phpcs.xml`), PHPStan lvl 6
      (`phpstan.neon`), composer scripts (`composer lint` / `stan`)
- [x] GitHub repo public (github.com/mailkite/mailkite-smtp) + CI workflow
      (php -l matrix, PHPCS, PHPStan, official Plugin Check action)
- [ ] Fix violations CI surfaces on first runs (PHPCS/PHPStan/PCP)

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
- [x] Retention setting + daily cron purge (unscheduled on deactivation)
- [x] CSV export

### Phase 3 — Admin UI & wizard (P0 — the conversion surface)

> **Scope decision 2026-08-01:** v1 connects with an **API key only** — signup, domain,
> DNS, and billing stay on app.mailkite.dev (deep-linked). We *may* build the full
> in-admin flow later: create-account form pre-filled with `admin_email` /
> current-user email → verify → add site domain → DNS records + check → scoped key,
> and possibly billing/upgrade via a Stripe-checkout redirect. The API supports all
> of it today (signup, domains/verify, keys/scoped, billing/usage) — this is a UI
> scope choice, not a technical limit. Rationale: ongoing management (multiple
> domains, webhooks, billing) belongs in our dashboard where it's already built and
> maintained once; the plugin's job is connect + send + observe. Revisit after
> measuring how many installs stall at "no API key yet".
- [x] Minimal settings page (PHP form, nonce + caps) — functional MVP, with
      mailer-choice section toggling (MailKite → key only; SMTP → server only;
      PHP mail → neither)
- [x] One-click settings migration importer (WP Mail SMTP incl. modern Easy WP SMTP,
      legacy Easy WP SMTP, FluentSMTP, Post SMTP — generic-SMTP configs only;
      detection banner on settings page)
- [x] REST: `mailkite-smtp/v1` settings/test/logs endpoints (`permission_callback`)
- [ ] `@wordpress/scripts` build; React page with `@wordpress/components`
- [ ] Full-screen first-run wizard: provider picker (MailKite recommended card + BYO
      grid) → connect → domain/DNS → test → done
- [ ] **Mint a real affiliate ref code for the plugin** — `ref` must be an 8-char
      Crockford-base32 affiliate code (`dashboard/src/lib/ref.ts`); `?ref=wp-plugin` is
      silently rejected. Until minted, links use plain UTM. Then embed
      `app.mailkite.dev/?ref=<code>` in the settings page + wizard signup call.
- [ ] **In-admin MailKite signup**: `POST /api/auth/signup` (+`ref` attribution) →
      verify → `POST /api/domains` → DNS records UI → `/api/domains/{id}/verify` poll
      → scoped key via `/api/keys/scoped` → test send  ⚠ needs `/api/keys*` +
      `/api/billing/usage` added to `sdks/spec/api.json` first
- [ ] Dashboard: connection health, 24h sent/failed, quota bar (`/api/billing/usage`),
      SPF/DKIM/DMARC card
- [x] One-click settings migration importer — shipped in the PHP MVP; re-surface it
      inside the React wizard when that lands

### Phase 4 — wp.org launch (M1/M2)
- [ ] Plugin Check clean pass; readme short description ≤ 150 chars; screenshots
      (wizard, logs, dashboard); banner/icon assets
- [ ] Submit to wordpress.org (slug fixed at submission — decide final name first);
      review queue 2–6 wks
- [ ] `10up/action-wordpress-plugin-deploy` tag→SVN pipeline + asset action
- [ ] Launch content cluster live same week (mailkite-seo skill; per-plugin fix guides)

### Phase 5 — P1 features
- [ ] BYO mailers: Gmail/Workspace OAuth, Brevo, SendGrid, Mailgun, SES
- [x] Automatic failover (free — everyone else paywalls it): MailKite failure retries
      via configured SMTP or PHP mail; log row records both outcomes
- [ ] **Retitle once failover + inbound ship** (claims must match shipped features):
      "MailKite SMTP – Multi-Provider SMTP with Failover, Email Logs & Inbound Email".
      Until then the name stays "MailKite SMTP – SMTP and Email Log Plugin for Any
      SMTP Provider" (decided 2026-08-01; slug `mailkite-smtp` is permanent).
      Failover shipped 2026-08-01 — inbound is now the only gate.
- [x] Instant failure **email** alerts (recursion-guarded, one alert per error per
      15 min, custom recipient defaulting to admin_email). Slack/Discord/Telegram todo
- [x] WP-CLI: `wp mailkite status|test|log|purge` (category first; not yet run against
      a real wp-cli install — Playground has none; verify before release)
- [ ] Inbound tab: routes UI + `do_action('mailkite_inbound', $message)` + docs
- [ ] Domain health weekly re-check + drift alert
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
