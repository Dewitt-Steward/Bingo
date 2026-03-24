# Let's Play Bingo

This guide reflects the current host and player experience in this repo.

## Live Session Flow

1. Open the app.  
   If host is not verified, the app shows a **Host Login** screen. Board controls and Order are not available until login is successful.

2. Host enters the access code and clicks **Login** (or presses Enter).  
   On successful login, the host is taken to the board screen.

3. Host sees board controls.  
   The board control area always includes **Host Access**.  
   At this stage, **Open Floor** and **Close Floor** are available.

4. Host clicks **Open Floor**.  
   A game selector dialog opens. Host chooses the game and clicks **Apply** to put that game in session.

5. Player links are used to open player cards.  
   Player URLs come from `Books.json` (localhost and production links).  
   Player pages do not show the host menu/header controls.

6. Before a game is selected, players see all assigned cards.  
   After a game is selected on **Open Floor**, player cards update to that game selection.

7. Host starts calling by clicking **Start Draw**.  
   During drawing, host can click **Hold Draw** to pause and **Resume Draw** to continue.  
   **Clear Board** is available in table-ready, drawing, and paused states.

8. Player card behavior during play.  
   Player view shows current call plus the previous 5 calls.  
   Cards are paged with **< Showing x-y of z >** and display the current `Game:` label under the pager.  
   `BIN` and `BCIN` are displayed on each card.

9. `FREE` space behavior.  
   `FREE` is only marked when a game is actively in session.

10. Ending or resetting host control.  
   Clicking **Close Floor** closes the floor, clears active session state, resets the board, and signs out host access.  
   If **Host Access** is clicked while host access is already active, a sign-out dialog appears with **Sign out** and **Cancel**.  
   Choosing **Sign out** requires entering tomorrow’s date in `MMDDYYYY` format.

11. Order screen behavior.  
   **Order** is available only in localhost development mode and only after host login.
