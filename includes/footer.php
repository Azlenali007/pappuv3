<?php
/**
 * Shared Footer Template
 * SMM Panel - PHP 8+
 */

declare(strict_types=1);

$currentScript = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '');
$isAdminPage = str_starts_with($currentScript, '/admin');
$isUserPage = str_starts_with($currentScript, '/user');
$currentUser = Auth::user();
$isServicesPage = str_contains($currentScript, 'services.php') || str_contains($_SERVER['REQUEST_URI'] ?? '', 'services.php');
$showUserDock = ($currentUser !== null) && ($isUserPage || $isServicesPage);
?>
        </div>
    </main>

    <?php if ($isAdminPage): ?>
            <!-- Admin Bottom Footer -->
            <footer class="mt-auto bg-white border-t border-slate-200/80 py-4 px-8 text-xs text-slate-500 flex items-center justify-between">
                <span>&copy; <?= date('Y') ?> <?= e(app_name()) ?>. Administrator Console.</span>
                <span class="font-mono text-[11px] text-slate-400">PHP 8.3+ • PDO Secure Engine</span>
            </footer>
        </div>
    </div>

    <?php elseif ($showUserDock): ?>
        <!-- User Floating Glass Bottom Dock (Dashboard, Services, New Order, Orders, Profile) -->
        <!-- Fixed to viewport with floating island layout, blur backdrop and safe-area padding -->
        <nav class="fixed bottom-3 sm:bottom-4 left-3 right-3 max-w-lg mx-auto z-40 bg-white/90 backdrop-blur-2xl border border-white/90 shadow-[0_20px_50px_-10px_rgba(15,23,42,0.2)] rounded-3xl p-1.5 transition-all" 
             style="padding-bottom: max(0.4rem, env(safe-area-inset-bottom, 0.4rem));"
             aria-label="User Quick Navigation">
            <div class="flex items-center justify-around relative">
                <!-- 1. Dashboard -->
                <?php $isDashActive = str_contains($currentScript, 'dashboard.php'); ?>
                <a href="/user/dashboard.php" 
                   class="flex flex-col items-center gap-0.5 py-1 px-2.5 rounded-2xl transition-all <?= $isDashActive ? 'text-blue-600 font-extrabold' : 'text-slate-500 hover:text-slate-900 font-medium' ?>">
                    <div class="w-8 h-8 rounded-xl flex items-center justify-center transition-colors <?= $isDashActive ? 'bg-blue-50 text-blue-600' : 'text-slate-500' ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                    </div>
                    <span class="text-[10px] tracking-tight">Home</span>
                </a>

                <!-- 2. Services -->
                <?php $isServicesActive = str_contains($currentScript, 'services.php'); ?>
                <a href="/user/services.php" 
                   class="flex flex-col items-center gap-0.5 py-1 px-2.5 rounded-2xl transition-all <?= $isServicesActive ? 'text-blue-600 font-extrabold' : 'text-slate-500 hover:text-slate-900 font-medium' ?>">
                    <div class="w-8 h-8 rounded-xl flex items-center justify-center transition-colors <?= $isServicesActive ? 'bg-blue-50 text-blue-600' : 'text-slate-500' ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                        </svg>
                    </div>
                    <span class="text-[10px] tracking-tight">Services</span>
                </a>

                <!-- 3. Prominent Centerpiece: New Order -->
                <?php $isNewOrderActive = str_contains($currentScript, 'new-order.php'); ?>
                <a href="/user/new-order.php" 
                   class="flex flex-col items-center -mt-5 group"
                   title="Place New Order">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-blue-600 via-indigo-600 to-blue-700 text-white flex items-center justify-center shadow-lg shadow-blue-500/35 border-2 border-white group-hover:scale-105 group-active:scale-95 transition-all">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                        </svg>
                    </div>
                    <span class="text-[10px] font-bold text-blue-600 mt-0.5 tracking-tight">Order</span>
                </a>

                <!-- 4. Orders / Transactions -->
                <?php $isOrdersActive = str_contains($currentScript, 'orders.php') || str_contains($currentScript, 'transactions.php'); ?>
                <a href="/user/orders.php" 
                   class="flex flex-col items-center gap-0.5 py-1 px-2.5 rounded-2xl transition-all <?= $isOrdersActive ? 'text-blue-600 font-extrabold' : 'text-slate-500 hover:text-slate-900 font-medium' ?>">
                    <div class="w-8 h-8 rounded-xl flex items-center justify-center transition-colors <?= $isOrdersActive ? 'bg-blue-50 text-blue-600' : 'text-slate-500' ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                        </svg>
                    </div>
                    <span class="text-[10px] tracking-tight">Orders</span>
                </a>

                <!-- 5. Profile / Support -->
                <?php $isProfileActive = str_contains($currentScript, 'profile.php') || str_contains($currentScript, 'tickets.php'); ?>
                <a href="/user/profile.php" 
                   class="flex flex-col items-center gap-0.5 py-1 px-2.5 rounded-2xl transition-all <?= $isProfileActive ? 'text-blue-600 font-extrabold' : 'text-slate-500 hover:text-slate-900 font-medium' ?>">
                    <div class="w-8 h-8 rounded-xl flex items-center justify-center transition-colors <?= $isProfileActive ? 'bg-blue-50 text-blue-600' : 'text-slate-500' ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                    </div>
                    <span class="text-[10px] tracking-tight">Profile</span>
                </a>
            </div>
        </nav>

    <?php else: ?>
        <!-- Public Bottom Footer -->
        <footer class="mt-auto bg-white border-t border-slate-200/80 py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row items-center justify-between gap-4 text-xs text-slate-500">
                <div class="flex items-center gap-2">
                    <span class="font-bold text-slate-700"><?= e(app_name()) ?></span>
                    <span>&copy; <?= date('Y') ?>. All rights reserved. Plain PHP 8+ & MySQL PDO architecture.</span>
                </div>
                <div class="flex items-center gap-6">
                    <a href="/install/" class="text-blue-600 hover:underline font-semibold">Web Installer</a>
                    <a href="/services.php" class="hover:text-slate-900 transition-colors">Services</a>
                    <a href="/user/tickets.php" class="hover:text-slate-900 transition-colors">Support</a>
                </div>
            </div>
        </footer>
    <?php endif; ?>

    <!-- Application JavaScript Core -->
    <script src="/assets/js/app.js"></script>
    <?php if ($isUserPage || $isServicesPage): ?>
        <script src="/assets/js/user.js"></script>
    <?php endif; ?>
    <?php if ($isAdminPage): ?>
        <script src="/assets/js/admin.js"></script>
    <?php endif; ?>
</body>
</html>
