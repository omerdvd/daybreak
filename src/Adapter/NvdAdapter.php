<?php

declare(strict_types=1);

namespace Daybreak\Adapter;

use Daybreak\Service\FetchClient;
use DateTimeImmutable;
use DateTimeZone;

/**
 * NIST NVD CVE API 2.0 adapter.
 * Fetches the 20 most recently published CVEs (last 7 days) for the CVE widget.
 * Items are stored in the articles table but the PublicController surfaces them
 * in the widget rail, not the main feed (adapter_type = 'nvd' is excluded from feed).
 *
 * The NVD API always returns results sorted oldest-first with no sort override
 * supported. To get the newest CVEs we first fetch with resultsPerPage=1 to read
 * totalResults, then jump to the last page via startIndex.
 */
final class NvdAdapter implements SourceAdapter
{
    private const PAGE_SIZE = 20;

    public function supports(string $adapterType): bool
    {
        return $adapterType === 'nvd';
    }

    public function fetch(array $source, FetchClient $fetcher): FetchResult
    {
        $base  = rtrim((string) $source['feed_url'], '?&');
        $tz    = new DateTimeZone('UTC');
        $start = (new DateTimeImmutable('-7 days', $tz))->format('Y-m-d\TH:i:s.000');
        $end   = (new DateTimeImmutable('now',     $tz))->format('Y-m-d\TH:i:s.000');

        // NVD API key is optional but strongly recommended — without it requests are
        // aggressively rate-limited and may return 503 under load.
        // Register free at https://nvd.nist.gov/developers/request-an-api-key
        //
        // Sent as an `apiKey` HEADER, not a query parameter — confirmed via direct
        // testing that NVD/Cloudflare rejects a valid, activated key passed as
        // ?apiKey=... with a 404 "Invalid parameter: apiKey", while the identical
        // key as a header succeeds (200). Both the unauthenticated request and the
        // header-based authenticated request work; only the query-param form is
        // rejected — looks like a WAF rule against API keys appearing in query
        // strings/logs, not an actual invalid-key condition.
        $accept = ['Accept: application/json'];
        $apiKey = \Daybreak\Config::get('NVD_API_KEY');
        if ($apiKey !== null && $apiKey !== '') {
            $accept[] = 'apiKey: ' . $apiKey;
        }

        $dateParams = '?pubStartDate=' . urlencode($start) . '&pubEndDate=' . urlencode($end);

        // Step 1: lightweight probe to get totalResults so we can jump to the last page.
        // NVD/Cloudflare is intermittently slow; retry once on transient failure.
        $probe = $this->fetchWithRetry($fetcher, $base . $dateParams . '&resultsPerPage=1', $accept);
        if ($probe['status'] >= 400) {
            $detail = mb_substr(strip_tags((string) $probe['body']), 0, 200);
            throw new \RuntimeException('NVD probe HTTP ' . $probe['status'] . ($detail !== '' ? ': ' . $detail : ''));
        }
        $meta  = json_decode($probe['body'], true);
        $total = is_array($meta) ? (int) ($meta['totalResults'] ?? 0) : 0;

        // Step 2: fetch the last page (the most recently published CVEs).
        $startIndex = max(0, $total - self::PAGE_SIZE);
        $url = $base . $dateParams . '&resultsPerPage=' . self::PAGE_SIZE . '&startIndex=' . $startIndex;

        $res  = $this->fetchWithRetry($fetcher, $url, $accept);
        $data = json_decode($res['body'], true);

        if (!is_array($data) || !isset($data['vulnerabilities']) || !is_array($data['vulnerabilities'])) {
            return new FetchResult([], $res['status']);
        }

        $items = [];
        foreach ($data['vulnerabilities'] as $vuln) {
            $cve = $vuln['cve'] ?? null;
            if (!is_array($cve) || !isset($cve['id'])) {
                continue;
            }

            $id = (string) $cve['id'];

            // English description only.
            $desc = '';
            foreach ($cve['descriptions'] ?? [] as $d) {
                if (($d['lang'] ?? '') === 'en') {
                    $desc = (string) ($d['value'] ?? '');
                    break;
                }
            }

            // CVSS severity: prefer v3.1, then v3.0, then v2.
            $severity = null;
            $score    = null;
            foreach (['cvssMetricV31', 'cvssMetricV30'] as $key) {
                if (isset($cve['metrics'][$key][0]['cvssData'])) {
                    $m        = $cve['metrics'][$key][0]['cvssData'];
                    $severity = (string) ($m['baseSeverity'] ?? '');
                    $score    = $m['baseScore'] ?? null;
                    break;
                }
            }
            if ($severity === null || $severity === '') {
                if (isset($cve['metrics']['cvssMetricV2'][0]['cvssData'])) {
                    $m        = $cve['metrics']['cvssMetricV2'][0]['cvssData'];
                    $severity = (string) ($m['baseSeverity'] ?? '');
                    $score    = $m['baseScore'] ?? null;
                }
            }

            $summary = '';
            if ($severity !== '' && $severity !== null) {
                $summary = $severity . ($score !== null ? ' (' . $score . ')' : '');
                if ($desc !== '') {
                    $summary .= ' — ' . $desc;
                }
            } else {
                $summary = $desc;
            }
            if (mb_strlen($summary) > 400) {
                $summary = mb_substr($summary, 0, 399) . '…';
            }

            $published = null;
            if (!empty($cve['published'])) {
                try {
                    $published = new DateTimeImmutable((string) $cve['published'], new DateTimeZone('UTC'));
                } catch (\Throwable) {
                }
            }

            $items[] = new NormalizedItem(
                guid: $id,
                title: $id,
                url: 'https://nvd.nist.gov/vuln/detail/' . rawurlencode($id),
                summary: $summary !== '' ? $summary : null,
                publishedAt: $published,
            );
        }

        return new FetchResult($items, $res['status'], $res['etag'], $res['last_modified']);
    }

    /**
     * Fetch with one retry on curl-level failure (errno 28 timeout, connection reset).
     * NVD/Cloudflare intermittently drops connections; a single retry resolves most cases.
     *
     * @param list<string> $headers
     * @return array{status:int,body:string,etag:?string,last_modified:?string,not_modified:bool}
     */
    private function fetchWithRetry(FetchClient $fetcher, string $url, array $headers): array
    {
        try {
            return $fetcher->get($url, null, null, $headers);
        } catch (\RuntimeException) {
            return $fetcher->get($url, null, null, $headers);
        }
    }
}
