<?php
/**
 * Dedicated Community Voting Page
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
$userId = (int)$user['id'];
$db = Database::getConnection();

// Self-healing database check
ensure_voting_tables_exist($db);

// Fetch all polls accessible to this user
$polls = get_polls_for_user($userId, Auth::isAdmin());

$pageTitle = "Community Voting - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Ambient Soft Background Accents matching User Dashboard visual style -->
<div class="fixed inset-0 pointer-events-none -z-10 bg-gradient-to-br from-blue-50/50 via-indigo-50/30 to-purple-50/20">
    <div class="absolute -top-32 -left-32 w-96 h-96 rounded-full bg-blue-200/20 blur-3xl"></div>
    <div class="absolute top-1/3 -right-32 w-96 h-96 rounded-full bg-indigo-200/20 blur-3xl"></div>
    <div class="absolute -bottom-32 left-1/3 w-96 h-96 rounded-full bg-purple-200/15 blur-3xl"></div>
</div>

<div class="max-w-3xl mx-auto my-4 sm:my-8 px-4 pb-28 space-y-6" x-data="communityVotingApp(<?= htmlspecialchars(json_encode($polls), ENT_QUOTES, 'UTF-8') ?>, '<?= CSRF::getToken() ?>')">
    
    <!-- Top Navigation Bar & Balance Review -->
    <div class="flex items-center justify-between">
        <a href="/user/dashboard.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Back to Dashboard</span>
        </a>
        <div class="text-xs font-bold text-slate-500">
            Active Community Polls: <strong class="text-blue-600 font-mono-nums" x-text="activePollsCount">0</strong>
        </div>
    </div>

    <?= render_flash() ?>

    <!-- Main Header Card -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl relative overflow-hidden">
        <!-- Subtle Top Glow Gradient -->
        <div class="absolute -top-16 -right-16 w-52 h-52 bg-gradient-to-br from-blue-500/10 to-indigo-500/15 rounded-full blur-2xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="max-w-xl">
                <div class="flex items-center gap-2 mb-2">
                    <span class="w-7 h-7 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-sm shadow-xs border border-blue-100/60 font-bold">
                        🗳️
                    </span>
                    <span class="text-xs font-extrabold uppercase tracking-wider text-blue-600">Community Voice</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight leading-tight">
                    Community Voting
                </h1>
                <p class="text-xs sm:text-sm text-slate-600 mt-2 leading-relaxed">
                    Have your say in shaping future services, panel improvements, and platform features. Every vote directly influences our roadmap.
                </p>
            </div>

            <!-- Summary Stats Pill Box -->
            <div class="shrink-0 flex sm:flex-col items-center sm:items-end justify-between sm:justify-center p-3 sm:p-4 rounded-2xl bg-slate-50 border border-slate-200/70 text-right">
                <span class="text-[10px] font-extrabold uppercase tracking-widest text-slate-400">Total Votes Cast</span>
                <span class="text-xl sm:text-2xl font-black text-slate-900 font-mono-nums mt-0.5" x-text="totalCommunityVotes">0</span>
            </div>
        </div>

        <!-- Filter Segmented Tabs -->
        <div class="mt-6 pt-6 border-t border-slate-100 flex items-center gap-2 overflow-x-auto scrollbar-none">
            <button type="button" 
                    @click="filter = 'all'" 
                    class="px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap"
                    :class="filter === 'all' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/25' : 'bg-slate-100 text-slate-600 hover:bg-slate-200/80'">
                All Polls (<span x-text="polls.length">0</span>)
            </button>
            <button type="button" 
                    @click="filter = 'active'" 
                    class="px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap"
                    :class="filter === 'active' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/25' : 'bg-slate-100 text-slate-600 hover:bg-slate-200/80'">
                Open for Voting (<span x-text="activePollsCount">0</span>)
            </button>
            <button type="button" 
                    @click="filter = 'voted'" 
                    class="px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap"
                    :class="filter === 'voted' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/25' : 'bg-slate-100 text-slate-600 hover:bg-slate-200/80'">
                Voted by You (<span x-text="votedPollsCount">0</span>)
            </button>
            <button type="button" 
                    @click="filter = 'closed'" 
                    class="px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap"
                    :class="filter === 'closed' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/25' : 'bg-slate-100 text-slate-600 hover:bg-slate-200/80'">
                Concluded (<span x-text="closedPollsCount">0</span>)
            </button>
        </div>
    </div>

    <!-- Empty State when no polls exist or match filter -->
    <template x-if="filteredPolls.length === 0">
        <div class="bg-white rounded-3xl p-10 border border-slate-200/80 shadow-md text-center space-y-4 max-w-md mx-auto my-8">
            <div class="w-16 h-16 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center mx-auto text-2xl shadow-inner">
                🗳️
            </div>
            <h3 class="text-lg font-extrabold text-slate-900">No Polls Available Right Now</h3>
            <p class="text-xs text-slate-500 leading-relaxed">
                <span x-show="filter === 'all'">There are currently no active public polls. Check back soon for new community votes and platform surveys!</span>
                <span x-show="filter !== 'all'">No polls match your selected tab filter. Switch to "All Polls" to see all available surveys.</span>
            </p>
            <div class="pt-2">
                <button type="button" @click="filter = 'all'" class="px-5 py-2.5 bg-blue-600 text-white text-xs font-bold rounded-xl shadow-md hover:bg-blue-700 transition-all cursor-pointer">
                    View All Polls
                </button>
            </div>
        </div>
    </template>

    <!-- Polls Feed List -->
    <div class="space-y-6">
        <template x-for="poll in filteredPolls" :key="poll.id">
            <div class="bg-white rounded-3xl p-6 sm:p-7 border border-slate-200/80 shadow-lg hover:shadow-xl transition-all relative overflow-hidden"
                 :id="'poll-' + poll.id">
                
                <!-- Poll Card Header / Meta Line -->
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <div class="flex items-center gap-2">
                        <!-- Clean Text Metadata -->
                        <span class="text-[11px] font-bold text-slate-400">Poll #<span x-text="poll.id"></span></span>
                        <span class="text-slate-300">·</span>
                        
                        <!-- Status indicator -->
                        <span class="inline-flex items-center gap-1.5 text-xs font-bold"
                              :class="poll.is_open ? 'text-emerald-700' : 'text-slate-500'">
                            <span class="w-2 h-2 rounded-full" :class="poll.is_open ? 'bg-emerald-500 animate-pulse' : 'bg-slate-400'"></span>
                            <span x-text="poll.is_open ? 'Open for Voting' : 'Voting Concluded'"></span>
                        </span>
                    </div>

                    <!-- Closing Timer Indicator -->
                    <template x-if="poll.is_open && poll.ends_at">
                        <span class="text-[11px] font-semibold"
                              :class="poll.is_closing_soon ? 'text-rose-600 font-bold' : 'text-slate-500'">
                            ⏳ Closes in <strong class="font-mono-nums" x-text="formatTimeLeft(poll)"></strong>
                        </span>
                    </template>
                </div>

                <!-- Poll Question -->
                <h3 class="text-base sm:text-lg font-extrabold text-slate-900 tracking-tight leading-snug">
                    <span x-text="poll.question"></span>
                </h3>

                <!-- Optional Poll Description -->
                <template x-if="poll.description && poll.description.trim() !== ''">
                    <p class="text-xs text-slate-500 mt-1.5 leading-relaxed whitespace-pre-line" x-text="poll.description"></p>
                </template>

                <!-- Options List / Results Breakdown -->
                <div class="mt-5 space-y-3">
                    
                    <!-- CASE A: RESULTS MODE (User already voted & results allowed, OR poll is closed & results allowed) -->
                    <template x-if="poll.show_results && !poll.is_editing">
                        <div class="space-y-2.5">
                            <template x-for="opt in poll.options" :key="opt.id">
                                <div class="relative overflow-hidden rounded-2xl border p-3.5 transition-all"
                                     :class="poll.user_voted_option_id === opt.id ? 'border-blue-400 bg-blue-50/40 ring-2 ring-blue-500/20' : 'border-slate-200/80 bg-slate-50/60'">
                                    
                                    <!-- Progress fill background bar -->
                                    <div class="absolute inset-y-0 left-0 transition-all duration-700 ease-out rounded-2xl"
                                         :style="'width: ' + opt.percentage + '%;'"
                                         :class="poll.user_voted_option_id === opt.id ? 'bg-blue-100/70' : 'bg-slate-200/60'">
                                    </div>

                                    <!-- Option content overlay -->
                                    <div class="relative z-10 flex items-center justify-between gap-3 text-xs">
                                        <div class="flex items-center gap-2">
                                            <template x-if="poll.user_voted_option_id === opt.id">
                                                <span class="w-5 h-5 rounded-full bg-blue-600 text-white flex items-center justify-center text-[10px] font-black shrink-0 shadow-xs">
                                                    ✓
                                                </span>
                                            </template>
                                            <span class="font-bold text-slate-800" x-text="opt.option_text"></span>
                                        </div>

                                        <div class="shrink-0 flex items-center gap-2 font-mono-nums">
                                            <span class="text-xs font-black text-slate-900" x-text="opt.percentage + '%'"></span>
                                            <span class="text-[10px] text-slate-400 font-semibold" x-text="'(' + opt.vote_count + ')'"></span>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    <!-- CASE B: VOTING SELECTION MODE (User has not yet voted, OR is changing vote) -->
                    <template x-if="!poll.show_results || poll.is_editing">
                        <div class="space-y-2">
                            <template x-for="opt in poll.options" :key="opt.id">
                                <label class="flex items-center justify-between p-3.5 rounded-2xl border transition-all cursor-pointer select-none group"
                                       :class="poll.selected_option_id === opt.id ? 'border-blue-500 bg-blue-50/50 ring-2 ring-blue-500/20 shadow-xs' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'">
                                    
                                    <div class="flex items-center gap-3">
                                        <!-- Custom radio indicator -->
                                        <div class="w-5 h-5 rounded-full border-2 flex items-center justify-center transition-all shrink-0"
                                             :class="poll.selected_option_id === opt.id ? 'border-blue-600 bg-blue-600' : 'border-slate-300 group-hover:border-slate-400'">
                                            <div class="w-2 h-2 rounded-full bg-white transition-transform"
                                                 :class="poll.selected_option_id === opt.id ? 'scale-100' : 'scale-0'"></div>
                                        </div>
                                        <span class="text-xs font-bold text-slate-800" x-text="opt.option_text"></span>
                                    </div>

                                    <input type="radio" 
                                           :name="'poll_opt_' + poll.id" 
                                           :value="opt.id" 
                                           @change="poll.selected_option_id = opt.id"
                                           :checked="poll.selected_option_id === opt.id"
                                           class="sr-only">
                                </label>
                            </template>
                        </div>
                    </template>
                </div>

                <!-- Footer Action Area -->
                <div class="mt-5 pt-4 border-t border-slate-100 flex flex-wrap items-center justify-between gap-3">
                    
                    <!-- Left Context Status -->
                    <div class="text-[11px] text-slate-500 flex items-center gap-2">
                        <span class="font-bold font-mono-nums"><span x-text="poll.total_votes">0</span> total votes</span>
                        <template x-if="poll.user_voted">
                            <span class="text-blue-600 font-extrabold flex items-center gap-1">
                                <span>·</span> You voted in this poll
                            </span>
                        </template>
                    </div>

                    <!-- Right Buttons -->
                    <div class="flex items-center gap-2">
                        <!-- If user already voted and allow_change is true, offer edit toggle -->
                        <template x-if="poll.is_open && poll.user_voted && poll.allow_change && !poll.is_editing">
                            <button type="button" 
                                    @click="startEdit(poll)"
                                    class="px-4 py-2 rounded-xl text-xs font-bold text-blue-600 hover:bg-blue-50 border border-blue-200 transition-all cursor-pointer">
                                Change Vote
                            </button>
                        </template>

                        <!-- Cancel edit button -->
                        <template x-if="poll.is_editing">
                            <button type="button" 
                                    @click="cancelEdit(poll)"
                                    class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 border border-slate-200 transition-all cursor-pointer">
                                Cancel
                            </button>
                        </template>

                        <!-- Submit Vote Button -->
                        <template x-if="poll.is_open && (!poll.user_voted || poll.is_editing)">
                            <button type="button" 
                                    @click="submitVote(poll)"
                                    :disabled="!poll.selected_option_id || poll.isSubmitting"
                                    class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-extrabold text-xs shadow-md shadow-blue-500/25 active:scale-95 transition-all cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
                                <span x-show="!poll.isSubmitting" x-text="poll.user_voted ? 'Update Vote' : 'Submit Vote'"></span>
                                <span x-show="poll.isSubmitting" class="animate-spin inline-block">⏳</span>
                                <span x-show="poll.isSubmitting">Submitting...</span>
                            </button>
                        </template>
                    </div>
                </div>

            </div>
        </template>
    </div>
</div>

<script>
function communityVotingApp(initialPolls, csrfToken) {
    // Enrich each poll with reactive UI properties
    const processedPolls = (initialPolls || []).map(p => {
        return {
            ...p,
            selected_option_id: p.user_voted_option_id || null,
            is_editing: false,
            isSubmitting: false
        };
    });

    return {
        polls: processedPolls,
        filter: 'all',
        csrfToken: csrfToken,

        get activePollsCount() {
            return this.polls.filter(p => p.is_open).length;
        },

        get votedPollsCount() {
            return this.polls.filter(p => p.user_voted).length;
        },

        get closedPollsCount() {
            return this.polls.filter(p => !p.is_open).length;
        },

        get totalCommunityVotes() {
            return this.polls.reduce((acc, p) => acc + (p.total_votes || 0), 0);
        },

        get filteredPolls() {
            if (this.filter === 'active') {
                return this.polls.filter(p => p.is_open);
            }
            if (this.filter === 'voted') {
                return this.polls.filter(p => p.user_voted);
            }
            if (this.filter === 'closed') {
                return this.polls.filter(p => !p.is_open);
            }
            return this.polls;
        },

        formatTimeLeft(poll) {
            if (!poll.remaining_seconds) return '';
            const days = poll.days_left || 0;
            const hours = poll.hours_left || 0;
            const mins = poll.minutes_left || 0;
            if (days > 0) return `${days}d ${hours}h`;
            if (hours > 0) return `${hours}h ${mins}m`;
            return `${mins}m`;
        },

        startEdit(poll) {
            poll.is_editing = true;
            poll.selected_option_id = poll.user_voted_option_id;
        },

        cancelEdit(poll) {
            poll.is_editing = false;
            poll.selected_option_id = poll.user_voted_option_id;
        },

        async submitVote(poll) {
            if (!poll.selected_option_id || poll.isSubmitting) return;

            poll.isSubmitting = true;

            try {
                // Robust URL construction supporting root, subdirectories, domains, and port proxies
                let apiUrl = '/api/voting.php';
                try {
                    apiUrl = new URL('../api/voting.php', window.location.href).href;
                } catch (e) {
                    apiUrl = '/api/voting.php';
                }

                const formData = new FormData();
                formData.append('action', 'vote');
                formData.append('poll_id', poll.id);
                formData.append('option_id', poll.selected_option_id);
                formData.append('csrf_token', this.csrfToken);

                const response = await fetch(apiUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrfToken
                    }
                });

                let data = null;
                const text = await response.text();
                try {
                    data = JSON.parse(text);
                } catch (parseErr) {
                    console.error('Server non-JSON response format:', text);
                    throw new Error('Server returned an unexpected response format (HTTP ' + response.status + ').');
                }

                if (response.ok && data && data.success && data.poll) {
                    // Update poll state in-place without reloading whole page
                    poll.total_votes = data.poll.total_votes;
                    poll.options = data.poll.options;
                    poll.user_voted = true;
                    poll.user_voted_option_id = data.poll.user_voted_option_id;
                    poll.show_results = data.poll.show_results;
                    poll.is_editing = false;

                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Vote Recorded!',
                            text: data.message || 'Thank you for making your voice heard.',
                            timer: 2200,
                            showConfirmButton: false
                        });
                    }
                } else {
                    const err = (data && (data.message || data.error)) || ('Vote submission failed (HTTP ' + response.status + ').');
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Vote Submission Failed',
                            text: err
                        });
                    } else {
                        alert(err);
                    }
                }
            } catch (err) {
                console.error('Voting submission error:', err);
                const isRealNetworkFailure = !navigator.onLine || 
                    (err instanceof TypeError && /failed to fetch|network|load failed/i.test(err.message));

                const errorTitle = isRealNetworkFailure ? 'Network Error' : 'Vote Submission Failed';
                const errorMsg = isRealNetworkFailure 
                    ? 'An error occurred while connecting to the server. Please check your connection and try again.'
                    : (err.message || 'An unexpected error occurred while processing your vote. Please try again.');

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: errorTitle,
                        text: errorMsg
                    });
                } else {
                    alert(errorMsg);
                }
            } finally {
                poll.isSubmitting = false;
            }
        }
    };
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
