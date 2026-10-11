<?php
/**
 * User Dashboard (Premium 3D Card Hub)
 * SMM Panel - PHP 8.3+
 * Rebuilt to match primary visual reference IMG_4246.jpeg exactly
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireLogin();

$user = Auth::user();
$db = Database::getConnection();

// Check for active popup announcements for logged-in user
$activeAnnouncement = null;
try {
    $dismissedSession = $_SESSION['dismissed_announcements'] ?? [];
    $stmtAnnounce = $db->prepare("
        SELECT a.id, a.type, a.title, a.message, a.badge_text, a.btn_text, a.btn_link, a.show_once 
        FROM announcements a
        LEFT JOIN user_announcement_dismissals d ON a.id = d.announcement_id AND d.user_id = :uid
        WHERE a.status = 'active'
          AND (a.show_once = 0 OR d.user_id IS NULL)
          AND (a.starts_at IS NULL OR a.starts_at <= NOW())
          AND (a.expires_at IS NULL OR a.expires_at >= NOW())
        ORDER BY a.id DESC
        LIMIT 1
    ");
    $stmtAnnounce->execute(['uid' => $user['id']]);
    $candidate = $stmtAnnounce->fetch();
    if ($candidate && !isset($dismissedSession[(int)$candidate['id']])) {
        $activeAnnouncement = $candidate;
    }
} catch (Exception $e) {
    error_log("Announcement check: " . $e->getMessage());
}

// 2. Fetch Active Flash Sales for Dashboard Banner
$dashboardFlashSales = get_active_flash_sales(5);

// Assemble Dynamic 3D Carousel Cards respecting Admin feature settings (Requirement 1)
$carouselCards = [];

// 1. New Order (Always enabled)
$carouselCards[] = [
    'id' => 'new-order',
    'title' => 'New Order',
    'subtitle' => 'Place a new order for your social media',
    'badge' => 'Instant Delivery',
    'icon' => '⚡',
    'bgClass' => 'from-blue-600 via-indigo-600 to-purple-600',
    'glow' => 'rgba(79, 70, 229, 0.55)',
    'link' => '/user/new-order.php',
    'action' => 'Open →'
];

// 2. Mass Order (if enabled in Admin)
if (get_setting('mass_order_enabled', '1') === '1') {
    $carouselCards[] = [
        'id' => 'mass-order',
        'title' => 'Mass Order',
        'subtitle' => 'Bulk submit multiple links & quantities',
        'badge' => 'Batch Engine',
        'icon' => '📚',
        'bgClass' => 'from-violet-600 via-purple-600 to-indigo-700',
        'glow' => 'rgba(124, 58, 237, 0.50)',
        'link' => '/user/mass-order.php',
        'action' => 'Open →'
    ];
}

// 3. Drip-feed Order (if enabled in Admin)
if (get_setting('dripfeed_enabled', '1') === '1') {
    $carouselCards[] = [
        'id' => 'drip-feed',
        'title' => 'Drip-feed Order',
        'subtitle' => 'Scheduled interval delivery across runs',
        'badge' => 'Gradual Growth',
        'icon' => '💧',
        'bgClass' => 'from-cyan-600 via-teal-600 to-emerald-600',
        'glow' => 'rgba(13, 148, 136, 0.50)',
        'link' => '/user/drip-feed.php',
        'action' => 'Open →'
    ];
}

// 4. Add Funds (Always enabled)
$carouselCards[] = [
    'id' => 'add-funds',
    'title' => 'Add Funds',
    'subtitle' => 'Top up your account balance',
    'badge' => 'Instant Wallet',
    'icon' => '💳',
    'bgClass' => 'from-emerald-500 via-green-500 to-teal-600',
    'glow' => 'rgba(16, 185, 129, 0.45)',
    'link' => '/user/add-funds.php',
    'action' => 'Open →'
];

// 5. My Orders (Always enabled)
$carouselCards[] = [
    'id' => 'my-orders',
    'title' => 'My Orders',
    'subtitle' => 'Track your live order statuses',
    'badge' => 'Live Status',
    'icon' => '📦',
    'bgClass' => 'from-amber-500 via-orange-500 to-amber-600',
    'glow' => 'rgba(245, 158, 11, 0.45)',
    'link' => '/user/orders.php',
    'action' => 'Open →'
];


// 7. Refer & Earn (if enabled in Admin)
if (get_setting('referral_enabled', '1') === '1') {
    $carouselCards[] = [
        'id' => 'refer-earn',
        'title' => 'Refer & Earn',
        'subtitle' => 'Invite friends & earn lifetime commissions',
        'badge' => 'Lifetime Payout',
        'icon' => '🎁',
        'bgClass' => 'from-rose-500 via-pink-600 to-fuchsia-600',
        'glow' => 'rgba(236, 72, 153, 0.50)',
        'link' => '/user/referrals.php',
        'action' => 'Open →'
    ];
}

$pageTitle = "Dashboard - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Ambient Floating Pastel Petals / Soft Glows matching reference aesthetic -->
<div class="fixed inset-0 pointer-events-none overflow-hidden -z-10 select-none">
    <div class="absolute top-12 left-1/4 w-72 h-72 rounded-full bg-sky-200/25 blur-3xl"></div>
    <div class="absolute top-36 right-1/4 w-80 h-80 rounded-full bg-blue-200/20 blur-3xl"></div>
    <div class="absolute bottom-16 left-1/3 w-96 h-96 rounded-full bg-indigo-100/30 blur-3xl"></div>
    <!-- Floating pastel leaf shapes from reference image -->
    <div class="absolute top-28 right-[18%] text-emerald-400/40 transform rotate-45 scale-125">🍃</div>
    <div class="absolute top-44 left-[14%] text-blue-300/30 transform -rotate-12 scale-110">✦</div>
    <div class="absolute top-[340px] left-[26%] text-teal-400/35 transform rotate-12 scale-100">🍃</div>
    <div class="absolute top-[360px] right-[24%] text-sky-400/30 transform 45 scale-90">✦</div>
</div>

<div class="max-w-5xl mx-auto space-y-6 sm:space-y-8 pb-12">
    <!-- 2. WELCOME + BALANCE AREA (Exact reference two-part layout) -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pt-1 px-1">
        <!-- LEFT: User Avatar, Welcome back, User Name, User ID -->
        <div class="flex items-center gap-3.5 sm:gap-4">
            <div class="relative">
                <div class="w-13 h-13 sm:w-14 sm:h-14 rounded-full bg-gradient-to-tr from-blue-600 via-indigo-600 to-blue-700 flex items-center justify-center text-white text-xl font-black shadow-md shadow-blue-500/25 border-2 border-white ring-2 ring-blue-100">
                    <svg class="w-7 h-7 text-white" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
                    </svg>
                </div>
            </div>
            <div>
                <span class="text-xs text-slate-400 font-medium block leading-tight">Welcome back,</span>
                <h1 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight leading-snug">
                    <?= e($user['username']) ?>
                </h1>
                <span class="text-xs text-slate-400 font-medium block leading-tight mt-0.5">User ID: #<?= (int)$user['id'] ?></span>
            </div>
        </div>

        <!-- RIGHT: Your Balance, Real Amount, Premium Add Funds Button -->
        <div class="flex items-center gap-3 w-full sm:w-auto justify-between sm:justify-end bg-white/60 sm:bg-transparent backdrop-blur-sm sm:backdrop-blur-none p-3 sm:p-0 rounded-2xl border sm:border-0 border-white/80">
            <div class="text-left sm:text-right">
                <span class="text-xs text-slate-400 font-medium block leading-tight">Your Balance</span>
                <div class="text-2xl sm:text-3xl font-black text-slate-900 tabular-nums font-mono-nums tracking-tight leading-none mt-0.5">
                    <?= format_currency($user['balance']) ?>
                </div>
            </div>
            <a href="/user/add-funds.php" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 active:scale-95 text-white font-black text-xs sm:text-sm rounded-full shadow-lg shadow-blue-500/30 hover:shadow-blue-500/40 transition-all flex items-center gap-1.5 shrink-0 whitespace-nowrap">
                <span class="text-base font-black leading-none">+</span>
                <span>Add Funds</span>
            </a>
        </div>
    </div>

    <!-- 3. MAIN FEATURE 3D CARD CAROUSEL (Centerpiece matching reference) -->
    <div x-data="cardCarousel()" class="relative w-full overflow-hidden select-none py-4" style="perspective: 1200px; -webkit-perspective: 1200px;">
        <!-- 3D Stage Container -->
        <div class="relative w-full h-[290px] sm:h-[320px] md:h-[340px] flex items-center justify-center overflow-visible" 
             style="transform-style: preserve-3d; -webkit-transform-style: preserve-3d;"
             @touchstart="touchStart($event)"
             @touchmove="touchMove($event)"
             @touchend="touchEnd($event)"
             @mousedown="dragStart($event)"
             @mousemove="dragMove($event)"
             @mouseup="dragEnd($event)"
             @mouseleave="dragEnd($event)">
            
            <template x-for="(item, index) in items" :key="item.id">
                <div class="absolute w-[300px] sm:w-[380px] md:w-[410px] h-[255px] sm:h-[285px] md:h-[305px] rounded-3xl p-6 sm:p-8 text-white shadow-2xl transition-[transform,opacity,filter,box-shadow] duration-500 ease-[cubic-bezier(0.25,1,0.5,1)] flex flex-col items-center justify-between text-center cursor-pointer border border-white/25 overflow-hidden group select-none"
                     :class="'bg-gradient-to-br ' + item.bgClass"
                     :style="getCardStyle(index)"
                     :data-card-index="index"
                     @click="cardClick($event, index, item.link)">
                    
                    <!-- Ambient Inner Glass Glow -->
                    <div class="absolute -top-16 -right-16 w-40 h-40 rounded-full bg-white/20 blur-2xl pointer-events-none"></div>

                    <!-- Top: Circular Translucent Icon Badge with Crisp White SVG Icon -->
                    <div class="relative w-14 h-14 sm:w-16 sm:h-16 rounded-full bg-white/20 backdrop-blur-md flex items-center justify-center shadow-inner border border-white/30 shrink-0 mt-1">
                        <!-- 1. New Order Lightning Bolt -->
                        <template x-if="item.id === 'new-order'">
                            <svg class="w-7 h-7 sm:w-8 sm:h-8 text-white drop-shadow-xs" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd" />
                            </svg>
                        </template>

                        <!-- 2. Add Funds Wallet Icon -->
                        <template x-if="item.id === 'add-funds'">
                            <svg class="w-7 h-7 sm:w-8 sm:h-8 text-white drop-shadow-xs" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                            </svg>
                        </template>

                        <!-- 3. Mass Order Batch Icon -->
                        <template x-if="item.id === 'mass-order'">
                            <svg class="w-7 h-7 sm:w-8 sm:h-8 text-white drop-shadow-xs" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                            </svg>
                        </template>

                        <!-- 4. Drip-feed Order Water Droplet Icon -->
                        <template x-if="item.id === 'drip-feed'">
                            <svg class="w-7 h-7 sm:w-8 sm:h-8 text-white drop-shadow-xs" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2.69l5.66 5.66a8 8 0 11-11.31 0z" />
                            </svg>
                        </template>


                        <!-- 6. Refer & Earn Gift/Reward Icon -->
                        <template x-if="item.id === 'refer-earn'">
                            <svg class="w-7 h-7 sm:w-8 sm:h-8 text-white drop-shadow-xs" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v13m0-13V6a2 2 0 112 2h-2zm0 0V6a2 2 0 10-2 2h2zm0 0H4m8 0h8m-16 5h16M4 13a2 2 0 00-2 2v4a2 2 0 002 2h16a2 2 0 002-2v-4a2 2 0 00-2-2H4z" />
                            </svg>
                        </template>

                        <!-- 6. My Orders Tracking Box Icon -->
                        <template x-if="item.id === 'my-orders'">
                            <svg class="w-7 h-7 sm:w-8 sm:h-8 text-white drop-shadow-xs" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                        </template>

                        <!-- 7. Services Diamond Icon -->
                        <template x-if="item.id === 'services'">
                            <svg class="w-7 h-7 sm:w-8 sm:h-8 text-white drop-shadow-xs" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                            </svg>
                        </template>

                        <!-- 8. Support Chat Icon -->
                        <template x-if="item.id === 'support'">
                            <svg class="w-7 h-7 sm:w-8 sm:h-8 text-white drop-shadow-xs" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                        </template>
                    </div>

                    <!-- Middle: Title and Subtitle -->
                    <div class="relative space-y-1">
                        <h2 class="text-2xl sm:text-3xl font-black text-white tracking-tight drop-shadow-sm" x-text="item.title"></h2>
                        <p class="text-xs sm:text-sm text-white/90 font-medium max-w-[260px] mx-auto leading-relaxed" x-text="item.subtitle"></p>
                    </div>

                    <!-- Bottom: Rounded CTA Button matching reference -->
                    <div class="relative pt-1 pb-1">
                        <!-- Center Card prominent white CTA button -->
                        <template x-if="getDiff(index) === 0">
                            <span class="inline-flex items-center gap-1.5 px-8 sm:px-9 py-2.5 rounded-full bg-white text-blue-600 hover:bg-slate-50 font-black text-xs sm:text-sm shadow-xl shadow-slate-950/20 active:scale-95 transition-all">
                                <span>Open</span>
                                <span class="text-base leading-none">&rarr;</span>
                            </span>
                        </template>

                        <!-- Side Card translucent CTA button -->
                        <template x-if="getDiff(index) !== 0">
                            <span class="inline-flex items-center gap-1.5 px-6 py-2 rounded-full bg-white/25 backdrop-blur-md text-white font-bold text-xs border border-white/30 shadow-md">
                                <span>Open</span>
                                <span>&rarr;</span>
                            </span>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        <!-- Carousel Pagination Dots matching reference image -->
        <div class="flex items-center justify-center gap-2 mt-4">
            <template x-for="(item, idx) in items" :key="'dot-' + item.id">
                <button type="button" 
                        @click="goTo(idx)" 
                        :aria-label="'Go to ' + item.title"
                        class="h-1.5 rounded-full transition-all duration-300 cursor-pointer"
                        :class="active === idx ? 'w-6 bg-blue-600' : 'w-1.5 bg-slate-300 hover:bg-slate-400'">
                </button>
            </template>
        </div>
    </div>

    <!-- 5. 4 COMPACT QUICK ACCESS CARDS (Exactly 4 items matching reference) -->
    <!-- Services | Transactions | Support | Profile -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 pt-1">
        <!-- 1. Services -->
        <a href="/user/services.php" class="bg-white/90 backdrop-blur-xl border border-white/90 shadow-[0_10px_30px_-5px_rgba(15,23,42,0.05)] hover:shadow-lg hover:border-pink-200 hover:-translate-y-1 transition-all rounded-3xl p-4 sm:p-4.5 flex items-center gap-3.5 group">
            <div class="w-11 h-11 rounded-2xl bg-pink-50 border border-pink-100/90 text-pink-500 flex items-center justify-center shrink-0 shadow-xs group-hover:scale-105 transition-transform">
                <svg class="w-5 h-5 text-pink-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                </svg>
            </div>
            <div>
                <h3 class="text-sm font-black text-slate-800 tracking-tight group-hover:text-pink-600 transition-colors">Services</h3>
                <span class="text-[11px] text-slate-400 font-medium block mt-0.5">Browse all services</span>
            </div>
        </a>

        <!-- 2. Transactions -->
        <a href="/user/transactions.php" class="bg-white/90 backdrop-blur-xl border border-white/90 shadow-[0_10px_30px_-5px_rgba(15,23,42,0.05)] hover:shadow-lg hover:border-blue-200 hover:-translate-y-1 transition-all rounded-3xl p-4 sm:p-4.5 flex items-center gap-3.5 group">
            <div class="w-11 h-11 rounded-2xl bg-blue-50 border border-blue-100/90 text-blue-600 flex items-center justify-center shrink-0 shadow-xs group-hover:scale-105 transition-transform">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                </svg>
            </div>
            <div>
                <h3 class="text-sm font-black text-slate-800 tracking-tight group-hover:text-blue-600 transition-colors">Transactions</h3>
                <span class="text-[11px] text-slate-400 font-medium block mt-0.5">Wallet & history</span>
            </div>
        </a>

        <!-- 3. Support -->
        <a href="/user/tickets.php" class="bg-white/90 backdrop-blur-xl border border-white/90 shadow-[0_10px_30px_-5px_rgba(15,23,42,0.05)] hover:shadow-lg hover:border-purple-200 hover:-translate-y-1 transition-all rounded-3xl p-4 sm:p-4.5 flex items-center gap-3.5 group">
            <div class="w-11 h-11 rounded-2xl bg-purple-50 border border-purple-100/90 text-purple-600 flex items-center justify-center shrink-0 shadow-xs group-hover:scale-105 transition-transform">
                <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
            </div>
            <div>
                <h3 class="text-sm font-black text-slate-800 tracking-tight group-hover:text-purple-600 transition-colors">Support</h3>
                <span class="text-[11px] text-slate-400 font-medium block mt-0.5">Get help</span>
            </div>
        </a>

        <!-- 4. Profile -->
        <a href="/user/profile.php" class="bg-white/90 backdrop-blur-xl border border-white/90 shadow-[0_10px_30px_-5px_rgba(15,23,42,0.05)] hover:shadow-lg hover:border-teal-200 hover:-translate-y-1 transition-all rounded-3xl p-4 sm:p-4.5 flex items-center gap-3.5 group">
            <div class="w-11 h-11 rounded-2xl bg-teal-50 border border-teal-100/90 text-teal-600 flex items-center justify-center shrink-0 shadow-xs group-hover:scale-105 transition-transform">
                <svg class="w-5 h-5 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
            </div>
            <div>
                <h3 class="text-sm font-black text-slate-800 tracking-tight group-hover:text-teal-600 transition-colors">Profile</h3>
                <span class="text-[11px] text-slate-400 font-medium block mt-0.5">Account settings</span>
            </div>
        </a>
    </div>

    <!-- 6. FLASH SALE SHOWCASE SECTION (Replaces Quick Access dock matching reference) -->
    <div x-data="dashboardFlashSaleSection(<?= htmlspecialchars(json_encode($dashboardFlashSales), ENT_QUOTES, 'UTF-8') ?>)" 
         class="mt-6">
        
        <template x-if="sales.length > 0">
            <!-- Active Flash Sale Showcase Card -->
            <div class="relative overflow-hidden rounded-3xl bg-white/95 backdrop-blur-xl border border-white/90 p-5 sm:p-6 shadow-[0_12px_36px_-10px_rgba(15,23,42,0.08)]">
                <!-- Ambient Subtle Gradient Backdrop Glow -->
                <div class="absolute -right-12 -top-12 w-64 h-64 bg-gradient-to-br from-indigo-500/15 via-purple-500/10 to-pink-500/15 rounded-full blur-2xl pointer-events-none"></div>
                <div class="absolute -left-12 -bottom-12 w-48 h-48 bg-gradient-to-tr from-blue-500/10 to-indigo-500/10 rounded-full blur-xl pointer-events-none"></div>

                <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-5">
                    <!-- Left Column: Icon, Badges, Title & Subtitle -->
                    <div class="flex items-start gap-4">
                        <!-- Lightning Gradient Icon Body -->
                        <div class="w-13 h-13 sm:w-14 sm:h-14 rounded-2xl bg-gradient-to-tr from-blue-600 via-indigo-600 to-purple-600 text-white flex items-center justify-center shrink-0 shadow-lg shadow-indigo-500/25 ring-4 ring-indigo-50">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </div>

                        <div>
                            <!-- Header Badges Row -->
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-wider bg-rose-50 border border-rose-200/80 text-rose-600 shadow-xs">
                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-500 animate-pulse"></span>
                                    <span>Limited-Time Offer</span>
                                </span>

                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-[11px] font-black bg-gradient-to-r from-blue-600 to-purple-600 text-white shadow-xs" x-text="currentSale.badge_text">
                                </span>

                                <template x-if="currentSale.category_name">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600 border border-slate-200/60" x-text="currentSale.category_name"></span>
                                </template>
                            </div>

                            <!-- Prominent Flash Sale Title -->
                            <h2 class="text-lg sm:text-xl font-black text-slate-900 tracking-tight mt-1.5 flex items-center gap-2" x-text="currentSale.title">
                            </h2>

                            <!-- Subtitle / Value Pitch -->
                            <p class="text-xs text-slate-500 mt-0.5 max-w-xl leading-relaxed" x-text="currentSale.subtitle || 'Massive limited-time price drops on high-quality engagement services. Grab deals before time runs out!'">
                            </p>
                        </div>
                    </div>

                    <!-- Right Column: Live Countdown Box + Action Button -->
                    <div class="flex flex-col sm:flex-row items-start sm:items-center lg:items-end justify-between sm:justify-end gap-3.5 pt-3 lg:pt-0 border-t lg:border-t-0 border-slate-100">
                        <!-- Live Interactive Countdown Timer -->
                        <div class="flex flex-col items-start sm:items-end">
                            <span class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400 block mb-1">Sale Ends In</span>
                            <div class="flex items-center gap-1.5">
                                <!-- Hours Box -->
                                <div class="flex flex-col items-center bg-slate-900 text-white px-2.5 py-1.5 rounded-xl min-w-[42px] shadow-xs">
                                    <span class="text-sm font-black font-mono leading-none" x-text="pad(hours)">00</span>
                                    <span class="text-[8px] font-bold uppercase text-slate-400 tracking-wider mt-0.5">HRS</span>
                                </div>
                                <span class="text-slate-400 font-black text-sm">:</span>
                                <!-- Minutes Box -->
                                <div class="flex flex-col items-center bg-slate-900 text-white px-2.5 py-1.5 rounded-xl min-w-[42px] shadow-xs">
                                    <span class="text-sm font-black font-mono leading-none" x-text="pad(minutes)">00</span>
                                    <span class="text-[8px] font-bold uppercase text-slate-400 tracking-wider mt-0.5">MIN</span>
                                </div>
                                <span class="text-slate-400 font-black text-sm">:</span>
                                <!-- Seconds Box -->
                                <div class="flex flex-col items-center bg-gradient-to-b from-rose-600 to-rose-700 text-white px-2.5 py-1.5 rounded-xl min-w-[42px] shadow-xs">
                                    <span class="text-sm font-black font-mono leading-none" x-text="pad(seconds)">00</span>
                                    <span class="text-[8px] font-bold uppercase text-rose-200 tracking-wider mt-0.5">SEC</span>
                                </div>
                            </div>
                        </div>

                        <!-- View Deals Button -->
                        <div class="flex items-center gap-2 w-full sm:w-auto">
                            <a :href="'/user/services.php?sale_id=' + currentSale.id" 
                               class="w-full sm:w-auto px-5 py-3 rounded-2xl bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 text-white font-extrabold text-xs shadow-lg shadow-indigo-500/25 flex items-center justify-center gap-2 hover:scale-[1.02] active:scale-[0.98] transition-all group">
                                <span>View Deals</span>
                                <svg class="w-4 h-4 text-white group-hover:translate-x-0.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Multiple Sales Indicator Dots / Tabs (if > 1 sale exists) -->
                <template x-if="sales.length > 1">
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                            Deal <span x-text="currentIndex + 1"></span> of <span x-text="sales.length"></span>
                        </span>
                        <div class="flex items-center gap-1.5">
                            <template x-for="(s, idx) in sales" :key="s.id">
                                <button type="button" 
                                        @click="selectSale(idx)"
                                        :class="currentIndex === idx ? 'w-6 bg-blue-600' : 'w-2 bg-slate-200 hover:bg-slate-300'"
                                        class="h-2 rounded-full transition-all duration-200"
                                        :title="s.title">
                                </button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <template x-if="sales.length === 0">
            <!-- Graceful Placeholder Banner when No Flash Sale is Currently Running -->
            <div class="bg-white/90 backdrop-blur-xl border border-white/90 rounded-3xl p-5 sm:p-6 shadow-[0_10px_35px_-10px_rgba(15,23,42,0.07)] flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-3.5">
                    <div class="w-12 h-12 rounded-2xl bg-amber-50 border border-amber-100 text-amber-600 flex items-center justify-center shrink-0 shadow-xs text-xl">
                        <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-black text-slate-900 tracking-tight">Flash Sale Portal</h3>
                            <span class="text-[10px] font-extrabold text-amber-600 bg-amber-50 px-2 py-0.5 rounded-full border border-amber-100">Next Drop Soon</span>
                        </div>
                        <p class="text-xs text-slate-400 mt-0.5">Stay tuned! Limited-time discounts and flash promotions drop frequently on top services.</p>
                    </div>
                </div>
                <a href="/user/services.php" class="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-extrabold text-xs transition-colors shrink-0">
                    Browse All Services
                </a>
            </div>
        </template>
    </div>
</div>

<?php if ($activeAnnouncement): ?>
<!-- ✨ Modern User Announcement / Notification Popup Modal -->
<div x-data="userAnnouncementPopup(<?= htmlspecialchars(json_encode($activeAnnouncement), ENT_QUOTES, 'UTF-8') ?>)"
     x-show="show"
     x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6"
     style="display: none;"
     @keydown.escape.window="dismiss(false)">
    
    <!-- Dark Backdrop with Soft Blur -->
    <div x-show="show"
         x-transition:enter="transition-opacity ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="dismiss(false)"
         class="fixed inset-0 bg-slate-950/60 backdrop-blur-sm"></div>

    <!-- Popup Card Container -->
    <div x-show="show"
         x-transition:enter="transition ease-out duration-300 transform"
         x-transition:enter-start="opacity-0 translate-y-4 scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-200 transform"
         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
         x-transition:leave-end="opacity-0 translate-y-4 scale-95"
         class="relative bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200/90 z-10 overflow-hidden"
         @click.outside="dismiss(false)">
        
        <!-- Subtle Decorative Ambient Light Glow -->
        <div class="absolute -top-14 -right-14 w-40 h-40 rounded-full bg-blue-500/10 blur-2xl pointer-events-none"></div>

        <!-- Top Right Close Button -->
        <button type="button" 
                @click="dismiss(false)" 
                aria-label="Close announcement"
                class="absolute top-4 right-4 w-9 h-9 rounded-full bg-slate-100 text-slate-400 hover:text-slate-700 hover:bg-slate-200 flex items-center justify-center transition-colors cursor-pointer">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>

        <!-- Badge & Icon Header -->
        <div class="flex items-center gap-3.5 mb-5">
            <!-- Dynamic Themed Icon Box -->
            <div class="w-13 h-13 rounded-2xl flex items-center justify-center text-2xl font-bold shadow-md shrink-0"
                 :class="{
                    'bg-sky-50 text-sky-600 border border-sky-200': announcement.type === 'telegram',
                    'bg-emerald-50 text-emerald-600 border border-emerald-200': announcement.type === 'offer',
                    'bg-purple-50 text-purple-600 border border-purple-200': announcement.type === 'service',
                    'bg-amber-50 text-amber-600 border border-amber-200': announcement.type === 'maintenance',
                    'bg-blue-50 text-blue-600 border border-blue-200': announcement.type === 'update',
                    'bg-indigo-50 text-indigo-600 border border-indigo-200': announcement.type === 'announcement' || announcement.type === 'system'
                 }">
                <!-- Telegram Icon -->
                <template x-if="announcement.type === 'telegram'">
                    <svg class="w-6 h-6 text-sky-500" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.37.74-.56 2.92-1.27 4.86-2.11 5.83-2.52 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06-.01.19-.03.38z"/>
                    </svg>
                </template>

                <!-- Offer / Bonus Icon -->
                <template x-if="announcement.type === 'offer'">
                    <span class="text-2xl">🎁</span>
                </template>

                <!-- Service Alert Icon -->
                <template x-if="announcement.type === 'service'">
                    <span class="text-2xl">⚡</span>
                </template>

                <!-- Maintenance Icon -->
                <template x-if="announcement.type === 'maintenance'">
                    <span class="text-2xl">⚠️</span>
                </template>

                <!-- Update Icon -->
                <template x-if="announcement.type === 'update'">
                    <span class="text-2xl">🚀</span>
                </template>

                <!-- General Announcement / System Icon -->
                <template x-if="announcement.type === 'announcement' || announcement.type === 'system'">
                    <span class="text-2xl">📢</span>
                </template>
            </div>

            <div>
                <!-- Category/Badge Pill -->
                <span class="inline-block px-3 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider shadow-xs"
                      :class="{
                        'bg-sky-50 text-sky-700 border border-sky-200': announcement.type === 'telegram',
                        'bg-emerald-50 text-emerald-700 border border-emerald-200': announcement.type === 'offer',
                        'bg-purple-50 text-purple-700 border border-purple-200': announcement.type === 'service',
                        'bg-amber-50 text-amber-700 border border-amber-200': announcement.type === 'maintenance',
                        'bg-blue-50 text-blue-700 border border-blue-200': announcement.type === 'update',
                        'bg-indigo-50 text-indigo-700 border border-indigo-200': announcement.type === 'announcement' || announcement.type === 'system'
                      }"
                      x-text="announcement.badge_text || 'Announcement'">
                </span>
                <span class="text-[11px] text-slate-400 block font-bold mt-0.5"><?= e(app_name()) ?> Notice</span>
            </div>
        </div>

        <!-- Title & Formatted Message -->
        <div class="space-y-2.5">
            <h3 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight leading-snug" 
                x-text="announcement.title"></h3>
            <div class="text-xs sm:text-sm text-slate-600 leading-relaxed font-normal whitespace-pre-line" 
                x-text="announcement.message"></div>
        </div>

        <!-- Action Button & Dismiss Controls -->
        <div class="pt-6 mt-6 border-t border-slate-100 flex flex-col-reverse sm:flex-row items-center justify-between gap-3">
            <button type="button" 
                    @click="dismiss(true)" 
                    class="w-full sm:w-auto px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition-colors text-center cursor-pointer">
                Don't show again
            </button>

            <template x-if="announcement.btn_text && announcement.btn_link">
                <a :href="announcement.btn_link" 
                   @click="dismiss(true)"
                   :target="announcement.btn_link.startsWith('http') ? '_blank' : '_self'" 
                   class="w-full sm:w-auto px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-black text-xs rounded-xl shadow-lg shadow-blue-500/25 transition-all flex items-center justify-center gap-2 text-center cursor-pointer">
                    <span x-text="announcement.btn_text"></span>
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </a>
            </template>
        </div>
    </div>
</div>

<script>
/**
 * User Announcement Popup Controller
 * Manages display, smooth animation, and background AJAX dismissal logging
 */
