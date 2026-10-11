<?php
/**
 * User Refer & Earn Portal
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

// Ensure user has a referral code
$myRefCode = get_user_referral_code((int)$user['id']);

// Construct full referral URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:3000';
$referralUrl = $protocol . $host . '/register.php?ref=' . urlencode($myRefCode);

// Settings
$referralEnabled = get_setting('referral_enabled', '1') === '1';
$commType = get_setting('referral_commission_type', 'percentage');
$commRate = (float)get_setting('referral_commission_rate', '5.00');
$commEvent = get_setting('referral_trigger_event', 'every_deposit');
$minDeposit = (float)get_setting('referral_min_deposit', '100');

// Calculate Statistics
$statsStmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT r.referred_id) AS total_referred,
        COALESCE(SUM(CASE WHEN rc.status = 'approved' THEN rc.commission_amount ELSE 0 END), 0) AS total_earned,
        COALESCE(SUM(CASE WHEN rc.status = 'pending' THEN rc.commission_amount ELSE 0 END), 0) AS pending_earned,
        COUNT(DISTINCT CASE WHEN rc.id IS NOT NULL THEN r.referred_id END) AS active_referrals
    FROM referrals r
    LEFT JOIN referral_commissions rc ON r.referrer_id = rc.referrer_id AND r.referred_id = rc.referred_id
    WHERE r.referrer_id = :uid
");
$statsStmt->execute(['uid' => $user['id']]);
$stats = $statsStmt->fetch() ?: [
    'total_referred' => 0,
    'total_earned' => 0,
    'pending_earned' => 0,
    'active_referrals' => 0
];

// Fetch recent commissions log
$historyStmt = $db->prepare("
    SELECT 
        rc.*, 
        u.username AS referred_username,
        u.created_at AS user_joined
    FROM referral_commissions rc
    JOIN users u ON rc.referred_id = u.id
    WHERE rc.referrer_id = :uid
    ORDER BY rc.created_at DESC
    LIMIT 50
");
$historyStmt->execute(['uid' => $user['id']]);
$commissions = $historyStmt->fetchAll();

// Fetch referred users list
$referredUsersStmt = $db->prepare("
    SELECT 
        r.*, 
        u.username,
        u.created_at AS joined_date
    FROM referrals r
    JOIN users u ON r.referred_id = u.id
    WHERE r.referrer_id = :uid
    ORDER BY r.created_at DESC
    LIMIT 20
");
$referredUsersStmt->execute(['uid' => $user['id']]);
$referredUsers = $referredUsersStmt->fetchAll();

$pageTitle = "Refer & Earn - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-5xl mx-auto my-6 space-y-6">
    <!-- Header Navigation -->
    <div class="flex items-center justify-between">
        <a href="/user/dashboard.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Back to Dashboard</span>
        </a>
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
            Program <?= $referralEnabled ? 'Active' : 'Paused' ?>
        </span>
    </div>

    <!-- Hero / Referral Share Banner -->
    <div class="bg-gradient-to-br from-indigo-900 via-blue-900 to-slate-900 rounded-3xl p-6 sm:p-10 text-white shadow-xl relative overflow-hidden">
        <!-- Background Ambient Blobs -->
        <div class="absolute -top-24 -right-24 w-80 h-80 rounded-full bg-blue-500/20 blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -left-24 w-80 h-80 rounded-full bg-purple-500/20 blur-3xl pointer-events-none"></div>

        <div class="relative z-10 grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">
            <div class="lg:col-span-7 space-y-4">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-500/20 border border-blue-400/30 text-blue-200 text-xs font-bold uppercase tracking-wider">
                    <span>🎁</span> High-Payout Referral Program
                </div>
                <h1 class="text-2xl sm:text-4xl font-extrabold tracking-tight leading-tight">
                    Invite Friends &amp; Earn <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-300 via-teal-200 to-emerald-300"><?= $commType === 'percentage' ? ($commRate . '% Lifetime') : format_currency($commRate) ?></span>
                </h1>
                <p class="text-xs sm:text-sm text-slate-300 leading-relaxed max-w-xl">
                    Share your unique referral code with content creators, agencies, and businesses. When they register and top up their wallet, commission rewards are automatically credited to your balance!
                </p>

                <!-- Copy Referral Link Box -->
                <div class="pt-2">
                    <label class="block text-[11px] font-bold text-slate-300 uppercase tracking-wider mb-1.5">Your Unique Referral Link</label>
                    <div class="flex flex-col sm:flex-row items-stretch gap-2 bg-slate-950/60 p-1.5 rounded-2xl border border-slate-700/80 backdrop-blur-md">
                        <input type="text" readonly id="refLinkInput" value="<?= e($referralUrl) ?>" class="flex-1 bg-transparent px-3 py-2 text-xs font-mono text-blue-200 focus:outline-none select-all truncate">
                        <button type="button" onclick="copyRefLink()" id="copyBtn" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-xs font-extrabold transition-all shadow-md shadow-blue-600/30 flex items-center justify-center gap-1.5 active:scale-95">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            <span id="copyBtnText">Copy Link</span>
                        </button>
                    </div>
                </div>

                <!-- Social Quick Share -->
                <div class="flex items-center gap-2 pt-1 text-xs">
                    <span class="text-slate-400 text-[11px]">Share via:</span>
                    <a href="https://api.whatsapp.com/send?text=<?= urlencode("Boost your social media with the fastest SMM Panel! Register here: " . $referralUrl) ?>" target="_blank" rel="noopener" class="px-3 py-1 rounded-lg bg-emerald-600/30 hover:bg-emerald-600 text-emerald-200 hover:text-white transition-colors text-[11px] font-bold">WhatsApp</a>
                    <a href="https://t.me/share/url?url=<?= urlencode($referralUrl) ?>&text=<?= urlencode("Check out the best SMM Panel for Instagram, YouTube & Telegram!") ?>" target="_blank" rel="noopener" class="px-3 py-1 rounded-lg bg-sky-600/30 hover:bg-sky-600 text-sky-200 hover:text-white transition-colors text-[11px] font-bold">Telegram</a>
                    <a href="https://twitter.com/intent/tweet?text=<?= urlencode("Scale your social media growth instantly! " . $referralUrl) ?>" target="_blank" rel="noopener" class="px-3 py-1 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-200 hover:text-white transition-colors text-[11px] font-bold">X (Twitter)</a>
                </div>
            </div>

            <!-- Referral Code Card Right -->
            <div class="lg:col-span-5 flex flex-col items-center justify-center p-6 bg-white/5 rounded-3xl border border-white/10 backdrop-blur-md text-center space-y-3">
                <span class="text-[11px] font-bold text-slate-300 uppercase tracking-widest">Referral Code</span>
                <div class="px-6 py-3 bg-white/10 rounded-2xl border border-white/20 text-2xl font-mono font-black tracking-widest text-emerald-300 select-all shadow-inner">
                    <?= e($myRefCode) ?>
                </div>
                <p class="text-[11px] text-slate-300 max-w-xs">
                    Friends can also type this code manually in the registration form.
                </p>
                <div class="pt-2 text-xs font-semibold text-blue-200 flex items-center gap-1.5">
                    <span>⚡ Min Qualifying Deposit:</span>
                    <span class="font-mono"><?= format_currency($minDeposit) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- KPI Statistics Grid -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- 1. Total Referred -->
        <div class="bg-white rounded-3xl p-5 border border-slate-200/80 shadow-sm space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-400">Total Referred</span>
                <span class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-sm">👥</span>
            </div>
            <div class="text-2xl font-black text-slate-900 font-mono-nums"><?= number_format((int)$stats['total_referred']) ?></div>
            <p class="text-[11px] text-slate-400 font-medium">Friends signed up</p>
        </div>

        <!-- 2. Active Paying Referrals -->
        <div class="bg-white rounded-3xl p-5 border border-slate-200/80 shadow-sm space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-400">Active Qualified</span>
                <span class="w-8 h-8 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-sm">⭐</span>
            </div>
            <div class="text-2xl font-black text-purple-600 font-mono-nums"><?= number_format((int)$stats['active_referrals']) ?></div>
            <p class="text-[11px] text-slate-400 font-medium">Deposited &amp; qualified</p>
        </div>

        <!-- 3. Approved Earnings -->
        <div class="bg-white rounded-3xl p-5 border border-slate-200/80 shadow-sm space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-400">Total Earned</span>
                <span class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm">💰</span>
            </div>
            <div class="text-2xl font-black text-emerald-600 font-mono-nums"><?= format_currency($stats['total_earned']) ?></div>
            <p class="text-[11px] text-slate-400 font-medium">Credited to wallet</p>
        </div>

        <!-- 4. Pending Commissions -->
        <div class="bg-white rounded-3xl p-5 border border-slate-200/80 shadow-sm space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-400">Pending Review</span>
                <span class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-sm">⏳</span>
            </div>
            <div class="text-2xl font-black text-amber-600 font-mono-nums"><?= format_currency($stats['pending_earned']) ?></div>
            <p class="text-[11px] text-slate-400 font-medium">Awaiting admin review</p>
        </div>
    </div>

    <!-- How It Works Section -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div>
            <h2 class="text-base font-extrabold text-slate-900 tracking-tight">How the Referral Program Works</h2>
            <p class="text-xs text-slate-400 mt-0.5">Simple 3-step automated earning cycle</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200/60 space-y-2">
                <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-700 flex items-center justify-center font-black text-sm">
                    1
                </div>
                <h3 class="text-xs font-extrabold text-slate-900">Share Your Link</h3>
                <p class="text-xs text-slate-500 leading-relaxed">
                    Send your referral link to friends, groups, or social channels. A 30-day cookie ensures you receive attribution even if they sign up later.
                </p>
            </div>

            <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200/60 space-y-2">
                <div class="w-10 h-10 rounded-xl bg-purple-100 text-purple-700 flex items-center justify-center font-black text-sm">
                    2
                </div>
                <h3 class="text-xs font-extrabold text-slate-900">They Deposit &amp; Order</h3>
                <p class="text-xs text-slate-500 leading-relaxed">
                    When your referred user adds funds of at least <?= format_currency($minDeposit) ?>, the system tracks the qualifying transaction.
                </p>
            </div>

            <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200/60 space-y-2">
                <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-black text-sm">
                    3
                </div>
                <h3 class="text-xs font-extrabold text-slate-900">Get Instant Commissions</h3>
                <p class="text-xs text-slate-500 leading-relaxed">
                    You earn <?= $commType === 'percentage' ? ($commRate . '%') : format_currency($commRate) ?> commission directly into your account balance for instant orders or withdrawals.
                </p>
            </div>
        </div>
    </div>

    <!-- Referral History Table -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h2 class="text-base font-extrabold text-slate-900 tracking-tight">Referral Commission History</h2>
                <p class="text-xs text-slate-400 mt-0.5">Real-time ledger of your earned commissions</p>
            </div>
            <span class="text-xs font-bold text-slate-500"><?= count($commissions) ?> records</span>
        </div>

        <?php if (empty($commissions)): ?>
            <div class="p-12 text-center space-y-3">
                <div class="w-14 h-14 rounded-2xl bg-slate-50 border border-slate-200 text-slate-400 flex items-center justify-center mx-auto text-2xl">
                    🎁
                </div>
                <h4 class="text-sm font-extrabold text-slate-800">No commissions yet</h4>
                <p class="text-xs text-slate-400 max-w-sm mx-auto">
                    Share your referral link above to start generating lifetime commissions when friends top up.
                </p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-slate-400 border-b border-slate-100 pb-3 uppercase text-[10px] font-bold tracking-wider">
                            <th class="py-3 px-3">Date</th>
                            <th class="py-3 px-3">Referred User</th>
                            <th class="py-3 px-3">Event</th>
                            <th class="py-3 px-3 text-right">Qualified Amount</th>
                            <th class="py-3 px-3 text-right">Commission</th>
                            <th class="py-3 px-3 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($commissions as $comm): ?>
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="py-3.5 px-3 text-slate-500 font-mono text-[11px]">
                                    <?= date('d M Y, H:i', strtotime($comm['created_at'])) ?>
                                </td>
                                <td class="py-3.5 px-3 font-semibold text-slate-800">
                                    <?= e(substr($comm['referred_username'] ?? 'User', 0, 3) . '***') ?>
                                </td>
                                <td class="py-3.5 px-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700">
                                        <?= e(ucfirst($comm['event_type'])) ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-3 text-right font-mono-nums text-slate-600">
                                    <?= format_currency($comm['source_amount']) ?>
                                </td>
                                <td class="py-3.5 px-3 text-right font-mono-nums font-bold text-emerald-600">
                                    +<?= format_currency($comm['commission_amount']) ?>
                                </td>
                                <td class="py-3.5 px-3 text-center">
                                    <?php if ($comm['status'] === 'approved'): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            ✓ Credited
                                        </span>
                                    <?php elseif ($comm['status'] === 'pending'): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                            ⏳ Pending
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                            Rejected
                                        </span>
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

<script>
function copyRefLink() {
    const input = document.getElementById('refLinkInput');
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(() => {
        const btnText = document.getElementById('copyBtnText');
        const oldText = btnText.innerText;
        btnText.innerText = 'Copied!';
        setTimeout(() => {
            btnText.innerText = oldText;
        }, 2000);
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
