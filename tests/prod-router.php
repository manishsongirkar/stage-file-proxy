<?php
/**
 * Router for the fake production site started by run-tests.php.
 *
 * Serves files as they are. A request with a resize argument (?w=, ?resize=) gets a tiny 10x5 image, like a
 * WordPress VIP site that resizes on the fly. A missing file is a 404. Exits unless it runs on `cli-server`.
 *
 * @package stage-file-proxy
 */

if ( 'cli-server' !== PHP_SAPI ) {
	http_response_code( 404 );
	exit;
}

$path = rawurldecode( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );

if ( ! is_file( $_SERVER['DOCUMENT_ROOT'] . $path ) ) {
	http_response_code( 404 );
	echo 'not found';
	return true;
}

if ( isset( $_GET['w'] ) || isset( $_GET['resize'] ) ) {
	header( 'Content-Type: image/png' );
	imagepng( imagecreatetruecolor( 10, 5 ) );
	return true;
}

return false; // The built-in server sends the file itself.
