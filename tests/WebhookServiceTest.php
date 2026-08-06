<?php

declare(strict_types=1);

namespace Daybreak\Tests;

use Daybreak\Adapter\NormalizedItem;
use Daybreak\Service\WebhookService;

/**
 * Tests for WebhookService filter matching and payload building.
 * DB-dependent methods (dispatch, retryFailed) are not covered here;
 * the pure logic (matches, payload builders) is tested via Reflection.
 */
final class WebhookServiceTest extends TestCase
{
    private WebhookService $service;

    public function setUp(): void
    {
        $this->service = new WebhookService(new FakeFetchClient([]));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function callMatches(array $webhook, NormalizedItem $item, array $source): bool
    {
        $m = new \ReflectionMethod(WebhookService::class, 'matches');
        $m->setAccessible(true);
        return $m->invoke($this->service, $webhook, $item, $source);
    }

    private function callPayload(string $method, NormalizedItem $item, string $sourceName): array
    {
        $m = new \ReflectionMethod(WebhookService::class, $method);
        $m->setAccessible(true);
        return json_decode($m->invoke($this->service, $item, $sourceName), true, 512, JSON_THROW_ON_ERROR);
    }

    private function item(string $title, string $summary = ''): NormalizedItem
    {
        return new NormalizedItem(
            guid:    'test-guid',
            title:   $title,
            url:     'https://example.test/article',
            summary: $summary !== '' ? $summary : null,
        );
    }

    private function webhook(?string $filterJson): array
    {
        return ['id' => 1, 'user_id' => 1, 'url' => 'https://example.test/hook',
                'format' => 'generic', 'filter_json' => $filterJson, 'active' => 1];
    }

    private function source(string $categorySlug = 'critical', string $slug = 'test-source'): array
    {
        return ['id' => 1, 'name' => 'Test Source', 'slug' => $slug, 'category_slug' => $categorySlug];
    }

    // ── Filter: no filter → match all ────────────────────────────────────────

    public function testMatchesAllWhenFilterJsonIsNull(): void
    {
        $this->assertTrue($this->callMatches($this->webhook(null), $this->item('Any title'), $this->source()));
    }

    public function testMatchesAllWhenFilterJsonIsEmptyObject(): void
    {
        $this->assertTrue($this->callMatches($this->webhook('{}'), $this->item('Any title'), $this->source()));
    }

    public function testMatchesAllWhenBothArraysAreEmpty(): void
    {
        $this->assertTrue($this->callMatches(
            $this->webhook('{"terms":[],"categories":[]}'),
            $this->item('Any title'),
            $this->source()
        ));
    }

    // ── Filter: terms only ────────────────────────────────────────────────────

    public function testTermMatchIsCaseInsensitive(): void
    {
        $wh = $this->webhook('{"terms":["CVE-2024"]}');
        $this->assertTrue($this->callMatches($wh, $this->item('cve-2024-1234 exploited'), $this->source()));
    }

    public function testTermMatchInSummary(): void
    {
        $wh = $this->webhook('{"terms":["zero-day"]}');
        $this->assertTrue($this->callMatches($wh, $this->item('Patch Tuesday', 'includes zero-day'), $this->source()));
    }

    public function testTermNoMatchReturnsFalse(): void
    {
        $wh = $this->webhook('{"terms":["ransomware"]}');
        $this->assertFalse($this->callMatches($wh, $this->item('Routine patch released'), $this->source()));
    }

    public function testTermsAreOredTogetherAnyOneSuffices(): void
    {
        $wh = $this->webhook('{"terms":["ransomware","CVE-2024"]}');
        $this->assertTrue($this->callMatches($wh, $this->item('CVE-2024-1234 patch'), $this->source()));
    }

    // ── Filter: categories only ───────────────────────────────────────────────

    public function testCategoryMatchBySlug(): void
    {
        $wh = $this->webhook('{"categories":["critical"]}');
        $this->assertTrue($this->callMatches($wh, $this->item('Anything'), $this->source('critical')));
    }

    public function testCategoryNoMatchReturnsFalse(): void
    {
        $wh = $this->webhook('{"categories":["ransomware"]}');
        $this->assertFalse($this->callMatches($wh, $this->item('Anything'), $this->source('critical')));
    }

    // ── Filter: sources only ─────────────────────────────────────────────────

    public function testSourceMatchBySlug(): void
    {
        $wh = $this->webhook('{"sources":["bleeping"]}');
        $this->assertTrue($this->callMatches($wh, $this->item('Anything'), $this->source('critical', 'bleeping')));
    }

    public function testSourceNoMatchReturnsFalse(): void
    {
        $wh = $this->webhook('{"sources":["krebs"]}');
        $this->assertFalse($this->callMatches($wh, $this->item('Anything'), $this->source('critical', 'bleeping')));
    }

    public function testSourceMatchIsExact(): void
    {
        $wh = $this->webhook('{"sources":["bleeping","krebs"]}');
        $this->assertTrue($this->callMatches($wh, $this->item('Anything'), $this->source('critical', 'krebs')));
    }

    // ── Filter: both set → AND logic ──────────────────────────────────────────

    public function testAndLogic_termMatchButWrongCategory_returnsFalse(): void
    {
        $wh = $this->webhook('{"terms":["CVE"],"categories":["ransomware"]}');
        $this->assertFalse($this->callMatches($wh, $this->item('CVE-2024 exploited'), $this->source('critical')));
    }

    public function testAndLogic_rightCategoryButNoTermMatch_returnsFalse(): void
    {
        $wh = $this->webhook('{"terms":["ransomware"],"categories":["critical"]}');
        $this->assertFalse($this->callMatches($wh, $this->item('Routine patch'), $this->source('critical')));
    }

    public function testAndLogic_bothMet_returnsTrue(): void
    {
        $wh = $this->webhook('{"terms":["CVE"],"categories":["critical"]}');
        $this->assertTrue($this->callMatches($wh, $this->item('CVE-2024 exploited'), $this->source('critical')));
    }

    public function testAndLogic_termAndSourceMet_returnsTrue(): void
    {
        $wh = $this->webhook('{"terms":["CVE"],"sources":["bleeping"]}');
        $this->assertTrue($this->callMatches($wh, $this->item('CVE-2024 exploited'), $this->source('critical', 'bleeping')));
    }

    public function testAndLogic_termMetButWrongSource_returnsFalse(): void
    {
        $wh = $this->webhook('{"terms":["CVE"],"sources":["krebs"]}');
        $this->assertFalse($this->callMatches($wh, $this->item('CVE-2024 exploited'), $this->source('critical', 'bleeping')));
    }

    // ── Payload builders ──────────────────────────────────────────────────────

    public function testSlackPayloadStructure(): void
    {
        $item = $this->item('HIGH: SQL injection', 'Affects npm:foobar');
        $payload = $this->callPayload('slackPayload', $item, 'GitHub Advisory');

        $this->assertArrayHasKey('text', $payload);
        $this->assertArrayHasKey('attachments', $payload);
        $att = $payload['attachments'][0];
        $this->assertSame('HIGH: SQL injection', $att['title']);
        $this->assertSame('https://example.test/article', $att['title_link']);
        $this->assertStringContains('Affects npm:foobar', $att['text']);
        $this->assertStringContains('GitHub Advisory', $att['footer']);
    }

    public function testDiscordPayloadStructure(): void
    {
        $item = $this->item('CRITICAL: Zero-day', 'Actively exploited.');
        $payload = $this->callPayload('discordPayload', $item, 'CISA KEV');

        $this->assertArrayHasKey('embeds', $payload);
        $embed = $payload['embeds'][0];
        $this->assertSame('CRITICAL: Zero-day', $embed['title']);
        $this->assertSame('https://example.test/article', $embed['url']);
        $this->assertStringContains('Actively exploited.', $embed['description']);
        $this->assertSame('CISA KEV', $embed['footer']['text']);
    }

    public function testTeamsPayloadStructure(): void
    {
        $item = $this->item('CRITICAL: RCE in widely used library', 'Patch immediately.');
        $payload = $this->callPayload('teamsPayload', $item, 'CISA KEV');

        $this->assertSame('message', $payload['type']);
        $card = $payload['attachments'][0];
        $this->assertSame('application/vnd.microsoft.card.adaptive', $card['contentType']);

        $content = $card['content'];
        $this->assertSame('AdaptiveCard', $content['type']);

        $titleBlock = $content['body'][0];
        $this->assertSame('CRITICAL: RCE in widely used library', $titleBlock['text']);

        $summaryBlock = $content['body'][1];
        $this->assertStringContains('Patch immediately.', $summaryBlock['text']);

        $footerBlock = $content['body'][2];
        $this->assertStringContains('CISA KEV', $footerBlock['text']);

        $action = $content['actions'][0];
        $this->assertSame('Action.OpenUrl', $action['type']);
        $this->assertSame('https://example.test/article', $action['url']);
    }

    public function testGenericPayloadStructure(): void
    {
        $item = new NormalizedItem(
            guid:        'g1',
            title:       'Test advisory',
            url:         'https://example.test/advisory',
            summary:     'Short summary.',
            publishedAt: new \DateTimeImmutable('2024-06-01T12:00:00Z'),
        );
        $payload = $this->callPayload('genericPayload', $item, 'Test Source');

        $this->assertSame('new_article', $payload['event']);
        $article = $payload['article'];
        $this->assertSame('Test advisory', $article['title']);
        $this->assertSame('https://example.test/advisory', $article['url']);
        $this->assertSame('Short summary.', $article['summary']);
        $this->assertSame('Test Source', $article['source']);
        $this->assertSame('2024-06-01T12:00:00Z', $article['published_at']);
    }

    // ── ntfy ─────────────────────────────────────────────────────────────────

    private function callNtfyPayload(NormalizedItem $item, string $sourceName, bool $urgent, ?string $secretEnc = null): array
    {
        $m = new \ReflectionMethod(WebhookService::class, 'ntfyPayload');
        $m->setAccessible(true);
        return $m->invoke($this->service, $item, $sourceName, $urgent, $secretEnc);
    }

    public function testNtfyPayloadStructureDefaultPriority(): void
    {
        $item = $this->item('Regular advisory', 'Some details.');
        $payload = $this->callNtfyPayload($item, 'Test Source', false);

        $this->assertStringContains('Some details.', $payload['body']);
        $this->assertStringContains('Test Source', $payload['body']);
        $this->assertTrue(in_array('Title: Regular advisory', $payload['headers'], true));
        $this->assertTrue(in_array('Priority: default', $payload['headers'], true));
        $this->assertTrue(in_array('Tags: newspaper', $payload['headers'], true));
        $this->assertTrue(in_array('Click: https://example.test/article', $payload['headers'], true));
    }

    public function testNtfyPayloadUrgentPriorityForCritical(): void
    {
        $item = $this->item('CRITICAL: RCE in widely used library', 'Patch immediately.');
        $payload = $this->callNtfyPayload($item, 'CISA KEV', true);

        $this->assertTrue(in_array('Priority: urgent', $payload['headers'], true));
        $this->assertTrue(in_array('Tags: rotating_light', $payload['headers'], true));
    }

    public function testNtfyPayloadFallsBackToTitleWhenNoSummary(): void
    {
        $item = $this->item('Advisory with no summary');
        $payload = $this->callNtfyPayload($item, '', false);

        $this->assertStringContains('Advisory with no summary', $payload['body']);
    }

    public function testNtfyPayloadOmitsAuthHeaderWithoutSecret(): void
    {
        $item = $this->item('No secret configured');
        $payload = $this->callNtfyPayload($item, '', false, null);

        foreach ($payload['headers'] as $h) {
            $this->assertFalse(str_starts_with($h, 'Authorization:'));
        }
    }

    public function testNtfyPayloadIncludesDecryptedAuthHeaderWhenSecretProvided(): void
    {
        $encrypted = \Daybreak\Service\CredentialVault::encrypt('tk_test_token_123');
        $item = $this->item('Protected topic delivery');
        $payload = $this->callNtfyPayload($item, '', false, $encrypted);

        $this->assertTrue(in_array('Authorization: Bearer tk_test_token_123', $payload['headers'], true));
    }

    public function testNtfyPayloadStripsLeadingCategoryTagFromTitleAndBody(): void
    {
        $item = new NormalizedItem(
            guid:    'g-tag',
            title:   '[webapps] Langflow 1.9.0 - RCE',
            url:     'https://example.test/a',
            summary: null,
        );
        $payload = $this->callNtfyPayload($item, '', false);

        $this->assertTrue(in_array('Title: Langflow 1.9.0 - RCE', $payload['headers'], true));
        $this->assertStringContains('Langflow 1.9.0 - RCE', $payload['body']);
        $this->assertFalse(str_contains($payload['body'], '[webapps]'));
    }

    public function testNtfyPayloadStripsLocalAndRemoteTagsToo(): void
    {
        $local = $this->callNtfyPayload(new NormalizedItem(
            guid: 'g-l', title: '[local] ProtonVPN v4.4.1 - Unquoted Service Path',
            url: 'https://example.test/a', summary: null,
        ), '', false);
        $this->assertTrue(in_array('Title: ProtonVPN v4.4.1 - Unquoted Service Path', $local['headers'], true));

        $remote = $this->callNtfyPayload(new NormalizedItem(
            guid: 'g-r', title: '[remote] Hydra - Stack Buffer Overflow',
            url: 'https://example.test/a', summary: null,
        ), '', false);
        $this->assertTrue(in_array('Title: Hydra - Stack Buffer Overflow', $remote['headers'], true));
    }

    public function testNtfyPayloadDoesNotAlterTitleWithoutCategoryTag(): void
    {
        $item = $this->item('CRITICAL: RCE in widely used library');
        $payload = $this->callNtfyPayload($item, '', false);

        $this->assertTrue(in_array('Title: CRITICAL: RCE in widely used library', $payload['headers'], true));
    }

    public function testNtfyPayloadBodyIncludesVisibleArticleLink(): void
    {
        $item = $this->item('Advisory', 'Some details.');
        $payload = $this->callNtfyPayload($item, '', false);

        $this->assertStringContains('https://example.test/article', $payload['body']);
    }

    public function testNtfyPayloadExtractsCvssFromNvdStyleSummary(): void
    {
        $item = new NormalizedItem(
            guid: 'g-nvd', title: 'CVE-2025-1234',
            url: 'https://example.test/a',
            summary: 'CRITICAL (9.8) — Remote code execution in widget parser.',
        );
        $payload = $this->callNtfyPayload($item, '', true);

        $this->assertStringContains('CVSS 9.8', $payload['body']);
    }

    public function testNtfyPayloadExtractsCvssFromGithubAdvisoryStyleSummary(): void
    {
        $item = new NormalizedItem(
            guid: 'g-gh', title: 'GHSA-xxxx-yyyy-zzzz',
            url: 'https://example.test/a',
            summary: 'CVE-2025-5678 · CVSS 7.5 · Affects widget-lib < 2.0',
        );
        $payload = $this->callNtfyPayload($item, '', false);

        $this->assertStringContains('CVSS 7.5', $payload['body']);
    }

    public function testNtfyPayloadOmitsCvssLineWhenNotPresent(): void
    {
        $item = $this->item('CISA KEV: some vendor product', 'Added to the known-exploited-vulnerabilities catalog.');
        $payload = $this->callNtfyPayload($item, '', true);

        $this->assertFalse(str_contains($payload['body'], 'CVSS'));
    }

    public function testNtfyPayloadSanitizesCrlfInjectionFromTitleAndUrl(): void
    {
        $item = new NormalizedItem(
            guid:    'g-injection',
            title:   "Evil title\r\nX-Injected: yes",
            url:     "https://example.test/a\r\nX-Injected: yes",
            summary: null,
        );
        $payload = $this->callNtfyPayload($item, '', false);

        foreach ($payload['headers'] as $h) {
            $this->assertFalse(str_contains($h, "\r"));
            $this->assertFalse(str_contains($h, "\n"));
        }
    }

    // ── ntfy throttle ────────────────────────────────────────────────────────

    /**
     * Pins the pacing interval so a deliberate change is a visible diff, not
     * silent drift — see the constant's own docblock for why 5.0s specifically
     * (matches the self-hosted ntfy server's visitor-request-limit-replenish).
     */
    public function testNtfyThrottleIntervalMatchesServerReplenishRate(): void
    {
        $r = new \ReflectionClassConstant(WebhookService::class, 'NTFY_MIN_INTERVAL_S');
        $this->assertSame(5.0, $r->getValue());
    }

    /**
     * Fast path only: when enough time has already elapsed since the last
     * publish, throttleNtfy() must not sleep at all. (The actual-sleep path
     * is intentionally not exercised here — a real 5s sleep in every CI run
     * forever isn't a trade worth making for that one assertion.)
     */
    public function testNtfyThrottleDoesNotSleepWhenIntervalAlreadyElapsed(): void
    {
        $prop = new \ReflectionProperty(WebhookService::class, 'lastNtfyPublishAt');
        $prop->setAccessible(true);
        $prop->setValue(null, microtime(true) - 10.0); // 10s ago, well past the 5s interval

        $m = new \ReflectionMethod(WebhookService::class, 'throttleNtfy');
        $m->setAccessible(true);

        $start = microtime(true);
        $m->invoke($this->service);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($elapsed < 0.5, 'throttleNtfy() slept when it should not have');
    }
}
