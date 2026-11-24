/**
 * Turf Teams - Admin Live Match Manager
 * Handles match lifecycle (setup -> live -> finished), tap-to-record goals,
 * own goals, undo/delete/edit controls, and WhatsApp summary exports.
 */

(function () {
    'use strict';

    // State
    const state = {
        data: null,
        clockOffsetMs: 0,
        pollIntervalId: null,
        clockIntervalId: null,
        isDebouncing: false,
        activePlayerForGoal: null
    };

    // DOM Elements
    const el = {
        container: document.getElementById('adminMatchSection'),
        modalOverlay: document.getElementById('recordGoalModalOverlay'),
        modalPlayerName: document.getElementById('goalModalPlayerName'),
        modalTeamName: document.getElementById('goalModalTeamName'),
        btnNormalGoal: document.getElementById('btnRecordNormalGoal'),
        btnOwnGoal: document.getElementById('btnRecordOwnGoal'),
        btnCancelGoal: document.getElementById('btnCancelRecordGoal'),
        editModalOverlay: document.getElementById('editGoalModalOverlay'),
        editGoalIdInput: document.getElementById('editGoalIdInput'),
        editGoalMinuteInput: document.getElementById('editGoalMinuteInput'),
        editGoalSecondInput: document.getElementById('editGoalSecondInput'),
        btnSaveEditGoal: document.getElementById('btnSaveEditGoal'),
        btnCancelEditGoal: document.getElementById('btnCancelEditGoal'),
        toastContainer: document.getElementById('toastContainer')
    };

    // Initialize
    function init() {
        if (!el.container) return;
        bindEvents();
        fetchMatchState();
        startPolling();
        startClock();
    }

    function bindEvents() {
        if (el.btnCancelGoal) {
            el.btnCancelGoal.addEventListener('click', closeRecordGoalModal);
        }
        if (el.btnNormalGoal) {
            el.btnNormalGoal.addEventListener('click', () => recordGoalAction(false));
        }
        if (el.btnOwnGoal) {
            el.btnOwnGoal.addEventListener('click', () => recordGoalAction(true));
        }
        if (el.modalOverlay) {
            el.modalOverlay.addEventListener('click', (e) => {
                if (e.target === el.modalOverlay) closeRecordGoalModal();
            });
        }

        if (el.btnCancelEditGoal) {
            el.btnCancelEditGoal.addEventListener('click', closeEditGoalModal);
        }
        if (el.btnSaveEditGoal) {
            el.btnSaveEditGoal.addEventListener('click', handleSaveGoalEdit);
        }
        if (el.editModalOverlay) {
            el.editModalOverlay.addEventListener('click', (e) => {
                if (e.target === el.editModalOverlay) closeEditGoalModal();
            });
        }
    }

    function startPolling() {
        if (state.pollIntervalId) clearInterval(state.pollIntervalId);
        state.pollIntervalId = setInterval(fetchMatchState, 3000);
    }

    function startClock() {
        if (state.clockIntervalId) clearInterval(state.clockIntervalId);
        state.clockIntervalId = setInterval(tickClock, 1000);
    }

    async function fetchMatchState() {
        try {
            const clientFetchTime = Date.now();
            const res = await fetch('../api/match_state.php', {
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();

            if (!res.ok || !data.success) {
                return;
            }

            if (data.server_time) {
                state.clockOffsetMs = (data.server_time * 1000) - clientFetchTime;
            }

            state.data = data;
            renderAdminMatch();
        } catch (err) {
            console.error('Error fetching admin match state:', err);
        }
    }

    function tickClock() {
        if (!state.data) return;
        const clockEl = document.getElementById('adminMatchClock');
        if (!clockEl) return;

        const d = state.data;
        if (d.state === 'live' && d.started_at) {
            const nowServerMs = Date.now() + state.clockOffsetMs;
            const startedAtMs = new Date(d.started_at).getTime();
            const elapsedSec = Math.max(0, Math.floor((nowServerMs - startedAtMs) / 1000));
            const mins = Math.floor(elapsedSec / 60);
            const secs = elapsedSec % 60;
            clockEl.textContent = `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
        } else if (d.state === 'finished') {
            clockEl.textContent = d.elapsed_formatted || 'FT';
        } else {
            clockEl.textContent = '00:00';
        }
    }

    function renderAdminMatch() {
        if (!el.container || !state.data) return;
        const d = state.data;

        const isSetup = (d.state === 'setup');
        const isLive = (d.state === 'live');
        const isFinished = (d.state === 'finished');

        let statusClass = 'chip-setup';
        let statusLabel = 'Match not started';

        if (isLive) {
            statusClass = 'chip-live';
            statusLabel = '<span class="pulse-live-dot"></span> LIVE';
        } else if (isFinished) {
            statusClass = 'chip-finished';
            statusLabel = 'Full time';
        }

        // Action Buttons Row
        let actionButtonsHtml = '';
        if (isSetup) {
            const canStart = d.can_start;
            const remaining = d.remaining_to_pick;
            const btnText = canStart ? '⚽ Start Match' : `Start Match (${remaining} players still to pick)`;
            actionButtonsHtml = `
                <button type="button" id="btnAdminStartMatch" class="btn btn-primary" ${canStart ? '' : 'disabled'} style="font-size:1rem;padding:0.75rem 1.5rem;">
                    ${btnText}
                </button>
            `;
        } else if (isLive) {
            actionButtonsHtml = `
                <button type="button" id="btnAdminEndMatch" class="btn btn-danger" style="font-size:1rem;padding:0.75rem 1.5rem;background:#ef4444;border-color:#ef4444;">
                    🏁 End Match (Full Time)
                </button>
            `;
        } else if (isFinished) {
            actionButtonsHtml = `
                <button type="button" id="btnAdminCopyWhatsApp" class="btn btn-whatsapp" style="padding:0.75rem 1.25rem;">
                    📋 Copy Result for WhatsApp
                </button>
                <button type="button" id="btnAdminResetMatch" class="btn btn-warning" style="padding:0.75rem 1.25rem;">
                    🔄 Start New Match (Reset Scoreboard)
                </button>
            `;
        }

        // Goal count map per player
        const playerGoalsMap = {};
        (d.goals || []).forEach(g => {
            const pid = g.player_id;
            if (!playerGoalsMap[pid]) playerGoalsMap[pid] = 0;
            playerGoalsMap[pid]++;
        });

        // Middle Section: Tap-to-Record Rosters (Side-by-Side)
        let rostersHtml = '';
        if (d.teams && d.teams.length >= 2) {
            const runda = d.teams.find(t => t.name.toLowerCase().includes('runda')) || d.teams[0];
            const mako = d.teams.find(t => t.name.toLowerCase().includes('makongeni')) || d.teams[1];

            // Look up squad members from global admin state or DOM
            const rundaMembers = getTeamMembers(runda.id);
            const makoMembers = getTeamMembers(mako.id);

            rostersHtml = `
                <div class="tap-roster-grid">
                    <!-- Runda FC Tap Roster -->
                    <div class="admin-card" style="border-top: 4px solid var(--runda-primary);">
                        <div class="admin-card-header" style="margin-bottom:0.75rem;">
                            <h4 style="margin:0;font-size:1.1rem;color:#34d399;">🟢 ${escapeHtml(runda.name)}</h4>
                            <span style="font-size:0.8rem;color:var(--text-dim);">${isLive ? 'Tap player to record goal' : 'Squad list'}</span>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:0.5rem;">
                            ${renderPlayerTapButtons(rundaMembers, runda, isLive, playerGoalsMap)}
                        </div>
                    </div>

                    <!-- Makongeni FC Tap Roster -->
                    <div class="admin-card" style="border-top: 4px solid var(--mako-primary);">
                        <div class="admin-card-header" style="margin-bottom:0.75rem;">
                            <h4 style="margin:0;font-size:1.1rem;color:#60a5fa;">🔵 ${escapeHtml(mako.name)}</h4>
                            <span style="font-size:0.8rem;color:var(--text-dim);">${isLive ? 'Tap player to record goal' : 'Squad list'}</span>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:0.5rem;">
                            ${renderPlayerTapButtons(makoMembers, mako, isLive, playerGoalsMap)}
                        </div>
                    </div>
                </div>
            `;
        }

        // Bottom Section: Chronological Goal Log
        let goalLogRowsHtml = '';
        if (d.goals && d.goals.length > 0) {
            const reversedGoals = [...d.goals].reverse();
            goalLogRowsHtml = reversedGoals.map(g => `
                <tr>
                    <td style="font-family:monospace;font-weight:700;color:#fbbf24;">${g.minute_label}</td>
                    <td>
                        <strong style="color:var(--text-primary);">${escapeHtml(g.player_name)}</strong>
                        ${g.is_own_goal ? '<span class="goal-feed-badge-og">OG</span>' : ''}
                    </td>
                    <td style="color:var(--text-secondary);">${escapeHtml(g.credited_team_name)}</td>
                    <td style="text-align:right;">
                        <button type="button" class="btn btn-outline btn-sm btn-edit-goal" data-id="${g.id}" data-minute="${g.match_minute}" data-second="${g.match_second}" data-name="${escapeHtml(g.player_name)}" title="Edit Time">
                            ✏️
                        </button>
                        <button type="button" class="btn btn-outline btn-sm btn-delete-goal" data-id="${g.id}" data-name="${escapeHtml(g.player_name)}" title="Delete Goal" style="border-color:#ef4444;color:#f87171;">
                            🗑️
                        </button>
                    </td>
                </tr>
            `).join('');
        } else {
            goalLogRowsHtml = `<tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:1.5rem;">No goals recorded yet.</td></tr>`;
        }

        el.container.innerHTML = `
            <!-- Top Scoreboard Card -->
            <div class="scoreboard-card" style="margin-bottom:1.25rem;">
                <div class="scoreboard-top-bar">
                    <div class="match-clock-container">
                        <span style="font-size:1.2rem;">⏱️</span>
                        <span class="match-clock" id="adminMatchClock">${d.elapsed_formatted || '00:00'}</span>
                    </div>
                    <div class="match-status-chip ${statusClass}">
                        ${statusLabel}
                    </div>
                </div>

                <div class="scoreboard-teams-row">
                    <div class="team-score-side">
                        <div class="team-score-crest">🟢</div>
                        <div class="team-score-name name-runda">Runda FC</div>
                    </div>

                    <div class="scoreboard-center">
                        <div class="score-display-box">
                            <span class="score-num">${d.scores.runda}</span>
                            <span class="score-divider">-</span>
                            <span class="score-num">${d.scores.makongeni}</span>
                        </div>
                    </div>

                    <div class="team-score-side">
                        <div class="team-score-crest">🔵</div>
                        <div class="team-score-name name-mako">Makongeni FC</div>
                    </div>
                </div>

                <!-- Match Lifecycle Action Toolbar -->
                <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid rgba(255,255,255,0.08);display:flex;justify-content:center;gap:0.75rem;flex-wrap:wrap;">
                    ${actionButtonsHtml}
                </div>
            </div>

            <!-- Middle: Tap-to-Record Rosters -->
            ${rostersHtml}

            <!-- Bottom: Goal Log & Undo Controls -->
            <div class="admin-card" style="margin-bottom:1.5rem;">
                <div class="admin-card-header">
                    <div>
                        <h4 style="margin:0;font-size:1.05rem;">📋 Match Goal Log (${d.goals ? d.goals.length : 0})</h4>
                        <span style="font-size:0.75rem;color:var(--text-dim);">Live goal chronology</span>
                    </div>
                    ${d.goals && d.goals.length > 0 ? `
                        <button type="button" id="btnAdminUndoGoal" class="btn btn-outline btn-sm" style="border-color:#f59e0b;color:#fbbf24;">
                            ↺ Undo Last Goal
                        </button>
                    ` : ''}
                </div>

                <div style="overflow-x:auto;">
                    <table class="goal-log-table">
                        <thead>
                            <tr>
                                <th>Min</th>
                                <th>Player</th>
                                <th>Credited To</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${goalLogRowsHtml}
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        bindDynamicButtons();
    }

    function renderPlayerTapButtons(members, team, isLive, goalCounts) {
        if (!members || members.length === 0) {
            return `<div class="roster-empty" style="padding:1rem;">No players registered to this team yet.</div>`;
        }

        return members.map(p => {
            const goals = goalCounts[p.id] || 0;
            const goalBadge = goals > 0 ? `<span class="player-goal-counter">⚽ x${goals}</span>` : '';
            return `
                <button type="button" class="tap-player-btn" data-player-id="${p.id}" data-player-name="${escapeHtml(p.name)}" data-team-id="${team.id}" data-team-name="${escapeHtml(team.name)}" ${isLive ? '' : 'disabled'}>
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <span style="color:var(--text-dim);font-size:0.75rem;">#${p.id}</span>
                        <span>${escapeHtml(p.name)}</span>
                    </div>
                    ${goalBadge}
                </button>
            `;
        }).join('');
    }

    function getTeamMembers(teamId) {
        // Look up registered players from window.turfAdminData or fetch if available
        if (window.turfAdminData && window.turfAdminData.teams) {
            const t = window.turfAdminData.teams.find(item => item.id === teamId);
            if (t && t.players) return t.players;
        }
        return [];
    }

    function bindDynamicButtons() {
        // Start Match
        const startBtn = document.getElementById('btnAdminStartMatch');
        if (startBtn && !startBtn.disabled) {
            startBtn.addEventListener('click', handleStartMatchClick);
        }

        // End Match
        const endBtn = document.getElementById('btnAdminEndMatch');
        if (endBtn) {
            endBtn.addEventListener('click', handleEndMatchClick);
        }

        // Copy WhatsApp
        const copyBtn = document.getElementById('btnAdminCopyWhatsApp');
        if (copyBtn) {
            copyBtn.addEventListener('click', handleCopyWhatsAppResult);
        }

        // Reset Match
        const resetBtn = document.getElementById('btnAdminResetMatch');
        if (resetBtn) {
            resetBtn.addEventListener('click', handleResetMatchClick);
        }

        // Tap Player to Record Goal
        const playerButtons = el.container.querySelectorAll('.tap-player-btn:not(:disabled)');
        playerButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                if (state.isDebouncing) return;
                const pId = parseInt(this.getAttribute('data-player-id'), 10);
                const pName = this.getAttribute('data-player-name');
                const tId = parseInt(this.getAttribute('data-team-id'), 10);
                const tName = this.getAttribute('data-team-name');
                openRecordGoalModal(pId, pName, tId, tName);
            });
        });

        // Undo Last Goal
        const undoBtn = document.getElementById('btnAdminUndoGoal');
        if (undoBtn) {
            undoBtn.addEventListener('click', handleUndoGoalClick);
        }

        // Delete Individual Goal
        const deleteButtons = el.container.querySelectorAll('.btn-delete-goal');
        deleteButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                const id = parseInt(this.getAttribute('data-id'), 10);
                const name = this.getAttribute('data-name');
                if (confirm(`Are you sure you want to delete this goal by ${name}?`)) {
                    deleteGoal(id);
                }
            });
        });

        // Edit Goal Minute
        const editButtons = el.container.querySelectorAll('.btn-edit-goal');
        editButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                const id = parseInt(this.getAttribute('data-id'), 10);
                const minute = parseInt(this.getAttribute('data-minute'), 10);
                const second = parseInt(this.getAttribute('data-second'), 10);
                const name = this.getAttribute('data-name');
                openEditGoalModal(id, minute, second, name);
            });
        });
    }

    // Modal: Record Goal Action Sheet
    function openRecordGoalModal(playerId, playerName, teamId, teamName) {
        state.activePlayerForGoal = { id: playerId, name: playerName, teamId: teamId, teamName: teamName };
        if (el.modalPlayerName) el.modalPlayerName.textContent = playerName;
        if (el.modalTeamName) el.modalTeamName.textContent = teamName;
        if (el.btnNormalGoal) el.btnNormalGoal.textContent = `⚽ Goal (+1 for ${teamName})`;
        
        // Find opposite team name
        let oppName = 'Opposing Team';
        if (state.data && state.data.teams) {
            const opp = state.data.teams.find(t => t.id !== teamId);
            if (opp) oppName = opp.name;
        }
        if (el.btnOwnGoal) el.btnOwnGoal.textContent = `🔴 Own Goal (OG) (+1 for ${oppName})`;

        if (el.modalOverlay) el.modalOverlay.classList.add('active');
    }

    function closeRecordGoalModal() {
        if (el.modalOverlay) el.modalOverlay.classList.remove('active');
        state.activePlayerForGoal = null;
    }

    async function recordGoalAction(isOwnGoal) {
        if (!state.activePlayerForGoal || state.isDebouncing) return;

        const p = state.activePlayerForGoal;
        closeRecordGoalModal();

        // 1-second debounce to prevent double tap
        state.isDebouncing = true;
        setTimeout(() => { state.isDebouncing = false; }, 1000);

        try {
            const res = await fetch('../api/match_state.php?action=record_goal', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    player_id: p.id,
                    is_own_goal: isOwnGoal
                })
            });

            const data = await res.json();
            if (data.success) {
                state.data = data;
                renderAdminMatch();
                notifyToast(data.message || 'Goal recorded!', 'success');
            } else {
                notifyToast(data.error || 'Failed to record goal', 'error');
            }
        } catch (err) {
            notifyToast('Network error while recording goal', 'error');
        }
    }

    // Modal: Edit Goal Time
    function openEditGoalModal(goalId, minute, second, playerName) {
        if (el.editGoalIdInput) el.editGoalIdInput.value = goalId;
        if (el.editGoalMinuteInput) el.editGoalMinuteInput.value = minute;
        if (el.editGoalSecondInput) el.editGoalSecondInput.value = second;
        const titleEl = document.getElementById('editGoalPlayerTitle');
        if (titleEl) titleEl.textContent = `Edit Goal Time for ${playerName}`;
        if (el.editModalOverlay) el.editModalOverlay.classList.add('active');
    }

    function closeEditGoalModal() {
        if (el.editModalOverlay) el.editModalOverlay.classList.remove('active');
    }

    async function handleSaveGoalEdit() {
        const goalId = parseInt(el.editGoalIdInput.value, 10);
        const minute = parseInt(el.editGoalMinuteInput.value, 10);
        const second = parseInt(el.editGoalSecondInput.value, 10);

        if (isNaN(minute) || minute < 0) {
            alert('Please enter a valid match minute (>= 0).');
            return;
        }

        closeEditGoalModal();

        try {
            const res = await fetch('../api/match_state.php?action=edit_goal', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    goal_id: goalId,
                    match_minute: minute,
                    match_second: isNaN(second) ? 0 : second
                })
            });
            const data = await res.json();
            if (data.success) {
                state.data = data;
                renderAdminMatch();
                notifyToast(data.message || 'Goal time updated', 'success');
            } else {
                notifyToast(data.error || 'Failed to edit goal', 'error');
            }
        } catch {
            notifyToast('Network error while editing goal', 'error');
        }
    }

    // Start Match
    async function handleStartMatchClick() {
        if (!confirm('Start match now?\n\nThis will start the live timer and automatically lock squad picking.')) {
            return;
        }

        try {
            const res = await fetch('../api/match_state.php?action=start_match', {
                method: 'POST',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();

            if (data.success) {
                state.data = data;
                renderAdminMatch();
                notifyToast(data.message || 'Match is now LIVE!', 'success');
            } else {
                notifyToast(data.error || 'Cannot start match', 'error');
            }
        } catch {
            notifyToast('Network error starting match', 'error');
        }
    }

    // End Match
    async function handleEndMatchClick() {
        if (!confirm('End match now?\n\nThis will stop the timer and freeze the final score.')) {
            return;
        }

        try {
            const res = await fetch('../api/match_state.php?action=end_match', {
                method: 'POST',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();

            if (data.success) {
                state.data = data;
                renderAdminMatch();
                notifyToast(data.message || 'Match finished!', 'success');
            } else {
                notifyToast(data.error || 'Failed to end match', 'error');
            }
        } catch {
            notifyToast('Network error ending match', 'error');
        }
    }

    // Undo Goal
    async function handleUndoGoalClick() {
        if (!confirm('Undo the last goal recorded?')) return;

        try {
            const res = await fetch('../api/match_state.php?action=undo_goal', {
                method: 'POST',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();

            if (data.success) {
                state.data = data;
                renderAdminMatch();
                notifyToast(data.message || 'Goal undone.', 'info');
            } else {
                notifyToast(data.error || 'Failed to undo goal', 'error');
            }
        } catch {
            notifyToast('Network error undoing goal', 'error');
        }
    }

    // Delete Goal
    async function deleteGoal(goalId) {
        try {
            const res = await fetch('../api/match_state.php?action=delete_goal', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ goal_id: goalId })
            });
            const data = await res.json();

            if (data.success) {
                state.data = data;
                renderAdminMatch();
                notifyToast(data.message || 'Goal removed.', 'info');
            } else {
                notifyToast(data.error || 'Failed to delete goal', 'error');
            }
        } catch {
            notifyToast('Network error deleting goal', 'error');
        }
    }

    // Reset Match
    async function handleResetMatchClick() {
        if (!confirm('Reset match scoreboard?\n\nThis will clear goals and return to setup state, but WILL KEEP team picks intact.')) {
            return;
        }

        try {
            const res = await fetch('../api/match_state.php?action=reset_match', {
                method: 'POST',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();

            if (data.success) {
                state.data = data;
                renderAdminMatch();
                notifyToast(data.message || 'Match reset to setup!', 'success');
            } else {
                notifyToast(data.error || 'Failed to reset match', 'error');
            }
        } catch {
            notifyToast('Network error resetting match', 'error');
        }
    }

    // Copy WhatsApp Result
    function handleCopyWhatsAppResult() {
        if (!state.data || !state.data.whatsapp_result) return;

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(state.data.whatsapp_result).then(() => {
                notifyToast('WhatsApp match summary copied to clipboard! Ready to paste.', 'success');
            }).catch(() => {
                promptCopyFallback(state.data.whatsapp_result);
            });
        } else {
            promptCopyFallback(state.data.whatsapp_result);
        }
    }

    function promptCopyFallback(text) {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        notifyToast('WhatsApp match summary copied!', 'success');
    }

    function notifyToast(msg, type = 'info') {
        const container = el.toastContainer || document.getElementById('toastContainer');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = msg;
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 300);
        }, 3000);
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Export refresh hook for admin.js so when admin dashboard polls teams, match renders too
    window.renderAdminMatchRosterSync = function (adminData) {
        window.turfAdminData = adminData;
        if (state.data) renderAdminMatch();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
