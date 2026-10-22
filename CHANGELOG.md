# Changelog

All notable changes to this plugin are recorded here, newest first.
The plugin header version (101) was not changed by the entries below.

## 2026-10-22

Compatibility update for WordPress 7.1.3 and PHP 8.0 to 8.5, support for any site layout, correct image sizes for WordPress VIP, input hardening, a faster filter path, and a regression test suite.

### Added

- **WordPress VIP image sizes.** On VIP, sizes are not files. VIP resizes from the original with query arguments (`hero.png?resize=200,200`, `hero.png?w=200`, `hero.png?w=200&h=200&crop=1`), but the plugin made up names such as `hero-200x200.png`, which do not exist there. Before, with an image from VIP:
  - a size gave the full-size image (the attachment data had no sizes),
  - the srcset held made-up `hero-150x150c.png`, `hero-200x200c.png` files that return 404 on VIP,
  - an `<img src="hero.png?w=200">` in post content got a srcset such as `hero.png?w=200-150x150c.png?w=200`.

  Now, when the site is a VIP site:
  - `sfp_get_remote_image_style()` returns `vip` when the VIP mu-plugins or constants are present, and `standard` otherwise. Override it with the new `STAGE_FILE_PROXY_IMAGE_STYLE` constant (`vip` or `standard`) or the new `sfp_remote_image_style` filter.
  - `sfp_build_remote_image_sizes()` and `sfp_inject_remote_image_sizes()` give an image that exists only on the remote site the size list VIP builds, so WordPress writes the right `src` and `srcset` (`hero.png?resize=200,200 200w`). The list is identical to the output of VIP's own generator for landscape, portrait and small images, including hard-crop sizes. Nothing is added where VIP's own filter runs, for images that exist on this server, or for files that are not images.
  - `sfp_rewrite_url_if_missing()`, `sfp_rewrite_srcset_value()` and `sfp_rewrite_srcset_attributes()` move the URLs of a srcset (filter, post content, image blocks) to the remote site and keep the query string. This is the part of the old `local-extras.php` helper that the plugin lacked. No file names are made up.
  - File checks ignore the query string, so `hero.png?w=200` counts as present when `hero.png` exists.
  - `download` mode fetches the original, so `hero.png?w=200` cannot save a 200 px copy as `hero.png`.
  - The settings page says which style is used.
  - A theme can still change the URLs. The NextGear 2025 theme, for example, adds `w`, `h`, `resize` and `strip=all` to every image URL with `add_query_arg`, which also turns `200,200` into `200%2C200`. It does the same on VIP production, on the same `?resize=W,H` input, so local output now matches production. The plugin only moves the result to the remote site.
  - **No change for other sites.** Every one of these behaviours depends on the style being `vip`. A site without VIP signs runs the same code as before: on single sites, both multisite types and Bedrock, the baseline and the new file give identical results for 136 image checks (`src`, `srcset`, `<img>` tags, attachment data, content, blocks, URL rewrites, file lookups). The same holds on a VIP site that sets the style to `standard`.

- **Dynamic uploads path.** The uploads location is now read from the site setup and no longer fixed to `/wp-content/uploads`.
  - New `sfp_get_remote_uploads_path()` works out the path on the remote site. It removes the parts that only exist locally (the site or WordPress folder, such as `/us`, `/blog` or `/wp`) and keeps `/sites/N` only for subdirectory networks, as before.
  - New `sfp_get_uploads_needles()` and `sfp_is_uploads_request()` decide which requests the plugin handles, from the same setup. `/wp-content/uploads/` is always accepted as a fallback.
  - Works without any setting on: single sites, WordPress in its own folder, a site in a subfolder, multisite (subdomain and subdirectory), a custom content folder such as Bedrock (`/app/uploads`), and the `UPLOADS` constant (`/wp-content/media`).
  - New filter `sfp_remote_uploads_path` for a remote site that uses another folder layout.
  - Standard layouts give the same remote URLs as before. The older code gave a wrong remote URL on Bedrock and with `UPLOADS`, and never noticed Bedrock upload requests.
