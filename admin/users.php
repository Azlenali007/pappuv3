<?php
/**
 * Admin User Management
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

// Handle balance adjustments or status toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';
    $targetUserId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'adjust_balance') {
        $amount = (float)($_POST['amount'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Admin balance adjustment');

        if ($targetUserId > 0 && $amount != 0) {
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE users SET balance = balance + :amt WHERE id = :id")->execute(['amt' => $amount, 'id' => $targetUserId]);
                $db->prepare("INSERT INTO transactions (user_id, type, amount, gateway, status, note) VALUES (:uid, 'admin_adjustment', :amt, 'admin', 'completed', :note)")->execute([
                    'uid' => $targetUserId,
                    'amt' => $amount,
                    'note' => $reason
                ]);
                $db->commit();
                set_flash('success', "Balance adjusted successfully by " . format_currency($amount));
            } catch (Exception $e) {
                $db->rollBack();
                set_flash('error', "Failed to adjust balance: " . $e->getMessage());
            }
        }
    } elseif ($action === 'toggle_status') {
        $newStatus = $_POST['status'] === 'banned' ? 'banned' : 'active';
        $db->prepare("UPDATE users SET status = :st WHERE id = :id")->execute(['st' => $newStatus, 'id' => $targetUserId]);
        set_flash('success', "User status updated to {$newStatus}.");
    }
    redirect('/admin/users.php');
}

$users = $db->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();

$pageTitle = "Manage Users - Admin";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto my-4 space-y-6">
    <div class="flex items-center justify-between">
        <a href="/admin/dashboard.php" class="text-xs font-bold text-slate-500 hover:text-blue-600">&larr; Back to Dashboard</a>
    </div>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl">
        <h2 class="text-2xl font-extrabold text-slate-900 pb-6 border-b border-slate-100">User Management</h2>
        
        <div class="overflow-x-auto mt-4">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-slate-200 text-slate-400 uppercase tracking-wider">
                        <th class="py-3 px-3">ID</th>
                        <th class="py-3 px-3">Username</th>
                        <th class="py-3 px-3">Email</th>
                        <th class="py-3 px-3">Balance</th>
                        <th class="py-3 px-3">Role</th>
                        <th class="py-3 px-3">Status</th>
                        <th class="py-3 px-3 text-right">Quick Balance Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php foreach ($users as $u): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="py-3 px-3 font-mono text-slate-400">#<?= (int)$u['id'] ?></td>
                            <td class="py-3 px-3 font-bold text-slate-900"><?= e($u['username']) ?></td>
                            <td class="py-3 px-3 font-medium text-slate-600"><?= e($u['email']) ?></td>
                            <td class="py-3 px-3 font-extrabold text-blue-600 tabular-nums"><?= format_currency($u['balance']) ?></td>
                            <td class="py-3 px-3 uppercase text-[10px] font-bold text-slate-500"><?= e($u['role']) ?></td>
                            <td class="py-3 px-3">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?= $u['status'] === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600' ?>">
                                    <?= e($u['status']) ?>
                                </span>
                            </td>
                            <td class="py-3 px-3 text-right">
                                <form action="/admin/users.php" method="POST" class="inline-flex items-center gap-1.5">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="adjust_balance">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <input type="number" name="amount" placeholder="+/- Amount" step="10" required class="w-24 px-2 py-1 text-xs border border-slate-200 rounded-lg">
                                    <button type="submit" class="px-2.5 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-bold">Apply</button>
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
