<?php

namespace Akibeo\Umami;

use Kirby\Cms\App;
use Kirby\Http\Remote;
use Throwable;

/**
 * Umami analytics for Kirby: renders the tracker script, sends server-side
 * events and reads stats from the Umami API for the Panel view.
 *
 * All settings live under the `akibeo.umami` option namespace, see index.php
 * for the defaults and README.md for the description of each option.
 */
class Umami
{
    protected const CACHE_TOKEN = 'auth-token';
    protected const TOKEN_TTL = 60 * 12; // minutes
    protected const STATS_TTL = 10; // minutes
    protected const ACTIVE_TTL = 1; // minutes

    /** Period presets accepted by stats(), metrics() and series(). */
    public const RANGES = ['24h', '7d', '30d', '90d'];

    /** Breakdown types of Umami's metrics endpoint. `path` is `url` on Umami < 3. */
    public const METRIC_TYPES = [
        'path', 'url', 'entry', 'exit', 'title', 'hostname', 'query',
        'referrer', 'channel',
        'browser', 'os', 'device', 'screen', 'language',
        'country', 'region', 'city',
        'event', 'tag',
    ];

    protected static ?Umami $instance = null;

    public function __construct(protected App $kirby)
    {
    }

    public static function instance(?App $kirby = null): static
    {
        return static::$instance ??= new static($kirby ?? App::instance());
    }

    /**
     * Reads one plugin option. Callables are resolved so options like the
     * nonce can be lazy.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        $value = $this->kirby->option('akibeo.umami.' . $key, $default);

        return is_callable($value) && !is_string($value) ? $value($this->kirby) : $value;
    }

    /* ------------------------------------------------------------------
     * Tracker script
     * ---------------------------------------------------------------- */

    public function websiteId(): string
    {
        return trim((string)$this->option('websiteId', ''));
    }

    public function scriptUrl(): string
    {
        return trim((string)$this->option('src', 'https://cloud.umami.is/script.js'));
    }

    /**
     * Origin of the Umami instance (no trailing slash). Falls back to the
     * origin of the tracker script, which is right for any self-hosted
     * install and for Umami Cloud.
     */
    public function hostUrl(): string
    {
        $host = trim((string)$this->option('hostUrl', ''));

        if ($host === '') {
            $parts = parse_url($this->scriptUrl());
            $host = isset($parts['scheme'], $parts['host'])
                ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
                : '';
        }

        return rtrim($host, '/');
    }

    public function isCloud(): bool
    {
        return str_contains($this->hostUrl(), 'umami.is');
    }

    /**
     * Whether the tracker script should be printed for the current request.
     */
    public function isEnabled(): bool
    {
        if ($this->option('enabled', false) !== true || $this->websiteId() === '') {
            return false;
        }

        if ($this->kirby->option('debug') === true && $this->option('trackInDebug', false) !== true) {
            return false;
        }

        if ($this->option('trackPanelUsers', true) !== true && $this->kirby->user() !== null) {
            return false;
        }

        return true;
    }

    /**
     * The `data-*` attributes for the tracker script, only those that differ
     * from Umami's defaults so the tag stays small.
     */
    public function scriptAttributes(): array
    {
        $attrs = [
            'defer' => true,
            'src' => $this->scriptUrl(),
            'data-website-id' => $this->websiteId(),
        ];

        if (($nonce = $this->nonce()) !== null) {
            $attrs['nonce'] = $nonce;
        }

        if (($host = trim((string)$this->option('hostUrl', ''))) !== '') {
            $attrs['data-host-url'] = rtrim($host, '/');
        }

        if ($this->option('autoTrack', true) !== true) {
            $attrs['data-auto-track'] = 'false';
        }

        $domains = $this->option('domains', []);
        $domains = array_filter(array_map('trim', is_array($domains) ? $domains : explode(',', (string)$domains)));
        if ($domains !== []) {
            $attrs['data-domains'] = implode(',', $domains);
        }

        if (($tag = trim((string)$this->option('tag', ''))) !== '') {
            $attrs['data-tag'] = $tag;
        }

        if ($this->option('excludeSearch', false) === true) {
            $attrs['data-exclude-search'] = 'true';
        }

        if ($this->option('excludeHash', false) === true) {
            $attrs['data-exclude-hash'] = 'true';
        }

        return $attrs;
    }

