#!/usr/bin/env bash
# Write src/app/config/database.php for a CI run.
#
# The file is gitignored and excluded from deploy.sh (see rsync-excludes), so a
# fresh checkout has none. This one reads the same env vars the compose stack's
# php container gets, so it can only ever point at the throwaway service DB.
set -euo pipefail
cd "$(dirname "$0")/../../src"
mkdir -p app/config
cat > app/config/database.php <<'PHP'
<?php
// CI only: written by .github/scripts/ci-db-config.sh, never committed.
return [
    'host'     => getenv('DB_HOST') ?: '127.0.0.1',
    'port'     => getenv('DB_PORT') ?: '3306',
    'dbname'   => getenv('DB_NAME') ?: 'rpg_5e',
    'username' => getenv('DB_USERNAME') ?: 'web',
    'password' => getenv('DB_PASSWORD') ?: 'devpassword',
    'charset'  => 'utf8mb4',
];
PHP
echo "wrote app/config/database.php (env-driven, CI service DB)"
