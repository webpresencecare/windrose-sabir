#!/bin/bash
# Downloads Multisite Language Switcher (MSLS) from its GitHub repository into a folder: PHP only, with its class
# autoloader (no dependency needed at run time). Used by bin/install-wp-tests.sh (PHPUnit) and by CI for PHPStan.
# Usage: bin/get-msls.sh <folder>
# Environment: MSLS_REF (commit or tag; default a tested master build, the release tag once 3.1.0 ships).
set -euo pipefail
dir=$1
ref=${MSLS_REF:-871eb096b82a1a7b6ebb6b076fe4fe01a1a17b0f}
mkdir -p "$dir"
curl -fsSL "https://github.com/lloc/Multisite-Language-Switcher/archive/$ref.tar.gz" | tar -xz --strip-components=1 -C "$dir"
composer dump-autoload --working-dir="$dir" --no-dev --no-plugins --no-scripts --quiet
