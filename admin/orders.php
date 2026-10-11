<?php
/**
 * Admin Orders Management
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    CSRF::verifyOrAbort();
    $orderId = (int)($_POST['order_id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';
    $allowed = ['pending', 'processing', 'in_progress', 'completed', 'partial', 'cancelled'];

    if (in_array($newStatus, $allowed) && $orderId > 0) {
        if ($newStatus === 'cancelled' && isset($_POST['refund_user'])) {
            $stmt = $db->prepare("SELECT user_id, charge, status FROM orders WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $orderId]);
            $ord = $stmt->fetch();

            if ($ord && $ord['status'] !== 'cancelled') {
                $db->beginTransaction();
                $db->prepare("UPDATE users SET balance = balance + :amt WHERE id = :uid")->execute(['amt' => $ord['charge'], 'uid' => $ord['user_id']]);
                $db->prepare("UPDATE orders SET status = 'cancelled' WHERE id = :id")->execute(['id' => $orderId]);
                $db->prepare("INSERT INTO transactions (user_id, order_id, type, amount, gateway, status, note) VALUES (:uid, :oid, 'refund', :amt, 'system', 'completed', 'Refund for cancelled order')")->execute([
                    'uid' => $ord['user_id'],
                    'oid' => $orderId,
                    'amt' => $ord['charge']
                ]);
                $db->commit();
                set_flash('success', "Order #{$orderId} cancelled and refunded.");
                redirect('/admin/orders.php');
            }
        } else {
            $db->prepare("UPDATE orders SET status = :st WHERE id = :id")->execute(['st' => $newStatus, 'id' => $orderId]);
            set_flash('success', "Order #{$orderId} status changed to {$newStatus}.");
            redirect('/admin/orders.php');
        }
    }
}

$orders = $db->query("
    SELECT o.*, u.username, s.name AS service_name
    FROM orders o
    JOIN users u ON o.user_id = u.id
    JOIN services s ON o.service_id = s.id
    ORDER BY o.id DESC
")->fetchAll();

$pageTitle = "Manage Orders - Admin";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto my-4 space-y-6">
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl">
        <h2 class="text-2xl font-extrabold text-slate-900 pb-6 border-b border-slate-100">All Client Orders</h2>
        
        <div class="overflow-x-auto mt-4">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-slate-200 text-slate-400 uppercase tracking-wider">
                        <th class="py-3 px-3">ID</th>
                        <th class="py-3 px-3">User</th>
                        <th class="py-3 px-3">Service</th>
                        <th class="py-3 px-3">Link</th>
                        <th class="py-3 px-3">Qty</th>
                        <th class="py-3 px-3">Charge</th>
                        <th class="py-3 px-3">Status</th>
                        <th class="py-3 px-3 text-right">Update Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php foreach ($orders as $o): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="py-3 px-3 font-mono text-slate-400">#<?= (int)$o['id'] ?></td>
                            <td class="py-3 px-3 font-bold text-slate-900"><?= e($o['username']) ?></td>
                            <td class="py-3 px-3 font-medium text-slate-800"><?= e($o['service_name']) ?></td>
                            <td class="py-3 px-3 font-mono text-[11px] text-blue-600 max-w-xs truncate"><a href="<?= e($o['link']) ?>" target="_blank" rel="noopener noreferrer"><?= e($o['link']) ?></a></td>
                            <td class="py-3 px-3 font-mono-nums"><?= number_format($o['quantity']) ?></td>
                            <td class="py-3 px-3 font-extrabold text-slate-900 tabular-nums"><?= format_currency($o['charge']) ?></td>
                            <td class="py-3 px-3">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase <?= $o['status'] === 'completed' ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600' ?>">
                                    <?= e($o['status']) ?>
                                </span>
                            </td>
                            <td class="py-3 px-3 text-right">
                                <form action="/admin/orders.php" method="POST" class="inline-flex items-center gap-1.5">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                    <select name="status" class="px-2 py-1 bg-slate-50 border border-slate-200 rounded-lg text-xs font-semibold">
                                        <option value="pending" <?= $o['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="processing" <?= $o['status'] === 'processing' ? 'selected' : '' ?>>Processing</option>
                                        <option value="in_progress" <?= $o['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                        <option value="completed" <?= $o['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                                        <option value="cancelled" <?= $o['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                    </select>
                                    <label class="text-[10px] text-slate-500 flex items-center gap-1">
                                        <input type="checkbox" name="refund_user" value="1" title="Refund if cancelled"> Refund?
                                    </label>
                                    <button type="submit" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-900 text-white rounded-lg text-xs font-bold">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
