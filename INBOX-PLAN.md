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
- Platform dependency (in progress, MailKite side): IMAP credentials usable **per
  address or per domain with route-style matching**, and valid as REST API bearer for
  the same scope.

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
2. **Provision on MailKite** (site's API key):
   a. create the **mailbox** for `local@domain` (needs mailbox-create in the public
      spec — see §6 platform asks),
   b. create a **route** `local@domain → store to mailbox` (createRoute exists). The
      domain's catch-all (site inbound webhook) is untouched — specific route wins,
      which is exactly the routes-matching model,
   c. create a **mailbox-scoped `mk_imap_` credential** (`POST /api/imap/keys` with
      `mailbox_id` — exists today, migration 0086),
   d. store per-user in usermeta: address, mailbox id, credential id + secret
      (AES-encrypted with the plugin's existing Crypto).
3. **Revoke/release**: delete credential (`DELETE /api/imap/keys/:id`), delete route,
   apply the deletion policy to the mailbox.

## 4. Credentials screen (per user)

Two surfaces, same renderer: **wp-admin → Profile → "Your MailKite mailbox"** and a
front-end **`[mailkite_credentials]` shortcode/block** for membership-style sites.

Shows: the address; IMAP settings (host `imap.mailkite.dev`, port 993, SSL, username =
the address, password = `mk_imap_…` shown **once** at creation with copy button, then
masked with a **Regenerate** action); "works in Apple Mail / Thunderbird / any IMAP
client" hint; and an **API access** block (same credential as bearer for the
mailbox-scoped REST reads) marked "rolling out" until the platform lands it (§6).

## 5. The in-WordPress inbox (client side)

**`[mailkite_inbox]` shortcode + block, plus the same view in wp-admin.**

- **Phase A — read**: message list (unread badges, sender, subject, date) + reader
  (text + sanitized HTML via `wp_kses_post`, attachment list with download).
  Transport: a WP REST proxy (`mailkite-smtp/v1/inbox/*`, logged-in + per-user
  authorization) that calls MailKite server-side. Until scoped API access lands, the
  proxy uses the **site key filtered to the user's mailbox in PHP** (site admin can
  already read all site mail today, so this leaks nothing new); once mailbox-scoped
  bearer access ships, the proxy switches to **each user's own credential** and the
  site key drops out of the read path entirely.
- **Phase B — act**: reply/forward/compose. Sends go through the plugin's existing
  mailer with `From:` **forced to the user's claimed address** (never user-chosen),
  logged like all plugin mail, per-user daily cap from §2.
- **Phase C — comfort**: search, folders/labels (maps to IMAP folders), unread count
  in the admin bar, optional email notification "you have mail on {site}".
- Explicit non-goal: rebuilding Gmail. The IMAP credential is the full-power client;
  the WP inbox is the zero-setup one.

## 6. Platform asks (MailKite side — the "in progress" list, confirmed against code)

| Ask | Today | Needed |
|---|---|---|
| Mailbox-scoped IMAP app-passwords | ✅ exists (`mailbox_id` on ImapCredentialRow, 0086) | — |
| **Route-style matching scopes** for credentials (pattern per address/domain, like routes) | ❌ scopes are exact (account / domain / one mailbox) | pattern scope (e.g. `*@clients.example.com`) — Gabe says in progress |
| **Same credential as API bearer** (REST reads scoped to the credential's mailbox/domain) | ❌ `mk_imap_` only authenticates IMAP (`/api/imap/auth`) | accept `mk_imap_` on a read-only REST surface (messages list/get for the scope) — in progress; unblocks §5 proxy v2 |
| Mailbox CRUD in the public spec | ❌ mailboxes exist internally, not in api.json | spec + SDK the create/list/delete (SSOT rules apply) |
| `/api/imap/keys` in the public spec | ❌ undocumented | add to spec (same treatment as the Account group got) |

## 7. Build phases

- **P1 — plumbing** (plugin): Mailboxes settings tab, reserved-list engine, claim +
  provisioning + revocation, credentials screen (IMAP usable in real clients on day
  one). *Depends only on what exists today + mailbox-create being spec'd.*
- **P2 — inbox read** (plugin): REST proxy + list/reader UI (site-key interim).
- **P3 — compose/reply + notifications.**
- **P4 — scoped-credential migration** (when platform lands §6): per-user bearer
  reads, site key out of the read path; pattern scopes let one credential cover
  `*@domain` for admin tooling.
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
4. Which of the §6 platform asks do you want built next session (spec for
   `/api/imap/keys` + mailbox CRUD is the cheap unblocker; the scoped-bearer read
   surface is the big one)?
