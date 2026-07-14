# Prime Cache

A fast and stable page caching plugin for WordPress.

Prime Cache speeds up your WordPress site with static page caching, file optimization, lazy loading, and modern image conversion — with a focus on stability and sensible defaults. It works on shared hosting and never edits `wp-config.php` for you.

- **WordPress.org**: https://wordpress.org/plugins/prime-cache/
- **Website**: https://raplsworks.com/plugins/prime-cache/

## Features (Free)

- **Page caching** — static HTML cache with an optional `.htaccess` fast-path that serves cached pages directly from Apache, before PHP runs.
- **Browser cache headers** — `Cache-Control` and `Expires` for CSS, JS, images, and fonts.
- **Compression** — Gzip out of the box; Brotli when available (`mod_brotli`).
- **File optimization** — HTML / CSS / JS minification, deferred and delayed JavaScript, async CSS, query-string removal.
- **Lazy loading** — images, iframes, and videos, with a configurable number of leading images skipped to protect LCP.
- **WebP conversion** — serve smaller images to modern browsers.
- **Cache preloading** — warm the cache in the background via non-blocking requests.
- **Performance tweaks** — disable emoji, embeds, Dashicons on the frontend, jQuery Migrate, XML-RPC, and more.
- **Auto purge** — clear the right caches automatically when posts, comments, menus, or themes change.

## Pro

Prime Cache Pro adds advanced optimization for production sites:

- Critical CSS generation and unused CSS cleanup
- Persistent object cache (Redis, Memcached, APCu)
- AVIF conversion (on top of WebP)
- External cache purge (Cloudflare, Sucuri, Varnish)
- Sitemap / resource preload, DNS prefetch, preconnect
- Scheduled database cleanup (revisions, transients, overhead)

Details: https://raplsworks.com/plugins/prime-cache/

## Requirements

- WordPress 5.8 or later
- PHP 7.4 or later
- Apache with `.htaccess` support for the fast-path (optional; standard mode works anywhere)

## Installation

1. Install **Prime Cache** from the WordPress plugin directory, or upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from the **Plugins** menu.
3. Open **Prime Cache** in the admin menu and enable caching. The default settings are safe to start with.

For the optional drop-in mode (serving cached pages before WordPress loads), the plugin shows step-by-step instructions in the admin — Prime Cache never edits `wp-config.php` itself.

## Translations

Japanese translation is maintained by the author. Contributions for other locales are welcome via [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/prime-cache/).

## License

GPL-2.0-or-later. See [the GNU General Public License v2.0](https://www.gnu.org/licenses/gpl-2.0.html).

---

Built by [Rapls Works](https://raplsworks.com/).
