#!/usr/bin/env bash
# Voltika — Truora status.php verification script.
#
# Tests whether the truora-status.php API fallback + account_id writeback
# fix is actually working on the server. Use this when a customer test
# gets stuck on "Verificando datos…" — pass the process_id and the script
# tells you in seconds whether status.php is delivering the verdict.
#
# Usage:
#   ./test-truora-status.sh IDP1234567890abcdef
#   PROCESS_ID=IDP1234... ./test-truora-status.sh
#   ./test-truora-status.sh IDP123 https://staging.voltika.mx/configurador_prueba/php/truora-status.php
#
# How to find a process_id:
#   1. Open the diag page in your browser:
#      https://voltika.mx/configurador_prueba/php/truora-diag-pipeline.php?token=voltika_diag_2026
#   2. Look at Section 4 ("truora_fetch_log") — most recent rows show process_id
#   3. OR Section 1 ("verificaciones_identidad") — truora_process_id column
#      (after the fix is working, this column will be populated)

set -u

PROCESS_ID="${1:-${PROCESS_ID:-}}"
URL="${2:-https://voltika.mx/configurador_prueba/php/truora-status.php}"

if [ -z "$PROCESS_ID" ]; then
  cat <<EOF
Usage: $0 <process_id> [status_url]

Example:
  $0 IDP9767abc123def456

Or set env var:
  PROCESS_ID=IDP9767abc... $0
EOF
  exit 1
fi

echo "================================================================"
echo " Truora status.php verification"
echo " URL        : $URL"
echo " process_id : $PROCESS_ID"
echo "================================================================"
echo

# ── Single call ──────────────────────────────────────────────────
TMP=$(mktemp); trap 'rm -f "$TMP"' EXIT

WRITEOUT=$(curl -s -o "$TMP" \
  --get "$URL" \
  --data-urlencode "process_id=$PROCESS_ID" \
  --max-time 30 \
  --write-out 'HTTP=%{http_code} TIME=%{time_total}')

HTTP_CODE=$(echo "$WRITEOUT" | sed -n 's/.*HTTP=\([0-9]*\).*/\1/p')
TIME_S=$(echo "$WRITEOUT" | sed -n 's/.*TIME=\([0-9.]*\).*/\1/p')
TIME_MS=$(awk "BEGIN { printf \"%d\", $TIME_S * 1000 }")
RESPONSE=$(cat "$TMP")

echo "HTTP : $HTTP_CODE · ${TIME_MS} ms"
echo
echo "Response body:"
if command -v jq >/dev/null 2>&1; then
  echo "$RESPONSE" | jq . 2>/dev/null || echo "$RESPONSE"
else
  echo "$RESPONSE"
fi
echo

# ── Field extraction (no-jq fallback) ─────────────────────────────
get_field() {
  local key="$1"
  echo "$RESPONSE" | grep -oE "\"$key\"[[:space:]]*:[[:space:]]*(\"[^\"]*\"|null|[^,}[:space:]]+)" \
    | head -1 \
    | sed -E "s/^\"$key\"[[:space:]]*:[[:space:]]*//; s/^\"//; s/\"$//"
}

OK_FIELD=$(get_field "ok")
APPROVED=$(get_field "approved")
STATUS=$(get_field "status")
CURP_MATCH=$(get_field "curp_match")
NAME_MATCH=$(get_field "name_match")
DECLINED=$(get_field "declined_reason")
SOURCE=$(get_field "source")
HINT=$(get_field "hint")

# ── Diagnosis ─────────────────────────────────────────────────────
echo "Field check:"
printf "  %-22s = %s\n" "ok"              "$OK_FIELD"
printf "  %-22s = %s\n" "approved"        "$APPROVED"
printf "  %-22s = %s\n" "status"          "$STATUS"
printf "  %-22s = %s\n" "curp_match"      "$CURP_MATCH"
printf "  %-22s = %s\n" "name_match"      "$NAME_MATCH"
printf "  %-22s = %s\n" "declined_reason" "$DECLINED"
printf "  %-22s = %s\n" "source"          "$SOURCE"
[ -n "$HINT" ] && printf "  %-22s = %s\n" "hint" "$HINT"

echo
echo "----------------------------------------------------------------"

# ── Verdict ───────────────────────────────────────────────────────
if [ "$HTTP_CODE" != "200" ]; then
  echo " RESULT: HTTP $HTTP_CODE — endpoint failed."
  echo "         The status.php endpoint returned a non-200 response."
  echo "         Check server PHP logs for stack trace."
  echo "----------------------------------------------------------------"
  exit 1
fi

if [ "$APPROVED" = "null" ] || [ -z "$APPROVED" ]; then
  if [ "$STATUS" = "pending" ]; then
    echo " RESULT: VERDICT NOT YET AVAILABLE"
    echo "         status.php returned approved=null (pending). Possible reasons:"
    echo "           - process_id is fresh — Truora's backend hasn't completed"
    echo "             yet. Re-run the script in 5 s. Verdict typically arrives"
    echo "             within 1-3 s of Truora's UI showing 'completed'."
    echo "           - status.php fix is NOT installed on the server: no API"
    echo "             fallback writeback happens, so approved stays null forever."
    echo "             To verify: check the diag page Section 1 for this process_id —"
    echo "             if truora_process_id column is empty, the fix isn't live."
  else
    echo " RESULT: UNEXPECTED — approved=null but status='$STATUS'"
    echo "         status.php is returning data but not classifying as approved/refused."
  fi
elif [ "$APPROVED" = "1" ]; then
  if [ "$NAME_MATCH" = "0" ] || [ "$CURP_MATCH" = "0" ]; then
    echo " RESULT: INCONSISTENT — approved=1 but name_match=$NAME_MATCH curp_match=$CURP_MATCH"
    echo "         This shouldn't happen; investigate the row in DB."
  else
    echo " RESULT: APPROVED ✓ — verdict delivered, fields populated."
    echo "         The SPA should advance to credito-enganche."
  fi
elif [ "$APPROVED" = "0" ]; then
  echo " RESULT: REFUSED ✓ — verdict delivered."
  echo "         declined_reason: $DECLINED"
  if [ "$NAME_MATCH" = "0" ]; then
    echo "         → Name mismatch. SPA should route to credito-nombre."
  fi
  if [ "$CURP_MATCH" = "0" ]; then
    echo "         → CURP mismatch. SPA should route to credito-nombre."
  fi
  echo "         status.php fix IS WORKING — verdict was persisted."
else
  echo " RESULT: UNRECOGNIZED — approved=$APPROVED"
fi

echo "----------------------------------------------------------------"
echo
echo "Run with --watch to poll every 4 s for up to 1 minute"
echo "(simulates what the SPA does)."
