#!/usr/bin/env sh
# Point honkio/honkio-php at ../php (same commit) instead of Packagist.
# Idempotent. Modifies composer.json locally: do not commit that change.
set -eu
cd "$(dirname "$0")/.."
composer config repositories.honkio-php '{"type":"path","url":"../php","options":{"symlink":true,"versions":{"honkio/honkio-php":"0.1.0"}}}'
