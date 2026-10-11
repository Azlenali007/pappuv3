<?php
/**
 * Admin Referral Program Management & Payouts
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

// Handle Actions (Settings Update, Approve, Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $enabled = isset($_POST['referral_enabled']) ? '1' : '0';
        $type = in_array($_POST['referral_commission_type'] ?? '', ['percentage', 'fixed']) ? $_POST['referral_commission_type'] : 'percentage';
        $rate = (float)($_POST['referral_commission_rate'] ?? 5.00);
        $event = in_array($_POST['referral_trigger_event'] ?? '', ['first_deposit', 'every_deposit', 'first_order']) ? $_POST['referral_trigger_event'] : 'every_deposit';
        $minDep = (float)($_POST['referral_min_deposit'] ?? 100);
        $maxComm = (float)($_POST['referral_max_commission'] ?? 1000);
        $defaultStatus = in_array($_POST['referral_default_status'] ?? '', ['approved', 'pending']) ? $_POST['referral_default_status'] : 'approved';

        set_setting('referral_enabled', $enabled);
        set_setting('referral_commission_type', $type);
        set_setting('referral_commission_rate', (string)$rate);
        set_setting('referral_trigger_event', $event);
        set_setting('referral_min_deposit', (string)$minDep);
        set_setting('referral_max_commission', (string)$maxComm);
        set_setting('referral_default_status', $defaultStatus);

        set_flash('success', 'Referral program configuration updated successfully.');
        redirect('/admin/referrals.php');
    } elseif ($action === 'approve_commission') {
        $commId = (int)($_POST['commission_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM referral_commissions WHERE id = :id AND status = 'pending' LIMIT 1");
        $stmt->execute(['id' => $commId]);
        $comm = $stmt->fetch();

        if ($comm) {
            $db->beginTransaction();
            try {
                // Update status to approved
                $db->prepare("UPDATE referral_commissions SET status = 'approved', updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                   ->execute(['id' => $commId]);

                // Credit referrer's wallet
                $db->prepare("UPDATE users SET balance = balance + :amt WHERE id = :uid")
                   ->execute(['amt' => $comm['commission_amount'], 'uid' => $comm['referrer_id']]);

                // Create ledger transaction
                $db->prepare("
                    INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, 'bonus', :amt, 'system', :txnid, 'completed', :note)
                ")->execute([
                    'uid' => $comm['referrer_id'],
                    'amt' => $comm['commission_amount'],
                    'txnid' => 'ref_appr_' . $commId,
                    'note' => 'Approved referral commission from User #' . $comm['referred_id']
                ]);

                $db->commit();
                set_flash('success', "Commission #{$commId} approved and credited to referrer wallet.");
            } catch (Exception $e) {
                $db->rollBack();
                set_flash('error', "Failed to approve commission: " . $e->getMessage());
            }
        }
        redirect('/admin/referrals.php');
    } elseif ($action === 'reject_commission') {
        $commId = (int)($_POST['commission_id'] ?? 0);
        $db->prepare("UPDATE referral_commissions SET status = 'rejected', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'pending'")
           ->execute(['id' => $commId]);
        set_flash('info', "Commission #{$commId} marked as rejected.");
        redirect('/admin/referrals.php');
    }
}

// Current Settings
$refEnabled = get_setting('referral_enabled', '1') === '1';
$refType = get_setting('referral_commission_type', 'percentage');
$refRate = (float)get_setting('referral_commission_rate', '5.00');
$refEvent = get_setting('referral_trigger_event', 'every_deposit');
$refMinDeposit = (float)get_setting('referral_min_deposit', '100');
$refMaxComm = (float)get_setting('referral_max_commission', '1000');
$refDefaultStatus = get_setting('referral_default_status', 'approved');

// Global Statistics
$totalReferrers = (int)$db->query("SELECT COUNT(DISTINCT referrer_id) FROM referrals")->fetchColumn();
$totalReferredUsers = (int)$db->query("SELECT COUNT(DISTINCT referred_id) FROM referrals")->fetchColumn();
$totalPaid = (float)$db->query("SELECT COALESCE(SUM(commission_amount), 0) FROM referral_commissions WHERE status = 'approved'")->fetchColumn();
$totalPending = (float)$db->query("SELECT COALESCE(SUM(commission_amount), 0) FROM referral_commissions WHERE status = 'pending'")->fetchColumn();
$pendingCount = (int)$db->query("SELECT COUNT(*) FROM referral_commissions WHERE status = 'pending'")->fetchColumn();

// Fetch Recent Commissions
$commStmt = $db->query("
    SELECT 
        rc.*,
        u_ref.username AS referrer_name,
        u_ref.email AS referrer_email,
        u_user.username AS referred_name
    FROM referral_commissions rc
    JOIN users u_ref ON rc.referrer_id = u_ref.id
    JOIN users u_user ON rc.referred_id = u_user.id
    ORDER BY rc.id DESC
    LIMIT 100
");
$allCommissions = $commStmt->fetchAll();

$pageTitle = "Referral System - Admin Console";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-6xl mx-auto my-6 space-y-8">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight flex items-center gap-2">
                <span>🎁</span> Refer &amp; Earn Program
            </h1>
            <p class="text-xs text-slate-500 mt-1">Configure automated user commission rewards, approval workflows, and audit payouts</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold <?= $refEnabled ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-600' ?>">
                Status: <?= $refEnabled ? 'Active' : 'Disabled' ?>
            </span>
            <?php if ($pendingCount > 0): ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200 animate-pulse">
                    <?= $pendingCount ?> Pending Approval
                </span>
            <?php endif; ?>
        </div>
    </div>

    <?= render_flash() ?>

    <!-- KPI Summary Grid -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-3xl p-5 border border-slate-200/80 shadow-xs space-y-1">
            <span class="text-xs font-bold text-slate-400">Total Referrers</span>
            <div class="text-2xl font-black text-slate-900 font-mono-nums"><?= number_format($totalReferrers) ?></div>
            <p class="text-[11px] text-slate-400">Active promoter accounts</p>
        </div>
        <div class="bg-white rounded-3xl p-5 border border-slate-200/80 shadow-xs space-y-1">
            <span class="text-xs font-bold text-slate-400">Referred Clients</span>
            <div class="text-2xl font-black text-blue-600 font-mono-nums"><?= number_format($totalReferredUsers) ?></div>
            <p class="text-[11px] text-slate-400">Acquired via referrals</p>
        </div>
        <div class="bg-white rounded-3xl p-5 border border-slate-200/80 shadow-xs space-y-1">
            <span class="text-xs font-bold text-slate-400">Paid Commissions</span>
            <div class="text-2xl font-black text-emerald-600 font-mono-nums"><?= format_currency($totalPaid) ?></div>
            <p class="text-[11px] text-slate-400">Credited to user balances</p>
        </div>
        <div class="bg-white rounded-3xl p-5 border border-slate-200/80 shadow-xs space-y-1">
            <span class="text-xs font-bold text-slate-400">Pending Review</span>
            <div class="text-2xl font-black text-amber-600 font-mono-nums"><?= format_currency($totalPending) ?></div>
            <p class="text-[11px] text-slate-400"><?= $pendingCount ?> records queued</p>
        </div>
    </div>

    <!-- Referral Settings Form -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div class="border-b border-slate-100 pb-4 flex items-center justify-between">
            <div>
                <h2 class="text-base font-extrabold text-slate-900 tracking-tight">Program Configuration</h2>
                <p class="text-xs text-slate-400 mt-0.5">Control reward percentages, qualification thresholds, and approval triggers</p>
            </div>
            <span class="text-xs font-mono text-slate-400">Settings Engine</span>
        </div>

        <form action="/admin/referrals.php" method="POST" class="space-y-6">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="save_settings">

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                <!-- 1. Enable / Disable Toggle -->
                <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 flex items-center justify-between">
                    <div>
                        <label class="block text-xs font-extrabold text-slate-800">Referral Program</label>
                        <span class="text-[11px] text-slate-400 block mt-0.5">Allow users to earn rewards</span>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="referral_enabled" value="1" <?= $refEnabled ? 'checked' : '' ?> class="sr-only peer">
                        <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                    </label>
                </div>

                <!-- 2. Commission Type -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Commission Type</label>
                    <select name="referral_commission_type" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                        <option value="percentage" <?= $refType === 'percentage' ? 'selected' : '' ?>>Percentage (%) of Transaction</option>
                        <option value="fixed" <?= $refType === 'fixed' ? 'selected' : '' ?>>Fixed Cash Reward</option>
                    </select>
                </div>

                <!-- 3. Commission Rate -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Commission Rate / Amount</label>
                    <input type="number" step="0.01" min="0.01" name="referral_commission_rate" value="<?= e((string)$refRate) ?>" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                    <span class="text-[11px] text-slate-400 mt-1 block">e.g. 5 for 5% or 50 for fixed amount</span>
                </div>

                <!-- 4. Trigger Event -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Reward Trigger Event</label>
                    <select name="referral_trigger_event" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                        <option value="every_deposit" <?= $refEvent === 'every_deposit' ? 'selected' : '' ?>>Every Eligible Wallet Deposit</option>
                        <option value="first_deposit" <?= $refEvent === 'first_deposit' ? 'selected' : '' ?>>First Deposit Only</option>
                        <option value="first_order" <?= $refEvent === 'first_order' ? 'selected' : '' ?>>First Placed Order</option>
                    </select>
                </div>

                <!-- 5. Minimum Qualifying Deposit -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Min Qualifying Deposit</label>
                    <input type="number" step="1" min="1" name="referral_min_deposit" value="<?= e((string)$refMinDeposit) ?>" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                    <span class="text-[11px] text-slate-400 mt-1 block">Minimum deposit required to trigger commission</span>
                </div>

                <!-- 6. Max Commission Per Transaction -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Max Commission Cap</label>
                    <input type="number" step="1" min="0" name="referral_max_commission" value="<?= e((string)$refMaxComm) ?>" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                    <span class="text-[11px] text-slate-400 mt-1 block">Upper cap per single reward (0 for uncapped)</span>
                </div>

                <!-- 7. Default Approval Status -->
                <div class="sm:col-span-2 lg:col-span-3">
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Commission Approval Policy</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="flex items-center gap-3 p-3.5 rounded-2xl border cursor-pointer <?= $refDefaultStatus === 'approved' ? 'bg-blue-50/60 border-blue-300' : 'bg-slate-50 border-slate-200' ?>">
                            <input type="radio" name="referral_default_status" value="approved" <?= $refDefaultStatus === 'approved' ? 'checked' : '' ?> class="text-blue-600 focus:ring-blue-500">
                            <div>
                                <span class="text-xs font-extrabold text-slate-900 block">Instant Automated Approval</span>
                                <span class="text-[11px] text-slate-500">Commissions are instantly credited to the referrer's wallet.</span>
                            </div>
                        </label>
                        <label class="flex items-center gap-3 p-3.5 rounded-2xl border cursor-pointer <?= $refDefaultStatus === 'pending' ? 'bg-blue-50/60 border-blue-300' : 'bg-slate-50 border-slate-200' ?>">
                            <input type="radio" name="referral_default_status" value="pending" <?= $refDefaultStatus === 'pending' ? 'checked' : '' ?> class="text-blue-600 focus:ring-blue-500">
                            <div>
                                <span class="text-xs font-extrabold text-slate-900 block">Manual Admin Review</span>
                                <span class="text-[11px] text-slate-500">Queues commissions as Pending for staff review and manual approval.</span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-slate-100 flex justify-end">
                <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold shadow-md shadow-blue-500/20 transition-all">
                    Save Configuration
                </button>
            </div>
        </form>
    </div>

    <!-- Referral Commissions Audit Ledger -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h2 class="text-base font-extrabold text-slate-900 tracking-tight">Commissions Audit Ledger</h2>
                <p class="text-xs text-slate-400 mt-0.5">Recent referral commission events and payouts</p>
            </div>
            <span class="text-xs font-bold text-slate-500"><?= count($allCommissions) ?> records</span>
        </div>

        <?php if (empty($allCommissions)): ?>
            <div class="p-12 text-center text-slate-400 space-y-2">
                <div class="text-3xl">📑</div>
                <div class="text-xs font-bold text-slate-600">No referral commission events recorded yet.</div>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-slate-400 border-b border-slate-100 pb-3 uppercase text-[10px] font-bold tracking-wider">
                            <th class="py-3 px-3">#ID</th>
                            <th class="py-3 px-3">Date</th>
                            <th class="py-3 px-3">Referrer</th>
                            <th class="py-3 px-3">Referred Client</th>
                            <th class="py-3 px-3">Event</th>
                            <th class="py-3 px-3 text-right">Qualified Deposit</th>
                            <th class="py-3 px-3 text-right">Commission</th>
                            <th class="py-3 px-3 text-center">Status</th>
                            <th class="py-3 px-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($allCommissions as $row): ?>
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="py-3 px-3 font-mono text-slate-400 text-[11px]">#<?= $row['id'] ?></td>
                                <td class="py-3 px-3 text-slate-500 font-mono text-[11px] whitespace-nowrap">
                                    <?= date('d M Y, H:i', strtotime($row['created_at'])) ?>
                                </td>
                                <td class="py-3 px-3">
                                    <div class="font-extrabold text-slate-800"><?= e($row['referrer_name']) ?></div>
                                    <div class="text-[10px] text-slate-400"><?= e($row['referrer_email']) ?></div>
                                </td>
                                <td class="py-3 px-3 font-semibold text-slate-700">
                                    <?= e($row['referred_name']) ?>
                                </td>
                                <td class="py-3 px-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700">
                                        <?= e(ucfirst($row['event_type'])) ?>
                                    </span>
                                </td>
                                <td class="py-3 px-3 text-right font-mono-nums text-slate-600">
                                    <?= format_currency($row['source_amount']) ?>
                                </td>
                                <td class="py-3 px-3 text-right font-mono-nums font-bold text-emerald-600">
                                    +<?= format_currency($row['commission_amount']) ?>
                                </td>
                                <td class="py-3 px-3 text-center">
                                    <?php if ($row['status'] === 'approved'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            Approved
                                        </span>
                                    <?php elseif ($row['status'] === 'pending'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                            Pending
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                            Rejected
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-3 text-right whitespace-nowrap">
                                    <?php if ($row['status'] === 'pending'): ?>
                                        <div class="flex items-center justify-end gap-1.5">
                                            <form action="/admin/referrals.php" method="POST" class="inline" onsubmit="return confirm('Approve commission and credit referrer wallet?')">
                                                <?= CSRF::field() ?>
                                                <input type="hidden" name="action" value="approve_commission">
                                                <input type="hidden" name="commission_id" value="<?= $row['id'] ?>">
                                                <button type="submit" class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-[10px] font-bold shadow-xs">
                                                    Approve
                                                </button>
                                            </form>
                                            <form action="/admin/referrals.php" method="POST" class="inline" onsubmit="return confirm('Reject this commission?')">
                                                <?= CSRF::field() ?>
                                                <input type="hidden" name="action" value="reject_commission">
                                                <input type="hidden" name="commission_id" value="<?= $row['id'] ?>">
                                                <button type="submit" class="px-2.5 py-1 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-[10px] font-bold shadow-xs">
                                                    Reject
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-slate-400 text-[11px]">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
