<?php
/**
 * User Transactions Ledger
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireLogin();
$user = Auth::user();
$db = Database::getConnection();

$typeFilter = strtolower(trim($_GET['type'] ?? 'all'));

$sql = "SELECT * FROM transactions WHERE user_id = :uid";
$params = ['uid' => $user['id']];

if ($typeFilter === 'add_funds') {
    $sql .= " AND type = 'deposit'";
} elseif ($typeFilter === 'orders') {
    $sql .= " AND type = 'order'";
} elseif ($typeFilter === 'refunds') {
    $sql .= " AND type = 'refund'";
} elseif ($typeFilter === 'bonuses') {
    $sql .= " AND type = 'bonus'";
}

$sql .= " ORDER BY created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Fetch summary stats
$statStmt = $db->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END), 0) AS total_deposits,
        COALESCE(SUM(CASE WHEN type = 'order' THEN ABS(amount) ELSE 0 END), 0) AS total_spent
    FROM transactions 
    WHERE user_id = :uid
");
$statStmt->execute(['uid' => $user['id']]);
$stats = $statStmt->fetch() ?: ['total_deposits' => 0, 'total_spent' => 0];

$pageTitle = "Transactions - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto my-4 space-y-6">
    <!-- Header Navigation -->
    <div class="flex items-center justify-between">
        <a href="/user/dashboard.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Back to Dashboard</span>
        </a>
        <a href="/user/add-funds.php" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-md transition-all">
            + Add Funds
        </a>
    </div>

    <!-- Financial Stats Row -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
        <div class="bg-white p-5 rounded-3xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block">Available Balance</span>
                <span class="text-2xl font-black text-slate-900 tabular-nums font-mono-nums"><?= format_currency($user['balance']) ?></span>
            </div>
            <div class="w-11 h-11 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center text-xl font-bold">
                💳
            </div>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block">Total Deposited</span>
                <span class="text-2xl font-black text-emerald-600 tabular-nums font-mono-nums"><?= format_currency($stats['total_deposits']) ?></span>
            </div>
            <div class="w-11 h-11 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl font-bold">
                ↓
            </div>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block">Total Deductions</span>
                <span class="text-2xl font-black text-slate-700 tabular-nums font-mono-nums"><?= format_currency($stats['total_spent']) ?></span>
            </div>
            <div class="w-11 h-11 rounded-2xl bg-slate-100 text-slate-700 flex items-center justify-center text-xl font-bold">
                ↑
            </div>
        </div>
    </div>

    <!-- Main Ledger Card -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl">
        <div class="pb-5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h2 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">Financial Ledger</h2>
                <p class="text-xs text-slate-400 mt-0.5">Complete statement of deposits, order payments, and adjustments</p>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="flex items-center gap-2 overflow-x-auto py-4">
            <a href="/user/transactions.php?type=all" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $typeFilter === 'all' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">All Transactions</a>
            <a href="/user/transactions.php?type=add_funds" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $typeFilter === 'add_funds' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">Deposits (Add Funds)</a>
            <a href="/user/transactions.php?type=orders" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $typeFilter === 'orders' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">Order Charges</a>
            <a href="/user/transactions.php?type=bonuses" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $typeFilter === 'bonuses' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">Referral Rewards</a>
            <a href="/user/transactions.php?type=refunds" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $typeFilter === 'refunds' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">Refunds</a>
        </div>

        <!-- Transactions List -->
        <?php if (empty($transactions)): ?>
            <div class="py-16 text-center">
                <div class="w-16 h-16 rounded-2xl bg-slate-100 mx-auto flex items-center justify-center text-2xl mb-3 shadow-xs">💳</div>
                <h3 class="text-sm font-bold text-slate-900">No transactions recorded yet</h3>
                <p class="text-xs text-slate-500 mt-1">Deposits and order charges will appear here in chronological order.</p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-slate-100 mt-2">
                <?php foreach ($transactions as $txn): 
                    $isDeposit = ((float)$txn['amount'] > 0);
                ?>
                    <div class="py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-slate-50/70 p-3 rounded-2xl transition-all">
                        <div class="flex items-center gap-3.5">
                            <div class="w-11 h-11 rounded-2xl flex items-center justify-center text-base font-black shadow-xs shrink-0 <?= $isDeposit ? 'bg-emerald-50 text-emerald-600 border border-emerald-100' : 'bg-rose-50 text-rose-600 border border-rose-100' ?>">
                                <?= $isDeposit ? '↓' : '↑' ?>
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-slate-900">
                                    <?= $isDeposit ? 'Wallet Deposit' : 'Order Charge' ?>
                                </h4>
                                <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500 mt-0.5">
                                    <span class="font-semibold text-slate-700"><?= e($txn['note'] ?: $txn['gateway']) ?></span>
                                    <span>•</span>
                                    <span class="font-mono text-[11px] text-slate-400"><?= e($txn['gateway_txn_id']) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center justify-between sm:justify-end gap-3 text-right shrink-0 pl-14 sm:pl-0">
                            <div>
                                <span class="text-base font-black tabular-nums font-mono-nums <?= $isDeposit ? 'text-emerald-600' : 'text-slate-900' ?>">
                                    <?= $isDeposit ? '+' : '' ?><?= format_currency($txn['amount']) ?>
                                </span>
                                <span class="block text-[11px] text-slate-400 mt-0.5">
                                    <?= date('d M Y, h:i A', strtotime($txn['created_at'])) ?>
                                </span>
                            </div>
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-wider <?= $txn['status'] === 'completed' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-600' ?>">
                                <?= e($txn['status']) ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
