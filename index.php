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
    <meta name="theme-color" content="#06140d">
    <title>Turf Teams - Weekend Football</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="pitch-overlay"></div>

    <div class="container" style="max-width:540px; padding-top:2.5rem;">
        <header class="app-header">
            <div class="brand-badge">⚽ Saturday Football Match</div>
            <h1 class="app-title">TURF TEAMS</h1>
            <p class="app-subtitle">Balanced Squads • 18 Players • Fair Play</p>
        </header>

        <div class="admin-card" style="text-align:center; padding:2rem 1.5rem; margin-top:1rem;">
            <div style="font-size:3rem; margin-bottom:1rem;">🏟️</div>
            <h2 style="font-size:1.3rem; margin-bottom:0.75rem; color:var(--text-primary);">Player Pass Required</h2>
            <p style="color:var(--text-secondary); font-size:0.95rem; line-height:1.6; margin-bottom:1.5rem;">
                Each player receives a unique personal link on WhatsApp to choose between 
                <strong style="color:var(--runda-crest);">Runda FC</strong> and 
                <strong style="color:var(--mako-crest);">Makongeni FC</strong>.
            </p>

            <!-- Quick Token Jump Form -->
            <form action="pick.php" method="GET" style="margin-bottom:1.75rem; text-align:left;">
                <label for="tokenInput" class="form-label">Have your personal player token?</label>
                <div style="display:flex; gap:0.5rem;">
                    <input type="text" id="tokenInput" name="t" class="form-input" placeholder="e.g. a1b2c3d4..." required>
                    <button type="submit" class="btn btn-primary" style="white-space:nowrap;">Enter Match</button>
                </div>
            </form>

            <hr style="border:0; border-top:1px solid var(--border-pitch); margin:1.5rem 0;">

            <div style="display:flex; flex-direction:column; gap:0.75rem;">
                <a href="admin/" class="btn btn-outline" style="width:100%; justify-content:center;">
                    🛡️ Admin / Organizer Portal
                </a>
            </div>
        </div>

        <div style="text-align:center; margin-top:2rem; color:var(--text-dim); font-size:0.8rem;">
            Turf Teams • Powered by PHP & MySQL
        </div>
    </div>
</body>
</html>
