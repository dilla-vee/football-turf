/**
 * Turf Teams - Admin Dashboard Application
 * Manages squad rosters, tier caps, live side-by-side view, WhatsApp sharing, and team resets.
 */

(function () {
    'use strict';

    // State
    const state = {
        authenticated: false,
        data: null,
        pollIntervalId: null,
        editingPlayer: null,
        confirmAction: null
    };

    // DOM Elements Cache
    const el = {
        loginView: document.getElementById('loginView'),
        dashboardView: document.getElementById('dashboardView'),
        loginForm: document.getElementById('loginForm'),
        adminPasswordInput: document.getElementById('adminPasswordInput'),
        loginError: document.getElementById('loginError'),
        logoutBtn: document.getElementById('logoutBtn'),
        
        // Stats
        statSkilled: document.getElementById('statSkilled'),
        statRegular: document.getElementById('statRegular'),
        statTotal: document.getElementById('statTotal'),
        statPicked: document.getElementById('statPicked'),
        
        // Rival Conflicts
        rivalConflictContainer: document.getElementById('rivalConflictContainer'),

        // Lock button
        lockToggleBtn: document.getElementById('lockToggleBtn'),
        lockBadge: document.getElementById('lockBadge'),

        // Match Board
        boardRunda: document.getElementById('boardRunda'),
        boardMakongeni: document.getElementById('boardMakongeni'),
        unassignedList: document.getElementById('unassignedList'),
        unassignedCountBadge: document.getElementById('unassignedCountBadge'),

        // Player Roster
        allPlayersList: document.getElementById('allPlayersList'),
        addPlayerForm: document.getElementById('addPlayerForm'),
        newPlayerName: document.getElementById('newPlayerName'),
        newPlayerTier: document.getElementById('newPlayerTier'),

        // Buttons
        copyAllLinksBtn: document.getElementById('copyAllLinksBtn'),
        resetAllBtn: document.getElementById('resetAllBtn'),
        exportWhatsAppBtn: document.getElementById('exportWhatsAppBtn'),

        // Base URL
        baseUrlInput: document.getElementById('baseUrlInput'),
        saveBaseUrlBtn: document.getElementById('saveBaseUrlBtn'),

        // Modals
        adminModalOverlay: document.getElementById('adminModalOverlay'),
        adminModalTitle: document.getElementById('adminModalTitle'),
        adminModalBody: document.getElementById('adminModalBody'),
        adminModalConfirmBtn: document.getElementById('adminModalConfirmBtn'),
        adminModalCancelBtn: document.getElementById('adminModalCancelBtn'),

        // Export Modal
        exportModalOverlay: document.getElementById('exportModalOverlay'),
        exportTextArea: document.getElementById('exportTextArea'),
        copyExportBtn: document.getElementById('copyExportBtn'),
        closeExportBtn: document.getElementById('closeExportBtn'),

        // Edit Player Modal
        editModalOverlay: document.getElementById('editModalOverlay'),
        editPlayerForm: document.getElementById('editPlayerForm'),
        editPlayerId: document.getElementById('editPlayerId'),
        editPlayerName: document.getElementById('editPlayerName'),
        editPlayerTier: document.getElementById('editPlayerTier'),
        closeEditBtn: document.getElementById('closeEditBtn'),

        // Mode & Team Customization
        modeBtn2Teams: document.getElementById('modeBtn2Teams'),
        modeBtn3Teams: document.getElementById('modeBtn3Teams'),
        btnOpenEditTeamNames: document.getElementById('btnOpenEditTeamNames'),
        editTeamNamesModalOverlay: document.getElementById('editTeamNamesModalOverlay'),
        editTeamNamesForm: document.getElementById('editTeamNamesForm'),
        teamNameInput1: document.getElementById('teamNameInput1'),
        teamNameInput2: document.getElementById('teamNameInput2'),
        teamNameInput3: document.getElementById('teamNameInput3'),
        closeEditTeamNamesBtn: document.getElementById('closeEditTeamNamesBtn'),
        adminBoardsGrid: document.getElementById('adminBoardsGrid'),

        // Toast Container
        toastContainer: document.getElementById('toastContainer')
    };

    // Initialize
    async function init() {
        bindEvents();
        await checkAuth();
    }

    // Bind Event Listeners
    function bindEvents() {
        if (el.loginForm) {
            el.loginForm.addEventListener('submit', handleLoginSubmit);
        }

        if (el.logoutBtn) {
            el.logoutBtn.addEventListener('click', handleLogout);
        }

        if (el.lockToggleBtn) {
            el.lockToggleBtn.addEventListener('click', handleToggleLock);
        }

        if (el.copyAllLinksBtn) {
            el.copyAllLinksBtn.addEventListener('click', handleCopyAllLinks);
        }

        if (el.resetAllBtn) {
            el.resetAllBtn.addEventListener('click', () => {
                openAdminConfirm(
                    'Reset ALL Player Picks?',
                    'This will clear team assignments for all 18 players. Tokens and player profiles will remain intact.',
                    handleResetAllPicks,
                    'Reset Everything',
                    'btn-danger'
                );
            });
        }

        if (el.exportWhatsAppBtn) {
            el.exportWhatsAppBtn.addEventListener('click', handleOpenExportModal);
        }

        if (el.copyExportBtn) {
            el.copyExportBtn.addEventListener('click', handleCopyExportText);
        }

        if (el.closeExportBtn) {
            el.closeExportBtn.addEventListener('click', () => {
                if (el.exportModalOverlay) el.exportModalOverlay.classList.remove('active');
            });
        }

        if (el.addPlayerForm) {
            el.addPlayerForm.addEventListener('submit', handleAddPlayerSubmit);
        }

        if (el.editPlayerForm) {
            el.editPlayerForm.addEventListener('submit', handleEditPlayerSubmit);
        }

        if (el.closeEditBtn) {
            el.closeEditBtn.addEventListener('click', () => {
                if (el.editModalOverlay) el.editModalOverlay.classList.remove('active');
            });
        }

        if (el.saveBaseUrlBtn) {
            el.saveBaseUrlBtn.addEventListener('click', handleSaveBaseUrl);
        }

        if (el.modeBtn2Teams) {
            el.modeBtn2Teams.addEventListener('click', () => setTeamMode('2_teams'));
        }

        if (el.modeBtn3Teams) {
            el.modeBtn3Teams.addEventListener('click', () => setTeamMode('3_teams'));
        }

        if (el.btnOpenEditTeamNames) {
            el.btnOpenEditTeamNames.addEventListener('click', handleOpenEditTeamNamesModal);
        }

        if (el.closeEditTeamNamesBtn) {
            el.closeEditTeamNamesBtn.addEventListener('click', () => {
                if (el.editTeamNamesModalOverlay) el.editTeamNamesModalOverlay.classList.remove('active');
            });
        }

        if (el.editTeamNamesForm) {
            el.editTeamNamesForm.addEventListener('submit', handleSaveTeamNames);
        }

        if (el.adminModalCancelBtn) {
            el.adminModalCancelBtn.addEventListener('click', closeAdminModal);
        }

        if (el.adminModalConfirmBtn) {
            el.adminModalConfirmBtn.addEventListener('click', () => {
                if (typeof state.confirmAction === 'function') {
                    state.confirmAction();
                }
            });
        }

        // Close modals on overlay backdrop tap
        [el.adminModalOverlay, el.exportModalOverlay, el.editModalOverlay, el.editTeamNamesModalOverlay].forEach(overlay => {
            if (overlay) {
                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) {
                        overlay.classList.remove('active');
                    }
                });
            }
        });
    }

    // Check Auth Status
    async function checkAuth() {
        try {
            const res = await fetch('api.php?action=check_auth');
            const data = await res.json();
            if (data.success && data.authenticated) {
                state.authenticated = true;
                showDashboard();
            } else {
                showLogin();
            }
        } catch {
            showLogin();
        }
    }

    // Show Login Screen
    function showLogin() {
        state.authenticated = false;
        if (state.pollIntervalId) clearInterval(state.pollIntervalId);
        if (el.dashboardView) el.dashboardView.style.display = 'none';
        if (el.loginView) el.loginView.style.display = 'block';
    }

    // Show Dashboard Screen
    function showDashboard() {
        state.authenticated = true;
        if (el.loginView) el.loginView.style.display = 'none';
        if (el.dashboardView) el.dashboardView.style.display = 'block';
        fetchDashboardData();
        startPolling();
    }

    // Start 5-second polling for live squad match board
    function startPolling() {
        if (state.pollIntervalId) clearInterval(state.pollIntervalId);
        state.pollIntervalId = setInterval(fetchDashboardData, 5000);
    }

    // Fetch Dashboard Data
    async function fetchDashboardData() {
        if (!state.authenticated) return;
        try {
            const res = await fetch('api.php?action=dashboard_data');
            const data = await res.json();

            if (!res.ok || !data.success) {
                if (res.status === 401) {
                    showLogin();
                }
                return;
            }

            state.data = data;
            renderDashboard();
        } catch (err) {
            console.error('Error fetching dashboard data:', err);
        }
    }

    // Handle Login Submit
    async function handleLoginSubmit(e) {
        e.preventDefault();
        const password = el.adminPasswordInput.value.trim();
        if (!password) return;

        if (el.loginError) el.loginError.style.display = 'none';

        try {
            const res = await fetch('api.php?action=login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: password })
            });
            const data = await res.json();

            if (data.success) {
                el.adminPasswordInput.value = '';
                showDashboard();
                showToast('Welcome to Turf Teams Admin', 'success');
            } else {
                if (el.loginError) {
                    el.loginError.textContent = data.error || 'Invalid admin password';
                    el.loginError.style.display = 'block';
                }
            }
        } catch (err) {
            if (el.loginError) {
                el.loginError.textContent = 'Network error during login';
                el.loginError.style.display = 'block';
            }
        }
    }

    // Handle Logout
    async function handleLogout() {
        try {
            await fetch('api.php?action=logout');
            showLogin();
            showToast('Logged out of admin dashboard', 'info');
        } catch {
            showLogin();
        }
    }

    // Render Dashboard Elements
    function renderDashboard() {
        if (!state.data) return;
        const d = state.data;

        // Share roster data with match scoreboard module
        window.turfAdminData = d;
        if (typeof window.renderAdminMatchRosterSync === 'function') {
            window.renderAdminMatchRosterSync(d);
        }

        // Mode tabs sync
        const is3Teams = (d.team_mode === '3_teams');
        if (el.modeBtn2Teams) el.modeBtn2Teams.classList.toggle('active', !is3Teams);
        if (el.modeBtn3Teams) el.modeBtn3Teams.classList.toggle('active', is3Teams);

        // Stats Counters
        const caps = d.team_caps || { total_target: 18, max_skilled_per_team: 5, max_regular_per_team: 5 };
        if (el.statSkilled) el.statSkilled.textContent = `${d.stats.skilled_count} / ${d.stats.skilled_target}`;
        if (el.statRegular) el.statRegular.textContent = `${d.stats.regular_count} / ${d.stats.regular_target}`;
        if (el.statTotal) el.statTotal.textContent = `${d.stats.total_players} / ${caps.total_target}`;
        if (el.statPicked) el.statPicked.textContent = `${d.stats.picked_count} / ${d.stats.total_players}`;

        // Lock Status Toggle
        if (el.lockBadge) {
            if (d.picking_locked) {
                const lockIcon = window.TurfIcons ? TurfIcons.icon('lock', { width:12, height:12 }) : '';
                el.lockBadge.innerHTML = `<span style="display:inline-flex;align-items:center;gap:3px;">${lockIcon} CLOSED</span>`;
                el.lockBadge.className = 'tier-badge tier-skilled';
                el.lockBadge.style.background = '#7f1d1d';
                el.lockBadge.style.color = '#fecaca';
                el.lockBadge.style.borderColor = '#ef4444';
            } else {
                const unlockIcon = window.TurfIcons ? TurfIcons.icon('unlock', { width:12, height:12 }) : '';
                el.lockBadge.innerHTML = `<span style="display:inline-flex;align-items:center;gap:3px;">${unlockIcon} OPEN</span>`;
                el.lockBadge.className = 'tier-badge tier-regular';
                el.lockBadge.style.background = '#064e3b';
                el.lockBadge.style.color = '#a7f3d0';
                el.lockBadge.style.borderColor = '#10b981';
            }
        }

        if (el.lockToggleBtn) {
            const btnLockIcon = window.TurfIcons ? TurfIcons.icon(d.picking_locked ? 'unlock' : 'lock', { width: 14, height: 14 }) : '';
            el.lockToggleBtn.innerHTML = `<span style="display:inline-flex;align-items:center;gap:0.4rem;">${btnLockIcon} <span>${d.picking_locked ? 'Unlock Picking' : 'Lock Picking'}</span></span>`;
            el.lockToggleBtn.className = d.picking_locked ? 'btn btn-primary btn-sm' : 'btn btn-warning btn-sm';
        }

        // Base URL Input
        if (el.baseUrlInput && document.activeElement !== el.baseUrlInput) {
            el.baseUrlInput.value = d.base_url || '';
        }

        // Render Rival Matchup Conflicts Alert (e.g. Jimmy & Oliver on same team)
        if (el.rivalConflictContainer) {
            if (d.rival_conflicts && d.rival_conflicts.length > 0) {
                const alertSvg = window.TurfIcons ? TurfIcons.icon('alert', { width: 16, height: 16 }) : '';
                const refreshSvg = window.TurfIcons ? TurfIcons.icon('refresh', { width: 12, height: 12 }) : '';
                el.rivalConflictContainer.innerHTML = d.rival_conflicts.map(c => `
                    <div class="conflict-alert" style="background:#451a1a;border:1px solid #ef4444;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.25rem;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem;color:#fecaca;box-shadow:0 4px 15px rgba(239,68,68,0.25);">
                        <div>
                            <div style="font-weight:800;color:#f87171;font-size:0.95rem;display:flex;align-items:center;gap:0.4rem;">
                                <span>${alertSvg}</span>
                                <span>RIVAL MATCHUP CONFLICT: Both Opponents on Same Team!</span>
                            </div>
                            <p style="margin:0.35rem 0 0 0;font-size:0.85rem;color:#fca5a5;line-height:1.4;">
                                <strong>${escapeHtml(c.player1.name)}</strong> and <strong>${escapeHtml(c.player2.name)}</strong> are both registered on <strong>${escapeHtml(c.team_name)}</strong>. Per the match sheet, they are paired head-to-head rivals and must play on opposing teams!
                            </p>
                        </div>
                        <button type="button" class="btn btn-warning btn-sm btn-conflict-reset" 
                            data-id="${c.player2.id}" data-name="${escapeHtml(c.player2.name)}" data-team="${escapeHtml(c.team_name)}" style="display:inline-flex;align-items:center;gap:0.35rem;">
                            ${refreshSvg} Reset ${escapeHtml(c.player2.name)}'s Pick
                        </button>
                    </div>
                `).join('');

                el.rivalConflictContainer.querySelectorAll('.btn-conflict-reset').forEach(btn => {
                    btn.addEventListener('click', function () {
                        const id = parseInt(this.getAttribute('data-id'), 10);
                        const name = this.getAttribute('data-name');
                        const team = this.getAttribute('data-team');
                        openAdminConfirm(
                            `Reset ${name}'s pick?`,
                            `This will remove ${name} from ${team}. ${name} can then open their link and join the opposing team to balance the squads!`,
                            () => handleResetPlayerPick(id),
                            'Reset Pick',
                            'btn-warning'
                        );
                    });
                });
            } else {
                el.rivalConflictContainer.innerHTML = '';
            }
        }

        // Render Side-by-Side Match Boards
        renderMatchBoards(d.teams);

        // Render Unassigned Players
        renderUnassigned(d.unassigned);

        // Render Player Directory
        renderPlayerDirectory(d.all_players);
    }

    // Render Squads Grid (Dynamically supports 2 or 3 teams)
    function renderMatchBoards(teams) {
        const grid = el.adminBoardsGrid;
        if (!grid) return;
        if (!teams || teams.length === 0) {
            grid.innerHTML = '<div style="text-align:center;padding:1.5rem;color:var(--text-dim);grid-column:1/-1;">No teams configured.</div>';
            return;
        }

        grid.innerHTML = teams.map((team) => {
            let playersHtml = '';
            if (team.players && team.players.length > 0) {
                playersHtml = team.players.map((p, index) => {
                    const tierClass = p.tier === 'skilled' ? 'tier-skilled' : 'tier-regular';
                    const tierLabel = p.tier === 'skilled' ? 'Skilled' : 'Regular';
                    const rivalHtml = p.rival_name 
                        ? `<span class="rival-badge" style="font-size:0.68rem;padding:2px 6px;border-radius:4px;border:1px solid var(--border-pitch);margin-left:4px;display:inline-flex;align-items:center;gap:3px;">${window.TurfIcons ? TurfIcons.icon('users', { width:10, height:10 }) : ''} vs ${escapeHtml(p.rival_name)}</span>` 
                        : '';
                    const refreshSvg = window.TurfIcons ? TurfIcons.icon('refresh', { width:12, height:12 }) : '';

                    return `
                        <div class="player-row">
                            <div class="player-info">
                                <span style="color:var(--text-dim);font-size:0.75rem;min-width:20px;">#${index + 1}</span>
                                <span style="font-weight:600;">${escapeHtml(p.name)}</span>
                                <span class="tier-badge ${tierClass}">${tierLabel}</span>
                                ${rivalHtml}
                            </div>
                            <div class="player-row-actions">
                                <button type="button" class="btn btn-outline btn-sm btn-reset-pick" 
                                    data-id="${p.id}" data-name="${escapeHtml(p.name)}" title="Reset this pick" style="display:inline-flex;align-items:center;gap:0.35rem;">
                                    ${refreshSvg} Reset
                                </button>
                            </div>
                        </div>
                    `;
                }).join('');
            } else {
                playersHtml = `<div class="roster-empty">No players registered yet.</div>`;
            }

            const badgeSvg = window.TurfIcons ? TurfIcons.teamBadge(team.name, 28) : '';
            return `
                <div class="admin-card">
                    <div class="admin-card-header">
                        <div>
                            <h3 class="admin-card-title" style="display:flex;align-items:center;gap:0.4rem;">
                                ${badgeSvg} ${escapeHtml(team.name)}
                            </h3>
                            <div style="font-size:0.8rem;color:var(--text-secondary);margin-top:0.2rem;">
                                Skilled: <strong>${team.skilled_count}/${team.skilled_cap}</strong> • 
                                Regular: <strong>${team.regular_count}/${team.regular_cap}</strong> • 
                                Total: <strong>${team.total_count}/${team.max_team_size}</strong>
                            </div>
                        </div>
                    </div>
                    <div class="team-board-list">
                        ${playersHtml}
                    </div>
                </div>
            `;
        }).join('');

        // Attach reset click handlers
        grid.querySelectorAll('.btn-reset-pick').forEach(btn => {
            btn.addEventListener('click', function () {
                const id = parseInt(this.getAttribute('data-id'), 10);
                const name = this.getAttribute('data-name');
                openAdminConfirm(
                    `Reset pick for ${name}?`,
                    `This will remove ${name}'s squad selection. They will be able to pick again.`,
                    () => handleResetPlayerPick(id),
                    'Reset Pick',
                    'btn-warning'
                );
            });
        });
    }

    // Switch Match Format (2 Teams vs 3 Teams)
    async function setTeamMode(mode) {
        try {
            const res = await fetch('api.php?action=set_team_mode', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mode: mode, team_mode: mode })
            });
            const data = await res.json();
            if (data.success) {
                showToast(data.message || `Switched to ${mode === '3_teams' ? '3 squads (6v6v6)' : '2 squads (9v9)'}`, 'success');
                await fetchDashboardData();
            } else {
                showToast(data.error || 'Failed to switch match mode', 'error');
            }
        } catch (e) {
            showToast('Network error setting match mode', 'error');
        }
    }

    // Open Custom Team Names Modal
    function handleOpenEditTeamNamesModal() {
        if (!el.editTeamNamesModalOverlay) return;
        const allTeams = state.data?.all_teams || state.data?.teams || [];
        const t1 = allTeams.find(t => t.id == 1);
        const t2 = allTeams.find(t => t.id == 2);
        const t3 = allTeams.find(t => t.id == 3);

        if (el.teamNameInput1) el.teamNameInput1.value = t1 ? t1.name : 'Runda FC';
        if (el.teamNameInput2) el.teamNameInput2.value = t2 ? t2.name : 'Makongeni FC';
        if (el.teamNameInput3) el.teamNameInput3.value = t3 ? t3.name : 'Parklands FC';

        el.editTeamNamesModalOverlay.classList.add('active');
    }

    // Save Custom Team Names
    async function handleSaveTeamNames(e) {
        e.preventDefault();
        const n1 = el.teamNameInput1?.value?.trim() || '';
        const n2 = el.teamNameInput2?.value?.trim() || '';
        const n3 = el.teamNameInput3?.value?.trim() || '';

        if (!n1 || !n2) {
            showToast('Team 1 and Team 2 names are required', 'warning');
            return;
        }

        const names = { 1: n1, 2: n2 };
        if (n3) names[3] = n3;

        try {
            const res = await fetch('api.php?action=update_team_names', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ names: names })
            });
            const data = await res.json();
            if (data.success) {
                if (el.editTeamNamesModalOverlay) el.editTeamNamesModalOverlay.classList.remove('active');
                showToast('Team names updated successfully!', 'success');
                fetchDashboardData();
            } else {
                showToast(data.error || 'Failed to update team names', 'error');
            }
        } catch (err) {
            showToast('Network error saving team names', 'error');
        }
    }

    // Render Unassigned Players
    function renderUnassigned(unassigned) {
        if (!el.unassignedList) return;

        if (el.unassignedCountBadge) {
            el.unassignedCountBadge.textContent = unassigned.length;
        }

        if (unassigned.length === 0) {
            el.unassignedList.innerHTML = `<div class="roster-empty" style="color:var(--grass-light);">All players have chosen their squads!</div>`;
            return;
        }

        const copySvg = window.TurfIcons ? TurfIcons.icon('copy', { width:12, height:12 }) : '';
        const shareSvg = window.TurfIcons ? TurfIcons.icon('share', { width:12, height:12 }) : '';

        const html = unassigned.map((p, index) => {
            const tierClass = p.tier === 'skilled' ? 'tier-skilled' : 'tier-regular';
            const tierLabel = p.tier === 'skilled' ? 'Skilled' : 'Regular';
            const rivalHtml = p.rival_name 
                ? `<span class="rival-badge" style="font-size:0.68rem;background:rgba(239,68,68,0.15);color:#fca5a5;padding:2px 6px;border-radius:4px;border:1px solid rgba(239,68,68,0.25);margin-left:4px;display:inline-flex;align-items:center;gap:3px;">${window.TurfIcons ? TurfIcons.icon('users', { width:10, height:10 }) : ''} vs ${escapeHtml(p.rival_name)}</span>` 
                : '';

            return `
                <div class="player-row">
                    <div class="player-info">
                        <span style="color:var(--text-dim);font-size:0.75rem;">#${index + 1}</span>
                        <span style="font-weight:600;">${escapeHtml(p.name)}</span>
                        <span class="tier-badge ${tierClass}">${tierLabel}</span>
                        ${rivalHtml}
                    </div>
                    <div class="player-row-actions">
                        <button type="button" class="btn btn-outline btn-sm btn-copy-single-link" 
                            data-link="${escapeHtml(p.link)}" title="Copy Personal Link" style="display:inline-flex;align-items:center;gap:0.35rem;">
                            ${copySvg} Copy Pass
                        </button>
                        <a href="${p.whatsapp_link}" target="_blank" rel="noopener noreferrer" 
                            class="btn btn-whatsapp btn-sm" title="Share via WhatsApp" style="display:inline-flex;align-items:center;gap:0.35rem;">
                            ${shareSvg} WhatsApp
                        </a>
                    </div>
                </div>
            `;
        }).join('');

        el.unassignedList.innerHTML = html;

        // Attach copy single link
        el.unassignedList.querySelectorAll('.btn-copy-single-link').forEach(btn => {
            btn.addEventListener('click', function () {
                const link = this.getAttribute('data-link');
                copyToClipboard(link, 'Personal match link copied!');
            });
        });
    }

    // Render Full Player Directory
    function renderPlayerDirectory(players) {
        if (!el.allPlayersList) return;

        if (!players || players.length === 0) {
            el.allPlayersList.innerHTML = `<div class="roster-empty">No players added yet.</div>`;
            return;
        }

        const copySvg = window.TurfIcons ? TurfIcons.icon('copy', { width:12, height:12 }) : '';
        const shareSvg = window.TurfIcons ? TurfIcons.icon('share', { width:12, height:12 }) : '';
        const editSvg = window.TurfIcons ? TurfIcons.icon('edit', { width:12, height:12 }) : '';
        const trashSvg = window.TurfIcons ? TurfIcons.icon('trash', { width:12, height:12 }) : '';

        const html = players.map(p => {
            const tierClass = p.tier === 'skilled' ? 'tier-skilled' : 'tier-regular';
            const tierLabel = p.tier === 'skilled' ? 'Skilled' : 'Regular';
            const statusText = p.team_name ? `<span style="color:#34d399;font-size:0.75rem;font-weight:600;">${escapeHtml(p.team_name)}</span>` : `<span style="color:var(--text-dim);font-size:0.75rem;">Pending</span>`;
            const rivalHtml = p.rival_name 
                ? `<span class="rival-badge" style="font-size:0.68rem;background:rgba(239,68,68,0.15);color:#fca5a5;padding:2px 6px;border-radius:4px;border:1px solid rgba(239,68,68,0.25);margin-left:4px;display:inline-flex;align-items:center;gap:3px;">${window.TurfIcons ? TurfIcons.icon('users', { width:10, height:10 }) : ''} vs ${escapeHtml(p.rival_name)}</span>` 
                : '';

            return `
                <div class="player-row">
                    <div class="player-info" style="min-width:200px;">
                        <span style="font-weight:700;">${escapeHtml(p.name)}</span>
                        <span class="tier-badge ${tierClass}">${tierLabel}</span>
                        ${rivalHtml}
                        ${statusText}
                    </div>
                    <div class="player-row-actions">
                        <button type="button" class="btn btn-outline btn-sm btn-copy-single-link" data-link="${escapeHtml(p.link)}" style="display:inline-flex;align-items:center;gap:0.35rem;">
                            ${copySvg} Pass
                        </button>
                        <a href="${p.whatsapp_link}" target="_blank" rel="noopener noreferrer" class="btn btn-whatsapp btn-sm" style="display:inline-flex;align-items:center;gap:0.35rem;">
                            ${shareSvg} Share
                        </a>
                        <button type="button" class="btn btn-outline btn-sm btn-edit-player" 
                            data-id="${p.id}" data-name="${escapeHtml(p.name)}" data-tier="${p.tier}" style="display:inline-flex;align-items:center;gap:0.35rem;">
                            ${editSvg} Edit
                        </button>
                        <button type="button" class="btn btn-danger btn-sm btn-delete-player" 
                            data-id="${p.id}" data-name="${escapeHtml(p.name)}" style="padding:0.3rem 0.5rem;">
                            ${trashSvg}
                        </button>
                    </div>
                </div>
            `;
        }).join('');

        el.allPlayersList.innerHTML = html;

        // Attach handlers
        el.allPlayersList.querySelectorAll('.btn-copy-single-link').forEach(btn => {
            btn.addEventListener('click', function () {
                const link = this.getAttribute('data-link');
                copyToClipboard(link, 'Personal link copied!');
            });
        });

        el.allPlayersList.querySelectorAll('.btn-edit-player').forEach(btn => {
            btn.addEventListener('click', function () {
                const id = parseInt(this.getAttribute('data-id'), 10);
                const name = this.getAttribute('data-name');
                const tier = this.getAttribute('data-tier');
                openEditModal(id, name, tier);
            });
        });

        el.allPlayersList.querySelectorAll('.btn-delete-player').forEach(btn => {
            btn.addEventListener('click', function () {
                const id = parseInt(this.getAttribute('data-id'), 10);
                const name = this.getAttribute('data-name');
                openAdminConfirm(
                    `Delete ${name}?`,
                    `Are you sure you want to permanently remove ${name}? This action cannot be undone.`,
                    () => handleDeletePlayer(id),
                    'Delete Player',
                    'btn-danger'
                );
            });
        });
    }

    // Toggle Picking Lock
    async function handleToggleLock() {
        if (!state.data) return;
        const newLockState = !state.data.picking_locked;

        try {
            const res = await fetch('api.php?action=toggle_lock', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ locked: newLockState })
            });
            const result = await res.json();
            if (result.success) {
                showToast(result.message, newLockState ? 'warning' : 'success');
                await fetchDashboardData();
            } else {
                showToast(result.error || 'Failed to toggle lock', 'error');
            }
        } catch (err) {
            showToast('Network error while toggling lock', 'error');
        }
    }

    // Reset Single Player Pick
    async function handleResetPlayerPick(playerId) {
        closeAdminModal();
        try {
            const res = await fetch('api.php?action=reset_player', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: playerId })
            });
            const result = await res.json();
            if (result.success) {
                showToast(result.message, 'success');
                await fetchDashboardData();
            } else {
                showToast(result.error || 'Failed to reset player', 'error');
            }
        } catch (err) {
            showToast('Network error while resetting player', 'error');
        }
    }

    // Reset All Picks
    async function handleResetAllPicks() {
        closeAdminModal();
        try {
            const res = await fetch('api.php?action=reset_all', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });
            const result = await res.json();
            if (result.success) {
                showToast(result.message, 'success');
                await fetchDashboardData();
            } else {
                showToast(result.error || 'Failed to reset all picks', 'error');
            }
        } catch (err) {
            showToast('Network error during reset all', 'error');
        }
    }

    // Copy All Links
    function handleCopyAllLinks() {
        if (!state.data || !state.data.all_players || state.data.all_players.length === 0) {
            showToast('No players to copy', 'warning');
            return;
        }

        const lines = state.data.all_players.map(p => `${p.name} - ${p.link}`);
        const allText = lines.join('\n');

        copyToClipboard(allText, `Copied all ${lines.length} player links to clipboard!`);
    }

    // Export WhatsApp Match Squad
    function handleOpenExportModal() {
        if (!state.data || !state.data.teams) return;

        const is3Teams = (state.data.team_mode === '3_teams');
        let text = `*TURF TEAMS - MATCHDAY SQUADS*\n`;
        text += `Format: ${is3Teams ? '3 Squads (6v6v6)' : '2 Squads (9v9)'}\n\n`;

        state.data.teams.forEach(team => {
            text += `*${team.name.toUpperCase()}* (${team.players.length}/${team.max_team_size})\n`;
            if (team.players.length === 0) {
                text += `_No players picked yet_\n`;
            } else {
                team.players.forEach((p, idx) => {
                    const tier = p.tier === 'skilled' ? 'Skilled' : 'Regular';
                    text += `${idx + 1}. ${p.name} (${tier})\n`;
                });
            }
            text += `\n`;
        });

        // Unassigned notice if any
        if (state.data.unassigned && state.data.unassigned.length > 0) {
            text += `*Pending Selection (${state.data.unassigned.length}):*\n`;
            text += state.data.unassigned.map(p => `• ${p.name}`).join('\n') + `\n\n`;
        }

        text += `*Matchday Notice: Please arrive 15 minutes before kickoff.*`;

        if (el.exportTextArea) {
            el.exportTextArea.value = text;
        }

        if (el.exportModalOverlay) {
            el.exportModalOverlay.classList.add('active');
        }
    }

    function handleCopyExportText() {
        if (!el.exportTextArea) return;
        copyToClipboard(el.exportTextArea.value, 'WhatsApp squad list copied to clipboard!');
    }

    // Add Player Submit
    async function handleAddPlayerSubmit(e) {
        e.preventDefault();
        const name = el.newPlayerName.value.trim();
        const tier = el.newPlayerTier.value;

        if (!name) return;

        try {
            const res = await fetch('api.php?action=add_player', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, tier })
            });
            const result = await res.json();
            if (result.success) {
                el.newPlayerName.value = '';
                showToast(result.message, 'success');
                await fetchDashboardData();
            } else {
                showToast(result.error || 'Failed to add player', 'error');
            }
        } catch (err) {
            showToast('Network error while adding player', 'error');
        }
    }

    // Edit Player Submit
    function openEditModal(id, name, tier) {
        if (el.editPlayerId) el.editPlayerId.value = id;
        if (el.editPlayerName) el.editPlayerName.value = name;
        if (el.editPlayerTier) el.editPlayerTier.value = tier;
        if (el.editModalOverlay) el.editModalOverlay.classList.add('active');
    }

    async function handleEditPlayerSubmit(e) {
        e.preventDefault();
        const id = parseInt(el.editPlayerId.value, 10);
        const name = el.editPlayerName.value.trim();
        const tier = el.editPlayerTier.value;

        if (!name || id <= 0) return;

        try {
            const res = await fetch('api.php?action=edit_player', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, name, tier })
            });
            const result = await res.json();
            if (result.success) {
                if (el.editModalOverlay) el.editModalOverlay.classList.remove('active');
                showToast(result.message, 'success');
                await fetchDashboardData();
            } else {
                showToast(result.error || 'Failed to update player', 'error');
            }
        } catch (err) {
            showToast('Network error while updating player', 'error');
        }
    }

    // Delete Player
    async function handleDeletePlayer(id) {
        closeAdminModal();
        try {
            const res = await fetch('api.php?action=delete_player', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            const result = await res.json();
            if (result.success) {
                showToast(result.message, 'success');
                await fetchDashboardData();
            } else {
                showToast(result.error || 'Failed to delete player', 'error');
            }
        } catch (err) {
            showToast('Network error while deleting player', 'error');
        }
    }

    // Save Custom Base URL
    async function handleSaveBaseUrl() {
        const url = el.baseUrlInput.value.trim();
        try {
            const res = await fetch('api.php?action=set_base_url', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ base_url: url })
            });
            const result = await res.json();
            if (result.success) {
                showToast('Base URL updated! All WhatsApp links refreshed.', 'success');
                await fetchDashboardData();
            }
        } catch (err) {
            showToast('Network error while updating base URL', 'error');
        }
    }

    // Confirm Dialog Helper
    function openAdminConfirm(title, bodyText, actionFn, confirmLabel = 'Confirm', btnClass = 'btn-primary') {
        state.confirmAction = actionFn;
        if (el.adminModalTitle) el.adminModalTitle.textContent = title;
        if (el.adminModalBody) el.adminModalBody.innerHTML = bodyText;
        if (el.adminModalConfirmBtn) {
            el.adminModalConfirmBtn.textContent = confirmLabel;
            el.adminModalConfirmBtn.className = `modal-btn ${btnClass}`;
        }
        if (el.adminModalOverlay) el.adminModalOverlay.classList.add('active');
    }

    function closeAdminModal() {
        state.confirmAction = null;
        if (el.adminModalOverlay) el.adminModalOverlay.classList.remove('active');
    }

    // Clipboard Copy Helper
    async function copyToClipboard(text, successMsg) {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                showToast(successMsg, 'success');
            } else {
                // Fallback for older browsers / non-HTTPS
                const textArea = document.createElement('textarea');
                textArea.value = text;
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                const successful = document.execCommand('copy');
                document.body.removeChild(textArea);
                if (successful) {
                    showToast(successMsg, 'success');
                } else {
                    prompt('Copy this text manually:', text);
                }
            }
        } catch (err) {
            prompt('Copy this text manually:', text);
        }
    }

    // Toast Message System
    function showToast(message, type = 'info') {
        if (!el.toastContainer) return;

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;

        let icon = '';
        if (window.TurfIcons) {
            if (type === 'success') icon = TurfIcons.icon('check', { width: 16, height: 16 });
            else if (type === 'error' || type === 'warning') icon = TurfIcons.icon('alert', { width: 16, height: 16 });
            else icon = TurfIcons.icon('ball', { width: 16, height: 16 });
        } else {
            icon = type === 'success' ? '✓' : '!';
        }
        toast.innerHTML = `<span style="display:inline-flex;align-items:center;">${icon}</span> <span>${escapeHtml(message)}</span>`;

        el.toastContainer.appendChild(toast);

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

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Run when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
