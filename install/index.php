<?php
/**
 * Web-Based Installation Wizard
 * SMM Panel - PHP 8+
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$lockFile = __DIR__ . '/installed.lock';
$isLocked = file_exists($lockFile);

if ($isLocked) {
    ?>
    <!DOCTYPE html>
    <html lang="en" class="h-full bg-slate-50">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Installer Locked - SMM Panel</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
        <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
    </head>
    <body class="min-h-full flex items-center justify-center p-4 bg-slate-50 text-slate-800">
        <div class="w-full max-w-md bg-white rounded-3xl p-8 border border-slate-200/80 shadow-2xl text-center">
            <div class="w-16 h-16 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center mx-auto mb-4 text-2xl">
                🔒
            </div>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Installation Already Completed</h1>
            <p class="text-xs text-slate-500 mt-2 leading-relaxed">
                For security reasons, the installer is locked. If you wish to reinstall, delete the <code class="bg-slate-100 px-1 py-0.5 rounded text-slate-800 font-mono">install/installed.lock</code> file from your server.
            </p>
            <div class="mt-6 flex items-center gap-3">
                <a href="/" class="flex-1 py-3 px-4 rounded-xl border border-slate-200 hover:bg-slate-50 text-xs font-bold text-slate-700 transition-all">Visit Website</a>
                <a href="/admin/dashboard.php" class="flex-1 py-3 px-4 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold shadow-md shadow-blue-500/20 transition-all">Admin Login</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$step = (int)($_GET['step'] ?? 1);
$installError = $_SESSION['install_error'] ?? null;
unset($_SESSION['install_error']);

// Requirement Checks
$phpVersionOk = version_compare(PHP_VERSION, '8.1.0', '>=');
$pdoOk = extension_loaded('pdo');
$pdoMysqlOk = extension_loaded('pdo_mysql');
$sessionOk = function_exists('session_start');
$curlOk = extension_loaded('curl');
$mbstringOk = extension_loaded('mbstring');
$jsonOk = extension_loaded('json');
$writableConfig = is_writable(dirname(__DIR__) . '/includes');
$writableInstall = is_writable(__DIR__);

$allRequirementsPass = $phpVersionOk && $pdoOk && $sessionOk && $jsonOk && $writableConfig;
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMM Panel Installation Wizard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="min-h-full flex items-center justify-center p-4 bg-slate-50 text-slate-800">
    <div class="w-full max-w-2xl bg-white rounded-3xl p-6 sm:p-10 border border-slate-200/80 shadow-2xl">
        <!-- Wizard Header -->
        <div class="flex items-center justify-between pb-6 border-b border-slate-100">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center font-black shadow-md shadow-blue-500/20">
                    ⚡
                </div>
                <div>
                    <h1 class="text-xl font-extrabold text-slate-900 tracking-tight">SMM Panel Installation</h1>
                    <span class="text-xs text-slate-400">Step <?= $step ?> of 3</span>
                </div>
            </div>
            <!-- Steps Progress Dots -->
            <div class="flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-full <?= $step >= 1 ? 'bg-blue-600' : 'bg-slate-200' ?>"></span>
                <span class="w-3 h-3 rounded-full <?= $step >= 2 ? 'bg-blue-600' : 'bg-slate-200' ?>"></span>
                <span class="w-3 h-3 rounded-full <?= $step >= 3 ? 'bg-blue-600' : 'bg-slate-200' ?>"></span>
            </div>
        </div>

        <?php if ($installError): ?>
            <div class="my-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-bold">
                <?= htmlspecialchars($installError) ?>
            </div>
        <?php endif; ?>

        <!-- STEP 1: WELCOME -->
        <?php if ($step === 1): ?>
            <div class="py-8 text-center space-y-4">
                <div class="w-20 h-20 bg-blue-50 text-blue-600 rounded-3xl flex items-center justify-center mx-auto text-3xl shadow-sm">
                    🚀
                </div>
                <h2 class="text-2xl font-extrabold text-slate-900">Welcome to the Installation Wizard</h2>
                <p class="text-xs sm:text-sm text-slate-500 max-w-md mx-auto leading-relaxed">
                    This setup assistant will guide you through system verification, database initialization, and administrator provisioning in a few simple clicks.
                </p>
                <div class="pt-6">
                    <a href="/install/index.php?step=2" class="inline-flex items-center gap-2 px-8 py-4 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-sm rounded-2xl shadow-xl shadow-blue-500/25 transition-all">
                        <span>Start Installation</span>
                        <span>&rarr;</span>
                    </a>
                </div>
            </div>

        <!-- STEP 2: SYSTEM REQUIREMENTS -->
        <?php elseif ($step === 2): ?>
            <div class="py-6 space-y-6">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900">System Compatibility Check</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Verifying that your hosting server satisfies the prerequisites.</p>
                </div>

                <div class="divide-y divide-slate-100 bg-slate-50/70 p-4 rounded-2xl border border-slate-200/60 text-xs">
                    <div class="py-3 flex items-center justify-between">
                        <span class="font-semibold text-slate-700">PHP Version (>= 8.1 Required)</span>
                        <span class="font-bold flex items-center gap-1 <?= $phpVersionOk ? 'text-emerald-600' : 'text-rose-600' ?>">
                            <?= PHP_VERSION ?> <?= $phpVersionOk ? '✓' : '✕' ?>
                        </span>
                    </div>
                    <div class="py-3 flex items-center justify-between">
                        <span class="font-semibold text-slate-700">PDO Database Abstraction</span>
                        <span class="font-bold flex items-center gap-1 <?= $pdoOk ? 'text-emerald-600' : 'text-rose-600' ?>">
                            <?= $pdoOk ? 'Available ✓' : 'Missing ✕' ?>
                        </span>
                    </div>
                    <div class="py-3 flex items-center justify-between">
                        <span class="font-semibold text-slate-700">PDO MySQL Driver</span>
                        <span class="font-bold flex items-center gap-1 <?= $pdoMysqlOk ? 'text-emerald-600' : 'text-amber-600' ?>">
                            <?= $pdoMysqlOk ? 'Installed ✓' : 'Optional (Fallback Active)' ?>
                        </span>
                    </div>
                    <div class="py-3 flex items-center justify-between">
                        <span class="font-semibold text-slate-700">PHP Session Support</span>
                        <span class="font-bold flex items-center gap-1 <?= $sessionOk ? 'text-emerald-600' : 'text-rose-600' ?>">
                            <?= $sessionOk ? 'Enabled ✓' : 'Disabled ✕' ?>
                        </span>
                    </div>
                    <div class="py-3 flex items-center justify-between">
                        <span class="font-semibold text-slate-700">cURL & Mbstring Extensions</span>
                        <span class="font-bold flex items-center gap-1 <?= ($curlOk && $mbstringOk) ? 'text-emerald-600' : 'text-amber-600' ?>">
                            <?= ($curlOk && $mbstringOk) ? 'Installed ✓' : 'Partial' ?>
                        </span>
                    </div>
                    <div class="py-3 flex items-center justify-between">
                        <span class="font-semibold text-slate-700">Writable Storage (includes/ & install/)</span>
                        <span class="font-bold flex items-center gap-1 <?= $writableConfig ? 'text-emerald-600' : 'text-rose-600' ?>">
                            <?= $writableConfig ? 'Writable ✓' : 'Read-Only ✕' ?>
                        </span>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-2">
                    <a href="/install/index.php?step=1" class="text-xs font-bold text-slate-500 hover:text-slate-800">&larr; Back</a>
                    <?php if ($allRequirementsPass): ?>
                        <a href="/install/index.php?step=3" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs rounded-xl shadow-md transition-all">
                            Next: Database & Admin Setup &rarr;
                        </a>
                    <?php else: ?>
                        <span class="text-xs text-rose-600 font-bold">Please resolve failed requirements above before continuing.</span>
                    <?php endif; ?>
                </div>
            </div>

        <!-- STEP 3: DATABASE CONFIGURATION & ADMIN CREATION -->
        <?php elseif ($step === 3): ?>
            <form action="/install/process.php" method="POST" class="py-6 space-y-6">
                <!-- Section 1: Database Settings -->
                <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-4">
                    <h2 class="text-sm font-extrabold text-slate-900 flex items-center gap-2">
                        <span>🗄️</span> 1. MySQL / MariaDB Database Configuration
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Database Host</label>
                            <input type="text" name="db_host" id="db_host" value="localhost" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Database Port</label>
                            <input type="text" name="db_port" id="db_port" value="3306" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Database Name</label>
                            <input type="text" name="db_name" id="db_name" value="smm_panel" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Database Username</label>
                            <input type="text" name="db_user" id="db_user" value="root" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-slate-700 mb-1">Database Password</label>
                            <input type="password" name="db_pass" id="db_pass" placeholder="••••••••" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl">
                        </div>
                    </div>
                    <!-- Test Connection Button -->
                    <div class="pt-1">
                        <button type="button" id="btnTestDb" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-800 rounded-xl text-xs font-bold transition-all">
                            Test Database Connection
                        </button>
                        <div id="dbTestResult"></div>
                    </div>
                </div>

                <!-- Section 2: Admin Account -->
                <div class="p-5 rounded-2xl bg-blue-50/50 border border-blue-200 space-y-4">
                    <h2 class="text-sm font-extrabold text-blue-950 flex items-center gap-2">
                        <span>🛡️</span> 2. Super Administrator Account
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Admin Full Name</label>
                            <input type="text" name="admin_name" value="Administrator" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Admin Email Address</label>
                            <input type="email" name="admin_email" value="admin@smmpanel.local" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-slate-700 mb-1">Admin Username</label>
                            <input type="text" name="admin_username" value="admin" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Admin Password</label>
                            <input type="password" name="admin_password" value="password123" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Confirm Password</label>
                            <input type="password" name="admin_password_confirm" value="password123" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl">
                        </div>
                    </div>
                </div>

                <!-- Section 3: Website Settings -->
                <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-4">
                    <h2 class="text-sm font-extrabold text-slate-900 flex items-center gap-2">
                        <span>🌐</span> 3. Website Configuration
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Website Name</label>
                            <input type="text" name="site_name" value="SMM Panel" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Timezone</label>
                            <input type="text" name="timezone" value="Asia/Kolkata" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-slate-700 mb-1">Website URL</label>
                            <input type="url" name="site_url" value="http://localhost:3000" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl">
                            <span class="text-[11px] text-slate-400 mt-1 block">Admin URL will be accessible at: <code>/admin</code></span>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="flex items-center justify-between pt-2">
                    <a href="/install/index.php?step=2" class="text-xs font-bold text-slate-500 hover:text-slate-800">&larr; Back</a>
                    <button type="submit" id="btnDbNext" class="px-8 py-4 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs rounded-xl shadow-xl shadow-blue-500/25 transition-all">
                        Install SMM Panel Now &rarr;
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <script src="/install/assets/installer.js"></script>
</body>
</html>
