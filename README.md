# Kirby Umami

[![Tests](https://github.com/wdebusschere/kirby-umami/actions/workflows/php.yml/badge.svg)](https://github.com/wdebusschere/kirby-umami/actions/workflows/php.yml) ![Kirby 4/5](https://img.shields.io/badge/Kirby-4%20%7C%205-green.svg) ![License MIT](https://img.shields.io/badge/license-MIT-blue.svg)

[Umami](https://umami.is) analytics for [Kirby](https://getkirby.com). Umami is cookieless and stores no personal data, so it needs no cookie banner and no consent gating.

- **Tracker script** — one snippet prints the `<script>` tag with every [tracker option](https://umami.is/docs/tracker-configuration) as config.
- **CSP nonce** — picks up `cspNonce()` from [akibeo/kirby-csp](https://github.com/wdebusschere/kirby-csp) automatically.
- **Off while developing** — nothing is tracked while Kirby's `debug` option is on.
- **Server-side events** — `umami()->track('contact-form')` from PHP, for things that never reach the browser tracker (form endpoints, redirects).
- **Panel view** — an "Analytics" entry with visitors, visits, pageviews, bounce rate and visit time for the last 24h / 7 / 30 / 90 days, the events, goals and breakdown tables of the Umami dashboard, a link to the Umami dashboard and, optionally, the embedded public share report. Follows the Panel's light and dark theme.
- **Cloud or self-hosted** — works with Umami Cloud (API key) and self-hosted instances (API key or username/password).

## Installation

### Composer

```bash
composer require akibeo/kirby-umami
```

### Download / Git submodule

Copy this repository into `site/plugins/kirby-umami/`:

```bash
git submodule add https://github.com/wdebusschere/kirby-umami.git site/plugins/kirby-umami
```

No build step is required — Kirby autoloads plugins from `site/plugins/`. The plugin registers itself as `akibeo/umami` and reads its options from the `akibeo.umami` namespace.

## Configuration

Add to `site/config/config.php`. Only `enabled` and `websiteId` are
required; the rest are shown with their defaults.

```php
return [
    'akibeo.umami' => [
        'enabled' => true,
        'websiteId' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
        'src' => 'https://stats.example.com/script.js', // default: https://cloud.umami.is/script.js

        // Tracker options, see https://umami.is/docs/tracker-configuration
        'hostUrl' => null,         // origin of the Umami instance; defaults to the origin of `src`
        'autoTrack' => true,       // false: only events you send with umami.track() in JS
        'domains' => [],           // only track on these hostnames
        'tag' => null,             // tag every view, to filter in the dashboard
        'excludeSearch' => false,  // drop ?query from tracked URLs
        'excludeHash' => false,    // drop #hash from tracked URLs

        'trackInDebug' => false,   // also track while option('debug') is true
        'trackPanelUsers' => true, // false: skip logged-in Panel users (see note)
        'nonce' => null,           // CSP nonce; defaults to cspNonce() when akibeo/kirby-csp is installed

        // Umami API, for the Panel view and umami()->stats()
        'apiUrl' => null,          // defaults to https://api.umami.is/v1 (cloud) or {hostUrl}/api
        'apiKey' => null,          // API key (Umami Cloud, or Settings > API keys on self-hosted)
        'username' => null,        // or the login of a self-hosted install
        'password' => null,
        'timeout' => 5,            // seconds, for every request to Umami

        // Panel
        'panel' => true,           // false: hide the "Analytics" menu entry
        'shareUrl' => null,        // public share URL, embedded in the Panel view

        'cache' => true,           // cache for API tokens and stats
    ],
];
```

Keep secrets (`apiKey`, `password`) in a host config
(`config.<host>.php`) that is not committed.

### Per-environment overrides

`config.localhost.php` and friends can override any key:

```php
'akibeo.umami' => [
    'trackInDebug' => true, // test the tracker locally
],
```

### Content-Security-Policy

With [akibeo/kirby-csp](https://github.com/wdebusschere/kirby-csp) the script
tag carries the per-request nonce, so `script-src` needs nothing extra. The
tracker posts to `/api/send` on the Umami host, so add that host to
`connect-src`:

```php
'akibeo.csp' => [
    'directives' => [
        'connect-src' => "'self' https://stats.example.com",
    ],
],
```

Without kirby-csp, set `'nonce' => fn () => myNonce()` (a callable is
resolved per request) or leave it `null`.

### `trackPanelUsers` and the pages cache

When the pages cache is on, the first visitor decides what gets cached. A
logged-in Panel user hitting an uncached page would cache it without the
tracker. Leave `trackPanelUsers` on when the pages cache is on, or exclude
your own visits in Umami instead (`localStorage.setItem('umami.disabled', 1)`
in the browser console).

## Usage

### Tracker script

In a plain PHP template:

```php
<?php snippet('umami') ?>
```

In a Blade layout:

```blade
@snippet('umami')
```

Or build the tag yourself:

```php
<?= umami()->scriptTag() ?>
<?= $site->umami()->scriptTag() ?>
```

The tag is empty when tracking is off, so it can always be printed.

### Server-side events

```php
umami()->track('contact-form', ['subject' => $subject]);
umami()->track('download', ['file' => $file->filename()], $page->url());
```

The event joins the visitor's session in Umami: the visitor's IP and
User-Agent are forwarded. Returns `false` when tracking is off or Umami
could not be reached; it never throws.

### Stats from PHP

```php
$stats = umami()->stats('30d'); // 24h, 7d, 30d, 90d
// ['pageviews' => 1234, 'visitors' => 567, 'visits' => 600,
//  'bounces' => 210, 'totaltime' => 41000, 'prev' => [...], ...]

$pages = umami()->metrics('path', '7d', 10);
// ['type' => 'path', 'total' => 900, 'rows' => [['x' => '/', 'y' => 400], …]]
// Types: path (url on Umami < 3), entry, exit, title, hostname, query,
// referrer, channel, browser, os, device, screen, language, country,
// region, city, event, tag — see Umami::METRIC_TYPES.

$series = umami()->series('7d');
// ['unit' => 'day', 'timezone' => 'Europe/Brussels',
//  'buckets' => [['key' => '2026-10-01', 'label' => '1 Oct', 'pageviews' => 120, 'sessions' => 80], …]]

$online = umami()->active(); // visitors in the last five minutes

$goals = umami()->goals('7d');
// ['goals' => [['id' => '…', 'name' => 'Contact form sent', 'description' => '',
//   'type' => 'path', 'value' => '/contact/thanks',
//   'conversions' => 23, 'visitors' => 567, 'rate' => 4.1], …]]
// The goals saved in Umami (3.0 or newer); empty on older versions.
```

Results are cached for 10 minutes (one minute for `active()`). All of them
throw `Akibeo\Umami\UmamiException` when the API is unreachable or the
credentials are wrong; `$e->status()` holds the HTTP status. Any other
endpoint of the Umami API is reachable through `umami()->api('websites/…')`.

### Panel

"Analytics" in the Panel menu: the summary numbers with the change against
the previous period, visitors online now, a pageviews chart, the events and
the goals saved in Umami with their conversion rate, and the breakdown
tables of the Umami dashboard (pages, referrers, browsers, countries, …) for
the last 24 hours, 7, 30 or 90 days. The goals card only appears when the
website has goals. Without API credentials it only links to the Umami
dashboard (and shows the share report if `shareUrl` is set). To embed
the share report, the Umami instance has to allow framing by the Panel
origin: set `ALLOWED_FRAME_URLS=https://www.example.com` in the Umami
environment.

### API endpoints

All of them need a Panel session.

`GET /api/plugin/umami/stats?range=7d` returns the summary, the live count
and the chart series:

```json
{
  "status": "success",
  "range": "7d",
  "stats": { "pageviews": 1234, "visitors": 567, "visits": 600, "bounceRate": 35, "avgTime": "1m 8s", "avgSeconds": 68 },
  "prev": { "...": "same keys for the previous period" },
  "active": 12,
  "series": { "unit": "day", "timezone": "Europe/Brussels", "buckets": [{ "key": "2026-10-01", "label": "1 Oct", "pageviews": 120, "sessions": 80 }] }
}
```

`GET /api/plugin/umami/metrics?type=country&range=7d&limit=50` returns one
breakdown table:

```json
{ "status": "success", "range": "7d", "type": "country", "total": 567, "rows": [{ "x": "BE", "y": 280 }] }
```

`GET /api/plugin/umami/goals?range=7d` returns the goals saved in Umami
with their conversions in the period (an empty list when there are none):

```json
{ "status": "success", "range": "7d", "goals": [{ "id": "…", "name": "Contact form sent", "description": "", "type": "path", "value": "/contact/thanks", "conversions": 23, "visitors": 567, "rate": 4.1 }] }
```

## Development

```bash
composer install
composer test      # vendor/bin/phpunit
```

The logic lives in `Akibeo\Umami\Umami` (`src/Umami.php`); the test suite in
`tests/` runs it against a bare Kirby app, so no Umami instance is needed.

## License

[MIT](LICENSE) — © [E-xperience LAB](https://e-xperience.pt)

## Credits

- Wannes Debusschere
