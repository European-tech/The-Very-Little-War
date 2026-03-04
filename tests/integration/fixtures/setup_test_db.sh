#!/bin/bash
# Creates and populates the TVLW test database.
# Usage: ./tests/integration/fixtures/setup_test_db.sh [mysql_user] [mysql_pass]

set -e
MYSQL_USER="${1:-tvlw_test}"
MYSQL_PASS="${2:-tvlw_test_password}"
DB_NAME="tvlw_test"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/../../.." && pwd)"

echo "=== TVLW Test Database Setup ==="

echo "1. Creating database..."
mysql -u root -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME CHARACTER SET latin1 COLLATE latin1_swedish_ci;" 2>/dev/null || {
    echo "ERROR: Cannot connect as root. Try: sudo mysql -u root -e \"CREATE DATABASE $DB_NAME;\""
    exit 1
}

echo "2. Creating user..."
mysql -u root -e "CREATE USER IF NOT EXISTS '$MYSQL_USER'@'localhost' IDENTIFIED BY '$MYSQL_PASS'; GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$MYSQL_USER'@'localhost'; FLUSH PRIVILEGES;" 2>/dev/null || true

echo "3. Loading schema..."
mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$DB_NAME" < "$SCRIPT_DIR/base_schema.sql"

echo "4. Applying migrations..."
for f in "$PROJECT_DIR/migrations/"*.sql; do
    if [ -f "$f" ]; then
        echo "   $(basename "$f")"
        mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$DB_NAME" < "$f" 2>/dev/null || true
    fi
done

echo "5. Loading seed data..."
if [ -f "$SCRIPT_DIR/seed_players.sql" ]; then
    mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$DB_NAME" < "$SCRIPT_DIR/seed_players.sql"
fi

echo ""
echo "Test database '$DB_NAME' is ready!"
echo "Run integration tests: vendor/bin/phpunit -c phpunit-integration.xml -v"
