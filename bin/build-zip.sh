#!/bin/bash
# Builds the installable zip dist/<slug>.zip with a <slug>/ root folder (never GitHub's source archive, whose folder
# name differs). Everything except what .distignore lists. Checks that the Version header, the version constant and
# (when given) the tag agree.
# Usage: bin/build-zip.sh [vX.Y.Z]
set -euo pipefail
cd "$(dirname "$0")/.."
slug=$(basename "$PWD")
main="$slug.php"
version=$(grep -m1 -E '^[[:space:]]*\*[[:space:]]*Version:' "$main" | awk '{print $NF}')
constant=$(grep -m1 -E "define\( '[A-Z_]+_VERSION'" "$main" | sed -E "s/.*, '([^']+)'.*/\1/")
if [ "$version" != "$constant" ]; then
	echo "Version header ($version) and version constant ($constant) differ." >&2
	exit 1
fi
if [ "${1:-}" != "" ] && [ "v$version" != "$1" ]; then
	echo "Tag $1 does not match the Version header ($version)." >&2
	exit 1
fi
[ -f build/network.js ] || { echo "build/ is missing: run npm run build first." >&2; exit 1; }
# Every PHP file of build/ stops when opened directly (bin/guard-build.mjs runs after npm run build).
unguarded=$(grep -rL --include=*.php "defined( 'ABSPATH' )" build 2>/dev/null || true)
[ -z "$unguarded" ] || { echo "Build files without the direct access guard (run npm run build): $unguarded" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
sed -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' -e 's#^/*#/#' .distignore > "$work/exclude.txt"
mkdir -p "$work/$slug" dist
rsync -a --exclude-from="$work/exclude.txt" ./ "$work/$slug/"
rm -f "dist/$slug.zip"
(cd "$work" && zip -qrX "$OLDPWD/dist/$slug.zip" "$slug")
echo "dist/$slug.zip ($version): $(unzip -Z1 "dist/$slug.zip" | wc -l) entries, sha256 $(sha256sum "dist/$slug.zip" | cut -d' ' -f1)"
