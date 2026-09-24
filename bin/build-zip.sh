#!/bin/sh
set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="cachicamoapp-for-woo"
BUILD_DIR="$ROOT_DIR/build"
STAGE_DIR="$BUILD_DIR/$SLUG"

cd "$ROOT_DIR"

composer install --no-dev -o

if [ -f package.json ]; then
	npm run build
fi

for LOCALE in es_VE es_MX es_AR es_CO; do
	for SRC in languages/"$SLUG"-es_ES.po languages/"$SLUG"-es_ES.mo languages/"$SLUG"-es_ES-*.json; do
		[ -f "$SRC" ] || continue
		DEST="$(echo "$SRC" | sed "s/-es_ES/-$LOCALE/")"
		cp "$SRC" "$DEST"
	done
done

rm -rf "$BUILD_DIR"
mkdir -p "$STAGE_DIR"

rsync -a --exclude-from=.distignore ./ "$STAGE_DIR/"

cd "$BUILD_DIR"
zip -r -q "$SLUG.zip" "$SLUG"

echo "built $BUILD_DIR/$SLUG.zip"
