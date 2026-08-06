<?php

declare(strict_types=1);

namespace Daybreak\Tests;

use Daybreak\Adapter\NvdAdapter;

final class NvdAdapterTest extends TestCase
{
    public function testFetchMapsNvdItems(): void
    {
        $baseUrl = 'https://services.nvd.nist.gov/rest/json/cves/2.0';
        $probeUrl = $this->nvdProbeUrl($baseUrl);
        $pageUrl  = $this->nvdPageUrl($baseUrl, 0);

        $fetcher = new FakeFetchClient([
            $probeUrl => [
                'status' => 200,
                'body' => json_encode(['totalResults' => 1], JSON_THROW_ON_ERROR),
                'etag' => null,
                'last_modified' => null,
                'not_modified' => false,
            ],
            $pageUrl => [
                'status' => 200,
                'body' => json_encode([
                    'vulnerabilities' => [[
                        'cve' => [
                            'id' => 'CVE-2026-1234',
                            'published' => '2026-06-10T08:00:00.000',
                            'descriptions' => [
                                ['lang' => 'en', 'value' => 'Critical remote code execution in widget stack.'],
                            ],
                            'metrics' => [
                                'cvssMetricV31' => [[
                                    'cvssData' => [
                                        'baseSeverity' => 'CRITICAL',
                                        'baseScore' => 9.8,
                                    ],
                                ]],
                            ],
                        ],
                    ]],
                ], JSON_THROW_ON_ERROR),
                'etag' => null,
                'last_modified' => null,
                'not_modified' => false,
            ],
        ]);

        $adapter = new NvdAdapter();
        $result = $adapter->fetch([
            'feed_url' => $baseUrl,
        ], $fetcher);

        $this->assertCount(1, $result->items);
        $this->assertSame('CVE-2026-1234', $result->items[0]->guid);
        $this->assertSame('CVE-2026-1234', $result->items[0]->title);
        $this->assertSame('https://nvd.nist.gov/vuln/detail/CVE-2026-1234', $result->items[0]->url);
        $this->assertSame(
            'CRITICAL (9.8) — Critical remote code execution in widget stack.',
            $result->items[0]->summary
        );
        $this->assertSame('2026-06-10 08:00:00', $result->items[0]->publishedAt?->format('Y-m-d H:i:s'));
        $this->assertSame($probeUrl, $fetcher->calls[0]['url']);
        $this->assertSame($pageUrl, $fetcher->calls[1]['url']);

        // The API key (when configured) must be sent as an `apiKey` header,
        // never in the URL/query string — NVD's WAF rejects a valid,
        // activated key passed as ?apiKey=... with a 404, while the
        // identical key as a header succeeds. Environment-agnostic: passes
        // whether or not this environment has NVD_API_KEY configured.
        $configuredKey = \Daybreak\Config::get('NVD_API_KEY');
        $expectedHeader = ($configuredKey !== null && $configuredKey !== '') ? 'apiKey: ' . $configuredKey : null;
        foreach ($fetcher->calls as $call) {
            $this->assertFalse(
                (bool) array_filter($call['headers'], static fn($h) => str_contains($h, 'apiKey=')),
                'apiKey must never appear in a query string / URL-shaped header value'
            );
            if ($expectedHeader !== null) {
                $this->assertTrue(in_array($expectedHeader, $call['headers'], true));
            }
        }
    }

    public function testFetchReturnsEmptyResultForInvalidPayload(): void
    {
        $baseUrl = 'https://services.nvd.nist.gov/rest/json/cves/2.0';
        $probeUrl = $this->nvdProbeUrl($baseUrl);
        $pageUrl  = $this->nvdPageUrl($baseUrl, 0);

        $invalidBody = json_encode(['unexpected' => true], JSON_THROW_ON_ERROR);

        $fetcher = new FakeFetchClient([
            $probeUrl => [
                'status' => 200,
                'body' => $invalidBody,
                'etag' => null,
                'last_modified' => null,
                'not_modified' => false,
            ],
            $pageUrl => [
                'status' => 200,
                'body' => $invalidBody,
                'etag' => null,
                'last_modified' => null,
                'not_modified' => false,
            ],
        ]);

        $adapter = new NvdAdapter();
        $result = $adapter->fetch([
            'feed_url' => $baseUrl,
        ], $fetcher);

        $this->assertCount(0, $result->items);
        $this->assertSame(200, $result->httpStatus);
    }

    private function nvdDateParams(): string
    {
        $tz = new \DateTimeZone('UTC');
        $start = (new \DateTimeImmutable('-7 days', $tz))->format('Y-m-d\TH:i:s.000');
        $end = (new \DateTimeImmutable('now', $tz))->format('Y-m-d\TH:i:s.000');

        return '?pubStartDate=' . urlencode($start) . '&pubEndDate=' . urlencode($end);
    }

    private function nvdProbeUrl(string $baseUrl): string
    {
        return rtrim($baseUrl, '?&') . $this->nvdDateParams() . '&resultsPerPage=1';
    }

    private function nvdPageUrl(string $baseUrl, int $startIndex): string
    {
        return rtrim($baseUrl, '?&') . $this->nvdDateParams() . '&resultsPerPage=20&startIndex=' . $startIndex;
    }
}