function userAnnouncementPopup(data) {
    return {
        show: !!data,
        announcement: data || {},

        dismiss(dontShowAgain = false) {
            this.show = false;
            if (!this.announcement || !this.announcement.id) return;

            // Send async dismissal request to server
            fetch('/user/api/dismiss-announcement.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    announcement_id: this.announcement.id,
                    dont_show_again: dontShowAgain || this.announcement.show_once == 1
                })
            }).catch(err => {
                console.log('Announcement dismissed locally', err);
            });
        }
    };
}
<?php endif; ?>

<script>
/**
 * Dashboard Flash Sale Section Controller
 * Provides dynamic multi-deal switching and real-time live countdown ticking
 */
function dashboardFlashSaleSection(sales) {
    return {
        sales: sales || [],
        currentIndex: 0,
        hours: 0,
        minutes: 0,
        seconds: 0,
        timer: null,

        get currentSale() {
            return this.sales[this.currentIndex] || {};
        },

        init() {
            if (this.sales.length > 0) {
                this.updateCountdown();
                this.timer = setInterval(() => {
                    this.updateCountdown();
                }, 1000);
            }
        },

        selectSale(idx) {
            this.currentIndex = idx;
            this.updateCountdown();
        },

        updateCountdown() {
            if (!this.currentSale || !this.currentSale.ends_at) return;
            const target = new Date(this.currentSale.ends_at.replace(' ', 'T')).getTime();
            const now = new Date().getTime();
            const diff = Math.max(0, Math.floor((target - now) / 1000));
            this.hours = Math.floor(diff / 3600);
            this.minutes = Math.floor((diff % 3600) / 60);
            this.seconds = diff % 60;
        },

        pad(n) {
            return String(n).padStart(2, '0');
        }
    };
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
