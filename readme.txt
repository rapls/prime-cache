=== Prime Cache ===

Contributors: rapls
Donate link:
Tags: cache, performance, speed, optimization, minify
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A fast, stable, and feature-rich caching plugin for WordPress. Page cache, object cache, file optimization, media optimization, CDN, and more.

== Description ==

Prime Cache is a comprehensive WordPress performance optimization plugin that combines page caching, file optimization, media optimization, and advanced features into a single, easy-to-use package. Built by analyzing the best practices of leading caching plugins while maintaining completely original code.

= Core Caching =

* **Page Cache** - Serve static HTML files before WordPress loads via advanced-cache.php dropin
* **Object Cache** - Persistent object caching with APCu, Redis, or Memcached backends
* **Browser Cache** - Cache-Control headers with configurable lifetimes per file type
* **.htaccess Optimization** - Apache rewrite rules, mod_deflate, mod_expires, ETag removal
* **Gzip & Brotli Compression** - Pre-compress cache files and enable server-level compression
* **404 Page Caching** - Cache Not Found pages to reduce server load

= File Optimization =

* **Minify HTML** - Remove whitespace with regex or DOM parser mode
* **Minify CSS** - Remove comments and whitespace from stylesheets
* **Combine CSS** - Merge multiple CSS files into one
* **Inline Small CSS** - Inline CSS files below a configurable threshold
* **Minify JavaScript** - Remove comments and whitespace from JS files
* **Combine JavaScript** - Merge JS files to reduce HTTP requests
* **Defer JavaScript** - Add defer attribute to eliminate render-blocking JS
* **Delay JavaScript** - Delay all JS until user interaction (scroll, click, touch)
* **Delay JS Safe Mode** - Only delay external (third-party) scripts
* **Critical CSS** - Auto-generate or manually define above-the-fold CSS
* **Remove Unused CSS** - Analyze each page and strip unused CSS rules
* **Optimize CSS Delivery** - Choose between Remove Unused CSS or Async CSS loading
* **Local Google Analytics** - Host gtag.js/analytics.js locally
* **Remove Query Strings** - Strip ?ver= from static resource URLs
* **Disable WordPress Emoji** - Remove emoji CSS, JS, and DNS prefetch

= Media Optimization =

* **Lazy Load** - Images, iframes, and videos with native loading="lazy"
* **Disable WordPress Native Lazy Load** - Full control over lazy loading
* **YouTube Thumbnail Replacement** - Replace iframe embeds with lightweight thumbnails
* **Add Missing Image Dimensions** - Prevent CLS by adding width/height attributes
* **WebP Conversion** - Auto-convert JPG/PNG to WebP on upload
* **AVIF Conversion** - Auto-convert to AVIF for even better compression
* **Lossy / Lossless / Custom Quality** - Per-format quality control
* **EXIF Data Removal** - Strip metadata for privacy and smaller files
* **Image Resize on Upload** - Auto-resize oversized images
* **Bulk Optimization** - Batch convert existing media library images
* **Multiple Delivery Methods** - .htaccess rewrite, picture tag, or URL rewrite
* **Per-folder Include/Exclude** - Target uploads, themes, plugins, or custom folders
* **Media Library Column** - Compression percentage displayed per image

= CDN Integration =

* **CDN URL Rewriting** - Serve static assets from any pull-zone CDN
* **Domain Sharding** - Multiple CDN hostnames for parallel downloads
* **Cloudflare** - Automatic zone purge and per-URL purge via API
* **Sucuri** - Firewall cache sync via API
* **Varnish** - HTTP PURGE requests with regex support

= Preloading =

* **Cache Preloading** - Background crawling to warm page cache (desktop + mobile). When Vary Cookies are active, only the default variant is preloaded; cookie-specific variants are generated on first visitor request.
* **Sitemap Preloading** - Discover URLs from XML sitemap
* **Link Prefetching** - JavaScript-based hover/viewport prefetch
* **Speculation Rules API** - Chrome prerendering for instant navigation
* **Font Preloading** - Auto-detect and preload @font-face fonts
* **LCP Optimization** - fetchpriority="high" and preload for hero images
* **DNS Prefetch** - Early DNS resolution for external domains
* **Preconnect** - DNS + TCP + TLS handshake in advance
* **Manual Resource Preloading** - Preload specific URLs with auto-detected type

