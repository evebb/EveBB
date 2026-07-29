# eveBB 2.1 roadmap

2.0.0 stable shipped on 2026-07-22, so the freeze is over and this queue
is live. Roughly in priority order; nothing here is a promise.

## 1. `[noparse]` BBCode

Escape hatch for posting BBCode literally (documentation, support
answers, style/plugin threads). Tops the list because the official
community board needs it daily: today the only workaround is the code
block, which changes presentation.

## 2. Style-provided logos in core

Styles should be able to ship their own board logo (the convention the
stylelogo plugin proves out: `img/logo-<style>.png`). Fold the lookup
into core so a style zip is all a board needs — no plugin, no manual
image swap. Keeps the plugin working as the fallback for older boards.

## 3. Attachments

The largest missing feature versus contemporary forum software, and the
reason downloads for the official board are FTP'd to the website
instead of posted. Needs: per-forum enable, size/type allowlists,
storage outside the webroot, permission-checked download handler,
orphan cleanup on post/topic delete, and a hard look at upload abuse
(tie into the antispam plugin's signals where possible).

## 4. First-post moderation

Hold a new member's first N posts for moderator approval — the single
most effective forum-spam control after registration-time checks.
Per-group setting; approval queue in the mod tools; email/notification
to moderators on new held posts.

## 5. Mentions, reactions, digests — and the hooks they need

Quality-of-life trio, deliberately grouped because they share plumbing
(a notification system):

- `@mentions` with a notification to the mentioned member
- lightweight post reactions (configurable set, no karma wars)
- opt-in email digests (daily/weekly activity summaries)

Doing these properly means growing the addon hook surface (post-save,
notification-dispatch, profile-settings hooks at minimum) so plugins can
extend rather than fork — the hook additions land first, the features
ride on them.

## 6. Embedded video in core

A `[video]` BBCode in the parser (YouTube first, click-to-load embeds
via youtube-nocookie.com, consent-aware where the cookieconsent plugin
is active) plus a matching button in the visual editor's toolbar. The
feature ships as a plugin first during the 2.0 freeze; this item is the
"bake it into core" half once 2.1 opens.

## 7. Two-factor authentication in core

Account security shouldn't require a plugin. The official
evebb-plugin-tfa (shipped during the 2.0 freeze) proves out the whole
design and serves boards until this lands; in 2.1 it moves into core
as a standard feature:

- TOTP (RFC 6238: SHA-1, 6 digits, 30 s, ±1 slot drift, replay-proof
  via a consumed last-slot) with locally rendered QR pairing — the
  plugin's lib.php crypto is validated against the RFC test vectors
  and ports over as include/tfa.php essentially unchanged.
- `tfa_users` + `tfa_backup` become core tables in install.php, with a
  db_update.php revision that ABSORBS an existing plugin install's
  tables and data in place (same names/shapes by design), so enabled
  members never notice the migration.
- Native UI instead of injections: a real "one-time code" field in
  login.php, a real Security section in profile.php (setup/QR/backup
  codes/disable), a staff reset in admin_users.php, proper lang
  strings throughout.
- Eight single-use backup codes (keyed hashes, atomic consumption),
  wrong codes feeding the core login throttle, sealed-HMAC setup
  tokens — all as proven in the plugin.
- The plugin detects core 2FA (FORUM_VERSION or a capability constant)
  and retires gracefully: its settings page says "now built in" and
  its hooks go inert, mirroring the video-plugin retirement plan.
- Possible 2.1+ stretch: per-group "require 2FA" (e.g. for
  Administrators), WebAuthn/passkeys as a second method.

## 8. Task scheduler in core (pseudo-cron)

A shared way to "run this periodically" so features stop hand-rolling
their own timers. The design deliberately follows WordPress's wp-cron
model, because a hard system-crontab dependency would fight both eveBB's
"fast, light, free, no dependencies" ethos and the shared-hosting
adoption goal (many cheap hosts have no reliable cron; requiring it
raises the install bar we want to lower).

Two drivers, same task registry:

- **Page-view driver (default, zero dependency).** Due tasks run in the
  background of ordinary web requests, exactly as several plugins
  already do by hand today. Works on any host with traffic; "runs
  within a few minutes of due, when someone next visits" is fine for
  almost everything a forum schedules.
- **Optional real cron (opt-in, punctual).** A `cron.php` endpoint an
  admin MAY hit from a system crontab (or an uptime pinger) for
  traffic-independent, on-time execution — the case for backups at 03:00
  or nightly digests. Guarded by a secret token so it can't be triggered
  by strangers. Setting a `PUN_DISABLE_PSEUDO_CRON` constant turns the
  page-view driver off when a real cron is wired up (mirrors WP's
  `DISABLE_WP_CRON`), so the work never runs twice.

Shape:

- A small `scheduler`/tasks table (task name, interval or next-run,
  last-run, payload) plus a register/claim API. Claiming a due task uses
  a compare-and-set on last-run so concurrent requests never double-run
  it — the same lock the badges sweep already uses, lifted into core.
- Tasks are set-based/idempotent where possible and must be safe to run
  late or twice.
- Admin visibility: a list of registered tasks with last/next run and a
  "run now" button, under Administration → Maintenance.
- A hook so plugins can register their own scheduled tasks.

Adoption (what migrates onto it once it lands): the badges hourly sweep,
the sitemap rebuild, relpost's GitHub polling, the coming outbound-
webhooks outbox drain, auto-lock-stale-topics, and scheduled backups
(the item under "Also on the radar" — it needs this, and is the main
reason a real-cron option matters). Each keeps working on its own
page-view timer until it's moved over; the scheduler is a consolidation,
not a hard cutover.

NOT a prerequisite for outbound webhooks — webhooks ship first on the
existing page-view outbox pattern and migrate here later. This item is
the "stop reinventing the timer" cleanup, valuable precisely because so
many features want it.

## 9. Social sign-in in core

Logging in with an account people already have shouldn't require a
plugin either. The official evebb-plugin-socialauth (shipped during the
2.0 freeze) proves out the whole design — Google, Discord, Microsoft and
GitHub through one shared OAuth2/OIDC flow — and serves boards until
this lands; in 2.1 it moves into core as a standard feature:

- A provider registry (label, endpoints, scope, identity normaliser)
  over one flow: authorize → code → token → identity. Adding a provider
  is a registry entry, not a new subsystem. Launch set: Google, Discord,
  Microsoft, GitHub. (Deliberately excluded for now: Apple — paid dev
  account + rotating JWT client secret raises the board-owner bar;
  Facebook — app review burden; X — paid API; Steam — OpenID 2.0 with no
  email, which breaks account matching.)
- One callback URL for every provider (`login.php?action=oauth` or
  similar once native), with the provider key carried inside the
  HMAC-sealed state token — never as a query parameter on the registered
  redirect URI, since some providers reject those. State is additionally
  bound to a short-lived nonce cookie (login-CSRF).
- `socialauth_users` becomes a core table in install.php — PK
  (user_id, provider), UNIQUE (provider, ext_id), so a member can hold
  one link per provider — with a db_update.php revision that ABSORBS an
  existing plugin install's table and data in place (same name/shape by
  design), so linked members never notice the migration.
