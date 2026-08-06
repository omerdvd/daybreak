<?php

declare(strict_types=1);

namespace Daybreak\Controller;

use Daybreak\Database;
use Daybreak\Security\Csrf;
use Daybreak\Security\Html;
use Daybreak\Security\SsrfGuard;
use Daybreak\Service\AggregationService;
use Daybreak\Service\AuditLog;
use Daybreak\Service\AuthService;
use Daybreak\Service\FeedFetcher;
use Daybreak\Service\SourcePreviewService;
use Daybreak\Service\WebhookService;

/**
 * Admin panel: source CRUD, suggestion moderation, feed health,
 * user admin, audit log. Every state-changing action writes audit_log.
 * All methods call AuthService::requireAdmin() as first step.
 */
final class AdminController
{
    private const SUPPORTED_ADAPTERS = ['rss_atom', 'json_api', 'ransomlook', 'nvd', 'cisa_kev'];
    private const FAIL_DEGRADE = 3;
    private const FAIL_DISABLE = 8;

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function dashboard(array $args = []): void
    {
        AuthService::requireAdmin();

        $sourceCounts = Database::query(
            "SELECT status, COUNT(*) AS n FROM sources GROUP BY status"
        )->fetchAll();
        $counts = [];
        foreach ($sourceCounts as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }

        $pendingSuggestions = (int) Database::query(
            "SELECT COUNT(*) FROM source_suggestions WHERE status = 'pending'"
        )->fetchColumn();

        $totalArticles = (int) Database::query("SELECT COUNT(*) FROM articles")->fetchColumn();

        $sources = Database::query(
            "SELECT s.id, s.name, s.slug, s.status, s.consecutive_failures,
                    s.last_fetch_at, s.last_success_at, s.last_error, s.next_fetch_at,
                    c.name AS category_name,
                    (SELECT COUNT(*) FROM articles a
                     WHERE a.source_id = s.id
                       AND a.fetched_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS items_today,
                    (SELECT fl.http_status FROM fetch_log fl
                     WHERE fl.source_id = s.id
                     ORDER BY fl.created_at DESC LIMIT 1) AS last_http_status,
                                        (SELECT ROUND(AVG(fl.duration_ms))
                                         FROM fetch_log fl
                                         WHERE fl.source_id = s.id
                                             AND fl.duration_ms IS NOT NULL
                                             AND fl.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS avg_duration_ms,
                                        (SELECT COUNT(*)
                                         FROM fetch_log fl
                                         WHERE fl.source_id = s.id
                                             AND fl.status = 'ok'
                                             AND fl.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS ok_last5,
                                        (SELECT COUNT(*)
                                         FROM fetch_log fl
                                         WHERE fl.source_id = s.id
                                             AND fl.status = 'ok'
                                             AND fl.items_found = 0
                                             AND fl.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS zero_ok_last5
             FROM sources s
             LEFT JOIN source_categories c ON c.id = s.category_id
             ORDER BY s.status DESC, s.consecutive_failures DESC, s.name"
        )->fetchAll();

        $healthSummary = [
            'degraded' => 0,
            'auto_disabled' => 0,
            'zero_yield_trend' => 0,
            'stale_24h' => 0,
        ];

        $now = new \DateTimeImmutable('now');
        foreach ($sources as $src) {
            if ((string) $src['status'] === 'degraded') {
                $healthSummary['degraded']++;
            }
            if ((string) $src['status'] === 'auto_disabled') {
                $healthSummary['auto_disabled']++;
            }

            $okLast5 = (int) ($src['ok_last5'] ?? 0);
            $zeroOkLast5 = (int) ($src['zero_ok_last5'] ?? 0);
            if ($okLast5 >= 3 && $okLast5 === $zeroOkLast5) {
                $healthSummary['zero_yield_trend']++;
            }

            $lastSuccessRaw = $src['last_success_at'] ?? null;
            if (!is_string($lastSuccessRaw) || $lastSuccessRaw === '') {
                $healthSummary['stale_24h']++;
                continue;
            }

            try {
                $lastSuccess = new \DateTimeImmutable($lastSuccessRaw);
                if (($now->getTimestamp() - $lastSuccess->getTimestamp()) > 86400) {
                    $healthSummary['stale_24h']++;
                }
            } catch (\Throwable) {
                $healthSummary['stale_24h']++;
            }
        }

        $topArticles = Database::query(
            "SELECT a.id, a.title, a.url, s.name AS source_name, COUNT(*) AS read_count
             FROM user_article_reads r
             JOIN articles a ON a.id = r.article_id
             JOIN sources s ON s.id = a.source_id
             WHERE r.read_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY a.id, a.title, a.url, s.name
             ORDER BY read_count DESC
             LIMIT 10"
        )->fetchAll();

        $topSources = Database::query(
            "SELECT s.name, COUNT(*) AS read_count
             FROM user_article_reads r
             JOIN articles a ON a.id = r.article_id
             JOIN sources s ON s.id = a.source_id
             WHERE r.read_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY s.id, s.name
             ORDER BY read_count DESC
             LIMIT 10"
        )->fetchAll();

        $title     = 'Admin — Dashboard';
        $adminNav  = 'dashboard';
        include DB_ROOT . '/src/View/admin_layout.php';
        include DB_ROOT . '/src/View/admin/dashboard.php';
        include DB_ROOT . '/src/View/admin_layout_end.php';
    }

    // ── Sources ───────────────────────────────────────────────────────────────

    public function sourcesList(array $args = []): void
    {
        AuthService::requireAdmin();

        $sources = Database::query(
            "SELECT s.id, s.name, s.slug, s.status, s.adapter_type,
                                        s.consecutive_failures, s.last_fetch_at, s.last_success_at, s.last_error,
                                        (SELECT fl.http_status FROM fetch_log fl
                                         WHERE fl.source_id = s.id
                                         ORDER BY fl.created_at DESC LIMIT 1) AS last_http_status,
                                        (SELECT ROUND(AVG(fl.duration_ms))
                                         FROM fetch_log fl
                                         WHERE fl.source_id = s.id
                                             AND fl.duration_ms IS NOT NULL
                                             AND fl.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS avg_duration_ms,
                                        (SELECT COUNT(*)
                                         FROM fetch_log fl
                                         WHERE fl.source_id = s.id
                                             AND fl.status = 'ok'
                                             AND fl.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS ok_last5,
                                        (SELECT COUNT(*)
                                         FROM fetch_log fl
                                         WHERE fl.source_id = s.id
                                             AND fl.status = 'ok'
                                             AND fl.items_found = 0
                                             AND fl.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS zero_ok_last5,
                    c.name AS category_name
             FROM sources s
             LEFT JOIN source_categories c ON c.id = s.category_id
             ORDER BY c.sort_order, s.name"
        )->fetchAll();

        $title    = 'Admin — Sources';
        $adminNav = 'sources';
        include DB_ROOT . '/src/View/admin_layout.php';
        include DB_ROOT . '/src/View/admin/sources/list.php';
        include DB_ROOT . '/src/View/admin_layout_end.php';
    }

    public function sourceCreate(array $args = []): void
    {
        AuthService::requireAdmin();
        $categories = Database::query('SELECT id, name FROM source_categories ORDER BY sort_order')->fetchAll();
        $source     = null; // null = create mode
        $title      = 'Admin — New source';
        $adminNav   = 'sources';
        include DB_ROOT . '/src/View/admin_layout.php';
        include DB_ROOT . '/src/View/admin/sources/edit.php';
        include DB_ROOT . '/src/View/admin_layout_end.php';
    }

    public function handleSourceCreate(array $args = []): void
    {
        AuthService::requireAdmin();
        Csrf::check();

        $action = (string) ($_POST['action'] ?? 'save');
        if ($action === 'preview') {
            $this->renderSourceFormPreview(null, $_POST);
            return;
        }

        [$ok, $err, $id] = $this->saveSource(null, $_POST);
        if (!$ok) {
            $_SESSION['flash_error'] = $err;
            header('Location: /admin/sources/create');
            exit;
        }
        AuditLog::write('source.create', 'source', (string) $id);
        $_SESSION['flash'] = 'Source created.';
        header('Location: /admin/sources/' . $id);
        exit;
    }

    public function sourceEdit(array $args = []): void
    {
        AuthService::requireAdmin();
        $source = $this->requireSource((int) ($args['id'] ?? 0));

        $categories   = Database::query('SELECT id, name FROM source_categories ORDER BY sort_order')->fetchAll();
        $recentLog    = Database::query(
            'SELECT status, http_status, items_found, items_new, duration_ms, error, created_at
             FROM fetch_log WHERE source_id = ? ORDER BY created_at DESC LIMIT 10',
            [(int) $source['id']]
        )->fetchAll();
        $articleCount = (int) Database::query('SELECT COUNT(*) FROM articles WHERE source_id = ?', [(int) $source['id']])->fetchColumn();
        $effectiveUa  = FeedFetcher::resolveUa((string) ($source['user_agent_override'] ?? ''));
        $debugResult  = null;

        $title    = 'Admin — Edit source';
        $adminNav = 'sources';
        include DB_ROOT . '/src/View/admin_layout.php';
        include DB_ROOT . '/src/View/admin/sources/edit.php';
        include DB_ROOT . '/src/View/admin_layout_end.php';
    }

    public function handleSourceEdit(array $args = []): void
    {
        AuthService::requireAdmin();
        Csrf::check();

        $source = $this->requireSource((int) ($args['id'] ?? 0));
        $id     = (int) $source['id'];
        $action = $_POST['action'] ?? 'save';

        switch ($action) {
            case 'save':
                [$ok, $err] = $this->saveSource($id, $_POST);
                if (!$ok) {
                    $_SESSION['flash_error'] = $err;
                } else {
                    AuditLog::write('source.edit', 'source', (string) $id);
                    $_SESSION['flash'] = 'Source saved.';
                }
                break;

            case 'preview':
                $this->renderSourceFormPreview($source, $_POST);
                return;

            case 'debug_fetch':
                $this->renderSourceFormDebug($source);
                return;

            case 'enable':
                Database::query("UPDATE sources SET status = 'active', consecutive_failures = 0 WHERE id = ?", [$id]);
                AuditLog::write('source.enable', 'source', (string) $id);
                $_SESSION['flash'] = 'Source enabled.';
                break;

            case 'disable':
                Database::query("UPDATE sources SET status = 'disabled' WHERE id = ?", [$id]);
                AuditLog::write('source.disable', 'source', (string) $id);
                $_SESSION['flash'] = 'Source disabled.';
                break;

            case 'reset':
                Database::query(
                    "UPDATE sources SET consecutive_failures = 0, last_error = NULL,
                     status = IF(status = 'auto_disabled', 'active', status)
                     WHERE id = ?",
                    [$id]
                );
                AuditLog::write('source.reset_failures', 'source', (string) $id);
                $_SESSION['flash'] = 'Failure counter reset.';
                break;

            case 'delete':
                $name = $source['name'];
                Database::query('DELETE FROM sources WHERE id = ?', [$id]);
                AuditLog::write('source.delete', 'source', $name);
                $_SESSION['flash'] = "Source \"{$name}\" deleted.";
                header('Location: /admin/sources');
                exit;
        }

        header('Location: /admin/sources/' . $id);
        exit;
    }

    public function sourceFetch(array $args = []): void
    {
        AuthService::requireAdmin();
        Csrf::check();

        $source = $this->requireSource((int) ($args['id'] ?? 0));
        $id     = (int) $source['id'];

        $svc = new AggregationService(new FeedFetcher(), new WebhookService(new FeedFetcher()));
        // Reload fresh from DB before running (source may have just been reset).
        $fresh = Database::query('SELECT * FROM sources WHERE id = ?', [$id])->fetch();
        $ok    = $fresh ? $svc->runSource($fresh) : false;

        AuditLog::write('source.fetch_now', 'source', (string) $id);
        $_SESSION[$ok ? 'flash' : 'flash_error'] = $ok ? 'Fetch completed.' : 'Fetch failed — check error column.';
        header('Location: /admin/sources/' . $id);
        exit;
    }

    // ── Suggestions ───────────────────────────────────────────────────────────

    public function suggestionsList(array $args = []): void
    {
        AuthService::requireAdmin();
        AuditLog::write('suggestions.view', 'suggestions', '');

        $suggestions = Database::query(
            "SELECT ss.*, u.display_name AS suggester_name, rv.display_name AS reviewer_name
             FROM source_suggestions ss
             LEFT JOIN users u  ON u.id  = ss.suggested_by
             LEFT JOIN users rv ON rv.id = ss.reviewed_by
             ORDER BY ss.status = 'pending' DESC, ss.created_at DESC"
        )->fetchAll();

        $categories = Database::query('SELECT id, name FROM source_categories ORDER BY sort_order')->fetchAll();

        $title    = 'Admin — Suggestions';
        $adminNav = 'suggestions';
        include DB_ROOT . '/src/View/admin_layout.php';
        include DB_ROOT . '/src/View/admin/suggestions/list.php';
        include DB_ROOT . '/src/View/admin_layout_end.php';
    }

    public function handleSuggestion(array $args = []): void
    {
        AuthService::requireAdmin();
        Csrf::check();

        $sgId   = (int) ($args['id'] ?? 0);
        $sg     = Database::query('SELECT * FROM source_suggestions WHERE id = ?', [$sgId])->fetch();
        if (!$sg) {
            http_response_code(404);
            echo 'Not found';
            exit;
        }

        $admin  = AuthService::currentUser();
        $action = $_POST['action'] ?? '';

        if ($action === 'approve') {
            // Create a pending source from suggestion data.
            $catId = ($_POST['category_id'] ?? '') !== '' ? (int) $_POST['category_id'] : null;
            $slug  = $this->makeSlug((string) $sg['name']);
            Database::query(
                'INSERT INTO sources
                 (name, slug, homepage_url, feed_url, adapter_type, category_id,
                  attribution_text, status, fetch_interval_min, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [
                    $sg['name'],
                    $slug,
                    $sg['homepage_url'],
                    $sg['feed_url'] ?: null,
                    $sg['detected_adapter'] ?: 'rss_atom',
                    $catId,
                    $sg['name'],           // attribution placeholder — admin can edit
                    'pending',
                    15,
                    (int) $admin['id'],
                ]
            );
            $newSourceId = (int) Database::lastInsertId();
            Database::query(
                "UPDATE source_suggestions SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?",
                [(int) $admin['id'], $sgId]
            );
            AuditLog::write('suggestion.approve', 'suggestion', (string) $sgId);
            $_SESSION['flash'] = 'Suggestion approved — source created in pending status. Edit and enable it under Sources.';
            header('Location: /admin/sources/' . $newSourceId);
            exit;
        }

        if ($action === 'reject') {
            $note = mb_substr(trim($_POST['review_note'] ?? ''), 0, 500);
            Database::query(
                "UPDATE source_suggestions SET status = 'rejected', reviewed_by = ?,
                 reviewed_at = NOW(), review_note = ? WHERE id = ?",
                [(int) $admin['id'], $note ?: null, $sgId]
            );
            AuditLog::write('suggestion.reject', 'suggestion', (string) $sgId);
            $_SESSION['flash'] = 'Suggestion rejected.';
        }

        header('Location: /admin/suggestions');
        exit;
    }

    // ── Users ─────────────────────────────────────────────────────────────────

    public function usersList(array $args = []): void
    {
        AuthService::requireAdmin();
        AuditLog::write('users.view', 'users', '');

        $users = Database::query(
            "SELECT id, email, display_name, role, status, last_login_at, last_seen_at, created_at
             FROM users ORDER BY created_at DESC"
        )->fetchAll();

        $title    = 'Admin — Users';
        $adminNav = 'users';
        include DB_ROOT . '/src/View/admin_layout.php';
        include DB_ROOT . '/src/View/admin/users/list.php';
        include DB_ROOT . '/src/View/admin_layout_end.php';
    }

    public function handleUser(array $args = []): void
    {
        AuthService::requireAdmin();
        Csrf::check();

        $targetId = (int) ($args['id'] ?? 0);
        $me       = AuthService::currentUser();
        if ($targetId === (int) $me['id']) {
            $_SESSION['flash_error'] = 'You cannot modify your own account from the admin panel.';
            header('Location: /admin/users');
            exit;
        }

        $target = Database::query('SELECT id, email, display_name FROM users WHERE id = ?', [$targetId])->fetch();
        if (!$target) {
            http_response_code(404);
            echo 'Not found';
            exit;
        }

        $action = $_POST['action'] ?? '';
        switch ($action) {
            case 'disable':
                Database::query("UPDATE users SET status = 'disabled' WHERE id = ?", [$targetId]);
                AuditLog::write('user.disable', 'user', (string) $targetId);
                $_SESSION['flash'] = 'Account disabled.';
                break;

            case 'enable':
                Database::query("UPDATE users SET status = 'active' WHERE id = ?", [$targetId]);
                AuditLog::write('user.enable', 'user', (string) $targetId);
                $_SESSION['flash'] = 'Account enabled.';
                break;

            case 'promote':
                Database::query("UPDATE users SET role = 'admin' WHERE id = ?", [$targetId]);
                AuditLog::write('user.promote', 'user', (string) $targetId);
                $_SESSION['flash'] = 'User promoted to admin.';
                break;

            case 'demote':
                Database::query("UPDATE users SET role = 'user' WHERE id = ?", [$targetId]);
                AuditLog::write('user.demote', 'user', (string) $targetId);
                $_SESSION['flash'] = 'Admin demoted to user.';
                break;

            case 'delete':
                $email = $target['email'];
                Database::query('DELETE FROM users WHERE id = ?', [$targetId]);
                AuditLog::write('user.delete', 'user', $email);
                $_SESSION['flash'] = "Account {$email} deleted.";
                break;
        }

        header('Location: /admin/users');
        exit;
    }

    // ── Audit log ─────────────────────────────────────────────────────────────

    public function auditList(array $args = []): void
    {
        AuthService::requireAdmin();
        AuditLog::write('audit.view', 'audit', '');

        $entries = Database::query(
            "SELECT al.id, al.action, al.target_type, al.target_id, al.created_at,
                    u.display_name AS actor
             FROM audit_log al
             LEFT JOIN users u ON u.id = al.user_id
             ORDER BY al.created_at DESC
             LIMIT 200"
        )->fetchAll();

        $title    = 'Admin — Audit log';
        $adminNav = 'audit';
        include DB_ROOT . '/src/View/admin_layout.php';
        include DB_ROOT . '/src/View/admin/audit/list.php';
        include DB_ROOT . '/src/View/admin_layout_end.php';
    }

    // ── OPML import ───────────────────────────────────────────────────────────

    public function showOpmlImport(array $args = []): void
    {
        AuthService::requireAdmin();
        $title    = 'Admin — Import OPML';
        $adminNav = 'sources';
        include DB_ROOT . '/src/View/admin_layout.php';
        include DB_ROOT . '/src/View/admin/sources/import_opml.php';
        include DB_ROOT . '/src/View/admin_layout_end.php';
    }

    public function handleOpmlImport(array $args = []): void
    {
        AuthService::requireAdmin();
        Csrf::check();

        $upload = $_FILES['opml'] ?? null;
        if (!is_array($upload) || ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE)) !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'No file uploaded or upload error.';
            header('Location: /admin/sources/import-opml');
            exit;
        }

        $ext = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['opml', 'xml'], true)) {
            $_SESSION['flash_error'] = 'File must have an .opml or .xml extension.';
            header('Location: /admin/sources/import-opml');
            exit;
        }

        if ((int) ($upload['size'] ?? 0) > 2 * 1024 * 1024) {
            $_SESSION['flash_error'] = 'File too large (max 2 MB).';
            header('Location: /admin/sources/import-opml');
            exit;
        }

        $tmpDir  = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $tmpFile = realpath((string) ($upload['tmp_name'] ?? '')) ?: '';
        if ($tmpFile === '' || !str_starts_with($tmpFile, $tmpDir . DIRECTORY_SEPARATOR)) {
            $_SESSION['flash_error'] = 'Invalid upload path.';
            header('Location: /admin/sources/import-opml');
            exit;
        }

        $content = file_get_contents($tmpFile);
        if ($content === false || $content === '') {
            $_SESSION['flash_error'] = 'Could not read uploaded file.';
            header('Location: /admin/sources/import-opml');
            exit;
        }

        // External entity loading is disabled by default in PHP 8.0+; LIBXML_NONET blocks network access.
        $xml = @simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($xml === false || strtolower($xml->getName()) !== 'opml') {
            $_SESSION['flash_error'] = 'File does not appear to be a valid OPML document.';
            header('Location: /admin/sources/import-opml');
            exit;
        }

        $outlines  = $this->extractOpmlOutlines($xml);
        $truncated = false;
        if (count($outlines) > 500) {
            $outlines  = array_slice($outlines, 0, 500);
            $truncated = true;
        }

        $admin          = AuthService::currentUser();
        $created        = 0;
        $skippedDup     = 0;
        $skippedInvalid = 0;
        $usedSlugs      = [];

        foreach ($outlines as $entry) {
            $xmlUrl  = trim((string) ($entry['xmlUrl'] ?? ''));
            $htmlUrl = trim((string) ($entry['htmlUrl'] ?? ''));
            $text    = trim((string) ($entry['text'] ?? ''));

            if ($xmlUrl === '' || !$this->isHttpUrl($xmlUrl)) {
                $skippedInvalid++;
                continue;
            }

            try {
                SsrfGuard::assertSafe($xmlUrl);
            } catch (\RuntimeException) {
                $skippedInvalid++;
                continue;
            }

            $existing = Database::query('SELECT id FROM sources WHERE feed_url = ? LIMIT 1', [$xmlUrl])->fetch();
            if ($existing) {
                $skippedDup++;
                continue;
            }

            $name        = $text !== '' ? mb_substr($text, 0, 120) : mb_substr((string) (parse_url($xmlUrl, PHP_URL_HOST) ?: $xmlUrl), 0, 120);
            $homepageUrl = ($htmlUrl !== '' && $this->isHttpUrl($htmlUrl)) ? $htmlUrl : $xmlUrl;
            $slug        = $this->generateUniqueSlug($name, $usedSlugs);

            Database::query(
                'INSERT INTO sources
                 (name, slug, homepage_url, feed_url, adapter_type, category_id,
                  attribution_text, status, fetch_interval_min, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$name, $slug, $homepageUrl, $xmlUrl, 'rss_atom', null, $name, 'pending', 15, (int) $admin['id']]
            );
            $created++;
        }

        AuditLog::write('source.opml_import', 'source', "created:{$created} dup:{$skippedDup} invalid:{$skippedInvalid}");

        $msg = "OPML import complete: {$created} added, {$skippedDup} skipped (duplicate), {$skippedInvalid} skipped (invalid/blocked URL).";
        if ($truncated) {
            $msg .= ' File was truncated to 500 entries.';
        }
        $_SESSION['flash'] = $msg;
        header('Location: /admin/sources');
        exit;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function requireSource(int $id): array
    {
        $src = Database::query('SELECT * FROM sources WHERE id = ?', [$id])->fetch();
        if (!$src) {
            http_response_code(404);
            echo '<!doctype html><meta charset="utf-8"><title>Not Found</title><h1>Source not found</h1>';
            exit;
        }
        return $src;
    }

    /**
     * Validate and upsert a source row.
     * @return array{0:bool, 1:string, 2:int} [success, errorMessage, insertedId]
     */
    private function saveSource(?int $id, array $post): array
    {
        $input = $this->normalizeSourceInput($post);
        $errors = $this->validateSourceInput($input);
        if ($errors !== []) {
            return [false, $errors[0], 0];
        }

        [$fieldMapJson, $fieldMapError] = $this->normalizeFieldMapForStorage((string) $input['field_map']);
        if ($fieldMapError !== null) {
            return [false, $fieldMapError, 0];
        }

        $name = (string) $input['name'];
        $slug = (string) $input['slug'];
        $homepage = (string) $input['homepage_url'];
        $feed = (string) $input['feed_url'];
        $adapter = (string) $input['adapter_type'];
        $catId = $input['category_id'];
        $attrib = (string) $input['attribution_text'];
        $license = (string) $input['license'];
        $language = $input['language'];
        $interval = (int) $input['fetch_interval_min'];
        $uaOverride = $input['user_agent_override'] !== '' ? $input['user_agent_override'] : null;

        if ($slug === '') {
            $slug = $this->makeSlug($name);
        }
        if ($attrib === '') {
            $attrib = $name;
        }

        if ($id === null) {
            // Create — check for duplicate slug.
            $exists = Database::query('SELECT id FROM sources WHERE slug = ?', [$slug])->fetch();
            if ($exists) {
                $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            }

            Database::query(
                'INSERT INTO sources
                 (name, slug, homepage_url, feed_url, adapter_type, category_id,
                  attribution_text, license, language, fetch_interval_min, field_map,
                  user_agent_override, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $name,
                    $slug,
                    $homepage,
                    $feed ?: null,
                    $adapter,
                    $catId,
                    $attrib,
                    $license ?: null,
                    $language,
                    $interval,
                    $fieldMapJson,
                    $uaOverride,
                    'pending'
                ]
            );
            return [true, '', (int) Database::lastInsertId()];
        }

        Database::query(
            'UPDATE sources SET name=?, slug=?, homepage_url=?, feed_url=?, adapter_type=?,
             category_id=?, attribution_text=?, license=?, language=?, fetch_interval_min=?, field_map=?,
             user_agent_override=?
             WHERE id=?',
            [
                $name,
                $slug,
                $homepage,
                $feed ?: null,
                $adapter,
                $catId,
                $attrib,
                $license ?: null,
                $language,
                $interval,
                $fieldMapJson,
                $uaOverride,
                $id
            ]
        );
        return [true, '', $id];
    }

    private function makeSlug(string $name): string
    {
        $s = mb_strtolower($name);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
        return trim($s, '-');
    }

    /**
     * @return array{name:string,slug:string,homepage_url:string,feed_url:string,adapter_type:string,category_id:?int,attribution_text:string,license:string,language:?string,fetch_interval_min:int,field_map:string,user_agent_override:string}
     */
    private function normalizeSourceInput(array $post): array
    {
        $allowedLanguages = ['en','de','fr','es','pt','nl','it','ja','zh','ko','ru','ar','pl','sv','fi','da','no'];
        $rawLang = mb_substr(trim((string) ($post['language'] ?? '')), 0, 10);
        return [
            'name' => mb_substr(trim((string) ($post['name'] ?? '')), 0, 120),
            'slug' => mb_substr(trim((string) ($post['slug'] ?? '')), 0, 120),
            'homepage_url' => mb_substr(trim((string) ($post['homepage_url'] ?? '')), 0, 500),
            'feed_url' => mb_substr(trim((string) ($post['feed_url'] ?? '')), 0, 500),
            'adapter_type' => mb_substr(trim((string) ($post['adapter_type'] ?? 'rss_atom')), 0, 30),
            'category_id' => (($post['category_id'] ?? '') !== '' ? (int) $post['category_id'] : null),
            'attribution_text' => mb_substr(trim((string) ($post['attribution_text'] ?? '')), 0, 255),
            'license' => mb_substr(trim((string) ($post['license'] ?? '')), 0, 120),
            'language' => in_array($rawLang, $allowedLanguages, true) ? $rawLang : null,
            'fetch_interval_min' => max(1, min(1440, (int) ($post['fetch_interval_min'] ?? 15))),
            'field_map' => trim((string) ($post['field_map'] ?? '')),
            'user_agent_override' => mb_substr(trim((string) ($post['user_agent_override'] ?? '')), 0, 255),
        ];
    }

    /** @return string[] */
    private function validateSourceInput(array $input): array
    {
        if ((string) $input['name'] === '') {
            return ['Name is required.'];
        }
        if ((string) $input['homepage_url'] === '') {
            return ['Homepage URL is required.'];
        }
        if (!$this->isHttpUrl((string) $input['homepage_url'])) {
            return ['Homepage URL must be a valid http/https URL.'];
        }

        $adapter = (string) $input['adapter_type'];
        if (!in_array($adapter, self::SUPPORTED_ADAPTERS, true)) {
            return ['Unsupported adapter type selected.'];
        }

        $feedUrl = (string) $input['feed_url'];
        if ($feedUrl !== '' && !$this->isHttpUrl($feedUrl)) {
            return ['Feed URL must be a valid http/https URL.'];
        }

        if (in_array($adapter, ['rss_atom', 'json_api', 'nvd'], true) && $feedUrl === '') {
            return ['Feed URL is required for the selected adapter.'];
        }

        if ($adapter === 'json_api') {
            [, $fieldMapError] = $this->decodeFieldMap((string) $input['field_map']);
            if ($fieldMapError !== null) {
                return [$fieldMapError];
            }
        }

        return [];
    }

    private function renderSourceFormPreview(?array $existingSource, array $post): void
    {
        $categories = Database::query('SELECT id, name FROM source_categories ORDER BY sort_order')->fetchAll();
        $recentLog = [];

        $input = $this->normalizeSourceInput($post);
        $formErrors = $this->validateSourceInput($input);

        $source = $this->sourceFromInput($input, $existingSource);
        $previewResult = null;

        $articleCount = 0;
        if ($existingSource !== null) {
            $recentLog  = Database::query(
                'SELECT status, http_status, items_found, items_new, duration_ms, error, created_at
                 FROM fetch_log WHERE source_id = ? ORDER BY created_at DESC LIMIT 10',
                [(int) $existingSource['id']]
            )->fetchAll();
            $articleCount = (int) Database::query('SELECT COUNT(*) FROM articles WHERE source_id = ?', [(int) $existingSource['id']])->fetchColumn();
        }
        $effectiveUa = FeedFetcher::resolveUa($input['user_agent_override']);
        $debugResult = null;

        if ($formErrors === []) {
            $preview = (new SourcePreviewService(new FeedFetcher($input['user_agent_override'])))->preview($source);
            $previewResult = $preview;
        }

        $isCreate = $existingSource === null;
        $title    = $isCreate ? 'Admin — New source' : 'Admin — Edit source';
        $adminNav = 'sources';
        include DB_ROOT . '/src/View/admin_layout.php';
        include DB_ROOT . '/src/View/admin/sources/edit.php';
        include DB_ROOT . '/src/View/admin_layout_end.php';
    }

    private function renderSourceFormDebug(array $source): void
    {
        $categories   = Database::query('SELECT id, name FROM source_categories ORDER BY sort_order')->fetchAll();
        $recentLog    = Database::query(
            'SELECT status, http_status, items_found, items_new, duration_ms, error, created_at
             FROM fetch_log WHERE source_id = ? ORDER BY created_at DESC LIMIT 10',
            [(int) $source['id']]
        )->fetchAll();
        $articleCount = (int) Database::query('SELECT COUNT(*) FROM articles WHERE source_id = ?', [(int) $source['id']])->fetchColumn();
        $uaOverride   = (string) ($source['user_agent_override'] ?? '');
        $effectiveUa  = FeedFetcher::resolveUa($uaOverride);
        $previewResult = null;
        $debugResult   = null;

        $feedUrl = (string) ($source['feed_url'] ?? '');
        if ($feedUrl !== '') {
            $fetcher = new FeedFetcher($uaOverride);
            try {
                $debugResult = $fetcher->getRaw($feedUrl);
            } catch (\Throwable $e) {
                $debugResult = [
                    'error'          => $e->getMessage(),
                    'status'         => 0,
                    'raw_headers'    => '',
                    'body_snippet'   => '',
                    'body_length'    => 0,
                    'content_type'   => null,
                    'etag'           => null,
                    'last_modified'  => null,
                    'not_modified'   => false,
                    'duration_ms'    => 0,
                    'effective_ua'   => $effectiveUa,
                    'final_url'      => $feedUrl,
                    'redirect_count' => 0,
                ];
            }
        } else {
            $debugResult = ['error' => 'No feed URL configured for this source.'];
        }

        $isCreate = false;
        $title    = 'Admin — Edit source';
        $adminNav = 'sources';
        include DB_ROOT . '/src/View/admin_layout.php';
        include DB_ROOT . '/src/View/admin/sources/edit.php';
        include DB_ROOT . '/src/View/admin_layout_end.php';
    }

    /**
     * @param array{name:string,slug:string,homepage_url:string,feed_url:string,adapter_type:string,category_id:?int,attribution_text:string,license:string,fetch_interval_min:int,field_map:string} $input
     */
    private function sourceFromInput(array $input, ?array $existingSource): array
    {
        $source = $existingSource ?? [
            'id' => null,
            'status' => 'pending',
            'consecutive_failures' => 0,
            'last_error' => null,
            'etag' => null,
            'last_modified_hdr' => null,
        ];

        $source['name'] = $input['name'];
        $source['slug'] = $input['slug'];
        $source['homepage_url'] = $input['homepage_url'];
        $source['feed_url'] = $input['feed_url'] !== '' ? $input['feed_url'] : null;
        $source['adapter_type'] = $input['adapter_type'];
        $source['category_id'] = $input['category_id'];
        $source['attribution_text'] = $input['attribution_text'];
        $source['license'] = $input['license'];
        $source['language'] = $input['language'];
        $source['fetch_interval_min']   = $input['fetch_interval_min'];
        $source['field_map']            = $input['field_map'];
        $source['user_agent_override']  = $input['user_agent_override'];

        return $source;
    }

    /** @return array{0:?string,1:?string} [fieldMapForStorage, error] */
    private function normalizeFieldMapForStorage(string $rawFieldMap): array
    {
        $trimmed = trim($rawFieldMap);
        if ($trimmed === '' || $trimmed === '{}') {
            return [null, null];
        }

        [, $error] = $this->decodeFieldMap($trimmed);
        if ($error !== null) {
            return [null, $error];
        }

        return [$trimmed, null];
    }

    /** @return array{0:array<string,mixed>,1:?string} [decodedMap, error] */
    private function decodeFieldMap(string $rawFieldMap): array
    {
        $trimmed = trim($rawFieldMap);
        if ($trimmed === '' || $trimmed === '{}') {
            return [[], null];
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return [[], 'Field map is not valid JSON.'];
        }
        if (array_is_list($decoded)) {
            return [[], 'Field map must be a JSON object with key/value mappings.'];
        }

        return [$decoded, null];
    }

    private function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }

    /** @return array<int,array{text:string,xmlUrl:string,htmlUrl:string}> */
    private function extractOpmlOutlines(\SimpleXMLElement $node): array
    {
        $results = [];
        foreach ($node->children() as $child) {
            $xmlUrl = trim((string) ($child['xmlUrl'] ?? ''));
            if ($xmlUrl !== '') {
                $results[] = [
                    'text'    => (string) ($child['text'] ?? ''),
                    'xmlUrl'  => $xmlUrl,
                    'htmlUrl' => (string) ($child['htmlUrl'] ?? ''),
                ];
            } else {
                foreach ($this->extractOpmlOutlines($child) as $sub) {
                    $results[] = $sub;
                }
            }
        }
        return $results;
    }

    /** @param array<string,bool> $usedInBatch */
    private function generateUniqueSlug(string $base, array &$usedInBatch): string
    {
        $slug = $this->makeSlug($base);
        if ($slug === '') {
            $slug = 'source';
        }

        if (!isset($usedInBatch[$slug]) && !Database::query('SELECT id FROM sources WHERE slug = ? LIMIT 1', [$slug])->fetch()) {
            $usedInBatch[$slug] = true;
            return $slug;
        }

        $candidate = $slug . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        $usedInBatch[$candidate] = true;
        return $candidate;
    }

    // ── Categories ────────────────────────────────────────────────────────────

    public function categoriesList(array $args = []): void
    {
        AuthService::requireAdmin();

        $categories = Database::query(
            "SELECT c.*, COUNT(s.id) AS source_count
             FROM source_categories c
             LEFT JOIN sources s ON s.category_id = c.id
             GROUP BY c.id
             ORDER BY c.sort_order, c.name"
        )->fetchAll();

        $title    = 'Admin — Categories';
        $adminNav = 'categories';
        include DB_ROOT . '/src/View/admin_layout.php';
        include DB_ROOT . '/src/View/admin/categories/list.php';
        include DB_ROOT . '/src/View/admin_layout_end.php';
    }

    public function categoryCreate(array $args = []): void
    {
        AuthService::requireAdmin();

        $title    = 'Admin — New category';
        $adminNav = 'categories';
        $isCreate = true;
        include DB_ROOT . '/src/View/admin_layout.php';
        include DB_ROOT . '/src/View/admin/categories/form.php';
        include DB_ROOT . '/src/View/admin_layout_end.php';
    }

    public function handleCategoryCreate(array $args = []): void
    {
        AuthService::requireAdmin();
        Csrf::check();

        [$fields, $errors] = $this->validateCategoryInput($_POST);

        if ($errors !== []) {
            $title    = 'Admin — New category';
            $adminNav = 'categories';
            $isCreate = true;
            $formErrors = $errors;
            $category = array_merge(['id' => null, 'slug' => '', 'sort_order' => 0], $fields);
            include DB_ROOT . '/src/View/admin_layout.php';
            include DB_ROOT . '/src/View/admin/categories/form.php';
            include DB_ROOT . '/src/View/admin_layout_end.php';
            return;
        }

        $slug = $fields['slug'] !== ''
            ? $fields['slug']
            : $this->makeSlug($fields['name']);

        if (Database::query('SELECT id FROM source_categories WHERE slug = ? LIMIT 1', [$slug])->fetch()) {
            $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        }

        Database::query(
            'INSERT INTO source_categories (name, slug, color, sort_order) VALUES (?, ?, ?, ?)',
            [$fields['name'], $slug, $fields['color'] ?: null, $fields['sort_order']]
        );
        $id = (int) Database::lastInsertId();

        AuditLog::write('category.create', 'category', $slug);
        $_SESSION['flash'] = "Category \"{$fields['name']}\" created.";
        header('Location: /admin/categories/' . $id);
        exit;
    }

    public function categoryEdit(array $args = []): void
    {
        AuthService::requireAdmin();

        $category = $this->requireCategory((int) ($args['id'] ?? 0));

        $title    = 'Admin — Edit category';
        $adminNav = 'categories';
        $isCreate = false;
        include DB_ROOT . '/src/View/admin_layout.php';
        include DB_ROOT . '/src/View/admin/categories/form.php';
        include DB_ROOT . '/src/View/admin_layout_end.php';
    }

    public function handleCategoryEdit(array $args = []): void
    {
        AuthService::requireAdmin();
        Csrf::check();

        $category = $this->requireCategory((int) ($args['id'] ?? 0));
        $id       = (int) $category['id'];
        $action   = (string) ($_POST['action'] ?? 'save');

        if ($action === 'delete') {
            $sourceCount = (int) Database::query(
                'SELECT COUNT(*) FROM sources WHERE category_id = ?', [$id]
            )->fetchColumn();

            if ($sourceCount > 0) {
                $_SESSION['flash_error'] = "Cannot delete: {$sourceCount} source(s) still assigned to this category. Reassign them first.";
                header('Location: /admin/categories/' . $id);
                exit;
            }

            $name = $category['name'];
            Database::query('DELETE FROM source_categories WHERE id = ?', [$id]);
            AuditLog::write('category.delete', 'category', $name);
            $_SESSION['flash'] = "Category \"{$name}\" deleted.";
            header('Location: /admin/categories');
            exit;
        }

        [$fields, $errors] = $this->validateCategoryInput($_POST);

        if ($errors !== []) {
            $title      = 'Admin — Edit category';
            $adminNav   = 'categories';
            $isCreate   = false;
            $formErrors = $errors;
            $category   = array_merge($category, $fields);
            include DB_ROOT . '/src/View/admin_layout.php';
            include DB_ROOT . '/src/View/admin/categories/form.php';
            include DB_ROOT . '/src/View/admin_layout_end.php';
            return;
        }

        $slug = $fields['slug'] !== '' ? $fields['slug'] : $this->makeSlug($fields['name']);
        $existing = Database::query(
            'SELECT id FROM source_categories WHERE slug = ? AND id != ? LIMIT 1', [$slug, $id]
        )->fetch();
        if ($existing) {
            $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        }

        Database::query(
            'UPDATE source_categories SET name = ?, slug = ?, color = ?, sort_order = ? WHERE id = ?',
            [$fields['name'], $slug, $fields['color'] ?: null, $fields['sort_order'], $id]
        );

        AuditLog::write('category.update', 'category', $slug);
        $_SESSION['flash'] = 'Category updated.';
        header('Location: /admin/categories/' . $id);
        exit;
    }

    /**
     * @return array{array{name:string,slug:string,color:string,sort_order:int},list<string>}
     */
    private function validateCategoryInput(array $post): array
    {
        $errors = [];

        $name = trim((string) ($post['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'Name is required.';
        } elseif (mb_strlen($name) > 80) {
            $errors[] = 'Name must be 80 characters or fewer.';
        }

        $slug = trim((string) ($post['slug'] ?? ''));
        if ($slug !== '' && !preg_match('/\A[a-z0-9-]+\z/', $slug)) {
            $errors[] = 'Slug may only contain lowercase letters, numbers, and hyphens.';
        }
        if (mb_strlen($slug) > 80) {
            $errors[] = 'Slug must be 80 characters or fewer.';
        }

        $color = trim((string) ($post['color'] ?? ''));
        if ($color !== '' && !preg_match('/\A#[0-9a-fA-F]{6}\z/', $color)) {
            $errors[] = 'Color must be a valid hex value (e.g. #3498db).';
        }

        $sortOrder = (int) ($post['sort_order'] ?? 0);

        return [
            ['name' => $name, 'slug' => $slug, 'color' => $color, 'sort_order' => $sortOrder],
            $errors,
        ];
    }

    private function requireCategory(int $id): array
    {
        $cat = Database::query('SELECT * FROM source_categories WHERE id = ?', [$id])->fetch();
        if (!$cat) {
            http_response_code(404);
            echo 'Category not found.';
            exit;
        }
        return $cat;
    }

    // ── Site notification ────────────────────────────────────────────────────

    public function notificationEdit(array $args = []): void
    {
        AuthService::requireAdmin();

        $notification = Database::query('SELECT * FROM site_notification WHERE id = 1')->fetch();

        $title    = 'Admin — Site notification';
        $adminNav = 'notification';
        include DB_ROOT . '/src/View/admin_layout.php';
        include DB_ROOT . '/src/View/admin/notification/edit.php';
        include DB_ROOT . '/src/View/admin_layout_end.php';
    }

    public function handleNotificationSave(array $args = []): void
    {
        AuthService::requireAdmin();
        Csrf::check();

        [$fields, $errors] = $this->validateNotificationInput($_POST);

        if ($errors !== []) {
            $notification = [
                'message'   => $fields['message'],
                'is_active' => $fields['is_active'] ? 1 : 0,
            ];
            $title      = 'Admin — Site notification';
            $adminNav   = 'notification';
            $formErrors = $errors;
            include DB_ROOT . '/src/View/admin_layout.php';
            include DB_ROOT . '/src/View/admin/notification/edit.php';
            include DB_ROOT . '/src/View/admin_layout_end.php';
            return;
        }

        $user = AuthService::currentUser();
        Database::query(
            'UPDATE site_notification SET message = ?, is_active = ?, updated_by = ? WHERE id = 1',
            [$fields['message'], $fields['is_active'] ? 1 : 0, $user ? (int) $user['id'] : null]
        );

        AuditLog::write('site_notification.update', 'site_notification', '1');
        $_SESSION['flash'] = $fields['is_active'] ? 'Site notification saved and activated.' : 'Site notification saved.';
        header('Location: /admin/notification');
        exit;
    }

    private function validateNotificationInput(array $post): array
    {
        $errors = [];

        $message  = trim((string) ($post['message'] ?? ''));
        $isActive = !empty($post['is_active']);

        if (mb_strlen($message) > 500) {
            $errors[] = 'Message must be 500 characters or fewer.';
        }
        if ($isActive && $message === '') {
            $errors[] = 'A message is required to activate the notification.';
        }

        return [
            ['message' => $message, 'is_active' => $isActive],
            $errors,
        ];
    }
}
