<?php

use Akibeo\Umami\Umami;
use Akibeo\Umami\UmamiException;
use Kirby\Cms\App as Kirby;

// Composer autoload when installed as a package; plain requires when the
// folder is dropped straight into site/plugins/. Both are idempotent.
@include_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/src/UmamiException.php';
require_once __DIR__ . '/src/Umami.php';

Kirby::plugin('akibeo/umami', [
    'options' => [
        // Tracker script
        'enabled' => false,
        'websiteId' => '',
        'src' => 'https://cloud.umami.is/script.js',
        'hostUrl' => null,           // origin of the Umami instance; defaults to the origin of `src`
        'autoTrack' => true,         // false: only events sent by umami.track() from your own JS
        'domains' => [],             // only track on these hostnames (Umami's data-domains)
        'tag' => null,               // Umami's data-tag, to filter views in the dashboard
        'excludeSearch' => false,    // drop the query string from tracked URLs
        'excludeHash' => false,      // drop the hash from tracked URLs
        'trackInDebug' => false,     // also load the tracker while Kirby's debug option is on
        'trackPanelUsers' => true,   // false: no tracker for logged-in Panel users (mind the pages cache)
        'nonce' => null,             // CSP nonce; defaults to cspNonce() from akibeo/kirby-csp when present

        // Umami API (Panel view + umami()->stats()). Either an API key
        // (Umami Cloud, or a self-hosted instance that issues them; sent as
        // x-umami-api-key to Cloud and as a Bearer token to self-hosted
        // installs) or a username/password of a self-hosted install.
        'apiUrl' => null,            // defaults to https://api.umami.is/v1 (cloud) or {hostUrl}/api
        'apiKey' => null,
        'username' => null,
        'password' => null,
        'timeout' => 5,              // seconds, for every request to Umami

        // Panel
        'panel' => true,             // false: no "Analytics" entry in the Panel menu
        'shareUrl' => null,          // public share URL of the website, embedded in the Panel view

        // Cache for API tokens and stats (site/cache/…/akibeo.umami)
        'cache' => true,
    ],

    'snippets' => [
        'umami' => __DIR__ . '/snippets/umami.php',
    ],

    'siteMethods' => [
        'umami' => fn () => Umami::instance(),
    ],

    'api' => [
        'routes' => (function () {
            // Runs one API call for the Panel and turns every failure into
            // a {status: 'error', message} answer the view can show.
            $guard = function (callable $fn): array {
                $umami = Umami::instance();

                if ($umami->hasApi() === false) {
                    return [
                        'status' => 'error',
                        'message' => 'No Umami API credentials configured. Add akibeo.umami.apiKey or username/password to the config.',
                    ];
                }

                try {
                    return ['status' => 'success'] + $fn($umami);
                } catch (UmamiException $e) {
                    return ['status' => 'error', 'message' => $e->getMessage()];
                } catch (Throwable $e) {
                    return ['status' => 'error', 'message' => 'Could not reach Umami: ' . $e->getMessage()];
                }
            };

            $summary = function (array $stats): array {
                $visits = max(1, $stats['visits']);

                return [
                    'pageviews' => $stats['pageviews'],
                    'visitors' => $stats['visitors'],
                    'visits' => $stats['visits'],
                    'bounceRate' => (int)round(min($stats['bounces'], $visits) / $visits * 100),
                    'avgTime' => Umami::formatDuration((int)round($stats['totaltime'] / $visits)),
                    'avgSeconds' => (int)round($stats['totaltime'] / $visits),
                ];
            };

            return [
                // Summary numbers, live visitors and the pageviews series
                // of one period, in one request.
                [
                    'pattern' => 'plugin/umami/stats',
                    'method' => 'GET',
                    'action' => function () use ($guard, $summary) {
                        $range = (string)$this->requestQuery('range', '7d');

                        return $guard(function (Umami $umami) use ($range, $summary) {
                            $stats = $umami->stats($range);

                            $result = [
                                'range' => $stats['range'],
                                'stats' => $summary($stats),
                                'prev' => $summary($stats['prev'] + ['pageviews' => 0, 'visitors' => 0, 'visits' => 0, 'bounces' => 0, 'totaltime' => 0]),
                            ];

                            // Chart and live count are extras: a failure
                            // there should not take the numbers down.
                            try {
                                $result['series'] = $umami->series($range);
                            } catch (Throwable $e) {
                                $result['series'] = null;
                            }

                            try {
                                $result['active'] = $umami->active();
                            } catch (Throwable $e) {
                                $result['active'] = null;
                            }

                            return $result;
                        });
                    }
                ],
                // One breakdown table: ?type=path|referrer|browser|country|…
                [
                    'pattern' => 'plugin/umami/metrics',
                    'method' => 'GET',
                    'action' => function () use ($guard) {
                        $type = (string)$this->requestQuery('type', 'path');
                        $range = (string)$this->requestQuery('range', '7d');
                        $limit = (int)$this->requestQuery('limit', 50);

                        return $guard(fn (Umami $umami) => $umami->metrics($type, $range, $limit));
                    }
                ],
            ];
        })(),
    ],

    'areas' => [
        'umami' => function (Kirby $kirby) {
            $umami = Umami::instance($kirby);

            if ($umami->option('panel', true) !== true) {
                return [];
            }

            return [
                'label' => 'Analytics',
                'icon' => 'chart',
                'menu' => true,
                'link' => 'umami',
                'views' => [
                    [
                        'pattern' => 'umami',
                        'action' => fn () => [
                            'component' => 'k-umami-view',
                            'title' => 'Analytics',
                            'props' => [
                                'enabled' => $umami->option('enabled', false) === true && $umami->websiteId() !== '',
                                'websiteId' => $umami->websiteId(),
                                'hostUrl' => $umami->hostUrl(),
                                'dashboardUrl' => $umami->dashboardUrl(),
                                'shareUrl' => $umami->option('shareUrl'),
                                'hasApi' => $umami->hasApi(),
                                'siteUrl' => $kirby->site()->url(),
                                'trackInDebug' => $umami->option('trackInDebug', false) === true,
                                'debug' => $kirby->option('debug') === true,
                            ],
                        ],
                    ],
                ],
            ];
        },
    ],
]);
