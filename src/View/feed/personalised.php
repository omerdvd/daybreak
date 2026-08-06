<?php

declare(strict_types=1);

use Daybreak\Security\Html;
use Daybreak\Security\Csrf;

// $sinceMode    — bool: user requested since-last-visit mode
// $sinceQuery   — bool: we actually queried by timestamp (false on first visit)
// $lastSeen     — string|null: the previous explicit "mark seen" timestamp
// $unreadCount  — int|null: count of new items (only when $sinceQuery)
// $windowDays   — int: days fallback (used when no previous visit)
// $markSeenReturnTo — string: local path to return after marking as seen

/** @var bool $canBookmarkToKioju */

$sinceMode      = $sinceMode      ?? false;
$sinceQuery     = $sinceQuery     ?? false;
$lastSeen       = $lastSeen       ?? null;
$unreadCount    = $unreadCount    ?? null;
$windowDays     = $windowDays     ?? 1;
$articles       = $articles       ?? [];
$markSeenReturnTo = $markSeenReturnTo ?? '/feed?days=since';
$page           = $page           ?? 1;
$totalPages     = $totalPages     ?? 1;
$paginationBase = $paginationBase ?? null;
$alertArticles  = $alertArticles  ?? [];
$watchTerms     = $watchTerms     ?? [];

if ($alertArticles !== []):
  $_watchNoticeId = md5(implode(',', array_map(static fn($wa) => (string) ($wa['id'] ?? ''), $alertArticles)));
  ?>
  <div class="watch-alerts" role="region" aria-label="Watch term alerts" id="watch-alerts" data-notice-id="<?= Html::e($_watchNoticeId) ?>">
    <div class="watch-alerts-header">
      <p class="watch-alerts-title">Watch term alerts</p>
      <button type="button" class="notice-dismiss" data-dismiss-notice aria-label="Dismiss watch term alerts">&times;</button>
    </div>
    <ul class="watch-alert-list">
      <?php foreach ($alertArticles as $wa): ?>
        <li class="watch-alert-item">
          <a href="<?= Html::e($wa['url']) ?>" target="_blank" rel="noopener noreferrer nofollow">
            <?= Html::e($wa['title']) ?>
          </a>
          <span class="source-badge" data-badge-color="<?= Html::e($wa['color'] ?? '#909090') ?>">
            <?= Html::e($wa['source_name']) ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<?php if ($sinceMode): ?>
  <div class="since-banner<?= ($sinceQuery && $unreadCount !== null) ? '' : ' since-banner--init' ?>">
    <?php if ($sinceQuery && $unreadCount !== null): ?>
      <strong class="since-count"><?= $unreadCount ?></strong>
      new <?= $unreadCount === 1 ? 'item' : 'items' ?> since
      <time datetime="<?= Html::e($lastSeen ?? '') ?>">
        <?= Html::e(date('M j, g:i a', strtotime($lastSeen ?? 'now'))) ?>
      </time>
      <form method="post" action="/feed/mark-seen" class="admin-action-row admin-action-row--inline">
        <input type="hidden" name="_csrf" value="<?= Html::e(Csrf::token()) ?>">
        <input type="hidden" name="return_to" value="<?= Html::e($markSeenReturnTo) ?>">
        <button type="submit" class="btn btn-secondary btn-sm">Mark feed as seen</button>
      </form>
    <?php else: ?>
      First visit &mdash; showing last <?= $windowDays ?> day<?= $windowDays !== 1 ? 's' : '' ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php if (empty($articles)): ?>
  <div class="no-articles">
    <p>Nothing new since your last visit. Check back later, or switch to a longer time window.</p>
  </div>
<?php else: ?>
  <?php foreach ($articles as $a): ?>
    <article class="article-card<?= ($a['watch_match'] ?? false) ? ' article-card--highlight' : '' ?><?= ($a['read'] ?? false) ? ' article-card--read' : '' ?>"
      data-article-id="<?= (int) ($a['id'] ?? 0) ?>"
      <?= !empty($a['source_language']) ? 'lang="' . Html::e((string) $a['source_language']) . '"' : '' ?>>
      <div class="article-meta">
        <span class="source-badge" data-badge-color="<?= Html::e($a['color'] ?? '#909090') ?>">
          <?= Html::e($a['source_name']) ?>
        </span>
        <?php if (!empty($a['category'])): ?>
          <span class="article-cat"><?= Html::e($a['category']) ?></span>
        <?php endif; ?>
        <?php if (($canBookmarkToKioju ?? false) === true): ?>
          <form method="post" action="/bookmark" class="article-bookmark-form">
            <input type="hidden" name="_csrf" value="<?= Html::e(Csrf::token()) ?>">
            <input type="hidden" name="url" value="<?= Html::e($a['url']) ?>">
            <input type="hidden" name="title" value="<?= Html::e($a['title']) ?>">
            <input type="hidden" name="origin" value="feed">
            <button type="submit" class="btn btn-secondary btn-sm">Add to Kioju</button>
          </form>
        <?php endif; ?>
        <button type="button"
          class="star-btn<?= ($a['starred'] ?? false) ? ' star-btn--active' : '' ?>"
          data-article-id="<?= (int) ($a['id'] ?? 0) ?>"
          aria-label="<?= ($a['starred'] ?? false) ? 'Unstar article' : 'Star article' ?>">
          <svg class="star-icon" width="14" height="14" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
          </svg>
        </button>
        <?php if (!empty($a['published_at'])): ?>
          <time class="article-time" datetime="<?= Html::e($a['published_at']) ?>"
            title="<?= Html::e($a['published_at']) ?>">
            <?= relativeTime($a['published_at']) ?>
          </time>
        <?php endif; ?>
      </div>
      <h3 class="article-title">
        <a href="<?= Html::e($a['url']) ?>" target="_blank" rel="noopener noreferrer nofollow">
          <?= Html::e($a['title']) ?>
        </a>
      </h3>
      <?php if (!empty($a['summary'])): ?>
        <p class="article-summary"><?= Html::e(Html::sanitizeSummary((string) $a['summary'], 220)) ?></p>
      <?php endif; ?>
      <p class="article-attribution">
        <?= Html::e($a['attribution_text']) ?>
        <?php if (!empty($a['also_by'])): ?>
          <span class="article-also-by">· Also: <?= Html::e(implode(', ', $a['also_by'])) ?><?php if (!empty($a['also_by_omitted'])): ?> +<?= (int) $a['also_by_omitted'] ?> more<?php endif; ?></span>
        <?php endif; ?>
      </p>
    </article>
  <?php endforeach; ?>
<?php endif; ?>
<?php if ($totalPages > 1 && $paginationBase !== null): ?>
  <nav class="feed-pagination" aria-label="Page navigation">
    <?php if ($page > 1): ?>
      <a href="<?= Html::e($paginationBase . 1) ?>" class="btn btn-secondary btn-sm">First</a>
      <a href="<?= Html::e($paginationBase . ($page - 1)) ?>" class="btn btn-secondary btn-sm">&larr; Prev</a>
    <?php endif; ?>
    <span class="feed-pagination-info">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?>
      <a href="<?= Html::e($paginationBase . ($page + 1)) ?>" class="btn btn-secondary btn-sm">Next &rarr;</a>
      <a href="<?= Html::e($paginationBase . $totalPages) ?>" class="btn btn-secondary btn-sm">Last</a>
    <?php endif; ?>
  </nav>
<?php endif; ?>
