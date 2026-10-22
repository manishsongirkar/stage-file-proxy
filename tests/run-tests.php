<?php
/**
 * Regression tests for Stage File Proxy.
 *
 * Run from the WordPress root, with the database running:
 *
 *     wp eval-file wp-content/plugins/stage-file-proxy/tests/run-tests.php
 *
 * The suite adapts to the site it runs on (single site, multisite, WordPress in
 * a subdirectory, custom content folder such as Bedrock, UPLOADS constant). The
 * uploads path expected on the remote site is /wp-content/uploads unless the
 * SFP_EXPECT_UPLOADS environment variable says otherwise, for example:
 *
 *     SFP_EXPECT_UPLOADS=/app/uploads wp eval-file .../run-tests.php
 *
 * The remote image URL style (standard or WordPress VIP) is detected from the site. Part 1 sets it
 * explicitly for each group of checks, so the same checks hold on any site.
 *
 * Part 1 calls plugin functions directly. Part 2 starts two PHP built-in
 * servers, a fake production site and this WordPress install, and checks real
 * HTTP responses. The tests only write under uploads/2099 and a few
 * transients, and they remove both at the end. No test framework is needed.
 *
 * @package stage-file-proxy
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit;
}

require __DIR__ . '/helpers.php';
sfp_require_dev_environment();

// =====================================================================
// PART 1: direct function tests
// =====================================================================

if ( ! function_exists( 'sfp_get_mode' ) ) {
	require dirname( __DIR__ ) . '/stage-file-proxy.php';
}
// Uploads path expected on the remote site (same layout as this site, without a subdirectory or /sites/N).
$expected_base = rtrim( getenv( 'SFP_EXPECT_UPLOADS' ) ? getenv( 'SFP_EXPECT_UPLOADS' ) : '/wp-content/uploads', '/' );
if ( ! defined( 'STAGE_FILE_PROXY_URL' ) ) {
	define( 'STAGE_FILE_PROXY_URL', SFP_TEST_PROD . $expected_base . '/' );
}
$up_path                = rtrim( (string) wp_parse_url( wp_upload_dir( null, false )['baseurl'], PHP_URL_PATH ), '/' );
$_SERVER['REQUEST_URI'] = $up_path . '/2026/10/test-300x200.jpg';
remove_action( 'init', 'sfp_dispatch' );

$uploads    = wp_upload_dir();
$base       = $uploads['baseurl'];
$remote_dir = SFP_TEST_PROD . $expected_base . ( is_multisite() && ! is_subdomain_install() && ! is_main_site() ? '/sites/' . get_current_blog_id() : '' );

// --- The plugin must never call a function or use a constant that only exists on WordPress VIP. This plugin runs on many kinds of
// sites, and a call to a missing VIP function (for example wpvip_get_app_name()) is a fatal error on every site without it. Names may
// appear only as strings, in function_exists(), has_filter() and defined(). The source file is scanned, so this holds on every site.
$vip_name   = '/^(wpvip_|wpcom_vip_|vip_|a8c_|is_vip_|wpcom_)/i';
$vip_const  = '/^(VIP_|WPVIP_|WPCOM_|FILES_CLIENT|FILES_ACCESS)/';
$plugin_src = (string) file_get_contents( dirname( __DIR__ ) . '/stage-file-proxy.php' );
$vip_calls  = array();
$tokens     = PhpToken::tokenize( $plugin_src );
foreach ( $tokens as $position => $token ) {
	if ( T_STRING !== $token->id ) {
		continue;
	}
	$next = $position + 1;
	while ( isset( $tokens[ $next ] ) && T_WHITESPACE === $tokens[ $next ]->id ) {
		++$next;
	}
	$is_call = isset( $tokens[ $next ] ) && '(' === $tokens[ $next ]->text;
	if ( $is_call && preg_match( $vip_name, $token->text ) ) {
		$vip_calls[] = 'line ' . $token->line . ': ' . $token->text . '()';
	} elseif ( preg_match( $vip_const, $token->text ) ) {
		// A read is fine when the same expression checks defined() first: defined( 'X' ) && X stops before an undefined constant.
		$guarded = 1 === preg_match( "/defined\(\s*['\"]" . preg_quote( $token->text, '/' ) . "['\"]\s*\)\s*&&\s*" . preg_quote( $token->text, '/' ) . '\b/', $plugin_src );
		if ( ! $guarded ) {
			$vip_calls[] = 'line ' . $token->line . ': ' . $token->text . ' (not guarded by defined())';
		}
	}
}
sfp_check( 'no direct call to a VIP function, and no VIP constant read without defined(), in the plugin', empty( $vip_calls ), $vip_calls );

// --- Image URL style: detected from the site, and always overridable.
$vip_signals    = function_exists( 'a8c_files_maybe_inject_image_sizes' ) || defined( 'VIP_GO_APP_ENVIRONMENT' ) || ( defined( 'WPCOM_IS_VIP_ENV' ) && WPCOM_IS_VIP_ENV );
$detected_style = defined( 'STAGE_FILE_PROXY_IMAGE_STYLE' ) ? ( 'vip' === STAGE_FILE_PROXY_IMAGE_STYLE ? 'vip' : 'standard' ) : ( $vip_signals ? 'vip' : 'standard' );
sfp_equals( 'style: detected from the site (VIP only with VIP signs)', $detected_style, 'sfp_get_remote_image_style' );
foreach ( array( 'vip' => 'vip', 'standard' => 'standard', 'VIP' => 'standard', '' => 'standard', 'other' => 'standard' ) as $given => $expected ) {
	add_filter( 'sfp_remote_image_style', $style_cb = fn() => $given );
	sfp_equals( 'style: the filter value ' . wp_json_encode( $given ) . ' gives ' . $expected, $expected, 'sfp_get_remote_image_style' );
	remove_filter( 'sfp_remote_image_style', $style_cb );
}
/**
 * Pin the image URL style for the checks that follow.
 *
 * @param string $style `vip` or `standard`.
 */
$pin_style = function ( $style ) {
	remove_all_filters( 'sfp_remote_image_style' );
	add_filter( 'sfp_remote_image_style', fn() => $style );
};
// The older checks below describe the standard style, which must work the same on every site, VIP or not.
$pin_style( 'standard' );

// --- Configuration typed by a human: extract the domain from whatever was pasted.
foreach ( array(
	'plain domain'                  => array( 'https://a.com', 'https://a.com' ),
	'trailing slash'                => array( 'https://a.com/', 'https://a.com' ),
	'pasted uploads path'           => array( 'https://a.com/wp-content/uploads/', 'https://a.com' ),
	'pasted multisite uploads path' => array( 'https://a.com/wp-content/uploads/sites/2/', 'https://a.com' ),
	'subfolder install'             => array( 'http://a.com/sub/', 'http://a.com/sub' ),
	'subfolder with uploads path'   => array( 'https://a.com:8080/sub/wp-content/uploads', 'https://a.com:8080/sub' ),
	'port kept'                     => array( 'http://localhost:8080', 'http://localhost:8080' ),
	'credentials dropped'           => array( 'https://user:pass@a.com/wp-content/uploads', 'https://a.com' ),
	'query string dropped'          => array( 'https://a.com/?utm=1', 'https://a.com' ),
	'empty'                         => array( '', '' ),
) as $label => $case ) {
	sfp_equals( "extract: $label", $case[1], fn() => sfp_extract_domain_from_url( $case[0] ) );
}

// --- Settings page input: the URL field.
$old_url = get_option( 'sfp_url' );
foreach ( array(
	'plain'              => array( 'https://x.com', 'https://x.com' ),
	'pasted uploads URL' => array( 'https://x.com/wp-content/uploads/', 'https://x.com' ),
	'padded spaces'      => array( '  https://x.com  ', 'https://x.com' ),
	'no scheme'          => array( 'x.com', 'http://x.com' ),
	'upper-case scheme'  => array( 'HTTPS://X.com/WP-CONTENT/uploads', 'https://X.com' ),
) as $label => $case ) {
	sfp_equals( "sanitize url: $label", $case[1], fn() => sfp_sanitize_url( $case[0] ) );
}
foreach ( array( 'javascript:alert(1)', 'http://', '', '   ', 'ftp://x.com', array( 'https://x.com' ), null, 12345, true ) as $i => $bad ) {
	$res = null;
	try {
		$res = sfp_sanitize_url( $bad );
	} catch ( Throwable $e ) {
		sfp_check( "sanitize url bad input #$i must not throw", false, $e->getMessage() );
		continue;
	}
	$safe = ( $res === $old_url ) || ( is_string( $res ) && 0 === strpos( $res, 'http' ) );
	sfp_check( "sanitize url bad input #$i keeps the old value or a http(s) URL", $safe && false === stripos( (string) $res, 'javascript' ), $res );
}

// --- Settings page input: the mode field.
$old_mode = get_option( 'sfp_mode', 'header' );
foreach ( array( 'header', 'download', 'photon', 'local', 'lorempixel' ) as $m ) {
	sfp_equals( "mode accepts $m", $m, fn() => sfp_sanitize_mode( $m ) );
}
foreach ( array( 'Header' => 'header', ' LOCAL ' => 'local', "Photon\n" => 'photon', 'DOWNLOAD' => 'download' ) as $typed => $saved ) {
	sfp_equals( 'mode tolerates typing: ' . wp_json_encode( $typed ), $saved, fn() => sfp_sanitize_mode( $typed ) );
}
foreach ( array( 'bogus', '', '0', 'redirect', 'head er', array( 'header' ), null, 0, true ) as $i => $bad ) {
	sfp_equals( "mode rejects bad input #$i and keeps the old value", $old_mode, fn() => sfp_sanitize_mode( $bad ) );
}

