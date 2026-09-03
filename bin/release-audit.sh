#!/usr/bin/env bash
#
# release-audit.sh
#
# Single-pass gate that runs every CI check in the order they appear in
# the v2.0.0 release hardening plan. Used by `bin/release-audit.sh` as
# well as a manual smoke for any contributor who wants to know "is this
# branch shippable?" in one command.
#
# Exits 0 only if all gates pass. Prints a summary table on success.
#
# Usage: bash bin/release-audit.sh
#
# Each gate is intentionally independent — a failure in one does NOT
# short-circuit the run, so the operator can see all problems at once.
set -u

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

PASS=0
FAIL=0
declare -a GATES

run_gate () {
  local name="$1"
  shift
  local start_ns end_ns status elapsed
  start_ns=$(date +%s%N)
  if "$@"; then
    status="PASS"
    PASS=$((PASS + 1))
  else
    status="FAIL"
    FAIL=$((FAIL + 1))
  fi
  end_ns=$(date +%s%N)
  elapsed=$(( (end_ns - start_ns) / 1000000 ))
  GATES+=("$(printf '%-10s %sms %s' "$status" "${elapsed}" "$name")")
  printf '%s %-32s %6sms\n' "$status" "$name" "${elapsed}"
}

echo "== PHPUnit (full suite) =="
run_gate "PHPUnit"       vendor/bin/phpunit >/tmp/release-audit-phpunit.log 2>&1

echo "== Acceptance matrix (Phase 58) =="
run_gate "Acceptance-Matrix" composer test:acceptance-matrix >/tmp/release-audit-acceptance-matrix.log 2>&1

echo "== Debug log acceptance (Phase 59) =="
run_gate "Debug-Log" composer test:debug-log >/tmp/release-audit-debug-log.log 2>&1

echo "== No-internal-details acceptance (Phase 60) =="
run_gate "No-Internal-Details" composer test:no-internal-details >/tmp/release-audit-no-internal-details.log 2>&1

echo "== CI docs acceptance (Phase 61) =="
run_gate "CI-Docs" composer test:ci-docs >/tmp/release-audit-ci-docs.log 2>&1

echo "== Build-order acceptance (Phase 62) =="
run_gate "Build-Order" composer test:build-order >/tmp/release-audit-build-order.log 2>&1

echo "== Exact-package clean-install acceptance (Phase 63) =="
run_gate "Exact-Package-Clean-Install" composer test:exact-package-clean-install >/tmp/release-audit-exact-package-clean-install.log 2>&1

echo "== Plugin Check triage acceptance (Phase 64) =="
run_gate "Plugin-Check-Triage" composer test:plugin-check-triage >/tmp/release-audit-plugin-check-triage.log 2>&1

echo "== JS error-free acceptance (Phase 65) =="
run_gate "JS-Error-Free" composer test:js-error-free >/tmp/release-audit-js-error-free.log 2>&1

echo "== AJAX network trace acceptance (Phase 66) =="
run_gate "AJAX-Network-Trace" composer test:ajax-network-trace >/tmp/release-audit-ajax-network-trace.log 2>&1

echo "== UI refactor discipline acceptance (Phase 67) =="
run_gate "UI-Refactor-Discipline" composer test:ui-refactor-discipline >/tmp/release-audit-ui-refactor-discipline.log 2>&1

echo "== Phase 68 test coverage acceptance (Phase 68) =="
run_gate "Phase-68-Test-Coverage" composer test:phase-68-test-coverage >/tmp/release-audit-phase-68-test-coverage.log 2>&1

echo "== Manual runtime tests acceptance (Phase 69) =="
run_gate "Manual-Runtime-Tests" composer test:manual-runtime-tests >/tmp/release-audit-manual-runtime-tests.log 2>&1

echo "== Release blockers acceptance (Phase 70) =="
run_gate "Release-Blockers" composer test:release-blockers >/tmp/release-audit-release-blockers.log 2>&1

echo "== Final CI state acceptance (Phase 71) =="
run_gate "Final-CI-State" composer test:final-ci-state >/tmp/release-audit-final-ci-state.log 2>&1

echo "== Exact artifact evidence acceptance (Phase 72) =="
run_gate "Exact-Artifact-Evidence" composer test:exact-artifact-evidence >/tmp/release-audit-exact-artifact-evidence.log 2>&1

echo "== Agent final report acceptance (Phase 73) =="
run_gate "Agent-Final-Report" composer test:agent-final-report >/tmp/release-audit-agent-final-report.log 2>&1

echo "== Auditor handoff acceptance (Phase 74) =="
run_gate "Auditor-Handoff" composer test:auditor-handoff >/tmp/release-audit-auditor-handoff.log 2>&1

echo "== PHPStan =="
run_gate "PHPStan-level-7" vendor/bin/phpstan analyse --memory-limit=1G --no-progress >/tmp/release-audit-phpstan.log 2>&1

echo "== PHPCS =="
run_gate "PHPCS"          vendor/bin/phpcs --standard=phpcs.xml >/tmp/release-audit-phpcs.log 2>&1

echo "== ESLint + Stylelint =="
run_gate "ESLint+Stylelint" npm run lint >/tmp/release-audit-eslint.log 2>&1

echo "== ZIP artifact certification =="
run_gate "Artifact-Cert"  bash -c "vendor/bin/phpunit --filter=SScribe_Artifact_Certification_Test --testsuite=Unit >/dev/null 2>&1"

echo "== Plugin-Check (full WP instance) =="
if [ -d /c/tmp/wp-a11y/wp-content/plugins/plugin-check ]; then
  run_gate "Plugin-Check" bash -c "cd /c/tmp/wp-a11y && WP_CLI_PHP_ARGS='-d extension=pdo_sqlite -d extension=sqlite3' /c/Users/Ahmed/AppData/Roaming/Composer/vendor/bin/wp plugin check sscribe-export-site-pages --allow-root >/tmp/release-audit-plugincheck.log 2>&1"
else
  GATES+=("SKIP       plugin-check testbench missing")
  echo "SKIP plugin-check testbench missing"
fi

echo
echo "== Release-Audit summary =="
for g in "${GATES[@]}"; do
  echo "  $g"
done
echo
echo "Pass: $PASS"
echo "Fail: $FAIL"
echo

if [ "$FAIL" -ne 0 ]; then
  echo "Latest logs:"
  echo "  /tmp/release-audit-phpunit.log"
  echo "  /tmp/release-audit-phpstan.log"
  echo "  /tmp/release-audit-phpcs.log"
  echo "  /tmp/release-audit-eslint.log"
  echo "  /tmp/release-audit-plugincheck.log"
  echo "  /tmp/release-audit-build-order.log"
  echo "  /tmp/release-audit-exact-package-clean-install.log"
  echo "  /tmp/release-audit-plugin-check-triage.log"
  echo "  /tmp/release-audit-js-error-free.log"
  echo "  /tmp/release-audit-ajax-network-trace.log"
  echo "  /tmp/release-audit-ui-refactor-discipline.log"
  echo "  /tmp/release-audit-phase-68-test-coverage.log"
  echo "  /tmp/release-audit-manual-runtime-tests.log"
  echo "  /tmp/release-audit-release-blockers.log"
  echo "  /tmp/release-audit-final-ci-state.log"
  echo "  /tmp/release-audit-exact-artifact-evidence.log"
  echo "  /tmp/release-audit-agent-final-report.log"
  echo "  /tmp/release-audit-auditor-handoff.log"
  exit 1
fi

echo "All gates green."
