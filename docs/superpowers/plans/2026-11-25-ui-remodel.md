# Turf Teams UI Remodeling & "AI Slop" Removal Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transform the Turf Teams interface from an emoji-cluttered, muddy AI-generated prototype into a sleek, broadcast-grade football match center (inspired by FotMob, Premier League, and Sky Sports), eliminating all tacky emojis, pseudo-pitch gradients, and cluttered layouts while preserving 100% of the underlying business logic, rival balancing, and live match state.

**Architecture:** 
1. Re-architect design tokens in `assets/css/style.css` with a refined obsidian/carbon dark palette, sharp contrasting club crests (Emerald for Runda FC, Royal Cobalt for Makongeni FC), tabular athletic typography, and custom SVG iconography.
2. Overhaul `index.php`, `pick.php`, and `admin/index.php` HTML structure: purge all emojis, integrate inline semantic SVGs, and establish clean visual hierarchy.
3. Overhaul `assets/js/admin_match.js`, `assets/js/match.js`, and `assets/js/admin.js` to render polished broadcast scoreboards, live status indicators, interactive tap-to-score squad cards, and intuitive modal sheets.
4. Verify all automated test suites (`test_match.php`, `test_rivals.php`, `test_suite.php`) pass with zero regressions.

**Tech Stack:** Vanilla PHP 8.2, CSS3 Design Tokens, Vanilla JS (ES6+), Inline SVG Icons, MySQL / PostgreSQL (Supabase/Render).

---

## Global Constraints
- Preserve all existing database schemas, session handlers, and API endpoints (`api.php`, `admin/api.php`, `api/match_state.php`).
- Zero dependencies on external runtime icon fonts or heavy libraries: use lightweight, self-contained SVG icons.
- Ensure strict mobile responsiveness (375px to 1440px) with minimum 44px touch targets for thumbs on the turf.
- Eliminate all emojis used as icons across headers, buttons, cards, and modal dialogs.
- Dual-compatibility: Must work locally on XAMPP and in cloud deployment on Render + Supabase.

---

## Tasks

### Task 1: Design Tokens & Modern Sports UI System (`assets/css/style.css`)
- [ ] Define obsidian carbon color palette (`--bg-canvas: #080c14`, `--bg-card: #0f172a`, `--bg-card-hover: #1e293b`).
- [ ] Refine team identity palettes (`--runda-primary: #10b981`, `--runda-dark: #064e3b`, `--mako-primary: #3b82f6`, `--mako-dark: #1e3a8a`).
- [ ] Remove cheesy pitch stripe overlay background; replace with subtle deep-gradient broadcast stadium glow.
- [ ] Add athletic tabular scoreboard typography styles, live pulsing broadcast chip, and clean pill badges.
- [ ] Style pro squad roster cards, player tap cards with smooth hover/active states, and sleek modal action sheets.

### Task 2: Remodel Player Experience (`index.php` & `pick.php`)
- [ ] Update `index.php`: Match pass welcome portal, replace emojis with clean SVGs, streamline token input and organizer portal link.
- [ ] Update `pick.php`: Re-model player identification card, rival matchup notice, squad balance indicators, and team selection cards.
- [ ] Update `assets/js/match.js`: Render live scoreboard with broadcast match-clock, tabular score digits, clean goal feed, and celebration flash.

### Task 3: Remodel Admin Live Match Center & Dashboard (`admin/index.php`)
- [ ] Modernize Admin Navigation Bar: Clean organizer crest, match status pill, streamlined action buttons (Lock, WhatsApp export, Logout).
- [ ] Re-model Top Stats Bar: Clean compact stat tiles with progress indicators instead of bloated generic boxes.
- [ ] Re-model Live Match Section: Broadcast scoreboard with prominent Start/End buttons and quick "+ Record Goal" triggers.
- [ ] Re-model Side-by-side Squad Rosters: Player jersey numbers, player role chips (Skilled vs Squad), and tap-to-score buttons.
- [ ] Re-model Goal Log: Chronological match event feed with player name, credited team badge, and action buttons (Edit minute, Delete).
- [ ] Re-model Modals (Goal sheet, Manual minute entry, Player edit) with clean modern layout.

### Task 4: Client Logic Refinement (`assets/js/admin_match.js` & `assets/js/admin.js`)
- [ ] Remove all hardcoded emojis from dynamic HTML templates in JS.
- [ ] Ensure SVG icons are used for goals, cards, whistles, and status badges.
- [ ] Connect quick goal entry and early start confirmation seamlessly.

### Task 5: Testing, Local Sync, and Production Push
- [ ] Run full automated test suites (`test_match.php`, `test_rivals.php`, `test_suite.php`).
- [ ] Sync updated files to `C:\xampp\htdocs\turf-teams\`.
- [ ] Git commit and push to `main` for automatic Render deployment.
- [ ] Verify live endpoints on Render (`ping.php`, `admin/`, `api/match_state.php`).
