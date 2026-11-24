# ⚽ Turf Teams - Mobile-First Team Selection Web App

**Turf Teams** is a mobile-first web app designed for 18 friends picking sides for a weekend football match so the two teams (**Runda FC** and **Makongeni FC**) are automatically balanced in skill.

Built with **PHP (no framework)**, **MySQL (InnoDB with row-level locking)**, and **vanilla HTML/CSS/JavaScript**, designed to run on **XAMPP** (`htdocs/turf-teams`).

---

## 🏆 Key Features & Rules

1. **Two Teams**:
   - **Runda FC** (Emerald Pitch Green theme)
   - **Makongeni FC** (Electric Royal Blue theme)
2. **Skill Balancing & Hard Caps**:
   - 18 players total: **8 Skilled** and **10 Regular**.
   - Each team ends up with **4 Skilled + 5 Regular** players (9 players per team).
   - Hard caps are strictly enforced: once a team reaches 4 Skilled players, Skilled players can no longer choose it; once a team reaches 5 Regular players, Regular players can no longer choose it.
   - If one team's slots are full for a player's tier, only the remaining team is selectable and the UI auto-highlights their single remaining option.
3. **Player Privacy**:
   - Players **never** see anyone's Skilled/Regular tier.
   - Players only see remaining slot counts relevant to their own tier (e.g., *"3 spots left for you"*). Tier badges are admin-only.
4. **Single-Choice Permanence**:
   - Each player gets a personalized link containing a random secure token (e.g. `/pick.php?t=8f3a9c...`).
   - No logins or passwords for players.
   - Choices are locked permanently once made ("*You are on Runda FC*"). Only the admin can reset a pick.
5. **Race-Condition Safe**:
   - Uses an ACID MySQL transaction with InnoDB row-level locking (`SELECT ... FOR UPDATE` on both team and player rows) so two players picking at the exact same millisecond can never exceed the tier caps.
6. **5-Second Real-Time Sync**:
   - Both the player screen and the admin dashboard poll every 5 seconds to update team rosters live without page reloads.
7. **Live Match Scoreboard & Goal Tracking**:
   - **3 Match States**: `setup` (picking & pre-game) -> `live` (match running) -> `finished` (full-time results).
   - **18-Player Start Guard**: "Start Match" is disabled both on the client and enforced on the server until all 18 players have joined a team.
   - **Auto-Lock on Kickoff**: Starting the match automatically locks player picking to prevent mid-game team swaps.
   - **Server-Time Synced Clock**: Clock calculates elapsed time directly from the server timestamp, keeping all phones in sync down to the second.
   - **Tap-to-Record Goals (Admin)**: Tap any player card on the live admin screen to log a Goal (+1 for their team) or Own Goal (+1 for opponent, marked `(OG)`).
   - **Undo, Edit & Delete**: Easily undo the last goal, edit a goal's minute/second, or delete an accidental entry with dynamic score recalculation.
   - **Live Goal Feed & Flash Animation**: Updates every 3s on player pages (`pick.php`) with score pulse animation when a goal is scored.
   - **Full-Time Results & WhatsApp Export**: Championship result banner, 2-column scorer breakdown, Golden Boot top scorer highlight, and one-click WhatsApp summary formatting.
   - **Reset Match vs Reset Everything**: Admin can reset the match scoreboard for a new game while keeping player squads, or reset everything.
8. **Organizer Admin Dashboard (`admin/`)**:
   - Password-protected with bcrypt hash (`turf2026`).
   - Add, edit, and delete players with live counters: *"Skilled 8/8, Regular 10/10, Total 18/18"*.
   - One-click **"Copy Link"** and **"Share on WhatsApp"** button (prefilled: `Hey [name], pick your side for Saturday: [link]`).
   - **"Copy All Player Links"** button.
   - Live side-by-side match boards with tier badges.
   - Controls: reset individual player picks, reset all picks, and a **"Lock Picking"** toggle.
   - **"Export Squad to WhatsApp"** clean formatted markdown block ready for group posting.

---

## 📁 Architecture ("One File Per Concern")

