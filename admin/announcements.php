<?php
/**
 * Admin User Popup Notification / Announcement System
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

// Ensure announcements tables exist if not already migrated
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `announcements` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `title` VARCHAR(255) NOT NULL,
          `type` ENUM('announcement', 'service', 'maintenance', 'offer', 'telegram', 'update', 'system') NOT NULL DEFAULT 'announcement',
          `badge_text` VARCHAR(64) DEFAULT 'Important Notice',
          `icon` VARCHAR(50) NULL,
          `message` TEXT NOT NULL,
          `btn_text` VARCHAR(100) NULL,
          `btn_link` VARCHAR(255) NULL,
          `target_audience` ENUM('all', 'active_users') NOT NULL DEFAULT 'all',
          `target_user_id` INT UNSIGNED NULL,
          `show_once` TINYINT(1) NOT NULL DEFAULT 1,
          `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
          `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
          `starts_at` DATETIME NULL,
          `expires_at` DATETIME NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX `idx_announcements_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- Add columns if table already exists without them
        ALTER TABLE `announcements`
          ADD COLUMN IF NOT EXISTS `target_user_id` INT UNSIGNED NULL AFTER `target_audience`,
          ADD COLUMN IF NOT EXISTS `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal' AFTER `status`,
          ADD COLUMN IF NOT EXISTS `icon` VARCHAR(50) NULL AFTER `badge_text`;

        CREATE TABLE IF NOT EXISTS `user_announcement_dismissals` (
          `user_id` INT UNSIGNED NOT NULL,
          `announcement_id` INT UNSIGNED NOT NULL,
          `dismissed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`user_id`, `announcement_id`),
          INDEX `idx_dismissal_announcement` (`announcement_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (PDOException $e) {
    error_log("Announcement table check: " . $e->getMessage());
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';

    // 1. Create New Announcement
    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $type = trim($_POST['type'] ?? 'announcement');
        $badgeText = trim($_POST['badge_text'] ?? 'Notice');
        $message = trim($_POST['message'] ?? '');
        $btnText = trim($_POST['btn_text'] ?? '');
        $btnLink = trim($_POST['btn_link'] ?? '');
        $targetAudience = trim($_POST['target_audience'] ?? 'all');
        $showOnce = isset($_POST['show_once']) ? 1 : 0;
        $status = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';

        $validTypes = ['announcement', 'service', 'maintenance', 'offer', 'telegram', 'update', 'system'];
        if (!in_array($type, $validTypes, true)) {
            $type = 'announcement';
        }

        if (empty($title) || empty($message)) {
            set_flash('error', 'Please provide both an announcement title and message content.');
            redirect('/admin/announcements.php');
        }

        $stmt = $db->prepare("
            INSERT INTO announcements (title, type, badge_text, message, btn_text, btn_link, target_audience, show_once, status)
            VALUES (:title, :type, :badge, :message, :btn_text, :btn_link, :target_audience, :show_once, :status)
        ");
        $stmt->execute([
            'title' => $title,
            'type' => $type,
            'badge' => $badgeText ?: 'Notice',
            'message' => $message,
            'btn_text' => $btnText ?: null,
            'btn_link' => $btnLink ?: null,
            'target_audience' => $targetAudience,
            'show_once' => $showOnce,
            'status' => $status
        ]);

        set_flash('success', 'User popup announcement created successfully.');
        redirect('/admin/announcements.php');
    }

    // 2. Update Existing Announcement
    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $type = trim($_POST['type'] ?? 'announcement');
        $badgeText = trim($_POST['badge_text'] ?? 'Notice');
        $message = trim($_POST['message'] ?? '');
        $btnText = trim($_POST['btn_text'] ?? '');
        $btnLink = trim($_POST['btn_link'] ?? '');
        $targetAudience = trim($_POST['target_audience'] ?? 'all');
        $showOnce = isset($_POST['show_once']) ? 1 : 0;
        $status = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';

        $validTypes = ['announcement', 'service', 'maintenance', 'offer', 'telegram', 'update', 'system'];
        if (!in_array($type, $validTypes, true)) {
            $type = 'announcement';
        }

        if ($id <= 0 || empty($title) || empty($message)) {
            set_flash('error', 'Invalid announcement details or missing required fields.');
            redirect('/admin/announcements.php');
        }

        $stmt = $db->prepare("
            UPDATE announcements
            SET title = :title,
                type = :type,
                badge_text = :badge,
                message = :message,
                btn_text = :btn_text,
                btn_link = :btn_link,
                target_audience = :target_audience,
                show_once = :show_once,
                status = :status,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            'title' => $title,
            'type' => $type,
            'badge' => $badgeText ?: 'Notice',
            'message' => $message,
            'btn_text' => $btnText ?: null,
            'btn_link' => $btnLink ?: null,
            'target_audience' => $targetAudience,
            'show_once' => $showOnce,
            'status' => $status,
            'id' => $id
        ]);

        set_flash('success', "Announcement #{$id} updated successfully.");
        redirect('/admin/announcements.php');
    }

    // 3. Toggle Status (Active / Inactive)
    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT status FROM announcements WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row) {
            $newStatus = ($row['status'] === 'active') ? 'inactive' : 'active';
            $db->prepare("UPDATE announcements SET status = :status WHERE id = :id")->execute([
                'status' => $newStatus,
                'id' => $id
            ]);
            set_flash('success', "Announcement status updated to " . strtoupper($newStatus) . ".");
        }
        redirect('/admin/announcements.php');
    }

    // 4. Delete Announcement
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM announcements WHERE id = :id");
        $stmt->execute(['id' => $id]);

        set_flash('success', "Announcement #{$id} has been permanently deleted.");
        redirect('/admin/announcements.php');
    }
}

// Fetch All Announcements with Dismissal / View counts
$stmt = $db->query("
    SELECT a.*, 
           COUNT(d.user_id) AS total_dismissals
    FROM announcements a
    LEFT JOIN user_announcement_dismissals d ON a.id = d.announcement_id
    GROUP BY a.id
    ORDER BY a.id DESC
");
$announcements = $stmt->fetchAll();

// Metrics
$totalAnnouncements = count($announcements);
$activeCount = count(array_filter($announcements, fn($a) => $a['status'] === 'active'));
$totalDismissals = array_sum(array_column($announcements, 'total_dismissals'));

$pageTitle = "User Popup Announcements - " . app_name() . " Admin";
require_once __DIR__ . '/../includes/header.php';
?>

<div x-data="announcementManager()" class="max-w-7xl mx-auto space-y-8 my-4">
    <!-- Header Title Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white p-6 md:p-8 rounded-3xl border border-slate-200/80 shadow-sm">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wider bg-blue-50 text-blue-700 border border-blue-200/70">
                    Dashboard Experience
                </span>
                <span class="text-xs font-semibold text-slate-400">Admin Managed</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight flex items-center gap-2.5">
                <span>📢</span>
                User Popup Notifications &amp; Announcements
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 mt-1 max-w-2xl leading-relaxed">
                Control announcements, maintenance notices, Telegram invitations, and special offers that appear as a modern popup when users log in and access their dashboard.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <button type="button" 
                    @click="openCreateModal()" 
                    class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs rounded-xl shadow-md shadow-blue-500/25 transition-all flex items-center gap-2 whitespace-nowrap cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                <span>Create Announcement</span>
            </button>
        </div>
    </div>

    <!-- Quick Stats Metric Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        <div class="bg-white p-6 rounded-3xl border border-slate-200/80 shadow-sm flex items-center justify-between">
            <div>
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Total Announcements</span>
                <span class="text-3xl font-black text-slate-900 font-mono-nums block mt-1"><?= $totalAnnouncements ?></span>
                <span class="text-[11px] text-slate-400 block mt-0.5">Configured popup notices</span>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center text-xl font-bold shadow-xs">
                📢
            </div>
        </div>

        <div class="bg-white p-6 rounded-3xl border border-slate-200/80 shadow-sm flex items-center justify-between">
            <div>
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Active On Dashboard</span>
                <span class="text-3xl font-black text-emerald-600 font-mono-nums block mt-1"><?= $activeCount ?></span>
                <span class="text-[11px] text-slate-400 block mt-0.5">Currently live for users</span>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl font-bold shadow-xs">
                ⚡
            </div>
        </div>

        <div class="bg-white p-6 rounded-3xl border border-slate-200/80 shadow-sm flex items-center justify-between">
            <div>
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Total User Views / Dismissals</span>
                <span class="text-3xl font-black text-indigo-600 font-mono-nums block mt-1"><?= (int)$totalDismissals ?></span>
                <span class="text-[11px] text-slate-400 block mt-0.5">Recorded user interactions</span>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl font-bold shadow-xs">
                👁️
            </div>
        </div>
    </div>

    <!-- Announcements Table & Management Hub -->
    <div class="bg-white rounded-3xl border border-slate-200/80 shadow-sm overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h3 class="text-base font-extrabold text-slate-900">Configured Popup Announcements</h3>
                <p class="text-xs text-slate-500 mt-0.5">Active announcements will automatically prompt logged-in users on their User Dashboard.</p>
            </div>
            <span class="text-xs font-bold text-slate-400 bg-slate-50 px-3 py-1 rounded-xl border border-slate-100">
                <?= count($announcements) ?> Total Records
            </span>
        </div>

        <?php if (empty($announcements)): ?>
            <div class="p-12 text-center">
                <div class="w-16 h-16 rounded-3xl bg-blue-50 text-blue-600 mx-auto flex items-center justify-center text-2xl font-bold mb-3 shadow-inner">
                    📢
                </div>
                <h4 class="text-base font-bold text-slate-800">No Announcements Created Yet</h4>
                <p class="text-xs text-slate-500 mt-1 max-w-sm mx-auto">Create your first popup announcement to engage users with news, service updates, or Telegram community invitations.</p>
                <button type="button" @click="openCreateModal()" class="mt-4 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-md shadow-blue-500/20">
                    + Create First Announcement
                </button>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="bg-slate-50/75 border-b border-slate-100 text-slate-400 uppercase text-[10px] font-extrabold tracking-wider">
                            <th class="py-3.5 px-6">ID &amp; Type</th>
                            <th class="py-3.5 px-6">Title &amp; Message</th>
                            <th class="py-3.5 px-6">Call-To-Action</th>
                            <th class="py-3.5 px-6">Delivery Policy</th>
                            <th class="py-3.5 px-6 text-center">Status</th>
                            <th class="py-3.5 px-6 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($announcements as $item): 
                            $typeStyles = [
                                'telegram' => ['bg' => 'bg-sky-50', 'text' => 'text-sky-700', 'border' => 'border-sky-200', 'icon' => '✈️'],
                                'offer' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-700', 'border' => 'border-emerald-200', 'icon' => '🎁'],
                                'service' => ['bg' => 'bg-purple-50', 'text' => 'text-purple-700', 'border' => 'border-purple-200', 'icon' => '⚡'],
                                'maintenance' => ['bg' => 'bg-amber-50', 'text' => 'text-amber-700', 'border' => 'border-amber-200', 'icon' => '⚠️'],
                                'update' => ['bg' => 'bg-blue-50', 'text' => 'text-blue-700', 'border' => 'border-blue-200', 'icon' => '🚀'],
                                'system' => ['bg' => 'bg-slate-100', 'text' => 'text-slate-700', 'border' => 'border-slate-300', 'icon' => '🛡️'],
                                'announcement' => ['bg' => 'bg-indigo-50', 'text' => 'text-indigo-700', 'border' => 'border-indigo-200', 'icon' => '📢']
                            ];
                            $style = $typeStyles[$item['type']] ?? $typeStyles['announcement'];
                        ?>
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="py-4 px-6 align-top whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono text-slate-400 font-bold">#<?= (int)$item['id'] ?></span>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wider <?= $style['bg'] ?> <?= $style['text'] ?> border <?= $style['border'] ?>">
                                            <span><?= $style['icon'] ?></span>
                                            <span><?= e($item['badge_text'] ?: ucfirst($item['type'])) ?></span>
                                        </span>
                                    </div>
                                    <span class="text-[10px] text-slate-400 block mt-1">
                                        <?= date('M d, Y', strtotime($item['created_at'])) ?>
                                    </span>
                                </td>

                                <td class="py-4 px-6 align-top max-w-sm">
                                    <div class="font-extrabold text-slate-900 text-sm leading-snug">
                                        <?= e($item['title']) ?>
                                    </div>
                                    <div class="text-slate-500 text-xs mt-1 line-clamp-2 leading-relaxed">
                                        <?= e($item['message']) ?>
                                    </div>
                                </td>

                                <td class="py-4 px-6 align-top whitespace-nowrap">
                                    <?php if (!empty($item['btn_text'])): ?>
                                        <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-blue-50 text-blue-700 border border-blue-200/60 font-bold text-[11px]">
                                            <span><?= e($item['btn_text']) ?></span>
                                            <span class="text-[10px] opacity-70">&rarr;</span>
                                        </div>
                                        <?php if (!empty($item['btn_link'])): ?>
                                            <span class="text-[10px] text-slate-400 block mt-1 truncate max-w-[180px]" title="<?= e($item['btn_link']) ?>">
                                                <?= e($item['btn_link']) ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-slate-400 text-xs italic">No CTA Button</span>
                                    <?php endif; ?>
                                </td>

                                <td class="py-4 px-6 align-top whitespace-nowrap">
                                    <?php if ($item['show_once']): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[10px] font-bold bg-slate-100 text-slate-700">
                                            <span>✓</span> Show Once (Dismissible)
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200/60">
                                            <span>🔄</span> Repeat Every Login
                                        </span>
                                    <?php endif; ?>
                                    <span class="text-[10px] text-slate-400 block mt-1">
                                        Views: <strong class="text-slate-600 font-mono-nums"><?= (int)$item['total_dismissals'] ?></strong>
                                    </span>
                                </td>

                                <td class="py-4 px-6 align-top text-center whitespace-nowrap">
                                    <form action="/admin/announcements.php" method="POST" class="inline">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                        <button type="submit" 
                                                title="Click to toggle status"
                                                class="cursor-pointer inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-wider transition-all <?= $item['status'] === 'active' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100' : 'bg-slate-100 text-slate-600 border border-slate-200 hover:bg-slate-200' ?>">
                                            <span class="w-1.5 h-1.5 rounded-full <?= $item['status'] === 'active' ? 'bg-emerald-500 animate-pulse' : 'bg-slate-400' ?>"></span>
                                            <span><?= ucfirst($item['status']) ?></span>
                                        </button>
                                    </form>
                                </td>

                                <td class="py-4 px-6 align-top text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-2">
                                        <!-- Live Preview Button -->
                                        <button type="button" 
                                                @click="previewAnnouncement(<?= htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8') ?>)"
                                                class="px-2.5 py-1.5 rounded-lg bg-slate-50 hover:bg-slate-100 text-slate-600 font-bold text-xs border border-slate-200/80 transition-colors"
                                                title="Live Modal Preview">
                                            👁️ Preview
                                        </button>

                                        <!-- Edit Button -->
                                        <button type="button" 
                                                @click="editAnnouncement(<?= htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8') ?>)"
                                                class="px-2.5 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold text-xs border border-blue-200/80 transition-colors"
                                                title="Edit Announcement">
                                            ✏️ Edit
                                        </button>

                                        <!-- Delete Button with SweetAlert2 confirmation -->
                                        <button type="button" 
                                                @click="confirmDelete(<?= (int)$item['id'] ?>, '<?= addslashes(e($item['title'])) ?>')"
                                                class="px-2.5 py-1.5 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 font-bold text-xs border border-red-200/80 transition-colors"
                                                title="Delete Announcement">
                                            🗑️
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Hidden Form for Deletion -->
    <form id="deleteForm" action="/admin/announcements.php" method="POST" style="display: none;">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="deleteFormId" value="0">
    </form>

    <!-- Modal: Create / Edit Announcement -->
    <div x-show="modalOpen" 
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-xs overflow-y-auto"
         style="display: none;"
         @keydown.escape.window="modalOpen = false">
        
        <div class="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200/80 my-8 space-y-6"
             @click.outside="modalOpen = false">
            
            <div class="flex items-start justify-between border-b border-slate-100 pb-4">
                <div>
                    <h3 class="text-lg font-black text-slate-900" x-text="isEdit ? 'Edit Announcement #' + form.id : 'Create New User Popup Announcement'"></h3>
                    <p class="text-xs text-slate-500 mt-0.5">Controls what appears on the User Dashboard when logged in.</p>
                </div>
                <button type="button" @click="modalOpen = false" class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200 flex items-center justify-center text-sm font-bold">
                    &times;
                </button>
            </div>

            <form action="/admin/announcements.php" method="POST" class="space-y-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" :value="isEdit ? 'update' : 'create'">
                <input type="hidden" name="id" :value="form.id">

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Announcement Title <span class="text-red-500">*</span></label>
                    <input type="text" 
                           name="title" 
                           x-model="form.title" 
                           required 
                           placeholder="e.g., Welcome to Our SMM Panel! Join Our Community" 
                           class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Announcement Type</label>
                        <select name="type" 
                                x-model="form.type" 
                                class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                            <option value="announcement">📢 General Announcement</option>
                            <option value="telegram">✈️ Telegram Community</option>
                            <option value="offer">🎁 Special Offer / Bonus</option>
                            <option value="service">⚡ New Service Alert</option>
                            <option value="maintenance">⚠️ Scheduled Maintenance</option>
                            <option value="update">🚀 Platform Update</option>
                            <option value="system">🛡️ System Information</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Badge Text</label>
                        <input type="text" 
                               name="badge_text" 
                               x-model="form.badge_text" 
                               placeholder="e.g. Official Telegram, Flash Sale" 
                               class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Message Content <span class="text-red-500">*</span></label>
                    <textarea name="message" 
                              x-model="form.message" 
                              rows="4" 
                              required 
                              placeholder="Write announcement text here..." 
                              class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 leading-relaxed"></textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Action Button Text (Optional)</label>
                        <input type="text" 
                               name="btn_text" 
                               x-model="form.btn_text" 
                               placeholder="e.g. Join Telegram Channel" 
                               class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Action Button Link (Optional)</label>
                        <input type="text" 
                               name="btn_link" 
                               x-model="form.btn_link" 
                               placeholder="https://t.me/... or /user/services.php" 
                               class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Target Audience</label>
                        <select name="target_audience" 
                                x-model="form.target_audience" 
                                class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                            <option value="all">All Registered Users</option>
                            <option value="active_users">Active Users Only</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Status</label>
                        <select name="status" 
                                x-model="form.status" 
                                class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                            <option value="active">Active (Show on Dashboard)</option>
                            <option value="inactive">Inactive (Draft / Hidden)</option>
                        </select>
                    </div>
                </div>

                <div class="pt-2">
                    <label class="flex items-start gap-3 p-3.5 rounded-2xl bg-slate-50 border border-slate-200/70 cursor-pointer hover:bg-slate-100/60 transition-colors">
                        <input type="checkbox" 
                               name="show_once" 
                               value="1" 
                               x-model="form.show_once" 
                               class="mt-0.5 w-4 h-4 text-blue-600 rounded border-slate-300 focus:ring-blue-500">
                        <div>
                            <span class="text-xs font-bold text-slate-900 block">Show Once (Dismissible by user)</span>
                            <span class="text-[11px] text-slate-500 block mt-0.5">When checked, once a user clicks "Dismiss" or "Don't show again", this popup will never show to that user again. If unchecked, it will reappear each time the user logs in.</span>
                        </div>
                    </label>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                    <button type="button" @click="modalOpen = false" class="px-5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs rounded-xl shadow-md shadow-blue-500/25">
                        <span x-text="isEdit ? 'Save Changes' : 'Create Announcement'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Live Preview Modal (Simulates the exact User Dashboard Popup) -->
    <div x-show="previewOpen" 
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-sm"
         style="display: none;"
         @keydown.escape.window="previewOpen = false">
        
        <div class="relative bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200/80 overflow-hidden transform transition-all"
             @click.outside="previewOpen = false">
            
            <!-- Live Preview Tag -->
            <div class="absolute top-4 right-14">
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-blue-50 text-blue-700 border border-blue-200">
                    User Preview
                </span>
            </div>

            <!-- Close 'X' Button -->
            <button type="button" 
                    @click="previewOpen = false" 
                    class="absolute top-4 right-4 w-8 h-8 rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200 hover:text-slate-800 flex items-center justify-center transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>

            <!-- Type Icon & Badge Header -->
            <div class="flex items-center gap-3 mb-4">
                <div class="w-12 h-12 rounded-2xl flex items-center justify-center text-2xl font-bold shadow-md"
                     :class="{
                        'bg-sky-50 text-sky-600 border border-sky-200': previewData.type === 'telegram',
                        'bg-emerald-50 text-emerald-600 border border-emerald-200': previewData.type === 'offer',
                        'bg-purple-50 text-purple-600 border border-purple-200': previewData.type === 'service',
                        'bg-amber-50 text-amber-600 border border-amber-200': previewData.type === 'maintenance',
                        'bg-blue-50 text-blue-600 border border-blue-200': previewData.type === 'update',
                        'bg-indigo-50 text-indigo-600 border border-indigo-200': previewData.type === 'announcement' || previewData.type === 'system'
                     }">
                    <span x-text="{
                        'telegram': '✈️',
                        'offer': '🎁',
                        'service': '⚡',
                        'maintenance': '⚠️',
                        'update': '🚀',
                        'system': '🛡️',
                        'announcement': '📢'
                    }[previewData.type] || '📢'"></span>
                </div>

                <div>
                    <span class="inline-block px-3 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wider"
                          :class="{
                            'bg-sky-50 text-sky-700 border border-sky-200': previewData.type === 'telegram',
                            'bg-emerald-50 text-emerald-700 border border-emerald-200': previewData.type === 'offer',
                            'bg-purple-50 text-purple-700 border border-purple-200': previewData.type === 'service',
                            'bg-amber-50 text-amber-700 border border-amber-200': previewData.type === 'maintenance',
                            'bg-blue-50 text-blue-700 border border-blue-200': previewData.type === 'update',
                            'bg-indigo-50 text-indigo-700 border border-indigo-200': previewData.type === 'announcement' || previewData.type === 'system'
                          }"
                          x-text="previewData.badge_text || 'Announcement'">
                    </span>
                    <span class="text-[11px] text-slate-400 block font-medium mt-0.5"><?= e(app_name()) ?> Community Update</span>
                </div>
            </div>

            <!-- Title & Message -->
            <div class="space-y-3">
                <h3 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight leading-snug" x-text="previewData.title"></h3>
                <div class="text-xs sm:text-sm text-slate-600 leading-relaxed font-normal whitespace-pre-line" x-text="previewData.message"></div>
            </div>

            <!-- Action & Dismiss Buttons -->
            <div class="pt-6 mt-6 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-3">
                <button type="button" 
                        @click="previewOpen = false" 
                        class="w-full sm:w-auto px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition-colors text-center">
                    Don't show again
                </button>

                <template x-if="previewData.btn_text && previewData.btn_link">
                    <a :href="previewData.btn_link" 
                       target="_blank" 
                       class="w-full sm:w-auto px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs rounded-xl shadow-lg shadow-blue-500/25 transition-all flex items-center justify-center gap-2 text-center">
                        <span x-text="previewData.btn_text"></span>
                        <span>&rarr;</span>
                    </a>
                </template>
            </div>
        </div>
    </div>
</div>

<script>
function announcementManager() {
    return {
        modalOpen: false,
        previewOpen: false,
        isEdit: false,
        form: {
            id: 0,
            title: '',
            type: 'announcement',
            badge_text: 'Notice',
            message: '',
            btn_text: '',
            btn_link: '',
            target_audience: 'all',
            show_once: true,
            status: 'active'
        },
        previewData: {},

        openCreateModal() {
            this.isEdit = false;
            this.form = {
                id: 0,
                title: '',
                type: 'announcement',
                badge_text: 'Notice',
                message: '',
                btn_text: '',
                btn_link: '',
                target_audience: 'all',
                show_once: true,
                status: 'active'
            };
            this.modalOpen = true;
        },

        editAnnouncement(item) {
            this.isEdit = true;
            this.form = {
                id: item.id,
                title: item.title,
                type: item.type,
                badge_text: item.badge_text || '',
                message: item.message,
                btn_text: item.btn_text || '',
                btn_link: item.btn_link || '',
                target_audience: item.target_audience || 'all',
                show_once: item.show_once == 1,
                status: item.status || 'active'
            };
            this.modalOpen = true;
        },

        previewAnnouncement(item) {
            this.previewData = item;
            this.previewOpen = true;
        },

        confirmDelete(id, title) {
            Swal.fire({
                title: 'Delete Announcement?',
                text: 'Are you sure you want to permanently delete "' + title + '"?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, Delete'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('deleteFormId').value = id;
                    document.getElementById('deleteForm').submit();
                }
            });
        }
    };
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