= Database Optimization =

* **Revisions** - Delete post revision history
* **Auto Drafts** - Clean up auto-saved drafts
* **Trashed Posts** - Permanently delete trashed content
* **Spam Comments** - Remove spam comments
* **Trashed Comments** - Remove trashed comments
* **Expired Transients** - Clean up expired temporary options
* **All Transients** - Remove all temporary options
* **Table Optimization** - Run OPTIMIZE TABLE on fragmented tables
* **Automatic Cleanup** - Schedule daily, weekly, or monthly cleanup via WP-Cron

= Performance Tweaks =

* **Disable jQuery Migrate** - Remove legacy compatibility script (~10 KB)
* **Disable WP Embed** - Remove wp-embed.min.js (~6 KB)
* **Disable Dashicons** - Remove icon font for non-logged-in users (~46 KB)
* **Remove WordPress Version** - Hide version meta tag
* **Disable XML-RPC** - Block legacy API and X-Pingback header
* **Disable Self-Pingbacks** - Prevent internal pingback requests
* **Limit Post Revisions** - Set maximum revision count
* **Disable RSS Feeds** - Redirect feeds to homepage
* **Disable oEmbed** - Remove discovery links and REST route
* **Disable Gutenberg Block CSS** - Remove block stylesheets
* **Disable Google Fonts** - Dequeue external font requests
* **Disable Global Styles SVG** - Remove WP 6.1+ inline markup
* **Remove Shortlink** - Clean up wp_head output
* **Remove RSD & WLW Manifest** - Remove legacy discovery links
* **Remove REST API Link** - Remove API discovery from frontend
* **Disable WordPress Sitemap** - Turn off built-in XML sitemap
* **Add Blank Favicon** - Prevent 404 for missing favicon.ico
* **WooCommerce Script Optimization** - Disable WC assets on non-WC pages
* **WooCommerce Cart Fragments** - Disable AJAX cart fragment loading

= Heartbeat API Control =

* Per-location control: Frontend, Admin Dashboard, Post Editor
* Three modes: Allow, Reduce Frequency, Disable
* Custom interval setting (15-300 seconds)

= Google Fonts =

* **Combine** - Merge multiple API requests into one
* **Self-host** - Download and serve fonts locally
* **Display Swap** - Add display=swap for visible text during loading
* **Disable** - Remove all external Google Fonts

= Security Headers =

* **HSTS** - HTTP Strict Transport Security with configurable max-age
* **X-Content-Type-Options** - Prevent MIME-type sniffing
* **X-Frame-Options** - Clickjacking protection
* **X-XSS-Protection** - Cross-site scripting protection
* **Referrer-Policy** - Control referrer information
* **Permissions-Policy** - Restrict browser features

= Tools =

* **Import / Export** - JSON-based settings backup and transfer
* **Reset to Defaults** - One-click factory reset
* **System Information** - Comprehensive debug info (PHP, GD, Imagick, extensions, etc.)
* **Debug Logging** - Log cache operations to file
* **Compatibility Check** - Detect conflicting caching plugins
* **WP-CLI Support** - Command-line cache management

= Admin Features =

* **Dashboard Tab** - KPI overview, feature status, storage usage, environment info
* **Admin Bar Menu** - Quick access to 10+ cache actions
* **Dashboard Widget** - Hit rate and cache status at a glance
* **Per-post Cache Control** - Disable cache for specific posts/pages via metabox
* **Media Library Column** - Compression stats for each image

= Auto Purge Triggers =