```text
turf-teams/
├── config.php                  # Database credentials, rival pairs, admin hash, team cap constants
├── db.php                      # PDO singleton connection helper with auto-table sync & UTF8mb4
├── schema.sql                  # Complete database schema + seed data (teams, players, match_state, goals)
├── migration.sql               # Incremental migration SQL for phpMyAdmin (match_state & goals tables)
├── index.php                   # Welcome page with player token jump & admin link
├── pick.php                    # Mobile-first player selection & live scoreboard page
├── api.php                     # Public player API (live status polling & race-safe atomic pick)
├── api/
│   └── match_state.php         # Dedicated match API (public state polling & admin match lifecycle/goals)
├── admin/
│   ├── index.php               # Admin dashboard & live match control center
│   └── api.php                 # Admin API (auth, CRUD, reset, lock toggle, custom base URL)
├── assets/
│   ├── css/
│   │   └── style.css           # Pitch-green dark theme, live clock, score flash, tap cards, modals
│   └── js/
│       ├── app.js              # Player frontend (squad picking, modal, rival rules)
│       ├── match.js            # Live scoreboard client module (3s polling, clock sync, goal feed)
│       ├── admin.js            # Admin dashboard frontend (roster management, WhatsApp export)
│       └── admin_match.js      # Admin live match manager (tap-to-record goals, undo, edit, lifecycle)
├── tests/
│   ├── test_suite.php          # 27 automated integration tests for caps, privacy, and CRUD
│   ├── test_concurrency.php    # Concurrency test simulating simultaneous row-locked picks
│   ├── test_rivals.php         # 17 tests validating head-to-head skilled rival matchups
│   └── test_match.php          # 48 tests validating live match lifecycle, timer, goals & reset
└── README.md                   # Setup guide and instructions
```

---

## 🚀 Setup on XAMPP

### Step 1: Start XAMPP Services
1. Open the **XAMPP Control Panel**.
2. Click **Start** for **Apache** and **MySQL**.

### Step 2: Database Setup (Fresh Install OR Migration)

