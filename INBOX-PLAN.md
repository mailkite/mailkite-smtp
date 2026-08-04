# WordPress User Mailboxes — a real inbox, client side

> Created 2026-08-03 | Status: PLAN (build not started)
> Feature name (working): **Mailboxes** — every WP user can have a real email address on
> the site's MailKite domain: readable inside WordPress, in any IMAP client, and via API.
> Platform grounding verified against web-monorepo 2026-08-03 (imap-access.md,
> ImapCredentialRow, /api/imap/keys, MailboxRow/grants).

## 1. What ships (user's words, restated)

- WP users can **register an email address** on the site's domain — **off by default**,
  admin turns it on.
- Optionally, **logged-in users are auto-tied to `{username}@domain`** — also off by
  default.
- **Admin reserves addresses** (role accounts and anything custom) that users can never
  claim.
- Every mailbox lands on the user's **Credentials screen**: real **IMAP user/pass**
  (`mk_imap_…` app-password, IMAPS 993) that is **also API access** for the same scope.
- Platform dependency: **shipped 2026-08-03** — app passwords scope to a domain + address
  pattern (route-style matching) and carry `protocols: ["imap","api"]`, so one credential
  is both the IMAP password and the API bearer for that scope. See §6.

## 2. Admin controls (Settings → new "Mailboxes" tab; everything default-off)

| Setting | Default | Notes |
|---|---|---|
| Enable user mailboxes | off | master switch |
| Mailbox domain | — | picker from the connected account's verified domains (same source as Inbound) |
| Auto-assign `{username}@domain` to logged-in users | off | assignment happens lazily on first visit to the credentials screen (no bulk provisioning surprise) |
| Allow self-registration of a chosen address | off | user picks a local part instead of/besides the username |
| Roles allowed | administrators only | multi-select; e.g. + editors, + subscribers |
| Reserved addresses | RFC-2142 set + common roles | `postmaster, abuse, admin, administrator, root, security, hostmaster, webmaster, billing, support, sales, info, contact, help, legal, privacy, dmca, noreply, no-reply, mail, smtp, imap, api, www, test` — admin-editable list, always lowercased; also auto-reserve any local part that already matches an existing MailKite route on the domain |
| Per-user send limit | 200/day | plugin-enforced; protects the site's sending reputation |
| Mailbox on user deletion | revoke access, keep mail 30 days | policy dropdown: keep / delete / export-then-delete |

## 3. Address lifecycle

1. **Claim** (auto-from-username or self-registered): lowercase, slugify to
   `[a-z0-9._-]`, length 1–64; reject reserved list, existing WP claims (usermeta
   uniqueness), and existing MailKite routes on the domain.
