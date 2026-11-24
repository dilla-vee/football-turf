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
    <meta name="theme-color" content="#06140d">
    <title>Turf Teams - Match Admin Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
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
            gap: 0.75rem;
        }
        .admin-nav-right {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
    </style>
</head>
<body>
    <div class="pitch-overlay"></div>

    <!-- Toast Notifications Container -->
    <div class="toast-container" id="toastContainer" aria-live="polite"></div>

    <div class="container container-wide">
        <!-- ==========================================
             1. Admin Login View (When Not Authenticated)
             ========================================== -->
        <div id="loginView" style="<?php echo $isLoggedIn ? 'display:none;' : 'display:block;'; ?>">
            <div class="error-screen" style="max-width:440px; margin-top:4rem;">
                <div style="font-size:3rem; margin-bottom:1rem;">🛡️</div>
                <h2 style="font-size:1.5rem; color:var(--text-primary); margin-bottom:0.5rem;">Match Organizer Login</h2>
                <p style="color:var(--text-secondary); font-size:0.9rem; margin-bottom:1.5rem;">
                    Sign in to manage players, view live team rosters, share WhatsApp match passes, and balance squads.
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
                    <div style="font-size:1.75rem;">⚽</div>
                    <div>
                        <h1 style="font-size:1.4rem; font-weight:800; color:var(--text-primary); margin:0; line-height:1.2;">
                            TURF TEAMS ADMIN
                        </h1>
                        <span style="font-size:0.75rem; color:var(--text-secondary);">Weekend Football Match Control</span>
                    </div>
                </div>
                <div class="admin-nav-right">
                    <span id="lockBadge" class="tier-badge tier-regular">🔓 OPEN</span>
                    <button type="button" id="lockToggleBtn" class="btn btn-warning btn-sm">🔒 Lock Picking</button>
                    <a href="../" class="btn btn-outline btn-sm" target="_blank">🌐 Home Page</a>
                    <button type="button" id="logoutBtn" class="btn btn-outline btn-sm">Sign Out</button>
                </div>
            </nav>

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
                <button type="button" id="copyAllLinksBtn" class="btn btn-primary">
                    📋 Copy All Player Links
                </button>
                <button type="button" id="exportWhatsAppBtn" class="btn btn-whatsapp">
                    💬 Export Squad to WhatsApp
                </button>
                <button type="button" id="resetAllBtn" class="btn btn-outline" style="border-color:#ef4444; color:#f87171;">
                    ⚠️ Reset All Picks
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
                    <label for="baseUrlInput" style="font-size:0.85rem; font-weight:700; color:var(--text-secondary); white-space:nowrap;">
                        🔗 Shareable Base URL:
                    </label>
                    <input type="text" id="baseUrlInput" class="form-input" style="flex:1; min-width:260px; padding:0.45rem 0.75rem; font-size:0.85rem;" placeholder="e.g. http://192.168.1.50/turf-teams or ngrok url">
                    <button type="button" id="saveBaseUrlBtn" class="btn btn-outline btn-sm">Save URL</button>
                    <span style="font-size:0.75rem; color:var(--text-dim);">
                        (Change this if testing from phone via Wi-Fi IP or Ngrok)
                    </span>
                </div>
            </section>

            <!-- Live Match Board (Side-by-side Runda FC vs Makongeni FC) -->
            <section style="margin-bottom:2rem;">
                <h2 style="font-size:1.25rem; font-weight:800; margin-bottom:1rem; display:flex; align-items:center; gap:0.5rem;">
                    <span>⚔️</span> Live Match Squads (Side-by-Side)
                </h2>
                <div class="admin-grid">
                    <!-- Runda FC Board -->
                    <div class="admin-card" id="boardRunda" style="border-top:4px solid var(--runda-primary);">
                        <div style="text-align:center; padding:1.5rem; color:var(--text-dim);">Loading Runda FC...</div>
                    </div>

                    <!-- Makongeni FC Board -->
                    <div class="admin-card" id="boardMakongeni" style="border-top:4px solid var(--mako-primary);">
                        <div style="text-align:center; padding:1.5rem; color:var(--text-dim);">Loading Makongeni FC...</div>
                    </div>
                </div>
            </section>

            <!-- Pending Players (Unassigned) -->
            <section class="admin-card" style="margin-bottom:2rem;">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">
                        <span>⏳</span> Pending Selection 
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
                        <h3 class="admin-card-title"><span>➕</span> Add New Player</h3>
                    </div>
                    <form id="addPlayerForm">
                        <div class="form-group">
                            <label for="newPlayerName" class="form-label">Player Full Name</label>
                            <input type="text" id="newPlayerName" class="form-input" placeholder="e.g. Dennis Oliech" required>
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
                        <h3 class="admin-card-title"><span>👥</span> Player Directory & Match Passes</h3>
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
            <div style="font-size:2.5rem; margin-bottom:0.5rem;">💬</div>
            <h3 class="modal-title">Share Squads on WhatsApp</h3>
            <p style="color:var(--text-secondary); font-size:0.85rem; margin-bottom:1rem;">
                Formatted with clean markdown and emojis. Ready to paste directly into your match group!
            </p>
            <div class="form-group">
                <textarea id="exportTextArea" class="form-textarea" rows="12" style="font-family:monospace; font-size:0.85rem; line-height:1.4; resize:vertical;"></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-cancel" id="closeExportBtn">Close</button>
                <button type="button" class="modal-btn modal-btn-confirm btn-whatsapp" id="copyExportBtn" style="background:var(--color-whatsapp); color:#000;">
                    📋 Copy to Clipboard
                </button>
            </div>
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
                    <input type="text" id="editPlayerName" class="form-input" required>
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

    <!-- Admin Application Script -->
    <script src="../assets/js/admin.js"></script>
</body>
</html>
