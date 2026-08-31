#!/usr/bin/env bash
#
# Remove the WordPress testbench scaffolded by bin/install-wp-tests.sh.
#
set -e

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_DIR="${PLUGIN_DIR}/tests-wp"

rm -rf "${TEST_DIR}/_wordpress"
rm -rf "${TEST_DIR}/_wordpress-tests-lib"
rm -rf "${TEST_DIR}/.cache"

echo "[uninstall-wp-tests] Removed WordPress core, test suite, and download cache under ${TEST_DIR}."
echo "[uninstall-wp-tests] Bootstrap, config, and test files were left in place."