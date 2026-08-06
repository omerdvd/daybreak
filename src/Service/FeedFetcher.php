<?php

declare(strict_types=1);

namespace Daybreak\Service;

use Daybreak\Config;
use Daybreak\Security\SsrfGuard;
use RuntimeException;

/**
 * The ONLY way to make outbound HTTP requests. SSRF-guarded, with a realistic
 * User-Agent (several sources, e.g. Cloudflare-fronted ones, reject non-browser
 * clients — SPEC Appendix A note 1), conditional GET, timeout and size caps, and
 * manual redirect handling that re-checks SsrfGuard at every hop.
 */
final class FeedFetcher implements FetchClient
{
    private const MAX_REDIRECTS = 4;
    private const TIMEOUT_S     = 20;
    private const MAX_BYTES     = 8 * 1024 * 1024; // 8 MB cap

    public function __construct(private readonly string $userAgent = '') {}

    public static function resolveUa(string $override = ''): string
    {
        return $override !== ''
            ? $override
            : (Config::get('FETCH_USER_AGENT')
                ?: 'Mozilla/5.0 (compatible; DaybreakAggregator/0.1; +https://daybreak.silverday.de)');
    }

    private function ua(): string
    {
        return self::resolveUa($this->userAgent);
    }

    /**
     * @return array{status:int,body:string,etag:?string,last_modified:?string,not_modified:bool}
     */
    public function get(string $url, ?string $etag = null, ?string $lastModified = null, array $extraHeaders = []): array
    {
        $redirects = 0;
        while (true) {
            $pin = SsrfGuard::assertSafe($url);

            $ch = curl_init($url);
            $headers = $extraHeaders !== []
                ? $extraHeaders
                : ['Accept: application/rss+xml, application/atom+xml, application/xml, application/json;q=0.9, */*;q=0.5'];
            if ($etag) {
                $headers[] = 'If-None-Match: ' . $etag;
            }
            if ($lastModified) {
                $headers[] = 'If-Modified-Since: ' . $lastModified;
            }

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_FOLLOWLOCATION => false,            // we follow manually to re-check SSRF
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => self::TIMEOUT_S,
                CURLOPT_USERAGENT      => $this->ua(),
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_BUFFERSIZE     => 16384,
                CURLOPT_NOPROGRESS     => false,
                CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROGRESSFUNCTION => static function ($_ch, $_dlTotal, $dlNow) {
                    return $dlNow > self::MAX_BYTES ? 1 : 0; // abort oversized responses
                },
            ];
            $resolveEntry = $this->curlResolveEntry($pin);
            if ($resolveEntry !== null) {
                $options[CURLOPT_RESOLVE] = $resolveEntry;
            }
            curl_setopt_array($ch, $options);

            $raw    = curl_exec($ch);
            $errno  = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $hdrLen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            if ($errno !== 0 || $raw === false) {
                throw new RuntimeException('fetch failed: curl errno ' . $errno);
            }

            $rawHeaders = substr($raw, 0, $hdrLen);
            $body       = substr($raw, $hdrLen);

            // Manual redirect handling with SSRF re-check.
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if (++$redirects > self::MAX_REDIRECTS) {
                    throw new RuntimeException('too many redirects');
                }
                if (preg_match('/^location:\s*(.+)$/im', $rawHeaders, $m)) {
                    $url   = $this->resolveRedirect($url, trim($m[1]));
                    $etag  = null;
                    $lastModified = null;
                    continue;
                }
                throw new RuntimeException('redirect without Location');
            }

