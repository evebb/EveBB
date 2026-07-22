# eveBB 2.1 roadmap

`main` is frozen for 2.0.0 (bug fixes only — see RELEASES.md for the
beta-exit checklist). This is the queue for the 2.1 cycle, roughly in
priority order. Nothing here is a promise; it's where development picks
up once 2.0.0 stable ships.

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

## Also on the radar

- Flip release.yml back to marking `-alpha/-beta/-rc` tags as GitHub
  prereleases once 2.0.0 stable exists (details in RELEASES.md), and add
  GPG signing of release artifacts (needs a signing secret in the repo).
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
