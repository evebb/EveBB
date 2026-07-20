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

## Also on the radar

- Flip release.yml back to marking `-alpha/-beta/-rc` tags as GitHub
  prereleases once 2.0.0 stable exists (details in RELEASES.md), and add
  GPG signing of release artifacts (needs a signing secret in the repo).
- Re-shoot the landing-page screenshots once the community board has
  real activity to show.
