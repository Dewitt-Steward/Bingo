# Let's Play Bingo

This document is the complete operating guide for the current codebase.  
It covers host flow, player flow, bingo claim handling, order generation, data files, and deployment behavior.

## 1) System Overview

The app has two runtime experiences:

1. Host board experience  
   Used to open/close the floor, control calling, and manage game state.

2. Player card experience  
   Opened through personalized links from `Books.json`, showing only player card content and live updates.

Core data files:

1. `data/Books.json`  
   Stores families, assigned books/cards by game, and player URLs.

2. `data/Bingo Cards.json`  
   Card catalog used to resolve cards by `Tier` + `BIN` + `BCIN`.

Published site assets:

1. `docs/`  
   GitHub Pages deploy root (must contain the latest built files).

2. `Bingo/build/`  
   Local build output produced by `npm run build`.

## 2) Host Access And Startup

1. Open app root (`/Bingo/`).

2. App shows **Host Login** screen only when `boardControlState` is `needs_host`.
   If board state is `host_ready`, `table_ready`, `drawing`, or `paused`,
   Host Login is not shown.
   Non-host clients still see the host board layout while host session is active,
   with host-only controls disabled unless locally verified.

3. Host enters credentials and clicks **Login** (or presses Enter).

4. On success:
   host is marked verified,
   app returns to board (`caller`) screen,
   board controls become available.

5. While not verified:
   board controls and order generation are not accessible.

## 3) Host Board Layout

Board view presents:

1. Call summary area:
   current call count, previous call number, selected game label.

2. Pattern selector:
   used to set winning pattern for bingo detection.

3. Main board + current ball display.

4. Previous-ball strip:
   current player view uses previous 5 calls;
   board summary also tracks recent history.

5. Host control row:
   buttons vary by board control state.

6. Header menu (host only):
   `Board`, and `Order` (localhost dev runtime only).

## 4) Host Control States And Buttons

`boardControlState` drives visible controls.

1. `needs_host`  
   Host not signed in. Login screen shown.

2. `host_ready`  
   Visible buttons: `Host Access`, `Open Floor`, `Close Floor`.

3. `table_ready`  
   Visible buttons: `Host Access`, `Start Draw`, `Clear Board`.

4. `drawing`  
   Visible buttons: `Host Access`, `Hold Draw`, `Clear Board`.

5. `paused`  
   Visible buttons: `Host Access`, `Resume Draw`, `Clear Board`.

## 5) Full Host Session Runbook

Use this sequence to run a complete session.

1. Sign in host.

2. Click **Open Floor**.
   A game dialog opens with game selection.

3. Select game and click **Apply**.
   Selected game enters session and state moves to `table_ready`.

4. Click **Start Draw** and confirm.
   Draw starts and numbers begin calling.
   The first number is called immediately.

5. During draw:
   use **Hold Draw** to pause when needed.

6. When paused:
   use **Resume Draw** to continue.
   After resume, calling continues and controls return to the table-ready set (`Start Draw`, `Clear Board`).

7. Between rounds:
   use **Clear Board** and confirm.
   This clears calls/cards and returns control flow for next setup.

8. End of session:
   click **Close Floor** and confirm.
   This clears session state and signs out host.

## 6) Dialogs And Confirmations

The app uses confirmation and modal dialogs for critical actions.

1. **Open Floor** dialog:
   buttons: `Apply`, `Cancel`.

2. Confirm dialogs:
   shown for `Start Draw`, `Hold Draw`, `Resume Draw`, `Clear Board`, `Close Floor`.
   buttons: action confirm + `Cancel`.

3. Host Access active dialog:
   if host is already active and **Host Access** is clicked.
   buttons: `Sign out`, `Cancel`.
   `Sign out` requires host sign-out verification prompt completion.

4. End-of-ball alert:
   if all 75 numbers are called, alert appears:
   `All bingo balls have been called.`
   button: `Close Alert`.
   After this alert, host typically clears the board or closes the floor.

## 7) Bingo Handling (Exhaustive)

### 7.1 Winning Rule Source

1. Winning logic uses selected pattern if a pattern is chosen.

2. If no pattern is chosen, classic win logic is used:
   any full row, any full column, or either diagonal.

### 7.2 Pattern Behavior

1. Pattern selector is available before draw starts.

2. During live draw, pattern selector is disabled.

3. `Clear Board` resets pattern selection state for the next round.

### 7.3 FREE Cell Rule

1. `FREE` is treated as marked only when a game is in session.

2. If no game is selected/open, `FREE` is not marked for win checks.

### 7.4 Player Bingo Eligibility

1. App evaluates the currently loaded game card set for that player against called numbers and active pattern.

2. Evaluation is game-scoped and only runs while a game is in session.

3. If any card in that in-session game set satisfies win logic, **Bingo** button becomes visible.

4. If no loaded card satisfies win logic, **Bingo** button is hidden.

