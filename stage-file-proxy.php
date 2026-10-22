<?php
/*
	Plugin Name: Stage File Proxy
	Plugin URI: http://alleyinteractive.com/
	Description: Get only the files you need from your production environment. Don't ever run this in production!
	Version: 101
	Requires PHP: 8.0
	Requires at least: 5.9
	Author: Austin Smith, Alley Interactive
	Author URI: http://www.alleyinteractive.com/
*/

/**
 * A very important mission we have is to shut up all errors on static-looking paths, otherwise errors
 * are going to screw up the header or download & serve process. So this plugin has to execute first.
 *
 * We're also going to *assume* that if a request for /wp-content/uploads/ causes PHP to load, it's
 * going to be a 404 and we should go and get it from the remote server.
 *
 * Developers need to know that this stuff is happening and should generally understand how this plugin
 * works before they employ it.
 *
 * CONFIGURATION:
 * - Set STAGE_FILE_PROXY_URL constant to your production domain (e.g., 'https://us.example.com')
 * - You can provide either just the domain or the full URL including wp-content/uploads/
 * - The plugin automatically extracts the domain and handles path structure correctly
 * - The plugin preserves the complete wp-content path structure when constructing remote URLs
 *
 * SUBDIRECTORY HANDLING:
 * - Local:  https://example.test/us/wp-content/uploads/2023/01/image.jpg
 * - Remote: https://us.example.com/wp-content/uploads/2023/01/image.jpg
 * - The plugin intelligently strips local subdirectory paths (/us/) when building remote URLs
 *
 * The dynamic resizing portion was adapted from dynamic-image-resizer.
 * See: http://wordpress.org/plugins/dynamic-image-resizer/
 */

register_activation_hook( __FILE__, 'sfp_set_redirect_flag' );
add_action( 'admin_init', 'sfp_activation_redirect' );

/**
 * Sets an option flag when the plugin is activated.
 *
 * Hooked to the plugin activation hook. The flag is read, and then removed,
 * by sfp_activation_redirect() on the next admin page load.
 *
 * @return void
 */
function sfp_set_redirect_flag() {
	add_option( 'sfp_do_activation_redirect', true );
}

/**
 * Checks for the redirect flag and performs the redirection if set.
 *
 * Hooked to `admin_init`. The flag is deleted before redirecting to prevent
 * redirect loops, and no redirect happens when several plugins are activated
 * at once (`activate-multi`). AJAX, cron and REST requests, and users who
 * cannot manage options, are skipped and keep the flag for later.
 * Ends the request with exit() when it redirects.
 *
 * @return void
 */
function sfp_activation_redirect() {
	// Only a person using the dashboard should be redirected, not AJAX, cron or REST calls,
	// and only when they can open the settings page. The flag stays until then.
	if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( get_option( 'sfp_do_activation_redirect', false ) ) {
		// Delete the flag immediately to prevent redirection loops
		delete_option( 'sfp_do_activation_redirect' );

		// Avoid redirecting when activating multiple plugins at once
		if ( ! isset( $_GET['activate-multi'] ) ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=stage-file-proxy' ) );
			exit;
		}
	}
}

/**
 * Load SFP before anything else so we can shut up any other plugins' warnings.
 *
 * Hooked to `activated_plugin`. Moves this plugin to the top of the
 * `active_plugins` option so it loads first on later requests.
 *
 * @see http://wordpress.org/support/topic/how-to-change-plugins-load-order
 *
 * @return void
 */
function sfp_first() {
	// The folder name can differ (e.g. a Git download), so use the real basename.
	$plugin_path    = plugin_basename( __FILE__ );
	$active_plugins = get_option( 'active_plugins' );
	if ( ! is_array( $active_plugins ) ) {
		return;
	}
	$plugin_key = array_search( $plugin_path, $active_plugins, true );
	if ( $plugin_key ) { // if it's 0 it's the first plugin already, no need to continue
		array_splice( $active_plugins, $plugin_key, 1 );
		array_unshift( $active_plugins, $plugin_path );
		update_option( 'active_plugins', $active_plugins );
	}
}
add_action( 'activated_plugin', 'sfp_first' );

// Only requests for the uploads folder are handled. The folder is read from the site setup.
if ( sfp_is_uploads_request() ) {
	sfp_expect();
}

/**
 * Get the request URI as a path and query string.
 *
 * REQUEST_URI is not set under WP-CLI or cron, which gives an empty string.
 * Some proxies send the absolute form ("https://host/path"); only the path
 * and query string are kept.
 *
 * @return string The request URI, or an empty string when there is none.
 */
function sfp_get_request_uri() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

	if ( preg_match( '#^[a-z][a-z0-9+.-]*://[^/?\#]*(.*)$#i', $uri, $matches ) ) {
		$uri = '' === $matches[1] ? '/' : $matches[1];
	}

	return $uri;
}

/**
 * Get the URL path fragments that mark a request for an uploaded file.
 *
 * Built from the site setup, so it fits single sites, multisite, WordPress in
 * a subdirectory, a custom content folder (e.g. Bedrock's /app) and the UPLOADS
 * constant. Each fragment also comes without the site's subdirectory prefix,
 * because servers often rewrite that prefix away. The standard
 * /wp-content/uploads/ is always included as a safe fallback.
 *
 * @return string[] Path fragments, each with a trailing slash.
 */
function sfp_get_uploads_needles() {
	static $needles = null;

	if ( null !== $needles ) {
		return $needles;
	}

	$paths   = array();
	$paths[] = (string) wp_parse_url( content_url( '/uploads' ), PHP_URL_PATH );

	if ( defined( 'UPLOADS' ) && is_string( UPLOADS ) && '' !== trim( UPLOADS, '/' ) ) {
		$paths[] = (string) wp_parse_url( site_url( '/' . trim( UPLOADS, '/' ) ), PHP_URL_PATH );
	}

	$subdirectory = sfp_get_local_subdirectory_path();
	foreach ( $paths as $path ) {
		if ( '' !== $subdirectory && 0 === strpos( $path, $subdirectory . '/' ) ) {
			$paths[] = substr( $path, strlen( $subdirectory ) );
		}
	}
	$paths[] = '/wp-content/uploads';

	$needles = array();
	foreach ( $paths as $path ) {
		$path = rtrim( $path, '/' );
		if ( '' !== $path && ! in_array( $path . '/', $needles, true ) ) {
			$needles[] = $path . '/';
		}
	}

	return $needles;
}

/**
 * Check whether the current request is for a file in the uploads folder.
 *
 * @return bool True when the request URI contains an uploads path fragment.
 */
function sfp_is_uploads_request() {
	$uri = sfp_get_request_uri();

	if ( '' === $uri ) {
		return false;
	}

	foreach ( sfp_get_uploads_needles() as $needle ) {
		if ( false !== stripos( $uri, $needle ) ) {
			return true;
		}
	}

	return false;
}

/**
 * This function, triggered above, sets the chain in motion.
 *
 * Starts output buffering, turns off error display so notices cannot corrupt
 * the file response, and schedules sfp_dispatch() on `init`.
 *
 * @return void
 */
function sfp_expect() {
	ob_start();
	// ini_set() is on the disabled-functions list of some hosts.
	if ( function_exists( 'ini_set' ) ) {
		ini_set( 'display_errors', 'off' );
	}
	add_action( 'init', 'sfp_dispatch' );
}

/**
 * This function can fetch a remote image or resize a local one.
 *
 * If a cropped image is requested, and the original does not exist locally, it will take two runs of
 * this function to return the proper resized image, which is achieved by the header("Location: ...")
 * bits. The first run will fetch the remote image, the second will resize it.
 *
 * Ideally we could do this in one pass.
 *
 * The behaviour depends on the mode returned by sfp_get_mode():
 * - header:     redirect to the remote file.
 * - photon:     redirect to the remote file, passing resize arguments to Photon.
 * - download:   fetch the remote file, save it locally and serve it.
 * - local:      if the remote fetch fails, serve a random local replacement image.
 * - lorempixel: if the remote fetch fails, redirect to a placeholder service.
 *
 * The function never returns normally. It ends the request with exit() after
 * serving or redirecting, or through sfp_error() on failure.
 *
 * @return void
 */
