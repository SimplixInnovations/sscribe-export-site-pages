#!/usr/bin/env bash
#
# Thin backward-compatible wrapper. Canonical implementation:
#   scripts/install-wp-tests.php
#
# Usage:
#   bin/install-wp-tests.sh [--sqlite] [--version <wp-version>]
#
exec php "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/scripts/install-wp-tests.php" "$@"
