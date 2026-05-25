=== Prime Cache ===

Contributors: rapls
Donate link:
Tags: cache, performance, speed, optimization, minify
Requires at least: 5.8
Tested up to: 7.0
Stable tag: 1.10.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight page caching and performance plugin for WordPress.

== Description ==

Prime Cache is a lightweight performance plugin for WordPress. It provides page caching, browser cache headers, basic file optimization, lazy loading, WebP conversion, cache preloading, and cache purge tools.

= Free Features =

* Page Cache
* Browser Cache Headers
* .htaccess Optimization
* Gzip Compression
* 404 Page Caching
* HTML / CSS / JavaScript Minification
* Inline Small CSS
* Defer JavaScript
* Delay JavaScript
* Google Fonts display=swap
* Lazy Load
* WebP Conversion
* Image Resize on Upload
* EXIF Data Removal
* Bulk WebP Optimization
* Cache Preloading for homepage, public posts, and public taxonomies
* Link Prefetching
* Performance Tweaks (disable emoji, jQuery Migrate, embeds, and other WordPress bloat)
* Automatic Cache Purge on content changes
* Security Headers
* Import / Export
* WP-CLI Support

= Optional Add-on Features =

Some additional performance features are available as a separate add-on from the author website. They are not required for the free plugin to work.

= Internationalization =

* English (source)
* Japanese translation included (WordPress Translation Style Guide compliant)
* Translation-ready with .pot template

== Installation ==

1. Upload the `prime-cache` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Prime Cache in the admin menu
4. The Dashboard tab shows an overview of all features
5. Enable Page Cache in the Page Cache tab to get started

= Quick Start =

1. **Page Cache tab** - Enable page caching and .htaccess optimization
2. **File Optimization tab** - Enable HTML, CSS, and JS minification
3. **Media tab** - Enable lazy loading and WebP conversion
4. **Preload tab** - Enable cache preloading and link prefetching

== Screenshots ==

1. Dashboard - Overview of cache status, hit rate, and feature status
2. Page Cache - General settings and browser cache settings
3. File Optimization - HTML/CSS/JS minification and performance tweaks
4. Media - Lazy load, WebP conversion, and bulk optimization
5. Preload - Cache warming and link prefetching
6. Cache Control - Query string, cookie, and exclusion controls
7. Auto Purge - Automatic purge triggers
8. Tools - Import/export, security headers, and system information

== Frequently Asked Questions ==

= What are the server requirements? =

WordPress 5.8+, PHP 7.4+. For WebP conversion, the GD or Imagick PHP extension is required.

= Is Prime Cache compatible with other caching plugins? =

No. Running multiple caching plugins causes conflicts. Prime Cache automatically detects and warns about 14 known caching plugins. Deactivate other caching plugins before using Prime Cache.

= Does it work with Nginx? =

Yes. Page caching, file optimization, media optimization, and all PHP-based features work on any server. The .htaccess optimization feature is Apache-specific but can be disabled on Nginx.

= Does it support WooCommerce? =

Yes. WooCommerce cart, checkout, and account pages are automatically excluded from caching. Additional WooCommerce optimizations include disabling scripts on non-WC pages and disabling cart fragments AJAX.

= How do I clear the cache? =

Multiple ways: Admin bar menu (10+ options), Dashboard quick actions, WP-CLI commands (`wp prime-cache flush`), or automatic purge triggers.

= Can I disable cache for a specific page? =

Yes. Edit the post/page and check "Disable cache for this page" in the Prime Cache metabox in the sidebar.

= Does the .htaccess fast-path work for all requests? =

The .htaccess fast-path serves cached pages without loading PHP for maximum speed. It requires: lowercase Host header, no query string (unless cache_query_strings is disabled), no Vary Cookies, ASCII-safe URL paths, and GET requests only. Requests that don't match these conditions are served via the PHP drop-in, which is still very fast but not zero-PHP.

= Does it support WordPress multisite? =

Page caching is not supported on multisite installations. Other features (file optimization, lazy load, image optimization, etc.) work normally on multisite.

= How does the image optimization work? =

On upload (if auto-convert is enabled), JPG/PNG images are converted to WebP. All thumbnail sizes are also converted. WebP is served to supporting browsers via .htaccess rewrite rules, picture tags, or URL rewriting.

= Is it safe to enable all optimizations? =

Start with page caching and basic file optimization. Test your site after each change. JavaScript defer and delay may affect some themes or plugins — test thoroughly.

== Changelog ==

= 1.10.3 =
* Improved: The readme and in-plugin wording now describe only the features included in the free plugin; optional features provided by the separate add-on are listed separately.
* Improved: "Go Pro" / "Get Pro" / "Upgrade to Pro" links reworded to neutral "Add-ons" / "Learn about optional add-ons".
* Hardening: Cache-file path containment now uses a strict directory-boundary check (no functional change).

