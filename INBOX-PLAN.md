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
