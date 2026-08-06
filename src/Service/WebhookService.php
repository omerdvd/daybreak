<?php

declare(strict_types=1);

namespace Daybreak\Service;

use Daybreak\Adapter\NormalizedItem;
use Daybreak\Database;

/**
 * Delivers new articles to user-configured outbound webhooks (Slack, Discord, Teams, generic HTTP).
 *
 * Called from AggregationService after each successful source fetch. Runs synchronously
 * in the cron process — delivery is fast enough at self-hosted article volumes.
 *
 * Filter semantics (filter_json = {"terms":[...],"categories":[...],"sources":[...]}):
 *   - No keys set  → match all articles
 *   - Terms only   → match if any term appears in title or summary (case-insensitive)
 *   - Categories   → match if source category slug is in the list
 *   - Sources      → match if source slug is in the list
 *   - Multiple set → AND: article must satisfy every filter type that is present
 *
 * SSRF guard is applied inside FetchClient::postJson() on every delivery attempt.
 */
final class WebhookService
{
    public function __construct(private readonly FetchClient $fetcher) {}

    /**
     * Deliver new articles from one source to all matching active webhooks.
     *
     * @param array{id:int,name:string,category_slug:?string} $source
     * @param NormalizedItem[] $newItems Articles that were INSERT-ed this run (not skipped by ON DUPLICATE KEY)
     */
    public function dispatch(array $source, array $newItems): void
    {
        if ($newItems === []) {
            return;
        }

        $webhooks = Database::query(
            'SELECT id, user_id, url, format, filter_json, secret_enc FROM user_webhooks WHERE active = 1'
        )->fetchAll();

        if ($webhooks === []) {
            return;
        }

        $urgent = ($source['category_slug'] ?? '') === 'critical';

        foreach ($webhooks as $wh) {
            foreach ($newItems as $item) {
                if (!$this->matches($wh, $item, $source)) {
                    continue;
                }

                if ($wh['format'] === 'ntfy') {
                    ['body' => $body, 'headers' => $headers] = $this->ntfyPayload(
                        $item,
                        $source['name'],
                        $urgent,
                        $wh['secret_enc'] ?? null
                    );
                    $this->deliverRaw((int) $wh['id'], $wh['url'], $body, $headers, $item, 1);
                    continue;
                }

                $payload = match ($wh['format']) {
                    'slack'   => $this->slackPayload($item, $source['name']),
                    'discord' => $this->discordPayload($item, $source['name']),
                    'teams'   => $this->teamsPayload($item, $source['name']),
                    default   => $this->genericPayload($item, $source['name']),
                };

                $this->deliver((int) $wh['id'], $wh['url'], $payload, $item, 1);
            }
        }
    }

    /**
     * Retry deliveries that failed on the previous cron run (status='failed', attempt=1).
     * Only retries entries from the last 24 hours to bound the retry window.
     */
    public function retryFailed(): void
    {
        $rows = Database::query(
            "SELECT wl.id, wl.webhook_id, wl.article_url, wl.article_title
             FROM webhook_log wl
             WHERE wl.status = 'failed' AND wl.attempt = 1
               AND wl.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        )->fetchAll();

        if ($rows === []) {
            return;
        }

        // Load the webhooks we need (may have been deleted since the failed run).
        $ids = array_unique(array_map(static fn($r) => (int) $r['webhook_id'], $rows));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $webhooks = Database::query(
            "SELECT id, url, format, secret_enc FROM user_webhooks WHERE id IN ({$placeholders}) AND active = 1",
            $ids
        )->fetchAll();
        $webhookMap = array_column($webhooks, null, 'id');

        foreach ($rows as $row) {
            $wh = $webhookMap[(int) $row['webhook_id']] ?? null;
            if ($wh === null) {
                // Webhook deleted or deactivated — mark as retry_failed without attempting.
                Database::query(
                    "UPDATE webhook_log SET status = 'retry_failed', attempt = 2 WHERE id = ?",
                    [(int) $row['id']]
                );
                continue;
            }

            // Reconstruct a minimal NormalizedItem for the payload builder.
            $item = new NormalizedItem(
                guid:    hash('sha256', $row['article_url']),
                title:   $row['article_title'],
                url:     $row['article_url'],
                summary: null,
            );

            // Source category is not recorded on webhook_log, so a retried
            // ntfy delivery can't know if the original article was
            // Critical/Patch Now — falls back to default priority, same
            // loss-of-context tradeoff the other formats already accept
            // (sourceName is blank on retry too).
            if ($wh['format'] === 'ntfy') {
                ['body' => $body, 'headers' => $headers] = $this->ntfyPayload($item, '', false, $wh['secret_enc'] ?? null);
                [$status, $error] = $this->attemptDeliveryRaw($wh['url'], $body, $headers);
            } else {
                $payload = match ($wh['format']) {
                    'slack'   => $this->slackPayload($item, ''),
                    'discord' => $this->discordPayload($item, ''),
                    'teams'   => $this->teamsPayload($item, ''),
                    default   => $this->genericPayload($item, ''),
                };
                [$status, $error] = $this->attemptDelivery($wh['url'], $payload);
            }

            // Deliver; mark the original log row as retry_ok or retry_failed.
            Database::query(
                "UPDATE webhook_log SET status = ?, http_status = ?, attempt = 2, error = ? WHERE id = ?",
                [
                    $status >= 200 && $status < 300 ? 'retry_ok' : 'retry_failed',
                    $status ?: null,
                    $error,
                    (int) $row['id'],
                ]
            );
        }
    }

