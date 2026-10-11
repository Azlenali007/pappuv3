<?php
/**
 * Community Voting API Endpoint
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

// Prevent accidental output or warnings from corrupting JSON responses
ob_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

/**
 * Standardized JSON response emitter that guarantees clean headers & buffers
 */
function send_json_response(array $data, int $statusCode = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Global exception safety handler for API endpoint
set_exception_handler(function (\Throwable $e) {
    error_log("Voting API uncaught error: " . $e->getMessage());
    send_json_response([
        'success' => false,
        'error' => $e->getMessage(),
        'message' => $e->getMessage()
    ], 500);
});

// 1. AUTHENTICATION ENFORCEMENT
if (!Auth::check()) {
    send_json_response([
        'success' => false,
        'error' => 'Authentication required. Please sign in to participate in voting.',
        'message' => 'Authentication required. Please sign in to participate in voting.'
    ], 401);
}

$user = Auth::user();
if (!$user) {
    send_json_response([
        'success' => false,
        'error' => 'User session invalid. Please log in again.',
        'message' => 'User session invalid. Please log in again.'
    ], 401);
}

$userId = (int)$user['id'];
$db = Database::getConnection();
ensure_voting_tables_exist($db);

// Support both JSON payload and standard POST FormData / query parameters
$rawInput = file_get_contents('php://input');
$jsonData = !empty($rawInput) ? json_decode($rawInput, true) : null;
if (!is_array($jsonData)) {
    $jsonData = [];
}

$action = trim((string)($_POST['action'] ?? ($jsonData['action'] ?? ($_GET['action'] ?? ''))));

// 2. ACTIVE FLOATING BUBBLE CHECK (GET/POST)
if ($action === 'active_bubble' || ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['poll_id']))) {
    $bubble = get_floating_voting_bubble_data($userId);
    send_json_response([
        'success' => true,
        'has_bubble' => ($bubble !== null),
        'bubble' => $bubble
    ]);
}

// 3. FETCH SINGLE POLL DATA (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['poll_id'])) {
    $pollId = (int)$_GET['poll_id'];
    $polls = get_polls_for_user($userId, Auth::isAdmin());
    foreach ($polls as $p) {
        if ((int)$p['id'] === $pollId) {
            send_json_response([
                'success' => true,
                'poll' => $p
            ]);
        }
    }
    send_json_response([
        'success' => false,
        'error' => 'Poll not found.',
        'message' => 'Poll not found.'
    ], 404);
}

// 4. POST STATE-CHANGING ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 4A. DISMISS FLOATING VOTING BUBBLE
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
                $ins = $db->prepare("
                    INSERT INTO user_poll_bubble_dismissals (user_id, poll_id, dismissed_at) 
                    VALUES (:uid, :pid, CURRENT_TIMESTAMP) 
                    ON DUPLICATE KEY UPDATE dismissed_at = CURRENT_TIMESTAMP
                ");
                $ins->execute(['uid' => $userId, 'pid' => $pollId]);
            } catch (\Throwable $e) {
                error_log("Dismiss bubble save error: " . $e->getMessage());
            }
        }

        send_json_response([
            'success' => true,
            'message' => 'Voting bubble dismissed.'
        ]);
    }

    // 4B. CAST OR CHANGE VOTE
    if ($action === 'vote') {
        // Validate CSRF token safely with JSON response on failure
        $csrfToken = (string)($_POST['csrf_token'] ?? ($jsonData['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
        if (!CSRF::validate($csrfToken)) {
            send_json_response([
                'success' => false,
                'error' => 'Security token invalid or expired. Please refresh the page and try again.',
                'message' => 'Security token invalid or expired. Please refresh the page and try again.'
            ], 403);
        }

        $pollId = (int)($_POST['poll_id'] ?? ($jsonData['poll_id'] ?? 0));
        $optionId = (int)($_POST['option_id'] ?? ($jsonData['option_id'] ?? 0));

        if ($pollId <= 0 || $optionId <= 0) {
            send_json_response([
                'success' => false,
                'error' => 'Please select a valid option to submit your vote.',
                'message' => 'Please select a valid option to submit your vote.'
            ], 422);
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

            $msg = !empty($res['is_change']) ? 'Your vote has been updated successfully!' : 'Thank you! Your vote has been recorded.';

            send_json_response([
                'success' => true,
                'message' => $msg,
                'is_change' => !empty($res['is_change']),
                'poll' => $matchingPoll
            ]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            send_json_response([
                'success' => false,
                'error' => $msg,
                'message' => $msg
            ], 400);
        }
    }
}

// 5. UNRECOGNIZED ACTION
send_json_response([
    'success' => false,
    'error' => 'Invalid action request.',
    'message' => 'Invalid action request.'
], 400);
