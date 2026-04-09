#!/bin/bash
#
# Build production artifact for WordPress.org submission.
# Excludes dev dependencies, tests, and unnecessary files.
# Auto-detects version from plugin header.
#
# Usage: ./scripts/build-release.sh [version]
#

set -e

PLUGIN_SLUG="sscribe-export-site-pages"
PLUGIN_FILE="sscribe-export-site-pages.php"

# Auto-detect version from plugin header if not provided
if [ -z "$1" ]; then
	VERSION=$(grep -oP 'Version:\s*\K[0-9.]+' "$PLUGIN_FILE" 2>/dev/null || grep -m1 'Version:' "$PLUGIN_FILE" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
	if [ -z "$VERSION" ]; then
		echo "❌ Error: Could not detect version from plugin header."
		exit 1
	fi
	echo "🔍 Auto-detected version: ${VERSION}"
else
	VERSION="$1"
fi

BUILD_DIR="build/${PLUGIN_SLUG}"

echo "🔨 Building ${PLUGIN_SLUG} v${VERSION}..."

# Clean previous builds
rm -rf build/
mkdir -p "${BUILD_DIR}"

# Install dependencies required to build the prefixed vendor tree
echo "📦 Installing build dependencies..."
composer install --optimize-autoloader --no-interaction --no-progress

# Generate the prefixed runtime vendor tree
echo "🧬 Generating prefixed vendor tree..."
composer vendor:prefix

# Build production assets
echo "🎨 Building production assets..."
composer build

# Copy plugin files using the canonical distribution ignore rules
echo "📁 Copying distribution files..."
rsync -av ./ "${BUILD_DIR}/" --exclude-from=.distignore

# Create ZIP archive
echo "📦 Creating ZIP archive..."
cd build
zip -r "${PLUGIN_SLUG}.${VERSION}.zip" "${PLUGIN_SLUG}/"
cd - > /dev/null

echo "✅ Build complete: build/${PLUGIN_SLUG}.${VERSION}.zip"
echo "📊 Size: $(du -sh "build/${PLUGIN_SLUG}.${VERSION}.zip" | cut -f1)"
