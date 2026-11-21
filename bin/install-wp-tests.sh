#!/bin/bash
# Installs WordPress (the nightly build), the WordPress test library and Multisite Language Switcher (MSLS), for
# PHPUnit on GitHub (.github/workflows/ci.yml) or locally.
# Usage: bin/install-wp-tests.sh <folder>
# Environment: DB_NAME, DB_USER, DB_PASSWORD, DB_HOST; WP_DEVELOP_REF (wordpress-develop branch, default trunk:
# trunk is 7.2 until WordPress creates its 7.2 branch, around RC; then use 7.2 and the 7.2 nightly); MSLS_REF (MSLS
# commit or tag on GitHub, default a tested master build; use the release tag once 3.1.0 ships).
set -euo pipefail
dir=$1
bin=$(cd "$(dirname "$0")" && pwd)
ref=${WP_DEVELOP_REF:-trunk}
msls_ref=${MSLS_REF:-871eb096b82a1a7b6ebb6b076fe4fe01a1a17b0f}
mkdir -p "$dir"
cd "$dir"

curl -fsSL -o wordpress.zip https://wordpress.org/nightly-builds/wordpress-latest.zip
unzip -q -o wordpress.zip

git clone --quiet --depth 1 --filter=blob:none --sparse --branch "$ref" https://github.com/WordPress/wordpress-develop.git develop
git -C develop sparse-checkout set tests/phpunit/includes tests/phpunit/data src/wp-content/themes/twentytwentyone
# A classic theme for the tests (the nightly ships block themes only).
cp -r develop/src/wp-content/themes/twentytwentyone wordpress/wp-content/themes/

# MSLS from its GitHub repository: PHP only (its class autoloader, no dependency needed at run time).
MSLS_REF=$msls_ref bash "$bin/get-msls.sh" msls

sed -e "s#^define( 'ABSPATH'.*#define( 'ABSPATH', '$dir/wordpress/' );#" \
	-e "s/youremptytestdbnamehere/${DB_NAME}/" \
	-e "s/yourusernamehere/${DB_USER}/" \
	-e "s/yourpasswordhere/${DB_PASSWORD}/" \
	-e "s|localhost|${DB_HOST}|" \
	develop/wp-tests-config-sample.php > wp-tests-config.php

echo "MSLS $(grep -m1 -oP "Version: \K.*" msls/MultisiteLanguageSwitcher.php) ($msls_ref)"
echo "WordPress $(grep -oP "wp_version = '\K[^']+" wordpress/wp-includes/version.php), test library wordpress-develop $ref $(git -C develop rev-parse --short HEAD)"