function sfp_dispatch() {
	$mode          = sfp_get_mode();
	$relative_path = sfp_get_relative_path();
	$base_url      = sfp_get_base_url();

	// CHECK: If the mode requires a base URL but one isn't set, error out here.
	// $base_url is guaranteed to be a string (or empty string) by sfp_get_base_url().
	if ( empty( $base_url ) && 'local' !== $mode ) {
		sfp_error();
	}

	if ( 'header' === $mode ) {
		$redirect_url = sfp_construct_remote_url( $relative_path );
		header( "Location: " . $redirect_url );
		exit;
	}

	$doing_resize = false;
	// resize an image maybe
	if ( preg_match( '/(.+)(-r)?-([0-9]+)x([0-9]+)(c)?\.(jpe?g|png|gif)/iU', $relative_path, $matches ) ) {
		$doing_resize       = true;
		$resize             = array();
		$resize['filename'] = $matches[1] . '.' . $matches[6];
		$resize['width']    = $matches[3];
		$resize['height']   = $matches[4];
		$resize['crop']     = ! empty( $matches[5] );
		$resize['mode']     = substr( $matches[2], 1 );

		if ( 'photon' === $mode ) {
			$photon_base_url = sfp_construct_remote_url( $resize['filename'] );

			header( 'Location: ' . add_query_arg(
				array(
					'w'      => $resize['width'],
					'h'      => $resize['height'],
					'resize' => $resize['crop'] ? "{$resize['width']},{$resize['height']}" : null,
				),
				$photon_base_url
			) );
			exit;
		}

		$uploads_dir = wp_upload_dir( null, false );
		$basefile    = $uploads_dir['basedir'] . '/' . $resize['filename'];
		sfp_resize_image( $basefile, $resize );
		$relative_path = $resize['filename'];
	} else if ( 'photon' === $mode ) {
		$photon_redirect_url = sfp_construct_remote_url( $relative_path );
		header( "Location: " . $photon_redirect_url );
		exit;
	}

	// Download a full-size original from the remote server.
	// If it needs to be resized, it will be on the next load.
	// On a VIP site a request like hero.png?w=200 would return a small copy that is then saved as hero.png,
	// so the original is fetched without the resize arguments.
	$remote_url = sfp_construct_remote_url( 'vip' === sfp_get_remote_image_style() ? explode( '?', $relative_path, 2 )[0] : $relative_path );

	/**
	 * Filter: sfp_http_remote_args
	 *
	 * Alter the args of the GET request.
	 *
	 * @param array $remote_http_request_args The request arguments.
	 */
	$remote_http_request_args = apply_filters( 'sfp_http_remote_args', array( 'timeout' => 30 ) );
	$remote_request           = wp_remote_get( $remote_url, $remote_http_request_args );

	if ( is_wp_error( $remote_request ) || ( isset( $remote_request['response']['code'] ) && $remote_request['response']['code'] > 400 ) ) {
		// If local mode, failover to local files
		if ( 'local' === $mode ) {
			// Cache replacement image by hashed request URI
			$transient_key = 'sfp_image_' . md5( sfp_get_request_uri() );
			if ( false === ( $basefile = get_transient( $transient_key ) ) ) {
				$basefile = sfp_get_random_local_file_path( $doing_resize );
				set_transient( $transient_key, $basefile );
			}

			// CRITICAL CHECK: If sfp_get_random_local_file_path returned false (no images found), fail gracefully.
			if ( ! $basefile ) {
				sfp_error();
			}

			// Resize if necessary
			if ( $doing_resize ) {
				sfp_resize_image( $basefile, $resize );
			} else {
				sfp_serve_requested_file( $basefile );
			}
		} elseif ( 'lorempixel' === $mode ) {
			$width  = $doing_resize && ! empty( $resize['width'] ) ? $resize['width'] : 800;
			$height = $doing_resize && ! empty( $resize['height'] ) ? $resize['height'] : 600;
			header( 'Location: http://lorempixel.com/' . $width . '/' . $height );
			exit;
		} else {
			sfp_error();
		}
	}

	// we could be making some dangerous assumptions here, but if WP is setup normally, this will work:
	$path_parts = explode( '/', $remote_url );
	$name       = array_pop( $path_parts );

	if ( strpos( $name, '?' ) ) {
		list( $name, $crap ) = explode( '?', $name, 2 );
	}

	$month = array_pop( $path_parts );
	$year  = array_pop( $path_parts );

	$upload = wp_upload_bits( $name, null, $remote_request['body'], "$year/$month" );

	if ( ! empty( $upload['file'] ) && empty( $upload['error'] ) ) {
		// if there was some other sort of error, and the file now does not exist, we could loop on accident.
		// should think about some other strategies.
		if ( $doing_resize ) {
			sfp_dispatch();
		} else {
			sfp_serve_requested_file( $upload['file'] );
		}
	} else {
		sfp_error();
	}
}

/**
 * Resizes $basefile based on parameters in $resize
 *
 * Saves the resized copy next to the original, using the WordPress naming
 * pattern, and serves it. Does nothing if $basefile does not exist. Ends the
 * request through sfp_error() if the image editor cannot open the file.
 *
 * @param string $basefile Absolute path of the original image.
 * @param array  $resize {
 *     Resize parameters.
 *
 *     @type string $filename Relative path of the original image.
 *     @type string $width    Target width in pixels.
 *     @type string $height   Target height in pixels.
 *     @type bool   $crop     Whether to crop to the exact dimensions.
 *     @type string $mode     Resize mode; 'r' adds an `r-` prefix to the size suffix.
 * }
 * @return void
 */
function sfp_resize_image( $basefile, $resize ) {
	if ( file_exists( $basefile ) ) {
		$suffix = $resize['width'] . 'x' . $resize['height'];
		if ( $resize['crop'] ) {
			$suffix  .= 'c';
		}
		if ( 'r' == $resize['mode'] ) {
			$suffix = 'r-' . $suffix;
		}
		$img = wp_get_image_editor( $basefile );

		// wp_get_image_editor can return a WP_Error if the file exists but is corrupted.
		if ( is_wp_error( $img ) ) {
			sfp_error();
		}

		$img->resize( $resize['width'], $resize['height'], $resize['crop'] );
		$info             = pathinfo( $basefile );
		$path_to_new_file = $info['dirname'] . '/' . $info['filename'] . '-' . $suffix . '.' . $info['extension'];
		$img->save( $path_to_new_file );
		sfp_serve_requested_file( $path_to_new_file );
	}
}

/**
 * Serve the file directly.
 *
 * Discards the output buffer, sends the Content-Type and Content-Length
 * headers, streams the file and ends the request with exit().
 *
 * @param string $filename Absolute path of the file to serve.
 * @return void
 */
function sfp_serve_requested_file( $filename ) {
	$type = sfp_get_mime_type( $filename );

	// serve the image this one time (next time the webserver will do it for us)
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	header( 'Content-Type: ' . $type );
	header( 'Content-Length: ' . filesize( $filename ) );
	readfile( $filename );
	exit;
}

/**
 * Check whether a file exists, remembering the answer for the rest of the request.
 *
 * One image is checked by several filters (URL, srcset, attributes, content, blocks).
 * PHP does not cache a failed lookup, so each check would otherwise hit the disk again,
 * which is slow on network storage. Not used where a file is created during the request.
 *
 * @param string $path Absolute path of the file.
 * @return bool Whether the file existed at the first check.
 */
function sfp_local_file_exists( $path ) {
	static $known = array();

	if ( ! isset( $known[ $path ] ) ) {
		$known[ $path ] = file_exists( $path );
	}

	return $known[ $path ];
}

/**
 * Get the MIME type of a file.
 *
 * Uses the fileinfo extension when the host has it, and the file extension
 * otherwise, because some hosts do not ship or allow fileinfo.
 *
 * @param string $filename Absolute path of the file.
 * @return string The MIME type, or application/octet-stream when unknown.
 */
function sfp_get_mime_type( $filename ) {
	// A missing or unreadable file would make finfo_file() raise a warning, so it goes straight to the fallback.
	if ( function_exists( 'finfo_open' ) && is_readable( $filename ) ) {
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		if ( $finfo ) {
			$type = finfo_file( $finfo, $filename );
			if ( is_string( $type ) && '' !== $type ) {
				return $type;
			}
		}
	}

	$check = wp_check_filetype( $filename );

	return $check['type'] ? $check['type'] : 'application/octet-stream';
}

/**
 * Prevent WP from generating resized images on upload.
 *
 * Hooked to `intermediate_image_sizes_advanced`. The original sizes are kept
 * in a global so sfp_generate_metadata() can record them without creating files.
 *
 * @param array $sizes Image sizes WordPress would generate, keyed by size name.
 * @return array An empty array, so that WordPress generates no sizes.
 */
function sfp_image_sizes_advanced( $sizes ) {
	global $dynimg_image_sizes;

	// save the sizes to a global, because the next function needs them to lie to WP about what sizes were generated
	$dynimg_image_sizes = $sizes;

	// force WP to not make sizes by telling it there's no sizes to make
	return array();
}
add_filter( 'intermediate_image_sizes_advanced', 'sfp_image_sizes_advanced', 99 );

/**
 * Add fake metadata for resized images to trick WordPress into thinking they exist.
 *
 * Hooked to `wp_generate_attachment_metadata`. Real files for these sizes are
 * created on demand by sfp_dispatch() when first requested.
 *
 * @param array $meta Image metadata.
 * @return array Modified metadata with fake size entries.
 */
function sfp_generate_metadata( $meta ) {
	global $dynimg_image_sizes;

	if ( ! is_array( $dynimg_image_sizes ) ) {
		return $meta;
	}

	foreach ( $dynimg_image_sizes as $sizename => $size ) {
		// figure out what size WP would make this:
		$newsize = image_resize_dimensions( $meta['width'], $meta['height'], $size['width'], $size['height'], $size['crop'] );

		if ( $newsize ) {
			$info = pathinfo( $meta['file'] );
			$ext  = $info['extension'];
			$name = wp_basename( $meta['file'], ".$ext" );

			$suffix = "r-{$newsize[4]}x{$newsize[5]}";
			if ( $size['crop'] )
				$suffix .= 'c';

			// build the fake meta entry for the size in question
			$resized = array(
				'file'   => "{$name}-{$suffix}.{$ext}",
				'width'  => $newsize[4],
				'height' => $newsize[5],
			);

			$meta['sizes'][ $sizename ] = $resized;
		}
	}

	return $meta;
}
add_filter( 'wp_generate_attachment_metadata', 'sfp_generate_metadata' );

/**
 * Ensure remote images have proper metadata for responsive image generation.
 *
 * This function creates metadata for attachments that reference remote images,
 * enabling WordPress to generate proper srcset attributes even when files don't exist locally.
 * It sends a HEAD request to the remote file and downloads it to read its size.
 * When the size cannot be read, it falls back to 1200x800.
 *
 * Hooked to `wp_get_attachment_metadata`.
 *
 * @param array|false $metadata      Image metadata array or false if not found.
 * @param int         $attachment_id The attachment ID.
 * @return array|false Existing metadata unchanged, generated metadata for a remote-only image,
 *                     or false when the attachment has no file or URL.
 */
