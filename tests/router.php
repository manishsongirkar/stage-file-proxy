<?php
/**
 * Router for the PHP built-in web server used by run-tests.php.
 *
 * Boots WordPress for every request and lets the test request choose the
 * plugin configuration through X-SFP-* headers. Refuses to run on any other
 * server, so it cannot be used from a real web server.
 *
 * @package stage-file-proxy
 */

if ( 'cli-server' !== PHP_SAPI ) {
	http_response_code( 404 );
	exit;
}

foreach ( array(
	'HTTP_X_SFP_URL'       => 'STAGE_FILE_PROXY_URL',
	'HTTP_X_SFP_MODE'      => 'STAGE_FILE_PROXY_MODE',
	'HTTP_X_SFP_LOCAL_DIR' => 'STAGE_FILE_PROXY_LOCAL_DIR',
	'HTTP_X_SFP_STYLE'     => 'STAGE_FILE_PROXY_IMAGE_STYLE',
) as $header => $constant ) {
	if ( isset( $_SERVER[ $header ] ) ) {
		define( $constant, 'NONE' === $_SERVER[ $header ] ? '' : $_SERVER[ $header ] );
	}
}

$_SERVER['HTTPS'] = 'on';

require getenv( 'SFP_WP_LOAD' ) ? getenv( 'SFP_WP_LOAD' ) : dirname( __DIR__, 4 ) . '/wp-load.php';