    /**
     * The complete <script> tag, or an empty string when tracking is off.
     */
    public function scriptTag(): string
    {
        if ($this->isEnabled() === false) {
            return '';
        }

        $html = '';
        foreach ($this->scriptAttributes() as $name => $value) {
            $html .= $value === true
                ? ' ' . $name
                : ' ' . $name . '="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"';
        }

        return '<script' . $html . '></script>';
    }

    protected function nonce(): ?string
    {
        $nonce = $this->option('nonce');

        if ($nonce === null && function_exists('cspNonce')) {
            $nonce = cspNonce();
        }

        $nonce = trim((string)$nonce);

        return $nonce !== '' ? $nonce : null;
    }

    /* ------------------------------------------------------------------
     * Server-side events (send API)
     * ---------------------------------------------------------------- */

    /**
     * Sends a custom event to Umami from PHP, e.g. after a form submission
     * that never reaches the browser tracker (JSON endpoint, redirect, …).
     *
     * @param string $name Event name as shown in the Umami dashboard
     * @param array $data Optional event data (strings, numbers, booleans)
     * @param string|null $url Page URL to attribute the event to; defaults
     *                         to the current request path
     * @return bool Whether Umami accepted the event
     */
    public function track(string $name, array $data = [], ?string $url = null): bool
    {
        if ($this->option('enabled', false) !== true || $this->websiteId() === '') {
            return false;
        }

        if ($this->kirby->option('debug') === true && $this->option('trackInDebug', false) !== true) {
            return false;
        }

        $request = $this->kirby->request();
        $visitor = $this->kirby->visitor();
        $siteUrl = $this->kirby->site()->url();

        $payload = [
            'website' => $this->websiteId(),
            'hostname' => (string)parse_url($siteUrl, PHP_URL_HOST),
            'url' => $url ?? '/' . ltrim($this->kirby->path(), '/'),
            'referrer' => (string)$request->header('Referer', ''),
            'language' => (string)($visitor->acceptedLanguage()?->code() ?? ''),
            'name' => $name,
        ];

        if ($data !== []) {
            $payload['data'] = $data;
        }

        if (($ip = $visitor->ip()) !== null && $ip !== '') {
            $payload['ip'] = $ip;
        }

        // Umami rejects requests without a User-Agent and uses it, together
        // with the IP, to bucket the event into a session. Forward the
        // visitor's own so a server-side event joins their session.
        $userAgent = (string)($visitor->userAgent() ?: 'Kirby/' . $this->kirby->version());

        try {
            $response = Remote::request($this->hostUrl() . '/api/send', [
                'method' => 'POST',
                'timeout' => (int)$this->option('timeout', 5),
                'headers' => [
                    'Content-Type: application/json',
                    'User-Agent: ' . $userAgent,
                    'X-Forwarded-For: ' . ($payload['ip'] ?? ''),
                ],
                'data' => json_encode(['type' => 'event', 'payload' => $payload]),
            ]);

            return $response->code() >= 200 && $response->code() < 300;
        } catch (Throwable) {
            return false;
        }
    }

    /* ------------------------------------------------------------------
     * Stats (Umami API, used by the Panel view)
     * ---------------------------------------------------------------- */

    /**
     * Whether the plugin has credentials to read from the Umami API.
     */
    public function hasApi(): bool
    {
        if ($this->websiteId() === '') {
            return false;
        }

        if (trim((string)$this->option('apiKey', '')) !== '') {
            return true;
        }

        return trim((string)$this->option('username', '')) !== ''
            && (string)$this->option('password', '') !== '';
    }

    /**
     * Base URL of the Umami API, no trailing slash.
     */
    public function apiUrl(): string
    {
        $url = trim((string)$this->option('apiUrl', ''));

        if ($url === '') {
            $url = $this->isCloud() ? 'https://api.umami.is/v1' : $this->hostUrl() . '/api';
        }

        return rtrim($url, '/');
    }

    /**
     * Link to the website in the Umami dashboard.
     */
    public function dashboardUrl(): string
    {
        $host = $this->isCloud() ? 'https://cloud.umami.is' : $this->hostUrl();

        return $host . '/websites/' . $this->websiteId();
    }

