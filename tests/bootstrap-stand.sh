#!/usr/bin/env bash
# Bring the local WordPress stand to a known state: core installed, plugin active,
# Plugin Check available, demo data seeded. Idempotent.
#
#   bash tests/bootstrap-stand.sh
#
# Overridable: STAND_URL, STAND_ADMIN_USER, STAND_ADMIN_PASSWORD, STAND_ADMIN_EMAIL.
set -euo pipefail

dir="$(cd "$(dirname "$0")" && pwd)"
cd "$dir" || exit 1

COMPOSE_FILE="docker-compose.stand.yml"
WP_CONTAINER="${WP_CONTAINER:-mdvrm-stand-wp-1}"
CLI_CONTAINER="${CLI_CONTAINER:-mdvrm-stand-wpcli-1}"
STAND_URL="${STAND_URL:-http://localhost:8490}"
STAND_ADMIN_USER="${STAND_ADMIN_USER:-stand}"
STAND_ADMIN_PASSWORD="${STAND_ADMIN_PASSWORD:-stand-local-dev}"
STAND_ADMIN_EMAIL="${STAND_ADMIN_EMAIL:-stand@mudrava.local}"

wp() {
	docker compose -f "$COMPOSE_FILE" exec -T wpcli wp --allow-root "$@"
}

echo "==> Starting containers"
docker compose -f "$COMPOSE_FILE" up -d

echo "==> Waiting for WP-CLI to reach the database"
for _ in $(seq 1 60); do
	if wp core is-installed >/dev/null 2>&1 || wp core version >/dev/null 2>&1; then
		break
	fi
	sleep 2
done

if ! wp core is-installed >/dev/null 2>&1; then
	echo "==> Installing WordPress at $STAND_URL"
	wp core install \
		--url="$STAND_URL" \
		--title="Mudrava RUM Stand" \
		--admin_user="$STAND_ADMIN_USER" \
		--admin_password="$STAND_ADMIN_PASSWORD" \
		--admin_email="$STAND_ADMIN_EMAIL"
else
	echo "==> WordPress already installed"
fi

echo "==> Activating Mudrava RUM"
wp plugin activate mudrava-rum >/dev/null
wp plugin status mudrava-rum

echo "==> Making wp-cli available inside the web container (tests/run.sh)"
mkdir -p "$dir/../.tools"
if [ ! -s "$dir/../.tools/wp-cli.phar" ]; then
	curl -fsSL -o "$dir/../.tools/wp-cli.phar" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
	chmod +x "$dir/../.tools/wp-cli.phar"
fi

echo "==> Installing Plugin Check"
if ! wp plugin is-installed plugin-check >/dev/null 2>&1; then
	wp plugin install plugin-check --activate
else
	wp plugin activate plugin-check >/dev/null
fi
wp plugin list --status=active --fields=name,version

echo "==> Permalinks and site identity"
wp rewrite structure '/%postname%/' >/dev/null
wp option update blogdescription "Mudrava RUM integration stand" >/dev/null
wp user create editor stand-editor@mudrava.local --role=editor --first_name=Stand --last_name=Editor --porcelain >/dev/null 2>&1 || true

echo "==> Seeding demo traffic"
wp eval-file /var/www/dev/tests/seed.php >/dev/null 2>&1 || true

echo "==> Smoke"
curl -fsS -o /dev/null -w 'site %{http_code}\n' "$STAND_URL/"
curl -fsS -o /dev/null -w 'rest %{http_code}\n' "$STAND_URL/wp-json/"
echo "Admin: $STAND_URL/wp-admin  $STAND_ADMIN_USER / $STAND_ADMIN_PASSWORD"
echo "Mailpit: http://localhost:8491"
