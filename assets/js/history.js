/**
 * Turf Teams - Match History & Ballon d'Or Hall of Fame Module
 * Pure Monochrome (Black & White) System.
 * Tracks past matches, all-time Ballon d'Or goal ranking, and Man of the Match awards.
 */

(function () {
    'use strict';

    // State
    const state = {
        data: null,
        isAdmin: false,
        expandedMatchIds: new Set()
    };

    // DOM Elements
    const el = {
        podiumContainer: document.getElementById('hallOfFamePodiumContainer'),
        matchesContainer: document.getElementById('archivedMatchesContainer'),
        refreshBtn: document.getElementById('btnRefreshHistory'),
        editMotmModal: document.getElementById('editMotmModalOverlay'),
        editMotmForm: document.getElementById('editMotmForm'),
        editMotmMatchId: document.getElementById('editMotmMatchId'),
        editMotmDesc: document.getElementById('editMotmMatchDesc'),
        editMotmSelect: document.getElementById('editMotmPlayerSelect'),
        editMotmCustom: document.getElementById('editMotmCustomInput'),
        btnCancelMotm: document.getElementById('btnCancelEditMotm')
    };

    function init() {
        if (!el.podiumContainer && !el.matchesContainer) return;
        state.isAdmin = window.location.pathname.includes('/admin');

        bindEvents();
        fetchHistory();
    }

    function bindEvents() {
        if (el.refreshBtn) {
            el.refreshBtn.addEventListener('click', () => {
                fetchHistory();
            });
        }

        if (el.btnCancelMotm) {
            el.btnCancelMotm.addEventListener('click', closeMotmModal);
        }

        if (el.editMotmModal) {
            el.editMotmModal.addEventListener('click', (e) => {
                if (e.target === el.editMotmModal) closeMotmModal();
            });
        }

        if (el.editMotmForm) {
            el.editMotmForm.addEventListener('submit', handleSaveMotm);
        }
    }

    async function fetchHistory() {
        const endpoint = state.isAdmin ? '../api/match_history.php' : 'api/match_history.php';
        try {
            const res = await fetch(endpoint, {
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();
            if (!res.ok || !data.success) {
                console.warn('Failed to load match history:', data.error);
                return;
            }

            state.data = data;
            renderPodiumAndLeaderboard();
            renderArchivedMatches();
        } catch (err) {
            console.error('Network error loading history:', err);
        }
    }

    function renderPodiumAndLeaderboard() {
        if (!el.podiumContainer || !state.data) return;
        const d = state.data;
        const stats = d.stats || {};
        const ballon = d.ballon_dor || [];
        const motm = d.motm_leaderboard || [];

        const trophySvg = window.TurfIcons ? TurfIcons.icon('trophy', { width: 18, height: 18 }) : '';
        const medalSvg = window.TurfIcons ? TurfIcons.icon('medal', { width: 18, height: 18 }) : '';
        const ballSvg = window.TurfIcons ? TurfIcons.icon('ball', { width: 16, height: 16 }) : '';
        const clockSvg = window.TurfIcons ? TurfIcons.icon('clock', { width: 16, height: 16 }) : '';

        const topScorerName = ballon.length > 0 ? `${ballon[0].player_name} (${ballon[0].goals}G)` : 'None';
        const topMotmName = motm.length > 0 ? `${motm[0].player_name} (${motm[0].awards}x)` : 'None';

        // Stats Banner
        let statsHtml = `
            <div class="hof-stats-grid">
                <div class="hof-stat-card">
                    <span class="hof-stat-label">${clockSvg} Total Matches</span>
                    <span class="hof-stat-value">${stats.total_matches || 0}</span>
                </div>
                <div class="hof-stat-card">
                    <span class="hof-stat-label">${ballSvg} Total Goals</span>
                    <span class="hof-stat-value">${stats.total_goals || 0}</span>
                </div>
                <div class="hof-stat-card">
                    <span class="hof-stat-label">Avg Goals / Match</span>
                    <span class="hof-stat-value">${stats.avg_goals_per_match || 0}</span>
                </div>
                <div class="hof-stat-card">
                    <span class="hof-stat-label">${trophySvg} Ballon d'Or Leader</span>
                    <span class="hof-stat-value" style="font-size:1.15rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(topScorerName)}</span>
                </div>
                <div class="hof-stat-card">
                    <span class="hof-stat-label">${medalSvg} MOTM Leader</span>
                    <span class="hof-stat-value" style="font-size:1.15rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(topMotmName)}</span>
                </div>
            </div>
        `;

        // Ballon d'Or Podium (Top 3)
        let ballonPodiumHtml = '';
        if (ballon.length > 0) {
            const top3 = ballon.slice(0, 3);
            ballonPodiumHtml = `
                <div class="hof-podium-row">
                    ${top3.map((p, idx) => {
                        const rankLabel = idx === 0 ? '1ST' : (idx === 1 ? '2ND' : '3RD');
                        return `
                            <div class="hof-podium-box rank-${idx + 1}">
                                <div class="hof-podium-rank-badge">${rankLabel}</div>
                                <div class="hof-podium-icon">${trophySvg}</div>
                                <div class="hof-podium-player">${escapeHtml(p.player_name)}</div>
                                <div class="hof-podium-score">${p.goals} <span style="font-size:0.75rem; color:var(--text-secondary); font-weight:600;">GOALS</span></div>
                                <div class="hof-podium-sub">${p.matches_scored_in} match${p.matches_scored_in === 1 ? '' : 'es'}</div>
                            </div>
                        `;
                    }).join('')}
                </div>
            `;
        }

        // Ballon d'Or Table
        let ballonTableRows = '';
        if (ballon.length > 0) {
            ballonTableRows = ballon.map(p => `
                <tr>
                    <td style="font-family:var(--font-mono); font-weight:800; color:var(--text-secondary); width:40px;">#${p.rank}</td>
                    <td style="font-weight:700; color:var(--text-primary);">${escapeHtml(p.player_name)}</td>
                    <td style="text-align:right; font-family:var(--font-mono); font-weight:800; color:var(--text-primary);">${p.goals}</td>
                    <td style="text-align:right; color:var(--text-secondary); font-size:0.85rem;">${p.matches_scored_in}</td>
                </tr>
            `).join('');
        } else {
            ballonTableRows = `
                <tr>
                    <td colspan="4" style="text-align:center; padding:1.5rem; color:var(--text-dim);">
                        No match history recorded yet. Complete a match and click "Start New Match" to archive results!
                    </td>
                </tr>
            `;
        }

        // MOTM Table
        let motmTableRows = '';
        if (motm.length > 0) {
            motmTableRows = motm.map(m => `
                <tr>
                    <td style="font-family:var(--font-mono); font-weight:800; color:var(--text-secondary); width:40px;">#${m.rank}</td>
                    <td style="font-weight:700; color:var(--text-primary);">${escapeHtml(m.player_name)}</td>
                    <td style="text-align:right; font-family:var(--font-mono); font-weight:800; color:var(--text-primary);">${m.awards}x</td>
                </tr>
            `).join('');
        } else {
            motmTableRows = `
                <tr>
                    <td colspan="3" style="text-align:center; padding:1.5rem; color:var(--text-dim);">
                        No Man of the Match awards recorded yet.
                    </td>
                </tr>
            `;
        }

        el.podiumContainer.innerHTML = `
            ${statsHtml}
            <div class="hof-grid">
                <!-- Ballon d'Or Card -->
                <div class="admin-card">
                    <div class="admin-card-header" style="margin-bottom:1rem;">
                        <div>
                            <h3 style="margin:0; font-size:1.1rem; display:flex; align-items:center; gap:0.4rem;">
                                ${trophySvg} Ballon d'Or Rankings
                            </h3>
                            <span style="font-size:0.75rem; color:var(--text-secondary);">All-time official goal tally</span>
                        </div>
                        <span class="chip-badge" style="font-size:0.75rem;">${ballon.length} Scorers</span>
                    </div>

                    ${ballonPodiumHtml}

                    <div style="overflow-x:auto; max-height:280px; overflow-y:auto; margin-top:0.75rem;">
                        <table class="hof-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Player</th>
                                    <th style="text-align:right;">Goals</th>
                                    <th style="text-align:right;">Matches</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${ballTableRows(ballonTableRows)}
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Man of the Match Card -->
                <div class="admin-card">
                    <div class="admin-card-header" style="margin-bottom:1rem;">
                        <div>
                            <h3 style="margin:0; font-size:1.1rem; display:flex; align-items:center; gap:0.4rem;">
                                ${medalSvg} Man of the Match Honours
                            </h3>
                            <span style="font-size:0.75rem; color:var(--text-secondary);">MVP titles awarded per match</span>
                        </div>
                        <span class="chip-badge" style="font-size:0.75rem;">${motm.length} Winners</span>
                    </div>

                    <div style="overflow-x:auto; max-height:360px; overflow-y:auto;">
                        <table class="hof-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Player / MVP</th>
                                    <th style="text-align:right;">Awards</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${motmTableRows}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
    }

    function ballTableRows(html) {
        return html;
    }

    function renderArchivedMatches() {
        if (!el.matchesContainer || !state.data) return;
        const matches = state.data.matches || [];

        if (matches.length === 0) {
            el.matchesContainer.innerHTML = `
                <div class="admin-card" style="text-align:center; padding:2rem 1rem;">
                    <div style="margin-bottom:0.75rem; color:var(--text-dim); display:flex; justify-content:center;">
                        ${window.TurfIcons ? TurfIcons.icon('history', { width: 36, height: 36 }) : ''}
                    </div>
                    <p style="font-weight:700; font-size:1.05rem; margin-bottom:0.35rem; color:var(--text-primary);">No Archived Matches Yet</p>
                    <p style="font-size:0.85rem; color:var(--text-secondary); max-width:440px; margin:0 auto;">
                        When a match concludes, clicking <strong>"Start New Match (Save to History)"</strong> will archive the final scores, full goal log, and award the Man of the Match here.
                    </p>
                </div>
            `;
            return;
        }

        const trophySvg = window.TurfIcons ? TurfIcons.icon('trophy', { width: 14, height: 14 }) : '';
        const medalSvg = window.TurfIcons ? TurfIcons.icon('medal', { width: 14, height: 14 }) : '';
        const ballSvg = window.TurfIcons ? TurfIcons.icon('ball', { width: 14, height: 14 }) : '';
        const clockSvg = window.TurfIcons ? TurfIcons.icon('clock', { width: 14, height: 14 }) : '';
        const editSvg = window.TurfIcons ? TurfIcons.icon('edit', { width: 13, height: 13 }) : 'Edit';
        const trashSvg = window.TurfIcons ? TurfIcons.icon('trash', { width: 13, height: 13 }) : 'Del';

        let html = `
            <div class="admin-card" style="padding:1.25rem;">
                <div class="admin-card-header" style="margin-bottom:1.25rem; flex-wrap:wrap; gap:0.5rem;">
                    <div>
                        <h3 style="margin:0; font-size:1.15rem; display:flex; align-items:center; gap:0.4rem;">
                            ${window.TurfIcons ? TurfIcons.icon('history', { width: 18, height: 18 }) : ''} Past Matches Archive (${matches.length})
                        </h3>
                        <span style="font-size:0.75rem; color:var(--text-secondary);">Historical scorecards & goal timelines</span>
                    </div>
                </div>

                <div class="archived-matches-list">
                    ${matches.map(m => {
                        const isExpanded = state.expandedMatchIds.has(m.id);
                        const teams = m.teams || [];
                        let scorelineHtml = '';

                        if (teams.length === 2) {
                            scorelineHtml = `
                                <div class="archived-scoreline">
                                    <span class="team-label">${escapeHtml(teams[0].name)}</span>
                                    <span class="score-pill">${teams[0].score ?? 0} - ${teams[1].score ?? 0}</span>
                                    <span class="team-label">${escapeHtml(teams[1].name)}</span>
                                </div>
                            `;
                        } else if (teams.length >= 3) {
                            scorelineHtml = `
                                <div class="archived-scoreline" style="display:flex; flex-wrap:wrap; gap:0.75rem; justify-content:center;">
                                    ${teams.map(t => `
                                        <span class="score-pill" style="font-size:0.9rem; padding:0.35rem 0.75rem;">
                                            ${escapeHtml(t.name)}: <strong>${t.score ?? 0}</strong>
                                        </span>
                                    `).join('')}
                                </div>
                            `;
                        }

                        // Winner label
                        let winnerBadge = '';
                        if (m.winner_team_name && m.winner_team_name !== 'Draw') {
                            winnerBadge = `<span class="chip-badge chip-winner">Winner: ${escapeHtml(m.winner_team_name)}</span>`;
                        } else {
                            winnerBadge = `<span class="chip-badge" style="background:#222; color:#fff;">Drawn</span>`;
                        }

                        // Top scorer label
                        let topScorerBadge = '';
                        if (m.top_scorer_name) {
                            topScorerBadge = `<span class="chip-badge" style="display:inline-flex; align-items:center; gap:3px;">${ballSvg} Top: ${escapeHtml(m.top_scorer_name)} (${m.top_scorer_goals}G)</span>`;
                        }

                        // MOTM label
                        let motmBadge = `<span class="chip-badge chip-motm" style="display:inline-flex; align-items:center; gap:4px;">${medalSvg} MOTM: ${escapeHtml(m.man_of_the_match || 'None')}</span>`;

                        // Goals accordion table
                        let goalsTableHtml = '';
                        if (m.goals && m.goals.length > 0) {
                            goalsTableHtml = `
                                <div class="match-goals-dropdown" style="${isExpanded ? 'display:block;' : 'display:none;'}">
                                    <table class="goal-log-table" style="margin-top:0.75rem;">
                                        <thead>
                                            <tr>
                                                <th style="width:60px;">Min</th>
                                                <th>Player</th>
                                                <th>Team</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${m.goals.map(g => `
                                                <tr>
                                                    <td style="font-family:var(--font-mono); font-weight:800;">${g.match_minute}'</td>
                                                    <td style="font-weight:700;">
                                                        ${escapeHtml(g.player_name)}
                                                        ${g.is_own_goal ? '<span class="goal-feed-badge-og">OG</span>' : ''}
                                                    </td>
                                                    <td style="color:var(--text-secondary);">${escapeHtml(g.team_name)}</td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>
                                </div>
                            `;
                        } else {
                            goalsTableHtml = `
                                <div class="match-goals-dropdown" style="${isExpanded ? 'display:block;' : 'display:none;'}">
                                    <p style="text-align:center; padding:0.75rem; color:var(--text-dim); font-size:0.85rem; margin:0;">No goals scored in this match.</p>
                                </div>
                            `;
                        }

                        return `
                            <div class="match-history-card">
                                <div class="match-card-top">
                                    <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                                        <span class="match-code-badge">${escapeHtml(m.match_code)}</span>
                                        <span style="font-size:0.85rem; color:var(--text-secondary);">${m.date_formatted}</span>
                                        <span style="font-size:0.8rem; color:var(--text-dim); font-family:var(--font-mono);">${clockSvg} ${m.duration_formatted}</span>
                                    </div>
                                    <div style="display:flex; align-items:center; gap:0.4rem; flex-wrap:wrap;">
                                        ${winnerBadge}
                                        ${topScorerBadge}
                                        ${motmBadge}
                                    </div>
                                </div>

                                <div class="match-card-center">
                                    ${scorelineHtml}
                                </div>

                                <div class="match-card-bottom">
                                    <div style="display:flex; gap:0.5rem; align-items:center;">
                                        <button type="button" class="btn btn-outline btn-sm btn-toggle-goals" data-match-id="${m.id}" style="font-size:0.75rem; padding:0.3rem 0.6rem;">
                                            ${isExpanded ? 'Hide Goals' : `View Goals (${m.total_goals})`}
                                        </button>
                                        ${state.isAdmin ? `
                                            <button type="button" class="btn btn-outline btn-sm btn-edit-motm" data-match-id="${m.id}" data-motm="${escapeHtml(m.man_of_the_match || '')}" data-code="${escapeHtml(m.match_code)}" style="font-size:0.75rem; padding:0.3rem 0.6rem; display:inline-flex; align-items:center; gap:3px;">
                                                ${editSvg} Set MOTM
                                            </button>
                                            <button type="button" class="btn btn-outline btn-sm btn-delete-match" data-match-id="${m.id}" data-code="${escapeHtml(m.match_code)}" style="font-size:0.75rem; padding:0.3rem 0.5rem; border-color:#ef4444; color:#f87171;" title="Delete match from history">
                                                ${trashSvg}
                                            </button>
                                        ` : ''}
                                    </div>
                                    <span style="font-size:0.75rem; color:var(--text-dim);">${m.team_mode === '3_teams' ? '3 Squads (6v6v6)' : '2 Squads (9v9)'}</span>
                                </div>

                                ${goalsTableHtml}
                            </div>
                        `;
                    }).join('')}
                </div>
            </div>
        `;

        el.matchesContainer.innerHTML = html;
        bindMatchCardButtons();
    }

    function bindMatchCardButtons() {
        if (!el.matchesContainer) return;

        // Toggle Goals Accordion
        const toggleButtons = el.matchesContainer.querySelectorAll('.btn-toggle-goals');
        toggleButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                const matchId = parseInt(this.getAttribute('data-match-id'), 10);
                if (state.expandedMatchIds.has(matchId)) {
                    state.expandedMatchIds.delete(matchId);
                } else {
                    state.expandedMatchIds.add(matchId);
                }
                renderArchivedMatches();
            });
        });

        // Edit MOTM Button
        const editMotmButtons = el.matchesContainer.querySelectorAll('.btn-edit-motm');
        editMotmButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                const matchId = parseInt(this.getAttribute('data-match-id'), 10);
                const currentMotm = this.getAttribute('data-motm');
                const matchCode = this.getAttribute('data-code');
                openMotmModal(matchId, currentMotm, matchCode);
            });
        });

        // Delete Match Button
        const deleteButtons = el.matchesContainer.querySelectorAll('.btn-delete-match');
        deleteButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                const matchId = parseInt(this.getAttribute('data-match-id'), 10);
                const matchCode = this.getAttribute('data-code');
                if (confirm(`Are you sure you want to delete match ${matchCode} from history?\n\nThis will remove goals scored in this match from Ballon d'Or totals.`)) {
                    deleteHistoricalMatch(matchId);
                }
            });
        });
    }

    // Modal: Edit Man of the Match
    function openMotmModal(matchId, currentMotm, matchCode) {
        if (!el.editMotmModal) return;
        el.editMotmMatchId.value = matchId;
        if (el.editMotmDesc) {
            el.editMotmDesc.textContent = `Award the official Match MVP for match ${matchCode}.`;
        }
        if (el.editMotmCustom) {
            el.editMotmCustom.value = '';
        }

        // Populate options from participants and active roster
        if (el.editMotmSelect) {
            let optionsHtml = '<option value="">-- Choose from Match Scorers / Players --</option>';

            // Find this match in state
            const match = (state.data.matches || []).find(m => m.id === matchId);
            const candidates = new Set();

            if (match && match.goals) {
                match.goals.forEach(g => {
                    if (!g.is_own_goal) candidates.add(g.player_name);
                });
            }

            // Also add all players if window.turfAdminData is available
            if (window.turfAdminData && window.turfAdminData.all_players) {
                window.turfAdminData.all_players.forEach(p => candidates.add(p.name));
            }

            candidates.forEach(name => {
                const isSelected = (name === currentMotm) ? 'selected' : '';
                optionsHtml += `<option value="${escapeHtml(name)}" ${isSelected}>${escapeHtml(name)}</option>`;
            });

            el.editMotmSelect.innerHTML = optionsHtml;
        }

        el.editMotmModal.classList.add('active');
    }

    function closeMotmModal() {
        if (el.editMotmModal) el.editMotmModal.classList.remove('active');
    }

    async function handleSaveMotm(e) {
        e.preventDefault();
        const matchId = parseInt(el.editMotmMatchId.value, 10);
        const selectedPlayer = el.editMotmSelect.value.trim();
        const customPlayer = el.editMotmCustom ? el.editMotmCustom.value.trim() : '';
        const playerName = customPlayer || selectedPlayer;

        if (matchId <= 0 || !playerName) {
            alert('Please select or type a player name for Man of the Match.');
            return;
        }

        closeMotmModal();

        try {
            const endpoint = state.isAdmin ? '../api/match_history.php?action=set_motm' : 'api/match_history.php?action=set_motm';
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ match_id: matchId, player_name: playerName })
            });
            const data = await res.json();
            if (data.success) {
                if (typeof showToast === 'function') {
                    showToast(data.message || `MOTM awarded to ${playerName}!`, 'success');
                } else {
                    alert(data.message || `MOTM awarded to ${playerName}!`);
                }
                fetchHistory();
            } else {
                alert(data.error || 'Failed to update Man of the Match');
            }
        } catch (err) {
            console.error('Network error saving MOTM:', err);
            alert('Network error saving MOTM');
        }
    }

    async function deleteHistoricalMatch(matchId) {
        try {
            const endpoint = state.isAdmin ? '../api/match_history.php?action=delete_match' : 'api/match_history.php?action=delete_match';
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ match_id: matchId })
            });
            const data = await res.json();
            if (data.success) {
                if (typeof showToast === 'function') {
                    showToast(data.message || 'Match removed from history', 'success');
                }
                fetchHistory();
            } else {
                alert(data.error || 'Failed to delete match');
            }
        } catch (err) {
            console.error('Network error deleting match:', err);
        }
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

    // Expose globally for cross-module coordination
    window.TurfHistory = {
        fetchHistory: fetchHistory
    };

    // Auto-init on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
