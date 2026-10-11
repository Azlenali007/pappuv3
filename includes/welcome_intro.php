<?php
/**
 * Premium Welcome Intro Component
 * SMM Panel - PHP 8.3+
 *
 * Displays a polished, full-screen onboarding experience after every
 * successful user login and registration.
 */

declare(strict_types=1);

if (!isset($_SESSION['show_welcome_intro']) || $_SESSION['show_welcome_intro'] !== true) {
    return;
}

// Consume one-time session trigger so refresh or navigation doesn't replay it
unset($_SESSION['show_welcome_intro']);

$welcomeUsername = !empty($currentUser['username']) ? htmlspecialchars($currentUser['username'], ENT_QUOTES, 'UTF-8') : 'Valued Member';
$welcomeAppName = htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8');
?>

<!-- Welcome Intro Full-Screen Overlay Component -->
<div id="welcomeIntroModal"
     class="fixed inset-0 z-[9999] flex items-center justify-center p-4 sm:p-6 select-none"
     role="dialog"
     aria-modal="true"
     aria-labelledby="welcomeIntroTitle"
     tabindex="-1">

    <!-- Backdrop with premium ultra-soft blur and radial darkening -->
    <div id="welcomeIntroBackdrop"
         onclick="window.dismissWelcomeIntro()"
         class="absolute inset-0 bg-slate-950/75 backdrop-blur-xl transition-opacity duration-350 ease-out"
         style="opacity: 0;"></div>

    <!-- Modal Dialog Window Container -->
    <div id="welcomeIntroCard"
         class="relative w-full max-w-xl bg-white/95 backdrop-blur-2xl rounded-3xl sm:rounded-[2.25rem] border border-white/60 shadow-2xl shadow-indigo-950/30 overflow-hidden z-10 flex flex-col transition-all duration-350 ease-out"
         style="opacity: 0; transform: scale(0.95) translateY(18px);">

        <!-- Ambient decorative top corner gradients -->
        <div class="pointer-events-none absolute -top-24 -left-24 w-64 h-64 bg-gradient-to-br from-blue-400/20 via-indigo-500/15 to-transparent rounded-full blur-2xl"></div>
        <div class="pointer-events-none absolute -bottom-24 -right-24 w-64 h-64 bg-gradient-to-tl from-purple-400/20 via-pink-500/15 to-transparent rounded-full blur-2xl"></div>

        <!-- Top Navigation Bar: Brand Pill + Close Button -->
        <div class="relative px-6 pt-5 sm:px-8 sm:pt-6 flex items-center justify-between">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-50/80 border border-blue-100 text-[11px] font-extrabold text-blue-700 tracking-wide uppercase">
                <span class="w-1.5 h-1.5 rounded-full bg-blue-600 animate-pulse"></span>
                <span><?= $welcomeAppName ?></span>
            </div>

            <!-- Dismiss × Button -->
            <button type="button"
                    onclick="window.dismissWelcomeIntro()"
                    class="w-9 h-9 rounded-2xl bg-slate-100/80 hover:bg-slate-200/90 active:scale-95 text-slate-500 hover:text-slate-800 flex items-center justify-center transition-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-blue-500/40"
                    aria-label="Close welcome modal"
                    title="Close (Esc)">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Slides Viewport (Single active slide displayed, switchable with fluid animation) -->
        <div class="relative px-6 py-4 sm:px-8 sm:py-6 overflow-hidden min-h-[360px] sm:min-h-[380px] flex items-center justify-center">

            <!-- SLIDE 1: Welcome To Your Growth Hub -->
            <div id="welcomeSlide-0" class="welcome-slide w-full flex flex-col items-center text-center">
                <!-- Premium Social Media Illustration -->
                <div class="slide-illustration relative w-48 h-40 sm:w-56 sm:h-44 flex items-center justify-center mb-4">
                    <svg viewBox="0 0 240 180" class="w-full h-full drop-shadow-md" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <linearGradient id="wGradBg1" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#3b82f6" />
                                <stop offset="50%" stop-color="#6366f1" />
                                <stop offset="100%" stop-color="#8b5cf6" />
                            </linearGradient>
                            <linearGradient id="wGradCard1" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#ffffff" stop-opacity="0.95" />
                                <stop offset="100%" stop-color="#f1f5f9" stop-opacity="0.9" />
                            </linearGradient>
                            <linearGradient id="wGradAccent1" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#10b981" />
                                <stop offset="100%" stop-color="#059669" />
                            </linearGradient>
                            <linearGradient id="wGradPink1" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#ec4899" />
                                <stop offset="100%" stop-color="#f43f5e" />
                            </linearGradient>
                        </defs>

                        <!-- Back Glow Circle -->
                        <circle cx="120" cy="90" r="68" fill="url(#wGradBg1)" fill-opacity="0.12" />

                        <!-- Central Social Engagement Hub / Smartphone Frame -->
                        <rect x="75" y="24" width="90" height="132" rx="20" fill="url(#wGradCard1)" stroke="#cbd5e1" stroke-width="2.5" />
                        <rect x="85" y="36" width="70" height="92" rx="10" fill="#f8fafc" />
                        <!-- Top speaker notch -->
                        <line x1="105" y1="30" x2="135" y2="30" stroke="#94a3b8" stroke-width="3" stroke-linecap="round" />
                        <!-- Home bar indicator -->
                        <line x1="105" y1="146" x2="135" y2="146" stroke="#94a3b8" stroke-width="3" stroke-linecap="round" />

                        <!-- Social Profile Avatar inside Phone -->
                        <circle cx="120" cy="58" r="14" fill="url(#wGradBg1)" />
                        <!-- User icon inside avatar -->
                        <circle cx="120" cy="54" r="5" fill="#ffffff" />
                        <path d="M112 66 C112 62 116 60 120 60 C124 60 128 62 128 66 Z" fill="#ffffff" />

                        <!-- Engagement Growth Bars Inside Phone -->
                        <rect x="94" y="80" width="52" height="5" rx="2.5" fill="#cbd5e1" />
                        <rect x="94" y="90" width="36" height="4" rx="2" fill="#e2e8f0" />

                        <!-- Mini Rocket Ascent Indicator -->
                        <path d="M102 116 L120 102 L138 116" stroke="url(#wGradBg1)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                        <line x1="120" y1="102" x2="120" y2="122" stroke="url(#wGradBg1)" stroke-width="3" stroke-linecap="round" />

                        <!-- Floating Social Floating Badge 1: Heart (Instagram/TikTok Engagement) -->
                        <g transform="translate(30, 48)">
                            <rect width="40" height="38" rx="14" fill="#ffffff" stroke="#fecdd3" stroke-width="2" />
                            <path d="M20 27 C20 27 12 21.5 12 16.5 C12 13.5 14.5 12 17 12 C18.5 12 19.5 13 20 14 C20.5 13 21.5 12 23 12 C25.5 12 28 13.5 28 16.5 C28 21.5 20 27 20 27 Z" fill="url(#wGradPink1)" />
                        </g>

                        <!-- Floating Social Floating Badge 2: Upward Growth Arrow (Analytics) -->
                        <g transform="translate(170, 40)">
                            <rect width="40" height="38" rx="14" fill="#ffffff" stroke="#a7f3d0" stroke-width="2" />
                            <path d="M14 26 L20 20 L24 24 L30 15" stroke="url(#wGradAccent1)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                            <polyline points="24,15 30,15 30,21" stroke="url(#wGradAccent1)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                        </g>

                        <!-- Floating Social Floating Badge 3: Play / Reach Indicator -->
                        <g transform="translate(162, 106)">
                            <rect width="42" height="36" rx="12" fill="#ffffff" stroke="#e0e7ff" stroke-width="2" />
                            <circle cx="21" cy="18" r="9" fill="url(#wGradBg1)" />
                            <polygon points="19,14 25,18 19,22" fill="#ffffff" />
                        </g>

                        <!-- Floating Sparkles -->
                        <path d="M48 114 L50 119 L55 121 L50 123 L48 128 L46 123 L41 121 L46 119 Z" fill="#f59e0b" />
                        <path d="M136 18 L138 22 L142 24 L138 26 L136 30 L134 26 L130 24 L134 22 Z" fill="#6366f1" />
                    </svg>
                </div>

                <!-- Slide 1 Text Content -->
                <div class="slide-text max-w-md">
                    <span class="inline-block text-[11px] font-bold text-blue-600 tracking-wider uppercase mb-1">Getting Started</span>
                    <h2 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight leading-tight">
                        Welcome to Your Growth Hub
                    </h2>
                    <p class="text-sm sm:text-base text-slate-500 font-medium mt-2 leading-relaxed">
                        Everything you need to manage your social media services, all in one place.
                    </p>
                </div>
            </div>

            <!-- SLIDE 2: Your Dashboard, Simplified -->
            <div id="welcomeSlide-1" class="welcome-slide w-full flex flex-col items-center text-center hidden">
                <!-- Professional Dashboard, Analytics & Service Management Illustration -->
                <div class="slide-illustration relative w-48 h-40 sm:w-56 sm:h-44 flex items-center justify-center mb-4">
                    <svg viewBox="0 0 240 180" class="w-full h-full drop-shadow-md" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <linearGradient id="wGradDashBg" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#4f46e5" />
                                <stop offset="100%" stop-color="#06b6d4" />
                            </linearGradient>
                            <linearGradient id="wGradBar1" x1="0%" y1="100%" x2="0%" y2="0%">
                                <stop offset="0%" stop-color="#3b82f6" />
                                <stop offset="100%" stop-color="#60a5fa" />
                            </linearGradient>
                            <linearGradient id="wGradBar2" x1="0%" y1="100%" x2="0%" y2="0%">
                                <stop offset="0%" stop-color="#6366f1" />
                                <stop offset="100%" stop-color="#818cf8" />
                            </linearGradient>
                            <linearGradient id="wGradBar3" x1="0%" y1="100%" x2="0%" y2="0%">
                                <stop offset="0%" stop-color="#10b981" />
                                <stop offset="100%" stop-color="#34d399" />
                            </linearGradient>
                        </defs>

                        <!-- Subtle background glow -->
                        <circle cx="120" cy="90" r="70" fill="url(#wGradDashBg)" fill-opacity="0.10" />

                        <!-- Main Dashboard Canvas Card -->
                        <rect x="36" y="22" width="168" height="136" rx="20" fill="#ffffff" stroke="#cbd5e1" stroke-width="2.5" />

                        <!-- Top Dashboard Title Bar -->
                        <rect x="36" y="22" width="168" height="26" rx="20" fill="#f8fafc" />
                        <rect x="36" y="38" width="168" height="10" fill="#f8fafc" />
                        <line x1="36" y1="48" x2="204" y2="48" stroke="#e2e8f0" stroke-width="1.5" />
                        <!-- Mac-style dots -->
                        <circle cx="52" cy="35" r="3.5" fill="#f87171" />
                        <circle cx="63" cy="35" r="3.5" fill="#fbbf24" />
                        <circle cx="74" cy="35" r="3.5" fill="#34d399" />
                        <!-- Header pill -->
                        <rect x="96" y="30" width="88" height="10" rx="5" fill="#e2e8f0" />

                        <!-- Left Metric Widget (Wallet Balance / Order Metrics) -->
                        <rect x="48" y="58" width="66" height="42" rx="10" fill="#eff6ff" stroke="#bfdbfe" stroke-width="1.5" />
                        <circle cx="62" cy="72" r="7" fill="#3b82f6" />
                        <!-- Wallet card mini icon -->
                        <rect x="58" y="69" width="8" height="6" rx="1.5" fill="#ffffff" />
                        <rect x="74" y="67" width="30" height="4" rx="2" fill="#93c5fd" />
                        <rect x="74" y="74" width="22" height="5" rx="2.5" fill="#1d4ed8" />
                        <rect x="56" y="88" width="48" height="4" rx="2" fill="#cbd5e1" />

                        <!-- Right Metric Widget (Live Orders Status) -->
                        <rect x="122" y="58" width="70" height="42" rx="10" fill="#ecfdf5" stroke="#a7f3d0" stroke-width="1.5" />
                        <circle cx="136" cy="72" r="7" fill="#10b981" />
                        <polyline points="133,72 135,74 139,70" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                        <rect x="148" y="67" width="34" height="4" rx="2" fill="#6ee7b7" />
                        <rect x="148" y="74" width="24" height="5" rx="2.5" fill="#047857" />
                        <rect x="130" y="88" width="52" height="4" rx="2" fill="#cbd5e1" />

                        <!-- Bottom Analytics Bar Chart -->
                        <rect x="48" y="108" width="144" height="40" rx="10" fill="#f8fafc" stroke="#e2e8f0" stroke-width="1.5" />
                        <!-- Chart Grid Line -->
                        <line x1="56" y1="138" x2="184" y2="138" stroke="#cbd5e1" stroke-width="1" stroke-dasharray="3 3" />
                        <!-- Chart Bars -->
                        <rect x="66" y="124" width="10" height="14" rx="3" fill="url(#wGradBar1)" />
                        <rect x="84" y="118" width="10" height="20" rx="3" fill="url(#wGradBar1)" />
                        <rect x="102" y="122" width="10" height="16" rx="3" fill="url(#wGradBar2)" />
                        <rect x="120" y="114" width="10" height="24" rx="3" fill="url(#wGradBar2)" />
                        <rect x="138" y="117" width="10" height="21" rx="3" fill="url(#wGradBar3)" />
                        <rect x="156" y="112" width="10" height="26" rx="3" fill="url(#wGradBar3)" />

                        <!-- Growth trend sparkline -->
                        <path d="M71 124 Q 102 110 125 114 T 161 112" stroke="#f59e0b" stroke-width="2.5" stroke-linecap="round" fill="none" />
                        <circle cx="161" cy="112" r="3.5" fill="#f59e0b" />
                    </svg>
                </div>

                <!-- Slide 2 Text Content -->
                <div class="slide-text max-w-md">
                    <span class="inline-block text-[11px] font-bold text-indigo-600 tracking-wider uppercase mb-1">Your Dashboard, Simplified</span>
                    <h2 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight leading-tight">
                        Everything at Your Fingertips
                    </h2>
                    <p class="text-sm sm:text-base text-slate-500 font-medium mt-2 leading-relaxed">
                        Explore services, manage orders, track progress, and control your wallet from one convenient dashboard.
                    </p>
                </div>
            </div>

            <!-- SLIDE 3: Support When You Need It -->
            <div id="welcomeSlide-2" class="welcome-slide w-full flex flex-col items-center text-center hidden">
                <!-- Premium Customer Support & Assistance Illustration -->
                <div class="slide-illustration relative w-48 h-40 sm:w-56 sm:h-44 flex items-center justify-center mb-4">
                    <svg viewBox="0 0 240 180" class="w-full h-full drop-shadow-md" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <linearGradient id="wGradSuppBg" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#0284c7" />
                                <stop offset="100%" stop-color="#2563eb" />
                            </linearGradient>
                            <linearGradient id="wGradChat1" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#ffffff" />
                                <stop offset="100%" stop-color="#f8fafc" />
                            </linearGradient>
                            <linearGradient id="wGradChat2" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#3b82f6" />
                                <stop offset="100%" stop-color="#1d4ed8" />
                            </linearGradient>
                            <linearGradient id="wGradBadge" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#10b981" />
                                <stop offset="100%" stop-color="#047857" />
                            </linearGradient>
                        </defs>

                        <!-- Soft background halo -->
                        <circle cx="120" cy="90" r="72" fill="url(#wGradSuppBg)" fill-opacity="0.10" />

                        <!-- Center Support Specialist Avatar with Headset -->
                        <circle cx="120" cy="74" r="28" fill="#e0f2fe" stroke="#38bdf8" stroke-width="2.5" />
                        <!-- Person Face -->
                        <circle cx="120" cy="68" r="14" fill="#bae6fd" />
                        <!-- Person Body silhouette -->
                        <path d="M100 100 C100 88 108 84 120 84 C132 84 140 88 140 100 Z" fill="#0284c7" />

                        <!-- Modern Headset Arc & Earcups -->
                        <path d="M104 68 C104 54 111 46 120 46 C129 46 136 54 136 68" stroke="#1e293b" stroke-width="3" stroke-linecap="round" fill="none" />
                        <rect x="100" y="62" width="6" height="12" rx="3" fill="#0f172a" />
                        <rect x="134" y="62" width="6" height="12" rx="3" fill="#0f172a" />
                        <!-- Mic boom -->
                        <path d="M104 72 Q 106 82 116 83" stroke="#1e293b" stroke-width="2" stroke-linecap="round" fill="none" />
                        <circle cx="116" cy="83" r="2.5" fill="#3b82f6" />

                        <!-- Left Chat Bubble (User question) -->
                        <g transform="translate(24, 38)">
                            <path d="M12 0 H68 C74.6 0 80 5.4 80 12 V34 C80 40.6 74.6 46 68 46 H26 L12 56 V46 H12 C5.4 46 0 40.6 0 34 V12 C0 5.4 5.4 0 12 0 Z" fill="url(#wGradChat1)" stroke="#cbd5e1" stroke-width="2" />
                            <!-- Chat text lines inside user bubble -->
                            <rect x="14" y="14" width="52" height="4.5" rx="2" fill="#94a3b8" />
                            <rect x="14" y="24" width="36" height="4" rx="2" fill="#cbd5e1" />
                        </g>

                        <!-- Right Chat Bubble (Support Specialist Response) -->
                        <g transform="translate(136, 88)">
                            <path d="M12 0 H68 C74.6 0 80 5.4 80 12 V34 C80 40.6 74.6 46 68 46 H68 V56 L54 46 H12 C5.4 46 0 40.6 0 34 V12 C0 5.4 5.4 0 12 0 Z" fill="url(#wGradChat2)" stroke="#2563eb" stroke-width="2" />
                            <!-- Response lines inside support bubble -->
                            <rect x="14" y="14" width="52" height="4.5" rx="2" fill="#ffffff" />
                            <rect x="14" y="24" width="40" height="4" rx="2" fill="#bfdbfe" />
                            <!-- Checkmarks -->
                            <path d="M58 35 L62 38 L68 32" stroke="#93c5fd" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                        </g>

                        <!-- Verified Fast Response 24/7 Support Desk Badge -->
                        <g transform="translate(100, 122)">
                            <rect width="40" height="36" rx="12" fill="#ffffff" stroke="#a7f3d0" stroke-width="2" />
                            <circle cx="20" cy="18" r="10" fill="url(#wGradBadge)" />
                            <!-- Check icon -->
                            <polyline points="15,18 19,21 25,15" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                        </g>

                        <!-- Floating support stars -->
                        <path d="M38 120 L40 124 L45 125 L41 128 L42 133 L38 130 L34 133 L35 128 L31 125 L36 124 Z" fill="#fbbf24" />
                        <path d="M202 36 L203.5 39 L207 40 L204 42.5 L205 46 L202 43.5 L199 46 L200 42.5 L197 40 L200.5 39 Z" fill="#fbbf24" />
                    </svg>
                </div>

                <!-- Slide 3 Text Content -->
                <div class="slide-text max-w-md">
                    <span class="inline-block text-[11px] font-bold text-sky-600 tracking-wider uppercase mb-1">Support Desk</span>
                    <h2 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight leading-tight">
                        We’re Here to Help
                    </h2>
                    <p class="text-sm sm:text-base text-slate-500 font-medium mt-2 leading-relaxed">
                        Access our 24/7 dedicated support desk whenever you need assistance or order updates.
                    </p>
                </div>
            </div>

        </div>

        <!-- Bottom Controls: Step Dots Indicator & Action Buttons -->
        <div class="px-6 pb-6 pt-2 sm:px-8 sm:pb-8 flex flex-col gap-5 border-t border-slate-100 bg-slate-50/50">
            <!-- Progress Dots -->
            <div class="flex items-center justify-center gap-2" role="tablist" aria-label="Welcome presentation steps">
                <button type="button"
                        onclick="window.goToWelcomeSlide(0)"
                        id="welcomeDot-0"
                        class="w-7 h-2 rounded-full bg-blue-600 transition-all duration-300 focus:outline-none"
                        aria-label="Go to slide 1"></button>
                <button type="button"
                        onclick="window.goToWelcomeSlide(1)"
                        id="welcomeDot-1"
                        class="w-2 h-2 rounded-full bg-slate-300 hover:bg-slate-400 transition-all duration-300 focus:outline-none"
                        aria-label="Go to slide 2"></button>
                <button type="button"
                        onclick="window.goToWelcomeSlide(2)"
                        id="welcomeDot-2"
                        class="w-2 h-2 rounded-full bg-slate-300 hover:bg-slate-400 transition-all duration-300 focus:outline-none"
                        aria-label="Go to slide 3"></button>
            </div>

            <!-- Action Buttons Row -->
            <div class="flex items-center justify-between gap-3">
                <!-- Skip Button (Visible on first two slides) -->
                <button type="button"
                        id="welcomeSkipBtn"
                        onclick="window.dismissWelcomeIntro()"
                        class="px-4 py-2.5 text-xs font-bold text-slate-500 hover:text-slate-800 hover:bg-slate-200/60 rounded-xl transition-all cursor-pointer">
                    Skip Intro
                </button>

                <!-- Next / Continue to Dashboard Button -->
                <div class="flex-1 sm:flex-initial flex justify-end">
                    <!-- Standard Continue Button (Slides 1 & 2) -->
                    <button type="button"
                            id="welcomeNextBtn"
                            onclick="window.nextWelcomeSlide()"
                            class="w-full sm:w-auto px-6 py-3 rounded-2xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 active:scale-95 text-white text-xs sm:text-sm font-extrabold shadow-lg shadow-blue-500/25 transition-all flex items-center justify-center gap-2 cursor-pointer">
                        <span>Continue</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </button>

                    <!-- Final Action Button: "Continue to Dashboard" (Slide 3) -->
                    <button type="button"
                            id="welcomeFinishBtn"
                            onclick="window.dismissWelcomeIntro()"
                            class="hidden w-full sm:w-auto px-7 py-3 rounded-2xl bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 hover:from-blue-700 hover:via-indigo-700 hover:to-purple-700 active:scale-95 text-white text-xs sm:text-sm font-black shadow-lg shadow-indigo-500/30 transition-all flex items-center justify-center gap-2 cursor-pointer">
                        <span>Continue to Dashboard</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * Welcome Intro Controller
 */
(function() {
    let currentSlide = 0;
    const totalSlides = 3;
    let isClosing = false;
    let touchStartX = 0;
    let touchEndX = 0;

    const modal = document.getElementById('welcomeIntroModal');
    const backdrop = document.getElementById('welcomeIntroBackdrop');
    const card = document.getElementById('welcomeIntroCard');
    const nextBtn = document.getElementById('welcomeNextBtn');
    const finishBtn = document.getElementById('welcomeFinishBtn');
    const skipBtn = document.getElementById('welcomeSkipBtn');

    if (!modal || !card) return;

    // Prevent background scrolling while modal is open
    document.body.style.overflow = 'hidden';

    // Premium entrance animation with staggered content
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    requestAnimationFrame(() => {
        if (prefersReducedMotion) {
            backdrop.style.opacity = '1';
            card.style.opacity = '1';
            card.style.transform = 'none';
        } else {
            backdrop.style.opacity = '1';
            setTimeout(() => {
                card.style.opacity = '1';
                card.style.transform = 'scale(1) translateY(0)';
            }, 50);
        }
        modal.focus();
    });

    window.goToWelcomeSlide = function(index) {
        if (index < 0 || index >= totalSlides || index === currentSlide || isClosing) return;

        const currentEl = document.getElementById('welcomeSlide-' + currentSlide);
        const nextEl = document.getElementById('welcomeSlide-' + index);
        const direction = index > currentSlide ? 1 : -1;

        if (currentEl && nextEl) {
            if (prefersReducedMotion) {
                currentEl.classList.add('hidden');
                nextEl.classList.remove('hidden');
            } else {
                // Exit current slide smoothly
                currentEl.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
                currentEl.style.opacity = '0';
                currentEl.style.transform = `translateX(${direction * -20}px)`;

                setTimeout(() => {
                    currentEl.classList.add('hidden');
                    currentEl.style.transform = '';
                    currentEl.style.opacity = '';

                    // Enter new slide
                    nextEl.classList.remove('hidden');
                    nextEl.style.opacity = '0';
                    nextEl.style.transform = `translateX(${direction * 20}px)`;

                    requestAnimationFrame(() => {
                        nextEl.style.transition = 'opacity 0.25s ease, transform 0.25s cubic-bezier(0.16, 1, 0.3, 1)';
                        nextEl.style.opacity = '1';
                        nextEl.style.transform = 'translateX(0)';
                    });
                }, 200);
            }
        }

        currentSlide = index;
        updateControls();
    };

    window.nextWelcomeSlide = function() {
        if (currentSlide < totalSlides - 1) {
            window.goToWelcomeSlide(currentSlide + 1);
        } else {
            window.dismissWelcomeIntro();
        }
    };

    function updateControls() {
        // Update dots
        for (let i = 0; i < totalSlides; i++) {
            const dot = document.getElementById('welcomeDot-' + i);
            if (!dot) continue;
            if (i === currentSlide) {
                dot.className = 'w-7 h-2 rounded-full bg-blue-600 transition-all duration-300 focus:outline-none';
            } else {
                dot.className = 'w-2 h-2 rounded-full bg-slate-300 hover:bg-slate-400 transition-all duration-300 focus:outline-none';
            }
        }

        // Update button visibility
        if (currentSlide === totalSlides - 1) {
            nextBtn.classList.add('hidden');
            finishBtn.classList.remove('hidden');
            skipBtn.classList.add('invisible');
        } else {
            nextBtn.classList.remove('hidden');
            finishBtn.classList.add('hidden');
            skipBtn.classList.remove('invisible');
        }
    }

    window.dismissWelcomeIntro = function() {
        if (isClosing) return;
        isClosing = true;

        if (prefersReducedMotion) {
            document.body.style.overflow = '';
            modal.remove();
            return;
        }

        // Exit animation: fade-out, subtle scale-down, slight upward transition
        card.style.transition = 'opacity 0.25s ease-in, transform 0.25s ease-in';
        backdrop.style.transition = 'opacity 0.25s ease-in';
        card.style.opacity = '0';
        card.style.transform = 'scale(0.96) translateY(-14px)';
        backdrop.style.opacity = '0';

        setTimeout(() => {
            document.body.style.overflow = '';
            modal.remove();
        }, 260);
    };

    // Keyboard navigation (Escape to close, Arrows to step)
    document.addEventListener('keydown', function(e) {
        if (!document.getElementById('welcomeIntroModal') || isClosing) return;
        if (e.key === 'Escape') {
            window.dismissWelcomeIntro();
        } else if (e.key === 'ArrowRight' || e.key === 'Enter') {
            if (currentSlide < totalSlides - 1) {
                window.nextWelcomeSlide();
            } else if (e.key === 'Enter') {
                window.dismissWelcomeIntro();
            }
        } else if (e.key === 'ArrowLeft') {
            if (currentSlide > 0) {
                window.goToWelcomeSlide(currentSlide - 1);
            }
        }
    });

    // Touch Swipe Support
    card.addEventListener('touchstart', function(e) {
        touchStartX = e.changedTouches[0].screenX;
    }, { passive: true });

    card.addEventListener('touchend', function(e) {
        touchEndX = e.changedTouches[0].screenX;
        const diff = touchStartX - touchEndX;
        if (Math.abs(diff) > 45) {
            if (diff > 0 && currentSlide < totalSlides - 1) {
                window.nextWelcomeSlide();
            } else if (diff < 0 && currentSlide > 0) {
                window.goToWelcomeSlide(currentSlide - 1);
            }
        }
    }, { passive: true });
})();
</script>