2. **Provision on MailKite** (site's API key) — now a single call:
   `createAppPassword({ domain, address: "<local>", protocols: ["imap","api"], label })`.
   Store per-user in usermeta: address, app-password id + secret (AES-encrypted with the
   plugin's existing `Crypto`). Add a store-to-mailbox route only if the domain's
   catch-all would otherwise swallow the address.
3. **Revoke/release**: `deleteAppPassword` (IMAP sessions and API calls using it stop
   immediately), then apply the deletion policy from §2.

## 4. Credentials screen (per user)

Two surfaces, same renderer: **wp-admin → Profile → "Your MailKite mailbox"** and a
front-end **`[mailkite_credentials]` shortcode/block** for membership-style sites.

Shows: the address; IMAP settings (host `imap.mailkite.dev`, port 993, SSL, username =
the address, password = `mk_imap_…` shown **once** at creation with copy button, then
masked with a **Regenerate** action); "works in Apple Mail / Thunderbird / any IMAP
client" hint; and an **API access** block — the same secret is the API bearer for that
address scope (`protocols: ["imap","api"]`), with a copyable curl example.

## 5. The in-WordPress inbox (client side)

**`[mailkite_inbox]` shortcode + block, plus the same view in wp-admin.**

- **Phase A — read**: message list (unread badges, sender, subject, date) + reader
  (text + sanitized HTML via `wp_kses_post`, attachment list with download).
  Transport: a WP REST proxy (`mailkite-smtp/v1/inbox/*`, logged-in + per-user
  authorization) that calls `listMailboxMessages` / `getMailboxMessageRaw` /
  `setMailboxMessageFlags` **with that user's own app password** — the site key never
  enters the read path.
- **Phase B — act**: reply/forward/compose. Sends go through the plugin's existing
  mailer with `From:` **forced to the user's claimed address** (never user-chosen),
  logged like all plugin mail, per-user daily cap from §2.
- **Phase C — comfort**: search, folders/labels (maps to IMAP folders), unread count
  in the admin bar, optional email notification "you have mail on {site}".
- Explicit non-goal: rebuilding Gmail. The IMAP credential is the full-power client;
  the WP inbox is the zero-setup one.

## 6. Platform asks (MailKite side — the "in progress" list, confirmed against code)

| Ask | Status 2026-08-03 |
|---|---|
| Mailbox/address-scoped app passwords | ✅ **shipped** — `createAppPassword` with `address` **pattern** scope (`*`, `hello`, `support-*`, `*-agent`) inside a domain |
| Route-style matching scopes | ✅ **shipped** — that same `address` pattern IS the route-style match |
| Same credential as API bearer | ✅ **shipped** — `protocols: ["imap","api"]` on the app password |
| Mailbox read/write API | ✅ **shipped** — `listMailboxMessages`, `getMailboxMessageRaw`, `setMailboxMessageFlags` |
| All of it in the public spec + SDKs/MCP/CLI | ✅ **shipped** — 77 spec methods @ 0.18.0, propagated |

**Consequence: the whole plan is unblocked, and P4 collapses into P2** — the WP inbox can
use each user's OWN app password (api protocol, scoped to their address pattern) from the
first commit; the site-key interim in §5 is no longer needed. Provisioning per user is now
one call (`createAppPassword` with `address: "<local>"`, `protocols: ["imap","api"]`)
instead of mailbox-create + route + credential.

## 7. Build phases

- **P1 — plumbing** (plugin): Mailboxes settings tab, reserved-list engine, claim +
  provisioning + revocation, credentials screen (IMAP usable in real clients on day
  one). *Fully unblocked — every API it needs is shipped and in the SDKs.*
- **P2 — inbox read** (plugin): REST proxy + list/reader UI, per-user credentials.
- **P3 — compose/reply + notifications.**
- ~~P4 — scoped-credential migration~~ **folded into P2** (platform shipped 2026-08-03):
  per-user app passwords are available immediately, so the site key never enters the
  read path.
- **P5 — multisite**: network domain policy, per-site local-part namespacing.

## 8. Security & privacy invariants

- Per-user secrets AES-encrypted (existing `Crypto`), never rendered after reveal-once.
- Users read **only their mailbox** — enforced in the proxy's permission callback by
  usermeta ownership, never by client input.
- Sending as an address requires owning it; From is server-forced.
- Reserved list prevents role-account capture; route-conflict check prevents
  shadowing existing automation.
- User deletion follows the §2 policy; everything logged in the existing email log
  (inbound rows already work).

## 9. Open decisions (Gabe)

1. Front-end-first (`[mailkite_inbox]` for membership sites) or wp-admin-first?
2. May users pick arbitrary local parts, or username-derived only (when self-serve is
   on)? Aliases (plus-addressing is free already)?
3. Address release on username change / user deletion — reassignable after cooldown?
4. ~~Which platform ask next~~ — all shipped. Remaining question: build P1+P2 in one
   pass, or ship P1 (addresses + credentials, usable in Apple Mail) first and let the
   in-WP reader follow?

---

## 10. Address model — decision needed (2026-08-04)

Question raised in testing: one address per user or several? Tied to the username or
chosen? And why couldn't an existing mailbox be changed?

**Why it couldn't be changed:** there was no rename. Changing the site's mailbox domain
only affects NEW claims, so an existing address kept sending as the old domain. Fixed
2026-08-04: the profile warns when an address is off-domain and offers a one-click
**Move** (same local part, new domain, old address stops working). A *rename* — keeping
the domain, changing the local part — is still missing.

### Recommendation: one identity address per user, renameable; aliases later

| Option | Verdict |
|---|---|
| **One address per user, derived from the username** | **Default.** One identity, one IMAP login, no decisions for the site owner. Matches what membership/team/agency sites actually want. |
| Let the user pick the local part | Offer as a site setting (already built: "let users choose their own address"). Right for communities, wrong for staff mailboxes. |
| Several *mailboxes* per user | Reject. Each is a separate IMAP account to configure and a separate inbox to check — the cost lands on the user for a rare need. |
| Several *addresses* into one mailbox (aliases) | **The right shape for "multiple" when we build it:** one primary address (the identity + IMAP login) plus aliases that deliver to the same inbox and can be picked as From. Gmail/Fastmail model. |

**Why aliases beat multiple mailboxes here:** MailKite app passwords already scope to an
address *pattern* (`jane`, `support-*`, `*`), so one credential can cover a family of
addresses without minting more. IMAP still logs in as one address, so the inbox stays
single — the plugin would merge the per-address listings for the reader.

### Settings shape to build

`Address mode` (one choice, admin):
1. **From username, locked** — `{username}@domain`, users cannot change it (default).
2. **From username, renameable** — same, but the holder may rename once (or admin any time).
3. **User chooses** — free local part at first claim, subject to the reserved list.

Then: **Rename** on the profile (release + claim under the hood, with a plain warning that
the old address stops receiving), and later **Aliases** as an additive list per user.

### Edge cases to handle when building
- Two usernames can slugify to the same local part (`jane.doe` and `jane_doe` → `jane-doe`).
  Auto-assign must append a suffix (`jane-doe2`) rather than silently fail for the second user.
- WordPress usernames are immutable in the UI, so "tied to username" is stable in practice —
  but a rename must never orphan mail: warn, and prefer keeping the old address as an alias
  once aliases exist.
- Moving/renaming revokes the old credential: any mail client configured with it stops
  working and must be updated. Say so at the point of action.

---

## 11. One webhook, one store (decided 2026-08-04)

Today there are two unrelated inbound paths: the SMTP plugin takes a **webhook push** and
stores rows locally; the Mailboxes add-on **pulls live** from the mailbox API and stores
nothing. That splits the truth, duplicates delivery, and leaves personal mail readable by
any administrator in the site log.

**Decision: one webhook, one local store, owned by the SMTP plugin; the add-on reads it.**

### Why local storage at all
MailKite retention is finite. The WordPress database becomes the archive of record — mail
stays readable after it ages out upstream. It also removes an API round-trip per page view,
which is what makes the inbox feel slow today.

### Ownership
The add-on already depends on the SMTP plugin (`Requires Plugins`), so the dependency
direction is settled: **the parent owns the endpoint and the schema**, the add-on only
reads. The add-on must never register a second webhook — one endpoint, one row per message.

### Schema (parent, migration to DB_VERSION 3)
Split the body off the list table so listing never drags message text through memory:

| Table | Holds |
|---|---|
| `..._log` (exists) | envelope + metadata: from, to, subject, status, mailer, thread_id, message_id, direction |
| `..._body` (new) | `log_id`, `body_text` LONGTEXT, `body_html` LONGTEXT |
| `..._attachment` (new) | `log_id`, filename, mime, size, storage (`db`/`file`/`remote`), path/url |

MySQL LONGTEXT is fine for HTML mail (typical 20–200KB; the ceiling is 4GB). The bloat
problem is not size, it is `SELECT *` on the list query — solved by the split, not by moving
bodies to disk.

### Attachments — where the file question lands
`wp_upload_dir()` is writable and `WP_Filesystem` works, but **uploads are web-served**:
`/wp-content/uploads/...` is a public URL. Storing raw mail there risks exactly the leak
several email-log plugins have shipped. Rules if we store files:
- write under `uploads/mailkite-private/` with an unguessable per-message directory,
- drop `index.php`, `.htaccess` (deny all) **and** `web.config` — noting nginx honours none
  of these, so obscurity + a PHP-gated download endpoint is the real control,
- never link the file path directly; serve through an authenticated endpoint that checks
  ownership.
Default: **do not store attachment bytes.** Keep filename/size/mime metadata and MailKite's
signed URL; offer "keep a local copy" as an explicit opt-in for people who need the archive.

### Access control (the leak this fixes)
A row whose recipient matches a **claimed user mailbox** is that user's mail:
- the admin Email Log must exclude it,
- the add-on shows it to that user only, matched on their stored address,
- capability check at query level, never in the template.
Site mail (`hello@`, `noreply@` replies, bounces) stays fully visible to admins as now.

### Retention, split in two
- **Log retention** (site mail): current setting, purge after N days.
- **Mailbox retention** (user mail): separate, default *keep* — purging someone's inbox on a
  log-cleanup schedule would be data loss.

### Reliability: webhook first, API as repair
Push is the fast path; a site that was offline still misses mail no matter the retries. Keep
the mailbox API as a **backfill/repair** path: "sync now" pulls anything missing since the
newest stored uid. Webhook for realtime, pull for gaps — the same pattern the log already
uses for its own outbound rows.

### Build order
1. Parent: schema v3 + body/attachment tables; inbound writes them; log detail reads them.
2. Parent: per-user rows excluded from the admin log; ownership helper the add-on can call.
3. Add-on: inbox reads local rows for the user's address (API only as fallback/backfill).
4. Retention split + a "sync now" repair action.
5. Optional: opt-in local attachment storage behind the protections above.

### §11 checklist

**Parent — MailKite SMTP (owns endpoint + schema)**
- [ ] 1. Schema v3: `_body` (text/html split off the list table), `_attachment` (metadata),
      `direction` and `owner_user_id` columns; migrate existing `body` values across.
- [ ] 2. `Log\Store` — the single read/write API both plugins call. No plugin hand-writes SQL
      against another plugin's tables.
- [ ] 3. Inbound webhook writes through Store: text + html kept apart, attachments recorded,
      `direction=inbound`, owner resolved via the `mailkite_smtp_mailbox_owner` filter.
- [ ] 4. Outbound capture records `direction` and owner (matched on the From address).
- [ ] 5. Admin Email Log excludes owned rows (a user's mail is not site mail) and reads
      bodies from `_body`; the reader renders HTML sanitised.
- [ ] 6. Retention splits: site mail purges on the existing schedule, mailbox mail is kept.

**Add-on — MailKite Mailboxes (reads, never writes schema)**
- [ ] 7. Answer `mailkite_smtp_mailbox_owner` so ingest can stamp the right user.
- [ ] 8. Inbox lists and reads from the local Store instead of a per-view API call.
- [ ] 9. "Sync now" backfills from the mailbox API — the repair path for anything the
      webhook missed while the site was down.
