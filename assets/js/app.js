/**
 * Turf Teams - Player Frontend Application
 * Handles mobile-first team selection, modal confirmations, 5-second live polling, and toast messages.
 */

(function () {
    'use strict';

    // State
    const state = {
        token: '',
        player: null,
        teams: [],
        pickingLocked: false,
        autoAssignedTeamId: null,
        pendingTeamPick: null,
        pollIntervalId: null,
        isSubmitting: false
    };

    // DOM Elements
    const elements = {
        loadingView: document.getElementById('loadingView'),
        errorView: document.getElementById('errorView'),
        appView: document.getElementById('appView'),
        playerNameDisplay: document.getElementById('playerNameDisplay'),
        lockedBanner: document.getElementById('lockedBanner'),
        lockedTeamName: document.getElementById('lockedTeamName'),
        lockedSubtext: document.getElementById('lockedSubtext'),
        pickingClosedAlert: document.getElementById('pickingClosedAlert'),
        teamsGrid: document.getElementById('teamsGrid'),
        modalOverlay: document.getElementById('modalOverlay'),
        modalTitle: document.getElementById('modalTitle'),
        modalBody: document.getElementById('modalBody'),
        modalBtnConfirm: document.getElementById('modalBtnConfirm'),
        modalBtnCancel: document.getElementById('modalBtnCancel'),
        toastContainer: document.getElementById('toastContainer'),
        pollIndicator: document.getElementById('pollIndicator')
    };

    // Initialize
    function init() {
        const urlParams = new URLSearchParams(window.location.search);
        state.token = urlParams.get('t') || '';

        if (!state.token) {
            showError('Missing Player Link', 'No player token was found in the link. Please open your personal link sent to your WhatsApp.');
            return;
        }

        bindEvents();
        fetchStatus(true);
        startPolling();
    }

    // Event Bindings
    function bindEvents() {
        if (elements.modalBtnCancel) {
            elements.modalBtnCancel.addEventListener('click', closeModal);
        }

        if (elements.modalOverlay) {
            elements.modalOverlay.addEventListener('click', function (e) {
                if (e.target === elements.modalOverlay) {
                    closeModal();
                }
            });
        }

        if (elements.modalBtnConfirm) {
            elements.modalBtnConfirm.addEventListener('click', confirmPick);
        }

        // Accessibility: Escape key closes modal
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && elements.modalOverlay && elements.modalOverlay.classList.contains('active')) {
                closeModal();
            }
        });
    }

    // Start 5-second polling
    function startPolling() {
        if (state.pollIntervalId) {
            clearInterval(state.pollIntervalId);
        }
        state.pollIntervalId = setInterval(function () {
            fetchStatus(false);
        }, 5000);
    }

    // Fetch live status from backend
    async function fetchStatus(isInitialLoad = false) {
        try {
            const response = await fetch(`api.php?action=status&t=${encodeURIComponent(state.token)}`, {
                headers: { 'Accept': 'application/json' }
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                if (isInitialLoad) {
                    showError(
                        data.error || 'Invalid Link',
                        'This match link is not recognized or has expired. Please contact the match organizer to get a valid link.'
                    );
                }
                return;
            }

            state.player = data.player;
            state.teams = data.teams;
            state.pickingLocked = data.picking_locked;
            state.autoAssignedTeamId = data.auto_assigned_team_id;

            renderApp();

            if (isInitialLoad) {
                if (elements.loadingView) elements.loadingView.style.display = 'none';
                if (elements.errorView) elements.errorView.style.display = 'none';
                if (elements.appView) elements.appView.style.display = 'block';
            }
        } catch (err) {
            console.error('Fetch error during polling:', err);
            if (isInitialLoad) {
                showError('Network Error', 'Unable to reach the server. Please check your internet connection.');
            }
        }
    }

    // Render App State
    function renderApp() {
        if (!state.player) return;

        // Player Name
        if (elements.playerNameDisplay) {
            elements.playerNameDisplay.textContent = state.player.name;
        }

        // Picking Closed Alert
        if (elements.pickingClosedAlert) {
            elements.pickingClosedAlert.style.display = state.pickingLocked ? 'flex' : 'none';
        }

        // Locked Banner (If player already picked)
        if (elements.lockedBanner) {
            if (state.player.has_picked && state.player.picked_team_name) {
                elements.lockedBanner.style.display = 'flex';
                elements.lockedTeamName.textContent = state.player.picked_team_name;
                elements.lockedSubtext.textContent = 'Your team selection is locked in. See you on the pitch!';
            } else {
                elements.lockedBanner.style.display = 'none';
            }
        }

        // Render Teams Grid
        renderTeams();
    }

    // Render the two team cards
    function renderTeams() {
        if (!elements.teamsGrid || !state.teams) return;

        const html = state.teams.map(team => {
            const isRunda = team.name.toLowerCase().includes('runda');
            const teamClass = isRunda ? 'team-runda' : 'team-makongeni';
            const crestIcon = window.TurfIcons 
                ? (isRunda ? TurfIcons.rundaBadge(40) : TurfIcons.makoBadge(40))
                : `<span class="club-pill-sm ${isRunda ? 'runda' : 'mako'}">${isRunda ? 'RFC' : 'MFC'}</span>`;
            const isViewerTeam = (state.player.picked_team_id === team.id);
            const isOnlyOption = (!state.player.has_picked && state.autoAssignedTeamId === team.id && team.is_selectable);

            let cardClasses = `team-card ${teamClass}`;
            if (team.is_full_for_viewer) cardClasses += ' is-full';
            if (team.is_selectable) cardClasses += ' is-selectable';
            if (isOnlyOption) cardClasses += ' is-only-choice';

            // Spots indicator text (Do NOT show tier badges/names; show viewer spots)
            let spotsHtml = '';
            if (team.blocked_by_rival) {
                spotsHtml = `<span class="spots-count spots-none">Blocked (${escapeHtml(team.rival_name || 'Rival')} on this team)</span>`;
            } else if (team.spots_left <= 0) {
                spotsHtml = `<span class="spots-count spots-none">Full for your tier</span>`;
            } else if (team.spots_left === 1) {
                spotsHtml = `<span class="spots-count spots-last">1 spot remaining for you</span>`;
            } else {
                spotsHtml = `<span class="spots-count spots-available">${team.spots_left} spots left for you</span>`;
            }

            // Pick button logic
            let buttonHtml = '';
            if (state.player.has_picked) {
                if (isViewerTeam) {
                    const checkIcon = window.TurfIcons ? TurfIcons.icon('check', { width: 16, height: 16 }) : '✓';
                    buttonHtml = `<button class="pick-btn" disabled style="background:#059669;color:#fff;display:inline-flex;align-items:center;justify-content:center;gap:0.4rem;">${checkIcon} You are on this team</button>`;
                } else {
                    buttonHtml = `<button class="pick-btn" disabled>Locked</button>`;
                }
            } else if (state.pickingLocked) {
                buttonHtml = `<button class="pick-btn" disabled>Picking Locked by Admin</button>`;
            } else if (team.blocked_by_rival) {
                buttonHtml = `<button class="pick-btn" disabled style="background:#451a1a;color:#fca5a5;border:1px solid #7f1d1d;cursor:not-allowed;">Blocked: Opponent ${escapeHtml(team.rival_name || '')} is here</button>`;
            } else if (team.is_full_for_viewer) {
                buttonHtml = `<button class="pick-btn" disabled>Team Full for You</button>`;
            } else {
                buttonHtml = `
                    <button class="pick-btn" data-team-id="${team.id}" data-team-name="${escapeHtml(team.name)}">
                        Pick ${escapeHtml(team.name)}
                    </button>
                `;
            }

            // Only choice badge text
            let onlyOptionPill = '';
            if (isOnlyOption) {
                if (state.player && state.player.rival_name) {
                    onlyOptionPill = `<div class="only-choice-pill">Only Choice (${escapeHtml(state.player.rival_name)} on rival team)</div>`;
                } else {
                    onlyOptionPill = `<div class="only-choice-pill">Only Choice Left</div>`;
                }
            }

            // Roster list (Names only - strictly no tier information revealed)
            let rosterItemsHtml = '';
            if (team.roster && team.roster.length > 0) {
                rosterItemsHtml = team.roster.map((member, index) => {
                    const isCurrentPlayer = (member.name === state.player.name);
                    return `
                        <li class="roster-item ${isCurrentPlayer ? 'roster-item-user' : ''}">
                            <span class="roster-item-name">
                                <span style="color:var(--text-dim);font-size:0.75rem;">#${index + 1}</span>
                                <span>${escapeHtml(member.name)} ${isCurrentPlayer ? ' (You)' : ''}</span>
                            </span>
                        </li>
                    `;
                }).join('');
            } else {
                rosterItemsHtml = `<li class="roster-empty">No players yet. Be the first to join!</li>`;
            }

            return `
                <div class="${cardClasses}" id="teamCard-${team.id}">
                    ${onlyOptionPill}
                    
                    <div class="team-header">
                        <div class="team-crest">${crestIcon}</div>
                        <div class="team-title-group">
                            <h2 class="team-name">${escapeHtml(team.name)}</h2>
                            <div class="team-total-count">${team.total_players} of ${team.max_team_size} players registered</div>
                        </div>
                    </div>

                    <div class="spots-box">
                        <span class="spots-label">Availability</span>
                        ${spotsHtml}
                    </div>

                    <div class="pick-action-container">
                        ${buttonHtml}
                    </div>

                    <div class="roster-section">
                        <div class="roster-header">
                            <span>Squad List (${team.roster.length})</span>
                            <span style="font-size:0.7rem;color:var(--text-dim);">Live Roster</span>
                        </div>
                        <ul class="roster-list">
                            ${rosterItemsHtml}
                        </ul>
                    </div>
                </div>
            `;
        }).join('');

        elements.teamsGrid.innerHTML = html;

        // Attach click listeners to pick buttons
        const pickButtons = elements.teamsGrid.querySelectorAll('.pick-btn:not(:disabled)');
        pickButtons.forEach(btn => {
            btn.addEventListener('click', function () {
                const teamId = parseInt(this.getAttribute('data-team-id'), 10);
                const teamName = this.getAttribute('data-team-name');
                openConfirmModal(teamId, teamName);
            });
        });
    }

    // Modal Confirmation Handling
    function openConfirmModal(teamId, teamName) {
        state.pendingTeamPick = { id: teamId, name: teamName };

        let rivalNotice = '';
        if (state.player && state.player.rival_name) {
            rivalNotice = `<br><span style="display:inline-flex;align-items:center;gap:0.35rem;color:#34d399;font-weight:600;font-size:0.875rem;">${window.TurfIcons ? TurfIcons.icon('users', { width:15, height:15 }) : ''} Facing opponent <strong>${escapeHtml(state.player.rival_name)}</strong> on the opposite team!</span><br>`;
        }

        elements.modalTitle.textContent = `Pick ${teamName}?`;
        elements.modalBody.innerHTML = `
            Are you sure you want to join <strong>${escapeHtml(teamName)}</strong>?<br>${rivalNotice}<br>
            <span style="display:inline-flex;align-items:center;gap:0.35rem;color:#fbbf24;font-size:0.85rem;">${window.TurfIcons ? TurfIcons.icon('alert', { width:15, height:15 }) : ''} This choice is permanent and cannot be changed once submitted.</span>
        `;
        elements.modalBtnConfirm.textContent = `Confirm ${teamName}`;
        elements.modalBtnConfirm.disabled = false;

        elements.modalOverlay.classList.add('active');
    }

    function closeModal() {
        if (state.isSubmitting) return;
        elements.modalOverlay.classList.remove('active');
        state.pendingTeamPick = null;
    }

    // Submit Team Pick to Backend
    async function confirmPick() {
        if (!state.pendingTeamPick || state.isSubmitting) return;

        state.isSubmitting = true;
        elements.modalBtnConfirm.disabled = true;
        elements.modalBtnConfirm.textContent = 'Locking your pick...';

        try {
            const pickUrl = `api.php?action=pick&t=${encodeURIComponent(state.token)}&team_id=${encodeURIComponent(state.pendingTeamPick.id)}`;
            const response = await fetch(pickUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    action: 'pick',
                    t: state.token,
                    team_id: state.pendingTeamPick.id
                })
            });

            const result = await response.json();

            if (result.success) {
                closeModal();
                showToast(`Joined ${state.pendingTeamPick.name}! Choice locked.`, 'success');
                // Refresh status immediately
                await fetchStatus(false);
            } else {
                showToast(result.error || 'Could not complete pick.', 'error');
                closeModal();
                // Re-fetch to reflect current slot caps
                await fetchStatus(false);
            }
        } catch (err) {
            console.error('Pick submission error:', err);
            showToast('Network error while saving pick. Please try again.', 'error');
            closeModal();
        } finally {
            state.isSubmitting = false;
        }
    }

    // Toast Message System
    function showToast(message, type = 'info') {
        if (!elements.toastContainer) return;

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;

        let icon = '';
        if (window.TurfIcons) {
            if (type === 'success') icon = TurfIcons.icon('check', { width: 16, height: 16 });
            else if (type === 'error') icon = TurfIcons.icon('alert', { width: 16, height: 16 });
            else icon = TurfIcons.icon('ball', { width: 16, height: 16 });
        } else {
            icon = type === 'success' ? '✓' : '!';
        }
        toast.innerHTML = `<span style="display:inline-flex;align-items:center;">${icon}</span> <span>${escapeHtml(message)}</span>`;

        elements.toastContainer.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-10px)';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, 4000);
    }

    // Show friendly error page
    function showError(title, message) {
        if (elements.loadingView) elements.loadingView.style.display = 'none';
        if (elements.appView) elements.appView.style.display = 'none';
        if (elements.errorView) {
            elements.errorView.style.display = 'block';
            const errorTitle = document.getElementById('errorTitle');
            const errorDesc = document.getElementById('errorDesc');
            if (errorTitle) errorTitle.textContent = title;
            if (errorDesc) errorDesc.textContent = message;
        }
    }

    // Safe string escape
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Expose toast helper for inline use if needed
    window.TurfApp = {
        showToast: showToast
    };

    // Run when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
