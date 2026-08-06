<?php

declare(strict_types=1);

/** Daybreak front controller. Apache DocumentRoot points here. */

require __DIR__ . '/../src/bootstrap.php';

use Daybreak\Router;
use Daybreak\Security\DbSessionHandler;
use Daybreak\Security\SecurityHeaders;
use Daybreak\Controller\AdminController;
use Daybreak\Controller\AuthController;
use Daybreak\Controller\BookmarkController;
use Daybreak\Controller\FeedController;
use Daybreak\Controller\PageController;
use Daybreak\Controller\PublicController;
use Daybreak\Controller\SearchController;
use Daybreak\Controller\SuggestController;
use Daybreak\Controller\StarController;
use Daybreak\Controller\UserController;
use Daybreak\Controller\WebhookController;

// DB-backed session handler must be registered before session_start().
session_set_save_handler(new DbSessionHandler(), true);

session_set_cookie_params([
    'lifetime' => 0,
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => ($_SERVER['HTTPS'] ?? '') === 'on',
]);
session_start();
\Daybreak\Service\AuthService::resolveRememberCookie();

SecurityHeaders::send();

$router = new Router();

// ── Public feed ────────────────────────────────────────────────────────────────
$router->get('/',                        [PublicController::class, 'home']);
$router->get('/category/{slug}',         [PublicController::class, 'home']);
$router->get('/public',                  [PublicController::class, 'home']);
$router->get('/public/category/{slug}',  [PublicController::class, 'home']);
$router->get('/sources',                 [PublicController::class, 'sources']);

// ── Registration ────────────────────────────────────────────────────────────────
$router->get('/register',               [AuthController::class,  'showRegister']);
$router->post('/register',               [AuthController::class,  'handleRegister']);

// ── Email verification ─────────────────────────────────────────────────────────
$router->get('/verify/{token}',          [AuthController::class,  'verify']);

// ── Login / logout ─────────────────────────────────────────────────────────────
$router->get('/login',                  [AuthController::class,  'showLogin']);
$router->post('/login',                  [AuthController::class,  'handleLogin']);
$router->post('/logout',                 [AuthController::class,  'logout']);

// ── Password reset ─────────────────────────────────────────────────────────────
$router->get('/password/forgot',        [AuthController::class,  'showForgot']);
$router->post('/password/forgot',        [AuthController::class,  'handleForgot']);
$router->get('/password/reset/{token}', [AuthController::class,  'showReset']);
$router->post('/password/reset/{token}', [AuthController::class,  'handleReset']);

// ── Starred articles (auth required) ──────────────────────────────────────────
$router->get('/starred',                   [StarController::class,  'list']);
$router->post('/star',                     [StarController::class,  'toggle']);

// ── Read tracking (auth required) ─────────────────────────────────────────────
$router->post('/read',                     [FeedController::class,  'markRead']);

// ── Personal RSS (token auth, no session required) ────────────────────────────
$router->get('/feed/rss',                  [FeedController::class,  'rss']);

// ── Personalised feed (auth required) ─────────────────────────────────────────
$router->get('/feed',                      [FeedController::class,  'feed']);
$router->get('/feed/category/{slug}',      [FeedController::class,  'feed']);
$router->post('/feed/mark-seen',           [FeedController::class,  'markSeen']);

// ── Account settings (auth required) ──────────────────────────────────────────
$router->get('/settings/account',         [UserController::class,  'showAccount']);
$router->post('/settings/account',         [UserController::class,  'handleAccount']);
$router->post('/settings/account/delete',  [UserController::class,  'deleteAccount']);
$router->get('/settings/security',        [UserController::class,  'showSecurity']);
$router->post('/settings/security',        [UserController::class,  'handleSecurity']);
$router->get('/settings/export',          [UserController::class,  'export']);

// ── Source preferences (auth required) ────────────────────────────────────────
$router->get('/settings/sources',         [UserController::class,  'showSources']);
$router->post('/settings/sources',         [UserController::class,  'handleSources']);
$router->get('/settings/widgets',         [UserController::class,  'showWidgets']);
$router->post('/settings/widgets',         [UserController::class,  'handleWidgets']);

