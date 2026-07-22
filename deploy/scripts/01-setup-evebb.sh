#!/usr/bin/env bash
#
# eveBB one-click image: build-time provisioner.
#
# Runs ONCE while the image is being built (by Packer or by hand on a build
# droplet). Installs the LEMP stack, downloads the latest eveBB release from
# the permanent GitHub link, verifies its SHA-256 against the published
# checksum, and stages everything so the first-boot script only has to mint
# per-droplet secrets.
#
# Nothing droplet-specific happens here: no passwords, no keys, no host
# names. Anything unique per instance belongs in 02-evebb-firstboot.sh.
#
# Tested on Ubuntu 24.04 LTS.
#
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

EVEBB_WEBROOT="/var/www/evebb"
EVEBB_RELEASE_URL="${EVEBB_RELEASE_URL:-https://github.com/evebb/EveBB/releases/latest/download/evebb-latest.zip}"
EVEBB_SHA_URL="${EVEBB_SHA_URL:-https://github.com/evebb/EveBB/releases/latest/download/evebb-latest.zip.sha256}"

# ---------------------------------------------------------------- packages --
apt-get update -q
apt-get install -qy nginx mariadb-server \
    php-fpm php-mysql php-mbstring php-gd php-curl php-zip php-xml \
    postfix unzip curl ca-certificates

# Postfix: local-only default ("Internet Site" is chosen at build; admins are
# pointed at Admin -> Email to configure real SMTP, since cloud providers
# commonly block outbound port 25 on new accounts).
postconf -e 'inet_interfaces = loopback-only' || true

PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"

# ------------------------------------------------------ fetch + verify eveBB --
TMP="$(mktemp -d)"
curl -fsSL -o "$TMP/evebb-latest.zip"        "$EVEBB_RELEASE_URL"
curl -fsSL -o "$TMP/evebb-latest.zip.sha256" "$EVEBB_SHA_URL"
( cd "$TMP" && sha256sum -c evebb-latest.zip.sha256 )

mkdir -p "$EVEBB_WEBROOT"
unzip -q "$TMP/evebb-latest.zip" -d "$EVEBB_WEBROOT"
# The package unzips as a flat tree; if it produced a single subfolder, flatten it.
if [ ! -f "$EVEBB_WEBROOT/install.php" ]; then
    SUB="$(find "$EVEBB_WEBROOT" -mindepth 1 -maxdepth 1 -type d | head -1)"
    if [ -n "$SUB" ] && [ -f "$SUB/install.php" ]; then
        shopt -s dotglob; mv "$SUB"/* "$EVEBB_WEBROOT"/; shopt -u dotglob
        rmdir "$SUB"
    fi
fi
[ -f "$EVEBB_WEBROOT/install.php" ] || { echo "eveBB package layout unexpected" >&2; exit 1; }

chown -R www-data:www-data "$EVEBB_WEBROOT"
find "$EVEBB_WEBROOT" -type d -exec chmod 755 {} +
find "$EVEBB_WEBROOT" -type f -exec chmod 644 {} +
# Directories the forum writes to
chmod 775 "$EVEBB_WEBROOT/cache" "$EVEBB_WEBROOT/img/avatars" 2>/dev/null || true
rm -rf "$TMP"

# ----------------------------------------------------------------- nginx ----
install -m 644 /tmp/evebb-build/nginx-evebb.conf /etc/nginx/sites-available/evebb
sed -i "s/__PHP_VER__/$PHP_VER/g" /etc/nginx/sites-available/evebb
ln -sf /etc/nginx/sites-available/evebb /etc/nginx/sites-enabled/evebb
rm -f /etc/nginx/sites-enabled/default
nginx -t
# On a live server (cloud-init path) nginx is already running with the
# default site - pick up the eveBB server block. No-op during image builds.
systemctl reload-or-restart nginx >/dev/null 2>&1 || true

# ------------------------------------------------------------- first boot ---
install -m 755 /tmp/evebb-build/02-evebb-firstboot.sh \
    /var/lib/cloud/scripts/per-instance/01-evebb-firstboot.sh

# ------------------------------------------------------------------ MOTD ----
install -m 755 /tmp/evebb-build/99-evebb-motd /etc/update-motd.d/99-evebb-motd

# -------------------------------------------------------------- firewall ----
if command -v ufw >/dev/null; then
    ufw allow OpenSSH
    ufw allow 'Nginx Full'
    ufw --force enable
fi

# Services enabled at boot (started fresh on each droplet)
systemctl enable nginx mariadb "php$PHP_VER-fpm" postfix >/dev/null 2>&1 || true

echo "eveBB image provisioning complete (eveBB staged in $EVEBB_WEBROOT)."
