#!/usr/bin/env bash
# Install the WordPress test suite for PHPUnit. Standard scaffold script.
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]

set -e

if [ $# -lt 3 ]; then
	echo "Usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
	exit 1
fi

DB_NAME="$1"
DB_USER="$2"
DB_PASS="$3"
DB_HOST="${4-localhost}"
WP_VERSION="${5-latest}"
SKIP_DB_CREATE="${6-false}"
TMPDIR="${TMPDIR-/tmp}"
TMPDIR="$(echo "$TMPDIR" | sed -e 's/\/$//')"
WP_TESTS_DIR="${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR-$TMPDIR/wordpress}"

download() {
	if [ "$(which curl)" ]; then
		curl -sL "$1" > "$2"
	elif [ "$(which wget)" ]; then
		wget -nv -O "$2" "$1"
	fi
}

if [[ "$WP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ "$WP_VERSION" =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
	WP_TESTS_TAG="tags/$WP_VERSION"
elif [[ "$WP_VERSION" == 'trunk' ]]; then
	WP_TESTS_TAG='trunk'
else
	# Latest stable.
	download "https://api.wordpress.org/core/version-check/1.7/" /tmp/wp-latest.json
	WP_VERSION=$(grep -o '"version":"[0-9]*\.[0-9]*\.[0-9]*"' /tmp/wp-latest.json | head -1 | sed 's/"version":"//;s/"//')
	WP_TESTS_TAG="tags/$WP_VERSION"
fi

set -ex

install_wp() {
	if [ -f "$WP_CORE_DIR/wp-load.php" ]; then
		return
	fi
	mkdir -p "$WP_CORE_DIR"
	if [ "$WP_VERSION" = 'trunk' ]; then
		svn export --quiet "https://develop.svn.wordpress.org/trunk/src/" "$WP_CORE_DIR"
	else
		download "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" /tmp/wordpress.tar.gz
		tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
	fi
}

install_test_suite() {
	if [ -f "$WP_TESTS_DIR/includes/bootstrap.php" ]; then
		return
	fi
	mkdir -p "$WP_TESTS_DIR"
	svn export --quiet "https://develop.svn.wordpress.org/$WP_TESTS_TAG/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
	svn export --quiet "https://develop.svn.wordpress.org/$WP_TESTS_TAG/tests/phpunit/data/" "$WP_TESTS_DIR/data"
}

install_db() {
	if [ "$SKIP_DB_CREATE" = 'true' ]; then
		return
	fi
	DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASS="$DB_PASS" DB_HOST="$DB_HOST" php -r "
	\$pdo = new PDO( 'mysql:host=' . getenv( 'DB_HOST' ) . ';charset=utf8', getenv( 'DB_USER' ), getenv( 'DB_PASS' ) );
	\$pdo->exec( 'CREATE DATABASE IF NOT EXISTS ' . getenv( 'DB_NAME' ) );
	"
}

install_config() {
	if [ -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
		return
	fi
	cat > "$WP_TESTS_DIR/wp-tests-config.php" <<CONFIG
<?php
define( 'ABSPATH', '$WP_CORE_DIR/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
define( 'DB_NAME', '$DB_NAME' );
define( 'DB_USER', '$DB_USER' );
define( 'DB_PASSWORD', '$DB_PASS' );
define( 'DB_HOST', '$DB_HOST' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'AUTH_KEY', 'findable-test' );
define( 'SECURE_AUTH_KEY', 'findable-test' );
define( 'LOGGED_IN_KEY', 'findable-test' );
define( 'NONCE_KEY', 'findable-test' );
define( 'AUTH_SALT', 'findable-test' );
define( 'SECURE_AUTH_SALT', 'findable-test' );
define( 'LOGGED_IN_SALT', 'findable-test' );
define( 'NONCE_SALT', 'findable-test' );
\$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
CONFIG
}

install_wp
install_test_suite
install_config
install_db