    /**
     * @param array{id:int,filter_json:?string} $webhook
     * @param array{slug:string,name:string,category_slug:?string} $source
     */
    private function matches(array $webhook, NormalizedItem $item, array $source): bool
    {
        $filter = [];
        if ($webhook['filter_json'] !== null && $webhook['filter_json'] !== '') {
            $filter = json_decode($webhook['filter_json'], true) ?? [];
        }

        $terms   = array_filter((array) ($filter['terms']      ?? []));
        $cats    = array_filter((array) ($filter['categories'] ?? []));
        $sources = array_filter((array) ($filter['sources']    ?? []));

        if ($terms === [] && $cats === [] && $sources === []) {
            return true;
        }

        $termMatch = $terms === [];
        if (!$termMatch) {
            $haystack = mb_strtolower($item->title . ' ' . ($item->summary ?? ''));
            foreach ($terms as $t) {
                if (str_contains($haystack, mb_strtolower((string) $t))) {
                    $termMatch = true;
                    break;
                }
            }
        }

        $catMatch = $cats === [];
        if (!$catMatch) {
            $catSlug = (string) ($source['category_slug'] ?? '');
            foreach ($cats as $c) {
                if ($catSlug === (string) $c) {
                    $catMatch = true;
                    break;
                }
            }
        }

        $sourceMatch = $sources === [];
        if (!$sourceMatch) {
            $srcSlug = (string) ($source['slug'] ?? '');
            $sourceMatch = in_array($srcSlug, array_map('strval', $sources), true);
        }

        return $termMatch && $catMatch && $sourceMatch;
    }

