<?php
/**
 * Admin Dashboard
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireAdmin();
$db = Database::getConnection();

// Aggregate stats
$totalUsers = (int)($db->query("SELECT COUNT(*) FROM users")->fetchColumn());
$ordersStats = $db->query("
    SELECT 
        COUNT(*) AS total_orders,
        COUNT(CASE WHEN status IN ('pending', 'processing') THEN 1 END) AS pending_orders
    FROM orders
")->fetch();
$totalOrders = (int)($ordersStats['total_orders'] ?? 0);
$pendingOrders = (int)($ordersStats['pending_orders'] ?? 0);
$totalRevenue = (float)($db->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = 'deposit' AND status = 'completed'")->fetchColumn());
$openTickets = (int)($db->query("SELECT COUNT(*) FROM tickets WHERE status = 'open'")->fetchColumn());

// Recent 5 orders
$recentOrders = $db->query("
    SELECT o.id, o.quantity, o.charge, o.status, o.created_at, u.username, s.name AS service_name
    FROM orders o
    JOIN users u ON o.user_id = u.id
    JOIN services s ON o.service_id = s.id
    ORDER BY o.id DESC
    LIMIT 5
")->fetchAll();

$pageTitle = "Admin Dashboard - " . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto my-4 space-y-6">
    <!-- Admin Top Bar Nav -->
    <div class="bg-slate-900 text-white rounded-3xl p-6 shadow-xl flex flex-wrap items-center justify-between gap-4">
        <div>
            <span class="px-2.5 py-1 rounded bg-indigo-500/20 text-indigo-300 text-[10px] font-bold uppercase tracking-wider">Administrator Mode</span>
            <h1 class="text-2xl font-extrabold mt-1">SMM Panel Control Center</h1>
        </div>
        <div class="flex items-center gap-2 overflow-x-auto text-xs font-semibold">
            <a href="/admin/dashboard.php" class="px-3.5 py-2 bg-indigo-600 rounded-xl">Dashboard</a>
            <a href="/admin/users.php" class="px-3.5 py-2 hover:bg-slate-800 rounded-xl transition-colors">Users</a>
            <a href="/admin/orders.php" class="px-3.5 py-2 hover:bg-slate-800 rounded-xl transition-colors">Orders</a>
            <a href="/admin/services.php" class="px-3.5 py-2 hover:bg-slate-800 rounded-xl transition-colors">Services</a>
            <a href="/admin/providers.php" class="px-3.5 py-2 hover:bg-slate-800 rounded-xl transition-colors">Providers</a>
            <a href="/admin/payments.php" class="px-3.5 py-2 hover:bg-slate-800 rounded-xl transition-colors">Payments</a>
            <a href="/admin/tickets.php" class="px-3.5 py-2 hover:bg-slate-800 rounded-xl transition-colors">Tickets</a>
            <a href="/admin/announcements.php" class="px-3.5 py-2 hover:bg-slate-800 rounded-xl transition-colors">Announcements</a>
            <a href="/admin/settings.php" class="px-3.5 py-2 hover:bg-slate-800 rounded-xl transition-colors">Settings</a>
            <a href="/user/dashboard.php" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-700 rounded-xl text-slate-300 transition-colors">Back to User View</a>
        </div>
    </div>

    <!-- 4 Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="bg-white p-6 rounded-3xl border border-slate-200/80 shadow-sm">
            <span class="text-xs font-semibold text-slate-400 block">Total Users</span>
            <span class="text-3xl font-extrabold text-slate-900 mt-2 block tabular-nums"><?= number_format($totalUsers) ?></span>
            <a href="/admin/users.php" class="text-xs font-bold text-blue-600 mt-3 inline-block hover:underline">Manage Users &rarr;</a>
        </div>
        <div class="bg-white p-6 rounded-3xl border border-slate-200/80 shadow-sm">
            <span class="text-xs font-semibold text-slate-400 block">Total Orders</span>
            <span class="text-3xl font-extrabold text-slate-900 mt-2 block tabular-nums"><?= number_format($totalOrders) ?></span>
            <span class="text-xs font-medium text-amber-600 mt-3 block"><?= $pendingOrders ?> pending/processing</span>
        </div>
        <div class="bg-white p-6 rounded-3xl border border-slate-200/80 shadow-sm">
            <span class="text-xs font-semibold text-slate-400 block">Total Deposits</span>
            <span class="text-3xl font-extrabold text-emerald-600 mt-2 block tabular-nums"><?= format_currency($totalRevenue) ?></span>
            <span class="text-xs font-medium text-slate-500 mt-3 block">Razorpay + Gateways</span>
        </div>
        <div class="bg-white p-6 rounded-3xl border border-slate-200/80 shadow-sm">
            <span class="text-xs font-semibold text-slate-400 block">Open Support Tickets</span>
            <span class="text-3xl font-extrabold text-slate-900 mt-2 block tabular-nums"><?= number_format($openTickets) ?></span>
            <a href="/admin/tickets.php" class="text-xs font-bold text-purple-600 mt-3 inline-block hover:underline">View Tickets &rarr;</a>
        </div>
    </div>

    <!-- Orders Trend Chart -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h2 class="text-base font-extrabold text-slate-900">Weekly Order Volume (Live Analytics)</h2>
                <p class="text-xs text-slate-500 mt-0.5">Real-time dispatched order progression across all server endpoints</p>
            </div>
            <span class="px-2.5 py-1 rounded bg-blue-50 text-blue-700 text-xs font-bold font-mono">Live Analytics</span>
        </div>
        <div class="h-64 mt-4 w-full">
            <canvas id="adminOrdersChart"></canvas>
        </div>
    </div>

    <!-- Recent Orders Table -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm">
        <div class="flex items-center justify-between pb-6 border-b border-slate-100">
            <h2 class="text-lg font-extrabold text-slate-900">Recent System Orders</h2>
            <a href="/admin/orders.php" class="text-xs font-bold text-blue-600 hover:underline">View All &rarr;</a>
        </div>
        <div class="overflow-x-auto mt-4">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-slate-200 text-slate-400 uppercase tracking-wider">
                        <th class="py-3 px-3">ID</th>
                        <th class="py-3 px-3">User</th>
                        <th class="py-3 px-3">Service</th>
                        <th class="py-3 px-3">Qty</th>
                        <th class="py-3 px-3">Charge</th>
                        <th class="py-3 px-3">Status</th>
                        <th class="py-3 px-3 text-right">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php foreach ($recentOrders as $ro): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="py-3 px-3 font-mono text-slate-400">#<?= (int)$ro['id'] ?></td>
                            <td class="py-3 px-3 font-bold text-slate-900"><?= e($ro['username']) ?></td>
                            <td class="py-3 px-3 font-medium text-slate-800"><?= e($ro['service_name']) ?></td>
                            <td class="py-3 px-3 font-mono-nums"><?= number_format($ro['quantity']) ?></td>
                            <td class="py-3 px-3 font-extrabold text-slate-900"><?= format_currency($ro['charge']) ?></td>
                            <td class="py-3 px-3">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase <?= $ro['status'] === 'completed' ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600' ?>">
                                    <?= e($ro['status']) ?>
                                </span>
                            </td>
                            <td class="py-3 px-3 text-right text-slate-400"><?= date('d M, h:i A', strtotime($ro['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