function sfp_ensure_remote_image_metadata( $metadata, $attachment_id ) {
	if ( ! empty( $metadata ) ) {
		return $metadata; // Metadata already exists
	}

	// Only images need generated metadata; this also avoids a remote request for other file types.
	if ( 0 !== strpos( (string) get_post_mime_type( $attachment_id ), 'image/' ) ) {
		return $metadata;
	}

	$file = get_attached_file( $attachment_id );
	if ( ! $file ) {
		return $metadata;
	}

	// Check if this is a remote-only image (doesn't exist locally)
	if ( sfp_local_file_exists( $file ) ) {
		return $metadata; // File exists locally, let WordPress handle it
	}

	// Try to get metadata from the remote image
	$attachment_url = wp_get_attachment_url( $attachment_id );
	if ( ! $attachment_url ) {
		return $metadata;
	}

	// If it's a local URL that doesn't exist, try to get it from remote
	$local_base = sfp_get_local_base_url();
	if ( strpos( $attachment_url, $local_base ) !== false ) {
		$remote_url = sfp_rewrite_local_to_remote( $attachment_url );

		// Try to get image dimensions from remote URL
		$image_info = wp_remote_head( $remote_url );
		if ( ! is_wp_error( $image_info ) ) {
			// Returns a string, or an array for repeated headers; strpos() needs a string in PHP 8.
			$content_type = wp_remote_retrieve_header( $image_info, 'content-type' );

			// If it's an image, try to get dimensions
			if ( is_string( $content_type ) && strpos( $content_type, 'image/' ) === 0 ) {
				// Create basic metadata structure
				$pathinfo = pathinfo( $file );
				$metadata = array(
					'width'      => 0,
					'height'     => 0,
					'file'       => _wp_relative_upload_path( $file ),
					'sizes'      => array(),
					'image_meta' => array(
						'aperture'          => '',
						'credit'            => '',
						'camera'            => '',
						'caption'           => '',
						'created_timestamp' => '',
						'copyright'         => '',
						'focal_length'      => '',
						'iso'               => '',
						'shutter_speed'     => '',
						'title'             => '',
						'orientation'       => '',
						'keywords'          => array(),
					),
				);

				// Try to get actual dimensions if possible
				$temp_file = download_url( $remote_url );
				if ( ! is_wp_error( $temp_file ) ) {
					$image_size = wp_getimagesize( $temp_file );
					if ( $image_size ) {
						$metadata['width']  = $image_size[0];
						$metadata['height'] = $image_size[1];
					}
					wp_delete_file( $temp_file );
				}

				// If we couldn't get dimensions, use defaults
				if ( ! $metadata['width'] || ! $metadata['height'] ) {
					$metadata['width']  = 1200; // Default width
					$metadata['height'] = 800; // Default height
				}
			}
		}
	}

	return $metadata;
}
add_filter( 'wp_get_attachment_metadata', 'sfp_ensure_remote_image_metadata', 10, 2 );
add_action( 'admin_menu', 'sfp_admin_menu' );
add_action( 'admin_init', 'sfp_admin_init' );

/**
 * Add the Stage File Proxy Options page to the WordPress dashboard.
 *
 * Hooked to `admin_menu`. The page is under Settings and needs the
 * `manage_options` capability.
 *
 * @return void
 */
function sfp_admin_menu() {
	add_options_page( 'Stage File Proxy Options', 'Stage File Proxy', 'manage_options', 'stage-file-proxy', 'sfp_options_page' );
}

/**
 * Register settings, sections, and fields.
 *
 * Hooked to `admin_init`. Registers the `sfp_url`, `sfp_mode` and
 * `sfp_local_dir` options, each with its own sanitize callback.
 *
 * @return void
 */
function sfp_admin_init() {
	// Register individual options used by the plugin
	register_setting(
		'sfp-settings-group',
		'sfp_url',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sfp_sanitize_url',
		)
	);
	register_setting(
		'sfp-settings-group',
		'sfp_mode',
		array(
			'type'              => 'string',
			'default'           => 'header',
			'sanitize_callback' => 'sfp_sanitize_mode',
		)
	);
	register_setting(
		'sfp-settings-group',
		'sfp_local_dir',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sfp_sanitize_local_dir',
		)
	);

	add_settings_section(
		'sfp-main-section',
		'Proxy Configuration',
		'sfp_section_callback',
		'stage-file-proxy'
	);

	add_settings_field( 'sfp-url', 'Production URL', 'sfp_url_callback', 'stage-file-proxy', 'sfp-main-section' );
	add_settings_field( 'sfp-mode', 'Proxy Mode', 'sfp_mode_callback', 'stage-file-proxy', 'sfp-main-section' );
	add_settings_field( 'sfp-local-dir', 'Local Fallback Directory', 'sfp_local_dir_callback', 'stage-file-proxy', 'sfp-main-section' );
}

/**
 * Sanitize the URL input, ensuring it's valid and extracting only the domain part.
 *
 * @param string $input The URL submitted by the user.
 * @return string The sanitized domain URL or the old option value on error.
 */
