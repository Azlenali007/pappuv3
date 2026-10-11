<?php
/**
 * Helper Functions
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

function e(?string $string): string {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

/**
 * Dynamic System Settings from Database
 */
function get_setting(string $key, ?string $default = null): ?string {
    if (!isset($GLOBALS['_app_settings_cache'])) {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT `key`, `value` FROM settings");
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $GLOBALS['_app_settings_cache'] = is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            $GLOBALS['_app_settings_cache'] = [];
        }
    }
    return $GLOBALS['_app_settings_cache'][$key] ?? $default;
}

function invalidate_settings_cache(): void {
    unset($GLOBALS['_app_settings_cache']);
}

function get_all_settings(): array {
    if (isset($GLOBALS['_app_settings_cache'])) {
        return $GLOBALS['_app_settings_cache'];
    }
    try {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT `key`, `value` FROM settings");
        $GLOBALS['_app_settings_cache'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        return $GLOBALS['_app_settings_cache'];
    } catch (\Throwable $e) {
        return [];
    }
}

function set_setting(string $key, string $value): bool {
    try {
        $db = Database::getConnection();
        $ins = $db->prepare("
            INSERT INTO settings (`key`, `value`) 
            VALUES (:k, :v)
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = CURRENT_TIMESTAMP
        ");
        $success = $ins->execute(['k' => $key, 'v' => $value]);
        if ($success) {
            if (!isset($GLOBALS['_app_settings_cache'])) {
                $GLOBALS['_app_settings_cache'] = [];
            }
            $GLOBALS['_app_settings_cache'][$key] = $value;
        }
        return $success;
    } catch (\Throwable $e) {
        error_log("set_setting error: " . $e->getMessage());
        return false;
    }
}

function update_config_file_settings(array $pairs): bool {
    $configFile = __DIR__ . '/config.php';
    if (!file_exists($configFile) || !is_writable($configFile)) {
        return false;
    }
    $content = file_get_contents($configFile);
    if ($content === false) return false;

    $map = [
        'site_name' => 'APP_NAME',
        'site_tagline' => 'APP_TAGLINE',
        'currency' => 'APP_CURRENCY',
        'currency_code' => 'APP_CURRENCY_CODE',
        'razorpay_key_id' => 'RAZORPAY_KEY_ID',
        'razorpay_key_secret' => 'RAZORPAY_KEY_SECRET'
    ];

    foreach ($pairs as $key => $val) {
        if (isset($map[$key])) {
            $constName = $map[$key];
            $escaped = addcslashes($val, "'\\");
            $pattern = "/define\s*\(\s*['\"]" . preg_quote($constName, '/') . "['\"]\s*,\s*.*?['\"]\s*\);/";
            $replacement = "define('" . $constName . "', '" . $escaped . "');";
            $content = preg_replace($pattern, $replacement, $content);
        }
    }

    return (bool)file_put_contents($configFile, $content);
}

function app_name(): string {
    $s = get_setting('site_name');
    if ($s !== null && $s !== '') return $s;
    return defined('APP_NAME') ? APP_NAME : 'SMM Panel';
}

function app_tagline(): string {
    $s = get_setting('site_tagline');
    if ($s !== null && $s !== '') return $s;
    return defined('APP_TAGLINE') ? APP_TAGLINE : 'Grow Your Social Media';
}

function app_currency(): string {
    $userCurr = get_user_currency();
    return $userCurr['symbol'] ?? (defined('APP_CURRENCY') ? APP_CURRENCY : '₹');
}

function app_currency_code(): string {
    $userCurr = get_user_currency();
    return $userCurr['code'] ?? (defined('APP_CURRENCY_CODE') ? APP_CURRENCY_CODE : 'INR');
}

function app_base_currency(): string {
    $base = get_base_currency();
    return $base['symbol'] ?? '₹';
}

function app_base_currency_code(): string {
    $base = get_base_currency();
    return $base['code'] ?? 'INR';
}

function get_user_rate(float|int|string $baseAmount): float {
    $val = (float)$baseAmount;
    $userCurr = get_user_currency();
    $baseCurr = get_base_currency();
    if ($userCurr['code'] !== $baseCurr['code']) {
        return convert_currency($val, $baseCurr['code'], $userCurr['code']);
    }
    return $val;
}

function is_maintenance_mode(): bool {
    return get_setting('maintenance_mode', '0') === '1';
}

function check_maintenance_mode(): void {
    if (!is_maintenance_mode()) {
        return;
    }

    // Admins always have access
    if (class_exists('Auth') && Auth::check() && Auth::isAdmin()) {
        return;
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '';

    // Allow admin section so admins can log in and manage settings
    if (str_starts_with($script, '/admin/')) {
        return;
    }

    // Allow login and logout scripts
    if (str_ends_with($script, 'login.php') || str_ends_with($script, 'logout.php')) {
        return;
    }

    // Handle API endpoints
    if (str_starts_with($script, '/api/') || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'error' => 'System is currently undergoing scheduled maintenance. Please try again later.'
        ]);
        exit;
    }

    // Render HTML maintenance page for public and user pages
    render_maintenance_page();
    exit;
}

function render_maintenance_page(): void {
    http_response_code(503);
    header('Retry-After: 3600');
    $appName = app_name();
    $appTagline = app_tagline();
    $supportEmail = get_setting('support_email', 'support@smmpanel.local');
    ?>
    <!DOCTYPE html>
    <html lang="en" class="h-full bg-slate-900">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?> - Maintenance Mode</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
    </head>
    <body class="h-full flex items-center justify-center p-4 bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-950 text-white">
        <div class="max-w-lg w-full text-center space-y-6 bg-slate-900/90 backdrop-blur-xl p-8 sm:p-10 rounded-3xl border border-slate-800 shadow-2xl">
            <div class="w-16 h-16 rounded-2xl bg-amber-500/20 text-amber-400 border border-amber-500/30 flex items-center justify-center text-3xl mx-auto shadow-inner">
                🛠️
            </div>
            
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-300 text-xs font-bold uppercase tracking-wider">
                <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span>
                Scheduled Maintenance Active
            </div>

            <div>
                <h1 class="text-3xl font-extrabold tracking-tight"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="text-xs text-indigo-300/80 font-medium mt-1"><?= htmlspecialchars($appTagline, ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <p class="text-sm text-slate-300 leading-relaxed font-normal">
                We are currently performing routine upgrades and server maintenance to enhance your experience. Normal access is temporarily paused. We will be back online shortly!
            </p>

            <div class="pt-4 border-t border-slate-800/80 flex flex-col sm:flex-row items-center justify-center gap-4 text-xs">
                <?php if ($supportEmail): ?>
                    <a href="mailto:<?= htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') ?>" class="text-slate-400 hover:text-slate-200 transition-colors">
                        <?= htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endif; ?>
                <a href="/login.php" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 hover:text-white font-bold transition-all border border-slate-700/60 inline-flex items-center gap-1.5">
                    <span>🔐</span> Admin Login
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function get_active_currencies(): array {
    if (isset($GLOBALS['_app_currencies_cache'])) {
        return $GLOBALS['_app_currencies_cache'];
    }
    try {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT * FROM currencies WHERE status = 'active' ORDER BY is_base DESC, code ASC");
        $currencies = $stmt->fetchAll();
        if (empty($currencies)) {
            $currencies = [
                ['code' => 'INR', 'symbol' => '₹', 'name' => 'Indian Rupee', 'exchange_rate' => 1.0, 'is_base' => 1],
                ['code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar', 'exchange_rate' => 0.0116, 'is_base' => 0]
            ];
        }
        $GLOBALS['_app_currencies_cache'] = $currencies;

        // Pre-index O(1) lookups
        $byCode = [];
        $rates = [];
        $base = null;
        foreach ($currencies as $curr) {
            $c = $curr['code'];
            $byCode[$c] = $curr;
            $rates[$c] = (float)$curr['exchange_rate'];
            if (!empty($curr['is_base']) && $base === null) {
                $base = $curr;
            }
        }
        $GLOBALS['_app_currencies_by_code'] = $byCode;
        $GLOBALS['_app_currency_rates'] = $rates;
        $GLOBALS['_app_base_currency_cache'] = $base ?: ($currencies[0] ?? ['code' => 'INR', 'symbol' => '₹', 'name' => 'Indian Rupee', 'exchange_rate' => 1.0, 'is_base' => 1]);

        return $currencies;
    } catch (\Throwable $e) {
        $fallback = [
            ['code' => 'INR', 'symbol' => '₹', 'name' => 'Indian Rupee', 'exchange_rate' => 1.0, 'is_base' => 1]
        ];
        $GLOBALS['_app_currencies_cache'] = $fallback;
        $GLOBALS['_app_currencies_by_code'] = ['INR' => $fallback[0]];
        $GLOBALS['_app_currency_rates'] = ['INR' => 1.0];
        $GLOBALS['_app_base_currency_cache'] = $fallback[0];
        return $fallback;
    }
}

function invalidate_currency_cache(): void {
    unset(
        $GLOBALS['_app_currencies_cache'],
        $GLOBALS['_app_currencies_by_code'],
        $GLOBALS['_app_currency_rates'],
        $GLOBALS['_app_base_currency_cache']
    );
}

function get_base_currency(): array {
    if (isset($GLOBALS['_app_base_currency_cache'])) {
        return $GLOBALS['_app_base_currency_cache'];
    }
    get_active_currencies();
    return $GLOBALS['_app_base_currency_cache'] ?? ['code' => 'INR', 'symbol' => '₹', 'name' => 'Indian Rupee', 'exchange_rate' => 1.0, 'is_base' => 1];
}

function get_user_currency(): array {
    if (!isset($GLOBALS['_app_currencies_cache'])) {
        get_active_currencies();
    }
    $chosenCode = $_SESSION['user_currency'] ?? null;
    if ($chosenCode && isset($GLOBALS['_app_currencies_by_code'][$chosenCode])) {
        return $GLOBALS['_app_currencies_by_code'][$chosenCode];
    }
    return get_base_currency();
}

function convert_currency(float $amount, string $fromCode, string $toCode): float {
    if ($fromCode === $toCode) {
        return $amount;
    }
    if (!isset($GLOBALS['_app_currency_rates'])) {
        get_active_currencies();
    }
    $rates = $GLOBALS['_app_currency_rates'] ?? [];
    $fromRate = $rates[$fromCode] ?? 1.0;
    $toRate = $rates[$toCode] ?? 1.0;

    if ($fromRate <= 0) $fromRate = 1.0;
    $baseAmount = $amount / $fromRate;
    return round($baseAmount * $toRate, 4);
}

function format_currency(float|int|string $amount, ?string $currencyCode = null, bool $convertFromBase = true): string {
    $val = (float)$amount;
    $userCurr = get_user_currency();
    $targetCode = $currencyCode ?: $userCurr['code'];
    $targetSymbol = $userCurr['symbol'] ?? '₹';

    if ($currencyCode && $currencyCode !== $userCurr['code']) {
        if (!isset($GLOBALS['_app_currencies_by_code'])) {
            get_active_currencies();
        }
        if (isset($GLOBALS['_app_currencies_by_code'][$currencyCode])) {
            $targetSymbol = $GLOBALS['_app_currencies_by_code'][$currencyCode]['symbol'] ?? $targetSymbol;
        }
    }

    $baseCurr = get_base_currency();
    if ($convertFromBase && $targetCode !== $baseCurr['code']) {
        $val = convert_currency($val, $baseCurr['code'], $targetCode);
    }

    return $targetSymbol . number_format($val, 2);
}

function redirect(string $path): never {
    header("Location: " . $path);
    exit;
}

function set_flash(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type' => $type, // 'success', 'error', 'warning', 'info'
        'message' => $message
    ];
}

function get_flash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function render_flash(): string {
    $flash = get_flash();
    if (!$flash) return '';
    $colors = [
        'success' => 'bg-emerald-500/10 border-emerald-500/30 text-emerald-700',
        'error' => 'bg-rose-500/10 border-rose-500/30 text-rose-700',
        'warning' => 'bg-amber-500/10 border-amber-500/30 text-amber-700',
        'info' => 'bg-blue-500/10 border-blue-500/30 text-blue-700'
    ];
    $cls = $colors[$flash['type']] ?? $colors['info'];
    return sprintf(
        '<div class="p-4 mb-4 text-sm rounded-xl border %s flex items-center justify-between" role="alert"><span>%s</span><button type="button" onclick="this.parentElement.remove()" class="text-xs font-bold opacity-75 hover:opacity-100">&times;</button></div>',
        $cls,
        e($flash['message'])
    );
}

/**
 * Standard SMM Provider API Connector (cURL)
 */
function call_provider_api(string $apiUrl, array $postData, int $timeout = 15): array {
    if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
        return ['error' => 'Invalid Provider API URL: ' . $apiUrl];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'User-Agent: SMM-Panel-Engine/1.0',
            'Accept: application/json'
        ]
    ]);
    
    $response = curl_exec($ch);
    $errorNumber = curl_errno($ch);
    $errorMessage = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errorNumber !== 0) {
        return ['error' => 'Provider connection failed (' . $errorMessage . ')'];
    }

    if ($httpCode >= 400) {
        return ['error' => 'Provider server returned HTTP ' . $httpCode];
    }

    if (empty($response)) {
        return ['error' => 'Empty response received from provider API'];
    }

    $decoded = json_decode((string)$response, true);
    if (!is_array($decoded)) {
        return ['error' => 'Invalid JSON received from provider API'];
    }

    return $decoded;
}

