<?php
/**
 * User Mass Order Batch Processing Engine
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

$error = null;
$batchResults = [];
$massInput = '';

// Handle Mass Order Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();

    $massInput = trim($_POST['mass_order_data'] ?? '');
    if (empty($massInput)) {
        $error = "Please enter at least one order line.";
    } else {
        $lines = preg_split('/\r\n|\r|\n/', $massInput);
        $parsedOrders = [];
        $totalRequiredCharge = 0.0;
        $lineErrors = [];

        // Preload active services with category and provider details
        $servicesStmt = $db->query("
            SELECT s.*, p.api_url, p.api_key, p.status AS provider_status 
            FROM services s 
            LEFT JOIN providers p ON s.provider_id = p.id 
            WHERE s.status = 'active'
        ");
        $activeServices = [];
        while ($s = $servicesStmt->fetch()) {
            $activeServices[(int)$s['id']] = $s;
        }

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $lineIndex = $lineNum + 1;
            $parts = array_map('trim', explode('|', $line));

            if (count($parts) < 3) {
                $lineErrors[$lineIndex] = "Invalid format. Expected: service_id | link | quantity";
                continue;
            }

            $serviceId = (int)$parts[0];
            $link = $parts[1];
            $quantity = (int)$parts[2];

            if (!isset($activeServices[$serviceId])) {
                $lineErrors[$lineIndex] = "Service ID #{$serviceId} does not exist or is inactive.";
                continue;
            }

            $svc = $activeServices[$serviceId];

            if (!filter_var($link, FILTER_VALIDATE_URL)) {
                $lineErrors[$lineIndex] = "Invalid target URL '{$link}'.";
                continue;
            }

            if ($quantity < (int)$svc['min_quantity'] || $quantity > (int)$svc['max_quantity']) {
                $lineErrors[$lineIndex] = "Quantity {$quantity} out of allowed limits ({$svc['min_quantity']} - {$svc['max_quantity']}) for '{$svc['name']}'.";
                continue;
            }

            $charge = round(($quantity / 1000) * (float)$svc['rate_per_1000'], 4);
            $totalRequiredCharge += $charge;

            $parsedOrders[] = [
                'line' => $lineIndex,
                'service' => $svc,
                'link' => $link,
                'quantity' => $quantity,
                'charge' => $charge
            ];
        }

        if (empty($parsedOrders)) {
            $error = "No valid order lines could be processed. Please check formatting.";
        } elseif ($user['balance'] < $totalRequiredCharge) {
            $error = "Insufficient balance. Total cost for " . count($parsedOrders) . " valid order(s) is " . format_currency($totalRequiredCharge) . ", but your available balance is " . format_currency($user['balance']) . ".";
        } else {
            // Process valid orders with per-line tracking and partial-failure reconciliation
            $db->beginTransaction();
            try {
                // Deduct maximum required balance initially
                $deduct = $db->prepare("UPDATE users SET balance = balance - :charge, spent = spent + :charge WHERE id = :uid AND balance >= :charge");
                $deduct->execute(['charge' => $totalRequiredCharge, 'uid' => $user['id']]);

                if ($deduct->rowCount() === 0) {
                    throw new Exception("Balance deduction failed due to concurrent update.");
                }

                $orderInsert = $db->prepare("
                    INSERT INTO orders (user_id, service_id, provider_id, link, quantity, charge, start_count, remains, status, mode)
                    VALUES (:uid, :sid, :pid, :link, :qty, :charge, 0, :remains, 'processing', 'auto')
                ");

                $txnInsert = $db->prepare("
                    INSERT INTO transactions (user_id, order_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, :oid, 'order', :amt, 'system', :txnid, 'completed', :note)
                ");

                $successfulChargeTotal = 0.0;
                $successfulOrdersCount = 0;
                $failedChargeTotal = 0.0;

                foreach ($parsedOrders as $ord) {
                    $svc = $ord['service'];
                    $providerOrderId = null;
                    $lineSuccess = true;
                    $failureReason = null;

                    // If service has active provider API, attempt live dispatch
                    if (!empty($svc['provider_id']) && !empty($svc['api_url']) && !empty($svc['api_key']) && !empty($svc['provider_service_id'])) {
                        $apiResp = call_provider_api($svc['api_url'], [
                            'key' => $svc['api_key'],
                            'action' => 'add',
                            'service' => $svc['provider_service_id'],
                            'link' => $ord['link'],
                            'quantity' => $ord['quantity']
                        ]);

                        if (!empty($apiResp['order'])) {
                            $providerOrderId = (string)$apiResp['order'];
                        } elseif (!empty($apiResp['error'])) {
                            // Provider rejected order
                            $lineSuccess = false;
                            $failureReason = is_string($apiResp['error']) ? $apiResp['error'] : 'Provider rejected order request';
                        }
                    }

                    if ($lineSuccess) {
                        $orderInsert->execute([
                            'uid' => $user['id'],
                            'sid' => $svc['id'],
                            'pid' => $svc['provider_id'] ?: null,
                            'link' => $ord['link'],
                            'qty' => $ord['quantity'],
                            'charge' => $ord['charge'],
                            'remains' => $ord['quantity']
                        ]);
                        $newOrderId = (int)$db->lastInsertId();

                        if ($providerOrderId) {
                            $db->prepare("UPDATE orders SET provider_order_id = :poid WHERE id = :id")->execute([
                                'poid' => $providerOrderId,
                                'id' => $newOrderId
                            ]);
                        }

                        $txnInsert->execute([
                            'uid' => $user['id'],
                            'oid' => $newOrderId,
                            'amt' => -$ord['charge'],
                            'txnid' => 'ORD-' . $newOrderId,
                            'note' => "Mass Order #{$newOrderId}: {$svc['name']}"
                        ]);

                        $successfulChargeTotal += $ord['charge'];
                        $successfulOrdersCount++;

                        $batchResults[] = [
                            'line' => $ord['line'],
                            'service_name' => $svc['name'],
                            'link' => $ord['link'],
                            'quantity' => $ord['quantity'],
                            'charge' => $ord['charge'],
                            'order_id' => $newOrderId,
                            'status' => 'success',
                            'message' => 'Order created successfully (#' . $newOrderId . ')'
                        ];
                    } else {
                        // Mark line as failed and do not charge user for it
                        $failedChargeTotal += $ord['charge'];
                        $batchResults[] = [
                            'line' => $ord['line'],
                            'service_name' => $svc['name'],
                            'link' => $ord['link'],
                            'quantity' => $ord['quantity'],
                            'charge' => $ord['charge'],
                            'order_id' => null,
                            'status' => 'failed',
                            'message' => $failureReason ?: 'Provider rejected order'
                        ];
                    }
                }

                // Append any formatting/validation failures
                foreach ($lineErrors as $lNum => $lMsg) {
                    $batchResults[] = [
                        'line' => $lNum,
                        'service_name' => 'N/A',
                        'link' => '—',
                        'quantity' => 0,
                        'charge' => 0.0,
                        'order_id' => null,
                        'status' => 'failed',
                        'message' => $lMsg
                    ];
                }

                // Partial Failure Reconciliation: Refund any uncharged/failed amounts
                if ($failedChargeTotal > 0.0001) {
                    $db->prepare("UPDATE users SET balance = balance + :refund, spent = spent - :refund WHERE id = :uid")->execute([
                        'refund' => $failedChargeTotal,
                        'uid' => $user['id']
                    ]);
                }

                $db->commit();

                // Sort results by line number
                usort($batchResults, fn($a, $b) => $a['line'] <=> $b['line']);

                if ($successfulOrdersCount > 0) {
                    set_flash('success', "Mass Order processed: {$successfulOrdersCount} orders placed (" . format_currency($successfulChargeTotal) . ").");
                } else {
                    set_flash('error', "No orders could be placed. Your balance was completely refunded.");
                }
            } catch (\Throwable $e) {
                $db->rollBack();
                $error = "Mass order transaction failed: " . $e->getMessage();
            }
        }
    }
}

// Popular services for quick reference guide
$popularServices = $db->query("
    SELECT s.id, s.name, s.rate_per_1000, s.min_quantity, s.max_quantity, c.platform 
    FROM services s 
    JOIN categories c ON s.category_id = c.id 
    WHERE s.status = 'active' 
    ORDER BY s.id ASC 
    LIMIT 15
")->fetchAll();

$pageTitle = "Mass Order - " . app_name();
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

    <!-- Batch Outcome Results Table (shown after submission) -->
    <?php if (!empty($batchResults)): ?>
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-4">
            <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-extrabold text-slate-900">Batch Submission Summary</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Truthful outcomes per submitted line with automated wallet reconciliation</p>
                </div>
                <span class="text-xs font-bold text-slate-600"><?= count($batchResults) ?> total lines parsed</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-slate-400 border-b border-slate-100 uppercase text-[10px] font-bold">
                            <th class="py-2.5 px-3">Line</th>
                            <th class="py-2.5 px-3">Service</th>
                            <th class="py-2.5 px-3">Target</th>
                            <th class="py-2.5 px-3 text-right">Qty</th>
                            <th class="py-2.5 px-3 text-right">Charge</th>
                            <th class="py-2.5 px-3 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($batchResults as $res): ?>
                            <tr>
                                <td class="py-3 px-3 font-mono font-bold text-slate-400">#<?= $res['line'] ?></td>
                                <td class="py-3 px-3 font-extrabold text-slate-800"><?= e($res['service_name']) ?></td>
                                <td class="py-3 px-3 font-mono text-[11px] text-slate-500 max-w-xs truncate"><?= e($res['link']) ?></td>
                                <td class="py-3 px-3 text-right font-mono font-bold text-slate-800"><?= number_format($res['quantity']) ?></td>
                                <td class="py-3 px-3 text-right font-mono font-extrabold <?= $res['status'] === 'success' ? 'text-slate-900' : 'text-slate-400 line-through' ?>"><?= format_currency($res['charge']) ?></td>
                                <td class="py-3 px-3 text-right">
                                    <?php if ($res['status'] === 'success'): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            ✓ Placed (#<?= $res['order_id'] ?>)
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200" title="<?= e($res['message']) ?>">
                                            ✕ <?= e($res['message']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Mass Order Form Card -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div>
            <div class="flex items-center gap-2">
                <span class="text-2xl">📚</span>
                <h1 class="text-xl font-black text-slate-900 tracking-tight">Mass Order Bulk Entry</h1>
            </div>
            <p class="text-xs text-slate-500 mt-1">Submit multiple service orders in a single request. One order line per row.</p>
        </div>

        <div class="p-4 rounded-2xl bg-violet-50/60 border border-violet-100 text-xs text-violet-900 space-y-1.5">
            <div class="font-extrabold flex items-center gap-1"><span>💡</span> Required Line Format:</div>
            <code class="block font-mono bg-white/80 p-2 rounded-xl text-violet-950 font-bold border border-violet-200/60 select-all">service_id | destination_link | quantity</code>
            <p class="text-[11px] text-violet-700 leading-relaxed">
                Example: <code class="bg-white/60 px-1 py-0.5 rounded text-[10px]">1 | https://instagram.com/myaccount | 1000</code>
            </p>
        </div>

        <form action="/user/mass-order.php" method="POST" class="space-y-4">
            <?= CSRF::field() ?>

            <div>
                <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-2">Orders Input List</label>
                <textarea name="mass_order_data" id="massInput" rows="8" required 
                          placeholder="1 | https://instagram.com/profile1 | 1000&#10;2 | https://instagram.com/profile2 | 500" 
                          class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl font-mono text-xs text-slate-800 leading-relaxed focus:ring-2 focus:ring-violet-500/20 focus:border-violet-600"><?= e($massInput) ?></textarea>
            </div>

            <div class="pt-2 flex items-center justify-between">
                <span class="text-[11px] text-slate-400">Lines are verified against current service minimums &amp; maximums.</span>
                <button type="submit" class="px-8 py-3.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-700 hover:to-indigo-700 text-white font-extrabold text-xs sm:text-sm rounded-2xl shadow-lg shadow-violet-500/25 active:scale-95 transition-all cursor-pointer">
                    Submit Mass Order Batch &rarr;
                </button>
            </div>
        </form>
    </div>

    <!-- Quick Service ID Lookup Sheet -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-4">
        <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
            <h2 class="text-sm font-extrabold text-slate-900">Active Services Quick ID Reference</h2>
            <a href="/user/services.php" class="text-xs font-bold text-blue-600 hover:underline">View All Services &rarr;</a>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5">
            <?php foreach ($popularServices as $ps): ?>
                <div class="p-3 rounded-xl bg-slate-50 border border-slate-200/60 text-xs flex items-center justify-between">
                    <div class="truncate max-w-[190px]">
                        <span class="font-mono font-black text-violet-700 mr-1.5">#<?= $ps['id'] ?></span>
                        <span class="font-bold text-slate-800"><?= e($ps['name']) ?></span>
                    </div>
                    <span class="font-mono text-[11px] font-bold text-slate-600 whitespace-nowrap ml-2"><?= format_currency($ps['rate_per_1000']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
