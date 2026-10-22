# Stage File Proxy

Mirror (or proxy to) uploaded files from a remote production site on your local development copy. This utility saves the trouble of downloading a giant uploads directory without sacrificing the images that accompany content.

This version (V101) includes **comprehensive responsive image support**, **intelligent domain extraction**, **dynamic conditional admin interface**, and robust support for **all WordPress installation types** including multisite subdirectories, single-site subdirectories, and proactive URL rewriting.

## ✨ Key Features

* **🖼️ Responsive Image Support:** Full srcset generation for remote images, ensuring proper responsive behavior even when files don't exist locally.

* **🔗 Smart URL Handling:** Automatically extracts domain from URLs - accepts both domain-only (`https://example.com`) and full paths (`https://example.com/wp-content/uploads/`) in configuration.

* **🎯 Dynamic Image Resizing:** Intercepts requests for cropped images (`image-300x200.jpg`) and dynamically creates the required size after downloading the original.

* **🌐 Universal WordPress Support:** Works seamlessly with single sites, multisite subdomains, multisite subdirectories, and WordPress installed in subdirectories.

* **⚡ Enhanced Content Processing:** Rewrites image URLs in post content, CSS backgrounds, Gutenberg blocks, and attachment metadata with full responsive attributes.

* **🎛️ Intelligent Admin Interface:** Local directory field appears only when "Local Fallback" mode is selected, providing a cleaner user experience.

## ⚙️ Configuration

Configuration can be handled using **PHP Constants (Recommended)** or the **Admin Settings Page**.

### 1. PHP Constants (Recommended for Production)

Defining constants in your `wp-config.php` file will override the database settings and provide environment consistency.

