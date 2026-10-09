# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- The Analytics view is readable in the Panel's dark theme: cards, the
  period toggle, badges, bars and tables now use theme-aware colours
  instead of fixed white and light greys, which hid the numbers on a dark
  background (#6).
- Breakdown table rows in the Analytics view lost their layout: the
  background bar became a grid cell and pushed the label, count and share
  into the wrong columns. The chart's axis numbers no longer overlap the
  last bar.

### Added

- Goals: the goals saved in Umami are listed in the Analytics view with
  their conversions and conversion rate for the chosen period, next to the
  events. The card only appears when the website has goals (#8).
  `umami()->goals($range)` and `GET /api/plugin/umami/goals?range=…`
  return the same list. Umami 3.4's goals API and the report API of
  Umami 3.0 – 3.3 are both supported; `umami()->api()` can now send POST
  requests with a JSON body for it.

- The "Analytics" Panel view is now a dashboard: visitors, visits,
  pageviews, bounce rate and visit time with the change against the previous
  period, visitors online right now, a pageviews/visits chart (hourly for
  24h, daily otherwise) and breakdown tables with tabs for pages (pages,
  entry, exit, titles), referrers (referrers, channels), environment
  (browsers, OS, devices, screens), location (countries, regions, cities,
  languages) and events. Country and city rows carry a flag, page rows link
  to the site, long tables expand on demand. The chosen period is remembered.
- `umami()->metrics($type, $range, $limit)` for one breakdown table (any of
  `Umami::METRIC_TYPES`), `umami()->series($range)` for the zero-filled
  pageviews/sessions buckets in Kirby's timezone and `umami()->active()` for
  the live visitor count. All cached like `stats()`.
- `GET /api/plugin/umami/metrics?type=…&range=…&limit=…` for the Panel; the
  stats endpoint also returns `series` and `active`.
- `UmamiException::status()` with the HTTP status of the failed response.

### Changed

- The events table moved up: it sits right under the summary card, before
  the pages, referrers, environment and location breakdowns (#7).
- `url` and `path` are accepted as the pages breakdown: Umami 3 renamed the
  type, the plugin retries with the other name on a 400.

### Fixed

- API keys of a self-hosted Umami instance are sent as `Authorization: Bearer`,
  which is what self-hosted Umami expects. They were sent as `x-umami-api-key`
  (Umami Cloud's header) and every stats request answered 401. Cloud keeps
  using `x-umami-api-key`.
- API errors now include Umami's own error message and code (for example
  `unauthorized` or `incorrect-username-password`) instead of only the HTTP
  status, so the Panel shows why a request failed.

## [1.0.0] - 2026-10-02

### Added

- `umami` snippet and `umami()->scriptTag()` that print the Umami tracker
  `<script>` with all tracker options (`hostUrl`, `autoTrack`, `domains`,
  `tag`, `excludeSearch`, `excludeHash`) as `data-*` attributes.
- CSP nonce on the script tag: the `nonce` option, or `cspNonce()` from
  `akibeo/kirby-csp` when that plugin is installed.
- Tracking is skipped while Kirby's `debug` option is on (`trackInDebug`
  overrides) and, optionally, for logged-in Panel users (`trackPanelUsers`).
- `umami()->track()` for server-side events through Umami's send API, with
  the visitor's IP and User-Agent forwarded so the event joins their session.
- `umami()->stats()` and the `GET /api/plugin/umami/stats` endpoint, reading
  summary numbers from the Umami API with an API key (Umami Cloud) or a
  username/password login (self-hosted). Tokens and results are cached.
- "Analytics" Panel area with visitors, visits, pageviews, bounce rate and
  average visit time for the last 24h / 7 / 30 / 90 days, a link to the
  Umami dashboard and the optional embedded public share report.
- Installable with Composer (`akibeo/kirby-umami`), as a Git submodule or by
  copying the folder into `site/plugins/`.

[Unreleased]: https://github.com/wdebusschere/kirby-umami/compare/1.0.0...HEAD
[1.0.0]: https://github.com/wdebusschere/kirby-umami/releases/tag/1.0.0
