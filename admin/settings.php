<?php
/**
 * Admin System Settings
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();

    $settings = [
        'site_name' => trim($_POST['site_name'] ?? 'SMM Panel'),
        'site_tagline' => trim($_POST['site_tagline'] ?? 'Grow Your Social Media'),
        'support_email' => trim($_POST['support_email'] ?? 'support@smmpanel.local'),
        'currency' => trim($_POST['currency'] ?? '₹'),
        'currency_code' => trim($_POST['currency_code'] ?? 'INR'),
        'min_deposit' => trim($_POST['min_deposit'] ?? '100'),
        'max_deposit' => trim($_POST['max_deposit'] ?? '50000'),
        'razorpay_key_id' => trim($_POST['razorpay_key_id'] ?? ''),
        'razorpay_key_secret' => trim($_POST['razorpay_key_secret'] ?? ''),
        'maintenance_mode' => isset($_POST['maintenance_mode']) ? '1' : '0',
        'allow_registration' => isset($_POST['allow_registration']) ? '1' : '0'
    ];

    foreach ($settings as $k => $v) {
        set_setting($k, $v);
    }
    update_config_file_settings($settings);

    set_flash('success', 'System settings updated and saved successfully.');
    redirect('/admin/settings.php');
}

$siteName = get_setting('site_name', APP_NAME);
$siteTagline = get_setting('site_tagline', APP_TAGLINE);
$supportEmail = get_setting('support_email', 'support@smmpanel.local');
$currency = get_setting('currency', APP_CURRENCY);
$currencyCode = get_setting('currency_code', APP_CURRENCY_CODE);
$minDeposit = get_setting('min_deposit', '100');
$maxDeposit = get_setting('max_deposit', '50000');
$razorpayKeyId = get_setting('razorpay_key_id', defined('RAZORPAY_KEY_ID') ? RAZORPAY_KEY_ID : '');
$razorpayKeySecret = get_setting('razorpay_key_secret', defined('RAZORPAY_KEY_SECRET') ? RAZORPAY_KEY_SECRET : '');
$maintenanceMode = get_setting('maintenance_mode', '0') === '1';
$allowRegistration = get_setting('allow_registration', '1') === '1';

$pageTitle = "System Settings - " . $siteName . " Admin";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto my-6 space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">System Settings</h1>
            <p class="text-xs text-slate-500 mt-1">Configure global application branding, payment gateways, and security controls</p>
        </div>
        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200/60">
            Database &amp; Config Synced
        </span>
    </div>

    <?= render_flash() ?>

    <!-- Interface Appearance & Theme Switcher (Zero Reload) -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-4">
        <div class="border-b border-slate-100 pb-4 flex items-center justify-between">
            <div>
                <h2 class="text-base font-extrabold text-slate-900 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-sm font-bold">🌓</span>
                    Interface Appearance &amp; Theme
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Choose your preferred visual theme. Updates instantly across your session without page reload.</p>
            </div>
            <span class="text-[11px] font-bold text-blue-600 bg-blue-50 px-2.5 py-1 rounded-full border border-blue-200/60">Real-Time Sync</span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1" role="radiogroup" aria-label="Theme selection">
            <!-- Light Mode Button -->
            <button type="button" 
                    data-theme-option="light"
                    onclick="window.ThemeManager.set('light')"
                    class="theme-card relative p-3.5 rounded-2xl border bg-slate-50 border-slate-200 text-left transition-all hover:border-blue-400 focus:outline-none cursor-pointer flex flex-col justify-between"
                    role="radio"
                    aria-checked="false">
                <div class="theme-active-ring absolute inset-0 rounded-2xl border-transparent pointer-events-none transition-all"></div>
                <div class="flex items-center justify-between">
                    <span class="text-xl">☀️</span>
                    <span class="theme-check-icon hidden text-blue-600 font-black text-xs">✓ Active</span>
                </div>
                <div class="mt-2.5">
                    <span class="text-xs font-black text-slate-800 block">Light Mode</span>
                    <span class="text-[11px] text-slate-400 font-medium block">Crisp daylight interface</span>
                </div>
            </button>

            <!-- Dark Mode Button -->
            <button type="button" 
                    data-theme-option="dark"
                    onclick="window.ThemeManager.set('dark')"
                    class="theme-card relative p-3.5 rounded-2xl border bg-slate-50 border-slate-200 text-left transition-all hover:border-blue-400 focus:outline-none cursor-pointer flex flex-col justify-between"
                    role="radio"
                    aria-checked="false">
                <div class="theme-active-ring absolute inset-0 rounded-2xl border-transparent pointer-events-none transition-all"></div>
                <div class="flex items-center justify-between">
                    <span class="text-xl">🌙</span>
                    <span class="theme-check-icon hidden text-blue-600 font-black text-xs">✓ Active</span>
                </div>
                <div class="mt-2.5">
                    <span class="text-xs font-black text-slate-800 block">Dark Mode</span>
                    <span class="text-[11px] text-slate-400 font-medium block">Deep slate night interface</span>
                </div>
            </button>

            <!-- System Default Button -->
            <button type="button" 
                    data-theme-option="system"
                    onclick="window.ThemeManager.set('system')"
                    class="theme-card relative p-3.5 rounded-2xl border bg-slate-50 border-slate-200 text-left transition-all hover:border-blue-400 focus:outline-none cursor-pointer flex flex-col justify-between"
                    role="radio"
                    aria-checked="false">
                <div class="theme-active-ring absolute inset-0 rounded-2xl border-transparent pointer-events-none transition-all"></div>
                <div class="flex items-center justify-between">
                    <span class="text-xl">💻</span>
                    <span class="theme-check-icon hidden text-blue-600 font-black text-xs">✓ Active</span>
                </div>
                <div class="mt-2.5">
                    <span class="text-xs font-black text-slate-800 block">System Default</span>
                    <span class="text-[11px] text-slate-400 font-medium block">Follow device setting</span>
                </div>
            </button>
        </div>
    </div>

    <form action="/admin/settings.php" method="POST" class="space-y-6">
        <?= CSRF::field() ?>

        <!-- 1. General & Branding -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
            <div class="border-b border-slate-100 pb-4">
                <h2 class="text-base font-extrabold text-slate-900 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-sm font-bold">🏷️</span>
                    General &amp; Branding
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Control the public identity and support channels of your platform</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Site Name <span class="text-rose-500">*</span></label>
                    <input type="text" name="site_name" value="<?= e($siteName) ?>" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                    <span class="text-[11px] text-slate-400 mt-1 block">Displayed on header, page titles, footer and notifications</span>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Site Tagline</label>
                    <input type="text" name="site_tagline" value="<?= e($siteTagline) ?>" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                    <span class="text-[11px] text-slate-400 mt-1 block">Subheading shown on landing and marketing pages</span>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1.5">Support &amp; Contact Email</label>
                <input type="email" name="support_email" value="<?= e($supportEmail) ?>" class="w-full sm:w-1/2 px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                <span class="text-[11px] text-slate-400 mt-1 block">Email address shown to users for tickets and questions</span>
            </div>
        </div>

        <!-- 2. Currency & Pricing -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
            <div class="border-b border-slate-100 pb-4">
                <h2 class="text-base font-extrabold text-slate-900 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm font-bold">💵</span>
                    Currency &amp; Deposit Limits
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Control panel currency symbol and wallet top-up bounds</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Currency Symbol <span class="text-rose-500">*</span></label>
                    <input type="text" name="currency" value="<?= e($currency) ?>" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                    <span class="text-[11px] text-slate-400 mt-1 block">E.g. ₹, $, €</span>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Currency ISO Code <span class="text-rose-500">*</span></label>
                    <input type="text" name="currency_code" value="<?= e($currencyCode) ?>" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900 uppercase focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                    <span class="text-[11px] text-slate-400 mt-1 block">E.g. INR, USD, EUR, GBP</span>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Minimum Deposit (<?= e($currency) ?>)</label>
                    <input type="number" step="0.01" min="1" name="min_deposit" value="<?= e($minDeposit) ?>" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                    <span class="text-[11px] text-slate-400 mt-1 block">Minimum wallet addition amount</span>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Maximum Deposit (<?= e($currency) ?>)</label>
                    <input type="number" step="0.01" min="10" name="max_deposit" value="<?= e($maxDeposit) ?>" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                    <span class="text-[11px] text-slate-400 mt-1 block">Maximum single wallet addition amount</span>
                </div>
            </div>
        </div>

        <!-- 3. Payment Gateway Credentials -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
            <div class="border-b border-slate-100 pb-4">
                <h2 class="text-base font-extrabold text-slate-900 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-sm font-bold">💳</span>
                    Razorpay Gateway Credentials
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Automated UPI, QR code, Card and Net Banking payment processing</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Razorpay Key ID</label>
                    <input type="text" name="razorpay_key_id" value="<?= e($razorpayKeyId) ?>" placeholder="rzp_test_..." class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Razorpay Key Secret</label>
                    <input type="password" name="razorpay_key_secret" value="<?= e($razorpayKeySecret) ?>" placeholder="••••••••••••" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                </div>
            </div>
        </div>

        <!-- 4. Security & Access Control -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
            <div class="border-b border-slate-100 pb-4">
                <h2 class="text-base font-extrabold text-slate-900 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-sm font-bold">🛡️</span>
                    Access &amp; Security Controls
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Maintenance mode and public user registrations</p>
            </div>

            <div class="space-y-4">
                <label class="flex items-start gap-3 p-4 rounded-2xl bg-slate-50 border border-slate-200/70 cursor-pointer hover:bg-slate-100/60 transition-colors">
                    <input type="checkbox" name="allow_registration" value="1" <?= $allowRegistration ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 text-blue-600 rounded border-slate-300 focus:ring-blue-500">
                    <div>
                        <span class="text-xs font-bold text-slate-900 block">Allow New User Registrations</span>
                        <span class="text-[11px] text-slate-500 block mt-0.5">When checked, guests can register for a new account. When unchecked, registration is closed.</span>
                    </div>
                </label>

                <label class="flex items-start gap-3 p-4 rounded-2xl bg-amber-50/50 border border-amber-200/70 cursor-pointer hover:bg-amber-100/40 transition-colors">
                    <input type="checkbox" name="maintenance_mode" value="1" <?= $maintenanceMode ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 text-amber-600 rounded border-slate-300 focus:ring-amber-500">
                    <div>
                        <span class="text-xs font-bold text-amber-900 block">Enable Maintenance Mode</span>
                        <span class="text-[11px] text-amber-700 block mt-0.5">Restricts normal user access and shows maintenance banner. Admin accounts continue to have full access.</span>
                    </div>
                </label>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 pt-4">
            <a href="/admin/dashboard.php" class="px-5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50 transition-colors">
                Cancel
            </a>
            <button type="submit" class="px-7 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-md shadow-blue-500/25 transition-all">
                Save System Settings
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
