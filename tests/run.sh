#!/usr/bin/env bash
# Integration test runner. Usage: WP_CONTAINER=mdvrm-stand-wp-1 bash tests/run.sh
set -u

CONTAINER="${WP_CONTAINER:-mdvrm-stand-wp-1}"
WP_PATH="${WP_PATH:-/var/www/html}"
dir="$(cd "$(dirname "$0")/.." && pwd)"
cd "$dir" || exit 1

# Ensure WP-CLI phar exists inside the container (writable layer is lost on recreate).
if ! docker exec "$CONTAINER" test -x /usr/local/bin/wp 2>/dev/null; then
	docker cp "$dir/.tools/wp-cli.phar" "$CONTAINER:/usr/local/bin/wp"
	docker exec -u root "$CONTAINER" sh -c 'chmod +x /usr/local/bin/wp'
fi

docker exec "$CONTAINER" mkdir -p "$WP_PATH/wp-content/mu-plugins"
docker cp tests/mu-plugin/hook-order-probe.php "$CONTAINER:$WP_PATH/wp-content/mu-plugins/hook-order-probe.php"

overall=0
for f in tests/[0-9]*.php; do
	printf '=== %s ===\n' "$f"
	out="$(docker exec -w "$WP_PATH" "$CONTAINER" wp eval-file "/var/www/dev/${f}" --allow-root 2>&1)"
	rc=$?
	echo "$out" | grep -E "FAIL|Fatal|Warning|Deprecated" || true
	if [ $rc -eq 0 ]; then
		printf 'SUITE PASS %s\n' "$f"
	else
		printf 'SUITE FAIL %s (rc=%d)\n' "$f" "$rc"
		echo "$out" | tail -20
		overall=1
	fi
done

if [ $overall -eq 0 ]; then
	echo "ALL SUITES PASSED"
fi

# Purge tests wipe the logs table; restore demo data so the admin UI stays usable.
docker exec -w "$WP_PATH" "$CONTAINER" wp eval-file "/var/www/dev/tests/seed.php" --allow-root >/dev/null 2>&1 || true

exit $overall
