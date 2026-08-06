<?php

declare(strict_types=1);

namespace Daybreak\Service;

interface FetchClient
{
    /**
     * @param list<string> $extraHeaders Additional headers (override defaults; e.g. ['Accept: application/json']).
     * @return array{status:int,body:string,etag:?string,last_modified:?string,not_modified:bool}
     */
    public function get(string $url, ?string $etag = null, ?string $lastModified = null, array $extraHeaders = []): array;

    /**
     * POST a raw JSON body (e.g. Slack/Discord webhook payloads).
     * SSRF-guarded; does not follow redirects.
     *
     * @param list<string> $extraHeaders
     * @return array{status:int,body:string,etag:?string,last_modified:?string,not_modified:bool}
     */
    public function postJson(string $url, string $jsonBody, array $extraHeaders = []): array;

    /**
     * POST an arbitrary raw body with caller-controlled headers and no
     * default Content-Type/Accept — unlike postJson(), nothing is assumed
     * about the body's shape. Used for ntfy, whose publish API takes a
     * plain-text message body plus headers (Title, Priority, Tags, ...)
     * rather than a JSON envelope.
     * SSRF-guarded; does not follow redirects.
     *
     * @param list<string> $headers
     * @return array{status:int,body:string,etag:?string,last_modified:?string,not_modified:bool}
     */
    public function post(string $url, string $body, array $headers = []): array;
}
