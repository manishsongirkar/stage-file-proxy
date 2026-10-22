<?php
/**
 * Shared helpers for the Stage File Proxy test scripts.
 *
 * Small assertion functions and a pair of helpers that start PHP built-in
 * servers and send requests to them. Loaded by run-tests.php and
 * run-config-tests.php.
 *
 * @package stage-file-proxy
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit;
}

const SFP_TEST_PROD = 'https://prod.example.com';

$GLOBALS['sfp_pass']   = 0;
$GLOBALS['sfp_fail']   = array();
$GLOBALS['sfp_errors'] = array();

set_error_handler(
	function ( $no, $str, $file, $line ) {
		$GLOBALS['sfp_errors'][] = "[$no] $str @ " . basename( $file ) . ":$line";
		return true;
	}
);

/**
 * Record one test result.
 *
 * @param string $name   Test name.
 * @param bool   $passed Whether it passed.
 * @param mixed  $detail Extra information shown on failure.
 */
function sfp_check( $name, $passed, $detail = null ) {
	if ( $passed ) {
		++$GLOBALS['sfp_pass'];
		return;
	}
	$GLOBALS['sfp_fail'][] = $name . ( null === $detail ? '' : ' => ' . wp_json_encode( $detail ) );
}

/**
 * Check that a call returns the expected value without throwing.
 *
 * @param string   $name     Test name.
 * @param mixed    $expected Expected value.
 * @param callable $fn       Code under test.
 */
function sfp_equals( $name, $expected, $fn ) {
	try {
		$actual = $fn();
	} catch ( Throwable $e ) {
		sfp_check( $name, false, 'threw ' . get_class( $e ) . ': ' . $e->getMessage() );
		return;
	}
	sfp_check( $name, $expected === $actual, array( 'expected' => $expected, 'actual' => $actual ) );
}

/**
 * Start a PHP built-in server in the background.
 *
 * @param string $docroot Document root.
 * @param string $router  Optional router script.
 * @return array{proc: resource, port: int}
 */
function sfp_start_server( $docroot, $router = '' ) {
	$sock = stream_socket_server( 'tcp://127.0.0.1:0' );
	$port = (int) substr( strrchr( stream_socket_get_name( $sock, false ), ':' ), 1 );
	fclose( $sock );

	// display_errors off: notices printed by other code (themes, mu-plugins) would end up in the response and send the headers early.
	$cmd  = escapeshellarg( PHP_BINARY ) . ' -d display_errors=0 -d mysqli.default_socket=' . escapeshellarg( (string) ini_get( 'mysqli.default_socket' ) );
	$cmd .= ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg( $docroot ) . ( $router ? ' ' . escapeshellarg( $router ) : '' );
	$proc = proc_open( $cmd, array( array( 'file', '/dev/null', 'r' ), array( 'file', '/dev/null', 'w' ), array( 'file', '/dev/null', 'w' ) ), $pipes );

	for ( $i = 0; $i < 50; $i++ ) {
		if ( @fsockopen( '127.0.0.1', $port ) ) {
			break;
		}
		usleep( 100000 );
	}
	return array( 'proc' => $proc, 'port' => $port );
}

/**
 * Send a request without following redirects.
 *
 * @param int    $port    Server port.
 * @param string $uri     Request URI.
 * @param array  $headers Extra request headers.
 * @return array{status: int, headers: array, body: string}
 */
function sfp_request( $port, $uri, $headers = array() ) {
	$ctx = stream_context_create(
		array(
			'http' => array(
				'method'          => 'GET',
				'follow_location' => 0,
				'ignore_errors'   => true,
				'timeout'         => 60,
				'header'          => array_merge( preg_grep( '/^Host:/i', $headers ) ? array() : array( 'Host: ' . ( $GLOBALS['sfp_host'] ?? 'localhost' ) ), $headers ),
			),
		)
	);
	$body = (string) file_get_contents( 'http://127.0.0.1:' . $port . $uri, false, $ctx );
	$res  = array( 'status' => 0, 'headers' => array(), 'body' => $body );
	foreach ( (array) ( $http_response_header ?? array() ) as $line ) {
		if ( preg_match( '#^HTTP/\S+ (\d+)#', $line, $m ) ) {
			$res['status'] = (int) $m[1];
		} elseif ( false !== strpos( $line, ':' ) ) {
			list( $k, $v ) = explode( ':', $line, 2 );
			$res['headers'][ strtolower( trim( $k ) ) ] = trim( $v );
		}
	}
	return $res;
}

/**
 * Print the results and stop with a non-zero code when a check failed.
 *
 * @param string $label Name of the test script, for the closing message.
 */
function sfp_finish( $label = 'Stage File Proxy' ) {
	$total = $GLOBALS['sfp_pass'] + count( $GLOBALS['sfp_fail'] );
	WP_CLI::line( '' );
	foreach ( $GLOBALS['sfp_fail'] as $f ) {
		WP_CLI::line( 'FAIL  ' . $f );
	}
	WP_CLI::line( sprintf( '%d of %d checks passed.', $GLOBALS['sfp_pass'], $total ) );
	if ( $GLOBALS['sfp_fail'] ) {
		WP_CLI::halt( 1 );
	}
	WP_CLI::success( 'All ' . $label . ' checks passed.' );
}
