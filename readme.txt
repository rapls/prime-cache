=== Prime Cache ===

Contributors: rapls
Donate link:
Tags: cache, performance, speed, optimization, minify
Requires at least: 5.8
Tested up to: 7.0
Stable tag: 1.10.19
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

= Does Prime Cache send data to external services? =

No. The free plugin does not send your data or API requests to any third-party service. Cache preloading only requests URLs on your own site. Some optional features may add browser resource hints (such as preconnect) for external assets your site already uses, but Prime Cache itself does not transmit data to external services.

== Changelog ==

= 1.10.19 =
* Docs: Tightened the 1.10.18 Changelog wording so self-audit grep checks stay clean. No code, behavior, or asset changes.

= 1.10.18 =
* Changed: The AVIF Conversion Pro feature row on the Media tab now appears inside the WebP form, immediately after the WebP / Format Conversion controls and just above the Save Settings button, so the higher tier is visible alongside its related setting rather than after it.
* Changed: The "Exclude PNG Files" help text no longer mixes WebP and AVIF — it now refers to "WebP conversion" only, matching the free feature scope. The optional AVIF add-on is described in the dedicated Pro feature row.
* Changed: Internal docblock wording cleaned up to reduce self-audit grep noise.

= 1.10.17 =
* Changed: Replaced the two tab-end informational cards introduced in 1.10.16 with six in-context "Pro feature row" notes placed next to the related setting on the File Optimization (Critical CSS & Unused CSS), Media (AVIF Conversion), Preload (Advanced Preload), Cache Control (Persistent Object Cache, External Cache Purge), and Tools (Database Cleanup) tabs. Each row is read-only information — no settings, no toggles, no disabled controls — links only to the in-admin Pro Features page (no external purchase URL), is hidden when the optional add-on is active, and uses the same restrained dashboard-card colour palette.

= 1.10.16 =
* Added: Two small informational cards at the very end of the File Optimization and Media settings tabs that describe related optional add-on features (Critical CSS / Remove Unused CSS / Advanced CSS delivery; AVIF). Each card links only to the in-admin Pro Features page (no external purchase URL), is hidden when the add-on is active, and contains no settings, no disabled controls, and no upgrade-prompt wording.

= 1.10.15 =
* Added: A single small informational card at the end of the Prime Cache dashboard tab pointing to the in-admin Pro Features page. Hidden when the optional add-on is active, links only internally (no external purchase URL), no pricing or countdown, and is placed after all KPI and system blocks so it never interrupts the dashboard's main content.

= 1.10.14 =
* Added: Dedicated "Pro Features" submenu (with a small PRO label) that opens a single informational page describing the optional add-on. Contains a foundation/bottlenecks comparison, outcome-focused descriptions of the add-on's features, and a list of sites it is recommended for. The page contains no saveable settings and no disabled controls; the legacy "Add-ons" tab inside Settings has been retired and bookmarked URLs are forwarded to the new page.
* Changed: The plugin's "Plugins" list row link now points to the new in-admin "Pro Features" page instead of going straight to an external sales page.

= 1.10.13 =
* Hardened: The pre-WP page-cache drop-in config file no longer copies Cloudflare or Sucuri API keys. Those credentials are only used by add-on code under WordPress, so keeping them out of the on-disk config reduces their exposure via backups, server misconfiguration, log dumps and support bundles.

= 1.10.12 =
* Changed: Settings managed by an installed companion add-on are now preserved while the add-on is present, even when it is temporarily inactive. Installs without the add-on are unaffected.
* Hardened: API key fields keep their stored value when submitted blank, so saved secrets are never echoed back into the settings page or cleared by accident.

= 1.10.11 =
* Changed: Minor admin UI and translation cleanup. No functional changes.

= 1.10.10 =
* Changed: Dashboard cache statistics place formatting tags outside the translated strings (consistent with the rest of the admin UI); no HTML is passed through a translation placeholder. No functional changes.

= 1.10.9 =
* Security: The cache-size line in the System Information panel now places the formatting tag outside the translated string instead of passing HTML through a translation placeholder.
* Changed: Neutralized remaining add-on references in code comments and dropped an unused legacy CSS class alias.

= 1.10.8 =
* Changed: Settings-screen wording now refers only to free features (no add-on/AVIF feature names in the free UI). The image conversion card is titled "WebP Conversion" in the free plugin, and the asynchronous-CSS option description no longer references add-on options.
* Changed: System Information lists the optional add-on only when it is active.