/**
 * =====================================================================
 * FUTURE FEATURE ARCHITECTURE: PROVIDER SERVICE IMPORT HELPERS
 * Foundation routines for normalizing diverse external API schemas,
 * duplicate detection, category matching, and pricing markup.
 * =====================================================================
 */

/**
 * Calculates selling rate from provider's original rate using percentage or fixed markup
 */
function calculate_service_markup(float $originalRate, float $markupPercent = 0.0, float $markupFixed = 0.0): float {
    $markupAmount = ($originalRate * ($markupPercent / 100)) + $markupFixed;
    return round($originalRate + $markupAmount, 4);
}

/**
 * Normalizes raw service payload from different SMM provider API formats
 * into the standardized panel schema while safely handling optional fields.
 */
function normalize_provider_service(array $raw, float $markupPercent = 0.0, float $markupFixed = 0.0): array {
    $providerServiceId = (string)($raw['service'] ?? $raw['service_id'] ?? $raw['id'] ?? '');
    $name = trim((string)($raw['name'] ?? $raw['title'] ?? 'Unnamed Service'));
    $originalRate = (float)($raw['rate'] ?? $raw['price'] ?? 0.0);
    $sellingRate = calculate_service_markup($originalRate, $markupPercent, $markupFixed);
    $minQty = (int)($raw['min'] ?? $raw['min_quantity'] ?? 10);
    $maxQty = (int)($raw['max'] ?? $raw['max_quantity'] ?? 100000);
    $category = trim((string)($raw['category'] ?? 'Other'));
    $type = strtolower(trim((string)($raw['type'] ?? 'default')));
    $dripfeed = !empty($raw['dripfeed']);
    $refill = !empty($raw['refill']);
    $cancel = !empty($raw['cancel']);
    $description = isset($raw['desc']) ? (string)$raw['desc'] : (isset($raw['description']) ? (string)$raw['description'] : null);

    return [
        'provider_service_id' => $providerServiceId,
        'name'                => $name,
        'original_rate'       => $originalRate,
        'rate_per_1000'       => $sellingRate,
        'min_quantity'        => max(1, $minQty),
        'max_quantity'        => max($minQty, $maxQty),
        'category_name'       => $category,
        'service_type'        => in_array($type, ['custom_comments', 'package', 'poll']) ? $type : 'default',
        'speed'               => 'Starts in 1-2 Hours',
        'description'         => $description,
        'supports_refill'     => $refill,
        'supports_cancel'     => $cancel,
        'supports_dripfeed'   => $dripfeed
    ];
}

