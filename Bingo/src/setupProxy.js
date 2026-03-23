const fs = require('fs');
const path = require('path');

module.exports = function setupProxy(app) {
	app.get('/api/session', (req, res) => {
		try {
			const repoRoot = path.resolve(__dirname, '..', '..');
			const filePath = path.join(repoRoot, 'data', 'SessionState.json');
			return res.json(readExistingSession(filePath));
		} catch (error) {
			return res.status(500).json({ error: 'read_failed' });
		}
	});

	app.post('/api/session', expressJsonFallback, (req, res) => {
		try {
			const repoRoot = path.resolve(__dirname, '..', '..');
			const filePath = path.join(repoRoot, 'data', 'SessionState.json');
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

	app.get('/api/books', (req, res) => {
		try {
			const repoRoot = path.resolve(__dirname, '..', '..');
			const filePath = path.join(repoRoot, 'data', 'Books.json');
			const existingData = readExistingBooks(filePath);
			const players = Object.entries(existingData.orders || {})
				.map(([familyId, rawOrder]) => {
					const order = normalizeOrderRecord(rawOrder) || {};
					const cleanFamilyId = String(familyId || order.familyId || '').replace(/[^\d]/g, '').slice(0, 5);
					if (!/^\d{5}$/.test(cleanFamilyId)) return null;
					const cardsPerGame = parseInt(order.totalBooks, 10) || parseInt((order.games && order.games[0] && order.games[0].cards) || 0, 10) || 0;
					const tier = getTierLabelFromBooks(cardsPerGame);
					const urls = normalizeOrderJoinUrls(order, req, cleanFamilyId);
					return {
						familyId: cleanFamilyId,
						tier: tier.charAt(0).toUpperCase() + tier.slice(1),
						cardsPerGame,
						urls,
					};
				})
				.filter(Boolean)
				.sort((a, b) => a.familyId.localeCompare(b.familyId));
			return res.json({ players });
		} catch (error) {
			return res.status(500).json({ error: 'read_failed' });
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
			const filePath = path.join(repoRoot, 'data', 'Books.json');
			const existingData = readExistingBooks(filePath);
			const rawOrder = existingData.orders && existingData.orders[familyId] ? existingData.orders[familyId] : null;
			const order = normalizeOrderRecord(rawOrder);
			if (!order) {
				return res.status(404).json({ error: 'not_found' });
			}
			let sessionOrder = { ...order };
			const bingoCardsPath = path.join(repoRoot, 'data', 'Bingo Cards.json');
			const bingoCards = readBingoCardsDeck(bingoCardsPath);
			if (!Number.isNaN(gameNumber) && gameNumber > 0) {
				const gameEntry = Array.isArray(order.games)
					? order.games.find((entry) => (parseInt(entry.game, 10) || 0) === gameNumber)
					: null;
				if (!gameEntry) {
					return res.status(404).json({ error: 'game_not_found' });
				}
				const cardsAssigned = Array.isArray(gameEntry.cardsAssigned) ? gameEntry.cardsAssigned : [];
				const resolvedDeck = resolveCardsFromAssignments(cardsAssigned, bingoCards);
				sessionOrder = {
					...order,
					totalCards: parseInt(gameEntry.cards, 10) || 0,
					gameNumber,
					cardsRemaining: parseInt(gameEntry.cardsRemaining, 10) || 0,
					cardsAssigned,
					playCardDeck: resolvedDeck,
				};
			} else {
				const allCardsAssigned = Array.isArray(order.games)
					? order.games.flatMap((entry) => (Array.isArray(entry && entry.cardsAssigned) ? entry.cardsAssigned : []))
					: (Array.isArray(order.cardsAssigned) ? order.cardsAssigned : []);
				const resolvedDeck = resolveCardsFromAssignments(allCardsAssigned, bingoCards);
				sessionOrder = {
					...order,
					gameNumber: 0,
					cardsRemaining: 0,
					cardsAssigned: allCardsAssigned,
					playCardDeck: resolvedDeck,
					totalCards: resolvedDeck.length > 0 ? resolvedDeck.length : (parseInt(order.totalCards, 10) || allCardsAssigned.length || 0),
				};
			}
			const allOrders = Object.values(existingData.orders || {});
			const currentTierKey = getTierKeyFromBooks(order.totalBooks);
			const matchingOrders = allOrders.filter((entry) => getTierKeyFromBooks((normalizeOrderRecord(entry) || {}).totalBooks) === currentTierKey);
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
			const games = Array.isArray(payload.games) ? payload.games : [];
			const cardsPerGameFromPayload = parseInt(payload.totalBooks, 10) || 0;
			const cardsPerGameFromGames = parseInt((games[0] && games[0].cards) || 0, 10) || 0;
			const cardsPerGame = cardsPerGameFromPayload || cardsPerGameFromGames;
			const gameCount = (parseInt(payload.totalGames, 10) || 0) || games.length;
			const totalCards = cardsPerGame > 0 && gameCount > 0
				? cardsPerGame * gameCount
				: ((parseInt(payload.totalCards, 10) || 0));
			if (!familyId || cardsPerGame <= 0 || gameCount <= 0 || totalCards <= 0) {
				return res.status(400).json({ error: 'invalid_payload' });
			}
			const repoRoot = path.resolve(__dirname, '..', '..');
			const fileName = 'Books.json';
			const filePath = path.join(repoRoot, 'data', fileName);
			const bingoCardsPath = path.join(repoRoot, 'data', 'Bingo Cards.json');
			const bingoCards = readBingoCardsDeck(bingoCardsPath);
			if (!Array.isArray(bingoCards) || bingoCards.length === 0) {
				return res.status(500).json({ error: 'bingo_cards_missing' });
			}
			const assignedGames = buildAssignedGames({
				familyId,
				totalBooks: cardsPerGame,
				totalGames: gameCount,
				totalCards,
				bingoCards,
			});
			const joinUrls = buildFamilyJoinUrls(req, familyId);
			const safePayload = {
				familyId,
				url: joinUrls.production || joinUrls.localhost || '',
				urls: joinUrls,
				games: assignedGames.length > 0
					? assignedGames
					: games.map((game) => ({
						game: parseInt(game.game, 10) || 0,
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
				path: path.dirname(filePath),
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
					[String(parsed.familyId)]: normalizeOrderRecord(parsed),
				},
			};
		}
		return { updatedAt: new Date().toISOString(), orders: {} };
	} catch (e) {
		return { updatedAt: new Date().toISOString(), orders: {} };
	}
}

function normalizeOrderRecord(order) {
	if (!order || typeof order !== 'object') return null;
	const games = Array.isArray(order.games) ? order.games : [];
	const cardsPerGame = parseInt((games[0] && games[0].cards) || order.totalBooks || 0, 10) || 0;
	const totalGames = games.length > 0 ? games.length : (parseInt(order.totalGames, 10) || 0);
	const totalCards = games.length > 0
		? games.reduce((sum, game) => sum + (parseInt((game && game.cards) || 0, 10) || 0), 0)
		: ((parseInt(order.totalCards, 10) || 0) || (cardsPerGame * totalGames));
	return {
		...order,
		totalBooks: cardsPerGame,
		totalGames,
		totalCards,
	};
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

function readBingoCardsDeck(filePath) {
	if (!fs.existsSync(filePath)) {
		return [];
	}
	try {
		const raw = fs.readFileSync(filePath, 'utf8');
		const parsed = raw ? JSON.parse(raw) : {};
		if (parsed && Array.isArray(parsed.cards)) {
			return parsed.cards
				.map(normalizeBingoCard)
				.filter(Boolean);
		}
		return [];
	} catch (e) {
		return [];
	}
}

function normalizeBingoCard(card) {
	if (!card || typeof card !== 'object') return null;
	const normalized = {
		Tier: String(card.Tier || card.tier || ''),
		BIN: String(card.BIN || card.bin || ''),
		BCIN: String(card.BCIN || card.bcin || ''),
		B: Array.isArray(card.B) ? card.B : [],
		I: Array.isArray(card.I) ? card.I : [],
		N: Array.isArray(card.N) ? card.N : [],
		G: Array.isArray(card.G) ? card.G : [],
		O: Array.isArray(card.O) ? card.O : [],
	};
	if (!normalized.BIN || !normalized.BCIN) return null;
	const hasValidColumns = ['B', 'I', 'N', 'G', 'O'].every((letter) => Array.isArray(normalized[letter]) && normalized[letter].length === 5);
	if (!hasValidColumns) return null;
	return normalized;
}

function buildAssignedGames({ familyId, totalBooks, totalGames, totalCards, bingoCards }) {
	const cardsPerGame = Math.max(0, parseInt(totalBooks, 10) || 0);
	const gameCount = Math.max(0, parseInt(totalGames, 10) || 0);
	const pool = Array.isArray(bingoCards) ? bingoCards : [];
	if (cardsPerGame <= 0 || gameCount <= 0 || pool.length === 0) {
		return [];
	}

	const familyNumeric = parseInt(familyId, 10) || 0;
	const startIndex = familyNumeric % pool.length;
	let offset = 0;

	return Array.from({ length: gameCount }, (_, index) => {
		const gameNumber = index + 1;
		const cardsAssigned = Array.from({ length: cardsPerGame }, () => {
			const poolIndex = (startIndex + offset) % pool.length;
			offset += 1;
			const picked = pool[poolIndex];
			return {
				Tier: picked.Tier,
				BIN: picked.BIN,
				BCIN: picked.BCIN,
			};
		});

		return {
			game: gameNumber,
			cards: cardsPerGame,
			cardsRemaining: Math.max(0, totalCards - (cardsPerGame * gameNumber)),
			cardsAssigned,
		};
	});
}

function resolveCardsFromAssignments(cardsAssigned, bingoCards) {
	if (!Array.isArray(cardsAssigned) || cardsAssigned.length === 0) {
		return [];
	}
	const lookup = buildBingoCardLookup(bingoCards);
	return cardsAssigned
		.map((assignment) => findCardForAssignment(assignment, lookup))
		.filter(Boolean);
}

function buildBingoCardLookup(cards) {
	const byTierBinBcin = new Map();
	const byBinBcin = new Map();
	(Array.isArray(cards) ? cards : []).forEach((card) => {
		if (!card) return;
		const tier = String(card.Tier || '').trim();
		const bin = String(card.BIN || '').trim();
		const bcin = String(card.BCIN || '').trim();
		if (!bin || !bcin) return;
		if (tier) {
			byTierBinBcin.set(makeTierBinBcinKey(tier, bin, bcin), card);
		}
		const fallbackKey = makeBinBcinKey(bin, bcin);
		if (!byBinBcin.has(fallbackKey)) {
			byBinBcin.set(fallbackKey, card);
		}
	});
	return { byTierBinBcin, byBinBcin };
}

function findCardForAssignment(assignment, lookup) {
	if (!assignment || typeof assignment !== 'object') return null;
	const tier = String(assignment.Tier || assignment.tier || '').trim();
	const bin = String(assignment.BIN || assignment.bin || '').trim();
	const bcin = String(assignment.BCIN || assignment.bcin || '').trim();
	if (!bin || !bcin) return null;
	if (tier) {
		const direct = lookup.byTierBinBcin.get(makeTierBinBcinKey(tier, bin, bcin));
		if (direct) return direct;
	}
	return lookup.byBinBcin.get(makeBinBcinKey(bin, bcin)) || null;
}

function makeTierBinBcinKey(tier, bin, bcin) {
	return `${String(tier || '').toUpperCase()}|${String(bin || '').toUpperCase()}|${String(bcin || '')}`;
}

function makeBinBcinKey(bin, bcin) {
	return `${String(bin || '').toUpperCase()}|${String(bcin || '')}`;
}

function normalizeOrderJoinUrls(order, req, familyId) {
	const defaultUrls = buildFamilyJoinUrls(req, familyId);
	const sourceOrder = order && typeof order === 'object' ? order : {};
	const sourceUrls = sourceOrder.urls && typeof sourceOrder.urls === 'object' ? sourceOrder.urls : {};
	const fallbackUrl = String(sourceOrder.url || '').trim();
	const fallbackIsLocalhost = fallbackUrl.includes('127.0.0.1') || fallbackUrl.includes('localhost');
	const localhostUrl = String(sourceUrls.localhost || '').trim() || (fallbackIsLocalhost ? fallbackUrl : '');
	const productionUrl = String(sourceUrls.production || '').trim() || (!fallbackIsLocalhost ? fallbackUrl : '');
	return {
		localhost: localhostUrl || defaultUrls.localhost,
		production: productionUrl || defaultUrls.production,
	};
}

function buildFamilyJoinUrls(req, familyId) {
	const packageHomepage = readPackageHomepageUrl();
	const configuredProductionBase = String(
		process.env.LPB_PRODUCTION_JOIN_BASE_URL ||
		process.env.LPB_JOIN_BASE_URL ||
		packageHomepage ||
		'https://dewitt-steward.github.io/Bingo'
	).trim();
	const configuredLocalBase = String(
		process.env.LPB_LOCAL_JOIN_BASE_URL ||
		'http://127.0.0.1:3000'
	).trim();
	const host = String((req && req.headers && req.headers.host) || '127.0.0.1:3000').trim();
	const forwardedProto = String((req && req.headers && req.headers['x-forwarded-proto']) || '').trim();
	const requestProtocol = forwardedProto || (host.startsWith('localhost') || host.startsWith('127.0.0.1') ? 'http' : 'https');
	const requestBase = `${requestProtocol}://${host}`;
	const localhostUrl = buildJoinUrlFromBase(configuredLocalBase, familyId);
	const productionUrl = buildJoinUrlFromBase(configuredProductionBase, familyId);
	const requestUrl = buildJoinUrlFromBase(requestBase, familyId);
	return {
		localhost: localhostUrl || requestUrl,
		production: productionUrl || requestUrl,
	};
}

function buildJoinUrlFromBase(base, familyId) {
	const cleanedBase = String(base || '').trim().replace(/\/+$/, '');
	const familyParam = encodeURIComponent(String(familyId || ''));
	if (!cleanedBase) {
		return '';
	}
	if (/\/Bingo$/i.test(cleanedBase)) {
		return `${cleanedBase}/?familyId=${familyParam}`;
	}
	return `${cleanedBase}/Bingo/?familyId=${familyParam}`;
}

function readPackageHomepageUrl() {
	try {
		const packagePath = path.resolve(__dirname, '..', 'package.json');
		if (!fs.existsSync(packagePath)) return '';
		const raw = fs.readFileSync(packagePath, 'utf8');
		const parsed = raw ? JSON.parse(raw) : {};
		const homepage = String(parsed.homepage || '').trim();
		return homepage;
	} catch (e) {
		return '';
	}
}

function getTierKeyFromBooks(totalBooks) {
	const books = parseInt(totalBooks, 10) || 0;
	if (books <= 1) return 'bronze';
	if (books === 2) return 'silver';
	if (books === 3) return 'gold';
	return 'platinum';
}
