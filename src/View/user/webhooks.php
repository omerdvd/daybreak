<?php

declare(strict_types=1);

use Daybreak\Security\Html;
use Daybreak\Security\Csrf;

/** @var array $webhooks       rows from user_webhooks */
/** @var array $categories     rows of {slug, name} */
/** @var array $activeSources  rows of {slug, name} — active/degraded sources */
/** @var array $recentLog      recent webhook_log rows */
/** @var int   $editingId      webhook id currently being edited, or 0 */
$webhooks      = $webhooks      ?? [];
$categories    = $categories    ?? [];
$activeSources = $activeSources ?? [];
$recentLog     = $recentLog     ?? [];
$editingId     = $editingId     ?? 0;

$formatLabels = ['slack' => 'Slack', 'discord' => 'Discord', 'teams' => 'Microsoft Teams', 'ntfy' => 'ntfy', 'generic' => 'Generic JSON'];
$formatColors = ['slack' => '#4a154b', 'discord' => '#5865f2', 'teams' => '#6264a7', 'ntfy' => '#556b2f'];
?>
<div class="settings-page">

  <section class="settings-section">
    <h2 class="settings-section-title">Webhooks</h2>
    <p class="form-hint u-mb-1">
      Push new articles to Slack, Discord, Microsoft Teams, ntfy, or any HTTP endpoint on every cron tick.
      Filters are optional &mdash; leave all blank to receive all new articles.
      When multiple filter types are set, the article must satisfy all of them.
    </p>

    <?php if ($webhooks !== []): ?>
      <ul class="watch-term-list u-mb-15">
        <?php foreach ($webhooks as $wh):
          $filter   = json_decode($wh['filter_json'] ?? '{}', true) ?? [];
          $terms    = implode(', ', (array) ($filter['terms']      ?? []));
          $cats     = implode(', ', (array) ($filter['categories'] ?? []));
          $srcs     = implode(', ', (array) ($filter['sources']    ?? []));
          $active   = (bool) $wh['active'];
          $fmtLabel = $formatLabels[$wh['format']] ?? $wh['format'];
          $isEditing = ($editingId === (int) $wh['id']);
        ?>
          <li class="watch-term-item webhook-item">
            <div class="webhook-item-header">
              <strong class="webhook-item-name"><?= Html::e($wh['name']) ?></strong>
              <span class="source-badge" data-badge-color="<?= Html::e($formatColors[$wh['format']] ?? '#334155') ?>"><?= Html::e($fmtLabel) ?></span>
              <?php if (!$active): ?><span class="source-badge" data-badge-color="#94a3b8">Paused</span><?php endif; ?>
              <?php if ($isEditing): ?>
                <a href="/settings/webhooks" class="btn btn-secondary btn-sm">Cancel</a>
              <?php else: ?>
                <a href="/settings/webhooks?edit=<?= (int) $wh['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
              <?php endif; ?>
              <form method="post" action="/settings/webhooks/<?= (int) $wh['id'] ?>">
                <input type="hidden" name="_csrf"   value="<?= Html::e(Csrf::token()) ?>">
                <input type="hidden" name="action"  value="toggle">
                <button type="submit" class="btn btn-secondary btn-sm"><?= $active ? 'Pause' : 'Resume' ?></button>
              </form>
              <form method="post" action="/settings/webhooks/<?= (int) $wh['id'] ?>">
                <input type="hidden" name="_csrf"   value="<?= Html::e(Csrf::token()) ?>">
                <input type="hidden" name="action"  value="delete">
                <button type="submit" class="btn btn-secondary btn-sm">Delete</button>
              </form>
            </div>
            <div class="form-hint webhook-item-detail"><?= Html::e(mb_substr($wh['url'], 0, 80)) ?><?= mb_strlen($wh['url']) > 80 ? '…' : '' ?></div>
            <?php if ($terms !== '' || $cats !== '' || $srcs !== ''): ?>
              <div class="form-hint webhook-item-detail">
                <?php if ($terms !== ''): ?>Terms: <strong><?= Html::e($terms) ?></strong><?php endif; ?>
                <?php if ($terms !== '' && $cats !== ''): ?> &middot; <?php endif; ?>
                <?php if ($cats !== ''): ?>Categories: <strong><?= Html::e($cats) ?></strong><?php endif; ?>
                <?php if (($terms !== '' || $cats !== '') && $srcs !== ''): ?> &middot; <?php endif; ?>
                <?php if ($srcs !== ''): ?>Sources: <strong><?= Html::e($srcs) ?></strong><?php endif; ?>
              </div>
            <?php else: ?>
              <div class="form-hint webhook-item-detail">No filter &mdash; receives all new articles</div>
            <?php endif; ?>
            <?php if ($isEditing):
              $editFilterCats = array_flip((array) ($filter['categories'] ?? []));
              $editFilterSrcs = array_flip((array) ($filter['sources']    ?? []));
              $editTerms      = implode(', ', (array) ($filter['terms']   ?? []));
            ?>
              <form method="post" action="/settings/webhooks/<?= (int) $wh['id'] ?>" class="webhook-edit-form">
                <input type="hidden" name="_csrf"  value="<?= Html::e(Csrf::token()) ?>">
                <input type="hidden" name="action" value="edit">

                <div class="form-group">
                  <label class="form-label" for="wh_edit_name">Name</label>
                  <input id="wh_edit_name" class="form-input" type="text"
                    name="name" maxlength="120" required autocomplete="off"
                    value="<?= Html::e($wh['name']) ?>">
                </div>

                <div class="form-group">
                  <label class="form-label" for="wh_edit_url">Webhook URL</label>
                  <input id="wh_edit_url" class="form-input" type="url"
                    name="url" required autocomplete="off"
                    value="<?= Html::e($wh['url']) ?>">
                </div>

                <div class="form-group">
                  <label class="form-label" for="wh_edit_format">Payload format</label>
                  <select id="wh_edit_format" class="form-input" name="format">
                    <?php foreach ($formatLabels as $val => $label): ?>
                      <option value="<?= Html::e($val) ?>"<?= $wh['format'] === $val ? ' selected' : '' ?>><?= Html::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="form-group">
                  <label class="form-label" for="wh_edit_ntfy_token">ntfy access token <span class="label-normal">(optional, ntfy only)</span></label>
                  <input id="wh_edit_ntfy_token" class="form-input" type="password"
                    name="ntfy_token" maxlength="300" autocomplete="off"
                    placeholder="<?= !empty($wh['has_token']) ? 'Token set — leave blank to keep it' : 'tk_… (leave blank for an unprotected topic)' ?>">
                  <p class="form-hint">Bearer token for a protected ntfy topic. Stored encrypted, never re-displayed.</p>
                </div>

                <div class="form-group">
                  <label class="form-label" for="wh_edit_terms">Filter: watch terms <span class="label-normal">(optional)</span></label>
                  <input id="wh_edit_terms" class="form-input" type="text"
                    name="filter_terms" maxlength="1600" autocomplete="off"
                    placeholder="CVE-2025, critical, zero-day, ransomware"
                    value="<?= Html::e($editTerms) ?>">
                  <p class="form-hint">Comma-separated. Article title or summary must contain at least one term (case-insensitive).</p>
                </div>

                <?php if ($categories !== []): ?>
                  <div class="form-group">
                    <span class="form-label">Filter: categories <span class="label-normal">(optional)</span></span>
                    <div class="cat-filter-grid">
                      <?php foreach ($categories as $cat): ?>
                        <label class="cat-filter-label">
                          <input type="checkbox" name="filter_categories[]" value="<?= Html::e($cat['slug']) ?>"<?= isset($editFilterCats[$cat['slug']]) ? ' checked' : '' ?>>
                          <?= Html::e($cat['name']) ?>
                        </label>
                      <?php endforeach; ?>
                    </div>
                    <p class="form-hint">Article source must belong to at least one checked category.</p>
                  </div>
                <?php endif; ?>

                <?php if ($activeSources !== []): ?>
                  <div class="form-group">
                    <span class="form-label">Filter: sources <span class="label-normal">(optional)</span></span>
                    <div class="cat-filter-grid" style="max-height:12rem;overflow-y:auto;">
                      <?php foreach ($activeSources as $src): ?>
                        <label class="cat-filter-label">
                          <input type="checkbox" name="filter_sources[]" value="<?= Html::e($src['slug']) ?>"<?= isset($editFilterSrcs[$src['slug']]) ? ' checked' : '' ?>>
                          <?= Html::e($src['name']) ?>
                        </label>
                      <?php endforeach; ?>
                    </div>
                    <p class="form-hint">Article must come from at least one of the selected sources.</p>
                  </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary">Save changes</button>
                <a href="/settings/webhooks" class="btn btn-secondary">Cancel</a>
              </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="form-hint u-mb-1">No webhooks configured.</p>
    <?php endif; ?>

    <?php if ($editingId === 0 && count($webhooks) < 10): ?>
      <form method="post" action="/settings/webhooks">
        <input type="hidden" name="_csrf" value="<?= Html::e(Csrf::token()) ?>">

        <div class="form-group">
          <label class="form-label" for="wh_name">Name</label>
          <input id="wh_name" class="form-input" type="text" name="name"
            maxlength="120" required autocomplete="off" placeholder="e.g. Security Slack">
        </div>

        <div class="form-group">
          <label class="form-label" for="wh_url">Webhook URL</label>
          <input id="wh_url" class="form-input" type="url" name="url"
            required autocomplete="off" placeholder="https://hooks.slack.com/services/…">
          <p class="form-hint">Slack / Discord / Teams incoming webhook URL, or any HTTPS endpoint.</p>
        </div>

        <div class="form-group">
          <label class="form-label" for="wh_format">Payload format</label>
          <select id="wh_format" class="form-input" name="format">
            <option value="slack">Slack (attachment)</option>
            <option value="discord">Discord (embed)</option>
            <option value="teams">Microsoft Teams (adaptive card)</option>
            <option value="ntfy">ntfy (push notification)</option>
            <option value="generic" selected>Generic JSON</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label" for="wh_ntfy_token">ntfy access token <span class="label-normal">(optional, ntfy only)</span></label>
          <input id="wh_ntfy_token" class="form-input" type="password" name="ntfy_token"
            maxlength="300" autocomplete="off" placeholder="tk_… (leave blank for an unprotected topic)">
          <p class="form-hint">Bearer token for a protected ntfy topic. Stored encrypted, never re-displayed.</p>
        </div>

        <div class="form-group">
          <label class="form-label" for="wh_terms">Filter: watch terms <span class="label-normal">(optional)</span></label>
          <input id="wh_terms" class="form-input" type="text" name="filter_terms"
            maxlength="1600" autocomplete="off" placeholder="CVE-2025, critical, zero-day, ransomware">
          <p class="form-hint">Comma-separated. Article title or summary must contain at least one term (case-insensitive).</p>
        </div>

        <?php if ($categories !== []): ?>
          <div class="form-group">
            <span class="form-label">Filter: categories <span class="label-normal">(optional)</span></span>
            <div class="cat-filter-grid">
              <?php foreach ($categories as $cat): ?>
                <label class="cat-filter-label">
                  <input type="checkbox" name="filter_categories[]" value="<?= Html::e($cat['slug']) ?>">
                  <?= Html::e($cat['name']) ?>
                </label>
              <?php endforeach; ?>
            </div>
            <p class="form-hint">Article source must belong to at least one checked category.</p>
          </div>
        <?php endif; ?>

        <?php if ($activeSources !== []): ?>
          <div class="form-group">
            <span class="form-label">Filter: sources <span class="label-normal">(optional)</span></span>
            <div class="cat-filter-grid" style="max-height:12rem;overflow-y:auto;">
              <?php foreach ($activeSources as $src): ?>
                <label class="cat-filter-label">
                  <input type="checkbox" name="filter_sources[]" value="<?= Html::e($src['slug']) ?>">
                  <?= Html::e($src['name']) ?>
                </label>
              <?php endforeach; ?>
            </div>
            <p class="form-hint">Article must come from at least one of the selected sources.</p>
          </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary">Add webhook</button>
      </form>
    <?php elseif ($editingId === 0): ?>
      <p class="form-hint">Maximum of 10 webhooks reached.</p>
    <?php endif; ?>
  </section>

  <?php if ($recentLog !== []): ?>
  <section class="settings-section settings-section--mt">
    <h2 class="settings-section-title">Recent deliveries</h2>
    <table class="wh-log-table">
      <thead>
        <tr>
          <th>Article</th>
          <th>Status</th>
          <th>When</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recentLog as $row):
          $ok = in_array($row['status'], ['ok', 'retry_ok'], true);
        ?>
          <tr>
            <td class="wh-log-article"><?= Html::e(mb_substr($row['article_title'], 0, 80)) ?></td>
            <td>
              <span class="source-badge" data-badge-color="<?= $ok ? '#16a34a' : '#dc2626' ?>">
                <?= Html::e($row['status']) ?>
                <?php if (!$ok && $row['http_status']): ?>(<?= (int) $row['http_status'] ?>)<?php endif; ?>
              </span>
            </td>
            <td class="wh-log-time"><?= Html::e(substr($row['created_at'], 0, 16)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endif; ?>

</div>