function sfp_sanitize_url( $input ) {
	// Non-string input (e.g. set through WP-CLI or code) cannot be a valid URL.
	if ( ! is_string( $input ) ) {
		return get_option( 'sfp_url' );
	}

	// Pasted URLs often carry leading or trailing spaces; sanitize_url() would turn them into %20.
	$sanitized = sanitize_url( trim( $input ) );

	// Check if the URL is valid and uses a scheme the proxy can fetch from.
	if ( ! filter_var( $sanitized, FILTER_VALIDATE_URL ) || ! in_array( wp_parse_url( $sanitized, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
		add_settings_error( 'sfp_url', 'invalid-url', 'Please enter a valid, complete URL (including http:// or https://).', 'error' );
		return get_option( 'sfp_url' ); // Return previous value on failure
	}

	// Extract domain part and remove any wp-content/uploads paths
	$clean_url = sfp_extract_domain_from_url( $sanitized );

	return $clean_url;
}

/**
 * Sanitize the proxy mode, accepting only known modes.
 *
 * Surrounding whitespace and letter case are ignored, so " Header" is saved as "header".
 *
 * @param mixed $input The mode submitted by the user.
 * @return string A valid mode, or the previous value on error.
 */
function sfp_sanitize_mode( $input ) {
	$modes = array( 'header', 'download', 'photon', 'local', 'lorempixel' );
	$mode  = is_string( $input ) ? strtolower( trim( $input ) ) : '';

	return in_array( $mode, $modes, true ) ? $mode : get_option( 'sfp_mode', 'header' );
}

/**
 * Check whether a local fallback directory name could leave the theme folder.
 *
 * @param string $dir Directory name, relative to the active theme.
 * @return bool True for parent-directory jumps ("..") and absolute paths.
 */
function sfp_is_unsafe_local_dir( $dir ) {
	return (bool) preg_match( '#(^|[\\\\/])\.\.([\\\\/]|$)|^[\\\\/]|^[a-z]:#i', $dir );
}

/**
 * Sanitize the local fallback directory name.
 *
 * The directory is looked up inside the active theme, so parent-directory
 * jumps ("..") and absolute paths are rejected.
 *
 * @param mixed $input The directory submitted by the user. A value that is not a string means the
 *                     field was not submitted (disabled by a constant), so the saved value is kept.
 * @return string A safe relative directory name, or the previous value on error.
 */
function sfp_sanitize_local_dir( $input ) {
	// A field disabled by a constant is not submitted; keep the saved value instead of clearing it.
	if ( ! is_string( $input ) ) {
		return get_option( 'sfp_local_dir', '' );
	}

	$dir = trim( sanitize_text_field( $input ) );

	if ( sfp_is_unsafe_local_dir( $dir ) ) {
		add_settings_error( 'sfp_local_dir', 'invalid-local-dir', 'The local fallback directory must be a folder name inside your theme, without ".." or a leading slash.', 'error' );
		return get_option( 'sfp_local_dir', '' );
	}

	return $dir;
}

/**
 * Section callback for the plugin settings page.
 * Displays a description of the proxy configuration section, and lists the
 * PHP constants that are in use.
 *
 * @return void Outputs HTML.
 */
function sfp_section_callback() {
	echo '<p>Configure the remote production site URL and the operating mode for file retrieval.</p>';

	$in_use = array_filter( array( 'STAGE_FILE_PROXY_URL', 'STAGE_FILE_PROXY_MODE', 'STAGE_FILE_PROXY_LOCAL_DIR', 'STAGE_FILE_PROXY_IMAGE_STYLE' ), 'defined' );
	if ( $in_use ) {
		echo '<p><strong>Constants in use:</strong> <code>' . implode( '</code>, <code>', array_map( 'esc_html', $in_use ) ) . '</code>. ';
		echo 'Fields set by a constant are read-only here and show the value that is used. To change them, edit <code>wp-config.php</code>.</p>';
	}
}

/**
 * Build the note shown under a field that a PHP constant controls.
 *
 * @param string $name  Constant name.
 * @param string $value Constant value to show, already safe to print after escaping.
 * @return string HTML for the start of the field description.
 */
function sfp_constant_note( $name, $value ) {
	return 'Configuration overridden by <code>' . esc_html( $name ) . '</code> constant: <code>' . ( '' === $value ? '(empty)' : esc_html( $value ) ) . '</code>. The value saved on this page is ignored while the constant is defined.';
}

/**
 * Renders the URL field in the settings page.
 * Creates the input field for the production URL setting.
 * The field is read-only whenever the STAGE_FILE_PROXY_URL constant is defined, even
 * when it is empty. It then shows the domain the plugin uses, and a note names the
 * constant and its value, without any credentials in the URL.
 *
 * @return void Outputs HTML.
 */
function sfp_url_callback() {
	$disabled      = defined( 'STAGE_FILE_PROXY_URL' );
	$constant      = $disabled ? (string) STAGE_FILE_PROXY_URL : '';
	$disabled_attr = disabled( $disabled, true, false );
	// With a constant, show the value the plugin really uses (the domain part).
	$value = $disabled ? sfp_extract_domain_from_url( $constant ) : get_option( 'sfp_url' );

	echo '<input type="text" id="sfp-url" name="sfp_url" value="' . esc_attr( $value ) . '" placeholder="e.g., https://production.com" style="width: 100%; max-width: 400px;" ' . $disabled_attr . ' />';
	if ( $disabled ) {
		// Do not print credentials that may be part of the URL.
		echo '<p class="description">' . sfp_constant_note( 'STAGE_FILE_PROXY_URL', preg_replace( '#//[^/@]*@#', '//***@', $constant ) ) . '<br>';
		if ( '' === $constant ) {
			echo '<strong>Warning:</strong> the constant is empty, so no remote site is set and proxied file requests fail.<br>';
		}
		echo '<strong>Note:</strong> You can provide either the base domain (e.g., https://production.com) or full URL including wp-content/uploads path. The plugin will automatically extract the domain part and handle path structure correctly.</p>';
	} else {
		echo '<p class="description">The base domain URL of your remote environment (e.g., https://production.com). You can also provide the full URL including wp-content/uploads path - the plugin will automatically extract the domain. The plugin will automatically preserve the wp-content/uploads path structure when constructing remote URLs.</p>';
	}
}

/**
 * Renders the mode selection field in the settings page.
 * Creates the dropdown for selecting the proxy mode.
 * The field is read-only whenever the STAGE_FILE_PROXY_MODE constant is defined. It then
 * selects the mode the plugin uses, and a note names the constant and its value, with a
 * warning when the value is not a known mode.
 * Also prints inline JavaScript that shows the local directory field only
 * when the "local" mode is active.
 *
 * @return void Outputs HTML and JavaScript.
 */
function sfp_mode_callback() {
	$disabled      = defined( 'STAGE_FILE_PROXY_MODE' );
	$constant      = $disabled ? (string) STAGE_FILE_PROXY_MODE : '';
	$disabled_attr = disabled( $disabled, true, false );
	// With a constant, select the mode the plugin really uses (an empty constant means header).
	$value = $disabled ? ( $constant ? $constant : 'header' ) : get_option( 'sfp_mode', 'header' );

	$modes = array(
		'header'     => 'Header Redirect (Fastest, does not save file locally)',
		'download'   => 'Download (Fetches and saves the file locally)',
		'photon'     => 'Photon Redirect (Redirects resizing requests to Photon/Jetpack)',
		'local'      => 'Local Fallback (Serves a local replacement file if remote fails)',
		'lorempixel' => 'Lorempixel (Redirects to a placeholder service)',
	);

	echo '<select id="sfp-mode" name="sfp_mode" ' . $disabled_attr . '>';
	foreach ( $modes as $mode_key => $mode_label ) {
		$selected = selected( $mode_key, $value, false );
		echo '<option value="' . esc_attr( $mode_key ) . '"' . $selected . '>' . esc_html( $mode_label ) . '</option>';
	}
	echo '</select>';

	if ( $disabled ) {
		echo '<p class="description">' . sfp_constant_note( 'STAGE_FILE_PROXY_MODE', $constant ) . '</p>';
		if ( ! isset( $modes[ $value ] ) ) {
			echo '<p class="description"><strong>Warning:</strong> this is not a known mode (' . esc_html( implode( ', ', array_keys( $modes ) ) ) . '), so files are handled as in Download mode.</p>';
		}
	} else {
		echo '<p class="description">Choose the method Stage File Proxy uses to retrieve or serve files.</p>';
	}

	// Add JavaScript to show/hide local directory field based on mode selection
	?>
	<script type="text/javascript">
	jQuery(document).ready(function($) {
		function toggleLocalDirField() {
			var selectedMode = $('#sfp-mode').val();
			var localDirRow = $('#sfp-local-dir').closest('tr');

			// Check if mode is overridden by constant
			<?php if ( $disabled && 'local' === $value ) : ?>
				// Mode is set to 'local' by constant, always show
				localDirRow.show();
			<?php elseif ( $disabled ) : ?>
				// Mode is set to non-local by constant, always hide
				localDirRow.hide();
			<?php else : ?>
				// Mode is configurable via dropdown
				if (selectedMode === 'local') {
					localDirRow.show();
				} else {
					localDirRow.hide();
				}
			<?php endif; ?>
		}

		// Toggle on page load
		toggleLocalDirField();

		// Toggle when selection changes (only if not disabled by constant)
		<?php if ( ! $disabled ) : ?>
		$('#sfp-mode').on('change', toggleLocalDirField);
		<?php endif; ?>
	});
	</script>
	<?php
}

/**
 * Renders the local directory field in the settings page.
 * Creates the input field for specifying a local fallback directory.
 * Only visible when "Local Fallback" mode is selected.
 * The field is read-only whenever the STAGE_FILE_PROXY_LOCAL_DIR constant is defined. It then
 * shows the folder the plugin uses, and a note names the constant and its value, with a
 * warning when the value is unsafe and the default folder is used instead.
 *
 * @return void Outputs HTML.
 */
function sfp_local_dir_callback() {
	$disabled      = defined( 'STAGE_FILE_PROXY_LOCAL_DIR' );
	$constant      = $disabled ? (string) STAGE_FILE_PROXY_LOCAL_DIR : '';
	$disabled_attr = disabled( $disabled, true, false );
	// With a constant, show the folder the plugin really uses (an empty or unsafe value gets the default).
	$value = $disabled ? sfp_normalize_local_dir( $constant ) : get_option( 'sfp_local_dir', '' );

	echo '<input type="text" id="sfp-local-dir" name="sfp_local_dir" value="' . esc_attr( $value ) . '" style="width: 200px;" ' . $disabled_attr . ' />';

	if ( $disabled ) {
		echo '<p class="description">' . sfp_constant_note( 'STAGE_FILE_PROXY_LOCAL_DIR', $constant ) . '</p>';
		if ( '' !== $constant && sfp_is_unsafe_local_dir( $constant ) ) {
			echo '<p class="description"><strong>Warning:</strong> the constant must be a folder name inside your theme, without ".." or a leading slash. The default folder <code>' . esc_html( $value ) . '</code> is used instead.</p>';
		}
	} else {
		echo '<p class="description">Subdirectory name within your active theme (e.g., <code>/wp-content/themes/your-theme/<strong>sfp-images</strong>/</code>) where local fallback images are stored for "Local Fallback" mode.</p>';
	}
}

/**
 * Renders the main settings page for Stage File Proxy.
 * Displays the configuration form with all available options.
 * Prints nothing if the current user lacks the `manage_options` capability.
 *
 * @return void Outputs HTML.
 */
function sfp_options_page() {
	// Check user capabilities
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

		<?php settings_errors(); ?>

		<style type="text/css">
		/* Smooth transition for local directory field visibility */
		.form-table tr {
			transition: opacity 0.3s ease-in-out;
		}
		.form-table tr.sfp-hidden {
			opacity: 0.3;
		}
		</style>

		<form action="options.php" method="post">
			<?php
			// Output security fields and the settings group name.
			settings_fields( 'sfp-settings-group' );

			// Output setting sections and their fields.
			do_settings_sections( 'stage-file-proxy' );

			// Output save button.
			submit_button( 'Save Proxy Settings' );
			?>
		</form>
	</div>
	<?php
}

/**
 * Get the local subdirectory path for WordPress installations in subdirectories.
 *
 * For WordPress sites installed in subdirectories, this function determines
 * the subdirectory path that should be excluded from remote URL construction.
 *
 * @return string The subdirectory path or empty string if WordPress is in root.
 */
function sfp_get_local_subdirectory_path() {
	static $subdirectory_to_strip = null;

	if ( $subdirectory_to_strip !== null ) {
		return $subdirectory_to_strip;
	}

	// Get the relative path part of the home URL.
	$home_url_path = wp_parse_url( home_url( '/', 'relative' ), PHP_URL_PATH );
	$home_url_path = $home_url_path ? trailingslashit( $home_url_path ) : '/';

	// Default to an empty strip path.
	$subdirectory_to_strip = '';

	// Handle single-site installed in a subdirectory (e.g. site_url=/wp/, home_url=/)
	if ( ! is_multisite() ) {
		$site_url_path = wp_parse_url( site_url( '/', 'relative' ), PHP_URL_PATH );
		$site_url_path = $site_url_path ? trailingslashit( $site_url_path ) : '/';

		// If site_url path is longer than home_url path, it's installed in a subdirectory.
		if ( strlen( $site_url_path ) > strlen( $home_url_path ) ) {
			// Path to strip is the site_url path (e.g., '/wp').
			$subdirectory_to_strip = rtrim( $site_url_path, '/' );
		}
	}

	// Handles Multisite Subdirectory paths (e.g., example.test/us/ -> /us)
	if ( empty( $subdirectory_to_strip ) && $home_url_path !== '/' ) {
		// Remove trailing slash. e.g., '/us/' becomes '/us'
		$subdirectory_to_strip = rtrim( $home_url_path, '/' );
	}

	return $subdirectory_to_strip;
}

/**
 * Extract the relative path from the current request URI.
 *
 * This function processes the REQUEST_URI to extract the file path relative
 * to the uploads directory, handling various WordPress installation types.
 * The result is cached for the rest of the request.
 *
 * @return string The relative path to the requested file, after the `sfp_relative_path` filter.
 */
function sfp_get_relative_path() {
	static $path;

	if ( ! $path ) {
		$uploads_url_path = wp_parse_url( content_url( '/uploads' ), PHP_URL_PATH );

		$request_uri = sfp_get_request_uri();

		// 1. Strip the local site subdirectory path (e.g., /myproject/us)
		$subdirectory_to_strip = sfp_get_local_subdirectory_path();
		if ( ! empty( $subdirectory_to_strip ) ) {
			if ( strpos( $request_uri, $subdirectory_to_strip ) === 0 ) {
				$request_uri = substr_replace( $request_uri, '', 0, strlen( $subdirectory_to_strip ) );
			}
		}

		// 2. Strip the uploads path component (including /wp-content/uploads/ and /sites/N/)
		// This tries to use a reliable string-based approach first.
		$uploads_pos = strpos( $request_uri, $uploads_url_path );

		if ( $uploads_pos !== false ) {
			// Extract only the path after /wp-content/uploads
			$path = substr( $request_uri, $uploads_pos + strlen( $uploads_url_path ) );
		} else {
			// Fallback: case-insensitive match on any known uploads path, which also strips a multisite /sites/N folder.
			$path = $request_uri;
			foreach ( sfp_get_uploads_needles() as $needle ) {
				$regex = '#.*' . preg_quote( rtrim( $needle, '/' ), '#' ) . '(?:/sites/\d+)?/#i';
				if ( preg_match( $regex, $request_uri ) ) {
					$path = preg_replace( $regex, '', $request_uri, 1 );
					break;
				}
			}
		}
	}

	/**
	 * Filter: sfp_relative_path
	 *
	 * Alter the relative path of an image in SFP.
	 *
	 * @param string $path The relative path of the file.
	 */
	$path = apply_filters( 'sfp_relative_path', $path );
	return $path;
}

/**
 * Get the local directory path for file operations.
 *
 * Returns the base directory path where files should be stored locally.
 * This can be the standard WordPress uploads directory or a custom directory.
 *
 * @return string The absolute path to the local directory.
 */
function sfp_get_local_dir() {
	static $local_dir = null;

	if ( $local_dir !== null ) {
		return $local_dir;
	}

	if ( defined( 'STAGE_FILE_PROXY_LOCAL_DIR' ) ) {
		$local_dir = STAGE_FILE_PROXY_LOCAL_DIR;
	} else {
		$local_dir = get_option( 'sfp_local_dir' );
	}

	$local_dir = sfp_normalize_local_dir( $local_dir );

	return $local_dir;
}

/**
 * Turn a configured local fallback directory into the one that is used.
 *
 * An empty value gets the default. A value that could leave the theme folder
 * (from the constant, or saved before the settings check existed) is replaced
 * by the default too.
 *
 * @param mixed $dir The configured directory.
 * @return string The directory name to use.
 */
function sfp_normalize_local_dir( $dir ) {
	if ( empty( $dir ) || ! is_string( $dir ) || sfp_is_unsafe_local_dir( $dir ) ) {
		return 'sfp-images'; // Default fallback
	}

	return $dir;
}

/**
 * Get a random local file path for fallback when remote files are unavailable.
 *
 * In 'local' mode, this function selects a random image from the local fallback
 * directory (see sfp_get_local_dir()) in the active theme, to serve as a
 * placeholder when the requested remote file is not available. The list of
 * files is cached in a transient.
 *
 * @param bool $doing_resize Whether the request is for a resized image. Currently unused.
 * @return string|false Path to a random local file, or false if none found.
 */
function sfp_get_random_local_file_path( $doing_resize ) {
	$transient_key = 'sfp-replacement-images';

	// Use the central getter function to fetch the directory name
	$local_dir = sfp_get_local_dir();

	$replacement_image_path = get_template_directory() . '/' . $local_dir . '/';

	// Cache image directory contents
	if ( false === ( $images = get_transient( $transient_key ) ) ) {
		$images = array(); // Initialize as array to prevent errors if glob fails
		// glob() returns false on error; foreach over false raises a warning in PHP 8.
		foreach ( (array) glob( $replacement_image_path . '*' ) as $filename ) {
			// Exclude resized images
			if ( ! preg_match( '/.+[0-9]+x[0-9]+c?\.(jpe?g|png|gif)$/iU', $filename ) ) {
				$images[] = basename( $filename );
			}
		}
		// Only set transient if images were found
		if ( ! empty( $images ) ) {
			set_transient( $transient_key, $images );
		}
	}

	// Ensure $images is an array before counting
	if ( ! is_array( $images ) || empty( $images ) ) {
		return false; // Return false if no local images found
	}

	$rand = wp_rand( 0, count( $images ) - 1 );
	return $replacement_image_path . $images[ $rand ];
}

/**
 * Get the current proxy mode setting.
 *
 * The STAGE_FILE_PROXY_MODE constant takes priority over the `sfp_mode` option.
 * The mode defaults to 'header' and is cached for the rest of the request.
 *
 * Determines how the plugin should handle missing files:
 * - 'header':     Send an HTTP redirect to the remote file.
 * - 'download':   Download the remote file, save it locally and serve it.
 * - 'photon':     Redirect to the remote file and use Photon for image resizing.
 * - 'local':      Use local fallback images when the remote file is unavailable.
 * - 'lorempixel': Redirect to a placeholder image service when the remote file is unavailable.
 *
 * @return string The current proxy mode.
 */
function sfp_get_mode() {
	static $mode = null;

	if ( $mode !== null ) {
		return $mode;
	}

	if ( defined( 'STAGE_FILE_PROXY_MODE' ) ) {
		$mode = STAGE_FILE_PROXY_MODE;
	} else {
		$mode = get_option( 'sfp_mode' );
	}

	if ( ! $mode ) {
		$mode = 'header';
	}

	return $mode;
}

/**
 * Get the uploads path to use on the remote site, from this site's own setup.
 *
 * The path is the local uploads URL path with the parts that only exist locally removed:
 * - the folder the site or WordPress itself is installed in (e.g. /us, /blog, /wp),
 * - the multisite /sites/N folder, which only subdirectory networks keep on the remote site.
 *
 * Examples (local setup => remote path):
 * - Standard single site, or WordPress in a subdirectory => /wp-content/uploads
 * - Multisite subdomain, site 2                           => /wp-content/uploads
 * - Multisite subdirectory, site 2                        => /wp-content/uploads/sites/2
 * - Custom content folder (e.g. Bedrock)                  => /app/uploads
 * - UPLOADS constant set to wp-content/media              => /wp-content/media
 *
 * The remote site is expected to use the same layout. Use the
 * `sfp_remote_uploads_path` filter when it does not.
 *
 * @return string Path starting with a slash, without a trailing slash.
 */
function sfp_get_remote_uploads_path() {
	static $cache = array();

	$blog_id = get_current_blog_id();

	if ( ! isset( $cache[ $blog_id ] ) ) {
		$uploads = wp_upload_dir( null, false );
		$path    = rtrim( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ), '/' );

		// With a custom upload_url_path the URL no longer follows the folder layout; use the content URL.
		if ( get_option( 'upload_url_path' ) ) {
			$path = rtrim( (string) wp_parse_url( content_url( '/uploads' ), PHP_URL_PATH ), '/' );
		}

		// Keep the multisite site folder aside; it is added back below when it applies.
		$site_folder = '';
		if ( preg_match( '#/sites/\d+$#', $path, $matches ) ) {
			$site_folder = $matches[0];
			$path        = substr( $path, 0, -strlen( $site_folder ) );
		}

		// Remove the local installation subdirectory.
		$subdirectory = sfp_get_local_subdirectory_path();
		if ( '' !== $subdirectory && 0 === strpos( $path, $subdirectory . '/' ) ) {
			$path = substr( $path, strlen( $subdirectory ) );
		}

		// Subdirectory networks keep /sites/N on the remote site. Single sites and subdomain networks do not.
		if ( is_multisite() && ! is_subdomain_install() ) {
			$path .= $site_folder;
		}

		$cache[ $blog_id ] = '' === $path ? '/wp-content/uploads' : $path;
	}

	/**
	 * Filter: sfp_remote_uploads_path
	 *
	 * Alter the uploads path used on the remote site, e.g. when the remote
	 * site uses a different folder layout than this one.
	 *
	 * @param string $path The remote uploads path, starting with a slash.
	 */
	return apply_filters( 'sfp_remote_uploads_path', $cache[ $blog_id ] );
}

/**
 * Construct a remote URL by combining base URL with the wp-content uploads path.
 *
 * This function intelligently handles different WordPress installation types:
 * 1. Single site (root or subdirectory)
 * 2. Multisite subdomain
 * 3. Multisite subdirectory
 *
 * It ensures consistent remote URL structure regardless of local setup.
 *
 * @param string $relative_path The relative file path (e.g., "2023/01/image.jpg").
 * @return string The complete remote URL.
 */
function sfp_construct_remote_url( $relative_path ) {
	$base_url = sfp_get_base_url();

	if ( empty( $base_url ) ) {
		return '';
	}

	// Build remote URL: domain from config + uploads path of this site setup + relative file path
	return rtrim( $base_url, '/' ) . sfp_get_remote_uploads_path() . '/' . ltrim( $relative_path, '/' );
}

/**
 * Extract the domain part from a URL, removing any wp-content/uploads paths.
 *
 * This function handles cases where users might mistakenly include the full path
 * in STAGE_FILE_PROXY_URL instead of just the domain.
 *
 * Examples:
 * - 'https://example.com/wp-content/uploads/' becomes 'https://example.com'
 * - 'https://example.com/wp-content/uploads/sites/2/' becomes 'https://example.com'
 * - 'https://example.com' remains 'https://example.com'
 *
 * @param string $url The URL to extract domain from.
 * @return string The clean domain URL without wp-content paths.
 */
function sfp_extract_domain_from_url( $url ) {
	if ( empty( $url ) ) {
		return '';
	}

	// Parse the URL to get components
	$parsed = wp_parse_url( $url );

	if ( ! $parsed || ! isset( $parsed['scheme'] ) || ! isset( $parsed['host'] ) ) {
		return rtrim( $url, '/' ); // Return as-is if parsing fails, but trim trailing slash
	}

	// Build clean domain URL
	$clean_url = $parsed['scheme'] . '://' . $parsed['host'];

	// Add port if specified
	if ( isset( $parsed['port'] ) ) {
		$clean_url .= ':' . $parsed['port'];
	}

	// Check if path contains wp-content/uploads and strip everything from that point
	if ( isset( $parsed['path'] ) && ! empty( $parsed['path'] ) ) {
		$path = $parsed['path'];

		// Find the content folder in the path: wp-content, or this site's own name for it (e.g. app).
		$folders        = array_unique( array( 'wp-content', basename( rtrim( (string) wp_parse_url( content_url(), PHP_URL_PATH ), '/' ) ) ) );
		$folders        = array_filter( array_map( 'preg_quote', $folders ) );
		$wp_content_pos = false;
		if ( preg_match( '#/(?:' . implode( '|', $folders ) . ')(?:/|$)#i', $path, $folder_match, PREG_OFFSET_CAPTURE ) ) {
			$wp_content_pos = $folder_match[0][1];
		}

		if ( $wp_content_pos !== false ) {
			// Keep only the path before the content folder
			$path_before_wp_content = substr( $path, 0, $wp_content_pos );
			if ( ! empty( $path_before_wp_content ) && $path_before_wp_content !== '/' ) {
				$clean_url .= rtrim( $path_before_wp_content, '/' );
			}
		} else {
			// No wp-content found, keep the entire path but trim trailing slash
			$clean_url .= rtrim( $path, '/' );
		}
	}

	return $clean_url;
}

/**
 * Get the base URL for the remote/production site.
 *
 * This URL is used to construct full URLs to remote files when local files
 * are not available. Supports both constant-based and options-based configuration;
 * the STAGE_FILE_PROXY_URL constant takes priority over the `sfp_url` option. Either
 * way a full uploads URL is reduced to its domain part.
 *
 * @return string The base URL for remote files, or empty string if not configured.
 */
function sfp_get_base_url() {
	static $url = null;

	if ( $url !== null ) {
		return $url;
	}

	if ( defined( 'STAGE_FILE_PROXY_URL' ) ) {
		// STAGE_FILE_PROXY_URL should be a domain-only URL (e.g., 'https://production.com')
		// But handle cases where users include full paths - extract only the domain part
		$url = sfp_extract_domain_from_url( STAGE_FILE_PROXY_URL );
	} else {
		// The option can be set through WP-CLI or code, which skips the settings page sanitizer,
		// so a pasted URL with a wp-content/uploads path is reduced to its domain here too.
		$url = sfp_extract_domain_from_url( (string) get_option( 'sfp_url' ) );
	}

	// If URL is empty and mode is not 'local', we return an empty string.
	// The error is handled gracefully by the empty() check in sfp_dispatch().
	if ( empty( $url ) && 'local' !== sfp_get_mode() ) {
		return '';
	}

	return $url;
}

/**
 * Get the local base URL for the uploads directory.
 *
 * Returns the base URL for the local WordPress uploads directory,
 * used for determining when URLs should be proxied to remote sources.
 *
 * @return string The base URL for local uploads.
 */
function sfp_get_local_base_url() {
	static $local_base_url = null;
	if ( $local_base_url === null ) {
		$uploads_dir = wp_upload_dir( null, false );
		// Use the full baseurl (e.g., http://local.dev/wp-content/uploads)
		$local_base_url = rtrim( $uploads_dir['baseurl'], '/' );
	}
	return $local_base_url;
}

/**
 * Map a URL to its corresponding local file system path.
 *
 * Converts a URL pointing to the uploads directory into the corresponding
 * local file system path, enabling file existence checks and operations.
 *
 * @param string $url The URL to map to a local path.
 * @return string The corresponding local file system path.
 */
function sfp_map_url_to_local_path( $url ) {
	$upload_dir = wp_upload_dir( null, false );
	$baseurl    = sfp_get_local_base_url();
	$basedir    = $upload_dir['basedir'];

	// Remove scheme and domain from $url if present.
	$relative = $url;

	if ( strpos( $url, $baseurl ) === 0 ) {
		// If it starts with our uploads URL, get the path after it.
		$relative = substr( $url, strlen( $baseurl ) );
	} else {
		// Try to remove just the domain part to get a path.
		$parsed = wp_parse_url( $url );
		if ( isset( $parsed['path'] ) ) {
			$relative = $parsed['path'];
		}
	}

	// On a VIP site the query string is a resize instruction (?w=200), not part of the file name.
	if ( 'vip' === sfp_get_remote_image_style() ) {
		$relative = (string) strtok( $relative, '?#' );
	}

	$relative   = ltrim( $relative, '/' );
	$local_path = trailingslashit( $basedir ) . $relative;

	return $local_path;
}

/**
 * Replace the local URL base with the remote base URL for an attachment URL.
 *
 * This function handles all WordPress installation types:
 * - Single site (root or subdirectory)
 * - Multisite subdomain
 * - Multisite subdirectory
 *
 * Examples:
 * Single site subdirectory:
 *   Local:  http://example.test/site/wp-content/uploads/2023/01/image.jpg
 *   Remote: https://example.com/wp-content/uploads/2023/01/image.jpg
 *
 * Multisite subdirectory:
 *   Local:  http://example.test/site2/wp-content/uploads/sites/2/2023/01/image.jpg
 *   Remote: https://example.com/wp-content/uploads/sites/2/2023/01/image.jpg
 *
 * @param string $url The local URL.
 * @return string The remote URL with proper path handling.
 */
function sfp_rewrite_local_to_remote( $url ) {
	$local_base  = sfp_get_local_base_url();
	$remote_base = sfp_get_base_url();

	// Check that we have valid bases and that the URL belongs to the local uploads area.
	if ( empty( $local_base ) || empty( $remote_base ) || strpos( $url, $local_base ) === false ) {
		return $url;
	}

	// Parse the URL to get its components
	$url_parsed    = wp_parse_url( $url );
	$remote_parsed = wp_parse_url( $remote_base );

	if ( ! $url_parsed || ! $remote_parsed || ! isset( $url_parsed['path'] ) ) {
		return $url;
	}

	// Get the relative path after removing the local uploads base
	$local_uploads_path = (string) wp_parse_url( $local_base, PHP_URL_PATH );
	$relative_path      = '';

	if ( strpos( $url_parsed['path'], $local_uploads_path ) === 0 ) {
		$relative_path = substr( $url_parsed['path'], strlen( $local_uploads_path ) );
	}

	// The uploads path on the remote site follows this site's own setup.
	$clean_uploads_path = sfp_get_remote_uploads_path();

	// Build the new URL using remote domain + clean path + relative file path
	$new_url = $remote_parsed['scheme'] . '://' . $remote_parsed['host'];

	// Add remote port if specified
	if ( isset( $remote_parsed['port'] ) ) {
		$new_url  .= ':' . $remote_parsed['port'];
	}

	// Add the clean uploads path and relative file path
	$new_url  .= $clean_uploads_path . $relative_path;

	// Preserve query string and fragment if they exist
	if ( isset( $url_parsed['query'] ) ) {
		$new_url  .= '?' . $url_parsed['query'];
	}

	if ( isset( $url_parsed['fragment'] ) ) {
		$new_url  .= '#' . $url_parsed['fragment'];
	}

	return $new_url;
}

/**
 * Filter attachment URLs to use remote sources when files don't exist locally.
 *
 * This filter rewrites wp_get_attachment_url() output to point to remote sources
 * when the local file is not available.
 *
 * @param string $url The original attachment URL.
 * @return string The potentially rewritten URL pointing to remote source.
 */
function sfp_wp_get_attachment_url_rewrite( $url ) {
	$local_base = sfp_get_local_base_url();
	if ( strpos( $url, $local_base ) !== false ) {
		$local_path = sfp_map_url_to_local_path( $url );
		if ( ! sfp_local_file_exists( $local_path ) ) {
			$url = sfp_rewrite_local_to_remote( $url );
		}
	}
	return $url;
}
add_filter( 'wp_get_attachment_url', 'sfp_wp_get_attachment_url_rewrite', 99 );





/**
 * Get the style of image URL the remote site understands.
 *
 * - `standard`: WordPress writes a separate file per size (hero-200x200.png). This is the default.
 * - `vip`: WordPress VIP (VIP Go File Service) has no such files. It resizes on the fly from the original, with
 *   query arguments: hero.png?resize=200,200 for the sizes in the attachment data, and hero.png?w=200 or
 *   hero.png?w=200&h=200&crop=1 for a single image.
 *
 * The style is `vip` only when this site shows that it is a WordPress VIP site (the VIP mu-plugins or VIP
 * constants are present). Every VIP-specific behaviour of this plugin depends on this function, so any other
 * site runs the same code as before. It can be set with the STAGE_FILE_PROXY_IMAGE_STYLE constant (`vip` or
 * `standard`), for example when only the remote site is on VIP, and with the `sfp_remote_image_style` filter.
 *
 * @return string `vip` or `standard`.
 */
function sfp_get_remote_image_style() {
	if ( defined( 'STAGE_FILE_PROXY_IMAGE_STYLE' ) ) {
		$style = STAGE_FILE_PROXY_IMAGE_STYLE;
	} elseif ( function_exists( 'a8c_files_maybe_inject_image_sizes' ) || defined( 'VIP_GO_APP_ENVIRONMENT' ) || ( defined( 'WPCOM_IS_VIP_ENV' ) && WPCOM_IS_VIP_ENV ) ) {
		$style = 'vip';
	} else {
		$style = 'standard';
	}

	/**
	 * Filter: sfp_remote_image_style
	 *
	 * Alter the style of image URL the remote site understands.
	 *
	 * @param string $style `vip` or `standard`.
	 */
	$style = apply_filters( 'sfp_remote_image_style', $style );

	return 'vip' === $style ? 'vip' : 'standard';
}

/**
 * Build the image sizes a WordPress VIP site serves, as attachment data `sizes`.
 *
 * Mirrors what the VIP mu-plugin (a8c_files_maybe_inject_image_sizes) does on production: every registered size
 * that fits the image gets an entry whose file is the original with a resize argument, for example
 * `hero.png?resize=200,200`. WordPress then builds the src and srcset URLs from these entries.
 *
 * @param array  $data          Attachment data with at least `file`, `width` and `height`.
 * @param string $mime_type     MIME type of the attachment.
 * @param int    $attachment_id Attachment ID, passed on to the `intermediate_image_sizes_advanced` filter.
 * @return array Sizes in the format of the attachment data `sizes` key. Sizes that do not fit are left out.
 */
function sfp_build_remote_image_sizes( $data, $mime_type, $attachment_id = 0 ) {
	$additional = wp_get_additional_image_sizes();
	$sizes      = array();

	foreach ( get_intermediate_image_sizes() as $name ) {
		$sizes[ $name ] = array(
			'width'  => isset( $additional[ $name ]['width'] ) ? (int) $additional[ $name ]['width'] : (int) get_option( "{$name}_size_w" ),
			'height' => isset( $additional[ $name ]['height'] ) ? (int) $additional[ $name ]['height'] : (int) get_option( "{$name}_size_h" ),
			'crop'   => isset( $additional[ $name ]['crop'] ) ? $additional[ $name ]['crop'] : (bool) get_option( "{$name}_crop" ),
		);
	}

	// Themes and plugins can remove sizes here, as on VIP. This plugin's own filter that empties the list is not applied.
	$own_priority = has_filter( 'intermediate_image_sizes_advanced', 'sfp_image_sizes_advanced' );
	if ( false !== $own_priority ) {
		remove_filter( 'intermediate_image_sizes_advanced', 'sfp_image_sizes_advanced', $own_priority );
	}
	$sizes = (array) apply_filters( 'intermediate_image_sizes_advanced', $sizes, $data, $attachment_id );
	if ( false !== $own_priority ) {
		add_filter( 'intermediate_image_sizes_advanced', 'sfp_image_sizes_advanced', $own_priority );
	}

	$built = array();
	foreach ( $sizes as $name => $size ) {
		if ( ! is_array( $size ) || ( ! isset( $size['width'] ) && ! isset( $size['height'] ) ) ) {
			continue;
		}

		$dimensions = image_resize_dimensions( (int) $data['width'], (int) $data['height'], (int) ( $size['width'] ?? 0 ), (int) ( $size['height'] ?? 0 ), $size['crop'] ?? false );
		if ( ! $dimensions ) {
			continue; // The image is not larger than this size.
		}

		$built[ $name ] = array(
			'file'      => wp_basename( $data['file'] ) . '?resize=' . $dimensions[4] . ',' . $dimensions[5],
			'width'     => (int) $dimensions[4],
			'height'    => (int) $dimensions[5],
			'mime-type' => $mime_type,
			'filesize'  => isset( $data['filesize'] ) ? (int) $data['filesize'] : 0,
		);
	}

	return $built;
}

/**
 * Give images that only exist on the remote site the sizes the remote site serves.
 *
 * On a WordPress VIP site the attachment data holds no per-size files; the VIP mu-plugin adds resize entries when
 * the data is read, but only on VIP servers. Without them a local copy has no sizes, so images are served in full
 * size, or, with the standard style, as files such as hero-200x200.png that do not exist on VIP. This filter adds
 * the same entries here. It does nothing unless the style is `vip`, when VIP's own filter is already active, for
 * attachments that are not images, and for images that exist on this server.
 *
 * Hooked to `wp_get_attachment_metadata` after the VIP filter (priority 20).
 *
 * @param array|false $data          Attachment data.
 * @param int         $attachment_id Attachment ID.
 * @return array|false The data, with VIP style sizes for an image that only exists on the remote site.
 */
function sfp_inject_remote_image_sizes( $data, $attachment_id ) {
	static $built = array();

	if ( empty( $data['file'] ) || empty( $data['width'] ) || empty( $data['height'] ) || 'vip' !== sfp_get_remote_image_style() ) {
		return $data;
	}

	// On a VIP server the VIP filter has done this already.
	if ( has_filter( 'wp_get_attachment_metadata', 'a8c_files_maybe_inject_image_sizes' ) ) {
		return $data;
	}

	$key = get_current_blog_id() . ':' . (int) $attachment_id . ':' . $data['file'];

	if ( ! isset( $built[ $key ] ) ) {
		$mime_type = (string) get_post_mime_type( (int) $attachment_id );
		$uploads   = wp_upload_dir( null, false );

		if ( 0 !== strpos( $mime_type, 'image/' ) || sfp_local_file_exists( trailingslashit( $uploads['basedir'] ) . $data['file'] ) ) {
			$built[ $key ] = false; // Not an image, or the file is here: keep the data as it is.
		} else {
			$built[ $key ] = sfp_build_remote_image_sizes( $data, $mime_type, (int) $attachment_id );
		}
	}

	if ( false !== $built[ $key ] ) {
		$data['sizes'] = $built[ $key ];
	}

	return $data;
}
add_filter( 'wp_get_attachment_metadata', 'sfp_inject_remote_image_sizes', 20, 2 );

/**
 * Move an uploads URL to the remote site when its file does not exist on this server.
 *
 * Keeps the query string, which a VIP site needs for the size (?resize=200,200, ?w=200).
 *
 * @param mixed $url A URL.
 * @return mixed The remote URL for a missing local upload, otherwise the URL as it was.
 */
function sfp_rewrite_url_if_missing( $url ) {
	$local_base = sfp_get_local_base_url();

	if ( ! is_string( $url ) || '' === $local_base || false === strpos( $url, $local_base ) ) {
		return $url;
	}

	if ( sfp_local_file_exists( sfp_map_url_to_local_path( $url ) ) ) {
		return $url;
	}

	return sfp_rewrite_local_to_remote( $url );
}

/**
 * Move the URLs of a srcset value to the remote site where the local file is missing.
 *
 * Candidates are separated by a comma and a space. A comma inside a URL (?resize=300,169) is not followed by one.
 *
 * @param string $srcset A srcset value, e.g. "a.png?resize=300,169 300w, a.png?resize=768,432 768w".
 * @return string The srcset value with remote URLs for missing local files.
 */
function sfp_rewrite_srcset_value( $srcset ) {
	$candidates = preg_split( '/,\s+/', trim( $srcset ) );

	foreach ( $candidates as $index => $candidate ) {
		if ( preg_match( '/^(\S+)(\s.*)?$/s', trim( $candidate ), $parts ) ) {
			$candidates[ $index ] = sfp_rewrite_url_if_missing( $parts[1] ) . ( $parts[2] ?? '' );
		}
	}

	return implode( ', ', $candidates );
}

/**
 * Move the srcset URLs in a string of tag attributes to the remote site where the local file is missing.
 *
 * @param string $attributes Attributes of an img tag, e.g. ' class="x" srcset="a.png 1w, b.png 2w"'.
 * @return string The attributes with remote srcset URLs for missing local files.
 */
function sfp_rewrite_srcset_attributes( $attributes ) {
	return preg_replace_callback(
		'/\bsrcset\s*=\s*(["\'])(.*?)\1/is',
		fn( $match ) => 'srcset=' . $match[1] . sfp_rewrite_srcset_value( $match[2] ) . $match[1],
		$attributes
	);
}

/**
 * Enhanced srcset generation for attachments that don't exist locally.
 *
 * This function generates srcset URLs for remote images by creating the expected
 * intermediate size URLs even when the files don't exist locally.
 * Sizes that do not exist locally point to the remote site.
 *
 * Hooked to `wp_calculate_image_srcset`.
 *
 * @param array  $sources       Srcset sources, keyed by width, each with `url`, `descriptor` and `value`.
 * @param array  $size_array    Requested width and height. Unused.
 * @param string $image_src     URL of the image.
 * @param array  $image_meta    Attachment metadata.
 * @param int    $attachment_id Attachment ID. Unused.
 * @return array Srcset sources, with generated entries merged in.
 */
function sfp_generate_remote_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
	// A VIP site has no per-size files to name. The sizes come from the attachment data (see sfp_inject_remote_image_sizes()),
	// so only the URLs move to the remote site, query string included.
	if ( 'vip' === sfp_get_remote_image_style() ) {
		if ( is_array( $sources ) ) {
			foreach ( $sources as $width => $source ) {
				if ( isset( $source['url'] ) ) {
					$sources[ $width ]['url'] = sfp_rewrite_url_if_missing( $source['url'] );
				}
			}
		}

		return $sources;
	}

	// Only process if we have valid image metadata and the main image doesn't exist locally
	if ( empty( $image_meta ) || empty( $image_src ) ) {
		return $sources;
	}

	$local_base = sfp_get_local_base_url();

	// Check if this is a local uploads URL that doesn't exist
	if ( strpos( $image_src, $local_base ) === false ) {
		return $sources;
	}

	$local_path = sfp_map_url_to_local_path( $image_src );
	if ( sfp_local_file_exists( $local_path ) ) {
		return $sources; // File exists locally, let WordPress handle it normally
	}

	// Get registered image sizes
	$image_sizes   = wp_get_additional_image_sizes();
	$default_sizes = array(
		'thumbnail'    => array( 'width' => get_option( 'thumbnail_size_w' ), 'height' => get_option( 'thumbnail_size_h' ), 'crop' => get_option( 'thumbnail_crop' ) ),
		'medium'       => array( 'width' => get_option( 'medium_size_w' ), 'height' => get_option( 'medium_size_h' ), 'crop' => false ),
		'medium_large' => array( 'width' => get_option( 'medium_large_size_w' ), 'height' => get_option( 'medium_large_size_h' ), 'crop' => false ),
		'large'        => array( 'width' => get_option( 'large_size_w' ), 'height' => get_option( 'large_size_h' ), 'crop' => false ),
	);
	$all_sizes     = array_merge( $default_sizes, $image_sizes );

	// Generate remote URLs for all image sizes
	$new_sources = array();

	foreach ( $all_sizes as $size_name => $size_data ) {
		if ( empty( $size_data['width'] ) && empty( $size_data['height'] ) ) {
			continue;
		}

		// Calculate dimensions
		$original_width  = isset( $image_meta['width'] ) ? $image_meta['width'] : 0;
		$original_height = isset( $image_meta['height'] ) ? $image_meta['height'] : 0;

		if ( ! $original_width || ! $original_height ) {
			continue;
		}

		$crop    = isset( $size_data['crop'] ) ? $size_data['crop'] : false;
		$resized = image_resize_dimensions( $original_width, $original_height, $size_data['width'], $size_data['height'], $crop );

		if ( ! $resized ) {
			continue;
		}

		// Generate the expected filename for this size
		$info = pathinfo( $image_src );
		$dir  = $info['dirname'];
		$ext  = $info['extension'];
		$name = wp_basename( $image_src, ".$ext" );

		// Generate suffix
		$suffix = $resized[4] . 'x' . $resized[5];
		if ( $crop ) {
			$suffix  .= 'c';
		}

		$resized_url = $dir . '/' . $name . '-' . $suffix . '.' . $ext;

		// Check if this size doesn't exist locally, then make it remote
		$resized_local_path = sfp_map_url_to_local_path( $resized_url );
		if ( ! sfp_local_file_exists( $resized_local_path ) ) {
			$resized_url = sfp_rewrite_local_to_remote( $resized_url );
		}

		$new_sources[ $resized[4] ] = array(
			'url'        => $resized_url,
			'descriptor' => 'w',
			'value'      => $resized[4],
		);
	}

	// Merge with existing sources, prioritizing our generated ones
	return array_merge( $sources, $new_sources );
}
add_filter( 'wp_calculate_image_srcset', 'sfp_generate_remote_srcset', 98, 5 );

