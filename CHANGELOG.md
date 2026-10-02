# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
