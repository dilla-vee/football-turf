<?php
/**
 * Turf Teams - Index / Welcome Page
 */

require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#080c14">
    <title>Turf Teams - Matchday Selection & Live Scoreboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/icons.js"></script>
</head>
<body>
    <div class="container" style="max-width:540px; padding-top:2.5rem;">
        <header class="app-header">
            <div class="brand-badge"><span class="badge-dot"></span> MATCHDAY SQUAD SELECTION</div>
            <h1 class="app-title">TURF TEAMS</h1>
            <p class="app-subtitle">Balanced Squads • 18 Players • Live Match Scoreboard</p>
        </header>

        <div class="admin-card" style="text-align:center; padding:2rem 1.5rem; margin-top:1rem;">
            <div id="indexBadgeContainer" style="margin-bottom:1.25rem;"></div>
            <h2 style="font-size:1.3rem; margin-bottom:0.75rem; color:var(--text-primary); letter-spacing:-0.02em;">Player Pass Required</h2>
            <p style="color:var(--text-secondary); font-size:0.95rem; line-height:1.6; margin-bottom:1.5rem;">
                Each player receives a unique personal link on WhatsApp to choose between 
                <strong style="color:var(--runda-crest);">Runda FC</strong> and 
                <strong style="color:var(--mako-crest);">Makongeni FC</strong>.
            </p>

            <!-- Quick Token Jump Form -->
            <form action="pick.php" method="GET" style="margin-bottom:1.75rem; text-align:left;">
                <label for="tokenInput" class="form-label">Have your personal player pass?</label>
                <div style="display:flex; gap:0.5rem;">
                    <input type="text" id="tokenInput" name="t" class="form-input" placeholder="e.g. a1b2c3d4..." required autocomplete="off" spellcheck="false">
                    <button type="submit" class="btn btn-primary" style="white-space:nowrap;">Enter Match</button>
                </div>
            </form>

            <hr style="border:0; border-top:1px solid var(--border-pitch); margin:1.5rem 0;">

            <div style="display:flex; flex-direction:column; gap:0.75rem;">
                <a href="admin/" class="btn btn-outline" style="width:100%; justify-content:center; gap:0.5rem;">
                    <span id="adminPortalIcon"></span> Match Organizer Portal
                </a>
            </div>
        </div>

        <div style="text-align:center; margin-top:2rem; color:var(--text-dim); font-size:0.8rem; letter-spacing:0.02em;">
            Turf Teams • Broadcast Scoreboard & Squad Balance Engine
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (window.TurfIcons) {
                const badgeEl = document.getElementById('indexBadgeContainer');
                if (badgeEl) {
                    badgeEl.innerHTML = TurfIcons.icon('trophy', { class: 'text-brand', width: 44, height: 44, strokeWidth: 1.5 });
                }
                const adminIconEl = document.getElementById('adminPortalIcon');
                if (adminIconEl) {
                    adminIconEl.innerHTML = TurfIcons.icon('shield', { width: 18, height: 18 });
                }
            }
        });
    </script>
</body>
</html>