/**
 * Generate responsive image attributes for images that don't exist locally.
 *
 * This ensures that even remote images get proper sizes, srcset, and other attributes.
 *
 * Hooked to `wp_get_attachment_image_attributes`.
 *
 * @param string[]     $attr       Image tag attributes.
 * @param WP_Post      $attachment Attachment post object.
 * @param string|int[] $size       Requested image size. Unused.
 * @return string[] The attributes, with `srcset` and `sizes` added when they can be generated.
 */
function sfp_wp_get_attachment_image_attributes( $attr, $attachment, $size ) {
	if ( empty( $attr['src'] ) ) {
		return $attr;
	}

	$local_base = sfp_get_local_base_url();

	// Only process local upload URLs that don't exist locally
	if ( strpos( $attr['src'], $local_base ) === false ) {
		return $attr;
	}

	$local_path = sfp_map_url_to_local_path( $attr['src'] );
	if ( sfp_local_file_exists( $local_path ) ) {
		return $attr; // File exists locally, let WordPress handle it normally
	}

	// Get attachment metadata
	$image_meta = wp_get_attachment_metadata( $attachment->ID );
	if ( empty( $image_meta ) ) {
		return $attr;
	}

	// Generate srcset for this image
	$size_array = array(
		isset( $attr['width'] ) ? $attr['width'] : 0,
		isset( $attr['height'] ) ? $attr['height'] : 0
	);
	$srcset     = wp_calculate_image_srcset( $size_array, $attr['src'], $image_meta, $attachment->ID );

	if ( $srcset ) {
		$attr['srcset'] = $srcset;
		$attr['sizes']  = wp_calculate_image_sizes( $size_array, $attr['src'], $image_meta, $attachment->ID );
	}

	return $attr;
}
add_filter( 'wp_get_attachment_image_attributes', 'sfp_wp_get_attachment_image_attributes', 99, 3 );



