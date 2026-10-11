<?php
/**
 * Standard SMM Reseller API v2 Engine
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Enforce system maintenance mode
check_maintenance_mode();

$db = Database::getConnection();

// API accepts POST or GET (standard SMM clients send POST)
$apiKey = trim((string)($_POST['key'] ?? $_GET['key'] ?? ''));
$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? '')));

if (empty($apiKey)) {
    http_response_code(400);
    echo json_encode(['error' => 'API Key is missing or invalid']);
    exit;
}

// Authenticate API key server-side
$userStmt = $db->prepare("SELECT id, username, email, balance, role, status FROM users WHERE api_key = :k LIMIT 1");
$userStmt->execute(['k' => $apiKey]);
$apiUser = $userStmt->fetch();

if (!$apiUser) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid API key provided']);
    exit;
}

if ($apiUser['status'] !== 'active') {
    http_response_code(403);
    echo json_encode(['error' => 'Account is suspended or banned']);
    exit;
}

$baseCurrency = get_base_currency();
$currencyCode = $baseCurrency['code'] ?? 'INR';

switch ($action) {
    // 1. SERVICES LIST
    case 'services':
        $sql = "
            SELECT 
                s.id AS service,
                s.name,
                c.name AS category,
                s.rate_per_1000 AS rate,
                s.min_quantity AS min,
                s.max_quantity AS max,
                s.service_type AS type,
                1 AS refill,
                0 AS cancel
            FROM services s
            JOIN categories c ON s.category_id = c.id
            WHERE s.status = 'active' AND c.status = 'active'
            ORDER BY c.sort_order ASC, s.id ASC
        ";
        $services = $db->query($sql)->fetchAll();

        $output = [];
        foreach ($services as $svc) {
            $output[] = [
                'service'  => (int)$svc['service'],
                'name'     => $svc['name'],
                'type'     => ucfirst($svc['type'] ?? 'Default'),
                'category' => $svc['category'],
                'rate'     => number_format((float)$svc['rate'], 4, '.', ''),
                'min'      => (int)$svc['min'],
                'max'      => (int)$svc['max'],
                'refill'   => (bool)$svc['refill'],
                'cancel'   => (bool)$svc['cancel']
            ];
        }

        echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;

    // 2. ADD ORDER
    case 'add':
        $serviceId = (int)($_POST['service'] ?? 0);
        $link = trim((string)($_POST['link'] ?? ''));
        $quantity = (int)($_POST['quantity'] ?? 0);
        $comments = trim((string)($_POST['comments'] ?? ''));

        if ($serviceId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Service ID is required']);
            exit;
        }

        if (empty($link) || !filter_var($link, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Valid target URL link is required']);
            exit;
        }

        // Fetch service
        $svcStmt = $db->prepare("SELECT * FROM services WHERE id = :id AND status = 'active' LIMIT 1");
        $svcStmt->execute(['id' => $serviceId]);
        $service = $svcStmt->fetch();

        if (!$service) {
            http_response_code(404);
            echo json_encode(['error' => 'Service not found or inactive']);
            exit;
        }

        if ($quantity < (int)$service['min_quantity'] || $quantity > (int)$service['max_quantity']) {
            http_response_code(400);
            echo json_encode(['error' => "Quantity must be between {$service['min_quantity']} and {$service['max_quantity']}"]);
            exit;
        }

        $ratePer1000 = (float)$service['rate_per_1000'];
        $charge = round(($quantity / 1000) * $ratePer1000, 4);

        // Atomic transaction with concurrency safety
        $db->beginTransaction();
        try {
            // Deduct balance atomically
            $deduct = $db->prepare("
                UPDATE users 
                SET balance = balance - :charge, spent = spent + :charge 
                WHERE id = :uid AND balance >= :charge
            ");
            $deduct->execute(['charge' => $charge, 'uid' => $apiUser['id']]);

            if ($deduct->rowCount() === 0) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Not enough funds on balance']);
                exit;
            }

            // Insert order
            $insOrder = $db->prepare("
                INSERT INTO orders (user_id, service_id, provider_id, link, quantity, charge, start_count, remains, status, mode)
                VALUES (:uid, :sid, :pid, :link, :qty, :charge, 0, :remains, 'processing', 'auto')
            ");
            $insOrder->execute([
                'uid' => $apiUser['id'],
                'sid' => $service['id'],
                'pid' => $service['provider_id'] ?: null,
                'link' => $link,
                'qty' => $quantity,
                'charge' => $charge,
                'remains' => $quantity
            ]);

            $orderId = (int)$db->lastInsertId();

            // Insert transaction record
            $insTxn = $db->prepare("
                INSERT INTO transactions (user_id, order_id, type, amount, gateway, gateway_txn_id, status, note)
                VALUES (:uid, :oid, 'order', :amt, 'system', :txnid, 'completed', :note)
            ");
            $insTxn->execute([
                'uid' => $apiUser['id'],
                'oid' => $orderId,
                'amt' => -$charge,
                'txnid' => 'api_ord_' . $orderId,
                'note' => "API Order #{$orderId}: {$service['name']}"
            ]);

            $db->commit();

            // Trigger referral commission if first order trigger configured
            process_referral_commission((int)$apiUser['id'], $charge, 'order', null, $orderId);

            echo json_encode(['order' => $orderId]);
            exit;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['error' => 'Order creation failed: ' . $e->getMessage()]);
            exit;
        }

    // 3. ORDER STATUS / MULTI-STATUS
    case 'status':
        if (!empty($_POST['orders'])) {
            // Multi-status query
            $orderIdsStr = (string)$_POST['orders'];
            $orderIds = array_filter(array_map('intval', explode(',', $orderIdsStr)));

            if (empty($orderIds)) {
                http_response_code(400);
                echo json_encode(['error' => 'Incorrect order ID list']);
                exit;
            }

            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $stmt = $db->prepare("
                SELECT id, charge, start_count, status, remains 
                FROM orders 
                WHERE user_id = ? AND id IN ({$placeholders})
            ");
            $params = array_merge([(int)$apiUser['id']], $orderIds);
            $stmt->execute($params);
            $orders = $stmt->fetchAll();

            $statusMap = [
                'pending'     => 'Pending',
                'processing'  => 'In progress',
                'in_progress' => 'In progress',
                'completed'   => 'Completed',
                'partial'     => 'Partial',
                'cancelled'   => 'Canceled'
            ];

            $result = [];
            foreach ($orderIds as $oid) {
                $found = null;
                foreach ($orders as $o) {
                    if ((int)$o['id'] === $oid) {
                        $found = $o;
                        break;
                    }
                }

                if ($found) {
                    $result[(string)$oid] = [
                        'charge'      => number_format((float)$found['charge'], 4, '.', ''),
                        'start_count' => (string)$found['start_count'],
                        'status'      => $statusMap[$found['status']] ?? 'Pending',
                        'remains'     => (string)$found['remains'],
                        'currency'    => $currencyCode
                    ];
                } else {
                    $result[(string)$oid] = ['error' => 'Incorrect order ID'];
                }
            }

            echo json_encode($result, JSON_PRETTY_PRINT);
            exit;
        } else {
            // Single status query
            $orderId = (int)($_POST['order'] ?? $_GET['order'] ?? 0);
            if ($orderId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Order ID is required']);
                exit;
            }

            $stmt = $db->prepare("SELECT id, charge, start_count, status, remains FROM orders WHERE id = :id AND user_id = :uid LIMIT 1");
            $stmt->execute(['id' => $orderId, 'uid' => $apiUser['id']]);
            $ord = $stmt->fetch();

            if (!$ord) {
                http_response_code(404);
                echo json_encode(['error' => 'Incorrect order ID']);
                exit;
            }

            $statusMap = [
                'pending'     => 'Pending',
                'processing'  => 'In progress',
                'in_progress' => 'In progress',
                'completed'   => 'Completed',
                'partial'     => 'Partial',
                'cancelled'   => 'Canceled'
            ];

            echo json_encode([
                'charge'      => number_format((float)$ord['charge'], 4, '.', ''),
                'start_count' => (string)$ord['start_count'],
                'status'      => $statusMap[$ord['status']] ?? 'Pending',
                'remains'     => (string)$ord['remains'],
                'currency'    => $currencyCode
            ]);
            exit;
        }

    // 4. USER BALANCE
    case 'balance':
        echo json_encode([
            'balance'  => number_format((float)$apiUser['balance'], 4, '.', ''),
            'currency' => $currencyCode
        ]);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Incorrect action parameter. Available actions: services, add, status, balance']);
        exit;
}
