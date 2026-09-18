#!/usr/bin/env bash
#
# Thin backward-compatible wrapper. Canonical implementation:
#   scripts/release-audit.php
#
# Usage: bash bin/release-audit.sh
#
exec php "$(cd "$(dirname "$0")/.." && pwd)/scripts/release-audit.php" "$@"
