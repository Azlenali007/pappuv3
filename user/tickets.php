<?php
/**
 * Support Tickets
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
$showNewModal = isset($_GET['new']);

// Handle ticket creation or user reply
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_ticket') {
        $subject = trim($_POST['subject'] ?? '');
        $orderId = !empty($_POST['order_id']) ? (int)$_POST['order_id'] : null;
        $priority = in_array($_POST['priority'] ?? '', ['low', 'medium', 'high']) ? $_POST['priority'] : 'medium';
        $message = trim($_POST['message'] ?? '');

        if (empty($subject) || empty($message)) {
            $error = "Please provide both subject and details for your ticket.";
        } else {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("
                    INSERT INTO tickets (user_id, order_id, subject, status, priority)
                    VALUES (:uid, :oid, :subj, 'open', :priority)
                ");
                $stmt->execute([
                    'uid' => $user['id'],
                    'oid' => $orderId,
                    'subj' => $subject,
                    'priority' => $priority
                ]);
                $ticketId = (int)$db->lastInsertId();

                $msgStmt = $db->prepare("
                    INSERT INTO ticket_messages (ticket_id, user_id, is_admin, message)
                    VALUES (:tid, :uid, 0, :msg)
                ");
                $msgStmt->execute([
                    'tid' => $ticketId,
                    'uid' => $user['id'],
                    'msg' => $message
                ]);

                $db->commit();
                set_flash('success', "Ticket #T{$ticketId} created successfully. Our team will review it shortly.");
                redirect('/user/tickets.php');
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to create ticket: " . $e->getMessage();
            }
        }
    } elseif ($action === 'reply_ticket') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');

        // Verify ticket belongs to user
        $check = $db->prepare("SELECT id FROM tickets WHERE id = :tid AND user_id = :uid LIMIT 1");
        $check->execute(['tid' => $ticketId, 'uid' => $user['id']]);

        if ($check->fetch() && !empty($message)) {
            $db->beginTransaction();
            try {
                $db->prepare("INSERT INTO ticket_messages (ticket_id, user_id, is_admin, message) VALUES (:tid, :uid, 0, :msg)")->execute([
                    'tid' => $ticketId,
                    'uid' => $user['id'],
                    'msg' => $message
                ]);
                $db->prepare("UPDATE tickets SET status = 'open', updated_at = CURRENT_TIMESTAMP WHERE id = :id")->execute(['id' => $ticketId]);
                $db->commit();
                set_flash('success', "Message sent on ticket #T{$ticketId}.");
                redirect('/user/tickets.php');
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to post reply: " . $e->getMessage();
            }
        }
    }
}

$statusFilter = strtolower(trim($_GET['status'] ?? 'all'));
$sql = "SELECT * FROM tickets WHERE user_id = :uid";
$params = ['uid' => $user['id']];

if (in_array($statusFilter, ['open', 'in_progress', 'closed'])) {
    $sql .= " AND status = :status";
    $params['status'] = $statusFilter;
}
$sql .= " ORDER BY updated_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

// Fetch messages for each ticket
$ticketMessages = [];
if (!empty($tickets)) {
    $tIds = array_column($tickets, 'id');
    $placeholders = implode(',', array_fill(0, count($tIds), '?'));
    $msgStmt = $db->prepare("SELECT * FROM ticket_messages WHERE ticket_id IN ($placeholders) ORDER BY created_at ASC");
    $msgStmt->execute($tIds);
    $allMsgs = $msgStmt->fetchAll();
    foreach ($allMsgs as $m) {
        $ticketMessages[$m['ticket_id']][] = $m;
    }
}

$pageTitle = "Support Tickets - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto my-4 space-y-6">
    <!-- Top Bar Navigation -->
    <div class="flex items-center justify-between">
        <a href="/user/dashboard.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Back to Dashboard</span>
        </a>
    </div>

    <!-- Main Tickets Card -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-slate-100">
            <div>
                <h2 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">Support Desk</h2>
                <p class="text-xs text-slate-400 mt-0.5">24/7 dedicated support team for order inquiries and technical help</p>
            </div>
            <button type="button" onclick="document.getElementById('newTicketSection').classList.toggle('hidden')" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 active:scale-95 text-white font-extrabold text-xs rounded-2xl shadow-lg shadow-blue-500/25 transition-all flex items-center justify-center gap-1.5 cursor-pointer">
                <span>+</span> Open New Ticket
            </button>
        </div>

        <?php if ($error): ?>
            <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold flex items-center gap-2">
                <span>⚠️</span>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- Create Ticket Collapsible Form -->
        <div id="newTicketSection" class="<?= $showNewModal || !empty($_GET['order_id']) ? '' : 'hidden' ?> p-6 rounded-3xl bg-gradient-to-br from-blue-50/60 via-white to-indigo-50/40 border border-blue-200/80 shadow-xs">
            <h3 class="text-base font-black text-slate-900 mb-4 flex items-center gap-2">
                <span>💬</span> Open a New Support Ticket
            </h3>
            <form action="/user/tickets.php" method="POST" class="space-y-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="create_ticket">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Subject</label>
                        <input type="text" name="subject" required placeholder="e.g. Order speed or refill inquiry" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-2xl text-xs font-semibold focus:ring-3 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                    </div>
                    <div>
                        <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Related Order ID (Optional)</label>
                        <input type="number" name="order_id" value="<?= e($_GET['order_id'] ?? '') ?>" placeholder="e.g. 10254" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-2xl text-xs font-semibold focus:ring-3 focus:ring-blue-500/20 focus:border-blue-600 transition-all font-mono-nums">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Priority Level</label>
                    <select name="priority" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-2xl text-xs font-semibold focus:ring-3 focus:ring-blue-500/20 focus:border-blue-600 transition-all cursor-pointer">
                        <option value="low">Low Priority (General question)</option>
                        <option value="medium" selected>Medium Priority (Standard support)</option>
                        <option value="high">High Priority (Urgent order issue)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Detailed Message</label>
                    <textarea name="message" rows="4" required placeholder="Describe your issue or order details clearly so our agents can resolve it quickly..." class="w-full p-4 bg-white border border-slate-200 rounded-2xl text-xs font-medium focus:ring-3 focus:ring-blue-500/20 focus:border-blue-600 transition-all"></textarea>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="button" onclick="document.getElementById('newTicketSection').classList.add('hidden')" class="px-5 py-2.5 text-xs font-bold text-slate-600 hover:bg-slate-200/70 rounded-xl transition-colors cursor-pointer">Cancel</button>
                    <button type="submit" class="px-7 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-black text-xs rounded-2xl shadow-lg shadow-blue-500/25 transition-all cursor-pointer">Submit Ticket &rarr;</button>
                </div>
            </form>
        </div>

        <!-- Filter Tabs -->
        <div class="flex items-center gap-2 overflow-x-auto py-2">
            <a href="/user/tickets.php?status=all" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $statusFilter === 'all' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">All Tickets</a>
            <a href="/user/tickets.php?status=open" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $statusFilter === 'open' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">Open</a>
            <a href="/user/tickets.php?status=in_progress" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $statusFilter === 'in_progress' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">In Progress</a>
            <a href="/user/tickets.php?status=closed" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $statusFilter === 'closed' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">Closed</a>
        </div>

        <!-- Tickets List -->
        <?php if (empty($tickets)): ?>
            <div class="py-16 text-center">
                <div class="w-16 h-16 rounded-2xl bg-slate-100 mx-auto flex items-center justify-center text-2xl mb-3 shadow-xs">💬</div>
                <h3 class="text-sm font-bold text-slate-900">No support tickets found</h3>
                <p class="text-xs text-slate-500 mt-1">Need help with an order or payment? Create a ticket anytime.</p>
            </div>
        <?php else: ?>
            <div class="space-y-4 mt-2">
                <?php foreach ($tickets as $t): 
                    $tId = (int)$t['id'];
                    $msgs = $ticketMessages[$tId] ?? [];
                ?>
                    <div class="p-5 rounded-3xl border border-slate-200/80 hover:border-slate-300 bg-white shadow-xs transition-all">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 cursor-pointer" onclick="document.getElementById('thread-<?= $tId ?>').classList.toggle('hidden')">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-black font-mono text-blue-600 bg-blue-50 px-2 py-0.5 rounded-lg border border-blue-100">#T<?= $tId ?></span>
                                    <h4 class="text-sm font-black text-slate-900"><?= e($t['subject']) ?></h4>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider <?= $t['priority'] === 'high' ? 'bg-rose-50 text-rose-600 border border-rose-200' : ($t['priority'] === 'medium' ? 'bg-amber-50 text-amber-600 border border-amber-200' : 'bg-slate-100 text-slate-600') ?>">
                                        <?= e($t['priority']) ?>
                                    </span>
                                </div>
                                <div class="flex flex-wrap items-center gap-2 text-[11px] text-slate-400 mt-1">
                                    <span><?= date('d M Y, h:i A', strtotime($t['created_at'])) ?></span>
                                    <?php if ($t['order_id']): ?>
                                        <span>• Order #<?= (int)$t['order_id'] ?></span>
                                    <?php endif; ?>
                                    <span>• <?= count($msgs) ?> message(s)</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-3 self-end sm:self-center">
                                <span class="px-3 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-wider <?= $t['status'] === 'open' ? 'bg-amber-50 text-amber-600 border border-amber-200' : ($t['status'] === 'in_progress' ? 'bg-blue-50 text-blue-600 border border-blue-200' : 'bg-slate-100 text-slate-600') ?>">
                                    <?= e(str_replace('_', ' ', $t['status'])) ?>
                                </span>
                                <span class="text-xs text-blue-600 font-bold hover:underline">Conversation &darr;</span>
                            </div>
                        </div>

                        <!-- Ticket Conversation Thread -->
                        <div id="thread-<?= $tId ?>" class="hidden mt-4 pt-4 border-t border-slate-100 space-y-3">
                            <?php foreach ($msgs as $msg): ?>
                                <div class="p-4 rounded-2xl text-xs <?= $msg['is_admin'] ? 'bg-blue-50/80 border border-blue-200/80 text-blue-950 ml-4 sm:ml-8' : 'bg-slate-50 border border-slate-200/80 text-slate-800 mr-4 sm:mr-8' ?>">
                                    <div class="flex items-center justify-between font-bold mb-1.5">
                                        <span class="<?= $msg['is_admin'] ? 'text-blue-700' : 'text-slate-800' ?>">
                                            <?= $msg['is_admin'] ? '🛡️ Support Specialist' : '👤 You' ?>
                                        </span>
                                        <span class="text-[10px] text-slate-400 font-normal"><?= date('d M, h:i A', strtotime($msg['created_at'])) ?></span>
                                    </div>
                                    <p class="leading-relaxed whitespace-pre-wrap text-slate-700"><?= e($msg['message']) ?></p>
                                </div>
                            <?php endforeach; ?>

                            <!-- Reply input for user -->
                            <?php if ($t['status'] !== 'closed'): ?>
                                <form action="/user/tickets.php" method="POST" class="pt-2 flex gap-2">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="reply_ticket">
                                    <input type="hidden" name="ticket_id" value="<?= $tId ?>">
                                    <input type="text" name="message" required placeholder="Type your response to support..." class="flex-1 px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-medium focus:ring-3 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                                    <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-2xl text-xs font-black shadow-md shadow-blue-500/20 transition-all cursor-pointer">Send</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
