# eveBB one-click deployment

Everything needed to build a server image (or bootstrap a fresh server) that
serves a ready-to-install eveBB forum: nginx + PHP-FPM + MariaDB provisioned,
the latest release downloaded and checksum-verified, the database created
with random credentials on first boot, and the user landing straight on
eveBB's own web installer.

This is the shared core for every "one-click" distribution channel. The
first target is the DigitalOcean Marketplace (`digitalocean/`); Linode,
Vultr and AWS Lightsail variants can reuse the same two scripts with a
different wrapper.

## Quickest path: cloud-init (no marketplace account needed)

`cloud-init/evebb-cloud-init.yaml` is a self-serve version of the same
provisioning: paste it into the "user data" box when creating an Ubuntu
24.04 server on DigitalOcean, Linode, Vultr, Hetzner or any cloud-init
provider, and the server provisions itself on first boot (it fetches the
scripts below from this repository). The wiki page
"Install on a Cloud Server" is the user-facing walkthrough.

## Layout

- `scripts/01-setup-evebb.sh` — build-time provisioner. Installs the stack
  and stages eveBB. Nothing instance-specific: safe to bake into an image.
- `scripts/02-evebb-firstboot.sh` — installed into
  `/var/lib/cloud/scripts/per-instance/` so cloud-init runs it once per new
  server: secures MariaDB, creates the forum database, writes the
  credentials to `/root/.evebb_credentials`.
- `files/nginx-evebb.conf` — server block (also enforces the protections
  that `.htaccess` files would provide under Apache, e.g. `cache/` is
  never served).
- `files/99-evebb-motd` — login banner with the get-started steps.
- `digitalocean/evebb.pkr.hcl` — Packer build for the DO Marketplace image.

## Using on a plain server (no marketplace)

On a fresh Ubuntu 24.04 server, as root:

```
mkdir -p /tmp/evebb-build
cp files/nginx-evebb.conf files/99-evebb-motd scripts/02-evebb-firstboot.sh /tmp/evebb-build/
bash scripts/01-setup-evebb.sh
bash /var/lib/cloud/scripts/per-instance/01-evebb-firstboot.sh   # or reboot
cat /root/.evebb_credentials
```

Then open `http://<server-ip>/` and follow the installer.

## DigitalOcean Marketplace submission (checklist)

1. **Build the snapshot**
   ```
   export DIGITALOCEAN_TOKEN=<API token with write scope>
   cd deploy/digitalocean
   packer init . && packer build evebb.pkr.hcl
   ```
   The build runs DigitalOcean's own `cleanup.sh` and `img_check.sh`
   (fetched from github.com/digitalocean/marketplace-partners) — the build
   fails if the image wouldn't pass Marketplace validation.
2. **Test the snapshot**: create a droplet from it in the control panel,
   SSH in (MOTD should show the setup steps), open the IP, run through the
   installer, post on the new board.
3. **Submit**: https://cloud.digitalocean.com/vendorportal — create the
   vendor profile (publish as **Alan Paynter / eveBB**), add the app
   listing (name, description, logo, category "Forum/CMS", support links
   to evebb.net and the GitHub issues page), select the snapshot, submit
   for review.
4. After approval, each new eveBB release only needs a rebuilt snapshot
   (`packer build` again — it always pulls the latest release) submitted as
   an image update on the existing listing.

## Notes

- The image downloads `evebb-latest.zip` from the permanent release link
  and verifies the published SHA-256 before unpacking — same integrity
  check the in-app updater performs.
- Outbound email: postfix is installed loopback-only. Cloud providers
  commonly block port 25, so point boards at a real SMTP service via
  Admin → Email once installed.
- After the forum is installed, updates flow through eveBB's own one-click
  updater (Admin → Maintenance); the image never needs to be rebuilt for
  the sake of existing droplets.
