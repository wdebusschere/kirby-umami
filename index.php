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
        // (Umami Cloud, or a self-hosted instance that issues them) or a
        // username/password of a self-hosted install.
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
        'routes' => [
            [
                'pattern' => 'plugin/umami/stats',
                'method' => 'GET',
                'action' => function () {
                    $umami = Umami::instance();
                    $range = (string)$this->requestQuery('range', '7d');

                    if ($umami->hasApi() === false) {
                        return [
                            'status' => 'error',
                            'message' => 'No Umami API credentials configured. Add akibeo.umami.apiKey or username/password to the config.',
                        ];
                    }

                    try {
                        $stats = $umami->stats($range);
                    } catch (UmamiException $e) {
                        return ['status' => 'error', 'message' => $e->getMessage()];
                    } catch (Throwable $e) {
                        return ['status' => 'error', 'message' => 'Could not reach Umami: ' . $e->getMessage()];
                    }

                    $visits = max(1, $stats['visits']);
                    $prevVisits = max(1, $stats['prev']['visits'] ?? 0);

                    return [
                        'status' => 'success',
                        'range' => $stats['range'],
                        'stats' => [
                            'pageviews' => $stats['pageviews'],
                            'visitors' => $stats['visitors'],
                            'visits' => $stats['visits'],
                            'bounceRate' => (int)round(min($stats['bounces'], $visits) / $visits * 100),
                            'avgTime' => Umami::formatDuration((int)round($stats['totaltime'] / $visits)),
                        ],
                        'prev' => [
                            'pageviews' => $stats['prev']['pageviews'] ?? 0,
                            'visitors' => $stats['prev']['visitors'] ?? 0,
                            'visits' => $stats['prev']['visits'] ?? 0,
                            'bounceRate' => (int)round(min($stats['prev']['bounces'] ?? 0, $prevVisits) / $prevVisits * 100),
                            'avgTime' => Umami::formatDuration((int)round(($stats['prev']['totaltime'] ?? 0) / $prevVisits)),
                        ],
                    ];
                }
            ],
        ],
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
