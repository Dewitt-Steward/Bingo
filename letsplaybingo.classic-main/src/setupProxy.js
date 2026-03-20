const fs = require('fs');
const path = require('path');

module.exports = function setupProxy(app) {
	app.get('/api/session', (req, res) => {
		try {
			const repoRoot = path.resolve(__dirname, '..', '..');
			const filePath = path.join(repoRoot, 'SessionState.json');
			return res.json(readExistingSession(filePath));
		} catch (error) {
			return res.status(500).json({ error: 'read_failed' });
		}
	});

	app.post('/api/session', expressJsonFallback, (req, res) => {
		try {
			const repoRoot = path.resolve(__dirname, '..', '..');
			const filePath = path.join(repoRoot, 'SessionState.json');
			const payload = req.body && req.body.session ? req.body.session : req.body;
			const normalizedSession = normalizeSharedSession(payload);
			const nextState = {
				updatedAt: new Date().toISOString(),
				session: normalizedSession,
			};
			fs.writeFileSync(filePath, JSON.stringify(nextState, null, 2), 'utf8');
			return res.json(nextState);
		} catch (error) {
			return res.status(500).json({ error: 'write_failed' });
		}
	});

	app.get('/api/books/:familyId', (req, res) => {
		try {
			const familyId = String((req.params && req.params.familyId) || '').replace(/[^\d]/g, '').slice(0, 5);
			const gameNumber = parseInt((req.query && req.query.game) || '', 10);
			if (!familyId) {
				return res.status(400).json({ error: 'invalid_family_id' });
			}
			const repoRoot = path.resolve(__dirname, '..', '..');
			const filePath = path.join(repoRoot, 'Books.json');
			const existingData = readExistingBooks(filePath);
			const order = existingData.orders && existingData.orders[familyId] ? existingData.orders[familyId] : null;
			if (!order) {
				return res.status(404).json({ error: 'not_found' });
			}
			let sessionOrder = { ...order };
			if (!Number.isNaN(gameNumber) && gameNumber > 0) {
				const gameEntry = Array.isArray(order.games)
					? order.games.find((entry) => (parseInt(entry.game, 10) || 0) === gameNumber)
					: null;
				if (!gameEntry) {
					return res.status(404).json({ error: 'game_not_found' });
				}
				sessionOrder = {
					...order,
					totalCards: parseInt(gameEntry.cards, 10) || 0,
					gameNumber,
					cardsRemaining: parseInt(gameEntry.cardsRemaining, 10) || 0,
				};
			}
			const allOrders = Object.values(existingData.orders || {});
			const currentTierKey = getTierKeyFromBooks(order.totalBooks);
			const matchingOrders = allOrders.filter((entry) => getTierKeyFromBooks(entry && entry.totalBooks) === currentTierKey);
			return res.json({
				order: {
					...sessionOrder,
					playerPosition: 1,
					playerCount: Math.max(1, matchingOrders.length),
				},
			});
		} catch (error) {
			return res.status(500).json({ error: 'read_failed' });
		}
	});

	app.post('/api/save-order-json', expressJsonFallback, (req, res) => {
		try {
			const payload = req.body || {};
			const familyId = String(payload.familyId || '').replace(/[^\d]/g, '').slice(0, 5);
			const totalBooks = parseInt(payload.totalBooks, 10) || 0;
			const totalGames = parseInt(payload.totalGames, 10) || 0;
			const totalCards = parseInt(payload.totalCards, 10) || 0;
			const games = Array.isArray(payload.games) ? payload.games : [];
			if (!familyId || totalBooks <= 0 || totalGames <= 0 || totalCards <= 0) {
				return res.status(400).json({ error: 'invalid_payload' });
			}
			const repoRoot = path.resolve(__dirname, '..', '..');
			const fileName = 'Books.json';
			const filePath = path.join(repoRoot, fileName);
			const safePayload = {
				familyId,
				totalBooks,
				totalGames,
				totalCards,
				games: games.map((game) => ({
					game: parseInt(game.game, 10) || 0,
					familyId,
					cards: parseInt(game.cards, 10) || 0,
					cardsRemaining: parseInt(game.cardsRemaining, 10) || 0,
				})),
			};
			const existingData = readExistingBooks(filePath);
			existingData.orders[familyId] = safePayload;
			existingData.updatedAt = new Date().toISOString();
			fs.writeFileSync(filePath, JSON.stringify(existingData, null, 2), 'utf8');
			return res.json({
				ok: true,
				fileName,
				path: repoRoot,
			});
		} catch (error) {
			return res.status(500).json({ error: 'write_failed' });
		}
	});
};

