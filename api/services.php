<?php
/**
 * SMM API v2 Services Endpoint
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

$db = Database::getConnection();

if ($action === 'services') {
    $stmt = $db->query("
        SELECT s.id AS service, s.name, 'Default' AS type, c.name AS category, s.rate_per_1000 AS rate, s.min_quantity AS min, s.max_quantity AS max
        FROM services s
        JOIN categories c ON s.category_id = c.id
        WHERE s.status = 'active'
        ORDER BY c.sort_order, s.id
    ");
    $services = $stmt->fetchAll();
    echo json_encode($services);
    exit;
}

echo json_encode(['error' => 'Invalid action parameter']);
