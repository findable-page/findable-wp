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
	grep '[0-9]+\.[0-9]+\.[0-9]+' /tmp/wp-latest.json
	WP_VERSION=$(grep -o '"version":"[0-9]*\.[0-9]*\.[0-9]*"' /tmp/wp-latest.json | head -1 | sed 's/"version":"//;s/"//')
	WP_TESTS_TAG="tags/$WP_VERSION"
fi

set -ex

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
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
	if [ -d "$WP_TESTS_DIR" ]; then
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
	php -r "
	\$pdo = new PDO( 'mysql:host=' . getenv( 'DB_HOST' ) . ';charset=utf8', getenv( 'DB_USER' ), getenv( 'DB_PASS' ) );
	\$pdo->exec( 'CREATE DATABASE IF NOT EXISTS ' . getenv( 'DB_NAME' ) );
	"
}

install_wp
install_test_suite
install_db
