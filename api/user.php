<?php
/**
 * User API Endpoint (Balance check)
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

check_maintenance_mode();

$key = $_POST['key'] ?? $_GET['key'] ?? '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (empty($key)) {
    echo json_encode(['error' => 'API Key is required']);
    exit;
}

$db = Database::getConnection();

$stmt = $db->prepare("SELECT id, balance, status FROM users WHERE api_key = :k LIMIT 1");
$stmt->execute(['k' => $key]);
$user = $stmt->fetch();

if (!$user || $user['status'] !== 'active') {
    echo json_encode(['error' => 'Invalid or inactive API Key']);
    exit;
}

if ($action === 'balance') {
    echo json_encode([
        'balance' => (string)number_format((float)$user['balance'], 2, '.', ''),
        'currency' => APP_CURRENCY_CODE
    ]);
    exit;
}

echo json_encode(['error' => 'Invalid action parameter']);