// --- Settings page input: the local directory field.
foreach ( array( 'sfp-images', 'assets/img', 'my images', '  padded  ' ) as $ok ) {
	sfp_equals( 'local dir accepts ' . wp_json_encode( $ok ), trim( $ok ), fn() => sfp_sanitize_local_dir( $ok ) );
}
sfp_equals( 'local dir strips tags', 'ac', fn() => sfp_sanitize_local_dir( '<b>a</b>c' ) );
sfp_equals( 'local dir empty is allowed', '', fn() => sfp_sanitize_local_dir( '' ) );
$old_dir = get_option( 'sfp_local_dir', '' );
foreach ( array( '../', '../../etc', 'a/../../b', '..\\..\\x', '/etc', '\\windows', 'C:\\x', 'c:/x', '..' ) as $bad ) {
	sfp_equals( 'local dir rejects ' . wp_json_encode( $bad ), $old_dir, fn() => sfp_sanitize_local_dir( $bad ) );
}
sfp_equals( 'local dir rejects non-string', $old_dir, fn() => sfp_sanitize_local_dir( array( 'x' ) ) );
sfp_check( 'local dir keeps "..." inside a name', 'v1..2' === sfp_sanitize_local_dir( 'v1..2' ), sfp_sanitize_local_dir( 'v1..2' ) );

// --- Settings registration, through WordPress core.
do_action( 'admin_init' );
global $wp_registered_settings;
foreach ( array( 'sfp_url' => 'sfp_sanitize_url', 'sfp_mode' => 'sfp_sanitize_mode', 'sfp_local_dir' => 'sfp_sanitize_local_dir' ) as $opt => $cb ) {
	sfp_check( "$opt registered with $cb", isset( $wp_registered_settings[ $opt ] ) && $cb === $wp_registered_settings[ $opt ]['sanitize_callback'], $wp_registered_settings[ $opt ] ?? null );
	sfp_check( "$opt is a string setting", 'string' === ( $wp_registered_settings[ $opt ]['type'] ?? '' ) );
}
sfp_equals( 'sanitize_option runs the mode sanitizer', 'local', fn() => sanitize_option( 'sfp_mode', ' Local ' ) );
sfp_equals( 'sfp_mode default is header', 'header', fn() => $wp_registered_settings['sfp_mode']['default'] );

// --- Remote URL building and rewriting.
foreach ( array(
	'plain'             => array( '2026/10/a.jpg', '/2026/10/a.jpg' ),
	'leading slash'     => array( '/2026/10/a.jpg', '/2026/10/a.jpg' ),
	'many slashes'      => array( '///2026/10/a.jpg', '/2026/10/a.jpg' ),
	'query string'      => array( '2026/10/a.jpg?ver=2', '/2026/10/a.jpg?ver=2' ),
	'encoded space'     => array( '2026/10/my%20photo.jpg', '/2026/10/my%20photo.jpg' ),
	'upper-case suffix' => array( '2026/10/A.JPG', '/2026/10/A.JPG' ),
) as $label => $case ) {
	sfp_equals( "construct remote url: $label", $remote_dir . $case[1], fn() => sfp_construct_remote_url( $case[0] ) );
}
sfp_equals( 'rewrite local to remote', $remote_dir . '/2026/10/a.jpg', fn() => sfp_rewrite_local_to_remote( "$base/2026/10/a.jpg" ) );
sfp_equals( 'rewrite keeps query and fragment', $remote_dir . '/2026/10/a.jpg?v=2#top', fn() => sfp_rewrite_local_to_remote( "$base/2026/10/a.jpg?v=2#top" ) );
sfp_equals( 'rewrite ignores other hosts', 'https://elsewhere.test/x.jpg', fn() => sfp_rewrite_local_to_remote( 'https://elsewhere.test/x.jpg' ) );
sfp_equals( 'rewrite ignores empty string', '', fn() => sfp_rewrite_local_to_remote( '' ) );
sfp_equals( 'map url to local path', trailingslashit( $uploads['basedir'] ) . '2026/10/a.jpg', fn() => sfp_map_url_to_local_path( "$base/2026/10/a.jpg" ) );
sfp_equals( 'attachment url filter rewrites a missing file', $remote_dir . '/2026/10/sfp-missing.jpg', fn() => sfp_wp_get_attachment_url_rewrite( "$base/2026/10/sfp-missing.jpg" ) );
sfp_equals( 'attachment url filter leaves foreign URLs alone', 'https://cdn.test/a.jpg', fn() => sfp_wp_get_attachment_url_rewrite( 'https://cdn.test/a.jpg' ) );

// --- Content filters.
$html = '<p><img class="x" src="' . $base . '/2026/10/none.jpg" alt="a"><img src="https://other.test/b.jpg"><span style="background-image:url(\'' . $base . '/2026/10/bg.jpg\')"></span></p>';
$out  = sfp_enhance_content_images( $html );
sfp_check( 'content: missing upload is rewritten to remote', false !== strpos( $out, 'src="' . $remote_dir . '/2026/10/none.jpg"' ), $out );
sfp_check( 'content: srcset is added', false !== strpos( $out, 'srcset="' ) );
sfp_check( 'content: foreign image is untouched', false !== strpos( $out, 'src="https://other.test/b.jpg"' ) );
sfp_check( 'content: css background is rewritten', false !== strpos( $out, 'url(\'' . $remote_dir . '/2026/10/bg.jpg\')' ), $out );
sfp_equals( 'content: empty string stays empty', '', fn() => sfp_enhance_content_images( '' ) );
sfp_equals( 'content: text without images is unchanged', '<p>Hello</p>', fn() => sfp_enhance_content_images( '<p>Hello</p>' ) );
sfp_equals( 'content: image with existing srcset is not given another', 1, fn() => substr_count( sfp_enhance_content_images( '<img src="' . $base . '/2026/10/none.jpg" srcset="x 1w">' ), 'srcset=' ) );
sfp_equals( 'block: core/image is rewritten', true, fn() => false !== strpos( sfp_render_block_core_image( $html, array( 'blockName' => 'core/image' ) ), $remote_dir ) );
sfp_equals( 'block: other blocks are untouched', $html, fn() => sfp_render_block_core_image( $html, array( 'blockName' => 'core/paragraph' ) ) );
sfp_equals( 'block: empty content is untouched', '', fn() => sfp_render_block_core_image( '', array( 'blockName' => 'core/image' ) ) );
sfp_equals( 'block: block without name does not break', $html, fn() => sfp_render_block_core_image( $html, array( 'blockName' => null ) ) );
$src = sfp_wp_get_attachment_image_src_enhanced( array( "$base/2026/10/none.jpg", 0, 0, false ), 0, 'full', false );
sfp_equals( 'image src filter: remote URL and fallback size', array( $remote_dir . '/2026/10/none.jpg', 1200, 800, true ), fn() => $src );
sfp_equals( 'image src filter: false passes through', false, fn() => sfp_wp_get_attachment_image_src_enhanced( false, 1, 'full', false ) );
sfp_equals( 'srcset filter: nothing without metadata', array( 'k' ), fn() => sfp_generate_remote_srcset( array( 'k' ), array( 1, 1 ), "$base/a.jpg", array(), 1 ) );
$set = sfp_generate_remote_srcset( array(), array( 100, 100 ), "$base/2026/10/none.jpg", array( 'width' => 2000, 'height' => 1000 ), 1 );
sfp_check( 'srcset filter: builds remote sizes', ! empty( $set ) && 0 === strpos( reset( $set )['url'], $remote_dir ), $set );
sfp_equals( 'image attributes filter: no src is a no-op', array( 'alt' => 'x' ), fn() => sfp_wp_get_attachment_image_attributes( array( 'alt' => 'x' ), (object) array( 'ID' => 1 ), 'full' ) );
sfp_check( 'srcset from url lists 5 candidates', 5 === count( sfp_generate_srcset_from_url( "$remote_dir/a.jpg" ) ) );

// --- Fake image sizes.
sfp_equals( 'no sizes are generated on upload', array(), fn() => sfp_image_sizes_advanced( array( 'thumbnail' => array( 'width' => 150, 'height' => 150, 'crop' => true ) ) ) );
$meta = sfp_generate_metadata( array( 'width' => 2000, 'height' => 1000, 'file' => '2026/10/a.jpg', 'sizes' => array() ) );
sfp_equals( 'fake metadata for thumbnail', array( 'file' => 'a-r-150x150c.jpg', 'width' => 150, 'height' => 150 ), fn() => $meta['sizes']['thumbnail'] ?? null );
sfp_equals( 'metadata without recorded sizes is unchanged', array( 'x' => 1 ), function () {
	$GLOBALS['dynimg_image_sizes'] = null;
	return sfp_generate_metadata( array( 'x' => 1 ) );
} );

