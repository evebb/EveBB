# eveBB

[![CI](https://github.com/evebb/EveBB/actions/workflows/ci.yml/badge.svg)](https://github.com/evebb/EveBB/actions/workflows/ci.yml)

A public test forum runs at [evebb.net](https://evebb.net).

eveBB is a fast, light, user-friendly forum application for your website. It is a modernized continuation of [FluxBB](https://fluxbb.org/), which ceased development after 2021, and inherits FluxBB's philosophy: fewer features, faster pages, and code you can actually read.

## Version

The current version is in the [`latest_version`](latest_version) file, with packaged builds on the [releases page](https://github.com/evebb/EveBB/releases). The database schema is fully compatible with FluxBB 1.5.x — existing FluxBB 1.5 boards can switch to eveBB in place, and older 1.4/1.2 boards upgrade through the bundled `db_update.php`. Boards update themselves through the built-in one-click updater (Admin → Maintenance).

How versions are numbered, how releases are cut and verified, and what each release channel means is described in [RELEASES.md](RELEASES.md). Documentation lives in the [wiki](https://github.com/evebb/EveBB/wiki).

## What eveBB adds over FluxBB 1.5

* Runs on modern PHP: fully compatible with PHP 8.1–8.4 (FluxBB required 5.6 and fatals on PHP 8).
* A PDO database layer alongside the classic drivers: MySQL (PDO), **SQLite3** (no database server needed — the forum runs on a single file), and PostgreSQL (PDO).
* The classic mysqli and pgsql drivers are retained and fixed for PHP 8.
* Modern security plumbing: `random_bytes()` entropy, bcrypt password hashing with transparent migration of legacy hashes, hardened CSRF checks.
* A real test suite: unit and characterization tests, a driver-agnostic database contract suite, and a scripted end-to-end suite covering install, login, posting, search, and registration.

## Requirements

* PHP 8.1 or later
* One of: MySQL/MariaDB (via mysqli or PDO), SQLite 3.35+ (via PDO), PostgreSQL 10+ (via pgsql or PDO)
* mbstring extension recommended (a native fallback is bundled)

## Installation

1. Upload the files to your web server.
2. Point your browser at `install.php` and follow the instructions.
3. When the installer finishes, remove `install.php` (the admin index will offer to do this for you).

For SQLite, enter a writable file path (for example `data/forum.sqlite`) as the database name — no database server or credentials are needed.

## Upgrading from FluxBB

Copy your `config.php` into a fresh eveBB tree (keeping your `img/avatars` and any custom styles), then open the forum. Boards older than 1.5 are redirected to `db_update.php` automatically. Take a database backup first, as you would for any upgrade.

## Running the tests

```
php tests/lite/run.php tests/functions tests/characterization   # unit tests
DB_TYPE=sqlite DB_NAME=/tmp/t.sqlite \
  php tests/lite/run.php tests/integration                      # DB contract
DB_TYPE=sqlite DB_NAME=/tmp/e2e.sqlite ./tests/e2e/run.sh       # end-to-end
```

The bundled `tests/lite` runner is PHPUnit-API-compatible; the same test files run unchanged under real PHPUnit (`composer install && composer test`).

## License and lineage

eveBB is free software released under the [GNU GPL, version 2 or later](https://www.gnu.org/licenses/gpl.html).

It is based on FluxBB, copyright (C) 2008–2012 the FluxBB team, which was in turn based on PunBB, copyright (C) 2002–2008 Rickard Andersson. All original copyright notices are retained in the source files. Thanks to both projects for two decades of lean forum software.

### Third-party components and credits

* **SCEditor** (`js/sceditor/`) — the visual editor, copyright (C) 2011–2017 Sam Clarke, [MIT License](js/sceditor/LICENSE.md).
* **Silk icons** (`js/sceditor/themes/famfamfam.png`) — editor toolbar icons by [Mark James](http://www.famfamfam.com/lab/icons/silk/), [Creative Commons Attribution 3.0](https://creativecommons.org/licenses/by/3.0/).
* **Nomicons emoticons** (`js/sceditor/emoticons/`) — "The Full Monty Emoticons" by Oscar Gruno and Andy Fedosjeenko, distributed with SCEditor.
* **Smilies** (`img/smilies/`) — from Google's [Noto Emoji](https://github.com/googlefonts/noto-emoji) project, [Apache License 2.0](https://www.apache.org/licenses/LICENSE-2.0).
* **UTF-8 library** (`include/utf8/`) — the phputf8 library by Harry Fuecks and contributors, portions ported from Mozilla code by Henri Sivonen; inherited from FluxBB with all notices retained.

See [`js/sceditor/CREDITS.md`](js/sceditor/CREDITS.md) and [`docs/copyright-audit.md`](docs/copyright-audit.md) for the full provenance audit.