- `sfp_get_request_uri()`: reads `REQUEST_URI` safely. It returns an empty string under WP-CLI and cron, and it accepts the absolute form some proxies send.
- `sfp_get_mime_type()`: uses the fileinfo extension when it exists and the file extension otherwise. It does not warn on a missing or unreadable file.
- `sfp_local_file_exists()`: remembers a file check for the rest of the request.
- `sfp_sanitize_mode()`: the mode setting accepts only `header`, `download`, `photon`, `local` and `lorempixel`. Case and surrounding spaces are ignored, so `Header` is saved as `header`. Other values keep the saved setting.
- `sfp_sanitize_local_dir()`, `sfp_is_unsafe_local_dir()` and `sfp_normalize_local_dir()`: the local fallback folder must stay inside the theme. Values with `..` or an absolute path are rejected with a settings error.
- Settings page, fields controlled by a PHP constant:
  - The field is read-only whenever the constant is defined, also when its value is empty.
  - It shows the value that is used: the domain for `STAGE_FILE_PROXY_URL`, the selected mode for `STAGE_FILE_PROXY_MODE`, the folder for `STAGE_FILE_PROXY_LOCAL_DIR`.
  - The note names the constant, shows its value (credentials in a URL are hidden as `***`) and says the saved value is ignored.
  - Warnings for an empty URL constant, an unknown mode constant (it behaves like Download) and an unsafe local folder constant (the default folder is used).
  - The section text lists the constants in use, including `STAGE_FILE_PROXY_IMAGE_STYLE`, and says which remote image style is used.
