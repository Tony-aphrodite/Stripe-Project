#!/usr/bin/env bash
# Master runner — ejecuta toda la suite y reporta resultado consolidado.
set -u
cd "$(dirname "$0")"

total=0
passed=0
failed=0
failures=()

run_case() {
  local label="$1"
  shift
  total=$((total+1))
  echo ""
  echo "════════════════════════════════════════════════════════════════"
  echo "  TEST $total: $label"
  echo "════════════════════════════════════════════════════════════════"
  if "$@"; then
    passed=$((passed+1))
    echo "  → ✓ PASS"
  else
    failed=$((failed+1))
    failures+=("$label")
    echo "  → ✗ FAIL"
  fi
}

run_case "01 Syntax check (PHP + JS)"   bash 01-syntax-check.sh
run_case "02 Static analysis (16 bugs)" bash 02-static-analysis.sh
run_case "03 Bug 1.1 — engine number"   php  03-bug-1-1-engine.php
run_case "04 Bug 2.1 — date validation" php  04-bug-2-1-fechas.php
run_case "05 Bug 5.2 — OTP email skip"  php  05-bug-5-2-otp.php

echo ""
echo "════════════════════════════════════════════════════════════════"
echo "  RESUMEN"
echo "════════════════════════════════════════════════════════════════"
echo "  Total:    $total"
echo "  Pasaron:  $passed"
echo "  Fallaron: $failed"
if [ $failed -gt 0 ]; then
  echo ""
  echo "  Fallas:"
  for f in "${failures[@]}"; do echo "    - $f"; done
  exit 1
fi
echo ""
echo "  ✓ TODA LA SUITE PASÓ"
