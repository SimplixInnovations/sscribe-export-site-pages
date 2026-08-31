#!/usr/bin/env bash
#
# Install a real WordPress testbench for SScribe plugin tests.
#
# Downloads WordPress core + the WordPress PHPUnit test suite, configures
# them under tests-wp/_wordpress/ and tests-wp/_wordpress-tests-lib/, and
# drops in the SQLite Database Integration plugin so the test DB runs
# without MySQL/MariaDB.
#
# Usage:
#   bin/install-wp-tests.sh [--sqlite] [--version <wp-version>]
#
# Env vars:
#   WP_TESTS_DIR    Override tests-wp/_wordpress-tests-lib path
#   WP_CORE_DIR     Override tests-wp/_wordpress path
#   WP_VERSION      WordPress version (default: latest)
#   SKIP_NETWORK    If set, skip the network install (WP multisite)
#

set -e

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_DIR="${PLUGIN_DIR}/tests-wp"
WP_CORE_DIR="${WP_CORE_DIR:-${TEST_DIR}/_wordpress}"
WP_TESTS_DIR="${WP_TESTS_DIR:-${TEST_DIR}/_wordpress-tests-lib}"
CACHE_DIR="${TEST_DIR}/.cache"
SQLITE=0
WP_VERSION="${WP_VERSION:-latest}"

for arg in "$@"; do
	case "$arg" in
		--sqlite)
			SQLITE=1
			;;
		--version)
			WP_VERSION="${2:-latest}"
			shift
			;;
		--help|-h)
			echo "Usage: bin/install-wp-tests.sh [--sqlite] [--version <version>]"
			exit 0
			;;
	esac
done

mkdir -p "${TEST_DIR}" "${CACHE_DIR}" "${WP_CORE_DIR}" "${WP_TESTS_DIR}"

log() { printf '[install-wp-tests] %s\n' "$*"; }

# 1. WordPress core -----------------------------------------------------------------

WP_VERSION="${WP_VERSION:-latest}"

if [ "${WP_VERSION}" = "latest" ] || [ -z "${WP_VERSION}" ]; then
	# Resolve the current WP version via the official version-check API. The
	# response is JSON; the first offer's "current" field is the latest stable.
	log "Resolving latest WordPress version from wordpress.org..."
	WP_VERSION="$(curl -fsSL 'https://api.wordpress.org/core/version-check/1.7/' \
		| grep -oE '"current":"[^"]+"' \
		| head -n1 \
		| sed -E 's/.*"([^"]+)".*/\1/')"
	if [ -z "${WP_VERSION}" ] || [ "${WP_VERSION}" = "latest" ]; then
		echo "ERROR: could not resolve latest WordPress version from wordpress.org API" >&2
		exit 1
	fi
fi

log "Downloading WordPress ${WP_VERSION}..."
WP_TARBALL="${CACHE_DIR}/wordpress-${WP_VERSION}.tar.gz"
if [ ! -f "${WP_TARBALL}" ]; then
	curl -fsSL -o "${WP_TARBALL}" \
		"https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
fi

log "Extracting WordPress into ${WP_CORE_DIR}..."
tar -xzf "${WP_TARBALL}" -C "${CACHE_DIR}"
SRC_DIR="${CACHE_DIR}/wordpress"
if [ ! -d "${SRC_DIR}" ]; then
	echo "ERROR: extracted archive does not contain 'wordpress' directory" >&2
	exit 1
