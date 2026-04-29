#!/usr/bin/env bash
# Verifies CDC_TEST_MODE is correctly short-circuiting consultar-buro.php
# by sending a known payload and checking the response shape + timing.
#
# Usage:
#   ./test-cdc-mode.sh                    # default URL (voltika.mx)
#   ./test-cdc-mode.sh https://your.host  # custom URL
#   CDC_URL=https://... ./test-cdc-mode.sh
#
# Pass criteria (test mode ON):
#   - HTTP 200
#   - "success": true
#   - "test_mode": true
#   - "score": 720
#   - "folioConsulta" starts with "TEST-"
#   - Response time < 1000 ms  (live CDC takes 2-5 s)
#
# Run again after flipping .env CDC_TEST_MODE=0 to verify live mode comes
# back up: test_mode key should disappear and timing should jump.

set -u

URL="${1:-${CDC_URL:-https://voltika.mx/configurador_prueba/php/consultar-buro.php}}"

PAYLOAD='{
  "primerNombre":"JUAN",
  "apellidoPaterno":"PEREZ",
  "apellidoMaterno":"LOPEZ",
  "fechaNacimiento":"1990-01-15",
  "CP":"06600"
}'

echo "================================================================"
echo " CDC test-mode verification"
echo " URL: $URL"
echo "================================================================"
echo

# Send request and time it. We use --write-out to get HTTP status and
# total time without depending on jq. Body goes to a temp file so we can
# parse it after.
TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT

WRITEOUT=$(curl -s -o "$TMP" -X POST "$URL" \
  -H 'Content-Type: application/json' \
  -d "$PAYLOAD" \
  --max-time 30 \
  --write-out 'HTTP=%{http_code} TIME=%{time_total}')

HTTP_CODE=$(echo "$WRITEOUT" | sed -n 's/.*HTTP=\([0-9]*\).*/\1/p')
TIME_S=$(echo "$WRITEOUT" | sed -n 's/.*TIME=\([0-9.]*\).*/\1/p')
TIME_MS=$(awk "BEGIN { printf \"%d\", $TIME_S * 1000 }")
RESPONSE=$(cat "$TMP")

echo "HTTP status : $HTTP_CODE"
echo "Elapsed     : ${TIME_MS} ms"
echo
echo "Response body:"
if command -v jq >/dev/null 2>&1; then
  echo "$RESPONSE" | jq .
else
  echo "$RESPONSE"
fi
echo

# Field extraction without jq dependency.
get_field() {
  # Extract "key": <value>  where value can be string|number|boolean|null
  local key="$1"
  echo "$RESPONSE" | grep -oE "\"$key\"[[:space:]]*:[[:space:]]*(\"[^\"]*\"|[^,}[:space:]]+)" \
    | head -1 \
    | sed -E "s/^\"$key\"[[:space:]]*:[[:space:]]*//; s/^\"//; s/\"$//"
}

SUCCESS=$(get_field "success")
TEST_MODE=$(get_field "test_mode")
SCORE=$(get_field "score")
FOLIO=$(get_field "folioConsulta")

PASS=0
FAIL=0
WARN=0

check_eq() {
  local name="$1" expected="$2" actual="$3"
  if [ "$actual" = "$expected" ]; then
    printf "  [PASS] %-32s = %s\n" "$name" "$actual"
    PASS=$((PASS+1))
  else
    printf "  [FAIL] %-32s expected '%s' got '%s'\n" "$name" "$expected" "$actual"
    FAIL=$((FAIL+1))
  fi
}

echo "Checks:"
check_eq "HTTP 200"        "200"  "$HTTP_CODE"
check_eq "success"         "true" "$SUCCESS"
check_eq "test_mode"       "true" "$TEST_MODE"
check_eq "score"           "720"  "$SCORE"

case "$FOLIO" in
  TEST-*)
    printf "  [PASS] %-32s = %s\n" "folioConsulta starts with TEST-" "$FOLIO"
    PASS=$((PASS+1))
    ;;
  *)
    printf "  [FAIL] %-32s got '%s'\n" "folioConsulta should start with TEST-" "$FOLIO"
    FAIL=$((FAIL+1))
    ;;
esac

if [ "$TIME_MS" -lt 1000 ]; then
  printf "  [PASS] %-32s = %s ms\n" "response time < 1000 ms" "$TIME_MS"
  PASS=$((PASS+1))
elif [ "$TIME_MS" -lt 2000 ]; then
  printf "  [WARN] %-32s = %s ms (slow but plausible)\n" "response time" "$TIME_MS"
  WARN=$((WARN+1))
else
  printf "  [FAIL] %-32s = %s ms (live CDC speed — short-circuit not active)\n" "response time" "$TIME_MS"
  FAIL=$((FAIL+1))
fi

echo
echo "----------------------------------------------------------------"
if [ "$FAIL" -eq 0 ]; then
  echo " RESULT: CDC_TEST_MODE is ACTIVE — $PASS checks passed${WARN:+, $WARN warnings}."
  echo "----------------------------------------------------------------"
  exit 0
else
  echo " RESULT: CDC_TEST_MODE is NOT working — $FAIL failed, $PASS passed."
  echo "----------------------------------------------------------------"
  echo
  echo " Likely causes:"
  echo "  1. .env on the server is missing the line:    CDC_TEST_MODE=1"
  echo "  2. .env file is at the wrong path (config.php"
  echo "     expects ../.env relative to configurador_prueba/php/)"
  echo "  3. The uploaded consultar-buro.php is the OLD version"
  echo "     (search for the string 'CDC test mode' inside it — should"
  echo "      appear near line 101)"
  echo "  4. PHP opcache is serving the stale file. Workaround: touch"
  echo "     consultar-buro.php on the server, or restart php-fpm."
  echo
  exit 1
fi