    /**
     * Summary stats for a period: `24h`, `7d`, `30d` or `90d`.
     *
     * @return array{range: string, startAt: int, endAt: int, pageviews: int, visitors: int, visits: int, bounces: int, totaltime: int, prev: array}
     */
    public function stats(string $range = '7d'): array
    {
        $period = $this->period($range);

        $cache = $this->kirby->cache('akibeo.umami');
        $cacheKey = 'stats-' . $this->websiteId() . '-' . $period['range'];

        if (($cached = $cache->get($cacheKey)) !== null) {
            return $cached;
        }

        $stats = static::normalizeStats($this->api('websites/' . $this->websiteId() . '/stats', [
            'startAt' => $period['startAt'],
            'endAt' => $period['endAt'],
        ]));

        $result = ['range' => $period['range'], 'startAt' => $period['startAt'], 'endAt' => $period['endAt']] + $stats;

        $cache->set($cacheKey, $result, static::STATS_TTL);

        return $result;
    }

    /**
     * Resolves a range preset to Umami's millisecond timestamps. Unknown
     * presets fall back to `7d`. The timestamps are rounded down to the
     * minute so repeated requests within the cache TTL share a cache key.
     *
     * @return array{range: string, seconds: int, startAt: int, endAt: int, unit: string}
     */
    public function period(string $range): array
    {
        $seconds = [
            '24h' => 60 * 60 * 24,
            '7d' => 60 * 60 * 24 * 7,
            '30d' => 60 * 60 * 24 * 30,
            '90d' => 60 * 60 * 24 * 90,
        ];

        $range = isset($seconds[$range]) ? $range : '7d';
        $endAt = intdiv(time(), 60) * 60 * 1000;

        return [
            'range' => $range,
            'seconds' => $seconds[$range],
            'startAt' => $endAt - $seconds[$range] * 1000,
            'endAt' => $endAt,
            'unit' => $range === '24h' ? 'hour' : 'day',
        ];
    }

    /**
     * One breakdown table of the dashboard: pages, referrers, browsers,
     * countries, events, … (see METRIC_TYPES). Rows are sorted by Umami,
     * most visitors first.
     *
     * @return array{range: string, type: string, total: int, rows: array<int, array{x: string, y: int, country?: string}>}
     */
    public function metrics(string $type, string $range = '7d', int $limit = 50): array
    {
        if (in_array($type, static::METRIC_TYPES, true) === false) {
            throw new UmamiException('Unknown metric type "' . $type . '"');
        }

        $period = $this->period($range);
        $limit = max(1, min(500, $limit));

        $cache = $this->kirby->cache('akibeo.umami');
        $cacheKey = 'metrics-' . $this->websiteId() . '-' . $type . '-' . $period['range'] . '-' . $limit;

        if (($cached = $cache->get($cacheKey)) !== null) {
            return $cached;
        }

        // Umami 3 renamed the `url` breakdown to `path`; older versions only
        // know `url`. Try the requested name first and the other on a 400.
        $types = match ($type) {
            'path' => ['path', 'url'],
            'url' => ['url', 'path'],
            default => [$type],
        };

        $raw = [];

        foreach ($types as $index => $try) {
            try {
                $raw = $this->api('websites/' . $this->websiteId() . '/metrics', [
                    'startAt' => $period['startAt'],
                    'endAt' => $period['endAt'],
                    'type' => $try,
                    'limit' => $limit,
                ]);
                break;
            } catch (UmamiException $e) {
                if ($e->status() !== 400 || $index === array_key_last($types)) {
                    throw $e;
                }
            }
        }

        $rows = static::normalizeMetrics($raw);

        $result = [
            'range' => $period['range'],
            'type' => $type,
            'total' => array_sum(array_column($rows, 'y')),
            'rows' => $rows,
        ];

        $cache->set($cacheKey, $result, static::STATS_TTL);

        return $result;
    }

    /**
     * Umami returns `[{x, y}]` (plus `country` for regions and cities).
     * Keeps that shape, casts the numbers and drops rows without a label.
     *
     * @return array<int, array{x: string, y: int, country?: string}>
     */
    public static function normalizeMetrics(array $raw): array
    {
        $rows = [];

        foreach ($raw as $row) {
            if (is_array($row) === false) {
                continue;
            }

            $label = trim((string)($row['x'] ?? ''));
            $item = ['x' => $label === '' ? '(unknown)' : $label, 'y' => (int)($row['y'] ?? 0)];

            if (isset($row['country']) && is_string($row['country']) && $row['country'] !== '') {
                $item['country'] = $row['country'];
            }

            $rows[] = $item;
        }

        return $rows;
    }

