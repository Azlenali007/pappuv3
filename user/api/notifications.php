<?php
/**
 * User Notifications AJAX API Endpoint
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$user = Auth::user();
$db = Database::getConnection();
$action = $_GET['action'] ?? $_POST['action'] ?? 'unread_count';

try {
    if ($action === 'unread_count') {
        $count = get_unread_notifications_count((int)$user['id']);
        echo json_encode(['success' => true, 'unread_count' => $count]);
        exit;
    }

    if ($action === 'mark_read') {
        $notifId = (int)($_POST['id'] ?? 0);
        if ($notifId > 0) {
            $stmt = $db->prepare("
                INSERT IGNORE INTO user_notification_reads (user_id, announcement_id)
                VALUES (:uid, :aid)
            ");
            $stmt->execute(['uid' => $user['id'], 'aid' => $notifId]);
        }
        $count = get_unread_notifications_count((int)$user['id']);
        echo json_encode(['success' => true, 'unread_count' => $count]);
        exit;
    }

    if ($action === 'mark_all_read') {
        $allStmt = $db->prepare("
            SELECT a.id FROM announcements a
            LEFT JOIN user_notification_reads r ON a.id = r.announcement_id AND r.user_id = :uid
            WHERE a.status = 'active'
              AND (a.target_audience = 'all' OR a.target_user_id = :uid)
              AND r.read_at IS NULL
        ");
        $allStmt->execute(['uid' => $user['id']]);
        $ids = $allStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($ids)) {
            $ins = $db->prepare("INSERT IGNORE INTO user_notification_reads (user_id, announcement_id) VALUES (:uid, :aid)");
            foreach ($ids as $aid) {
                $ins->execute(['uid' => $user['id'], 'aid' => (int)$aid]);
            }
        }
        echo json_encode(['success' => true, 'unread_count' => 0]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action']);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