// --- Remote image metadata (HTTP is mocked). Uses a real attachment; nothing is saved.
$attachment = get_posts( array( 'post_type' => 'attachment', 'numberposts' => 1, 'fields' => 'ids' ) );
if ( $attachment ) {
	$att_id = $attachment[0];
	// The plugin's own URL filter would turn the local URL into the remote one first; switch it off for this block.
	remove_filter( 'wp_get_attachment_url', 'sfp_wp_get_attachment_url_rewrite', 99 );
	add_filter( 'wp_get_attached_file', fn() => $uploads['basedir'] . '/__sfp_missing/none.jpg', 99 );
	add_filter( 'wp_get_attachment_url', fn() => $base . '/__sfp_missing/none.jpg', 1 );
	$GLOBALS['sfp_ct'] = 'image/jpeg';
	add_filter(
		'pre_http_request',
		fn() => array( 'headers' => array( 'content-type' => $GLOBALS['sfp_ct'] ), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null )
	);
	foreach ( array(
		'image'                  => array( 'image/jpeg', true ),
		'not an image'           => array( 'text/html', false ),
		'repeated header (array)' => array( array( 'image/jpeg', 'image/jpeg' ), false ),
		'missing header'         => array( '', false ),
	) as $label => $case ) {
		$GLOBALS['sfp_ct'] = $case[0];
		$res               = null;
		try {
			$res = sfp_ensure_remote_image_metadata( false, $att_id );
		} catch ( Throwable $e ) {
			sfp_check( "remote metadata ($label) must not throw", false, $e->getMessage() );
			continue;
		}
		sfp_check( "remote metadata ($label)", $case[1] ? ( is_array( $res ) && 1200 === $res['width'] && 800 === $res['height'] ) : false === $res, $res );
	}
	sfp_equals( 'remote metadata: existing metadata is returned as is', array( 'a' => 1 ), fn() => sfp_ensure_remote_image_metadata( array( 'a' => 1 ), $att_id ) );
	remove_all_filters( 'pre_http_request' );
	remove_all_filters( 'wp_get_attached_file', 99 );
	remove_all_filters( 'wp_get_attachment_url', 1 );
	add_filter( 'wp_get_attachment_url', 'sfp_wp_get_attachment_url_rewrite', 99 );
}

// --- Request path, as a browser or crawler might send it.
$expected_mode = defined( 'STAGE_FILE_PROXY_MODE' ) ? STAGE_FILE_PROXY_MODE : get_option( 'sfp_mode' );
sfp_equals( 'mode comes from the constant, then the saved option, then defaults to header', $expected_mode ? $expected_mode : 'header', 'sfp_get_mode' );
sfp_equals( 'base url comes from the constant and loses the uploads path', SFP_TEST_PROD, 'sfp_get_base_url' );
sfp_check( 'subdirectory path is a string', is_string( sfp_get_local_subdirectory_path() ) );
sfp_check( 'local dir is a safe, non-empty name', is_string( sfp_get_local_dir() ) && '' !== sfp_get_local_dir() && ! sfp_is_unsafe_local_dir( sfp_get_local_dir() ), sfp_get_local_dir() );
foreach ( array( '..', '../x', 'x/../y', '/abs', 'C:\\x', '\\x' ) as $d ) {
	sfp_check( 'unsafe local dir detected: ' . wp_json_encode( $d ), sfp_is_unsafe_local_dir( $d ) );
}
foreach ( array( 'sfp-images', 'a/b', 'v1..2', '.hidden' ) as $d ) {
	sfp_check( 'safe local dir accepted: ' . wp_json_encode( $d ), ! sfp_is_unsafe_local_dir( $d ) );
}
// Subsites of a subdomain network keep their /sites/N folder in the request path (long-standing behaviour).
$expected_relative = ( is_multisite() && is_subdomain_install() && ! is_main_site() ? 'sites/' . get_current_blog_id() . '/' : '' ) . '2026/10/test-300x200.jpg';
sfp_equals( 'relative path is cut at the uploads folder', $expected_relative, fn() => ltrim( sfp_get_relative_path(), '/' ) );

// --- Settings page output.
ob_start();
sfp_url_callback();
sfp_mode_callback();
sfp_local_dir_callback();
$page = ob_get_clean();
sfp_check( 'settings: fields are disabled by the constants that are defined', false !== strpos( $page, 'id="sfp-url"' ) && preg_match( '/id="sfp-url"[^>]*disabled/', $page ), $page );
sfp_check( 'settings: no unescaped script injection', false === strpos( $page, '<script>alert' ) );
$super      = get_super_admins();
$super_user = $super ? get_user_by( 'login', $super[0] ) : false; // The login can name a user that does not exist (a fresh install).
if ( $super_user ) {
	wp_set_current_user( $super_user->ID );
	$GLOBALS['title'] = 'Stage File Proxy';
	set_current_screen( 'settings_page_stage-file-proxy' );
	ob_start();
	sfp_options_page();
	sfp_check( 'settings page renders the form for an admin', false !== strpos( ob_get_clean(), 'options.php' ) );
	wp_set_current_user( 0 );
	ob_start();
	sfp_options_page();
	sfp_equals( 'settings page prints nothing for a visitor', '', fn() => ob_get_clean() );
}

// --- Plugin order: the plugin moves itself to the front, whatever its folder is called (the VIP filter that blocks reordering is bypassed).
// sfp_first() saves the new list with update_option(). This test must only SEE that list, never store it: the test lists are fake
// (a/a.php, b/b.php), and saving them would deactivate the real plugins of the site that runs the tests. The capture filter runs
// first; the last filter hands back the old value, so WordPress finds nothing to change and writes nothing. The check after the
// block fails if the stored list was changed anyway.
$raw_active_before = $GLOBALS['wpdb']->get_var( "SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'active_plugins'" );
$self              = plugin_basename( dirname( __DIR__ ) . '/stage-file-proxy.php' );
add_filter( 'pre_update_option_active_plugins', function ( $v ) { $GLOBALS['sfp_ap'] = $v; return $v; }, 1 );
add_filter( 'pre_update_option_active_plugins', fn( $v, $old ) => $old, PHP_INT_MAX, 2 );
$order = function ( $list ) {
	$GLOBALS['sfp_ap'] = null;
	add_filter( 'pre_option_active_plugins', $cb = fn() => $list, 1 );
	sfp_first();
	remove_filter( 'pre_option_active_plugins', $cb, 1 );
	return $GLOBALS['sfp_ap'];
};
sfp_equals( 'plugin moves to the front of active_plugins', array( $self, 'a/a.php', 'b/b.php' ), fn() => $order( array( 'a/a.php', 'b/b.php', $self ) ) );
sfp_equals( 'plugin in the middle moves to the front', array( $self, 'a/a.php', 'b/b.php' ), fn() => $order( array( 'a/a.php', $self, 'b/b.php' ) ) );
sfp_equals( 'plugin already first: nothing is saved', null, fn() => $order( array( $self, 'a/a.php' ) ) );
sfp_equals( 'plugin not in the list: nothing is saved', null, fn() => $order( array( 'a/a.php', 'b/b.php' ) ) );
sfp_equals( 'active_plugins that is not an array: nothing is saved and no error', null, fn() => $order( 'broken' ) );
remove_all_filters( 'pre_update_option_active_plugins', 1 );
remove_all_filters( 'pre_update_option_active_plugins', PHP_INT_MAX );
sfp_check( 'the plugin order test did not change the stored active_plugins of this site', $raw_active_before === $GLOBALS['wpdb']->get_var( "SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'active_plugins'" ) );

// --- Activation redirect: only for a person using the dashboard, never for AJAX or cron. No database writes are made.
$super_logins = get_super_admins();
$admin_user   = $super_logins ? get_user_by( 'login', $super_logins[0] ) : false;
if ( $admin_user && ! get_option( 'sfp_do_activation_redirect', false ) ) {
	$admin_id = $admin_user->ID;
	add_filter( 'pre_option_sfp_do_activation_redirect', '__return_true' );
	add_filter( 'wp_redirect', function ( $location ) { throw new Exception( 'redirect:' . $location ); } );
	$redirect_for = function ( $user_id ) {
		wp_set_current_user( $user_id );
		try {
			sfp_activation_redirect();
		} catch ( Exception $e ) {
			return $e->getMessage();
		}
		return 'no redirect';
	};
	$went = $redirect_for( $admin_id );
	sfp_check( 'activation redirect: an admin is sent to the settings page', 0 === strpos( $went, 'redirect:' ) && false !== strpos( $went, 'options-general.php?page=stage-file-proxy' ), $went );
	sfp_equals( 'activation redirect: a visitor is not redirected', 'no redirect', fn() => $redirect_for( 0 ) );
	add_filter( 'wp_doing_ajax', '__return_true' );
	sfp_equals( 'activation redirect: AJAX requests are not redirected', 'no redirect', fn() => $redirect_for( $admin_id ) );
	remove_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_doing_cron', '__return_true' );
	sfp_equals( 'activation redirect: cron requests are not redirected', 'no redirect', fn() => $redirect_for( $admin_id ) );
	remove_filter( 'wp_doing_cron', '__return_true' );
	$_GET['activate-multi'] = '1';
	sfp_equals( 'activation redirect: not when several plugins are activated at once', 'no redirect', fn() => $redirect_for( $admin_id ) );
	unset( $_GET['activate-multi'] );
	remove_all_filters( 'wp_redirect' );
	remove_filter( 'pre_option_sfp_do_activation_redirect', '__return_true' );
	wp_set_current_user( 0 );
}

// --- Request URI handling: WP-CLI and cron (no REQUEST_URI), proxies (absolute form) and odd values.
$saved_uri = $_SERVER['REQUEST_URI'];
unset( $_SERVER['REQUEST_URI'] );
sfp_equals( 'no REQUEST_URI (WP-CLI, cron): empty URI', '', 'sfp_get_request_uri' );
sfp_equals( 'no REQUEST_URI (WP-CLI, cron): not an uploads request', false, 'sfp_is_uploads_request' );
foreach ( array(
	'/wp-content/uploads/a.jpg?x=1'                    => '/wp-content/uploads/a.jpg?x=1',
	'https://host.test/wp-content/uploads/a.jpg?x=1'   => '/wp-content/uploads/a.jpg?x=1',
	'http://host.test:8080/wp-content/uploads/a.jpg'   => '/wp-content/uploads/a.jpg',
	'https://host.test'                                => '/',
	''                                                 => '',
) as $in => $out ) {
	$_SERVER['REQUEST_URI'] = (string) $in;
	sfp_equals( 'request uri form ' . wp_json_encode( $in ), $out, 'sfp_get_request_uri' );
}
$_SERVER['REQUEST_URI'] = array( 'x' );
sfp_equals( 'REQUEST_URI that is not a string is ignored', '', 'sfp_get_request_uri' );
foreach ( array(
	'/wp-content/uploads/2026/a.jpg'        => true,
	'/WP-CONTENT/UPLOADS/2026/a.jpg'        => true,
	$up_path . '/2026/a.jpg'                => true,
	strtoupper( $up_path ) . '/2026/a.jpg'  => true,
	'https://x.test/wp-content/uploads/a.jpg' => true,
	'/about-us/'                            => false,
	'/wp-content/plugins/x/y.js'            => false,
	'/wp-content/uploads'                   => false,
) as $uri => $expected ) {
	$_SERVER['REQUEST_URI'] = $uri;
	sfp_equals( 'uploads request detected: ' . $uri, $expected, 'sfp_is_uploads_request' );
}
$_SERVER['REQUEST_URI'] = $saved_uri;

