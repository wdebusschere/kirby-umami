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
