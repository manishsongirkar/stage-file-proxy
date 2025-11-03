<?php
/*
	Plugin Name: Stage File Proxy
	Plugin URI: http://alleyinteractive.com/
	Description: Get only the files you need from your production environment. Don't ever run this in production!
	Version: 101
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
 * The dynamic resizing portion was adapted from dynamic-image-resizer.
 * See: http://wordpress.org/plugins/dynamic-image-resizer/
 */

register_activation_hook( __FILE__, 'sfp_set_redirect_flag' );
add_action( 'admin_init', 'sfp_activation_redirect' );

/**
 * Sets an option flag when the plugin is activated.
 */
function sfp_set_redirect_flag() {
	add_option( 'sfp_do_activation_redirect', true );
}

/**
 * Checks for the redirect flag and performs the redirection if set.
 */
function sfp_activation_redirect() {
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
 * @see http://wordpress.org/support/topic/how-to-change-plugins-load-order
 */
function sfp_first() {
	$plugin_path    = 'stage-file-proxy/stage-file-proxy.php';
	$active_plugins = get_option( 'active_plugins' );
	$plugin_key     = array_search( $plugin_path, $active_plugins );
	if ( $plugin_key ) { // if it's 0 it's the first plugin already, no need to continue
		array_splice( $active_plugins, $plugin_key, 1 );
		array_unshift( $active_plugins, $plugin_path );
		update_option( 'active_plugins', $active_plugins );
	}
}

add_action( 'activated_plugin', 'sfp_first' );

if ( stripos( $_SERVER['REQUEST_URI'], '/wp-content/uploads/' ) !== false ) {
	sfp_expect();
}

/**
 * This function, triggered above, sets the chain in motion.
 */
function sfp_expect() {
	ob_start();
	ini_set( 'display_errors', 'off' );
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
 */
function sfp_dispatch() {
	$mode          = sfp_get_mode();
	$relative_path = sfp_get_relative_path();
	if ( 'header' === $mode ) {
		header( "Location: " . sfp_get_base_url() . $relative_path );
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
			header( 'Location: ' . add_query_arg(
				array(
					'w'      => $resize['width'],
					'h'      => $resize['height'],
					'resize' => $resize['crop'] ? "{$resize['width']},{$resize['height']}" : null,
				),
				sfp_get_base_url() . $resize['filename']
			) );
			exit;
		}

		$uploads_dir = wp_upload_dir();
		$basefile    = $uploads_dir['basedir'] . '/' . $resize['filename'];
		sfp_resize_image( $basefile, $resize );
		$relative_path = $resize['filename'];
	} else if ( 'photon' === $mode ) {
		header( "Location: " . sfp_get_base_url() . $relative_path );
		exit;
	}

	// Download a full-size original from the remote server.
	// If it needs to be resized, it will be on the next load.
	$remote_url = sfp_get_base_url() . $relative_path;

	/**
	 * Filter: sfp_http_request_args
	 *
	 * Alter the args of the GET request.
	 *
	 * @param array $remote_http_request_args The request arguments.
	 */
	$remote_http_request_args = apply_filters( 'sfp_http_remote_args', array( 'timeout' => 30 ) );
	$remote_request           = wp_remote_get( $remote_url, $remote_http_request_args );

	if ( is_wp_error( $remote_request ) || $remote_request['response']['code'] > 400 ) {
		// If local mode, failover to local files
		if ( 'local' === $mode ) {
			// Cache replacement image by hashed request URI
			$transient_key = 'sfp_image_' . md5( $_SERVER['REQUEST_URI'] );
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
			header( 'Location: http://lorempixel.com/' . $resize['width'] . '/' . $resize['height'] );
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

	if ( ! $upload['error'] ) {
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
 * Resizes an image file based on provided parameters.
 *
 * Creates a resized version of the given image file using WordPress image editor.
 * Handles both cropped and uncropped resizing based on the resize parameters.
 *
 * @param string $basefile The full path to the source image file.
 * @param array  $resize   Array containing resize parameters:
 *                         - width: Target width in pixels
 *                         - height: Target height in pixels
 *                         - crop: Whether to crop the image (boolean)
 *                         - mode: Resize mode ('r' for retina or empty)
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
 * Serve a file directly to the browser.
 *
 * Determines the MIME type of the file and sends appropriate headers
 * before serving the file content directly to the browser.
 *
 * @param string $filename The full path to the file to serve.
 */
function sfp_serve_requested_file( $filename ) {
	// find the mime type
	$finfo = finfo_open( FILEINFO_MIME_TYPE );
	$type  = finfo_file( $finfo, $filename );
	// serve the image this one time (next time the webserver will do it for us)
	ob_end_clean();
	header( 'Content-Type: ' . $type );
	header( 'Content-Length: ' . filesize( $filename ) );
	readfile( $filename );
	exit;
}

/**
 * Prevent WordPress from generating resized images on upload.
 *
 * Intercepts the intermediate image sizes configuration and saves it to a global
 * variable, then returns an empty array to prevent WordPress from generating
 * any resized versions during upload.
 *
 * @param array $sizes Array of image sizes to generate.
 * @return array Empty array to prevent WordPress from generating resized images.
 */
function sfp_image_sizes_advanced( $sizes ) {
	global $dynimg_image_sizes;

	// save the sizes to a global, because the next function needs them to lie to WP about what sizes were generated
	$dynimg_image_sizes = $sizes;

	// force WP to not make sizes by telling it there's no sizes to make
	return array();
}
add_filter( 'intermediate_image_sizes_advanced', 'sfp_image_sizes_advanced' );

/**
 * Generate fake metadata for attachment images.
 *
 * Tricks WordPress into thinking that resized images were generated during upload
 * by creating fake metadata entries for each image size that would normally be created.
 * This allows WordPress to reference these sizes even though they don't exist locally.
 *
 * @param array $meta The attachment metadata.
 * @return array Modified metadata with fake size entries added.
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
add_action( 'admin_menu', 'sfp_admin_menu' );
add_action( 'admin_init', 'sfp_admin_init' );

/**
 * Add the Stage File Proxy settings page to the WordPress dashboard.
 */
function sfp_admin_menu() {
	add_options_page( 'Stage File Proxy Settings', 'Stage File Proxy', 'manage_options', 'stage-file-proxy', 'sfp_options_page' );
}

/**
 * Register settings, sections, and fields.
 */
function sfp_admin_init() {
	// Register individual options used by the plugin
	register_setting( 'sfp-settings-group', 'sfp_url', 'sfp_sanitize_url' );
	register_setting( 'sfp-settings-group', 'sfp_mode' );
	register_setting( 'sfp-settings-group', 'sfp_local_dir' );

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
 * Sanitize the URL input, ensuring it's valid and stripped of the trailing slash.
 *
 * @param string $input The URL submitted by the user.
 * @return string The sanitized URL or the old option value on error.
 */
function sfp_sanitize_url( $input ) {
	$sanitized = sanitize_url( $input );

	// Check if the URL is valid.
	if ( ! filter_var( $sanitized, FILTER_VALIDATE_URL ) ) {
		add_settings_error( 'sfp_url', 'invalid-url', 'Please enter a valid, complete URL (including http:// or https://).', 'error' );
		return get_option( 'sfp_url' ); // Return previous value on failure
	}

	// Trim trailing slash for clean concatenation later
	return rtrim( $sanitized, '/' );
}

/**
 * Section Callback: Description for the main section.
 */
function sfp_section_callback() {
	echo '<p>Configure the remote production site URL and the operating mode for file retrieval.</p>';
}

/**
 * Field Callback: Production URL.
 */
function sfp_url_callback() {
	$value         = get_option( 'sfp_url' );
	$constant      = defined( 'STAGE_FILE_PROXY_URL' ) ? STAGE_FILE_PROXY_URL : '';
	$disabled      = ! empty( $constant );
	$disabled_attr = $disabled ? 'disabled="disabled"' : '';

	echo '<input type="text" id="sfp-url" name="sfp_url" value="' . esc_attr( $value ) . '" placeholder="e.g., https://production.com" style="width: 100%; max-width: 400px;" ' . $disabled_attr . ' />';
	if ( $disabled ) {
		echo '<p class="description">Configuration overridden by <code>STAGE_FILE_PROXY_URL</code> constant: <code>' . esc_html( $constant ) . '</code></p>';
	} else {
		echo '<p class="description">The base URL of your remote environment, which must not have a trailing slash.</p>';
	}
}

/**
 * Field Callback: Proxy Mode.
 */
function sfp_mode_callback() {
	$value         = get_option( 'sfp_mode', 'header' );
	$constant      = defined( 'STAGE_FILE_PROXY_MODE' ) ? STAGE_FILE_PROXY_MODE : '';
	$disabled      = ! empty( $constant );
	$disabled_attr = $disabled ? 'disabled="disabled"' : '';

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
		echo '<p class="description">Configuration overridden by <code>STAGE_FILE_PROXY_MODE</code> constant: <code>' . esc_html( $constant ) . '</code></p>';
	} else {
		echo '<p class="description">Choose the method Stage File Proxy uses to retrieve or serve files.</p>';
	}
}

/**
 * Field Callback: Local Fallback Directory (used for 'local' mode).
 */
function sfp_local_dir_callback() {
	$value = get_option( 'sfp_local_dir', 'sfp-images' );

	$constant      = defined( 'STAGE_FILE_PROXY_LOCAL_DIR' ) ? STAGE_FILE_PROXY_LOCAL_DIR : '';
	$disabled      = ! empty( $constant );
	$disabled_attr = $disabled ? 'disabled="disabled"' : '';

	echo '<input type="text" id="sfp-local-dir" name="sfp_local_dir" value="' . esc_attr( $value ) . '" style="width: 200px;" ' . $disabled_attr . ' />';

	if ( $disabled ) {
		echo '<p class="description">Configuration overridden by <code>STAGE_FILE_PROXY_LOCAL_DIR</code> constant: <code>' . esc_html( $constant ) . '</code></p>';
	} else {
		echo '<p class="description">Subdirectory name within your active theme (e.g., <code>/wp-content/themes/your-theme/<strong>sfp-images</strong>/</code>) where local fallback images are stored for "Local Fallback" mode.</p>';
	}
}


/**
 * Render the full Admin Options Page.
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
 * Stage File Proxy: Automatically determine the local subdirectory path to strip.
 *
 * This function is necessary for correctly handling single-site installs in
 * subdirectories and multisite subdirectory installs by normalizing the URL path.
 *
 * @return string The subdirectory path, e.g., '/myproject' or '' for root installs.
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
 * Get the relative file path by stripping out the local subdirectory and the /wp-content/uploads/ business.
 * MODIFIED to include subdirectory stripping logic for accurate remote URL construction.
 */
function sfp_get_relative_path() {
	static $path;

	if ( ! $path ) {
		$uploads_url_path = wp_parse_url( content_url( '/uploads' ), PHP_URL_PATH );

		$request_uri = $_SERVER['REQUEST_URI'];

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
			// Fallback: Use the original regex logic for safety/multisite site ID path stripping
			$path = preg_replace( '/.*\/wp\-content\/uploads(\/sites\/\d+)?\//i', '', $request_uri );
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
 * Get the local fallback directory name.
 *
 * Retrieves the directory name for local fallback images used in 'local' mode.
 * Configuration is prioritized from constants, then database option, then default.
 *
 * @return string The local directory name (defaults to 'sfp-images').
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

	if ( empty( $local_dir ) ) {
		$local_dir = 'sfp-images'; // Default fallback
	}

	return $local_dir;
}


/**
 * Get a random file from the local fallback directory.
 *
 * Searches the local fallback directory for images and returns a random file path.
 * Excludes already resized images from selection. Results are cached using transients.
 *
 * @param bool $doing_resize Whether the request is for a resized image.
 * @return string|false The full path to a random local image file, or false if none found.
 */
function sfp_get_random_local_file_path( $doing_resize ) {
	$transient_key = 'sfp-replacement-images';

	// Use the central getter function to fetch the directory name
	$local_dir = sfp_get_local_dir();

	$replacement_image_path = get_template_directory() . '/' . $local_dir . '/';

	// Cache image directory contents
	if ( false === ( $images = get_transient( $transient_key ) ) ) {
		$images = array(); // Initialize as array to prevent errors if glob fails
		foreach ( glob( $replacement_image_path . '*' ) as $filename ) {
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

	$rand = rand( 0, count( $images ) - 1 );
	return $replacement_image_path . $images[ $rand ];
}

/**
 * Get the Stage File Proxy operating mode.
 *
 * Determines which mode SFP should operate in (header, download, photon, local, lorempixel).
 * Configuration is prioritized from constants, then database option, then default ('header').
 *
 * @return string The operating mode.
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
 * Get the base URL of the remote uploads directory.
 *
 * Retrieves the production/remote site URL where files should be fetched from.
 * Configuration is prioritized from constants, then database option, then error handling.
 * Will trigger an error if no URL is configured and mode is not 'local'.
 *
 * @return string|null The base URL for remote file requests.
 */
function sfp_get_base_url() {
	static $url = null;
	$mode = sfp_get_mode();

	if ( $url !== null ) {
		return $url;
	}

	if ( defined( 'STAGE_FILE_PROXY_URL' ) ) {
		$url = STAGE_FILE_PROXY_URL;
	} else {
		$url = get_option( 'sfp_url' );
	}

	// Error handling: If URL is empty and mode is not 'local', exit with an error.
	if ( ! $url && 'local' !== $mode ) {
		sfp_error();
	}

	return $url;
}

/**
 * Get the local uploads base URL for comparison in content rewrites.
 *
 * @return string The local uploads base URL, without trailing slash.
 */
function sfp_get_local_base_url() {
	static $local_base_url = null;
	if ( $local_base_url === null ) {
		$uploads_dir = wp_upload_dir();
		// Use the full baseurl (e.g., http://local.dev/wp-content/uploads)
		$local_base_url = rtrim( $uploads_dir['baseurl'], '/' );
	}
	return $local_base_url;
}

/**
 * Map a URL to a local file path in the uploads directory.
 *
 * @param  string $url URL to map.
 * @return string Local file system path.
 */
function sfp_map_url_to_local_path( $url ) {
	$upload_dir = wp_upload_dir();
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

	$relative   = ltrim( $relative, '/' );
	$local_path = trailingslashit( $basedir ) . $relative;

	return $local_path;
}

/**
 * Replace the local URL base with the remote base URL for an attachment URL.
 *
 * @param string $url The local URL.
 * @return string The remote URL.
 */
function sfp_rewrite_local_to_remote( $url ) {
	$local_base  = sfp_get_local_base_url();
	$remote_base = sfp_get_base_url();

	// Check that we have valid bases and that the URL belongs to the local uploads area.
	if ( empty( $local_base ) || empty( $remote_base ) || strpos( $url, $local_base ) === false ) {
		return $url;
	}

	return str_replace( $local_base, $remote_base, $url );
}

/**
 * Filter attachment URLs to use remote URLs when local files don't exist.
 *
 * Checks if the requested attachment file exists locally, and if not,
 * rewrites the URL to point to the remote server instead.
 *
 * @param string $url The attachment URL.
 * @return string The original URL if file exists locally, or remote URL if it doesn't.
 */
function sfp_wp_get_attachment_url_rewrite( $url ) {
	$local_base = sfp_get_local_base_url();
	if ( strpos( $url, $local_base ) !== false ) {
		$local_path = sfp_map_url_to_local_path( $url );
		if ( ! file_exists( $local_path ) ) {
			$url = sfp_rewrite_local_to_remote( $url );
		}
	}
	return $url;
}
add_filter( 'wp_get_attachment_url', 'sfp_wp_get_attachment_url_rewrite', 99 );

/**
 * Filter attachment image source results to use remote URLs when local files don't exist.
 *
 * Processes the image source array returned by wp_get_attachment_image_src()
 * and rewrites the URL to point to the remote server if the local file doesn't exist.
 *
 * @param array $image Array containing image data: [url, width, height, resized].
 * @return array Modified image array with remote URL if local file doesn't exist.
 */
function sfp_wp_get_attachment_image_src_rewrite( $image ) {
	if ( isset( $image[0] ) && ! empty( $image[0] ) ) {
		$local_base = sfp_get_local_base_url();
		if ( strpos( $image[0], $local_base ) !== false ) {
			$local_path = sfp_map_url_to_local_path( $image[0] );
			if ( ! file_exists( $local_path ) ) {
				$image[0] = sfp_rewrite_local_to_remote( $image[0] );
			}
		}
	}
	return $image;
}
add_filter( 'wp_get_attachment_image_src', 'sfp_wp_get_attachment_image_src_rewrite', 99 );

/**
 * Filter image srcset sources to use remote URLs when local files don't exist.
 *
 * Processes each source in an image's srcset attribute and rewrites URLs
 * to point to the remote server if the corresponding local files don't exist.
 *
 * @param array $sources Array of image sources for different sizes.
 * @return array Modified sources array with remote URLs where needed.
 */
function sfp_wp_calculate_image_srcset_rewrite( $sources ) {
	if ( ! is_array( $sources ) || empty( $sources ) ) {
		return $sources;
	}

	$local_base = sfp_get_local_base_url();

	foreach ( $sources as $size => $source ) {
		if ( isset( $source['url'] ) && strpos( $source['url'], $local_base ) !== false ) {
			$local_path = sfp_map_url_to_local_path( $source['url'] );
			if ( ! file_exists( $local_path ) ) {
				$sources[ $size ]['url'] = sfp_rewrite_local_to_remote( $source['url'] );
			}
		}
	}

	return $sources;
}
add_filter( 'wp_calculate_image_srcset', 'sfp_wp_calculate_image_srcset_rewrite', 99 );


/**
 * Filters the post content to rewrite img src and background URLs that don't exist locally.
 *
 * @param string $content Content of the current post.
 * @return string
 */
function sfp_the_content_rewrite( $content ) {
	// Only run on the front end and if we have content.
	if ( is_admin() || empty( $content ) ) {
		return $content;
	}

	$local_base = sfp_get_local_base_url();

	// 1. Rewrite <img> tag sources
	$content = preg_replace_callback( '/<img[^>]+src=["\\\']([^"\\\']+)["\\\'][^>]*>/i', function ( $matches ) use ( $local_base ) {
		$img_tag = $matches[0];
		$src = $matches[1];

		if ( strpos( $src, $local_base ) !== false ) {
			$local_path = sfp_map_url_to_local_path( $src );
			if ( ! file_exists( $local_path ) ) {
				$new_src = sfp_rewrite_local_to_remote( $src );
				$img_tag = str_replace( $src, $new_src, $img_tag );
			}
		}
		return $img_tag;
	}, $content );


	// 2. Rewrite inline CSS background-image and shorthand background:url(...)
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
				if ( ! file_exists( $local_path ) ) {
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
add_filter( 'the_content', 'sfp_the_content_rewrite', 99 );

/**
 * Handle Stage File Proxy errors and terminate execution.
 *
 * Displays an error message and stops script execution when SFP
 * encounters an unrecoverable error during file processing.
 */
function sfp_error() {
	die( 'SFP tried to load, but encountered an error' );
}
