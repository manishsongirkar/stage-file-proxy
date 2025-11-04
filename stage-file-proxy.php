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

		$uploads_dir = wp_upload_dir();
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
	$remote_url = sfp_construct_remote_url( $relative_path );

	/**
	 * Filter: sfp_http_request_args
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
 * prevent WP from generating resized images on upload
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
 *
 * @param array|false $metadata Image metadata array or false if not found.
 * @param int $attachment_id The attachment ID.
 * @return array|false Modified metadata or false if unable to process.
 */
function sfp_ensure_remote_image_metadata( $metadata, $attachment_id ) {
	if ( ! empty( $metadata ) ) {
		return $metadata; // Metadata already exists
	}

	$file = get_attached_file( $attachment_id );
	if ( ! $file ) {
		return $metadata;
	}

	// Check if this is a remote-only image (doesn't exist locally)
	if ( file_exists( $file ) ) {
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
		if ( ! is_wp_error( $image_info ) && isset( $image_info['headers']['content-type'] ) ) {
			$content_type = $image_info['headers']['content-type'];

			// If it's an image, try to get dimensions
			if ( strpos( $content_type, 'image/' ) === 0 ) {
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
					unlink( $temp_file );
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
 */
function sfp_admin_menu() {
	add_options_page( 'Stage File Proxy Options', 'Stage File Proxy', 'manage_options', 'stage-file-proxy', 'sfp_options_page' );
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
 * Sanitize the URL input, ensuring it's valid and extracting only the domain part.
 *
 * @param string $input The URL submitted by the user.
 * @return string The sanitized domain URL or the old option value on error.
 */
function sfp_sanitize_url( $input ) {
	$sanitized = sanitize_url( $input );

	// Check if the URL is valid.
	if ( ! filter_var( $sanitized, FILTER_VALIDATE_URL ) ) {
		add_settings_error( 'sfp_url', 'invalid-url', 'Please enter a valid, complete URL (including http:// or https://).', 'error' );
		return get_option( 'sfp_url' ); // Return previous value on failure
	}

	// Extract domain part and remove any wp-content/uploads paths
	$clean_url = sfp_extract_domain_from_url( $sanitized );

	return $clean_url;
}

/**
 * Section callback for the plugin settings page.
 * Displays a description of the proxy configuration section.
 */
function sfp_section_callback() {
	echo '<p>Configure the remote production site URL and the operating mode for file retrieval.</p>';
}

/**
 * Renders the URL field in the settings page.
 * Creates the input field for the production URL setting.
 */
function sfp_url_callback() {
	$value         = get_option( 'sfp_url' );
	$constant      = defined( 'STAGE_FILE_PROXY_URL' ) ? STAGE_FILE_PROXY_URL : '';
	$disabled      = ! empty( $constant );
	$disabled_attr = $disabled ? 'disabled="disabled"' : '';

	echo '<input type="text" id="sfp-url" name="sfp_url" value="' . esc_attr( $value ) . '" placeholder="e.g., https://production.com" style="width: 100%; max-width: 400px;" ' . $disabled_attr . ' />';
	if ( $disabled ) {
		echo '<p class="description">Configuration overridden by <code>STAGE_FILE_PROXY_URL</code> constant: <code>' . esc_html( $constant ) . '</code><br>';
		echo '<strong>Note:</strong> You can provide either the base domain (e.g., https://production.com) or full URL including wp-content/uploads path. The plugin will automatically extract the domain part and handle path structure correctly.</p>';
	} else {
		echo '<p class="description">The base domain URL of your remote environment (e.g., https://production.com). You can also provide the full URL including wp-content/uploads path - the plugin will automatically extract the domain. The plugin will automatically preserve the wp-content/uploads path structure when constructing remote URLs.</p>';
	}
}

/**
 * Renders the mode selection field in the settings page.
 * Creates the dropdown for selecting the proxy mode.
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

	// Add JavaScript to show/hide local directory field based on mode selection
	?>
	<script type="text/javascript">
	jQuery(document).ready(function($) {
		function toggleLocalDirField() {
			var selectedMode = $('#sfp-mode').val();
			var localDirRow = $('#sfp-local-dir').closest('tr');

			// Check if mode is overridden by constant
			<?php if ( $disabled && $constant === 'local' ): ?>
				// Mode is set to 'local' by constant, always show
				localDirRow.show();
			<?php elseif ( $disabled && $constant !== 'local' ): ?>
				// Mode is set to non-local by constant, always hide
				localDirRow.hide();
			<?php else: ?>
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
		<?php if ( ! $disabled ): ?>
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
 */
function sfp_local_dir_callback() {
	$value = get_option( 'sfp_local_dir', '' );

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
 * Renders the main settings page for Stage File Proxy.
 * Displays the configuration form with all available options.
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
 * to the wp-content/uploads directory, handling various WordPress installation types.
 *
 * @return string The relative path to the requested file.
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

	if ( empty( $local_dir ) ) {
		$local_dir = 'sfp-images'; // Default fallback
	}

	return $local_dir;
}

/**
 * Get a random local file path for fallback when remote files are unavailable.
 *
 * In 'local' mode, this function selects a random image from the local uploads
 * directory to serve as a placeholder when the requested remote file is not available.
 *
 * @param bool $doing_resize Whether the request is for a resized image.
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
 * Get the current proxy mode setting.
 *
 * Determines how the plugin should handle missing files:
 * - 'redirect': Redirect to remote files
 * - 'download': Download and serve files locally
 * - 'header': Send HTTP redirect headers
 * - 'photon': Use Photon for image processing
 * - 'local': Use local fallback images
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

	// Get the local uploads directory info
	$uploads_dir       = wp_upload_dir();
	$local_uploads_url = $uploads_dir['baseurl'];

	// Parse the local uploads URL to get just the path part
	$local_uploads_path = wp_parse_url( $local_uploads_url, PHP_URL_PATH );

	// Default to standard wp-content/uploads path
	$clean_uploads_path = '/wp-content/uploads';

	// Handle different WordPress installation scenarios
	if ( is_multisite() ) {
		// For multisite, we need to preserve the sites/N structure for subdirectory networks
		if ( ! is_subdomain_install() ) {
			// Multisite subdirectory: preserve /sites/N/ structure
			if ( preg_match( '#/wp-content/uploads/sites/(\d+)#', $local_uploads_path, $matches ) ) {
				$clean_uploads_path = '/wp-content/uploads/sites/' . $matches[1];
			}
		}
		// For subdomain multisite, use standard /wp-content/uploads (no sites/N)
	} else {
		// Single site: check if WordPress is installed in a subdirectory
		$home_url_path = wp_parse_url( home_url(), PHP_URL_PATH );
		$site_url_path = wp_parse_url( site_url(), PHP_URL_PATH );

		// Normalize paths
		$home_url_path = $home_url_path ? rtrim( $home_url_path, '/' ) : '';
		$site_url_path = $site_url_path ? rtrim( $site_url_path, '/' ) : '';

		// If site_url contains WordPress core files in a subdirectory (e.g., /wp/)
		// but home_url is at root, we still use standard uploads path
		if ( $home_url_path !== $site_url_path ) {
			// WordPress core in subdirectory, but home is at root
			$clean_uploads_path = '/wp-content/uploads';
		} else if ( ! empty( $home_url_path ) ) {
			// Both home and site in same subdirectory - this is a site subdirectory install
			// We want to strip the local subdirectory for the remote URL
			$clean_uploads_path = '/wp-content/uploads';
		}
	}

	// Build remote URL: domain from config + clean wp-content path + relative file path
	return rtrim( $base_url, '/' ) . $clean_uploads_path . '/' . ltrim( $relative_path, '/' );
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

		// Find position of wp-content in the path
		$wp_content_pos = stripos( $path, '/wp-content' );

		if ( $wp_content_pos !== false ) {
			// Keep only the path before wp-content
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
 * are not available. Supports both constant-based and options-based configuration.
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
		$url = get_option( 'sfp_url' );
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
		$uploads_dir = wp_upload_dir();
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
	$local_uploads_path = wp_parse_url( $local_base, PHP_URL_PATH );
	$relative_path      = '';

	if ( strpos( $url_parsed['path'], $local_uploads_path ) === 0 ) {
		$relative_path = substr( $url_parsed['path'], strlen( $local_uploads_path ) );
	}

	// Determine the clean uploads path based on WordPress installation type
	$clean_uploads_path = '/wp-content/uploads';

	if ( is_multisite() ) {
		// For multisite, preserve the sites/N structure for subdirectory networks
		if ( ! is_subdomain_install() ) {
			// Multisite subdirectory: preserve /sites/N/ structure
			if ( preg_match( '#/wp-content/uploads/sites/(\d+)#', $local_uploads_path, $matches ) ) {
				$clean_uploads_path = '/wp-content/uploads/sites/' . $matches[1];
			}
		}
		// For subdomain multisite, use standard /wp-content/uploads
	}
	// For single site (including subdirectory installs), always use /wp-content/uploads

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
		if ( ! file_exists( $local_path ) ) {
			$url = sfp_rewrite_local_to_remote( $url );
		}
	}
	return $url;
}
add_filter( 'wp_get_attachment_url', 'sfp_wp_get_attachment_url_rewrite', 99 );





/**
 * Enhanced srcset generation for attachments that don't exist locally.
 *
 * This function generates srcset URLs for remote images by creating the expected
 * intermediate size URLs even when the files don't exist locally.
 */
function sfp_generate_remote_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
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
	if ( file_exists( $local_path ) ) {
		return $sources; // File exists locally, let WordPress handle it normally
	}

	// Get the original file info
	$upload_dir = wp_upload_dir();
	$base_url   = $upload_dir['baseurl'];

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
		if ( ! file_exists( $resized_local_path ) ) {
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
	if ( file_exists( $local_path ) ) {
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
 * in content have proper srcset and sizes attributes.
 */
function sfp_enhance_content_images( $content ) {
	// Only run on the front end and if we have content.
	if ( is_admin() || empty( $content ) ) {
		return $content;
	}

	$local_base = sfp_get_local_base_url();

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
				if ( ! file_exists( $local_path ) ) {
					$new_src = sfp_rewrite_local_to_remote( $src );

					// Try to add responsive attributes if missing
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
add_filter( 'the_content', 'sfp_enhance_content_images', 98 );

/**
 * Generate srcset URLs from a main image URL.
 *
 * This function creates responsive image URLs based on common WordPress image sizes.
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
 */
function sfp_wp_get_attachment_image_src_enhanced( $image, $attachment_id, $size, $icon ) {
	if ( ! $image || empty( $image[0] ) ) {
		return $image;
	}

	$local_base = sfp_get_local_base_url();

	// Check if this is a local upload URL that doesn't exist locally
	if ( strpos( $image[0], $local_base ) !== false ) {
		$local_path = sfp_map_url_to_local_path( $image[0] );
		if ( ! file_exists( $local_path ) ) {
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
 * @param string $block_content The rendered block content.
 * @param array $block The block data.
 * @return string The enhanced block content.
 */
function sfp_render_block_core_image( $block_content, $block ) {
	if ( $block['blockName'] !== 'core/image' || empty( $block_content ) ) {
		return $block_content;
	}

	$local_base = sfp_get_local_base_url();

	// Process image blocks to ensure remote images have responsive attributes
	$block_content = preg_replace_callback(
		'/<img([^>]*?)src=["\\\']([^"\\\']+)["\\\']([^>]*?)>/i',
		function ( $matches ) use ( $local_base ) {
			$before_src = $matches[1];
			$src       = $matches[2];
			$after_src = $matches[3];

			if ( strpos( $src, $local_base ) !== false ) {
				$local_path = sfp_map_url_to_local_path( $src );
				if ( ! file_exists( $local_path ) ) {
					$new_src = sfp_rewrite_local_to_remote( $src );

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
 */
function sfp_error() {
	die( 'SFP tried to load, but encountered an error' );
}
