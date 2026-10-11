<?php
/**
 * User Announcement Dismissal Endpoint
 * Records user dismissal for popup announcements
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?: $_POST;

$announcementId = (int)($data['announcement_id'] ?? 0);
$dontShowAgain = !empty($data['dont_show_again']);

if ($announcementId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid announcement ID']);
    exit;
}

$user = Auth::user();
$userId = (int)$user['id'];

// Always mark in session so current session won't see it again
if (!isset($_SESSION['dismissed_announcements']) || !is_array($_SESSION['dismissed_announcements'])) {
    $_SESSION['dismissed_announcements'] = [];
}
$_SESSION['dismissed_announcements'][$announcementId] = true;

// If "Don't show again" or announcement is show_once, persist dismissal in database
try {
    $db = Database::getConnection();
    
    // Check if announcement is show_once
    $stmt = $db->prepare("SELECT id, show_once FROM announcements WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $announcementId]);
    $announcement = $stmt->fetch();

    if ($announcement && ($announcement['show_once'] == 1 || $dontShowAgain)) {
        $insert = $db->prepare("
            INSERT IGNORE INTO user_announcement_dismissals (user_id, announcement_id, dismissed_at)
            VALUES (:uid, :aid, CURRENT_TIMESTAMP)
        ");
        $insert->execute([
            'uid' => $userId,
            'aid' => $announcementId
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Announcement dismissed successfully']);
} catch (Exception $e) {
    error_log("Announcement dismissal error: " . $e->getMessage());
    // Still return success to client because session is marked
    echo json_encode(['success' => true, 'message' => 'Dismissed in session']);
}
