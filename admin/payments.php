<?php
/**
 * Admin Payment Gateway Management & Deposit Approvals
 * SMM Panel - PHP 8.3+
 *
 * Provides centralized management for:
 * - Razorpay, PayPal, PhonePe, Paytm, Binance Pay
 * - Enable/Disable toggles with strict configuration checks
 * - Credential configurations and server-side secret protection
 * - Manual UPI / QR deposit approvals queue
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireAdmin();
$db = Database::getConnection();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';

    // 1. Toggle Gateway Status (Enable / Disable)
    if ($action === 'toggle_status') {
        $code = trim($_POST['code'] ?? '');
        $enable = (int)($_POST['enable'] ?? 0) === 1;

        $res = toggle_gateway_status($code, $enable);
        if ($res['success']) {
            $statusLabel = $enable ? 'enabled' : 'disabled';
            set_flash('success', "Payment gateway '{$code}' has been successfully {$statusLabel}.");
        } else {
            set_flash('error', $res['error']);
        }
        redirect('/admin/payments.php');
    }

    // 2. Save Gateway Configuration
    if ($action === 'configure_gateway') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $mode = trim($_POST['mode'] ?? 'test');
        $minAmount = (float)($_POST['min_amount'] ?? 100);
        $maxAmount = (float)($_POST['max_amount'] ?? 50000);
        $feePercent = (float)($_POST['fee_percent'] ?? 0);
        $instructions = trim($_POST['instructions'] ?? '');

        // Extract credentials depending on gateway code
        $config = [];
        if ($code === 'razorpay') {
            $config = [
                'key_id' => trim($_POST['razorpay_key_id'] ?? ''),
                'key_secret' => trim($_POST['razorpay_key_secret'] ?? ''),
                'webhook_secret' => trim($_POST['razorpay_webhook_secret'] ?? ''),
                'mode' => $mode
            ];
        } elseif ($code === 'paypal') {
            $config = [
                'client_id' => trim($_POST['paypal_client_id'] ?? ''),
                'client_secret' => trim($_POST['paypal_client_secret'] ?? ''),
                'webhook_id' => trim($_POST['paypal_webhook_id'] ?? ''),
                'mode' => in_array($mode, ['sandbox', 'live'], true) ? $mode : ($mode === 'live' ? 'live' : 'sandbox')
            ];
        } elseif ($code === 'phonepe') {
            $config = [
                'merchant_id' => trim($_POST['phonepe_merchant_id'] ?? ''),
                'salt_key' => trim($_POST['phonepe_salt_key'] ?? ''),
                'salt_index' => trim($_POST['phonepe_salt_index'] ?? '1'),
                'mode' => in_array($mode, ['sandbox', 'production'], true) ? $mode : ($mode === 'live' ? 'production' : 'sandbox')
            ];
        } elseif ($code === 'paytm') {
            $config = [
                'merchant_id' => trim($_POST['paytm_merchant_id'] ?? ''),
                'merchant_key' => trim($_POST['paytm_merchant_key'] ?? ''),
                'channel_id' => trim($_POST['paytm_channel_id'] ?? 'WEB'),
                'industry_type' => trim($_POST['paytm_industry_type'] ?? 'Retail'),
                'upi_id' => trim($_POST['paytm_upi_id'] ?? 'smmpanel@upi'),
                'mode' => in_array($mode, ['staging', 'production'], true) ? $mode : ($mode === 'live' ? 'production' : 'staging')
            ];
        } elseif ($code === 'binance') {
            $config = [
                'api_key' => trim($_POST['binance_api_key'] ?? ''),
                'secret_key' => trim($_POST['binance_secret_key'] ?? ''),
                'merchant_id' => trim($_POST['binance_merchant_id'] ?? ''),
                'mode' => $mode
            ];
        } elseif ($code === 'phonepe_business_qr') {
            $existingMethod = get_payment_method('phonepe_business_qr');
            $existingConfig = $existingMethod ? get_gateway_config($existingMethod) : [];

            $qrImageUrl = trim($_POST['phonepe_qr_image_url'] ?? ($existingConfig['qr_image_url'] ?? ''));

            // Handle QR Image File Upload
            if (!empty($_FILES['qr_image']['name']) && $_FILES['qr_image']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['qr_image'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowedExts = ['png', 'jpg', 'jpeg', 'webp', 'svg'];
                $allowedMimes = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];

                $mime = mime_content_type($file['tmp_name']) ?: '';

                if (in_array($ext, $allowedExts, true) && (in_array($mime, $allowedMimes, true) || $ext === 'svg')) {
                    $uploadDir = __DIR__ . '/../assets/uploads/qr';
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0755, true);
                    }
                    $safeName = 'phonepe_qr_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $dest = $uploadDir . '/' . $safeName;
                    if (move_uploaded_file($file['tmp_name'], $dest)) {
                        $qrImageUrl = '/assets/uploads/qr/' . $safeName;
                    }
                } else {
                    set_flash('error', "Invalid QR image format. Please upload PNG, JPG, WEBP, or SVG.");
                }
            }

            $appPassword = trim($_POST['gmail_app_password'] ?? '');
            if (empty($appPassword) && !empty($existingConfig['gmail_app_password'])) {
                $appPassword = $existingConfig['gmail_app_password'];
            }

            $clientSecret = trim($_POST['gmail_client_secret'] ?? '');
            if (empty($clientSecret) && !empty($existingConfig['gmail_client_secret'])) {
                $clientSecret = $existingConfig['gmail_client_secret'];
            }

            $refreshToken = trim($_POST['gmail_refresh_token'] ?? '');
            if (empty($refreshToken) && !empty($existingConfig['gmail_refresh_token'])) {
                $refreshToken = $existingConfig['gmail_refresh_token'];
            }

            $config = [
                'merchant_id' => trim($_POST['phonepe_qr_merchant_id'] ?? ''),
                'upi_id' => trim($_POST['phonepe_qr_upi_id'] ?? ''),
                'qr_image_url' => $qrImageUrl,
                'gmail_method' => in_array($_POST['gmail_method'] ?? '', ['oauth', 'imap'], true) ? $_POST['gmail_method'] : 'imap',
                'gmail_email' => trim($_POST['gmail_email'] ?? ''),
                'gmail_app_password' => $appPassword,
                'gmail_client_id' => trim($_POST['gmail_client_id'] ?? ''),
                'gmail_client_secret' => $clientSecret,
                'gmail_refresh_token' => $refreshToken,
                'sender_whitelist' => trim($_POST['sender_whitelist'] ?? 'noreply@phonepe.com,merchant-care@phonepe.com'),
                'auto_verify_on_submit' => isset($_POST['auto_verify_on_submit']) ? 1 : 0,
                'mode' => in_array($mode, ['live', 'production'], true) ? 'live' : 'test'
            ];
        } else {
            // Generic custom gateway
            $config = $_POST['custom_config'] ?? [];
        }

        $extra = [
            'name' => $name,
            'min_amount' => $minAmount,
            'max_amount' => $maxAmount,
            'fee_percent' => $feePercent,
            'instructions' => $instructions,
            'mode' => in_array($mode, ['live', 'production'], true) ? 'live' : 'test'
        ];

        $ok = update_gateway_config($code, $config, $extra);
        if ($ok) {
            set_flash('success', "Configuration for '{$name}' saved successfully.");
        } else {
            set_flash('error', "Failed to update configuration for '{$name}'.");
        }
        redirect('/admin/payments.php');
    }

    // 3. Test Gmail Connection
    if ($action === 'test_gmail_connection') {
        require_once __DIR__ . '/../includes/gmail_verifier.php';
        $verifier = new GmailPaymentVerifier();
        $testResult = $verifier->testConnection();

        if (!empty($testResult['success'])) {
            $sampleMsg = "Found {$testResult['sample_count']} recent payment notification(s).";
            set_flash('success', "{$testResult['message']} {$sampleMsg}");
        } else {
            set_flash('error', $testResult['error'] ?? 'Gmail connection test failed.');
        }
        redirect('/admin/payments.php');
    }

    // 4. Verify Deposit via Gmail
    if ($action === 'verify_deposit_gmail') {
        $txnId = (int)($_POST['transaction_id'] ?? 0);
        require_once __DIR__ . '/../includes/gmail_verifier.php';
        $verifier = new GmailPaymentVerifier();
        $verifyRes = $verifier->verifyDeposit($db, $txnId);

        if (!empty($verifyRes['success']) && !empty($verifyRes['credited'])) {
            set_flash('success', "Deposit #{$txnId} verified via Gmail! Credited " . format_currency($verifyRes['amount']) . " to user.");
        } elseif (($verifyRes['status'] ?? '') === 'already_completed') {
            set_flash('info', "Deposit #{$txnId} was already completed.");
        } elseif (($verifyRes['status'] ?? '') === 'duplicate_rejected') {
            set_flash('error', "Rejected: " . ($verifyRes['error'] ?? 'Duplicate UTR detected.'));
        } elseif (($verifyRes['status'] ?? '') === 'amount_mismatch') {
            set_flash('error', "Amount Mismatch: " . ($verifyRes['error'] ?? 'Received amount does not match expected deposit.'));
        } elseif (($verifyRes['status'] ?? '') === 'connection_error') {
            set_flash('error', "Gmail Connection Failed: " . ($verifyRes['error'] ?? 'Could not connect.'));
        } else {
            set_flash('warning', "No matching payment email found for UTR '" . ($verifyRes['target_utr'] ?? '') . "'. Deposit remains pending.");
        }
        redirect('/admin/payments.php');
    }

    // 5. Approve Manual Deposit
    if ($action === 'approve_deposit') {
        $txnId = (int)($_POST['transaction_id'] ?? 0);

        $stmt = $db->prepare("SELECT * FROM transactions WHERE id = :id AND type = 'deposit' AND status = 'pending' LIMIT 1");
        $stmt->execute(['id' => $txnId]);
        $txn = $stmt->fetch();

        if ($txn) {
            $db->beginTransaction();
            try {
                // Concurrency-safe atomic credit
                $credit = $db->prepare("UPDATE users SET balance = balance + :amt WHERE id = :uid");
                $credit->execute(['amt' => $txn['amount'], 'uid' => $txn['user_id']]);

                $upd = $db->prepare("UPDATE transactions SET status = 'completed', note = CONCAT(COALESCE(note, ''), ' [Approved by Admin]') WHERE id = :id");
                $upd->execute(['id' => $txnId]);

                $db->commit();

                // Process referral rewards if eligible
                process_referral_commission((int)$txn['user_id'], (float)$txn['amount'], 'deposit', $txnId, null);

                set_flash('success', "Deposit #{$txnId} of " . format_currency($txn['amount']) . " approved and credited to User #{$txn['user_id']}.");
            } catch (Exception $e) {
                $db->rollBack();
                set_flash('error', "Deposit approval failed: " . $e->getMessage());
            }
        }
        redirect('/admin/payments.php');
    }

    // 6. Reject Manual Deposit
    if ($action === 'reject_deposit') {
        $txnId = (int)($_POST['transaction_id'] ?? 0);
        $reason = trim($_POST['reject_reason'] ?? 'Invalid transaction reference or unconfirmed receipt');

        $upd = $db->prepare("UPDATE transactions SET status = 'failed', note = CONCAT(COALESCE(note, ''), ' [Rejected: ', :reason, ']') WHERE id = :id AND status = 'pending'");
        $upd->execute(['id' => $txnId, 'reason' => $reason]);

        set_flash('info', "Deposit #{$txnId} rejected.");
        redirect('/admin/payments.php');
    }
}

// Fetch all payment gateways
$allGateways = get_payment_methods(false);

// Pending Manual Deposits Queue
$pendingStmt = $db->query("
    SELECT t.*, u.username, u.email, u.balance AS current_balance
    FROM transactions t
    JOIN users u ON t.user_id = u.id
    WHERE t.type = 'deposit' AND t.status = 'pending'
    ORDER BY t.created_at ASC
");
$pendingDeposits = $pendingStmt->fetchAll();

// Fetch Recent Payment Verification Audit Logs
$logsStmt = $db->query("
    SELECT * FROM payment_verification_logs
    ORDER BY id DESC
    LIMIT 15
");
$verificationLogs = $logsStmt ? $logsStmt->fetchAll() : [];

$pageTitle = "Payment Gateways & Approvals - Admin Console";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-6xl mx-auto my-6 space-y-8" x-data="adminPaymentsHub()">
    <!-- Header Summary -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight flex items-center gap-2">
                <span>💳</span> Payment Gateway Management &amp; Approvals
            </h1>
            <p class="text-xs text-slate-500 mt-1">Configure live and test gateway credentials, enable/disable customer payment methods, and review manual deposits</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-200">
                <span>🔒</span> Server-Side Secrets Protected
            </span>
            <?php if (count($pendingDeposits) > 0): ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200 animate-pulse">
                    ⚡ <?= count($pendingDeposits) ?> Manual Deposits Awaiting Verification
                </span>
            <?php endif; ?>
        </div>
    </div>

    <?= render_flash() ?>

    <!-- SECTION 1: PAYMENT GATEWAYS LIST (Requirements 2, 3, 4, 5) -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div class="border-b border-slate-100 pb-4 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>
                <h2 class="text-base font-extrabold text-slate-900 tracking-tight">Active &amp; Available Payment Gateways</h2>
                <p class="text-xs text-slate-400 mt-0.5">Toggle customer checkout availability, set transaction limits, and configure API merchant credentials</p>
            </div>
            <div class="text-xs font-medium text-slate-500">
                Gateways enabled here dynamically appear on the user Add Funds portal.
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4">
            <?php foreach ($allGateways as $gw): 
                $isConfigured = is_gateway_configured($gw);
                $isEnabled = ($gw['status'] === 'active');
                $cfg = get_gateway_config($gw);
                $modeVal = $gw['mode'] ?? ($cfg['mode'] ?? 'test');
                $isLive = in_array(strtolower($modeVal), ['live', 'production'], true);
            ?>
                <div class="p-5 rounded-2xl border transition-all <?= $isEnabled ? 'bg-white border-blue-200/80 shadow-xs' : 'bg-slate-50/70 border-slate-200' ?>">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <!-- Left: Icon, Name, Code, Badges -->
                        <div class="flex items-start sm:items-center gap-4">
                            <div class="w-13 h-13 rounded-2xl flex items-center justify-center text-2xl shadow-xs shrink-0 <?= $isEnabled ? 'bg-blue-50 text-blue-600 border border-blue-100' : 'bg-slate-200 text-slate-400' ?>">
                                <?= e($gw['icon'] ?: '💳') ?>
                            </div>
                            <div class="space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-extrabold text-slate-900"><?= e($gw['name']) ?></h3>
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-mono font-bold bg-slate-100 text-slate-600 border border-slate-200">
                                        <?= e($gw['code']) ?>
                                    </span>
                                    
                                    <!-- Status Badge -->
                                    <?php if ($isEnabled): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Enabled
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase bg-slate-100 text-slate-600 border border-slate-200">
                                            Disabled
                                        </span>
                                    <?php endif; ?>

                                    <!-- Configuration Status Badge -->
                                    <?php if ($isConfigured): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                            ✓ Configured
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                            ⚠️ Configuration Required
                                        </span>
                                    <?php endif; ?>

                                    <!-- Environment Mode Badge -->
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-black uppercase tracking-wider <?= $isLive ? 'bg-purple-50 text-purple-700 border border-purple-200' : 'bg-amber-50 text-amber-800 border border-amber-200' ?>">
                                        <?= $isLive ? 'Live' : 'Sandbox / Test' ?>
                                    </span>
                                </div>

                                <p class="text-xs text-slate-500 max-w-2xl leading-relaxed">
                                    <?= e($gw['instructions'] ?: 'Standard payment processing gateway.') ?>
                                </p>

                                <div class="flex flex-wrap items-center gap-3 text-[11px] font-medium text-slate-400 pt-0.5">
                                    <span>Limits: <strong class="text-slate-700 font-mono"><?= format_currency($gw['min_amount']) ?> - <?= format_currency($gw['max_amount']) ?></strong></span>
                                    <span>•</span>
                                    <span>Fee: <strong class="text-slate-700 font-mono"><?= (float)$gw['fee_percent'] ?>%</strong></span>
                                    <span>•</span>
                                    <span>Priority: <strong class="text-slate-700 font-mono">#<?= (int)$gw['sort_order'] ?></strong></span>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Actions (Configure, Enable, Disable) -->
                        <div class="flex items-center gap-2.5 shrink-0 pt-2 lg:pt-0">
                            <!-- Configure Button -->
                            <button type="button" 
                                    @click="openConfigModal(<?= htmlspecialchars(json_encode($gw), ENT_QUOTES) ?>)" 
                                    class="px-4 py-2 bg-slate-100 hover:bg-slate-200 active:scale-95 text-slate-800 text-xs font-bold rounded-xl transition-all flex items-center gap-1.5 cursor-pointer">
                                <span>⚙️</span>
                                <span>Configure</span>
                            </button>

                            <!-- Enable / Disable Form Actions -->
                            <?php if ($isEnabled): ?>
                                <form action="/admin/payments.php" method="POST" class="inline" onsubmit="return confirm('Disable <?= addslashes($gw['name']) ?>? It will immediately be hidden from user Add Funds.')">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="code" value="<?= e($gw['code']) ?>">
                                    <input type="hidden" name="enable" value="0">
                                    <button type="submit" class="px-4 py-2 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 active:scale-95 text-xs font-bold rounded-xl transition-all cursor-pointer">
                                        Disable
                                    </button>
                                </form>
                            <?php else: ?>
                                <form action="/admin/payments.php" method="POST" class="inline">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="code" value="<?= e($gw['code']) ?>">
                                    <input type="hidden" name="enable" value="1">
                                    <button type="submit" 
                                            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white active:scale-95 text-xs font-bold rounded-xl shadow-sm transition-all cursor-pointer">
                                        Enable
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- SECTION 2: PENDING MANUAL DEPOSITS QUEUE (Preserved & Enhanced) -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div class="border-b border-slate-100 pb-4 flex items-center justify-between">
            <div>
                <h2 class="text-base font-extrabold text-slate-900 tracking-tight">Manual &amp; QR Deposits Queue</h2>
                <p class="text-xs text-slate-400 mt-0.5">Users who submitted 12-digit UTR numbers or bank transfer reference slips</p>
            </div>
            <span class="text-xs font-mono font-bold text-amber-600 bg-amber-50 px-2.5 py-1 rounded-lg border border-amber-200">
                <?= count($pendingDeposits) ?> Pending
            </span>
        </div>

        <?php if (empty($pendingDeposits)): ?>
            <div class="p-10 text-center text-slate-400 space-y-2">
                <div class="text-3xl">✨</div>
                <div class="text-xs font-bold text-slate-600">No pending manual deposits in queue.</div>
                <p class="text-[11px] text-slate-400">Incoming Paytm QR and bank wire deposit requests will appear here for staff review.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-slate-400 border-b border-slate-100 pb-3 uppercase text-[10px] font-bold tracking-wider">
                            <th class="py-3 px-3">#ID</th>
                            <th class="py-3 px-3">Submitted</th>
                            <th class="py-3 px-3">User</th>
                            <th class="py-3 px-3">Method</th>
                            <th class="py-3 px-3">UTR / Txn ID</th>
                            <th class="py-3 px-3 text-right">Amount</th>
                            <th class="py-3 px-3">User Notes</th>
                            <th class="py-3 px-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($pendingDeposits as $dep): ?>
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="py-3.5 px-3 font-mono text-slate-400 text-[11px]">#<?= $dep['id'] ?></td>
                                <td class="py-3.5 px-3 font-mono text-slate-500 text-[11px] whitespace-nowrap">
                                    <?= date('d M Y, H:i', strtotime($dep['created_at'])) ?>
                                </td>
                                <td class="py-3.5 px-3">
                                    <div class="font-extrabold text-slate-900"><?= e($dep['username']) ?></div>
                                    <div class="text-[10px] text-slate-400 font-mono"><?= e($dep['email']) ?></div>
                                </td>
                                <td class="py-3.5 px-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700">
                                        <?= e($dep['gateway']) ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-3 font-mono font-bold text-slate-800 select-all">
                                    <?= e($dep['gateway_txn_id'] ?: 'N/A') ?>
                                </td>
                                <td class="py-3.5 px-3 text-right font-mono-nums font-black text-emerald-600 text-sm">
                                    <?= format_currency($dep['amount']) ?>
                                </td>
                                <td class="py-3.5 px-3 text-slate-500 max-w-xs truncate" title="<?= e($dep['note']) ?>">
                                    <?= e($dep['note'] ?: '—') ?>
                                </td>
                                <td class="py-3.5 px-3 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <?php if (stripos($dep['gateway'], 'phonepe') !== false): ?>
                                            <form action="/admin/payments.php" method="POST" class="inline">
                                                <?= CSRF::field() ?>
                                                <input type="hidden" name="action" value="verify_deposit_gmail">
                                                <input type="hidden" name="transaction_id" value="<?= $dep['id'] ?>">
                                                <button type="submit" class="px-2.5 py-1.5 bg-purple-600 hover:bg-purple-700 text-white font-extrabold rounded-lg text-xs shadow-xs transition-colors cursor-pointer flex items-center gap-1" title="Scan Gmail inbox for genuine payment notification and auto-credit">
                                                    <span>⚡</span> Verify via Gmail
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <form action="/admin/payments.php" method="POST" class="inline" onsubmit="return confirm('Confirm receipt of <?= format_currency($dep['amount']) ?> and credit wallet?')">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="action" value="approve_deposit">
                                            <input type="hidden" name="transaction_id" value="<?= $dep['id'] ?>">
                                            <button type="submit" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold rounded-lg text-xs shadow-xs transition-colors cursor-pointer">
                                                Approve
                                            </button>
                                        </form>

                                        <form action="/admin/payments.php" method="POST" class="inline" onsubmit="return confirm('Reject deposit #<?= $dep['id'] ?>?')">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="action" value="reject_deposit">
                                            <input type="hidden" name="transaction_id" value="<?= $dep['id'] ?>">
                                            <button type="submit" class="px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 font-extrabold rounded-lg text-xs transition-colors cursor-pointer">
                                                Reject
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- SECTION 3: GMAIL VERIFICATION AUDIT TRAIL -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-4">
        <div class="border-b border-slate-100 pb-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-extrabold text-slate-900 tracking-tight flex items-center gap-2">
                    <span>📬</span> PhonePe &amp; Gmail Verification Audit Trail
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Real-time logs of automated inbox payment checks, UTR matching outcomes, and duplicate safeguards</p>
            </div>
            <div>
                <form action="/admin/payments.php" method="POST" class="inline">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="test_gmail_connection">
                    <button type="submit" class="px-3.5 py-2 bg-purple-50 hover:bg-purple-100 text-purple-700 border border-purple-200 active:scale-95 text-xs font-bold rounded-xl transition-all flex items-center gap-1.5 cursor-pointer">
                        <span>🧪</span> Test Gmail Connection
                    </button>
                </form>
            </div>
        </div>

        <?php if (empty($verificationLogs)): ?>
            <div class="p-8 text-center text-slate-400 space-y-1">
                <div class="text-2xl">📋</div>
                <div class="text-xs font-bold text-slate-600">No verification checks recorded yet.</div>
                <p class="text-[11px] text-slate-400">Attempts to verify PhonePe Business QR deposits via Gmail will be logged here with complete audit details.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-slate-400 border-b border-slate-100 pb-3 uppercase text-[10px] font-bold tracking-wider">
                            <th class="py-2.5 px-3">Time</th>
                            <th class="py-2.5 px-3">Deposit</th>
                            <th class="py-2.5 px-3">UTR Queried</th>
                            <th class="py-2.5 px-3">Amount (Req / Found)</th>
                            <th class="py-2.5 px-3">Status</th>
                            <th class="py-2.5 px-3">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-mono text-[11px]">
                        <?php foreach ($verificationLogs as $log): ?>
                            <tr class="hover:bg-slate-50/70">
                                <td class="py-2.5 px-3 text-slate-500 whitespace-nowrap">
                                    <?= date('d M, H:i:s', strtotime($log['created_at'])) ?>
                                </td>
                                <td class="py-2.5 px-3 font-bold text-slate-700">
                                    <?= $log['transaction_id'] ? ('#' . $log['transaction_id']) : '—' ?>
                                </td>
                                <td class="py-2.5 px-3 font-bold text-slate-900 select-all">
                                    <?= e($log['utr_searched'] ?: '—') ?>
                                </td>
                                <td class="py-2.5 px-3">
                                    <span class="text-slate-600"><?= $log['amount_expected'] ? format_currency($log['amount_expected']) : '—' ?></span>
                                    <?php if ($log['amount_found']): ?>
                                        <span class="text-emerald-600 font-bold"> / <?= format_currency($log['amount_found']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 px-3">
                                    <?php if ($log['status'] === 'matched_success'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            ✓ Matched &amp; Credited
                                        </span>
                                    <?php elseif ($log['status'] === 'pending_no_match'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                            ⏳ No Match Yet
                                        </span>
                                    <?php elseif ($log['status'] === 'duplicate_rejected'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                            🚫 Duplicate Rejected
                                        </span>
                                    <?php elseif ($log['status'] === 'amount_mismatch'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-50 text-purple-700 border border-purple-200">
                                            ⚠️ Amount Mismatch
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                            ✕ <?= e($log['status']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 px-3 text-slate-500 font-sans text-xs max-w-xs truncate" title="<?= e($log['details']) ?>">
                                    <?= e($log['details'] ?: '—') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- GATEWAY CONFIGURATION MODAL (Requirements 5 & 7) -->
    <div x-show="activeGw !== null"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-xs overflow-y-auto"
         style="display: none;"
         @keydown.escape.window="closeConfigModal()">
        
        <div @click.outside="closeConfigModal()" class="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl border border-slate-100 relative space-y-6 my-8">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <span class="text-3xl" x-text="activeGw?.icon || '💳'"></span>
                    <div>
                        <h3 class="text-lg font-black text-slate-900 tracking-tight" x-text="'Configure ' + (activeGw?.name || 'Gateway')"></h3>
                        <p class="text-xs text-slate-400">Settings and API keys remain protected server-side.</p>
                    </div>
                </div>
                <button type="button" @click="closeConfigModal()" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center text-sm font-bold">✕</button>
            </div>

            <form action="/admin/payments.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="configure_gateway">
                <input type="hidden" name="code" :value="activeGw?.code">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Display Name</label>
                        <input type="text" name="name" :value="activeGw?.name" required class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Environment Mode</label>
                        <select name="mode" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <option value="test" :selected="activeGw?.mode === 'test' || activeGwConfig?.mode === 'test' || activeGwConfig?.mode === 'sandbox' || activeGwConfig?.mode === 'staging'">Sandbox / Test / Staging</option>
                            <option value="live" :selected="activeGw?.mode === 'live' || activeGwConfig?.mode === 'live' || activeGwConfig?.mode === 'production'">Live / Production</option>
                        </select>
                    </div>
                </div>

                <!-- 1. RAZORPAY CONFIGURATION FIELDS -->
                <template x-if="activeGw?.code === 'razorpay'">
                    <div class="space-y-3 p-4 rounded-2xl bg-blue-50/50 border border-blue-100">
                        <span class="text-[11px] font-extrabold text-blue-900 block">Razorpay Merchant Credentials</span>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Key ID <span class="text-rose-500">*</span></label>
                            <input type="text" name="razorpay_key_id" :value="activeGwConfig.key_id || ''" placeholder="rzp_test_..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Key Secret <span class="text-rose-500">*</span></label>
                            <input type="password" name="razorpay_key_secret" :value="activeGwConfig.key_secret || ''" placeholder="••••••••••••••••" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Webhook Secret (Optional)</label>
                            <input type="password" name="razorpay_webhook_secret" :value="activeGwConfig.webhook_secret || ''" placeholder="••••••••••••••••" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                    </div>
                </template>

                <!-- 2. PAYPAL CONFIGURATION FIELDS -->
                <template x-if="activeGw?.code === 'paypal'">
                    <div class="space-y-3 p-4 rounded-2xl bg-sky-50/50 border border-sky-100">
                        <span class="text-[11px] font-extrabold text-sky-900 block">PayPal REST API Credentials</span>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Client ID <span class="text-rose-500">*</span></label>
                            <input type="text" name="paypal_client_id" :value="activeGwConfig.client_id || ''" placeholder="AXXXXXXXXX..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Client Secret <span class="text-rose-500">*</span></label>
                            <input type="password" name="paypal_client_secret" :value="activeGwConfig.client_secret || ''" placeholder="••••••••••••••••" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Webhook ID (Optional)</label>
                            <input type="text" name="paypal_webhook_id" :value="activeGwConfig.webhook_id || ''" placeholder="8XXXXXXXXX..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                    </div>
                </template>

                <!-- 3. PHONEPE CONFIGURATION FIELDS -->
                <template x-if="activeGw?.code === 'phonepe'">
                    <div class="space-y-3 p-4 rounded-2xl bg-purple-50/50 border border-purple-100">
                        <span class="text-[11px] font-extrabold text-purple-900 block">PhonePe Merchant Credentials</span>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Merchant ID <span class="text-rose-500">*</span></label>
                            <input type="text" name="phonepe_merchant_id" :value="activeGwConfig.merchant_id || ''" placeholder="MERCHANTUAT" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Salt Key <span class="text-rose-500">*</span></label>
                            <input type="password" name="phonepe_salt_key" :value="activeGwConfig.salt_key || ''" placeholder="••••••••••••••••" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Salt Index</label>
                            <input type="text" name="phonepe_salt_index" :value="activeGwConfig.salt_index || '1'" placeholder="1" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                    </div>
                </template>

                <!-- 4. PAYTM CONFIGURATION FIELDS -->
                <template x-if="activeGw?.code === 'paytm'">
                    <div class="space-y-3 p-4 rounded-2xl bg-blue-50/50 border border-blue-100">
                        <span class="text-[11px] font-extrabold text-blue-900 block">Paytm Merchant &amp; QR Settings</span>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Merchant ID (MID) <span class="text-rose-500">*</span></label>
                            <input type="text" name="paytm_merchant_id" :value="activeGwConfig.merchant_id || ''" placeholder="YourPaytmMID123" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Merchant Key <span class="text-rose-500">*</span></label>
                            <input type="password" name="paytm_merchant_key" :value="activeGwConfig.merchant_key || ''" placeholder="••••••••••••••••" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Official UPI ID for Manual QR</label>
                            <input type="text" name="paytm_upi_id" :value="activeGwConfig.upi_id || 'smmpanel@upi'" placeholder="smmpanel@upi" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                    </div>
                </template>

                <!-- 5. BINANCE PAY CONFIGURATION FIELDS -->
                <template x-if="activeGw?.code === 'binance'">
                    <div class="space-y-3 p-4 rounded-2xl bg-amber-50/50 border border-amber-100">
                        <span class="text-[11px] font-extrabold text-amber-900 block">Binance Pay Merchant API</span>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">API Key <span class="text-rose-500">*</span></label>
                            <input type="text" name="binance_api_key" :value="activeGwConfig.api_key || ''" placeholder="binance_api_key_..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Secret Key <span class="text-rose-500">*</span></label>
                            <input type="password" name="binance_secret_key" :value="activeGwConfig.secret_key || ''" placeholder="••••••••••••••••" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Merchant ID (Optional)</label>
                            <input type="text" name="binance_merchant_id" :value="activeGwConfig.merchant_id || ''" placeholder="987654321" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                    </div>
                </template>

                <!-- 6. PHONEPE BUSINESS QR + GMAIL VERIFICATION CONFIGURATION -->
                <template x-if="activeGw?.code === 'phonepe_business_qr'">
                    <div class="space-y-4">
                        <!-- A. PhonePe Merchant & QR Section -->
                        <div class="space-y-3 p-4 rounded-2xl bg-purple-50/60 border border-purple-100">
                            <div class="flex items-center justify-between">
                                <span class="text-[11px] font-extrabold text-purple-900 block flex items-center gap-1.5">
                                    <span>🟣</span> PhonePe Business &amp; QR Setup
                                </span>
                                <span class="text-[10px] text-purple-600 font-bold bg-purple-100/70 px-2 py-0.5 rounded-md">Merchant Profile</span>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-600 mb-1">PhonePe Merchant ID (MID)</label>
                                    <input type="text" name="phonepe_qr_merchant_id" :value="activeGwConfig.merchant_id || ''" placeholder="M22XXXXXXXXX" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Merchant UPI ID / VPA</label>
                                    <input type="text" name="phonepe_qr_upi_id" :value="activeGwConfig.upi_id || ''" placeholder="merchant@ybl" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                                </div>
                            </div>

                            <!-- QR Image Upload & Preview -->
                            <div class="space-y-2 pt-1 border-t border-purple-100/60">
                                <label class="block text-[11px] font-bold text-slate-700">
                                    Upload PhonePe Business QR Image <span class="text-rose-500">*</span>
                                </label>
                                
                                <div class="flex flex-col sm:flex-row items-center gap-4 bg-white p-3 rounded-xl border border-slate-200">
                                    <!-- QR Thumbnail Preview -->
                                    <template x-if="activeGwConfig.qr_image_url">
                                        <div class="shrink-0 text-center">
                                            <img :src="activeGwConfig.qr_image_url" alt="QR Code" class="w-20 h-20 object-contain rounded-lg border border-purple-200 bg-white shadow-xs p-1">
                                            <span class="text-[9px] text-emerald-600 font-bold block mt-1">✓ Active Image</span>
                                        </div>
                                    </template>
                                    <template x-if="!activeGwConfig.qr_image_url">
                                        <div class="w-20 h-20 rounded-lg border-2 border-dashed border-slate-300 flex items-center justify-center text-slate-400 text-xs text-center p-1 shrink-0">
                                            No QR Uploaded
                                        </div>
                                    </template>

                                    <div class="flex-1 w-full space-y-2">
                                        <input type="file" name="qr_image" accept="image/png, image/jpeg, image/webp, image/svg+xml" class="block w-full text-xs text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-purple-100 file:text-purple-700 hover:file:bg-purple-200 cursor-pointer">
                                        
                                        <div class="flex items-center gap-2">
                                            <span class="text-[10px] text-slate-400 shrink-0">Or Image URL:</span>
                                            <input type="text" name="phonepe_qr_image_url" :value="activeGwConfig.qr_image_url || ''" placeholder="/assets/uploads/qr/... or https://..." class="w-full px-2.5 py-1 text-[11px] bg-slate-50 border border-slate-200 rounded-lg font-mono">
                                        </div>
                                        <p class="text-[10px] text-slate-400">Upload your genuine PhonePe Business QR image. It will be displayed without alteration.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- B. Genuine Gmail Payment Verification Configuration -->
                        <div class="space-y-3 p-4 rounded-2xl bg-sky-50/60 border border-sky-100">
                            <div class="flex items-center justify-between">
                                <span class="text-[11px] font-extrabold text-sky-900 block flex items-center gap-1.5">
                                    <span>📬</span> Gmail Automated Payment Verification
                                </span>
                                <span class="text-[10px] text-sky-700 font-bold bg-sky-100 px-2 py-0.5 rounded-md">Genuine Mail Scanner</span>
                            </div>

                            <p class="text-[11px] text-slate-500 leading-relaxed">
                                Real-time backend verification scans genuine incoming transaction emails from PhonePe and bank UPI alerts. Never credits wallet without a confirmed matching email.
                            </p>

                            <!-- Method Switcher -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Connection Protocol</label>
                                    <select name="gmail_method" x-model="activeGwConfig.gmail_method" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold">
                                        <option value="imap">IMAP SSL (16-char App Password)</option>
                                        <option value="oauth">Google OAuth 2.0 (REST API)</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Gmail Address <span class="text-rose-500">*</span></label>
                                    <input type="email" name="gmail_email" :value="activeGwConfig.gmail_email || ''" placeholder="yourbusiness@gmail.com" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                                </div>
                            </div>

                            <!-- IMAP Configuration Details -->
                            <div x-show="!activeGwConfig.gmail_method || activeGwConfig.gmail_method === 'imap'" class="space-y-2 pt-2 border-t border-sky-100/60">
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-700 mb-0.5">
                                        Google App Password (16 characters) <span class="text-rose-500">*</span>
                                    </label>
                                    <input type="password" name="gmail_app_password" :value="activeGwConfig.gmail_app_password || ''" placeholder="••••••••••••••••" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                                    <p class="text-[10px] text-slate-500 mt-1">
                                        🔒 <strong>Security Note:</strong> Never enter your personal Google password. Enable 2-Step Verification on your Google Account, then generate an App Password at <span class="font-mono text-blue-600">myaccount.google.com/apppasswords</span>.
                                    </p>
                                </div>
                            </div>

                            <!-- OAuth 2.0 Configuration Details -->
                            <div x-show="activeGwConfig.gmail_method === 'oauth'" class="space-y-2 pt-2 border-t border-sky-100/60" style="display: none;">
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-700 mb-0.5">Google Client ID</label>
                                    <input type="text" name="gmail_client_id" :value="activeGwConfig.gmail_client_id || ''" placeholder="XXXXX.apps.googleusercontent.com" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[11px] font-bold text-slate-700 mb-0.5">Client Secret</label>
                                        <input type="password" name="gmail_client_secret" :value="activeGwConfig.gmail_client_secret || ''" placeholder="••••••••••••••••" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-bold text-slate-700 mb-0.5">Refresh Token</label>
                                        <input type="password" name="gmail_refresh_token" :value="activeGwConfig.gmail_refresh_token || ''" placeholder="1//0XXXX..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                                    </div>
                                </div>
                            </div>

                            <!-- Sender Whitelist & Auto-Verify -->
                            <div class="space-y-2 pt-2 border-t border-sky-100/60">
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-700 mb-0.5">Trusted Sender Filter (Comma-separated)</label>
                                    <input type="text" name="sender_whitelist" :value="activeGwConfig.sender_whitelist || 'noreply@phonepe.com, merchant-care@phonepe.com, alerts@hdfcbank.net, alerts@icicibank.com, upi@axisbank.com'" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                                    <p class="text-[10px] text-slate-400 mt-0.5">Only emails from these verified domains/addresses are processed.</p>
                                </div>

                                <div class="flex items-center gap-2 pt-1">
                                    <input type="checkbox" name="auto_verify_on_submit" id="auto_verify_on_submit" value="1" :checked="activeGwConfig.auto_verify_on_submit !== 0" class="w-4 h-4 rounded text-blue-600 focus:ring-blue-500 border-slate-300">
                                    <label for="auto_verify_on_submit" class="text-xs font-bold text-slate-700 cursor-pointer">
                                        Attempt automated Gmail verification immediately when user submits UTR
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Limits & Instructions -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Min Deposit</label>
                        <input type="number" step="1" name="min_amount" :value="activeGw?.min_amount" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Max Deposit</label>
                        <input type="number" step="1" name="max_amount" :value="activeGw?.max_amount" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Fee %</label>
                        <input type="number" step="0.1" name="fee_percent" :value="activeGw?.fee_percent" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">User Instructions / Note</label>
                    <textarea name="instructions" rows="2" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs" :value="activeGw?.instructions"></textarea>
                </div>

                <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-3">
                    <button type="button" @click="closeConfigModal()" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-xs cursor-pointer">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-extrabold rounded-xl text-xs shadow-md shadow-blue-500/25 cursor-pointer">
                        Save Gateway Configuration &rarr;
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function adminPaymentsHub() {
    return {
        activeGw: null,
        activeGwConfig: {},

        openConfigModal(gw) {
            this.activeGw = gw;
            let cfg = {};
            try {
                if (typeof gw.config_data === 'string' && gw.config_data.trim() !== '') {
                    cfg = JSON.parse(gw.config_data);
                } else if (typeof gw.config_data === 'object' && gw.config_data !== null) {
                    cfg = gw.config_data;
                }
            } catch (e) {
                cfg = {};
            }
            this.activeGwConfig = cfg;
        },

        closeConfigModal() {
            this.activeGw = null;
            this.activeGwConfig = {};
        }
    };
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