// --- WordPress VIP style images: resize arguments instead of a file per size.
set_current_screen( 'front' ); // The content filters skip admin screens; an earlier block set one.
$pin_style( 'vip' );
$uploads_dir = wp_upload_dir( null, false );
$vip_data    = array( 'file' => '2026/10/image.jpg', 'width' => 2000, 'height' => 1125, 'sizes' => array() );
$vip_sizes   = sfp_build_remote_image_sizes( $vip_data, 'image/jpeg', 0 );
sfp_check( 'vip: sizes are built for the image', ! empty( $vip_sizes ), $vip_sizes );
$vip_shape_ok = true;
foreach ( $vip_sizes as $name => $size ) {
	$vip_shape_ok = $vip_shape_ok
		&& 1 === preg_match( '/^image\.jpg\?resize=(\d+),(\d+)$/', $size['file'], $m )
		&& (int) $m[1] === $size['width'] && (int) $m[2] === $size['height']
		&& $size['width'] <= 2000 && $size['height'] <= 1125 && 'image/jpeg' === $size['mime-type'];
}
sfp_check( 'vip: every size is image.jpg?resize=W,H with matching width and height, never larger than the image', $vip_shape_ok, $vip_sizes );
$defaults = 150 === (int) get_option( 'thumbnail_size_w' ) && 150 === (int) get_option( 'thumbnail_size_h' ) && get_option( 'thumbnail_crop' ) && 300 === (int) get_option( 'medium_size_w' ) && 300 === (int) get_option( 'medium_size_h' ) && 1024 === (int) get_option( 'large_size_w' ) && 1024 === (int) get_option( 'large_size_h' );
if ( $defaults ) {
	sfp_equals( 'vip: thumbnail is a 150x150 crop', 'image.jpg?resize=150,150', fn() => $vip_sizes['thumbnail']['file'] ?? null );
	sfp_equals( 'vip: medium keeps the aspect ratio (300x169)', 'image.jpg?resize=300,169', fn() => $vip_sizes['medium']['file'] ?? null );
	sfp_equals( 'vip: large keeps the aspect ratio (1024x576)', 'image.jpg?resize=1024,576', fn() => $vip_sizes['large']['file'] ?? null );
}
add_image_size( 'sfp-test-200', 200, 200, true );
add_image_size( 'sfp-test-wide', 600, 0, false );
$vip_sizes = sfp_build_remote_image_sizes( $vip_data, 'image/jpeg', 0 );
sfp_equals( 'vip: a custom 200x200 crop size is image.jpg?resize=200,200', 'image.jpg?resize=200,200', fn() => $vip_sizes['sfp-test-200']['file'] ?? null );
sfp_equals( 'vip: a custom size without a height keeps the ratio (600x338)', 'image.jpg?resize=600,338', fn() => $vip_sizes['sfp-test-wide']['file'] ?? null );
sfp_equals( 'vip: a small image gets no size that would enlarge it', array(), fn() => sfp_build_remote_image_sizes( array( 'file' => 'x/tiny.png', 'width' => 100, 'height' => 100 ), 'image/png', 0 ) );
sfp_equals( 'vip: portrait image sizes keep the ratio', 'tall.jpg?resize=150,300', fn() => sfp_build_remote_image_sizes( array( 'file' => 'a/tall.jpg', 'width' => 800, 'height' => 1600 ), 'image/jpeg', 0 )['medium']['file'] ?? null );
add_filter( 'intermediate_image_sizes_advanced', $drop_cb = function ( $sizes ) { unset( $sizes['sfp-test-200'] ); return $sizes; } );
sfp_check( 'vip: a theme can remove a size with intermediate_image_sizes_advanced', ! isset( sfp_build_remote_image_sizes( $vip_data, 'image/jpeg', 0 )['sfp-test-200'] ) );
remove_filter( 'intermediate_image_sizes_advanced', $drop_cb );
sfp_check( 'vip: this plugin\'s own filter that empties the list is still in place afterwards', false !== has_filter( 'intermediate_image_sizes_advanced', 'sfp_image_sizes_advanced' ) );

// The attachment data filter: only images that exist on the remote site only.
$an_image = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'numberposts' => 1, 'fields' => 'ids' ) );
if ( $an_image ) {
	$image_id = $an_image[0];
	$missing  = array( 'file' => '2099/01/sfp-missing-' . getmypid() . '.png', 'width' => 2000, 'height' => 1125, 'sizes' => array( 'thumbnail' => array( 'file' => 'legacy-150x150.png' ) ) );
	$injected = sfp_inject_remote_image_sizes( $missing, $image_id );
	sfp_check( 'vip: an image missing here gets the VIP sizes in place of its own', isset( $injected['sizes']['thumbnail']['file'] ) && 0 === strpos( $injected['sizes']['thumbnail']['file'], 'sfp-missing-' . getmypid() . '.png?resize=150,150' ), $injected );
	sfp_equals( 'vip: data without a file, width or height is left alone', array( 'width' => 5 ), fn() => sfp_inject_remote_image_sizes( array( 'width' => 5 ), $image_id ) );
	sfp_equals( 'vip: no data (false) is left alone', false, fn() => sfp_inject_remote_image_sizes( false, $image_id ) );
	$present_dir = $uploads_dir['basedir'] . '/2099/01';
	wp_mkdir_p( $present_dir );
	$present_file = 'sfp-present-' . getmypid() . '.png';
	file_put_contents( "$present_dir/$present_file", 'x' );
	$present = array( 'file' => "2099/01/$present_file", 'width' => 2000, 'height' => 1125, 'sizes' => array( 'thumbnail' => array( 'file' => 'own-150x150.png' ) ) );
	sfp_equals( 'vip: an image that exists on this server keeps its own sizes', $present, fn() => sfp_inject_remote_image_sizes( $present, $image_id ) );
	wp_delete_file( "$present_dir/$present_file" );
	@rmdir( $present_dir );
	@rmdir( $uploads_dir['basedir'] . '/2099' );
	add_filter( 'wp_get_attachment_metadata', 'a8c_files_maybe_inject_image_sizes', 20, 2 );
	$other = array( 'file' => '2099/01/sfp-other-' . getmypid() . '.png', 'width' => 2000, 'height' => 1125, 'sizes' => array() );
	sfp_equals( 'vip: nothing is added where VIP\'s own filter already runs', $other, fn() => sfp_inject_remote_image_sizes( $other, $image_id ) );
	remove_filter( 'wp_get_attachment_metadata', 'a8c_files_maybe_inject_image_sizes', 20 );
	$pin_style( 'standard' );
	$other2 = array( 'file' => '2099/01/sfp-std-' . getmypid() . '.png', 'width' => 2000, 'height' => 1125, 'sizes' => array() );
	sfp_equals( 'standard: attachment data is never changed', $other2, fn() => sfp_inject_remote_image_sizes( $other2, $image_id ) );
	$pin_style( 'vip' );
}

// URLs: the query string stays, and only files missing here move to the remote site.
$vip_missing = $uploads_dir['baseurl'] . '/2099/01/sfp-vip-missing.png';
sfp_equals( 'vip: a missing file with ?resize moves to the remote site with its query', $remote_dir . '/2099/01/sfp-vip-missing.png?resize=200,200', fn() => sfp_rewrite_url_if_missing( $vip_missing . '?resize=200,200' ) );
sfp_equals( 'vip: a missing file with ?w=200&h=200&crop=1 keeps all arguments', $remote_dir . '/2099/01/sfp-vip-missing.png?w=200&h=200&crop=1', fn() => sfp_rewrite_url_if_missing( $vip_missing . '?w=200&h=200&crop=1' ) );
sfp_equals( 'vip: other hosts are not touched', 'https://cdn.example.com/a.png?w=1', fn() => sfp_rewrite_url_if_missing( 'https://cdn.example.com/a.png?w=1' ) );
sfp_equals( 'vip: a value that is not a string is returned as it is', array( 'x' ), fn() => sfp_rewrite_url_if_missing( array( 'x' ) ) );
$vip_here_dir = $uploads_dir['basedir'] . '/2099/01';
wp_mkdir_p( $vip_here_dir );
$vip_here = 'sfp-vip-here-' . getmypid() . '.png';
file_put_contents( "$vip_here_dir/$vip_here", 'x' );
$vip_here_url = $uploads_dir['baseurl'] . "/2099/01/$vip_here";
sfp_equals( 'vip: a file that exists here stays local even with a resize query', $vip_here_url . '?w=200', fn() => sfp_rewrite_url_if_missing( $vip_here_url . '?w=200' ) );
sfp_equals( 'vip: local path ignores the query string', "$vip_here_dir/$vip_here", fn() => sfp_map_url_to_local_path( $vip_here_url . '?w=200#top' ) );
$pin_style( 'standard' );
sfp_equals( 'standard: local path keeps the whole URL tail (unchanged)', "$vip_here_dir/$vip_here?w=200", fn() => sfp_map_url_to_local_path( $vip_here_url . '?w=200' ) );
sfp_equals( 'standard: a file that exists here with a query is moved to the remote site (unchanged)', $remote_dir . "/2099/01/$vip_here?w=200", fn() => sfp_wp_get_attachment_url_rewrite( $vip_here_url . '?w=200' ) );
$pin_style( 'vip' );

