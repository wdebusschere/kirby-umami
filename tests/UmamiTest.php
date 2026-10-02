<?php

namespace Akibeo\Umami\Tests;

use Akibeo\Umami\Umami;

class UmamiTest extends TestCase
{
    protected function umami(array $options = [], array $kirbyOptions = []): Umami
    {
        return new Umami($this->kirby(['akibeo.umami' => $options] + $kirbyOptions));
    }

    protected function enabled(array $options = [], array $kirbyOptions = []): Umami
    {
        return $this->umami($options + [
            'enabled' => true,
            'websiteId' => 'abc-123',
            'src' => 'https://stats.example.com/script.js',
        ], $kirbyOptions);
    }

    public function testScriptTagIsEmptyWhenDisabled(): void
    {
        $this->assertSame('', $this->umami()->scriptTag());
        $this->assertSame('', $this->umami(['enabled' => true])->scriptTag());
        $this->assertSame('', $this->umami(['enabled' => true, 'websiteId' => '  '])->scriptTag());
    }

    public function testScriptTagRendersMinimalTag(): void
    {
        $this->assertSame(
            '<script defer src="https://stats.example.com/script.js" data-website-id="abc-123"></script>',
            $this->enabled()->scriptTag()
        );
    }

    public function testScriptTagIsSkippedInDebugUnlessTrackInDebug(): void
    {
        $this->assertSame('', $this->enabled([], ['debug' => true])->scriptTag());
        $this->assertStringContainsString('<script', $this->enabled(['trackInDebug' => true], ['debug' => true])->scriptTag());
    }

    public function testScriptAttributesOnlyIncludeNonDefaultOptions(): void
    {
        $attrs = $this->enabled([
            'hostUrl' => 'https://collect.example.com/',
            'autoTrack' => false,
            'domains' => ['example.com', ' www.example.com '],
            'tag' => 'campaign',
            'excludeSearch' => true,
            'excludeHash' => true,
        ])->scriptAttributes();

        $this->assertSame([
            'defer' => true,
            'src' => 'https://stats.example.com/script.js',
            'data-website-id' => 'abc-123',
            'data-host-url' => 'https://collect.example.com',
            'data-auto-track' => 'false',
            'data-domains' => 'example.com,www.example.com',
            'data-tag' => 'campaign',
            'data-exclude-search' => 'true',
            'data-exclude-hash' => 'true',
        ], $attrs);
    }

    public function testDomainsAcceptACommaSeparatedString(): void
    {
        $attrs = $this->enabled(['domains' => 'a.com, b.com,,'])->scriptAttributes();

        $this->assertSame('a.com,b.com', $attrs['data-domains']);
    }

    public function testNonceOptionIsRenderedAndEscaped(): void
    {
        $tag = $this->enabled(['nonce' => 'abc"><x'])->scriptTag();

        $this->assertStringContainsString('nonce="abc&quot;&gt;&lt;x"', $tag);
    }

    public function testNonceOptionCanBeACallable(): void
    {
        $tag = $this->enabled(['nonce' => fn () => 'lazy'])->scriptTag();

        $this->assertStringContainsString('nonce="lazy"', $tag);
    }

    public function testHostUrlFallsBackToTheScriptOrigin(): void
    {
        $this->assertSame('https://stats.example.com', $this->enabled()->hostUrl());
        $this->assertSame('http://localhost:3000', $this->enabled(['src' => 'http://localhost:3000/script.js'])->hostUrl());
        $this->assertSame('https://collect.example.com', $this->enabled(['hostUrl' => 'https://collect.example.com/'])->hostUrl());
    }

