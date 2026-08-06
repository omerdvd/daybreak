<?php

declare(strict_types=1);

namespace Daybreak\Adapter;

use Daybreak\Security\Html;
use Daybreak\Service\FetchClient;
use DateTimeImmutable;

/** Parses RSS 2.0 and Atom. The workhorse adapter for most sources. */
final class RssAtomAdapter implements SourceAdapter
{
    public function supports(string $adapterType): bool
    {
        return $adapterType === 'rss_atom';
    }

    public function fetch(array $source, FetchClient $fetcher): FetchResult
    {
        $res = $fetcher->get((string) $source['feed_url'], $source['etag'] ?? null, $source['last_modified_hdr'] ?? null);
        if ($res['not_modified']) {
            return new FetchResult([], 304, $res['etag'], $res['last_modified'], true);
        }

        // XXE guard: strip DOCTYPE before parsing. Valid RSS/Atom feeds never use
        // DOCTYPE; any feed that includes one is either malformed or attempting XXE.
        // Removing the declaration before it reaches the XML parser eliminates the
        // attack vector entirely. The regex handles internal subsets (<!DOCTYPE foo [...]>).
        // libxml_set_external_entity_loader and LIBXML_NONET are additional layers.
        $xmlBody = (string) preg_replace('/<!DOCTYPE\b[^[>]*(?:\[[^\]]*])?[^>]*>/is', '', $res['body']);
        // Only repair bytes when the document claims to be UTF-8 (or declares nothing,
        // which defaults to UTF-8) but isn't. Documents that honestly declare a
        // different encoding (e.g. ISO-8859-1) are handled correctly by libxml itself
        // via that declaration — "fixing" their bytes first would double-convert them.
        if (self::declaresUtf8($xmlBody) && !mb_check_encoding($xmlBody, 'UTF-8')) {
            $xmlBody = self::repairInvalidUtf8($xmlBody);
        }
        libxml_set_external_entity_loader(static fn() => null);
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlBody, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        if ($xml === false) {
            return new FetchResult([], $res['status'], $res['etag'], $res['last_modified']);
        }

        $items = [];
        if (isset($xml->channel->item)) {                 // RSS 2.0
            foreach ($xml->channel->item as $it) {
                $items[] = $this->item(
                    (string) ($it->guid ?: $it->link),
                    (string) $it->title,
                    (string) $it->link,
                    (string) ($it->description ?? ''),
                    (string) ($it->pubDate ?? '')
                );
            }
        } elseif (isset($xml->entry)) {                   // Atom
            foreach ($xml->entry as $en) {
                $link = '';
                foreach ($en->link as $l) {
                    if ((string) $l['rel'] === 'alternate' || (string) $l['rel'] === '') {
                        $link = (string) $l['href'];
                        break;
                    }
                }
                $items[] = $this->item(
                    (string) ($en->id ?: $link),
                    (string) $en->title,
                    $link,
                    (string) ($en->summary ?: $en->content ?? ''),
                    (string) ($en->updated ?: $en->published ?? '')
                );
            }
        }

        return new FetchResult($items, $res['status'], $res['etag'], $res['last_modified']);
    }

    private static function declaresUtf8(string $xmlBody): bool
    {
        if (str_starts_with($xmlBody, "\xEF\xBB\xBF")) {
            return true; // UTF-8 BOM
        }
        if (preg_match('/\A\s*<\?xml\b[^>]*\bencoding\s*=\s*["\']([^"\']+)["\']/i', $xmlBody, $m)) {
            return strcasecmp(trim($m[1]), 'UTF-8') === 0;
        }
        return true; // no declaration — XML defaults to UTF-8
    }

    /**
     * Some sources declare `encoding="UTF-8"` but mis-encode occasional non-ASCII
     * characters as Windows-1252/Latin-1 (e.g. an author name pasted from a CMS
     * field), which makes the whole document invalid UTF-8 and fails XML parsing
     * outright — even though every other byte is fine. Re-decode only the runs of
     * high-bit bytes that aren't already valid UTF-8 on their own, leaving correctly
     * encoded content untouched.
     */
    private static function repairInvalidUtf8(string $xmlBody): string
    {
        return (string) preg_replace_callback(
            '/[\x80-\xFF]+/',
            static function (array $m): string {
                if (mb_check_encoding($m[0], 'UTF-8')) {
                    return $m[0];
                }
                $converted = @mb_convert_encoding($m[0], 'UTF-8', 'Windows-1252');
                return $converted !== false ? $converted : '';
            },
            $xmlBody
        );
    }

    private function item(string $guid, string $title, string $url, string $summary, string $date): NormalizedItem
    {
        $published = null;
        if ($date !== '') {
            try {
                $published = new DateTimeImmutable($date);
            } catch (\Throwable) {
            }
        }
        return new NormalizedItem(
            guid: $guid !== '' ? $guid : hash('sha256', $url),
            title: trim(html_entity_decode(strip_tags($title), ENT_QUOTES, 'UTF-8')),
            url: trim($url),
            summary: Html::sanitizeSummary($summary) ?: null,
            publishedAt: $published,
        );
    }
}
