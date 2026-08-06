<?php

declare(strict_types=1);

use Daybreak\Security\Html;
use Daybreak\Security\Csrf;

$formErrors   = $formErrors   ?? [];
$notification = $notification ?? ['message' => '', 'is_active' => 0];
?>
<div class="admin-page-header">
  <h1 class="admin-page-title">Site notification</h1>
</div>

<?php if (!empty($formErrors)): ?>
  <div class="flash-wrap flash-wrap--form">
    <?php foreach ($formErrors as $error): ?>
      <div class="flash flash-error"><?= Html::e((string) $error) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="settings-page settings-page--wide">

  <form method="post" action="/admin/notification">
    <input type="hidden" name="_csrf" value="<?= Html::e(Csrf::token()) ?>">

    <div class="settings-section">
      <h2 class="settings-section-title">Banner message</h2>
      <p class="text-sm text-secondary">
        Shown at the top of every feed page for all visitors, until you deactivate it.
        Visitors can dismiss it for their own browser; it reappears there if you edit
        the message again.
      </p>
      <div class="form-group">
        <label class="form-label" for="notice-message">Message</label>
        <textarea id="notice-message" class="form-input" name="message" rows="4"
          maxlength="500"><?= Html::e((string) $notification['message']) ?></textarea>
        <p class="form-hint">Plain text only, up to 500 characters. Line breaks are preserved.</p>
      </div>
      <div class="form-group">
        <label class="remember-me-label">
          <input type="checkbox" name="is_active" value="1" <?= !empty($notification['is_active']) ? 'checked' : '' ?>>
          Active — show this banner to visitors
        </label>
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save</button>
    </div>
  </form>

</div>
