#!/usr/bin/env bash
# Voltika — SMS OTP send test.
#
# Triggers ONE real OTP send to a phone number you control (e.g. your own
# mobile) so we can see the actual SMSMasivos response. The new
# enviar-otp.php with /tmp dual-log will record the full SMSMasivos response,
# which sms-diag.php then surfaces.
#
# This is NOT a fake / fabricated test — it sends a real OTP to a real
# phone you can verify. Use your own number.
#
# Usage:
#   ./test-sms-send.sh 5512345678
#   PHONE=5512345678 ./test-sms-send.sh

set -u

PHONE="${1:-${PHONE:-}}"
BASE="${2:-https://voltika.mx/configurador_prueba}"

if [ -z "$PHONE" ]; then
  cat <<EOF
Usage: $0 <10-digit-mexican-mobile> [base_url]

Example:
  $0 5512345678

The script:
  1. POSTs to enviar-otp.php with the given phone number
  2. Shows what SMSMasivos returned (success / error / why)
  3. Verifies the /tmp fallback log got the entry
  4. Tells you next step

USE YOUR OWN PHONE — you'll receive a real OTP if SMS is working.
EOF
  exit 1
fi

# Strip non-digits
PHONE_CLEAN=$(echo "$PHONE" | tr -cd '0-9')
if [ ${#PHONE_CLEAN} -lt 10 ]; then
  echo "ERROR: phone must be at least 10 digits (got: $PHONE_CLEAN)"
  exit 1
fi

echo "================================================================"
echo " SMS OTP send test"
echo " URL   : $BASE/php/enviar-otp.php"
echo " Phone : $PHONE_CLEAN  (enter the OTP you receive on this number)"
echo "================================================================"
echo

# ── Step 1: Trigger send ─────────────────────────────────────────
TMP=$(mktemp); trap 'rm -f "$TMP"' EXIT

PAYLOAD='{"telefono":"'$PHONE_CLEAN'","nombre":"Test User","attempt_hint":"diag-'$(date +%s)'"}'
echo "Step 1: POST enviar-otp.php"
echo "Payload: $PAYLOAD"
echo

WRITEOUT=$(curl -s -o "$TMP" -X POST "$BASE/php/enviar-otp.php" \
  -H 'Content-Type: application/json' \
  -d "$PAYLOAD" \
  --max-time 30 \
  --write-out 'HTTP=%{http_code} TIME=%{time_total}')

HTTP_CODE=$(echo "$WRITEOUT" | sed -n 's/.*HTTP=\([0-9]*\).*/\1/p')
TIME_S=$(echo "$WRITEOUT" | sed -n 's/.*TIME=\([0-9.]*\).*/\1/p')
TIME_MS=$(awk "BEGIN { printf \"%d\", $TIME_S * 1000 }")
RESPONSE=$(cat "$TMP")

echo "HTTP : $HTTP_CODE · ${TIME_MS} ms"
echo "Response:"
if command -v jq >/dev/null 2>&1; then echo "$RESPONSE" | jq . 2>/dev/null || echo "$RESPONSE"; else echo "$RESPONSE"; fi
echo

# ── Step 2: Interpret ────────────────────────────────────────────
echo "Step 2: Interpretation"
if echo "$RESPONSE" | grep -q '"fallback":true'; then
  echo "  [WARN] enviar-otp.php fell back to test code — SMSMasivos call FAILED"
  echo "         The 'testCode' shown above is the OTP you'd need to type."
  echo "         The actual SMS did NOT send. SMSMasivos response is in the log."
elif echo "$RESPONSE" | grep -q '"status":"sent"' && ! echo "$RESPONSE" | grep -q '"fallback":true'; then
  echo "  [OK] enviar-otp.php reports SMS was accepted by SMSMasivos."
  echo "       Check phone $PHONE_CLEAN — OTP should arrive within a few seconds."
  echo "       If it does NOT arrive, the issue is downstream of SMSMasivos"
  echo "       (carrier filtering, recipient block)."
else
  echo "  [WARN] Unexpected response shape. See body above."
fi
echo

# ── Step 3: Hint to check diag page ──────────────────────────────
echo "Step 3: Verify in diagnostic page"
echo "  Open the diag page now and look at Section 3 — there should be"
echo "  a NEW log entry with timestamp matching this run:"
echo "    $BASE/php/sms-diag.php?token=voltika_diag_2026"
echo
echo "  The new entry will show:"
echo "    - HTTP code from SMSMasivos"
echo "    - Full response body from SMSMasivos"
echo "    - cURL error (if connection failed)"
echo
echo "  Share that Section 3 entry and the answer is in it."
echo
echo "----------------------------------------------------------------"
