<?php
/**
 * Admin Services Management
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_service') {
        $catId = (int)$_POST['category_id'];
        $name = trim($_POST['name']);
        $rate = (float)$_POST['rate_per_1000'];
        $min = (int)$_POST['min_quantity'];
        $max = (int)$_POST['max_quantity'];
        $speed = trim($_POST['speed'] ?? 'Fast Delivery');

        $stmt = $db->prepare("
            INSERT INTO services (category_id, name, rate_per_1000, min_quantity, max_quantity, speed, status)
            VALUES (:cid, :name, :rate, :min, :max, :speed, 'active')
        ");
        $stmt->execute(['cid' => $catId, 'name' => $name, 'rate' => $rate, 'min' => $min, 'max' => $max, 'speed' => $speed]);
        set_flash('success', 'Service added successfully!');
        redirect('/admin/services.php');
    } elseif ($action === 'update_rate') {
        $sid = (int)$_POST['service_id'];
        $rate = (float)$_POST['rate_per_1000'];
        $db->prepare("UPDATE services SET rate_per_1000 = :r, updated_at = CURRENT_TIMESTAMP WHERE id = :id")->execute(['r' => $rate, 'id' => $sid]);
        set_flash('success', 'Rate updated.');
        redirect('/admin/services.php');
    } elseif ($action === 'delete_single') {
        $sid = (int)($_POST['service_id'] ?? 0);
        if ($sid > 0) {
            try {
                $del = $db->prepare("DELETE FROM services WHERE id = :id");
                $del->execute(['id' => $sid]);
                set_flash('success', "Service #{$sid} deleted successfully.");
            } catch (\PDOException $e) {
                // In case orders refer to service, soft-delete
                $db->prepare("UPDATE services SET status = 'inactive' WHERE id = :id")->execute(['id' => $sid]);
                set_flash('info', "Service #{$sid} has linked orders, so it was set to inactive.");
            }
        }
        redirect('/admin/services.php');
    } elseif ($action === 'bulk_delete') {
        $selectedIds = array_filter(array_map('intval', $_POST['selected_ids'] ?? []));
        if (!empty($selectedIds)) {
            $deletedCount = 0;
            $deactivatedCount = 0;
            foreach ($selectedIds as $sid) {
                try {
                    $del = $db->prepare("DELETE FROM services WHERE id = :id");
                    $del->execute(['id' => $sid]);
                    $deletedCount++;
                } catch (\PDOException $e) {
                    $db->prepare("UPDATE services SET status = 'inactive' WHERE id = :id")->execute(['id' => $sid]);
                    $deactivatedCount++;
                }
            }
            $msg = "Successfully processed {$deletedCount} service(s) deletion.";
            if ($deactivatedCount > 0) {
                $msg .= " ({$deactivatedCount} with existing orders marked inactive).";
            }
            set_flash('success', $msg);
        } else {
            set_flash('error', 'No services were selected for deletion.');
        }
        redirect('/admin/services.php');
    } elseif ($action === 'bulk_status') {
        $selectedIds = array_filter(array_map('intval', $_POST['selected_ids'] ?? []));
        $newStatus = in_array($_POST['target_status'] ?? '', ['active', 'inactive']) ? $_POST['target_status'] : 'active';
        if (!empty($selectedIds)) {
            $stmt = $db->prepare("UPDATE services SET status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            foreach ($selectedIds as $sid) {
                $stmt->execute(['st' => $newStatus, 'id' => $sid]);
            }
            set_flash('success', "Updated status to '{$newStatus}' for " . count($selectedIds) . " service(s).");
        } else {
            set_flash('error', 'No services were selected.');
        }
        redirect('/admin/services.php');
    }
}

$categories = $db->query("SELECT * FROM categories ORDER BY sort_order ASC")->fetchAll();
$services = $db->query("
    SELECT s.*, c.name AS category_name
    FROM services s
    JOIN categories c ON s.category_id = c.id
    ORDER BY s.id DESC
")->fetchAll();

$pageTitle = "Manage Services - " . app_name() . " Admin";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto my-4 space-y-6">
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-slate-100">
            <div>
                <h2 class="text-2xl font-extrabold text-slate-900">Services & Pricing Engine</h2>
                <p class="text-xs text-slate-500 mt-1">Manage rates, bulk select, and configure platform catalogs</p>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs text-slate-400 font-bold"><?= count($services) ?> Total Services</span>
                <a href="/admin/import-services.php" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 shadow-sm">
                    <span>⚡</span> Import from Provider API
                </a>
                <button type="button" onclick="document.getElementById('addSvcForm').classList.toggle('hidden')" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold shadow-md shadow-blue-500/20 transition-all flex items-center gap-1.5">
                    <span>+</span> Add Service
                </button>
            </div>
        </div>

        <!-- Add Service Form -->
        <div id="addSvcForm" class="hidden my-6 p-6 rounded-2xl bg-slate-50 border border-slate-200">
            <h3 class="text-sm font-bold text-slate-900 mb-4">Add New Service</h3>
            <form action="/admin/services.php" method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="create_service">

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Category</label>
                    <select name="category_id" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs">
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Service Title</label>
                    <input type="text" name="name" required placeholder="Instagram Followers - Instant Start" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Rate / 1K (<?= e(app_currency()) ?>)</label>
                    <input type="number" step="0.01" name="rate_per_1000" required placeholder="35.00" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Min Quantity</label>
                    <input type="number" name="min_quantity" value="100" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Max Quantity</label>
                    <input type="number" name="max_quantity" value="1000000" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs">
                </div>

                <div class="sm:col-span-3 text-right">
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white rounded-xl text-xs font-bold shadow-md">Create Service</button>
                </div>
            </form>
        </div>

        <!-- Bulk Operations Bar -->
        <form id="bulkActionForm" action="/admin/services.php" method="POST">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" id="bulkActionInput" value="">
            <input type="hidden" name="target_status" id="targetStatusInput" value="">

            <div id="bulkActionBar" class="hidden my-4 p-4 rounded-2xl bg-slate-900 text-white flex flex-wrap items-center justify-between gap-3 shadow-lg transition-all animate-fadeIn">
                <div class="flex items-center gap-3">
                    <span class="w-7 h-7 rounded-lg bg-blue-600 flex items-center justify-center font-bold text-xs" id="selectedCountBadge">0</span>
                    <span class="text-xs font-bold"><span id="selectedCountText">0</span> service(s) selected</span>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="confirmBulkDelete()" class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-bold shadow-md transition-all flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        Delete Selected
                    </button>
                    <button type="button" onclick="submitBulkStatus('active')" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition-all">
                        Set Active
                    </button>
                    <button type="button" onclick="submitBulkStatus('inactive')" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold transition-all">
                        Set Inactive
                    </button>
                    <button type="button" onclick="deselectAll()" class="px-3 py-2 text-xs text-slate-400 hover:text-white transition-colors">
                        Clear Selection
                    </button>
                </div>
            </div>

            <!-- Services Table -->
            <div class="overflow-x-auto mt-4">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 text-slate-400 uppercase tracking-wider">
                            <th class="py-3 px-3 w-10 text-center">
                                <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)" class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer">
                            </th>
                            <th class="py-3 px-3">ID</th>
                            <th class="py-3 px-3">Service Name</th>
                            <th class="py-3 px-3">Category</th>
                            <th class="py-3 px-3">Rate / 1K</th>
                            <th class="py-3 px-3">Min / Max</th>
                            <th class="py-3 px-3">Status</th>
                            <th class="py-3 px-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <?php foreach ($services as $s): ?>
                            <tr class="hover:bg-slate-50 transition-colors" id="row-<?= (int)$s['id'] ?>">
                                <td class="py-3 px-3 text-center">
                                    <input type="checkbox" name="selected_ids[]" value="<?= (int)$s['id'] ?>" onchange="updateBulkBar()" class="service-checkbox w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer">
                                </td>
                                <td class="py-3 px-3 font-mono text-slate-400">#<?= (int)$s['id'] ?></td>
                                <td class="py-3 px-3 font-bold text-slate-900"><?= e($s['name']) ?></td>
                                <td class="py-3 px-3 text-slate-500"><?= e($s['category_name']) ?></td>
                                <td class="py-3 px-3 font-extrabold text-blue-600 tabular-nums"><?= format_currency($s['rate_per_1000']) ?></td>
                                <td class="py-3 px-3 text-slate-500"><?= number_format($s['min_quantity']) ?> - <?= number_format($s['max_quantity']) ?></td>
                                <td class="py-3 px-3">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase <?= $s['status'] === 'active' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-600 border border-slate-200' ?>">
                                        <?= e($s['status']) ?>
                                    </span>
                                </td>
                                <td class="py-3 px-3 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <button type="button" onclick="editRate(<?= (int)$s['id'] ?>, <?= (float)$s['rate_per_1000'] ?>, '<?= e(addslashes($s['name'])) ?>')" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-bold transition-colors">
                                            Edit Rate
                                        </button>
                                        <button type="button" onclick="confirmSingleDelete(<?= (int)$s['id'] ?>, '<?= e(addslashes($s['name'])) ?>')" class="px-2 py-1 text-rose-500 hover:bg-rose-50 rounded-lg text-xs font-bold transition-colors" title="Delete service">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
</div>

<!-- Hidden Rate Update Form for Swal Prompt -->
<form id="singleRateForm" action="/admin/services.php" method="POST" class="hidden">
    <?= CSRF::field() ?>
    <input type="hidden" name="action" value="update_rate">
    <input type="hidden" name="service_id" id="rateServiceId">
    <input type="hidden" name="rate_per_1000" id="rateAmount">
</form>

<!-- Hidden Single Delete Form -->
<form id="singleDeleteForm" action="/admin/services.php" method="POST" class="hidden">
    <?= CSRF::field() ?>
    <input type="hidden" name="action" value="delete_single">
    <input type="hidden" name="service_id" id="singleDeleteId">
</form>

<script>
function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.service-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = master.checked;
        highlightRow(cb);
    });
    updateBulkBar();
}

function highlightRow(cb) {
    const row = document.getElementById('row-' + cb.value);
    if (row) {
        if (cb.checked) {
            row.classList.add('bg-blue-50/40');
        } else {
            row.classList.remove('bg-blue-50/40');
        }
    }
}

function updateBulkBar() {
    const checkboxes = document.querySelectorAll('.service-checkbox');
    const checked = document.querySelectorAll('.service-checkbox:checked');
    const master = document.getElementById('selectAllCheckbox');
    const bar = document.getElementById('bulkActionBar');
    const countBadge = document.getElementById('selectedCountBadge');
    const countText = document.getElementById('selectedCountText');

    checkboxes.forEach(cb => highlightRow(cb));

    if (master) {
        master.checked = checkboxes.length > 0 && checked.length === checkboxes.length;
        master.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
    }

    if (checked.length > 0) {
        bar.classList.remove('hidden');
        countBadge.innerText = checked.length;
        countText.innerText = checked.length;
    } else {
        bar.classList.add('hidden');
    }
}

function deselectAll() {
    const master = document.getElementById('selectAllCheckbox');
    if (master) master.checked = false;
    toggleSelectAll({ checked: false });
}

function confirmBulkDelete() {
    const checked = document.querySelectorAll('.service-checkbox:checked');
    const count = checked.length;
    if (count === 0) {
        Swal.fire({
            icon: 'info',
            title: 'No services selected',
            text: 'Please select one or more services to delete.'
        });
        return;
    }

    Swal.fire({
        title: 'Delete Selected Services?',
        text: `Are you sure you want to delete the ${count} selected service(s)? This action cannot be undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, delete them!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.getElementById('bulkActionForm');
            document.getElementById('bulkActionInput').value = 'bulk_delete';
            form.submit();
        }
    });
}

function submitBulkStatus(status) {
    const checked = document.querySelectorAll('.service-checkbox:checked');
    const count = checked.length;
    if (count === 0) return;

    Swal.fire({
        title: `Set ${status === 'active' ? 'Active' : 'Inactive'}?`,
        text: `Do you want to change status to "${status}" for ${count} selected service(s)?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: status === 'active' ? '#10b981' : '#64748b',
        confirmButtonText: 'Confirm',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.getElementById('bulkActionForm');
            document.getElementById('bulkActionInput').value = 'bulk_status';
            document.getElementById('targetStatusInput').value = status;
            form.submit();
        }
    });
}

function confirmSingleDelete(id, name) {
    Swal.fire({
        title: 'Delete Service?',
        text: `Are you sure you want to delete service #${id} ("${name}")?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('singleDeleteId').value = id;
            document.getElementById('singleDeleteForm').submit();
        }
    });
}

function editRate(id, currentRate, name) {
    Swal.fire({
        title: 'Update Rate per 1K',
        text: name,
        input: 'number',
        inputValue: currentRate,
        inputAttributes: {
            step: '0.01',
            min: '0.01'
        },
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        confirmButtonText: 'Update Rate',
        cancelButtonText: 'Cancel',
        inputValidator: (value) => {
            if (!value || parseFloat(value) <= 0) {
                return 'Please enter a valid rate greater than zero.';
            }
        }
    }).then((result) => {
        if (result.isConfirmed && result.value) {
            document.getElementById('rateServiceId').value = id;
            document.getElementById('rateAmount').value = result.value;
            document.getElementById('singleRateForm').submit();
        }
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
