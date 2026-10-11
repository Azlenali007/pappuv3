<?php
/**
 * SMM API v2 Standard Orders Endpoint
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

// Authenticate API key
$stmt = $db->prepare("SELECT id, balance, status FROM users WHERE api_key = :k LIMIT 1");
$stmt->execute(['k' => $key]);
$user = $stmt->fetch();

if (!$user || $user['status'] !== 'active') {
    echo json_encode(['error' => 'Invalid or inactive API Key']);
    exit;
}

switch ($action) {
    case 'add':
        $serviceId = (int)($_POST['service'] ?? 0);
        $link = trim($_POST['link'] ?? '');
        $quantity = (int)($_POST['quantity'] ?? 0);

        $svcStmt = $db->prepare("SELECT * FROM services WHERE id = :id AND status = 'active' LIMIT 1");
        $svcStmt->execute(['id' => $serviceId]);
        $service = $svcStmt->fetch();

        if (!$service) {
            echo json_encode(['error' => 'Service not found or inactive']);
            exit;
        }

        if ($quantity < $service['min_quantity'] || $quantity > $service['max_quantity']) {
            echo json_encode(['error' => "Quantity must be between {$service['min_quantity']} and {$service['max_quantity']}"]);
            exit;
        }

        $ratePer1000 = (float)$service['rate_per_1000'];
        $charge = round(($quantity / 1000) * $ratePer1000, 4);

        if ($user['balance'] < $charge) {
            echo json_encode(['error' => 'Not enough funds on balance']);
            exit;
        }

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE users SET balance = balance - :ch, spent = spent + :ch WHERE id = :uid AND balance >= :ch")->execute(['ch' => $charge, 'uid' => $user['id']]);
            $ordStmt = $db->prepare("INSERT INTO orders (user_id, service_id, link, quantity, charge, remains, status, mode) VALUES (:uid, :sid, :link, :qty, :charge, :rem, 'processing', 'auto')");
            $ordStmt->execute([
                'uid' => $user['id'],
                'sid' => $service['id'],
                'link' => $link,
                'qty' => $quantity,
                'charge' => $charge,
                'rem' => $quantity
            ]);
            $orderId = (int)$db->lastInsertId();

            $db->prepare("INSERT INTO transactions (user_id, order_id, type, amount, gateway, gateway_txn_id, status, note) VALUES (:uid, :oid, 'order', :amt, 'api', :txnid, 'completed', :note)")->execute([
                'uid' => $user['id'],
                'oid' => $orderId,
                'amt' => -$charge,
                'txnid' => 'API-ORD-' . $orderId,
                'note' => 'API Order #' . $orderId
            ]);
            $db->commit();

            echo json_encode(['order' => $orderId]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['error' => 'Order failed: ' . $e->getMessage()]);
        }
        break;

    case 'status':
        $orderId = (int)($_POST['order'] ?? 0);
        $ordStmt = $db->prepare("SELECT status, charge, start_count, remains FROM orders WHERE id = :id AND user_id = :uid LIMIT 1");
        $ordStmt->execute(['id' => $orderId, 'uid' => $user['id']]);
        $order = $ordStmt->fetch();

        if (!$order) {
            echo json_encode(['error' => 'Order not found']);
            exit;
        }

        echo json_encode([
            'charge' => (string)$order['charge'],
            'start_count' => (string)$order['start_count'],
            'status' => $order['status'] === 'in_progress' ? 'In progress' : ucfirst($order['status']),
            'remains' => (string)$order['remains'],
            'currency' => APP_CURRENCY_CODE
        ]);
        break;

    default:
        echo json_encode(['error' => 'Incorrect request']);
        break;
}
