<?php
/**
 * User Login Page
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

if (Auth::check()) {
    redirect('/user/dashboard.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = "Please provide both email and password.";
    } elseif (Auth::login($email, $password)) {
        if (is_maintenance_mode() && !Auth::isAdmin()) {
            Auth::logout();
            $error = "The system is currently undergoing scheduled maintenance. Only administrators may log in.";
        } else {
            $_SESSION['show_welcome_intro'] = true;
            set_flash('success', 'Welcome back!');
            $redirectUrl = Auth::isAdmin() ? '/admin/dashboard.php' : '/user/dashboard.php';
            redirect($redirectUrl);
        }
    } else {
        $error = "Invalid email or password. Please try again.";
    }
}

$pageTitle = "Login - " . app_name();
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-4xl mx-auto my-8">
    <div class="bg-white rounded-3xl shadow-xl border border-slate-200/80 overflow-hidden grid grid-cols-1 md:grid-cols-2">
        <!-- Left Banner -->
        <div class="bg-gradient-to-br from-blue-600 via-indigo-600 to-purple-700 p-8 text-white flex flex-col justify-between relative overflow-hidden">
            <div class="relative z-10">
                <div class="flex items-center gap-2 mb-8">
                    <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" /></svg>
                    </div>
                    <span class="font-bold text-lg"><?= e(app_name()) ?></span>
                </div>
                <h2 class="text-3xl font-extrabold leading-tight">
                    More Followers<br>
                    More Engagement<br>
                    More Success
                </h2>
                <p class="mt-4 text-xs text-blue-100 max-w-xs">
                    Accelerate your social growth with lightning-fast delivery and top-tier retention services.
                </p>
            </div>
            <div class="relative z-10 pt-8 flex items-center gap-2 text-xs text-blue-200">
                <span>⚡ Instant Start</span> • <span>🔒 Bank-grade Security</span>
            </div>
        </div>

        <!-- Right Form -->
        <div class="p-8 sm:p-10 flex flex-col justify-center">
            <!-- Tabs: Login / Register -->
            <div class="flex rounded-xl bg-slate-100 p-1 mb-8">
                <a href="/login.php" class="flex-1 text-center py-2 text-xs font-bold rounded-lg bg-white text-blue-600 shadow-sm">Login</a>
                <a href="/register.php" class="flex-1 text-center py-2 text-xs font-bold rounded-lg text-slate-600 hover:text-slate-900">Register</a>
            </div>

            <h3 class="text-2xl font-extrabold text-slate-900">Welcome Back!</h3>
            <p class="text-xs text-slate-500 mt-1">Sign in to your account to continue.</p>

            <?php if (is_maintenance_mode()): ?>
                <div class="mt-4 p-3.5 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 text-xs font-semibold flex items-center gap-2.5">
                    <span class="text-base">⚠️</span>
                    <span><strong>Maintenance Mode Active:</strong> System access is currently restricted to administrators.</span>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mt-4 p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-600 text-xs font-semibold">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form action="/login.php" method="POST" class="mt-6 space-y-4">
                <?= CSRF::field() ?>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Email Address</label>
                    <div class="relative">
                        <input type="email" name="email" required placeholder="name@example.com" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs font-bold text-slate-700">Password</label>
                    </div>
                    <div class="relative">
                        <input type="password" name="password" required placeholder="••••••••" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                    </div>
                </div>

                <div class="flex items-center">
                    <input type="checkbox" id="remember" name="remember" class="w-4 h-4 text-blue-600 rounded border-slate-300 focus:ring-blue-500">
                    <label for="remember" class="ml-2 text-xs font-semibold text-slate-600">Remember Me</label>
                </div>

                <button type="submit" class="w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm rounded-xl shadow-lg shadow-blue-500/25 transition-all">
                    Login &rarr;
                </button>
            </form>

            <p class="mt-6 text-center text-xs text-slate-500">
                Don't have an account? <a href="/register.php" class="font-bold text-blue-600 hover:underline">Register</a>
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
