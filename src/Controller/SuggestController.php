<?php

declare(strict_types=1);

namespace Daybreak\Controller;

use Daybreak\Database;
use Daybreak\Security\Csrf;
use Daybreak\Security\Html;
use Daybreak\Service\AuthService;
use Daybreak\Service\SuggestionService;

final class SuggestController
{
    public function show(array $args = []): void
    {
        AuthService::requireAuth();

        $title         = 'Suggest a source';
        $activeNav     = 'suggest';
        $showWidgets   = false;
        $showFilterBar = false;
        include DB_ROOT . '/src/View/layout.php';
        include DB_ROOT . '/src/View/suggest/index.php';
        include DB_ROOT . '/src/View/layout_end.php';
    }

    public function handle(array $args = []): void
    {
        AuthService::requireAuth();
        Csrf::check();

        $userId   = (int) (AuthService::currentUser()['id'] ?? 0);
        $dayCount = (int) Database::query(
            'SELECT COUNT(*) FROM source_suggestions
             WHERE suggested_by = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)',
            [$userId]
        )->fetchColumn();
        if ($dayCount >= 5) {
            $_SESSION['flash_error'] = 'You have reached the daily suggestion limit (5 per day).';
            header('Location: /suggest');
            exit;
        }

        $name     = mb_substr(trim($_POST['name']         ?? ''), 0, 120);
        $homepage = mb_substr(trim($_POST['homepage_url'] ?? ''), 0, 500);
        $feedUrl  = mb_substr(trim($_POST['feed_url']     ?? ''), 0, 500);
        $note     = mb_substr(trim($_POST['note']         ?? ''), 0, 500);

        if ($name === '' || $homepage === '') {
            $_SESSION['flash_error'] = 'Name and homepage URL are required.';
            header('Location: /suggest');
            exit;
        }

        if (!filter_var($homepage, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $homepage)) {
            $_SESSION['flash_error'] = 'Homepage URL must be a valid http/https URL.';
            header('Location: /suggest');
            exit;
        }

        if ($feedUrl !== '' && (!filter_var($feedUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $feedUrl))) {
            $_SESSION['flash_error'] = 'Feed URL must be a valid http/https URL.';
            header('Location: /suggest');
            exit;
        }

        $probe   = null;
        $probeOn = $feedUrl !== '' ? $feedUrl : $homepage;
        try {
            $probe = SuggestionService::probe($probeOn);
        } catch (\Throwable) {
            // probe failure is non-fatal
        }

        $user   = AuthService::currentUser();
        $userId = $user ? (int) $user['id'] : null;

        SuggestionService::submit(
            $userId,
            $name,
            $homepage,
            $feedUrl !== '' ? $feedUrl : ($probe['feed_url'] ?? null),
            $note !== '' ? $note : null,
            $probe
        );

        try {
            (new \Daybreak\Service\MailService())->sendAdminNewSuggestion($name, $homepage, $user);
        } catch (\Throwable) {
            // non-fatal
        }

        $_SESSION['flash'] = 'Thanks — your suggestion has been submitted for review.';
        header('Location: /suggest');
        exit;
    }
}
