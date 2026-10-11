<?php
/**
 * User Notification Center
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

// Ensure reads tracking table exists
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `user_notification_reads` (
          `user_id` INT UNSIGNED NOT NULL,
          `announcement_id` INT UNSIGNED NOT NULL,
          `read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`user_id`, `announcement_id`),
          INDEX `idx_notif_user` (`user_id`),
          INDEX `idx_notif_announcement` (`announcement_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (\Throwable $e) {
    // Ignore if table exists
}

// Handle Form POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';

    // Mark single notification as read
    if ($action === 'mark_read') {
        $notifId = (int)($_POST['id'] ?? 0);
        if ($notifId > 0) {
            $stmt = $db->prepare("
                INSERT IGNORE INTO user_notification_reads (user_id, announcement_id)
                VALUES (:uid, :aid)
            ");
            $stmt->execute(['uid' => $user['id'], 'aid' => $notifId]);
            set_flash('success', 'Notification marked as read.');
        }
        redirect('/user/notifications.php');
    }

    // Mark all notifications as read
    if ($action === 'mark_all_read') {
        $allNotifsStmt = $db->prepare("
            SELECT a.id FROM announcements a
            LEFT JOIN user_notification_reads r ON a.id = r.announcement_id AND r.user_id = :uid
            WHERE a.status = 'active'
              AND (a.target_audience = 'all' OR a.target_user_id = :uid)
              AND r.read_at IS NULL
        ");
        $allNotifsStmt->execute(['uid' => $user['id']]);
        $unreadIds = $allNotifsStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($unreadIds)) {
            $ins = $db->prepare("INSERT IGNORE INTO user_notification_reads (user_id, announcement_id) VALUES (:uid, :aid)");
            foreach ($unreadIds as $aid) {
                $ins->execute(['uid' => $user['id'], 'aid' => (int)$aid]);
            }
            set_flash('success', 'All notifications marked as read.');
        } else {
            set_flash('info', 'No unread notifications to mark.');
        }
        redirect('/user/notifications.php');
    }
}

// Filter query parameter
$filter = trim($_GET['filter'] ?? 'all');
$validFilters = ['all', 'unread', 'announcement', 'service', 'update', 'offer'];
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}

// Fetch user notifications safely
$notifications = [];
try {
    $sql = "
        SELECT a.*, r.read_at,
               CASE WHEN r.read_at IS NULL THEN 1 ELSE 0 END AS is_unread
        FROM announcements a
        LEFT JOIN user_notification_reads r ON a.id = r.announcement_id AND r.user_id = :uid
        WHERE a.status = 'active'
          AND (a.target_audience = 'all' OR a.target_user_id = :uid)
          AND (a.starts_at IS NULL OR a.starts_at <= NOW())
          AND (a.expires_at IS NULL OR a.expires_at >= NOW())
    ";

    if ($filter === 'unread') {
        $sql .= " AND r.read_at IS NULL";
    } elseif ($filter !== 'all') {
        $sql .= " AND a.type = :type";
    }

    $sql .= " ORDER BY is_unread DESC, a.created_at DESC LIMIT 50";

    $stmt = $db->prepare($sql);
    $params = ['uid' => $user['id']];
    if ($filter !== 'all' && $filter !== 'unread') {
        $params['type'] = $filter;
    }
    $stmt->execute($params);
    $notifications = $stmt->fetchAll() ?: [];
} catch (\Throwable $e) {
    error_log("Notification fetch error: " . $e->getMessage());
    $notifications = [];
}

// Unread count
$unreadCount = get_unread_notifications_count((int)$user['id']);

$pageTitle = "Notification Center - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="text-2xl">🔔</span>
                <h1 class="text-2xl font-black text-slate-900 tracking-tight">Notification Center</h1>
                <?php if ($unreadCount > 0): ?>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-black bg-blue-600 text-white shadow-xs">
                        <?= $unreadCount ?> new
                    </span>
                <?php endif; ?>
            </div>
            <p class="text-xs text-slate-400 mt-1">Platform service updates, balance alerts, flash offers, and announcements</p>
        </div>

        <?php if ($unreadCount > 0): ?>
            <form action="/user/notifications.php" method="POST" class="shrink-0">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="px-4 py-2 bg-white hover:bg-slate-50 text-slate-700 hover:text-blue-600 border border-slate-200 rounded-xl text-xs font-extrabold shadow-xs transition-all flex items-center gap-1.5 cursor-pointer">
                    <svg class="w-3.5 h-3.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    <span>Mark All as Read</span>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Filter Pills Bar -->
    <div class="flex items-center gap-2 overflow-x-auto pb-1 text-xs font-bold no-scrollbar">
        <a href="/user/notifications.php?filter=all" 
           class="px-3.5 py-1.5 rounded-xl transition-all whitespace-nowrap <?= $filter === 'all' ? 'bg-blue-600 text-white shadow-sm shadow-blue-500/25' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200/80' ?>">
            All
        </a>
        <a href="/user/notifications.php?filter=unread" 
           class="px-3.5 py-1.5 rounded-xl transition-all whitespace-nowrap flex items-center gap-1.5 <?= $filter === 'unread' ? 'bg-blue-600 text-white shadow-sm shadow-blue-500/25' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200/80' ?>">
            <span>Unread</span>
            <?php if ($unreadCount > 0): ?>
                <span class="w-2 h-2 rounded-full <?= $filter === 'unread' ? 'bg-white' : 'bg-blue-600' ?>"></span>
            <?php endif; ?>
        </a>
        <a href="/user/notifications.php?filter=announcement" 
           class="px-3.5 py-1.5 rounded-xl transition-all whitespace-nowrap <?= $filter === 'announcement' ? 'bg-blue-600 text-white shadow-sm shadow-blue-500/25' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200/80' ?>">
            📢 Announcements
        </a>
        <a href="/user/notifications.php?filter=service" 
           class="px-3.5 py-1.5 rounded-xl transition-all whitespace-nowrap <?= $filter === 'service' ? 'bg-blue-600 text-white shadow-sm shadow-blue-500/25' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200/80' ?>">
            ⚡ Services
        </a>
        <a href="/user/notifications.php?filter=update" 
           class="px-3.5 py-1.5 rounded-xl transition-all whitespace-nowrap <?= $filter === 'update' ? 'bg-blue-600 text-white shadow-sm shadow-blue-500/25' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200/80' ?>">
            🚀 System Updates
        </a>
        <a href="/user/notifications.php?filter=offer" 
           class="px-3.5 py-1.5 rounded-xl transition-all whitespace-nowrap <?= $filter === 'offer' ? 'bg-blue-600 text-white shadow-sm shadow-blue-500/25' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200/80' ?>">
            🎁 Offers
        </a>
    </div>

    <!-- Notifications List -->
    <?php if (empty($notifications)): ?>
        <div class="bg-white rounded-3xl p-12 text-center border border-slate-200/80 shadow-xs space-y-3">
            <div class="w-14 h-14 mx-auto rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center text-2xl">
                ✨
            </div>
            <h3 class="text-base font-extrabold text-slate-900">You're all caught up!</h3>
            <p class="text-xs text-slate-400 max-w-sm mx-auto">There are no notifications matching your selected filter. Important system updates and promotions will appear here.</p>
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($notifications as $n): 
                $isUnread = (int)$n['is_unread'] === 1;
                $type = $n['type'] ?? 'announcement';

                $icon = match($type) {
                    'service' => '⚡',
                    'maintenance' => '🛠️',
                    'offer' => '🎁',
                    'telegram' => '✈️',
                    'update' => '🚀',
                    'system' => '⚙️',
                    default => '📢'
                };

                $badgeColor = match($type) {
                    'service' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                    'maintenance' => 'bg-amber-50 text-amber-700 border-amber-200',
                    'offer' => 'bg-rose-50 text-rose-700 border-rose-200',
                    'telegram' => 'bg-sky-50 text-sky-700 border-sky-200',
                    'update' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                    default => 'bg-blue-50 text-blue-700 border-blue-200'
                };
            ?>
                <div class="bg-white rounded-2xl p-5 border transition-all <?= $isUnread ? 'border-blue-300/80 shadow-xs ring-1 ring-blue-500/10' : 'border-slate-200/70 opacity-90' ?>">
                    <div class="flex items-start gap-4">
                        <!-- Icon Bubble -->
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center text-lg shrink-0 <?= $isUnread ? 'bg-blue-50 text-blue-600 border border-blue-100 shadow-xs' : 'bg-slate-100 text-slate-500' ?>">
                            <?= e($n['icon'] ?: $icon) ?>
                        </div>

                        <!-- Content Area -->
                        <div class="flex-1 min-w-0 space-y-1.5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-extrabold uppercase border <?= $badgeColor ?>">
                                        <?= e($n['badge_text'] ?: ucfirst($type)) ?>
                                    </span>
                                    <h3 class="text-sm font-extrabold text-slate-900 <?= $isUnread ? 'font-black' : '' ?>">
                                        <?= e($n['title']) ?>
                                    </h3>
                                    <?php if ($isUnread): ?>
                                        <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                                    <?php endif; ?>
                                </div>
                                <span class="text-[11px] font-mono text-slate-400 whitespace-nowrap">
                                    <?= date('d M Y, H:i', strtotime($n['created_at'])) ?>
                                </span>
                            </div>

                            <p class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">
                                <?= nl2br(e($n['message'])) ?>
                            </p>

                            <!-- Actions Row -->
                            <div class="pt-2 flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <?php if (!empty($n['btn_text']) && !empty($n['btn_link'])): ?>
                                        <a href="<?= e($n['btn_link']) ?>" class="inline-flex items-center gap-1 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-bold shadow-xs transition-all">
                                            <span><?= e($n['btn_text']) ?></span>
                                            <span>&rarr;</span>
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <?php if ($isUnread): ?>
                                    <form action="/user/notifications.php" method="POST" class="inline">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="mark_read">
                                        <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                        <button type="submit" class="text-[11px] font-bold text-slate-400 hover:text-blue-600 transition-colors cursor-pointer">
                                            Mark as read
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-[11px] text-slate-400 flex items-center gap-1">
                                        <span>✓</span> Read
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