/**
 * Enhanced content rewriting with responsive image support.
 *
 * This function not only rewrites URLs but also ensures that images
 * in content have proper srcset and sizes attributes. It also rewrites
 * CSS `background` and `background-image` URLs. Only URLs of local upload
 * files that do not exist locally are changed. Does nothing in the admin.
 *
 * Hooked to `the_content`.
 *
 * @param string $content Post content.
 * @return string The content with remote URLs for missing local files.
 */
function sfp_enhance_content_images( $content ) {
	// Only run on the front end and if we have content.
	if ( is_admin() || empty( $content ) ) {
		return $content;
	}

	$local_base = sfp_get_local_base_url();

	// Both rewrites below only act on URLs inside the local uploads folder, so skip the regex work without one.
	if ( '' === $local_base || false === strpos( $content, $local_base ) ) {
		return $content;
	}

	// Enhanced img tag processing with srcset generation
	$content = preg_replace_callback(
		'/<img([^>]*?)src=["\\\']([^"\\\']+)["\\\']([^>]*?)>/i',
		function ( $matches ) use ( $local_base ) {
			$before_src = $matches[1];
			$src        = $matches[2];
			$after_src  = $matches[3];

			// Check if this is a local uploads URL
			if ( strpos( $src, $local_base ) !== false ) {
				$local_path = sfp_map_url_to_local_path( $src );

				// If file doesn't exist locally, enhance the image tag
				if ( ! sfp_local_file_exists( $local_path ) ) {
					$new_src = sfp_rewrite_local_to_remote( $src );

					// Try to add responsive attributes if missing
					// VIP resizes with query arguments, so file names for sizes cannot be made up. WordPress adds the srcset
					// from the attachment data; here its URLs only move to the remote site.
					if ( 'vip' === sfp_get_remote_image_style() ) {
						return '<img' . sfp_rewrite_srcset_attributes( $before_src ) . 'src="' . $new_src . '"' . sfp_rewrite_srcset_attributes( $after_src ) . '>';
					}

					$has_srcset = stripos( $before_src . $after_src, 'srcset' ) !== false;
					$has_sizes = stripos( $before_src . $after_src, 'sizes' ) !== false;

					if ( ! $has_srcset ) {
						// Generate srcset based on the image URL pattern
						$srcset_urls = sfp_generate_srcset_from_url( $new_src );
						if ( ! empty( $srcset_urls ) ) {
							$after_src  .= ' srcset="' . esc_attr( implode( ', ', $srcset_urls ) ) . '"';
						}
					}

					if ( ! $has_sizes && ! $has_srcset ) {
						// Add basic sizes attribute
						$after_src  .= ' sizes="(max-width: 768px) 100vw, (max-width: 1024px) 50vw, 33vw"';
					}

					return '<img' . $before_src . 'src="' . $new_src . '"' . $after_src . '>';
				}
			}

			return $matches[0]; // Return original if no changes needed
		},
		$content
	);

	// Also handle CSS background-image and shorthand background:url(...)
	$content = preg_replace_callback(
		'/\bbackground(?:-image)?\s*:\s*url\(\s*(["\']?)([^"\')]+)\1\s*\)/i',
		function ( $matches ) use ( $local_base ) {
			$full = $matches[0];
			$src = $matches[2]; // clean URL only, no quotes

			// Clean up common attribute encoding issues like &#039;
			$src = str_replace( array( '&#039;', '&#39;' ), '', $src );

			// Check if the source URL belongs to the local uploads domain/path
			if ( strpos( $src, $local_base ) !== false ) {
				$local_path = sfp_map_url_to_local_path( $src );

				// If the file does NOT exist locally, rewrite the URL to the remote one.
				if ( ! sfp_local_file_exists( $local_path ) ) {
					$new  = sfp_rewrite_local_to_remote( $src );
					$full = str_replace( $src, $new, $full );
				}
			}

			return $full;
		},
		$content
	);

	return $content;
}
add_filter( 'the_content', 'sfp_enhance_content_images', 98 );