    /**
     * Pageviews and sessions over time for the chart: hourly buckets for
     * `24h`, daily buckets otherwise. Every bucket of the period is present,
     * zero-filled, in Kirby's configured timezone.
     *
     * @return array{range: string, unit: string, timezone: string, buckets: array<int, array{key: string, label: string, pageviews: int, sessions: int}>}
     */
    public function series(string $range = '7d'): array
    {
        $period = $this->period($range);
        $timezone = date_default_timezone_get();

        $cache = $this->kirby->cache('akibeo.umami');
        $cacheKey = 'series-' . $this->websiteId() . '-' . $period['range'];

        if (($cached = $cache->get($cacheKey)) !== null) {
            return $cached;
        }

        $raw = $this->api('websites/' . $this->websiteId() . '/pageviews', [
            'startAt' => $period['startAt'],
            'endAt' => $period['endAt'],
            'unit' => $period['unit'],
            'timezone' => $timezone,
        ]);

        $result = [
            'range' => $period['range'],
            'unit' => $period['unit'],
            'timezone' => $timezone,
            'buckets' => static::normalizeSeries($raw, $period, $timezone),
        ];

        $cache->set($cacheKey, $result, static::STATS_TTL);

        return $result;
    }

    /**
     * Zero-fills the `{pageviews: [{x, y}], sessions: [{x, y}]}` response of
     * the pageviews endpoint into one bucket per hour/day of the period.
     * Umami labels buckets with the local date/time of the requested
     * timezone (suffixed with "Z" although it is not UTC), so the labels are
     * matched on their date/hour part without converting them.
     *
     * @return array<int, array{key: string, label: string, pageviews: int, sessions: int}>
     */
    public static function normalizeSeries(array $raw, array $period, string $timezone): array
    {
        $hourly = ($period['unit'] ?? 'day') === 'hour';
        $keyLength = $hourly ? 13 : 10; // "2026-10-02T14" / "2026-10-02"

        $index = [];
        foreach (['pageviews', 'sessions'] as $metric) {
            foreach ((array)($raw[$metric] ?? []) as $point) {
                if (is_array($point) && isset($point['x'])) {
                    $index[$metric][substr((string)$point['x'], 0, $keyLength)] = (int)($point['y'] ?? 0);
                }
            }
        }

        $tz = new \DateTimeZone($timezone);
        $cursor = (new \DateTimeImmutable('@' . intdiv((int)$period['startAt'], 1000)))->setTimezone($tz);
        $end = (new \DateTimeImmutable('@' . intdiv((int)$period['endAt'], 1000)))->setTimezone($tz);
        $cursor = $hourly ? $cursor->setTime((int)$cursor->format('H'), 0) : $cursor->setTime(0, 0);
        $step = new \DateInterval($hourly ? 'PT1H' : 'P1D');

        $buckets = [];
        while ($cursor <= $end) {
            $key = $cursor->format($hourly ? 'Y-m-d\TH' : 'Y-m-d');
            $buckets[] = [
                'key' => $key,
                'label' => $cursor->format($hourly ? 'H:i' : 'j M'),
                'pageviews' => $index['pageviews'][$key] ?? 0,
                'sessions' => $index['sessions'][$key] ?? 0,
            ];
            $cursor = $cursor->add($step);
        }

        return $buckets;
    }

    /**
     * Visitors active in the last five minutes.
     */
    public function active(): int
    {
        $cache = $this->kirby->cache('akibeo.umami');
        $cacheKey = 'active-' . $this->websiteId();

        if (is_int($cached = $cache->get($cacheKey))) {
            return $cached;
        }

        $raw = $this->api('websites/' . $this->websiteId() . '/active');
        $active = static::normalizeActive($raw);

        $cache->set($cacheKey, $active, static::ACTIVE_TTL);

        return $active;
    }

    /**
     * The active endpoint answers `{visitors: n}` on recent Umami versions
     * and `[{x: n}]` on older ones.
     */
    public static function normalizeActive(array $raw): int
    {
        if (isset($raw['visitors'])) {
            return (int)$raw['visitors'];
        }

        return (int)($raw[0]['x'] ?? $raw['x'] ?? 0);
    }

    /**
     * Umami has changed the shape of the stats response over time: v2 returns
     * `{pageviews: {value, prev}}`, newer releases return flat numbers with a
     * `comparison` object. Both end up as flat numbers plus a `prev` array.
     */
    public static function normalizeStats(array $raw): array
    {
        $keys = ['pageviews', 'visitors', 'visits', 'bounces', 'totaltime'];
        $stats = ['prev' => []];

        foreach ($keys as $key) {
            $value = $raw[$key] ?? 0;

            if (is_array($value)) {
                $stats[$key] = (int)($value['value'] ?? 0);
                $stats['prev'][$key] = (int)($value['prev'] ?? 0);
            } else {
                $stats[$key] = (int)$value;
                $stats['prev'][$key] = (int)($raw['comparison'][$key] ?? 0);
            }
        }

        return $stats;
    }

