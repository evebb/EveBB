# Security policy

## Reporting a vulnerability

Please report suspected security issues privately rather than in a public
issue: open a [GitHub security advisory](https://github.com/evebb/EveBB/security/advisories/new)
for the repository. We'll acknowledge the report and work with you on a fix
and disclosure timeline.

## Keeping an install secure

- **Update promptly.** Security fixes ship in releases; the built-in one-click
  updater (Admin → Maintenance) applies them over verified HTTPS.
- **Delete `install.php`** after setup. The updater removes it, and the admin
  console warns while it's present.
- **Protect `config.php`.** New installs ship an Apache `.htaccess` that denies
  direct access to it; on nginx/IIS add the equivalent rule to the server
  config.
- **Serve over HTTPS** and set `$cookie_secure = 1` in `config.php` so the auth
  cookie is only ever sent over TLS.

A full audit of the codebase is in [`docs/security-audit.md`](docs/security-audit.md).
