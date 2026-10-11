<?php
/**
 * Admin Currency Management & Dynamic Exchange Rates
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireAdmin();
$db = Database::getConnection();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';

    if ($action === 'sync_live_rates') {
        // Fetch base currency
        $baseStmt = $db->query("SELECT code FROM currencies WHERE is_base = 1 LIMIT 1");
        $baseCode = $baseStmt->fetchColumn() ?: 'INR';

        // Call public open exchange rates API server-side
        $apiUrl = "https://open.er-api.com/v6/latest/" . urlencode($baseCode);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'SMM-Panel-CurrencySync/1.0'
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && !empty($response)) {
            $data = json_decode((string)$response, true);
            $rates = $data['rates'] ?? [];

            if (!empty($rates)) {
                $currencies = $db->query("SELECT id, code, is_base FROM currencies")->fetchAll();
                $updatedCount = 0;

                $updateStmt = $db->prepare("UPDATE currencies SET exchange_rate = :rate, updated_at = CURRENT_TIMESTAMP WHERE id = :id");

                foreach ($currencies as $curr) {
                    if (!empty($curr['is_base'])) {
                        $updateStmt->execute(['rate' => 1.000000, 'id' => $curr['id']]);
                        continue;
                    }

                    $currCode = $curr['code'];
                    if (isset($rates[$currCode])) {
                        $rate = (float)$rates[$currCode];
                        $updateStmt->execute(['rate' => $rate, 'id' => $curr['id']]);
                        $updatedCount++;
                    }
                }

                invalidate_currency_cache();
                set_flash('success', "Live exchange rates successfully synchronized ({$updatedCount} currencies updated against {$baseCode}).");
            } else {
                set_flash('error', "No exchange rate data received from exchange rate provider.");
            }
        } else {
            set_flash('error', "Unable to connect to live exchange rates service (HTTP {$httpCode}). Please check server outbound connection or update manually.");
        }
        redirect('/admin/currencies.php');
    } elseif ($action === 'update_rate') {
        $id = (int)($_POST['id'] ?? 0);
        $rate = (float)($_POST['exchange_rate'] ?? 1.0);
        if ($id > 0 && $rate > 0) {
            $stmt = $db->prepare("UPDATE currencies SET exchange_rate = :rate, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND is_base = 0");
            $stmt->execute(['rate' => $rate, 'id' => $id]);
            invalidate_currency_cache();
            set_flash('success', "Exchange rate updated successfully.");
        }
        redirect('/admin/currencies.php');
    } elseif ($action === 'add_currency') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $symbol = trim($_POST['symbol'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $rate = (float)($_POST['exchange_rate'] ?? 1.0);

        if (empty($code) || empty($symbol) || empty($name) || $rate <= 0) {
            set_flash('error', "All currency fields are required.");
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO currencies (code, symbol, name, exchange_rate, is_base, status)
                    VALUES (:c, :s, :n, :r, 0, 'active')
                ");
                $stmt->execute(['c' => $code, 's' => $symbol, 'n' => $name, 'r' => $rate]);
                invalidate_currency_cache();
                set_flash('success', "Currency {$code} ({$symbol}) added successfully.");
            } catch (\Throwable $e) {
                set_flash('error', "Currency code {$code} already exists or invalid data.");
            }
        }
        redirect('/admin/currencies.php');
    } elseif ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $curr = $db->prepare("SELECT id, status, is_base FROM currencies WHERE id = :id LIMIT 1");
        $curr->execute(['id' => $id]);
        $row = $curr->fetch();

        if ($row && empty($row['is_base'])) {
            $newStatus = $row['status'] === 'active' ? 'inactive' : 'active';
            $db->prepare("UPDATE currencies SET status = :st WHERE id = :id")->execute(['st' => $newStatus, 'id' => $id]);
            invalidate_currency_cache();
            set_flash('success', "Currency status changed to {$newStatus}.");
        }
        redirect('/admin/currencies.php');
    }
}

// Fetch all currencies
$currencies = $db->query("SELECT * FROM currencies ORDER BY is_base DESC, code ASC")->fetchAll();
$baseCurrency = null;
foreach ($currencies as $c) {
    if (!empty($c['is_base'])) {
        $baseCurrency = $c;
        break;
    }
}

$pageTitle = "Currencies & Exchange Rates - Admin Console";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-6xl mx-auto my-6 space-y-8">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight flex items-center gap-2">
                <span>💱</span> Multi-Currency &amp; Exchange Rates
            </h1>
            <p class="text-xs text-slate-500 mt-1">Manage global display currencies, automated real-time exchange rate sync, and regional conversions</p>
        </div>
        <div class="flex items-center gap-3">
            <form action="/admin/currencies.php" method="POST" class="inline">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="sync_live_rates">
                <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-md shadow-emerald-500/20 transition-all flex items-center gap-1.5 active:scale-95">
                    <span>⚡</span> Sync Live Rates Now
                </button>
            </form>
            <button type="button" onclick="document.getElementById('addCurrencyModal').classList.remove('hidden')" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold shadow-md shadow-blue-500/20 transition-all flex items-center gap-1.5">
                <span>+</span> Add Currency
            </button>
        </div>
    </div>

    <?= render_flash() ?>

    <!-- Base Currency Banner -->
    <div class="p-5 rounded-3xl bg-gradient-to-r from-blue-50 via-indigo-50 to-white border border-blue-200/80 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="w-12 h-12 rounded-2xl bg-blue-600 text-white flex items-center justify-center font-black text-xl shadow-md shadow-blue-500/25">
                <?= e($baseCurrency['symbol'] ?? '₹') ?>
            </div>
            <div>
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">System Base Currency</span>
                <span class="text-base font-extrabold text-slate-900">
                    <?= e($baseCurrency['name'] ?? 'Indian Rupee') ?> (<?= e($baseCurrency['code'] ?? 'INR') ?>)
                </span>
                <span class="text-[11px] text-slate-500 block">All database service rates and ledger transactions are anchored to this base currency.</span>
            </div>
        </div>
        <div class="text-left sm:text-right">
            <span class="text-[11px] font-mono text-slate-400">Anchor Ratio</span>
            <div class="text-lg font-black text-blue-700 font-mono">1.000000</div>
        </div>
    </div>

    <!-- Currencies Table Card -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h2 class="text-base font-extrabold text-slate-900 tracking-tight">Active Currency Registry</h2>
                <p class="text-xs text-slate-400 mt-0.5">Rates represent the multiplier relative to 1 unit of base currency (<?= e($baseCurrency['code'] ?? 'INR') ?>)</p>
            </div>
            <span class="text-xs font-bold text-slate-500"><?= count($currencies) ?> Currencies</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="text-slate-400 border-b border-slate-100 pb-3 uppercase text-[10px] font-bold tracking-wider">
                        <th class="py-3 px-3">Currency</th>
                        <th class="py-3 px-3">Symbol</th>
                        <th class="py-3 px-3">Type</th>
                        <th class="py-3 px-3 text-right">Exchange Rate</th>
                        <th class="py-3 px-3">Last Synced</th>
                        <th class="py-3 px-3 text-center">Status</th>
                        <th class="py-3 px-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($currencies as $curr): ?>
                        <tr class="hover:bg-slate-50/70 transition-colors">
                            <td class="py-3.5 px-3">
                                <div class="font-extrabold text-slate-900 text-sm flex items-center gap-2">
                                    <span class="w-7 h-7 rounded-lg bg-slate-100 text-slate-700 flex items-center justify-center font-bold text-xs"><?= e($curr['code']) ?></span>
                                    <span><?= e($curr['name']) ?></span>
                                </div>
                            </td>
                            <td class="py-3.5 px-3 font-bold text-slate-800 text-sm">
                                <?= e($curr['symbol']) ?>
                            </td>
                            <td class="py-3.5 px-3">
                                <?php if (!empty($curr['is_base'])): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-black bg-blue-100 text-blue-800 border border-blue-200">
                                        BASE ANCHOR
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-600">
                                        Secondary
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3.5 px-3 text-right">
                                <?php if (!empty($curr['is_base'])): ?>
                                    <span class="font-mono text-slate-400 font-bold">1.000000</span>
                                <?php else: ?>
                                    <form action="/admin/currencies.php" method="POST" class="inline-flex items-center gap-1.5 justify-end">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="update_rate">
                                        <input type="hidden" name="id" value="<?= $curr['id'] ?>">
                                        <input type="number" step="0.000001" min="0.000001" name="exchange_rate" value="<?= e((string)$curr['exchange_rate']) ?>" class="w-28 px-2 py-1 bg-slate-50 border border-slate-200 rounded-lg text-xs font-mono text-right focus:ring-1 focus:ring-blue-500">
                                        <button type="submit" class="p-1 hover:bg-slate-100 rounded text-blue-600 font-bold" title="Save Rate">✓</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td class="py-3.5 px-3 text-slate-500 font-mono text-[11px] whitespace-nowrap">
                                <?= date('d M Y, H:i', strtotime($curr['updated_at'])) ?>
                            </td>
                            <td class="py-3.5 px-3 text-center">
                                <?php if ($curr['status'] === 'active'): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        Active
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-500">
                                        Disabled
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3.5 px-3 text-right">
                                <?php if (empty($curr['is_base'])): ?>
                                    <form action="/admin/currencies.php" method="POST" class="inline">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?= $curr['id'] ?>">
                                        <button type="submit" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold <?= $curr['status'] === 'active' ? 'text-amber-600 hover:bg-amber-50' : 'text-emerald-600 hover:bg-emerald-50' ?>">
                                            <?= $curr['status'] === 'active' ? 'Disable' : 'Enable' ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-[11px] text-slate-400">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Currency Modal -->
<div id="addCurrencyModal" class="hidden fixed inset-0 z-50 bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-2xl space-y-6">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <h3 class="text-base font-extrabold text-slate-900">Add New Currency</h3>
            <button type="button" onclick="document.getElementById('addCurrencyModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700 font-bold">&times;</button>
        </div>

        <form action="/admin/currencies.php" method="POST" class="space-y-4">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="add_currency">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Currency Code (ISO)</label>
                <input type="text" name="code" required placeholder="e.g. AED, CAD, AUD" maxlength="6" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono uppercase focus:ring-2 focus:ring-blue-500">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Currency Symbol</label>
                <input type="text" name="symbol" required placeholder="e.g. د.إ, $, CA$" maxlength="10" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-blue-500">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Currency Name</label>
                <input type="text" name="name" required placeholder="e.g. UAE Dirham" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-blue-500">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Initial Exchange Rate (vs <?= e($baseCurrency['code'] ?? 'INR') ?>)</label>
                <input type="number" step="0.000001" min="0.000001" name="exchange_rate" value="1.000000" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono focus:ring-2 focus:ring-blue-500">
                <span class="text-[11px] text-slate-400 mt-1 block">Can be automatically updated via Live Rate Sync</span>
            </div>

            <div class="pt-2 flex items-center justify-end gap-2">
                <button type="button" onclick="document.getElementById('addCurrencyModal').classList.add('hidden')" class="px-4 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-5 py-2 rounded-xl text-xs font-bold bg-blue-600 hover:bg-blue-700 text-white shadow-md shadow-blue-500/20">Create Currency</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
