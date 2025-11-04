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

## 🏗️ Installation Types Supported

✅ **Single Site (Root)**: `https://example.com/wp-content/uploads/`
✅ **Single Site (Subdirectory)**: `https://example.com/site/wp-content/uploads/`
✅ **Multisite Subdomain**: `https://site1.example.com/wp-content/uploads/`
✅ **Multisite Subdirectory**: `https://example.com/site1/wp-content/uploads/sites/2/`

The plugin automatically detects your installation type and handles URL construction accordingly.

## ⚠️ Important Notes

- **Never run this plugin in production** - it's designed for development/staging environments only
- The plugin automatically loads first to prevent conflicts with other plugins
- Responsive image features work with all modern WordPress themes and Gutenberg blocks
- All image processing maintains proper WordPress standards and metadata