* Post publish, update, trash, delete
* Comment post, approve, edit, trash, delete
* Term create, edit, delete
* Theme switch
* Permalink structure change
* Plugin activate / deactivate
* Customizer save
* Widget update
* Navigation menu update
* WordPress core update
* User profile update

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
2. Page Cache - General settings, browser cache, Varnish/Sucuri integration
3. File Optimization - HTML/CSS/JS minification, performance tweaks
4. Media - Lazy load, WebP/AVIF conversion, bulk optimization
5. CDN - URL rewriting and Cloudflare integration
6. Preload - Cache warming, link prefetch, Speculation Rules, LCP optimization
7. Database - Cleanup options with item counts and auto-scheduling
8. Tools - Import/export, security headers, system information

== Frequently Asked Questions ==

= What are the server requirements? =

WordPress 5.8+, PHP 7.4+. For WebP/AVIF conversion, GD or Imagick PHP extension is required. For object caching, APCu, Redis, or Memcached extension is needed.

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

Page caching is not supported on multisite installations. Other features (file optimization, lazy load, CDN, image optimization, etc.) work normally on multisite.

= What is the Speculation Rules API? =

A new browser API (Chrome 109+) that prerenders pages when users are likely to navigate to them. It makes navigation virtually instant. Non-supporting browsers simply ignore the rules.

= How does the image optimization work? =

On upload (if auto-convert is enabled), JPG/PNG images are converted to WebP and/or AVIF format. All thumbnail sizes are also converted. The best format is served based on browser support via .htaccess rewrite rules, picture tags, or URL rewriting.

= Is it safe to enable all optimizations? =

Start with page caching and basic file optimization. Test your site after each change. CSS/JS combining and delay may cause issues with some themes/plugins — test thoroughly.

== Changelog ==

= 1.0.0 =
* Initial release
* Page Cache with advanced-cache.php dropin
* Object Cache (APCu, Redis, Memcached)
* File Optimization (HTML/CSS/JS minify, combine, defer, delay)
* Critical CSS (auto-generate and manual)
* Remove Unused CSS
* Optimize CSS Delivery (Remove Unused CSS or Async CSS)
* Inline Small CSS
* Media optimization (Lazy Load, WebP, AVIF, EXIF strip, resize)
* Bulk image optimization with AJAX progress
* Media Library compression column
* YouTube iframe thumbnail replacement
* Add missing image dimensions (CLS prevention)
* CDN URL rewriting with domain sharding
* Cloudflare cache sync (zone + URL purge)
* Sucuri firewall cache sync
* Varnish HTTP PURGE integration
* Cache preloading (DB-based + sitemap)
* Link prefetching (hover + viewport)
* Speculation Rules API (Chrome prerendering)
* Font preloading (auto-detect)
* LCP optimization (fetchpriority + preload)
* DNS prefetch and preconnect
* Manual resource preloading
* Database optimization (8 items + auto-schedule)
* Browser Cache headers with per-type lifetimes
* Gzip and Brotli compression
* .htaccess optimization with MIME types
* HSTS and security headers
* Heartbeat API control (3 locations x 3 modes)
* Performance tweaks (16 WordPress bloat removals)
* WooCommerce script optimization + cart fragments
* Google Fonts (combine, self-host, display swap, disable)
* Local Google Analytics hosting
* Delay JS safe mode and 3rd-party presets
* 404 page caching
* Auto purge (12 WordPress event triggers)
* Cache exclusion rules (URL, cookie, UA, referrer)
* Cache control (query params, vary cookies, always purge URLs)
* Per-post cache disable metabox
* Admin bar with 10+ quick actions
* Dashboard tab with KPI overview
* Dashboard widget
* WP-CLI support (flush, preload, status, db-cleanup)
* Import/Export settings (JSON)
* Reset to defaults
* System information page
* Debug logging
* Compatibility check (14 plugins)
* Disable WordPress emoji
* Remove query strings
* .htaccess WebP/AVIF image rewrite
* Picture tag delivery for WebP/AVIF
* English + Japanese translation (WordPress style guide compliant)

== Upgrade Notice ==

= 1.0.0 =
Initial release.
