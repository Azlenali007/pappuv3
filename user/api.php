<?php
/**
 * User API Documentation & Key Management Portal
 * SMM Panel - PHP 8.3+
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

// Handle API key regeneration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';

    if ($action === 'regenerate_key') {
        $newApiKey = 'usr_' . bin2hex(random_bytes(14));
        $stmt = $db->prepare("UPDATE users SET api_key = :k WHERE id = :id");
        $stmt->execute(['k' => $newApiKey, 'id' => $user['id']]);

        // Refresh session user
        $_SESSION['user_api_key'] = $newApiKey;
        set_flash('success', 'Your API Key has been refreshed successfully.');
        redirect('/user/api.php');
    }
}

// Current API Key
$apiKeyStmt = $db->prepare("SELECT api_key FROM users WHERE id = :id LIMIT 1");
$apiKeyStmt->execute(['id' => $user['id']]);
$currentApiKey = (string)$apiKeyStmt->fetchColumn();

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:3000';
$apiUrl = $protocol . $host . '/api/v2.php';

$pageTitle = "Developer API - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-5xl mx-auto my-6 space-y-8">
    <!-- Header Back Navigation -->
    <div class="flex items-center justify-between">
        <a href="/user/dashboard.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Back to Dashboard</span>
        </a>
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-200">
            <span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
            SMM API v2 Standard Compatible
        </span>
    </div>

    <!-- API Key & Credentials Card -->
    <div class="bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 rounded-3xl p-6 sm:p-8 text-white shadow-xl relative overflow-hidden space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-slate-800">
            <div>
                <span class="text-[11px] font-bold text-blue-300 uppercase tracking-widest block">Developer Portal</span>
                <h1 class="text-2xl sm:text-3xl font-black tracking-tight mt-1">REST API Credentials</h1>
                <p class="text-xs text-slate-400 mt-0.5">Automate orders, balance monitoring, and catalog synchronization</p>
            </div>
            <div class="text-left sm:text-right">
                <span class="text-[10px] font-mono uppercase text-slate-400 block">HTTP Endpoint</span>
                <span class="text-xs font-mono font-bold text-emerald-300"><?= e($apiUrl) ?></span>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-center">
            <div class="lg:col-span-8 space-y-2">
                <label class="block text-xs font-bold text-slate-300 uppercase tracking-wider">Your Secret API Key</label>
                <div class="flex items-center gap-2 bg-slate-950/80 p-2 rounded-2xl border border-slate-700/80 backdrop-blur-md">
                    <input type="password" id="apiKeyInput" readonly value="<?= e($currentApiKey) ?>" class="flex-1 bg-transparent px-3 py-1.5 text-xs font-mono text-emerald-300 focus:outline-none select-all truncate">
                    <button type="button" onclick="toggleKeyVisibility()" class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-bold transition-colors">
                        <span id="eyeText">Show</span>
                    </button>
                    <button type="button" onclick="copyApiKey()" id="copyKeyBtn" class="px-4 py-1.5 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-xs font-extrabold transition-all shadow-md">
                        Copy Key
                    </button>
                </div>
                <p class="text-[11px] text-slate-400">Keep this key secret. Do not expose it in client-side code or public repositories.</p>
            </div>

            <div class="lg:col-span-4 flex justify-start lg:justify-end">
                <form action="/user/api.php" method="POST" onsubmit="return confirm('Regenerating your API Key will invalidate your current key immediately. Continue?');">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="regenerate_key">
                    <button type="submit" class="px-4 py-2.5 rounded-2xl border border-rose-500/40 bg-rose-500/10 hover:bg-rose-500 text-rose-300 hover:text-white text-xs font-bold transition-all flex items-center gap-2">
                        <span>🔄</span> Regenerate API Key
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- API Endpoints Interactive Documentation -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div>
            <h2 class="text-lg font-black text-slate-900 tracking-tight">API Reference &amp; Specifications</h2>
            <p class="text-xs text-slate-400 mt-0.5">Standard SMM v2 protocol format. All requests must be sent as HTTP POST.</p>
        </div>

        <div class="space-y-6">
            <!-- 1. Service List -->
            <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-blue-600 text-white font-mono">POST</span>
                        <h3 class="text-xs font-extrabold text-slate-900 font-mono">action=services</h3>
                    </div>
                    <span class="text-[11px] text-slate-500">Service Catalog</span>
                </div>
                <p class="text-xs text-slate-600">Retrieve all available active services with current rates, limits, and categories.</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs font-mono">
                        <thead>
                            <tr class="text-slate-400 border-b border-slate-200 pb-2 text-[10px]">
                                <th class="py-2">Parameter</th>
                                <th class="py-2">Type</th>
                                <th class="py-2">Description</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr>
                                <td class="py-2 text-blue-600 font-bold">key</td>
                                <td class="py-2 text-slate-400">string</td>
                                <td class="py-2">Your API Key</td>
                            </tr>
                            <tr>
                                <td class="py-2 text-blue-600 font-bold">action</td>
                                <td class="py-2 text-slate-400">string</td>
                                <td class="py-2">Set to <code class="bg-slate-200 px-1 py-0.5 rounded">services</code></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 2. Add Order -->
            <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-600 text-white font-mono">POST</span>
                        <h3 class="text-xs font-extrabold text-slate-900 font-mono">action=add</h3>
                    </div>
                    <span class="text-[11px] text-slate-500">Create Order</span>
                </div>
                <p class="text-xs text-slate-600">Places a new order with atomic balance deduction and instant order queueing.</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs font-mono">
                        <thead>
                            <tr class="text-slate-400 border-b border-slate-200 pb-2 text-[10px]">
                                <th class="py-2">Parameter</th>
                                <th class="py-2">Type</th>
                                <th class="py-2">Description</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr>
                                <td class="py-2 text-blue-600 font-bold">key</td>
                                <td class="py-2 text-slate-400">string</td>
                                <td class="py-2">Your API Key</td>
                            </tr>
                            <tr>
                                <td class="py-2 text-blue-600 font-bold">action</td>
                                <td class="py-2 text-slate-400">string</td>
                                <td class="py-2">Set to <code class="bg-slate-200 px-1 py-0.5 rounded">add</code></td>
                            </tr>
                            <tr>
                                <td class="py-2 text-blue-600 font-bold">service</td>
                                <td class="py-2 text-slate-400">integer</td>
                                <td class="py-2">Service ID (from services action)</td>
                            </tr>
                            <tr>
                                <td class="py-2 text-blue-600 font-bold">link</td>
                                <td class="py-2 text-slate-400">string</td>
                                <td class="py-2">Target profile or post URL</td>
                            </tr>
                            <tr>
                                <td class="py-2 text-blue-600 font-bold">quantity</td>
                                <td class="py-2 text-slate-400">integer</td>
                                <td class="py-2">Desired quantity within min/max bounds</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 3. Order Status -->
            <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-purple-600 text-white font-mono">POST</span>
                        <h3 class="text-xs font-extrabold text-slate-900 font-mono">action=status</h3>
                    </div>
                    <span class="text-[11px] text-slate-500">Order Progress</span>
                </div>
                <p class="text-xs text-slate-600">Check current delivery progress, start count, remains, and completion status.</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs font-mono">
                        <thead>
                            <tr class="text-slate-400 border-b border-slate-200 pb-2 text-[10px]">
                                <th class="py-2">Parameter</th>
                                <th class="py-2">Type</th>
                                <th class="py-2">Description</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr>
                                <td class="py-2 text-blue-600 font-bold">key</td>
                                <td class="py-2 text-slate-400">string</td>
                                <td class="py-2">Your API Key</td>
                            </tr>
                            <tr>
                                <td class="py-2 text-blue-600 font-bold">action</td>
                                <td class="py-2 text-slate-400">string</td>
                                <td class="py-2">Set to <code class="bg-slate-200 px-1 py-0.5 rounded">status</code></td>
                            </tr>
                            <tr>
                                <td class="py-2 text-blue-600 font-bold">order</td>
                                <td class="py-2 text-slate-400">integer</td>
                                <td class="py-2">Single Order ID (or use <code>orders</code> with comma-separated IDs)</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 4. User Balance -->
            <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-600 text-white font-mono">POST</span>
                        <h3 class="text-xs font-extrabold text-slate-900 font-mono">action=balance</h3>
                    </div>
                    <span class="text-[11px] text-slate-500">Account Balance</span>
                </div>
                <p class="text-xs text-slate-600">Retrieve your current available wallet balance and currency.</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs font-mono">
                        <thead>
                            <tr class="text-slate-400 border-b border-slate-200 pb-2 text-[10px]">
                                <th class="py-2">Parameter</th>
                                <th class="py-2">Type</th>
                                <th class="py-2">Description</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr>
                                <td class="py-2 text-blue-600 font-bold">key</td>
                                <td class="py-2 text-slate-400">string</td>
                                <td class="py-2">Your API Key</td>
                            </tr>
                            <tr>
                                <td class="py-2 text-blue-600 font-bold">action</td>
                                <td class="py-2 text-slate-400">string</td>
                                <td class="py-2">Set to <code class="bg-slate-200 px-1 py-0.5 rounded">balance</code></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Code Examples Box -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-4">
        <h3 class="text-base font-extrabold text-slate-900">Code Examples</h3>
        
        <!-- cURL Example -->
        <div class="space-y-2">
            <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">cURL (Add Order)</span>
            <pre class="bg-slate-900 text-blue-300 p-4 rounded-2xl text-xs font-mono overflow-x-auto select-all">curl -X POST "<?= e($apiUrl) ?>" \
  -d "key=<?= e($currentApiKey) ?>" \
  -d "action=add" \
  -d "service=101" \
  -d "link=https://instagram.com/myprofile" \
  -d "quantity=1000"</pre>
        </div>

        <!-- Python Example -->
        <div class="space-y-2 pt-2">
            <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Python 3 (Requests)</span>
            <pre class="bg-slate-900 text-emerald-300 p-4 rounded-2xl text-xs font-mono overflow-x-auto select-all">import requests

url = "<?= e($apiUrl) ?>"
data = {
    "key": "<?= e($currentApiKey) ?>",
    "action": "balance"
}
response = requests.post(url, data=data)
print(response.json())</pre>
        </div>
    </div>
</div>

<script>
function toggleKeyVisibility() {
    const input = document.getElementById('apiKeyInput');
    const eye = document.getElementById('eyeText');
    if (input.type === 'password') {
        input.type = 'text';
        eye.innerText = 'Hide';
    } else {
        input.type = 'password';
        eye.innerText = 'Show';
    }
}

function copyApiKey() {
    const input = document.getElementById('apiKeyInput');
    const wasPassword = input.type === 'password';
    input.type = 'text';
    input.select();
    navigator.clipboard.writeText(input.value).then(() => {
        if (wasPassword) input.type = 'password';
        const btn = document.getElementById('copyKeyBtn');
        const oldText = btn.innerText;
        btn.innerText = 'Copied!';
        setTimeout(() => { btn.innerText = oldText; }, 2000);
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
