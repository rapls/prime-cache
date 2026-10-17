=== Prime Cache – Page Cache on Activation & One-Click Speed Optimization ===

Contributors: rapls
Tags: cache, page cache, performance, optimization, core web vitals
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.10.62
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Page caching that works the moment you activate it, plus one-click speed tuning. Runs on shared hosting without editing wp-config.php.

== Description ==

Activate Prime Cache and your pages are cached. There is no setup screen to get through first and no wp-config.php to edit.

When you want more, one button, Auto, applies a set of speed settings in one go: HTML, CSS and JavaScript minification, deferred JavaScript, non-blocking CSS, lazy loading, browser caching, link prefetching and cache preloading. Every setting stays on its own screen afterwards, so you can review or change any of them.

**Watch the 5-minute setup tutorial:**

[youtube https://www.youtube.com/watch?v=ZUxESCRtaEU]

= How it works without any setup =

Page caching is on from the moment you activate the plugin. Prime Cache runs its page-cache engine early in each request and sends the stored copy of a page instead of building it again, so the theme and the database queries behind the page are skipped. Logged-in users, the admin screens, form submissions and WooCommerce cart, checkout and account pages are never served from the cache.

Two faster serving paths are optional:

* **.htaccess Optimization** (servers that read .htaccess): cached pages are sent by the web server without starting PHP. Turn it on in the Page Cache tab, or let Auto turn it on when .htaccess is writable.
* **Drop-in mode**: add `define( 'WP_CACHE', true );` to wp-config.php yourself and cached pages are served before WordPress core loads. The settings screen shows the exact line.

The free plugin never edits wp-config.php. That file controls the whole site and some hosts manage it themselves, so an automatic edit is a risk the plugin does not take. The drop-in step stays in your hands, and page caching works without it.

Prime Cache is developed and run on shared hosting.

= If something looks wrong after Auto =

Auto's JavaScript and CSS settings do the most for speed, and they are also the ones most likely to disagree with a theme or another plugin. Menus, sliders and forms are where problems usually show.

The quickest fix is the Safe preset in the Tools tab. It keeps page caching, browser caching and lazy loading, and turns off minification, deferred and delayed JavaScript, the CSS delivery settings and the other Auto settings that change how a page looks, including any you turned on yourself. The preset card in the Tools tab lists every setting it turns off. Clear the cache and check the page again.

To keep the rest of Auto and find the one setting that disagrees, apply Auto again and turn settings off in this order instead, clearing the cache after each step:

1. **Delay JavaScript Execution** and **Maximum Delay**, if you turned them on yourself. Auto does not turn them on in the free plugin.
2. **Load JavaScript Deferred**
3. **Load CSS Asynchronously (Free)**, then **Inline Small CSS Files**
4. **Minify HTML**, **Minify CSS** and **Minify JavaScript**

= Free features =

* Page cache, on from activation
* Auto preset (plus Safe, Balanced and Aggressive presets)
* Browser cache headers
* .htaccess Optimization
* Gzip compression
* 404 page caching
* HTML / CSS / JavaScript minification
* Inline small CSS
* Defer JavaScript
* Delay JavaScript, including Maximum Delay
* Google Fonts display=swap
* Lazy load for images, iframes and videos
* WebP conversion, with bulk conversion
* Image resize on upload and EXIF removal
* Cache preloading for the homepage, public posts and public taxonomies
* Link prefetching
* Performance tweaks (disable emoji, jQuery Migrate, embeds and other WordPress extras)
* Automatic cache purge on content changes
* Security headers
* Import / Export
* WP-CLI support

= Prime Cache Pro (optional add-on) =

Prime Cache Pro is a separate add-on sold on the author's website (https://raplsworks.com/plugins/prime-cache/). Everything described above is free, with no limits, and the free plugin does not need Pro to work.

With Pro installed, Auto also turns on:

* Remove Unused CSS, with automatically generated Critical CSS
* Delay JavaScript Execution with Maximum Delay
* AVIF image conversion (WebP when the server cannot make AVIF)
* Self-hosted Google Fonts and font preloading
* LCP optimization
* Speculation Rules
* Click-to-load YouTube thumbnails
* Preloading of taxonomy archives

Pro also adds features you turn on yourself:

* AI speed diagnosis and consultation (bring your own API key: Anthropic / OpenAI / OpenRouter / Gemini)
* CSS and JavaScript combining
* CDN URL rewriting
* Cloudflare, Sucuri and Varnish integration
* Object cache backends (APCu / Redis / Memcached)
* Sitemap-based cache preloading
* Database cleanup
* Heartbeat control
* Self-hosted Google Analytics
* One-click `WP_CACHE` setup in wp-config.php, with a backup written first
* A warning when a server-side cache sits in front of the site

**Prime Cache Pro walkthrough (Japanese narration):**

[youtube https://www.youtube.com/watch?v=n0oQ3w9nuuU]

= Japanese =

The admin screens are translated into Japanese.

= Documentation =

Full documentation for every setting and every behavior:

* Free Manual (English): https://raplsworks.com/prime-cache-free-manual-en/
* Free 版マニュアル (日本語): https://raplsworks.com/prime-cache-free-manual-ja/
* Pro Manual (English): https://raplsworks.com/prime-cache-manual-pro-en/
* Pro 版マニュアル (日本語): https://raplsworks.com/prime-cache-manual-pro-ja/

== External services ==

The free Prime Cache plugin does not connect to any external service. No data is sent to any third party at any time by the free plugin.

The following third-party hostnames appear inside the plugin's source code as **string literals only**, and the plugin never makes outbound requests to them:

* `googletagmanager.com`, `google-analytics.com`, `connect.facebook.net`, `widget.intercom.io`, `embed.tawk.to` — listed in `includes/class-file-optimizer.php` as URL-pattern presets for the "Delay JS" feature. They are used **only** to recognize third-party scripts already present on the page (added by other plugins or the theme) and to defer their execution until first user interaction. The plugin itself does not load, fetch, or embed any of these services.
* `cdnjs.cloudflare.com` — referenced **only** in code comments and admin-screen help text describing how some themes replace bundled jQuery with a CDN version. The plugin does not call or include any resource from this host.

If you install the optional Prime Cache Pro add-on, that separate plugin documents its own external service usage in its own readme — Prime Cache (free) on its own makes no outbound calls.

== Why the page-cache drop-in keeps an open output buffer ==

A page cache has to capture the entire rendered HTML response so the body can be written to disk before the browser receives it. `dropins/page-cache.php` opens an `ob_start()` early in the request and lets PHP flush the buffer naturally at request shutdown — the captured body is written to the cache file inside the buffer callback. The buffer is deliberately not closed mid-request; doing so would either truncate the cached body or break the capture for plugins/themes that emit their last output during shutdown. This is the standard design for WordPress page caching. The HTML transformation pipeline in `includes/class-html-pipeline.php` follows the same pattern for the same reason.

== Installation ==

1. Go to Plugins > Add New in your WordPress admin and search for "Prime Cache", then click Install Now (or upload the `prime-cache` folder to `/wp-content/plugins/`)
2. Activate the plugin through the 'Plugins' menu in WordPress. Page caching starts immediately; no wp-config.php changes are needed.
3. Optional: go to Prime Cache > Tools and click Apply Auto Preset to turn on the speed settings described above.
4. Optional: follow the on-screen note to add the `WP_CACHE` line yourself for drop-in mode.

= Settings tabs =

1. **Page Cache tab** - Mobile cache, gzip compression, .htaccess Optimization and 404 caching
2. **File Optimization tab** - HTML, CSS and JS minification, defer and delay
3. **Media tab** - Lazy loading and WebP conversion
4. **Preload tab** - Cache preloading and link prefetching

== Screenshots ==

1. Dashboard - Cache hit rate, quick actions, and system / feature status
2. Page Cache - General settings: mobile cache, gzip compression, .htaccess optimization, and 404 caching
3. File Optimization - HTML/CSS/JS minification and optimization settings
4. Media - Lazy load settings for images, iframes, and videos
5. Preload - Cache preloading settings
6. Cache Control - Ignored and cached query parameter controls
7. Auto Purge - Automatic purge triggers
8. Tools - Optimization presets, import/export, and system information

== Frequently Asked Questions ==

= Something looks broken after I pressed Auto. =

Apply the Safe preset in the Tools tab first. It keeps page caching, browser caching and lazy loading, and turns off minification, deferred and delayed JavaScript, the CSS delivery settings and the other Auto settings that change how a page looks, including any you turned on yourself. The preset card in the Tools tab lists every setting it turns off. Then clear the cache (Admin bar > Prime Cache > Clear All Cache) and check menus, sliders and forms again.

If Safe fixes it and you want to keep the rest of Auto, apply Auto again and turn settings off in this order, clearing the cache after each step:

1. **Delay JavaScript Execution** and **Maximum Delay**, if you turned them on yourself. Auto does not turn them on in the free plugin.
2. **Load JavaScript Deferred**
3. **Load CSS Asynchronously (Free)**, then **Inline Small CSS Files**
4. **Minify HTML**, **Minify CSS** and **Minify JavaScript**

= Does it work with the Cocoon theme? =

Yes. In our tests with Cocoon 2.9.5 (October 2026), Prime Cache's Auto preset worked together with all of Cocoon's speed settings turned on (minification, lazy loading and browser cache), and no layout or script problems were found. Both write browser-cache rules to .htaccess; where they overlap, Cocoon's rules come later and take effect, which is fine. If something does look wrong on your site, turn off Cocoon's HTML/CSS/JS minification first, then press Safe in Prime Cache.

= What does Auto change, exactly? =

In the free plugin, Auto turns on: page cache with mobile caching, gzip compression, browser cache headers, .htaccess Optimization (only when .htaccess is writable), HTML / CSS / JavaScript minification, removal of HTML comments, lazy loading for images, iframes and videos (the first six images stay eager so the largest image is not delayed), missing image width/height, a local copy of jQuery when the theme loads it from a CDN, deferred JavaScript, inline small CSS, asynchronous loading of non-first CSS, Google Fonts display=swap, disabling emoji and embeds, removing version query strings, limiting DNS prefetch, disabling block CSS on classic themes, link prefetching and cache preloading. On WooCommerce sites it also turns off WooCommerce scripts on non-shop pages and cart fragments, and adds the cart, checkout and account pages to the exclusions.

Auto does not turn on Delay JavaScript Execution, WebP conversion or Brotli compression in the free plugin. Prime Cache Pro adds its own items to Auto (see the Description).

= Does it edit wp-config.php? =

The free plugin does not. It reads wp-config.php to see whether `WP_CACHE` is set, and never writes to it. Page caching works without `WP_CACHE`. If you add `define( 'WP_CACHE', true );` yourself, cached pages are served before WordPress core loads, which is faster still. Prime Cache Pro can add the line for you with one click and writes a backup first.

= Can I use it with another cache plugin? =

Do not run two page caches at once. Deactivate the other cache plugin before you activate Prime Cache. Prime Cache detects 14 known caching and optimization plugins and warns you when one is active.

= My host already has a server cache. =

Do not let two page caches serve the same pages. When the host's cache sits in front of Prime Cache, Prime Cache's purge does not reach it, so visitors can keep seeing an old copy after you update a post. Decide which one serves your pages. If you keep the host's cache, clear it as well whenever you clear Prime Cache. Prime Cache Pro detects common server-side caches and tells you when one is in front of the site.

= Logged-in users see old pages. =

They should not by default. Logged-in users are never served cached pages, and page optimizations are skipped for them. If you turned on **Logged-in User Cache** in the Page Cache tab, every logged-in user shares one cached copy with anonymous visitors; turn it off.

= How do I clear the cache? =

Multiple ways: the admin bar menu (Prime Cache > Clear All Cache and other options), Dashboard quick actions, WP-CLI (`wp prime-cache flush`), or the automatic purge triggers.

= Is it available in Japanese? =

Yes. The admin screens are translated into Japanese.

= Does it help Core Web Vitals? =

The features target the usual Core Web Vitals bottlenecks: page caching and preloading shorten server response time (TTFB, which feeds LCP), minification and deferred JavaScript reduce render-blocking resources, lazy loading and WebP conversion cut image weight, and keeping the first images eager protects the LCP image. Results depend on your theme, hosting and other plugins, so measure before and after each change.

= What are the server requirements? =

WordPress 5.8+, PHP 7.4+. For WebP conversion, the GD or Imagick PHP extension is required.

= Does it work with Nginx? =

Yes. Page caching, file optimization, media optimization, and all PHP-based features work on any server. The .htaccess Optimization feature needs a server that reads .htaccess and can be left off on Nginx.

= Does it support WooCommerce? =

Yes. WooCommerce cart, checkout, and account pages are automatically excluded from caching. Additional WooCommerce optimizations include disabling scripts on non-WC pages and disabling cart fragments AJAX.

= Can I disable cache for a specific page? =

Yes. Edit the post/page and check "Disable cache for this page" in the Prime Cache metabox in the sidebar.

= Does the .htaccess fast path work for all requests? =

The .htaccess fast path serves cached pages without loading PHP. It requires: lowercase Host header, no query string (unless cache_query_strings is disabled), no Vary Cookies, ASCII-safe URL paths, and GET requests only. Requests that don't match these conditions are served by the PHP engine, which is still fast but not zero-PHP.

= Does it support WordPress multisite? =

Page caching is not supported on multisite installations. Other features (file optimization, lazy load, image optimization, etc.) work normally on multisite.

= How does the image optimization work? =

When WebP conversion and auto-convert are on, JPG/PNG images are converted to WebP on upload, including every thumbnail size. WebP is served to supporting browsers via .htaccess rewrite rules, picture tags, or URL rewriting.

= Does Prime Cache send data to external services? =

No. The free plugin does not send your data or API requests to any third-party service. Cache preloading only requests URLs on your own site. Some optional features may add browser resource hints (such as preconnect) for external assets your site already uses, but Prime Cache itself does not transmit data to external services.

== Changelog ==

= 1.10.62 =
* Fixed: with Delay JavaScript on, the Cocoon theme's mobile sidebar drawer opened empty on the first tap and worked from the second tap on. The tap that opens the drawer is also the one that starts loading the delayed scripts, so Cocoon's script that moves the sidebar into the drawer had not run yet. A small script that is never delayed now moves the sidebar when Cocoon's script has not run yet, and does nothing once Cocoon's script is running. Only sites using Cocoon are affected.

= 1.10.61 =
* Display name and readme updated.
* Changed: the Safe preset now also turns off Load CSS Asynchronously, Inline Small CSS Files and the other page-changing settings Auto turns on (Use Local jQuery, Disable Gutenberg Block CSS, Disable WP Embed, Disable oEmbed, Add Missing Image Dimensions, the HTML DOM and comment options, and the WooCommerce script and cart-fragment options), so it undoes every setting in the free Auto preset that can change how a page looks. The Safe card in the Tools tab now lists everything it turns off. Add-ons can extend Safe through the new `prime_cache_preset_safe` filter.

= 1.10.60 =
* Fixed: with "Purge on WordPress core update" turned off, no cache was cleared after a plugin or theme update either — even with "Purge on plugin activate/deactivate" and "Purge on theme switch" enabled. All three update types arrive through one WordPress hook, and that hook was only registered when the core-update setting was on. It is now registered whenever any of the three is enabled, and each update type follows its own setting. Sites with all three enabled (the default) are unaffected.

= 1.10.59 =
* Fixed: the CSS minifier corrupted `:not()` selectors. A rule that added the required space to media-query keywords (`and(` to `and (`) also matched the `:not(` pseudo-class, turning `.a:not(.b)` into the invalid `.a:not (.b)` — so browsers dropped every style rule that used `:not()`. On themes that rely on `:not()` for layout (e.g. Avada's search form) this left elements mispositioned or unstyled once CSS optimization was on. The minifier now leaves `:not()` untouched while still spacing media-query keywords.
* Changed: minified CSS/JS (and, with the Pro add-on, combined and unused-CSS files) now include the plugin version in their cache filename. A code fix like the one above previously reused the old, broken output because the source file's timestamp had not changed; now an update regenerates the assets automatically, and the new filenames also stop upstream/server caches from serving stale bytes. Existing optimized files are replaced on the next visit after updating.

= 1.10.58 =
* Fixed: a deleted image could keep being shown. The WebP rewrite rule served a `.webp` whenever one existed on disk, without checking that the original was still there — so an orphaned `.webp` left behind by a deleted original kept being served at 200 instead of returning a clean 404. The rule now also requires the original file to exist. (The Pro add-on's AVIF rule gets the same fix.)
* Fixed: deleting an image in the Media Library now also deletes its generated `.webp`/`.avif` variants (the original and every size). Before, WordPress removed only the originals it tracks and the converted files were orphaned — and because conversion skips a file that already has a variant, re-uploading an image under the same name could keep showing the OLD picture.
* Added: an orphaned-file cleanup for variants left behind by deletes made before this fix. The Media settings show a count and a one-click "Delete Orphaned Files" (with confirmation); `wp prime-cache image-orphans` lists them (add `--delete` to remove).

= 1.10.57 =
* Fixed: updating a plugin or theme did not clear the cache, so a cached page — and any combined/minified CSS or JS whose content hash came from the updated files — could keep serving the old version until the cache was cleared by hand. Prime Cache only purged on WordPress core updates, and a normal in-place plugin update leaves the plugin active, so no other event caught it. It now purges the whole cache after a plugin or theme update as well (core updates already did this; translation-only updates are still ignored).

= 1.10.56 =
* Fixed: an optimizer asset could be written as a 0-byte file and then served as an empty but "successful" (200) stylesheet or script, leaving the page silently unstyled — harder to spot than a 404. Prime Cache now refuses to write an empty optimized asset and falls back to the original, un-optimized file instead. This is a likely cause of the blank Cocoon mobile sidebar on some sites.
* Added: the hourly cleanup now also ages out generated optimizer assets (combined/minified CSS/JS and Remove Unused CSS) and removes any stray 0-byte files. Previously these lived in a sibling directory the cleanup never reached, so they could accumulate without bound; critical CSS and self-hosted fonts are left untouched. A still-referenced asset that is removed is rebuilt on demand by the missing-asset fallback added in 1.10.55.

= 1.10.55 =
* Fixed: "Clear Minified CSS/JS" and "Clear Critical CSS" now also clear the page cache. The cached page HTML embeds the hashed filenames of those generated assets; deleting the files while keeping the HTML left every cached page pointing at assets that no longer existed. Served as a cache hit, they returned the theme's 404 HTML page, which the browser rejects as a stylesheet, so the page rendered unstyled until it was regenerated. Both buttons now purge the page cache so the HTML is rebuilt with valid asset URLs.
* Added: a missing-asset fallback. If an optimizer asset (minified/combined CSS/JS or Remove Unused CSS) is ever requested but its file is gone — a partial cache clear, a manual delete, a disk hiccup — Prime Cache now rebuilds it on the fly from the original source (recorded in a small manifest) and serves it with the correct `text/css` / `application/javascript` type and a 200, self-healing it back to disk. When it cannot be rebuilt it serves an empty file of the right type instead of a 404 HTML page, so a single missing asset can no longer break a page's styling.

= 1.10.54 =
* Fixed: with Defer or Delay JavaScript enabled, WordPress i18n-based scripts could fail with "wp is not defined" or "Cannot read properties of undefined (reading 'hooks')", breaking translations, Contact Form 7, the admin bar, and other wp.* features. A script kept synchronous for its inline translation block (e.g. wp-i18n, which carries a `-js-after`) was allowed to run before its deferred/delayed dependency (wp-hooks), so it read `window.wp.hooks` before wp-hooks had defined it — taking down the whole `wp` namespace. Prime Cache now keeps the dependencies of such kept-synchronous scripts synchronous as well, preserving dependency order. The set is scoped to scripts actually printed on the page, so unrelated optimizations — including mobile jQuery defer — are unaffected.

= 1.10.53 =
* Fixed: on the Cocoon theme, the mobile slide-in sidebar could still open blank even after the jQuery fix in 1.10.52. When the sidebar button is tapped, Cocoon moves the sidebar into the drawer and renames its id, but its own script then re-selects the element by the *old* id when adding the visibility class — so the class is never applied and the relocated sidebar falls back to the theme's mobile display:none. Prime Cache now outputs a small Cocoon-only CSS rule that keeps the relocated sidebar visible inside the drawer (scoped to the drawer, so the in-page sidebar is untouched). Together with 1.10.52 this fully resolves the blank Cocoon mobile sidebar.

= 1.10.52 =
* Fixed: with Delay JavaScript enabled, jQuery — and with it every jQuery-dependent script on the page — could become undefined on any site where the theme or a plugin calls `jQuery.noConflict()` (the common call that frees the `$` alias). The Delay JS loader backed both `window.jQuery` and `window.$` with a single internal variable, so `noConflict()` resetting `window.$` to its pre-jQuery value also wiped `window.jQuery`. The whole page then failed with "jQuery is not a function" / "$ is not a function", breaking anything that relies on jQuery. `window.$` now keeps its own backing value, independent of `window.jQuery`. This is the actual cause behind the Cocoon mobile slide-in sidebar opening blank (reported for 1.10.51), and the fix restores every other jQuery-dependent feature broken the same way.
* Reverted: the Cocoon-specific change shipped in 1.10.51 targeted the wrong cause and has been removed; the jQuery loader fix above is the correct fix.

= 1.10.51 =
* Attempted a Cocoon-specific fix for the mobile slide-in sidebar opening blank under Delay JavaScript, by forcing Cocoon's main script to run at load. This addressed the wrong cause and did not resolve the issue; it is reverted in 1.10.52, which fixes the real cause (see above).

= 1.10.50 =
* Fixed: the minified CSS/JS cache is now keyed by the source file rather than its request URL. Some themes — Cocoon most notably — add a version query string to every stylesheet that changes on each page render; because the cache filename was derived from the full URL, byte-identical CSS was written to a fresh file on every hit and the `wp-content/cache/prime-cache-fo` folder grew without bound. One site had reached over 250,000 files and several gigabytes of duplicate CSS. The cache key now uses the resolved file path plus its modification time and size, so identical output collapses to a single file. After updating, clear the Minify cache once to remove the duplicates that have already accumulated.

= 1.10.49 =
* Display name shortened to "Prime Cache – Page Cache & Core Web Vitals (Zero Config)". The previous title listed six features and ran to 81 characters, past the 80 the directory allows and well into what the guidelines call readme spam. The features it named have moved into the description, where they are still indexed. No functional change.

= 1.10.48 =
* Fixed: "Inline Small CSS Files" now preserves each stylesheet's media condition. Inlining a small media-scoped stylesheet — for example a theme's mobile-only CSS loaded via `<link media="(max-width:800px)">` — previously dropped the media query, so those rules applied at every screen size and could break the desktop layout. The inlined CSS is now wrapped in its matching @media block.

= 1.10.47 =
* Fixed: the Object Cache status could show a backend as "Active" when it was installed but not actually running — for example when the PHP extension is present but disabled at runtime, so WordPress had quietly fallen back to its internal cache. The status now confirms the object cache really initialized before reporting it active, and shows "Installed but not running" otherwise. (Pairs with Prime Cache Pro 1.9.6.)

= 1.10.46 =
* Changed: the "How to turn on drop-in mode" step-by-step guide in the setup notice is now collapsed by default so the notice stays compact — click the summary to expand it. (Refines the 1.10.44 change, which showed it expanded.)
* Changed: removed the "Protocol" line from the Auto preset's environment summary. The server-visible HTTP protocol is unreliable behind a reverse proxy or CDN (PHP can report HTTP/1.1 even when visitors are on HTTP/2), so displaying it was misleading; no setting depends on it.

= 1.10.45 =
* Improved: overhauled the optimization presets (Aggressive and Auto) for higher Core Web Vitals scores, based on real-world tuning. They now keep the first several images eager to protect LCP, add missing image width/height to reduce layout shift (CLS), enable DOM-based HTML optimization, and use a longer Delay JS fallback. With the Pro add-on, presets now favor Remove Unused CSS + Critical CSS instead of file combining (which provides no benefit on HTTP/2) and replace YouTube embeds with click-to-load thumbnails. Re-apply a preset from the Tools tab to pick up the new tuning.

= 1.10.44 =
* Improved: the "Maximum Delay" warning now has a "Got it" button. Instead of reappearing every time you save while Maximum Delay is on, the warning stays on the plugin screen until you acknowledge it once, then hides for good. It shows again if you turn Maximum Delay off and back on.
* Improved: the optional "enable drop-in mode" (WP_CACHE) instructions are now beginner-friendly. The step-by-step guide is shown expanded with a copy-ready code line and a clear before/after example of wp-config.php, so anyone can make the one-line change with confidence.

= 1.10.43 =
* Added: a friendly one-time review request. After you've used Prime Cache for a week, a dismissible banner appears once on the plugin's own screens inviting you to leave a WordPress.org review. It never nags across the rest of the admin, and once you review or dismiss it, it never shows again.

= 1.10.42 =
* Added: an extension point so the optional add-on can replace the "enable drop-in mode" (WP_CACHE) notice with a one-click action. The free plugin still never edits wp-config.php itself — it only shows the manual instructions, and now steps aside cleanly when the add-on offers to do it for you. No change for sites without the add-on.

= 1.10.41 =
* Fixed: with Delay JavaScript enabled, saving settings on any tab re-enabled Mobile Cache and Separate Mobile Cache even after you turned them off. Delay JS no longer forces mobile caching on — it applies to dynamically generated mobile pages too — and only requires Separate Mobile Cache while Mobile Cache itself is on (to keep the mobile-transformed HTML out of the desktop cache). The same over-eager coupling in preset application is fixed.

= 1.10.40 =
* Added: 5-minute video tutorial embedded on the plugin page and linked from the documentation.
* Improved: the Pro Features page now introduces AI Speed Diagnosis & consultation (BYOK) — measure your environment, get plain-language findings, apply validated fixes in one click, and ask follow-up questions in chat.

= 1.10.39 =
* Improved: dashboard statistics now count cache files written by the preload crawler separately as "Preload" instead of inflating MISS, and preload fetches of already-warm pages are not counted at all — the hit rate now reflects real visitor traffic only. Existing counters are unaffected; use Reset to start fresh.

= 1.10.38 =
* Added: Maximum Delay mode — extends Delay JS to jQuery core and inline scripts. Scripts still execute in document order after user interaction, with DOMContentLoaded/load re-dispatched, so dependency chains keep working. During a PageSpeed lab run almost no JavaScript executes, dropping TBT to near zero. Consent scripts, JSON-LD and your exclusion list are never delayed.
* Added: "Apply Delay JS on Desktop" option — Delay JS was mobile-only; this opt-in extends it to desktop responses to improve the desktop PageSpeed score as well.
* Added: nginx direct-serving snippet — a copy-paste server-block configuration (shown in Cache settings) that lets nginx serve cached pages straight from disk without booting PHP, mirroring the .htaccess fast path condition for condition. Includes gzip_static support for the pre-compressed variants.
* Improved: the Inline Small CSS threshold now also applies to the Pro add-on's combined stylesheet — when the combined result fits, it is inlined for zero render-blocking CSS requests without async-CSS restyle jank.

= 1.10.37 =
* Fixed: logged-in users are no longer run through the HTML optimization pipeline (minify, defer/delay JS, and the add-on's CSS optimizations) unless logged-in caching is enabled. Their pages bypass the page cache, so optimizing them was per-request overhead — and user-only elements such as the WP admin bar or Cocoon's front-side admin menu could render unstyled or invisible, because per-URL cached artifacts like the Remove Unused CSS output were computed from an anonymous view that does not contain them.

= 1.10.36 =
* Changed: the lazy-load module's fetchpriority="high" stamp on the first image can now be disabled via the prime_cache_lazyload_first_image_priority filter. The Pro add-on's LCP Optimization uses it so only the image it identifies as the LCP candidate carries the high priority — two "high" images dilute the boost.

= 1.10.35 =
* Fixed (Cocoon theme): mobile drawer menus (hamburger / search / share / sidebar) stayed open when returning to a page via the browser's back/forward cache. Cocoon's checkbox-driven menus ship no pageshow handling, and this plugin's Delay JS / async CSS remove the in-flight requests that previously made pages bfcache-ineligible, surfacing the issue. A compatibility shim now closes the drawers on bfcache restore.

= 1.10.34 =
* Fixed: Lazy Load's "skip first images" setting now removes a loading="lazy" attribute that WordPress core or the theme already added to one of the skipped images. Previously the skipped images only avoided getting a new lazy attribute, so an above-the-fold (LCP) image could stay lazy-loaded and delay rendering by seconds.
* Improved: saving settings now purges the page cache automatically. Cached pages are rendered with the settings active at the time, so with the default 7-day lifespan a change to Lazy Load, minification, Defer/Delay JavaScript, image delivery, or any other HTML-affecting option could keep serving stale HTML long after the change. Settings now take effect on the next page view.

= 1.10.33 =
* Fixed: Google Site Kit is now always excluded from Combine, Delay, and Defer JavaScript. Site Kit ships as a multi-chunk webpack app whose runtime, vendor, and module bundles share one registry and must load in their original order; optimizing any single chunk desynchronized the registry and threw "googlesitekit is not defined" (Site Kit dashboards/snippets failed to load). Handled like jQuery and Divi — no manual exclusion needed.

= 1.10.32 =
* Fixed: the Defer/Delay JavaScript exclusion list matched case-sensitively, so a natural entry like "jQuery" did not match the actual script URL "jquery.min.js" and jQuery kept being deferred — breaking inline scripts with "jQuery is not a function". Exclusions now match case-insensitively.
* Fixed: jQuery and jquery-migrate are now always excluded from Defer JavaScript (they were already excluded from Delay), so deferring can no longer break themes/plugins that print inline jQuery calls.

= 1.10.31 =
* Fixed: "Reset settings to defaults" now also clears all caches. Previously the reset only restored the settings, so pages already cached under the old optimization settings (Remove Unused CSS, Delay JS, etc.) kept being served — sometimes with broken markup — even though the settings that produced them were gone.

= 1.10.30 =
* Fixed: after turning gzip compression off, a leftover .gz cache variant could keep serving older content than the regenerated HTML until cleanup. The drop-in now serves the .gz variant only when gzip is enabled in the current settings and the .gz file is at least as new as the HTML it mirrors.
* Fixed: cache statistics and the hourly cleanup no longer risk a fatal error when a cache subdirectory becomes unreadable mid-scan; the scans are exception-guarded (partial stats are shown, and the cleanup retries on its next run).
* Fixed: uninstall now also removes the prime_cache_config_schema option.
* Clarified: the Cache 404 Pages setting description now notes the disk-usage trade-off on sites exposed to random-URL scans.
* Improved: the standard-mode notice now includes collapsible step-by-step instructions for editing wp-config.php (file location, backup, the exact line to change or add, and how to verify).
* Listing: refreshed the description, installation steps, and screenshots for the WordPress.org plugin directory.

= 1.10.29 =
* Zero-configuration caching: page caching now works immediately after activation with no wp-config.php change. When the optional `WP_CACHE` drop-in constant is not present, the plugin serves cached pages itself in a new standard mode (WordPress core loads, but the theme, queries, and rendering are skipped). Adding `define( 'WP_CACHE', true );` manually upgrades serving to the faster drop-in mode.
* wp-config.php is never written: all code that edited wp-config.php has been removed (including the 1.10.28 one-click consent button). The settings screen shows the optional line to add manually; deactivation and uninstall no longer touch the file. This addresses WordPress.org review feedback.
* Object cache drop-in install moved to the optional add-on: the free plugin no longer generates or writes wp-content/object-cache.php; it only removes its own signed drop-in. Installing a backend (APCu / Redis / Memcached) is delegated to the add-on.

= 1.10.28 =
* Privacy/consent: Prime Cache no longer edits wp-config.php automatically. Enabling page caching requires the standard `define( 'WP_CACHE', true );` drop-in constant; the plugin now asks for the site owner's explicit permission first. After activation an admin notice offers a one-click button to add the line (or shows the snippet so you can add it yourself), and the self-heal path only (re)writes the line after that approval has been recorded. Deactivation still removes only Prime Cache's own tagged line. This addresses WordPress.org review feedback.

= 1.10.27 =
* Hardening: advanced-cache.php is now produced by copying a bundled drop-in template (`dropins/advanced-cache.tpl.php`) and substituting this install's resolved paths, instead of assembling executable PHP from a string. The copied file is rewritten once on upgrade. WordPress includes the drop-in before plugin-location constants exist, so the loader path is still baked in at copy time.
* Hardening: The image optimizer's "convert a file by path" guard now confines files to WordPress-managed locations resolved through their own APIs (WP_CONTENT_DIR, the plugins directory, get_theme_root(), and wp_get_upload_dir()), plus any configured custom include directories, instead of assuming everything lives under ABSPATH. This keeps the check working when themes, plugins, or uploads have been relocated outside the WordPress root.

= 1.10.26 =
* Hardening: The drop-in's JSON config now lives under `wp-content/cache/prime-cache-config/` (a sanctioned cache location, alongside the page-cache and file-optimizer directories) instead of `wp-content/prime-cache-config/`. It is kept separate from the page-cache directory so a cache purge never removes the drop-in's settings. On upgrade the config is regenerated at the new path, advanced-cache.php is rewritten to point there, and the old directory is fully swept — every legacy format this install wrote there (install-keyed, AUTH_SALT-keyed, plain, and the earliest host-named files) is removed, and the directory itself is dropped once no co-resident install's config remains. Settings remain stored canonically via the Settings API.

= 1.10.25 =
* Hardening: The pre-WordPress page-cache drop-in now reads its settings from a non-executable JSON data file (`site-config-*.json`) instead of a generated PHP file. Settings remain stored canonically via the Settings API; the JSON file only mirrors the subset the drop-in needs before WordPress (and the options API) is available. Existing PHP config files are regenerated on upgrade and removed. A deny-all `.htaccess` and `index.html` are added to the config directory as defence in depth (the data contains no secrets).
* Hardening: URL-to-path and path-to-URL resolution now route through shared, relocation-aware mappers built on wp_get_upload_dir(), content_url(), plugins_url(), and home_url() instead of assuming everything lives directly under ABSPATH. WebP conversion, CSS inline/minify, and media optimization keep working when wp-content or uploads has been relocated, and external hosts and path-traversal are rejected.
* Hardening: The advanced-cache.php generator's fallback drop-in path is derived from plugin_dir_path() (the plugin's own location) rather than a hardcoded wp-content/plugins path.
* Docs: Reworded the Local jQuery help text and a code comment so they no longer name a specific CDN host. The free plugin never loads files from a remote host; the Local jQuery feature only re-points an existing handle back to WordPress core.

= 1.10.24 =
* Hardening: Prefixed the three image-conversion AJAX actions (`pc_img_scan` / `pc_img_batch` / `pc_img_stats` are now `prime_cache_img_*`), the bulk-convert nonce (`pc_img_nonce` -> `prime_cache_img_nonce`), and the image-dimension transient key (`pc_imgdim_` -> `prime_cache_imgdim_`) to the plugin's full `prime_cache_` namespace. Uninstall cleanup removes both the new and the legacy transient keys.
* Hardening: Renamed every internal page-cache drop-in variable from the short `$_pc_` prefix to the unique `$prime_cache_pc_` prefix so nothing leaks into the global scope under a generic name after the drop-in returns control to WordPress on a cache miss. No behavior change.

= 1.10.23 =
* Hardening: All five admin-settings inline <script> blocks are now attached via wp_add_inline_script() against a footer-registered stub handle (`prime-cache-admin-ui`), so Plugin Check no longer flags them as direct script output.
* Hardening: The page-cache drop-in now stripslashes and (where applicable) length-caps `$_SERVER['HTTP_HOST']`, `HTTP_REFERER`, `HTTP_USER_AGENT`, and `HTTP_IF_MODIFIED_SINCE` before consumption, and validates `SERVER_PROTOCOL` against an allow-list before reflecting it in the 304 status line.
* Hardening: Renamed the object-cache drop-in signature constant from `PRIME_OBJECT_CACHE` to `PRIME_CACHE_OBJECT_CACHE_DROPIN` to clear the plugin's `PRIME_CACHE_` prefix convention end-to-end.
* Docs: Added an "External services" section to readme.txt clarifying that the free plugin makes no outbound calls — the third-party hostnames (Google Analytics, GTM, Facebook Pixel, Intercom, Tawk) appear only as Delay-JS detection patterns and `cdnjs.cloudflare.com` only in code comments / help text.
* Docs: Added a "Why the page-cache drop-in keeps an open output buffer" section explaining the intentional ob_start() lifecycle shared with WP Super Cache / W3 Total Cache / WP Rocket.
* Docs: The two HTML-pipeline `<style>` / `<script>` rewrite paths in includes/class-file-optimizer.php now carry phpcs:ignore rationale comments explaining why they must replace existing tags in-place rather than enqueue.

= 1.10.22 =
* Fixed: The wp_is_block_theme() calls in the system-info / theme-detection blocks now dispatch through call_user_func() with a function_exists() guard so Plugin Check's static analysis (which does not honor phpcs:ignore) stops reporting wp_function_not_compatible_with_requires_wp errors. Behavior on both WP 5.8 and 5.9+ is unchanged.
* Fixed: Corrected the phpcs:ignore rule code on the load_plugin_textdomain() call so Plugin Check no longer reports the discouraged-function warning. The call itself is intentionally kept (see 1.10.21 changelog).

= 1.10.21 =
* Fixed: Restored bundled translation loading. The 1.10.20 removal of load_plugin_textdomain() relied on WordPress.org language packs, which are not available before translate.wordpress.org distributes them and never available for sideloaded installs — those environments showed the admin UI entirely in English. The call is now restored and runs on the init hook, with the Domain Path header added so the bundled languages/*.mo files are picked up reliably.

= 1.10.20 =
* Changed: Removed the explicit load_plugin_textdomain() call. WordPress 4.6+ loads translations automatically for plugins hosted on WordPress.org via the Text Domain header, so the explicit load was redundant and is now flagged as discouraged by Plugin Check.
* Changed: Documented the two static-analysis false positives that Plugin Check reports against this plugin so the reasoning travels with the code: (1) the file_put_contents() / rename() pairs that install the official advanced-cache.php and object-cache.php drop-ins write under WP_CONTENT_DIR (sibling of the plugin folder), not the plugin directory; (2) the wp_is_block_theme() calls are guarded by function_exists() in the same expression so they short-circuit safely on the supported WP 5.8 baseline. No behavior change.

= Older versions =
* For the changelog of version 1.10.19 and earlier, see the plugin page: https://raplsworks.com/plugins/prime-cache/

== Upgrade Notice ==

= 1.10.62 =
Fixes the Cocoon mobile sidebar drawer opening empty on the first tap when Delay JavaScript is on.

= 1.10.61 =
Display name and readme updated. The Safe preset now undoes every setting in the free Auto preset that can change how a page looks.

= 1.10.60 =
Fixes plugin and theme updates not clearing the cache on sites that turned "Purge on WordPress core update" off. Only matters if you changed that setting; the default configuration is unaffected.

= 1.10.59 =
Fixes a CSS minifier bug that broke `:not()` selectors (turning `:not(` into an invalid `:not (`), which could misposition or unstyle elements on themes that use `:not()` for layout, such as Avada. Optimized CSS/JS now carry the plugin version in their filename so fixes take effect on update without a manual cache clear. Recommended for all users, especially anyone using CSS optimization.

= 1.10.58 =
Fixes a deleted image continuing to show (an orphaned WebP/AVIF served at 200), and now removes the converted variants when you delete an image. Adds a cleanup for variants orphaned by earlier deletes (Media settings, or `wp prime-cache image-orphans`). Recommended for all users.

= 1.10.57 =
Clears the cache after a plugin or theme update, not just WordPress core updates, so changes to a plugin's or theme's CSS/JS take effect without a manual cache clear. Recommended for all users.

= 1.10.56 =
Prevents optimizer assets from being written as empty 0-byte files that serve as a silent, unstyled 200 (a likely cause of the blank Cocoon mobile sidebar), and ages out old/stray optimizer files the hourly cleanup previously never reached. Recommended for all users.

= 1.10.55 =
Fixes cached pages rendering unstyled after "Clear Minified/Critical CSS" (those now also purge the page cache), and adds a fallback that rebuilds a missing optimizer asset instead of returning a 404 that breaks the page. Recommended for all users.

= 1.10.54 =
Fixes Defer/Delay JavaScript breaking WordPress i18n scripts ("wp is not defined"), which could break translations, Contact Form 7, and the admin bar. Dependencies of kept-synchronous scripts now stay in order. Recommended for all Defer/Delay JS users.

= 1.10.53 =
Completes the Cocoon mobile slide-in sidebar fix: the relocated sidebar now stays visible inside the drawer instead of opening blank. Pairs with the jQuery fix in 1.10.52.

= 1.10.52 =
Fixes Delay JavaScript breaking jQuery (making the whole page fail with "jQuery is not a function") on sites where a theme or plugin calls jQuery.noConflict(). This is the real cause of the Cocoon mobile sidebar opening blank; the 1.10.51 Cocoon change is reverted. Recommended for all Delay JS users.

= 1.10.51 =
Attempted Cocoon mobile-sidebar fix that targeted the wrong cause. Superseded by 1.10.52 — please update to 1.10.52.

= 1.10.50 =
Fixes a cache key that could fill wp-content/cache/prime-cache-fo with hundreds of thousands of duplicate minified CSS/JS files on themes (e.g. Cocoon) that vary the ?ver= query per render. Clear the Minify cache once after updating to reclaim the space.

= 1.10.49 =
Name change only. Nothing about the plugin's behaviour or settings changes.

= 1.10.48 =
Fixes "Inline Small CSS Files" dropping a stylesheet's media condition, which could apply mobile-only CSS on desktop and break the layout. Inlined CSS is now wrapped in its @media block.

= 1.10.47 =
Fixes a false "Active" Object Cache status when a backend is installed but not actually running (e.g. the PHP extension is disabled at runtime). Pairs with Prime Cache Pro 1.9.6.

= 1.10.46 =
Minor UI cleanup: the drop-in mode guide is collapsed by default, and the unreliable "Protocol" line was removed from the Auto preset's environment summary.

= 1.10.45 =
Presets retuned for higher Core Web Vitals scores (better LCP/CLS, DOM-based HTML optimization, smarter CSS delivery). Re-apply a preset from the Tools tab to pick up the new tuning.

= 1.10.44 =
The "Maximum Delay" warning is now acknowledge-able: click "Got it" once and it stops reappearing on every save. It shows again only if you toggle Maximum Delay off and back on.

= 1.10.43 =
Adds a one-time, dismissible review request that appears on the plugin's own screens after a week of use. Shown once and never again after you review or dismiss it.

= 1.10.42 =
Adds an extension point so the optional add-on can offer one-click drop-in mode (WP_CACHE). No change for sites without the add-on; the free plugin still never edits wp-config.php.

= 1.10.41 =
Fixes Mobile Cache / Separate Mobile Cache turning themselves back on after saving when Delay JavaScript is enabled.

= 1.10.40 =
Adds the 5-minute video tutorial and introduces the Pro AI Speed Diagnosis on the Pro Features page. No functional changes to caching.

= 1.10.39 =
Preload warm-ups are no longer counted as cache misses — the dashboard hit rate now reflects real visitors only.

= 1.10.38 =
New Maximum Delay mode (delays jQuery + inline scripts for near-zero lab TBT), desktop Delay JS option, and an nginx direct-serving config generator.

= 1.10.37 =
Fixes admin-only front-end elements (WP admin bar, Cocoon's admin menu) rendering unstyled or invisible for logged-in users: the HTML optimization pipeline now skips logged-in requests unless logged-in caching is enabled.

= 1.10.36 =
Lets the Pro LCP module take over the first-image fetchpriority stamp so the real LCP image gets the full priority boost.

= 1.10.35 =
Cocoon sites: fixes mobile drawer menus staying open after browser back navigation (bfcache restore).

= 1.10.34 =
Fixes above-the-fold images staying lazy-loaded despite the skip-first setting (a major LCP improvement on themes that add their own lazy attribute), and settings saves now purge the page cache automatically.

= 1.10.32 =
Fixes "jQuery is not a function" errors when Defer JavaScript is on: jQuery is no longer deferred, and exclusion matching is now case-insensitive.

= 1.10.31 =
Reset settings now also clears all caches, so stale pages built under old optimization settings are no longer served after a reset.

= 1.10.30 =
Fixes a stale gzip-variant edge case after disabling gzip compression, guards cache scans against unreadable directories, and completes uninstall cleanup. Recommended for all users.

= 1.10.29 =
Caching now works out of the box with no wp-config.php change (new standard mode), and the plugin never writes to wp-config.php at all. Adding the WP_CACHE line yourself remains an optional speed-up (drop-in mode). Existing installs that already have the line keep working unchanged.

= 1.10.28 =
Prime Cache no longer edits wp-config.php on its own. Enabling page caching now asks for your explicit permission before adding the WP_CACHE line — click the one-time button in the admin notice, or add the line yourself. Existing installs that already have the line keep working unchanged.

= 1.10.27 =
advanced-cache.php is now copied from a bundled template (with this install's paths substituted) instead of built from a string, and the image optimizer's path guard resolves allowed locations via WordPress APIs rather than assuming ABSPATH. No behavior change on standard installs.

= 1.10.26 =
The drop-in's JSON config moves under wp-content/cache/ (a sanctioned cache location), separate from the page-cache directory so purges never remove it. Migrated automatically on upgrade; the old wp-content/prime-cache-config/ directory is cleaned up. No behavior change on standard installs.

= 1.10.25 =
The drop-in now reads a non-executable JSON config instead of a generated PHP file (regenerated automatically on upgrade), and URL/path resolution is relocation-aware (uploads, content, plugins) instead of assuming ABSPATH. No behavior change on standard installs.

= 1.10.24 =
Prefix hardening: image AJAX actions, the bulk nonce, the image-dimension transient, and all page-cache drop-in variables now use the full prime_cache_ namespace. No behavior change.

= 1.10.23 =
Pre-review hardening pass: admin inline scripts now go through wp_add_inline_script(); drop-in $_SERVER inputs are unslashed and validated; readme adds an External services section; the object-cache constant is renamed. No behavior change.

= 1.10.22 =
Silences the two Plugin Check static-analysis errors (wp_is_block_theme on a WP 5.8 baseline) by dispatching the call dynamically. No behavior change.

= 1.10.21 =
Restores bundled translation loading. Fixes the English-only admin UI seen on sideloaded installs and on sites running before WordPress.org has distributed the Japanese language pack.

= 1.10.20 =
Removes the discouraged load_plugin_textdomain() call (WordPress auto-loads .org translations) and adds in-source rationale for two Plugin Check static-analysis false positives. No behavior change.