= 1.10.2 =
* Improved: Reworded the optional add-on information shown in the settings screen — neutral, informational phrasing (e.g. "Available in Prime Cache Pro", "Learn more about Prime Cache Pro") in place of upgrade/unlock prompts, and a single, low-key link on the add-on information tab. No change to the free feature set.

= 1.10.1 =
* Security: The .htaccess fast-path now rejects path-traversal sequences ("..") in the request URI.
* Security: The query-string / Vary-cookie cache-key suffix is widened to 64-bit to prevent collision-based cache poisoning.
* Security: The "Logged-in User Cache" setting description now states clearly that it serves one shared cached copy to all visitors — only enable it on sites that serve identical content to everyone.
* Fix: URL image-delivery mode no longer rewrites src/srcset inside <script>, <template> (including nested templates) or <textarea>, preventing corruption of client-side templates; real <picture>/<source>/<noscript> markup is still rewritten.
* Fix: Images whose source file was replaced are re-converted instead of being suppressed by a stale ".skip" marker, and a stale "optimized" record is cleared when a replaced image can no longer produce a variant.
* Fix: WordPress Coding Standards compliance (output escaping, i18n translator comments, intentional direct-DB-query annotations).

= 1.10.0 =
* New: WebP image conversion is now a free feature — convert on upload, bulk-optimize the media library, serve via .htaccess rewrite / <picture> tag / URL replacement, and view per-image savings in the Media Library column.
* New: Extension hooks (prime_cache_convert_image_extra, prime_cache_picture_extra_sources, prime_cache_url_rewrite_format, prime_cache_image_needs_conversion, prime_cache_image_htaccess_rules, prime_cache_image_has_extra_formats, prime_cache_preload_urls) let the optional add-on layer additional formats and preloading on top.
* Change: Image conversion to AVIF, YouTube thumbnail replacement, and advanced preloading are provided by the separate add-on; the free plugin no longer bundles that code.
* Improved: The settings screen now includes clearer information about optional add-on features near the related settings.
* Fix: WebP server-support is detected and reported on the Media tab.

= 1.9.9.5 =
* Tested: Confirmed compatible with WordPress 7.0
* Fix: Resolve "preg_match(): Unknown modifier" warning in font preload detection — woff/woff2 URLs containing a query string or fragment were silently skipped, breaking font preloading on PHP 8.x
* Fix: Cache hit/miss statistics now accumulate correctly. The stats file was opened write-only, so reads failed and the dashboard counters could not grow
* Fix: Prevent the "translation loading triggered too early" notice on WordPress 6.7+ during the one-time Delay JS Timeout migration
* Fix: 3rd-Party Script Delay preset checkboxes now save reliably in the Free version (their JavaScript handler was blocked by an unrelated control guard)
* Improved: Add-on feature information is now shown near the related settings for easier discovery

= 1.9.3 =
* Fix: Place .htaccess cache rewrite rules before WordPress rewrite block for PHP-less serving
* Fix: Defer jQuery safely by wrapping inline jQuery code with DOMContentLoaded
* Fix: Stop treating Cache-Control no-cache as uncacheable (allow caching with security plugins)
* Fix: Self-heal setup on admin_init when activation hook fails silently
* Fix: Auto-replace orphaned advanced-cache.php from deactivated or unknown plugins
* New: Google Fonts async loading (media=print onload pattern) with automatic preconnect
* New: Cache preloading available for Free users (homepage + public posts)
* New: Preload triggers on plugin activation and settings save
* New: Async non-first CSS for Free (reduce render-blocking)
* New: Inline small CSS for Free (eliminate HTTP requests for small stylesheets)
* New: Lazy load configurable skip-first-N images (default 3) with fetchpriority=high on first image
* New: Preconnect/DNS-prefetch limiting enabled by default (cap at 4, remove self-origin)
* New: System Info shows dropin loaded status and WP_CACHE runtime/file diagnostics

= 1.0.0 =
* Initial release: page cache (advanced-cache.php drop-in), browser cache headers, .htaccess optimization, Gzip compression, 404 caching, HTML/CSS/JS minification, lazy load, WebP conversion, bulk image optimization, cache preloading, link prefetching, automatic cache purge, performance tweaks, security headers, import/export, and WP-CLI support.

== Upgrade Notice ==

= 1.10.3 =
Documentation and in-plugin wording clarified to describe the free feature set, plus a path-containment hardening. Recommended for all users.

= 1.9.9.5 =
WordPress 7.0 compatibility plus fixes for font preloading, dashboard statistics, and the Delay JS preset controls. Recommended for all users.

= 1.9.3 =
Major PageSpeed improvement. jQuery defer, .htaccess fast-path fix, Google Fonts async, cache preloading for Free, and multiple CSS optimization features. Clear all caches after update.

= 1.0.0 =
Initial release.
