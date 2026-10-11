<?php
/**
 * Admin Support Tickets Management & AI Reply Assistant Architecture
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

// Handle Admin Reply Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    CSRF::verifyOrAbort();

    if ($_POST['action'] === 'reply_ticket') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $reply = trim($_POST['message'] ?? '');
        $newStatus = trim($_POST['status'] ?? 'in_progress');

        if (!in_array($newStatus, ['open', 'in_progress', 'closed'], true)) {
            $newStatus = 'in_progress';
        }

        if (!empty($reply) && $ticketId > 0) {
            $db->beginTransaction();
            $db->prepare("
                INSERT INTO ticket_messages (ticket_id, user_id, is_admin, message, created_at) 
                VALUES (:tid, :uid, 1, :msg, CURRENT_TIMESTAMP)
            ")->execute([
                'tid' => $ticketId,
                'uid' => Auth::id(),
                'msg' => $reply
            ]);

            $db->prepare("
                UPDATE tickets 
                SET status = :st, updated_at = CURRENT_TIMESTAMP 
                WHERE id = :id
            ")->execute([
                'st' => $newStatus,
                'id' => $ticketId
            ]);
            $db->commit();

            set_flash('success', "Reply successfully posted to Ticket #T{$ticketId}.");
            redirect('/admin/tickets.php?view=' . $ticketId);
        } else {
            set_flash('error', "Please enter a message before sending.");
            redirect('/admin/tickets.php' . ($ticketId > 0 ? '?view=' . $ticketId : ''));
        }
    }

    if ($_POST['action'] === 'update_status') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? '');
        if (in_array($newStatus, ['open', 'in_progress', 'closed'], true) && $ticketId > 0) {
            $db->prepare("UPDATE tickets SET status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id")->execute([
                'st' => $newStatus,
                'id' => $ticketId
            ]);
            set_flash('success', "Ticket #T{$ticketId} status updated to " . ucfirst(str_replace('_', ' ', $newStatus)) . ".");
            redirect('/admin/tickets.php?view=' . $ticketId);
        }
    }
}

// Filter parameter
$statusFilter = strtolower(trim($_GET['status'] ?? 'all'));
$activeTicketId = (int)($_GET['view'] ?? 0);

// Query Ticket Counts for Badges
$counts = [
    'all' => (int)($db->query("SELECT COUNT(*) FROM tickets")->fetchColumn() ?: 0),
    'open' => (int)($db->query("SELECT COUNT(*) FROM tickets WHERE status = 'open'")->fetchColumn() ?: 0),
    'in_progress' => (int)($db->query("SELECT COUNT(*) FROM tickets WHERE status = 'in_progress'")->fetchColumn() ?: 0),
    'closed' => (int)($db->query("SELECT COUNT(*) FROM tickets WHERE status = 'closed'")->fetchColumn() ?: 0)
];

// Query Ticket List
$sql = "
    SELECT t.*, u.username, u.email
    FROM tickets t
    JOIN users u ON t.user_id = u.id
";
$params = [];
if (in_array($statusFilter, ['open', 'in_progress', 'closed'], true)) {
    $sql .= " WHERE t.status = :st";
    $params['st'] = $statusFilter;
}
$sql .= " ORDER BY t.updated_at DESC, t.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

// If no active view is selected, default to the first ticket if available
if ($activeTicketId === 0 && !empty($tickets)) {
    $activeTicketId = (int)$tickets[0]['id'];
}

// Fetch Active Ticket Conversation Thread
$selectedTicket = null;
$ticketMessages = [];
$relatedOrder = null;

if ($activeTicketId > 0) {
    $stmtSelected = $db->prepare("
        SELECT t.*, u.username, u.email, u.phone
        FROM tickets t
        JOIN users u ON t.user_id = u.id
        WHERE t.id = :id
        LIMIT 1
    ");
    $stmtSelected->execute(['id' => $activeTicketId]);
    $selectedTicket = $stmtSelected->fetch();

    if ($selectedTicket) {
        $stmtMessages = $db->prepare("
            SELECT tm.*, u.username, u.role
            FROM ticket_messages tm
            JOIN users u ON tm.user_id = u.id
            WHERE tm.ticket_id = :tid
            ORDER BY tm.created_at ASC
        ");
        $stmtMessages->execute(['tid' => $activeTicketId]);
        $ticketMessages = $stmtMessages->fetchAll();

        // Check related order
        if (!empty($selectedTicket['order_id'])) {
            $stmtOrder = $db->prepare("
                SELECT o.*, s.name AS service_name
                FROM orders o
                JOIN services s ON o.service_id = s.id
                WHERE o.id = :oid
                LIMIT 1
            ");
            $stmtOrder->execute(['oid' => (int)$selectedTicket['order_id']]);
            $relatedOrder = $stmtOrder->fetch();
        }
    }
}

$pageTitle = "Support Tickets - Admin Control";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto my-6 space-y-6">
    <!-- Top Summary & Title -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Customer Support Tickets</h1>
            <p class="text-xs text-slate-500 mt-0.5">Manage customer inquiries, view message threads, and compose resolution replies</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="/admin/settings.php" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition-all flex items-center gap-1.5">
                <span>⚙️</span>
                <span>Ticket Settings</span>
            </a>
        </div>
    </div>

    <?= render_flash() ?>

    <!-- Filter Tabs Bar -->
    <div class="flex items-center gap-2 overflow-x-auto pb-1 text-xs">
        <a href="/admin/tickets.php?status=all" class="px-4 py-2 rounded-xl font-bold whitespace-nowrap transition-all <?= $statusFilter === 'all' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-white text-slate-600 hover:bg-slate-50 border border-slate-200/80' ?>">
            All Tickets (<?= $counts['all'] ?>)
        </a>
        <a href="/admin/tickets.php?status=open" class="px-4 py-2 rounded-xl font-bold whitespace-nowrap transition-all <?= $statusFilter === 'open' ? 'bg-amber-600 text-white shadow-md shadow-amber-500/20' : 'bg-white text-slate-600 hover:bg-slate-50 border border-slate-200/80' ?>">
            Open (<?= $counts['open'] ?>)
        </a>
        <a href="/admin/tickets.php?status=in_progress" class="px-4 py-2 rounded-xl font-bold whitespace-nowrap transition-all <?= $statusFilter === 'in_progress' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-white text-slate-600 hover:bg-slate-50 border border-slate-200/80' ?>">
            In Progress (<?= $counts['in_progress'] ?>)
        </a>
        <a href="/admin/tickets.php?status=closed" class="px-4 py-2 rounded-xl font-bold whitespace-nowrap transition-all <?= $statusFilter === 'closed' ? 'bg-slate-800 text-white shadow-md shadow-slate-800/20' : 'bg-white text-slate-600 hover:bg-slate-50 border border-slate-200/80' ?>">
            Closed (<?= $counts['closed'] ?>)
        </a>
    </div>

    <!-- Dual Column Layout: Tickets List (Left) & Conversation Thread (Right) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        <!-- Tickets Sidebar List -->
        <div class="lg:col-span-5 bg-white rounded-3xl p-5 border border-slate-200/80 shadow-sm space-y-3">
            <h2 class="text-xs font-bold text-slate-400 uppercase tracking-wider px-2">Ticket Inquiries</h2>

            <?php if (empty($tickets)): ?>
                <div class="py-12 text-center text-xs text-slate-400">
                    No support tickets found in this status.
                </div>
            <?php else: ?>
                <div class="divide-y divide-slate-100 max-h-[640px] overflow-y-auto space-y-1 pr-1">
                    <?php foreach ($tickets as $t): 
                        $isSelected = ($activeTicketId === (int)$t['id']);
                        $statusBadgeClass = match($t['status']) {
                            'open' => 'bg-amber-50 text-amber-700 border-amber-200',
                            'in_progress' => 'bg-blue-50 text-blue-700 border-blue-200',
                            default => 'bg-slate-100 text-slate-600 border-slate-200'
                        };
                    ?>
                        <a href="/admin/tickets.php?status=<?= urlencode($statusFilter) ?>&view=<?= (int)$t['id'] ?>" 
                           class="block p-3.5 rounded-2xl transition-all <?= $isSelected ? 'bg-blue-50/70 border border-blue-200/80 shadow-xs' : 'hover:bg-slate-50 border border-transparent' ?>">
                            <div class="flex items-start justify-between gap-2 mb-1">
                                <span class="font-mono text-[11px] font-bold text-slate-400">#T<?= (int)$t['id'] ?></span>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase border <?= $statusBadgeClass ?>">
                                    <?= e(str_replace('_', ' ', $t['status'])) ?>
                                </span>
                            </div>
                            <h3 class="text-xs font-bold text-slate-900 truncate <?= $isSelected ? 'text-blue-700' : '' ?>">
                                <?= e($t['subject']) ?>
                            </h3>
                            <div class="flex items-center justify-between mt-2 text-[11px] text-slate-400">
                                <span><?= e($t['username']) ?></span>
                                <span><?= date('M j, g:i a', strtotime($t['updated_at'] ?: $t['created_at'])) ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Conversation Details & Reply Thread -->
        <div class="lg:col-span-7 bg-white rounded-3xl p-6 sm:p-7 border border-slate-200/80 shadow-sm space-y-6">
            <?php if (!$selectedTicket): ?>
                <div class="py-20 text-center space-y-2">
                    <span class="text-3xl">💬</span>
                    <h3 class="text-sm font-bold text-slate-700">Select a ticket from the left</h3>
                    <p class="text-xs text-slate-400">Click any customer ticket to view the conversation and compose a reply.</p>
                </div>
            <?php else: ?>
                <!-- Ticket Thread Header -->
                <div class="border-b border-slate-100 pb-5 space-y-3">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2 mb-1">
                                <span class="font-mono text-xs font-bold text-slate-400 bg-slate-100 px-2.5 py-0.5 rounded-md">
                                    Ticket #T<?= (int)$selectedTicket['id'] ?>
                                </span>
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase border <?= match($selectedTicket['status']) {
                                    'open' => 'bg-amber-50 text-amber-700 border-amber-200',
                                    'in_progress' => 'bg-blue-50 text-blue-700 border-blue-200',
                                    default => 'bg-slate-100 text-slate-600 border-slate-200'
                                } ?>">
                                    <?= e(str_replace('_', ' ', $selectedTicket['status'])) ?>
                                </span>
                                <?php if (!empty($selectedTicket['priority'])): ?>
                                    <span class="text-[10px] font-bold text-slate-500 uppercase bg-slate-50 px-2 py-0.5 rounded-md border border-slate-200/60">
                                        Priority: <?= e(ucfirst($selectedTicket['priority'])) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <h2 class="text-lg font-extrabold text-slate-900 leading-snug">
                                <?= e($selectedTicket['subject']) ?>
                            </h2>
                            <p class="text-xs text-slate-500 mt-0.5">
                                User: <strong class="text-slate-800 font-bold"><?= e($selectedTicket['username']) ?></strong> (<?= e($selectedTicket['email']) ?>)
                            </p>
                        </div>

                        <!-- Status Quick Action Form -->
                        <form action="/admin/tickets.php" method="POST" class="flex items-center gap-2 self-start sm:self-auto">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="ticket_id" value="<?= (int)$selectedTicket['id'] ?>">
                            <select name="status" onchange="this.form.submit()" class="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 cursor-pointer">
                                <option value="open" <?= $selectedTicket['status'] === 'open' ? 'selected' : '' ?>>Open</option>
                                <option value="in_progress" <?= $selectedTicket['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                <option value="closed" <?= $selectedTicket['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                            </select>
                        </form>
                    </div>

                    <!-- Associated Order Info (if any) -->
                    <?php if ($relatedOrder): ?>
                        <div class="bg-blue-50/50 rounded-2xl p-3 border border-blue-100 text-xs flex items-center justify-between">
                            <div>
                                <span class="font-bold text-blue-900">Associated Order #<?= (int)$relatedOrder['id'] ?>:</span>
                                <span class="text-slate-600 ml-1"><?= e($relatedOrder['service_name']) ?></span>
                            </div>
                            <span class="font-bold uppercase text-[10px] px-2 py-0.5 rounded bg-blue-100 text-blue-800">
                                <?= e($relatedOrder['status']) ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Messages Thread -->
                <div class="space-y-4 max-h-[380px] overflow-y-auto pr-1">
                    <?php if (empty($ticketMessages)): ?>
                        <div class="py-8 text-center text-xs text-slate-400">
                            No messages recorded yet in this ticket.
                        </div>
                    <?php else: ?>
                        <?php foreach ($ticketMessages as $msg): 
                            $isAdmin = !empty($msg['is_admin']);
                        ?>
                            <div class="flex flex-col <?= $isAdmin ? 'items-end' : 'items-start' ?>">
                                <div class="flex items-center gap-1.5 text-[10px] text-slate-400 mb-1 px-1">
                                    <span class="font-bold <?= $isAdmin ? 'text-blue-600' : 'text-slate-700' ?>">
                                        <?= $isAdmin ? 'Staff Support' : e($msg['username']) ?>
                                    </span>
                                    <span>•</span>
                                    <span><?= date('M j, Y g:i a', strtotime($msg['created_at'])) ?></span>
                                </div>
                                <div class="max-w-[85%] rounded-2xl p-4 text-xs leading-relaxed <?= $isAdmin ? 'bg-blue-600 text-white rounded-tr-xs shadow-xs' : 'bg-slate-100 text-slate-800 rounded-tl-xs' ?>">
                                    <?= nl2br(e($msg['message'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Admin Reply Box -->
                <div class="pt-4 border-t border-slate-100 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-700">Compose Reply</span>
                    </div>

                    <!-- Reply Form -->
                    <form action="/admin/tickets.php" method="POST" class="space-y-3">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="reply_ticket">
                        <input type="hidden" name="ticket_id" value="<?= (int)$selectedTicket['id'] ?>">

                        <textarea name="message" 
                                  id="ticketReplyTextarea" 
                                  rows="4" 
                                  required 
                                  placeholder="Type your response to customer..." 
                                  class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-medium text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all leading-relaxed"></textarea>

                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-bold text-slate-500">Set Status:</span>
                                <select name="status" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-700">
                                    <option value="in_progress" <?= $selectedTicket['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                    <option value="closed" <?= $selectedTicket['status'] === 'closed' ? 'selected' : '' ?>>Closed / Resolved</option>
                                    <option value="open" <?= $selectedTicket['status'] === 'open' ? 'selected' : '' ?>>Keep Open</option>
                                </select>
                            </div>

                            <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs rounded-xl shadow-md shadow-blue-500/20 transition-all flex items-center justify-center gap-1.5">
                                <span>Send Reply</span>
                                <span>&rarr;</span>
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
