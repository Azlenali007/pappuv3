<?php
/**
 * Admin Flash Sale Management Portal
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireAdmin();
$db = Database::getConnection();

// Self-healing table check
ensure_flash_sales_table_exists($db);

// Handle Actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';

    // 1. Create Flash Sale
    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $badgeText = trim($_POST['badge_text'] ?? '⚡ FLASH SALE');
        $discountType = in_array($_POST['discount_type'] ?? '', ['percentage', 'fixed'], true) ? $_POST['discount_type'] : 'percentage';
        $discountValue = max(0.01, (float)($_POST['discount_value'] ?? 20.0));
        $scopeType = in_array($_POST['scope_type'] ?? '', ['all', 'category', 'services'], true) ? $_POST['scope_type'] : 'all';
        $categoryId = ($scopeType === 'category' && !empty($_POST['category_id'])) ? (int)$_POST['category_id'] : null;
        
        $serviceIds = null;
        if ($scopeType === 'services' && !empty($_POST['service_ids'])) {
            $rawIds = is_array($_POST['service_ids']) ? $_POST['service_ids'] : explode(',', (string)$_POST['service_ids']);
            $cleanIds = array_filter(array_map('intval', $rawIds));
            if (!empty($cleanIds)) {
                $serviceIds = implode(',', $cleanIds);
            }
        }

        $startsAt = !empty($_POST['starts_at']) ? date('Y-m-d H:i:s', strtotime($_POST['starts_at'])) : date('Y-m-d H:i:s');
        $endsAt = !empty($_POST['ends_at']) ? date('Y-m-d H:i:s', strtotime($_POST['ends_at'])) : date('Y-m-d H:i:s', strtotime('+3 days'));
        $maxOrders = !empty($_POST['max_orders']) ? (int)$_POST['max_orders'] : null;
        $bannerBg = trim($_POST['banner_bg'] ?? 'from-blue-600 via-indigo-600 to-purple-600');
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

        if (empty($title)) {
            set_flash('error', 'Sale title is required.');
        } elseif (strtotime($endsAt) <= strtotime($startsAt)) {
            set_flash('error', 'Sale end date must be after the start date.');
        } else {
            $stmt = $db->prepare("
                INSERT INTO flash_sales 
                (title, subtitle, badge_text, discount_type, discount_value, scope_type, category_id, service_ids, starts_at, ends_at, status, max_orders, banner_bg)
                VALUES (:title, :subtitle, :badge, :dtype, :dval, :stype, :cid, :sids, :starts, :ends, :status, :maxo, :bg)
            ");
            $stmt->execute([
                'title' => $title,
                'subtitle' => $subtitle ?: null,
                'badge' => $badgeText,
                'dtype' => $discountType,
                'dval' => $discountValue,
                'stype' => $scopeType,
                'cid' => $categoryId,
                'sids' => $serviceIds,
                'starts' => $startsAt,
                'ends' => $endsAt,
                'status' => $status,
                'maxo' => $maxOrders,
                'bg' => $bannerBg
            ]);
            set_flash('success', "Flash sale '{$title}' created successfully.");
        }
        redirect('/admin/flash-sales.php');
    }

    // 2. Update Flash Sale
    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $badgeText = trim($_POST['badge_text'] ?? '⚡ FLASH SALE');
        $discountType = in_array($_POST['discount_type'] ?? '', ['percentage', 'fixed'], true) ? $_POST['discount_type'] : 'percentage';
        $discountValue = max(0.01, (float)($_POST['discount_value'] ?? 20.0));
        $scopeType = in_array($_POST['scope_type'] ?? '', ['all', 'category', 'services'], true) ? $_POST['scope_type'] : 'all';
        $categoryId = ($scopeType === 'category' && !empty($_POST['category_id'])) ? (int)$_POST['category_id'] : null;
        
        $serviceIds = null;
        if ($scopeType === 'services' && !empty($_POST['service_ids'])) {
            $rawIds = is_array($_POST['service_ids']) ? $_POST['service_ids'] : explode(',', (string)$_POST['service_ids']);
            $cleanIds = array_filter(array_map('intval', $rawIds));
            if (!empty($cleanIds)) {
                $serviceIds = implode(',', $cleanIds);
            }
        }

        $startsAt = !empty($_POST['starts_at']) ? date('Y-m-d H:i:s', strtotime($_POST['starts_at'])) : date('Y-m-d H:i:s');
        $endsAt = !empty($_POST['ends_at']) ? date('Y-m-d H:i:s', strtotime($_POST['ends_at'])) : date('Y-m-d H:i:s', strtotime('+3 days'));
        $maxOrders = !empty($_POST['max_orders']) ? (int)$_POST['max_orders'] : null;
        $bannerBg = trim($_POST['banner_bg'] ?? 'from-blue-600 via-indigo-600 to-purple-600');
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive', 'archived'], true) ? $_POST['status'] : 'active';

        if ($id <= 0) {
            set_flash('error', 'Invalid flash sale ID.');
        } elseif (empty($title)) {
            set_flash('error', 'Sale title is required.');
        } elseif (strtotime($endsAt) <= strtotime($startsAt)) {
            set_flash('error', 'Sale end date must be after start date.');
        } else {
            $stmt = $db->prepare("
                UPDATE flash_sales
                SET title = :title,
                    subtitle = :subtitle,
                    badge_text = :badge,
                    discount_type = :dtype,
                    discount_value = :dval,
                    scope_type = :stype,
                    category_id = :cid,
                    service_ids = :sids,
                    starts_at = :starts,
                    ends_at = :ends,
                    status = :status,
                    max_orders = :maxo,
                    banner_bg = :bg
                WHERE id = :id
            ");
            $stmt->execute([
                'title' => $title,
                'subtitle' => $subtitle ?: null,
                'badge' => $badgeText,
                'dtype' => $discountType,
                'dval' => $discountValue,
                'stype' => $scopeType,
                'cid' => $categoryId,
                'sids' => $serviceIds,
                'starts' => $startsAt,
                'ends' => $endsAt,
                'status' => $status,
                'maxo' => $maxOrders,
                'bg' => $bannerBg,
                'id' => $id
            ]);
            set_flash('success', "Flash sale updated successfully.");
        }
        redirect('/admin/flash-sales.php');
    }

    // 3. Toggle Status
    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT status FROM flash_sales WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $curr = $stmt->fetchColumn();
        if ($curr !== false) {
            $newStatus = ($curr === 'active') ? 'inactive' : 'active';
            $upd = $db->prepare("UPDATE flash_sales SET status = :status WHERE id = :id");
            $upd->execute(['status' => $newStatus, 'id' => $id]);
            set_flash('success', "Flash sale status changed to " . ucfirst($newStatus) . ".");
        }
        redirect('/admin/flash-sales.php');
    }

    // 4. Delete Flash Sale
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $del = $db->prepare("DELETE FROM flash_sales WHERE id = :id");
        $del->execute(['id' => $id]);
        set_flash('success', "Flash sale removed successfully.");
        redirect('/admin/flash-sales.php');
    }
}

// Fetch all flash sales
$salesStmt = $db->query("
    SELECT fs.*, c.name AS category_name, c.platform AS category_platform
    FROM flash_sales fs
    LEFT JOIN categories c ON fs.category_id = c.id
    ORDER BY fs.id DESC
");
$allSales = $salesStmt->fetchAll() ?: [];

// Fetch categories and services for modal selectors
$categories = $db->query("SELECT id, name, platform FROM categories WHERE status = 'active' ORDER BY sort_order ASC, name ASC")->fetchAll() ?: [];
$services = $db->query("SELECT id, name, category_id, rate_per_1000 FROM services WHERE status = 'active' ORDER BY category_id ASC, id ASC")->fetchAll() ?: [];

// Compute statistics
$totalSales = count($allSales);
$activeSalesCount = 0;
$totalOrdersDriven = 0;
$nowTime = time();

foreach ($allSales as $s) {
    $isNowActive = ($s['status'] === 'active' && strtotime($s['starts_at']) <= $nowTime && strtotime($s['ends_at']) > $nowTime);
    if ($isNowActive) {
        $activeSalesCount++;
    }
    $totalOrdersDriven += (int)$s['orders_count'];
}

$pageTitle = "Flash Sales Management - Admin Panel";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto space-y-6" x-data="flashSaleAdmin(<?= htmlspecialchars(json_encode($allSales), ENT_QUOTES, 'UTF-8') ?>)">
    <!-- Header with Breadcrumb & Action Button -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs font-bold text-slate-400 uppercase tracking-widest">
                <a href="/admin/dashboard.php" class="hover:text-blue-600 transition-colors">Admin</a>
                <span>/</span>
                <span class="text-slate-700">Promotions</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight mt-1 flex items-center gap-2.5">
                <span class="w-9 h-9 rounded-2xl bg-gradient-to-tr from-amber-500 via-rose-500 to-purple-600 text-white flex items-center justify-center shadow-md shadow-rose-500/20 text-lg">⚡</span>
                Flash Sales Engine
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 mt-1">
                Create and manage limited-time countdown offers with automatic discounted pricing on user and landing portals.
            </p>
        </div>

        <button @click="openCreateModal()" type="button" class="px-5 py-2.5 rounded-2xl bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 text-white font-extrabold text-xs shadow-lg shadow-indigo-500/25 flex items-center gap-2 hover:scale-[1.02] active:scale-[0.98] transition-all self-start sm:self-auto">
            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
            <span>Create New Flash Sale</span>
        </button>
    </div>

    <?= render_flash() ?>

    <!-- Stat Metrics Row -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-3xl p-5 border border-slate-200/80 shadow-xs flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-blue-50 border border-blue-100 text-blue-600 flex items-center justify-center text-xl shrink-0">
                🔥
            </div>
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Active Flash Sales</span>
                <div class="flex items-baseline gap-2 mt-0.5">
                    <span class="text-2xl font-black text-slate-900"><?= number_format($activeSalesCount) ?></span>
                    <span class="text-xs font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-100">Live Now</span>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-3xl p-5 border border-slate-200/80 shadow-xs flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-purple-50 border border-purple-100 text-purple-600 flex items-center justify-center text-xl shrink-0">
                📦
            </div>
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Flash Orders Driven</span>
                <div class="flex items-baseline gap-2 mt-0.5">
                    <span class="text-2xl font-black text-slate-900"><?= number_format($totalOrdersDriven) ?></span>
                    <span class="text-xs font-semibold text-slate-400">Total volume</span>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-3xl p-5 border border-slate-200/80 shadow-xs flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-amber-50 border border-amber-100 text-amber-600 flex items-center justify-center text-xl shrink-0">
                🎯
            </div>
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Total Campaigns</span>
                <div class="flex items-baseline gap-2 mt-0.5">
                    <span class="text-2xl font-black text-slate-900"><?= number_format($totalSales) ?></span>
                    <span class="text-xs font-semibold text-slate-400">All-time</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Sales List Table / Cards -->
    <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="p-5 border-b border-slate-100 flex items-center justify-between">
            <h2 class="text-base font-extrabold text-slate-900 flex items-center gap-2">
                <span>All Flash Sale Campaigns</span>
                <span class="text-xs font-bold text-slate-400 bg-slate-100 px-2.5 py-0.5 rounded-full"><?= count($allSales) ?></span>
            </h2>
        </div>

        <?php if (empty($allSales)): ?>
            <div class="py-16 text-center px-4">
                <div class="w-16 h-16 rounded-3xl bg-amber-50 border border-amber-100 text-amber-600 flex items-center justify-center text-3xl mx-auto shadow-inner mb-4">
                    ⚡
                </div>
                <h3 class="text-base font-bold text-slate-800">No Flash Sales Created Yet</h3>
                <p class="text-xs text-slate-400 max-w-sm mx-auto mt-1">
                    Launch a high-conversion flash deal with live countdown timers and discounted rates to boost user engagement!
                </p>
                <button @click="openCreateModal()" type="button" class="mt-4 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-sm transition-colors">
                    Create First Campaign
                </button>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50/70 border-b border-slate-100 text-[11px] font-extrabold text-slate-400 uppercase tracking-wider">
                            <th class="py-3.5 px-5">Campaign</th>
                            <th class="py-3.5 px-4">Discount</th>
                            <th class="py-3.5 px-4">Scope</th>
                            <th class="py-3.5 px-4">Schedule & Remaining</th>
                            <th class="py-3.5 px-4">Orders</th>
                            <th class="py-3.5 px-4">Status</th>
                            <th class="py-3.5 px-5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-xs">
                        <?php foreach ($allSales as $s): 
                            $startsTs = strtotime($s['starts_at']);
                            $endsTs = strtotime($s['ends_at']);
                            $isLive = ($s['status'] === 'active' && $startsTs <= $nowTime && $endsTs > $nowTime);
                            $isFuture = ($startsTs > $nowTime);
                            $isExpired = ($endsTs <= $nowTime);
                            $remainingSec = max(0, $endsTs - $nowTime);
                            $hours = floor($remainingSec / 3600);
                            $mins = floor(($remainingSec % 3600) / 60);
                        ?>
                            <tr class="hover:bg-slate-50/60 transition-colors">
                                <!-- Campaign Title & Badge -->
                                <td class="py-4 px-5">
                                    <div class="flex items-start gap-3">
                                        <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr <?= e($s['banner_bg']) ?> text-white flex items-center justify-center shrink-0 shadow-xs font-bold text-sm">
                                            ⚡
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <span class="font-extrabold text-slate-900"><?= e($s['title']) ?></span>
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-indigo-50 border border-indigo-100 text-indigo-600">
                                                    <?= e($s['badge_text']) ?>
                                                </span>
                                            </div>
                                            <?php if (!empty($s['subtitle'])): ?>
                                                <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1 max-w-xs"><?= e($s['subtitle']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <!-- Discount -->
                                <td class="py-4 px-4 font-bold text-slate-800">
                                    <?php if ($s['discount_type'] === 'percentage'): ?>
                                        <span class="px-2.5 py-1 rounded-xl bg-purple-50 text-purple-700 font-extrabold border border-purple-100">
                                            <?= rtrim(rtrim(number_format((float)$s['discount_value'], 2), '0'), '.') ?>% OFF
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 rounded-xl bg-emerald-50 text-emerald-700 font-extrabold border border-emerald-100">
                                            <?= format_currency((float)$s['discount_value']) ?> OFF
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Scope -->
                                <td class="py-4 px-4">
                                    <?php if ($s['scope_type'] === 'all'): ?>
                                        <span class="px-2.5 py-1 rounded-lg bg-blue-50 text-blue-700 font-bold text-[11px] border border-blue-100">
                                            All Services
                                        </span>
                                    <?php elseif ($s['scope_type'] === 'category'): ?>
                                        <div class="text-[11px]">
                                            <span class="font-bold text-slate-700"><?= e($s['category_name'] ?? 'Category #' . $s['category_id']) ?></span>
                                            <span class="text-slate-400 block text-[10px]">Category Scope</span>
                                        </div>
                                    <?php else: ?>
                                        <?php 
                                            $ids = array_filter(array_map('intval', explode(',', (string)$s['service_ids'])));
                                        ?>
                                        <span class="px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700 font-bold text-[11px] border border-slate-200">
                                            <?= count($ids) ?> Selected Services
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Schedule & Remaining -->
                                <td class="py-4 px-4">
                                    <?php if ($isLive): ?>
                                        <div class="flex items-center gap-1.5 text-rose-600 font-extrabold text-xs">
                                            <span class="w-2 h-2 rounded-full bg-rose-500 animate-pulse"></span>
                                            <span><?= $hours ?>h <?= $mins ?>m remaining</span>
                                        </div>
                                        <span class="text-[10px] text-slate-400 block mt-0.5">Ends: <?= date('M d, H:i', $endsTs) ?></span>
                                    <?php elseif ($isFuture): ?>
                                        <span class="text-xs font-bold text-indigo-600">Starts in <?= max(1, round(($startsTs - $nowTime) / 3600)) ?>h</span>
                                        <span class="text-[10px] text-slate-400 block mt-0.5"><?= date('M d, H:i', $startsTs) ?></span>
                                    <?php elseif ($isExpired): ?>
                                        <span class="text-xs font-bold text-slate-400">Ended</span>
                                        <span class="text-[10px] text-slate-400 block mt-0.5"><?= date('M d, H:i', $endsTs) ?></span>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-400">Inactive</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Orders Count -->
                                <td class="py-4 px-4">
                                    <span class="font-extrabold text-slate-800"><?= number_format((int)$s['orders_count']) ?></span>
                                    <?php if (!empty($s['max_orders'])): ?>
                                        <span class="text-slate-400 text-[10px] block">/ <?= number_format((int)$s['max_orders']) ?> cap</span>
                                    <?php else: ?>
                                        <span class="text-slate-400 text-[10px] block">unlimited</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Status Badge -->
                                <td class="py-4 px-4">
                                    <?php if ($s['status'] === 'inactive'): ?>
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-extrabold bg-slate-100 text-slate-500 border border-slate-200">
                                            Disabled
                                        </span>
                                    <?php elseif ($isExpired): ?>
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-extrabold bg-amber-50 text-amber-700 border border-amber-100">
                                            Expired
                                        </span>
                                    <?php elseif ($isFuture): ?>
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-extrabold bg-blue-50 text-blue-700 border border-blue-100">
                                            Scheduled
                                        </span>
                                    <?php elseif ($isLive): ?>
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-extrabold bg-emerald-50 text-emerald-700 border border-emerald-100 flex items-center gap-1 w-fit">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-ping"></span>
                                            Active
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-extrabold bg-slate-100 text-slate-600">
                                            <?= e(ucfirst($s['status'])) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions -->
                                <td class="py-4 px-5 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <!-- Toggle Status Form -->
                                        <form method="POST" action="/admin/flash-sales.php" class="inline">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                            <button type="submit" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition-colors" title="<?= $s['status'] === 'active' ? 'Disable Campaign' : 'Enable Campaign' ?>">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                            </button>
                                        </form>

                                        <!-- Edit Button -->
                                        <button @click="openEditModal(<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>)" type="button" class="p-1.5 text-blue-600 hover:text-blue-800 rounded-lg hover:bg-blue-50 transition-colors" title="Edit Campaign">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                        </button>

                                        <!-- Delete Form -->
                                        <form method="POST" action="/admin/flash-sales.php" class="inline" onsubmit="return confirm('Are you sure you want to delete this flash sale?');">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                            <button type="submit" class="p-1.5 text-rose-500 hover:text-rose-700 rounded-lg hover:bg-rose-50 transition-colors" title="Delete Campaign">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
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

    <!-- Create / Edit Modal -->
    <div x-show="modalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6" style="display: none;" @keydown.escape.window="modalOpen = false">
        <div class="fixed inset-0 bg-slate-950/60 backdrop-blur-sm" @click="modalOpen = false"></div>

        <div class="relative w-full max-w-2xl bg-white rounded-3xl shadow-2xl border border-slate-100 overflow-hidden z-10 max-h-[90vh] flex flex-col">
            <!-- Modal Header -->
            <div class="px-6 py-4.5 bg-gradient-to-r from-slate-900 to-indigo-950 text-white flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <span class="w-8 h-8 rounded-xl bg-white/10 flex items-center justify-center text-lg">⚡</span>
                    <h3 class="text-base font-black tracking-tight" x-text="isEdit ? 'Edit Flash Sale Campaign' : 'Create New Flash Sale'"></h3>
                </div>
                <button type="button" @click="modalOpen = false" class="text-slate-400 hover:text-white transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <!-- Modal Body Form -->
            <form method="POST" action="/admin/flash-sales.php" class="p-6 space-y-4 overflow-y-auto">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" :value="isEdit ? 'update' : 'create'">
                <input type="hidden" name="id" x-model="form.id">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Title -->
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Campaign Title *</label>
                        <input type="text" name="title" x-model="form.title" required placeholder="e.g. Weekend Mega Boost" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                    </div>

                    <!-- Subtitle -->
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Subtitle / Promo Description</label>
                        <input type="text" name="subtitle" x-model="form.subtitle" placeholder="e.g. Grab instant viral boosts on top Instagram & YouTube services!" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                    </div>

                    <!-- Badge Text -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Badge Text</label>
                        <input type="text" name="badge_text" x-model="form.badge_text" placeholder="⚡ 30% OFF" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                    </div>

                    <!-- Status -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Status</label>
                        <select name="status" x-model="form.status" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                            <option value="active">Active (Visible)</option>
                            <option value="inactive">Inactive (Hidden)</option>
                        </select>
                    </div>

                    <!-- Discount Type -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Discount Type</label>
                        <select name="discount_type" x-model="form.discount_type" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                            <option value="percentage">Percentage Discount (%)</option>
                            <option value="fixed">Fixed Price Reduction</option>
                        </select>
                    </div>

                    <!-- Discount Value -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                            <span x-text="form.discount_type === 'percentage' ? 'Discount Percentage (%) *' : 'Discount Amount (per 1K) *'"></span>
                        </label>
                        <input type="number" step="0.01" min="0.01" name="discount_value" x-model="form.discount_value" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                    </div>

                    <!-- Scope Type -->
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Discount Scope</label>
                        <select name="scope_type" x-model="form.scope_type" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                            <option value="all">Apply to All Services</option>
                            <option value="category">Specific Category</option>
                            <option value="services">Selected Specific Services</option>
                        </select>
                    </div>

                    <!-- Category Selector (Shown if scope_type === 'category') -->
                    <div class="sm:col-span-2" x-show="form.scope_type === 'category'">
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Target Category</label>
                        <select name="category_id" x-model="form.category_id" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                            <option value="">-- Choose Category --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?> (<?= e(ucfirst($cat['platform'])) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Services Selector (Shown if scope_type === 'services') -->
                    <div class="sm:col-span-2" x-show="form.scope_type === 'services'">
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Target Services (Comma-separated IDs)</label>
                        <input type="text" name="service_ids" x-model="form.service_ids" placeholder="e.g. 101, 102, 201" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                        <p class="text-[10px] text-slate-400 mt-1">Available service IDs: <?= implode(', ', array_slice(array_column($services, 'id'), 0, 10)) ?>...</p>
                    </div>

                    <!-- Starts At -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Start Date & Time *</label>
                        <input type="datetime-local" name="starts_at" x-model="form.starts_at" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                    </div>

                    <!-- Ends At -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">End Date & Time *</label>
                        <input type="datetime-local" name="ends_at" x-model="form.ends_at" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                    </div>

                    <!-- Max Orders Cap -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Max Orders Cap (Optional)</label>
                        <input type="number" min="1" name="max_orders" x-model="form.max_orders" placeholder="e.g. 500 (blank for unlimited)" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                    </div>

                    <!-- Banner Background Styling -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Theme Gradient</label>
                        <select name="banner_bg" x-model="form.banner_bg" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                            <option value="from-blue-600 via-indigo-600 to-purple-600">Blue to Purple (Standard)</option>
                            <option value="from-purple-600 via-pink-600 to-rose-600">Purple to Pink (Instagram)</option>
                            <option value="from-amber-500 via-orange-600 to-rose-600">Sunset Fire (Hot Deal)</option>
                            <option value="from-emerald-600 via-teal-600 to-cyan-600">Emerald Glow</option>
                            <option value="from-slate-900 via-indigo-950 to-blue-900">Midnight Dark</option>
                        </select>
                    </div>
                </div>

                <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-3">
                    <button type="button" @click="modalOpen = false" class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs shadow-md shadow-blue-500/20 transition-colors">
                        <span x-text="isEdit ? 'Save Changes' : 'Create Campaign'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function flashSaleAdmin(sales) {
    return {
        sales: sales,
        modalOpen: false,
        isEdit: false,
        form: {
            id: '',
            title: '',
            subtitle: '',
            badge_text: '⚡ 30% OFF',
            discount_type: 'percentage',
            discount_value: 30,
            scope_type: 'category',
            category_id: '1',
            service_ids: '',
            starts_at: '',
            ends_at: '',
            max_orders: '',
            banner_bg: 'from-blue-600 via-indigo-600 to-purple-600',
            status: 'active'
        },

        init() {
            const now = new Date();
            const future = new Date(now.getTime() + (3 * 24 * 60 * 60 * 1000));
            this.form.starts_at = this.formatDatetime(now);
            this.form.ends_at = this.formatDatetime(future);
        },

        formatDatetime(d) {
            const pad = (n) => String(n).padStart(2, '0');
            return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
        },

        openCreateModal() {
            this.isEdit = false;
            const now = new Date();
            const future = new Date(now.getTime() + (3 * 24 * 60 * 60 * 1000));
            this.form = {
                id: '',
                title: 'Weekend Mega Boost',
                subtitle: 'Grab instant viral boosts on top Instagram & social growth services!',
                badge_text: '⚡ 30% OFF',
                discount_type: 'percentage',
                discount_value: 30,
                scope_type: 'category',
                category_id: '1',
                service_ids: '',
                starts_at: this.formatDatetime(now),
                ends_at: this.formatDatetime(future),
                max_orders: '500',
                banner_bg: 'from-blue-600 via-indigo-600 to-purple-600',
                status: 'active'
            };
            this.modalOpen = true;
        },

        openEditModal(item) {
            this.isEdit = true;
            this.form = {
                id: item.id,
                title: item.title,
                subtitle: item.subtitle || '',
                badge_text: item.badge_text || '⚡ FLASH SALE',
                discount_type: item.discount_type || 'percentage',
                discount_value: parseFloat(item.discount_value) || 20,
                scope_type: item.scope_type || 'all',
                category_id: item.category_id ? String(item.category_id) : '',
                service_ids: item.service_ids || '',
                starts_at: item.starts_at ? item.starts_at.replace(' ', 'T').substring(0, 16) : '',
                ends_at: item.ends_at ? item.ends_at.replace(' ', 'T').substring(0, 16) : '',
                max_orders: item.max_orders ? String(item.max_orders) : '',
                banner_bg: item.banner_bg || 'from-blue-600 via-indigo-600 to-purple-600',
                status: item.status || 'active'
            };
            this.modalOpen = true;
        }
    };
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
