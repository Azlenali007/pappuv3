<?php
/**
 * Admin Community Voting Management Hub
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

// Self-healing database check
ensure_voting_tables_exist($db);

// Handle Form Submissions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';

    // 1. CREATE NEW POLL
    if ($action === 'create') {
        $question = trim($_POST['question'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['draft', 'published', 'paused', 'closed'], true) ? $_POST['status'] : 'draft';
        $resultsVisibility = in_array($_POST['results_visibility'] ?? '', ['always', 'after_vote', 'after_close', 'hidden'], true) ? $_POST['results_visibility'] : 'after_vote';
        $allowChange = isset($_POST['allow_change']) ? 1 : 0;
        
        $startsAt = !empty($_POST['starts_at']) ? date('Y-m-d H:i:s', strtotime($_POST['starts_at'])) : date('Y-m-d H:i:s');
        $endsAt = !empty($_POST['ends_at']) ? date('Y-m-d H:i:s', strtotime($_POST['ends_at'])) : null;

        // Process options
        $rawOptions = $_POST['options'] ?? [];
        $cleanOptions = [];
        if (is_array($rawOptions)) {
            foreach ($rawOptions as $optText) {
                $t = trim((string)$optText);
                if ($t !== '') {
                    $cleanOptions[] = $t;
                }
            }
        }

        if (empty($question)) {
            set_flash('error', 'Poll question is required.');
        } elseif (count($cleanOptions) < 2) {
            set_flash('error', 'A poll requires at least 2 distinct voting options.');
        } elseif ($endsAt !== null && strtotime($endsAt) <= strtotime($startsAt)) {
            set_flash('error', 'Closing date must be scheduled after the start date.');
        } else {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("
                    INSERT INTO polls (question, description, status, results_visibility, allow_change, starts_at, ends_at)
                    VALUES (:q, :d, :st, :rv, :ac, :starts, :ends)
                ");
                $stmt->execute([
                    'q' => $question,
                    'd' => $description ?: null,
                    'st' => $status,
                    'rv' => $resultsVisibility,
                    'ac' => $allowChange,
                    'starts' => $startsAt,
                    'ends' => $endsAt
                ]);
                $pollId = (int)$db->lastInsertId();

                $optStmt = $db->prepare("INSERT INTO poll_options (poll_id, option_text, sort_order) VALUES (:pid, :text, :sort)");
                foreach ($cleanOptions as $idx => $optText) {
                    $optStmt->execute([
                        'pid' => $pollId,
                        'text' => $optText,
                        'sort' => $idx + 1
                    ]);
                }

                $db->commit();
                set_flash('success', "Poll '{$question}' created successfully with " . count($cleanOptions) . " options.");
            } catch (\Throwable $e) {
                $db->rollBack();
                error_log("Poll create error: " . $e->getMessage());
                set_flash('error', "Failed to create poll: " . $e->getMessage());
            }
        }
        redirect('/admin/voting.php');
    }

    // 2. EDIT POLL
    if ($action === 'edit') {
        $pollId = (int)($_POST['poll_id'] ?? 0);
        $question = trim($_POST['question'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['draft', 'published', 'paused', 'closed', 'archived', 'cancelled'], true) ? $_POST['status'] : 'draft';
        $resultsVisibility = in_array($_POST['results_visibility'] ?? '', ['always', 'after_vote', 'after_close', 'hidden'], true) ? $_POST['results_visibility'] : 'after_vote';
        $allowChange = isset($_POST['allow_change']) ? 1 : 0;
        
        $startsAt = !empty($_POST['starts_at']) ? date('Y-m-d H:i:s', strtotime($_POST['starts_at'])) : date('Y-m-d H:i:s');
        $endsAt = !empty($_POST['ends_at']) ? date('Y-m-d H:i:s', strtotime($_POST['ends_at'])) : null;

        // Process options
        $rawOptions = $_POST['options'] ?? [];
        $cleanOptions = [];
        if (is_array($rawOptions)) {
            foreach ($rawOptions as $optText) {
                $t = trim((string)$optText);
                if ($t !== '') {
                    $cleanOptions[] = $t;
                }
            }
        }

        if ($pollId <= 0 || empty($question)) {
            set_flash('error', 'Valid poll ID and question are required.');
        } elseif (count($cleanOptions) < 2) {
            set_flash('error', 'A poll requires at least 2 distinct voting options.');
        } elseif ($endsAt !== null && strtotime($endsAt) <= strtotime($startsAt)) {
            set_flash('error', 'Closing date must be scheduled after the start date.');
        } else {
            $db->beginTransaction();
            try {
                $upd = $db->prepare("
                    UPDATE polls 
                    SET question = :q, description = :d, status = :st, results_visibility = :rv, allow_change = :ac, starts_at = :starts, ends_at = :ends
                    WHERE id = :id
                ");
                $upd->execute([
                    'q' => $question,
                    'd' => $description ?: null,
                    'st' => $status,
                    'rv' => $resultsVisibility,
                    'ac' => $allowChange,
                    'starts' => $startsAt,
                    'ends' => $endsAt,
                    'id' => $pollId
                ]);

                // Check if any votes exist before modifying options
                $voteCount = (int)$db->query("SELECT COUNT(*) FROM poll_votes WHERE poll_id = {$pollId}")->fetchColumn();

                if ($voteCount === 0) {
                    // Safe to replace options entirely
                    $delOpts = $db->prepare("DELETE FROM poll_options WHERE poll_id = :pid");
                    $delOpts->execute(['pid' => $pollId]);

                    $optStmt = $db->prepare("INSERT INTO poll_options (poll_id, option_text, sort_order) VALUES (:pid, :text, :sort)");
                    foreach ($cleanOptions as $idx => $optText) {
                        $optStmt->execute([
                            'pid' => $pollId,
                            'text' => $optText,
                            'sort' => $idx + 1
                        ]);
                    }
                } else {
                    // Update existing options safely
                    $currentOpts = $db->query("SELECT id, option_text FROM poll_options WHERE poll_id = {$pollId} ORDER BY sort_order ASC, id ASC")->fetchAll();
                    $updOpt = $db->prepare("UPDATE poll_options SET option_text = :text, sort_order = :sort WHERE id = :id");
                    $insOpt = $db->prepare("INSERT INTO poll_options (poll_id, option_text, sort_order) VALUES (:pid, :text, :sort)");

                    foreach ($cleanOptions as $idx => $optText) {
                        if (isset($currentOpts[$idx])) {
                            $updOpt->execute(['text' => $optText, 'sort' => $idx + 1, 'id' => $currentOpts[$idx]['id']]);
                        } else {
                            $insOpt->execute(['pid' => $pollId, 'text' => $optText, 'sort' => $idx + 1]);
                        }
                    }
                }

                $db->commit();
                set_flash('success', "Poll #{$pollId} updated successfully.");
            } catch (\Throwable $e) {
                $db->rollBack();
                error_log("Poll edit error: " . $e->getMessage());
                set_flash('error', "Failed to update poll: " . $e->getMessage());
            }
        }
        redirect('/admin/voting.php');
    }

    // 3. CHANGE STATUS (Publish, Pause, Close, Archive)
    if ($action === 'change_status') {
        $pollId = (int)($_POST['poll_id'] ?? 0);
        $newStatus = trim($_POST['new_status'] ?? '');
        $allowed = ['draft', 'published', 'paused', 'closed', 'archived', 'cancelled'];

        if ($pollId > 0 && in_array($newStatus, $allowed, true)) {
            $stmt = $db->prepare("UPDATE polls SET status = :st WHERE id = :id");
            $stmt->execute(['st' => $newStatus, 'id' => $pollId]);
            set_flash('success', "Poll #{$pollId} status updated to '" . ucfirst($newStatus) . "'.");
        }
        redirect('/admin/voting.php');
    }

    // 4. DELETE POLL
    if ($action === 'delete') {
        $pollId = (int)($_POST['poll_id'] ?? 0);
        if ($pollId > 0) {
            $stmt = $db->prepare("DELETE FROM polls WHERE id = :id");
            $stmt->execute(['id' => $pollId]);
            set_flash('success', "Poll #{$pollId} and its associated options and votes have been deleted.");
        }
        redirect('/admin/voting.php');
    }

    // 5. TOGGLE FEATURE-LEVEL ENABLE / DISABLE
    if ($action === 'toggle_feature') {
        $enabled = ($_POST['voting_enabled'] ?? '') === '1' ? '1' : '0';
        set_setting('voting_system_enabled', $enabled);
        set_flash('success', $enabled === '1' ? 'Community Voting system enabled across dashboard.' : 'Community Voting system disabled.');
        redirect('/admin/voting.php');
    }
}

// Fetch all polls with full metrics for Admin
$allPolls = get_polls_for_user(0, true);
$totalPolls = count($allPolls);
$activePollsCount = count(array_filter($allPolls, fn($p) => $p['status'] === 'published'));
$totalVotesCount = array_sum(array_column($allPolls, 'total_votes'));
$systemEnabled = get_setting('voting_system_enabled', '1') === '1';

$pageTitle = "Community Voting Management - " . app_name() . " Admin";
require_once __DIR__ . '/../includes/header.php';
?>

<div x-data="adminVotingManager(<?= htmlspecialchars(json_encode($allPolls), ENT_QUOTES, 'UTF-8') ?>)" class="max-w-7xl mx-auto space-y-8 my-4">
    
    <!-- Top Header Card -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white p-6 md:p-8 rounded-3xl border border-slate-200/80 shadow-sm">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wider bg-blue-50 text-blue-700 border border-blue-200/70">
                    Community Feedback Engine
                </span>
                <span class="text-xs font-semibold text-slate-400">Admin Control</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight flex items-center gap-2.5">
                <span>🗳️</span>
                Community Voting &amp; Poll Management
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 mt-1 max-w-2xl leading-relaxed">
                Create surveys, manage poll schedules, configure result visibility, and engage users with an interactive voting bubble on their dashboard.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <!-- Global System Toggle -->
            <form action="/admin/voting.php" method="POST" class="inline-block">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="toggle_feature">
                <input type="hidden" name="voting_enabled" value="<?= $systemEnabled ? '0' : '1' ?>">
                <button type="submit" 
                        class="px-4 py-2.5 rounded-xl border text-xs font-bold transition-all flex items-center gap-2 cursor-pointer <?= $systemEnabled ? 'bg-emerald-50 text-emerald-800 border-emerald-200 hover:bg-emerald-100' : 'bg-slate-100 text-slate-600 border-slate-200 hover:bg-slate-200' ?>">
                    <span class="w-2 h-2 rounded-full <?= $systemEnabled ? 'bg-emerald-500 animate-pulse' : 'bg-slate-400' ?>"></span>
                    <span>System: <?= $systemEnabled ? 'Active (Enabled)' : 'Disabled' ?></span>
                </button>
            </form>

            <!-- Create Poll Button -->
            <button type="button" 
                    @click="openCreateModal()" 
                    class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs rounded-xl shadow-md shadow-blue-500/25 transition-all flex items-center gap-2 whitespace-nowrap cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                <span>Create New Poll</span>
            </button>
        </div>
    </div>

    <?= render_flash() ?>

    <!-- Metrics Bar -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        <div class="bg-white p-6 rounded-3xl border border-slate-200/80 shadow-sm flex items-center justify-between">
            <div>
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Total Polls</span>
                <span class="text-3xl font-black text-slate-900 font-mono-nums block mt-1"><?= $totalPolls ?></span>
                <span class="text-[11px] text-slate-400 block mt-0.5">Surveys created in system</span>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center text-xl font-bold shadow-xs">
                📋
            </div>
        </div>

        <div class="bg-white p-6 rounded-3xl border border-slate-200/80 shadow-sm flex items-center justify-between">
            <div>
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Active On Dashboard</span>
                <span class="text-3xl font-black text-emerald-600 font-mono-nums block mt-1"><?= $activePollsCount ?></span>
                <span class="text-[11px] text-slate-400 block mt-0.5">Currently open for voting</span>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl font-bold shadow-xs">
                ⚡
            </div>
        </div>

        <div class="bg-white p-6 rounded-3xl border border-slate-200/80 shadow-sm flex items-center justify-between">
            <div>
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Total Votes Cast</span>
                <span class="text-3xl font-black text-indigo-600 font-mono-nums block mt-1"><?= $totalVotesCount ?></span>
                <span class="text-[11px] text-slate-400 block mt-0.5">Verified user responses</span>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl font-bold shadow-xs">
                🗳️
            </div>
        </div>
    </div>

    <!-- Polls Management Table -->
    <div class="bg-white rounded-3xl border border-slate-200/80 shadow-sm overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h3 class="text-base font-extrabold text-slate-900">Community Polls Catalog</h3>
                <p class="text-xs text-slate-500 mt-0.5">Published polls will automatically trigger the floating voting bubble on authenticated user dashboards.</p>
            </div>
            <span class="text-xs font-bold text-slate-400 bg-slate-50 px-3 py-1 rounded-xl border border-slate-100">
                <?= $totalPolls ?> Total Records
            </span>
        </div>

        <?php if (empty($allPolls)): ?>
            <div class="p-12 text-center">
                <div class="w-16 h-16 rounded-3xl bg-blue-50 text-blue-600 mx-auto flex items-center justify-center text-2xl font-bold mb-3 shadow-inner">
                    🗳️
                </div>
                <h4 class="text-base font-bold text-slate-800">No Polls Created Yet</h4>
                <p class="text-xs text-slate-500 mt-1 max-w-sm mx-auto">Create your first community poll to collect user feedback on new platform services or features.</p>
                <button type="button" @click="openCreateModal()" class="mt-4 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-md shadow-blue-500/20 cursor-pointer">
                    + Create First Poll
                </button>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="bg-slate-50/80 border-b border-slate-100 text-slate-400 uppercase tracking-wider text-[10px]">
                            <th class="py-4 px-6 font-extrabold">Poll / Question</th>
                            <th class="py-4 px-4 font-extrabold">Status</th>
                            <th class="py-4 px-4 font-extrabold">Schedule</th>
                            <th class="py-4 px-4 font-extrabold">Visibility &amp; Rules</th>
                            <th class="py-4 px-4 font-extrabold">Votes</th>
                            <th class="py-4 px-6 font-extrabold text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($allPolls as $poll): 
                            $statusClasses = [
                                'published' => 'bg-emerald-50 text-emerald-700 border-emerald-200/70',
                                'draft' => 'bg-slate-100 text-slate-700 border-slate-200',
                                'paused' => 'bg-amber-50 text-amber-700 border-amber-200/70',
                                'closed' => 'bg-rose-50 text-rose-700 border-rose-200/70',
                                'archived' => 'bg-purple-50 text-purple-700 border-purple-200/70',
                                'cancelled' => 'bg-zinc-100 text-zinc-600 border-zinc-200'
                            ];
                            $badgeClass = $statusClasses[$poll['status']] ?? 'bg-slate-100 text-slate-700 border-slate-200';
                        ?>
                            <tr class="hover:bg-slate-50/60 transition-colors">
                                <!-- Question & Options count -->
                                <td class="py-4 px-6 max-w-sm">
                                    <div class="flex items-start gap-2">
                                        <span class="text-slate-400 font-bold shrink-0">#<?= (int)$poll['id'] ?></span>
                                        <div>
                                            <strong class="font-extrabold text-slate-900 block text-xs leading-snug">
                                                <?= e($poll['question']) ?>
                                            </strong>
                                            <?php if (!empty($poll['description'])): ?>
                                                <p class="text-[11px] text-slate-500 mt-0.5 line-clamp-1"><?= e($poll['description']) ?></p>
                                            <?php endif; ?>
                                            <span class="text-[10px] text-blue-600 font-bold block mt-1">
                                                <?= count($poll['options']) ?> Options Configured
                                            </span>
                                        </div>
                                    </div>
                                </td>

                                <!-- Status Badge -->
                                <td class="py-4 px-4 whitespace-nowrap">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider border <?= $badgeClass ?>">
                                        <?= e(ucfirst($poll['status'])) ?>
                                    </span>
                                </td>

                                <!-- Schedule Dates -->
                                <td class="py-4 px-4 whitespace-nowrap text-slate-600 text-[11px]">
                                    <div>Starts: <strong class="text-slate-800 font-mono-nums"><?= date('M d, Y H:i', strtotime($poll['starts_at'])) ?></strong></div>
                                    <div class="mt-0.5">
                                        Ends: <?= !empty($poll['ends_at']) ? '<strong class="text-slate-800 font-mono-nums">' . date('M d, Y H:i', strtotime($poll['ends_at'])) . '</strong>' : '<span class="text-slate-400 italic">No expiry</span>' ?>
                                    </div>
                                </td>

                                <!-- Visibility & Re-vote Rules -->
                                <td class="py-4 px-4 whitespace-nowrap text-slate-600 text-[11px]">
                                    <div>Results: <strong class="text-slate-800 font-semibold"><?= e(ucwords(str_replace('_', ' ', $poll['results_visibility']))) ?></strong></div>
                                    <div class="mt-0.5">
                                        Change Vote: <span class="font-bold <?= (bool)$poll['allow_change'] ? 'text-emerald-600' : 'text-slate-400' ?>"><?= (bool)$poll['allow_change'] ? 'Allowed' : 'Disabled' ?></span>
                                    </div>
                                </td>

                                <!-- Vote Count -->
                                <td class="py-4 px-4 whitespace-nowrap">
                                    <button type="button" 
                                            @click="viewStats(<?= (int)$poll['id'] ?>)" 
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-blue-50 text-slate-800 hover:text-blue-600 font-black rounded-xl font-mono-nums text-xs transition-colors cursor-pointer"
                                            title="View detailed vote breakdown">
                                        <span>📊</span>
                                        <span><?= (int)$poll['total_votes'] ?></span>
                                    </button>
                                </td>

                                <!-- Actions Dropdown / Quick Buttons -->
                                <td class="py-4 px-6 text-right whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1.5">
                                        <!-- Quick Status Toggle (Publish/Pause/Close) -->
                                        <?php if ($poll['status'] === 'draft' || $poll['status'] === 'paused'): ?>
                                            <form action="/admin/voting.php" method="POST" class="inline-block">
                                                <?= CSRF::field() ?>
                                                <input type="hidden" name="action" value="change_status">
                                                <input type="hidden" name="poll_id" value="<?= (int)$poll['id'] ?>">
                                                <input type="hidden" name="new_status" value="published">
                                                <button type="submit" class="px-2.5 py-1.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 font-bold rounded-lg transition-colors cursor-pointer text-[11px]">
                                                    Publish
                                                </button>
                                            </form>
                                        <?php elseif ($poll['status'] === 'published'): ?>
                                            <form action="/admin/voting.php" method="POST" class="inline-block">
                                                <?= CSRF::field() ?>
                                                <input type="hidden" name="action" value="change_status">
                                                <input type="hidden" name="poll_id" value="<?= (int)$poll['id'] ?>">
                                                <input type="hidden" name="new_status" value="paused">
                                                <button type="submit" class="px-2.5 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-700 font-bold rounded-lg transition-colors cursor-pointer text-[11px]">
                                                    Pause
                                                </button>
                                            </form>
                                            <form action="/admin/voting.php" method="POST" class="inline-block">
                                                <?= CSRF::field() ?>
                                                <input type="hidden" name="action" value="change_status">
                                                <input type="hidden" name="poll_id" value="<?= (int)$poll['id'] ?>">
                                                <input type="hidden" name="new_status" value="closed">
                                                <button type="submit" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-lg transition-colors cursor-pointer text-[11px]">
                                                    Close
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Edit Button -->
                                        <button type="button" 
                                                @click="openEditModal(<?= (int)$poll['id'] ?>)" 
                                                class="px-2.5 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold rounded-lg transition-colors cursor-pointer text-[11px]">
                                            Edit
                                        </button>

                                        <!-- Delete Button -->
                                        <button type="button" 
                                                @click="confirmDelete(<?= (int)$poll['id'] ?>, '<?= e(addslashes($poll['question'])) ?>')" 
                                                class="px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold rounded-lg transition-colors cursor-pointer text-[11px]">
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ================================================================= -->
    <!-- CREATE / EDIT POLL MODAL                                          -->
    <!-- ================================================================= -->
    <div x-show="showModal" 
         x-cloak 
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs overflow-y-auto"
         @keydown.escape.window="showModal = false">
        
        <div class="bg-white rounded-3xl p-6 sm:p-8 max-w-2xl w-full shadow-2xl border border-slate-200/80 relative my-8" 
             @click.outside="showModal = false">
            
            <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-5">
                <div>
                    <h3 class="text-lg font-black text-slate-900 tracking-tight" x-text="isEditing ? 'Edit Poll #' + formData.poll_id : 'Create Community Poll'"></h3>
                    <p class="text-xs text-slate-400 mt-0.5">Configure question, voting choices, scheduling, and results access</p>
                </div>
                <button type="button" @click="showModal = false" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center text-sm font-bold cursor-pointer">
                    ✕
                </button>
            </div>

            <form action="/admin/voting.php" method="POST" class="space-y-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" :value="isEditing ? 'edit' : 'create'">
                <input type="hidden" name="poll_id" :value="formData.poll_id">

                <!-- 1. Question -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">
                        Poll Question <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" 
                           name="question" 
                           x-model="formData.question" 
                           required 
                           placeholder="e.g. Which platform services should we prioritize next?" 
                           class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                </div>

                <!-- 2. Description (Optional) -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">
                        Optional Description / Context
                    </label>
                    <textarea name="description" 
                              x-model="formData.description" 
                              rows="2" 
                              placeholder="Add additional guidance or context for voters..." 
                              class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 transition-all"></textarea>
                </div>

                <!-- 3. Dynamic Options List -->
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-xs font-bold text-slate-700">
                            Voting Options <span class="text-rose-500">*</span> <span class="text-[11px] text-slate-400">(At least 2 required)</span>
                        </label>
                        <button type="button" 
                                @click="addOption()" 
                                class="px-3 py-1 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-600 font-extrabold text-[11px] transition-all cursor-pointer">
                            + Add Option
                        </button>
                    </div>

                    <div class="space-y-2">
                        <template x-for="(opt, index) in formData.options" :key="index">
                            <div class="flex items-center gap-2">
                                <span class="w-6 text-center text-xs font-bold text-slate-400 font-mono-nums" x-text="index + 1 + '.'"></span>
                                <input type="text" 
                                       name="options[]" 
                                       x-model="formData.options[index]" 
                                       required 
                                       :placeholder="'Option ' + (index + 1)" 
                                       class="flex-1 px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                                <button type="button" 
                                        @click="removeOption(index)" 
                                        :disabled="formData.options.length <= 2"
                                        class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-400 flex items-center justify-center text-xs font-bold disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer transition-colors"
                                        title="Remove option">
                                    ✕
                                </button>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- 4. Schedule & Dates -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Start Date / Time</label>
                        <input type="datetime-local" 
                               name="starts_at" 
                               x-model="formData.starts_at" 
                               class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono-nums">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Closing Date / Time <span class="text-slate-400 font-normal">(Optional)</span></label>
                        <input type="datetime-local" 
                               name="ends_at" 
                               x-model="formData.ends_at" 
                               class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono-nums">
                    </div>
                </div>

                <!-- 5. Settings: Results Visibility & Status -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Results Visibility</label>
                        <select name="results_visibility" 
                                x-model="formData.results_visibility" 
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold cursor-pointer">
                            <option value="after_vote">After Voting (Recommended)</option>
                            <option value="always">Always Visible</option>
                            <option value="after_close">Only After Poll Closes</option>
                            <option value="hidden">Hidden from Users (Admin Only)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Initial Status</label>
                        <select name="status" 
                                x-model="formData.status" 
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold cursor-pointer">
                            <option value="published">Published (Active on Dashboard)</option>
                            <option value="draft">Draft (Saved only)</option>
                            <option value="paused">Paused</option>
                            <option value="closed">Closed</option>
                            <template x-if="isEditing">
                                <option value="archived">Archived</option>
                            </template>
                        </select>
                    </div>
                </div>

                <!-- 6. Allow Changing Vote Checkbox -->
                <div class="p-3 rounded-xl bg-slate-50 border border-slate-200/70 flex items-center gap-3">
                    <input type="checkbox" 
                           name="allow_change" 
                           id="allow_change" 
                           x-model="formData.allow_change" 
                           class="w-4 h-4 rounded text-blue-600 focus:ring-blue-500 cursor-pointer">
                    <label for="allow_change" class="text-xs font-bold text-slate-700 cursor-pointer">
                        Allow users to change their vote before poll closure
                    </label>
                </div>

                <!-- Submit & Cancel Buttons -->
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                    <button type="button" @click="showModal = false" class="px-5 py-2.5 rounded-xl border border-slate-200 hover:bg-slate-50 text-xs font-bold text-slate-600 cursor-pointer">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold shadow-md shadow-blue-500/20 cursor-pointer">
                        <span x-text="isEditing ? 'Save Changes' : 'Create Poll'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- DETAILED STATS BREAKDOWN MODAL                                    -->
    <!-- ================================================================= -->
    <div x-show="showStatsModal" 
         x-cloak 
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs"
         @keydown.escape.window="showStatsModal = false">
        
        <div class="bg-white rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl border border-slate-200/80 relative"
             @click.outside="showStatsModal = false">
            
            <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
                <div>
                    <h3 class="text-base font-extrabold text-slate-900">Vote Breakdown</h3>
                    <p class="text-xs text-slate-400 mt-0.5" x-text="activeStatsPoll ? 'Poll #' + activeStatsPoll.id : ''"></p>
                </div>
                <button type="button" @click="showStatsModal = false" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center text-sm font-bold cursor-pointer">
                    ✕
                </button>
            </div>

            <template x-if="activeStatsPoll">
                <div class="space-y-4">
                    <h4 class="text-sm font-black text-slate-800 leading-snug" x-text="activeStatsPoll.question"></h4>

                    <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-100 flex items-center justify-between text-xs">
                        <span class="text-slate-500 font-bold">Total Votes Recorded:</span>
                        <strong class="text-base font-mono-nums text-blue-600 font-black" x-text="activeStatsPoll.total_votes"></strong>
                    </div>

                    <div class="space-y-3">
                        <template x-for="opt in activeStatsPoll.options" :key="opt.id">
                            <div class="space-y-1">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-bold text-slate-700" x-text="opt.option_text"></span>
                                    <span class="font-mono-nums font-black text-slate-900" x-text="opt.vote_count + ' (' + opt.percentage + '%)'"></span>
                                </div>
                                <div class="w-full h-3 rounded-full bg-slate-100 overflow-hidden">
                                    <div class="h-full bg-blue-600 rounded-full transition-all duration-500" :style="'width: ' + opt.percentage + '%;'"></div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="pt-3 border-t border-slate-100 flex justify-end">
                        <button type="button" @click="showStatsModal = false" class="px-5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl cursor-pointer">
                            Close
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- Hidden form for deletion -->
    <form id="deletePollForm" action="/admin/voting.php" method="POST" class="hidden">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="poll_id" id="deletePollId" value="">
    </form>
</div>

<script>
function adminVotingManager(initialPolls) {
    return {
        polls: initialPolls || [],
        showModal: false,
        showStatsModal: false,
        isEditing: false,
        activeStatsPoll: null,

        formData: {
            poll_id: 0,
            question: '',
            description: '',
            status: 'published',
            results_visibility: 'after_vote',
            allow_change: true,
            starts_at: '',
            ends_at: '',
            options: ['', '']
        },

        openCreateModal() {
            this.isEditing = false;
            const now = new Date();
            const startsStr = now.toISOString().slice(0, 16);
            
            const twoWeeks = new Date(now.getTime() + 14 * 24 * 60 * 60 * 1000);
            const endsStr = twoWeeks.toISOString().slice(0, 16);

            this.formData = {
                poll_id: 0,
                question: '',
                description: '',
                status: 'published',
                results_visibility: 'after_vote',
                allow_change: true,
                starts_at: startsStr,
                ends_at: endsStr,
                options: ['', '', '']
            };
            this.showModal = true;
        },

        openEditModal(pollId) {
            const p = this.polls.find(item => item.id == pollId);
            if (!p) return;

            this.isEditing = true;
            this.formData = {
                poll_id: p.id,
                question: p.question,
                description: p.description || '',
                status: p.status,
                results_visibility: p.results_visibility || 'after_vote',
                allow_change: !!p.allow_change,
                starts_at: p.starts_at ? p.starts_at.replace(' ', 'T').slice(0, 16) : '',
                ends_at: p.ends_at ? p.ends_at.replace(' ', 'T').slice(0, 16) : '',
                options: p.options.map(o => o.option_text)
            };
            this.showModal = true;
        },

        addOption() {
            this.formData.options.push('');
        },

        removeOption(index) {
            if (this.formData.options.length > 2) {
                this.formData.options.splice(index, 1);
            }
        },

        viewStats(pollId) {
            const p = this.polls.find(item => item.id == pollId);
            if (p) {
                this.activeStatsPoll = p;
                this.showStatsModal = true;
            }
        },

        async confirmDelete(pollId, question) {
            if (typeof Swal !== 'undefined') {
                const result = await Swal.fire({
                    title: 'Delete this Poll?',
                    text: `Are you sure you want to permanently delete Poll #${pollId} ("${question}") and all recorded votes?`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#e11d48',
                    cancelButtonColor: '#64748b',
                    confirmButtonText: 'Yes, delete poll'
                });
                if (result.isConfirmed) {
                    document.getElementById('deletePollId').value = pollId;
                    document.getElementById('deletePollForm').submit();
                }
            } else if (confirm(`Are you sure you want to delete Poll #${pollId}?`)) {
                document.getElementById('deletePollId').value = pollId;
                document.getElementById('deletePollForm').submit();
            }
        }
    };
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