/**
 * Generate srcset URLs from a main image URL.
 *
 * This function creates responsive image URLs based on common WordPress image sizes.
 * The original image is added with an assumed width of 1200w.
 *
 * @param string $image_url URL of the full-size image.
 * @return string[] List of srcset entries, each as "<url> <width>w".
 */
function sfp_generate_srcset_from_url( $image_url ) {
	$srcset_urls = array();

	// Common WordPress image sizes
	$sizes = array(
		array( 'width' => 150, 'height' => 150, 'crop' => true ), // thumbnail
		array( 'width' => 300, 'height' => 300, 'crop' => false ), // medium
		array( 'width' => 768, 'height' => 768, 'crop' => false ), // medium_large
		array( 'width' => 1024, 'height' => 1024, 'crop' => false ), // large
	);

	$info = pathinfo( $image_url );
	$dir  = $info['dirname'];
	$ext  = $info['extension'];
	$name = wp_basename( $image_url, ".$ext" );

	foreach ( $sizes as $size ) {
		$suffix = $size['width'] . 'x' . $size['height'];
		if ( $size['crop'] ) {
			$suffix  .= 'c';
		}

		$sized_url     = $dir . '/' . $name . '-' . $suffix . '.' . $ext;
		$srcset_urls[] = $sized_url . ' ' . $size['width'] . 'w';
	}

	// Add the original image
	$srcset_urls[] = $image_url . ' 1200w'; // Assume original is 1200w

	return $srcset_urls;
}