// srcset
$src_sources = array(
	200 => array( 'url' => $vip_missing . '?resize=200,200', 'descriptor' => 'w', 'value' => 200 ),
	300 => array( 'url' => $vip_missing . '?resize=300,169', 'descriptor' => 'w', 'value' => 300 ),
	512 => array( 'url' => $vip_here_url, 'descriptor' => 'w', 'value' => 512 ),
	600 => array( 'url' => 'https://cdn.example.com/b.png?resize=600,338', 'descriptor' => 'w', 'value' => 600 ),
);
$moved = sfp_generate_remote_srcset( $src_sources, array( 200, 200 ), $vip_missing, array( 'width' => 512, 'height' => 513 ), 1 );
sfp_equals( 'vip srcset: missing sizes move to the remote site with their resize argument', array( $remote_dir . '/2099/01/sfp-vip-missing.png?resize=200,200', $remote_dir . '/2099/01/sfp-vip-missing.png?resize=300,169' ), fn() => array( $moved[200]['url'], $moved[300]['url'] ) );
sfp_equals( 'vip srcset: a file that exists here and another host are left alone', array( $vip_here_url, 'https://cdn.example.com/b.png?resize=600,338' ), fn() => array( $moved[512]['url'], $moved[600]['url'] ) );
sfp_equals( 'vip srcset: widths and descriptors are kept', array( 'w', 200 ), fn() => array( $moved[200]['descriptor'], $moved[200]['value'] ) );
sfp_check( 'vip srcset: no file names are made up', 4 === count( $moved ) && empty( preg_grep( '/-\d+x\d+c?\.png/', array_column( $moved, 'url' ) ) ), $moved );
sfp_equals( 'vip srcset: false (no sources) passes through', false, fn() => sfp_generate_remote_srcset( false, array( 1, 1 ), $vip_missing, array(), 1 ) );
sfp_equals( 'vip srcset value: URLs with a comma inside are not split', $remote_dir . '/2099/01/sfp-vip-missing.png?resize=300,169 300w, ' . $remote_dir . '/2099/01/sfp-vip-missing.png?resize=768,432 768w', fn() => sfp_rewrite_srcset_value( $vip_missing . '?resize=300,169 300w, ' . $vip_missing . '?resize=768,432 768w' ) );
sfp_equals( 'vip srcset value: a candidate without a descriptor works', $remote_dir . '/2099/01/sfp-vip-missing.png', fn() => sfp_rewrite_srcset_value( $vip_missing ) );

// content and blocks
$vip_html = '<img class="wp-image-88" src="' . $vip_missing . '?w=200" alt="">';
$vip_out  = sfp_enhance_content_images( $vip_html );
sfp_equals( 'vip content: src moves to the remote site and keeps ?w=200', '<img class="wp-image-88" src="' . $remote_dir . '/2099/01/sfp-vip-missing.png?w=200" alt="">', fn() => $vip_out );
sfp_check( 'vip content: no srcset or sizes are made up', false === strpos( $vip_out, 'srcset' ) && false === strpos( $vip_out, 'sizes' ), $vip_out );
$vip_html2 = '<img src="' . $vip_missing . '?w=300" srcset="' . $vip_missing . '?resize=150,150 150w, ' . $vip_missing . '?resize=300,169 300w" sizes="100vw">';
sfp_equals( 'vip content: the srcset that WordPress added moves to the remote site too', '<img src="' . $remote_dir . '/2099/01/sfp-vip-missing.png?w=300" srcset="' . $remote_dir . '/2099/01/sfp-vip-missing.png?resize=150,150 150w, ' . $remote_dir . '/2099/01/sfp-vip-missing.png?resize=300,169 300w" sizes="100vw">', fn() => sfp_enhance_content_images( $vip_html2 ) );
sfp_equals( 'vip content: an image that exists here is untouched', '<img src="' . $vip_here_url . '?w=200">', fn() => sfp_enhance_content_images( '<img src="' . $vip_here_url . '?w=200">' ) );
sfp_equals( 'vip block: core/image src and srcset move to the remote site', '<figure><img src="' . $remote_dir . '/2099/01/sfp-vip-missing.png?w=300" srcset="' . $remote_dir . '/2099/01/sfp-vip-missing.png?resize=150,150 150w" class="x"></figure>', fn() => sfp_render_block_core_image( '<figure><img src="' . $vip_missing . '?w=300" srcset="' . $vip_missing . '?resize=150,150 150w" class="x"></figure>', array( 'blockName' => 'core/image' ) ) );
sfp_check( 'vip block: no srcset is made up', false === strpos( sfp_render_block_core_image( '<img src="' . $vip_missing . '?w=300">', array( 'blockName' => 'core/image' ) ), 'srcset' ) );
// srcset attributes in odd HTML
$vip_remote_file = $remote_dir . '/2099/01/sfp-vip-missing.png';
sfp_equals( 'vip srcset attribute: single quotes', "srcset='{$vip_remote_file}?resize=150,150 150w'", fn() => sfp_rewrite_srcset_attributes( "srcset='{$vip_missing}?resize=150,150 150w'" ) );
sfp_equals( 'vip srcset attribute: upper-case name', "srcset=\"{$vip_remote_file}?resize=150,150 150w\"", fn() => sfp_rewrite_srcset_attributes( "SRCSET=\"{$vip_missing}?resize=150,150 150w\"" ) );
sfp_equals( 'vip srcset attribute: data-srcset of a lazy loader is moved too', "data-srcset=\"{$vip_remote_file}?w=300 300w\"", fn() => sfp_rewrite_srcset_attributes( "data-srcset=\"{$vip_missing}?w=300 300w\"" ) );
sfp_equals( 'vip srcset attribute: tags without srcset are unchanged', ' class="x" alt="y"', fn() => sfp_rewrite_srcset_attributes( ' class="x" alt="y"' ) );
sfp_equals( 'vip srcset attribute: an empty srcset is unchanged', ' srcset=""', fn() => sfp_rewrite_srcset_attributes( ' srcset=""' ) );
sfp_equals( 'vip srcset value: candidates on separate lines are joined with a comma and a space', "{$vip_remote_file}?resize=1,1 1w, {$vip_remote_file}?resize=2,2 2w", fn() => sfp_rewrite_srcset_value( "{$vip_missing}?resize=1,1 1w,\n  {$vip_missing}?resize=2,2 2w" ) );
sfp_equals( 'vip srcset value: an escaped ampersand in a URL is kept', "{$vip_remote_file}?w=1&amp;h=2 300w", fn() => sfp_rewrite_srcset_value( "{$vip_missing}?w=1&amp;h=2 300w" ) );
sfp_equals( 'vip srcset value: foreign and remote URLs are not touched', 'https://cdn.example.com/a.png?w=1 1w, ' . $vip_remote_file . '?w=2 2w', fn() => sfp_rewrite_srcset_value( 'https://cdn.example.com/a.png?w=1 1w, ' . $vip_remote_file . '?w=2 2w' ) );

// hooks
sfp_equals( 'hooks: the attachment data filter runs after VIP\'s own (priority 20)', 20, fn() => has_filter( 'wp_get_attachment_metadata', 'sfp_inject_remote_image_sizes' ) );
sfp_equals( 'hooks: the srcset filter keeps priority 98', 98, fn() => has_filter( 'wp_calculate_image_srcset', 'sfp_generate_remote_srcset' ) );
sfp_equals( 'hooks: the content filter keeps priority 98', 98, fn() => has_filter( 'the_content', 'sfp_enhance_content_images' ) );

