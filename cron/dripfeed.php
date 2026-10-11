<?php
/**
 * Cron Job: Drip-feed Order Scheduled Runner
 * SMM Panel - PHP 8.3+
 * Usage: php cron/dripfeed.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli' && empty($_GET['cron_key'])) {
    http_response_code(403);
    die("CLI execution only or valid cron_key parameter required.\n");
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting Drip-feed Scheduled Dispatcher...\n";

$db = Database::getConnection();

// Ensure drip feed tables exist
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `drip_feed_orders` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT UNSIGNED NOT NULL,
          `service_id` INT UNSIGNED NOT NULL,
          `link` VARCHAR(512) NOT NULL,
          `quantity_per_run` INT UNSIGNED NOT NULL,
          `total_runs` INT UNSIGNED NOT NULL,
          `completed_runs` INT UNSIGNED NOT NULL DEFAULT 0,
          `failed_runs` INT UNSIGNED NOT NULL DEFAULT 0,
          `interval_minutes` INT UNSIGNED NOT NULL DEFAULT 60,
          `charge_per_run` DECIMAL(10, 4) NOT NULL,
          `total_charge` DECIMAL(10, 4) NOT NULL,
          `refunded_amount` DECIMAL(10, 4) NOT NULL DEFAULT 0.0000,
          `status` ENUM('queued', 'running', 'completed', 'partially_failed', 'cancelled', 'failed') NOT NULL DEFAULT 'queued',
          `last_run_at` DATETIME NULL,
          `next_run_at` DATETIME NOT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX `idx_drip_status_next` (`status`, `next_run_at`),
          INDEX `idx_drip_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `drip_feed_runs` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `drip_feed_id` INT UNSIGNED NOT NULL,
          `run_number` INT UNSIGNED NOT NULL,
          `order_id` INT UNSIGNED NULL,
          `status` ENUM('pending', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
          `provider_order_id` VARCHAR(64) NULL,
          `error_message` TEXT NULL,
          `scheduled_at` DATETIME NOT NULL,
          `executed_at` DATETIME NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_drip_run_schedule` (`drip_feed_id`, `run_number`),
          INDEX `idx_drip_run_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (\Throwable $e) {
    // Ignore if tables exist
}

// Find due drip-feed orders
$dueStmt = $db->prepare("
    SELECT d.*, s.name AS service_name, s.provider_id, s.provider_service_id, s.status AS service_status,
           p.api_url, p.api_key
    FROM drip_feed_orders d
    JOIN services s ON d.service_id = s.id
    LEFT JOIN providers p ON s.provider_id = p.id
    WHERE d.status IN ('queued', 'running')
      AND d.next_run_at <= NOW()
    LIMIT 25
");
$dueStmt->execute();
$dueJobs = $dueStmt->fetchAll();

if (empty($dueJobs)) {
    echo "No due drip-feed jobs found.\n";
    exit;
}

foreach ($dueJobs as $job) {
    $jobId = (int)$job['id'];
    echo "Processing Drip-feed Job #{$jobId} (Service: {$job['service_name']})...\n";

    // Concurrency lock: mark this specific run as running
    $runStmt = $db->prepare("
        SELECT * FROM drip_feed_runs
        WHERE drip_feed_id = :jid AND status = 'pending'
        ORDER BY run_number ASC
        LIMIT 1
    ");
    $runStmt->execute(['jid' => $jobId]);
    $run = $runStmt->fetch();

    if (!$run) {
        // No pending runs left
        $newStatus = ($job['failed_runs'] > 0) ? 'partially_failed' : 'completed';
        $db->prepare("UPDATE drip_feed_orders SET status = :st WHERE id = :id")->execute(['st' => $newStatus, 'id' => $jobId]);
        echo " -> No pending runs remaining. Marked job as {$newStatus}.\n";
        continue;
    }

    $runId = (int)$run['id'];
    $runNum = (int)$run['run_number'];

    // Lock run
    $lockStmt = $db->prepare("UPDATE drip_feed_runs SET status = 'running' WHERE id = :rid AND status = 'pending'");
    $lockStmt->execute(['rid' => $runId]);
    if ($lockStmt->rowCount() === 0) {
        echo " -> Run #{$runNum} already picked up by another process. Skipping.\n";
        continue;
    }

    // Check service active
    if ($job['service_status'] !== 'active') {
        echo " -> Service #{$job['service_id']} is no longer active. Marking run as failed.\n";
        $db->prepare("UPDATE drip_feed_runs SET status = 'failed', error_message = 'Service inactive', executed_at = NOW() WHERE id = :rid")->execute(['rid' => $runId]);
        $db->prepare("UPDATE drip_feed_orders SET failed_runs = failed_runs + 1 WHERE id = :jid")->execute(['jid' => $jobId]);
        continue;
    }

    $db->beginTransaction();
    try {
        // 1. Create standard order row
        $insOrder = $db->prepare("
            INSERT INTO orders (user_id, service_id, provider_id, link, quantity, charge, start_count, remains, status, mode)
            VALUES (:uid, :sid, :pid, :link, :qty, :charge, 0, :remains, 'processing', 'auto')
        ");
        $insOrder->execute([
            'uid' => $job['user_id'],
            'sid' => $job['service_id'],
            'pid' => $job['provider_id'] ?: null,
            'link' => $job['link'],
            'qty' => $job['quantity_per_run'],
            'charge' => $job['charge_per_run'],
            'remains' => $job['quantity_per_run']
        ]);
        $newOrderId = (int)$db->lastInsertId();

        // 2. Dispatch to provider if configured
        $providerOrderId = null;
        $orderStatus = 'processing';
        $runError = null;

        if (!empty($job['provider_id']) && !empty($job['api_url']) && !empty($job['api_key']) && !empty($job['provider_service_id'])) {
            $apiResp = call_provider_api($job['api_url'], [
                'key' => $job['api_key'],
                'action' => 'add',
                'service' => $job['provider_service_id'],
                'link' => $job['link'],
                'quantity' => $job['quantity_per_run']
            ]);

            if (!empty($apiResp['order'])) {
                $providerOrderId = (string)$apiResp['order'];
                $db->prepare("UPDATE orders SET provider_order_id = :poid, status = 'processing' WHERE id = :oid")->execute([
                    'poid' => $providerOrderId,
                    'oid' => $newOrderId
                ]);
            } elseif (!empty($apiResp['error'])) {
                $runError = is_string($apiResp['error']) ? $apiResp['error'] : 'Provider rejected order';
                $db->prepare("UPDATE orders SET status = 'pending' WHERE id = :oid")->execute(['oid' => $newOrderId]);
            }
        }

        // 3. Update run record
        $db->prepare("
            UPDATE drip_feed_runs 
            SET order_id = :oid, status = 'completed', provider_order_id = :poid, error_message = :err, executed_at = NOW()
            WHERE id = :rid
        ")->execute([
            'oid' => $newOrderId,
            'poid' => $providerOrderId,
            'err' => $runError,
            'rid' => $runId
        ]);

        // 4. Update parent drip feed order progress
        $nextRunNumber = $runNum + 1;
        $allFinished = ($nextRunNumber > (int)$job['total_runs']);
        $nextRunTime = date('Y-m-d H:i:s', strtotime("+{$job['interval_minutes']} minutes"));

        if ($allFinished) {
            $db->prepare("
                UPDATE drip_feed_orders 
                SET completed_runs = completed_runs + 1, last_run_at = NOW(), status = 'completed'
                WHERE id = :jid
            ")->execute(['jid' => $jobId]);
        } else {
            $db->prepare("
                UPDATE drip_feed_orders 
                SET completed_runs = completed_runs + 1, last_run_at = NOW(), next_run_at = :nxt, status = 'queued'
                WHERE id = :jid
            ")->execute(['nxt' => $nextRunTime, 'jid' => $jobId]);
        }

        $db->commit();
        echo " -> Run #{$runNum} completed successfully! Created Order #{$newOrderId}.\n";
    } catch (\Throwable $e) {
        $db->rollBack();
        $db->prepare("UPDATE drip_feed_runs SET status = 'failed', error_message = :err WHERE id = :rid")->execute([
            'err' => $e->getMessage(),
            'rid' => $runId
        ]);
        $db->prepare("UPDATE drip_feed_orders SET failed_runs = failed_runs + 1, status = 'queued' WHERE id = :jid")->execute(['jid' => $jobId]);
        echo " -> Run #{$runNum} encountered error: " . $e->getMessage() . "\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Drip-feed dispatch completed.\n";
