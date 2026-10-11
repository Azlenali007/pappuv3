<?php
/**
 * Admin API Providers Management
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

// Handle AJAX or Form Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';
    $isAjax = isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

    // 1. Refresh Provider Balance via real API call
    if ($action === 'refresh_balance') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $stmt = $db->prepare("SELECT id, name, api_url, api_key FROM providers WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $providerId]);
        $provider = $stmt->fetch();

        if (!$provider) {
            $msg = "Provider #{$providerId} not found in database.";
            if ($isAjax) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => false, 'error' => $msg]);
                exit;
            }
            set_flash('error', $msg);
            redirect('/admin/providers.php');
        }

        // Contact external provider API
        $response = call_provider_api($provider['api_url'], [
            'key' => $provider['api_key'],
            'action' => 'balance'
        ], 12);

        if (!empty($response['error'])) {
            $err = "API Error from " . e($provider['name']) . ": " . (is_string($response['error']) ? $response['error'] : 'Unknown API error');
            if ($isAjax) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => false, 'error' => $err]);
                exit;
            }
            set_flash('error', $err);
            redirect('/admin/providers.php');
        }

        if (isset($response['balance'])) {
            $newBalance = (float)$response['balance'];
            $currency = !empty($response['currency']) ? strtoupper(trim((string)$response['currency'])) : 'USD';

            $upd = $db->prepare("UPDATE providers SET balance = :b, currency = :c, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $upd->execute([
                'b' => $newBalance,
                'c' => $currency,
                'id' => $providerId
            ]);

            $formatted = $currency . ' ' . number_format($newBalance, 2);
            if ($isAjax) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'success' => true,
                    'balance' => $newBalance,
                    'currency' => $currency,
                    'formatted' => $formatted,
                    'message' => "Successfully refreshed balance for {$provider['name']}: {$formatted}"
                ]);
                exit;
            }

            set_flash('success', "Balance for {$provider['name']} refreshed: {$formatted}");
            redirect('/admin/providers.php');
        } else {
            $err = "Provider responded, but the response did not include a valid balance field. Endpoint may not support 'action=balance'.";
            if ($isAjax) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => false, 'error' => $err]);
                exit;
            }
            set_flash('error', $err);
            redirect('/admin/providers.php');
        }
    }

    // 2. Add New Provider
    if ($action === 'add_provider') {
        $name = trim($_POST['name'] ?? '');
        $apiUrl = trim($_POST['api_url'] ?? '');
        $apiKey = trim($_POST['api_key'] ?? '');

        if (!empty($name) && !empty($apiUrl) && !empty($apiKey)) {
            $stmt = $db->prepare("INSERT INTO providers (name, api_url, api_key, status) VALUES (:name, :url, :key, 'active')");
            $stmt->execute(['name' => $name, 'url' => $apiUrl, 'key' => $apiKey]);
            $newId = (int)$db->lastInsertId();

            // Try fetching initial live balance
            $initialResponse = call_provider_api($apiUrl, [
                'key' => $apiKey,
                'action' => 'balance'
            ], 8);

            if (isset($initialResponse['balance'])) {
                $initBal = (float)$initialResponse['balance'];
                $initCurr = !empty($initialResponse['currency']) ? strtoupper((string)$initialResponse['currency']) : 'USD';
                $db->prepare("UPDATE providers SET balance = :b, currency = :c WHERE id = :id")->execute([
                    'b' => $initBal,
                    'c' => $initCurr,
                    'id' => $newId
                ]);
                set_flash('success', "External provider '{$name}' added. Initial balance: {$initCurr} " . number_format($initBal, 2));
            } else {
                set_flash('success', "External provider '{$name}' added. (Could not fetch initial balance automatically - you can refresh anytime).");
            }
            redirect('/admin/providers.php');
        } else {
            set_flash('error', 'All provider fields are required.');
            redirect('/admin/providers.php');
        }
    }

    // 3. Delete Provider
    if ($action === 'delete_provider') {
        $pid = (int)($_POST['provider_id'] ?? 0);
        if ($pid > 0) {
            $db->prepare("DELETE FROM providers WHERE id = :id")->execute(['id' => $pid]);
            set_flash('success', "Provider #{$pid} removed.");
        }
        redirect('/admin/providers.php');
    }
}

$providers = $db->query("SELECT * FROM providers ORDER BY id DESC")->fetchAll();
$csrfToken = CSRF::getToken();

$pageTitle = "API Providers - " . app_name() . " Admin";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-6xl mx-auto my-4 space-y-6">
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-slate-100">
            <div>
                <h2 class="text-2xl font-extrabold text-slate-900">External SMM API Providers</h2>
                <p class="text-xs text-slate-500 mt-1">Connect external SMM servers, track live API balances, and dispatch automated orders</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-slate-400"><?= count($providers) ?> Provider(s) Connected</span>
            </div>
        </div>

        <!-- Add Provider Form -->
        <div class="my-6 p-6 rounded-2xl bg-slate-50 border border-slate-200">
            <h3 class="text-sm font-bold text-slate-900 mb-3 flex items-center gap-2">
                <span>🔌</span> Connect New SMM API Provider
            </h3>
            <form action="/admin/providers.php" method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="add_provider">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Provider Name</label>
                    <input type="text" name="name" required placeholder="Main SMM Server" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">API Endpoint URL</label>
                    <input type="url" name="api_url" required placeholder="https://provider.com/api/v2" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Provider API Key</label>
                    <input type="password" name="api_key" required placeholder="api_key_xxxxxxxx" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                </div>
                <div class="sm:col-span-3 text-right">
                    <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-md transition-all">
                        Connect Provider &rarr;
                    </button>
                </div>
            </form>
        </div>

        <!-- Architecture Notice for Extensibility (Requirement 1: Future Feature Ready) -->
        <div class="p-4 rounded-2xl bg-blue-50/50 border border-blue-200/80 text-xs text-blue-900 flex items-start gap-3">
            <div class="text-base text-blue-600">ℹ️</div>
            <div>
                <strong class="font-bold">Provider API Architecture:</strong>
                <span class="text-blue-800">
                    Providers configured here communicate directly through server-side cURL without exposing secrets to the browser.
                    The system architecture is prepared to support future automated service catalog imports and synchronization directly via these endpoints.
                </span>
            </div>
        </div>

        <!-- Providers Table -->
        <div class="overflow-x-auto mt-6">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-slate-200 text-slate-400 uppercase tracking-wider">
                        <th class="py-3 px-3">ID</th>
                        <th class="py-3 px-3">Provider</th>
                        <th class="py-3 px-3">API URL</th>
                        <th class="py-3 px-3">Live API Balance</th>
                        <th class="py-3 px-3">Status</th>
                        <th class="py-3 px-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php if (empty($providers)): ?>
                        <tr><td colspan="6" class="py-8 text-center text-slate-400">No external providers added yet. Connect one to enable live balance checks and automated order forwarding.</td></tr>
                    <?php else: ?>
                        <?php foreach ($providers as $p): ?>
                            <tr class="hover:bg-slate-50 transition-colors" id="provider-row-<?= (int)$p['id'] ?>">
                                <td class="py-3 px-3 font-mono text-slate-400">#<?= (int)$p['id'] ?></td>
                                <td class="py-3 px-3 font-bold text-slate-900"><?= e($p['name']) ?></td>
                                <td class="py-3 px-3 font-mono text-[11px] text-slate-500 max-w-xs truncate" title="<?= e($p['api_url']) ?>">
                                    <?= e($p['api_url']) ?>
                                </td>
                                <td class="py-3 px-3">
                                    <div class="flex items-center gap-2">
                                        <span id="provider-bal-<?= (int)$p['id'] ?>" class="font-extrabold text-emerald-600 tabular-nums">
                                            <?= e($p['currency'] ?? 'USD') ?> <?= number_format((float)$p['balance'], 2) ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="py-3 px-3">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?= $p['status'] === 'active' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-600' ?>">
                                        <?= e($p['status']) ?>
                                    </span>
                                </td>
                                <td class="py-3 px-3 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <!-- Working Refresh Balance Button (AJAX) -->
                                        <button type="button" 
                                                id="btn-refresh-<?= (int)$p['id'] ?>"
                                                onclick="refreshProviderBalance(<?= (int)$p['id'] ?>, this)"
                                                class="px-3 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 rounded-xl font-bold text-xs transition-all flex items-center gap-1.5 shadow-xs"
                                                title="Fetch current live balance from provider API">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                            <span>Refresh Balance</span>
                                        </button>

                                        <!-- Service Import Link (Reusing Provider Credentials) -->
                                        <a href="/admin/import-services.php?provider_id=<?= (int)$p['id'] ?>" 
                                           class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-bold text-xs transition-colors flex items-center gap-1"
                                           title="Import services from this provider">
                                            <span>⚡</span> Import Services
                                        </a>

                                        <!-- Delete Provider Button -->
                                        <button type="button"
                                                onclick="confirmDeleteProvider(<?= (int)$p['id'] ?>, '<?= e(addslashes($p['name'])) ?>')"
                                                class="px-2.5 py-1.5 text-rose-600 hover:bg-rose-50 rounded-xl font-bold text-xs transition-colors"
                                                title="Delete Provider">
                                            ✕
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Hidden Delete Form -->
<form id="deleteProviderForm" action="/admin/providers.php" method="POST" class="hidden">
    <?= CSRF::field() ?>
    <input type="hidden" name="action" value="delete_provider">
    <input type="hidden" name="provider_id" id="deleteProviderId">
</form>

<script>
const CSRF_TOKEN = '<?= e($csrfToken) ?>';

/**
 * Refresh Provider Balance via Secure AJAX Request
 * Contact backend -> Backend contacts Provider API -> Real Balance displayed
 */