- Only provider-VERIFIED emails ever match or create accounts (Google's
  email_verified, Discord's verified, GitHub's per-address list; a
  Microsoft account's email is the credential itself). No verified email
  → the visitor confirms one before the account is created. Accounts get
  a random unusable password; "forgotten password" adds one later.
- Native UI instead of injections: provider buttons rendered by
  login.php/register.php themselves, an admin page for per-provider
  client id/secret + the link-by-email toggle, a "linked accounts"
  section in profile.php (link/unlink per provider), proper lang
  strings.
- The plugin detects core social sign-in (capability constant) and
  retires gracefully, mirroring the tfa/video retirement plan.
- Possible 2.1+ stretch: more providers via the registry (any OIDC
  issuer as a generic entry), per-group "may use social sign-in", and
  account-settings unlink audit.

## 10. Zero-config install defaults (one-click images) — SHIPPED on main

Implemented 2026-07-23 (install.php reads install_defaults.php, prefills
and locks the preconfigured fields, ignores tampered submissions for
them, and deletes the file on success; e2e coverage in
tests/e2e/install-defaults-test.sh). Ships with the next release.

The `deploy/` one-click server images (DigitalOcean first) pre-provision
the database, but the visitor still has to copy credentials from
`/root/.evebb_credentials` into install.php's form. Close that last gap:
let install.php read defaults from a file the image drops next to it —
e.g. `install_defaults.php` returning an array of db_type/host/name/
user/password (and optionally base_url) — and prefill (or lock) those
fields, so a one-click user only ever chooses a board title and admin
account. The installer deletes the defaults file on success, as it
already prompts for install.php itself. Benefits every distribution
channel the same way: DO, Linode/Vultr/Lightsail templates, Softaculous
(whose installer conventions expect exactly this shape), Cloudron.

## 11. Private conversations (member-to-member messaging)

FluxBB never shipped private messages, so eveBB boards have no
member-to-member messaging at all — the most-requested feature class on
every comparable platform. Decided 2026-07-29: this arrives the proven
way — an OFFICIAL plugin first, absorbed into core in 2.1 on the §7/§9
pattern, with the plugin's tables kept in place so nothing migrates.

Deliberately not a copy of the 1998 inbox/outbox model that every
legacy platform ships and every admin ends up hating. The design:

- **Conversations, not mail.** One thread per set of participants,
  messages chronological, messenger-style. No folders, no sent-items,
  no per-message subjects. Three tables: conversations,
  conversation_users (participants map with last_read_at/deleted_at),
  messages (preparsed BBCode through the standard parser). Schema is
  group-capable from day one even if the first UI is pairs-first —
  staff-mode below already requires multi-participant plumbing.
- **Spam-proof by design.** New members can REPLY to conversations
  started with them but cannot INITIATE until a configurable age/post
  threshold; rate limit on starting conversations; per-member Block as
  a first-class control. PM spam is why admins disable messaging on
  other platforms; here the defence is the default.
- **Privacy people can verify.** True deletion — once every participant
  has deleted a conversation, the rows are removed by a scheduled sweep
  (the §8 scheduler's job once it lands). Messages are covered by the
  data-export and anonymise tooling. No staff read-access by default;
  if a board enables it, that fact is shown to members plainly.
- **Staff conversations.** A conversation opened "with the team"
  reaches all staff, any of whom can reply, with a private internal
  notes rail — a lightweight support desk inside the board, which no
  comparable PM system offers.
- **Zero administration.** Works on activation; a sane storage cap with
  oldest-conversation pruning instead of quota walls; unread badge in
  the header from one cheap timestamp comparison.
- Ecosystem: a message.received webhook event, badge triggers, and the
  natural first consumer for browser push notifications if that lands.
- Core absorption checklist mirrors §7: tables absorbed in place via a
  db_update.php revision, native profile/header UI replacing
  injections, lang strings, and the plugin retiring behind a capability
  constant.

## Also on the radar
- Re-shoot the landing-page screenshots once the community board has
  real activity to show.
- Migration importers (phpBB / SMF -> eveBB): bigger than a plugin and
  more valuable than most for adoption - likely a standalone `import/`
  tool in the spirit of `db_update.php`.
- Scheduled backups: admin-triggered or scheduled database dump,
  downloadable or emailed. The "scheduled" half wants the §8 task
  scheduler (and is the strongest case for its optional-real-cron
  driver — a backup should run at a set time, not "next time someone
  visits").
