# Changelog

All notable changes to the plugin code are recorded here, newest first.
The plugin header version (101) was not changed by the entries below.

## 2026-10-22

Compatibility with WordPress 7.1.3 and PHP 8.0 to 8.5, support for any site layout, correct image sizes for WordPress VIP, and stricter settings input.

### Added

- **WordPress VIP image sizes.** VIP does not store a file per size. It resizes from the original with query arguments (`hero.png?resize=200,200`, `hero.png?w=200`, `hero.png?w=200&h=200&crop=1`). The plugin used to make up file names such as `hero-200x200.png`, which do not exist on VIP, so a size gave the full-size image, the srcset pointed to files that return 404, and an `<img src="hero.png?w=200">` in post content got a broken srcset such as `hero.png?w=200-150x150c.png?w=200`. Now, when the site is a VIP site:
  - `sfp_get_remote_image_style()` returns `vip` when the VIP mu-plugins or VIP constants are present, and `standard` otherwise. Set it with the new `STAGE_FILE_PROXY_IMAGE_STYLE` constant (`vip` or `standard`) or the new `sfp_remote_image_style` filter.
  - `sfp_build_remote_image_sizes()` and `sfp_inject_remote_image_sizes()` give an image that exists only on the remote site the same size list that the VIP mu-plugin builds on production (`file.png?resize=W,H` for every registered size that fits), so WordPress writes the right `src` and `srcset`. The `intermediate_image_sizes_advanced` filter still applies, so a theme can remove sizes. Nothing is added where VIP's own filter runs, for images that exist on this server, or for files that are not images. The filter runs at priority 20 on `wp_get_attachment_metadata`.
  - `sfp_rewrite_url_if_missing()`, `sfp_rewrite_srcset_value()` and `sfp_rewrite_srcset_attributes()` move the URLs of a srcset (in the `wp_calculate_image_srcset` filter, post content and image blocks) to the remote site and keep the query string. No file names are made up, and no srcset is added to an image that has none.
  - File checks ignore the query string, so `hero.png?w=200` counts as present when `hero.png` exists.
  - `download` mode fetches the original, so `hero.png?w=200` cannot save a small copy as `hero.png`.
  - Every one of these behaviours depends on the style being `vip`. A site without VIP signs runs the same code as before.
- **Dynamic uploads path.** The uploads location is read from the site setup and is no longer fixed to `/wp-content/uploads`.
  - `sfp_get_remote_uploads_path()` works out the path on the remote site. It removes the parts that only exist locally (the site or WordPress folder, such as `/us`, `/blog` or `/wp`) and keeps `/sites/N` only for subdirectory networks, as before.
  - `sfp_get_uploads_needles()` and `sfp_is_uploads_request()` decide which requests the plugin handles, from the same setup. `/wp-content/uploads/` is always accepted as a fallback.
  - It works without any setting on single sites, WordPress in its own folder, a site in a subfolder, multisite (subdomain and subdirectory), a custom content folder such as Bedrock (`/app/uploads`), and the `UPLOADS` constant (`/wp-content/media`). Standard layouts give the same remote URLs as before.
  - New filter `sfp_remote_uploads_path` for a remote site that uses another folder layout.
- `sfp_get_request_uri()`: reads `REQUEST_URI` safely. It returns an empty string under WP-CLI and cron, and accepts the absolute form some proxies send.
- `sfp_get_mime_type()`: uses the fileinfo extension when it exists and the file extension otherwise. It does not warn on a missing or unreadable file.
- `sfp_local_file_exists()`: remembers a file check for the rest of the request.
- `sfp_sanitize_mode()`: the mode setting accepts only `header`, `download`, `photon`, `local` and `lorempixel`. Case and surrounding spaces are ignored, so `Header` is saved as `header`. Other values keep the saved setting.
- `sfp_sanitize_local_dir()`, `sfp_is_unsafe_local_dir()` and `sfp_normalize_local_dir()`: the local fallback folder must stay inside the theme. Values with `..` or an absolute path are rejected with a settings error.
- Settings page, fields controlled by a PHP constant:
  - The field is read-only whenever the constant is defined, also when its value is empty.
  - It shows the value that is used: the domain for `STAGE_FILE_PROXY_URL`, the selected mode for `STAGE_FILE_PROXY_MODE`, the folder for `STAGE_FILE_PROXY_LOCAL_DIR`.
  - The note names the constant, shows its value (credentials in a URL are hidden as `***`) and says the saved value is ignored.
  - Warnings for an empty URL constant, an unknown mode constant (it behaves like Download) and an unsafe local folder constant (the default folder is used).
  - The section text lists the constants in use, including `STAGE_FILE_PROXY_IMAGE_STYLE`.
