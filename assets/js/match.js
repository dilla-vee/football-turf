/**
 * Turf Teams - Live Match Scoreboard Module
 * Handles 3-second live polling, server-timestamp clock synchronization,
 * goal feed rendering, and final results display for player & spectator views.
 */

(function () {
    'use strict';

    // State
    const state = {
        data: null,
        clockOffsetMs: 0,
        pollIntervalId: null,
        clockIntervalId: null,
        lastScores: { runda: 0, makongeni: 0 },
        lastGoalCount: 0
    };

    // DOM Elements Cache
    const el = {
        container: document.getElementById('matchScoreboardContainer'),
        teamsGrid: document.getElementById('teamsGrid'),
        lockedBanner: document.getElementById('lockedBanner'),
        pickingClosedAlert: document.getElementById('pickingClosedAlert')
    };

    // Initialize
    function init() {
        if (!el.container) return;
        fetchMatchState(true);
        startPolling();
        startClock();
    }

    // Polling every 3 seconds
    function startPolling() {
        if (state.pollIntervalId) clearInterval(state.pollIntervalId);
        state.pollIntervalId = setInterval(fetchMatchState, 3000);
    }

    // 1-second clock ticker for smooth timer
    function startClock() {
        if (state.clockIntervalId) clearInterval(state.clockIntervalId);
        state.clockIntervalId = setInterval(tickClock, 1000);
    }

    // Fetch match state from server
    async function fetchMatchState(isFirstLoad = false) {
        try {
            const clientFetchTime = Date.now();
            const res = await fetch('api/match_state.php', {
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();

            if (!res.ok || !data.success) {
                console.warn('Match state polling returned error:', data.error);
                return;
            }

            // Correct clock differences between phone and server
            if (data.server_time) {
                const serverTimeMs = data.server_time * 1000;
                // Offset between server and client clock
                state.clockOffsetMs = serverTimeMs - clientFetchTime;
            }

            const prevScores = { ...state.lastScores };
            const prevGoalCount = state.lastGoalCount;

            state.data = data;
            state.lastScores = {
                runda: data.scores?.runda || 0,
                makongeni: data.scores?.makongeni || 0
            };
            state.lastGoalCount = data.goals?.length || 0;

            renderScoreboard();

            // Detect score increase for pulse flash animation
            if (!isFirstLoad) {
                const scoreChanged = (state.lastScores.runda !== prevScores.runda) || 
                                     (state.lastScores.makongeni !== prevScores.makongeni) ||
                                     (state.lastGoalCount > prevGoalCount);
                if (scoreChanged) {
                    triggerScoreFlash();
                }
            }

            // Sync pick interface visibility based on match state
            updatePickInterfaceVisibility(data.state);

        } catch (err) {
            console.error('Error fetching match state:', err);
        }
    }

    // Tick the match clock every second
    function tickClock() {
        if (!state.data) return;

        const clockEl = document.getElementById('matchClockDisplay');
        if (!clockEl) return;

        const d = state.data;
        if (d.state === 'live' && d.started_at) {
            const nowServerMs = Date.now() + state.clockOffsetMs;
            const startedAtMs = new Date(d.started_at).getTime();
            const elapsedSec = Math.max(0, Math.floor((nowServerMs - startedAtMs) / 1000));
            clockEl.textContent = formatDuration(elapsedSec);
        } else if (d.state === 'finished') {
            clockEl.textContent = d.elapsed_formatted || 'FT';
        } else {
            clockEl.textContent = '00:00';
        }
    }

    function formatDuration(totalSec) {
        const mins = Math.floor(totalSec / 60);
        const secs = totalSec % 60;
        return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }

    function triggerScoreFlash() {
        const nums = document.querySelectorAll('.score-num');
        nums.forEach(n => {
            n.classList.remove('score-flash');
            void n.offsetWidth; // force reflow
            n.classList.add('score-flash');
        });
    }

    // Toggle Pick UI when match goes live or finished
    function updatePickInterfaceVisibility(matchState) {
        const isLiveOrFinished = (matchState === 'live' || matchState === 'finished');
        
        // Hide pick buttons when match is live or finished
        const pickButtons = document.querySelectorAll('.pick-action-container');
        pickButtons.forEach(btnContainer => {
            btnContainer.style.display = isLiveOrFinished ? 'none' : 'block';
        });

        if (el.pickingClosedAlert && matchState === 'live') {
            el.pickingClosedAlert.style.display = 'flex';
            el.pickingClosedAlert.innerHTML = `<span>⚽</span><span>Match is currently LIVE! Squad selection is locked.</span>`;
        } else if (el.pickingClosedAlert && matchState === 'finished') {
            el.pickingClosedAlert.style.display = 'flex';
            el.pickingClosedAlert.innerHTML = `<span>🏁</span><span>Match has finished! Squad selection is locked.</span>`;
        }
    }

    // Render Full Scoreboard & Goal Feed
    function renderScoreboard() {
        if (!el.container || !state.data) return;
        const d = state.data;

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

        // Render Goal Feed items (reverse chronological: newest first)
        let goalFeedHtml = '';
        if (d.goals && d.goals.length > 0) {
            const reversedGoals = [...d.goals].reverse();
            goalFeedHtml = reversedGoals.map(g => {
                const isRunda = g.credited_team_name.toLowerCase().includes('runda');
                const borderClass = isRunda ? 'goal-runda' : 'goal-mako';
                return `
                    <li class="goal-feed-item ${borderClass}">
                        <div style="display:flex;align-items:center;">
                            <span class="goal-feed-minute">${g.minute_label}</span>
                            <span class="goal-feed-player">
                                ${escapeHtml(g.player_name)}
                                ${g.is_own_goal ? '<span class="goal-feed-badge-og">OG</span>' : ''}
                            </span>
                        </div>
                        <span class="goal-feed-team">${escapeHtml(g.credited_team_name)}</span>
                    </li>
                `;
            }).join('');
        } else {
            goalFeedHtml = `<li class="goal-feed-empty">${isLive ? 'No goals yet. Pitch action is underway!' : 'Match goals will appear here live.'}</li>`;
        }

        // Final Results breakdown (shown when finished)
        let finalResultsHtml = '';
        if (isFinished) {
            const rundaScore = d.scores.runda;
            const makoScore = d.scores.makongeni;

            let winnerText = '🤝 Match Drawn!';
            if (rundaScore > makoScore) {
                winnerText = '🏆 Runda FC Win!';
            } else if (makoScore > rundaScore) {
                winnerText = '🏆 Makongeni FC Win!';
            }

            // Group scorers per team
            const rundaScorers = [];
            const makoScorers = [];

            (d.goals || []).forEach(g => {
                const label = g.is_own_goal ? `${g.player_name} (OG) ${g.match_minute}'` : `${g.player_name} ${g.match_minute}'`;
                if (g.credited_team_name.toLowerCase().includes('runda')) {
                    rundaScorers.push(label);
                } else {
                    makoScorers.push(label);
                }
            });

            let topScorerHtml = '';
            if (d.top_scorers && d.top_scorers.length > 0) {
                const names = d.top_scorers.map(s => escapeHtml(s.name)).join(', ');
                const goalsCount = d.top_scorers[0].goals;
                const suffix = goalsCount > 1 ? 'goals' : 'goal';
                topScorerHtml = `
                    <div class="results-top-scorer-box">
                        <span>⭐ Top Scorer:</span>
                        <strong>${names} (${goalsCount} ${suffix})</strong>
                    </div>
                `;
            }

            finalResultsHtml = `
                <div class="final-results-card">
                    <div class="results-winner-banner">${winnerText}</div>
                    <div style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:0.75rem;">
                        Full-Time Duration: <strong>${d.elapsed_formatted}</strong>
                    </div>
                    
                    <div class="results-columns-grid">
                        <div class="results-team-col">
                            <div class="results-team-col-header" style="color:#34d399;">🟢 Runda FC (${rundaScore})</div>
                            <ul class="results-scorers-list">
                                ${rundaScorers.length > 0 ? rundaScorers.map(s => `<li>⚽ ${escapeHtml(s)}</li>`).join('') : '<li style="color:var(--text-dim);font-style:italic;">No goals</li>'}
                            </ul>
                        </div>
                        <div class="results-team-col">
                            <div class="results-team-col-header" style="color:#60a5fa;">🔵 Makongeni FC (${makoScore})</div>
                            <ul class="results-scorers-list">
                                ${makoScorers.length > 0 ? makoScorers.map(s => `<li>⚽ ${escapeHtml(s)}</li>`).join('') : '<li style="color:var(--text-dim);font-style:italic;">No goals</li>'}
                            </ul>
                        </div>
                    </div>

                    ${topScorerHtml}
                </div>
            `;
        }

        el.container.innerHTML = `
            ${finalResultsHtml}

            <div class="scoreboard-card">
                <div class="scoreboard-top-bar">
                    <div class="match-clock-container">
                        <span style="font-size:1.1rem;">⏱️</span>
                        <span class="match-clock" id="matchClockDisplay">${d.elapsed_formatted || '00:00'}</span>
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
                            <span class="score-num" id="scoreRunda">${d.scores.runda}</span>
                            <span class="score-divider">-</span>
                            <span class="score-num" id="scoreMako">${d.scores.makongeni}</span>
                        </div>
                    </div>

                    <div class="team-score-side">
                        <div class="team-score-crest">🔵</div>
                        <div class="team-score-name name-mako">Makongeni FC</div>
                    </div>
                </div>

                <div class="goal-feed-section">
                    <div class="goal-feed-title">
                        <span>⚽</span>
                        <span>Live Goal Feed (${d.goals ? d.goals.length : 0})</span>
                    </div>
                    <ul class="goal-feed-list">
                        ${goalFeedHtml}
                    </ul>
                </div>
            </div>
        `;
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

    // Auto-boot on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
