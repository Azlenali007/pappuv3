<?php
/**
 * Admin Transactions Ledger
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireAdmin();
$db = Database::getConnection();

$transactions = $db->query("
    SELECT t.*, u.username
    FROM transactions t
    JOIN users u ON t.user_id = u.id
    ORDER BY t.created_at DESC
")->fetchAll();

$pageTitle = "All Transactions - Admin";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto my-4 space-y-6">
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl">
        <h2 class="text-2xl font-extrabold text-slate-900 pb-6 border-b border-slate-100">System Transaction Log</h2>
        
        <div class="overflow-x-auto mt-4">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-slate-200 text-slate-400 uppercase tracking-wider">
                        <th class="py-3 px-3">ID</th>
                        <th class="py-3 px-3">User</th>
                        <th class="py-3 px-3">Type</th>
                        <th class="py-3 px-3">Amount</th>
                        <th class="py-3 px-3">Gateway</th>
                        <th class="py-3 px-3">Txn ID</th>
                        <th class="py-3 px-3">Status</th>
                        <th class="py-3 px-3 text-right">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php foreach ($transactions as $t): 
                        $isPos = (float)$t['amount'] > 0;
                    ?>
                        <tr class="hover:bg-slate-50">
                            <td class="py-3 px-3 font-mono text-slate-400">#<?= (int)$t['id'] ?></td>
                            <td class="py-3 px-3 font-bold text-slate-900"><?= e($t['username']) ?></td>
                            <td class="py-3 px-3 font-semibold text-slate-600 uppercase text-[10px]"><?= e($t['type']) ?></td>
                            <td class="py-3 px-3 font-extrabold tabular-nums <?= $isPos ? 'text-emerald-600' : 'text-rose-600' ?>">
                                <?= $isPos ? '+' : '' ?><?= format_currency($t['amount']) ?>
                            </td>
                            <td class="py-3 px-3 font-medium text-slate-500"><?= e($t['gateway']) ?></td>
                            <td class="py-3 px-3 font-mono text-[11px] text-slate-400"><?= e($t['gateway_txn_id']) ?></td>
                            <td class="py-3 px-3"><span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-emerald-50 text-emerald-700"><?= e($t['status']) ?></span></td>
                            <td class="py-3 px-3 text-right text-slate-400"><?= date('d M, h:i A', strtotime($t['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