// The whole path through WordPress core, for a real image whose file is not on this server.
$core_id   = $an_image ? $an_image[0] : 0;
$core_meta = $core_id ? wp_get_attachment_metadata( $core_id ) : array();
if ( $core_id && ! empty( $core_meta['file'] ) && min( (int) ( $core_meta['width'] ?? 0 ), (int) ( $core_meta['height'] ?? 0 ) ) > 300 && ! file_exists( $uploads_dir['basedir'] . '/' . $core_meta['file'] ) ) {
	$core_file = wp_basename( $core_meta['file'] );
	$core_src  = wp_get_attachment_image_src( $core_id, 'sfp-test-200' );
	$core_set  = wp_get_attachment_image_srcset( $core_id, 'sfp-test-200' );
	$core_tag  = wp_get_attachment_image( $core_id, 'sfp-test-200' );
	sfp_check( 'vip core: the attachment data holds the 200x200 size as ?resize=200,200', ( $core_meta['sizes']['sfp-test-200']['file'] ?? '' ) === $core_file . '?resize=200,200', $core_meta['sizes'] ?? null );
	// A theme may add arguments (w, h, strip) or re-encode the comma, so the 200x200 resize is checked in any of its forms.
	$size_arg = '/[?&](resize=200(,|%2C)200|w=200)(&|$)/';
	sfp_check( 'vip core: src for the 200x200 size is a remote URL with the 200x200 resize', is_array( $core_src ) && 0 === strpos( $core_src[0], SFP_TEST_PROD ) && 1 === preg_match( $size_arg, $core_src[0] ) && 200 === $core_src[1] && 200 === $core_src[2], $core_src );
	sfp_check( 'vip core: srcset uses resize arguments on remote URLs, with no made-up file names', is_string( $core_set ) && false !== strpos( $core_set, '?resize=' ) && empty( preg_grep( '/-\d+x\d+c?\.(png|jpe?g|gif|webp)/i', explode( ', ', $core_set ) ) ) && 0 === preg_match( '#(?:^|, )(?!' . preg_quote( SFP_TEST_PROD, '#' ) . ')#', $core_set ), $core_set );
	sfp_check( 'vip core: the img tag has the remote src and a srcset', false !== strpos( $core_tag, 'src="' . SFP_TEST_PROD ) && false !== strpos( $core_tag, 'srcset="' ) && 1 === preg_match( '/[?&](resize=200(,|%2C)200|w=200)(&|&amp;|")/', $core_tag ), $core_tag );
	$pin_style( 'standard' );
	// The database may already hold VIP's resize entries (VIP saves them), so compare with and without this plugin's filter.
	$std_meta = wp_get_attachment_metadata( $core_id );
	remove_filter( 'wp_get_attachment_metadata', 'sfp_inject_remote_image_sizes', 20 );
	$raw_meta = wp_get_attachment_metadata( $core_id );
	add_filter( 'wp_get_attachment_metadata', 'sfp_inject_remote_image_sizes', 20, 2 );
	sfp_check( 'standard core: the plugin leaves the attachment data exactly as it is stored', $std_meta === $raw_meta, array( 'with' => $std_meta['sizes'] ?? null, 'without' => $raw_meta['sizes'] ?? null ) );
	$pin_style( 'vip' );
}

$pin_style( 'standard' );
sfp_check( 'standard content: a srcset is still made up (unchanged)', false !== strpos( sfp_enhance_content_images( '<img src="' . $vip_missing . '">' ), 'srcset=' ) );
wp_delete_file( "$vip_here_dir/$vip_here" );
@rmdir( $vip_here_dir );
@rmdir( $uploads_dir['basedir'] . '/2099' );

// --- Layout: the remote uploads path comes from the site setup and can be overridden.
$expected_remote_path = $expected_base . ( is_multisite() && ! is_subdomain_install() && ! is_main_site() ? '/sites/' . get_current_blog_id() : '' );
sfp_equals( 'remote uploads path follows the site setup', $expected_remote_path, 'sfp_get_remote_uploads_path' );
add_filter( 'sfp_remote_uploads_path', fn() => '/custom/uploads' );
sfp_equals( 'the sfp_remote_uploads_path filter overrides it', '/custom/uploads', 'sfp_get_remote_uploads_path' );
sfp_equals( 'the filter also reaches the remote URL', SFP_TEST_PROD . '/custom/uploads/2026/a.jpg', fn() => sfp_construct_remote_url( '2026/a.jpg' ) );
remove_all_filters( 'sfp_remote_uploads_path' );
$needles = sfp_get_uploads_needles();
sfp_check( 'uploads needles all end with a slash', $needles === array_filter( $needles, fn( $n ) => '/' === substr( $n, -1 ) && '/' === $n[0] ), $needles );
sfp_check( 'uploads needles include this site\'s uploads path and the standard one', in_array( rtrim( (string) wp_parse_url( content_url( '/uploads' ), PHP_URL_PATH ), '/' ) . '/', $needles, true ) && in_array( '/wp-content/uploads/', $needles, true ), $needles );
$own_folder = basename( rtrim( (string) wp_parse_url( content_url(), PHP_URL_PATH ), '/' ) );
sfp_equals( 'pasted URL with this site\'s own content folder name is cut at it', 'https://a.com', fn() => sfp_extract_domain_from_url( "https://a.com/$own_folder/uploads/" ) );
sfp_equals( 'a folder that only starts like the content folder is kept', 'https://a.com/wp-contents-blog', fn() => sfp_extract_domain_from_url( 'https://a.com/wp-contents-blog/' ) );

// --- Helpers: local fallback folder, notes, file type, remembered file checks.
foreach ( array( '' => 'sfp-images', null => 'sfp-images', 0 => 'sfp-images', '../x' => 'sfp-images', '/abs' => 'sfp-images', 'my-dir' => 'my-dir', 'a/b' => 'a/b' ) as $in => $out ) {
	sfp_equals( 'normalize local dir ' . wp_json_encode( $in ), $out, fn() => sfp_normalize_local_dir( $in ) );
}
sfp_equals( 'normalize local dir: an array gets the default', 'sfp-images', fn() => sfp_normalize_local_dir( array( 'x' ) ) );
sfp_check( 'constant note names the constant and the value', false !== strpos( sfp_constant_note( 'STAGE_FILE_PROXY_URL', 'https://a.com' ), '<code>STAGE_FILE_PROXY_URL</code>' ) && false !== strpos( sfp_constant_note( 'X', 'https://a.com' ), '<code>https://a.com</code>' ) );
sfp_check( 'constant note escapes the value', false === strpos( sfp_constant_note( 'X', '<script>alert(1)</script>' ), '<script>' ) );
sfp_check( 'constant note says (empty) for an empty constant', false !== strpos( sfp_constant_note( 'X', '' ), '(empty)' ) );
$tmp_png = wp_tempnam( 'sfp.txt' );
file_put_contents( $tmp_png, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ) );
sfp_equals( 'mime type is read from the file content when fileinfo exists', function_exists( 'finfo_open' ) ? 'image/png' : 'text/plain', fn() => sfp_get_mime_type( $tmp_png ) );
sfp_check( 'mime type always returns a type, even for a missing file', is_string( sfp_get_mime_type( $tmp_png . '.missing' ) ) && '' !== sfp_get_mime_type( $tmp_png . '.missing' ) );
sfp_equals( 'mime type falls back to the extension', 'image/jpeg', fn() => sfp_get_mime_type( '/no/such/dir/photo.JPG' ) );
sfp_equals( 'file check: existing file', true, fn() => sfp_local_file_exists( $tmp_png ) );
wp_delete_file( $tmp_png );
sfp_equals( 'file check: the first answer is remembered for the request', true, fn() => sfp_local_file_exists( $tmp_png ) );
$new_path = $tmp_png . '.later';
sfp_equals( 'file check: missing file', false, fn() => sfp_local_file_exists( $new_path ) );
file_put_contents( $new_path, 'x' );
sfp_equals( 'file check: a file created later is not seen again in the same request', false, fn() => sfp_local_file_exists( $new_path ) );
wp_delete_file( $new_path );

// --- Speed shortcuts must not change results: content without a local uploads URL is returned untouched.
$no_local = '<p>Hi</p><img src="https://cdn.example.com/a.jpg"><div style="background:url(https://cdn.example.com/b.jpg)"></div>';
sfp_equals( 'content without a local uploads URL is unchanged', $no_local, fn() => sfp_enhance_content_images( $no_local ) );
sfp_equals( 'image block without a local uploads URL is unchanged', $no_local, fn() => sfp_render_block_core_image( $no_local, array( 'blockName' => 'core/image' ) ) );

// --- Where the VIP mu-plugins are installed: the size list must equal what VIP's own generator builds.
if ( defined( 'WPMU_PLUGIN_DIR' ) && file_exists( WPMU_PLUGIN_DIR . '/files/class-image-sizes.php' ) && function_exists( 'a8c_files_maybe_inject_image_sizes' ) && $an_image ) {
	require_once WPMU_PLUGIN_DIR . '/files/class-image.php';
	require_once WPMU_PLUGIN_DIR . '/files/class-image-sizes.php';
	$strip   = fn( $sizes ) => array_map( fn( $s ) => array( $s['file'], $s['width'], $s['height'] ), $sizes );
	$samples = array(
		'landscape 2000x1125' => array( 'file' => '2026/10/hero.png', 'width' => 2000, 'height' => 1125, 'sizes' => array() ),
		'portrait 800x1600'   => array( 'file' => '2026/10/tall.jpg', 'width' => 800, 'height' => 1600, 'sizes' => array() ),
		'small 194x194'       => array( 'file' => '2021/01/favicon.png', 'width' => 194, 'height' => 194, 'sizes' => array() ),
	);
	foreach ( $samples as $label => $sample ) {
		$ours = sfp_build_remote_image_sizes( $sample, 'image/png', $an_image[0] ); // First: VIP's class adds filters of its own.
		$prop = new ReflectionProperty( 'Automattic\VIP\Files\ImageSizes', 'sizes' );
		$prop->setValue( null, null );
		remove_filter( 'intermediate_image_sizes_advanced', 'sfp_image_sizes_advanced', 99 );
		$vip = ( new Automattic\VIP\Files\ImageSizes( $an_image[0], $sample ) )->generate_sizes_meta();
		add_filter( 'intermediate_image_sizes_advanced', 'sfp_image_sizes_advanced', 99 );
		remove_filter( 'intermediate_image_sizes', 'wpcom_intermediate_sizes' );
		remove_filter( 'intermediate_image_sizes_advanced', 'wpcom_intermediate_sizes' );
		sfp_check( "vip oracle ($label): our size list equals VIP's own generator", $strip( $vip ) === $strip( $ours ) && count( $ours ) > 0, array( 'ours' => $strip( $ours ), 'vip' => $strip( $vip ) ) );
	}
}

