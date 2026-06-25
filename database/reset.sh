#!/usr/bin/env bash
# Drops and recreates the local dev database, then applies all migrations in order.
# Requires the Docker MySQL container to be running: docker compose up -d
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MIGRATIONS_DIR="$SCRIPT_DIR/migrations"

DB_NAME="balonkydecor"
DB_USER="balonky"
DB_PASS="balonky"

MYSQL="docker exec balonkydecor_db mysql -u$DB_USER -p$DB_PASS"

echo "Resetting database '$DB_NAME'..."

$MYSQL -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

for file in "$MIGRATIONS_DIR"/V*.sql; do
    version=$(basename "$file" .sql)
    echo "  Applying $version..."
    docker exec -i balonkydecor_db mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$file"
    $MYSQL "$DB_NAME" -e "INSERT INTO schema_migrations (version) VALUES ('$version');"
done

echo "Done."
