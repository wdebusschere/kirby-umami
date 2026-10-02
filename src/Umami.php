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
        $ranges = ['24h' => 60 * 60 * 24, '7d' => 60 * 60 * 24 * 7, '30d' => 60 * 60 * 24 * 30, '90d' => 60 * 60 * 24 * 90];
        $seconds = $ranges[$range] ?? $ranges['7d'];
        $range = isset($ranges[$range]) ? $range : '7d';

        $cache = $this->kirby->cache('akibeo.umami');
        $cacheKey = 'stats-' . $this->websiteId() . '-' . $range;

        if (($cached = $cache->get($cacheKey)) !== null) {
            return $cached;
        }

        $endAt = time() * 1000;
        $startAt = ($endAt - $seconds * 1000);

        $stats = static::normalizeStats($this->api('websites/' . $this->websiteId() . '/stats', [
            'startAt' => $startAt,
            'endAt' => $endAt,
        ]));

        $result = ['range' => $range, 'startAt' => $startAt, 'endAt' => $endAt] + $stats;

        $cache->set($cacheKey, $result, static::STATS_TTL);

        return $result;
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
            throw new UmamiException(static::describeError($response->code(), $path, is_array($json) ? $json : []));
        }

        if (is_array($json) === false) {
            throw new UmamiException('Umami API returned no JSON for ' . $path);
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
            throw new UmamiException('Umami login failed (HTTP ' . $response->code() . '); check akibeo.umami.username/password');
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
