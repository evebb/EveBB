# eveBB release and development policy

This document describes how eveBB is versioned, how releases are cut, and
what users and contributors can expect from each release channel. The
[wiki](https://github.com/evebb/EveBB/wiki) covers using and extending the
software; this file covers how it ships.

## Versioning

eveBB uses `MAJOR.MINOR.PATCH` version numbers, optionally suffixed with a
prerelease channel (`-beta.N`, `-rc.N`):

- **PATCH** (2.0.0 → 2.0.1): bug fixes and security fixes only. Never a
  database change, never a behaviour change an admin has to think about.
  Released as soon as they are ready — security fixes immediately.
- **MINOR** (2.0.x → 2.1.0): new features and improvements, on a relaxed
  cadence (roughly every one to two months). Database revision changes only
  ever happen in a minor release, and the guided/silent update path from
  every prior release is tested before tagging.
- **MAJOR** (2.x → 3.0.0): reserved for changes that break plugins, styles
  or the upgrade path. None are planned.

The `1.x-alpha` series (2026) was the rapid modernization phase that turned
FluxBB 1.5 into eveBB; the `1.x` numbering honoured that lineage. Stable
eveBB begins at **2.0.0**.

Version ordering follows PHP's `version_compare`, which the in-app updater
uses: `alpha` < `beta` < `rc` < plain release. Boards update themselves
along that path in order, never backwards.

## Release channels

- **Stable** (e.g. `2.0.0`, `2.0.1`): what the evebb.net download button
  serves and what the in-app updater offers by default. Recommended for all
  boards.
- **Beta / RC** (e.g. `2.1.0-beta.1`): feature-complete previews of the next
  minor, for testers. Once a stable release exists, prereleases are marked
  as such on GitHub, so the permanent download link and `releases/latest`
  keep pointing at stable; testers pick betas up from the
  [releases page](https://github.com/evebb/EveBB/releases). The in-app
  updater prefers stable over prereleases on its own.

Until the first stable ships, the newest beta is published as the current
release (there is no stable to point at yet).

## The road from beta to stable

A prerelease graduates when the checklist is green, not on a date:

1. **Feature freeze** — from `X.Y.0-beta.1` onward, only bug fixes land
   until `X.Y.0` ships. Features wait for the next minor.
2. **Schema freeze** — no database revision changes during the freeze.
3. **Green suite** — unit tests, the database contract suite, and the full
   end-to-end suite (install, login, posting, search, registration,
   updater) pass on all five database drivers: mysqli, MySQL (PDO), SQLite,
   pgsql, PostgreSQL (PDO).
4. **Proven upgrade path** — the one-click updater and the guided database
   update are exercised from a FluxBB 1.5 board and from earlier eveBB
   releases.
5. **Burn-in** — evebb.net (which always runs the newest release) has run
   the candidate without incident.
6. **Docs current** — the wiki reflects the release.

If a serious bug is found in an RC, the fix ships as the next RC and the
clock restarts for that item only.

## How releases are built

**Before tagging, bump the version in all three places.** They are not
generated from one another and nothing fails if they disagree:

- `include/common.php` — `FORUM_VERSION`
- `install.php` — `FORUM_VERSION` (must match the above)
- `latest_version` — the plain-text pointer the readme links to

Every release is cut by tagging `main` (`git tag vX.Y.Z`); a GitHub
workflow builds the package deterministically from the tag and publishes:

- `evebb-X.Y.Z.zip` — the deployment package (no tests, no `.git`)
- `evebb-X.Y.Z.zip.sha256` — SHA-256 checksum in `sha256sum -c` format
- `evebb-latest.zip` (+ `.sha256`) — byte-identical stable-name copy behind
  the permanent download link
- `evebb-X.Y.Z.zip.sha256.asc` — detached GPG signature, when a signing key
  is configured

The in-app updater downloads the package, verifies the published SHA-256
**before** extracting, and refuses a mismatch. Manual installs can do the
same with `sha256sum -c`.

### Verifying the GPG signature

From 2.0.0 onward, the checksum file of every release is signed. The public
key ships in the source tree as
[`docs/release-signing-key.asc`](docs/release-signing-key.asc)
(ed25519, fingerprint `CCD5 0C89 57DB 3A41 24B9  94AD B8CC B11B DFE7 8E1D`).
To verify a download:

```
gpg --import docs/release-signing-key.asc
gpg --verify evebb-X.Y.Z.zip.sha256.asc evebb-X.Y.Z.zip.sha256
sha256sum -c evebb-X.Y.Z.zip.sha256
```

A good signature over the checksum file plus a matching checksum proves the
package is exactly what the release workflow built.

## Development

Day-to-day development happens on `main`, which is kept releasable: every
change lands with its tests, and CI runs the suites on each push. Releases
are tags on `main`. A maintenance branch (e.g. `2.0.x`) is only created the
day a stable release needs a patch while unreleased feature work is already
sitting on `main` — not before.

Security issues: please report privately per [SECURITY.md](SECURITY.md)
rather than opening a public issue. Fixes ship as an immediate patch
release for the current stable.

## Support expectations

The current stable release receives bug and security fixes. The previous
minor receives security fixes for 6 months after being superseded. Alpha
and beta releases are supported in one direction only: forward, by
updating.