// ── Watch terms (auth required) ───────────────────────────────────────────────
$router->get('/settings/watch',           [UserController::class,     'showWatch']);
$router->post('/settings/watch',           [UserController::class,     'handleWatch']);

// ── Webhooks (auth required) ──────────────────────────────────────────────────
$router->get('/settings/webhooks',            [WebhookController::class, 'showWebhooks']);
$router->post('/settings/webhooks',            [WebhookController::class, 'handleCreate']);
$router->post('/settings/webhooks/{id}',       [WebhookController::class, 'handleUpdate']);

// ── Source suggestions ────────────────────────────────────────────────────────
$router->get('/suggest',              [SuggestController::class, 'show']);
$router->post('/suggest',              [SuggestController::class, 'handle']);

// ── Search ────────────────────────────────────────────────────────────────────
$router->get('/search',                [SearchController::class, 'search']);

// ── Bookmark sync (auth required) ─────────────────────────────────────────────
$router->post('/bookmark',             [BookmarkController::class, 'save']);

// ── Admin panel (admin role required) ─────────────────────────────────────────
$router->get('/admin',                          [AdminController::class, 'dashboard']);
$router->get('/admin/sources',                  [AdminController::class, 'sourcesList']);
$router->get('/admin/sources/create',           [AdminController::class, 'sourceCreate']);
$router->post('/admin/sources/create',           [AdminController::class, 'handleSourceCreate']);
$router->get('/admin/sources/import-opml',      [AdminController::class, 'showOpmlImport']);
$router->post('/admin/sources/import-opml',      [AdminController::class, 'handleOpmlImport']);
$router->get('/admin/sources/{id}',             [AdminController::class, 'sourceEdit']);
$router->post('/admin/sources/{id}',             [AdminController::class, 'handleSourceEdit']);
$router->post('/admin/sources/{id}/fetch',       [AdminController::class, 'sourceFetch']);
$router->get('/admin/suggestions',              [AdminController::class, 'suggestionsList']);
$router->post('/admin/suggestions/{id}',         [AdminController::class, 'handleSuggestion']);
$router->get('/admin/users',                    [AdminController::class, 'usersList']);
$router->post('/admin/users/{id}',               [AdminController::class, 'handleUser']);
$router->get('/admin/audit',                    [AdminController::class, 'auditList']);
$router->get('/admin/categories',               [AdminController::class, 'categoriesList']);
$router->get('/admin/categories/create',        [AdminController::class, 'categoryCreate']);
$router->post('/admin/categories/create',        [AdminController::class, 'handleCategoryCreate']);
$router->get('/admin/categories/{id}',          [AdminController::class, 'categoryEdit']);
$router->post('/admin/categories/{id}',          [AdminController::class, 'handleCategoryEdit']);
$router->get('/admin/notification',             [AdminController::class, 'notificationEdit']);
$router->post('/admin/notification',             [AdminController::class, 'handleNotificationSave']);

// ── Legal / static pages ───────────────────────────────────────────────────────
$router->get('/about',   [PageController::class, 'about']);
$router->get('/accessibility', [PageController::class, 'accessibility']);
$router->get('/imprint', [PageController::class, 'imprint']);
$router->get('/terms',   [PageController::class, 'terms']);
$router->get('/privacy', [PageController::class, 'privacy']);
$router->get('/robots.txt', [PageController::class, 'robots']);
$router->get('/sitemap.xml', [PageController::class, 'sitemap']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$isHead = $method === 'HEAD';
if ($isHead) {
    $method = 'GET';
    ob_start();
}

try {
    $router->dispatch($method, $path);
} catch (\Throwable $e) {
    $status = http_response_code();
    if ($status < 400) {
        $status = 500;
        http_response_code($status);
    }

    if ($status >= 500) {
        error_log('[daybreak] ' . $e->getMessage());
        echo 'Internal error';
    } elseif ($status === 419) {
        echo 'Request expired. Please retry.';
    } else {
        echo 'Request failed.';
    }
}

if ($isHead) {
    ob_end_clean();
}
