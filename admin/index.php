<?php
/**
 * Turf Teams - Admin Dashboard Page
 */

require_once __DIR__ . '/../config.php';
session_start();
$isLoggedIn = !empty($_SESSION[ADMIN_SESSION_KEY]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#080c14">
    <title>Turf Teams - Match Control Center</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="../assets/js/icons.js"></script>
    <style>
        .admin-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem 0;
            border-bottom: 1px solid var(--border-pitch);
        }
        .admin-nav-left {
            display: flex;
            align-items: center;
            gap: 0.85rem;
        }
        .admin-nav-right {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .nav-logo-badge {
            width: 38px;
            height: 38px;
            border-radius: var(--radius-sm);
            background: linear-gradient(135deg, rgba(16,185,129,0.2) 0%, rgba(37,99,235,0.2) 100%);
            border: 1px solid rgba(255,255,255,0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #34d399;
        }
        .club-pill-sm {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            padding: 0.15rem 0.45rem;
            border-radius: var(--radius-pill);
            margin-right: 0.35rem;
        }
        .club-pill-sm.runda {
            background: rgba(16,185,129,0.18);
            color: #34d399;
            border: 1px solid rgba(16,185,129,0.35);
        }
        .club-pill-sm.mako {
            background: rgba(37,99,235,0.18);
            color: #60a5fa;
            border: 1px solid rgba(37,99,235,0.35);
        }
    </style>
</head>
<body>
    <!-- Toast Notifications Container -->
    <div class="toast-container" id="toastContainer" aria-live="polite"></div>

    <div class="container container-wide">
        <!-- ==========================================
             1. Admin Login View (When Not Authenticated)
             ========================================== -->
        <div id="loginView" style="<?php echo $isLoggedIn ? 'display:none;' : 'display:block;'; ?>">
            <div class="error-screen" style="max-width:440px; margin-top:4rem;">
                <div id="loginShieldBox" style="margin-bottom:1.25rem;"></div>
                <h2 style="font-size:1.5rem; color:var(--text-primary); margin-bottom:0.5rem; letter-spacing:-0.02em;">Match Organizer Portal</h2>
                <p style="color:var(--text-secondary); font-size:0.9rem; margin-bottom:1.5rem; line-height:1.5;">
                    Sign in to manage players, view live squad rosters, share match passes, and control live match scoring.
                </p>

                <div id="loginError" style="display:none; color:#f87171; background:rgba(239,68,68,0.15); border:1px solid #ef4444; padding:0.6rem; border-radius:var(--radius-sm); font-size:0.85rem; margin-bottom:1rem;"></div>

                <form id="loginForm">
                    <div class="form-group">
                        <label for="adminPasswordInput" class="form-label">Admin Password</label>
                        <input type="password" id="adminPasswordInput" class="form-input" placeholder="Enter password (default: turf2026)" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%; min-height:48px; justify-content:center; font-size:1rem;">
                        Sign In to Dashboard
                    </button>
                </form>

                <div style="margin-top:1.5rem; padding-top:1rem; border-top:1px solid var(--border-pitch); font-size:0.8rem; color:var(--text-dim);">
                    Default password: <code style="color:var(--grass-light);">turf2026</code> (configured in config.php)
                </div>
            </div>
        </div>

        <!-- ==========================================
             2. Admin Dashboard View (When Authenticated)
             ========================================== -->
        <div id="dashboardView" style="<?php echo $isLoggedIn ? 'display:block;' : 'display:none;'; ?>">
            <!-- Navigation Header -->
            <nav class="admin-nav">
                <div class="admin-nav-left">
                    <div class="nav-logo-badge" id="adminNavLogo"></div>
                    <div>
                        <h1 style="font-size:1.35rem; font-weight:800; color:var(--text-primary); margin:0; line-height:1.2; letter-spacing:-0.02em;">
                            TURF TEAMS ADMIN
                        </h1>
                        <span style="font-size:0.75rem; color:var(--text-secondary); letter-spacing:0.04em; text-transform:uppercase; font-weight:600;">Matchday Operations Center</span>
                    </div>
                </div>
                <div class="admin-nav-right">
                    <span id="lockBadge" class="tier-badge tier-regular">OPEN</span>
                    <button type="button" id="lockToggleBtn" class="btn btn-outline btn-sm" style="display:inline-flex; align-items:center; gap:0.4rem;">
                        <span id="lockBtnIcon"></span><span id="lockBtnText">Lock Picking</span>
                    </button>
                    <a href="../" class="btn btn-outline btn-sm" target="_blank" style="display:inline-flex; align-items:center; gap:0.4rem;">
                        <span id="homeBtnIcon"></span> Public View
                    </a>
                    <button type="button" class="theme-toggle-btn" id="adminThemeToggleBtn" onclick="TurfTheme.toggle()" title="Toggle Black / White Mode"></button>
                    <button type="button" id="logoutBtn" class="btn btn-outline btn-sm">Sign Out</button>
                </div>
            </nav>

            <!-- Match Format & Team Customization Bar -->
            <section style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.75rem; margin-bottom:1.25rem;">
                <div class="mode-switch-bar" style="max-width:400px; margin:0;">
                    <button type="button" class="mode-tab-btn active" id="modeBtn2Teams" data-mode="2_teams">
                        2 Squads (9 vs 9)
                    </button>
                    <button type="button" class="mode-tab-btn" id="modeBtn3Teams" data-mode="3_teams">
                        3 Squads (6 vs 6 vs 6)
                    </button>
                </div>

                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <button type="button" class="btn btn-outline btn-sm" id="btnOpenEditTeamNames" style="display:inline-flex; align-items:center; gap:0.4rem;">
                        <span id="editTeamsIcon"></span> Customize Team Names
                    </button>
                </div>
            </section>

            <!-- Conflict Banner Container -->
            <div id="rivalConflictContainer" style="margin-bottom: 1rem;"></div>

            <!-- Stats & Balance Counters Bar -->
            <section class="admin-stats-bar">
                <div class="stat-card">
                    <div class="stat-label">Skilled Balance</div>
                    <div class="stat-value" id="statSkilled">0 / 10</div>
                    <div class="stat-target">5 per team cap (5 pairs)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Regular Balance</div>
                    <div class="stat-value" id="statRegular">0 / 10</div>
                    <div class="stat-target">5 per team cap</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Roster</div>
                    <div class="stat-value" id="statTotal">0 / 20</div>
                    <div class="stat-target">Max 20 players</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Locked In</div>
                    <div class="stat-value" id="statPicked" style="color:var(--grass-light);">0 / 20</div>
                    <div class="stat-target">Picked teams</div>
                </div>
            </section>

            <!-- Action Toolbar -->
            <section class="admin-toolbar">
                <button type="button" id="copyAllLinksBtn" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:0.45rem;">
                    <span id="copyLinksIcon"></span> Copy All Player Passes
                </button>
                <button type="button" id="exportWhatsAppBtn" class="btn btn-whatsapp" style="display:inline-flex; align-items:center; gap:0.45rem;">
                    <span id="exportWaIcon"></span> Export Squad to WhatsApp
                </button>
                <button type="button" id="resetAllBtn" class="btn btn-outline" style="border-color:#ef4444; color:#f87171; display:inline-flex; align-items:center; gap:0.45rem;">
                    <span id="resetPicksIcon"></span> Reset All Picks
                </button>
                <div style="flex-grow:1;"></div>
                <div class="polling-status" style="margin:0;">
                    <span class="poll-dot"></span>
                    <span>Live 5s Match Sync</span>
                </div>
            </section>

            <!-- Shareable Base URL Config Bar -->
            <section class="admin-card" style="margin-bottom:1.5rem; padding:0.9rem 1.25rem;">
                <div style="display:flex; flex-wrap:wrap; align-items:center; gap:0.75rem;">
                    <label for="baseUrlInput" style="font-size:0.85rem; font-weight:700; color:var(--text-secondary); white-space:nowrap; display:inline-flex; align-items:center; gap:0.35rem;">
                        <span id="shareUrlIcon"></span> Shareable Base URL:
                    </label>
                    <input type="text" id="baseUrlInput" class="form-input" style="flex:1; min-width:260px; padding:0.45rem 0.75rem; font-size:0.85rem;" placeholder="e.g. http://192.168.1.50/turf-teams or ngrok url">
                    <button type="button" id="saveBaseUrlBtn" class="btn btn-outline btn-sm">Save URL</button>
                    <span style="font-size:0.75rem; color:var(--text-dim);">
                        (Change this if testing from phone via Wi-Fi IP or Ngrok)
                    </span>
                </div>
            </section>

            <!-- ==========================================
                 Live Match & Scoreboard Section
                 ========================================== -->
            <section id="adminMatchSection" style="margin-bottom:2rem;">
                <div style="text-align:center; padding:1.5rem; color:var(--text-dim);">Loading live match scoreboard...</div>
            </section>

            <!-- Live Match Board (Squads Grid: 2 or 3 teams) -->
            <section style="margin-bottom:2rem;">
                <h2 style="font-size:1.25rem; font-weight:800; margin-bottom:1rem; display:flex; align-items:center; gap:0.5rem; letter-spacing:-0.02em;">
                    <span id="squadsTitleIcon"></span> Live Match Squads
                </h2>
                <div class="admin-grid" id="adminBoardsGrid">
                    <div style="text-align:center; padding:1.5rem; color:var(--text-dim); grid-column: 1/-1;">Loading squad boards...</div>
                </div>
            </section>

            <!-- Pending Players (Unassigned) -->
            <section class="admin-card" style="margin-bottom:2rem;">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">
                        <span id="pendingTitleIcon"></span> Pending Selection 
                        <span class="tier-badge tier-regular" id="unassignedCountBadge">0</span>
                    </h3>
                    <span style="font-size:0.8rem; color:var(--text-dim);">Players who have not picked a team yet</span>
                </div>
                <div id="unassignedList">
                    <div class="roster-empty">Loading pending players...</div>
                </div>
            </section>

            <!-- Player Directory & CRUD Management -->
            <section class="admin-grid">
                <!-- Add Player Form -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title"><span id="addPlayerTitleIcon"></span> Add New Player</h3>
                    </div>
                    <form id="addPlayerForm">
                        <div class="form-group">
                            <label for="newPlayerName" class="form-label">Player Full Name</label>
                            <input type="text" id="newPlayerName" class="form-input" placeholder="e.g. Dennis Oliech" required autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label for="newPlayerTier" class="form-label">Skill Tier</label>
                            <select id="newPlayerTier" class="form-select" required>
                                <option value="skilled">Skilled (Cap: 4 per team)</option>
                                <option value="regular" selected>Regular (Cap: 5 per team)</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">
                            Add Player & Generate Link
                        </button>
                    </form>
                </div>

                <!-- Complete Player Directory -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title"><span id="dirTitleIcon"></span> Player Directory & Match Passes</h3>
                    </div>
                    <div id="allPlayersList" style="max-height:480px; overflow-y:auto; padding-right:0.25rem;">
                        <div class="roster-empty">Loading directory...</div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <!-- ==========================================
         Admin Confirmation Modal
         ========================================== -->
    <div class="modal-overlay" id="adminModalOverlay" role="dialog" aria-modal="true">
        <div class="modal-box">
            <h3 class="modal-title" id="adminModalTitle">Confirm Action</h3>
            <div class="modal-body" id="adminModalBody">Are you sure?</div>
            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-cancel" id="adminModalCancelBtn">Cancel</button>
                <button type="button" class="modal-btn modal-btn-confirm" id="adminModalConfirmBtn">Confirm</button>
            </div>
        </div>
    </div>

    <!-- ==========================================
         Export WhatsApp Squad Modal
         ========================================== -->
    <div class="modal-overlay" id="exportModalOverlay" role="dialog" aria-modal="true">
        <div class="modal-box" style="max-width:550px;">
            <div id="waModalIcon" style="margin-bottom:0.75rem;"></div>
            <h3 class="modal-title">Share Squads on WhatsApp</h3>
            <p style="color:var(--text-secondary); font-size:0.85rem; margin-bottom:1rem;">
                Formatted squad lineup ready to paste directly into your match group.
            </p>
            <div class="form-group">
                <textarea id="exportTextArea" class="form-textarea" rows="12" style="font-family:'JetBrains Mono', monospace; font-size:0.82rem; line-height:1.45; resize:vertical;"></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-cancel" id="closeExportBtn">Close</button>
                <button type="button" class="modal-btn modal-btn-confirm btn-whatsapp" id="copyExportBtn" style="background:var(--color-whatsapp); color:#000; display:inline-flex; align-items:center; gap:0.4rem;">
                    <span id="copyWaBtnIcon"></span> Copy to Clipboard
                </button>
            </div>
        </div>
    </div>

    <!-- ==========================================
         Edit Team Names Modal
         ========================================== -->
    <div class="modal-overlay" id="editTeamNamesModalOverlay" role="dialog" aria-modal="true">
        <div class="modal-box" style="max-width:440px;">
            <h3 class="modal-title">Customize Team Names</h3>
            <p style="color:var(--text-secondary); font-size:0.85rem; margin-bottom:1rem;">
                Set custom names for all squads. Changes apply immediately to player passes and live scoreboards.
            </p>
            <form id="editTeamNamesForm" style="text-align:left;">
                <div class="form-group">
                    <label for="teamNameInput1" class="form-label">Team 1 Name</label>
                    <input type="text" id="teamNameInput1" class="form-input" required autocomplete="off" placeholder="e.g. Runda FC">
                </div>
                <div class="form-group">
                    <label for="teamNameInput2" class="form-label">Team 2 Name</label>
                    <input type="text" id="teamNameInput2" class="form-input" required autocomplete="off" placeholder="e.g. Makongeni FC">
                </div>
                <div class="form-group" id="team3NameGroup">
                    <label for="teamNameInput3" class="form-label">Team 3 Name</label>
                    <input type="text" id="teamNameInput3" class="form-input" autocomplete="off" placeholder="e.g. Parklands FC">
                </div>
                <div class="modal-actions" style="margin-top:1.5rem;">
                    <button type="button" class="modal-btn modal-btn-cancel" id="closeEditTeamNamesBtn">Cancel</button>
                    <button type="submit" class="modal-btn modal-btn-confirm">Save Team Names</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==========================================
         Edit Player Modal
         ========================================== -->
    <div class="modal-overlay" id="editModalOverlay" role="dialog" aria-modal="true">
        <div class="modal-box" style="max-width:440px;">
            <h3 class="modal-title">Edit Player</h3>
            <form id="editPlayerForm" style="margin-top:1rem; text-align:left;">
                <input type="hidden" id="editPlayerId">
                <div class="form-group">
                    <label for="editPlayerName" class="form-label">Full Name</label>
                    <input type="text" id="editPlayerName" class="form-input" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="editPlayerTier" class="form-label">Skill Tier</label>
                    <select id="editPlayerTier" class="form-select" required>
                        <option value="skilled">Skilled</option>
                        <option value="regular">Regular</option>
                    </select>
                </div>
                <div class="modal-actions" style="margin-top:1.5rem;">
                    <button type="button" class="modal-btn modal-btn-cancel" id="closeEditBtn">Cancel</button>
                    <button type="submit" class="modal-btn modal-btn-confirm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==========================================
         Record Goal Action Sheet / Modal
         ========================================== -->
    <div class="modal-overlay" id="recordGoalModalOverlay" role="dialog" aria-modal="true">
        <div class="modal-box" style="max-width:440px;">
            <div id="goalModalIcon" style="margin-bottom:0.75rem;"></div>
            <h3 class="modal-title" id="goalModalPlayerName">Record Goal</h3>
            <p style="color:var(--text-secondary); font-size:0.9rem; margin-bottom:1.5rem;">
                Player registered with <strong id="goalModalTeamName" style="color:var(--grass-light);">Team</strong>. Select goal type below:
            </p>
            <div style="display:flex; flex-direction:column; gap:0.75rem;">
                <button type="button" class="btn btn-primary" id="btnRecordNormalGoal" style="width:100%; min-height:52px; justify-content:center; font-size:1rem; display:inline-flex; align-items:center; gap:0.5rem;">
                    <span id="recordGoalNormalIcon"></span> Goal (+1 for Scorer Team)
                </button>
                <button type="button" class="btn btn-danger" id="btnRecordOwnGoal" style="width:100%; min-height:52px; justify-content:center; font-size:1rem; background:#7f1d1d; border-color:#ef4444; color:#fca5a5; display:inline-flex; align-items:center; gap:0.5rem;">
                    <span id="recordGoalOgIcon"></span> Own Goal (OG) (+1 for Opponent)
                </button>
                <button type="button" class="btn btn-outline" id="btnCancelRecordGoal" style="width:100%; min-height:44px; justify-content:center;">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- ==========================================
         Edit Goal Minute Modal
         ========================================== -->
    <div class="modal-overlay" id="editGoalModalOverlay" role="dialog" aria-modal="true">
        <div class="modal-box" style="max-width:400px;">
            <div id="editGoalTimeIcon" style="margin-bottom:0.75rem;"></div>
            <h3 class="modal-title" id="editGoalPlayerTitle">Edit Goal Time</h3>
            <div style="margin-top:1rem; text-align:left;">
                <input type="hidden" id="editGoalIdInput">
                <div class="form-group">
                    <label for="editGoalMinuteInput" class="form-label">Match Minute</label>
                    <input type="number" id="editGoalMinuteInput" class="form-input" min="0" max="150" required placeholder="e.g. 23">
                </div>
                <div class="form-group">
                    <label for="editGoalSecondInput" class="form-label">Match Second (0-59)</label>
                    <input type="number" id="editGoalSecondInput" class="form-input" min="0" max="59" value="0" placeholder="e.g. 15">
                </div>
                <div class="modal-actions" style="margin-top:1.5rem;">
                    <button type="button" class="modal-btn modal-btn-cancel" id="btnCancelEditGoal">Cancel</button>
                    <button type="button" class="modal-btn modal-btn-confirm" id="btnSaveEditGoal">Save Time</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ==========================================
         Manual Key-In Score / Goal Modal
         ========================================== -->
    <div class="modal-overlay" id="keyInScoreModalOverlay" role="dialog" aria-modal="true">
        <div class="modal-box" style="max-width:440px;">
            <div id="keyInScoreModalIcon" style="margin-bottom:0.75rem;"></div>
            <h3 class="modal-title">Key in Score / Goal</h3>
            <p style="color:var(--text-secondary); font-size:0.9rem; margin-bottom:1.25rem;">
                Manually record a goal for either squad at any minute.
            </p>
            <form id="keyInScoreForm" style="text-align:left;">
                <!-- Team Selection Buttons (Rendered dynamically for 2 or 3 teams) -->
                <div class="form-group">
                    <label class="form-label">Team Credited With Goal</label>
                    <div id="keyInTeamButtonsContainer" style="display:flex; flex-wrap:wrap; gap:0.5rem;">
                        <!-- Populated dynamically via admin_match.js -->
                    </div>
                </div>

                <!-- Goal Scorer Dropdown -->
                <div class="form-group">
                    <label for="keyInPlayerSelect" class="form-label">Goal Scorer (Player)</label>
                    <select id="keyInPlayerSelect" class="form-select" required>
                        <!-- Populated dynamically -->
                    </select>
                </div>

                <!-- Goal Type Choice -->
                <div class="form-group">
                    <label class="form-label">Goal Type</label>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                        <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.85rem; cursor:pointer; background:rgba(255,255,255,0.03); padding:0.6rem; border-radius:var(--radius-sm); border:1px solid var(--border-pitch);">
                            <input type="radio" name="keyInGoalType" value="normal" checked>
                            <span style="font-weight:600;">Goal (Scorer Team)</span>
                        </label>
                        <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.85rem; cursor:pointer; background:rgba(255,255,255,0.03); padding:0.6rem; border-radius:var(--radius-sm); border:1px solid var(--border-pitch);">
                            <input type="radio" name="keyInGoalType" value="og">
                            <span style="font-weight:600; color:#fca5a5;">Own Goal (OG)</span>
                        </label>
                    </div>
                </div>

                <!-- Minute & Second -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                    <div class="form-group">
                        <label for="keyInMinuteInput" class="form-label">Match Minute</label>
                        <input type="number" id="keyInMinuteInput" class="form-input" min="0" max="150" value="0" required placeholder="e.g. 23">
                    </div>
                    <div class="form-group">
                        <label for="keyInSecondInput" class="form-label">Second (0-59)</label>
                        <input type="number" id="keyInSecondInput" class="form-input" min="0" max="59" value="0" placeholder="0">
                    </div>
                </div>

                <div class="modal-actions" style="margin-top:1.5rem;">
                    <button type="button" class="modal-btn modal-btn-cancel" id="btnCancelKeyInScore">Cancel</button>
                    <button type="submit" class="modal-btn modal-btn-confirm" id="btnSubmitKeyInScore" style="background:var(--grass-light); color:#000; font-weight:800; display:inline-flex; align-items:center; justify-content:center; gap:0.4rem;">
                        <span id="saveGoalBtnIcon"></span> Save Goal
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Static Icons Initialization Script -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (window.TurfIcons) {
                const setIcon = (id, iconName, opts = {}) => {
                    const el = document.getElementById(id);
                    if (el) el.innerHTML = TurfIcons.icon(iconName, opts);
                };

                setIcon('loginShieldBox', 'shield', { width: 48, height: 48, class: 'text-brand' });
                setIcon('adminNavLogo', 'ball', { width: 22, height: 22 });
                setIcon('lockBtnIcon', 'lock', { width: 14, height: 14 });
                setIcon('homeBtnIcon', 'share', { width: 14, height: 14 });
                setIcon('copyLinksIcon', 'copy', { width: 16, height: 16 });
                setIcon('exportWaIcon', 'share', { width: 16, height: 16 });
                setIcon('resetPicksIcon', 'alert', { width: 16, height: 16 });
                setIcon('shareUrlIcon', 'share', { width: 15, height: 15 });
                setIcon('squadsTitleIcon', 'users', { width: 18, height: 18 });
                setIcon('pendingTitleIcon', 'clock', { width: 18, height: 18 });
                setIcon('addPlayerTitleIcon', 'plus', { width: 18, height: 18 });
                setIcon('dirTitleIcon', 'users', { width: 18, height: 18 });
                setIcon('waModalIcon', 'share', { width: 36, height: 36 });
                setIcon('copyWaBtnIcon', 'copy', { width: 16, height: 16 });
                setIcon('goalModalIcon', 'ball', { width: 36, height: 36, class: 'text-brand' });
                setIcon('recordGoalNormalIcon', 'ball', { width: 18, height: 18 });
                setIcon('recordGoalOgIcon', 'alert', { width: 18, height: 18 });
                setIcon('editGoalTimeIcon', 'clock', { width: 32, height: 32, class: 'text-brand' });
                setIcon('keyInScoreModalIcon', 'ball', { width: 36, height: 36, class: 'text-brand' });
                setIcon('saveGoalBtnIcon', 'ball', { width: 16, height: 16 });
            }
        });
    </script>

    <!-- Admin Application Scripts -->
    <script src="../assets/js/admin.js"></script>
    <script src="../assets/js/admin_match.js"></script>
</body>
</html>
