# eveBB security audit

Audit performed at version 1.19.0-alpha; fixes landed in **1.20.0-alpha**.

## Scope and method

The whole codebase (~32,000 lines of PHP across the forum, admin console,
database layer, parser, updater, plugin/style managers and the new visual
editor) was reviewed in five independent passes:

1. SQL injection and database-layer safety
2. XSS (stored and reflected) and CSRF
3. File uploads, path traversal, local/remote file inclusion, the self-updater
4. Authentication, sessions, passwords, authorization and IDOR
5. Legacy/dead code, information disclosure, config hygiene and robustness

Every candidate finding was then re-traced by hand against the actual code to
confirm it was real and reachable before any change was made, and each fix is
covered by an automated test. The full suite (unit + end-to-end) runs green on
all five supported database drivers (MySQLi, PDO-MySQL, SQLite, PostgreSQL and
PDO-PostgreSQL).

## Headline

The forum's foundations are sound. Specifically, the audit found **no SQL
injection**, **no XSS in the new features** (the BBCode parser escapes the
whole message before expansion, `javascript:` URLs are neutralised, and
avatars, the logo, smilies and the new profile fields are all escaped),
**no path traversal / zip-slip / file-inclusion** (the plugin, style and
updater extractors all validate entry names and confine writes), and a
**well-built cookie-auth and password layer** (HMAC-signed cookies compared
with `hash_equals`, bcrypt via `password_hash`, transparent rehashing of
legacy hashes).

The changes below harden the edges, remove dead legacy code, and fix one
correctness bug.

## Fixed in 1.20.0-alpha

**Account-takeover tokens now compared in constant time.** The
password-reset key and email-change key were compared with a loose `!=`,
which is both a timing side-channel and a PHP type-juggling hazard on a
secret token. They now use `hash_equals()` with strict typing.
*(profile.php)*

**Uploaded avatars are always re-encoded.** An avatar within the display
size was previously stored byte-for-byte, so a crafted image/PHP polyglot
could sit on disk and would execute under a misconfigured web server. Every
accepted upload is now decoded and re-encoded through GD (which never
upscales), so a stored avatar can never be executable content. A regression
test uploads a real PNG with PHP appended and asserts the stored file
contains none of it. *(profile.php)*

**Self-updater transport hardened.** The updater downloads code and then runs
it, so the fetch is now HTTPS-only for any remote host (a loopback mirror is
still allowed, as it can't be intercepted), with certificate and hostname
verification forced on and redirect-downgrades to plain HTTP refused — even on
a host whose `php.ini` would otherwise relax verification. *(include/update.php)*

**SameSite=Lax on the auth cookie.** The session cookie is now issued with
`SameSite=Lax`, so a forged cross-site request arrives unauthenticated — a
browser-enforced CSRF layer on top of the existing referrer check, covering
posting, editing, deleting, profile changes, avatar upload, moderation and
admin actions in one stroke. *(include/functions.php)*

**Database-updater confirmation gate.** The gate that confirms an admin by
matching the config password was case-insensitive and used a loose `!=`; it
now uses `hash_equals()` against the exact value, and the update-session
token is generated with the CSPRNG instead of `uniqid()`/`rand()`.
*(db_update.php)*

**Robustness: no TypeError on an unknown login.** Authenticating a
non-existent username (e.g. via the feed's HTTP Basic Auth) reached a string
comparison against a null stored hash, raising a PHP 8 TypeError (a 500). The
comparison is now guarded and falls through to the guest user. *(include/functions.php)*

**Correctness: the PDO-MySQL driver was unreachable.** A duplicate
`case 'mysql'` in the driver loader meant the "MySQL (PDO)" option silently
ran the classic MySQLi driver instead — and would have hard-failed on a host
that has PDO-MySQL but not MySQLi. `mysql` now maps to the PDO driver as
intended, and the end-to-end suite genuinely exercises it for the first time.
*(include/dblayer/common_db.php)*

**Stronger new-install cookie secret.** Fresh installs now generate a 128-bit
cookie seed (was 64-bit). Existing installs are unaffected. *(install.php)*

**Deserialization hardened.** Every `unserialize()` of a stored moderator
list or the search cache now passes `allowed_classes => false`. These blobs
are written by the application, not users, so this is defense-in-depth against
PHP object injection. *(19 call sites)*

**Baseline `.htaccess`.** New installs ship a conservative Apache `.htaccess`
that denies directory listing and blocks direct HTTP access to `config.php`
and to raw `*.sqlite` files. The one-click updater preserves it, so it never
overwrites an admin's customised copy. On nginx/IIS the equivalent belongs in
the server config.

**Dead legacy code removed.** Deleted the redundant re-read of
`/proc/loadavg`, the `exec('uptime')` shell-out fallback, the PHP4/5-era
accelerator detection (ionCube/APC/eAccelerator/XCache/Turck MMCache/Zend
Optimizer — only OPcache and APCu remain), and a no-op `mt_srand()` on every
request.

## Recommended next steps (not yet done)

These are real improvements but larger than a hardening pass, so they're
called out rather than silently deferred:

- **Login rate-limiting / lockout.** ✅ **Implemented in 1.21.0-alpha.** Failed
  logins are now throttled per client IP (real `REMOTE_ADDR`, so it can't be
  bypassed by spoofing a forwarded-for header): after a configurable number of
  failures an IP is locked out for a configurable window, and a successful
  login clears the counter. The lock is per-IP, not per-account, so it can't be
  used to lock a member out of their own account. State lives in a new
  `login_attempts` table; the threshold, window and an on/off switch are in
  Admin → Options → Features.
- **Per-form CSRF tokens.** SameSite=Lax plus the referrer check is a strong
  defense, but adding an explicit CSRF token to the classic POST forms
  (inherited from FluxBB, currently referrer-protected) would be defense in
  depth. The token machinery already exists in the codebase.
- **Registration CAPTCHA / proof-of-work.** Registration is throttled only by
  one-per-IP-per-hour; a CAPTCHA toggle would blunt mass automated signups.
- **Signed / checksummed releases.** The updater now enforces verified HTTPS,
  but does not yet verify a publisher signature or checksum of the downloaded
  package. Publishing a SHA-256 (or a minisign/GPG signature) in the release
  feed and verifying it before extraction would protect against a compromised
  or swapped release asset.
- **Deployment hardening docs.** Document the nginx/IIS equivalents of the
  shipped `.htaccess`, and keep nudging admins to delete `install.php` after
  setup (the admin console and updater already do).

## Notably verified safe

For the record, these were traced and found solid: all `$db->query()`
construction (consistent `intval`/`escape` with whitelisted sort columns and
directions); the BBCode/URL/image/colour parser and smiley/avatar/logo output
escaping; the SCEditor integration (only static config reaches the page, URLs
resolve client-side); plugin/style/updater zip extraction (zip-slip guarded,
slug-validated, confined writes); admin/mod authorization on every
`admin_*.php` and privileged action; post/profile ownership checks (no IDOR);
hidden-forum permission filtering; and email header-injection defenses in
`pun_mail()`.
