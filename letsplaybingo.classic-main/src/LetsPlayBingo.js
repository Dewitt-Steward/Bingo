/*
 * Let's Play Bingo
 * App written by Karol Brennan
 * https://karol.dev
 * http://github.com/karolbrennan
 */
import React, { Component } from 'react';
import _ from 'underscore';
// Styles and Images
import logo from './logo.svg';
import logoLight from './logo-light.svg';
// Components
import BingoBoard from './components/BingoBoard.js';
import BallDisplay from './components/BallDisplay.js';
import Pattern from './components/Pattern.js';
import SevenSegmentText from './components/SevenSegmentText.js';

const orderPackages = [
	{
		tier: 'Bronze',
		name: 'Lucky Seat',
		price: 10,
		cardsPerGameCount: 1,
		cardsPerGame: '1 card per game',
		description:
			'A refined entry into the action. Bronze Lucky Seat places 1 card into automatic play each round, giving you a smooth and steady presence throughout the full session.',
	},
	{
		tier: 'Silver',
		name: 'Double Down',
		price: 15,
		cardsPerGameCount: 2,
		cardsPerGame: '2 cards per game',
		description:
			'A stronger position with added reach. Silver Double Down places 2 cards into automatic play each round, giving you greater coverage and more ways to stay in contention.',
	},
	{
		tier: 'Gold',
		name: 'High Roller',
		price: 20,
		cardsPerGameCount: 3,
		cardsPerGame: '3 cards per game',
		description:
			'A more commanding way to play. Gold High Roller places 3 cards into automatic play each round, giving you broader board presence and elevated momentum across the session.',
	},
	{
		tier: 'Platinum',
		name: 'Royal Flush',
		price: 25,
		cardsPerGameCount: 4,
		cardsPerGame: '4 cards per game',
		description:
			'Our most premium level of play. Platinum Royal Flush places 4 cards into automatic play each round, delivering your strongest coverage and the fullest level of action available.',
	},
];

const bingoLetters = ['B', 'I', 'N', 'G', 'O'];
const openTableDeals = [
	'Open Deal',
	'Lucky Run',
	'Double Down',
	'High Stakes',
	'Jackpot Chase',
	'Royal Finish',
];
const radioStreamUrl = 'https://ice41.securenetsystems.net/KOWNLP';
const radioMetadataUrl = 'https://r.jina.ai/http://https://957fmtheboss.com/?qtproxycall=aHR0cHM6Ly9pY2U0MS5zZWN1cmVuZXRzeXN0ZW1zLm5ldC9LT1dOTFA=&icymetadata=1';
const radioLogoUrl = 'https://957fmtheboss.com/wp-content/uploads/2020/06/boss_blk.png';
const localhostJoinBase = 'http://127.0.0.1:3000/Bingo';
const productionJoinBase = 'https://dewitt-steward.github.io/Bingo';

function getBingoRuntimeConfig() {
	try {
		if (typeof window === 'undefined' || !window.LPB_CONFIG || typeof window.LPB_CONFIG !== 'object') {
			return {};
		}
		return window.LPB_CONFIG;
	} catch (e) {
		return {};
	}
}

function isLocalhostRuntime() {
	if (typeof window === 'undefined') {
		return false;
	}
	const host = String(window.location.hostname || '').toLowerCase();
	return host === 'localhost' || host === '127.0.0.1';
}

function isLocalDevRuntime() {
	return isLocalhostRuntime() && process.env.NODE_ENV !== 'production';
}

function getSharedSessionEndpoint() {
	const runtimeConfig = getBingoRuntimeConfig();
	const configuredUrl = String(runtimeConfig.sessionApiUrl || '').trim();
	if (configuredUrl) {
		return configuredUrl;
	}
	if (isLocalhostRuntime()) {
		return '/api/session';
	}
	return '';
}

function getOrderSaveEndpoint() {
	if (!isLocalDevRuntime()) {
		return '';
	}
	return '/api/save-order-json';
}

function getDeepLinkFamilyIdFromUrl() {
	if (typeof window === 'undefined') {
		return '';
	}
	try {
		const pickFamilyId = (params) => {
			for (const [key, value] of params.entries()) {
				if (String(key || '').toLowerCase() !== 'familyid') continue;
				const cleanedFamilyId = String(value || '').replace(/[^\d]/g, '').slice(0, 5);
				if (/^\d{5}$/.test(cleanedFamilyId)) {
					return cleanedFamilyId;
				}
			}
			return '';
		};
		const searchParams = new URLSearchParams(String(window.location.search || ''));
		const fromSearch = pickFamilyId(searchParams);
		if (fromSearch) return fromSearch;
		const hash = String(window.location.hash || '');
		const hashQueryIndex = hash.indexOf('?');
		if (hashQueryIndex >= 0) {
			const hashParams = new URLSearchParams(hash.slice(hashQueryIndex + 1));
			const fromHash = pickFamilyId(hashParams);
			if (fromHash) return fromHash;
		}
		return '';
	} catch (e) {
		return '';
	}
}

function buildJoinUrlFromBase(base, familyId) {
	const cleanFamilyId = String(familyId || '').replace(/[^\d]/g, '').slice(0, 5);
	if (!/^\d{5}$/.test(cleanFamilyId)) {
		return '';
	}
	const cleanBase = String(base || '').trim().replace(/\/+$/, '');
	if (!cleanBase) {
		return '';
	}
	if (/\/Bingo$/i.test(cleanBase)) {
		return `${cleanBase}/?familyId=${cleanFamilyId}`;
	}
	return `${cleanBase}/Bingo/?familyId=${cleanFamilyId}`;
}

function getTierLabelFromCardsPerGame(cardsPerGame) {
	const cards = parseInt(cardsPerGame, 10) || 0;
	if (cards <= 1) return 'Bronze';
	if (cards === 2) return 'Silver';
	if (cards === 3) return 'Gold';
	return 'Platinum';
}

function getCardsPerGameFromOrder(order) {
	if (!order || typeof order !== 'object') return 0;
	const games = Array.isArray(order.games) ? order.games : [];
	const cardsFromGame = parseInt((games[0] && games[0].cards) || 0, 10) || 0;
	return cardsFromGame || (parseInt(order.totalBooks, 10) || 0);
}

function normalizePlayersEntry(entry, familyIdHint) {
	const source = entry && typeof entry === 'object' ? entry : {};
	const familyId = String(source.familyId || familyIdHint || '').replace(/[^\d]/g, '').slice(0, 5);
	if (!/^\d{5}$/.test(familyId)) return null;
	const cardsPerGame = parseInt(source.cardsPerGame, 10) || getCardsPerGameFromOrder(source);
	const tier = String(source.tier || getTierLabelFromCardsPerGame(cardsPerGame));
	const sourceUrls = source.urls && typeof source.urls === 'object' ? source.urls : {};
	const fallbackUrl = String(source.url || '').trim();
	const localhostUrl = String(sourceUrls.localhost || '').trim();
	const productionUrl = String(sourceUrls.production || '').trim();
	const fallbackIsLocal = fallbackUrl.includes('127.0.0.1') || fallbackUrl.includes('localhost');
	return {
		familyId,
		tier,
		cardsPerGame,
		urls: {
			localhost:
				localhostUrl ||
				(fallbackIsLocal ? fallbackUrl : '') ||
				buildJoinUrlFromBase(localhostJoinBase, familyId),
			production:
				productionUrl ||
				(!fallbackIsLocal ? fallbackUrl : '') ||
				buildJoinUrlFromBase(productionJoinBase, familyId),
		},
	};
}

function buildPlayersListFromOrdersMap(ordersMap) {
	const entries = ordersMap && typeof ordersMap === 'object' ? Object.entries(ordersMap) : [];
	return entries
		.map(([familyId, order]) => normalizePlayersEntry(order, familyId))
		.filter(Boolean)
		.sort((a, b) => a.familyId.localeCompare(b.familyId));
}

function shuffleList(values) {
	const list = [...values];
	for (let i = list.length - 1; i > 0; i -= 1) {
		const j = Math.floor(Math.random() * (i + 1));
		const temp = list[i];
		list[i] = list[j];
		list[j] = temp;
	}
	return list;
}

function pickRandomUnique(min, max, count) {
	const pool = Array.from({ length: (max - min) + 1 }, (_, idx) => min + idx);
	return shuffleList(pool).slice(0, count).sort((a, b) => a - b);
}

function generateRandomBingoCard() {
	const b = pickRandomUnique(1, 15, 5);
	const i = pickRandomUnique(16, 30, 5);
	const nBase = pickRandomUnique(31, 45, 4);
	const g = pickRandomUnique(46, 60, 5);
	const o = pickRandomUnique(61, 75, 5);
	const n = [nBase[0], nBase[1], 'FREE', nBase[2], nBase[3]];
	return { B: b, I: i, N: n, G: g, O: o };
}

function generateRandomBingoDeck(cardCount) {
	const safeCount = Math.max(0, parseInt(cardCount, 10) || 0);
	const uniqueCards = [];
	const seen = new Set();
	let attempts = 0;
	const maxAttempts = Math.max(200, safeCount * 120);
	while (uniqueCards.length < safeCount && attempts < maxAttempts) {
		attempts += 1;
		const card = generateRandomBingoCard();
		const signature = ['B', 'I', 'N', 'G', 'O']
			.map((letter) => (Array.isArray(card[letter]) ? card[letter].join('-') : ''))
			.join('|');
		if (seen.has(signature)) {
			continue;
		}
		seen.add(signature);
		uniqueCards.push(card);
	}
	return uniqueCards;
}

function normalizeAssignedCard(card) {
	if (!card || typeof card !== 'object') return null;
	const normalized = {
		Tier: card.Tier || card.tier || '',
		BIN: card.BIN || '',
		BCIN: card.BCIN || '',
		B: Array.isArray(card.B) ? card.B : [],
		I: Array.isArray(card.I) ? card.I : [],
		N: Array.isArray(card.N) ? card.N : [],
		G: Array.isArray(card.G) ? card.G : [],
		O: Array.isArray(card.O) ? card.O : [],
	};
	const hasValidColumns = bingoLetters.every((letter) => Array.isArray(normalized[letter]) && normalized[letter].length === 5);
	return hasValidColumns ? normalized : null;
}

function makeTierBinBcinKey(tier, bin, bcin) {
	return `${String(tier || '').toUpperCase()}|${String(bin || '').toUpperCase()}|${String(bcin || '')}`;
}

function makeBinBcinKey(bin, bcin) {
	return `${String(bin || '').toUpperCase()}|${String(bcin || '')}`;
}