            return [
                'status'       => $status,
                'body'         => $status === 304 ? '' : $body,
                'etag'         => $this->header($rawHeaders, 'etag'),
                'last_modified' => $this->header($rawHeaders, 'last-modified'),
                'not_modified' => $status === 304,
            ];
        }
    }

    /**
     * @param array<string,string> $data
     * @param list<string> $extraHeaders
     * @return array{status:int,body:string,etag:?string,last_modified:?string,not_modified:bool}
     */
    public function postForm(string $url, array $data, array $extraHeaders = []): array
    {
        $pin = SsrfGuard::assertSafe($url);

        $ch = curl_init($url);
        $headers = array_merge([
            'Accept: application/json, */*;q=0.5',
            'Content-Type: application/x-www-form-urlencoded',
        ], $extraHeaders);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => self::TIMEOUT_S,
            CURLOPT_USERAGENT      => $this->ua(),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_BUFFERSIZE     => 16384,
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROGRESSFUNCTION => static function ($_ch, $_dlTotal, $dlNow) {
                return $dlNow > self::MAX_BYTES ? 1 : 0;
            },
        ];
        $resolveEntry = $this->curlResolveEntry($pin);
        if ($resolveEntry !== null) {
            $options[CURLOPT_RESOLVE] = $resolveEntry;
        }
        curl_setopt_array($ch, $options);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hdrLen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            throw new RuntimeException('fetch failed: curl errno ' . $errno);
        }

        return [
            'status'        => $status,
            'body'          => substr($raw, $hdrLen),
            'etag'          => null,
            'last_modified' => null,
            'not_modified'  => false,
        ];
    }

    /**
     * POST a raw JSON body. SSRF-guarded. Does not follow redirects.
     *
     * @param list<string> $extraHeaders
     * @return array{status:int,body:string,etag:?string,last_modified:?string,not_modified:bool}
     */
    public function postJson(string $url, string $jsonBody, array $extraHeaders = []): array
    {
        $pin = SsrfGuard::assertSafe($url);

        $ch = curl_init($url);
        $headers = array_merge([
            'Accept: application/json, */*;q=0.5',
            'Content-Type: application/json',
        ], $extraHeaders);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => self::TIMEOUT_S,
            CURLOPT_USERAGENT      => $this->ua(),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROGRESSFUNCTION => static function ($_ch, $_dlTotal, $dlNow) {
                return $dlNow > self::MAX_BYTES ? 1 : 0;
            },
        ];
        $resolveEntry = $this->curlResolveEntry($pin);
        if ($resolveEntry !== null) {
            $options[CURLOPT_RESOLVE] = $resolveEntry;
        }
        curl_setopt_array($ch, $options);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hdrLen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            throw new RuntimeException('postJson failed: curl errno ' . $errno);
        }

        return [
            'status'        => $status,
            'body'          => substr($raw, $hdrLen),
            'etag'          => null,
            'last_modified' => null,
            'not_modified'  => false,
        ];
    }

    public function post(string $url, string $body, array $headers = []): array
    {
        $pin = SsrfGuard::assertSafe($url);

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => self::TIMEOUT_S,
            CURLOPT_USERAGENT      => $this->ua(),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROGRESSFUNCTION => static function ($_ch, $_dlTotal, $dlNow) {
                return $dlNow > self::MAX_BYTES ? 1 : 0;
            },
        ];
        $resolveEntry = $this->curlResolveEntry($pin);
        if ($resolveEntry !== null) {
            $options[CURLOPT_RESOLVE] = $resolveEntry;
        }
        curl_setopt_array($ch, $options);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hdrLen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            throw new RuntimeException('post failed: curl errno ' . $errno);
        }

        return [
            'status'        => $status,
            'body'          => substr($raw, $hdrLen),
            'etag'          => null,
            'last_modified' => null,
            'not_modified'  => false,
        ];
    }

    /**
     * Diagnostic raw fetch — not part of the FetchClient interface, used by the admin debug panel only.
     * Returns HTTP status, raw response headers, body snippet, timing, and the effective UA sent.
     *
     * @return array{status:int,raw_headers:string,body_snippet:string,body_length:int,content_type:?string,etag:?string,last_modified:?string,not_modified:bool,duration_ms:int,effective_ua:string,final_url:string,redirect_count:int}
     */
    public function getRaw(string $url): array
    {
        $started   = microtime(true);
        $redirects = 0;
        $finalUrl  = $url;

        while (true) {
            $pin = SsrfGuard::assertSafe($url);

            $ch = curl_init($url);
            $options = [
                CURLOPT_RETURNTRANSFER   => true,
                CURLOPT_HEADER           => true,
                CURLOPT_FOLLOWLOCATION   => false,
                CURLOPT_CONNECTTIMEOUT   => 8,
                CURLOPT_TIMEOUT          => self::TIMEOUT_S,
                CURLOPT_USERAGENT        => $this->ua(),
                CURLOPT_HTTPHEADER       => ['Accept: application/rss+xml, application/atom+xml, application/xml, application/json;q=0.9, */*;q=0.5'],
                CURLOPT_PROTOCOLS        => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_BUFFERSIZE       => 16384,
                CURLOPT_NOPROGRESS       => false,
                CURLOPT_SSLVERSION       => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_SSL_VERIFYPEER   => true,
                CURLOPT_SSL_VERIFYHOST   => 2,
                CURLOPT_PROGRESSFUNCTION => static function ($_ch, $_dlTotal, $dlNow) {
                    return $dlNow > self::MAX_BYTES ? 1 : 0;
                },
            ];
            $resolveEntry = $this->curlResolveEntry($pin);
            if ($resolveEntry !== null) {
                $options[CURLOPT_RESOLVE] = $resolveEntry;
            }
            curl_setopt_array($ch, $options);

            $raw    = curl_exec($ch);
            $errno  = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $hdrLen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            if ($errno !== 0 || $raw === false) {
                throw new RuntimeException('fetch failed: curl errno ' . $errno);
            }

            $rawHeaders = substr($raw, 0, $hdrLen);
            $body       = substr($raw, $hdrLen);

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if (++$redirects > self::MAX_REDIRECTS) {
                    throw new RuntimeException('too many redirects');
                }
                if (preg_match('/^location:\s*(.+)$/im', $rawHeaders, $m)) {
                    $finalUrl = $url = $this->resolveRedirect($url, trim($m[1]));
                    continue;
                }
                throw new RuntimeException('redirect without Location');
            }

            return [
                'status'         => $status,
                'raw_headers'    => trim($rawHeaders),
                'body_snippet'   => mb_substr($body, 0, 1000),
                'body_length'    => strlen($body),
                'content_type'   => $this->header($rawHeaders, 'content-type'),
                'etag'           => $this->header($rawHeaders, 'etag'),
                'last_modified'  => $this->header($rawHeaders, 'last-modified'),
                'not_modified'   => $status === 304,
                'duration_ms'    => (int) ((microtime(true) - $started) * 1000),
                'effective_ua'   => $this->ua(),
                'final_url'      => $finalUrl,
                'redirect_count' => $redirects,
            ];
        }
    }

    /**
     * @param array{host:string,ip:string,port:int} $pin
     * @return list<string>|null
     */
    private function curlResolveEntry(array $pin): ?array
    {
        if (filter_var($pin['host'], FILTER_VALIDATE_IP)) {
            return null;
        }

        return [sprintf('%s:%d:%s', $pin['host'], $pin['port'], $pin['ip'])];
    }

    private function resolveRedirect(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $baseParts = parse_url($base);
        $locationParts = parse_url($location);

        if (!is_array($baseParts) || empty($baseParts['host'])) {
            throw new RuntimeException('invalid redirect base URL');
        }
        if ($locationParts === false) {
            throw new RuntimeException('invalid redirect location');
        }

        $scheme = (string) ($baseParts['scheme'] ?? 'https');
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        $origin = $scheme . '://' . $baseParts['host'];
        if (isset($baseParts['port'])) {
            $origin .= ':' . (int) $baseParts['port'];
        }

        $query = isset($locationParts['query']) ? '?' . $locationParts['query'] : '';
        $fragment = isset($locationParts['fragment']) ? '#' . $locationParts['fragment'] : '';
        $locationPath = (string) ($locationParts['path'] ?? '');

        if ($locationPath === '') {
            $basePath = (string) ($baseParts['path'] ?? '/');
            return $origin . ($basePath !== '' ? $basePath : '/') . $query . $fragment;
        }

        if (str_starts_with($locationPath, '/')) {
            $path = $locationPath;
        } else {
            $basePath = (string) ($baseParts['path'] ?? '/');
            $baseDir = str_ends_with($basePath, '/') ? $basePath : dirname($basePath);
            if ($baseDir === '.' || $baseDir === '\\') {
                $baseDir = '/';
            }
            $path = rtrim($baseDir, '/') . '/' . $locationPath;
        }

        return $origin . $this->normalizePath($path) . $query . $fragment;
    }

    private function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    private function header(string $rawHeaders, string $name): ?string
    {
        return preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/im', $rawHeaders, $m)
            ? trim($m[1]) : null;
    }
}
