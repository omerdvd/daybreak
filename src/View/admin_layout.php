<?php

declare(strict_types=1);

use Daybreak\Security\Html;
use Daybreak\Security\Csrf;
use Daybreak\Service\AuthService;
use Daybreak\Config;

$_flash    = $_SESSION['flash']       ?? null;
$_flashErr = $_SESSION['flash_error'] ?? null;
if ($_flash) {
  unset($_SESSION['flash']);
}
if ($_flashErr) {
  unset($_SESSION['flash_error']);
}

$_currentUser = AuthService::currentUser();
$_adminNav    = $adminNav ?? '';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$canonicalPath = (string) ($canonicalPath ?? $requestPath);
$scheme = (($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
$configuredBaseUrl = '';
$configuredRaw = trim((string) (Config::get('APP_URL', '') ?? ''));
if ($configuredRaw !== '') {
  $parsedConfigured = parse_url($configuredRaw);
  if (
    is_array($parsedConfigured)
    && in_array((string) ($parsedConfigured['scheme'] ?? ''), ['http', 'https'], true)
    && isset($parsedConfigured['host'])
    && is_string($parsedConfigured['host'])
    && preg_match('/\A[a-z0-9.-]+\z/i', $parsedConfigured['host']) === 1
  ) {
    $configuredBaseUrl = (string) $parsedConfigured['scheme'] . '://' . (string) $parsedConfigured['host'];
    if (isset($parsedConfigured['port'])) {
      $configuredBaseUrl .= ':' . (int) $parsedConfigured['port'];
    }
  }
}

$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$isValidHost = preg_match('/\A[a-z0-9.-]+(?::\d{1,5})?\z/i', $host) === 1;
$siteBaseUrl = $configuredBaseUrl !== ''
  ? $configuredBaseUrl
  : (($isValidHost) ? ($scheme . '://' . $host) : '');
$canonicalUrl = $siteBaseUrl !== '' ? $siteBaseUrl . $canonicalPath : $canonicalPath;
$socialImagePath = '/assets/images/daybreak-logo.png';
$socialImageUrl = $siteBaseUrl !== '' ? $siteBaseUrl . $socialImagePath : $socialImagePath;

$seoTitle = (string) ($seoTitle ?? (($title ?? 'Admin') . ' · Daybreak'));
$seoDescription = (string) ($seoDescription ?? 'Administrative pages for Daybreak.');
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="<?= Html::e(Csrf::token()) ?>">
  <meta name="description" content="<?= Html::e($seoDescription) ?>">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <link rel="canonical" href="<?= Html::e($canonicalUrl) ?>">
  <meta property="og:title" content="<?= Html::e($seoTitle) ?>">
  <meta property="og:description" content="<?= Html::e($seoDescription) ?>">
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?= Html::e($canonicalUrl) ?>">
  <meta property="og:site_name" content="Daybreak">
  <meta property="og:image" content="<?= Html::e($socialImageUrl) ?>">
  <meta property="og:image:width" content="1434">
  <meta property="og:image:height" content="478">
  <meta property="og:image:alt" content="Daybreak logo">
  <meta name="twitter:card" content="summary">
  <meta name="twitter:title" content="<?= Html::e($seoTitle) ?>">
  <meta name="twitter:description" content="<?= Html::e($seoDescription) ?>">
  <meta name="twitter:image" content="<?= Html::e($socialImageUrl) ?>">
  <title><?= Html::e($title ?? 'Admin') ?> · Daybreak</title>
  <script nonce="<?= Html::e(\Daybreak\Security\SecurityHeaders::nonce()) ?>">
    (function() {
      var s = localStorage.getItem('daybreak-theme');
      var d = s === 'dark' || (s !== 'light' && window.matchMedia('(prefers-color-scheme:dark)').matches);
      document.documentElement.setAttribute('data-theme', d ? 'dark' : 'light');
    }());
  </script>
  <link rel="stylesheet" href="/assets/css/app.css">
  <script src="/assets/js/app.js" defer></script>
</head>

<body class="admin-body">

  <a class="skip-link" href="#main-content">Skip to main content</a>

  <header class="site-header">
    <div class="site-header-inner">
      <a href="/" class="logo" aria-label="Daybreak home">
        <img src="<?= Html::e($socialImagePath) ?>" alt="Daybreak" class="logo-image">
      </a>
      <nav class="site-nav" aria-label="User navigation">
        <a href="/" class="site-nav-link">View Site</a>
        <a href="/settings/account" class="site-nav-link"><?= Html::e($_currentUser['display_name'] ?? '') ?></a>
        <button type="button" id="theme-toggle" class="theme-toggle" aria-label="Switch colour theme" aria-pressed="false">
          <svg class="theme-toggle-icon theme-toggle-icon--moon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
          </svg>
          <svg class="theme-toggle-icon theme-toggle-icon--sun" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="5" />
            <line x1="12" y1="1" x2="12" y2="3" />
            <line x1="12" y1="21" x2="12" y2="23" />
            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" />
            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" />
            <line x1="1" y1="12" x2="3" y2="12" />
            <line x1="21" y1="12" x2="23" y2="12" />
            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" />
            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" />
          </svg>
        </button>
        <form method="post" action="/logout" class="site-nav-logout">
          <input type="hidden" name="_csrf" value="<?= Html::e(Csrf::token()) ?>">
          <button type="submit" class="site-nav-btn">Sign out</button>
        </form>
      </nav>
    </div>
  </header>

  <div class="admin-subnav">
    <div class="admin-subnav-inner">
      <a href="/admin" class="admin-nav-link<?= $_adminNav === 'dashboard'   ? ' is-active' : '' ?>" <?= $_adminNav === 'dashboard' ? ' aria-current="page"' : '' ?>>Dashboard</a>
      <a href="/admin/sources" class="admin-nav-link<?= $_adminNav === 'sources'     ? ' is-active' : '' ?>" <?= $_adminNav === 'sources' ? ' aria-current="page"' : '' ?>>Sources</a>
      <a href="/admin/suggestions" class="admin-nav-link<?= $_adminNav === 'suggestions' ? ' is-active' : '' ?>" <?= $_adminNav === 'suggestions' ? ' aria-current="page"' : '' ?>>Suggestions</a>
      <a href="/admin/users" class="admin-nav-link<?= $_adminNav === 'users'       ? ' is-active' : '' ?>" <?= $_adminNav === 'users' ? ' aria-current="page"' : '' ?>>Users</a>
      <a href="/admin/categories" class="admin-nav-link<?= $_adminNav === 'categories' ? ' is-active' : '' ?>" <?= $_adminNav === 'categories' ? ' aria-current="page"' : '' ?>>Categories</a>
      <a href="/admin/notification" class="admin-nav-link<?= $_adminNav === 'notification' ? ' is-active' : '' ?>" <?= $_adminNav === 'notification' ? ' aria-current="page"' : '' ?>>Site Notification</a>
      <a href="/admin/audit" class="admin-nav-link<?= $_adminNav === 'audit'       ? ' is-active' : '' ?>" <?= $_adminNav === 'audit' ? ' aria-current="page"' : '' ?>>Audit Log</a>
    </div>
  </div>

  <?php if ($_flash || $_flashErr): ?>
    <div class="flash-wrap" aria-live="polite" aria-atomic="true">
      <?php if ($_flash): ?><div class="flash flash-success" role="status"><?= Html::e($_flash) ?></div><?php endif; ?>
      <?php if ($_flashErr): ?><div class="flash flash-error" role="alert"><?= Html::e($_flashErr) ?></div><?php endif; ?>
    </div>
  <?php endif; ?>

  <main id="main-content" class="admin-main" tabindex="-1">
    <div class="admin-content">