function readExistingBooks(filePath) {
	if (!fs.existsSync(filePath)) {
		return { updatedAt: new Date().toISOString(), orders: {} };
	}
	try {
		const raw = fs.readFileSync(filePath, 'utf8');
		const parsed = raw ? JSON.parse(raw) : {};
		if (parsed && parsed.orders && typeof parsed.orders === 'object' && !Array.isArray(parsed.orders)) {
			return {
				updatedAt: parsed.updatedAt || new Date().toISOString(),
				orders: parsed.orders,
			};
		}
		if (parsed && parsed.familyId) {
			return {
				updatedAt: new Date().toISOString(),
				orders: {
					[String(parsed.familyId)]: parsed,
				},
			};
		}
		return { updatedAt: new Date().toISOString(), orders: {} };
	} catch (e) {
		return { updatedAt: new Date().toISOString(), orders: {} };
	}
}

function readExistingSession(filePath) {
	if (!fs.existsSync(filePath)) {
		return {
			updatedAt: new Date().toISOString(),
			session: getDefaultSharedSession(),
		};
	}
	try {
		const raw = fs.readFileSync(filePath, 'utf8');
		const parsed = raw ? JSON.parse(raw) : {};
		return {
			updatedAt: parsed && parsed.updatedAt ? parsed.updatedAt : new Date().toISOString(),
			session: normalizeSharedSession(parsed && parsed.session ? parsed.session : parsed),
		};
	} catch (e) {
		return {
			updatedAt: new Date().toISOString(),
			session: getDefaultSharedSession(),
		};
	}
}

function getDefaultSharedSession() {
	return {
		balls: buildDefaultBalls(),
		callHistory: [],
		newGame: true,
		running: false,
		selectedTableDeal: '',
		selectedTableDealIndex: 0,
		boardControlState: 'needs_host',
		bingoDetectedPin: '',
		patternResetToken: 0,
	};
}

function buildDefaultBalls() {
	const balls = {};
	for (let number = 1; number <= 75; number += 1) {
		let letter = 'B';
		if (number >= 16 && number <= 30) letter = 'I';
		if (number >= 31 && number <= 45) letter = 'N';
		if (number >= 46 && number <= 60) letter = 'G';
		if (number >= 61) letter = 'O';
		balls[number] = {
			letter,
			number,
			called: false,
			active: false,
		};
	}
	return balls;
}

function normalizeSharedSession(session) {
	const base = getDefaultSharedSession();
	const incoming = session && typeof session === 'object' ? session : {};
	const allowedBoardStates = ['needs_host', 'host_ready', 'table_ready', 'drawing', 'paused'];
	const normalizedBoardState = allowedBoardStates.includes(incoming.boardControlState)
		? incoming.boardControlState
		: base.boardControlState;
	const incomingHistory = Array.isArray(incoming.callHistory) ? incoming.callHistory : [];
	return {
		balls: normalizeBalls(incoming.balls),
		callHistory: incomingHistory
			.map((entry) => ({
				letter: String((entry && entry.letter) || '').slice(0, 1),
				number: parseInt(entry && entry.number, 10) || 0,
			}))
			.filter((entry) => entry.letter && entry.number > 0)
			.slice(-75),
		newGame: typeof incoming.newGame === 'boolean' ? incoming.newGame : base.newGame,
		running: typeof incoming.running === 'boolean' ? incoming.running : base.running,
		selectedTableDeal: String(incoming.selectedTableDeal || ''),
		selectedTableDealIndex: parseInt(incoming.selectedTableDealIndex, 10) || 0,
		boardControlState: normalizedBoardState,
		bingoDetectedPin: String(incoming.bingoDetectedPin || ''),
		patternResetToken: parseInt(incoming.patternResetToken, 10) || 0,
	};
}

function normalizeBalls(sourceBalls) {
	const baseBalls = buildDefaultBalls();
	const incoming = sourceBalls && typeof sourceBalls === 'object' ? sourceBalls : {};
	Object.keys(baseBalls).forEach((key) => {
		const incomingBall = incoming[key] || {};
		baseBalls[key] = {
			letter: baseBalls[key].letter,
			number: baseBalls[key].number,
			called: !!incomingBall.called,
			active: !!incomingBall.active,
		};
	});
	return baseBalls;
}

function expressJsonFallback(req, res, next) {
	if (req.body && Object.keys(req.body).length > 0) {
		return next();
	}
	let raw = '';
	req.on('data', (chunk) => {
		raw += chunk;
	});
	req.on('end', () => {
		try {
			req.body = raw ? JSON.parse(raw) : {};
		} catch (e) {
			req.body = {};
		}
		next();
	});
}

function getTierKeyFromBooks(totalBooks) {
	const books = parseInt(totalBooks, 10) || 0;
	if (books <= 1) return 'bronze';
	if (books === 2) return 'silver';
	if (books === 3) return 'gold';
	return 'platinum';
}