    public function testCloudDetectionAndDefaultApiUrl(): void
    {
        $cloud = $this->enabled(['src' => 'https://cloud.umami.is/script.js']);
        $this->assertTrue($cloud->isCloud());
        $this->assertSame('https://api.umami.is/v1', $cloud->apiUrl());
        $this->assertSame('https://cloud.umami.is/websites/abc-123', $cloud->dashboardUrl());

        $hosted = $this->enabled();
        $this->assertFalse($hosted->isCloud());
        $this->assertSame('https://stats.example.com/api', $hosted->apiUrl());
        $this->assertSame('https://stats.example.com/websites/abc-123', $hosted->dashboardUrl());

        $this->assertSame('https://proxy.example.com/api', $this->enabled(['apiUrl' => 'https://proxy.example.com/api/'])->apiUrl());
    }

    public function testHasApiNeedsAWebsiteIdAndCredentials(): void
    {
        $this->assertFalse($this->enabled()->hasApi());
        $this->assertFalse($this->umami(['apiKey' => 'key'])->hasApi());
        $this->assertTrue($this->enabled(['apiKey' => 'key'])->hasApi());
        $this->assertFalse($this->enabled(['username' => 'admin'])->hasApi());
        $this->assertTrue($this->enabled(['username' => 'admin', 'password' => 'secret'])->hasApi());
    }

    public function testApiKeyHeaderDependsOnCloudOrSelfHosted(): void
    {
        $cloud = $this->enabled(['src' => 'https://cloud.umami.is/script.js', 'apiKey' => ' key ']);
        $this->assertSame(['x-umami-api-key: key'], $cloud->authHeaders());

        $hosted = $this->enabled(['apiKey' => 'key']);
        $this->assertSame(['Authorization: Bearer key'], $hosted->authHeaders());
    }

    public function testDescribeErrorIncludesUmamisErrorCode(): void
    {
        $this->assertSame(
            'Umami API responded with HTTP 401 for websites/abc/stats',
            Umami::describeError(401, 'websites/abc/stats')
        );
        $this->assertSame(
            'Umami API responded with HTTP 401 for websites/abc/stats (unauthorized)',
            Umami::describeError(401, 'websites/abc/stats', ['error' => ['message' => 'Unauthorized', 'code' => 'unauthorized', 'status' => 401]])
        );
        $this->assertSame(
            'Umami API responded with HTTP 401 for auth/login (unauthorized: incorrect-username-password)',
            Umami::describeError(401, 'auth/login', ['error' => ['message' => 'Unauthorized', 'code' => 'incorrect-username-password']])
        );
        $this->assertSame(
            'Umami API responded with HTTP 500 for x (boom)',
            Umami::describeError(500, 'x', ['error' => 'boom'])
        );
    }

    public function testPeriodFallsBackToSevenDaysAndPicksTheUnit(): void
    {
        $umami = $this->enabled();

        $week = $umami->period('7d');
        $this->assertSame('7d', $week['range']);
        $this->assertSame('day', $week['unit']);
        $this->assertSame(7 * 24 * 60 * 60 * 1000, $week['endAt'] - $week['startAt']);
        $this->assertSame(0, $week['endAt'] % 60000, 'endAt is rounded to the minute');

        $this->assertSame('hour', $umami->period('24h')['unit']);
        $this->assertSame('7d', $umami->period('bogus')['range']);
    }

    public function testMetricsRejectsUnknownTypes(): void
    {
        $this->expectException(\Akibeo\Umami\UmamiException::class);
        $this->enabled(['apiKey' => 'key'])->metrics('password', '7d');
    }

    public function testNormalizeMetricsKeepsLabelsCountsAndCountry(): void
    {
        $rows = Umami::normalizeMetrics([
            ['x' => '/aanbod', 'y' => '12'],
            ['x' => 'Amsterdam', 'y' => 3, 'country' => 'NL'],
            ['x' => '', 'y' => 1],
            'junk',
        ]);

        $this->assertSame([
            ['x' => '/aanbod', 'y' => 12],
            ['x' => 'Amsterdam', 'y' => 3, 'country' => 'NL'],
            ['x' => '(unknown)', 'y' => 1],
        ], $rows);
    }

