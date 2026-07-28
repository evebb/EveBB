# Plugin end-to-end suites

Suites for the plugins shipped in `website/plugins/`. Each one installs
a real board through the real installer, activates the plugin from its
**committed zip**, drives it over HTTP with `curl`, and asserts on what
comes back.

These live apart from `tests/e2e/` because they test the plugins rather
than the core, and because they need a plugin zip to exist. They are not
wired into `ci.yml`: the core workflow names its suites explicitly, and
a plugin release does not necessarily coincide with a core release. Run
them by hand when changing a plugin.

## Running

    ./tests/plugins/showcase-test.sh

No arguments. Each suite builds a throwaway board under `mktemp -d` and
cleans up after itself. Requires `php` (with `pdo_sqlite`), `curl` and
`unzip`.

Useful overrides:

| Variable | Purpose |
|---|---|
| `PORT` | port for the built-in server (default varies per suite) |
| `ZIP` | test a different zip than the committed one |
| `PLUGIN_SRC` | test an unpacked plugin folder instead of a zip |

`PLUGIN_SRC` is the one to use while developing: point it at your
working copy and re-run without repackaging.

## Writing one

Reuse the shape of `showcase-test.sh`. The traps that have cost time
before, all of them still live:

- Start PHP with `-d opcache.enable=0`, or deleting `cache/cache_*.php`
  will not reliably take effect.
- **The installer creates its own category and forum**, so forums you
  seed are not ids 1 and 2 — capture the ids you insert and use them
  everywhere, including any settings-page POST that would otherwise
  reset the mapping.
- `login.php` needs a `csrf_token` in the POST; read it out of the login
  form first.
- **Plugin config rows do not exist until the settings page first
  saves**, so a test changing a setting must INSERT, not UPDATE.
- Activating a plugin means UPDATE-ing the existing `o_active_plugins`
  row (an INSERT hits the unique key and silently does nothing), then
  deleting the config cache.
- The settings page is at
  `admin_plugins.php?action=settings&plugin=<slug>`; a POST without
  `action=settings` silently renders the plugin list instead.
- `redirect()` renders an interstitial rather than sending `Location`,
  so assert a login by fetching a page with the same cookie jar and
  looking for the logout link.
- Assert the PHP error log is clean at the end. Filter out core noise
  you did not cause, but print it — that is how core bugs get found.

A suite that only greps markup proves nothing about appearance. If a
change is visual, render the page in a browser and look at it.
