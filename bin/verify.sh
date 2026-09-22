#!/usr/bin/env bash
set -e

echo "=== [1/4] Checking Version Consistency ==="
HEADER_VER=$(grep -m 1 -E "^[[:space:]]*\*[[:space:]]*Version:" wc-nova-express.php | awk '{print $NF}')
CONST_VER=$(grep -E "define\([[:space:]]*'NVX_VERSION'" wc-nova-express.php | sed -E "s/.*'NVX_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'.*/\1/")
STABLE_VER=$(grep -m 1 -i "Stable tag:" readme.txt | awk '{print $NF}')

echo "Header:     $HEADER_VER"
echo "Constant:   $CONST_VER"
echo "readme.txt: $STABLE_VER"

if [ -z "$HEADER_VER" ] || [ -z "$CONST_VER" ] || [ -z "$STABLE_VER" ]; then
    echo "ERROR: Version could not be extracted!" >&2
    exit 1
fi

if [ "$HEADER_VER" != "$CONST_VER" ] || [ "$HEADER_VER" != "$STABLE_VER" ]; then
    echo "ERROR: Version mismatch between wc-nova-express.php and readme.txt!" >&2
    exit 1
fi
echo "Version consistency OK."

echo "=== [2/4] Checking PHP Syntax (php -l) ==="
nix-shell -p php84 --run 'find . -type f -name "*.php" -not -path "./vendor/*" -exec php -l {} +'

echo "=== [3/4] Checking JavaScript Syntax (node --check) ==="
node --check assets/js/*.js
echo "JavaScript syntax OK."

echo "=== [4/4] Running PHPUnit Test Suite ==="
if [ ! -f "vendor/bin/phpunit" ]; then
    echo "vendor/bin/phpunit not found. Running composer install..."
    nix-shell -p php84 php84Packages.composer --run 'composer install --no-interaction --prefer-dist --no-progress'
fi
nix-shell -p php84 php84Packages.composer --run 'vendor/bin/phpunit --configuration phpunit.xml.dist'

echo "=== ALL CHECKS PASSED SUCCESSFULLY ==="
