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
  downloadable or emailed.