    public function testNormalizeSeriesZeroFillsEveryBucket(): void
    {
        $period = [
            'unit' => 'day',
            'startAt' => (new \DateTimeImmutable('2026-10-01 10:00', new \DateTimeZone('Europe/Brussels')))->getTimestamp() * 1000,
            'endAt' => (new \DateTimeImmutable('2026-10-04 10:00', new \DateTimeZone('Europe/Brussels')))->getTimestamp() * 1000,
        ];

        $buckets = Umami::normalizeSeries([
            'pageviews' => [['x' => '2026-10-02T00:00:00Z', 'y' => 5], ['x' => '2026-10-04T00:00:00Z', 'y' => 2]],
            'sessions' => [['x' => '2026-10-02T00:00:00Z', 'y' => 3]],
        ], $period, 'Europe/Brussels');

        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04'], array_column($buckets, 'key'));
        $this->assertSame(['1 Oct', '2 Oct', '3 Oct', '4 Oct'], array_column($buckets, 'label'));
        $this->assertSame([0, 5, 0, 2], array_column($buckets, 'pageviews'));
        $this->assertSame([0, 3, 0, 0], array_column($buckets, 'sessions'));
    }

    public function testNormalizeSeriesUsesHourlyBucketsForOneDay(): void
    {
        $period = [
            'unit' => 'hour',
            'startAt' => (new \DateTimeImmutable('2026-10-02 10:30', new \DateTimeZone('Europe/Brussels')))->getTimestamp() * 1000,
            'endAt' => (new \DateTimeImmutable('2026-10-02 12:30', new \DateTimeZone('Europe/Brussels')))->getTimestamp() * 1000,
        ];

        $buckets = Umami::normalizeSeries([
            'pageviews' => [['x' => '2026-10-02T11:00:00Z', 'y' => 7]],
            'sessions' => [],
        ], $period, 'Europe/Brussels');

        $this->assertSame(['10:00', '11:00', '12:00'], array_column($buckets, 'label'));
        $this->assertSame([0, 7, 0], array_column($buckets, 'pageviews'));
    }

    public function testNormalizeActiveHandlesBothResponseShapes(): void
    {
        $this->assertSame(30, Umami::normalizeActive(['visitors' => 30]));
        $this->assertSame(4, Umami::normalizeActive([['x' => 4]]));
        $this->assertSame(0, Umami::normalizeActive([]));
    }

    public function testExceptionCarriesTheHttpStatus(): void
    {
        $e = new \Akibeo\Umami\UmamiException('nope', 401);
        $this->assertSame(401, $e->status());
        $this->assertSame(0, (new \Akibeo\Umami\UmamiException('nope'))->status());
    }

    public function testTrackReturnsFalseWhenDisabled(): void
    {
        $this->assertFalse($this->umami()->track('event'));
        $this->assertFalse($this->enabled([], ['debug' => true])->track('event'));
    }

    public function testNormalizeStatsHandlesV2AndFlatResponses(): void
    {
        $v2 = Umami::normalizeStats([
            'pageviews' => ['value' => 10, 'prev' => 8],
            'visitors' => ['value' => 4, 'prev' => 5],
        ]);

        $this->assertSame(10, $v2['pageviews']);
        $this->assertSame(8, $v2['prev']['pageviews']);
        $this->assertSame(0, $v2['bounces']);
        $this->assertSame(0, $v2['prev']['visits']);

        $flat = Umami::normalizeStats([
            'pageviews' => 10,
            'visits' => '6',
            'comparison' => ['pageviews' => 8],
        ]);

        $this->assertSame(10, $flat['pageviews']);
        $this->assertSame(6, $flat['visits']);
        $this->assertSame(8, $flat['prev']['pageviews']);
        $this->assertSame(0, $flat['prev']['visits']);
    }

    public function testFormatDuration(): void
    {
        $this->assertSame('0s', Umami::formatDuration(-5));
        $this->assertSame('45s', Umami::formatDuration(45));
        $this->assertSame('2m 13s', Umami::formatDuration(133));
    }
}
