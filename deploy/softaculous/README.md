# eveBB - Softaculous package (draft)

Groundwork for getting eveBB into the Softaculous auto-installer
catalogue, which ships inside most cPanel-style hosting panels and is
the widest single distribution channel for shared-hosting users.

## Status: DRAFT - awaiting Softaculous engagement

Inclusion in the official catalogue is decided by the Softaculous team,
historically via "Script Request" threads on softaculous.com/board plus
direct contact. This directory exists so that when they engage, we can
hand them a working package instead of a request - which materially
lowers their cost to say yes.

The pitch that matters: **Softaculous carried FluxBB, and FluxBB is
abandoned upstream (2021). eveBB is its direct continuation** - fully
schema-compatible with FluxBB 1.5 - so this is less "please add a new
app" and more "here is the maintained successor to an app in your
catalogue".

## What's here

- `evebb/info.xml` - package metadata (overview, features, version,
  links) in their documented format.
- `evebb/install.xml` - install mode. **Copy mode** (`<softcopy>`):
  Softaculous extracts the files and sends the user to eveBB's own
  installer at `install.php`. This is deliberate for v1:
  - eveBB's installer covers everything, including SQLite boards with
    no database server - something full automation can't express.
  - No duplicated schema/seed logic to drift out of sync with core.
  - Roadmap item 10 (install defaults file) will let the package
    pre-fill the DB fields Softaculous provisions; a later revision can
    then move to full `<softinstall>` automation (their preference for
    featured scripts).
- `make-package.sh` - assembles the payload: downloads the latest
  official release (SHA-256 verified) and names it `evebb.zip` as
  Softaculous requires, alongside the two XML files.

## Not yet done (needs a Softaculous instance to test against)

- **fileindex.php** (uninstall tracking) and **install.js** (form
  validation) - their exact expected content is best generated against
  a real Softaculous dev install; the docs are thin here.
- **upgrade.xml / upgrade.php** - version-upgrade packaging. Note the
  interplay: eveBB self-updates via Admin -> Maintenance, so a board
  installed through Softaculous will drift ahead of what Softaculous
  thinks is installed. Options to discuss with their team: mark the
  script as self-updating (other self-updating scripts exist in their
  catalogue), or ship an upgrade package per release.
- End-to-end test on a trial Softaculous licence (they offer free
  trials; needs a VPS with a panel - could reuse a DO droplet).

## Process notes

- Their package docs: softaculous.com/docs/developers/making-custom-package/
- Custom packages install into `/var/softaculous/evebb/` on any
  Softaculous instance - that's how to test before submission.
- The Script Request post comes from Alan (maintainer) - draft provided
  in the project's discovery materials.
