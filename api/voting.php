<?php
/**
 * Community Voting API Endpoint
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required. Please sign in to participate in voting.']);
    exit;
}

$user = Auth::user();
$userId = (int)$user['id'];
$db = Database::getConnection();

// Support both JSON payload and standard POST FormData
$rawInput = file_get_contents('php://input');
$jsonData = json_decode($rawInput, true);
$action = $_POST['action'] ?? ($jsonData['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. DISMISS FLOATING VOTING BUBBLE
    if ($action === 'dismiss_bubble') {
        $pollId = (int)($_POST['poll_id'] ?? ($jsonData['poll_id'] ?? 0));
        if ($pollId > 0) {
            if (!isset($_SESSION['dismissed_poll_bubbles'])) {
                $_SESSION['dismissed_poll_bubbles'] = [];
            }
            if (!in_array($pollId, $_SESSION['dismissed_poll_bubbles'], true)) {
                $_SESSION['dismissed_poll_bubbles'][] = $pollId;
            }

            try {
                ensure_voting_tables_exist($db);
                $ins = $db->prepare("
                    INSERT INTO user_poll_bubble_dismissals (user_id, poll_id) 
                    VALUES (:uid, :pid) 
                    ON DUPLICATE KEY UPDATE dismissed_at = CURRENT_TIMESTAMP
                ");
                $ins->execute(['uid' => $userId, 'pid' => $pollId]);
            } catch (\Throwable $e) {
                error_log("Dismiss bubble save error: " . $e->getMessage());
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // 2. CAST OR CHANGE VOTE
    if ($action === 'vote') {
        CSRF::verifyOrAbort();

        $pollId = (int)($_POST['poll_id'] ?? ($jsonData['poll_id'] ?? 0));
        $optionId = (int)($_POST['option_id'] ?? ($jsonData['option_id'] ?? 0));

        if ($pollId <= 0 || $optionId <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Please select an option to submit your vote.']);
            exit;
        }

        try {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $res = cast_poll_vote($db, $pollId, $optionId, $userId, $ipAddress);

            // Fetch fresh state for this single poll to return reactive data
            $updatedPolls = get_polls_for_user($userId, Auth::isAdmin());
            $matchingPoll = null;
            foreach ($updatedPolls as $p) {
                if ((int)$p['id'] === $pollId) {
                    $matchingPoll = $p;
                    break;
                }
            }

            $msg = $res['is_change'] ? 'Your vote has been updated successfully!' : 'Thank you! Your vote has been recorded.';

            echo json_encode([
                'success' => true,
                'message' => $msg,
                'is_change' => $res['is_change'],
                'poll' => $matchingPoll
            ]);
            exit;
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
}

// 3. GET FRESH POLL DATA (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['poll_id'])) {
    $pollId = (int)$_GET['poll_id'];
    $polls = get_polls_for_user($userId, Auth::isAdmin());
    foreach ($polls as $p) {
        if ((int)$p['id'] === $pollId) {
            echo json_encode(['success' => true, 'poll' => $p]);
            exit;
        }
    }
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Poll not found.']);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action request.']);