- `Requires PHP: 8.0` and `Requires at least: 5.9` plugin headers. `5.9` is needed for `sanitize_url()`.
- Tests, in `tests/` (run them with WP-CLI from the WordPress root):
  - `run-tests.php`: about 300 checks.
    - Direct function tests: URL helpers, remote URL building, content and block filters, settings sanitizers, constants on the settings page, remote image metadata (HTTP mocked), plugin load order, the activation redirect, request URI handling, helper functions.
    - Real HTTP requests to a fake production site and to the WordPress install, through PHP's built-in server: the `header`, `photon`, `download`, `local` and `lorempixel` modes, resize and crop, errors, subsites of a network (by path or by host).
    - VIP style: size lists (against VIP's own generator where the VIP mu-plugins are installed), the attachment data filter and all its skips, srcset, content and blocks, URLs with resize queries, `download` mode, odd srcset HTML (single quotes, upper-case, `data-srcset`, line breaks, `&amp;`), hook priorities, and the whole path through WordPress core for a real image (attachment data, `src`, `srcset`, `<img>` tag). A theme that adds its own arguments is accepted. The older checks run with the style pinned to `standard`, which proves that path is unchanged on a VIP site too.
    - The new constant on the settings page, the style note, and the order of constant, filter and detection.
    - Adapts to the site it runs on. `SFP_EXPECT_UPLOADS` sets the remote uploads path for another layout.
    - Cases for common human mistakes: pasted URLs with `wp-content/uploads`, trailing slashes or spaces, no scheme, `ftp://` or `javascript:` values, wrong-case modes, `../` and absolute fallback folders, a forgotten URL, an unreachable or 404 remote, upper-case folders and extensions, encoded spaces, query strings.
  - `run-config-tests.php`: about 30 checks of the documented WP-CLI commands (`wp option update|get|delete` for `sfp_url`, `sfp_mode`, `sfp_local_dir`), saved options driving every mode over HTTP, and constants overriding saved options. It writes the options, so it needs `SFP_TEST_ALLOW_OPTION_WRITES=1`, runs on the main site only, and restores the original values.
  - `helpers.php` (shared helpers), `router.php` (router for the built-in server) and `prod-router.php` (fake production site that answers `?w=` and `?resize=` with a small image). Both routers exit unless they run on `cli-server`.
  - The test servers run with `display_errors` off, so notices from other code cannot reach a response.
- Documentation: README sections on the new filter, folder layouts, hosts (tested and not tested), HTTP basic auth through `sfp_http_remote_args`, and running the tests.

### Changed

- The remote URL of a pasted option value: `sfp_get_base_url()` now reduces a saved `sfp_url` that holds a `wp-content/uploads` path to its domain, as it already did for the constant and as the README promises. Before, `wp option update sfp_url 'https://x.com/wp-content/uploads/'` gave `…/wp-content/uploads/wp-content/uploads/…`.
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
- Every function has a full doc comment with `@param`, `@return` and details. The PHP code is the same with comments removed.

### Fixed

- **PHP 8.1 and later, WP-CLI and cron:** the top-level code read `$_SERVER['REQUEST_URI']` without a check. It caused an undefined-key warning and a `stripos(null)` deprecation when no web request exists.
- **PHP 8:** `foreach` over the result of `glob()` raised a warning when `glob()` returned `false`. The result is now cast to an array.
- **PHP 8:** the `Content-Type` header of the remote HEAD request could be an array, and `strpos()` throws a `TypeError` for an array. The header is read with `wp_remote_retrieve_header()` and checked with `is_string()`.
- **PHP 8.1 and later:** `wp_parse_url( ..., PHP_URL_PATH )` can return `null`. Two uses are cast to `(string)` so they never pass `null` to `strpos()` or `preg_match()`.
- Serving a file whose MIME type cannot be read no longer fails when the fileinfo extension is missing or disabled, and no longer warns when the file is unreadable.
- `sfp_sanitize_url()` no longer fails when it receives a value that is not a string.
- Doc comments: the filter name in `sfp_dispatch()` was written as `sfp_http_request_args`. The real hook is `sfp_http_remote_args`. The mode list in `sfp_get_mode()` named a `redirect` mode that does not exist.

### Fixed (tests)

- The plugin order test used to write its fake plugin list (`a/a.php`, `b/b.php`) into the real `active_plugins` option of the site that ran the tests, which deactivated the real plugins of that site. It now only reads the list `sfp_first()` would save: a last filter hands back the old value, so nothing is written, and a check fails if the stored list changed anyway. This was found on a real site, and reproduced and verified on a scratch install (the old test overwrote the list, the new one leaves it as it was).
- The tests refuse to run unless the environment type is `local` or `development` (`SFP_TEST_ALLOW_ANY_ENV=1` overrides this).
- A new check scans the plugin source and fails if the plugin calls a VIP function directly (for example `wpvip_get_app_name()`) or reads a VIP constant without `defined()`. The plugin only uses `function_exists()`, `has_filter()` and `defined()` for VIP, so it cannot cause an "undefined function" fatal on any site. Proven with two deliberate breakages.
- The checks that need an administrator skip cleanly when the super admin login names a user that does not exist.

### Notes

- A fatal such as `Call to undefined function wpvip_get_app_name()` on a local copy of a VIP site does not come from this plugin. It happens with the plugin switched off as well. The newer VIP mu-plugins need `mu-plugins/000-pre-vip-config/requires.php` loaded from `wp-config.php` on local setups that do not use VIP's own environment. See the README.

### Security

- **Local fallback folder:** with `STAGE_FILE_PROXY_LOCAL_DIR` set to a value such as `../../..`, local mode listed and served entries from outside the theme folder. `sfp_get_local_dir()` now falls back to `sfp-images` for any value with `..` or an absolute path. This covers the constant and values already saved in the database.

### Performance

The plugin adds little work to a page, and the changes keep it that way. Measured on a single site with 24 missing images (CLI, no opcache):

| | No plugin | Original code | Now |
| :--- | :--- | :--- | :--- |
| `the_content`, 24 missing images | 286 µs | 417 µs | 395 µs |
| `the_content`, text only | 289 µs | 292 µs | 292 µs |
| 24 attachment URLs | 43 µs | 111 µs | 96 µs |

- On a site without VIP signs, the new attachment data filter ends after a style check: about 0.35 µs per read (43 µs to 52 µs for 24 reads). Printing 24 image tags costs about 3 % more than before, which is within the noise of the measurement.
- With the `vip` style, printing an image tag costs about 17 µs more, because the tag now carries a real srcset with every size, and the size list is built once per request and image.
- Deciding whether a request is for an upload takes 0.2 µs once the folder list is built (measured on the current code only).
- File checks are remembered for the request. One image is checked by up to five filters, and PHP does not cache a failed lookup.
- Content and image blocks without a local uploads URL skip the regex work.
- The remote metadata filter returns early for attachments that are not images, which avoids a remote request for them.

### Verified

- **Layouts**, each with the full test suite: single site, WordPress in `/wp`, a site in `/blog`, multisite subdomain (main site and a subsite), multisite subdirectory (main site and a subsite), Bedrock, and the `UPLOADS` constant.
- **PHP 8.1.29, 8.2.29, 8.3.11, 8.4.10 and 8.5.3** (the builds LocalWP ships), plus 8.5.12 and a PHPCompatibility check for 8.0 and later. PHP 8.0 itself was not run.
- **LocalWP**, the real multisite with the VIP mu-plugins: the full suite on the main site and both subsites, and real requests through Local's nginx and PHP-FPM (missing files redirect to the configured remote, existing files are served by nginx, normal pages and login are untouched).
- **Original code compared with now**, for the documented WP-CLI and constant workflows on a single site, a subdirectory network and a subdomain network: 31 of 32 results are identical. The one difference is the fix for a pasted `wp-content/uploads` path.
- **Mutation checks:** deliberate breakages of the plugin, 22 earlier ones, 8 for the VIP code and its gates, and 4 for the srcset parsing, hooks and size arguments, each caught by a test.
- **WordPress VIP**, with your real VIP mu-plugins loaded in local mode, and VIP's own generator as the reference:
  - the LocalWP copy of your site (subdirectory network: main site, `/us/`, `/ca/`), including real requests through Local's nginx for `?resize=`, `?w=` and `?w=&h=&crop=1` URLs,
  - a VIP single site,
  - a VIP subdomain network (main site and a subsite),
  - a VIP subdirectory network (main site and a subsite).

  On each, the size list equals VIP's generator, and `src` and `srcset` for a custom 200×200 crop size are remote URLs with the resize arguments. On a subdirectory subsite the remote path includes `/sites/N`, on a subdomain subsite it does not (the long-standing rule below).
- **Real VIP data:** your database already holds sizes that VIP itself saved (`ca-home.jpg?resize=216,300` and so on), which confirms the format. For the real images that have them, the plugin builds the same sizes for 18 of 19. The one difference is an edited image whose saved sizes belong to the edited file, while its attachment data points to the original; the plugin builds from the attachment data, as VIP's own code does.
- Not run on a live VIP environment (the VIP file service itself).
- Not tested on the real service: WP Engine, Pressable, Kinsta, Pantheon and other hosts. See the README for notes.

### Known behaviour, not changed

- Local mode caches a failed image lookup for a request URI with no expiry (`sfp_image_<md5>` transient). Adding fallback images later does not help for URIs that already failed until that transient is deleted.
- `STAGE_FILE_PROXY_MODE` set to an unknown value, such as `Header` with a capital letter, behaves like `download`. Only the settings page normalises the value. The settings page now warns about it.
- Local mode serves any entry in the fallback folder, not only images.
- `STAGE_FILE_PROXY_URL` without a scheme, such as `example.com`, produces a relative redirect target.
- The `lorempixel` mode points to a service that has shut down.
- On a subsite of a subdomain network, a request path keeps its `sites/N` folder, while a rewritten attachment URL drops it. Both were already so. The remote path for subdomain networks (no `sites/N`) is the plugin's long-standing rule and was not changed for VIP. If a VIP subdomain network keeps `sites/N` on its file service, use the `sfp_remote_uploads_path` filter.
- `sfp_ensure_remote_image_metadata()` checks for the local base URL, but the plugin's own `wp_get_attachment_url` filter has already replaced it with the remote URL. The branch seldom runs in practice.
- `download` mode reads the whole remote file into memory, so a file larger than the PHP memory limit fails.
- With the `vip` style, `src` for a size comes from the attachment data (`?resize=W,H`). VIP itself uses `?w=W` or `?w=W&h=H&crop=1` for a single image. Both give the same picture on VIP.
- A request for a file name with a size suffix (`hero-200x200.png`) is still sent to the remote site as it is. It works where the file exists (images from before VIP) and does not on VIP for newer uploads. The plugin no longer makes such names up.
- Two copies of the plugin loaded together (for example in `mu-plugins` and `plugins`) would stop with a "cannot redeclare function" error. Not tested.