// --- Settings page with constants: read-only fields that show the value in use and name the constant.
// STAGE_FILE_PROXY_URL is already defined for this run. The other two are defined here, so keep this block last in part 1.
if ( ! defined( 'STAGE_FILE_PROXY_MODE' ) && ! defined( 'STAGE_FILE_PROXY_LOCAL_DIR' ) ) {
	define( 'STAGE_FILE_PROXY_MODE', 'photon' );
	define( 'STAGE_FILE_PROXY_LOCAL_DIR', 'sfp-images-x' );
	$defined_style_here = ! defined( 'STAGE_FILE_PROXY_IMAGE_STYLE' );
	if ( $defined_style_here ) {
		define( 'STAGE_FILE_PROXY_IMAGE_STYLE', 'vip' );
	}
	$render = function ( $callback ) {
		ob_start();
		$callback();
		return ob_get_clean();
	};
	$url_html  = $render( 'sfp_url_callback' );
	$mode_html = $render( 'sfp_mode_callback' );
	$dir_html  = $render( 'sfp_local_dir_callback' );
	$sec_html  = $render( 'sfp_section_callback' );
	if ( $defined_style_here ) {
		sfp_check( 'constants: the section lists STAGE_FILE_PROXY_IMAGE_STYLE', false !== strpos( $sec_html, 'STAGE_FILE_PROXY_IMAGE_STYLE</code>' ) || false !== strpos( $sec_html, 'STAGE_FILE_PROXY_IMAGE_STYLE,' ) || false !== strpos( $sec_html, 'STAGE_FILE_PROXY_IMAGE_STYLE<' ), $sec_html );
		remove_all_filters( 'sfp_remote_image_style' );
		sfp_equals( 'constants: STAGE_FILE_PROXY_IMAGE_STYLE sets the style when no filter changes it', 'vip', 'sfp_get_remote_image_style' );
		add_filter( 'sfp_remote_image_style', fn() => 'standard' );
		sfp_equals( 'constants: the sfp_remote_image_style filter has the last word', 'standard', 'sfp_get_remote_image_style' );
	}
	// The image style is chosen automatically, so the settings page shows no notice about it, for either style.
	foreach ( array( 'vip', 'standard' ) as $style_name ) {
		$pin_style( $style_name );
		$section = $render( 'sfp_section_callback' );
		sfp_check( "settings: no image style notice for the $style_name style", false === strpos( $section, 'Remote image URLs' ) && false === strpos( $section, 'style is detected' ) && false === strpos( $section, 'hero.png' ), $section );
	}
	sfp_check( 'constants: URL field is read-only and shows the domain in use', 1 === preg_match( '/id="sfp-url"[^>]*value="' . preg_quote( SFP_TEST_PROD, '/' ) . '"[^>]*disabled/', $url_html ), $url_html );
	sfp_check( 'constants: URL note names the constant', false !== strpos( $url_html, 'STAGE_FILE_PROXY_URL' ) && false !== strpos( $url_html, 'ignored while the constant is defined' ) );
	sfp_check( 'constants: mode field is read-only', 1 === preg_match( '/<select id="sfp-mode"[^>]*disabled/', $mode_html ), $mode_html );
	sfp_check( 'constants: mode dropdown selects the constant mode', 1 === preg_match( '/<option value="photon"[^>]*selected/', $mode_html ) && 0 === preg_match( '/<option value="header"[^>]*selected/', $mode_html ), $mode_html );
	sfp_check( 'constants: mode note names the constant and its value', false !== strpos( $mode_html, 'STAGE_FILE_PROXY_MODE' ) && false !== strpos( $mode_html, '<code>photon</code>' ) );
	sfp_check( 'constants: local dir field is read-only and shows the constant value', 1 === preg_match( '/id="sfp-local-dir"[^>]*value="sfp-images-x"[^>]*disabled/', $dir_html ), $dir_html );
	sfp_check( 'constants: local dir note names the constant', false !== strpos( $dir_html, 'STAGE_FILE_PROXY_LOCAL_DIR' ) );
	sfp_check( 'constants: the section lists the constants in use', false !== strpos( $sec_html, 'STAGE_FILE_PROXY_URL' ) && false !== strpos( $sec_html, 'STAGE_FILE_PROXY_MODE' ) && false !== strpos( $sec_html, 'STAGE_FILE_PROXY_LOCAL_DIR' ), $sec_html );
	sfp_check( 'constants: a field that is not submitted keeps the saved value (all three sanitizers)', sfp_sanitize_url( null ) === get_option( 'sfp_url' ) && sfp_sanitize_mode( null ) === get_option( 'sfp_mode', 'header' ) && sfp_sanitize_local_dir( null ) === get_option( 'sfp_local_dir', '' ) );
}

// --- No PHP notices, warnings or deprecations came from the plugin during part 1.
$own = array_filter( $GLOBALS['sfp_errors'], fn( $e ) => false !== strpos( $e, 'stage-file-proxy.php' ) );
sfp_check( 'part 1: the plugin raised no PHP notices, warnings or deprecations', empty( $own ), array_values( $own ) );

// =====================================================================
// PART 2: real HTTP requests
// =====================================================================

// The test server answers for the main site, so uploads and transients are created there; clean up in the same place.
$was_main_site = is_main_site();
if ( is_multisite() ) {
	switch_to_blog( get_main_site_id() );
}
$main_uploads = wp_upload_dir();

$GLOBALS['sfp_host'] = (string) wp_parse_url( get_home_url( get_main_site_id() ), PHP_URL_HOST );
putenv( 'SFP_WP_LOAD=' . ABSPATH . 'wp-load.php' );

$docroot = sys_get_temp_dir() . '/sfp-prod-' . getmypid();
$png     = $docroot . $expected_base . '/2099/01/sfp-test.png';
wp_mkdir_p( dirname( $png ) );
$img = imagecreatetruecolor( 100, 50 );
imagepng( $img, $png );
$png_bytes = file_get_contents( $png );

$prod = sfp_start_server( $docroot, __DIR__ . '/prod-router.php' );
$site = sfp_start_server( ABSPATH, __DIR__ . '/router.php' );
$org  = 'http://127.0.0.1:' . $prod['port'];
$log  = WP_CONTENT_DIR . '/debug.log';
$log0 = file_exists( $log ) ? filesize( $log ) : 0;

$img_size_of = fn( $r ) => @getimagesizefromstring( $r['body'] );
$cfg          = fn( $mode, $url = null, $extra = array() ) => array_merge(
	array( 'X-SFP-Mode: ' . $mode, 'X-SFP-Url: ' . ( $url ?? $org ) ),
	$extra
);
$get = fn( $uri, $headers ) => sfp_request( $site['port'], $uri, $headers );
$loc = fn( $r ) => $r['headers']['location'] ?? null;
// Uploads URL path of the main site, as its own web request sees it. When this script runs for a subsite,
// WordPress still holds that subsite's content URL constant, so the path is built from the main site's home URL.
$up  = $was_main_site ? $up_path : rtrim( (string) wp_parse_url( get_home_url( get_main_site_id() ), PHP_URL_PATH ), '/' ) . $expected_base;
$ro  = $org . $expected_base;

$cases = array(
	'header: redirects to the remote file'               => array( "$up/2026/10/a.jpg", $cfg( 'header' ), "$ro/2026/10/a.jpg" ),
	'header: keeps the query string'                     => array( "$up/2026/10/a.jpg?ver=1", $cfg( 'header' ), "$ro/2026/10/a.jpg?ver=1" ),
	'header: URL typed with uploads path'                => array( "$up/2026/10/a.jpg", $cfg( 'header', "$ro/" ), "$ro/2026/10/a.jpg" ),
	'header: URL typed with trailing slash'              => array( "$up/2026/10/a.jpg", $cfg( 'header', "$org/" ), "$ro/2026/10/a.jpg" ),
	'header: resized name goes to the remote as is'      => array( "$up/2026/10/a-300x200.jpg", $cfg( 'header' ), "$ro/2026/10/a-300x200.jpg" ),
	'header: upper-case file extension'                  => array( "$up/2026/10/A.JPG", $cfg( 'header' ), "$ro/2026/10/A.JPG" ),
	'header: encoded space in the file name'             => array( "$up/2026/10/my%20photo.jpg", $cfg( 'header' ), "$ro/2026/10/my%20photo.jpg" ),
	'header: upper-case folder names in the request'     => array( strtoupper( $up ) . '/2026/10/a.jpg', $cfg( 'header' ), "$ro/2026/10/a.jpg" ),
	'header: file type that is not an image'             => array( "$up/2026/10/report.pdf", $cfg( 'header' ), "$ro/2026/10/report.pdf" ),
	'header: file with size-like text in the name'       => array( "$up/2026/10/photo-2020x2020-final.jpg", $cfg( 'header' ), "$ro/2026/10/photo-2020x2020-final.jpg" ),
	'header: VIP ?resize query is kept'                  => array( "$up/2026/10/a.png?resize=200,200", $cfg( 'header' ), "$ro/2026/10/a.png?resize=200,200" ),
	'header: VIP ?w, ?h and ?crop query is kept'         => array( "$up/2026/10/a.png?w=200&h=200&crop=1", $cfg( 'header' ), "$ro/2026/10/a.png?w=200&h=200&crop=1" ),
	'photon: plain file redirects to the remote'         => array( "$up/2026/10/a.jpg", $cfg( 'photon' ), "$ro/2026/10/a.jpg" ),
	'photon: resize request passes the size'             => array( "$up/2026/10/a-300x200.jpg", $cfg( 'photon' ), "$ro/2026/10/a.jpg?w=300&h=200" ),
	'photon: crop request passes the crop size'          => array( "$up/2026/10/a-300x200c.jpg", $cfg( 'photon' ), "$ro/2026/10/a.jpg?w=300&h=200&resize=300,200" ),
	'lorempixel: failed remote redirects to the service' => array( "$up/2026/10/missing.jpg", $cfg( 'lorempixel' ), 'http://lorempixel.com/800/600' ),
	'lorempixel: failed resize asks for the same size'    => array( "$up/2026/10/missing-320x240.jpg", $cfg( 'lorempixel' ), 'http://lorempixel.com/320/240' ),
);
foreach ( $cases as $name => $c ) {
	$r = $get( $c[0], $c[1] );
	sfp_check( $name, $loc( $r ) === $c[2], array( 'status' => $r['status'], 'location' => $loc( $r ), 'expected' => $c[2], 'body' => substr( $r['body'], 0, 120 ) ) );
	sfp_check( "$name (no PHP errors in the page)", ! preg_match( '/Warning:|Notice:|Deprecated:|Fatal error|Stack trace/', $r['body'] ), substr( $r['body'], 0, 200 ) );
}