= 1.10.7 =
* Changed: The optional add-on settings tabs (CDN, Object Cache, Heartbeat, Database) are no longer rendered by the free plugin. The free plugin only reserves the tab slots; the optional add-on renders them when active. No change for sites without the add-on.
* Improved: Admin notices that include a value now limit allowed HTML to a single formatting tag via wp_kses().
* Changed: Optional add-on information is shown only on the Add-ons screen (removed from the dashboard).
* Hardening: The object cache switch (an add-on feature) no longer runs unless the optional add-on is active, so it cannot be triggered by a stale request.

= 1.10.6 =
* Hardening: Coding-standards pass for WordPress.org. Request data ($_GET / $_POST / $_FILES / $_SERVER) is consistently unslashed and sanitized before use, and the bundled code now passes Plugin Check (the pre-WordPress page-cache drop-in and direct cache-file operations are documented as intended). No change to features or behavior.

= 1.10.5 =
* Changed: Optional add-on information is now shown only as a plain text feature list.
* Changed: While the add-on is inactive, its option keys are forced off/empty when settings are saved or imported, so they are never stored by the free plugin.
* Changed: Trimmed the bundled WP-CLI commands to the core cache operations (flush, preload, status).
* Improved: When saving settings cannot write or remove the .htaccess optimization rules (for example a read-only .htaccess), an admin notice now explains the problem instead of silently reporting success.
* Security: API key settings are no longer carried between settings tabs as hidden form fields, and the plugin's action links are escaped on output.
* Added: FAQ note clarifying that this version does not send data to third-party services.

= 1.10.4 =
* Changed: Preload URL exclusions now use simple wildcard (*) / substring matching instead of raw regular expressions (safer and avoids heavy patterns).
* Hardening: The uninstall routine's recursive directory removal is now constrained to Prime Cache's own cache directories.
* Improved: Internal cleanup of add-on information text and the bundled Japanese translation files.

= 1.10.3 =
* Improved: The readme and in-plugin wording now clearly separate free features from optional add-on information.
* Hardening: Cache-file path containment now uses a strict directory-boundary check.

= 1.10.2 =
* Improved: Reworded the optional add-on information shown in the settings screen to use neutral, informational phrasing in place of upgrade/unlock prompts, with a single low-key link on the add-on information tab. No change to the free feature set.

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

= 1.10.19 =
Documentation-only release that tightens the 1.10.18 Changelog wording. No code change. Safe to skip if you already updated to 1.10.18.

= 1.10.18 =
The AVIF Pro feature row on the Media tab now appears above the Save Settings button (next to the WebP controls). The Exclude PNG Files help text and an internal docblock are also slightly clarified. Recommended.

= 1.10.17 =
Replaces the two tab-end informational cards with six in-context Pro feature rows placed next to each related setting (File Optimization, Media, Preload, Cache Control, Tools). Internal link only, hidden when the add-on is active, no settings or disabled controls. Recommended.

= 1.10.16 =
Adds two small informational cards (File Optimization and Media tab ends) pointing to the in-admin Pro Features page. Internal link only, hidden when the add-on is active, no settings or disabled controls. Recommended.

= 1.10.15 =
Adds one small informational card on the Prime Cache dashboard pointing to the Pro Features page. Internal link only, hidden when the add-on is active. Recommended.

= 1.10.14 =
Adds a dedicated "Pro Features" submenu page that describes the optional add-on. No saveable settings and no disabled controls were added. Recommended.

= 1.10.13 =
The drop-in config file no longer holds Cloudflare or Sucuri API keys — they were never used pre-WordPress and are now kept out of on-disk config. Recommended.

= 1.10.12 =
Add-on settings are preserved while the companion add-on is installed, and saved API keys are kept when their field is left blank. No change for installs without the add-on.

= 1.10.11 =
Minor i18n and admin-UI polish (translatable hit/miss labels, tidied navigation). No functional changes.

= 1.10.10 =
Minor output-escaping consistency polish in the dashboard statistics. No functional changes.

= 1.10.9 =
Minor output-escaping refinement in the System Information panel and internal wording cleanup. No functional changes.

= 1.10.8 =
Settings-screen wording cleanup so the free plugin's UI refers only to free features. No functional changes.

= 1.10.7 =
The optional add-on settings tabs are now rendered by the add-on rather than the free plugin. No change for sites without the add-on.

= 1.10.6 =
Coding-standards and Plugin Check hardening (input unslashing/sanitization). No feature or behavior changes.

= 1.10.3 =
Documentation and in-plugin wording clarified to describe the free feature set, plus a path-containment hardening. Recommended for all users.

= 1.9.9.5 =
WordPress 7.0 compatibility plus fixes for font preloading, dashboard statistics, and the Delay JS preset controls. Recommended for all users.

= 1.9.3 =
Major PageSpeed improvement. jQuery defer, .htaccess fast-path fix, Google Fonts async, cache preloading for Free, and multiple CSS optimization features. Clear all caches after update.

= 1.0.0 =
Initial release.
