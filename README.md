# Stage File Proxy

Mirror (or header to) uploaded files from a remote production site on your local development copy. This utility saves the trouble of downloading a giant uploads directory without sacrificing the images that accompany content.

This version (V105) includes robust support for **Multisite subdirectories**, **single-site subdirectories**, and **proactive URL rewriting** to handle images embedded with absolute URLs in your content.

## ✨ Features

* **Dynamic Image Resizing:** Intercepts requests for cropped images (`image-300x200.jpg`) and dynamically creates the required size after downloading the original, preventing WordPress from generating sizes on upload.

* **Proactive URL Rewriting:** Rewrites absolute local image URLs (found in post content, inline CSS, attachment data, and `srcset`) to point directly to the remote server if the local file is missing. This prevents client-side 404s for embedded files.

* **Subdirectory Support:** Automatically normalizes the request URI to work correctly when the local WordPress site or Multisite subsite is installed in a subdirectory (e.g., `localhost/project/site1/`).

* **Configurable Modes:** Supports multiple file retrieval and serving modes.

## ⚙️ Configuration

Configuration can be handled using **PHP Constants (Recommended)**, the **Admin Options Page**, or **WP-CLI**.

### 1. PHP Constants (Recommended for Developers)

Defining constants in your `wp-config.php` file will override the database settings and disable the Admin Options Page fields, ensuring environment consistency.

| Constant | Type | Description |
| :--- | :--- | :--- |
| `STAGE_FILE_PROXY_URL` | String | **MANDATORY.** The base URL of the remote production site (e.g., `https://my-prod-site.com`). Must not have a trailing slash. |
| `STAGE_FILE_PROXY_MODE` | String | Sets the operating mode (see Proxy Modes below). |
| `STAGE_FILE_PROXY_LOCAL_DIR` | String | The subdirectory inside your theme for fallback images (default: `sfp-images`). |

**Example Usage (`wp-config.php`):**

```php
// The URL of your production site (NO trailing slash)
define( 'STAGE_FILE_PROXY_URL', 'https://production.example.com' );

// Set mode to download and save files locally (vs 'header' redirect)
define( 'STAGE_FILE_PROXY_MODE', 'download' );
````

### 2\. Admin Options Page

Access the settings page via **Settings \> Stage File Proxy** after activation. Fields defined by constants will be shown as read-only.

### 3\. WP-CLI Setup

The settings are stored as standard WordPress options (`sfp_url`, `sfp_mode`, `sfp_local_dir`).

#### Set the Production URL

```bash
# Sets the remote URL
wp option update sfp_url 'https://production.example.com'
```

#### Set the Proxy Mode

```bash
# Set mode to download and save the file locally
wp option update sfp_mode download

# Set mode to redirect the user directly to the remote file (faster, no local file saved)
wp option update sfp_mode header
```

#### Set Local Fallback Directory

```bash
# Set the directory name within the theme (for 'local' mode)
wp option update sfp_local_dir 'my-theme-fallback-images'
```

## 🎯 Proxy Modes

| Mode | Description | Behavior |
| :--- | :--- | :--- |
| **`download`** | Downloads the file from the remote server, saves it to the local uploads folder, and serves it to the browser. (Recommended) | **Saves file locally.** |
| **`header`** | Issues an HTTP 302 Redirect directly to the remote file URL. | **Does NOT save file locally.** Fastest proxy option. |
| **`photon`** | Redirects image resize requests to the Jetpack/WordPress.com CDN URL structure. | Useful if using Jetpack's Photon service. |
| **`local`** | If the remote request fails, serves a random replacement image from the defined `sfp_local_dir` inside the active theme. | **Development Fallback.** |
| **`lorempixel`** | Redirects to a placeholder image service for the requested dimensions. | **Development Fallback.** |

## 🛠️ Developer Hooks

Developers can interact with the plugin using standard WordPress filters:

### `sfp_http_remote_args`

Allows modification of arguments passed to `wp_remote_get()` when fetching files from the remote server.

```php
/**
 * Example: Increase the timeout for large files.
 */
add_filter( 'sfp_http_remote_args', function( $args ) {
    $args['timeout'] = 60; // Set timeout to 60 seconds
    return $args;
});
```

### `sfp_relative_path`

Allows modification of the file path used to build the final remote URL, after the local subdirectory has been stripped.

```php
/**
 * Example: Add a custom folder slug to the path before fetching.
 */
add_filter( 'sfp_relative_path', function( $path ) {
    return 'custom-prefix/' . $path;
});
```
