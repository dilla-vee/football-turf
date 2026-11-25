<?php
/**
 * Turf Teams - Player Selection Page
 * Personalized player team picker identified by URL token.
 */

require_once __DIR__ . '/config.php';

$token = trim($_GET['t'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#080c14">
    <title>Turf Teams - Squad Selection</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/icons.js"></script>
</head>
<body>
    <!-- Toast Notification Container -->
    <div class="toast-container" id="toastContainer" aria-live="polite"></div>

    <div class="container">
        <!-- App Header -->
        <header class="app-header">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
                <div class="brand-badge"><span class="badge-dot"></span> MATCHDAY SQUAD SELECTION</div>
                <button type="button" class="theme-toggle-btn" onclick="TurfTheme.toggle()" aria-label="Toggle theme"></button>
            </div>
            <h1 class="app-title">TURF TEAMS</h1>
            <p class="app-subtitle">Balanced Squads • Fair Competition</p>
        </header>

        <!-- Initial Loading State -->
        <div id="loadingView" style="text-align:center; padding:3rem 1rem;">
            <div class="poll-dot" style="width:16px; height:16px; margin:0 auto 1rem; display:block;"></div>
            <p style="color:var(--text-secondary); font-size:0.95rem;">Checking your match pass...</p>
        </div>

        <!-- Friendly Error State (Invalid Token) -->
        <div id="errorView" class="error-screen" style="display:none;">
            <div class="error-icon" id="errorIconBox"></div>
            <h2 class="error-title" id="errorTitle">Match Pass Invalid</h2>
            <p class="error-desc" id="errorDesc">
                This personal link is invalid, expired, or was altered. Please check the WhatsApp link sent by the match organizer.
            </p>
            <a href="index.php" class="btn btn-outline" style="margin-top:1rem;">Back to Home</a>
        </div>

        <!-- Main Player App View -->
        <main id="appView" style="display:none;">
            <!-- Live Match Scoreboard & Goal Feed Container -->
            <div id="matchScoreboardContainer"></div>

            <!-- Player Identification Card -->
            <section class="player-welcome-card">
                <span class="welcome-label">Player Pass</span>
                <div class="player-name-display" id="playerNameDisplay">Loading...</div>
                <div class="welcome-instruction">Choose your squad below. Each team must be balanced in skill.</div>
            </section>

            <!-- Global Picking Locked Alert -->
            <div class="picking-closed-alert" id="pickingClosedAlert" style="display:none;">
                <span class="closed-alert-icon" id="closedAlertIcon"></span>
                <span>Team selection is currently closed by the match organizer.</span>
            </div>

            <!-- Locked Confirmation State (Shows when player has already picked) -->
            <section class="locked-status-banner" id="lockedBanner" style="display:none;">
                <div class="locked-icon" id="lockedIconBox"></div>
                <div class="locked-details">
                    <div class="locked-tagline">Squad Locked</div>
                    <div class="locked-team-name" id="lockedTeamName">Team Name</div>
                    <div class="locked-subtext" id="lockedSubtext">Your choice is confirmed. Only the admin can reset your pick.</div>
                </div>
            </section>

            <!-- Teams Selection Grid -->
            <section class="teams-grid" id="teamsGrid">
                <!-- Injected dynamically via assets/js/app.js -->
            </section>

            <!-- Live Auto-refresh Indicator -->
            <div class="polling-status" id="pollIndicator">
                <span class="poll-dot"></span>
                <span>Live squad sync active • Auto-refreshes every 5s</span>
            </div>

            <!-- Ballon d'Or & Match History Hall of Fame -->
            <section style="margin-top:2rem; border-top:1px solid var(--border-pitch); padding-top:1.5rem;">
                <details style="cursor:pointer;" id="playerHofDetails">
                    <summary style="font-weight:800; font-size:1.05rem; display:flex; align-items:center; gap:0.5rem; color:var(--text-primary); outline:none; user-select:none;">
                        <span id="playerHofIcon"></span> Ballon d'Or & Match History Hall of Fame
                    </summary>
                    <div style="margin-top:1.25rem;">
                        <div id="hallOfFamePodiumContainer"></div>
                        <div id="archivedMatchesContainer" style="margin-top:1rem;"></div>
                    </div>
                </details>
            </section>
        </main>
    </div>

    <!-- Confirmation Modal Dialog -->
    <div class="modal-overlay" id="modalOverlay" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-box">
            <div class="modal-icon" id="modalIconBox"></div>
            <h3 class="modal-title" id="modalTitle">Confirm Selection</h3>
            <div class="modal-body" id="modalBody">
                Are you sure you want to lock in your squad?
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-cancel" id="modalBtnCancel">Cancel</button>
                <button type="button" class="modal-btn modal-btn-confirm" id="modalBtnConfirm">Confirm Squad</button>
            </div>
        </div>
    </div>

    <!-- Application Scripts -->
    <script src="assets/js/match.js"></script>
    <script src="assets/js/app.js"></script>
    <script src="assets/js/history.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (window.TurfIcons) {
                const errBox = document.getElementById('errorIconBox');
                if (errBox) errBox.innerHTML = TurfIcons.icon('alert', { width: 48, height: 48 });
                const modalBox = document.getElementById('modalIconBox');
                if (modalBox) modalBox.innerHTML = TurfIcons.icon('shield', { width: 48, height: 48, class: 'text-brand' });
                const alertClosedBox = document.getElementById('closedAlertIcon');
                if (alertClosedBox) alertClosedBox.innerHTML = TurfIcons.icon('lock', { width: 18, height: 18 });
                const hofIconBox = document.getElementById('playerHofIcon');
                if (hofIconBox) hofIconBox.innerHTML = TurfIcons.icon('trophy', { width: 18, height: 18 });
            }
        });
    </script>
</body>
</html>
