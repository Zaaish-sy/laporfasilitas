#!/usr/bin/env bash
# Menjalankan SEMUA pengecekan. Dari akar repo: bash tools/run-tests.sh
# Butuh: php 8.3 + ext pdo_pgsql + mbstring, PostgreSQL, python3 (pyyaml, openapi-spec-validator).
# Database uji: LAPOR_TEST_DSN / LAPOR_TEST_USER / LAPOR_TEST_PASS (lihat core/tests/DbHarness.php). Tes membuat dan menghapus skema sementara sendiri.
set -u
fail=0
run() { echo "== $1"; shift; "$@" || fail=1; }
run "OpenAPI = SQL = PHP"            python3 tools/check_parity.py
run "State machine"                  php core/tests/StateMachineTest.php
run "AuthService (PostgreSQL)"       php core/tests/AuthServiceDbTest.php
run "ReportTransitions (PostgreSQL)" php core/tests/ReportTransitionsDbTest.php
[ $fail -eq 0 ] && echo "SEMUA LULUS" || { echo "ADA YANG GAGAL"; exit 1; }
