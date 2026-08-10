#!/usr/bin/env bash
#
# Wire-level test for board email transport.
#
# Sends one notification through the real pun_mail()/smtp_mail() into a
# throwaway SMTP sink and asserts, against the bytes that actually went over
# the socket, that:
#   - no line breaches the SMTP line limits, even with a 5000-character
#     unbroken URL pasted into the post
#   - the transfer encoding is advertised to match the encoded body
#   - the post arrives byte-identical: UTF-8, trailing spaces, leading dots
#     and equals signs all intact
#
# The lesson this encodes (eveBB Hosted, 2026-07-25): tests that inspect a
# message before it is sent cannot catch a transport limit. Only the wire can.
#
# Needs no database and no web server.
#
#   bash tests/e2e/mail-test.sh
#
set -u

PORT="${MAIL_PORT:-2526}"

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TMP="$(mktemp -d)"
CAPTURE="$TMP/captured.txt"
EXPECTED="$TMP/post.txt"
SINK_LOG="$TMP/sink.log"
READY="$TMP/ready"

cleanup() {
  [ -n "${SINK_PID:-}" ] && kill "$SINK_PID" 2>/dev/null
  rm -rf "$TMP"
}
trap cleanup EXIT

php "$ROOT/tests/e2e/mail/sink.php" "$PORT" "$CAPTURE" "$READY" >"$SINK_LOG" 2>&1 &
SINK_PID=$!

# Wait for the sink to be listening. Do NOT probe the port: the sink serves a
# single client, so a probe connection would be the one it accepts.
for i in $(seq 1 25); do
  [ -f "$READY" ] && break
  sleep 0.2
done

if [ ! -f "$READY" ]; then
  echo "  FAIL: the SMTP sink never came up on port $PORT"
  sed 's/^/    /' "$SINK_LOG"
  echo "== mail transport: 0 passed, 1 failed =="
  exit 1
fi

if ! php "$ROOT/tests/e2e/mail/send.php" "$PORT" "$EXPECTED" >"$TMP/send.log" 2>&1; then
  echo "  FAIL: pun_mail() errored while sending"
  sed 's/^/    /' "$TMP/send.log"
  echo "== mail transport: 0 passed, 1 failed =="
  exit 1
fi

# Let the sink finish writing the capture
wait "$SINK_PID" 2>/dev/null
SINK_PID=""

php "$ROOT/tests/e2e/mail/check.php" "$CAPTURE" "$EXPECTED"
