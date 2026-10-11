<?php
/**
 * User Profile & Settings
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

$tab = $_GET['tab'] ?? 'account';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $username = trim($_POST['username'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (empty($username)) {
            $error = "Name cannot be empty.";
        } else {
            $stmt = $db->prepare("UPDATE users SET username = :username, phone = :phone WHERE id = :id");
            $stmt->execute(['username' => $username, 'phone' => $phone, 'id' => $user['id']]);
            set_flash('success', 'Profile updated successfully!');
            redirect('/user/profile.php');
        }
    } elseif ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $stmt = $db->prepare("SELECT password FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $user['id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($current, $row['password'])) {
            $error = "Incorrect current password.";
        } elseif (strlen($new) < 8) {
            $error = "New password must be at least 8 characters long.";
        } elseif ($new !== $confirm) {
            $error = "New passwords do not match.";
        } else {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = :pwd WHERE id = :id");
            $stmt->execute(['pwd' => $hashed, 'id' => $user['id']]);
            set_flash('success', 'Password updated successfully!');
            redirect('/user/profile.php?tab=password');
        }
    } elseif ($action === 'regenerate_api') {
        $newApiKey = 'usr_' . bin2hex(random_bytes(12));
        $stmt = $db->prepare("UPDATE users SET api_key = :key WHERE id = :id");
        $stmt->execute(['key' => $newApiKey, 'id' => $user['id']]);
        set_flash('success', 'New API Key generated successfully!');
        redirect('/user/profile.php?tab=api');
    }
}

$pageTitle = "Profile - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto my-6 space-y-6">
    <div class="flex items-center justify-between">
        <a href="/user/dashboard.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Back to Dashboard</span>
        </a>
    </div>

    <!-- Main Profile Card -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-slate-100">
            <div>
                <h2 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">Profile & Account Settings</h2>
                <p class="text-xs text-slate-400 mt-0.5">Manage your personal credentials, security, and developer API key</p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold flex items-center gap-2">
                <span>⚠️</span>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 pt-2">
            <!-- Left Tabs Navigation -->
            <div class="space-y-1.5">
                <a href="/user/profile.php?tab=account" class="flex items-center gap-3 px-4 py-3 rounded-2xl text-xs font-extrabold transition-all <?= $tab === 'account' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/25 scale-[1.01]' : 'text-slate-600 hover:bg-slate-50' ?>">
                    <span>👤</span> Account Details
                </a>
                <a href="/user/profile.php?tab=appearance" class="flex items-center gap-3 px-4 py-3 rounded-2xl text-xs font-extrabold transition-all <?= $tab === 'appearance' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/25 scale-[1.01]' : 'text-slate-600 hover:bg-slate-50' ?>">
                    <span>🌓</span> Theme &amp; Appearance
                </a>
                <a href="/user/profile.php?tab=password" class="flex items-center gap-3 px-4 py-3 rounded-2xl text-xs font-extrabold transition-all <?= $tab === 'password' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/25 scale-[1.01]' : 'text-slate-600 hover:bg-slate-50' ?>">
                    <span>🔒</span> Security &amp; Password
                </a>
                <a href="/user/referrals.php" class="flex items-center gap-3 px-4 py-3 rounded-2xl text-xs font-extrabold transition-all text-slate-600 hover:bg-slate-50">
                    <span>🎁</span> Refer &amp; Earn Program
                </a>
                <a href="/user/api.php" class="flex items-center gap-3 px-4 py-3 rounded-2xl text-xs font-extrabold transition-all text-slate-600 hover:bg-slate-50">
                    <span>🔌</span> Full API Documentation
                </a>
                <a href="/user/profile.php?tab=api" class="flex items-center gap-3 px-4 py-3 rounded-2xl text-xs font-extrabold transition-all <?= $tab === 'api' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/25 scale-[1.01]' : 'text-slate-600 hover:bg-slate-50' ?>">
                    <span>⚡</span> Developer API Key
                </a>
            </div>

            <!-- Right Content Panels -->
            <div class="md:col-span-2">
                <?php if ($tab === 'account'): ?>
                    <!-- Avatar & User ID header -->
                    <div class="flex items-center gap-4 p-5 bg-gradient-to-br from-blue-50/50 via-white to-indigo-50/40 rounded-3xl border border-blue-200/80 mb-6 shadow-xs">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 flex items-center justify-center text-white text-2xl font-black shadow-md shadow-blue-500/25 border-2 border-white">
                            <?= strtoupper(substr($user['username'], 0, 1)) ?>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-base font-black text-slate-900"><?= e($user['username']) ?></h3>
                                <span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-600 border border-emerald-200/80 text-[10px] font-extrabold">Active</span>
                            </div>
                            <span class="text-xs text-slate-400 mt-0.5 block">Client ID: <strong class="text-slate-700 font-mono">#<?= (int)$user['id'] ?></strong></span>
                        </div>
                    </div>

                    <form action="/user/profile.php" method="POST" class="space-y-4">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="update_profile">

                        <div>
                            <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Username / Handle</label>
                            <input type="text" name="username" value="<?= e($user['username']) ?>" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-semibold focus:ring-3 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                        </div>

                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider">Email Address</label>
                                <span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-600 text-[10px] font-bold">Verified</span>
                            </div>
                            <input type="email" value="<?= e($user['email']) ?>" disabled class="w-full px-4 py-3 bg-slate-100 border border-slate-200 rounded-2xl text-xs font-semibold text-slate-500 cursor-not-allowed">
                        </div>

                        <div>
                            <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Phone Number (Optional)</label>
                            <input type="tel" name="phone" value="<?= e($user['phone'] ?? '+91 98765 43210') ?>" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-semibold focus:ring-3 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                        </div>

                        <div>
                            <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Member Since</label>
                            <input type="text" value="<?= date('d M Y, h:i A', strtotime($user['created_at'])) ?>" disabled class="w-full px-4 py-3 bg-slate-100 border border-slate-200 rounded-2xl text-xs font-semibold text-slate-500 cursor-not-allowed">
                        </div>

                        <button type="submit" class="w-full py-4 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-black text-xs rounded-2xl shadow-xl shadow-blue-500/25 transition-all mt-4 cursor-pointer">
                            Save Profile Changes
                        </button>
                    </form>

                    <!-- Quick Theme Switcher Card inside Account Tab -->
                    <div class="mt-8 pt-6 border-t border-slate-100 space-y-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-xs font-black text-slate-900 uppercase tracking-wider">Interface Theme</h3>
                                <p class="text-[11px] text-slate-400">Choose between light, dark, or device system appearance</p>
                            </div>
                            <span class="text-[11px] font-bold text-blue-600">Zero Reload</span>
                        </div>

                        <div class="grid grid-cols-3 gap-2.5 pt-1" role="radiogroup" aria-label="Theme selection">
                            <!-- 1. Light Mode -->
                            <button type="button" 
                                    data-theme-option="light"
                                    onclick="window.ThemeManager.set('light')"
                                    class="theme-card relative p-3 rounded-2xl border bg-slate-50 border-slate-200 text-left transition-all hover:border-blue-300 focus:outline-none cursor-pointer flex flex-col justify-between"
                                    role="radio"
                                    aria-checked="false">
                                <div class="theme-active-ring absolute inset-0 rounded-2xl border-transparent pointer-events-none transition-all"></div>
                                <div class="flex items-center justify-between">
                                    <span class="text-lg">☀️</span>
                                    <span class="theme-check-icon hidden text-blue-600 font-black text-xs">✓</span>
                                </div>
                                <div class="mt-2">
                                    <span class="text-xs font-black text-slate-800 block">Light</span>
                                    <span class="text-[10px] text-slate-400 font-medium block">Clean daylight</span>
                                </div>
                            </button>

                            <!-- 2. Dark Mode -->
                            <button type="button" 
                                    data-theme-option="dark"
                                    onclick="window.ThemeManager.set('dark')"
                                    class="theme-card relative p-3 rounded-2xl border bg-slate-50 border-slate-200 text-left transition-all hover:border-blue-300 focus:outline-none cursor-pointer flex flex-col justify-between"
                                    role="radio"
                                    aria-checked="false">
                                <div class="theme-active-ring absolute inset-0 rounded-2xl border-transparent pointer-events-none transition-all"></div>
                                <div class="flex items-center justify-between">
                                    <span class="text-lg">🌙</span>
                                    <span class="theme-check-icon hidden text-blue-600 font-black text-xs">✓</span>
                                </div>
                                <div class="mt-2">
                                    <span class="text-xs font-black text-slate-800 block">Dark</span>
                                    <span class="text-[10px] text-slate-400 font-medium block">Deep slate night</span>
                                </div>
                            </button>

                            <!-- 3. System Default -->
                            <button type="button" 
                                    data-theme-option="system"
                                    onclick="window.ThemeManager.set('system')"
                                    class="theme-card relative p-3 rounded-2xl border bg-slate-50 border-slate-200 text-left transition-all hover:border-blue-300 focus:outline-none cursor-pointer flex flex-col justify-between"
                                    role="radio"
                                    aria-checked="false">
                                <div class="theme-active-ring absolute inset-0 rounded-2xl border-transparent pointer-events-none transition-all"></div>
                                <div class="flex items-center justify-between">
                                    <span class="text-lg">💻</span>
                                    <span class="theme-check-icon hidden text-blue-600 font-black text-xs">✓</span>
                                </div>
                                <div class="mt-2">
                                    <span class="text-xs font-black text-slate-800 block">System</span>
                                    <span class="text-[10px] text-slate-400 font-medium block">Follows OS</span>
                                </div>
                            </button>
                        </div>
                    </div>

                <?php elseif ($tab === 'appearance'): ?>
                    <!-- Full Dedicated Appearance & Theme Studio -->
                    <div class="space-y-6">
                        <div>
                            <h3 class="text-sm font-black text-slate-900 tracking-tight">Theme &amp; Display Preferences</h3>
                            <p class="text-xs text-slate-400 mt-0.5">Customize your visual interface experience. Your selection is automatically saved and synced instantly.</p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4" role="radiogroup" aria-label="Theme selection studio">
                            <!-- 1. Light Mode Card -->
                            <div data-theme-option="light"
                                 onclick="window.ThemeManager.set('light')"
                                 class="theme-card relative p-5 rounded-3xl border bg-gradient-to-b from-white to-slate-50 border-slate-200 hover:border-blue-400 transition-all cursor-pointer shadow-xs group flex flex-col justify-between"
                                 role="radio"
                                 aria-checked="false">
                                <div class="theme-active-ring absolute inset-0 rounded-3xl border-transparent pointer-events-none transition-all"></div>
                                <div class="flex items-start justify-between">
                                    <div class="w-10 h-10 rounded-2xl bg-amber-50 border border-amber-200/60 text-amber-600 flex items-center justify-center text-xl shadow-xs">
                                        ☀️
                                    </div>
                                    <span class="theme-check-icon hidden px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 font-extrabold text-[10px] border border-blue-200/80">Active</span>
                                </div>
                                <div class="mt-4 space-y-1">
                                    <h4 class="text-sm font-black text-slate-900">Light Mode</h4>
                                    <p class="text-[11px] text-slate-500 leading-relaxed">Crisp daylight palette with bright surfaces, high-contrast typography, and vibrant accents.</p>
                                </div>
                                <div class="mt-4 pt-3 border-t border-slate-100 flex items-center gap-1.5 text-[11px] font-bold text-blue-600">
                                    <span>Select Light</span> &rarr;
                                </div>
                            </div>

                            <!-- 2. Dark Mode Card -->
                            <div data-theme-option="dark"
                                 onclick="window.ThemeManager.set('dark')"
                                 class="theme-card relative p-5 rounded-3xl border bg-gradient-to-b from-slate-900 to-slate-950 border-slate-800 hover:border-blue-500 transition-all cursor-pointer shadow-xs group flex flex-col justify-between text-white"
                                 role="radio"
                                 aria-checked="false">
                                <div class="theme-active-ring absolute inset-0 rounded-3xl border-transparent pointer-events-none transition-all"></div>
                                <div class="flex items-start justify-between">
                                    <div class="w-10 h-10 rounded-2xl bg-indigo-950 border border-indigo-800/60 text-indigo-400 flex items-center justify-center text-xl shadow-xs">
                                        🌙
                                    </div>
                                    <span class="theme-check-icon hidden px-2 py-0.5 rounded-full bg-blue-600 text-white font-extrabold text-[10px] shadow-xs">Active</span>
                                </div>
                                <div class="mt-4 space-y-1">
                                    <h4 class="text-sm font-black text-white">Dark Mode</h4>
                                    <p class="text-[11px] text-slate-400 leading-relaxed">Deep midnight slate with relaxed contrast, reduced eye fatigue in low light, and restrained glow.</p>
                                </div>
                                <div class="mt-4 pt-3 border-t border-slate-800 flex items-center gap-1.5 text-[11px] font-bold text-blue-400">
                                    <span>Select Dark</span> &rarr;
                                </div>
                            </div>

                            <!-- 3. System Default Card -->
                            <div data-theme-option="system"
                                 onclick="window.ThemeManager.set('system')"
                                 class="theme-card relative p-5 rounded-3xl border bg-gradient-to-b from-slate-50 to-slate-100/60 border-slate-200 hover:border-blue-400 transition-all cursor-pointer shadow-xs group flex flex-col justify-between"
                                 role="radio"
                                 aria-checked="false">
                                <div class="theme-active-ring absolute inset-0 rounded-3xl border-transparent pointer-events-none transition-all"></div>
                                <div class="flex items-start justify-between">
                                    <div class="w-10 h-10 rounded-2xl bg-blue-50 border border-blue-200/60 text-blue-600 flex items-center justify-center text-xl shadow-xs">
                                        💻
                                    </div>
                                    <span class="theme-check-icon hidden px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 font-extrabold text-[10px] border border-blue-200/80">Active</span>
                                </div>
                                <div class="mt-4 space-y-1">
                                    <h4 class="text-sm font-black text-slate-900">System Default</h4>
                                    <p class="text-[11px] text-slate-500 leading-relaxed">Automatically matches your operating system appearance preference (Windows, macOS, iOS, Android).</p>
                                </div>
                                <div class="mt-4 pt-3 border-t border-slate-100 flex items-center gap-1.5 text-[11px] font-bold text-blue-600">
                                    <span>Follow System</span> &rarr;
                                </div>
                            </div>
                        </div>

                        <div class="p-4 rounded-2xl bg-blue-50/70 border border-blue-200/60 flex items-center gap-3 text-xs text-blue-900 leading-relaxed">
                            <span class="text-base">⚡</span>
                            <span><strong>Instant Switching:</strong> Changing the theme updates the interface immediately without reloading the page and persists across all your visits.</span>
                        </div>
                    </div>

                <?php elseif ($tab === 'password'): ?>
                    <form action="/user/profile.php?tab=password" method="POST" class="space-y-4">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="change_password">

                        <div>
                            <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Current Password</label>
                            <input type="password" name="current_password" required placeholder="Enter current password" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-semibold focus:ring-3 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                        </div>

                        <div>
                            <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">New Password</label>
                            <input type="password" name="new_password" required minlength="8" placeholder="Minimum 8 characters" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-semibold focus:ring-3 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                        </div>

                        <div>
                            <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-1.5">Confirm New Password</label>
                            <input type="password" name="confirm_password" required minlength="8" placeholder="Re-enter new password" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-semibold focus:ring-3 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                        </div>

                        <button type="submit" class="w-full py-4 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-black text-xs rounded-2xl shadow-xl shadow-blue-500/25 transition-all mt-4 cursor-pointer">
                            Update Secure Password
                        </button>
                    </form>

                <?php elseif ($tab === 'api'): ?>
                    <div class="space-y-5">
                        <div class="p-5 bg-gradient-to-br from-blue-50/50 via-white to-indigo-50/40 rounded-3xl border border-blue-200/80 shadow-xs space-y-3">
                            <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider">Your Personal API Key</label>
                            <div class="flex items-center gap-2">
                                <input type="text" readonly value="<?= e($user['api_key']) ?>" id="apiKeyField" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-2xl text-xs font-mono font-bold text-slate-800 select-all">
                                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('apiKeyField').value); notify.success('API Key copied to clipboard!');" class="px-5 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-2xl text-xs font-black shadow-md shadow-blue-500/25 transition-all cursor-pointer whitespace-nowrap">
                                    Copy
                                </button>
                            </div>
                        </div>

                        <p class="text-xs text-slate-500 leading-relaxed">
                            Use this key to automate orders through the SMM API v2 endpoint at <code class="font-mono text-blue-600 bg-blue-50 px-2 py-0.5 rounded-lg border border-blue-100"><?= e(APP_URL) ?>/api/orders.php</code>.
                        </p>

                        <form action="/user/profile.php?tab=api" method="POST" onsubmit="return confirm('Regenerating will invalidate your existing API key. Any external bots or tools using the old key will stop working. Continue?');">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="regenerate_api">
                            <button type="submit" class="px-5 py-3 bg-rose-50 border border-rose-200 text-rose-600 hover:bg-rose-100 font-black text-xs rounded-2xl transition-colors cursor-pointer">
                                🔄 Regenerate API Key
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