async function refreshProviderBalance(providerId, btn) {
    const originalContent = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = `
        <svg class="animate-spin w-3.5 h-3.5 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
        </svg>
        <span>Checking...</span>
    `;

    try {
        const formData = new FormData();
        formData.append('action', 'refresh_balance');
        formData.append('provider_id', providerId);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('ajax', '1');

        const response = await fetch('/admin/providers.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const data = await response.json();

        if (data.success) {
            const balElement = document.getElementById(`provider-bal-${providerId}`);
            if (balElement) {
                balElement.innerText = data.formatted;
                balElement.classList.add('bg-emerald-100', 'px-1.5', 'py-0.5', 'rounded');
                setTimeout(() => {
                    balElement.classList.remove('bg-emerald-100', 'px-1.5', 'py-0.5', 'rounded');
                }, 2000);
            }

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: data.message || 'Balance updated: ' + data.formatted,
                    showConfirmButton: false,
                    timer: 2800
                });
            }
        } else {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Balance Check Failed',
                    text: data.error || 'Failed to retrieve balance from provider API.'
                });
            } else {
                alert('Balance check failed: ' + (data.error || 'Unknown error'));
            }
        }
    } catch (err) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'error',
                title: 'Network / Connection Error',
                text: 'Could not connect to the server: ' + err.message
            });
        } else {
            alert('Connection error: ' + err.message);
        }
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalContent;
    }
}

function confirmDeleteProvider(id, name) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Delete Provider?',
            text: `Are you sure you want to remove "${name}" (#${id})? Linked orders will lose their provider reference.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, delete',
            cancelButtonText: 'Cancel'
        }).then((res) => {
            if (res.isConfirmed) {
                document.getElementById('deleteProviderId').value = id;
                document.getElementById('deleteProviderForm').submit();
            }
        });
    } else {
        if (confirm(`Delete provider "${name}"?`)) {
            document.getElementById('deleteProviderId').value = id;
            document.getElementById('deleteProviderForm').submit();
        }
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