fi
# Windows-friendly: make sure every extracted entry is writable so mv works.
chmod -R u+w "${SRC_DIR}" 2>/dev/null || true
shopt -s dotglob nullglob
for entry in "${SRC_DIR}"/*; do
	name="$(basename "${entry}")"
	if [ -e "${WP_CORE_DIR}/${name}" ]; then continue; fi
	mv "${entry}" "${WP_CORE_DIR}/${name}" 2>/dev/null || cp -R "${entry}" "${WP_CORE_DIR}/${name}"
done
shopt -u dotglob nullglob
rm -rf "${SRC_DIR}"

# 2. WordPress test suite -----------------------------------------------------------

log "Downloading WordPress test suite from wp-phpunit/wp-phpunit..."
TEST_SUITE_REPO="https://github.com/wp-phpunit/wp-phpunit.git"
TEST_SUITE_DIR="${CACHE_DIR}/wp-phpunit"
if [ ! -d "${TEST_SUITE_DIR}/includes" ]; then
	rm -rf "${TEST_SUITE_DIR}"
	git clone --depth 1 "${TEST_SUITE_REPO}" "${TEST_SUITE_DIR}"
fi
if [ ! -d "${TEST_SUITE_DIR}/includes" ]; then
	echo "ERROR: wp-phpunit clone has no includes/ directory" >&2
	exit 1
fi

# Copy wp-phpunit contents into WP_TESTS_DIR.
log "Copying test suite into ${WP_TESTS_DIR}..."
rm -rf "${WP_TESTS_DIR}"
mkdir -p "${WP_TESTS_DIR}"
if command -v rsync >/dev/null 2>&1; then
	rsync -a --exclude='.git' --exclude='composer.json' "${TEST_SUITE_DIR}/" "${WP_TESTS_DIR}/"
else
	cp -R "${TEST_SUITE_DIR}/." "${WP_TESTS_DIR}/"
fi
rm -f "${WP_TESTS_DIR}/composer.json" "${WP_TESTS_DIR}/README.md"
rm -rf "${TEST_SUITE_DIR}/.git"
# Free disk: remove the .git directory we no longer need.
rm -rf "${TEST_SUITE_DIR}/.git"

# 3. wp-tests-config.php -----------------------------------------------------------

CONFIG_TEMPLATE="${PLUGIN_DIR}/tests-wp/wp-tests-config.php"
if [ ! -f "${CONFIG_TEMPLATE}" ]; then
	echo "ERROR: missing ${CONFIG_TEMPLATE}" >&2
	exit 1
fi

CONFIG_TARGET="${WP_TESTS_DIR}/wp-tests-config.php"
log "Writing wp-tests-config.php..."
cp "${CONFIG_TEMPLATE}" "${CONFIG_TARGET}"

# 4. SQLite drop-in -----------------------------------------------------------------

if [ "${SQLITE}" = "1" ]; then
	log "Installing SQLite Database Integration drop-in..."
	SQLITE_DIR="${WP_CORE_DIR}/wp-content/plugins/sqlite-database-integration"
	if [ ! -d "${SQLITE_DIR}" ]; then
		SQLITE_ZIP="${CACHE_DIR}/sqlite-database-integration.zip"
		if [ ! -f "${SQLITE_ZIP}" ]; then
			# Resolve latest release download URL via GitHub API.
			SQLITE_URL="$(curl -fsSL \
				'https://api.github.com/repos/WordPress/sqlite-database-integration/releases/latest' \
				| grep -E '"browser_download_url":\s*"[^"]+\.zip"' \
				| head -n1 \
				| sed -E 's/.*"([^"]+)".*/\1/')"
			if [ -z "${SQLITE_URL}" ]; then
				echo "ERROR: could not resolve SQLite Database Integration release URL" >&2
				exit 1
			fi
			curl -fsSL -o "${SQLITE_ZIP}" "${SQLITE_URL}"
		fi
		unzip -q -o "${SQLITE_ZIP}" -d "${CACHE_DIR}/sqlite-extract"
		# The release zip's root folder has changed names across releases:
		# "sqlite-database-integration", "plugin-sqlite-database-integration",
		# and occasionally a versioned name. Pick the first directory that
		# contains db.copy.
		EXTRACTED="$(find "${CACHE_DIR}/sqlite-extract" -mindepth 2 -maxdepth 3 -type f -name 'db.copy' -print | head -n1 | xargs -I{} dirname {})"
		if [ -z "${EXTRACTED}" ]; then
			echo "ERROR: could not find db.copy in SQLite Database Integration release zip" >&2
			exit 1
		fi
		mkdir -p "${WP_CORE_DIR}/wp-content/plugins"
		mv "${EXTRACTED}" "${SQLITE_DIR}"
	fi
	# Drop-in db.php is inside the plugin; copy it to wp-content/db.php.
	if [ -f "${SQLITE_DIR}/db.copy" ]; then
		cp "${SQLITE_DIR}/db.copy" "${WP_CORE_DIR}/wp-content/db.php"
	elif [ -f "${SQLITE_DIR}/db.php" ]; then
		cp "${SQLITE_DIR}/db.php" "${WP_CORE_DIR}/wp-content/db.php"
	else
		echo "ERROR: SQLite Database Integration plugin does not ship db.copy or db.php" >&2
		exit 1
	fi
	log "SQLite drop-in installed at wp-content/db.php"
fi

# 5. Summary ------------------------------------------------------------------------

log "WordPress testbench installed."
log "  WP core:       ${WP_CORE_DIR}"
log "  WP tests lib:  ${WP_TESTS_DIR}"
log "  Test config:   ${CONFIG_TARGET}"
if [ "${SQLITE}" = "1" ]; then
	log "  SQLite drop-in: ${WP_CORE_DIR}/wp-content/db.php"
	log "  Run tests with: php -d extension=sqlite3 -d extension=pdo_sqlite vendor/bin/phpunit --testsuite=WordPress"
else
	log "  Run tests with: vendor/bin/phpunit --testsuite=WordPress (configure wp-tests-config.php DB credentials first)"
fi