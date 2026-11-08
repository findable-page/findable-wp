<?php
/**
 * Regenerate data/fix-advice-en.php from the findable-page web bundle.
 *
 * Usage: php bin/sync-fix-advice.php [path-to-findable-page]
 *
 * Reads the fixAdvice object literal from web/index.html and writes the
 * bundled English advice map. Run before each release; the result is
 * committed so CI never fetches it live.
 *
 * @package findable-wp
 */

$repo = isset( $argv[1] ) ? $argv[1] : dirname( __DIR__ ) . '/../findable-page';
$file = $repo . '/web/index.html';

if ( ! file_exists( $file ) ) {
	fwrite( STDERR, "Not found: $file (pass the findable-page checkout path)\n" );
	exit( 1 );
}

$html = file_get_contents( $file );
if ( ! preg_match( '/const\s+fixAdvice\s*=\s*\{(.*?)\n\s*\};/s', $html, $m ) ) {
	fwrite( STDERR, "fixAdvice block not found in web/index.html\n" );
	exit( 1 );
}

preg_match_all( '/([a-z0-9_]+)\s*:\s*([\'"])((?:\\\\.|(?!\2).)*)\2/', $m[1], $pairs, PREG_SET_ORDER );
if ( empty( $pairs ) ) {
	fwrite( STDERR, "No advice entries parsed\n" );
	exit( 1 );
}

$map = array();
foreach ( $pairs as $pair ) {
	$map[ $pair[1] ] = stripslashes( $pair[3] );
}

$php = "<?php\n/**\n * Bundled English fix advice, keyed by check id.\n *\n * Source: https://findable.page web UI (findable-page/web/index.html fixAdvice),\n * MIT licensed, GPL-compatible. Regenerate with `php bin/sync-fix-advice.php`.\n *\n * @package findable-wp\n */\n\ndefined( 'ABSPATH' ) || exit;\n\nreturn array(\n";
foreach ( $map as $id => $text ) {
	$php .= "\t'" . $id . "' => " . var_export( $text, true ) . ",\n";
}
$php .= ");\n";

file_put_contents( dirname( __DIR__ ) . '/data/fix-advice-en.php', $php );
echo 'Wrote ' . count( $map ) . " advice entries.\n";