    private function slackPayload(NormalizedItem $item, string $sourceName): string
    {
        $title   = mb_substr($item->title, 0, 150);
        $summary = $item->summary !== null ? mb_substr($item->summary, 0, 300) : '';
        $footer  = $sourceName !== '' ? 'Daybreak · ' . $sourceName : 'Daybreak';

        return json_encode([
            'text'        => "*{$title}*" . ($sourceName !== '' ? " — {$sourceName}" : ''),
            'attachments' => [[
                'color'      => '#c0392b',
                'title'      => $title,
                'title_link' => $item->url,
                'text'       => $summary,
                'footer'     => $footer,
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function discordPayload(NormalizedItem $item, string $sourceName): string
    {
        $title   = mb_substr($item->title, 0, 256);
        $summary = $item->summary !== null ? mb_substr($item->summary, 0, 4096) : '';

        return json_encode([
            'username' => 'Daybreak',
            'embeds'   => [[
                'title'       => $title,
                'url'         => $item->url,
                'description' => $summary,
                'color'       => 0xc0392b,
                'footer'      => ['text' => $sourceName !== '' ? $sourceName : 'Daybreak'],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Microsoft Teams incoming webhooks (Power Automate "Workflows") require an
     * Adaptive Card wrapped in a `message` envelope — the legacy MessageCard format
     * and plain JSON bodies are both rejected with a "missing card" error.
     */
    private function teamsPayload(NormalizedItem $item, string $sourceName): string
    {
        $title   = mb_substr($item->title, 0, 150);
        $summary = $item->summary !== null ? mb_substr($item->summary, 0, 500) : '';

        $body = [[
            'type'   => 'TextBlock',
            'text'   => $title,
            'weight' => 'Bolder',
            'size'   => 'Medium',
            'wrap'   => true,
        ]];

        if ($summary !== '') {
            $body[] = [
                'type' => 'TextBlock',
                'text' => $summary,
                'wrap' => true,
            ];
        }

        $body[] = [
            'type'     => 'TextBlock',
            'text'     => $sourceName !== '' ? 'Daybreak · ' . $sourceName : 'Daybreak',
            'isSubtle' => true,
            'spacing'  => 'Small',
            'wrap'     => true,
        ];

        return json_encode([
            'type'        => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content'     => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type'    => 'AdaptiveCard',
                    'version' => '1.4',
                    'body'    => $body,
                    'actions' => [[
                        'type'  => 'Action.OpenUrl',
                        'title' => 'Read article',
                        'url'   => $item->url,
                    ]],
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function genericPayload(NormalizedItem $item, string $sourceName): string
    {
        return json_encode([
            'event'   => 'new_article',
            'article' => [
                'title'        => $item->title,
                'url'          => $item->url,
                'summary'      => $item->summary,
                'source'       => $sourceName,
                'published_at' => $item->publishedAt?->format('Y-m-d\TH:i:s\Z'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * ntfy's publish API takes a raw text message body plus headers
     * (Title, Priority, Tags, Click) rather than a JSON envelope — see
     * https://docs.ntfy.sh/publish/. Click links straight to the original
     * source article, same as every other format's payload.
     *
     * Header values are sanitized against CRLF injection since title/
     * summary/url ultimately originate from external, untrusted feeds.
     *
     * @return array{body:string,headers:list<string>}
     */
    private function ntfyPayload(NormalizedItem $item, string $sourceName, bool $urgent, ?string $secretEnc): array
    {
        $title   = $this->sanitizeHeaderValue(mb_substr($item->title, 0, 150));
        $summary = $item->summary !== null ? trim($item->summary) : '';
        $body    = $summary !== '' ? mb_substr($summary, 0, 1000) : $item->title;
        if ($sourceName !== '') {
            $body .= "\n\n— " . $sourceName;
        }

        $headers = [
            'Title: ' . $title,
            'Priority: ' . ($urgent ? 'urgent' : 'default'),
            'Tags: ' . ($urgent ? 'rotating_light' : 'newspaper'),
            'Click: ' . $this->sanitizeHeaderValue($item->url),
        ];

        if ($secretEnc !== null && $secretEnc !== '') {
            $headers[] = 'Authorization: Bearer ' . CredentialVault::decrypt($secretEnc);
        }

        return ['body' => $body, 'headers' => $headers];
    }

    private function sanitizeHeaderValue(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }

    /** Attempt one delivery and log the result to webhook_log. */
    private function deliver(int $webhookId, string $url, string $payload, NormalizedItem $item, int $attempt): void
    {
        [$status, $error] = $this->attemptDelivery($url, $payload);

        Database::query(
            'INSERT INTO webhook_log (webhook_id, article_url, article_title, status, http_status, attempt, error)
             VALUES (?,?,?,?,?,?,?)',
            [
                $webhookId,
                mb_substr($item->url,   0, 1000),
                mb_substr($item->title, 0, 500),
                $status >= 200 && $status < 300 ? 'ok' : 'failed',
                $status ?: null,
                $attempt,
                $error,
            ]
        );
    }

    /**
     */
    private function attemptDelivery(string $url, string $payload): array
    {
        try {
            $res = $this->fetcher->postJson($url, $payload);
            $ok  = $res['status'] >= 200 && $res['status'] < 300;
            return [
                $res['status'],
                $ok ? null : mb_substr('HTTP ' . $res['status'] . ': ' . strip_tags($res['body']), 0, 500),
            ];
        } catch (\Throwable $e) {
            return [0, mb_substr($e->getMessage(), 0, 500)];
        }
    }

    /** Same as deliver(), but for the raw-body/headers ntfy delivery path. */
    private function deliverRaw(int $webhookId, string $url, string $body, array $headers, NormalizedItem $item, int $attempt): void
    {
        [$status, $error] = $this->attemptDeliveryRaw($url, $body, $headers);

        Database::query(
            'INSERT INTO webhook_log (webhook_id, article_url, article_title, status, http_status, attempt, error)
             VALUES (?,?,?,?,?,?,?)',
            [
                $webhookId,
                mb_substr($item->url,   0, 1000),
                mb_substr($item->title, 0, 500),
                $status >= 200 && $status < 300 ? 'ok' : 'failed',
                $status ?: null,
                $attempt,
                $error,
            ]
        );
    }

    private function attemptDeliveryRaw(string $url, string $body, array $headers): array
    {
        try {
            $res = $this->fetcher->post($url, $body, $headers);
            $ok  = $res['status'] >= 200 && $res['status'] < 300;
            return [
                $res['status'],
                $ok ? null : mb_substr('HTTP ' . $res['status'] . ': ' . strip_tags($res['body']), 0, 500),
            ];
        } catch (\Throwable $e) {
            return [0, mb_substr($e->getMessage(), 0, 500)];
        }
    }
}
