# Let's Play Bingo

A classic, web-based Bingo game implemented in PHP, supporting both hosts (game organizers) and players.

---

## Table of Contents

1. [About](#about)
2. [Features](#features)
3. [Requirements](#requirements)
4. [Installation](#installation)
5. [File Structure](#file-structure)
6. [Game Flow Overview](#game-flow-overview)
7. [Host Guide](#host-guide)
8. [Player Guide](#player-guide)
9. [Customization](#customization)
10. [Troubleshooting](#troubleshooting)
11. [Contributing](#contributing)
12. [License](#license)
13. [Contact](#contact)

---

## 1. About

Let's Play Bingo is a web-based Bingo game that allows a host to run a Bingo session and players to join, receive cards, and play interactively in their browsers.

---

## 2. Features

- Host can start and control Bingo games
- Players can join games and receive unique cards
- Automated number calling and display
- Real-time marking of cards
- Win detection (rows, columns, diagonals, full card)
- Responsive web interface

---

## 3. Requirements

- PHP 7.0 or higher
- Web server (Apache, Nginx, etc.)

---

## 4. Installation

1. **Clone or Download the Repository**
   ```bash
   git clone https://github.com/yourusername/letsplaybingo.classic-main.git
   ```
2. **Move Files to Web Server**
   Place all files (including `letsplaybingo.classic-main` and `bingo.php`) in your web server's document root.
3. **Set Permissions**
   Ensure PHP can read/write to any directories used for saving data (if applicable).

---

## 5. File Structure

```
letsplaybingo.classic-main/
├── assets/                # Images, CSS, JS
├── config/                # Configuration files
├── includes/              # PHP includes and helpers
├── templates/             # HTML templates
├── classes/               # PHP classes (game logic, user, etc.)
├── data/                  # (Optional) Persistent data storage
├── tests/                 # Unit and integration tests
├── README.md
├── bingo.php              # Main entry point
└── ...existing code...
```

---

## 6. Game Flow Overview

### For the Host

1. **Start a New Game:**  
   The host accesses `bingo.php` and selects "Host a Game". A new game session is created.

2. **Share Game Code/Link:**  
   The host receives a unique game code or link to share with players.

3. **Monitor Player Join:**  
   The host waits for players to join. The interface shows a list of connected players.

4. **Begin the Game:**  
   Once all players have joined, the host starts the game. Each player receives a unique Bingo card.

5. **Call Numbers:**  
   The host uses the interface to randomly call numbers. Each called number is displayed to all players.

6. **Monitor for Winners:**  
   The system automatically checks for winning cards as numbers are called. When a player wins, the host is notified.

7. **End or Restart Game:**  
   The host can end the session or start a new game.

---

### For the Player

1. **Join a Game:**  
   The player receives a game code or link from the host and navigates to `bingo.php`, selecting "Join a Game".

2. **Enter Game Code:**  
   The player enters the provided code to join the correct session.

3. **Receive Bingo Card:**  
   Once the host starts the game, the player is given a unique Bingo card displayed in their browser.

4. **Mark Numbers:**  
   As the host calls numbers, the player marks matching numbers on their card by clicking them.

5. **Win Detection:**  
   When the player completes a winning pattern (row, column, diagonal, or full card), the system notifies both the player and the host.

6. **Game End:**  
   The player can view results and wait for the host to start a new game or exit.

---

## 7. Host Guide

1. **Access the Game:**  
   Open your browser and go to `http://your-server/bingo.php`.

2. **Select "Host a Game":**  
   Click the "Host a Game" button to create a new session.

3. **Share the Game Code/Link:**  
   Copy the code or link shown and send it to your players.

4. **Wait for Players:**  
   Watch as players join. Their names or IDs will appear in your lobby.

5. **Start the Game:**  
   When ready, click "Start Game". All players will receive their cards.

6. **Call Numbers:**  
   Use the "Call Number" button to draw and announce numbers. The current and previous numbers are displayed.

7. **Monitor for Winners:**  
   The system will alert you when a player wins. You can verify and announce the winner.

8. **Restart or End:**  
   After a win, you can start a new round or end the session.

---

## 8. Player Guide

1. **Get the Game Code/Link:**  
   Obtain the code or link from your host.

2. **Join the Game:**  
   Go to `http://your-server/bingo.php`, select "Join a Game", and enter the code.

3. **Wait for the Game to Start:**  
   Once the host starts, your Bingo card will appear.

4. **Mark Numbers:**  
   As numbers are called, click the matching numbers on your card to mark them.

5. **Check for Win:**  
   The system will automatically detect if you have a winning pattern and notify you.

6. **Celebrate or Play Again:**  
   Wait for the host to start a new game or exit the session.

---

## 9. Customization

- **Game Rules:**  
  Edit files in `classes/` to change win conditions or card size.
- **UI/UX:**  
  Modify `assets/css/` and `assets/js/` for styling and interactivity.
- **Localization:**  
  Add or edit language files in `config/` or `includes/`.

---

## 10. Troubleshooting

- **Blank Page/Error:**  
  Check PHP error logs and ensure all required extensions are enabled.
- **Session Issues:**  
  Make sure PHP sessions are enabled and writable.
- **Permissions:**  
  Verify file and directory permissions for uploads or data storage.

---

## 11. Contributing

1. Fork the repository
2. Create a new branch (`git checkout -b feature/your-feature`)
3. Commit your changes (`git commit -am 'Add new feature'`)
4. Push to the branch (`git push origin feature/your-feature`)
5. Open a Pull Request

---

## 12. License

This project is provided for educational and entertainment purposes.  
See [LICENSE](LICENSE) for more details.

---

## 13. Contact

For questions or support, contact [your-email@example.com].