function buildBingoCardLookup(cardsCatalog) {
	const byTierBinBcin = new Map();
	const byBinBcin = new Map();
	(Array.isArray(cardsCatalog) ? cardsCatalog : [])
		.map((card) => normalizeAssignedCard(card))
		.filter(Boolean)
		.forEach((card) => {
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

function resolveCardsFromAssignments(cardsAssigned, cardsCatalog) {
	if (!Array.isArray(cardsAssigned) || cardsAssigned.length === 0) {
		return [];
	}
	const lookup = buildBingoCardLookup(cardsCatalog);
	return cardsAssigned
		.map((assignment) => {
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
		})
		.filter(Boolean);
}

function normalizeOrderForGame(order, selectedGameIndex) {
	if (!order || typeof order !== 'object') return null;
	const gameNumber = parseInt(selectedGameIndex, 10) || 0;
	const games = Array.isArray(order.games) ? order.games : [];
	if (gameNumber < 1) {
		const cardsAssigned = games.flatMap((entry) => (
			Array.isArray(entry && entry.cardsAssigned) ? entry.cardsAssigned : []
		));
		const playCardDeck = Array.isArray(order.playCardDeck) ? order.playCardDeck : [];
		const totalCardsFromGames = games.reduce((sum, entry) => sum + (parseInt((entry && entry.cards) || 0, 10) || 0), 0);
		return {
			...order,
			totalCards: totalCardsFromGames || (parseInt(order.totalCards, 10) || cardsAssigned.length || 0),
			gameNumber: 0,
			cardsRemaining: 0,
			cardsAssigned,
			playCardDeck,
		};
	}
	const gameEntry = games.find((entry) => (parseInt(entry && entry.game, 10) || 0) === gameNumber);
	if (!gameEntry) return null;
	const cardsAssigned = Array.isArray(gameEntry.cardsAssigned)
		? gameEntry.cardsAssigned
		: (Array.isArray(order.cardsAssigned) ? order.cardsAssigned : []);
	const playCardDeck = Array.isArray(gameEntry.gameCardDeck)
		? gameEntry.gameCardDeck
		: (Array.isArray(order.playCardDeck) ? order.playCardDeck : []);
	return {
		...order,
		totalCards: parseInt(gameEntry.cards, 10) || 0,
		gameNumber,
		cardsRemaining: parseInt(gameEntry.cardsRemaining, 10) || 0,
		cardsAssigned,
		playCardDeck,
	};
}

function buildPlayCardDeck(orderData) {
	const assignedDeck = Array.isArray(orderData && orderData.playCardDeck) ? orderData.playCardDeck : [];
	const normalizedDeck = assignedDeck.map(normalizeAssignedCard).filter(Boolean);
	if (normalizedDeck.length > 0) {
		return normalizedDeck;
	}
	const fallbackCount = parseInt(orderData && orderData.totalCards, 10) || 0;
	return generateRandomBingoDeck(fallbackCount);
}

function isMarkedCellValue(value, calledNumbersSet, markFreeSpace = true) {
	if (value === 'FREE') return !!markFreeSpace;
	const numericValue = parseInt(value, 10);
	if (Number.isNaN(numericValue)) return false;
	return calledNumbersSet.has(numericValue);
}

function getStoredPatternConfig() {
	try {
		if (typeof window === 'undefined' || !window.localStorage) {
			return null;
		}
		const raw = window.localStorage.getItem('lpbclassicpattern');
		if (!raw) return null;
		const parsed = JSON.parse(raw);
		const pattern = parsed && parsed.pattern ? parsed.pattern : null;
		if (!pattern || typeof pattern !== 'object') return null;
		return pattern;
	} catch (e) {
		return null;
	}
}

function hasPatternSelections(pattern) {
	if (!pattern || typeof pattern !== 'object') return false;
	return bingoLetters.some((letter) => {
		const column = pattern[letter];
		return Array.isArray(column) && column.some((slot) => !!slot);
	});
}

function cardMatchesSelectedPattern(cardData, calledNumbersSet, pattern, markFreeSpace = true) {
	if (!cardData || !hasPatternSelections(pattern)) return false;
	return bingoLetters.every((letter) => {
		const patternColumn = Array.isArray(pattern[letter]) ? pattern[letter] : [];
		const cardColumn = Array.isArray(cardData[letter]) ? cardData[letter] : [];
		for (let row = 0; row < 5; row += 1) {
			if (patternColumn[row] && !isMarkedCellValue(cardColumn[row], calledNumbersSet, markFreeSpace)) {
				return false;
			}
		}
		return true;
	});
}

function cardHasClassicBingo(cardData, calledNumbersSet, markFreeSpace = true) {
	if (!cardData) return false;
	const grid = Array.from({ length: 5 }, (_, rowIndex) => (
		bingoLetters.map((letter) => {
			const column = Array.isArray(cardData[letter]) ? cardData[letter] : [];
			return column[rowIndex];
		})
	));
	const hasWinningRow = grid.some((row) => row.every((value) => isMarkedCellValue(value, calledNumbersSet, markFreeSpace)));
	if (hasWinningRow) return true;
	const hasWinningColumn = bingoLetters.some((_, colIndex) => (
		grid.every((row) => isMarkedCellValue(row[colIndex], calledNumbersSet, markFreeSpace))
	));
	if (hasWinningColumn) return true;
	const hasPrimaryDiagonal = grid.every((row, index) => isMarkedCellValue(row[index], calledNumbersSet, markFreeSpace));
	if (hasPrimaryDiagonal) return true;
	const hasSecondaryDiagonal = grid.every((row, index) => isMarkedCellValue(row[4 - index], calledNumbersSet, markFreeSpace));
	return hasSecondaryDiagonal;
}

function cardHasBingo(cardData, calledNumbersSet, pattern, markFreeSpace = true) {
	if (hasPatternSelections(pattern)) {
		return cardMatchesSelectedPattern(cardData, calledNumbersSet, pattern, markFreeSpace);
	}
	return cardHasClassicBingo(cardData, calledNumbersSet, markFreeSpace);
}

function getCentralCalendarAccessCode(offsetDays = 0) {
	try {
		const formatter = new Intl.DateTimeFormat('en-US', {
			timeZone: 'America/Chicago',
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
		});
		const parts = formatter.formatToParts(new Date());
		const month = (parts.find((part) => part.type === 'month') || {}).value || '01';
		const day = (parts.find((part) => part.type === 'day') || {}).value || '01';
		const year = (parts.find((part) => part.type === 'year') || {}).value || '1970';
		const baseDate = new Date(Date.UTC(parseInt(year, 10), parseInt(month, 10) - 1, parseInt(day, 10), 12, 0, 0));
		baseDate.setUTCDate(baseDate.getUTCDate() + (parseInt(offsetDays, 10) || 0));
		const targetMonth = String(baseDate.getUTCMonth() + 1).padStart(2, '0');
		const targetDay = String(baseDate.getUTCDate()).padStart(2, '0');
		const targetYear = String(baseDate.getUTCFullYear());
		return `${targetMonth}${targetDay}${targetYear}`;
	} catch (e) {
		const fallback = new Date();
		fallback.setDate(fallback.getDate() + (parseInt(offsetDays, 10) || 0));
		const month = String(fallback.getMonth() + 1).padStart(2, '0');
		const day = String(fallback.getDate()).padStart(2, '0');
		const year = String(fallback.getFullYear());
		return `${month}${day}${year}`;
	}
}

function getCentralDateAccessCode() {
	return getCentralCalendarAccessCode(0);
}

function getCentralTomorrowAccessCode() {
	return getCentralCalendarAccessCode(1);
}

function getBcinStart(familyId, totalCards) {
	const familyIdNumber = parseInt(String(familyId || '0'), 10) || 0;
	const totalCardCount = parseInt(String(totalCards || '0'), 10) || 0;
	return (familyIdNumber * totalCardCount) % 100000;
}

function getBcinValue(familyId, totalCards, cardIndex) {
	const start = getBcinStart(familyId, totalCards);
	return String((start + cardIndex) % 100000).padStart(5, '0');
}

function getPlayRoomMeta(totalBooks) {
	if (totalBooks <= 1) {
		return {
			key: 'bronze',
			title: 'Lucky Seat Lounge',
			welcome:
				'Welcome to the Lucky Seat Lounge, where polished entry meets steady action and every round keeps you in play.',
		};
	}
	if (totalBooks === 2) {
		return {
			key: 'silver',
			title: 'Double Down Suite',
			welcome:
				'Welcome to the Double Down Suite, where stronger position, added reach, and elevated action shape your experience each round.',
		};
	}
	if (totalBooks === 3) {
		return {
			key: 'gold',
			title: 'High Roller Salon',
			welcome:
				'Welcome to the High Roller Salon, where premium play, broader coverage, and commanding presence define the session.',
		};
	}
	return {
		key: 'platinum',
		title: 'Royal Flush Reserve',
		welcome:
			'Welcome to the Royal Flush Reserve, where top-tier standing, prime position, and full-strength action set the standard for play.',
	};
}

const newGameState = {
	balls: {
		1: { letter: 'B', number: 1, called: false, active: false },
		2: { letter: 'B', number: 2, called: false, active: false },
		3: { letter: 'B', number: 3, called: false, active: false },
		4: { letter: 'B', number: 4, called: false, active: false },
		5: { letter: 'B', number: 5, called: false, active: false },
		6: { letter: 'B', number: 6, called: false, active: false },
		7: { letter: 'B', number: 7, called: false, active: false },
		8: { letter: 'B', number: 8, called: false, active: false },
		9: { letter: 'B', number: 9, called: false, active: false },
		10: { letter: 'B', number: 10, called: false, active: false },
		11: { letter: 'B', number: 11, called: false, active: false },
		12: { letter: 'B', number: 12, called: false, active: false },
		13: { letter: 'B', number: 13, called: false, active: false },
		14: { letter: 'B', number: 14, called: false, active: false },
		15: { letter: 'B', number: 15, called: false, active: false },
		16: { letter: 'I', number: 16, called: false, active: false },
		17: { letter: 'I', number: 17, called: false, active: false },
		18: { letter: 'I', number: 18, called: false, active: false },
		19: { letter: 'I', number: 19, called: false, active: false },
		20: { letter: 'I', number: 20, called: false, active: false },
		21: { letter: 'I', number: 21, called: false, active: false },
		22: { letter: 'I', number: 22, called: false, active: false },
		23: { letter: 'I', number: 23, called: false, active: false },
		24: { letter: 'I', number: 24, called: false, active: false },
		25: { letter: 'I', number: 25, called: false, active: false },
		26: { letter: 'I', number: 26, called: false, active: false },
		27: { letter: 'I', number: 27, called: false, active: false },
		28: { letter: 'I', number: 28, called: false, active: false },
		29: { letter: 'I', number: 29, called: false, active: false },
		30: { letter: 'I', number: 30, called: false, active: false },
		31: { letter: 'N', number: 31, called: false, active: false },
		32: { letter: 'N', number: 32, called: false, active: false },
		33: { letter: 'N', number: 33, called: false, active: false },
		34: { letter: 'N', number: 34, called: false, active: false },
		35: { letter: 'N', number: 35, called: false, active: false },
		36: { letter: 'N', number: 36, called: false, active: false },
		37: { letter: 'N', number: 37, called: false, active: false },
		38: { letter: 'N', number: 38, called: false, active: false },
		39: { letter: 'N', number: 39, called: false, active: false },
		40: { letter: 'N', number: 40, called: false, active: false },
		41: { letter: 'N', number: 41, called: false, active: false },
		42: { letter: 'N', number: 42, called: false, active: false },
		43: { letter: 'N', number: 43, called: false, active: false },
		44: { letter: 'N', number: 44, called: false, active: false },
		45: { letter: 'N', number: 45, called: false, active: false },
		46: { letter: 'G', number: 46, called: false, active: false },
		47: { letter: 'G', number: 47, called: false, active: false },
		48: { letter: 'G', number: 48, called: false, active: false },
		49: { letter: 'G', number: 49, called: false, active: false },
		50: { letter: 'G', number: 50, called: false, active: false },
		51: { letter: 'G', number: 51, called: false, active: false },
		52: { letter: 'G', number: 52, called: false, active: false },
		53: { letter: 'G', number: 53, called: false, active: false },
		54: { letter: 'G', number: 54, called: false, active: false },
		55: { letter: 'G', number: 55, called: false, active: false },
		56: { letter: 'G', number: 56, called: false, active: false },
		57: { letter: 'G', number: 57, called: false, active: false },
		58: { letter: 'G', number: 58, called: false, active: false },
		59: { letter: 'G', number: 59, called: false, active: false },
		60: { letter: 'G', number: 60, called: false, active: false },
		61: { letter: 'O', number: 61, called: false, active: false },
		62: { letter: 'O', number: 62, called: false, active: false },
		63: { letter: 'O', number: 63, called: false, active: false },
		64: { letter: 'O', number: 64, called: false, active: false },
		65: { letter: 'O', number: 65, called: false, active: false },
		66: { letter: 'O', number: 66, called: false, active: false },
		67: { letter: 'O', number: 67, called: false, active: false },
		68: { letter: 'O', number: 68, called: false, active: false },
		69: { letter: 'O', number: 69, called: false, active: false },
		70: { letter: 'O', number: 70, called: false, active: false },
		71: { letter: 'O', number: 71, called: false, active: false },
		72: { letter: 'O', number: 72, called: false, active: false },
		73: { letter: 'O', number: 73, called: false, active: false },
		74: { letter: 'O', number: 74, called: false, active: false },
		75: { letter: 'O', number: 75, called: false, active: false },
	},
	newGame: true,
	running: false,
	callHistory: [],
};

class LetsPlayBingo extends Component {
	/*
	 * Constructor
	 * State Variables
	 * balls: balls object, holds letter, number, called and active statues
	 * running: determines if the game is presently running
	 * interval & delay: how often the balls are generated
	 */
	constructor(props) {
		super(props);
		this.radioAudio = null;
		this.radioMetadataTimer = null;
		this.radioAudioContext = null;
		this.radioAnalyser = null;
		this.radioSourceNode = null;
		this.radioAnimationFrame = null;
		this.radioFrequencyData = null;
		this.sharedSessionPoller = null;
		this.sharedSessionUpdatedAt = '';
		this.isApplyingSharedSession = false;
		this.deepLinkFamilyId = '';
		this.deepLinkLoadedKey = '';
		try {
			window.name = 'lpb_caller_window';
		} catch (e) {}

		this.state = {
			showAlert: false,
			showConfirm: false,
			showBackdrop: false,
			confirmTitle: '',
			confirmMessage: '',
			confirmButtonText: 'Confirm',
			radioPlaying: false,
			radioNowPlaying: '',
			radioVisualizerLevels: Array.from({ length: 16 }, () => 0.18),
			balls: {
				1: { letter: 'B', number: 1, called: false, active: false },
				2: { letter: 'B', number: 2, called: false, active: false },
				3: { letter: 'B', number: 3, called: false, active: false },
				4: { letter: 'B', number: 4, called: false, active: false },
				5: { letter: 'B', number: 5, called: false, active: false },
				6: { letter: 'B', number: 6, called: false, active: false },
				7: { letter: 'B', number: 7, called: false, active: false },
				8: { letter: 'B', number: 8, called: false, active: false },
				9: { letter: 'B', number: 9, called: false, active: false },
				10: { letter: 'B', number: 10, called: false, active: false },
				11: { letter: 'B', number: 11, called: false, active: false },
				12: { letter: 'B', number: 12, called: false, active: false },
				13: { letter: 'B', number: 13, called: false, active: false },
				14: { letter: 'B', number: 14, called: false, active: false },
				15: { letter: 'B', number: 15, called: false, active: false },
				16: { letter: 'I', number: 16, called: false, active: false },
				17: { letter: 'I', number: 17, called: false, active: false },
				18: { letter: 'I', number: 18, called: false, active: false },
				19: { letter: 'I', number: 19, called: false, active: false },
				20: { letter: 'I', number: 20, called: false, active: false },
				21: { letter: 'I', number: 21, called: false, active: false },
				22: { letter: 'I', number: 22, called: false, active: false },
				23: { letter: 'I', number: 23, called: false, active: false },
				24: { letter: 'I', number: 24, called: false, active: false },
				25: { letter: 'I', number: 25, called: false, active: false },
				26: { letter: 'I', number: 26, called: false, active: false },
				27: { letter: 'I', number: 27, called: false, active: false },
				28: { letter: 'I', number: 28, called: false, active: false },
				29: { letter: 'I', number: 29, called: false, active: false },
				30: { letter: 'I', number: 30, called: false, active: false },
				31: { letter: 'N', number: 31, called: false, active: false },
				32: { letter: 'N', number: 32, called: false, active: false },
				33: { letter: 'N', number: 33, called: false, active: false },
				34: { letter: 'N', number: 34, called: false, active: false },
				35: { letter: 'N', number: 35, called: false, active: false },
				36: { letter: 'N', number: 36, called: false, active: false },
				37: { letter: 'N', number: 37, called: false, active: false },
				38: { letter: 'N', number: 38, called: false, active: false },
				39: { letter: 'N', number: 39, called: false, active: false },
				40: { letter: 'N', number: 40, called: false, active: false },
				41: { letter: 'N', number: 41, called: false, active: false },
				42: { letter: 'N', number: 42, called: false, active: false },
				43: { letter: 'N', number: 43, called: false, active: false },
				44: { letter: 'N', number: 44, called: false, active: false },
				45: { letter: 'N', number: 45, called: false, active: false },
				46: { letter: 'G', number: 46, called: false, active: false },
				47: { letter: 'G', number: 47, called: false, active: false },
				48: { letter: 'G', number: 48, called: false, active: false },
				49: { letter: 'G', number: 49, called: false, active: false },
				50: { letter: 'G', number: 50, called: false, active: false },
				51: { letter: 'G', number: 51, called: false, active: false },
				52: { letter: 'G', number: 52, called: false, active: false },
				53: { letter: 'G', number: 53, called: false, active: false },
				54: { letter: 'G', number: 54, called: false, active: false },
				55: { letter: 'G', number: 55, called: false, active: false },
				56: { letter: 'G', number: 56, called: false, active: false },
				57: { letter: 'G', number: 57, called: false, active: false },
				58: { letter: 'G', number: 58, called: false, active: false },
				59: { letter: 'G', number: 59, called: false, active: false },
				60: { letter: 'G', number: 60, called: false, active: false },
				61: { letter: 'O', number: 61, called: false, active: false },
				62: { letter: 'O', number: 62, called: false, active: false },
				63: { letter: 'O', number: 63, called: false, active: false },
				64: { letter: 'O', number: 64, called: false, active: false },
				65: { letter: 'O', number: 65, called: false, active: false },
				66: { letter: 'O', number: 66, called: false, active: false },
				67: { letter: 'O', number: 67, called: false, active: false },
				68: { letter: 'O', number: 68, called: false, active: false },
				69: { letter: 'O', number: 69, called: false, active: false },
				70: { letter: 'O', number: 70, called: false, active: false },
				71: { letter: 'O', number: 71, called: false, active: false },
				72: { letter: 'O', number: 72, called: false, active: false },
				73: { letter: 'O', number: 73, called: false, active: false },
				74: { letter: 'O', number: 74, called: false, active: false },
				75: { letter: 'O', number: 75, called: false, active: false },
			},
			newGame: true,
			running: false,
			callHistory: [],
			activeScreen: 'caller',
			familyIdInput: '',
			orderTotalInput: '',
			orderSaveMessage: '',
			playFamilyIdInput: '',
			playLookupError: '',
			playLookupLoading: false,
			playOrderData: null,
			playCardDeck: [],
			playersList: [],
			playersLoading: false,
			playersError: '',
			bingoDetectedPin: '',
			playPage: 0,
			patternResetToken: 0,
			hostVerified: false,
			hostAccessCode: '',
			boardControlState: 'needs_host',
			showHostAccessDialog: false,
			hostAccessInput: '',
			hostAccessError: '',
			showHostSignoutDialog: false,
			hostSignoutDateInput: '',
			hostSignoutError: '',
			headerMenuSelection: '',
			showOpenTableDialog: false,
			openTableDeal: openTableDeals[0],
			selectedTableDeal: '',
			selectedTableDealIndex: 0,
			interval: 0,
			delay: 10000,
		};
		this.pendingConfirmAction = null;
		const cache = JSON.parse(localStorage.getItem('lpbclassic'));
		if (cache) {
			if (Object.keys(cache).length > 0) {
				// there's a cache available, apply to this.state
				const ignoredKeys = [
					'showAlert',
					'showBackdrop',
					'running',
				];
				Object.keys(cache).forEach((key) => {
					if (!ignoredKeys.includes(key)) {
						// If the key is not ignored, update this.state with the cached value
						this.state[key] = cache[key];
					}
				});
				this.state.running = false;
			}
		}
		let now = new Date();
		now = now.getTime();
		let unloadTime = localStorage.getItem('lpb-unloadtime');
		if (unloadTime) {
			unloadTime = new Date(JSON.parse(unloadTime));
			unloadTime = unloadTime.getTime();
			const timeDiff = now - unloadTime;

			if (timeDiff < 500) {
				// this is a reload event. reload the game.
				Object.keys(newGameState).forEach((key) => {
					this.state[key] = newGameState[key];
				});
			}
		}

		const isOnIOS =
			navigator.userAgent.match(/iPad/i) ||
			navigator.userAgent.match(/iPhone/i);
		const eventName = isOnIOS ? 'pagehide' : 'beforeunload';

		window.addEventListener(eventName, function () {
			let unloadingTime = new Date();
			unloadingTime = unloadingTime.getTime();
			localStorage.setItem('lpb-unloadtime', JSON.stringify(unloadingTime));
		});
	}

	componentDidMount() {
		if (!isLocalDevRuntime() && this.state.activeScreen === 'order') {
			this.setState({ activeScreen: 'caller', orderSaveMessage: '' });
		}
		this.fetchSharedSession();
		this.startSharedSessionPolling();
		if (this.state.activeScreen === 'players') {
			this.loadPlayersList();
		}
		const deepLinkFamilyId = getDeepLinkFamilyIdFromUrl();
		if (deepLinkFamilyId) {
			this.deepLinkFamilyId = deepLinkFamilyId;
			this.setState(
				{
					activeScreen: 'join_session',
					playFamilyIdInput: deepLinkFamilyId,
					playLookupError: '',
				},
				() => {
					this.maybeLoadDeepLinkedOrder();
				}
			);
		}
	}

	componentWillUnmount() {
		if (this.sharedSessionPoller) {
			clearInterval(this.sharedSessionPoller);
			this.sharedSessionPoller = null;
		}
		if (this.state.interval) {
			clearInterval(this.state.interval);
		}
		this.stopRadioVisualizer();
		if (this.radioMetadataTimer) {
			clearInterval(this.radioMetadataTimer);
			this.radioMetadataTimer = null;
		}
		if (this.radioAudio) {
			try {
				this.radioAudio.removeEventListener('pause', this.handleRadioAudioPause);
				this.radioAudio.removeEventListener('ended', this.handleRadioAudioEnded);
				this.radioAudio.removeEventListener('error', this.handleRadioAudioError);
				this.radioAudio.removeEventListener('abort', this.handleRadioAudioError);
				this.radioAudio.removeEventListener('emptied', this.handleRadioAudioError);
				this.radioAudio.pause();
				this.radioAudio.src = '';
			} catch (e) {}
			this.radioAudio = null;
		}
		return undefined;
	}

	getSharedSessionSnapshot = (stateRef) => {
		const sourceState = stateRef || this.state;
		const sourceBalls = sourceState && sourceState.balls ? sourceState.balls : {};
		return {
			balls: _.mapObject(newGameState.balls, (baseBall, key) => {
				const ball = sourceBalls[key] || baseBall;
				return {
					letter: baseBall.letter,
					number: baseBall.number,
					called: !!ball.called,
					active: !!ball.active,
				};
			}),
			callHistory: (Array.isArray(sourceState.callHistory) ? sourceState.callHistory : [])
				.map((entry) => ({
					letter: String((entry && entry.letter) || '').slice(0, 1),
					number: parseInt(entry && entry.number, 10) || 0,
				}))
				.filter((entry) => entry.letter && entry.number > 0)
				.slice(-75),
			newGame: !!sourceState.newGame,
			running: !!sourceState.running,
			selectedTableDeal: String(sourceState.selectedTableDeal || ''),
			selectedTableDealIndex: parseInt(sourceState.selectedTableDealIndex, 10) || 0,
			boardControlState: String(sourceState.boardControlState || 'needs_host'),
			bingoDetectedPin: String(sourceState.bingoDetectedPin || ''),
			patternResetToken: parseInt(sourceState.patternResetToken, 10) || 0,
		};
	};

	applySharedSessionState = (session) => {
		if (!session || typeof session !== 'object') {
			return;
		}
		if (this.state.interval && !session.running) {
			clearInterval(this.state.interval);
		}
		const nextBalls = _.mapObject(newGameState.balls, (baseBall, key) => {
			const ball = session.balls && session.balls[key] ? session.balls[key] : baseBall;
			return {
				letter: baseBall.letter,
				number: baseBall.number,
				called: !!ball.called,
				active: !!ball.active,
			};
		});
		this.isApplyingSharedSession = true;
		this.setState(
			{
				balls: nextBalls,
				callHistory: Array.isArray(session.callHistory) ? session.callHistory.slice(-75) : [],
				newGame: typeof session.newGame === 'boolean' ? session.newGame : true,
				running: typeof session.running === 'boolean' ? session.running : false,
				selectedTableDeal: String(session.selectedTableDeal || ''),
				selectedTableDealIndex: parseInt(session.selectedTableDealIndex, 10) || 0,
				boardControlState: String(session.boardControlState || 'needs_host'),
				bingoDetectedPin: String(session.bingoDetectedPin || ''),
				patternResetToken: parseInt(session.patternResetToken, 10) || 0,
			},
			() => {
				this.isApplyingSharedSession = false;
			}
		);
	};

	fetchSharedSession = async () => {
		const endpoint = getSharedSessionEndpoint();
		if (!endpoint) {
			return;
		}
		try {
			const response = await fetch(endpoint, {
				method: 'GET',
			});
			if (!response.ok) {
				return;
			}
			const result = await response.json();
			const nextUpdatedAt = result && result.updatedAt ? String(result.updatedAt) : '';
			if (nextUpdatedAt && nextUpdatedAt === this.sharedSessionUpdatedAt) {
				return;
			}
			this.sharedSessionUpdatedAt = nextUpdatedAt;
			this.applySharedSessionState(result && result.session ? result.session : null);
		} catch (e) {}
	};

	startSharedSessionPolling = () => {
		if (this.sharedSessionPoller) {
			clearInterval(this.sharedSessionPoller);
		}
		this.sharedSessionPoller = setInterval(() => {
			this.fetchSharedSession();
		}, 1500);
	};

	publishSharedSession = async (stateRef) => {
		const endpoint = getSharedSessionEndpoint();
		const hostKey = String(
			(stateRef && stateRef.hostAccessCode) ||
			this.state.hostAccessCode ||
			''
		).trim();
		if (!endpoint || !hostKey) {
			return;
		}
		try {
			const response = await fetch(endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify({
					hostKey,
					session: this.getSharedSessionSnapshot(stateRef),
				}),
			});
			if (!response.ok) {
				return;
			}
			const result = await response.json();
			if (result && result.updatedAt) {
				this.sharedSessionUpdatedAt = String(result.updatedAt);
			}
		} catch (e) {}
	};

	stopRadioVisualizer = () => {
		if (this.radioAnimationFrame) {
			cancelAnimationFrame(this.radioAnimationFrame);
			this.radioAnimationFrame = null;
		}
		if (this.radioAnalyser) {
			try {
				this.radioAnalyser.disconnect();
			} catch (e) {}
			this.radioAnalyser = null;
		}
		if (this.radioSourceNode) {
			try {
				this.radioSourceNode.disconnect();
			} catch (e) {}
			this.radioSourceNode = null;
		}
		if (this.radioAudioContext) {
			try {
				this.radioAudioContext.close();
			} catch (e) {}
			this.radioAudioContext = null;
		}
		this.radioFrequencyData = null;
		this.setState({
			radioVisualizerLevels: Array.from({ length: 16 }, () => 0.18),
		});
	};

	updateRadioVisualizerFrame = () => {
		if (!this.radioAnalyser || !this.radioFrequencyData || !this.state.radioPlaying) {
			return;
		}
		this.radioAnalyser.getByteFrequencyData(this.radioFrequencyData);
		const bucketCount = 16;
		const valuesPerBucket = Math.max(1, Math.floor(this.radioFrequencyData.length / bucketCount));
		const nextLevels = Array.from({ length: bucketCount }, (_, bucketIndex) => {
			const start = bucketIndex * valuesPerBucket;
			const end = Math.min(this.radioFrequencyData.length, start + valuesPerBucket);
			let total = 0;
			let count = 0;
			for (let i = start; i < end; i += 1) {
				total += this.radioFrequencyData[i];
				count += 1;
			}
			const average = count ? total / count : 0;
			return Math.max(0.16, Math.min(1, average / 255));
		});
		this.setState({ radioVisualizerLevels: nextLevels });
		this.radioAnimationFrame = requestAnimationFrame(this.updateRadioVisualizerFrame);
	};

	startRadioVisualizer = async () => {
		if (!this.radioAudio) {
			return;
		}
		const AudioContextClass = window.AudioContext || window.webkitAudioContext;
		if (!AudioContextClass) {
			return;
		}
		if (!this.radioAudioContext) {
			this.radioAudioContext = new AudioContextClass();
		}
		if (this.radioAudioContext.state === 'suspended') {
			try {
				await this.radioAudioContext.resume();
			} catch (e) {}
		}
		if (!this.radioSourceNode) {
			this.radioSourceNode = this.radioAudioContext.createMediaElementSource(this.radioAudio);
		}
		if (!this.radioAnalyser) {
			this.radioAnalyser = this.radioAudioContext.createAnalyser();
			this.radioAnalyser.fftSize = 64;
			this.radioAnalyser.smoothingTimeConstant = 0.82;
			this.radioFrequencyData = new Uint8Array(this.radioAnalyser.frequencyBinCount);
			this.radioSourceNode.connect(this.radioAnalyser);
			this.radioAnalyser.connect(this.radioAudioContext.destination);
		}
		if (this.radioAnimationFrame) {
			cancelAnimationFrame(this.radioAnimationFrame);
		}
		this.radioAnimationFrame = requestAnimationFrame(this.updateRadioVisualizerFrame);
	};

	stopRadioPlayback = () => {
		this.stopRadioVisualizer();
		if (this.radioMetadataTimer) {
			clearInterval(this.radioMetadataTimer);
			this.radioMetadataTimer = null;
		}
		if (this.radioAudio) {
			try {
				this.radioAudio.pause();
			} catch (e) {}
		}
		this.setState({
			radioPlaying: false,
			radioNowPlaying: '',
			radioVisualizerLevels: Array.from({ length: 16 }, () => 0.18),
		});
	};

	handleRadioAudioPause = () => {
		if (!this.radioAudio || this.radioAudio.ended) {
			return;
		}
		this.stopRadioPlayback();
	};

	handleRadioAudioEnded = () => {
		this.stopRadioPlayback();
	};

	handleRadioAudioError = () => {
		this.stopRadioPlayback();
	};

	parseRadioMetadata = (rawText) => {
		const text = String(rawText || '').trim();
		if (!text) return '';
		const markdownMarker = 'Markdown Content:';
		const body = text.includes(markdownMarker)
			? text.split(markdownMarker).pop()
			: text;
		const lines = body
			.split('\n')
			.map((line) => line.trim())
			.filter(Boolean)
			.filter((line) => !/^Title:\s*/i.test(line))
			.filter((line) => !/^URL Source:\s*/i.test(line))
			.filter((line) => !/^Published Time:\s*/i.test(line));
		return lines.length ? lines[lines.length - 1] : '';
	};

	parseRadioNowPlayingParts = (nowPlaying) => {
		const text = String(nowPlaying || '').trim();
		if (!text) {
			return { artist: '', track: '' };
		}
		const separators = [' - ', ' – ', ' — ', '-'];
		for (let i = 0; i < separators.length; i += 1) {
			const separator = separators[i];
			const parts = text.split(separator).map((part) => part.trim()).filter(Boolean);
			if (parts.length >= 2) {
				return {
					artist: parts[0],
					track: parts.slice(1).join(' - '),
				};
			}
		}
		return { artist: '', track: text };
	};

	fetchRadioMetadata = async () => {
		try {
			const response = await fetch(radioMetadataUrl, { method: 'GET' });
			if (!response.ok) return;
			const rawText = await response.text();
			const nowPlaying = this.parseRadioMetadata(rawText);
			if (nowPlaying) {
				this.setState({ radioNowPlaying: nowPlaying });
			}
		} catch (e) {}
	};

	startRadioMetadataPolling = () => {
		if (this.radioMetadataTimer) {
			clearInterval(this.radioMetadataTimer);
		}
		this.fetchRadioMetadata();
		this.radioMetadataTimer = setInterval(this.fetchRadioMetadata, 15000);
	};

	getCalledNumbersSet = (ballsState) => {
		const sourceBalls = ballsState || {};
		return new Set(
			Object.keys(sourceBalls)
				.map((key) => sourceBalls[key])
				.filter((ball) => ball && ball.called)
				.map((ball) => parseInt(ball.number, 10))
				.filter((number) => !Number.isNaN(number))
		);
	};

	hasBingoInState = (stateRef) => {
		const state = stateRef || this.state;
		const deck = Array.isArray(state.playCardDeck) ? state.playCardDeck : [];
		if (!deck.length) return false;
		const calledNumbersSet = this.getCalledNumbersSet(state.balls);
		const selectedPattern = getStoredPatternConfig();
		const markFreeSpace = this.isGameInSession(state);
		return deck.some((cardData) => cardHasBingo(cardData, calledNumbersSet, selectedPattern, markFreeSpace));
	};

	createBingoPin = () => {
		const now = new Date();
		const hour24 = now.getHours();
		const hour12 = (hour24 % 12) || 12;
		const hh = String(hour12).padStart(2, '0');
		const mm = String(now.getMinutes()).padStart(2, '0');
		const ss = String(now.getSeconds()).padStart(2, '0');
		const cs = String(Math.floor(now.getMilliseconds() / 10)).padStart(2, '0');
		return `${hh}${mm}${ss}${cs}`;
	};

	componentDidUpdate(prevProps, prevState) {
		let stateCopy = { ...this.state };
		delete stateCopy.showAlert;
		delete stateCopy.showBackdrop;
		localStorage.setItem('lpbclassic', JSON.stringify(stateCopy));
		const didGameSelectionChange =
			prevState.selectedTableDeal !== this.state.selectedTableDeal ||
			prevState.selectedTableDealIndex !== this.state.selectedTableDealIndex;
		if (
			this.deepLinkFamilyId &&
			(didGameSelectionChange || prevState.playFamilyIdInput !== this.state.playFamilyIdInput)
		) {
			this.maybeLoadDeepLinkedOrder();
		} else if (
			didGameSelectionChange &&
			(this.state.activeScreen === 'join_session' || this.state.activeScreen === 'play') &&
			/^\d{5}$/.test(String(this.state.playFamilyIdInput || '').replace(/[^\d]/g, '').slice(0, 5))
		) {
			this.loadPlayOrder();
		}
	};

	maybeLoadDeepLinkedOrder = () => {
		const deepLinkFamilyId = String(this.deepLinkFamilyId || '').replace(/[^\d]/g, '').slice(0, 5);
		const selectedGameIndex = this.getSelectedGameIndexOrZero();
		if (!/^\d{5}$/.test(deepLinkFamilyId)) {
			return;
		}
		const loadKey = `${deepLinkFamilyId}:${selectedGameIndex > 0 ? selectedGameIndex : 'all'}`;
		if (this.deepLinkLoadedKey === loadKey) {
			return;
		}
		this.deepLinkLoadedKey = loadKey;
		if (String(this.state.playFamilyIdInput || '') !== deepLinkFamilyId) {
			this.setState({ playFamilyIdInput: deepLinkFamilyId, playLookupError: '' }, () => {
				this.loadPlayOrder();
			});
			return;
		}
		this.loadPlayOrder();
	};

	getSelectedGameIndexOrZero = () => {
		const selectedGameIndex = parseInt(this.state.selectedTableDealIndex, 10) || 0;
		if (selectedGameIndex >= 1 && selectedGameIndex <= 6) {
			return selectedGameIndex;
		}
		return 0;
	};

	isGameInSession = (stateRef) => {
		const sourceState = stateRef || this.state;
		const selectedGameIndex = parseInt(sourceState.selectedTableDealIndex, 10) || 0;
		const hasSelectedDeal = !!String(sourceState.selectedTableDeal || '').trim();
		return hasSelectedDeal && selectedGameIndex >= 1 && selectedGameIndex <= 6;
	};

	say = () => {};

	cancelSpeech = () => {};

	broadcastCurrentCall = (ball) => {
		if (!ball || !ball.letter || !ball.number) return;
		try {
			localStorage.setItem('lpbclassic_current_call', JSON.stringify({
				letter: ball.letter,
				number: ball.number,
				call: ball.letter + '' + ball.number,
				ts: Date.now(),
			}));
		} catch (e) {}
	};

	confirmAction = (title, message, action, buttonText = 'Confirm') => {
		this.pendingConfirmAction = action;
		document.body.classList.add('backdrop-visible');
		this.setState({
			showConfirm: true,
			showBackdrop: true,
			confirmTitle: title,
			confirmMessage: message,
			confirmButtonText: buttonText,
		});
	};

	closeConfirm = () => {
		this.pendingConfirmAction = null;
		document.body.classList.remove('backdrop-visible');
		this.setState({
			showConfirm: false,
			showBackdrop: false,
			confirmTitle: '',
			confirmMessage: '',
			confirmButtonText: 'Confirm',
		});
	};

	proceedConfirm = () => {
		const action = this.pendingConfirmAction;
		this.pendingConfirmAction = null;
		document.body.classList.remove('backdrop-visible');
		this.setState(
			{
				showConfirm: false,
				showBackdrop: false,
				confirmTitle: '',
				confirmMessage: '',
				confirmButtonText: 'Confirm',
			},
			() => {
				if (typeof action === 'function') action();
			}
		);
	};

	handleDraw = () => {
		this.confirmAction(
			'Start Draw',
			'Start the live draw and put the board in play. Confirm to continue.',
			() => {
				this.startGame();
				this.setState({ boardControlState: 'drawing' }, () => {
					this.publishSharedSession();
				});
			},
			'Start Draw'
		);
	};

	handleHoldDraw = () => {
		this.confirmAction(
			'Hold Draw',
			'Place the draw on hold for the current round. Confirm to continue.',
			() => {
				this.pauseGame();
				this.setState({ boardControlState: 'paused' }, () => {
					this.publishSharedSession();
				});
			},
			'Hold Draw'
		);
	};

	handleResume = () => {
		this.confirmAction(
			'Resume Draw',
			'Return the draw to play for the current round. Confirm to continue.',
			() => {
				this.resumeGame();
				this.setState({ boardControlState: 'table_ready' }, () => {
					this.publishSharedSession();
				});
			},
			'Resume Draw'
		);
	};

	handleBingo = () => {
		this.pauseGame();
		if (!this.state.bingoDetectedPin) {
			this.setState({ bingoDetectedPin: this.createBingoPin() });
		}
	};

	handleReset = () => {
		this.confirmAction(
			'Clear Board',
			'Clear the board, remove all called balls, and clear loaded session cards. Confirm to continue.',
			this.resetBoard,
			'Clear Board'
		);
	};

	handleCloseTable = () => {
		this.confirmAction(
			'Close Floor',
			'Close the floor, clear the active session, reset the board, and sign out host access. Confirm to continue.',
			this.closeTable,
			'Close Floor'
		);
	};

	openRadio = async () => {
		if (this.state.radioPlaying) {
			this.stopRadioPlayback();
			return;
		}
		if (!this.radioAudio) {
			const audio = new Audio(radioStreamUrl);
			audio.preload = 'none';
			audio.crossOrigin = 'anonymous';
			audio.addEventListener('pause', this.handleRadioAudioPause);
			audio.addEventListener('ended', this.handleRadioAudioEnded);
			audio.addEventListener('error', this.handleRadioAudioError);
			audio.addEventListener('abort', this.handleRadioAudioError);
			audio.addEventListener('emptied', this.handleRadioAudioError);
			this.radioAudio = audio;
		}
		try {
			await this.radioAudio.play();
			this.setState({ radioPlaying: true });
			await this.startRadioVisualizer();
			this.startRadioMetadataPolling();
		} catch (e) {
			window.open(radioStreamUrl, '_blank', 'noopener,noreferrer');
		}
	};

	openHostAccessDialog = () => {
		if (this.state.hostVerified) {
			document.body.classList.add('backdrop-visible');
			this.setState({
				showHostSignoutDialog: true,
				hostSignoutDateInput: '',
				hostSignoutError: '',
			});
			return;
		}
		document.body.classList.add('backdrop-visible');
		this.setState({
			showHostAccessDialog: true,
			hostAccessInput: '',
			hostAccessError: '',
		});
	};

	closeHostAccessDialog = () => {
		if (!this.state.showConfirm && !this.state.showAlert && !this.state.showOpenTableDialog && !this.state.showHostSignoutDialog) {
			document.body.classList.remove('backdrop-visible');
		}
		this.setState({
			showHostAccessDialog: false,
			hostAccessError: '',
		});
	};

	closeHostSignoutDialog = () => {
		if (!this.state.showConfirm && !this.state.showAlert && !this.state.showOpenTableDialog && !this.state.showHostAccessDialog) {
			document.body.classList.remove('backdrop-visible');
		}
		this.setState({
			showHostSignoutDialog: false,
			hostSignoutDateInput: '',
			hostSignoutError: '',
		});
	};

	handleHostAccessInputChange = (e) => {
		const cleanedValue = String(e.target.value || '').replace(/[^\d]/g, '').slice(0, 8);
		this.setState({
			hostAccessInput: cleanedValue,
			hostAccessError: '',
		});
	};

	handleHostSignoutDateInputChange = (e) => {
		const cleanedValue = String(e.target.value || '').replace(/[^\d]/g, '').slice(0, 8);
		this.setState({
			hostSignoutDateInput: cleanedValue,
			hostSignoutError: '',
		});
	};

	confirmHostSignout = () => {
		const expected = getCentralTomorrowAccessCode();
		const provided = String(this.state.hostSignoutDateInput || '').replace(/[^\d]/g, '').slice(0, 8);
		if (provided !== expected) {
			this.setState({ hostSignoutError: "Enter tomorrow's date (MMDDYYYY) to continue." });
			return;
		}
		this.closeTable();
	};

	verifyHostAccess = () => {
		const expected = getCentralDateAccessCode();
		const provided = String(this.state.hostAccessInput || '').replace(/[^\d]/g, '').slice(0, 8);
		if (provided !== expected) {
			this.setState({ hostAccessError: 'Access Denied' });
			return;
		}
		document.body.classList.remove('backdrop-visible');
		this.setState(
			{
				showHostAccessDialog: false,
				hostVerified: true,
				hostAccessCode: provided,
				boardControlState: 'host_ready',
				hostAccessError: '',
			},
			() => {
				this.publishSharedSession();
			}
		);
	};

	openTableDialog = () => {
		if (!this.state.hostVerified) {
			return;
		}
		document.body.classList.add('backdrop-visible');
		this.setState({ showOpenTableDialog: true });
	};

	closeOpenTableDialog = () => {
		if (!this.state.showConfirm && !this.state.showAlert) {
			document.body.classList.remove('backdrop-visible');
		}
		this.setState({ showOpenTableDialog: false });
	};

	handleOpenTableDealChange = (e) => {
		this.setState({ openTableDeal: String(e.target.value || openTableDeals[0]) });
	};

	applyOpenTableDeal = () => {
		const selectedDeal = String(this.state.openTableDeal || openTableDeals[0]);
		const selectedIndex = Math.max(0, openTableDeals.indexOf(selectedDeal)) + 1;
		document.body.classList.remove('backdrop-visible');
		this.setState(
			{
				showOpenTableDialog: false,
				selectedTableDeal: selectedDeal,
				selectedTableDealIndex: selectedIndex,
				boardControlState: 'table_ready',
				bingoDetectedPin: '',
			},
			() => {
				this.publishSharedSession();
			}
		);
	};

	openOrderScreen = () => {
		if (!isLocalDevRuntime()) {
			this.setState({
				activeScreen: 'caller',
				orderSaveMessage: 'Order/Generate is only available on localhost dev server.',
			});
			return;
		}
		this.setState({ activeScreen: 'order' });
	};

	closeOrderScreen = () => {
		this.setState({ activeScreen: 'caller', orderSaveMessage: '' });
	};

	openPlayScreen = () => {
		this.setState({
			activeScreen: 'join_session',
			playLookupError: '',
			playLookupLoading: false,
		});
	};

	openPlayersScreen = () => {
		this.setState({
			activeScreen: 'players',
			playersError: '',
		}, () => {
			this.loadPlayersList();
		});
	};

	closePlayScreen = () => {
		this.setState({
			activeScreen: 'caller',
		});
	};

	loadPlayersList = async () => {
		this.setState({
			playersLoading: true,
			playersError: '',
		});
		try {
			let playersList = [];
			try {
				const response = await fetch('/api/books');
				if (!response.ok) {
					throw new Error('api_not_found');
				}
				const result = await response.json();
				const apiPlayers = Array.isArray(result && result.players) ? result.players : [];
				playersList = apiPlayers
					.map((entry) => normalizePlayersEntry(entry))
					.filter(Boolean)
					.sort((a, b) => a.familyId.localeCompare(b.familyId));
			} catch (apiError) {
				const publicUrl = process.env.PUBLIC_URL || '';
				const staticBooksUrl = `${publicUrl}/Books.json`;
				const staticResponse = await fetch(staticBooksUrl);
				if (!staticResponse.ok) {
					throw new Error('not_found');
				}
				const staticData = await staticResponse.json();
				playersList = buildPlayersListFromOrdersMap(staticData && staticData.orders ? staticData.orders : {});
			}
			this.setState({
				playersLoading: false,
				playersList,
				playersError: '',
			});
		} catch (e) {
			this.setState({
				playersLoading: false,
				playersList: [],
				playersError: 'Unable to load players list.',
			});
		}
	};

	handleHeaderMenuChange = (e) => {
		const selected = String(e.target.value || '');
		if (selected === 'board') {
			this.setState({
				headerMenuSelection: '',
				activeScreen: 'caller',
				orderSaveMessage: '',
			});
			return;
		}
		if (selected === 'join_session') {
			this.setState({ headerMenuSelection: '' });
			this.openPlayScreen();
			return;
		}
		if (selected === 'players') {
			this.setState({ headerMenuSelection: '' });
			this.openPlayersScreen();
			return;
		}
		if (selected === 'order') {
			if (!isLocalDevRuntime()) {
				this.setState({
					headerMenuSelection: '',
					activeScreen: 'caller',
					orderSaveMessage: 'Order/Generate is only available on localhost dev server.',
				});
				return;
			}
			this.setState({ headerMenuSelection: '' });
			this.openOrderScreen();
			return;
		}
		this.setState({ headerMenuSelection: selected });
	};

	handleOrderTotalChange = (e) => {
		const cleanedValue = String(e.target.value || '').replace(/[^\d.]/g, '');
		this.setState({ orderTotalInput: cleanedValue });
	};

	handleFamilyIdChange = (e) => {
		const cleanedValue = String(e.target.value || '').replace(/[^\d]/g, '').slice(0, 5);
		this.setState({ familyIdInput: cleanedValue });
	};

	handlePlayFamilyIdChange = (e) => {
		const cleanedValue = String(e.target.value || '').replace(/[^\d]/g, '').slice(0, 5);
		const isValidFamilyId = /^\d{5}$/.test(cleanedValue);
		this.setState({
			playFamilyIdInput: cleanedValue,
			playLookupError: '',
			...(isValidFamilyId
				? {}
				: {
					playOrderData: null,
					playCardDeck: [],
					bingoDetectedPin: '',
					playPage: 0,
				}),
		}, () => {
			if (isValidFamilyId) {
				this.loadPlayOrder();
			}
		});
	};

	loadPlayOrder = async () => {
		const familyId = String(this.state.playFamilyIdInput || '').replace(/[^\d]/g, '').slice(0, 5);
		const selectedGameIndex = this.getSelectedGameIndexOrZero();
		if (!/^\d{5}$/.test(familyId)) {
			this.setState({
				playLookupError: 'Enter a valid 5 digit Family ID.',
				playOrderData: null,
				playCardDeck: [],
				bingoDetectedPin: '',
				playPage: 0,
			});
			return;
		}
		this.setState({
			playLookupLoading: true,
			playLookupError: '',
		});
		try {
			let orderData = null;
			let staticBingoCardsCatalog = [];
			try {
				const endpoint = selectedGameIndex > 0
					? `/api/books/${familyId}?game=${selectedGameIndex}`
					: `/api/books/${familyId}`;
				const response = await fetch(endpoint);
				if (!response.ok) {
					throw new Error('api_not_found');
				}
				const result = await response.json();
				const apiOrder = result && result.order ? result.order : null;
				orderData = normalizeOrderForGame(apiOrder, selectedGameIndex) || apiOrder;
			} catch (apiError) {
				const publicUrl = process.env.PUBLIC_URL || '';
				const staticBooksUrl = `${publicUrl}/Books.json`;
				const staticResponse = await fetch(staticBooksUrl);
				if (!staticResponse.ok) {
					throw new Error('not_found');
				}
				const staticData = await staticResponse.json();
				const staticOrder =
					staticData &&
					staticData.orders &&
					staticData.orders[familyId]
						? staticData.orders[familyId]
						: null;
				orderData = normalizeOrderForGame(staticOrder, selectedGameIndex) || staticOrder;
				try {
					const staticCardsUrl = `${publicUrl}/Bingo%20Cards.json`;
					const cardsResponse = await fetch(staticCardsUrl);
					if (cardsResponse.ok) {
						const cardsData = await cardsResponse.json();
						staticBingoCardsCatalog = Array.isArray(cardsData && cardsData.cards) ? cardsData.cards : [];
					}
				} catch (cardsError) {}
			}
			if (!orderData || typeof orderData.totalCards !== 'number') {
				throw new Error('not_found');
			}
			const resolvedDeck = resolveCardsFromAssignments(orderData.cardsAssigned, staticBingoCardsCatalog);
			if (resolvedDeck.length > 0) {
				orderData = {
					...orderData,
					playCardDeck: resolvedDeck,
					totalCards: parseInt(orderData.totalCards, 10) || resolvedDeck.length,
				};
			}
			const generatedDeck = buildPlayCardDeck(orderData);
			this.setState({
				playLookupLoading: false,
				playLookupError: '',
				playOrderData: orderData,
				playCardDeck: generatedDeck,
				bingoDetectedPin: '',
				playPage: 0,
			});
		} catch (e) {
			this.setState({
				playLookupLoading: false,
				playLookupError: 'Family ID not found.',
				playOrderData: null,
				playCardDeck: [],
				bingoDetectedPin: '',
				playPage: 0,
			});
		}
	};

	goToPreviousPlayPage = () => {
		this.setState((prevState) => ({
			playPage: Math.max(0, prevState.playPage - 1),
		}));
	};

	goToNextPlayPage = () => {
		const totalCards = parseInt(this.state.playOrderData && this.state.playOrderData.totalCards, 10) || 0;
		const pageCount = Math.max(1, Math.ceil(totalCards / 4));
		this.setState((prevState) => ({
			playPage: Math.min(pageCount - 1, prevState.playPage + 1),
		}));
	};

	generateOrderJson = async (familyId, totalBooks) => {
		const endpoint = getOrderSaveEndpoint();
		if (!endpoint) {
			this.setState({
				orderSaveMessage: 'Generate is only available on localhost dev server and writes to Bingo/Books.json.',
			});
			return;
		}
		const totalGames = 6;
		const cardsPerGame = totalBooks;
		const totalCards = totalBooks * totalGames;
		const games = Array.from({ length: totalGames }, (_, index) => {
			const gameNumber = index + 1;
			const remainingCards = totalCards - (cardsPerGame * gameNumber);
			return {
				game: gameNumber,
				familyId,
				cards: cardsPerGame,
				cardsRemaining: remainingCards,
			};
		});
		const payload = {
			familyId,
			totalBooks,
			totalGames,
			totalCards,
			games,
		};
		try {
			const response = await fetch(endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify(payload),
			});
				if (!response.ok) {
					throw new Error('save_failed');
				}
				const result = await response.json();
				const didWriteBooksFile = result && result.fileName === 'Books.json' && result.path;
				if (!didWriteBooksFile) {
					throw new Error('save_not_confirmed');
				}
				this.setState({
					orderSaveMessage: `Updated ${result.fileName} for Family ID ${familyId} in ${result.path}`,
				});
			} catch (e) {
				this.setState({
					orderSaveMessage: 'Save failed. Books.json in the Bingo folder was not updated.',
				});
			}
		};


	startGame = () => {
		if (this.state.newGame) {
			this.say("Let's Play Bingo!");
		}
		if (this.state.interval) {
			clearInterval(this.state.interval);
		}
		const nextInterval = setInterval(this.callNumber, this.state.delay);
		this.setState({ newGame: false, running: true, interval: nextInterval }, () => {
			this.publishSharedSession();
			this.callNumber();
		});
	};

	resetBoard = () => {
		this.cancelSpeech();
		if (this.state.interval) {
			clearInterval(this.state.interval);
		}
		const resetBalls = _.mapObject(newGameState.balls, (ball) => ({ ...ball }));
		try {
			localStorage.removeItem('lpbclassic_current_call');
		} catch (e) {}
		document.body.classList.remove('backdrop-visible');
		const nextPatternResetToken = (parseInt(this.state.patternResetToken, 10) || 0) + 1;
		this.setState(
			{
				balls: resetBalls,
				callHistory: [],
				newGame: true,
				running: false,
				interval: 0,
				showAlert: false,
				showBackdrop: false,
				playLookupError: '',
				playLookupLoading: false,
				playOrderData: null,
				playCardDeck: [],
				bingoDetectedPin: '',
				showOpenTableDialog: false,
				showHostAccessDialog: false,
				hostAccessInput: '',
				hostAccessError: '',
				showHostSignoutDialog: false,
				hostSignoutDateInput: '',
				hostSignoutError: '',
				boardControlState: this.state.hostVerified ? 'host_ready' : 'needs_host',
				playPage: 0,
				patternResetToken: nextPatternResetToken,
			},
			() => {
				this.publishSharedSession();
			}
		);
	};

	closeTable = () => {
		this.cancelSpeech();
		if (this.state.interval) {
			clearInterval(this.state.interval);
		}
		const resetBalls = _.mapObject(newGameState.balls, (ball) => ({ ...ball }));
		try {
			localStorage.removeItem('lpbclassic_current_call');
		} catch (e) {}
		document.body.classList.remove('backdrop-visible');
		const nextPatternResetToken = (parseInt(this.state.patternResetToken, 10) || 0) + 1;
		this.setState(
			{
				balls: resetBalls,
				callHistory: [],
				newGame: true,
				running: false,
				interval: 0,
				showAlert: false,
				showConfirm: false,
				showBackdrop: false,
				playFamilyIdInput: '',
				playLookupError: '',
				playLookupLoading: false,
				playOrderData: null,
				playCardDeck: [],
				bingoDetectedPin: '',
				showOpenTableDialog: false,
				showHostAccessDialog: false,
				hostAccessInput: '',
				hostAccessError: '',
				showHostSignoutDialog: false,
				hostSignoutDateInput: '',
				hostSignoutError: '',
				hostVerified: false,
				activeScreen: 'caller',
				headerMenuSelection: '',
				selectedTableDeal: '',
				selectedTableDealIndex: 0,
				boardControlState: 'needs_host',
				playPage: 0,
				patternResetToken: nextPatternResetToken,
			},
			() => {
				this.publishSharedSession();
			}
		);
	};

	pauseGame = () => {
		if (this.state.interval) {
			clearInterval(this.state.interval);
		}
		this.setState({ newGame: false, running: false, interval: 0 }, () => {
			this.publishSharedSession();
		});
	};

	resumeGame = () => {
		if (this.state.interval) {
			clearInterval(this.state.interval);
		}
		const nextInterval = setInterval(this.callNumber, this.state.delay);
		this.setState({ newGame: false, running: true, interval: nextInterval }, () => {
			this.publishSharedSession();
		});
	};

	/*
	 *  Set Delay Function
	 *  Fires when the user uses the delay slider
	 *  If the game is running it'll clear the existing interval and set a new one
	 *  Otherwise it will just update the delay
	 */
	setDelay = (e) => {
		if (this.state.running) {
			clearInterval(this.state.interval);
			this.setState({
				delay: e.target.value,
				interval: setInterval(this.callNumber, e.target.value),
			});
		} else {
			this.setState({ delay: e.target.value });
		}
	};

	/*
	 *  Call Number Function
	 *  Will get all of the balls, find the active one and reset it
	 *  Grabs uncalled balls and determines if there are still uncalled balls
	 *  Otherwise, it'll generate a random ball, set it to called and active
	 */
	callNumber = () => {
		// get all balls
		let balls = this.state.balls;
		// get active bll and reset
		let active = _.where(balls, { active: true });
		active.forEach((ball) => {
			ball.active = false;
		});
		// get all uncalled balls
		let uncalled = _.where(balls, { called: false });
		if (uncalled.length === 0) {
			this.openAlert();
		} else {
			// choose a random ball
			let randomball = uncalled[Math.floor(Math.random() * uncalled.length)];
			let newBall = balls[randomball.number];
			// set status of ball as called and active
			newBall.called = true;
			newBall.active = true;
			this.broadcastCurrentCall(newBall);
			// call the new ball, first call it all together, then call each character individually
			let ballstring = newBall.number.toString();
			this.say([
				newBall.letter,
				newBall.number,
				' ',
				' ',
				newBall.letter,
				' ',
				ballstring.length === 2
					? [ballstring.charAt(0), ' ', ballstring.charAt(1)]
					: newBall.number,
			]);
			// update the state to re-render the board
			const nextCallHistory = (Array.isArray(this.state.callHistory) ? this.state.callHistory : []).concat([
				{ letter: newBall.letter, number: newBall.number },
			]).slice(-75);
			this.setState({ balls: balls, callHistory: nextCallHistory }, () => {
				this.publishSharedSession();
			});
		}
	};

	openAlert = () => {
		window.scrollTo(0, 0);
		document.body.classList.add('backdrop-visible');
		this.setState({ showAlert: true, showBackdrop: true });
	};

	closeAlert = () => {
		document.body.classList.remove('backdrop-visible');
		this.setState({ showAlert: false, showBackdrop: false });
	};

	get backdropClasses() {
		return this.state.showBackdrop || this.state.showOpenTableDialog || this.state.showHostAccessDialog || this.state.showHostSignoutDialog ? 'show' : 'hide';
	}
	get alertClasses() {
		return this.state.showAlert ? 'show text-center' : 'hide';
	}
	get confirmClasses() {
		return this.state.showConfirm ? 'show text-center' : 'hide';
	}

	get year() {
		return new Date().getFullYear();
	}

	/*
	 *  Render Method
	 *  Displays the bingo page
	 */
	render() {
		const ballList = Object.keys(this.state.balls || {}).map((key) => this.state.balls[key]).filter(Boolean);
		const activeBall = ballList.find((ball) => ball && ball.active) || null;
		const totalCalls = Array.isArray(this.state.callHistory) ? this.state.callHistory.length : 0;
		const totalCallsText = totalCalls > 0 ? String(totalCalls) : '—';
		const previousCall = totalCalls > 1
			? this.state.callHistory[totalCalls - 2]
			: null;
		const previousCallText = previousCall ? String(previousCall.number) : '—';
		const priorCallHistory = activeBall
			? this.state.callHistory.slice(Math.max(totalCalls - 10, 0), Math.max(totalCalls - 1, 0))
			: this.state.callHistory.slice(Math.max(totalCalls - 9, 0));
		const previousBallSlots = Array.from({ length: 5 }, (_, index) => priorCallHistory[index] || null);
		const orderTotal = Math.max(0, parseInt(this.state.orderTotalInput || '0', 10) || 0);
		let remainingOrderTotal = orderTotal;
		const orderAllocation = [...orderPackages]
			.sort((a, b) => b.price - a.price)
			.map((pkg) => {
				const quantity = Math.floor(remainingOrderTotal / pkg.price);
				remainingOrderTotal -= quantity * pkg.price;
				return {
					...pkg,
					quantity,
					subtotal: quantity * pkg.price,
				};
			});
		const cartItems = orderAllocation.filter((pkg) => pkg.quantity > 0);
		const totalBooks = cartItems.reduce((sum, pkg) => sum + (pkg.quantity * pkg.cardsPerGameCount), 0);
		const totalCards = totalBooks * 6;
		const isFamilyIdValid = /^\d{5}$/.test(this.state.familyIdInput || '');
		const playTotalCards = parseInt(this.state.playOrderData && this.state.playOrderData.totalCards, 10) || 0;
		const playCardsPerGame = parseInt(this.state.playOrderData && this.state.playOrderData.totalBooks, 10) || 0;
		const playFamilyId = String(
			(this.state.playOrderData && this.state.playOrderData.familyId) || this.state.playFamilyIdInput || ''
		).replace(/[^\d]/g, '').slice(0, 5);
		const playRoom = getPlayRoomMeta(playCardsPerGame);
		const playPlayerPosition = parseInt(this.state.playOrderData && this.state.playOrderData.playerPosition, 10) || 1;
		const playPlayerCount = parseInt(this.state.playOrderData && this.state.playOrderData.playerCount, 10) || 1;
		const playPageCount = Math.max(1, Math.ceil(playTotalCards / 4));
		const playPage = Math.min(this.state.playPage, Math.max(playPageCount - 1, 0));
		const playStartCard = playTotalCards > 0 ? (playPage * 4) + 1 : 0;
		const isJoinSessionScreen =
			this.state.activeScreen === 'join_session' || this.state.activeScreen === 'play';
		const isPlayCardsVisible = isJoinSessionScreen && !!this.state.playOrderData;
		const previousCallsCount = 5;
		const priorCallHistoryForPlay = activeBall
			? this.state.callHistory.slice(
				Math.max(totalCalls - (previousCallsCount + 1), 0),
				Math.max(totalCalls - 1, 0)
			)
			: this.state.callHistory.slice(Math.max(totalCalls - previousCallsCount, 0));
		const previousBallSlotsForPlay = Array.from({ length: previousCallsCount }, (_, index) => priorCallHistoryForPlay[index] || null);
		const playVisibleCards = Array.from(
			{ length: Math.max(0, Math.min(4, playTotalCards - (playPage * 4))) },
			(_, index) => playStartCard + index
		);
		const playCardDeck = Array.isArray(this.state.playCardDeck) ? this.state.playCardDeck : [];
		const calledNumbersSet = new Set(
			ballList
				.filter((ball) => ball && ball.called)
				.map((ball) => parseInt(ball.number, 10))
				.filter((number) => !Number.isNaN(number))
		);
		const selectedPattern = getStoredPatternConfig();
		const markFreeSpace = this.isGameInSession();
		const hasBingoCard = playCardDeck.some((cardData) => cardHasBingo(cardData, calledNumbersSet, selectedPattern, markFreeSpace));
		const bingoDetectedPin = this.state.bingoDetectedPin || '';
		const radioNowPlayingParts = this.parseRadioNowPlayingParts(this.state.radioNowPlaying);
		const radioVisualizerLevels = Array.isArray(this.state.radioVisualizerLevels)
			? this.state.radioVisualizerLevels
			: [];
		const showRadioNowPlaying = !!(
			this.state.radioPlaying &&
			this.radioAudio &&
			!this.radioAudio.paused &&
			!this.radioAudio.ended
		);
		const hasSelectedTableDeal = Boolean(this.state.selectedTableDeal);
		const selectedTableDealLine = hasSelectedTableDeal
			? `${this.state.selectedTableDeal}`
			: '';
		const isPlayerLinkMode = !!getDeepLinkFamilyIdFromUrl();
		const canUseLocalOrderGeneration = isLocalDevRuntime() && !isPlayerLinkMode;
		const isOrderScreen = this.state.activeScreen === 'order' && canUseLocalOrderGeneration;
		const isPlayersScreen = this.state.activeScreen === 'players';
		const canJoinSession = true;
		const playersList = Array.isArray(this.state.playersList) ? this.state.playersList : [];
		const boardControlState = this.state.boardControlState || (this.state.hostVerified ? 'host_ready' : 'needs_host');
		const getBallColor = (letter) => {
			switch (letter) {
				case 'B':
					return 'blue';
				case 'I':
					return 'red';
				case 'N':
					return 'white';
				case 'G':
					return 'green';
				case 'O':
					return 'yellow';
				default:
					return 'white';
			}
		};
		const callerBoardShell = (
			<div className={`row lpb-board-shell${isPlayCardsVisible ? ' lpb-board-shell-compact' : ''}`}>
				<div className="lpb-board-side lpb-board-stats">
					<div className="lpb-call-summary-wrap notranslate">
						<div className="lpb-call-summary">
							<div className="lpb-call-summary-item">
								<div className="lpb-call-summary-box">
									<SevenSegmentText text={totalCallsText} variant="box" />
								</div>
								<div className="lpb-call-summary-label">Calls</div>
							</div>
							<div className="lpb-call-summary-item">
								<div className="lpb-call-summary-box">
									<SevenSegmentText text={previousCallText} variant="box" />
								</div>
								<div className="lpb-call-summary-label">Previous</div>
							</div>
							<div className="lpb-call-summary-game-inline">
								{hasSelectedTableDeal ? selectedTableDealLine : '--'}
							</div>
						</div>
						<div className="lpb-call-pattern-wrap">
							<Pattern
								resetToken={this.state.patternResetToken}
								disabled={!this.state.newGame}
							/>
						</div>
					</div>
				</div>
				<div className="lpb-board-center">
						<div className="lpb-board-center-panel">
							<BingoBoard balls={this.state.balls} />
							<div className="lpb-board-current-ball-wrap">
								<div className="lpb-board-current-ball-box">
									<BallDisplay balls={this.state.balls} />
								</div>
								<div className="lpb-board-current-info-box">
									{hasSelectedTableDeal ? (
										<div className="lpb-call-history-strip notranslate" aria-label="Previous five balls">
											{previousBallSlots.map((ball, index) => (
												<div
													key={ball ? `${ball.letter}${ball.number}-${index}` : `inline-empty-${index}`}
													className="lpb-call-history-slot"
												>
													<div className={`lpb-mini-ball ${ball ? getBallColor(ball.letter) : 'lpb-mini-ball-empty'}`}>
														<div className="lpb-mini-ball-content">
															{ball ? (
																<span>
																	<span className="ball-letter">{ball.letter}</span>
																	<span className="ball-number">{ball.number}</span>
																</span>
															) : null}
														</div>
													</div>
												</div>
											))}
										</div>
									) : (
										<div className="lpb-board-current-info-logo" aria-hidden="true">
											<img src={logoLight} alt="Let's Play Bingo logo" />
										</div>
									)}
								</div>
							</div>
						</div>
					</div>
			</div>
		);
		const controlsPanel = (
			<div className="row lpb-buttons-controls-row">
				<div className="col c100">
					{isOrderScreen ? (
						<>
							<button
								className="lpb-btn lpb-btn-order"
								onClick={() => this.generateOrderJson(this.state.familyIdInput, totalBooks)}
								disabled={cartItems.length === 0 || !isFamilyIdValid}
							>
								Generate
							</button>
						</>
					) : isPlayersScreen ? null : isJoinSessionScreen ? (
						<div className="lpb-join-controls">
							{hasBingoCard ? (
								<button className="lpb-btn lpb-btn-bingo" onClick={this.handleBingo}>Bingo</button>
							) : null}
							{hasBingoCard && bingoDetectedPin ? (
								<div className="lpb-bingo-pin">Bingo Pin: {bingoDetectedPin}</div>
							) : null}
						</div>
					) : (
						<div className="lpb-board-controls">
							<div className="lpb-board-controls-buttons">
								<button className="lpb-btn lpb-btn-host" onClick={this.openHostAccessDialog}>Host Access</button>
								{this.state.hostVerified && boardControlState === 'host_ready' ? (
									<>
										<button className="lpb-btn lpb-btn-open-table" onClick={this.openTableDialog}>Open Floor</button>
										<button className="lpb-btn lpb-btn-close-table" onClick={this.handleCloseTable}>Close Floor</button>
									</>
								) : null}
								{this.state.hostVerified && boardControlState === 'table_ready' ? (
									<>
										<button className="lpb-btn lpb-btn-open" onClick={this.handleDraw}>Start Draw</button>
										<button className="lpb-btn lpb-btn-reset" onClick={this.handleReset}>Clear Board</button>
									</>
								) : null}
								{this.state.hostVerified && boardControlState === 'drawing' ? (
									<>
										<button className="lpb-btn lpb-btn-hold" onClick={this.handleHoldDraw}>Hold Draw</button>
										<button className="lpb-btn lpb-btn-reset" onClick={this.handleReset}>Clear Board</button>
									</>
								) : null}
								{this.state.hostVerified && boardControlState === 'paused' ? (
									<>
										<button className="lpb-btn lpb-btn-resume" onClick={this.handleResume}>Resume Draw</button>
										<button className="lpb-btn lpb-btn-reset" onClick={this.handleReset}>Clear Board</button>
									</>
								) : null}
								{this.state.hostVerified ? (
									<button className="lpb-btn lpb-btn-radio" onClick={this.openRadio}>Radio</button>
								) : null}
							</div>
							{showRadioNowPlaying ? (
								<div className="lpb-radio-now-playing">
									<img className="lpb-radio-now-playing-logo" src={radioLogoUrl} alt="95.7 The Boss" />
									<div className="lpb-radio-now-playing-copy">
										<div className="lpb-radio-visualizer" aria-hidden="true">
											{radioVisualizerLevels.map((level, index) => (
												<span
													key={`radio-bar-${index}`}
													className="lpb-radio-visualizer-bar"
													style={{ transform: `scaleY(${level})` }}
												></span>
											))}
										</div>
										{radioNowPlayingParts.track ? (
											<div className="lpb-radio-now-playing-track">{radioNowPlayingParts.track}</div>
										) : null}
										{radioNowPlayingParts.artist ? (
											<div className="lpb-radio-now-playing-artist">{radioNowPlayingParts.artist}</div>
										) : null}
									</div>
								</div>
							) : null}
						</div>
					)}
				</div>
			</div>
		);
		return (
			<div>
				<div id="backdrop" className={this.backdropClasses}></div>
				<div id="disclaimer" className={this.alertClasses}>
					<h4 className="no-margin">Bingo!</h4>
					<p className="small-text">All bingo balls have been called.</p>
					<p>
						<button onClick={this.closeAlert}>Close Alert</button>
					</p>
				</div>
				<div id="confirmation" className={this.confirmClasses}>
					<h4 className="no-margin">{this.state.confirmTitle}</h4>
					<p className="small-text">{this.state.confirmMessage}</p>
					<p>
						<button className="lpb-btn lpb-btn-confirm" onClick={this.proceedConfirm}>{this.state.confirmButtonText}</button> |{' '}
						<button className="lpb-btn lpb-btn-clear" onClick={this.closeConfirm}>Cancel</button>
					</p>
				</div>
				<div id="open-table-dialog" className={this.state.showOpenTableDialog ? 'show' : 'hide'}>
					<h4 className="no-margin">Open Floor</h4>
					<p className="small-text">Select the game to bring to the floor.</p>
					<div className="lpb-open-table-field">
						<label htmlFor="lpb-open-table-deal">Game</label>
						<select
							id="lpb-open-table-deal"
							value={this.state.openTableDeal}
							onChange={this.handleOpenTableDealChange}
						>
							{openTableDeals.map((dealName) => (
								<option key={dealName} value={dealName}>
									{dealName}
								</option>
							))}
						</select>
					</div>
					<p>
						<button className="lpb-btn lpb-btn-open-table" onClick={this.applyOpenTableDeal}>Apply</button> |{' '}
						<button className="lpb-btn lpb-btn-clear" onClick={this.closeOpenTableDialog}>Cancel</button>
					</p>
				</div>
				<div id="host-access-dialog" className={this.state.showHostAccessDialog ? 'show' : 'hide'}>
					<h4 className="no-margin">Host Access</h4>
					<p className="small-text">Enter 8-digit access code.</p>
					<div className="lpb-open-table-field">
						<label htmlFor="lpb-host-access-input">Access Code</label>
						<input
							id="lpb-host-access-input"
							type="text"
							inputMode="numeric"
							maxLength="8"
							value={this.state.hostAccessInput}
							onChange={this.handleHostAccessInputChange}
							onKeyDown={(e) => {
								if (e.key === 'Enter') this.verifyHostAccess();
							}}
						/>
					</div>
					{this.state.hostAccessError ? (
						<p className="small-text lpb-host-access-error">{this.state.hostAccessError}</p>
					) : null}
					<p>
						<button className="lpb-btn lpb-btn-host" onClick={this.verifyHostAccess}>Enter</button> |{' '}
						<button className="lpb-btn lpb-btn-clear" onClick={this.closeHostAccessDialog}>Cancel</button>
					</p>
				</div>
				<div id="host-signout-dialog" className={this.state.showHostSignoutDialog ? 'show' : 'hide'}>
					<h4 className="no-margin">Host Access Active</h4>
					<p className="small-text">Host access is already active on this board. Sign out the current host before continuing.</p>
					<div className="lpb-open-table-field">
						<label htmlFor="lpb-host-signout-date-input">Tomorrow&apos;s Date (MMDDYYYY)</label>
						<input
							id="lpb-host-signout-date-input"
							type="text"
							inputMode="numeric"
							maxLength="8"
							value={this.state.hostSignoutDateInput}
							onChange={this.handleHostSignoutDateInputChange}
							onKeyDown={(e) => {
								if (e.key === 'Enter') this.confirmHostSignout();
							}}
						/>
					</div>
					{this.state.hostSignoutError ? (
						<p className="small-text lpb-host-access-error">{this.state.hostSignoutError}</p>
					) : null}
					<p>
						<button className="lpb-btn lpb-btn-close-table" onClick={this.confirmHostSignout}>Sign out</button> |{' '}
						<button className="lpb-btn lpb-btn-clear" onClick={this.closeHostSignoutDialog}>Cancel</button>
					</p>
				</div>

				{!isPlayerLinkMode ? (
					<header>
						<div className="row">
							<div className="col c100">
								<div className="logo-block">
									<img className="logo" src={logo} alt="Let's Play Bingo Logo" />
									<div className="lpb-header-menu">
										<select
											id="lpb-header-menu-select"
											value={this.state.headerMenuSelection}
											onChange={this.handleHeaderMenuChange}
										>
											<option value="" disabled hidden>Menu</option>
											<option value="board">Board</option>
											{canJoinSession ? (
												<option value="join_session">Join a Session</option>
											) : null}
											<option value="players">Players</option>
											{canUseLocalOrderGeneration ? (
												<option value="order">Order</option>
											) : null}
										</select>
									</div>
								</div>
							</div>
						</div>
					</header>
				) : null}

				{isOrderScreen ? (
					<section id="board">
						<div className="row lpb-order-shell">
							<div className="lpb-order-panel">
								<div className="lpb-order-header">
									<h2>Order</h2>
									<p>Choose your level of play. Each package includes automatic game play for the full session, with your cards entered into every round based on the package you select.</p>
								</div>
								<div className="lpb-order-builder">
									<div className="lpb-order-fields">
										<div className="lpb-order-field">
											<label htmlFor="lpb-family-id">Family ID</label>
											<input
												id="lpb-family-id"
												type="text"
												inputMode="numeric"
												maxLength="5"
												value={this.state.familyIdInput}
												onChange={this.handleFamilyIdChange}
											/>
										</div>
										<div className="lpb-order-field">
											<label htmlFor="lpb-order-total">Order Total</label>
											<input
												id="lpb-order-total"
												type="text"
												inputMode="numeric"
												value={this.state.orderTotalInput}
												onChange={this.handleOrderTotalChange}
											/>
										</div>
									</div>
									<div className="lpb-order-cart">
										<div className="lpb-order-cart-header">Cart</div>
										{cartItems.length > 0 ? (
											<>
												{cartItems.map((pkg) => (
													<div key={`${pkg.tier}-cart`} className="lpb-order-cart-row">
														<div className="lpb-order-cart-tier">
															<div className="lpb-order-cart-tier-name">{pkg.quantity} x {pkg.tier}</div>
															<div className="lpb-order-cart-tier-meta">{pkg.cardsPerGame}</div>
															<div className="lpb-order-cart-tier-meta">6 games</div>
														</div>
														<div className="lpb-order-cart-subtotal">
															${pkg.subtotal}
														</div>
													</div>
												))}
												<div className="lpb-order-cart-row lpb-order-cart-total">
													<div>Total Cards</div>
													<div>{totalCards}</div>
												</div>
												<div className="lpb-order-cart-row lpb-order-cart-total">
													<div>Total Cards Per Game</div>
													<div>{totalBooks}</div>
												</div>
												<div className="lpb-order-cart-row lpb-order-cart-total">
													<div>Total Applied</div>
													<div>${orderTotal - remainingOrderTotal}</div>
												</div>
												<div className="lpb-order-cart-row lpb-order-cart-remainder">
													<div>Unapplied Balance</div>
													<div>${remainingOrderTotal}</div>
												</div>
											</>
										) : (
											<div className="lpb-order-cart-empty">Enter an order total to build the cart.</div>
										)}
										{this.state.orderSaveMessage ? (
											<div className="lpb-order-cart-status">{this.state.orderSaveMessage}</div>
										) : null}
									</div>
								</div>
								<div className="lpb-order-grid">
									{orderPackages.map((pkg) => (
										<div key={pkg.tier} className={`lpb-order-card lpb-order-card-${pkg.tier.toLowerCase()}`}>
											<div className="lpb-order-card-title">
												{pkg.tier} - {pkg.name}
											</div>
											<div className="lpb-order-card-price">${pkg.price}</div>
											<div className="lpb-order-card-meta">{pkg.cardsPerGame}</div>
											<div className="lpb-order-card-copy">{pkg.description}</div>
										</div>
									))}
								</div>
							</div>
						</div>
					</section>
				) : isPlayersScreen ? (
					<section id="board">
						<div className="row lpb-players-shell">
							<div className="lpb-order-panel lpb-players-panel">
								<div className="lpb-order-header">
									<h2>Players</h2>
									<p>All players, assigned tier, cards per game, and player links.</p>
								</div>
								{this.state.playersLoading ? (
									<div className="lpb-players-status">Loading players...</div>
								) : null}
								{this.state.playersError ? (
									<div className="lpb-players-error">{this.state.playersError}</div>
								) : null}
								{!this.state.playersLoading && !this.state.playersError ? (
									playersList.length > 0 ? (
										<div className="lpb-players-table-wrap">
											<table className="lpb-players-table">
												<thead>
													<tr>
														<th>Family ID</th>
														<th>Tier</th>
														<th>Cards/Game</th>
														<th>Localhost</th>
														<th>Production</th>
													</tr>
												</thead>
												<tbody>
													{playersList.map((player) => (
														<tr key={`player-row-${player.familyId}`}>
															<td>{player.familyId}</td>
															<td>{player.tier}</td>
															<td>{player.cardsPerGame}</td>
															<td>
																<a
																	className="lpb-players-link-btn"
																	href={player.urls.localhost}
																	target="_blank"
																	rel="noopener noreferrer"
																	title={player.urls.localhost}
																>
																	Localhost
																</a>
															</td>
															<td>
																<a
																	className="lpb-players-link-btn"
																	href={player.urls.production}
																	target="_blank"
																	rel="noopener noreferrer"
																	title={player.urls.production}
																>
																	Production
																</a>
															</td>
														</tr>
													))}
												</tbody>
											</table>
										</div>
									) : (
										<div className="lpb-players-status">No players found.</div>
									)
								) : null}
							</div>
						</div>
					</section>
				) : isJoinSessionScreen ? (
					<section id="board">
		<div className={`row lpb-play-shell lpb-play-panel lpb-play-room lpb-play-room-${playRoom.key}`}>
							{!isPlayerLinkMode ? (
								<div className="lpb-play-lookup-inline">
									<div className="lpb-play-inline-field">
										<label htmlFor="lpb-play-family-id-inline">Family ID</label>
										<input
											id="lpb-play-family-id-inline"
											type="text"
											inputMode="numeric"
											maxLength="5"
											value={this.state.playFamilyIdInput}
											onChange={this.handlePlayFamilyIdChange}
											onKeyDown={(e) => {
												if (e.key === 'Enter') {
													this.loadPlayOrder();
												}
											}}
										/>
									</div>
									<button
										className="lpb-btn lpb-btn-order"
										onClick={this.loadPlayOrder}
										disabled={this.state.playLookupLoading}
									>
										{this.state.playLookupLoading ? 'Checking...' : 'Enter'}
									</button>
								</div>
							) : null}
							{this.state.playLookupError ? (
								<div className="lpb-play-error">{this.state.playLookupError}</div>
							) : null}
							{this.state.playOrderData ? (
								<div className="lpb-play-view">
									<div className="lpb-play-topbar">
										<div className="lpb-play-room-title">{playRoom.title}</div>
										<div className="lpb-play-player-count">
											Player {playPlayerPosition} of {playPlayerCount}
										</div>
									</div>
									<div className="lpb-play-room-meta">
										<span className="lpb-play-room-welcome">Welcome:</span> {playRoom.welcome}
									</div>
									<div className="lpb-play-call-row notranslate" aria-label="Current and previous five balls">
										<div className="lpb-play-call-row-item lpb-play-call-current">
											<div className={`lpb-mini-ball ${activeBall ? getBallColor(activeBall.letter) : 'lpb-mini-ball-empty'}`}>
												<div className="lpb-mini-ball-content">
													{activeBall ? (
														<span>
															<span className="ball-letter">{activeBall.letter}</span>
															<span className="ball-number">{activeBall.number}</span>
														</span>
													) : null}
												</div>
											</div>
										</div>
										<div className="lpb-play-call-divider" aria-hidden="true"></div>
										{previousBallSlotsForPlay.map((ball, index) => (
											<div
												key={ball ? `${ball.letter}${ball.number}-play-row-${index}` : `play-row-empty-${index}`}
												className="lpb-play-call-row-item"
											>
												<div className={`lpb-mini-ball ${ball ? getBallColor(ball.letter) : 'lpb-mini-ball-empty'}`}>
													<div className="lpb-mini-ball-content">
														{ball ? (
															<span>
																<span className="ball-letter">{ball.letter}</span>
																<span className="ball-number">{ball.number}</span>
															</span>
														) : null}
													</div>
												</div>
											</div>
										))}
									</div>
									<div className="lpb-play-carousel">
										<button
											className="lpb-play-arrow"
											onClick={this.goToPreviousPlayPage}
											disabled={playPage === 0}
											aria-label="Previous cards"
										>
											&lt;
										</button>
										<div className={`lpb-play-cards lpb-play-cards-${playRoom.key}`}>
											{playVisibleCards.map((cardNumber) => {
												const cardData = playCardDeck[cardNumber - 1] || null;
												const displayBcin = cardData && cardData.BCIN
													? cardData.BCIN
													: getBcinValue(playFamilyId, playTotalCards, cardNumber - 1);
												const displayBin = cardData && cardData.BIN ? cardData.BIN : '';
												return (
													<div key={`play-card-${cardNumber}`} className="lpb-play-card">
														<div className="lpb-play-card-board notranslate">
															{bingoLetters.map((letter) => (
																<div key={`play-card-${cardNumber}-${letter}`} className="pattern-col">
																	<div className="pattern-letter">{letter}</div>
																	{Array.from({ length: 5 }, (_, index) => {
																		const column = cardData && Array.isArray(cardData[letter]) ? cardData[letter] : [];
																		const value = column[index];
																		const marked = isMarkedCellValue(value, calledNumbersSet, markFreeSpace);
																		return (
																			<div
																				key={`play-card-${cardNumber}-${letter}-cell-${index + 1}`}
																				className={`pattern-slot${value === 'FREE' ? ' lpb-play-card-free' : ''}${marked ? ' lpb-play-card-marked' : ''}`}
																			>
																				{value === 'FREE' ? (
																					<span className="lpb-play-card-free-text">FREE</span>
																				) : (value || '\u00a0')}
																			</div>
																		);
																	})}
																</div>
															))}
														</div>
														<div className="lpb-play-card-bcin">
															{displayBin ? `BIN: ${displayBin} | ` : ''}BCIN: {displayBcin}
														</div>
													</div>
												);
											})}
										</div>
										<button
											className="lpb-play-arrow"
											onClick={this.goToNextPlayPage}
											disabled={playPage >= playPageCount - 1}
											aria-label="Next cards"
										>
											&gt;
										</button>
									</div>
									<div className="lpb-play-page-indicator">
										Showing {playVisibleCards.length > 0 ? `${playStartCard}-${playStartCard + playVisibleCards.length - 1}` : '0'} of {playTotalCards}
									</div>
								</div>
							) : null}
						</div>
					</section>
				) : (
					<section id="board">
						<div className="row lpb-board-stack">
							{callerBoardShell}
							<section id="buttons" className="lpb-buttons-attached">
								{controlsPanel}
							</section>
						</div>
					</section>
				)}

				{(isOrderScreen || isJoinSessionScreen) && !isPlayerLinkMode ? (
					<section id="buttons" className="lpb-buttons-floating">
						{controlsPanel}
					</section>
				) : null}

				<footer>
					<div className="row">
						<div className="col c50 text-left">
							For fundraising purposes only.
						</div>
						<div className="col c50 text-right">
							<p>
								© {this.year}{' '}
								<a href="https://letsplaybingo.io" className="notranslate">
									Let's Play Bingo!
								</a>
							</p>
						</div>
					</div>
				</footer>
			</div>
		);
	}
}

export default LetsPlayBingo;
