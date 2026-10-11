<?php
/**
 * Modern Landing Page with Reference-Matched 3D Service Carousel
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$currentUser = Auth::user();
$isLoggedIn = ($currentUser !== null);
$db = Database::getConnection();

// Fetch REAL services from existing database (strictly up to 10 maximum)
$featuredServices = [];
try {
    $stmt = $db->query("
        SELECT s.*, c.name AS category_name, c.platform
        FROM services s
        JOIN categories c ON s.category_id = c.id
        WHERE s.status = 'active'
        ORDER BY c.sort_order ASC, s.rate_per_1000 ASC, s.id ASC
        LIMIT 10
    ");
    $featuredServices = $stmt->fetchAll() ?: [];
} catch (Exception $e) {
    $featuredServices = [];
}

$servicesCount = count($featuredServices);

// Fetch Active Flash Sales for Landing Page Showcase
$landingFlashSales = get_active_flash_sales(2);
$landingFlashServices = [];

if (!empty($landingFlashSales)) {
    $primarySale = $landingFlashSales[0];
    try {
        if ($primarySale['scope_type'] === 'category' && !empty($primarySale['category_id'])) {
            $fStmt = $db->prepare("
                SELECT s.*, c.name AS category_name, c.platform
                FROM services s
                JOIN categories c ON s.category_id = c.id
                WHERE s.category_id = :cid AND s.status = 'active'
                ORDER BY s.rate_per_1000 ASC
                LIMIT 4
            ");
            $fStmt->execute(['cid' => $primarySale['category_id']]);
            $landingFlashServices = $fStmt->fetchAll() ?: [];
        } elseif ($primarySale['scope_type'] === 'services' && !empty($primarySale['service_ids'])) {
            $sids = array_filter(array_map('intval', explode(',', (string)$primarySale['service_ids'])));
            if (!empty($sids)) {
                $inQuery = implode(',', $sids);
                $fStmt = $db->query("
                    SELECT s.*, c.name AS category_name, c.platform
                    FROM services s
                    JOIN categories c ON s.category_id = c.id
                    WHERE s.id IN ($inQuery) AND s.status = 'active'
                    ORDER BY s.rate_per_1000 ASC
                    LIMIT 4
                ");
                $landingFlashServices = $fStmt->fetchAll() ?: [];
            }
        } else {
            // All services scope
            $fStmt = $db->query("
                SELECT s.*, c.name AS category_name, c.platform
                FROM services s
                JOIN categories c ON s.category_id = c.id
                WHERE s.status = 'active'
                ORDER BY c.sort_order ASC, s.rate_per_1000 ASC
                LIMIT 4
            ");
            $landingFlashServices = $fStmt->fetchAll() ?: [];
        }
    } catch (Exception $e) {
        $landingFlashServices = [];
    }
}

// Helper for rendering high-fidelity platform 3D artwork matching reference image
function render_landing_card_artwork(string $platform, string $serviceName): string {
    $p = strtolower($platform);
    ob_start();
    ?>
    <?php if ($p === 'instagram'): ?>
        <!-- 3D Glossy Instagram Artwork (Reference Style) -->
        <div class="w-full h-52 sm:h-56 md:h-60 rounded-3xl relative overflow-hidden flex items-center justify-center bg-gradient-to-tr from-[#f09433] via-[#dc2743] via-[#cc2366] to-[#bc1888] shadow-inner select-none">
            <!-- Frosted Floating Platform Badge -->
            <div class="absolute top-3.5 left-3.5 z-20 px-3.5 py-1 rounded-full bg-white/20 backdrop-blur-md border border-white/30 text-white text-[11px] font-bold tracking-wide shadow-xs">
                Instagram
            </div>
            <!-- Glow & Depth Highlights -->
            <div class="absolute -top-12 -right-12 w-44 h-44 rounded-full bg-white/25 blur-xl pointer-events-none"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-black/20 via-transparent to-white/20 pointer-events-none"></div>

            <div class="relative flex items-center justify-center">
                <!-- Floating 3D Heart Bubble (Top Right) -->
                <div class="absolute -top-4 -right-8 w-11 h-11 rounded-2xl bg-gradient-to-tr from-pink-400 to-rose-500 shadow-xl shadow-rose-950/40 flex items-center justify-center rotate-12 z-20 border border-white/20">
                    <svg class="w-6 h-6 text-white fill-current drop-shadow-xs" viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                </div>
                <!-- Floating 3D Heart Bubble (Bottom Left) -->
                <div class="absolute -bottom-3 -left-7 w-9 h-9 rounded-xl bg-gradient-to-tr from-pink-400 to-rose-500 shadow-lg shadow-pink-950/40 flex items-center justify-center -rotate-12 z-20 border border-white/20">
                    <svg class="w-4.5 h-4.5 text-white fill-current" viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                </div>
                <!-- Floating +1K Growth Pill (Bottom Right) -->
                <div class="absolute -bottom-4 right-0 px-3.5 py-1 rounded-full bg-gradient-to-r from-purple-600 to-indigo-600 text-white font-black text-xs shadow-xl shadow-purple-950/50 z-20 rotate-6 border border-white/30">
                    +1K
                </div>

                <!-- 3D Glossy Camera Icon Body -->
                <div class="w-24 h-24 sm:w-28 sm:h-28 rounded-[28px] bg-gradient-to-tr from-[#f58529] via-[#dd2a7b] to-[#8134af] p-1.5 shadow-2xl shadow-rose-950/50 relative flex items-center justify-center ring-4 ring-white/30">
                    <div class="w-full h-full rounded-[24px] bg-gradient-to-tr from-[#ff3871] via-[#dd2a7b] to-[#9b34db] flex items-center justify-center relative overflow-hidden">
                        <div class="absolute top-0 left-0 right-0 h-1/2 bg-gradient-to-b from-white/35 to-transparent rounded-t-[24px]"></div>
                        <div class="w-15 h-15 sm:w-17 sm:h-17 rounded-2xl border-4 border-white flex items-center justify-center relative shadow-sm">
                            <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-full border-4 border-white flex items-center justify-center">
                                <div class="w-2 h-2 rounded-full bg-white/80"></div>
                            </div>
                            <div class="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-white"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($p === 'youtube'): ?>
        <!-- 3D Glossy YouTube Artwork (Reference Style) -->
        <div class="w-full h-52 sm:h-56 md:h-60 rounded-3xl relative overflow-hidden flex items-center justify-center bg-gradient-to-tr from-red-600 via-rose-600 to-red-500 shadow-inner select-none">
            <div class="absolute top-3.5 left-3.5 z-20 px-3.5 py-1 rounded-full bg-white/20 backdrop-blur-md border border-white/30 text-white text-[11px] font-bold tracking-wide shadow-xs">
                YouTube
            </div>
            <div class="absolute -top-12 -right-12 w-44 h-44 rounded-full bg-white/25 blur-xl pointer-events-none"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-black/20 via-transparent to-white/20 pointer-events-none"></div>

            <div class="relative flex items-center justify-center">
                <!-- Floating Trending Pill -->
                <div class="absolute -top-3 -right-6 px-3 py-1 rounded-full bg-white/25 backdrop-blur-md text-white font-black text-xs shadow-lg border border-white/30 rotate-6 z-20 flex items-center gap-1">
                    <span>🔥</span> Trending
                </div>
                <div class="absolute -bottom-3 -left-6 w-9 h-9 rounded-xl bg-gradient-to-tr from-red-500 to-rose-600 shadow-lg shadow-red-950/40 flex items-center justify-center -rotate-12 z-20 text-white font-bold text-xs border border-white/20">
                    ▶
                </div>
                <!-- 3D Red Play Button Body -->
                <div class="w-28 h-20 sm:w-32 sm:h-22 rounded-3xl bg-gradient-to-b from-red-500 via-red-600 to-red-700 shadow-2xl shadow-red-950/50 relative flex items-center justify-center ring-4 ring-white/30">
                    <div class="absolute top-0 left-0 right-0 h-1/2 bg-gradient-to-b from-white/35 to-transparent rounded-t-3xl"></div>
                    <div class="w-0 h-0 border-y-12 sm:border-y-14 border-y-transparent border-l-20 sm:border-l-24 border-l-white ml-2 drop-shadow-[0_4px_6px_rgba(0,0,0,0.3)]"></div>
                </div>
            </div>
        </div>

    <?php elseif ($p === 'telegram'): ?>
        <!-- 3D Glossy Telegram Artwork (Reference Style) -->
        <div class="w-full h-52 sm:h-56 md:h-60 rounded-3xl relative overflow-hidden flex items-center justify-center bg-gradient-to-tr from-sky-400 via-sky-500 to-blue-600 shadow-inner select-none">
            <div class="absolute top-3.5 left-3.5 z-20 px-3.5 py-1 rounded-full bg-white/20 backdrop-blur-md border border-white/30 text-white text-[11px] font-bold tracking-wide shadow-xs">
                Telegram
            </div>
            <div class="absolute -top-12 -right-12 w-44 h-44 rounded-full bg-white/25 blur-xl pointer-events-none"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-black/15 via-transparent to-white/20 pointer-events-none"></div>

            <div class="relative flex items-center justify-center">
                <!-- Floating Speed Badge -->
                <div class="absolute -top-3 -right-6 px-3 py-1 rounded-full bg-white/25 backdrop-blur-md text-white font-black text-xs shadow-lg border border-white/30 rotate-6 z-20 flex items-center gap-1">
                    <span>⚡</span> Instant
                </div>
                <!-- 3D Blue Disc Body -->
                <div class="w-24 h-24 sm:w-28 sm:h-28 rounded-full bg-gradient-to-tr from-sky-400 via-sky-500 to-blue-600 shadow-2xl shadow-sky-950/50 relative flex items-center justify-center ring-4 ring-white/30">
                    <div class="absolute top-0 left-0 right-0 h-1/2 bg-gradient-to-b from-white/35 to-transparent rounded-t-full"></div>
                    <svg class="w-13 h-13 sm:w-15 sm:h-15 text-white fill-current transform -translate-x-1 translate-y-0.5 drop-shadow-[0_4px_8px_rgba(0,0,0,0.25)]" viewBox="0 0 24 24">
                        <path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221l-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.446 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.121l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.458c.536-.196 1.006.128.832.943z"/>
                    </svg>
                </div>
            </div>
        </div>

    <?php elseif ($p === 'facebook'): ?>
        <!-- 3D Glossy Facebook Artwork -->
        <div class="w-full h-52 sm:h-56 md:h-60 rounded-3xl relative overflow-hidden flex items-center justify-center bg-gradient-to-tr from-blue-600 via-indigo-600 to-blue-700 shadow-inner select-none">
            <div class="absolute top-3.5 left-3.5 z-20 px-3.5 py-1 rounded-full bg-white/20 backdrop-blur-md border border-white/30 text-white text-[11px] font-bold tracking-wide shadow-xs">
                Facebook
            </div>
            <div class="absolute -top-12 -right-12 w-44 h-44 rounded-full bg-white/25 blur-xl pointer-events-none"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-black/15 via-transparent to-white/20 pointer-events-none"></div>
            <div class="relative flex items-center justify-center">
                <div class="absolute -top-3 -right-6 w-11 h-11 rounded-2xl bg-gradient-to-tr from-blue-500 to-indigo-600 shadow-xl shadow-blue-950/40 flex items-center justify-center rotate-12 z-20 text-white border border-white/20">
                    👍
                </div>
                <div class="w-24 h-24 sm:w-28 sm:h-28 rounded-[28px] bg-gradient-to-tr from-blue-600 via-blue-700 to-indigo-800 shadow-2xl shadow-blue-950/50 relative flex items-center justify-center ring-4 ring-white/30">
                    <div class="absolute top-0 left-0 right-0 h-1/2 bg-gradient-to-b from-white/35 to-transparent rounded-t-[28px]"></div>
                    <span class="text-white font-black text-5xl sm:text-6xl drop-shadow-[0_4px_8px_rgba(0,0,0,0.3)]">f</span>
                </div>
            </div>
        </div>

    <?php elseif ($p === 'tiktok'): ?>
        <!-- 3D Glossy TikTok Artwork -->
        <div class="w-full h-52 sm:h-56 md:h-60 rounded-3xl relative overflow-hidden flex items-center justify-center bg-gradient-to-tr from-slate-950 via-zinc-900 to-black shadow-inner select-none">
            <div class="absolute top-3.5 left-3.5 z-20 px-3.5 py-1 rounded-full bg-white/20 backdrop-blur-md border border-white/30 text-white text-[11px] font-bold tracking-wide shadow-xs">
                TikTok
            </div>
            <div class="absolute -top-12 -right-12 w-44 h-44 rounded-full bg-cyan-400/20 blur-xl pointer-events-none"></div>
            <div class="absolute -bottom-12 -left-12 w-44 h-44 rounded-full bg-rose-500/20 blur-xl pointer-events-none"></div>
            <div class="relative flex items-center justify-center">
                <div class="absolute -top-3 -right-6 px-3 py-1 rounded-full bg-white/20 backdrop-blur-md text-white font-black text-xs shadow-lg border border-white/30 rotate-6 z-20 flex items-center gap-1">
                    <span>🎵</span> Viral
                </div>
                <div class="w-24 h-24 sm:w-28 sm:h-28 rounded-[28px] bg-gradient-to-tr from-zinc-900 via-black to-zinc-950 shadow-2xl shadow-cyan-950/40 relative flex items-center justify-center ring-4 ring-white/25">
                    <div class="absolute top-0 left-0 right-0 h-1/2 bg-gradient-to-b from-white/25 to-transparent rounded-t-[28px]"></div>
                    <svg class="w-13 h-13 sm:w-15 sm:h-15 text-white fill-current drop-shadow-[2px_2px_0px_#00f2fe] drop-shadow-[-2px_-2px_0px_#fe0979]" viewBox="0 0 24 24">
                        <path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.24 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.89 1.53-.02 2.87-1.12 3.18-2.61.12-.59.13-1.2.12-1.8V.02h-.01z"/>
                    </svg>
                </div>
            </div>
        </div>

    <?php elseif ($p === 'twitter'): ?>
        <!-- 3D Glossy Twitter (X) Artwork -->
        <div class="w-full h-52 sm:h-56 md:h-60 rounded-3xl relative overflow-hidden flex items-center justify-center bg-gradient-to-tr from-slate-900 via-slate-800 to-zinc-950 shadow-inner select-none">
            <div class="absolute top-3.5 left-3.5 z-20 px-3.5 py-1 rounded-full bg-white/20 backdrop-blur-md border border-white/30 text-white text-[11px] font-bold tracking-wide shadow-xs">
                Twitter (X)
            </div>
            <div class="absolute -top-12 -right-12 w-44 h-44 rounded-full bg-white/20 blur-xl pointer-events-none"></div>
            <div class="relative flex items-center justify-center">
                <div class="w-24 h-24 sm:w-28 sm:h-28 rounded-[28px] bg-gradient-to-tr from-slate-900 via-black to-slate-950 shadow-2xl shadow-slate-950/50 relative flex items-center justify-center ring-4 ring-white/25">
                    <div class="absolute top-0 left-0 right-0 h-1/2 bg-gradient-to-b from-white/30 to-transparent rounded-t-[28px]"></div>
                    <svg class="w-13 h-13 sm:w-15 sm:h-15 text-white fill-current drop-shadow-[0_4px_8px_rgba(0,0,0,0.35)]" viewBox="0 0 24 24">
                        <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                    </svg>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- 3D Glossy Growth Artwork -->
        <div class="w-full h-52 sm:h-56 md:h-60 rounded-3xl relative overflow-hidden flex items-center justify-center bg-gradient-to-tr from-indigo-600 via-blue-600 to-cyan-500 shadow-inner select-none">
            <div class="absolute top-3.5 left-3.5 z-20 px-3.5 py-1 rounded-full bg-white/20 backdrop-blur-md border border-white/30 text-white text-[11px] font-bold tracking-wide shadow-xs">
                <?= e(ucfirst($platform ?: 'Growth')) ?>
            </div>
            <div class="absolute -top-12 -right-12 w-44 h-44 rounded-full bg-white/25 blur-xl pointer-events-none"></div>
            <div class="relative flex items-center justify-center">
                <div class="w-24 h-24 sm:w-28 sm:h-28 rounded-[28px] bg-gradient-to-tr from-indigo-500 via-blue-600 to-cyan-500 shadow-2xl shadow-blue-950/50 relative flex items-center justify-center ring-4 ring-white/30">
                    <div class="absolute top-0 left-0 right-0 h-1/2 bg-gradient-to-b from-white/35 to-transparent rounded-t-[28px]"></div>
                    <span class="text-white text-4xl sm:text-5xl drop-shadow-[0_4px_8px_rgba(0,0,0,0.3)]">⚡</span>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

$pageTitle = "SMM Panel - Boost Your Social Media";
require_once __DIR__ . '/includes/header.php';
?>

<!-- Hero Section -->
<section class="py-12 md:py-20 text-center relative overflow-hidden">
    <!-- Ambient Soft Background Accents (Matching Reference Image) -->
    <div class="absolute -top-24 -left-24 w-96 h-96 bg-blue-100/50 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute top-1/3 -right-24 w-96 h-96 bg-sky-100/60 rounded-full blur-3xl pointer-events-none"></div>

    <!-- Accent Top Badge -->
    <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-blue-50 border border-blue-200/60 text-blue-600 text-xs font-bold uppercase tracking-widest mb-6 shadow-xs">
        <svg class="w-4 h-4 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
            <path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/>
        </svg>
        Social Media Growth
    </div>

    <!-- Main Headline -->
    <h1 class="text-4xl sm:text-6xl font-extrabold text-slate-900 tracking-tight max-w-4xl mx-auto leading-tight px-4">
        Boost Your Social Media <br>
        <span class="font-script text-5xl sm:text-7xl font-bold text-blue-600 inline-block -rotate-1 mt-1">With Our SMM Panel</span>
    </h1>

    <p class="mt-6 text-base sm:text-lg text-slate-600 max-w-2xl mx-auto leading-relaxed px-4">
        Get real followers, likes, views and more. Fast, secure and affordable services for all major social media platforms.
    </p>

    <!-- 4 Key Badges -->
    <div class="mt-8 flex flex-wrap items-center justify-center gap-3 sm:gap-6 text-xs sm:text-sm font-semibold text-slate-700 px-4">
        <div class="flex items-center gap-2 bg-white px-4 py-2 rounded-xl shadow-xs border border-slate-200/80">
            <span class="text-blue-600">⚡</span> Fast Delivery
        </div>
        <div class="flex items-center gap-2 bg-white px-4 py-2 rounded-xl shadow-xs border border-slate-200/80">
            <span class="text-blue-600">🔒</span> 100% Safe
        </div>
        <div class="flex items-center gap-2 bg-white px-4 py-2 rounded-xl shadow-xs border border-slate-200/80">
            <span class="text-amber-500">⭐</span> High Quality
        </div>
        <div class="flex items-center gap-2 bg-white px-4 py-2 rounded-xl shadow-xs border border-slate-200/80">
            <span class="text-blue-600">💬</span> 24/7 Support
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- 3D CAROUSEL SECTION (MATCHING REFERENCE IMAGE VISUAL SPECIFICATION) -->
    <!-- ================================================================= -->
    <div class="mt-14 sm:mt-18 relative max-w-6xl mx-auto px-4 overflow-x-clip py-4">
        <?php if ($servicesCount === 0): ?>
            <!-- Real Database Empty State -->
            <div class="max-w-md mx-auto bg-white rounded-3xl p-10 border border-slate-200/80 shadow-xl text-center space-y-4">
                <div class="w-16 h-16 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center mx-auto text-2xl shadow-inner">
                    ⚡
                </div>
                <h3 class="text-lg font-extrabold text-slate-900">No Active Services Available</h3>
                <p class="text-xs text-slate-500 leading-relaxed">
                    Our catalog is currently being updated. Services will appear here as soon as they are configured in the system.
                </p>
                <div class="pt-2">
                    <a href="/login.php" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 text-white text-xs font-bold rounded-xl shadow-md">
                        Check Client Portal &rarr;
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div x-data="landingCarousel(<?= $servicesCount ?>)" class="relative w-full select-none" style="perspective: 1200px; -webkit-perspective: 1200px;">
                
                <!-- 3D Carousel Stage Area -->
                <div class="relative w-full h-[520px] sm:h-[550px] md:h-[570px] flex items-center justify-center overflow-visible"
                     style="transform-style: preserve-3d; -webkit-transform-style: preserve-3d;"
                     @touchstart="touchStart($event)"
                     @touchmove="touchMove($event)"
                     @touchend="touchEnd($event)"
                     @mousedown="dragStart($event)"
                     @mousemove="dragMove($event)"
                     @mouseup="dragEnd($event)"
                     @mouseleave="dragEnd($event)">

                    <?php foreach ($featuredServices as $index => $s): 
                        $platform = strtolower($s['platform'] ?? 'other');
                        $orderUrl = $isLoggedIn 
                            ? '/user/new-order.php?service_id=' . (int)$s['id'] 
                            : '/login.php?redirect=' . urlencode('/user/new-order.php?service_id=' . (int)$s['id']);
                        $speedLabel = !empty($s['speed']) ? $s['speed'] : 'Fast Delivery';
                        $subDesc = !empty($s['description']) 
                            ? $s['description'] 
                            : 'Real & Active • High Quality • Fast Delivery';
                    ?>
                        <!-- 3D Card Item -->
                        <div class="absolute w-[290px] sm:w-[330px] md:w-[360px] bg-white rounded-[32px] p-4 sm:p-5 shadow-2xl transition-all duration-500 ease-[cubic-bezier(0.25,1,0.5,1)] flex flex-col justify-between border border-white/80 ring-1 ring-slate-100"
                             :style="getCardStyle(<?= $index ?>)"
                             :class="active === <?= $index ?> ? 'shadow-2xl shadow-blue-500/20 ring-2 ring-blue-100' : 'shadow-xl shadow-slate-900/10 cursor-pointer'"
                             @click="cardClick($event, <?= $index ?>, '<?= e($orderUrl) ?>')">

                            <!-- Top Large Artwork / Icon Area (Platform Specific 3D Visual) -->
                            <?= render_landing_card_artwork($platform, $s['name']) ?>

                            <!-- Bottom Card Info & Price Section -->
                            <div class="pt-4 text-left flex flex-col justify-between flex-1">
                                <div>
                                    <!-- Service Title -->
                                    <h3 class="text-base sm:text-lg font-extrabold text-slate-900 tracking-tight leading-snug line-clamp-1">
                                        <?= e($s['name']) ?>
                                    </h3>

                                    <!-- Subtitle / Guarantee Line -->
                                    <p class="text-xs text-slate-500 font-medium mt-1 truncate">
                                        <?= e($subDesc) ?>
                                    </p>
                                </div>

                                <!-- Price Row with Speed Badge -->
                                <div class="mt-4 mb-4 flex items-baseline justify-between gap-2">
                                    <div class="flex items-baseline">
                                        <span class="text-2xl sm:text-3xl font-black text-slate-900 tabular-nums font-mono-nums">
                                            <?= format_currency((float)$s['rate_per_1000']) ?>
                                        </span>
                                        <span class="text-xs font-semibold text-slate-400 ml-1">/ 1K</span>
                                    </div>
                                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-600 border border-emerald-100/60 whitespace-nowrap">
                                        ⚡ <?= e($speedLabel) ?>
                                    </span>
                                </div>

                                <!-- Modern Blue CTA Button (Matching Reference) -->
                                <a href="<?= e($orderUrl) ?>" 
                                   @click="buttonClick($event, '<?= e($orderUrl) ?>')"
                                   class="w-full py-3.5 px-6 bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-700 hover:to-blue-600 active:scale-98 text-white font-bold text-xs sm:text-sm rounded-2xl shadow-lg shadow-blue-500/30 transition-all flex items-center justify-center gap-2 group">
                                    <!-- Shopping Cart Icon -->
                                    <svg class="w-4 h-4 fill-none stroke-current" stroke-width="2.2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                                    </svg>
                                    <span>Order Now</span>
                                    <span class="text-base group-hover:translate-x-1 transition-transform">&rarr;</span>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Carousel Controls: Indicators (● ○ ○ ○ ○) & Arrows -->
                <div class="flex items-center justify-center gap-4 mt-6">
                    <!-- Left Arrow Button -->
                    <button type="button" 
                            @click="prev()" 
                            aria-label="Previous service" 
                            class="w-8 h-8 rounded-full bg-white border border-slate-200/80 shadow-xs flex items-center justify-center text-slate-600 hover:text-blue-600 hover:bg-slate-50 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                    </button>

                    <!-- Indicators Dots -->
                    <div class="flex items-center gap-2">
                        <?php for ($i = 0; $i < $servicesCount; $i++): ?>
                            <button type="button" 
                                    @click="goTo(<?= $i ?>)" 
                                    aria-label="Go to service <?= $i + 1 ?>"
                                    class="h-2.5 rounded-full transition-all duration-300"
                                    :class="active === <?= $i ?> ? 'w-8 bg-blue-600 shadow-sm shadow-blue-500/40' : 'w-2.5 bg-blue-200/80 hover:bg-blue-300'">
                            </button>
                        <?php endfor; ?>
                    </div>

                    <!-- Right Arrow Button -->
                    <button type="button" 
                            @click="next()" 
                            aria-label="Next service" 
                            class="w-8 h-8 rounded-full bg-white border border-slate-200/80 shadow-xs flex items-center justify-center text-slate-600 hover:text-blue-600 hover:bg-slate-50 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($landingFlashSales)): 
        $activeSale = $landingFlashSales[0];
    ?>
    <!-- ================================================================= -->
    <!-- FLASH SALE LANDING SHOWCASE BANNER (AFTER CARDS SECTION)          -->
    <!-- ================================================================= -->
    <div class="mt-14 sm:mt-18 max-w-6xl mx-auto px-4" x-data="landingFlashSale(<?= htmlspecialchars(json_encode($activeSale), ENT_QUOTES, 'UTF-8') ?>)">
        <div class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-blue-700 via-indigo-700 to-purple-800 text-white p-6 sm:p-8 shadow-2xl shadow-indigo-950/20">
            <!-- Decorative Glow Elements -->
            <div class="absolute -right-20 -top-20 w-80 h-80 bg-pink-500/20 rounded-full blur-3xl pointer-events-none"></div>
            <div class="absolute -left-20 -bottom-20 w-80 h-80 bg-blue-400/20 rounded-full blur-3xl pointer-events-none"></div>

            <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                <!-- Left Details -->
                <div class="text-left max-w-2xl">
                    <div class="flex flex-wrap items-center gap-2 mb-3">
                        <span class="inline-flex items-center gap-1.5 px-3.5 py-1 rounded-full text-xs font-black uppercase tracking-wider bg-rose-500 text-white shadow-md shadow-rose-950/30">
                            <span class="w-2 h-2 rounded-full bg-white animate-pulse"></span>
                            <span>Limited Time Event</span>
                        </span>
                        <span class="px-3 py-1 rounded-full text-xs font-black bg-white/20 backdrop-blur-md text-white border border-white/30 shadow-xs">
                            <?= e($activeSale['badge_text']) ?>
                        </span>
                        <?php if (!empty($activeSale['category_name'])): ?>
                            <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-white/10 text-indigo-100 border border-white/20">
                                <?= e($activeSale['category_name']) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <h2 class="text-2xl sm:text-3xl font-black text-white tracking-tight leading-tight">
                        ⚡ <?= e($activeSale['title']) ?>
                    </h2>
                    <p class="text-xs sm:text-sm text-indigo-100/90 mt-2 leading-relaxed">
                        <?= e($activeSale['subtitle'] ?: 'Boost your social presence today with massive limited-time savings across our highest rated services.') ?>
                    </p>
                </div>

                <!-- Right Countdown & CTA -->
                <div class="flex flex-col sm:flex-row lg:flex-col items-start lg:items-end gap-4 shrink-0">
                    <div>
                        <span class="text-[10px] font-black uppercase tracking-widest text-indigo-200 block mb-1 text-left lg:text-right">Hurry! Offer Ends In</span>
                        <div class="flex items-center gap-1.5">
                            <div class="flex flex-col items-center bg-black/40 backdrop-blur-md border border-white/20 text-white px-3 py-2 rounded-2xl min-w-[50px] shadow-inner">
                                <span class="text-base sm:text-lg font-black font-mono leading-none" x-text="pad(hours)">00</span>
                                <span class="text-[8px] font-extrabold uppercase text-indigo-200 tracking-wider mt-1">HRS</span>
                            </div>
                            <span class="text-white/60 font-black text-base">:</span>
                            <div class="flex flex-col items-center bg-black/40 backdrop-blur-md border border-white/20 text-white px-3 py-2 rounded-2xl min-w-[50px] shadow-inner">
                                <span class="text-base sm:text-lg font-black font-mono leading-none" x-text="pad(minutes)">00</span>
                                <span class="text-[8px] font-extrabold uppercase text-indigo-200 tracking-wider mt-1">MIN</span>
                            </div>
                            <span class="text-white/60 font-black text-base">:</span>
                            <div class="flex flex-col items-center bg-rose-600 border border-rose-400 text-white px-3 py-2 rounded-2xl min-w-[50px] shadow-md shadow-rose-950/40">
                                <span class="text-base sm:text-lg font-black font-mono leading-none" x-text="pad(seconds)">00</span>
                                <span class="text-[8px] font-bold uppercase text-rose-200 tracking-wider mt-1">SEC</span>
                            </div>
                        </div>
                    </div>

                    <a href="<?= $isLoggedIn ? '/user/services.php?sale_id=' . (int)$activeSale['id'] : '/login.php?redirect=' . urlencode('/user/services.php?sale_id=' . (int)$activeSale['id']) ?>" 
                       class="px-6 py-3 rounded-2xl bg-white hover:bg-indigo-50 text-indigo-900 font-black text-xs sm:text-sm shadow-xl shadow-black/20 flex items-center gap-2 hover:scale-105 active:scale-95 transition-all">
                        <span>View All Deals</span>
                        <svg class="w-4 h-4 text-indigo-900" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </a>
                </div>
            </div>

            <!-- Real Discounted Services Grid -->
            <?php if (!empty($landingFlashServices)): ?>
                <div class="mt-8 pt-6 border-t border-white/15">
                    <span class="text-[11px] font-black uppercase tracking-wider text-indigo-200 block text-left mb-4">
                        Featured Flash Deals (<?= count($landingFlashServices) ?> Services on Sale)
                    </span>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 text-left">
                        <?php foreach ($landingFlashServices as $fsvc): 
                            $fDisc = get_service_flash_sale_discount((int)$fsvc['id'], (int)$fsvc['category_id'], (float)$fsvc['rate_per_1000'], [$activeSale]);
                            $origRate = (float)$fsvc['rate_per_1000'];
                            $saleRate = $fDisc ? $fDisc['sale_rate'] : $origRate;
                            $discPercent = $fDisc ? $fDisc['discount_percent'] : 0;
                            $targetOrderUrl = $isLoggedIn 
                                ? '/user/new-order.php?service_id=' . (int)$fsvc['id'] 
                                : '/login.php?redirect=' . urlencode('/user/new-order.php?service_id=' . (int)$fsvc['id']);
                        ?>
                            <div class="bg-white/10 backdrop-blur-md border border-white/20 rounded-2xl p-4 flex flex-col justify-between hover:bg-white/15 transition-all group">
                                <div>
                                    <div class="flex items-center justify-between gap-2 mb-2">
                                        <span class="px-2 py-0.5 rounded-lg bg-white/20 text-[10px] font-extrabold uppercase tracking-wide text-white">
                                            <?= e($fsvc['platform'] ?: 'Service') ?>
                                        </span>
                                        <span class="px-2 py-0.5 rounded-full bg-rose-500 text-[10px] font-black text-white shadow-xs">
                                            -<?= $discPercent ?>% OFF
                                        </span>
                                    </div>
                                    <h4 class="text-xs font-bold text-white line-clamp-2 group-hover:text-amber-200 transition-colors">
                                        <?= e($fsvc['name']) ?>
                                    </h4>
                                </div>

                                <div class="mt-4 pt-3 border-t border-white/10 flex items-center justify-between">
                                    <div>
                                        <span class="text-[10px] text-indigo-200/80 line-through block"><?= format_currency($origRate) ?></span>
                                        <span class="text-sm font-black text-white"><?= format_currency($saleRate) ?> <span class="text-[9px] text-indigo-200 font-medium">/ 1K</span></span>
                                    </div>
                                    <a href="<?= $targetOrderUrl ?>" class="px-3 py-1.5 rounded-xl bg-white text-indigo-900 font-extrabold text-[11px] hover:bg-indigo-50 shadow-sm transition-all">
                                        Order Now
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Supported Platforms Section (Preserved) -->
    <div class="mt-20 sm:mt-24">
        <span class="text-xs font-bold text-slate-400 uppercase tracking-widest block mb-2">Supported Platforms</span>
        <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900">All Major Social Media Platforms</h2>
        
        <div class="mt-8 flex flex-wrap items-center justify-center gap-4 sm:gap-6 max-w-4xl mx-auto px-4">
            <a href="/services.php?platform=instagram" class="flex flex-col items-center gap-2 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-xs w-28 hover:shadow-md hover:-translate-y-1 transition-all">
                <div class="w-12 h-12 rounded-xl bg-gradient-to-tr from-amber-500 via-rose-500 to-purple-600 flex items-center justify-center text-white text-xl font-bold shadow-md">IG</div>
                <span class="text-xs font-semibold text-slate-700">Instagram</span>
            </a>
            <a href="/services.php?platform=youtube" class="flex flex-col items-center gap-2 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-xs w-28 hover:shadow-md hover:-translate-y-1 transition-all">
                <div class="w-12 h-12 rounded-xl bg-red-600 flex items-center justify-center text-white text-xl font-bold shadow-md">YT</div>
                <span class="text-xs font-semibold text-slate-700">YouTube</span>
            </a>
            <a href="/services.php?platform=telegram" class="flex flex-col items-center gap-2 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-xs w-28 hover:shadow-md hover:-translate-y-1 transition-all">
                <div class="w-12 h-12 rounded-xl bg-sky-500 flex items-center justify-center text-white text-xl font-bold shadow-md">TG</div>
                <span class="text-xs font-semibold text-slate-700">Telegram</span>
            </a>
            <a href="/services.php?platform=facebook" class="flex flex-col items-center gap-2 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-xs w-28 hover:shadow-md hover:-translate-y-1 transition-all">
                <div class="w-12 h-12 rounded-xl bg-blue-600 flex items-center justify-center text-white text-xl font-bold shadow-md">FB</div>
                <span class="text-xs font-semibold text-slate-700">Facebook</span>
            </a>
            <a href="/services.php?platform=tiktok" class="flex flex-col items-center gap-2 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-xs w-28 hover:shadow-md hover:-translate-y-1 transition-all">
                <div class="w-12 h-12 rounded-xl bg-black flex items-center justify-center text-white text-xl font-bold shadow-md">TT</div>
                <span class="text-xs font-semibold text-slate-700">TikTok</span>
            </a>
            <a href="/services.php?platform=twitter" class="flex flex-col items-center gap-2 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-xs w-28 hover:shadow-md hover:-translate-y-1 transition-all">
                <div class="w-12 h-12 rounded-xl bg-slate-900 flex items-center justify-center text-white text-xl font-bold shadow-md">X</div>
                <span class="text-xs font-semibold text-slate-700">Twitter (X)</span>
            </a>
        </div>
    </div>

    <!-- Slogan Accent (Preserved) -->
    <div class="mt-20">
        <h3 class="font-script text-4xl sm:text-5xl font-bold text-blue-600">Grow Faster, Smarter!</h3>
    </div>
</section>

<script>
function landingCarousel(count) {
    return {
        active: count > 2 ? 1 : 0, // Focus the second service in center on load if multiple exist
        count: count,
        startX: 0,
        startY: 0,
        deltaX: 0,
        isSwiping: false,
        isDragging: false,

        goTo(index) {
            if (index < 0) index = 0;
            if (index >= this.count) index = this.count - 1;
            this.active = index;
        },

        next() {
            this.active = (this.active + 1) % this.count;
        },

        prev() {
            this.active = (this.active - 1 + this.count) % this.count;
        },

        // Touch & swipe handling
        touchStart(e) {
            if (e.touches && e.touches.length > 0) {
                this.startX = e.touches[0].clientX;
                this.startY = e.touches[0].clientY;
                this.deltaX = 0;
                this.isSwiping = false;
            }
        },

        touchMove(e) {
            if (this.startX === 0 || !e.touches || e.touches.length === 0) return;
            this.deltaX = e.touches[0].clientX - this.startX;
            const deltaY = e.touches[0].clientY - this.startY;
            if (Math.abs(this.deltaX) > 12 && Math.abs(this.deltaX) > Math.abs(deltaY)) {
                this.isSwiping = true;
            }
        },

        touchEnd(e) {
            if (this.isSwiping && Math.abs(this.deltaX) > 40) {
                if (this.deltaX < 0) {
                    this.next();
                } else {
                    this.prev();
                }
            }
            this.startX = 0;
            this.deltaX = 0;
            setTimeout(() => { this.isSwiping = false; }, 80);
        },

        // Mouse drag handling for desktop
        dragStart(e) {
            // Ignore drag if clicking directly on link or button
            if (e.target.closest('a') || e.target.closest('button')) return;
            this.startX = e.clientX;
            this.deltaX = 0;
            this.isDragging = true;
            this.isSwiping = false;
        },

        dragMove(e) {
            if (!this.isDragging) return;
            this.deltaX = e.clientX - this.startX;
            if (Math.abs(this.deltaX) > 15) {
                this.isSwiping = true;
            }
        },

        dragEnd(e) {
            if (this.isDragging && this.isSwiping && Math.abs(this.deltaX) > 40) {
                if (this.deltaX < 0) {
                    this.next();
                } else {
                    this.prev();
                }
            }
            this.isDragging = false;
            this.startX = 0;
            this.deltaX = 0;
            setTimeout(() => { this.isSwiping = false; }, 80);
        },

        cardClick(e, index, url) {
            if (this.isSwiping) {
                e.preventDefault();
                return;
            }
            if (this.active !== index) {
                e.preventDefault();
                this.goTo(index);
            }
        },

        buttonClick(e, url) {
            if (this.isSwiping) {
                e.preventDefault();
                return;
            }
            // Proceed normally with link click navigation
        },

        // 3D Perspective placement math matching reference image
        getCardStyle(index) {
            if (this.count <= 1) {
                return 'transform: translateX(0) scale(1) translateZ(40px); z-index: 30; opacity: 1;';
            }

            let diff = index - this.active;

            // Handle cyclic wrap for carousel
            if (diff > this.count / 2) diff -= this.count;
            if (diff < -this.count / 2) diff += this.count;

            const isMobile = window.innerWidth < 640;
            const xOffset = isMobile ? 62 : 60; // percentage offset

            if (diff === 0) {
                // Center prominent card (Faces forward, largest scale, high z-index, strong depth)
                return 'transform: translateX(0%) scale(1) rotateY(0deg) translateZ(60px); z-index: 30; opacity: 1; pointer-events: auto;';
            } else if (diff === -1) {
                // Left card angled towards user
                return 'transform: translateX(-' + xOffset + '%) scale(0.86) rotateY(18deg) translateZ(-40px); z-index: 20; opacity: 0.95;';
            } else if (diff === 1) {
                // Right card angled towards user
                return 'transform: translateX(' + xOffset + '%) scale(0.86) rotateY(-18deg) translateZ(-40px); z-index: 20; opacity: 0.95;';
            } else if (diff === -2) {
                // Far left peeking card
                return 'transform: translateX(-' + (xOffset * 1.7) + '%) scale(0.72) rotateY(25deg) translateZ(-110px); z-index: 10; opacity: 0.5;';
            } else if (diff === 2) {
                // Far right peeking card
                return 'transform: translateX(' + (xOffset * 1.7) + '%) scale(0.72) rotateY(-25deg) translateZ(-110px); z-index: 10; opacity: 0.5;';
            } else {
                // Rest of cards tucked behind
                const dir = diff < 0 ? '-' : '';
                return 'transform: translateX(' + dir + '200%) scale(0.6) translateZ(-160px); z-index: 5; opacity: 0; pointer-events: none;';
            }
        }
    };
}

function landingFlashSale(sale) {
    return {
        sale: sale || {},
        hours: 0,
        minutes: 0,
        seconds: 0,
        timer: null,

        init() {
            if (this.sale && this.sale.ends_at) {
                this.updateCountdown();
                this.timer = setInterval(() => {
                    this.updateCountdown();
                }, 1000);
            }
        },

        updateCountdown() {
            if (!this.sale || !this.sale.ends_at) return;
            const target = new Date(this.sale.ends_at.replace(' ', 'T')).getTime();
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
