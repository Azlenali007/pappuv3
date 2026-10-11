<?php
/**
 * Modern Services Catalog & Interactive Card Grid
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = Auth::user();
$isLoggedIn = ($user !== null);
$db = Database::getConnection();

$platformFilter = strtolower(trim($_GET['platform'] ?? 'all'));
$search = trim($_GET['search'] ?? '');
$categoryParam = trim($_GET['category'] ?? 'all');

// Query active categories
$categoriesList = [];
try {
    $categoriesList = $db->query("SELECT id, name, platform FROM categories WHERE status = 'active' ORDER BY sort_order ASC, name ASC")->fetchAll() ?: [];
} catch (Exception $e) {
    $categoriesList = [];
}

// Fetch all active services with categories
$servicesList = [];
try {
    $stmt = $db->query("
        SELECT s.*, c.name AS category_name, c.platform
        FROM services s
        JOIN categories c ON s.category_id = c.id
        WHERE s.status = 'active'
        ORDER BY c.sort_order ASC, s.rate_per_1000 ASC, s.id ASC
    ");
    $servicesList = $stmt->fetchAll() ?: [];
} catch (Exception $e) {
    $servicesList = [];
}

// Total active services count across the catalog (computed from result set)
$totalCatalogCount = count($servicesList);

// Fetch Active Flash Sales for Discount Calculations & Filters
$activeFlashSales = get_active_flash_sales();
$saleIdFilter = (int)($_GET['sale_id'] ?? 0);
$activeSaleObj = null;
if ($saleIdFilter > 0) {
    foreach ($activeFlashSales as $afs) {
        if ((int)$afs['id'] === $saleIdFilter) {
            $activeSaleObj = $afs;
            break;
        }
    }
}

// Helper for platform-specific badge and styling
function get_platform_info(string $platform): array {
    $p = strtolower($platform);
    return match ($p) {
        'instagram' => [
            'bg' => 'from-pink-500 via-rose-500 to-purple-600',
            'badge_bg' => 'bg-pink-50 text-pink-700 border-pink-200/80',
            'accent' => 'text-pink-600',
            'border_hover' => 'hover:border-pink-300 group-hover:border-pink-200',
            'pill_active' => 'bg-gradient-to-r from-pink-500 via-rose-500 to-purple-600 text-white shadow-pink-500/25',
            'label' => 'Instagram',
            'icon' => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>'
        ],
        'youtube' => [
            'bg' => 'from-red-600 to-rose-600',
            'badge_bg' => 'bg-red-50 text-red-700 border-red-200/80',
            'accent' => 'text-red-600',
            'border_hover' => 'hover:border-red-300 group-hover:border-red-200',
            'pill_active' => 'bg-red-600 text-white shadow-red-500/25',
            'label' => 'YouTube',
            'icon' => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>'
        ],
        'telegram' => [
            'bg' => 'from-sky-500 to-blue-600',
            'badge_bg' => 'bg-sky-50 text-sky-700 border-sky-200/80',
            'accent' => 'text-sky-600',
            'border_hover' => 'hover:border-sky-300 group-hover:border-sky-200',
            'pill_active' => 'bg-sky-500 text-white shadow-sky-500/25',
            'label' => 'Telegram',
            'icon' => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221l-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.446 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.121l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.458c.536-.196 1.006.128.832.943z"/></svg>'
        ],
        'facebook' => [
            'bg' => 'from-blue-600 to-indigo-700',
            'badge_bg' => 'bg-blue-50 text-blue-700 border-blue-200/80',
            'accent' => 'text-blue-600',
            'border_hover' => 'hover:border-blue-300 group-hover:border-blue-200',
            'pill_active' => 'bg-blue-600 text-white shadow-blue-500/25',
            'label' => 'Facebook',
            'icon' => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>'
        ],
        'tiktok' => [
            'bg' => 'from-slate-900 via-zinc-800 to-black',
            'badge_bg' => 'bg-slate-100 text-slate-800 border-slate-200/80',
            'accent' => 'text-slate-900',
            'border_hover' => 'hover:border-slate-400 group-hover:border-slate-300',
            'pill_active' => 'bg-slate-900 text-white shadow-slate-900/25',
            'label' => 'TikTok',
            'icon' => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.24 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.89 1.53-.02 2.87-1.12 3.18-2.61.12-.59.13-1.2.12-1.8V.02h-.01z"/></svg>'
        ],
        'twitter' => [
            'bg' => 'from-slate-800 to-slate-900',
            'badge_bg' => 'bg-slate-100 text-slate-800 border-slate-200/80',
            'accent' => 'text-slate-900',
            'border_hover' => 'hover:border-slate-400 group-hover:border-slate-300',
            'pill_active' => 'bg-slate-900 text-white shadow-slate-900/25',
            'label' => 'Twitter (X)',
            'icon' => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>'
        ],
        default => [
            'bg' => 'from-indigo-600 to-blue-600',
            'badge_bg' => 'bg-indigo-50 text-indigo-700 border-indigo-200/80',
            'accent' => 'text-indigo-600',
            'border_hover' => 'hover:border-indigo-300 group-hover:border-indigo-200',
            'pill_active' => 'bg-indigo-600 text-white shadow-indigo-500/25',
            'label' => ucfirst($platform ?: 'General'),
            'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>'
        ]
    };
}

// Prepare JSON payload for Alpine.js client-side instant filtering
$servicesJsonData = [];
$platformCounts = [
    'all' => count($servicesList),
    'instagram' => 0,
    'youtube' => 0,
    'telegram' => 0,
    'facebook' => 0,
    'tiktok' => 0,
    'twitter' => 0,
    'other' => 0
];

foreach ($servicesList as $s) {
    $p = strtolower($s['platform'] ?? 'other');
    if (!isset($platformCounts[$p])) {
        $p = 'other';
    }
    $platformCounts[$p]++;
    
    $theme = get_platform_info($s['platform'] ?? 'other');
    $orderUrl = $isLoggedIn 
        ? '/user/new-order.php?service_id=' . (int)$s['id'] 
        : '/login.php?redirect=' . urlencode('/user/new-order.php?service_id=' . (int)$s['id']);

    $origRate = (float)$s['rate_per_1000'];
    $flashDisc = get_service_flash_sale_discount((int)$s['id'], (int)$s['category_id'], $origRate, $activeFlashSales);
    $isFlash = ($flashDisc !== null);
    $saleRate = $isFlash ? $flashDisc['sale_rate'] : $origRate;

    $servicesJsonData[] = [
        'id' => (int)$s['id'],
        'category_id' => (int)$s['category_id'],
        'category_name' => $s['category_name'] ?? 'General',
        'platform' => $p,
        'platform_label' => $theme['label'],
        'name' => $s['name'],
        'is_flash_sale' => $isFlash,
        'flash_sale_id' => $flashDisc['flash_sale_id'] ?? null,
        'flash_badge' => $flashDisc['badge'] ?? null,
        'flash_discount_percent' => $flashDisc['discount_percent'] ?? 0,
        'original_rate' => $origRate,
        'original_rate_formatted' => format_currency($origRate),
        'sale_rate' => $saleRate,
        'rate' => $saleRate,
        'rate_formatted' => format_currency($saleRate),
        'min' => (int)$s['min_quantity'],
        'min_formatted' => number_format((int)$s['min_quantity']),
        'max' => (int)$s['max_quantity'],
        'max_formatted' => number_format((int)$s['max_quantity']),
        'speed' => $s['speed'] ?: 'Instant Start',
        'service_type' => $s['service_type'] ?? 'default',
        'description' => $s['description'] ?: 'High quality, stable social media engagement service delivered with prompt execution and reliable fulfillment.',
        'order_url' => $orderUrl,
        'bg_gradient' => $theme['bg'],
        'badge_bg' => $theme['badge_bg'],
        'accent' => $theme['accent']
    ];
}

$pageTitle = "Services Hub & Price Catalog - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div x-data="servicesHub()" x-cloak class="max-w-7xl mx-auto my-4 space-y-8">
    <!-- Top Bar: Navigation Links & New Order Action -->
    <div class="flex items-center justify-between">
        <?php if ($isLoggedIn): ?>
            <a href="/user/dashboard.php" class="flex items-center gap-2 text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span>Back to Dashboard</span>
            </a>
            <a href="/user/new-order.php" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-extrabold rounded-xl shadow-md shadow-blue-500/20 transition-all flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>New Custom Order</span>
            </a>
        <?php else: ?>
            <a href="/" class="flex items-center gap-2 text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span>Back to Home</span>
            </a>
            <div class="flex items-center gap-2">
                <a href="/login.php" class="px-3.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition-all">Sign In</a>
                <a href="/register.php" class="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-sm transition-all">Get Started</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($activeSaleObj): ?>
        <!-- Active Flash Sale Promotion Banner -->
        <div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 rounded-3xl p-5 sm:p-6 text-white shadow-lg shadow-indigo-500/20 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div class="flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-2xl bg-white/20 backdrop-blur-md flex items-center justify-center text-xl shrink-0 border border-white/20">
                    ⚡
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase bg-white/20 text-white border border-white/30"><?= e($activeSaleObj['badge_text']) ?></span>
                        <h2 class="text-base font-black"><?= e($activeSaleObj['title']) ?></h2>
                    </div>
                    <p class="text-xs text-indigo-100 mt-0.5"><?= e($activeSaleObj['subtitle'] ?: 'Exclusive limited-time promotional pricing active across these services!') ?></p>
                </div>
            </div>
            <a href="/user/services.php" class="px-4 py-2.5 rounded-xl bg-white text-indigo-900 font-extrabold text-xs hover:bg-indigo-50 shadow-sm shrink-0 transition-all flex items-center gap-1.5">
                <span>Show All Services</span>
                <span>&rarr;</span>
            </a>
        </div>
    <?php endif; ?>

    <!-- Header Banner & Interactive Search / Filter Panel -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6 pb-6 border-b border-slate-100">
            <div>
                <div class="flex items-center gap-2 mb-2">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-blue-50 text-blue-700 border border-blue-200/60">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-600 animate-pulse"></span>
                        Live Service Directory
                    </span>
                    <span class="text-[11px] font-bold text-slate-400 bg-slate-50 px-2.5 py-0.5 rounded-full border border-slate-200/60">
                        100% Guaranteed Delivery
                    </span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">Services Catalog</h1>
                <p class="text-xs sm:text-sm text-slate-500 mt-1 font-medium">
                    Showing <strong class="text-slate-900 font-bold" x-text="filteredServices.length"></strong> of <span class="font-bold text-slate-700"><?= number_format($totalCatalogCount) ?></span> active services with instant fulfillment & best rates.
                </p>
            </div>

            <!-- Instant Search Box & Sort Controls -->
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 w-full lg:w-auto">
                <!-- Search Input -->
                <div class="relative w-full sm:w-72">
                    <span class="absolute left-3.5 top-3 text-slate-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </span>
                    <input type="text" 
                           x-model.debounce.150ms="searchQuery" 
                           placeholder="Search by name, ID, category..." 
                           class="w-full pl-10 pr-9 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                    <button type="button" 
                            x-show="searchQuery.length > 0" 
                            @click="searchQuery = ''" 
                            class="absolute right-3 top-2.5 text-slate-400 hover:text-slate-700 text-xs font-bold" 
                            title="Clear search">✕</button>
                </div>

                <!-- Category Dropdown Filter -->
                <div class="relative min-w-[180px]">
                    <select x-model="selectedCategory" 
                            class="w-full py-2.5 pl-3 pr-8 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all cursor-pointer">
                        <option value="all">All Categories (<?= count($categoriesList) ?>)</option>
                        <?php foreach ($categoriesList as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>"><?= e($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Sort Order Dropdown -->
                <div class="relative min-w-[140px]">
                    <select x-model="sortBy" 
                            class="w-full py-2.5 pl-3 pr-8 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all cursor-pointer">
                        <option value="default">Default Order</option>
                        <option value="price_asc">Price: Low to High</option>
                        <option value="price_desc">Price: High to Low</option>
                        <option value="name_asc">Name: A to Z</option>
                        <option value="id_asc">Service ID</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Modern Horizontally Scrollable Platform Filter Tabs -->
        <div class="flex items-center gap-2 overflow-x-auto pb-2 scrollbar-none" aria-label="Platform Filters">
            <!-- All Platforms Button -->
            <button type="button" 
                    @click="selectedPlatform = 'all'" 
                    class="px-4 py-2.5 rounded-2xl text-xs font-extrabold whitespace-nowrap transition-all duration-200 flex items-center gap-2"
                    :class="selectedPlatform === 'all' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/25 scale-102' : 'bg-slate-50 hover:bg-slate-100 text-slate-600 border border-slate-200/70'">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                <span>All Platforms</span>
                <span class="px-2 py-0.5 rounded-full text-[10px]" :class="selectedPlatform === 'all' ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-700'"><?= $platformCounts['all'] ?></span>
            </button>

            <?php
            $platforms = [
                'instagram' => ['label' => 'Instagram', 'key' => 'instagram'],
                'youtube' => ['label' => 'YouTube', 'key' => 'youtube'],
                'telegram' => ['label' => 'Telegram', 'key' => 'telegram'],
                'facebook' => ['label' => 'Facebook', 'key' => 'facebook'],
                'tiktok' => ['label' => 'TikTok', 'key' => 'tiktok'],
                'twitter' => ['label' => 'Twitter (X)', 'key' => 'twitter'],
                'other' => ['label' => 'Other', 'key' => 'other']
            ];
            foreach ($platforms as $pKey => $pData):
                $pTheme = get_platform_info($pKey);
                $pCount = $platformCounts[$pKey] ?? 0;
            ?>
                <button type="button" 
                        @click="selectedPlatform = '<?= $pKey ?>'" 
                        class="px-4 py-2.5 rounded-2xl text-xs font-extrabold whitespace-nowrap transition-all duration-200 flex items-center gap-2"
                        :class="selectedPlatform === '<?= $pKey ?>' ? '<?= $pTheme['pill_active'] ?> shadow-md scale-102' : 'bg-slate-50 hover:bg-slate-100 text-slate-600 border border-slate-200/70'">
                    <span :class="selectedPlatform === '<?= $pKey ?>' ? 'text-white' : '<?= $pTheme['accent'] ?>'"><?= $pTheme['icon'] ?></span>
                    <span><?= $pData['label'] ?></span>
                    <span class="px-2 py-0.5 rounded-full text-[10px]" :class="selectedPlatform === '<?= $pKey ?>' ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-700'"><?= $pCount ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Filter Status & Quick Reset Line -->
        <div x-show="hasActiveFilters" x-transition class="flex items-center justify-between pt-2 border-t border-slate-100 text-xs">
            <div class="flex items-center gap-2 text-slate-500 font-medium">
                <span>Active Filters:</span>
                <span x-show="searchQuery" class="px-2 py-0.5 bg-blue-50 text-blue-700 rounded-md font-bold" x-text="'Keyword: ' + searchQuery"></span>
                <span x-show="selectedPlatform !== 'all'" class="px-2 py-0.5 bg-indigo-50 text-indigo-700 rounded-md font-bold" x-text="'Platform: ' + selectedPlatform"></span>
                <span x-show="selectedCategory !== 'all'" class="px-2 py-0.5 bg-amber-50 text-amber-700 rounded-md font-bold" x-text="'Category ID: ' + selectedCategory"></span>
                <span x-show="sortBy !== 'default'" class="px-2 py-0.5 bg-slate-100 text-slate-700 rounded-md font-bold" x-text="'Sort: ' + sortBy"></span>
            </div>
            <button type="button" @click="resetFilters()" class="text-rose-600 hover:text-rose-700 font-bold flex items-center gap-1 hover:underline">
                <span>Reset All Filters</span>
                <span>✕</span>
            </button>
        </div>
    </div>

    <!-- Responsive Animated Card Grid (Desktop 2-4 cols, Mobile 1 col) -->
    <div class="space-y-6">
        <!-- Empty State -->
        <div x-show="filteredServices.length === 0" 
             x-transition 
             class="bg-white rounded-3xl p-12 text-center border border-slate-200/80 shadow-sm max-w-lg mx-auto space-y-4">
            <div class="w-16 h-16 rounded-3xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto text-2xl shadow-inner">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 9.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <h3 class="text-base font-extrabold text-slate-900">No Services Found</h3>
                <p class="text-xs text-slate-500 mt-1 max-w-sm mx-auto leading-relaxed">
                    No active services match your current search query or filter selection. Try adjusting your search term or clearing the active filters.
                </p>
            </div>
            <div class="pt-2">
                <button type="button" @click="resetFilters()" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-md transition-all">
                    <span>Clear All Filters</span>
                    <span>&rarr;</span>
                </button>
            </div>
        </div>

        <!-- Cards Grid Container (1 col on mobile, 2 cols on sm, 3 cols on lg, 4 cols on xl) -->
        <div x-show="filteredServices.length > 0" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 sm:gap-6">
            <template x-for="item in filteredServices" :key="item.id">
                <div class="service-card bg-white rounded-3xl p-5 sm:p-6 border border-slate-200/80 shadow-xs hover:border-slate-300 flex flex-col justify-between group relative overflow-hidden">
                    <!-- Top Subtle Gradient Glow Line -->
                    <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r transition-all group-hover:h-1.5" :class="item.bg_gradient"></div>

                    <!-- Card Header: Category Badge & ID -->
                    <div>
                        <div class="flex items-start justify-between gap-2 mb-3">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold border transition-colors" :class="item.badge_bg">
                                <span class="w-2 h-2 rounded-full bg-current"></span>
                                <span class="truncate max-w-[130px]" x-text="item.platform_label"></span>
                            </span>
                            <span class="font-mono text-[11px] font-bold text-slate-400 bg-slate-50 px-2 py-0.5 rounded-lg border border-slate-100" x-text="'#' + item.id"></span>
                        </div>

                        <!-- Service Title -->
                        <h3 class="text-sm sm:text-base font-extrabold text-slate-900 group-hover:text-blue-600 transition-colors line-clamp-2 leading-snug">
                            <a :href="item.order_url" class="focus:outline-none" x-text="item.name"></a>
                        </h3>
                        <span class="text-[11px] font-medium text-slate-400 block mt-1 truncate" x-text="item.category_name"></span>

                        <!-- Specs Grid (Min, Max, Speed) -->
                        <div class="grid grid-cols-2 gap-2 mt-4 text-center">
                            <div class="bg-slate-50/80 rounded-xl p-2 border border-slate-100 group-hover:bg-slate-50 transition-colors">
                                <span class="text-[10px] text-slate-400 uppercase font-bold block">Min Order</span>
                                <span class="text-xs font-black text-slate-800 font-mono-nums" x-text="item.min_formatted"></span>
                            </div>
                            <div class="bg-slate-50/80 rounded-xl p-2 border border-slate-100 group-hover:bg-slate-50 transition-colors">
                                <span class="text-[10px] text-slate-400 uppercase font-bold block">Max Order</span>
                                <span class="text-xs font-black text-slate-800 font-mono-nums" x-text="item.max_formatted"></span>
                            </div>
                            <div class="col-span-2 bg-slate-50/80 rounded-xl px-2.5 py-1.5 border border-slate-100 flex items-center justify-between text-xs group-hover:bg-slate-50 transition-colors">
                                <span class="text-[10px] text-slate-400 uppercase font-bold">Speed</span>
                                <span class="text-[11px] font-bold text-emerald-600 flex items-center gap-1 truncate">
                                    <span>⚡</span>
                                    <span class="truncate" x-text="item.speed"></span>
                                </span>
                            </div>
                        </div>

                        <!-- Description Snippet & Details Button -->
                        <div class="mt-3 bg-slate-50/60 rounded-xl p-2.5 border border-slate-100 text-[11px] text-slate-500">
                            <p class="line-clamp-2 leading-relaxed" x-text="item.description"></p>
                            <div class="mt-2 pt-1 border-t border-slate-200/50 flex justify-end">
                                <button type="button" 
                                        @click="openDetails(item)" 
                                        class="text-[10px] font-bold text-blue-600 hover:text-blue-700 flex items-center gap-1 hover:underline cursor-pointer">
                                    <span>View Full Details & Specs</span>
                                    <span>&rarr;</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Card Footer: Rate & Order Action -->
                    <div class="mt-5 pt-4 border-t border-slate-100">
                        <div class="flex items-baseline justify-between mb-3">
                            <div>
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Price per 1K</span>
                                <div class="flex items-baseline gap-1.5">
                                    <template x-if="item.is_flash_sale">
                                        <span class="text-xs font-bold text-slate-400 line-through tabular-nums" x-text="item.original_rate_formatted"></span>
                                    </template>
                                    <span class="text-xl sm:text-2xl font-black tabular-nums" :class="item.is_flash_sale ? 'text-rose-600' : 'text-slate-900'" x-text="item.rate_formatted"></span>
                                    <span class="text-xs font-semibold text-slate-500 ml-0.5">/ 1K</span>
                                </div>
                            </div>
                            <template x-if="item.is_flash_sale">
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase bg-rose-50 text-rose-600 border border-rose-200 shadow-xs">
                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-500 animate-pulse"></span>
                                    <span x-text="item.flash_badge || ('-' + item.flash_discount_percent + '%')"></span>
                                </span>
                            </template>
                            <template x-if="!item.is_flash_sale">
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                    Active
                                </span>
                            </template>
                        </div>

                        <!-- Dual Action Buttons: Order Now & View Details -->
                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" 
                                    @click="openDetails(item)" 
                                    class="py-2.5 px-3 bg-slate-100 hover:bg-slate-200 active:scale-95 text-slate-700 font-extrabold text-xs rounded-xl transition-all flex items-center justify-center gap-1 cursor-pointer">
                                <span>Details</span>
                            </button>
                            <a :href="item.order_url" 
                               class="py-2.5 px-3 bg-blue-600 hover:bg-blue-700 active:scale-95 text-white font-extrabold text-xs rounded-xl shadow-md shadow-blue-500/25 transition-all flex items-center justify-center gap-1 group-hover:bg-blue-700">
                                <span>Order Now</span>
                                <svg class="w-3.5 h-3.5 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                            </a>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- Service Details & Instructions Modal -->
    <div x-show="activeModalService !== null" 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4" 
         @keydown.escape.window="closeDetails()"
         style="display: none;">
        
        <div @click.away="closeDetails()" 
             class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-100 relative overflow-hidden space-y-6">
            
            <!-- Top Gradient Bar -->
            <div class="absolute top-0 left-0 right-0 h-2 bg-gradient-to-r" :class="activeModalService ? activeModalService.bg_gradient : 'from-blue-600 to-indigo-600'"></div>

            <!-- Modal Header -->
            <div class="flex items-start justify-between gap-4 pt-1">
                <div>
                    <div class="flex items-center gap-2 mb-2">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-bold border" 
                              :class="activeModalService ? activeModalService.badge_bg : ''" 
                              x-text="activeModalService ? activeModalService.platform_label : ''"></span>
                        <span class="font-mono text-xs font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded-lg" 
                              x-text="'ID: #' + (activeModalService ? activeModalService.id : '')"></span>
                    </div>
                    <h2 class="text-lg sm:text-xl font-extrabold text-slate-900 leading-snug" 
                        x-text="activeModalService ? activeModalService.name : ''"></h2>
                    <span class="text-xs font-semibold text-slate-400 block mt-1" 
                          x-text="activeModalService ? activeModalService.category_name : ''"></span>
                </div>
                <button type="button" 
                        @click="closeDetails()" 
                        class="w-8 h-8 rounded-full bg-slate-100 text-slate-400 hover:text-slate-700 hover:bg-slate-200 flex items-center justify-center font-bold text-sm transition-colors">
                    ✕
                </button>
            </div>

            <!-- Specifications Table / Pills -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 bg-slate-50 p-4 rounded-2xl border border-slate-100 text-center">
                <div>
                    <span class="text-[10px] text-slate-400 uppercase font-bold block">Rate / 1K</span>
                    <span class="text-sm font-black text-slate-900" x-text="activeModalService ? activeModalService.rate_formatted : ''"></span>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 uppercase font-bold block">Min Quantity</span>
                    <span class="text-sm font-black text-slate-900 font-mono-nums" x-text="activeModalService ? activeModalService.min_formatted : ''"></span>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 uppercase font-bold block">Max Quantity</span>
                    <span class="text-sm font-black text-slate-900 font-mono-nums" x-text="activeModalService ? activeModalService.max_formatted : ''"></span>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 uppercase font-bold block">Fulfillment</span>
                    <span class="text-xs font-black text-emerald-600 truncate block mt-0.5" x-text="activeModalService ? activeModalService.speed : ''"></span>
                </div>
            </div>

            <!-- Full Description Box -->
            <div class="space-y-2">
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Service Description & Instructions</span>
                <div class="bg-slate-50/80 rounded-2xl p-4 border border-slate-200/70 text-xs text-slate-600 leading-relaxed max-h-48 overflow-y-auto whitespace-pre-line" 
                     x-text="activeModalService ? activeModalService.description : ''"></div>
            </div>

            <!-- Guidelines / Safety Checklist -->
            <div class="bg-amber-50/80 rounded-2xl p-4 border border-amber-200/60 text-[11px] text-amber-800 space-y-1">
                <div class="font-bold flex items-center gap-1.5 text-amber-900">
                    <span>⚠️</span>
                    <span>Order Guidelines:</span>
                </div>
                <ul class="list-disc pl-4 space-y-0.5">
                    <li>Please make sure your social media account/link is set to <strong>PUBLIC</strong>, not private.</li>
                    <li>Do not submit a duplicate order for the same link until the previous order completes.</li>
                    <li>Ensure the URL format is correct (e.g. valid username or post link).</li>
                </ul>
            </div>

            <!-- Modal Action Buttons -->
            <div class="flex items-center gap-3 pt-2">
                <button type="button" 
                        @click="closeDetails()" 
                        class="w-1/3 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-extrabold text-xs rounded-xl transition-all text-center">
                    Close
                </button>
                <a :href="activeModalService ? activeModalService.order_url : '#'" 
                   class="w-2/3 py-3 bg-blue-600 hover:bg-blue-700 active:scale-95 text-white font-extrabold text-xs rounded-xl shadow-md shadow-blue-500/25 transition-all flex items-center justify-center gap-2 text-center">
                    <span>Proceed to Order Now</span>
                    <span>&rarr;</span>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function servicesHub() {
    const rawServices = <?= json_encode($servicesJsonData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    return {
        services: rawServices,
        searchQuery: '<?= e(addslashes($search)) ?>',
        selectedPlatform: '<?= e(addslashes($platformFilter)) ?>',
        selectedCategory: '<?= e(addslashes($categoryParam)) ?>',
        flashSaleFilter: <?= $saleIdFilter ?>,
        sortBy: 'default',
        activeModalService: null,

        get filteredServices() {
            let list = this.services.filter(s => {
                // Flash sale filter
                if (this.flashSaleFilter > 0) {
                    if (s.flash_sale_id !== this.flashSaleFilter && !s.is_flash_sale) {
                        return false;
                    }
                }
                // Platform filter
                if (this.selectedPlatform !== 'all' && s.platform.toLowerCase() !== this.selectedPlatform.toLowerCase()) {
                    return false;
                }
                // Category filter
                if (this.selectedCategory !== 'all' && String(s.category_id) !== String(this.selectedCategory)) {
                    return false;
                }
                // Search query
                if (this.searchQuery && this.searchQuery.trim() !== '') {
                    const q = this.searchQuery.toLowerCase().trim();
                    const matchName = (s.name || '').toLowerCase().includes(q);
                    const matchCat = (s.category_name || '').toLowerCase().includes(q);
                    const matchId = String(s.id).includes(q);
                    const matchDesc = (s.description || '').toLowerCase().includes(q);
                    if (!matchName && !matchCat && !matchId && !matchDesc) {
                        return false;
                    }
                }
                return true;
            });

            // Sorting
            if (this.sortBy === 'price_asc') {
                list.sort((a, b) => a.rate - b.rate);
            } else if (this.sortBy === 'price_desc') {
                list.sort((a, b) => b.rate - a.rate);
            } else if (this.sortBy === 'name_asc') {
                list.sort((a, b) => a.name.localeCompare(b.name));
            } else if (this.sortBy === 'id_asc') {
                list.sort((a, b) => a.id - b.id);
            }

            return list;
        },

        get hasActiveFilters() {
            return this.searchQuery.trim() !== '' || this.selectedPlatform !== 'all' || this.selectedCategory !== 'all' || this.sortBy !== 'default';
        },

        resetFilters() {
            this.searchQuery = '';
            this.selectedPlatform = 'all';
            this.selectedCategory = 'all';
            this.sortBy = 'default';
        },

        openDetails(service) {
            this.activeModalService = service;
        },

        closeDetails() {
            this.activeModalService = null;
        }
    };
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