// Wrong or missing configuration must end in the plugin's error message, not a crash or an open redirect.
// Local mode caches a failed lookup per request URI (without expiry), so every case uses its own file name.
$error_cases = array(
	'forgot the URL (empty constant)'      => array( 'err-1.jpg', $cfg( 'header', 'NONE' ) ),
	'download mode, remote file is 404'    => array( 'err-2.jpg', $cfg( 'download' ) ),
	'download mode, remote is down'        => array( 'err-3.jpg', $cfg( 'download', 'http://127.0.0.1:1' ) ),
	'unknown mode behaves like download'   => array( 'err-4.jpg', $cfg( 'Header' ) ),
	'local mode, no fallback images'       => array( 'err-5.jpg', $cfg( 'local', null, array( 'X-SFP-Local-Dir: sfp-no-such-dir' ) ) ),
	'local mode, no URL and no images'     => array( 'err-6.jpg', $cfg( 'local', 'NONE', array( 'X-SFP-Local-Dir: sfp-no-such-dir' ) ) ),
	'local mode, folder outside the theme' => array( 'err-7.jpg', $cfg( 'local', null, array( 'X-SFP-Local-Dir: ../../../../..' ) ) ),
	'local mode, absolute folder'          => array( 'err-8.jpg', $cfg( 'local', null, array( 'X-SFP-Local-Dir: ' . ABSPATH ) ) ),
);
foreach ( $error_cases as $name => $c ) {
	$r = $get( "$up/2026/10/" . $c[0], $c[1] );
	sfp_check( "error: $name", false !== strpos( $r['body'], 'SFP tried to load' ) && null === $loc( $r ), array( 'status' => $r['status'], 'type' => $r['headers']['content-type'] ?? null, 'location' => $loc( $r ), 'body' => substr( $r['body'], 0, 160 ) ) );
	delete_transient( 'sfp_image_' . md5( "$up/2026/10/" . $c[0] ) );
}
delete_transient( 'sfp-replacement-images' );
delete_transient( 'sfp_image_' . md5( "$up/2026/10/missing.jpg" ) );

// Subsites of a network: the request goes to the main site's server, by path (subdirectory) or by host (subdomain).
if ( is_multisite() ) {
	$checked_subsites = 0;
	foreach ( get_sites( array( 'number' => 4, 'site__not_in' => array( get_main_site_id() ), 'fields' => 'ids' ) ) as $site_id ) {
		if ( $checked_subsites >= 2 ) {
			break;
		}
		$details = get_blog_details( $site_id );
		if ( is_subdomain_install() ) {
			$host_header = array( 'Host: ' . $details->domain );
			$prefix      = '';
		} else {
			$host_header = array();
			$prefix      = rtrim( $details->path, '/' );
		}
		// Some networks redirect a whole subsite elsewhere; there is nothing for the plugin to do there.
		$home = $get( $prefix . '/', array_merge( $cfg( 'header' ), $host_header ) );
		if ( $home['status'] >= 300 && $home['status'] < 400 ) {
			continue;
		}
		++$checked_subsites;
		$r = $get( "$prefix$up/sites/$site_id/2026/10/a.jpg", array_merge( $cfg( 'header' ), $host_header ) );
		sfp_check( "subsite $site_id: file request is sent to the remote with its sites/N folder", $loc( $r ) === "$ro/sites/$site_id/2026/10/a.jpg", array( 'status' => $r['status'], 'location' => $loc( $r ) ) );
		$r = $get( "$prefix$up/sites/$site_id/2026/10/a-300x200.jpg", array_merge( $cfg( 'photon' ), $host_header ) );
		sfp_check( "subsite $site_id: photon resize request", $loc( $r ) === "$ro/sites/$site_id/2026/10/a.jpg?w=300&h=200", array( 'status' => $r['status'], 'location' => $loc( $r ) ) );
	}
}

// Requests that must not be touched by the plugin.
$r = $get( '/', $cfg( 'header' ) );
sfp_check( 'a normal page is not redirected by the plugin', null === $loc( $r ) || false === strpos( (string) $loc( $r ), $org ), $loc( $r ) );
sfp_check( 'a normal page does not show the plugin error', false === strpos( $r['body'], 'SFP tried to load' ) );
$r = $get( (string) wp_parse_url( plugin_dir_url( dirname( __DIR__ ) . '/stage-file-proxy.php' ), PHP_URL_PATH ) . 'stage-file-proxy.php', $cfg( 'header' ) );
sfp_check( 'opening the plugin file directly shows no PHP errors', ! preg_match( '/Warning:|Notice:|Deprecated:|Fatal error/', $r['body'] ), substr( $r['body'], 0, 200 ) );

// Download mode: saves the file, serves it, and resizes on demand.
$saved = $main_uploads['basedir'] . '/2099/01';
$r     = $get( "$up/2099/01/sfp-test.png", $cfg( 'download' ) );
sfp_check( 'download: serves the remote file', $png_bytes === $r['body'] && 0 === strpos( $r['headers']['content-type'] ?? '', 'image/png' ), array( 'status' => $r['status'], 'type' => $r['headers']['content-type'] ?? null, 'bytes' => strlen( $r['body'] ) ) );
sfp_check( 'download: saves the file in uploads', file_exists( "$saved/sfp-test.png" ) && filesize( "$saved/sfp-test.png" ) === strlen( $png_bytes ) );
sfp_check( 'download: sends the right Content-Length', (string) strlen( $png_bytes ) === ( $r['headers']['content-length'] ?? '' ), $r['headers']['content-length'] ?? null );
foreach ( glob( "$saved/*" ) ?: array() as $f ) {
	wp_delete_file( $f );
}
$r    = $get( "$up/2099/01/sfp-test-50x40c.png", $cfg( 'download' ) );
$size = @getimagesizefromstring( $r['body'] );
sfp_check( 'download: crop resize returns exactly 50x40', $size && 50 === $size[0] && 40 === $size[1], array( 'status' => $r['status'], 'size' => $size, 'body' => substr( $r['body'], 0, 100 ) ) );
foreach ( glob( "$saved/*" ) ?: array() as $f ) {
	wp_delete_file( $f );
}
$r    = $get( "$up/2099/01/sfp-test-50x40.png", $cfg( 'download' ) );
$size = @getimagesizefromstring( $r['body'] );
sfp_check( 'download: resize keeps the aspect ratio (50x25)', $size && 50 === $size[0] && 25 === $size[1], $size );
foreach ( glob( "$saved/*" ) ?: array() as $f ) {
	wp_delete_file( $f );
}
// A resize argument must not turn a small copy into the saved original (the fake production site answers ?w= with a 10x5 image).
$r    = $get( "$up/2099/01/sfp-test.png?w=50", array_merge( $cfg( 'download' ), array( 'X-SFP-Style: vip' ) ) );
$size = $img_size_of( $r );
sfp_check( 'vip download: the original is fetched without the resize argument', $size && 100 === $size[0] && 50 === $size[1] && file_exists( "$saved/sfp-test.png" ) && filesize( "$saved/sfp-test.png" ) === strlen( $png_bytes ), array( 'status' => $r['status'], 'size' => $size, 'saved' => file_exists( "$saved/sfp-test.png" ) ? filesize( "$saved/sfp-test.png" ) : null ) );
foreach ( glob( "$saved/*" ) ?: array() as $f ) {
	wp_delete_file( $f );
}
$r    = $get( "$up/2099/01/sfp-test.png?w=50", array_merge( $cfg( 'download' ), array( 'X-SFP-Style: standard' ) ) );
$size = $img_size_of( $r );
sfp_check( 'standard download: unchanged, the remote answer is served as it comes', $size && 10 === $size[0] && 5 === $size[1], array( 'status' => $r['status'], 'size' => $size ) );
foreach ( glob( "$saved/*" ) ?: array() as $f ) {
	wp_delete_file( $f );
}
$r = $get( "$up/2099/01/sfp-test-50x40.png", $cfg( 'photon' ) );
sfp_check( 'photon: nothing is saved locally', empty( glob( "$saved/*" ) ) );

// No PHP problems may reach the log from the plugin.
clearstatcache();
$new = file_exists( $log ) && filesize( $log ) > $log0 ? substr( (string) file_get_contents( $log ), $log0 ) : '';
sfp_check( 'part 2: debug.log has no entries from stage-file-proxy', false === strpos( $new, 'stage-file-proxy' ), substr( $new, 0, 600 ) );

// --- Clean up.
foreach ( array( $prod, $site ) as $srv ) {
	proc_terminate( $srv['proc'] );
	proc_close( $srv['proc'] );
}
foreach ( glob( "$saved/*" ) ?: array() as $f ) {
	wp_delete_file( $f );
}
@rmdir( $saved );
@rmdir( $main_uploads['basedir'] . '/2099' );
$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $docroot, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $items as $item ) {
	$item->isDir() ? @rmdir( $item->getPathname() ) : wp_delete_file( $item->getPathname() );
}
@rmdir( $docroot );
if ( is_multisite() ) {
	restore_current_blog();
}

// --- Report.
sfp_finish();
