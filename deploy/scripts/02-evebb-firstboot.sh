#!/usr/bin/env bash
#
# eveBB one-click image: first-boot script.
#
# Installed by the build provisioner into
#   /var/lib/cloud/scripts/per-instance/01-evebb-firstboot.sh
# so cloud-init runs it exactly ONCE, on the first boot of each new droplet
# (never again on reboots, and never on the build image itself).
#
# Everything unique to this droplet is minted here: the MariaDB root and
# forum-database passwords. The credentials the user needs for eveBB's web
# installer are written to /root/.evebb_credentials and surfaced in the MOTD.
#
set -euo pipefail

CREDS_FILE="/root/.evebb_credentials"
[ -f "$CREDS_FILE" ] && exit 0    # already provisioned

# NOTE: a naive `tr </dev/urandom | head` dies under pipefail (head's exit
# SIGPIPEs tr). Reading a bounded amount first avoids it.
rand() { head -c 512 /dev/urandom | tr -dc 'A-Za-z0-9' | cut -c1-24; }

DB_NAME="evebb"
DB_USER="evebb"
DB_PASS="$(rand)"
ROOT_PASS="$(rand)"

# MariaDB may still be warming up on first boot
for i in $(seq 1 30); do
    mysqladmin ping >/dev/null 2>&1 && break
    sleep 1
done

mysql <<SQL
-- lock down the defaults (mini mysql_secure_installation)
ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('$ROOT_PASS');
DELETE FROM mysql.global_priv WHERE User='';
DROP DATABASE IF EXISTS test;

-- the forum's database and account
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

umask 077
cat > "$CREDS_FILE" <<EOF
eveBB database credentials (enter these in the web installer)

  Database type:      MySQL
  Database host:      localhost
  Database name:      $DB_NAME
  Database user:      $DB_USER
  Database password:  $DB_PASS

MariaDB root password: $ROOT_PASS

Open http://<this droplet's IP>/ to finish setting up your forum —
the installer creates your admin account and board settings.
After installing, this file can be deleted: rm $CREDS_FILE
EOF

echo "eveBB first-boot provisioning complete."
