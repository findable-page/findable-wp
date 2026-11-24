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

if [[ "$WP_VERSION" == 'latest' ]]; then
	download "https://api.wordpress.org/core/version-check/1.7/" /tmp/wp-latest.json
	WP_VERSION=$(grep -o '"version":"[0-9][0-9.]*"' /tmp/wp-latest.json | head -1 | sed 's/"version":"//;s/"//')
	if [ -z "$WP_VERSION" ]; then
		echo "Could not determine the latest WordPress version."
		exit 1
	fi
fi

# wordpress-develop git ref holding tests/phpunit: trunk, an x.y branch or an x.y.z tag.
WP_TESTS_REF="$WP_VERSION"

set -ex

install_wp() {
	if [ -f "$WP_CORE_DIR/wp-load.php" ]; then
		return
	fi
	mkdir -p "$WP_CORE_DIR"
	if [ "$WP_VERSION" = 'trunk' ]; then
		download "https://wordpress.org/nightly-builds/wordpress-latest.zip" /tmp/wordpress-nightly.zip
		unzip -q /tmp/wordpress-nightly.zip -d /tmp/wordpress-nightly
		mv /tmp/wordpress-nightly/wordpress/* "$WP_CORE_DIR"
	else
		download "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" /tmp/wordpress.tar.gz
		tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
	fi
}

install_test_suite() {
	if [ ! -f "$WP_TESTS_DIR/includes/bootstrap.php" ]; then
		# GitHub runners no longer ship svn; take tests/phpunit from the git mirror.
		local checkout
		checkout="$(mktemp -d)"
		git clone --quiet --depth 1 --branch "$WP_TESTS_REF" --filter=blob:none --sparse \
			https://github.com/WordPress/wordpress-develop.git "$checkout"
		git -C "$checkout" sparse-checkout set tests/phpunit/includes tests/phpunit/data
		mkdir -p "$WP_TESTS_DIR"
		cp -R "$checkout/tests/phpunit/includes" "$checkout/tests/phpunit/data" "$WP_TESTS_DIR/"
		cp "$checkout/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config-sample.php"
		rm -rf "$checkout"
	fi

	if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
		sed -e "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" \
			-e "s:__DIR__ . '/src/':'$WP_CORE_DIR/':" \
			-e "s/youremptytestdbnamehere/$DB_NAME/" \
			-e "s/yourusernamehere/$DB_USER/" \
			-e "s/yourpasswordhere/$DB_PASS/" \
			-e "s|localhost|${DB_HOST}|" \
			"$WP_TESTS_DIR/wp-tests-config-sample.php" > "$WP_TESTS_DIR/wp-tests-config.php"
	fi
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

install_wp
install_test_suite
install_db
