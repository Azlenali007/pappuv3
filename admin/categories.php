<?php
/**
 * Admin Category Management Portal
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

    // 1. Create Category
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $platform = strtolower(trim($_POST['platform'] ?? 'other'));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $status = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';

        $allowedPlatforms = ['instagram', 'youtube', 'telegram', 'facebook', 'tiktok', 'twitter', 'other'];
        if (!in_array($platform, $allowedPlatforms, true)) {
            $platform = 'other';
        }

        if (empty($name)) {
            set_flash('error', 'Category name cannot be empty.');
        } else {
            $stmt = $db->prepare("
                INSERT INTO categories (name, platform, sort_order, status)
                VALUES (:name, :platform, :sort, :status)
            ");
            $stmt->execute([
                'name' => $name,
                'platform' => $platform,
                'sort' => $sortOrder,
                'status' => $status
            ]);
            set_flash('success', "Category '{$name}' created successfully.");
        }
        redirect('/admin/categories.php');
    }

    // 2. Edit Category
    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $platform = strtolower(trim($_POST['platform'] ?? 'other'));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $status = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';

        $allowedPlatforms = ['instagram', 'youtube', 'telegram', 'facebook', 'tiktok', 'twitter', 'other'];
        if (!in_array($platform, $allowedPlatforms, true)) {
            $platform = 'other';
        }

        if ($id <= 0 || empty($name)) {
            set_flash('error', 'Invalid category information supplied.');
        } else {
            $stmt = $db->prepare("
                UPDATE categories 
                SET name = :name, platform = :platform, sort_order = :sort, status = :status
                WHERE id = :id
            ");
            $stmt->execute([
                'name' => $name,
                'platform' => $platform,
                'sort' => $sortOrder,
                'status' => $status,
                'id' => $id
            ]);
            set_flash('success', "Category #{$id} updated successfully.");
        }
        redirect('/admin/categories.php');
    }

    // 3. Toggle Category Status (Enable / Disable)
    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $newStatus = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';

        if ($id > 0) {
            $stmt = $db->prepare("UPDATE categories SET status = :status WHERE id = :id");
            $stmt->execute(['status' => $newStatus, 'id' => $id]);
            set_flash('success', "Category status updated to {$newStatus}.");
        }
        redirect('/admin/categories.php');
    }

    // 4. Safe Delete Category (Integrity Protected)
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            // Check associated services count
            $svcCountStmt = $db->prepare("SELECT COUNT(*) FROM services WHERE category_id = :id");
            $svcCountStmt->execute(['id' => $id]);
            $associatedServices = (int)$svcCountStmt->fetchColumn();

            if ($associatedServices > 0) {
                set_flash('error', "Cannot delete category: Contains {$associatedServices} associated service(s). Please reassign or delete these services first, or disable the category instead.");
            } else {
                $delStmt = $db->prepare("DELETE FROM categories WHERE id = :id");
                $delStmt->execute(['id' => $id]);
                set_flash('success', "Category #{$id} deleted successfully.");
            }
        }
        redirect('/admin/categories.php');
    }
}

// Search and Platform Filters
$search = trim($_GET['search'] ?? '');
$platformFilter = strtolower(trim($_GET['platform'] ?? 'all'));

$sql = "
    SELECT c.*, COUNT(s.id) AS service_count,
           SUM(CASE WHEN s.status = 'active' THEN 1 ELSE 0 END) AS active_service_count
    FROM categories c
    LEFT JOIN services s ON c.id = s.category_id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $sql .= " AND c.name LIKE :search";
    $params['search'] = "%{$search}%";
}

if ($platformFilter !== 'all' && in_array($platformFilter, ['instagram', 'youtube', 'telegram', 'facebook', 'tiktok', 'twitter', 'other'], true)) {
    $sql .= " AND c.platform = :plat";
    $params['plat'] = $platformFilter;
}

$sql .= " GROUP BY c.id ORDER BY c.sort_order ASC, c.id ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$categories = $stmt->fetchAll();

$pageTitle = "Category Management - Admin Console";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-6xl mx-auto my-6 space-y-6" x-data="adminCategoryManager()">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight flex items-center gap-2">
                <span>📁</span> Category Management
            </h1>
            <p class="text-xs text-slate-500 mt-1">Organize social media services by platform, maintain integrity, and configure presentation order</p>
        </div>
        <button type="button" @click="openCreateModal()" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs rounded-xl shadow-md shadow-blue-500/25 active:scale-95 transition-all flex items-center gap-1.5 cursor-pointer">
            <span class="text-base font-black leading-none">+</span>
            <span>Create New Category</span>
        </button>
    </div>

    <?= render_flash() ?>

    <!-- Filter & Search Toolbar -->
    <div class="bg-white rounded-3xl p-4 sm:p-5 border border-slate-200/80 shadow-xs flex flex-col sm:flex-row items-center justify-between gap-4">
        <form action="/admin/categories.php" method="GET" class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search category name..." class="px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium focus:ring-2 focus:ring-blue-500/20 w-full sm:w-64">
            
            <select name="platform" onchange="this.form.submit()" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-700">
                <option value="all" <?= $platformFilter === 'all' ? 'selected' : '' ?>>All Platforms</option>
                <option value="instagram" <?= $platformFilter === 'instagram' ? 'selected' : '' ?>>Instagram</option>
                <option value="youtube" <?= $platformFilter === 'youtube' ? 'selected' : '' ?>>YouTube</option>
                <option value="telegram" <?= $platformFilter === 'telegram' ? 'selected' : '' ?>>Telegram</option>
                <option value="facebook" <?= $platformFilter === 'facebook' ? 'selected' : '' ?>>Facebook</option>
                <option value="tiktok" <?= $platformFilter === 'tiktok' ? 'selected' : '' ?>>TikTok</option>
                <option value="twitter" <?= $platformFilter === 'twitter' ? 'selected' : '' ?>>Twitter</option>
                <option value="other" <?= $platformFilter === 'other' ? 'selected' : '' ?>>Other</option>
            </select>

            <button type="submit" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold rounded-xl transition-colors cursor-pointer">Filter</button>
            <?php if (!empty($search) || $platformFilter !== 'all'): ?>
                <a href="/admin/categories.php" class="text-xs text-rose-500 hover:underline font-bold px-1">Clear</a>
            <?php endif; ?>
        </form>

        <span class="text-xs font-bold text-slate-400 font-mono"><?= count($categories) ?> categories found</span>
    </div>

    <!-- Categories List Table -->
    <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm overflow-x-auto">
        <?php if (empty($categories)): ?>
            <div class="p-8 text-center text-slate-400 text-xs">
                No categories match your search or filter criteria. Click "Create New Category" to add one.
            </div>
        <?php else: ?>
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="text-slate-400 border-b border-slate-100 uppercase text-[10px] font-bold">
                        <th class="py-3 px-3">Order</th>
                        <th class="py-3 px-3">Platform</th>
                        <th class="py-3 px-3">Category Name</th>
                        <th class="py-3 px-3 text-center">Services</th>
                        <th class="py-3 px-3 text-center">Status</th>
                        <th class="py-3 px-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($categories as $cat): 
                        $isActive = ($cat['status'] === 'active');
                        $svcCount = (int)$cat['service_count'];
                    ?>
                        <tr class="hover:bg-slate-50/70 transition-colors">
                            <td class="py-3.5 px-3 font-mono font-bold text-slate-400">#<?= (int)$cat['sort_order'] ?></td>
                            <td class="py-3.5 px-3">
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase border bg-slate-50 text-slate-700 border-slate-200">
                                    <?= e(ucfirst($cat['platform'])) ?>
                                </span>
                            </td>
                            <td class="py-3.5 px-3">
                                <span class="font-extrabold text-slate-900 text-sm"><?= e($cat['name']) ?></span>
                                <span class="block text-[10px] text-slate-400 font-mono">ID: #<?= (int)$cat['id'] ?></span>
                            </td>
                            <td class="py-3.5 px-3 text-center font-mono font-bold">
                                <span class="px-2 py-0.5 rounded-md bg-blue-50 text-blue-700 text-xs">
                                    <?= $svcCount ?> service<?= $svcCount !== 1 ? 's' : '' ?>
                                </span>
                            </td>
                            <td class="py-3.5 px-3 text-center">
                                <?php if ($isActive): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Active
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase bg-slate-100 text-slate-500 border border-slate-200">
                                        Disabled
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3.5 px-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <!-- Edit Button -->
                                    <button type="button" 
                                            @click="openEditModal(<?= htmlspecialchars(json_encode($cat), ENT_QUOTES) ?>)" 
                                            class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-lg text-xs transition-colors cursor-pointer">
                                        Edit
                                    </button>

                                    <!-- Status Toggle -->
                                    <form action="/admin/categories.php" method="POST" class="inline">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                                        <input type="hidden" name="status" value="<?= $isActive ? 'inactive' : 'active' ?>">
                                        <button type="submit" class="px-2.5 py-1 <?= $isActive ? 'bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200' : 'bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200' ?> rounded-lg text-xs font-bold transition-colors cursor-pointer">
                                            <?= $isActive ? 'Disable' : 'Enable' ?>
                                        </button>
                                    </form>

                                    <!-- Delete Button (Only permitted if 0 services) -->
                                    <form action="/admin/categories.php" method="POST" class="inline" onsubmit="return confirm('Delete category <?= addslashes($cat['name']) ?>?')">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                                        <button type="submit" 
                                                <?= $svcCount > 0 ? 'title="Contains ' . $svcCount . ' services - deletion blocked for data integrity"' : '' ?>
                                                class="px-2.5 py-1 <?= $svcCount > 0 ? 'opacity-40 cursor-not-allowed bg-slate-100 text-slate-400' : 'bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 cursor-pointer' ?> rounded-lg text-xs font-bold transition-colors">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- CREATE CATEGORY MODAL -->
    <div x-show="showCreateModal" 
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-xs"
         style="display: none;"
         @keydown.escape.window="showCreateModal = false">
        
        <div @click.outside="showCreateModal = false" class="bg-white rounded-3xl max-w-md w-full p-6 sm:p-8 shadow-2xl border border-slate-100 space-y-5">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <h3 class="text-base font-black text-slate-900">Create New Category</h3>
                <button type="button" @click="showCreateModal = false" class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 font-bold flex items-center justify-center">✕</button>
            </div>

            <form action="/admin/categories.php" method="POST" class="space-y-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="create">

                <div>
                    <label class="block text-xs font-extrabold text-slate-700 uppercase mb-1">Category Name</label>
                    <input type="text" name="name" required placeholder="e.g. Instagram Followers - Instant" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-extrabold text-slate-700 uppercase mb-1">Platform</label>
                        <select name="platform" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
                            <option value="instagram">Instagram</option>
                            <option value="youtube">YouTube</option>
                            <option value="telegram">Telegram</option>
                            <option value="facebook">Facebook</option>
                            <option value="tiktok">TikTok</option>
                            <option value="twitter">Twitter</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-extrabold text-slate-700 uppercase mb-1">Sort Order</label>
                        <input type="number" name="sort_order" value="0" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-extrabold text-slate-700 uppercase mb-1">Status</label>
                    <select name="status" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
                        <option value="active">Active</option>
                        <option value="inactive">Disabled</option>
                    </select>
                </div>

                <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                    <button type="button" @click="showCreateModal = false" class="px-4 py-2 bg-slate-100 text-slate-700 font-bold rounded-xl text-xs">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-extrabold rounded-xl text-xs shadow-md shadow-blue-500/25">Create Category</button>
                </div>
            </form>
        </div>
    </div>

    <!-- EDIT CATEGORY MODAL -->
    <div x-show="editingCategory !== null" 
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-xs"
         style="display: none;"
         @keydown.escape.window="editingCategory = null">
        
        <div @click.outside="editingCategory = null" class="bg-white rounded-3xl max-w-md w-full p-6 sm:p-8 shadow-2xl border border-slate-100 space-y-5">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <h3 class="text-base font-black text-slate-900" x-text="'Edit Category #' + (editingCategory?.id || '')"></h3>
                <button type="button" @click="editingCategory = null" class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 font-bold flex items-center justify-center">✕</button>
            </div>

            <form action="/admin/categories.php" method="POST" class="space-y-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" :value="editingCategory?.id">

                <div>
                    <label class="block text-xs font-extrabold text-slate-700 uppercase mb-1">Category Name</label>
                    <input type="text" name="name" required :value="editingCategory?.name" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-extrabold text-slate-700 uppercase mb-1">Platform</label>
                        <select name="platform" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
                            <option value="instagram" :selected="editingCategory?.platform === 'instagram'">Instagram</option>
                            <option value="youtube" :selected="editingCategory?.platform === 'youtube'">YouTube</option>
                            <option value="telegram" :selected="editingCategory?.platform === 'telegram'">Telegram</option>
                            <option value="facebook" :selected="editingCategory?.platform === 'facebook'">Facebook</option>
                            <option value="tiktok" :selected="editingCategory?.platform === 'tiktok'">TikTok</option>
                            <option value="twitter" :selected="editingCategory?.platform === 'twitter'">Twitter</option>
                            <option value="other" :selected="editingCategory?.platform === 'other'">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-extrabold text-slate-700 uppercase mb-1">Sort Order</label>
                        <input type="number" name="sort_order" :value="editingCategory?.sort_order" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-extrabold text-slate-700 uppercase mb-1">Status</label>
                    <select name="status" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
                        <option value="active" :selected="editingCategory?.status === 'active'">Active</option>
                        <option value="inactive" :selected="editingCategory?.status === 'inactive'">Disabled</option>
                    </select>
                </div>

                <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                    <button type="button" @click="editingCategory = null" class="px-4 py-2 bg-slate-100 text-slate-700 font-bold rounded-xl text-xs">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-extrabold rounded-xl text-xs shadow-md shadow-blue-500/25">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function adminCategoryManager() {
    return {
        showCreateModal: false,
        editingCategory: null,

        openCreateModal() {
            this.showCreateModal = true;
        },

        openEditModal(cat) {
            this.editingCategory = cat;
        }
    };
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
