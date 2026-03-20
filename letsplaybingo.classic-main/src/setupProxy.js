const fs = require('fs');
const path = require('path');

module.exports = function setupProxy(app) {
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