### 7.5 On Bingo Button Press

1. Draw is paused.

2. Host validates the claim while play is paused.

### 7.6 Host Claim Verification Procedure

Use this exact process when a player claims bingo:

1. Keep draw paused.

2. Obtain player claim details:
   `BIN` and `BCIN`.

3. Verify against:
   called numbers,
   active game,
   active winning pattern.

4. If valid:
   complete your payout/announcement flow,
   then `Clear Board` (next round) or `Close Floor` (session end).

5. If invalid:
   continue current round using `Resume Draw`.

### 7.7 Responsibility Boundary

1. App provides pattern checks and claim signaling.

2. Final prize adjudication remains a host/operator responsibility.

## 8) Player Experience (Exhaustive)

### 8.1 How Players Enter

1. Players use personalized URLs from `Books.json`.

2. Player links include localhost and production variants.

3. Player pages do not expose host menu controls.

### 8.2 What Players See

1. Room identity panel:
   title and welcome message based on card package level.

2. Player position:
   `Player X of Y`.

3. Call strip:
   current call + previous 5 calls.

4. Cards:
   4 cards per page.

5. Pager format:
   `< Showing x-y of z >`
   with game label directly below: `Game: <name>`.

6. Card footer IDs:
   `BIN` and `BCIN`.

### 8.3 No Game Selected vs Game In Session

1. If no game is selected:
   player view loads all assigned cards.

2. After host selects game in **Open Floor**:
   player view updates to cards for that selected game.

3. `FREE` marking follows session state rule (Section 7.3).

### 8.4 Player Bingo Behavior

1. **Bingo** button appears only when current loaded deck satisfies win logic.

2. Pressing **Bingo** pauses draw for host review.

3. Player should report `BIN` and `BCIN` to host.

## 9) Game Names

Current game/deal names:

1. Open Deal
2. Lucky Run
3. Double Down
4. High Stakes
5. Jackpot Chase
6. Royal Finish

## 10) Order Screen (Localhost Dev Only)

Order generation is restricted by design:

1. Host must be signed in.

2. Runtime must be localhost dev (`isLocalDevRuntime`).

3. In production (GitHub Pages), `Order` is not available.

Order workflow:

1. Open header menu and select `Order`.

2. Enter `Family ID` (5 digits) and `Order Total`.

3. Cart auto-allocates package quantities from order total.

4. `Generate` is enabled when:
   cart is non-empty and family ID is valid.

5. On generate success:
   app updates `data/Books.json` through local save endpoint
   and shows save confirmation message.

6. If save fails:
   app displays error indicating `Books.json` was not updated.

Package definitions used in order allocation:

1. Bronze / Lucky Seat / $10 / 1 card per game
2. Silver / Double Down / $15 / 2 cards per game
3. Gold / High Roller / $20 / 3 cards per game
4. Platinum / Royal Flush / $25 / 4 cards per game

## 11) Data And Linking Behavior

1. `Books.json` stores:
   family order metadata,
   per-game card assignments (`Tier`, `BIN`, `BCIN`),
   player URLs.

2. `Bingo Cards.json` stores actual card faces by `Tier` + `BIN` + `BCIN`.

3. Player card load flow:
   resolve family order,
   choose selected game (or all when none),
   resolve cards from assignments + card catalog.

4. If static card resolution fails:
   app can generate fallback random cards for play rendering.

## 12) Shared Session Sync

1. Host publishes shared session snapshots to session endpoint.

2. Clients poll for updates on an interval.
   Poll interval is 1.5 seconds.

3. Shared state includes:
   called balls,
   call history,
   new/running flags,
   selected game + index,
   board control state,
   pattern reset token.

## 13) Release And GitHub Pages Checklist

Use this checklist every time you want GitHub to match local behavior:

1. Build from app folder:
   `cd Bingo && npm run build`

2. Sync build output into docs:
   copy/sync `Bingo/build/` -> `docs/`.

3. Confirm `docs/index.html` references latest hashed JS/CSS files.

4. Commit:
   source updates + docs build artifacts + README changes.

5. Push to `main`.

6. After deploy, hard refresh browser (service worker/cache can hold old assets).

## 14) Operational Troubleshooting

1. Host logs in and sees wrong screen  
   Host login success should route to board (`caller`) view.

2. Host Login appears while host is already active  
   Host Login should only appear in `needs_host`.
   If board state is `host_ready`, `table_ready`, `drawing`, or `paused`, Host Login stays hidden.

3. GitHub page does not show latest UI  
   Usually `docs/` was not updated from latest `Bingo/build/`.

4. Player sees no bingo button  
   Either no card matches current called numbers/pattern,
   or no active game session (`FREE` not counted).

5. Order menu missing  
   Expected outside localhost dev runtime or while host is not signed in.

6. Player link opens but cards missing  
   Validate family entry exists in `Books.json` and assignments resolve against `Bingo Cards.json`.
