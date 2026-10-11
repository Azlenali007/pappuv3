<?php
/**
 * User New Order Portal
 * SMM Panel - PHP 8.3+
 *
 * Implements two-step order flow:
 * Step 1: Configure (Category -> Service -> Target URL -> Quantity -> Continue to Checkout)
 * Step 2: Checkout (Charge preview, Order summary, Wallet safety & Live submission)
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

// 1. Fetch real active categories from MySQL database
$categoriesStmt = $db->query("
    SELECT c.id, c.name, c.platform, c.sort_order,
           (SELECT COUNT(*) FROM services s WHERE s.category_id = c.id AND s.status = 'active') AS service_count
    FROM categories c
    WHERE c.status = 'active'
    ORDER BY c.sort_order ASC, c.name ASC
");
$allCategories = $categoriesStmt->fetchAll() ?: [];

// 2. Fetch all real active services belonging to active categories
$servicesStmt = $db->query("
    SELECT s.id, s.category_id, s.name, s.rate_per_1000, s.min_quantity, s.max_quantity, s.speed, s.description, s.service_type,
           c.name AS category_name, c.platform
    FROM services s
    JOIN categories c ON s.category_id = c.id
    WHERE s.status = 'active' AND c.status = 'active'
    ORDER BY c.sort_order ASC, s.rate_per_1000 ASC, s.id ASC
");
$allServices = $servicesStmt->fetchAll() ?: [];

// 3. Resolve pre-selections from GET parameters or existing session state
$preServiceId = (int)($_GET['service_id'] ?? ($_SESSION['pending_order']['service_id'] ?? 0));
$preCategoryId = (int)($_GET['category_id'] ?? ($_SESSION['pending_order']['category_id'] ?? 0));
$preLink = trim((string)($_GET['link'] ?? ($_SESSION['pending_order']['link'] ?? '')));
$preQuantity = (int)($_GET['quantity'] ?? ($_SESSION['pending_order']['quantity'] ?? 0));

// If a specific service was passed in URL/session, locate its actual category
if ($preServiceId > 0) {
    foreach ($allServices as $s) {
        if ((int)$s['id'] === $preServiceId) {
            $preCategoryId = (int)$s['category_id'];
            if ($preQuantity <= 0) {
                $preQuantity = (int)$s['min_quantity'];
            }
            break;
        }
    }
}

// Fallback to first available category with active services
if ($preCategoryId <= 0 || !in_array($preCategoryId, array_column($allCategories, 'id'), true)) {
    foreach ($allCategories as $c) {
        if ((int)$c['service_count'] > 0) {
            $preCategoryId = (int)$c['id'];
            break;
        }
    }
    if ($preCategoryId <= 0 && !empty($allCategories)) {
        $preCategoryId = (int)$allCategories[0]['id'];
    }
}

// Gather services available for the pre-selected category
$servicesInPreCategory = array_values(array_filter($allServices, function($s) use ($preCategoryId) {
    return (int)$s['category_id'] === $preCategoryId;
}));

// Verify that pre-selected service belongs to this category; if not, pick the first service in category
$serviceInCatFound = false;
foreach ($servicesInPreCategory as $s) {
    if ((int)$s['id'] === $preServiceId) {
        $serviceInCatFound = true;
        break;
    }
}
if (!$serviceInCatFound && !empty($servicesInPreCategory)) {
    $preServiceId = (int)$servicesInPreCategory[0]['id'];
    if ($preQuantity <= 0) {
        $preQuantity = (int)$servicesInPreCategory[0]['min_quantity'];
    }
}

$error = null;

// =========================================================================
// STEP 1 HANDLER: Configure Order (Validate Category, Service, Link, Qty)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'configure') {
    CSRF::verifyOrAbort();

    $categoryId = (int)($_POST['category_id'] ?? 0);
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $link = trim($_POST['link'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 0);

    // Validate Category exists and is active
    $catStmt = $db->prepare("SELECT id, name, platform FROM categories WHERE id = :cid AND status = 'active' LIMIT 1");
    $catStmt->execute(['cid' => $categoryId]);
    $cat = $catStmt->fetch();

    // Validate Service exists, is active, AND strictly belongs to the chosen Category
    $svcStmt = $db->prepare("
        SELECT s.*, c.name AS category_name, c.platform 
        FROM services s 
        JOIN categories c ON s.category_id = c.id 
        WHERE s.id = :sid AND s.category_id = :cid AND s.status = 'active' AND c.status = 'active' 
        LIMIT 1
    ");
    $svcStmt->execute(['sid' => $serviceId, 'cid' => $categoryId]);
    $svc = $svcStmt->fetch();

    if (!$cat) {
        $error = "Selected category is invalid or inactive.";
    } elseif (!$svc) {
        $error = "Selected service does not belong to the chosen category or is unavailable.";
    } elseif (empty($link) || !filter_var($link, FILTER_VALIDATE_URL)) {
        $error = "Please enter a valid target URL (e.g. https://instagram.com/profile).";
    } elseif ($quantity < (int)$svc['min_quantity'] || $quantity > (int)$svc['max_quantity']) {
        $error = sprintf(
            "Quantity must be between %s and %s for '%s'.",
            number_format($svc['min_quantity']),
            number_format($svc['max_quantity']),
            $svc['name']
        );
    } else {
        $origRate = (float)$svc['rate_per_1000'];
        $flashDiscount = get_service_flash_sale_discount((int)$svc['id'], (int)$svc['category_id'], $origRate);
        $effectiveRate = $flashDiscount ? $flashDiscount['sale_rate'] : $origRate;
        $discountAmount = $flashDiscount ? round(($quantity / 1000) * $flashDiscount['discount_amount'], 4) : 0.0;
        $flashSaleId = $flashDiscount ? $flashDiscount['flash_sale_id'] : null;

        $serverCharge = round(($quantity / 1000) * $effectiveRate, 4);
        $token = bin2hex(random_bytes(16));

        // Store authoritative order configuration in session for checkout
        $_SESSION['pending_order'] = [
            'category_id' => (int)$cat['id'],
            'category_name' => (string)$cat['name'],
            'service_id' => (int)$svc['id'],
            'service_name' => (string)$svc['name'],
            'service_type' => (string)($svc['service_type'] ?? 'default'),
            'link' => $link,
            'quantity' => $quantity,
            'rate_per_1000' => $effectiveRate,
            'original_rate_per_1000' => $origRate,
            'is_flash_sale' => ($flashDiscount !== null),
            'flash_sale_id' => $flashSaleId,
            'flash_badge' => $flashDiscount['badge'] ?? null,
            'flash_discount_amount' => $discountAmount,
            'calculated_charge' => $serverCharge,
            'token' => $token,
            'created_at' => time()
        ];

        redirect('/user/new-order.php');
    }
}

// =========================================================================
// STEP 2 HANDLER: Final Checkout Submission (Wallet Safety & Execution)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'submit_order')) {
    CSRF::verifyOrAbort();

    $submittedToken = trim($_POST['order_token'] ?? '');
    $pending = $_SESSION['pending_order'] ?? null;

    if (!$pending || empty($pending['service_id']) || empty($pending['category_id']) || empty($pending['link']) || empty($pending['quantity'])) {
        $error = "Order details are missing. Please configure your target order first.";
    } elseif (!empty($submittedToken) && !hash_equals((string)($pending['token'] ?? ''), $submittedToken)) {
        $error = "Order session expired or security token mismatch. Please re-confirm your order.";
    } else {
        $categoryId = (int)$pending['category_id'];
        $serviceId = (int)$pending['service_id'];
        $link = (string)$pending['link'];
        $quantity = (int)$pending['quantity'];

        // Authoritative server-side re-verification
        $svcStmt = $db->prepare("
            SELECT s.*, c.name AS category_name 
            FROM services s 
            JOIN categories c ON s.category_id = c.id 
            WHERE s.id = :sid AND s.category_id = :cid AND s.status = 'active' AND c.status = 'active' 
            LIMIT 1
        ");
        $svcStmt->execute(['sid' => $serviceId, 'cid' => $categoryId]);
        $service = $svcStmt->fetch();

        if (!$service) {
            $error = "Selected service is no longer active or category relationship was modified.";
        } elseif (!filter_var($link, FILTER_VALIDATE_URL)) {
            $error = "Invalid destination URL.";
        } elseif ($quantity < (int)$service['min_quantity'] || $quantity > (int)$service['max_quantity']) {
            $error = "Quantity out of allowed limits for this service.";
        } else {
            // Recalculate authoritative server charge in base currency (applying active flash sale)
            $origRate = (float)$service['rate_per_1000'];
            $flashDiscount = get_service_flash_sale_discount((int)$service['id'], (int)$service['category_id'], $origRate);
            $effectiveRate = $flashDiscount ? $flashDiscount['sale_rate'] : $origRate;
            $discountAmount = $flashDiscount ? round(($quantity / 1000) * $flashDiscount['discount_amount'], 4) : 0.0;
            $flashSaleId = $flashDiscount ? $flashDiscount['flash_sale_id'] : null;

            $charge = round(($quantity / 1000) * $effectiveRate, 4);

            if ($user['balance'] < $charge) {
                $error = sprintf(
                    "Insufficient balance. Total charge is %s, but your available balance is %s. Please add funds to proceed.",
                    format_currency($charge),
                    format_currency($user['balance'])
                );
            } else {
                // Atomic database transaction
                $db->beginTransaction();
                try {
                    // 1. Deduct wallet balance with concurrency lock
                    $deduct = $db->prepare("
                        UPDATE users 
                        SET balance = balance - :charge, spent = spent + :charge 
                        WHERE id = :uid AND balance >= :charge
                    ");
                    $deduct->execute(['charge' => $charge, 'uid' => $user['id']]);

                    if ($deduct->rowCount() === 0) {
                        throw new Exception("Balance deduction failed due to insufficient funds.");
                    }

                    // 2. Insert new order record (with flash sale tracking if applicable)
                    $insOrder = $db->prepare("
                        INSERT INTO orders (user_id, service_id, flash_sale_id, provider_id, link, quantity, charge, discount_amount, start_count, remains, status, mode)
                        VALUES (:uid, :sid, :fsid, :pid, :link, :qty, :charge, :disc, 0, :remains, 'processing', 'auto')
                    ");
                    $insOrder->execute([
                        'uid' => $user['id'],
                        'sid' => $service['id'],
                        'fsid' => $flashSaleId,
                        'pid' => $service['provider_id'] ?: null,
                        'link' => $link,
                        'qty' => $quantity,
                        'charge' => $charge,
                        'disc' => $discountAmount,
                        'remains' => $quantity
                    ]);
                    $orderId = (int)$db->lastInsertId();

                    if ($flashSaleId) {
                        increment_flash_sale_orders($flashSaleId);
                    }

                    // 3. Dispatch to live external provider if mapped
                    $providerOrderId = null;
                    if (!empty($service['provider_id'])) {
                        $pStmt = $db->prepare("SELECT api_url, api_key, status FROM providers WHERE id = :pid LIMIT 1");
                        $pStmt->execute(['pid' => $service['provider_id']]);
                        $prov = $pStmt->fetch();

                        if ($prov && $prov['status'] === 'active' && !empty($service['provider_service_id'])) {
                            $apiResp = call_provider_api($prov['api_url'], [
                                'key' => $prov['api_key'],
                                'action' => 'add',
                                'service' => $service['provider_service_id'],
                                'link' => $link,
                                'quantity' => $quantity
                            ]);

                            if (!empty($apiResp['order'])) {
                                $providerOrderId = (string)$apiResp['order'];
                                $db->prepare("UPDATE orders SET provider_order_id = :poid, status = 'processing' WHERE id = :id")->execute([
                                    'poid' => $providerOrderId,
                                    'id' => $orderId
                                ]);
                            }
                        }
                    }

                    // 4. Record transaction ledger entry
                    $insTxn = $db->prepare("
                        INSERT INTO transactions (user_id, order_id, type, amount, gateway, gateway_txn_id, status, note)
                        VALUES (:uid, :oid, 'order', :amt, 'system', :txnid, 'completed', :note)
                    ");
                    $insTxn->execute([
                        'uid' => $user['id'],
                        'oid' => $orderId,
                        'amt' => -$charge,
                        'txnid' => 'ORD-' . $orderId,
                        'note' => 'Order #' . $orderId . ': ' . $service['name']
                    ]);

                    $db->commit();

                    // Clear pending order session state
                    unset($_SESSION['pending_order']);

                    // Process referral commission if eligible
                    process_referral_commission((int)$user['id'], $charge, 'order', null, $orderId);

                    set_flash('success', "Order #{$orderId} placed successfully!");
                    redirect('/user/orders.php');
                } catch (\Throwable $e) {
                    $db->rollBack();
                    $error = "Failed to place order: " . $e->getMessage();
                }
            }
        }
    }
}

// Current active pending order state for Step 2
$pendingOrder = $_SESSION['pending_order'] ?? null;
$selectedService = null;
$finalCharge = 0.0;

if ($pendingOrder && !empty($pendingOrder['service_id'])) {
    foreach ($allServices as $s) {
        if ((int)$s['id'] === (int)$pendingOrder['service_id']) {
            $selectedService = $s;
            break;
        }
    }
    if ($selectedService) {
        $origRate = (float)$selectedService['rate_per_1000'];
        $flashDiscount = get_service_flash_sale_discount((int)$selectedService['id'], (int)$selectedService['category_id'], $origRate);
        $effectiveRate = $flashDiscount ? $flashDiscount['sale_rate'] : $origRate;
        $finalCharge = round(((int)$pendingOrder['quantity'] / 1000) * $effectiveRate, 4);
    }
}

// Check if user requested to edit details or has no configured pending order
$showConfigStep = isset($_GET['edit']) || empty($pendingOrder['link']) || empty($pendingOrder['service_id']);

// Prepare lightweight JSON payloads for client-side reactive filtering
$activeFlashSales = get_active_flash_sales();
$categoriesJson = [];
foreach ($allCategories as $c) {
    $categoriesJson[] = [
        'id' => (int)$c['id'],
        'name' => (string)$c['name'],
        'platform' => (string)$c['platform'],
        'service_count' => (int)($c['service_count'] ?? 0)
    ];
}

$servicesJson = [];
foreach ($allServices as $s) {
    $origRate = (float)$s['rate_per_1000'];
    $flashDisc = get_service_flash_sale_discount((int)$s['id'], (int)$s['category_id'], $origRate, $activeFlashSales);
    $isFlash = ($flashDisc !== null);
    $saleRate = $isFlash ? $flashDisc['sale_rate'] : $origRate;

    $servicesJson[] = [
        'id' => (int)$s['id'],
        'category_id' => (int)$s['category_id'],
        'name' => (string)$s['name'],
        'is_flash_sale' => $isFlash,
        'flash_badge' => $flashDisc['badge'] ?? null,
        'flash_discount_percent' => $flashDisc['discount_percent'] ?? 0,
        'original_rate' => $origRate,
        'original_rate_formatted' => format_currency($origRate),
        'sale_rate' => $saleRate,
        'rate' => $saleRate,
        'rate_formatted' => format_currency($saleRate),
        'min' => (int)$s['min_quantity'],
        'max' => (int)$s['max_quantity'],
        'min_formatted' => number_format((int)$s['min_quantity']),
        'max_formatted' => number_format((int)$s['max_quantity']),
        'speed' => (string)($s['speed'] ?: 'Fast Delivery'),
        'description' => (string)($s['description'] ?: ''),
        'platform' => (string)$s['platform']
    ];
}

$userCurr = get_user_currency();
$userCurrencySymbol = $userCurr['symbol'] ?? '₹';
$userExchangeRate = (float)get_user_rate(1.0);

$pageTitle = "New Order - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Cyan / Turquoise Ambient Background Surroundings -->
<div class="fixed inset-0 pointer-events-none -z-10 bg-gradient-to-br from-[#cffafe]/40 via-[#ccfbf1]/30 to-[#f0fdf4]/50">
    <div class="absolute -top-32 -left-32 w-96 h-96 rounded-full bg-cyan-200/30 blur-3xl"></div>
    <div class="absolute top-1/3 -right-32 w-96 h-96 rounded-full bg-teal-200/25 blur-3xl"></div>
    <div class="absolute -bottom-32 left-1/4 w-96 h-96 rounded-full bg-emerald-200/25 blur-3xl"></div>
</div>

<div class="max-w-2xl mx-auto my-4 sm:my-8 px-4 space-y-6">
    <!-- Top Bar with Wallet Balance & Back link -->
    <div class="flex items-center justify-between">
        <a href="/user/dashboard.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Back to Dashboard</span>
        </a>
        <div class="text-xs font-bold text-slate-500">
            Available Balance: <strong class="text-slate-900 font-mono-nums text-sm"><?= format_currency($user['balance']) ?></strong>
        </div>
    </div>

    <?= render_flash() ?>

    <?php if ($error): ?>
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold flex items-center gap-2">
            <span>⚠️</span>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($showConfigStep): ?>
        <!-- ================================================================= -->
        <!-- STEP 1: CONFIGURE NEW ORDER FORM (CORRECT SEQUENCE RESTORED)     -->
        <!-- Sequence: 1. Category -> 2. Service -> 3. Target URL -> 4. Qty    -->
        <!-- ================================================================= -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-teal-100 shadow-xl space-y-5" x-data="newOrderConfig()" x-cloak>
            <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-black text-[#0f2d59] tracking-tight">Configure New Order</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Select category, service, target link, and quantity</p>
                </div>
                <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-cyan-50 text-cyan-800 border border-cyan-200">
                    Step 1 of 2
                </span>
            </div>

            <form action="/user/new-order.php" method="POST" class="space-y-4" @submit="onFormSubmit($event)">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="configure">

                <!-- 1. CATEGORY FIELD (APPEARS ABOVE SERVICE) -->
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label for="configCategory" class="block text-xs font-extrabold text-[#0f2d59] uppercase tracking-wider">
                            1. Category
                        </label>
                        <span class="text-[11px] font-bold text-slate-400" x-text="categories.length + ' Categories'"></span>
                    </div>
                    <select name="category_id" 
                            id="configCategory" 
                            x-model="selectedCategoryId" 
                            @change="onCategoryChange()" 
                            required 
                            class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs sm:text-sm font-semibold text-slate-800 focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-600 cursor-pointer max-w-full truncate transition-all">
                        <template x-for="cat in categories" :key="cat.id">
                            <option :value="String(cat.id)" x-text="cat.name + ' (' + cat.service_count + ')'"></option>
                        </template>
                    </select>
                </div>

                <!-- 2. SERVICE FIELD (SHOWS ONLY SERVICES BELONGING TO SELECTED CATEGORY) -->
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label for="configService" class="block text-xs font-extrabold text-[#0f2d59] uppercase tracking-wider">
                            2. Service
                        </label>
                        <span class="text-[11px] font-bold text-cyan-700" x-text="currentServices.length + ' Available'"></span>
                    </div>
                    <select name="service_id" 
                            id="configService" 
                            x-model="selectedServiceId" 
                            @change="onServiceChange()" 
                            required 
                            :disabled="currentServices.length === 0"
                            class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs sm:text-sm font-semibold text-slate-800 focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-600 cursor-pointer max-w-full truncate transition-all disabled:opacity-60 disabled:cursor-not-allowed">
                        <template x-if="currentServices.length === 0">
                            <option value="">No active services in this category</option>
                        </template>
                        <template x-for="svc in currentServices" :key="svc.id">
                            <option :value="String(svc.id)" x-text="'#' + svc.id + ' • ' + svc.name + ' — ' + svc.rate_formatted + ' / 1K'"></option>
                        </template>
                    </select>
                </div>

                <!-- DYNAMIC SERVICE DETAILS DISPLAY -->
                <template x-if="currentService">
                    <div class="p-4 rounded-2xl bg-cyan-50/60 border border-cyan-100 text-xs space-y-3">
                        <template x-if="currentService.is_flash_sale">
                            <div class="p-3 rounded-xl bg-gradient-to-r from-rose-500 via-purple-600 to-indigo-600 text-white flex items-center justify-between text-xs font-bold shadow-xs">
                                <div class="flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-white/20 flex items-center justify-center text-sm">⚡</span>
                                    <div>
                                        <span class="block text-xs font-black">Flash Sale Discount Applied!</span>
                                        <span class="text-[10px] text-rose-100 font-medium" x-text="currentService.flash_badge || ('Save ' + currentService.flash_discount_percent + '%')"></span>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <span class="text-[10px] text-rose-200 line-through block" x-text="currentService.original_rate_formatted"></span>
                                    <span class="text-sm font-black text-white" x-text="currentService.rate_formatted"></span>
                                </div>
                            </div>
                        </template>

                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-center">
                            <div class="p-2.5 rounded-xl bg-white border border-cyan-100/60 shadow-xs">
                                <span class="block text-[10px] uppercase font-bold text-slate-400">Rate / 1K</span>
                                <strong class="text-sm font-black font-mono-nums" :class="currentService.is_flash_sale ? 'text-rose-600' : 'text-cyan-900'" x-text="currentService.rate_formatted"></strong>
                            </div>
                            <div class="p-2.5 rounded-xl bg-white border border-cyan-100/60 shadow-xs">
                                <span class="block text-[10px] uppercase font-bold text-slate-400">Min Quantity</span>
                                <strong class="text-sm font-black text-slate-800 font-mono-nums" x-text="currentService.min_formatted"></strong>
                            </div>
                            <div class="p-2.5 rounded-xl bg-white border border-cyan-100/60 shadow-xs">
                                <span class="block text-[10px] uppercase font-bold text-slate-400">Max Quantity</span>
                                <strong class="text-sm font-black text-slate-800 font-mono-nums" x-text="currentService.max_formatted"></strong>
                            </div>
                            <div class="p-2.5 rounded-xl bg-white border border-cyan-100/60 shadow-xs">
                                <span class="block text-[10px] uppercase font-bold text-slate-400">Fulfillment</span>
                                <strong class="text-xs font-black text-emerald-700 truncate block mt-0.5" x-text="currentService.speed"></strong>
                            </div>
                        </div>

                        <template x-if="currentService.description && currentService.description.trim() !== ''">
                            <div class="pt-2 border-t border-cyan-100/80 text-slate-600 text-[11px] leading-relaxed whitespace-pre-line" x-text="currentService.description"></div>
                        </template>
                    </div>
                </template>

                <!-- 3. TARGET PROFILE OR POST URL -->
                <div>
                    <label for="configLink" class="block text-xs font-extrabold text-[#0f2d59] uppercase tracking-wider mb-2">
                        3. Target Profile or Post URL
                    </label>
                    <input type="url" 
                           name="link" 
                           id="configLink" 
                           x-model="targetUrl" 
                           required 
                           :placeholder="urlPlaceholder" 
                           class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs sm:text-sm font-medium focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-600 transition-all">
                </div>

                <!-- 4. ORDER QUANTITY -->
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label for="configQuantity" class="block text-xs font-extrabold text-[#0f2d59] uppercase tracking-wider">
                            4. Order Quantity
                        </label>
                        <template x-if="currentService">
                            <span class="text-[11px] font-bold text-slate-400">
                                Limits: <span class="text-slate-700 font-mono-nums" x-text="currentService.min_formatted + ' - ' + currentService.max_formatted"></span>
                            </span>
                        </template>
                    </div>
                    <input type="number" 
                           name="quantity" 
                           id="configQuantity" 
                           x-model.number="quantity" 
                           required 
                           :min="currentService ? currentService.min : 1" 
                           :max="currentService ? currentService.max : 10000000" 
                           class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs sm:text-sm font-bold font-mono-nums focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-600 transition-all">
                    
                    <template x-if="currentService && (quantity < currentService.min || quantity > currentService.max)">
                        <p class="text-[11px] font-bold text-rose-600 mt-1.5 flex items-center gap-1">
                            <span>⚠️</span>
                            <span>Quantity must be between <strong x-text="currentService.min_formatted"></strong> and <strong x-text="currentService.max_formatted"></strong></span>
                        </p>
                    </template>
                </div>

                <!-- ESTIMATED CHARGE LIVE BADGE -->
                <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/80 flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-500">Calculated Charge</span>
                    <div class="text-right">
                        <span class="text-base sm:text-lg font-black text-cyan-900 font-mono-nums" x-text="formattedCharge"></span>
                    </div>
                </div>

                <!-- SUBMIT BUTTON -->
                <div class="pt-3 border-t border-slate-100 flex items-center justify-end">
                    <button type="submit" 
                            :disabled="currentServices.length === 0 || (currentService && (quantity < currentService.min || quantity > currentService.max))"
                            class="px-7 py-3.5 bg-gradient-to-r from-blue-600 to-cyan-600 hover:from-blue-700 hover:to-cyan-700 text-white font-black text-xs sm:text-sm rounded-2xl shadow-lg shadow-cyan-500/25 active:scale-95 transition-all cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
                        Continue to Checkout &rarr;
                    </button>
                </div>
            </form>
        </div>

        <script>
        function newOrderConfig() {
            const rawCategories = <?= json_encode($categoriesJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            const rawServices = <?= json_encode($servicesJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            const initialCatId = '<?= (string)$preCategoryId ?>';
            const initialSvcId = '<?= (string)$preServiceId ?>';
            const initialLink = '<?= e(addslashes($preLink)) ?>';
            const initialQty = <?= (int)$preQuantity ?>;
            const userSymbol = '<?= addslashes($userCurrencySymbol) ?>';
            const userRate = <?= (float)$userExchangeRate ?>;

            return {
                categories: rawCategories,
                services: rawServices,
                selectedCategoryId: initialCatId,
                selectedServiceId: initialSvcId,
                targetUrl: initialLink,
                quantity: initialQty > 0 ? initialQty : 1000,
                userCurrencySymbol: userSymbol,
                userExchangeRate: userRate,

                init() {
                    if (!this.selectedCategoryId && this.categories.length > 0) {
                        this.selectedCategoryId = String(this.categories[0].id);
                    }
                    const inCat = this.currentServices.some(s => String(s.id) === String(this.selectedServiceId));
                    if (!inCat && this.currentServices.length > 0) {
                        this.selectedServiceId = String(this.currentServices[0].id);
                    }
                    if (this.currentService && (!this.quantity || this.quantity <= 0)) {
                        this.quantity = Number(this.currentService.min);
                    }
                },

                get currentCategory() {
                    return this.categories.find(c => String(c.id) === String(this.selectedCategoryId)) || null;
                },

                get currentServices() {
                    return this.services.filter(s => String(s.category_id) === String(this.selectedCategoryId));
                },

                get currentService() {
                    return this.services.find(s => String(s.id) === String(this.selectedServiceId)) || null;
                },

                onCategoryChange() {
                    const available = this.currentServices;
                    if (available.length > 0) {
                        this.selectedServiceId = String(available[0].id);
                        const svc = available[0];
                        const min = Number(svc.min);
                        const max = Number(svc.max);
                        if (this.quantity < min || this.quantity > max) {
                            this.quantity = min;
                        }
                    } else {
                        this.selectedServiceId = '';
                    }
                },

                onServiceChange() {
                    if (this.currentService) {
                        const min = Number(this.currentService.min);
                        const max = Number(this.currentService.max);
                        if (this.quantity < min) {
                            this.quantity = min;
                        } else if (this.quantity > max) {
                            this.quantity = max;
                        }
                    }
                },

                get calculatedBaseCharge() {
                    if (!this.currentService || !this.quantity || this.quantity <= 0) return 0;
                    return (Number(this.quantity) / 1000) * Number(this.currentService.rate);
                },

                get formattedCharge() {
                    if (!this.currentService || !this.quantity || this.quantity <= 0) {
                        return this.userCurrencySymbol + '0.00';
                    }
                    const userAmount = this.calculatedBaseCharge * this.userExchangeRate;
                    return this.userCurrencySymbol + userAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                },

                get urlPlaceholder() {
                    if (!this.currentCategory) return 'https://instagram.com/yourusername';
                    const p = (this.currentCategory.platform || '').toLowerCase();
                    switch (p) {
                        case 'instagram': return 'https://instagram.com/yourusername or post link';
                        case 'youtube': return 'https://youtube.com/watch?v=... or channel link';
                        case 'telegram': return 'https://t.me/channel_or_group_link';
                        case 'tiktok': return 'https://tiktok.com/@username/video/...';
                        case 'facebook': return 'https://facebook.com/page_or_post_link';
                        case 'twitter': return 'https://x.com/username or tweet link';
                        default: return 'https://...';
                    }
                },

                onFormSubmit(e) {
                    if (!this.selectedCategoryId) {
                        e.preventDefault();
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({ icon: 'warning', title: 'Attention', text: 'Please select a category.' });
                        }
                        return;
                    }
                    if (!this.selectedServiceId) {
                        e.preventDefault();
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({ icon: 'warning', title: 'Attention', text: 'Please select a service.' });
                        }
                        return;
                    }
                    if (this.currentService) {
                        const q = Number(this.quantity);
                        if (q < this.currentService.min || q > this.currentService.max) {
                            e.preventDefault();
                            if (typeof Swal !== 'undefined') {
                                Swal.fire({ 
                                    icon: 'warning', 
                                    title: 'Invalid Quantity', 
                                    text: 'Quantity must be between ' + this.currentService.min_formatted + ' and ' + this.currentService.max_formatted 
                                });
                            }
                            return;
                        }
                    }
                }
            };
        }
        </script>

    <?php else: ?>

        <!-- ================================================================= -->
        <!-- STEP 2: CHECKOUT & CONFIRMATION FORM                             -->
        <!-- Form contains ONLY: 1. Charge, 2. Submit button                   -->
        <!-- With full order context review & back link                        -->
        <!-- ================================================================= -->
        <div class="bg-white rounded-3xl p-6 sm:p-10 shadow-2xl border border-teal-100/80 max-w-md w-full mx-auto relative space-y-6">
            
            <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-black text-[#0f2d59] tracking-tight">Order Checkout</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Review calculated charge and authorize order</p>
                </div>
                <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-800 border border-emerald-200">
                    Step 2 of 2
                </span>
            </div>

            <form action="/user/new-order.php" method="POST" id="simplifiedOrderForm" class="space-y-6">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="submit_order">
                <input type="hidden" name="order_token" value="<?= e($pendingOrder['token'] ?? '') ?>">

                <!-- Flash Sale savings notice on Step 2 checkout -->
                <?php if (!empty($pendingOrder['is_flash_sale'])): ?>
                    <div class="p-3.5 rounded-2xl bg-gradient-to-r from-rose-500 to-purple-600 text-white flex items-center justify-between text-xs shadow-md shadow-rose-500/20">
                        <div class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-lg bg-white/20 flex items-center justify-center font-bold">⚡</span>
                            <div>
                                <span class="font-extrabold block"><?= e($pendingOrder['flash_badge'] ?: 'Flash Sale Applied') ?></span>
                                <span class="text-[10px] text-rose-100">Discounted promotional rate</span>
                            </div>
                        </div>
                        <span class="px-2 py-0.5 rounded-full bg-white/20 text-[11px] font-black">
                            Saved <?= format_currency($pendingOrder['flash_discount_amount'] ?? 0) ?>
                        </span>
                    </div>
                <?php endif; ?>

                <!-- 1. CHARGE FIELD ONLY (Pale mint background, dark blue label) -->
                <div>
                    <label class="block text-sm font-black text-[#0f2d59] tracking-tight mb-2">Charge</label>
                    <input type="text" 
                           id="chargeDisplay"
                           readonly 
                           value="<?= e(format_currency($finalCharge)) ?>" 
                           class="w-full px-5 py-4 rounded-2xl bg-[#e8f5e9] border border-[#c8e6c9] text-[#0f2d59] font-black text-xl font-mono-nums focus:outline-none cursor-default select-all shadow-inner">
                </div>

                <!-- 2. SUBMIT BUTTON ONLY (Large rounded cyan/blue CTA) -->
                <button type="submit" 
                        id="btnSubmitOrder"
                        class="w-full py-4 px-6 rounded-2xl bg-gradient-to-r from-[#0284c7] to-[#0ea5e9] hover:from-[#0369a1] hover:to-[#0284c7] text-white font-black text-base shadow-lg shadow-sky-500/25 active:scale-95 transition-all cursor-pointer text-center block">
                    Submit
                </button>
            </form>

            <!-- Order Review Details -->
            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 text-xs space-y-2">
                <div class="flex items-start justify-between gap-2">
                    <span class="text-slate-400 font-bold">Category:</span>
                    <span class="text-slate-800 font-bold text-right"><?= e($pendingOrder['category_name'] ?? 'Category') ?></span>
                </div>
                <div class="flex items-start justify-between gap-2">
                    <span class="text-slate-400 font-bold">Service:</span>
                    <span class="text-slate-800 font-bold text-right truncate max-w-[200px]" title="<?= e($pendingOrder['service_name'] ?? '') ?>"><?= e($pendingOrder['service_name'] ?? 'Service') ?></span>
                </div>
                <div class="flex items-start justify-between gap-2">
                    <span class="text-slate-400 font-bold">Target Link:</span>
                    <span class="text-blue-600 font-mono text-[11px] text-right truncate max-w-[200px]" title="<?= e($pendingOrder['link'] ?? '') ?>"><?= e($pendingOrder['link'] ?? '') ?></span>
                </div>
                <div class="flex items-start justify-between gap-2">
                    <span class="text-slate-400 font-bold">Quantity:</span>
                    <span class="text-slate-900 font-mono-nums font-black text-right"><?= number_format($pendingOrder['quantity'] ?? 0) ?> units</span>
                </div>
            </div>

            <!-- Balance vs Charge Assessment -->
            <?php if ($user['balance'] < $finalCharge): ?>
                <div class="p-3.5 rounded-2xl bg-amber-50 border border-amber-200 text-amber-800 text-xs space-y-2">
                    <div class="font-bold flex items-center gap-1.5">
                        <span>⚠️</span>
                        <span>Insufficient Wallet Balance</span>
                    </div>
                    <p class="text-[11px] leading-relaxed">
                        Your balance is <strong><?= format_currency($user['balance']) ?></strong>, but this order requires <strong><?= format_currency($finalCharge) ?></strong>.
                    </p>
                    <a href="/user/add-funds.php" class="inline-block py-2 px-4 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-extrabold text-[11px] shadow-sm">
                        Add Funds Now &rarr;
                    </a>
                </div>
            <?php endif; ?>

            <!-- Navigation back to modify configuration -->
            <div class="pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-400">
                <span>Need to adjust values?</span>
                <a href="/user/new-order.php?edit=1" class="text-xs font-bold text-blue-600 hover:text-blue-700 hover:underline">
                    &larr; Change Details
                </a>
            </div>
        </div>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
