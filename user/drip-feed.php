<?php
/**
 * User Drip-feed Order Portal & Scheduling Engine
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireLogin();
$user = Auth::user();
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
    // Ignore if table exists
}

// Fetch active services
$servicesStmt = $db->query("
    SELECT s.*, c.name AS category_name, c.platform 
    FROM services s 
    JOIN categories c ON s.category_id = c.id 
    WHERE s.status = 'active' 
    ORDER BY c.sort_order ASC, s.id ASC
");
$services = $servicesStmt->fetchAll();

$error = null;

// Handle Schedule Submission or Cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? 'create_schedule';

    // 1. Cancel Active Schedule
    if ($action === 'cancel_schedule') {
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        $schedStmt = $db->prepare("SELECT * FROM drip_feed_orders WHERE id = :id AND user_id = :uid LIMIT 1 FOR UPDATE");
        
        $db->beginTransaction();
        try {
            $schedStmt->execute(['id' => $scheduleId, 'uid' => $user['id']]);
            $schedule = $schedStmt->fetch();

            if (!$schedule || !in_array($schedule['status'], ['queued', 'running'], true)) {
                throw new Exception("Schedule cannot be cancelled or is already finished.");
            }

            $unexecutedRuns = (int)$schedule['total_runs'] - (int)$schedule['completed_runs'] - (int)$schedule['failed_runs'];
            $refundAmount = 0.0;

            if ($unexecutedRuns > 0) {
                $refundAmount = round($unexecutedRuns * (float)$schedule['charge_per_run'], 4);
                
                // Refund wallet
                $db->prepare("UPDATE users SET balance = balance + :amt WHERE id = :uid")->execute([
                    'amt' => $refundAmount,
                    'uid' => $user['id']
                ]);

                // Record transaction
                $db->prepare("
                    INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, 'refund', :amt, 'system', :txnid, 'completed', :note)
                ")->execute([
                    'uid' => $user['id'],
                    'amt' => $refundAmount,
                    'txnid' => 'REFUND-DRIP-' . $scheduleId,
                    'note' => "Refund for {$unexecutedRuns} cancelled runs of Drip-feed #{$scheduleId}"
                ]);
            }

            // Cancel pending runs
            $db->prepare("UPDATE drip_feed_runs SET status = 'cancelled' WHERE drip_feed_id = :jid AND status = 'pending'")->execute(['jid' => $scheduleId]);

            // Update parent status
            $db->prepare("
                UPDATE drip_feed_orders 
                SET status = 'cancelled', refunded_amount = refunded_amount + :ref
                WHERE id = :id
            ")->execute(['ref' => $refundAmount, 'id' => $scheduleId]);

            $db->commit();
            set_flash('success', "Drip-feed Schedule #{$scheduleId} cancelled. Refunded " . format_currency($refundAmount) . " for {$unexecutedRuns} unexecuted runs.");
            redirect('/user/drip-feed.php');
        } catch (\Throwable $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
    }

    // 2. Create New Drip-feed Schedule
    if ($action === 'create_schedule') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $link = trim($_POST['link'] ?? '');
        $qtyPerRun = (int)($_POST['quantity_per_run'] ?? 0);
        $runs = (int)($_POST['runs'] ?? 0);
        $interval = (int)($_POST['interval'] ?? 0); // minutes

        $svcStmt = $db->prepare("SELECT * FROM services WHERE id = :id AND status = 'active' LIMIT 1");
        $svcStmt->execute(['id' => $serviceId]);
        $service = $svcStmt->fetch();

        if (!$service) {
            $error = "Selected service is invalid or unavailable.";
        } elseif (empty($link) || !filter_var($link, FILTER_VALIDATE_URL)) {
            $error = "Please enter a valid target URL (e.g. https://instagram.com/profile).";
        } elseif ($qtyPerRun < (int)$service['min_quantity'] || $qtyPerRun > (int)$service['max_quantity']) {
            $error = "Quantity per run must be between {$service['min_quantity']} and {$service['max_quantity']}.";
        } elseif ($runs < 2 || $runs > 100) {
            $error = "Drip-feed runs must be between 2 and 100 cycles.";
        } elseif ($interval < 10 || $interval > 1440) {
            $error = "Interval must be between 10 minutes and 1440 minutes (24 hours).";
        } else {
            $ratePer1000 = (float)$service['rate_per_1000'];
            $chargePerRun = round(($qtyPerRun / 1000) * $ratePer1000, 4);
            $totalPlannedQty = $qtyPerRun * $runs;
            $totalCharge = round($chargePerRun * $runs, 4);

            if ($user['balance'] < $totalCharge) {
                $error = "Insufficient balance. Total planned cost is " . format_currency($totalCharge) . ", but your available balance is " . format_currency($user['balance']) . ".";
            } else {
                $db->beginTransaction();
                try {
                    // Deduct total planned charge from wallet atomically
                    $deduct = $db->prepare("UPDATE users SET balance = balance - :charge, spent = spent + :charge WHERE id = :uid AND balance >= :charge");
                    $deduct->execute(['charge' => $totalCharge, 'uid' => $user['id']]);

                    if ($deduct->rowCount() === 0) {
                        throw new Exception("Balance deduction failed due to insufficient funds.");
                    }

                    // Insert parent drip-feed schedule
                    $insDrip = $db->prepare("
                        INSERT INTO drip_feed_orders (
                            user_id, service_id, link, quantity_per_run, total_runs, 
                            completed_runs, failed_runs, interval_minutes, charge_per_run, 
                            total_charge, status, next_run_at
                        ) VALUES (
                            :uid, :sid, :link, :qpr, :runs,
                            0, 0, :interval, :cpr,
                            :tot, 'queued', NOW()
                        )
                    ");
                    $insDrip->execute([
                        'uid' => $user['id'],
                        'sid' => $service['id'],
                        'link' => $link,
                        'qpr' => $qtyPerRun,
                        'runs' => $runs,
                        'interval' => $interval,
                        'cpr' => $chargePerRun,
                        'tot' => $totalCharge
                    ]);
                    $dripId = (int)$db->lastInsertId();

                    // Pre-generate run placeholders
                    $insRun = $db->prepare("
                        INSERT INTO drip_feed_runs (drip_feed_id, run_number, status, scheduled_at)
                        VALUES (:did, :num, 'pending', :sched)
                    ");

                    for ($i = 1; $i <= $runs; $i++) {
                        $schedTime = date('Y-m-d H:i:s', strtotime("+" . (($i - 1) * $interval) . " minutes"));
                        $insRun->execute([
                            'did' => $dripId,
                            'num' => $i,
                            'sched' => $schedTime
                        ]);
                    }

                    // Record initial ledger transaction
                    $insTxn = $db->prepare("
                        INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                        VALUES (:uid, 'order', :amt, 'system', :txnid, 'completed', :note)
                    ");
                    $insTxn->execute([
                        'uid' => $user['id'],
                        'amt' => -$totalCharge,
                        'txnid' => 'DRIP-' . $dripId,
                        'note' => "Drip-feed Schedule #{$dripId}: {$runs} runs of {$qtyPerRun} every {$interval}m ({$service['name']})"
                    ]);

                    // Execute Run #1 immediately for responsive user experience
                    $insOrder = $db->prepare("
                        INSERT INTO orders (user_id, service_id, provider_id, link, quantity, charge, start_count, remains, status, mode)
                        VALUES (:uid, :sid, :pid, :link, :qty, :charge, 0, :remains, 'processing', 'auto')
                    ");
                    $insOrder->execute([
                        'uid' => $user['id'],
                        'sid' => $service['id'],
                        'pid' => $service['provider_id'] ?: null,
                        'link' => $link,
                        'qty' => $qtyPerRun,
                        'charge' => $chargePerRun,
                        'remains' => $qtyPerRun
                    ]);
                    $firstOrderId = (int)$db->lastInsertId();

                    // Dispatch provider if configured
                    $providerOrderId = null;
                    if (!empty($service['provider_id'])) {
                        $pStmt = $db->prepare("SELECT api_url, api_key FROM providers WHERE id = :pid LIMIT 1");
                        $pStmt->execute(['pid' => $service['provider_id']]);
                        $prov = $pStmt->fetch();
                        if ($prov && !empty($service['provider_service_id'])) {
                            $apiResp = call_provider_api($prov['api_url'], [
                                'key' => $prov['api_key'],
                                'action' => 'add',
                                'service' => $service['provider_service_id'],
                                'link' => $link,
                                'quantity' => $qtyPerRun
                            ]);
                            if (!empty($apiResp['order'])) {
                                $providerOrderId = (string)$apiResp['order'];
                                $db->prepare("UPDATE orders SET provider_order_id = :poid WHERE id = :id")->execute([
                                    'poid' => $providerOrderId,
                                    'id' => $firstOrderId
                                ]);
                            }
                        }
                    }

                    // Update Run #1 status
                    $db->prepare("
                        UPDATE drip_feed_runs 
                        SET order_id = :oid, status = 'completed', provider_order_id = :poid, executed_at = NOW()
                        WHERE drip_feed_id = :did AND run_number = 1
                    ")->execute([
                        'oid' => $firstOrderId,
                        'poid' => $providerOrderId,
                        'did' => $dripId
                    ]);

                    // Update parent schedule progress
                    $nextRunTime = date('Y-m-d H:i:s', strtotime("+{$interval} minutes"));
                    $db->prepare("
                        UPDATE drip_feed_orders 
                        SET completed_runs = 1, last_run_at = NOW(), next_run_at = :nxt, status = 'queued'
                        WHERE id = :id
                    ")->execute([
                        'nxt' => $nextRunTime,
                        'id' => $dripId
                    ]);

                    $db->commit();
                    set_flash('success', "Drip-feed Schedule #{$dripId} started! Run 1/ {$runs} executed immediately (Order #{$firstOrderId}). Next run in {$interval}m.");
                    redirect('/user/drip-feed.php');
                } catch (\Throwable $e) {
                    $db->rollBack();
                    $error = "Failed to schedule drip-feed order: " . $e->getMessage();
                }
            }
        }
    }
}

// Fetch user's existing drip-feed schedules
$schedulesStmt = $db->prepare("
    SELECT d.*, s.name AS service_name, s.speed
    FROM drip_feed_orders d
    JOIN services s ON d.service_id = s.id
    WHERE d.user_id = :uid
    ORDER BY d.id DESC
    LIMIT 30
");
$schedulesStmt->execute(['uid' => $user['id']]);
$userSchedules = $schedulesStmt->fetchAll();

$pageTitle = "Drip-feed Order - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto my-6 space-y-8">
    <div class="flex items-center justify-between">
        <a href="/user/dashboard.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Back to Dashboard</span>
        </a>
        <div class="text-xs font-bold text-slate-500">
            Current Balance: <strong class="text-slate-900 font-mono-nums text-sm"><?= format_currency($user['balance']) ?></strong>
        </div>
    </div>

    <?= render_flash() ?>

    <?php if ($error): ?>
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold flex items-center gap-2">
            <span>⚠️</span>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- 1. Drip-feed Order Form -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div>
            <div class="flex items-center gap-2">
                <span class="text-2xl">💧</span>
                <h1 class="text-xl font-black text-slate-900 tracking-tight">Schedule Drip-feed Order</h1>
            </div>
            <p class="text-xs text-slate-500 mt-1">Automatically deliver your orders in gradual, natural batches over time to protect algorithm retention</p>
        </div>

        <form action="/user/drip-feed.php" method="POST" id="dripForm" class="space-y-5">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="create_schedule">

            <div>
                <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-2">Service</label>
                <select name="service_id" id="svcSelect" required onchange="recalculateDrip()" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs sm:text-sm font-semibold text-slate-800 focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 cursor-pointer">
                    <?php foreach ($services as $svc): ?>
                        <option value="<?= $svc['id'] ?>"
                                data-rate="<?= (float)$svc['rate_per_1000'] ?>"
                                data-min="<?= (int)$svc['min_quantity'] ?>"
                                data-max="<?= (int)$svc['max_quantity'] ?>">
                            [<?= ucfirst($svc['platform']) ?>] <?= e($svc['name']) ?> — <?= format_currency($svc['rate_per_1000']) ?> / 1K (Min: <?= number_format($svc['min_quantity']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-2">Destination Link</label>
                <input type="url" name="link" placeholder="https://instagram.com/yourprofile" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs sm:text-sm font-medium focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-2">Quantity Per Run</label>
                    <input type="number" name="quantity_per_run" id="qprInput" value="500" min="10" required oninput="recalculateDrip()" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs sm:text-sm font-black font-mono-nums">
                </div>
                <div>
                    <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-2">Total Runs (Cycles)</label>
                    <input type="number" name="runs" id="runsInput" value="5" min="2" max="100" required oninput="recalculateDrip()" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs sm:text-sm font-black font-mono-nums">
                    <span class="text-[10px] text-slate-400 mt-1 block">Between 2 and 100 runs</span>
                </div>
                <div>
                    <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-2">Interval (Minutes)</label>
                    <input type="number" name="interval" id="intervalInput" value="60" min="10" max="1440" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs sm:text-sm font-black font-mono-nums">
                    <span class="text-[10px] text-slate-400 mt-1 block">Min 10m, Max 1440m (24h)</span>
                </div>
            </div>

            <!-- Calculation Summary Panel -->
            <div class="p-4 rounded-2xl bg-cyan-50/50 border border-cyan-200/80 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 text-xs">
                <div>
                    <span class="text-cyan-900 font-bold block">Delivery Schedule Plan:</span>
                    <span class="text-slate-600">Total Planned Delivery: <strong id="planQty" class="text-slate-900 font-mono">2,500</strong> items across <strong id="planRuns" class="text-slate-900 font-mono">5</strong> runs</span>
                </div>
                <div class="text-left sm:text-right">
                    <span class="text-slate-400 font-bold uppercase text-[10px] block">Estimated Total Cost:</span>
                    <span id="planCost" class="text-lg font-black text-cyan-800 font-mono-nums">₹0.00</span>
                </div>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-end">
                <button type="submit" class="px-8 py-3.5 bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-700 hover:to-blue-700 text-white font-extrabold text-xs sm:text-sm rounded-2xl shadow-lg shadow-cyan-500/25 active:scale-95 transition-all cursor-pointer">
                    ⚡ Start Drip-feed Schedule Now
                </button>
            </div>
        </form>
    </div>

    <!-- 2. Active & Past Drip-feed Schedules Ledger -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-5">
        <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
            <div>
                <h2 class="text-base font-extrabold text-slate-900 tracking-tight">Your Scheduled Drip-feeds</h2>
                <p class="text-xs text-slate-400 mt-0.5">Real-time execution status, batch progress, and cancellation controls</p>
            </div>
            <span class="text-xs font-mono font-bold text-slate-500"><?= count($userSchedules) ?> total</span>
        </div>

        <?php if (empty($userSchedules)): ?>
            <div class="p-8 text-center text-slate-400 text-xs">
                No active or past drip-feed schedules found. Use the form above to schedule your first batch!
            </div>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($userSchedules as $sched): 
                    $pct = ($sched['total_runs'] > 0) ? round(($sched['completed_runs'] / $sched['total_runs']) * 100) : 0;
                    $isCancellable = in_array($sched['status'], ['queued', 'running'], true);
                    $badgeStyle = match($sched['status']) {
                        'completed' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                        'running', 'queued' => 'bg-blue-50 text-blue-700 border-blue-200 animate-pulse',
                        'cancelled' => 'bg-slate-100 text-slate-600 border-slate-200',
                        'partially_failed' => 'bg-amber-50 text-amber-700 border-amber-200',
                        default => 'bg-rose-50 text-rose-700 border-rose-200'
                    };
                ?>
                    <div class="p-5 rounded-2xl border border-slate-200/80 bg-slate-50/50 space-y-3">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-mono font-bold text-slate-400">#<?= $sched['id'] ?></span>
                                    <h3 class="text-sm font-extrabold text-slate-900"><?= e($sched['service_name']) ?></h3>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase border <?= $badgeStyle ?>">
                                        <?= e($sched['status']) ?>
                                    </span>
                                </div>
                                <div class="text-[11px] text-slate-500 font-mono truncate max-w-md mt-0.5">
                                    Target: <a href="<?= e($sched['link']) ?>" target="_blank" class="text-blue-600 hover:underline"><?= e($sched['link']) ?></a>
                                </div>
                            </div>

                            <div class="text-left sm:text-right">
                                <span class="text-sm font-black text-slate-900 font-mono-nums"><?= format_currency($sched['total_charge']) ?></span>
                                <span class="text-[10px] text-slate-400 block"><?= $sched['quantity_per_run'] ?> per run &bull; every <?= $sched['interval_minutes'] ?>m</span>
                            </div>
                        </div>

                        <!-- Progress Bar -->
                        <div class="space-y-1">
                            <div class="flex items-center justify-between text-[11px] font-bold text-slate-600">
                                <span>Progress: <?= $sched['completed_runs'] ?> / <?= $sched['total_runs'] ?> Runs Completed</span>
                                <span><?= $pct ?>%</span>
                            </div>
                            <div class="w-full h-2 rounded-full bg-slate-200 overflow-hidden">
                                <div class="h-full bg-blue-600 rounded-full transition-all" style="width: <?= $pct ?>%;"></div>
                            </div>
                        </div>

                        <!-- Footer details and Cancel Action -->
                        <div class="flex items-center justify-between pt-2 border-t border-slate-200/60 text-[11px] text-slate-400">
                            <div>
                                <?php if ($sched['status'] === 'queued' || $sched['status'] === 'running'): ?>
                                    <span>Next run: <strong class="text-slate-700"><?= date('H:i, d M', strtotime($sched['next_run_at'])) ?></strong></span>
                                <?php elseif ($sched['status'] === 'cancelled'): ?>
                                    <span>Cancelled (Refunded: <?= format_currency($sched['refunded_amount']) ?>)</span>
                                <?php else: ?>
                                    <span>Finished on: <?= date('d M Y, H:i', strtotime($sched['updated_at'])) ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if ($isCancellable): ?>
                                <form action="/user/drip-feed.php" method="POST" onsubmit="return confirm('Cancel remaining unexecuted runs? Unexecuted balance will be automatically refunded.')">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="cancel_schedule">
                                    <input type="hidden" name="schedule_id" value="<?= (int)$sched['id'] ?>">
                                    <button type="submit" class="px-3 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-lg text-xs font-bold transition-all cursor-pointer">
                                        Cancel Remaining
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function recalculateDrip() {
    const sel = document.getElementById('svcSelect');
    const opt = sel.options[sel.selectedIndex];
    if (!opt) return;

    const rate = parseFloat(opt.getAttribute('data-rate') || 0);
    const qpr = parseInt(document.getElementById('qprInput').value) || 0;
    const runs = parseInt(document.getElementById('runsInput').value) || 0;

    const totalQty = qpr * runs;
    const totalCost = (totalQty / 1000) * rate;

    document.getElementById('planQty').innerText = totalQty.toLocaleString();
    document.getElementById('planRuns').innerText = runs.toString();
    document.getElementById('planCost').innerText = '<?= e(app_currency()) ?>' + totalCost.toFixed(2);
}

document.addEventListener('DOMContentLoaded', recalculateDrip);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