/**
 * Duplicate Prevention Engine:
 * Verifies if an external provider service has already been imported into the catalog.
 */
function is_provider_service_imported(int $providerId, string $providerServiceId): bool {
    if ($providerId <= 0 || empty($providerServiceId)) {
        return false;
    }
    try {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM services WHERE provider_id = :pid AND provider_service_id = :psid LIMIT 1");
        $stmt->execute(['pid' => $providerId, 'psid' => $providerServiceId]);
        return (bool)$stmt->fetch();
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Intelligent Category Matcher:
 * Maps external category strings to existing panel category records.
 * Prioritizes exact normalized matching to avoid assigning services to unrelated categories.
 */
function find_matching_category_id(string $providerCategoryName, int $defaultCategoryId = 1): int {
    if (empty($providerCategoryName)) {
        return $defaultCategoryId;
    }
    try {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT id, name, platform FROM categories WHERE status = 'active' ORDER BY sort_order ASC");
        $categories = $stmt->fetchAll();

        $cleanName = strtolower(trim($providerCategoryName));
        // Strict exact match first
        foreach ($categories as $cat) {
            $catTitle = strtolower(trim((string)$cat['name']));
            if ($catTitle === $cleanName) {
                return (int)$cat['id'];
            }
        }
    } catch (\Throwable $e) {
        // Fall back to default
    }
    return $defaultCategoryId;
}

/**
 * =====================================================================
 * REFER & EARN / REFERRAL SYSTEM ENGINE
 * =====================================================================
 */

function get_user_referral_code(int $userId): string {
    try {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT referral_code FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $code = $stmt->fetchColumn();
        if (!empty($code)) {
            return (string)$code;
        }
        $newCode = 'REF' . $userId . strtoupper(bin2hex(random_bytes(2)));
        $upd = $db->prepare("UPDATE users SET referral_code = :code WHERE id = :id");
        $upd->execute(['code' => $newCode, 'id' => $userId]);
        return $newCode;
    } catch (\Throwable $e) {
        return 'REF' . $userId;
    }
}

function process_referral_commission(int $userId, float $amount, string $eventType, ?int $txnId = null, ?int $orderId = null): ?int {
    if (get_setting('referral_enabled', '1') !== '1') {
        return null;
    }

    try {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT referred_by FROM users WHERE id = :uid LIMIT 1");
        $stmt->execute(['uid' => $userId]);
        $referrerId = (int)$stmt->fetchColumn();

        if ($referrerId <= 0 || $referrerId === $userId) {
            return null;
        }

        $triggerEvent = get_setting('referral_trigger_event', 'every_deposit');
        if ($triggerEvent === 'first_deposit') {
            if ($eventType !== 'deposit') return null;
            $prev = $db->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = :uid AND type = 'deposit' AND status = 'completed'");
            $prev->execute(['uid' => $userId]);
            if ((int)$prev->fetchColumn() > 1) {
                return null;
            }
        } elseif ($triggerEvent === 'first_order') {
            if ($eventType !== 'order') return null;
            $prev = $db->prepare("SELECT COUNT(*) FROM orders WHERE user_id = :uid");
            $prev->execute(['uid' => $userId]);
            if ((int)$prev->fetchColumn() > 1) {
                return null;
            }
        }

        $minQualifying = (float)get_setting('referral_min_deposit', '100');
        if ($amount < $minQualifying) {
            return null;
        }

        $commissionType = get_setting('referral_commission_type', 'percentage');
        $commissionRate = (float)get_setting('referral_commission_rate', '5.00');
        $maxCommission = (float)get_setting('referral_max_commission', '1000');

        if ($commissionType === 'fixed') {
            $commissionAmount = $commissionRate;
        } else {
            $commissionAmount = round(($amount * ($commissionRate / 100)), 4);
        }

        if ($maxCommission > 0 && $commissionAmount > $maxCommission) {
            $commissionAmount = $maxCommission;
        }

        if ($commissionAmount <= 0) {
            return null;
        }

        $defaultStatus = get_setting('referral_default_status', 'approved');

        $db->beginTransaction();

        $ins = $db->prepare("
            INSERT INTO referral_commissions (referrer_id, referred_id, transaction_id, order_id, event_type, source_amount, commission_rate, commission_amount, status, note)
            VALUES (:rid, :refid, :txnid, :oid, :ev, :src, :rate, :comm, :st, :note)
        ");
        $ins->execute([
            'rid' => $referrerId,
            'refid' => $userId,
            'txnid' => $txnId,
            'oid' => $orderId,
            'ev' => $eventType,
            'src' => $amount,
            'rate' => $commissionRate,
            'comm' => $commissionAmount,
            'st' => $defaultStatus,
            'note' => ucfirst($eventType) . ' commission'
        ]);
        $commissionId = (int)$db->lastInsertId();

        $db->prepare("UPDATE referrals SET total_commission = total_commission + :comm WHERE referrer_id = :rid AND referred_id = :refid")
           ->execute(['comm' => $commissionAmount, 'rid' => $referrerId, 'refid' => $userId]);

        if ($defaultStatus === 'approved') {
            $credit = $db->prepare("UPDATE users SET balance = balance + :comm WHERE id = :rid");
            $credit->execute(['comm' => $commissionAmount, 'rid' => $referrerId]);

            $db->prepare("
                INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                VALUES (:rid, 'bonus', :comm, 'system', :txnid, 'completed', :note)
            ")->execute([
                'rid' => $referrerId,
                'comm' => $commissionAmount,
                'txnid' => 'ref_' . $commissionId,
                'note' => 'Referral commission earned from User #' . $userId
            ]);
        }

        $db->commit();
        return $commissionId;
    } catch (\Throwable $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("process_referral_commission error: " . $e->getMessage());
        return null;
    }
}

/**
 * =====================================================================
 * CONCURRENCY & WALLET SECURITY ENGINE
 * Atomic, race-condition protected wallet debit and credit.
 * =====================================================================
 */

function wallet_debit(PDO $db, int $userId, float $amount, string $note, ?int $orderId = null): bool {
    if ($amount <= 0) return true;

    $stmt = $db->prepare("UPDATE users SET balance = balance - :amt, spent = spent + :amt WHERE id = :uid AND balance >= :amt");
    $stmt->execute(['amt' => $amount, 'uid' => $userId]);

    if ($stmt->rowCount() === 0) {
        return false;
    }

    $ins = $db->prepare("
        INSERT INTO transactions (user_id, order_id, type, amount, gateway, gateway_txn_id, status, note)
        VALUES (:uid, :oid, 'order', :amt, 'system', :txnid, 'completed', :note)
    ");
    $ins->execute([
        'uid' => $userId,
        'oid' => $orderId,
        'amt' => -$amount,
        'txnid' => 'ord_' . ($orderId ?: bin2hex(random_bytes(4))),
        'note' => $note
    ]);
    return true;
}

function wallet_credit(PDO $db, int $userId, float $amount, string $gateway, ?string $txnId = null, string $note = ''): bool {
    if ($amount <= 0) return false;

    $stmt = $db->prepare("UPDATE users SET balance = balance + :amt WHERE id = :uid");
    $stmt->execute(['amt' => $amount, 'uid' => $userId]);

    $ins = $db->prepare("
        INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
        VALUES (:uid, 'deposit', :amt, :gw, :txnid, 'completed', :note)
    ");
    $ins->execute([
        'uid' => $userId,
        'amt' => $amount,
        'gw' => $gateway,
        'txnid' => $txnId ?: 'dep_' . bin2hex(random_bytes(6)),
        'note' => $note ?: "Added funds via {$gateway}"
    ]);

    // Trigger referral commission if eligible
    process_referral_commission($userId, $amount, 'deposit', (int)$db->lastInsertId(), null);

    return true;
}

/**
 * =====================================================================
 * PAYMENT GATEWAY MANAGEMENT REPOSITORY
 * Razorpay, PayPal, PhonePe, Paytm, Binance Pay
 * =====================================================================
 */

function is_gateway_configured(array $gateway): bool {
    $code = strtolower($gateway['code'] ?? '');
    $config = get_gateway_config($gateway);

    return match ($code) {
        'razorpay' => !empty($config['key_id']) && !empty($config['key_secret']) && !str_contains($config['key_id'], 'YourKeyHere'),
        'paypal' => !empty($config['client_id']) && !empty($config['client_secret']),
        'phonepe' => !empty($config['merchant_id']) && !empty($config['salt_key']),
        'paytm' => !empty($config['merchant_id']) && !empty($config['merchant_key']),
        'binance' => !empty($config['api_key']) && !empty($config['secret_key']),
        'phonepe_business_qr' => (!empty($config['qr_image_url']) || !empty($config['upi_id']) || !empty($config['merchant_id'])),
        default => !empty($gateway['instructions']) || !empty($config)
    };
}

function get_gateway_config(array $gateway): array {
    $raw = $gateway['config_data'] ?? '';
    if (empty($raw)) {
        return [];
    }
    if (is_array($raw)) {
        return $raw;
    }
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : [];
}

function get_payment_methods(bool $activeOnly = true, bool $configuredOnly = false): array {
    try {
        $db = Database::getConnection();
        $sql = "SELECT * FROM payment_methods";
        if ($activeOnly) {
            $sql .= " WHERE status = 'active'";
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";
        $stmt = $db->query($sql);
        $methods = $stmt->fetchAll();
    } catch (\Throwable $e) {
        $methods = [];
    }

    if (empty($methods)) {
        // Fallback default registry
        $methods = [
            [
                'id' => 1,
                'name' => 'Razorpay',
                'code' => 'razorpay',
                'type' => 'automatic',
                'icon' => '⚡',
                'min_amount' => 100,
                'max_amount' => 50000,
                'fee_percent' => 0,
                'instructions' => 'Automated instant checkout supporting UPI, Credit/Debit cards, Net Banking & Wallets.',
                'config_data' => json_encode(['key_id' => get_setting('razorpay_key_id', ''), 'key_secret' => get_setting('razorpay_key_secret', ''), 'mode' => 'test']),
                'status' => 'active',
                'mode' => 'test',
                'sort_order' => 1
            ],
            [
                'id' => 2,
                'name' => 'PayPal',
                'code' => 'paypal',
                'type' => 'automatic',
                'icon' => '🅿️',
                'min_amount' => 500,
                'max_amount' => 500000,
                'fee_percent' => 3.5,
                'instructions' => 'Global payment checkout via PayPal account, Visa, MasterCard, and Amex.',
                'config_data' => json_encode(['client_id' => '', 'client_secret' => '', 'mode' => 'sandbox']),
                'status' => 'inactive',
                'mode' => 'test',
                'sort_order' => 2
            ],
            [
                'id' => 3,
                'name' => 'PhonePe',
                'code' => 'phonepe',
                'type' => 'automatic',
                'icon' => '🟣',
                'min_amount' => 100,
                'max_amount' => 100000,
                'fee_percent' => 0,
                'instructions' => 'Direct PhonePe UPI & QR automated payment with instant server callback.',
                'config_data' => json_encode(['merchant_id' => '', 'salt_key' => '', 'salt_index' => '1', 'mode' => 'sandbox']),
                'status' => 'inactive',
                'mode' => 'test',
                'sort_order' => 3
            ],
            [
                'id' => 4,
                'name' => 'Paytm',
                'code' => 'paytm',
                'type' => 'automatic',
                'icon' => '📲',
                'min_amount' => 50,
                'max_amount' => 100000,
                'fee_percent' => 0,
                'instructions' => 'Paytm Gateway / UPI QR & Net Banking with automated verification.',
                'config_data' => json_encode(['merchant_id' => '', 'merchant_key' => '', 'channel_id' => 'WEB', 'industry_type' => 'Retail', 'upi_id' => 'smmpanel@upi', 'mode' => 'staging']),
                'status' => 'inactive',
                'mode' => 'test',
                'sort_order' => 4
            ],
            [
                'id' => 5,
                'name' => 'Binance Pay',
                'code' => 'binance',
                'type' => 'automatic',
                'icon' => '🟡',
                'min_amount' => 500,
                'max_amount' => 1000000,
                'fee_percent' => 1,
                'instructions' => 'Cryptocurrency checkout powered by Binance Pay (USDT, BTC, ETH, BUSD).',
                'config_data' => json_encode(['api_key' => '', 'secret_key' => '', 'merchant_id' => '', 'mode' => 'test']),
                'status' => 'inactive',
                'mode' => 'test',
                'sort_order' => 5
            ],
            [
                'id' => 6,
                'name' => 'PhonePe Business QR',
                'code' => 'phonepe_business_qr',
                'type' => 'manual',
                'icon' => '🟣',
                'min_amount' => 10,
                'max_amount' => 100000,
                'fee_percent' => 0,
                'instructions' => 'Scan the PhonePe Business QR code with any UPI app. After payment, submit your 12-digit UTR/Reference ID from the payment receipt.',
                'config_data' => json_encode([
                    'merchant_id' => '',
                    'upi_id' => '',
                    'qr_image_url' => '',
                    'gmail_method' => 'imap',
                    'gmail_email' => '',
                    'gmail_app_password' => '',
                    'gmail_client_id' => '',
                    'gmail_client_secret' => '',
                    'gmail_refresh_token' => '',
                    'sender_whitelist' => 'noreply@phonepe.com,merchant-care@phonepe.com',
                    'auto_verify_on_submit' => 1,
                    'mode' => 'test'
                ]),
                'status' => 'inactive',
                'mode' => 'test',
                'sort_order' => 6
            ]
        ];
    }

    if ($configuredOnly) {
        $methods = array_filter($methods, fn($m) => is_gateway_configured($m));
    }

    return array_values($methods);
}

function get_payment_method(string $code): ?array {
    $methods = get_payment_methods(false);
    foreach ($methods as $m) {
        if (strtolower($m['code']) === strtolower($code)) {
            return $m;
        }
    }
    return null;
}

function update_gateway_config(string $code, array $config, array $extra = []): bool {
    try {
        $db = Database::getConnection();
        $fields = ["config_data = :config"];
        $params = [
            'code' => $code,
            'config' => json_encode($config)
        ];

        if (isset($extra['name'])) {
            $fields[] = "name = :name";
            $params['name'] = trim((string)$extra['name']);
        }
        if (isset($extra['min_amount'])) {
            $fields[] = "min_amount = :min_amount";
            $params['min_amount'] = (float)$extra['min_amount'];
        }
        if (isset($extra['max_amount'])) {
            $fields[] = "max_amount = :max_amount";
            $params['max_amount'] = (float)$extra['max_amount'];
        }
        if (isset($extra['fee_percent'])) {
            $fields[] = "fee_percent = :fee_percent";
            $params['fee_percent'] = (float)$extra['fee_percent'];
        }
        if (isset($extra['instructions'])) {
            $fields[] = "instructions = :instructions";
            $params['instructions'] = trim((string)$extra['instructions']);
        }
        if (isset($extra['mode'])) {
            $fields[] = "mode = :mode";
            $params['mode'] = in_array($extra['mode'], ['test', 'live'], true) ? $extra['mode'] : 'test';
        }

        $sql = "UPDATE payment_methods SET " . implode(', ', $fields) . " WHERE code = :code";
        $stmt = $db->prepare($sql);
        $ok = $stmt->execute($params);

        // Sync razorpay keys with settings table for backward compatibility
        if ($code === 'razorpay' && isset($config['key_id'], $config['key_secret'])) {
            set_setting('razorpay_key_id', $config['key_id']);
            set_setting('razorpay_key_secret', $config['key_secret']);
        }

        return $ok;
    } catch (\Throwable $e) {
        error_log("update_gateway_config error: " . $e->getMessage());
        return false;
    }
}

function toggle_gateway_status(string $code, bool $enable): array {
    $method = get_payment_method($code);
    if (!$method) {
        return ['success' => false, 'error' => "Gateway '{$code}' not found."];
    }

    if ($enable && !is_gateway_configured($method)) {
        return [
            'success' => false,
            'error' => "Cannot enable {$method['name']}: Configuration is incomplete. Please enter required API credentials first."
        ];
    }

    try {
        $db = Database::getConnection();
        $newStatus = $enable ? 'active' : 'inactive';
        $stmt = $db->prepare("UPDATE payment_methods SET status = :status WHERE code = :code");
        $stmt->execute(['status' => $newStatus, 'code' => $code]);
        return ['success' => true, 'status' => $newStatus];
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * =====================================================================
 * NOTIFICATION CENTER & UNREAD HELPERS
 * =====================================================================
 */
function get_unread_notifications_count(int $userId): int {
    try {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM announcements a
            LEFT JOIN user_notification_reads r ON a.id = r.announcement_id AND r.user_id = :uid
            WHERE a.status = 'active'
              AND (a.target_audience = 'all' OR a.target_user_id = :uid)
              AND (a.starts_at IS NULL OR a.starts_at <= NOW())
              AND (a.expires_at IS NULL OR a.expires_at >= NOW())
              AND r.read_at IS NULL
        ");
        $stmt->execute(['uid' => $userId]);
        return (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * =====================================================================
 * SQL MIGRATION & INSTALLER STATEMENT PARSER
 * Safely parses and splits complex SQL scripts into individual valid
 * statements, respecting quotes, escaped characters, and comments.
 * =====================================================================
 */
function split_sql_statements(string $sql): array {
    $statements = [];
    $current = '';
    $len = strlen($sql);
    $inSingleQuote = false;
    $inDoubleQuote = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        $next = ($i + 1 < $len) ? $sql[$i + 1] : '';

        // Handle line comments: -- or #
        if ($inLineComment) {
            if ($c === "\n" || $c === "\r") {
                $inLineComment = false;
            }
            continue;
        }

        // Handle block comments: /* ... */
        if ($inBlockComment) {
            if ($c === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        // Check for comment starts if outside string literals
        if (!$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
            if (($c === '-' && $next === '-') || $c === '#') {
                $inLineComment = true;
                if ($c === '-') $i++;
                continue;
            }
            if ($c === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
        }

        // Handle string literals and identifiers
        if ($c === "'" && !$inDoubleQuote && !$inBacktick) {
            // Check for escaped quote \'
            $escaped = ($i > 0 && $sql[$i - 1] === '\\');
            if (!$escaped) {
                $inSingleQuote = !$inSingleQuote;
            }
        } elseif ($c === '"' && !$inSingleQuote && !$inBacktick) {
            $escaped = ($i > 0 && $sql[$i - 1] === '\\');
            if (!$escaped) {
                $inDoubleQuote = !$inDoubleQuote;
            }
        } elseif ($c === '`' && !$inSingleQuote && !$inDoubleQuote) {
            $inBacktick = !$inBacktick;
        }

        // Check for statement delimiter ';' outside strings
        if ($c === ';' && !$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            continue;
        }

        $current .= $c;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

/**
 * =========================================================================
 * FLASH SALE ENGINE - REALTIME PRICING, SCOPING & LIFECYCLE
 * =========================================================================
 */

function ensure_flash_sales_table_exists(?PDO $db = null): void {
    static $ensured = false;
    if ($ensured) return;
    try {
        $db = $db ?: Database::getConnection();
        $db->exec("
            CREATE TABLE IF NOT EXISTS `flash_sales` (
              `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `title` VARCHAR(191) NOT NULL,
              `subtitle` VARCHAR(255) NULL,
              `badge_text` VARCHAR(64) NOT NULL DEFAULT 'FLASH SALE',
              `discount_type` ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
              `discount_value` DECIMAL(10, 4) NOT NULL DEFAULT 20.0000,
              `scope_type` ENUM('all', 'category', 'services') NOT NULL DEFAULT 'all',
              `category_id` INT UNSIGNED NULL,
              `service_ids` TEXT NULL,
              `starts_at` DATETIME NOT NULL,
              `ends_at` DATETIME NOT NULL,
              `status` ENUM('active', 'inactive', 'archived') NOT NULL DEFAULT 'active',
              `max_orders` INT UNSIGNED NULL,
              `orders_count` INT UNSIGNED NOT NULL DEFAULT 0,
              `banner_bg` VARCHAR(64) NOT NULL DEFAULT 'from-blue-600 via-indigo-600 to-purple-600',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX `idx_flash_status_dates` (`status`, `starts_at`, `ends_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $ensured = true;
    } catch (\Throwable $e) {
        error_log("Flash sale table check: " . $e->getMessage());
    }
}

function get_active_flash_sales(?int $limit = null): array {
    ensure_flash_sales_table_exists();
    try {
        $db = Database::getConnection();
        $sql = "
            SELECT fs.*, c.name AS category_name, c.platform AS category_platform
            FROM flash_sales fs
            LEFT JOIN categories c ON fs.category_id = c.id
            WHERE fs.status = 'active'
              AND fs.starts_at <= NOW()
              AND fs.ends_at > NOW()
              AND (fs.max_orders IS NULL OR fs.orders_count < fs.max_orders)
            ORDER BY fs.starts_at DESC
        ";
        if ($limit !== null && $limit > 0) {
            $sql .= " LIMIT " . (int)$limit;
        }
        $stmt = $db->query($sql);
        $sales = $stmt->fetchAll() ?: [];
        $now = time();
        foreach ($sales as &$sale) {
            $endTimestamp = strtotime((string)$sale['ends_at']);
            $sale['remaining_seconds'] = max(0, $endTimestamp - $now);
            $sale['hours'] = floor($sale['remaining_seconds'] / 3600);
            $sale['minutes'] = floor(($sale['remaining_seconds'] % 3600) / 60);
            $sale['seconds'] = $sale['remaining_seconds'] % 60;
            $sale['discount_formatted'] = $sale['discount_type'] === 'percentage'
                ? rtrim(rtrim(number_format((float)$sale['discount_value'], 2), '0'), '.') . '%'
                : format_currency((float)$sale['discount_value']);
        }
        unset($sale);
        return $sales;
    } catch (\Throwable $e) {
        error_log("get_active_flash_sales error: " . $e->getMessage());
        return [];
    }
}

function get_service_flash_sale_discount(int $serviceId, int $categoryId, float $baseRate, ?array $activeSales = null): ?array {
    if ($activeSales === null) {
        $activeSales = get_active_flash_sales();
    }
    if (empty($activeSales)) {
        return null;
    }

    $bestMatch = null;
    $maxSavings = -1.0;

    foreach ($activeSales as $sale) {
        $applies = false;
        $scope = $sale['scope_type'];
        if ($scope === 'all') {
            $applies = true;
        } elseif ($scope === 'category') {
            $applies = ((int)($sale['category_id'] ?? 0) === $categoryId);
        } elseif ($scope === 'services') {
            $ids = array_filter(array_map('intval', explode(',', (string)($sale['service_ids'] ?? ''))));
            $applies = in_array($serviceId, $ids, true);
        }

        if (!$applies) continue;

        $discountVal = (float)$sale['discount_value'];
        if ($sale['discount_type'] === 'percentage') {
            $discountAmount = $baseRate * ($discountVal / 100.0);
            $discountPercent = round($discountVal);
        } else {
            $discountAmount = min($baseRate, $discountVal);
            $discountPercent = $baseRate > 0 ? round(($discountAmount / $baseRate) * 100) : 0;
        }
        $saleRate = max(0.0001, round($baseRate - $discountAmount, 4));

        if ($discountAmount > $maxSavings) {
            $maxSavings = $discountAmount;
            $bestMatch = [
                'flash_sale_id' => (int)$sale['id'],
                'title' => (string)$sale['title'],
                'badge' => (string)($sale['badge_text'] ?: 'FLASH SALE'),
                'discount_type' => (string)$sale['discount_type'],
                'discount_value' => $discountVal,
                'discount_percent' => (int)$discountPercent,
                'discount_amount' => round($discountAmount, 4),
                'original_rate' => $baseRate,
                'sale_rate' => $saleRate,
                'ends_at' => (string)$sale['ends_at'],
                'remaining_seconds' => (int)($sale['remaining_seconds'] ?? 0)
            ];
        }
    }

    return $bestMatch;
}

function increment_flash_sale_orders(int $saleId): void {
    if ($saleId <= 0) return;
    try {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE flash_sales SET orders_count = orders_count + 1 WHERE id = :id");
        $stmt->execute(['id' => $saleId]);
    } catch (\Throwable $e) {
        error_log("increment_flash_sale_orders error: " . $e->getMessage());
    }
}

// =========================================================================
// COMMUNITY VOTING & POLL SYSTEM HELPERS
// =========================================================================

function ensure_voting_tables_exist(PDO $db): void {
    static $checked = false;
    if ($checked) return;

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `polls` (
              `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `question` VARCHAR(255) NOT NULL,
              `description` TEXT NULL,
              `status` ENUM('draft', 'published', 'paused', 'closed', 'cancelled', 'archived') NOT NULL DEFAULT 'draft',
              `results_visibility` ENUM('always', 'after_vote', 'after_close', 'hidden') NOT NULL DEFAULT 'after_vote',
              `allow_change` TINYINT(1) NOT NULL DEFAULT 0,
              `starts_at` DATETIME NULL,
              `ends_at` DATETIME NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX `idx_polls_status_dates` (`status`, `starts_at`, `ends_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `poll_options` (
              `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `poll_id` INT UNSIGNED NOT NULL,
              `option_text` VARCHAR(255) NOT NULL,
              `sort_order` INT NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_poll_options_poll` (`poll_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `poll_votes` (
              `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `poll_id` INT UNSIGNED NOT NULL,
              `option_id` INT UNSIGNED NOT NULL,
              `user_id` INT UNSIGNED NOT NULL,
              `ip_address` VARCHAR(45) NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_poll_user` (`poll_id`, `user_id`),
              INDEX `idx_poll_votes_poll` (`poll_id`),
              INDEX `idx_poll_votes_option` (`option_id`),
              INDEX `idx_poll_votes_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `user_poll_bubble_dismissals` (
              `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `user_id` INT UNSIGNED NOT NULL,
              `poll_id` INT UNSIGNED NOT NULL,
              `dismissed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_user_poll_dismissal` (`user_id`, `poll_id`),
              INDEX `idx_user_dismissal` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Seed initial poll if no polls exist
        $pollCount = (int)$db->query("SELECT COUNT(*) FROM polls")->fetchColumn();
        if ($pollCount === 0) {
            $db->exec("
                INSERT INTO `polls` (`id`, `question`, `description`, `status`, `results_visibility`, `allow_change`, `starts_at`, `ends_at`) VALUES
                (1, 'Which platform services should we prioritize next?', 'Help us choose the next major social platform to expand with ultra-fast delivery and lower prices.', 'published', 'after_vote', 1, DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 14 DAY));

                INSERT INTO `poll_options` (`id`, `poll_id`, `option_text`, `sort_order`) VALUES
                (1, 1, 'Instagram Threads & Broadcast Channels', 1),
                (2, 1, 'YouTube 4K Watch Hours & Monetization Pack', 2),
                (3, 1, 'Telegram Boosts & Premium Channel Members', 3),
                (4, 1, 'TikTok Live Stream Viewers & Comments', 4);
            ");
        }

        $checked = true;
    } catch (\Throwable $e) {
        error_log("ensure_voting_tables_exist error: " . $e->getMessage());
    }
}

/**
 * Fetch all polls visible to the given user (or admin) with live stats and user votes
 */
function get_polls_for_user(int $userId, bool $isAdmin = false): array {
    try {
        $db = Database::getConnection();
        ensure_voting_tables_exist($db);

        // For normal users, fetch published polls or closed polls (archived/draft are excluded)
        if ($isAdmin) {
            $sql = "SELECT * FROM polls ORDER BY id DESC";
            $stmt = $db->query($sql);
            $polls = $stmt->fetchAll() ?: [];
        } else {
            $sql = "
                SELECT * FROM polls 
                WHERE status IN ('published', 'closed')
                  AND (starts_at IS NULL OR starts_at <= NOW())
                ORDER BY 
                  CASE WHEN status = 'published' AND (ends_at IS NULL OR ends_at >= NOW()) THEN 0 ELSE 1 END ASC,
                  id DESC
            ";
            $stmt = $db->query($sql);
            $polls = $stmt->fetchAll() ?: [];
        }

        if (empty($polls)) {
            return [];
        }

        $now = time();
        $pollIds = array_column($polls, 'id');
        $placeholders = implode(',', array_fill(0, count($pollIds), '?'));

        // Load all options for these polls
        $optStmt = $db->prepare("SELECT * FROM poll_options WHERE poll_id IN ({$placeholders}) ORDER BY sort_order ASC, id ASC");
        $optStmt->execute($pollIds);
        $allOptions = $optStmt->fetchAll() ?: [];

        $optionsByPoll = [];
        foreach ($allOptions as $opt) {
            $optionsByPoll[(int)$opt['poll_id']][] = $opt;
        }

        // Load total votes per option
        $voteCountsStmt = $db->prepare("SELECT option_id, COUNT(*) AS vote_count FROM poll_votes WHERE poll_id IN ({$placeholders}) GROUP BY option_id");
        $voteCountsStmt->execute($pollIds);
        $voteCounts = $voteCountsStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        // Load user's vote for these polls
        $userVotes = [];
        if ($userId > 0) {
            $userVoteStmt = $db->prepare("SELECT poll_id, option_id, created_at, updated_at FROM poll_votes WHERE user_id = ? AND poll_id IN ({$placeholders})");
            $userVoteStmt->execute(array_merge([$userId], $pollIds));
            while ($row = $userVoteStmt->fetch()) {
                $userVotes[(int)$row['poll_id']] = [
                    'option_id' => (int)$row['option_id'],
                    'voted_at' => (string)$row['created_at'],
                    'updated_at' => (string)$row['updated_at']
                ];
            }
        }

        // Process each poll with calculated metrics
        foreach ($polls as &$poll) {
            $pid = (int)$poll['id'];
            $poll['id'] = $pid;
            $opts = $optionsByPoll[$pid] ?? [];

            $totalVotes = 0;
            foreach ($opts as &$o) {
                $oid = (int)$o['id'];
                $o['id'] = $oid;
                $cnt = (int)($voteCounts[$oid] ?? 0);
                $o['vote_count'] = $cnt;
                $totalVotes += $cnt;
            }
            unset($o);

            // Compute percentages
            foreach ($opts as &$o) {
                $o['percentage'] = $totalVotes > 0 ? round(($o['vote_count'] / $totalVotes) * 100, 1) : 0.0;
            }
            unset($o);

            $poll['options'] = $opts;
            $poll['total_votes'] = $totalVotes;

            // Schedule & Status calculation
            $hasStarted = empty($poll['starts_at']) || strtotime((string)$poll['starts_at']) <= $now;
            $hasEnded = !empty($poll['ends_at']) && strtotime((string)$poll['ends_at']) < $now;
            
            $isEffectivelyOpen = ($poll['status'] === 'published') && $hasStarted && !$hasEnded;
            $poll['is_open'] = $isEffectivelyOpen;

            // Remaining time
            if (!empty($poll['ends_at'])) {
                $endTs = strtotime((string)$poll['ends_at']);
                $diff = max(0, $endTs - $now);
                $poll['remaining_seconds'] = $diff;
                $poll['days_left'] = floor($diff / 86400);
                $poll['hours_left'] = floor(($diff % 86400) / 3600);
                $poll['minutes_left'] = floor(($diff % 3600) / 60);
                $poll['is_closing_soon'] = ($diff > 0 && $diff <= 86400 * 2); // Within 48 hours
            } else {
                $poll['remaining_seconds'] = null;
                $poll['days_left'] = null;
                $poll['is_closing_soon'] = false;
            }

            // User vote state
            $uVote = $userVotes[$pid] ?? null;
            $poll['user_voted'] = ($uVote !== null);
            $poll['user_voted_option_id'] = $uVote ? (int)$uVote['option_id'] : null;
            $poll['user_voted_at'] = $uVote ? (string)$uVote['voted_at'] : null;

            // Can user vote?
            $poll['can_vote'] = $isEffectivelyOpen && (!$poll['user_voted'] || (bool)$poll['allow_change']);

            // Should results be displayed to user?
            if ($isAdmin) {
                $poll['show_results'] = true;
            } else {
                switch ($poll['results_visibility']) {
                    case 'always':
                        $poll['show_results'] = true;
                        break;
                    case 'after_vote':
                        $poll['show_results'] = $poll['user_voted'];
                        break;
                    case 'after_close':
                        $poll['show_results'] = !$isEffectivelyOpen;
                        break;
                    case 'hidden':
                    default:
                        $poll['show_results'] = false;
                        break;
                }
            }
        }
        unset($poll);

        return $polls;
    } catch (\Throwable $e) {
        error_log("get_polls_for_user error: " . $e->getMessage());
        return [];
    }
}

/**
 * Returns active published poll data specifically for the User Dashboard Floating Bubble
 * Returns null if no eligible polls exist or if user already dismissed the active poll.
 */
function get_floating_voting_bubble_data(int $userId): ?array {
    if ($userId <= 0) return null;

    try {
        $db = Database::getConnection();
        ensure_voting_tables_exist($db);

        // Check if feature is globally disabled
        if (get_setting('voting_system_enabled', '1') === '0') {
            return null;
        }

        // Active session dismissals
        $dismissedSession = array_map('intval', (array)($_SESSION['dismissed_poll_bubbles'] ?? []));

        // Persistent database dismissals with timestamp
        $stmtD = $db->prepare("SELECT poll_id, dismissed_at FROM user_poll_bubble_dismissals WHERE user_id = :uid");
        $stmtD->execute(['uid' => $userId]);
        $dbDismissals = $stmtD->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $dismissedTimestamps = [];
        foreach ($dbDismissals as $d) {
            $dismissedTimestamps[(int)$d['poll_id']] = !empty($d['dismissed_at']) ? strtotime((string)$d['dismissed_at']) : time();
        }

        // Find active open published polls (authoritative database NOW())
        $stmt = $db->query("
            SELECT p.* 
            FROM polls p
            WHERE p.status = 'published'
              AND (p.starts_at IS NULL OR p.starts_at <= NOW())
              AND (p.ends_at IS NULL OR p.ends_at >= NOW())
            ORDER BY p.id DESC
        ");
        $activePolls = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($activePolls)) {
            return null;
        }

        // Check user votes for active polls
        $pollIds = array_map('intval', array_column($activePolls, 'id'));
        $placeholders = implode(',', array_fill(0, count($pollIds), '?'));
        $voteStmt = $db->prepare("SELECT poll_id, option_id FROM poll_votes WHERE user_id = ? AND poll_id IN ({$placeholders})");
        $voteStmt->execute(array_merge([$userId], $pollIds));
        $userVotedMap = $voteStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        // Helper to determine if a specific poll is genuinely dismissed
        $isPollDismissed = function(array $p) use ($dismissedSession, $dismissedTimestamps): bool {
            $pid = (int)$p['id'];
            if (in_array($pid, $dismissedSession, true)) {
                return true;
            }
            if (isset($dismissedTimestamps[$pid])) {
                $dismissTime = $dismissedTimestamps[$pid];
                $updateTime = !empty($p['updated_at']) ? strtotime((string)$p['updated_at']) : 0;
                // If poll was updated/re-published after dismissal, do not suppress it
                if ($updateTime <= $dismissTime) {
                    return true;
                }
            }
            return false;
        };

        // Pick the best candidate poll:
        // Priority 1: Active poll user hasn't voted in yet and hasn't dismissed
        $selectedPoll = null;
        foreach ($activePolls as $p) {
            $pid = (int)$p['id'];
            if ($isPollDismissed($p)) {
                continue;
            }
            if (!isset($userVotedMap[$pid])) {
                $selectedPoll = $p;
                break;
            }
        }

        // Priority 2: If all unvoted polls were dismissed/unavailable, check polls that allow changing vote
        if (!$selectedPoll) {
            foreach ($activePolls as $p) {
                $pid = (int)$p['id'];
                if (!$isPollDismissed($p) && (bool)$p['allow_change']) {
                    $selectedPoll = $p;
                    break;
                }
            }
        }

        if (!$selectedPoll) {
            return null;
        }

        $pid = (int)$selectedPoll['id'];
        $hasVoted = isset($userVotedMap[$pid]);

        return [
            'poll_id' => $pid,
            'question' => (string)$selectedPoll['question'],
            'description' => (string)($selectedPoll['description'] ?? ''),
            'has_voted' => $hasVoted,
            'allow_change' => (bool)$selectedPoll['allow_change'],
            'ends_at' => $selectedPoll['ends_at'] ? (string)$selectedPoll['ends_at'] : null
        ];
    } catch (\Throwable $e) {
        error_log("get_floating_voting_bubble_data error: " . $e->getMessage());
        return null;
    }
}

/**
 * Cast a vote in a poll with atomic database transaction & constraint safety
 */
function cast_poll_vote(PDO $db, int $pollId, int $optionId, int $userId, ?string $ipAddress = null): array {
    ensure_voting_tables_exist($db);

    // 1. Authoritative check on poll status & schedule
    $pollStmt = $db->prepare("
        SELECT * FROM polls 
        WHERE id = :id 
          AND status = 'published'
          AND (starts_at IS NULL OR starts_at <= NOW())
          AND (ends_at IS NULL OR ends_at >= NOW())
        LIMIT 1
    ");
    $pollStmt->execute(['id' => $pollId]);
    $poll = $pollStmt->fetch(PDO::FETCH_ASSOC);

    if (!$poll) {
        // Detailed error context for client
        $checkStmt = $db->prepare("SELECT id, status, starts_at, ends_at FROM polls WHERE id = :id LIMIT 1");
        $checkStmt->execute(['id' => $pollId]);
        $existingPoll = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingPoll) {
            throw new Exception("The selected poll does not exist.");
        }
        if ($existingPoll['status'] !== 'published') {
            throw new Exception("This poll is currently not accepting votes (status: " . htmlspecialchars($existingPoll['status']) . ").");
        }
        throw new Exception("This poll is closed or not currently active for voting.");
    }

    // 2. Validate option belongs to this poll
    $optStmt = $db->prepare("SELECT id, option_text FROM poll_options WHERE id = :oid AND poll_id = :pid LIMIT 1");
    $optStmt->execute(['oid' => $optionId, 'pid' => $pollId]);
    $option = $optStmt->fetch(PDO::FETCH_ASSOC);

    if (!$option) {
        throw new Exception("Selected voting option is invalid for this poll.");
    }

    // 3. Check existing vote
    $existStmt = $db->prepare("SELECT id, option_id FROM poll_votes WHERE poll_id = :pid AND user_id = :uid LIMIT 1");
    $existStmt->execute(['pid' => $pollId, 'uid' => $userId]);
    $existingVote = $existStmt->fetch(PDO::FETCH_ASSOC);

    $db->beginTransaction();
    try {
        if ($existingVote) {
            if (!(bool)$poll['allow_change']) {
                throw new Exception("You have already voted in this poll and changing your vote is not allowed.");
            }

            // Update existing vote
            $upd = $db->prepare("
                UPDATE poll_votes 
                SET option_id = :oid, ip_address = :ip, updated_at = NOW() 
                WHERE id = :vid
            ");
            $upd->execute([
                'oid' => $optionId,
                'ip' => $ipAddress,
                'vid' => $existingVote['id']
            ]);
            $isChange = true;
        } else {
            // Insert new vote
            $ins = $db->prepare("
                INSERT INTO poll_votes (poll_id, option_id, user_id, ip_address) 
                VALUES (:pid, :oid, :uid, :ip)
            ");
            $ins->execute([
                'pid' => $pollId,
                'oid' => $optionId,
                'uid' => $userId,
                'ip' => $ipAddress
            ]);
            $isChange = false;
        }

        if ($db->inTransaction()) {
            $db->commit();
        }

        return [
            'success' => true,
            'is_change' => $isChange,
            'poll_id' => $pollId,
            'option_id' => $optionId,
            'option_text' => $option['option_text']
        ];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}