#### Option A: Fresh Database Install
1. Open your browser and go to **[http://localhost/phpmyadmin](http://localhost/phpmyadmin)**.
2. Click on the **Import** tab at the top.
3. Click **Choose File** and select `schema.sql` located inside `C:\xampp\htdocs\turf-teams\schema.sql`.
4. Click **Import** (or **Go**) at the bottom.

#### Option B: Upgrading an Existing Database (Migration for phpMyAdmin)
If you already have `turf_teams` running and want to add the scoreboard without losing existing players or picks:
1. Open **[http://localhost/phpmyadmin](http://localhost/phpmyadmin)** and click on the **turf_teams** database.
2. Click on the **Import** tab.
3. Choose `migration.sql` from `C:\xampp\htdocs\turf-teams\migration.sql`.
4. Click **Go** / **Import**. This creates the `match_state` and `goals` tables.

*(Alternatively, run from Windows command prompt:*
```powershell
C:\xampp\mysql\bin\mysql.exe -u root turf_teams < C:\xampp\htdocs\turf-teams\migration.sql
```
*)*

### Step 3: Match Flow (How to Test Live Scoreboard)

1. **Setup State (`setup`)**:
   - Players open their WhatsApp links (`pick.php?t=...`) and select their teams.
   - At the top of `pick.php`, the scoreboard displays `0 - 0` with the status `"Match not started"`.
   - In the Admin Dashboard (`admin/`), the **"Start Match"** button is disabled until 18 players have picked, showing *"Start Match (X players still to pick)"*.
2. **Start Match (`live`)**:
   - Once all 18 players have joined a team, the admin clicks **"⚽ Start Match"** (with confirm dialog).
   - The match transitions to `live`.
   - The server timestamp is saved as `started_at`, and the match clock begins ticking (`MM:SS`) on all phones.
   - Player picking is automatically locked to prevent changes during the game.
3. **Record Goals**:
   - On the admin live screen, tap any player's card from the Runda FC or Makongeni FC roster.
   - Select **"⚽ Goal (+1 for their team)"** or **"🔴 Own Goal (+1 for opponent, marked OG)"**.
   - The server automatically calculates and records the match minute from the live clock.
   - The score on all players' phones flashes/pulses and updates live every 3 seconds via `api/match_state.php`.
   - Admin can undo the most recent goal, edit a goal's minute, or delete an accidental entry.
4. **End Match (`finished`)**:
   - At full time, the admin clicks **"🏁 End Match"** (with confirm dialog).
   - The timer freezes at the final duration and no more goals can be added.
   - The **Final Results Screen** appears on both admin and player phones:
     - Big final score and FT duration.
     - Winner banner (*"🏆 Runda FC Win!"* or *"🤝 Match Drawn!"*).
     - Two-column scorer list with goal minutes and `(OG)` tags.
     - Golden Boot top scorer highlight.
   - Admin can click **"📋 Copy Result for WhatsApp"** to copy a clean formatted match report.
5. **Start New Match / Reset**:
   - Click **"🔄 Start New Match (Reset Scoreboard)"** to clear goals and reset the match state to `setup` while **preserving player team picks**.
   - Or click **"⚠️ Reset All Picks"** to reset everything from scratch.

### Step 4: Verify Installation
Open **[http://localhost/turf-teams/](http://localhost/turf-teams/)** in your browser.

- **Admin Dashboard**: `http://localhost/turf-teams/admin/`
  - Default Password: **`turf2026`**
- **Sample Skilled Player Link**:
  `http://localhost/turf-teams/pick.php?t=291c9104b29acd1934f2b669771c13ba` (Jimmy)
- **Sample Regular Player Link**:
  `http://localhost/turf-teams/pick.php?t=3622253a346bf02104f96b34f9193e8a` (Josee)

---

## 📱 How to Make Links Reachable by Friends on Their Phones

Since friends will open their links from WhatsApp on their phones, `localhost` will not work on their mobile devices. Choose either of the two easy options below:

### Option A: Local Wi-Fi Network (Same Wi-Fi Router)

1. Find your computer's local IP address on Windows:
   - Open PowerShell and run:
     ```powershell
     ipconfig
     ```
   - Look for **IPv4 Address** (e.g. `192.168.1.50`).
2. Connect your phone to the **same Wi-Fi network**.
3. Open the Admin Dashboard: `http://localhost/turf-teams/admin/`
4. In the **"Shareable Base URL"** bar, enter:
   ```text
   http://192.168.1.50/turf-teams
   ```
   and click **Save URL**.
5. All "Copy Link" and "Share on WhatsApp" buttons will immediately use this IP address.

### Option B: Free Public Tunnel (Ngrok or Cloudflare)

To share links across mobile data (4G/5G) or when friends are not on your Wi-Fi:

1. Download and run **Ngrok**:
   ```bash
   ngrok http 80
   ```
2. Ngrok provides a public HTTPS forwarding address (e.g. `https://a1b2c3d4.ngrok-free.app`).
3. In the Admin Dashboard (`admin/`), set the **Shareable Base URL** to:
   ```text
   https://a1b2c3d4.ngrok-free.app/turf-teams
   ```
   and click **Save URL**.
4. Now, any link shared to WhatsApp can be opened by anyone from anywhere in the world!

---

## 💬 WhatsApp Integration Features

- **Share on WhatsApp (`wa.me`)**:
  Clicking "Share" next to any player opens WhatsApp with a pre-filled message:
  ```text
  Hey Brian Ochieng, pick your side for Saturday: http://192.168.1.50/turf-teams/pick.php?t=a1b2c3d4e5f678901234567890abcdef
  ```
- **Copy All Links**:
  Outputs a formatted list of all 18 players and links:
  ```text
  Brian Ochieng - http://.../pick.php?t=...
  Kevin Mwangi - http://.../pick.php?t=...
  ...
  ```
- **Export Final Match Squad**:
  Formats the rosters into a WhatsApp group announcement:
  ```text
  ⚽ *TURF TEAMS - SATURDAY MATCH SQUADS* ⚽
  📅 Saturday Match | Balanced Squads

  🟢 *RUNDA FC* (9/9)
  1. Brian Ochieng (Skilled)
  2. Kevin Mwangi (Skilled)
  3. Dennis Kipchumba (Skilled)
  4. Eric Otieno (Skilled)
  5. Samuel Karanja (Regular)
  6. David Njoroge (Regular)
  7. George Maina (Regular)
  8. Joseph Kamau (Regular)
  9. Peter Omondi (Regular)

  🔵 *MAKONGENI FC* (9/9)
  1. Alex Kimani (Skilled)
  2. Victor Wanyama (Skilled)
  3. Collins Kiprop (Skilled)
  4. Michael Olunga (Skilled)
  5. Evans Mutua (Regular)
  6. Antony Wekesa (Regular)
  7. Daniel Gitau (Regular)
  8. Francis Kibet (Regular)
  9. James Baraza (Regular)

  🔥 *Game on! Please be at the turf 15 minutes before kickoff.*
  ```

---

## 🧪 Running the Automated Test Suite

Run the test suite from PowerShell:
```powershell
& "C:\xampp\php\php.exe" tests/test_suite.php
& "C:\xampp\php\php.exe" tests/test_concurrency.php
```

Both tests verify:
- Clean database seeding
- Tier privacy (no tier badges leaked to player API)
- Correct viewer spot calculations (4 skilled / 5 regular)
- Invalid token 404 response
- Single choice lock permanence
- Hard caps enforcement (5th skilled player rejected; 6th regular player rejected)
- Auto-selection of single remaining option
- Admin lock toggle & reset functions
- Admin player CRUD
- Concurrent row-locking mutual exclusion (`SELECT ... FOR UPDATE`)
