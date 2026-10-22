<?php
/**
 * Configuration tests for Stage File Proxy: the documented WP-CLI commands and PHP constants.
 *
 * Runs the `wp option ...` commands from the README for real, then sends HTTP
 * requests to check that the saved options drive the plugin as documented, and
 * that the PHP constants override them. The README says:
 *
 * - `wp option update|get|delete` work for sfp_url, sfp_mode and sfp_local_dir,
 * - a URL with a wp-content/uploads path is reduced to its domain,
 * - a constant wins over the saved option.
 *
 * This script WRITES the sfp_url, sfp_mode and sfp_local_dir options, so it only
 * runs when you opt in, and it puts the original values back when it ends. Use a
 * development copy. Run it from the WordPress root, for the main site:
 *
 *     SFP_TEST_ALLOW_OPTION_WRITES=1 wp eval-file wp-content/plugins/stage-file-proxy/tests/run-config-tests.php
 *
 * Like run-tests.php it accepts SFP_EXPECT_UPLOADS for sites with another folder layout.
 *
 * @package stage-file-proxy
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit;
}

require __DIR__ . '/helpers.php';

if ( '1' !== getenv( 'SFP_TEST_ALLOW_OPTION_WRITES' ) ) {
	WP_CLI::error( 'This script writes the sfp_url, sfp_mode and sfp_local_dir options (it restores them at the end). Set SFP_TEST_ALLOW_OPTION_WRITES=1 to run it, preferably on a development copy.' );
}
if ( is_multisite() && ! is_main_site() ) {
	WP_CLI::error( 'Run this script for the main site: the test server answers for the main site.' );
}

// Remember the options, and put them back however the script ends.
$originals = array();
foreach ( array( 'sfp_url', 'sfp_mode', 'sfp_local_dir' ) as $option ) {
	$originals[ $option ] = get_option( $option, '__absent__' );
}
$restore = function () use ( $originals ) {
	foreach ( $originals as $option => $value ) {
		'__absent__' === $value ? delete_option( $option ) : update_option( $option, $value );
	}
};
register_shutdown_function( $restore );

$cli = fn( $command ) => trim( (string) WP_CLI::runcommand( $command, array( 'return' => true, 'exit_error' => false, 'launch' => false ) ) );

// =====================================================================
// PART 1: the WP-CLI commands from the README
// =====================================================================

sfp_check( 'wp option update sfp_url reports success', 1 === preg_match( "/Success: (Updated|Added) 'sfp_url' option/", $cli( "option update sfp_url 'https://production.example.com'" ) ) );
sfp_equals( 'wp option get sfp_url returns the value', 'https://production.example.com', fn() => $cli( 'option get sfp_url' ) );
foreach ( array( 'header', 'download', 'photon', 'local', 'lorempixel' ) as $mode ) {
	$cli( "option update sfp_mode '$mode'" );
	sfp_equals( "wp option update sfp_mode '$mode' then get", $mode, fn() => $cli( 'option get sfp_mode' ) );
}
$cli( "option update sfp_local_dir 'my-fallback-images'" );
sfp_equals( 'wp option update sfp_local_dir then get', 'my-fallback-images', fn() => $cli( 'option get sfp_local_dir' ) );
// `wp option list --search="sfp_*"` prints a table that cannot be captured inside a running script, so the same search is run on the database.
$stored = function () {
	global $wpdb;
	$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'sfp\\_url' OR option_name LIKE 'sfp\\_mode' OR option_name LIKE 'sfp\\_local\\_dir' ORDER BY option_name" );
	return $names;
};
sfp_equals( 'the three options are stored under their sfp_ names', array( 'sfp_local_dir', 'sfp_mode', 'sfp_url' ), $stored );
sfp_equals( 'the plugin reads the saved local dir', 'my-fallback-images', fn() => sfp_normalize_local_dir( get_option( 'sfp_local_dir' ) ) );
$cli( 'option delete sfp_local_dir' );
sfp_equals( 'wp option delete sfp_local_dir resets it to the default folder', 'sfp-images', fn() => sfp_normalize_local_dir( get_option( 'sfp_local_dir' ) ) );
sfp_check( 'settings page sanitizers are not active in WP-CLI, so wp option writes are stored as typed', false === has_filter( 'sanitize_option_sfp_mode' ) );
$cli( "option update sfp_mode 'bogus'" );
sfp_equals( 'a value typed through WP-CLI is stored as typed', 'bogus', fn() => $cli( 'option get sfp_mode' ) );
foreach ( array( 'sfp_url', 'sfp_mode', 'sfp_local_dir' ) as $option ) {
	$cli( "option delete $option" );
}
sfp_equals( 'reset: wp option delete removes all three', array(), $stored );

// =====================================================================
// PART 2: saved options and constants, over HTTP
// =====================================================================

$expected_base = rtrim( getenv( 'SFP_EXPECT_UPLOADS' ) ? getenv( 'SFP_EXPECT_UPLOADS' ) : '/wp-content/uploads', '/' );
$up            = rtrim( (string) wp_parse_url( wp_upload_dir( null, false )['baseurl'], PHP_URL_PATH ), '/' );
$uploads       = wp_upload_dir( null, false );
$saved         = $uploads['basedir'] . '/2099';

$GLOBALS['sfp_host'] = (string) wp_parse_url( home_url(), PHP_URL_HOST );
putenv( 'SFP_WP_LOAD=' . ABSPATH . 'wp-load.php' );

$docroot = sys_get_temp_dir() . '/sfp-config-' . getmypid();
$png     = $docroot . $expected_base . '/2099/01/cfg.png';
wp_mkdir_p( dirname( $png ) );
$img = imagecreatetruecolor( 100, 50 );
imagepng( $img, $png );
$png_bytes = file_get_contents( $png );

$prod = sfp_start_server( $docroot );
$site = sfp_start_server( ABSPATH, __DIR__ . '/router.php' );
$org  = 'http://127.0.0.1:' . $prod['port'];
$ro   = $org . $expected_base;
$get  = fn( $uri, $headers = array() ) => sfp_request( $site['port'], $uri, $headers );
$loc  = fn( $r ) => $r['headers']['location'] ?? null;
$set  = function ( $url, $mode, $dir = null ) use ( $cli ) {
	$cli( "option update sfp_url '$url'" );
	$cli( "option update sfp_mode '$mode'" );
	$dir ? $cli( "option update sfp_local_dir '$dir'" ) : $cli( 'option delete sfp_local_dir' );
};
$wipe = function () use ( $saved ) {
	foreach ( glob( "$saved/01/*" ) ?: array() as $f ) {
		wp_delete_file( $f );
	}
	@rmdir( "$saved/01" );
	@rmdir( $saved );
};
$img_size = fn( $r ) => @getimagesizefromstring( $r['body'] );
$is_error = fn( $r ) => false !== strpos( $r['body'], 'SFP tried to load' ) && null === $loc( $r );

// header
$set( $org, 'header' );
$r = $get( "$up/2099/01/cfg.png" );
sfp_check( 'saved options, header mode: redirects to the saved URL', $loc( $r ) === "$ro/2099/01/cfg.png", array( 'status' => $r['status'], 'location' => $loc( $r ) ) );
$r = $get( "$up/2099/01/cfg-50x40c.png" );
sfp_check( 'saved options, header mode: resized name goes to the remote as is', $loc( $r ) === "$ro/2099/01/cfg-50x40c.png", $loc( $r ) );

// photon
$set( $org, 'photon' );
$r = $get( "$up/2099/01/cfg-50x40c.png" );
sfp_check( 'saved options, photon mode: passes the size and crop', $loc( $r ) === "$ro/2099/01/cfg.png?w=50&h=40&resize=50,40", $loc( $r ) );

// lorempixel
$set( $org, 'lorempixel' );
$r = $get( "$up/2099/01/none-lp.png" );
sfp_check( 'saved options, lorempixel mode: failed remote goes to the placeholder service', $loc( $r ) === 'http://lorempixel.com/800/600', $loc( $r ) );

// local (only when the theme does not already have a folder of that name)
if ( ! is_dir( get_template_directory() . '/sfp-images' ) ) {
	$set( $org, 'local' );
	$r = $get( "$up/2099/01/none-local.png" );
	sfp_check( 'saved options, local mode: no fallback images gives the plugin error', $is_error( $r ), array( 'status' => $r['status'], 'location' => $loc( $r ), 'body' => substr( $r['body'], 0, 100 ) ) );
	delete_transient( 'sfp_image_' . md5( "$up/2099/01/none-local.png" ) );
	delete_transient( 'sfp-replacement-images' );
}

// download
$set( $org, 'download' );
$r = $get( "$up/2099/01/cfg.png" );
sfp_check( 'saved options, download mode: serves the remote file', $png_bytes === $r['body'] && 0 === strpos( $r['headers']['content-type'] ?? '', 'image/png' ), array( 'status' => $r['status'], 'bytes' => strlen( $r['body'] ) ) );
sfp_check( 'saved options, download mode: saves the file in uploads', file_exists( "$saved/01/cfg.png" ) );
$wipe();
$r    = $get( "$up/2099/01/cfg-50x40c.png" );
$size = $img_size( $r );
sfp_check( 'saved options, download mode: crop resize is exactly 50x40', $size && 50 === $size[0] && 40 === $size[1], array( 'status' => $r['status'], 'size' => $size ) );
$wipe();
$r = $get( "$up/2099/01/not-there.png" );
sfp_check( 'saved options, download mode: a 404 on the remote gives the plugin error', $is_error( $r ), substr( $r['body'], 0, 100 ) );

// a full uploads URL typed with WP-CLI is reduced to its domain, as the README says
$set( "$org/wp-content/uploads/", 'header' );
$r = $get( "$up/2099/01/cfg.png" );
sfp_check( 'saved URL with a pasted wp-content/uploads path is reduced to the domain', $loc( $r ) === "$ro/2099/01/cfg.png", $loc( $r ) );
$set( "$org/", 'header' );
$r = $get( "$up/2099/01/cfg.png" );
sfp_check( 'saved URL with a trailing slash works', $loc( $r ) === "$ro/2099/01/cfg.png", $loc( $r ) );

// constants win over the saved options
$set( 'http://127.0.0.1:1', 'download' );
$r = $get( "$up/2099/01/cfg.png", array( 'X-SFP-Mode: header', 'X-SFP-Url: ' . $org ) );
sfp_check( 'constants STAGE_FILE_PROXY_URL and _MODE win over the saved options', $loc( $r ) === "$ro/2099/01/cfg.png", array( 'status' => $r['status'], 'location' => $loc( $r ) ) );
$r    = $get( "$up/2099/01/cfg-9x9.png", array( 'X-SFP-Url: ' . $org ) );
$size = $img_size( $r );
sfp_check( 'constant URL wins while the saved mode (download) is still used', $size && 9 === $size[0], array( 'status' => $r['status'], 'type' => $r['headers']['content-type'] ?? null ) );
$wipe();
$set( $org, 'header', 'saved-dir' );
$r = $get( "$up/2099/01/none-dir.png", array( 'X-SFP-Mode: local', 'X-SFP-Local-Dir: constant-dir' ) );
sfp_check( 'constant STAGE_FILE_PROXY_LOCAL_DIR with the local mode is accepted (no images gives the plugin error)', $is_error( $r ), substr( $r['body'], 0, 100 ) );
delete_transient( 'sfp_image_' . md5( "$up/2099/01/none-dir.png" ) );
delete_transient( 'sfp-replacement-images' );

// nothing configured
foreach ( array( 'sfp_url', 'sfp_mode', 'sfp_local_dir' ) as $option ) {
	$cli( "option delete $option" );
}
$r = $get( "$up/2099/01/cfg.png" );
sfp_check( 'nothing configured: the plugin error, no redirect', $is_error( $r ), array( 'status' => $r['status'], 'location' => $loc( $r ) ) );
$r = $get( "$up/2099/01/cfg.png", array( 'X-SFP-Url: ' . $org ) );
sfp_check( 'only the URL constant set: the default mode (header) is used', $loc( $r ) === "$ro/2099/01/cfg.png", $loc( $r ) );

// No PHP problems may reach the log from the plugin, and nothing is left behind.
foreach ( array( $prod, $site ) as $srv ) {
	proc_terminate( $srv['proc'] );
	proc_close( $srv['proc'] );
}
$wipe();
$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $docroot, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $items as $item ) {
	$item->isDir() ? @rmdir( $item->getPathname() ) : wp_delete_file( $item->getPathname() );
}
@rmdir( $docroot );
$restore();
sfp_check( 'the original options are back', array_map( fn( $o ) => get_option( $o, '__absent__' ), array_keys( $originals ) ) === array_values( $originals ) );
sfp_check( 'no test files are left in uploads', ! is_dir( $saved ) );

sfp_finish( 'Stage File Proxy configuration' );
