#!/usr/bin/env bash
# Replace the local SQLite database with a copy of production (PostgreSQL).
# Opens an SSH tunnel to the server's Postgres, migrates a fresh local SQLite DB and copies every row.
# Override the defaults with env vars, e.g. REMOTE_HOST=other ./pull-prod-db.sh
set -euo pipefail

REMOTE_HOST=${REMOTE_HOST:-ksse}
REMOTE_APP=${REMOTE_APP:-/home/forge/snab.orkana8.net/current}
TUNNEL_PORT=${TUNNEL_PORT:-54329}
LOCAL_DB=database/database.sqlite

cd "$(dirname "$0")"

# Production DB credentials, read from the server's .env (never stored locally)
PROD_ENV=$(ssh "$REMOTE_HOST" "grep -E '^DB_' '$REMOTE_APP/.env'")
if ! grep -q '^DB_CONNECTION=pgsql' <<<"$PROD_ENV"; then
    echo "Production is not on pgsql — this script only handles PostgreSQL." >&2
    exit 1
fi
export PROD_ENV TUNNEL_PORT

ssh -N -o ExitOnForwardFailure=yes -L "$TUNNEL_PORT:127.0.0.1:5432" "$REMOTE_HOST" &
TUNNEL_PID=$!
trap 'kill $TUNNEL_PID 2>/dev/null || true' EXIT
for _ in $(seq 50); do nc -z 127.0.0.1 "$TUNNEL_PORT" 2>/dev/null && break; sleep 0.2; done

if [ -f "$LOCAL_DB" ]; then
    backup="database/database.$(date +%Y%m%d-%H%M%S).sqlite"
    cp "$LOCAL_DB" "$backup"
    echo "Local DB backed up to $backup"
fi

rm -f "$LOCAL_DB" "$LOCAL_DB-wal" "$LOCAL_DB-shm"
touch "$LOCAL_DB"
php artisan migrate --force --no-interaction --quiet

php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$env = Dotenv\Dotenv::parse(getenv('PROD_ENV'));
config(['database.connections.prod' => [
    'driver' => 'pgsql',
    'host' => '127.0.0.1',
    'port' => getenv('TUNNEL_PORT'),
    'database' => $env['DB_DATABASE'] ?? 'laravel',
    'username' => $env['DB_USERNAME'] ?? 'root',
    'password' => $env['DB_PASSWORD'] ?? '',
    'charset' => 'utf8',
    'prefix' => '',
    'search_path' => 'public',
    'sslmode' => 'prefer',
]]);

// Framework tables that are either recreated by migrate or only make sense on the server
$skip = ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

$prod = DB::connection('prod');
$local = DB::connection('sqlite');
$local->getSchemaBuilder()->disableForeignKeyConstraints();

$tables = array_column($prod->getSchemaBuilder()->getTables('public'), 'name');
foreach ($tables as $table) {
    if (in_array($table, $skip, true)) {
        continue;
    }
    if (! Schema::connection('sqlite')->hasTable($table)) {
        echo "  skipped {$table} (no such local table — run git pull?)\n";
        continue;
    }

    // Only copy columns both sides know, so a local branch with extra/removed columns still works
    $columns = array_values(array_intersect(
        $prod->getSchemaBuilder()->getColumnListing($table),
        $local->getSchemaBuilder()->getColumnListing($table),
    ));

    $count = 0;
    $query = $prod->table($table)->select($columns);
    $order = in_array('id', $columns, true) ? 'id' : $columns[0];
    $query->orderBy($order)->chunk(500, function ($rows) use ($local, $table, &$count) {
        $local->table($table)->insert($rows->map(fn ($row) => array_map(
            fn ($v) => is_resource($v) ? stream_get_contents($v) : (is_bool($v) ? (int) $v : $v),
            (array) $row,
        ))->all());
        $count += $rows->count();
    });
    echo "  {$table}: {$count}\n";
}

$local->getSchemaBuilder()->enableForeignKeyConstraints();
PHP

echo "Production DB copied to $LOCAL_DB"
