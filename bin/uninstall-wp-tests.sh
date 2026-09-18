#!/usr/bin/env bash
#
# Thin backward-compatible wrapper. Canonical implementation:
#   scripts/uninstall-wp-tests.php
#
exec php "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/scripts/uninstall-wp-tests.php" "$@"
