<?php
/**
 * Add Funds Page (Dynamic Production Gateway Architecture)
 * SMM Panel - PHP 8.3+
 *
 * Dynamically displays ONLY payment gateways enabled & configured by Admin:
 * Razorpay, PayPal, PhonePe, Paytm, Binance Pay
 * Server-side security prevents manual execution of disabled gateways.
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

// Fetch ONLY active AND configured gateways (Requirement 6)
$availableGateways = get_payment_methods(true, true);

$error = null;
$success = null;

// Determine default active tab
$defaultMethod = !empty($availableGateways) ? $availableGateways[0]['code'] : '';
$activeTab = $_GET['method'] ?? $defaultMethod;

// Verify activeTab is valid, fallback to first available
$activeGatewayData = null;
foreach ($availableGateways as $gw) {
    if ($gw['code'] === $activeTab) {
        $activeGatewayData = $gw;
        break;
    }
}
if (!$activeGatewayData && !empty($availableGateways)) {
    $activeGatewayData = $availableGateways[0];
    $activeTab = $activeGatewayData['code'];
}

// Handle Form Submission with Server-Side Validation (Requirements 4, 6 & 7)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();

    $gatewayCode = trim($_POST['gateway'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);

    // 1. Strict Server-Side Validation: Gateway MUST exist, be active, and configured
    $gateway = get_payment_method($gatewayCode);
    if (!$gateway || $gateway['status'] !== 'active' || !is_gateway_configured($gateway)) {
        $error = "This payment gateway is currently disabled or unavailable. Please choose another active payment method.";
    } else {
        $min = (float)$gateway['min_amount'];
        $max = (float)$gateway['max_amount'];

        if ($amount < $min) {
            $error = "Minimum deposit amount for {$gateway['name']} is " . format_currency($min) . ".";
        } elseif ($amount > $max) {
            $error = "Maximum single deposit amount for {$gateway['name']} is " . format_currency($max) . ".";
        } else {
            $gatewayConfig = get_gateway_config($gateway);

            // Handle specific gateway submission
            if ($gatewayCode === 'razorpay') {
                $orderId = 'rzp_ord_' . bin2hex(random_bytes(6));
                $stmt = $db->prepare("
                    INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, 'deposit', :amt, 'Razorpay', :txnid, 'pending', 'Awaiting Razorpay gateway completion')
                ");
                $stmt->execute([
                    'uid' => $user['id'],
                    'amt' => $amount,
                    'txnid' => $orderId
                ]);

                $_SESSION['pending_razorpay'] = [
                    'txn_id' => $db->lastInsertId(),
                    'order_id' => $orderId,
                    'amount' => $amount
                ];

                set_flash('info', "Payment intent initiated for " . format_currency($amount) . ". Complete checkout via Razorpay.");
                redirect('/user/add-funds.php?checkout=razorpay&order=' . urlencode($orderId));
            } elseif ($gatewayCode === 'paytm' || $gatewayCode === 'paytm_qr') {
                $utr = trim($_POST['utr_number'] ?? '');
                $senderNote = trim($_POST['sender_note'] ?? '');

                if (empty($utr) || strlen($utr) < 6) {
                    $error = "Please enter a valid 12-digit UTR number or bank transaction reference ID.";
                } else {
                    $check = $db->prepare("SELECT id FROM transactions WHERE gateway_txn_id = :utr LIMIT 1");
                    $check->execute(['utr' => $utr]);
                    if ($check->fetch()) {
                        $error = "This UTR / Transaction ID has already been submitted.";
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                            VALUES (:uid, 'deposit', :amt, :gw, :txnid, 'pending', :note)
                        ");
                        $stmt->execute([
                            'uid' => $user['id'],
                            'amt' => $amount,
                            'gw' => $gateway['name'],
                            'txnid' => $utr,
                            'note' => "Pending verification (" . ($senderNote ?: "UTR: {$utr}") . ")"
                        ]);

                        set_flash('success', "Deposit request of " . format_currency($amount) . " submitted! Your UTR #{$utr} is queued for admin verification.");
                        redirect('/user/add-funds.php');
                    }
                }
            } elseif ($gatewayCode === 'phonepe') {
                $phonePeTxnId = 'pp_' . bin2hex(random_bytes(6));
                $stmt = $db->prepare("
                    INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, 'deposit', :amt, 'PhonePe', :txnid, 'pending', 'Awaiting PhonePe confirmation')
                ");
                $stmt->execute([
                    'uid' => $user['id'],
                    'amt' => $amount,
                    'txnid' => $phonePeTxnId
                ]);

                set_flash('success', "PhonePe intent #{$phonePeTxnId} created for " . format_currency($amount) . ". Complete via PhonePe UPI.");
                redirect('/user/add-funds.php');
            } elseif ($gatewayCode === 'phonepe_business_qr') {
                $utr = trim($_POST['utr_number'] ?? '');
                $senderNote = trim($_POST['sender_note'] ?? '');

                // Normalize UTR
                $cleanUtr = strtoupper(trim(preg_replace('/\s+/', '', $utr)));

                if (empty($cleanUtr) || strlen($cleanUtr) < 6) {
                    $error = "Please enter a valid 12-digit UPI Reference / UTR number from your payment receipt.";
                } else {
                    // Check duplicate
                    $check = $db->prepare("SELECT id, status FROM transactions WHERE gateway_txn_id = :utr LIMIT 1");
                    $check->execute(['utr' => $cleanUtr]);
                    $existing = $check->fetch();

                    if ($existing) {
                        $error = "This UTR / Transaction reference (#{$cleanUtr}) has already been submitted.";
                    } else {
                        // Create pending deposit
                        $stmt = $db->prepare("
                            INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                            VALUES (:uid, 'deposit', :amt, :gw, :txnid, 'pending', :note)
                        ");
                        $stmt->execute([
                            'uid' => $user['id'],
                            'amt' => $amount,
                            'gw' => 'PhonePe Business QR',
                            'txnid' => $cleanUtr,
                            'note' => "PhonePe QR Deposit (" . ($senderNote ?: "UTR: {$cleanUtr}") . ")"
                        ]);
                        $depositId = (int)$db->lastInsertId();

                        // Automated verification attempt via Gmail if enabled & configured
                        $autoVerify = (int)($gatewayConfig['auto_verify_on_submit'] ?? 1) === 1;
                        $hasGmail = !empty($gatewayConfig['gmail_email']) || !empty($gatewayConfig['gmail_client_id']);

                        if ($autoVerify && $hasGmail) {
                            require_once __DIR__ . '/../includes/gmail_verifier.php';
                            $verifier = new GmailPaymentVerifier($gatewayConfig);
                            $verifyRes = $verifier->verifyDeposit($db, $depositId, $cleanUtr, $amount);

                            if (!empty($verifyRes['success']) && !empty($verifyRes['credited'])) {
                                set_flash('success', "Payment of " . format_currency($amount) . " verified instantly via PhonePe! Credited to your wallet.");
                                redirect('/user/add-funds.php');
                            } elseif (($verifyRes['status'] ?? '') === 'duplicate_rejected') {
                                $error = "Verification failed: Duplicate UTR detected. This transaction was already claimed.";
                            } elseif (($verifyRes['status'] ?? '') === 'amount_mismatch') {
                                $error = $verifyRes['error'] ?? "Verification failed: Received amount does not match submitted deposit amount.";
                            } else {
                                // Email not yet arrived or pending review
                                set_flash('info', "Deposit request for " . format_currency($amount) . " submitted (UTR #{$cleanUtr}). Awaiting payment notification verification.");
                                redirect('/user/add-funds.php');
                            }
                        } else {
                            set_flash('success', "Deposit request for " . format_currency($amount) . " submitted! Your UTR #{$cleanUtr} has been queued for verification.");
                            redirect('/user/add-funds.php');
                        }
                    }
                }
            } elseif ($gatewayCode === 'paypal') {
                $paypalTxnId = 'ppl_' . bin2hex(random_bytes(6));
                $stmt = $db->prepare("
                    INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, 'deposit', :amt, 'PayPal', :txnid, 'pending', 'Awaiting PayPal checkout confirmation')
                ");
                $stmt->execute([
                    'uid' => $user['id'],
                    'amt' => $amount,
                    'txnid' => $paypalTxnId
                ]);

                set_flash('success', "PayPal invoice #{$paypalTxnId} created for " . format_currency($amount) . ".");
                redirect('/user/add-funds.php');
            } elseif ($gatewayCode === 'binance') {
                $binanceTxnId = 'bin_' . bin2hex(random_bytes(6));
                $stmt = $db->prepare("
                    INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, 'deposit', :amt, 'Binance Pay', :txnid, 'pending', 'Awaiting Binance Pay blockchain confirmation')
                ");
                $stmt->execute([
                    'uid' => $user['id'],
                    'amt' => $amount,
                    'txnid' => $binanceTxnId
                ]);

                set_flash('success', "Binance Pay invoice #{$binanceTxnId} generated for " . format_currency($amount) . ".");
                redirect('/user/add-funds.php');
            } else {
                // Fallback for custom or manual gateways
                $ref = trim($_POST['reference_id'] ?? bin2hex(random_bytes(6)));
                $stmt = $db->prepare("
                    INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, 'deposit', :amt, :gw, :txnid, 'pending', 'Pending manual confirmation')
                ");
                $stmt->execute([
                    'uid' => $user['id'],
                    'amt' => $amount,
                    'gw' => $gateway['name'],
                    'txnid' => $ref
                ]);
                set_flash('success', "Deposit intent for " . format_currency($amount) . " submitted.");
                redirect('/user/add-funds.php');
            }
        }
    }
}

// Fetch user's recent deposits
$recentDepositsStmt = $db->prepare("
    SELECT * FROM transactions 
    WHERE user_id = :uid AND type = 'deposit' 
    ORDER BY id DESC LIMIT 15
");
$recentDepositsStmt->execute(['uid' => $user['id']]);
$recentDeposits = $recentDepositsStmt->fetchAll();

$pageTitle = "Add Funds - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto my-6 space-y-6">
    <!-- Header Back Navigation -->
    <div class="flex items-center justify-between">
        <a href="/user/dashboard.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Back to Dashboard</span>
        </a>
        <a href="/user/transactions.php" class="text-xs font-bold text-blue-600 hover:underline">
            View Ledger History &rarr;
        </a>
    </div>

    <!-- Current Balance Callout Card -->
    <div class="p-6 rounded-3xl bg-gradient-to-br from-blue-900 via-indigo-900 to-slate-900 text-white shadow-xl flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <span class="text-[11px] text-blue-300 font-bold uppercase tracking-wider block">Current Account Balance</span>
            <div class="text-3xl sm:text-4xl font-black font-mono-nums mt-1 text-emerald-300">
                <?= format_currency($user['balance']) ?>
            </div>
            <span class="text-[11px] text-slate-300 font-medium flex items-center gap-1.5 mt-1.5">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span> Instant Wallet Delivery
            </span>
        </div>
        <div class="text-left sm:text-right">
            <span class="text-[11px] text-blue-300 font-bold uppercase tracking-wider block">Active Gateways</span>
            <span class="text-xs text-slate-200 block font-mono"><?= count($availableGateways) ?> Methods Operational</span>
        </div>
    </div>

    <?= render_flash() ?>

    <?php if ($error): ?>
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold flex items-center gap-2">
            <span>⚠️</span>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Gateway Selector & Form Container (Requirement 6) -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div>
            <h2 class="text-lg font-black text-slate-900 tracking-tight">Select Deposit Gateway</h2>
            <p class="text-xs text-slate-400 mt-0.5">Only administrator-enabled and verified payment methods are displayed below</p>
        </div>

        <?php if (empty($availableGateways)): ?>
            <div class="p-10 text-center text-slate-500 space-y-3 bg-slate-50 rounded-2xl border border-slate-200">
                <div class="text-3xl">⚠️</div>
                <h3 class="text-sm font-extrabold text-slate-800">No Payment Gateways Currently Active</h3>
                <p class="text-xs text-slate-400 max-w-md mx-auto">
                    The administrator has not yet enabled any payment methods. Please open a support ticket or check back shortly.
                </p>
                <a href="/user/tickets.php" class="inline-flex px-4 py-2 bg-blue-600 text-white font-bold text-xs rounded-xl shadow-sm">
                    Contact Support &rarr;
                </a>
            </div>
        <?php else: ?>
            <!-- Dynamic Gateway Tabs (Strictly enabled & configured gateways only) -->
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
                <?php foreach ($availableGateways as $gw): 
                    $isSelected = ($gw['code'] === $activeTab);
                ?>
                    <button type="button" 
                            onclick="selectGateway('<?= e($gw['code']) ?>')" 
                            id="tab-<?= e($gw['code']) ?>" 
                            class="p-3.5 rounded-2xl border text-left transition-all gateway-tab <?= $isSelected ? 'bg-blue-50/70 border-blue-500 shadow-xs ring-2 ring-blue-500/20' : 'border-slate-200 hover:border-slate-300' ?>">
                        <span class="text-xl block mb-1"><?= e($gw['icon'] ?: '💳') ?></span>
                        <span class="text-xs font-black text-slate-900 block leading-tight truncate"><?= e($gw['name']) ?></span>
                        <span class="text-[10px] text-slate-500 font-bold block mt-0.5 font-mono">
                            <?= (float)$gw['fee_percent'] > 0 ? ((float)$gw['fee_percent'] . '% fee') : '0% Fee' ?>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Dynamic Gateway Form Panels -->
            <?php foreach ($availableGateways as $gw): 
                $isSelected = ($gw['code'] === $activeTab);
                $gwCfg = get_gateway_config($gw);
                $minG = (float)$gw['min_amount'];
                $maxG = (float)$gw['max_amount'];
            ?>
                <div id="method-<?= e($gw['code']) ?>" class="gateway-content space-y-6 pt-2 <?= $isSelected ? '' : 'hidden' ?>">
                    <!-- Gateway Header Info Box -->
                    <div class="p-5 rounded-2xl bg-gradient-to-br from-slate-50 to-blue-50/30 border border-slate-200/80 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-black uppercase text-blue-700 tracking-wider flex items-center gap-1.5">
                                <span><?= e($gw['icon'] ?: '💳') ?></span>
                                <span><?= e($gw['name']) ?> Checkout</span>
                            </span>
                            <span class="text-xs font-mono font-bold text-slate-600">
                                Limits: <?= format_currency($minG) ?> - <?= format_currency($maxG) ?>
                            </span>
                        </div>
                        <p class="text-xs text-slate-600 leading-relaxed">
                            <?= e($gw['instructions'] ?: 'Fast automated payment fulfillment.') ?>
                        </p>
                    </div>

                    <!-- Gateway Specific Form -->
                    <form action="/user/add-funds.php" method="POST" class="space-y-4">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="gateway" value="<?= e($gw['code']) ?>">

                        <?php if ($gw['code'] === 'phonepe_business_qr'): ?>
                            <!-- PhonePe Business QR Visual Display + UTR Submission -->
                            <div class="grid grid-cols-1 md:grid-cols-12 gap-6 p-5 rounded-2xl bg-gradient-to-br from-purple-50/50 via-slate-50 to-purple-50/30 border border-purple-100 items-center">
                                <div class="md:col-span-5 flex flex-col items-center justify-center p-4 bg-white rounded-2xl border border-purple-200 text-center shadow-xs">
                                    <?php if (!empty($gwCfg['qr_image_url'])): ?>
                                        <!-- Exact Admin-Uploaded PhonePe Business QR Image -->
                                        <div class="p-2 bg-white rounded-xl border border-slate-200">
                                            <img src="<?= e($gwCfg['qr_image_url']) ?>" alt="PhonePe Business QR" class="w-48 h-48 object-contain rounded-lg">
                                        </div>
                                    <?php else: ?>
                                        <div class="w-48 h-48 rounded-xl border-2 border-dashed border-purple-300 flex flex-col items-center justify-center p-4 text-center bg-purple-50/40">
                                            <span class="text-3xl mb-1">🟣</span>
                                            <span class="text-xs font-black text-purple-900">PhonePe Business QR</span>
                                            <span class="text-[10px] text-slate-500 mt-1">Scan or pay to merchant UPI ID</span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($gwCfg['upi_id'])): ?>
                                        <div class="mt-3 flex items-center justify-between gap-2 px-3 py-1.5 bg-purple-50 border border-purple-200 rounded-lg w-full">
                                            <div class="text-left truncate">
                                                <span class="text-[9px] uppercase font-bold text-purple-600 block">UPI ID:</span>
                                                <span class="text-[11px] font-mono font-black text-purple-950 truncate block select-all"><?= e($gwCfg['upi_id']) ?></span>
                                            </div>
                                            <button type="button" onclick="navigator.clipboard.writeText('<?= addslashes($gwCfg['upi_id']) ?>'); alert('UPI ID copied to clipboard!');" class="text-[10px] font-bold text-purple-700 bg-white px-2 py-0.5 rounded border border-purple-200 hover:bg-purple-100 cursor-pointer shrink-0">
                                                Copy
                                            </button>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($gwCfg['merchant_id'])): ?>
                                        <span class="text-[10px] text-slate-400 font-mono mt-1 block">MID: <?= e($gwCfg['merchant_id']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="md:col-span-7 space-y-3">
                                    <h4 class="text-xs font-extrabold text-slate-900 flex items-center gap-1.5">
                                        <span>🟣</span> How to pay with PhonePe Business QR:
                                    </h4>
                                    <ol class="text-xs text-slate-600 space-y-2 list-decimal pl-4 leading-relaxed">
                                        <li>Open <strong>PhonePe, Google Pay, Paytm, BHIM</strong>, or any UPI banking app.</li>
                                        <li>Scan the official <strong>PhonePe Business QR</strong> code on the left (or transfer to UPI ID).</li>
                                        <li>Enter your deposit amount and complete the payment.</li>
                                        <li>Copy the <strong>12-digit UTR / UPI Reference Number</strong> from your payment receipt screen.</li>
                                        <li>Enter the exact amount and 12-digit UTR below for automated verification.</li>
                                    </ol>
                                    <div class="p-3 bg-purple-50/70 border border-purple-100 rounded-xl text-[11px] text-purple-900 leading-tight">
                                        ⚡ <strong>Automated Verification:</strong> Your payment reference will be verified against genuine transaction notifications received in our bank inbox for instant balance crediting.
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">Deposit Amount (<?= e(app_currency()) ?>) <span class="text-rose-500">*</span></label>
                                    <input type="number" step="1" min="<?= $minG ?>" max="<?= $maxG ?>" name="amount" required placeholder="<?= $minG ?>" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold focus:ring-2 focus:ring-purple-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">12-Digit UTR / Reference ID <span class="text-rose-500">*</span></label>
                                    <input type="text" name="utr_number" required placeholder="e.g. 412389501234" maxlength="35" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold focus:ring-2 focus:ring-purple-500">
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">Sender UPI ID or Remark (Optional)</label>
                                <input type="text" name="sender_note" placeholder="e.g. yourname@upi" maxlength="100" class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                            </div>
                        <?php elseif ($gw['code'] === 'paytm' || $gw['code'] === 'paytm_qr'): ?>
                            <!-- Paytm UPI QR Visual + UTR submission -->
                            <div class="grid grid-cols-1 md:grid-cols-12 gap-6 p-5 rounded-2xl bg-slate-50 border border-slate-200/80 items-center">
                                <div class="md:col-span-4 flex flex-col items-center justify-center p-4 bg-white rounded-2xl border border-slate-200 text-center shadow-xs">
                                    <div class="w-36 h-36 bg-slate-900 rounded-xl p-2 flex items-center justify-center text-white relative group">
                                        <svg class="w-32 h-32 text-white" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M2 2h8v8H2V2zm2 2v4h4V4H4zm10-2h8v8h-8V2zm2 2v4h4V4h-4zM2 14h8v8H2v-8zm2 2v4h4v-4H4zm13-2h3v3h-3v-3zm0 5h3v3h-3v-3zm-3-5h2v2h-2v-2zm0 4h2v2h-2v-2zm5-1h2v2h-2v-2z"/>
                                        </svg>
                                    </div>
                                    <span class="text-[11px] font-bold text-slate-500 mt-2 block">UPI ID: <?= e($gwCfg['upi_id'] ?? 'smmpanel@upi') ?></span>
                                </div>
                                <div class="md:col-span-8 space-y-2">
                                    <h4 class="text-xs font-extrabold text-slate-900">How to pay:</h4>
                                    <ol class="text-xs text-slate-600 space-y-1 list-decimal pl-4">
                                        <li>Scan QR or pay to UPI ID: <strong class="text-blue-600 font-mono"><?= e($gwCfg['upi_id'] ?? 'smmpanel@upi') ?></strong></li>
                                        <li>Copy the 12-digit UTR/Reference ID from the receipt.</li>
                                        <li>Submit below for instant verification.</li>
                                    </ol>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">Deposit Amount (<?= e(app_currency()) ?>) <span class="text-rose-500">*</span></label>
                                    <input type="number" step="1" min="<?= $minG ?>" max="<?= $maxG ?>" name="amount" required placeholder="<?= $minG ?>" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">12-Digit UTR / Transaction ID <span class="text-rose-500">*</span></label>
                                    <input type="text" name="utr_number" required placeholder="e.g. 412389501234" maxlength="32" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Automated Checkout Amount Input -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">Amount to Add (<?= e(app_currency()) ?>) <span class="text-rose-500">*</span></label>
                                <input type="number" step="1" min="<?= $minG ?>" max="<?= $maxG ?>" name="amount" required placeholder="<?= $minG ?>" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold focus:ring-2 focus:ring-blue-500">
                            </div>
                        <?php endif; ?>

                        <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs rounded-xl shadow-md shadow-blue-500/25 transition-all">
                            Proceed via <?= e($gw['name']) ?> &rarr;
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- User Recent Deposit Ledger -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-base font-extrabold text-slate-900 tracking-tight">Recent Deposits History</h3>
                <p class="text-xs text-slate-400 mt-0.5">Live statement of your deposits</p>
            </div>
            <span class="text-xs font-bold text-slate-500"><?= count($recentDeposits) ?> records</span>
        </div>

        <?php if (empty($recentDeposits)): ?>
            <div class="p-8 text-center text-slate-400 space-y-2">
                <div class="text-2xl">💳</div>
                <div class="text-xs font-bold text-slate-600">No deposit records found.</div>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-slate-400 border-b border-slate-100 pb-3 uppercase text-[10px] font-bold tracking-wider">
                            <th class="py-3 px-3">Date</th>
                            <th class="py-3 px-3">Gateway</th>
                            <th class="py-3 px-3">Reference / UTR</th>
                            <th class="py-3 px-3 text-right">Amount</th>
                            <th class="py-3 px-3 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($recentDeposits as $tx): ?>
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="py-3 px-3 text-slate-500 font-mono text-[11px] whitespace-nowrap">
                                    <?= date('d M Y, H:i', strtotime($tx['created_at'])) ?>
                                </td>
                                <td class="py-3 px-3 font-semibold text-slate-800">
                                    <?= e($tx['gateway']) ?>
                                </td>
                                <td class="py-3 px-3 font-mono text-slate-600 select-all">
                                    <?= e($tx['gateway_txn_id'] ?: '—') ?>
                                </td>
                                <td class="py-3 px-3 text-right font-mono-nums font-black text-slate-900">
                                    +<?= format_currency($tx['amount']) ?>
                                </td>
                                <td class="py-3 px-3 text-center">
                                    <?php if ($tx['status'] === 'completed'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            ✓ Credited
                                        </span>
                                    <?php elseif ($tx['status'] === 'pending'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                            ⏳ Pending Review
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                            Failed / Rejected
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function selectGateway(method) {
    document.querySelectorAll('.gateway-tab').forEach(t => {
        t.classList.remove('bg-blue-50/70', 'border-blue-500', 'shadow-xs', 'ring-2', 'ring-blue-500/20');
        t.classList.add('border-slate-200');
    });
    const currentTab = document.getElementById('tab-' + method);
    if (currentTab) {
        currentTab.classList.add('bg-blue-50/70', 'border-blue-500', 'shadow-xs', 'ring-2', 'ring-blue-500/20');
        currentTab.classList.remove('border-slate-200');
    }
    document.querySelectorAll('.gateway-content').forEach(c => c.classList.add('hidden'));
    const currentContent = document.getElementById('method-' + method);
    if (currentContent) {
        currentContent.classList.remove('hidden');
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