| Constant | Type | Description |
| :--- | :--- | :--- |
| `STAGE_FILE_PROXY_URL` | String | **REQUIRED.** Production site URL. Accepts domain only OR full path - plugin automatically extracts domain. |
| `STAGE_FILE_PROXY_MODE` | String | Operating mode (see Proxy Modes below). Default: `header` |
| `STAGE_FILE_PROXY_LOCAL_DIR` | String | Subdirectory in theme for fallback images (only for `local` mode). Default: `sfp-images` |
| `STAGE_FILE_PROXY_IMAGE_STYLE` | String | How the remote site names image sizes: `vip` or `standard`. Normally not needed, because it is detected (see [WordPress VIP sites](#-wordpress-vip-sites)). |

**Example Usage (`wp-config.php`):**

```php
// Flexible URL configuration - both formats work:
define( 'STAGE_FILE_PROXY_URL', 'https://production.example.com' );
// OR with full path (plugin extracts domain automatically):
// define( 'STAGE_FILE_PROXY_URL', 'https://production.example.com/wp-content/uploads/' );

// Set proxy mode
define( 'STAGE_FILE_PROXY_MODE', 'header' );

// Local fallback directory (only needed for 'local' mode)
define( 'STAGE_FILE_PROXY_LOCAL_DIR', 'fallback-images' );
```

### 2. Admin Settings Page

Access **Settings → Stage File Proxy** after activation. Constant-defined settings appear as read-only with clear indicators.

### 3. WP-CLI Commands

For developers who prefer command-line configuration, you can manage all Stage File Proxy settings using WP-CLI:

#### Set Production URL

```bash
# Set the remote production URL
wp option update sfp_url 'https://production.example.com'

# Verify the setting
wp option get sfp_url
```

#### Configure Proxy Mode

```bash
# Set to header redirect (fastest, recommended for development)
wp option update sfp_mode 'header'

# Set to download and save files locally
wp option update sfp_mode 'download'

# Set to use Photon/Jetpack CDN
wp option update sfp_mode 'photon'

# Set to local fallback images
wp option update sfp_mode 'local'

# Set to placeholder service
wp option update sfp_mode 'lorempixel'

# Check current mode
wp option get sfp_mode
```

#### Set Local Directory (for 'local' mode only)

```bash
# Set custom fallback directory in theme
wp option update sfp_local_dir 'my-fallback-images'

# Reset to default
wp option delete sfp_local_dir

# Check current setting
wp option get sfp_local_dir
```

#### Bulk Configuration

```bash
# Configure multiple settings at once
wp option update sfp_url 'https://production.example.com'
wp option update sfp_mode 'header'

# View all Stage File Proxy settings
wp option list --search="sfp_*"
```

#### Reset All Settings

```bash
# Remove all Stage File Proxy settings
wp option delete sfp_url
wp option delete sfp_mode
wp option delete sfp_local_dir

# Verify cleanup
wp option list --search="sfp_*"
```

#### Environment-Specific Scripts

**Development Setup:**
```bash
#!/bin/bash
# dev-setup.sh
wp option update sfp_url 'https://production.example.com'
wp option update sfp_mode 'header'
echo "✅ Stage File Proxy configured for development"
```

**Staging Setup:**
```bash
#!/bin/bash
# staging-setup.sh
wp option update sfp_url 'https://production.example.com'
wp option update sfp_mode 'download'
echo "✅ Stage File Proxy configured for staging"
```

## 🎯 Proxy Modes

| Mode | Behavior | Use Case |
| :--- | :--- | :--- |
| **`header`** | HTTP redirect to remote file (fastest, no local storage) | **Recommended** - Development environments |
| **`download`** | Downloads and saves files locally | Staging environments with local storage needs |
| **`photon`** | Redirects to Jetpack/WordPress.com CDN | Sites using Jetpack Photon service |
| **`local`** | Serves random fallback images from theme directory | Development with placeholder images |
| **`lorempixel`** | Redirects to placeholder image service | Development with generated placeholder images |

## 🛠️ Developer Hooks

### `sfp_http_remote_args`
Modify arguments for remote file requests:

```php
add_filter( 'sfp_http_remote_args', function( $args ) {
    $args['timeout'] = 60; // Increase timeout for large files
    return $args;
});
```

### `sfp_relative_path`
Modify file paths before remote URL construction:

```php
add_filter( 'sfp_relative_path', function( $path ) {
    return 'custom-prefix/' . $path;
});
```

### `sfp_remote_image_style`
Set the image URL style yourself. `vip` for a remote WordPress VIP site, `standard` for any other. See [WordPress VIP sites](#-wordpress-vip-sites).

```php
// This local site is not a VIP site, but the remote site is.
add_filter( 'sfp_remote_image_style', fn() => 'vip' );
```

### `sfp_remote_uploads_path`
The uploads path used on the remote site is worked out from this site's own setup (see below). Use this filter when the remote site uses a different folder layout than this one:

```php
// Local site uses /app/uploads (Bedrock), the remote site uses the standard folders.
add_filter( 'sfp_remote_uploads_path', function( $path ) {
    return '/wp-content/uploads';
});
```

### Remote site behind a password (HTTP basic auth)
Use `sfp_http_remote_args` to send credentials in `download` mode. Redirect modes (`header`, `photon`) cannot carry them.

```php
add_filter( 'sfp_http_remote_args', function( $args ) {
    $args['headers']['Authorization'] = 'Basic ' . base64_encode( 'user:password' );
    return $args;
});
```

## 🏢 WordPress VIP sites

WordPress VIP does not store a file per image size. Where WordPress writes `hero-200x200.png`, VIP resizes on the fly from the original, with query arguments: `hero.png?resize=200,200` for the sizes in the attachment data (this is what VIP's own mu-plugin builds), and `hero.png?w=200` or `hero.png?w=200&h=200&crop=1` for a single image. A request for `hero-200x200.png` does not find a file on VIP.

The plugin has two styles of remote image URL:

| Style | Remote sizes look like | Used when |
| :--- | :--- | :--- |
| `standard` | `hero-200x200.png` | Always, except as below. Nothing changes for these sites. |
| `vip` | `hero.png?resize=200,200` | The site shows VIP signs (the VIP mu-plugins or VIP constants are present), or you set it. |

The style is detected from the site. To set it, define the constant, or use the `sfp_remote_image_style` filter. The constant is for a local site without the VIP mu-plugins that copies a VIP site:

```php
define( 'STAGE_FILE_PROXY_IMAGE_STYLE', 'vip' );      // 'standard' turns the VIP support off on a VIP site
```

With the `vip` style:

- **Attachment data:** an image whose file is only on the remote site gets the same size list VIP builds (every registered size that fits, as `file.png?resize=W,H`). WordPress then builds `src` and `srcset` from it. The list equals what VIP's own generator gives (checked on landscape, portrait and small images, and for hard-crop sizes). Images that exist on this server keep their own data. Themes can still remove sizes with `intermediate_image_sizes_advanced`.
- **srcset and post content:** URLs for files missing here move to the remote site and keep their query string (`?resize=…`, `?w=…`). No file names are made up, and no `srcset` is added for an image that has none.
- **File checks ignore the query string:** `hero.png?w=200` counts as present when `hero.png` exists here.
- **`download` mode** fetches the original, so `hero.png?w=200` cannot save a small copy over `hero.png`.
- **Theme filters keep working.** A theme that adds its own arguments (`w`, `h`, `strip`) or re-encodes the comma (`%2C`) does so on production too; the plugin only moves the URL to the remote site.

Checked on single sites, subdirectory networks and subdomain networks, with the VIP mu-plugins loaded in local mode. On a subdirectory network the remote path keeps `/sites/N` for subsites. On a subdomain network it does not, as for every site type. If your VIP network differs, use the `sfp_remote_uploads_path` filter.

### Troubleshooting on a local copy of a VIP site

If a page shows `Call to undefined function wpvip_get_app_name()` (for example in Query Monitor's VIP panel), the cause is not this plugin: it never calls a VIP function, and a test checks that. The newer VIP mu-plugins expect `wp-content/mu-plugins/000-pre-vip-config/requires.php` to be loaded before `vip-config.php`. VIP's own local environment does this. For any other local setup, add this to `wp-config.php`, before the `vip-config.php` include:

```php
if ( file_exists( __DIR__ . '/wp-content/mu-plugins/000-pre-vip-config/requires.php' ) ) {
	require_once __DIR__ . '/wp-content/mu-plugins/000-pre-vip-config/requires.php';
}
```

## 🏗️ Installation Types Supported

| Setup / Environment | Example Uploads Path | Status |
| :--- | :--- | :---: |
| **Single Site (Root)** | `https://example.com/wp-content/uploads/` | ✅ |
| **Single Site (Subdirectory)** | `https://example.com/site/wp-content/uploads/` | ✅ |
| **Multisite Subdomain** | `https://site1.example.com/wp-content/uploads/` | ✅ |
| **Multisite Subdirectory** | `https://example.com/site1/wp-content/uploads/sites/2/` | ✅ |
| **WordPress in its own folder** (site address and WordPress address differ) | `https://example.com/wp/wp-content/uploads/` | ✅ |
| **Custom content folder, e.g. Bedrock** | `https://example.com/app/uploads/` | ✅ |
| **Custom `UPLOADS` constant** | `https://example.com/wp-content/media/` | ✅ |

The plugin reads the uploads location from WordPress and detects your installation type, so no path needs to be set. The remote site is expected to use the same folder layout as this one. If it does not, use the `sfp_remote_uploads_path` filter.

### Hosts and local tools

The plugin only needs WordPress, PHP 8.0 or later, and a web server that sends requests for missing files to WordPress, which is the normal setup. Nothing in it is specific to one host.

**Tested:** LocalWP (nginx and PHP-FPM; single site and multisite; PHP 8.1 to 8.5), and clean WordPress installs of every layout listed above.

**Not tested on the real service:** the other platforms below. The notes come from how each platform is built, so check them on your own setup.

| Platform | Notes |
| :--- | :--- |
| LocalWP (tested) | Local's nginx sends missing files to WordPress (`error_page 404 = @wperror`) and keeps the original request path. Works as is. |
| Flywheel hosting | Same nginx setup as Local. |
| WP Engine, Kinsta, Pressable, SiteGround, Cloudways, cPanel hosts | Standard layout. A rule that answers missing image files with a plain 404 before WordPress runs (some static-file rules) stops the plugin from seeing the request. |
| WordPress VIP | Loaded through `client-mu-plugins` or `wpcom_vip_load_plugin()`. Files live on a stream wrapper, so `header` mode is the one to use. The plugin remembers file checks to avoid repeated remote lookups. Image sizes use the `vip` style (see [WordPress VIP sites](#-wordpress-vip-sites)). Tested on a LocalWP copy of a VIP site, with VIP's own code as the reference. |
| Pantheon | Uploads are a mounted folder. `header` mode needs no write access. `download` mode needs a writable `files` folder. |
| Docker setups (wp-env, DDEV, Lando) | Standard layout. |
| IIS | WordPress corrects `REQUEST_URI` itself. Needs a rewrite rule that sends missing files to `index.php`. |

## ⚠️ Important Notes

- **Never run this plugin in production** - it's designed for development/staging environments only
- The plugin automatically loads first to prevent conflicts with other plugins
- Responsive image features work with all modern WordPress themes and Gutenberg blocks
- All image processing maintains proper WordPress standards and metadata

## 🧪 Running the tests

The tests are for a local or development site: they refuse to run when `WP_ENVIRONMENT_TYPE` is anything else (override with `SFP_TEST_ALLOW_ANY_ENV=1`, at your own risk). They need WP-CLI and a running database. Run them from the WordPress root. Both scripts exit with a non-zero code when a check fails.

```bash
# Regression suite: functions, filters, settings page, constants, and real HTTP requests
wp eval-file wp-content/plugins/stage-file-proxy/tests/run-tests.php

# Documented WP-CLI commands and constants, with saved options.
# This WRITES the sfp_* options (and restores them), so it needs an opt-in. Use a development copy and the main site.
SFP_TEST_ALLOW_OPTION_WRITES=1 wp eval-file wp-content/plugins/stage-file-proxy/tests/run-config-tests.php
```

The tests adapt to the site they run on. They expect the standard `/wp-content/uploads` path on the remote site. For another layout, say what the remote path should be:

```bash
SFP_EXPECT_UPLOADS=/app/uploads wp eval-file wp-content/plugins/stage-file-proxy/tests/run-tests.php
```

See [CHANGELOG.md](CHANGELOG.md) for what the tests cover and for the history of changes.
