<?php
/**
 * Fail when line coverage in a PHPUnit clover report is below a threshold.
 *
 * Usage: php bin/check-coverage.php coverage/clover.xml,coverage/clover-constants.xml 95 [--missing]
 *
 * @package findable-wp
 */

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php bin/check-coverage.php <clover.xml> <min-percent> [--missing]\n" );
	exit( 2 );
}

// Union of every clover report (comma separated): a line is covered if any run hit it.
$lines = array();
foreach ( explode( ',', $argv[1] ) as $report ) {
	$xml = simplexml_load_file( $report );
	if ( false === $xml ) {
		fwrite( STDERR, "Cannot read {$report}\n" );
		exit( 2 );
	}
	foreach ( $xml->xpath( '//file' ) as $file ) {
		$name = basename( (string) $file['name'] );
		foreach ( $file->line as $line ) {
			if ( 'stmt' === (string) $line['type'] ) {
				$key           = (int) $line['num'];
				$lines[ $name ][ $key ] = max( $lines[ $name ][ $key ] ?? 0, (int) $line['count'] );
			}
		}
	}
}

$total   = 0;
$covered = 0;
foreach ( $lines as $name => $rows ) {
	$missing = array();
	foreach ( $rows as $num => $count ) {
		++$total;
		if ( $count > 0 ) {
			++$covered;
		} else {
			$missing[] = $num;
		}
	}
	if ( $missing && in_array( '--missing', $argv, true ) ) {
		echo $name, ': ', implode( ',', $missing ), "\n";
	}
}

$percent = $total ? round( 100 * $covered / $total, 2 ) : 0;
echo "Line coverage: {$percent}% ({$covered}/{$total}), required {$argv[2]}%\n";
exit( $percent >= (float) $argv[2] ? 0 : 1 );