- `Requires PHP: 8.0` and `Requires at least: 5.9` plugin headers. `5.9` is needed for `sanitize_url()`.

### Changed

- `sfp_get_base_url()` reduces a saved `sfp_url` that holds a `wp-content/uploads` path to its domain, as it already did for the constant. Before, `wp option update sfp_url 'https://x.com/wp-content/uploads/'` gave `…/wp-content/uploads/wp-content/uploads/…`.
- Settings are registered with the array form of `register_setting()` (`type`, `sanitize_callback`, and a `default` of `header` for the mode). The old form still works in core, but it is the legacy style.
- `sfp_url` is trimmed before it is sanitized, so a pasted value with trailing spaces is no longer rejected. It accepts only `http` and `https` URLs.
- A settings field that is not submitted (disabled by a constant) keeps its saved value on Save in all three sanitizers. Before, saving the page cleared the saved local folder.
- `sfp_first()` uses the real plugin path (`plugin_basename()`), so the plugin still moves to the front when its folder has another name, and it ignores an `active_plugins` value that is not an array.
- The activation redirect skips AJAX, cron and REST requests, and users who cannot manage options. The flag stays until a person with access opens the dashboard. Before, the first AJAX request after activation could be redirected.
- `rand()` is now `wp_rand()` and `unlink()` is now `wp_delete_file()`. `array_search()` in `sfp_first()` runs in strict mode.
- `ini_set()` is only called when the host allows it.
- A served file clears all output buffers, not only the plugin's own, so a buffer from another plugin cannot change the file.
- `wp_upload_dir( null, false )` is used wherever only a URL or path is read. It no longer creates folders or raises errors on read-only storage.
- The `disabled` attribute on the settings fields is printed with `disabled()`. The HTML is `disabled='disabled'` with single quotes, which browsers treat the same way.
- Less work per page: file checks are remembered for the request, content and image blocks without a local uploads URL skip the regex work, and the remote metadata filter returns early for attachments that are not images, which avoids a remote request for them.
- Every function has a full doc comment with `@param`, `@return` and details. The PHP code is the same with comments removed.

### Fixed

- **PHP 8.1 and later, WP-CLI and cron:** the top-level code read `$_SERVER['REQUEST_URI']` without a check. It caused an undefined-key warning and a `stripos(null)` deprecation when no web request exists.
- **PHP 8:** `foreach` over the result of `glob()` raised a warning when `glob()` returned `false`. The result is now cast to an array.
- **PHP 8:** the `Content-Type` header of the remote HEAD request could be an array, and `strpos()` throws a `TypeError` for an array. The header is read with `wp_remote_retrieve_header()` and checked with `is_string()`.
- **PHP 8.1 and later:** `wp_parse_url( ..., PHP_URL_PATH )` can return `null`. Two uses are cast to `(string)` so they never pass `null` to `strpos()` or `preg_match()`.
- Serving a file whose MIME type cannot be read no longer fails when the fileinfo extension is missing or disabled, and no longer warns when the file is unreadable.
- `sfp_sanitize_url()` no longer fails when it receives a value that is not a string.
- Doc comments: the filter name in `sfp_dispatch()` was written as `sfp_http_request_args`. The real hook is `sfp_http_remote_args`. The mode list in `sfp_get_mode()` named a `redirect` mode that does not exist.

### Security

- **Local fallback folder:** with `STAGE_FILE_PROXY_LOCAL_DIR` set to a value such as `../../..`, local mode listed and served entries from outside the theme folder. `sfp_get_local_dir()` now falls back to `sfp-images` for any value with `..` or an absolute path. This covers the constant and values already saved in the database.
