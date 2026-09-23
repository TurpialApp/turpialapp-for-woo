#!/bin/sh
set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="cachicamoapp-for-woo"
BUILD_DIR="$ROOT_DIR/build"
STAGE_DIR="$BUILD_DIR/$SLUG"

rm -rf "$BUILD_DIR"
mkdir -p "$STAGE_DIR"

cd "$ROOT_DIR"
rsync -a --exclude-from=.distignore ./ "$STAGE_DIR/"

cd "$BUILD_DIR"
zip -r -q "$SLUG.zip" "$SLUG"

echo "built $BUILD_DIR/$SLUG.zip"
