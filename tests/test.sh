#!/usr/bin/env bash

# Capture the absolute path of tests/ and the package root (1 level up)

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PACKAGE_ROOT="$(dirname "$SCRIPT_DIR")"

echo "[*] Package root detected at: $PACKAGE_ROOT"

# 1. Create a secure temporary directory
TEMP_DIR=$(mktemp -d -t tarantool_ephemeral_XXXXXX)
echo "[*] Created ephemeral sandbox at: $TEMP_DIR"

# Ensure cleanup kills the background tarantool process and deletes the temp dir
cleanup() {
    echo "[*] Stopping ephemeral Tarantool instance and cleaning up..."
    if [ ! -z "$TARANTOOL_PID" ]; then
        kill "$TARANTOOL_PID" > /dev/null 2>&1 || true
    fi
    rm -rf "$TEMP_DIR"
    echo "[*] Sandbox destroyed. Clean slate restored."
}
trap cleanup EXIT

cd "$TEMP_DIR"

# 2. Spin up an isolated local Tarantool instance and grant testuser super privileges
echo "[*] Booting isolated ephemeral Tarantool instance on port 3311..."
tarantool -e "
box.cfg{
    listen = 3311,
    work_dir = '$TEMP_DIR',
    vinyl_dir = '$TEMP_DIR',
    memtx_dir = '$TEMP_DIR',
    wal_dir = '$TEMP_DIR',
    background = false
}
if not box.schema.user.exists('testuser') then
    box.schema.user.create('testuser', {password = 'testpassword'})
end
box.schema.user.grant('testuser', 'super', nil, nil, {if_not_exists = true})
" > "$TEMP_DIR/tarantool.log" 2>&1 &

TARANTOOL_PID=$!
sleep 2 # Give Tarantool a moment to initialize

# 3. Initialize a throwaway Composer project and allow plugins automatically
echo "[*] Initializing temporary Composer project..."
composer init --no-interaction --stability=dev --name="test/tarantool-ephemeral" > /dev/null 2>&1
composer config allow-plugins true > /dev/null 2>&1

# Configure path repository pointing to package root with explicit symlink disabling
composer config repositories.local '{"type": "path", "url": "'"$PACKAGE_ROOT"'", "options": {"symlink": false}}'

echo "[*] Installing required packages..."
composer require --dev --no-interaction illuminate/database "pestphp/pest:^2.0|^3.0" "local/laravel-tarantool-nosql:*"

# 4. Create a clean, minimal phpunit.xml
echo "[*] Creating phpunit.xml..."
cat << 'EOF' > phpunit.xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit colors="true">
    <testsuites>
        <testsuite name="Ephemeral Test Suite">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
EOF

# 5. Copy permanent tests directory into the ephemeral sandbox workspace
echo "[*] Copying package tests into sandbox..."
cp -r "$SCRIPT_DIR" "$TEMP_DIR/tests"

# 6. Run Pest cleanly across all split files
echo "[*] Executing Pest test suite..."
vendor/bin/pest