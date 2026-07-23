#!/usr/bin/env bash
#
# Assemble the Softaculous package payload for eveBB.
#
# Softaculous expects, in /var/softaculous/evebb/:
#   evebb.zip     - the application files (this script builds it from the
#                   official release package, SHA-256 verified)
#   info.xml      - package metadata (in this directory)
#   install.xml   - install mode (copy mode; in this directory)
#
# Usage: ./make-package.sh [output-dir]
#
set -euo pipefail

OUT="${1:-./dist}"
REL="https://github.com/evebb/EveBB/releases/latest/download/evebb-latest.zip"
SHA="$REL.sha256"

mkdir -p "$OUT"
TMP="$(mktemp -d)"
curl -fsSL -o "$TMP/evebb-latest.zip" "$REL"
curl -fsSL -o "$TMP/evebb-latest.zip.sha256" "$SHA"
( cd "$TMP" && sha256sum -c evebb-latest.zip.sha256 )

# Softaculous wants the payload named after the soft identifier
cp "$TMP/evebb-latest.zip" "$OUT/evebb.zip"
cp evebb/info.xml evebb/install.xml "$OUT/"
rm -rf "$TMP"

echo "Package payload assembled in $OUT:"
ls -la "$OUT"
echo
echo "Copy the contents of $OUT to /var/softaculous/evebb/ on a Softaculous"
echo "installation to test (see README.md)."
