<?php
/**
 * Installation Success Page
 * SMM Panel - PHP 8+
 */

declare(strict_types=1);

$lockFile = __DIR__ . '/installed.lock';
$lockInfo = file_exists($lockFile) ? json_decode(file_get_contents($lockFile), true) : null;
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation Complete - SMM Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="min-h-full flex items-center justify-center p-4 bg-slate-50 text-slate-800">
    <div class="w-full max-w-xl bg-white rounded-3xl p-8 sm:p-10 border border-slate-200/80 shadow-2xl text-center">
        <!-- Success Icon -->
        <div class="w-20 h-20 bg-emerald-50 text-emerald-600 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-md shadow-emerald-500/10">
            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
            </svg>
        </div>

        <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Installation Completed Successfully</h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-2 max-w-md mx-auto leading-relaxed">
            Your SMM Panel is fully configured and ready for live operation. The database schema and administrator credentials have been initialized.
        </p>

        <!-- Lock Notification -->
        <div class="my-6 p-4 rounded-2xl bg-slate-50 border border-slate-200 text-xs text-slate-600 text-left space-y-1">
            <div class="flex items-center gap-2 font-bold text-slate-800">
                <span>🔒</span>
                <span>Security Notice: Installer Locked</span>
            </div>
            <p class="text-[11px] text-slate-500">
                The file <code class="bg-slate-200 px-1 py-0.5 rounded text-slate-700">install/installed.lock</code> has been created. Reinstallation is permanently disabled.
            </p>
            <?php if ($lockInfo && !empty($lockInfo['admin_username'])): ?>
                <div class="pt-2 text-[11px] text-slate-600 border-t border-slate-200/60 mt-2">
                    Admin Username: <strong class="text-slate-900"><?= htmlspecialchars($lockInfo['admin_username']) ?></strong>
                </div>
            <?php endif; ?>
        </div>

        <!-- Action Links -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
            <a href="/" class="py-3.5 px-6 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-800 font-extrabold text-xs shadow-sm transition-all flex items-center justify-center gap-2">
                <span>Visit Website</span>
                <span>&rarr;</span>
            </a>
            <a href="/admin/dashboard.php" class="py-3.5 px-6 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs shadow-lg shadow-blue-500/25 transition-all flex items-center justify-center gap-2">
                <span>Admin Login</span>
                <span>&rarr;</span>
            </a>
        </div>
    </div>
</body>
</html>