    /**
     * GET request against the Umami API. Throws on transport errors, auth
     * failures or non-JSON responses so the Panel can show the reason.
     */
    public function api(string $path, array $query = []): array
    {
        if ($this->hasApi() === false) {
            throw new UmamiException('No Umami API credentials configured (akibeo.umami.apiKey or username/password)');
        }

        $url = $this->apiUrl() . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $response = Remote::request($url, [
            'method' => 'GET',
            'timeout' => (int)$this->option('timeout', 5),
            'headers' => array_merge(['Accept: application/json'], $this->authHeaders()),
        ]);

        if ($response->code() === 401 && trim((string)$this->option('apiKey', '')) === '') {
            // Token expired or was revoked: log in again once.
            $this->kirby->cache('akibeo.umami')->remove(static::CACHE_TOKEN);

            $response = Remote::request($url, [
                'method' => 'GET',
                'timeout' => (int)$this->option('timeout', 5),
                'headers' => array_merge(['Accept: application/json'], $this->authHeaders()),
            ]);
        }

        $json = json_decode($response->content(), true);

        if ($response->code() < 200 || $response->code() >= 300) {
            throw new UmamiException(static::describeError($response->code(), $path, is_array($json) ? $json : []), $response->code());
        }

        if (is_array($json) === false) {
            throw new UmamiException('Umami API returned no JSON for ' . $path, $response->code());
        }

        return $json;
    }

    /**
     * Error message for a failed API call. Umami answers errors with
     * `{error: {message, code, status}}`; the code (`unauthorized`,
     * `incorrect-username-password`, …) is what tells a wrong key apart from
     * a key without access to the website, so it is included when present.
     */
    public static function describeError(int $status, string $path, array $json = []): string
    {
        $message = 'Umami API responded with HTTP ' . $status . ' for ' . $path;
        $error = $json['error'] ?? [];

        if (is_string($error)) {
            $error = ['message' => $error];
        }

        $details = array_filter([
            trim((string)($error['message'] ?? '')),
            trim((string)($error['code'] ?? '')),
        ], fn (string $part) => $part !== '');

        if ($details !== []) {
            $message .= ' (' . implode(': ', array_unique(array_map('strtolower', $details))) . ')';
        }

        return $message;
    }

    /**
     * Auth headers for the Umami API. Umami Cloud reads API keys from its
     * own `x-umami-api-key` header; a self-hosted install expects the API
     * key (or a login token) as a Bearer token and answers 401 otherwise.
     */
    public function authHeaders(): array
    {
        $apiKey = trim((string)$this->option('apiKey', ''));

        if ($apiKey !== '') {
            return $this->isCloud()
                ? ['x-umami-api-key: ' . $apiKey]
                : ['Authorization: Bearer ' . $apiKey];
        }

        return ['Authorization: Bearer ' . $this->token()];
    }

    /**
     * Bearer token for username/password auth (self-hosted). Cached so the
     * Panel does not log in on every request.
     */
    protected function token(): string
    {
        $cache = $this->kirby->cache('akibeo.umami');

        if (is_string($token = $cache->get(static::CACHE_TOKEN)) && $token !== '') {
            return $token;
        }

        $response = Remote::request($this->apiUrl() . '/auth/login', [
            'method' => 'POST',
            'timeout' => (int)$this->option('timeout', 5),
            'headers' => ['Content-Type: application/json', 'Accept: application/json'],
            'data' => json_encode([
                'username' => (string)$this->option('username', ''),
                'password' => (string)$this->option('password', ''),
            ]),
        ]);

        $json = json_decode($response->content(), true);
        $token = $json['token'] ?? null;

        if ($response->code() !== 200 || is_string($token) === false || $token === '') {
            throw new UmamiException('Umami login failed (HTTP ' . $response->code() . '); check akibeo.umami.username/password', $response->code());
        }

        $cache->set(static::CACHE_TOKEN, $token, static::TOKEN_TTL);

        return $token;
    }

    /**
     * Human-friendly duration for the Panel, e.g. "2m 13s".
     */
    public static function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return $minutes > 0 ? $minutes . 'm ' . $rest . 's' : $rest . 's';
    }
}
