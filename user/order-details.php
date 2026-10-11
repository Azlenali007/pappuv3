<?php
/**
 * User Order Details
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

$orderId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT o.*, s.name AS service_name, s.speed, c.name AS category_name, c.platform
    FROM orders o
    JOIN services s ON o.service_id = s.id
    JOIN categories c ON s.category_id = c.id
    WHERE o.id = :oid AND o.user_id = :uid
    LIMIT 1
");
$stmt->execute(['oid' => $orderId, 'uid' => $user['id']]);
$order = $stmt->fetch();

if (!$order) {
    set_flash('error', 'Order not found.');
    redirect('/user/orders.php');
}

$pageTitle = "Order #" . $order['id'] . " - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-3xl mx-auto my-6 space-y-6">
    <!-- Top Bar Navigation -->
    <div class="flex items-center justify-between">
        <a href="/user/orders.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Back to Orders</span>
        </a>
        <a href="/user/tickets.php?order_id=<?= (int)$order['id'] ?>" class="inline-flex items-center gap-1 text-xs font-bold text-blue-600 hover:bg-blue-50 px-3 py-1.5 rounded-xl transition-colors">
            <span>💬 Need Help?</span>
        </a>
    </div>

    <!-- Main Card -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl space-y-6">
        <!-- Order Header -->
        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4 border-b border-slate-100 pb-6">
            <div>
                <div class="flex items-center gap-2">
                    <span class="text-xs font-mono font-bold text-blue-600 bg-blue-50 px-2.5 py-0.5 rounded-lg border border-blue-100">ORDER #<?= (int)$order['id'] ?></span>
                    <span class="text-xs text-slate-400 font-medium"><?= date('d M Y, h:i A', strtotime($order['created_at'])) ?></span>
                </div>
                <h2 class="text-xl sm:text-2xl font-black text-slate-900 mt-2 tracking-tight"><?= e($order['service_name']) ?></h2>
                <span class="text-xs font-semibold text-slate-500 mt-0.5 block"><?= e($order['category_name']) ?></span>
            </div>
            <span class="self-start px-4 py-1.5 rounded-full text-xs font-black uppercase tracking-wider <?= $order['status'] === 'completed' ? 'bg-emerald-50 text-emerald-600 border border-emerald-200' : ($order['status'] === 'processing' ? 'bg-amber-50 text-amber-600 border border-amber-200' : 'bg-slate-100 text-slate-600') ?>">
                <?= e($order['status']) ?>
            </span>
        </div>

        <!-- Order Progress Timeline -->
        <div class="py-2">
            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block mb-3">Order Status Pipeline</span>
            <div class="flex items-center justify-between relative max-w-md mx-auto">
                <!-- Step 1: Placed -->
                <div class="flex flex-col items-center gap-1.5 z-10">
                    <div class="w-8 h-8 rounded-full bg-blue-600 text-white flex items-center justify-center text-xs font-bold shadow-md shadow-blue-500/25">✓</div>
                    <span class="text-[11px] font-bold text-slate-700">Received</span>
                </div>
                <div class="flex-1 h-1 bg-blue-600 -mt-5"></div>

                <!-- Step 2: Processing -->
                <div class="flex flex-col items-center gap-1.5 z-10">
                    <div class="w-8 h-8 rounded-full <?= in_array($order['status'], ['processing', 'completed']) ? 'bg-blue-600 text-white shadow-md shadow-blue-500/25' : 'bg-slate-100 text-slate-400' ?> flex items-center justify-center text-xs font-bold">
                        <?= $order['status'] === 'completed' ? '✓' : '⚡' ?>
                    </div>
                    <span class="text-[11px] font-bold <?= in_array($order['status'], ['processing', 'completed']) ? 'text-blue-600' : 'text-slate-400' ?>">Processing</span>
                </div>
                <div class="flex-1 h-1 <?= $order['status'] === 'completed' ? 'bg-blue-600' : 'bg-slate-200' ?> -mt-5"></div>

                <!-- Step 3: Completed -->
                <div class="flex flex-col items-center gap-1.5 z-10">
                    <div class="w-8 h-8 rounded-full <?= $order['status'] === 'completed' ? 'bg-emerald-600 text-white shadow-md shadow-emerald-500/25' : 'bg-slate-100 text-slate-400' ?> flex items-center justify-center text-xs font-bold">
                        <?= $order['status'] === 'completed' ? '✓' : '3' ?>
                    </div>
                    <span class="text-[11px] font-bold <?= $order['status'] === 'completed' ? 'text-emerald-600' : 'text-slate-400' ?>">Completed</span>
                </div>
            </div>
        </div>

        <!-- 4-Card Details Grid -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-slate-50/80 p-4 rounded-3xl border border-slate-200/70 text-center">
            <div class="bg-white p-3.5 rounded-2xl border border-slate-100 shadow-xs">
                <span class="text-[11px] text-slate-400 block font-bold uppercase tracking-wider">Quantity</span>
                <span class="text-lg font-black text-slate-900 tabular-nums font-mono-nums"><?= number_format($order['quantity']) ?></span>
            </div>
            <div class="bg-white p-3.5 rounded-2xl border border-slate-100 shadow-xs">
                <span class="text-[11px] text-slate-400 block font-bold uppercase tracking-wider">Total Charge</span>
                <span class="text-lg font-black text-blue-600 tabular-nums font-mono-nums"><?= format_currency($order['charge']) ?></span>
            </div>
            <div class="bg-white p-3.5 rounded-2xl border border-slate-100 shadow-xs">
                <span class="text-[11px] text-slate-400 block font-bold uppercase tracking-wider">Start Count</span>
                <span class="text-lg font-black text-slate-900 tabular-nums font-mono-nums"><?= number_format($order['start_count']) ?></span>
            </div>
            <div class="bg-white p-3.5 rounded-2xl border border-slate-100 shadow-xs">
                <span class="text-[11px] text-slate-400 block font-bold uppercase tracking-wider">Remains</span>
                <span class="text-lg font-black text-slate-900 tabular-nums font-mono-nums"><?= number_format($order['remains']) ?></span>
            </div>
        </div>

        <!-- Target Link Box with Copy Button -->
        <div>
            <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-2">Target Link / URL</label>
            <div class="flex items-center gap-2 p-3.5 bg-slate-50 border border-slate-200 rounded-2xl">
                <span class="text-slate-400">🔗</span>
                <span class="text-xs font-mono text-blue-600 break-all select-all flex-1 truncate">
                    <?= e($order['link']) ?>
                </span>
                <button type="button" onclick="navigator.clipboard.writeText('<?= addslashes($order['link']) ?>'); notify.success('Link copied to clipboard!');" class="px-3 py-1 rounded-xl bg-white border border-slate-200 text-xs font-bold text-slate-700 hover:bg-slate-100 transition-colors whitespace-nowrap cursor-pointer">
                    Copy
                </button>
            </div>
        </div>

        <!-- Footer Meta -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs text-slate-400 border-t border-slate-100 pt-4 font-medium">
            <span>Created: <?= date('d M Y, h:i:s A', strtotime($order['created_at'])) ?></span>
            <span>Estimated Speed: <?= e($order['speed']) ?></span>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