/**
 * Filter to ensure wp_get_attachment_image() generates proper responsive attributes
 * for images that don't exist locally.
 *
 * Rewrites the image URL to the remote site. Fills in missing width and height
 * from the attachment metadata, or uses 1200x800 when there is none.
 *
 * Hooked to `wp_get_attachment_image_src`.
 *
 * @param array|false  $image         Array of image data (URL, width, height, is_intermediate), or false.
 * @param int          $attachment_id Attachment ID.
 * @param string|int[] $size          Requested image size. Unused.
 * @param bool         $icon          Whether the image is a media icon. Unused.
 * @return array|false The image data, with a remote URL when the local file is missing.
 */
function sfp_wp_get_attachment_image_src_enhanced( $image, $attachment_id, $size, $icon ) {
	if ( ! $image || empty( $image[0] ) ) {
		return $image;
	}

	$local_base = sfp_get_local_base_url();

	// Check if this is a local upload URL that doesn't exist locally
	if ( strpos( $image[0], $local_base ) !== false ) {
		$local_path = sfp_map_url_to_local_path( $image[0] );
		if ( ! sfp_local_file_exists( $local_path ) ) {
			$image[0] = sfp_rewrite_local_to_remote( $image[0] );

			// Ensure we have width and height for srcset generation
			if ( empty( $image[1] ) || empty( $image[2] ) ) {
				$metadata = wp_get_attachment_metadata( $attachment_id );
				if ( ! empty( $metadata['width'] ) && ! empty( $metadata['height'] ) ) {
					$image[1] = $metadata['width'];
					$image[2] = $metadata['height'];
				} else {
					// Fallback dimensions
					$image[1] = 1200;
					$image[2] = 800;
				}
				$image[3] = true; // Mark as resized for srcset generation
			}
		}
	}

	return $image;
}
add_filter( 'wp_get_attachment_image_src', 'sfp_wp_get_attachment_image_src_enhanced', 98, 4 );

/**
 * Filter block content to enhance core/image blocks with responsive attributes.
 *
 * Specifically targets Gutenberg image blocks to ensure they have proper
 * srcset and sizes attributes when using remote images.
 *
 * Hooked to `render_block`.
 *
 * @param string $block_content The rendered block content.
 * @param array  $block         The block data. Only `blockName` is used.
 * @return string The enhanced block content.
 */
function sfp_render_block_core_image( $block_content, $block ) {
	if ( $block['blockName'] !== 'core/image' || empty( $block_content ) ) {
		return $block_content;
	}

	$local_base = sfp_get_local_base_url();

	// Only URLs inside the local uploads folder are rewritten, so skip the regex work without one.
	if ( '' === $local_base || false === strpos( $block_content, $local_base ) ) {
		return $block_content;
	}

	// Process image blocks to ensure remote images have responsive attributes
	$block_content = preg_replace_callback(
		'/<img([^>]*?)src=["\\\']([^"\\\']+)["\\\']([^>]*?)>/i',
		function ( $matches ) use ( $local_base ) {
			$before_src = $matches[1];
			$src       = $matches[2];
			$after_src = $matches[3];

			if ( strpos( $src, $local_base ) !== false ) {
				$local_path = sfp_map_url_to_local_path( $src );
				if ( ! sfp_local_file_exists( $local_path ) ) {
					$new_src = sfp_rewrite_local_to_remote( $src );

					// VIP resizes with query arguments, so file names for sizes cannot be made up (see sfp_enhance_content_images()).
					if ( 'vip' === sfp_get_remote_image_style() ) {
						return '<img' . sfp_rewrite_srcset_attributes( $before_src ) . 'src="' . $new_src . '"' . sfp_rewrite_srcset_attributes( $after_src ) . '>';
					}

					// Check if srcset is missing and add it
					if ( stripos( $before_src . $after_src, 'srcset' ) === false ) {
						$srcset_urls = sfp_generate_srcset_from_url( $new_src );
						if ( ! empty( $srcset_urls ) ) {
							$after_src  .= ' srcset="' . esc_attr( implode( ', ', $srcset_urls ) ) . '"';
							$after_src  .= ' sizes="(max-width: 768px) 100vw, (max-width: 1024px) 50vw, 33vw"';
						}
					}

					return '<img' . $before_src . 'src="' . $new_src . '"' . $after_src . '>';
				}
			}

			return $matches[0];
		},
		$block_content
	);

	return $block_content;
}
add_filter( 'render_block', 'sfp_render_block_core_image', 10, 2 );

/**
 * Display an error message and terminate execution.
 *
 * This function is called when the plugin encounters a critical error
 * that prevents it from functioning properly.
 *
 * @return never
 */
function sfp_error() {
	die( 'SFP tried to load, but encountered an error' );
}
