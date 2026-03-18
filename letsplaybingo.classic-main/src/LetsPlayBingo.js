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
import venmo from './images/venmo.jpeg';
import paypal from './images/paypalme.jpeg';
import buyacoffee from './images/buyacoffee.png';
// Components
import BingoBoard from './components/BingoBoard.js';
import Pattern from './components/Pattern.js';
import BallDisplay from './components/BallDisplay.js';
import SevenSegmentText from './components/SevenSegmentText.js';

const SHARED_BINGO_ENDPOINTS = [
	'https://dlbhfamily.com/wp-json/dlbh-bingo/v1',
	'https://www.dlbhfamily.com/wp-json/dlbh-bingo/v1',
];

function getCentralDateAccessCode() {
	const parts = new Intl.DateTimeFormat('en-US', {
		timeZone: 'America/Chicago',
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	}).formatToParts(new Date());
	const month = parts.find((part) => part.type === 'month')?.value || '';
	const day = parts.find((part) => part.type === 'day')?.value || '';
	const year = parts.find((part) => part.type === 'year')?.value || '';
	return `${month}${day}${year}`;
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
	gameId: 1,
	tableEmpty: false,
	tableOpen: false,
	tableOpening: false,
	playPhase: 'idle',
	callHistory: [],
	sessionAction: 'reset',
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
			accessCodeInput: '',
			accessCodeVerified: false,
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
			gameId: 1,
			tableEmpty: false,
			tableOpen: false,
			tableOpening: false,
			playPhase: 'idle',
			callHistory: [],
			sessionAction: 'reset',
			interval: 0,
			delay: 10000,
		};
		this.pendingConfirmAction = null;
		let skipCacheRestore = false;
		try {
			const host = String(window.location.hostname || '').toLowerCase();
			const path = String(window.location.pathname || '');
			const search = String(window.location.search || '');
			this.isViewerMode = /(?:\?|&)viewer=1(?:&|$)/.test(search);
			if (host === 'dewitt-steward.github.io' && path.indexOf('/Bingo') === 0) {
				skipCacheRestore = true;
				localStorage.removeItem('lpbclassic');
			}
		} catch (e) {}
		if (typeof this.isViewerMode !== 'boolean') this.isViewerMode = false;
		this.isSharedHost = skipCacheRestore;
		this.sharedSessionLoaded = !skipCacheRestore;
		this.applyingSharedSession = false;

		const cache = skipCacheRestore ? null : JSON.parse(localStorage.getItem('lpbclassic'));
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
		if (!this.isViewerMode) {
			window.addEventListener('message', this.handleBridgeMessage);
			setTimeout(this.notifyBridgeReady, 250);
			this.bridgeInterval = setInterval(this.bridgeHeartbeat, 1000);
		}
	}

	componentDidMount() {
		if (this.isSharedHost) {
			this.loadSharedSession();
			if (this.isViewerMode) {
				this.sharedSessionPollInterval = setInterval(this.loadSharedSession, 1000);
			}
		}
	}

	componentWillUnmount() {
		if (!this.isViewerMode) window.removeEventListener('message', this.handleBridgeMessage);
		if (this.bridgeInterval) clearInterval(this.bridgeInterval);
		if (this.sharedSessionPollInterval) clearInterval(this.sharedSessionPollInterval);
		if (this.tableOpeningTimeout) clearTimeout(this.tableOpeningTimeout);
	}

	componentDidUpdate() {
		let stateCopy = { ...this.state };
		delete stateCopy.showAlert;
		delete stateCopy.showBackdrop;
		localStorage.setItem('lpbclassic', JSON.stringify(stateCopy));
		if (this.isSharedHost && !this.isViewerMode && this.sharedSessionLoaded && !this.applyingSharedSession) {
			this.pushSharedSession();
		}
	}

	buildSharedSessionState = () => {
		const safeBalls = {};
		Object.keys(this.state.balls || {}).forEach((key) => {
			const ball = this.state.balls[key];
			if (!ball) return;
			safeBalls[key] = {
				letter: ball.letter,
				number: ball.number,
				called: !!ball.called,
				active: !!ball.active,
			};
		});
		return {
			balls: safeBalls,
			newGame: !!this.state.newGame,
			running: !!this.state.running,
			gameId: parseInt(this.state.gameId, 10) || 1,
			tableEmpty: !!this.state.tableEmpty,
			tableOpen: !!this.state.tableOpen,
			tableOpening: !!this.state.tableOpening,
			playPhase: this.state.playPhase || 'idle',
			callHistory: Array.isArray(this.state.callHistory) ? this.state.callHistory.slice(-75) : [],
			sessionAction: this.state.sessionAction || 'play',
			delay: parseInt(this.state.delay, 10) || 10000,
			showAlert: !!this.state.showAlert,
			ts: Date.now(),
		};
	};

	pushSharedSession = () => {
		if (typeof fetch !== 'function') return;
		const payload = this.buildSharedSessionState();
		SHARED_BINGO_ENDPOINTS.forEach((baseUrl) => {
			try {
				fetch(baseUrl + '/session', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify(payload),
					mode: 'cors',
					credentials: 'omit',
				}).catch(() => {});
			} catch (e) {}
		});
	};

	loadSharedSession = async () => {
		if (typeof fetch !== 'function') {
			this.sharedSessionLoaded = true;
			return;
		}
		for (let i = 0; i < SHARED_BINGO_ENDPOINTS.length; i++) {
			try {
				const response = await fetch(SHARED_BINGO_ENDPOINTS[i] + '/session', {
					method: 'GET',
					mode: 'cors',
					credentials: 'omit',
					cache: 'no-store',
				});
				if (!response.ok) continue;
				const session = await response.json();
				if (!session || typeof session !== 'object' || !session.balls) continue;
				const mergedBalls = JSON.parse(JSON.stringify(newGameState.balls));
				Object.keys(session.balls || {}).forEach((key) => {
					if (!mergedBalls[key] || !session.balls[key]) return;
					mergedBalls[key] = {
						...mergedBalls[key],
						called: !!session.balls[key].called,
						active: !!session.balls[key].active,
					};
				});
				this.applyingSharedSession = true;
				this.setState(
					{
						balls: mergedBalls,
						newGame: typeof session.newGame === 'boolean' ? session.newGame : false,
						running: false,
						gameId: parseInt(session.gameId, 10) || 1,
						tableEmpty: !!session.tableEmpty,
						tableOpen: !!session.tableOpen,
						tableOpening: !!session.tableOpening,
						playPhase: session.playPhase || 'idle',
						callHistory: Array.isArray(session.callHistory) ? session.callHistory.slice(-75) : [],
						sessionAction: session.sessionAction || 'play',
						delay: parseInt(session.delay, 10) || 10000,
						showAlert: !!session.showAlert,
						showBackdrop: !!session.showAlert,
					},
					() => {
						this.applyingSharedSession = false;
						this.sharedSessionLoaded = true;
						if (!this.isViewerMode) this.pushSharedSession();
					}
				);
				return;
			} catch (e) {}
		}
		this.sharedSessionLoaded = true;
		if (!this.isViewerMode) this.pushSharedSession();
	};

	say = () => {};

	cancelSpeech = () => {};

	/*
	 *  Broadcast current called ball so external pages (like bingo.php Play)
	 *  can render the live call in real time.
	 */
	broadcastCurrentCall = (ball) => {
		if (!ball || !ball.letter || !ball.number) return;
		const calledBalls = _.where(this.state.balls, { called: true });
		const calledCount = calledBalls.length;
		const calledNumbers = calledBalls
			.map((item) => parseInt(item.number, 10))
			.filter((item) => !isNaN(item))
			.sort((a, b) => a - b);
		const payload = {
			type: 'LPB_CALL',
			letter: ball.letter,
			number: ball.number,
			count: calledCount,
			called_numbers: calledNumbers,
			call: ball.letter + '' + ball.number,
			ts: Date.now(),
		};
		try {
			localStorage.setItem('lpbclassic_current_call', JSON.stringify(payload));
		} catch (e) {}
		try { window.name = 'lpb_caller_window'; } catch (e) {}
		try {
			if (window.parent && window.parent !== window) {
				window.parent.postMessage(payload, '*');
			}
		} catch (e) {}
		try {
			if (window.opener && !window.opener.closed) {
				window.opener.postMessage(payload, '*');
			}
		} catch (e) {}
		this.pushLiveCall(payload);
	};

	pushLiveCall = (payload) => {
		if (!payload || typeof fetch !== 'function') return;
		const endpoints = [
			'https://dlbhfamily.com/wp-json/dlbh-bingo/v1/live-call',
			'https://www.dlbhfamily.com/wp-json/dlbh-bingo/v1/live-call',
		];
		endpoints.forEach((endpoint) => {
			try {
				fetch(endpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify(payload),
					mode: 'cors',
					credentials: 'omit',
				}).catch(() => {});
			} catch (e) {}
		});
	};

	pushLiveCallReset = () => {
		try {
			localStorage.removeItem('lpbclassic_current_call');
		} catch (e) {}
		this.pushLiveCall({
			active: false,
			letter: '',
			number: '',
			count: 0,
			called_numbers: [],
			ts: Date.now(),
		});
	};

	handleBridgeMessage = (event) => {
		let data = event ? event.data : null;
		if (typeof data === 'string') {
			try {
				data = JSON.parse(data);
			} catch (e) {
				return;
			}
		}
		if (!data || data.type !== 'LPB_REQUEST_CALL') return;

		let payload = null;
		try {
			const raw = localStorage.getItem('lpbclassic_current_call');
			if (raw) {
				const parsed = JSON.parse(raw);
				if (parsed && parsed.letter && parsed.number) {
					payload = {
						type: 'LPB_CALL',
						letter: parsed.letter,
						number: parsed.number,
						call: parsed.letter + '' + parsed.number,
						ts: Date.now(),
					};
				}
			}
		} catch (e) {}

		if (!payload) {
			const active = _.where(this.state.balls, { active: true })[0];
			if (active && active.letter && active.number) {
				payload = {
					type: 'LPB_CALL',
					letter: active.letter,
					number: active.number,
					call: active.letter + '' + active.number,
					ts: Date.now(),
				};
			}
		}
		if (!payload) return;

		try {
			if (event && event.source && typeof event.source.postMessage === 'function') {
				event.source.postMessage(payload, '*');
			}
		} catch (e) {}
	};

	notifyBridgeReady = () => {
		const payload = { type: 'LPB_READY', ts: Date.now() };
		try { window.name = 'lpb_caller_window'; } catch (e) {}
		try {
			if (window.parent && window.parent !== window) {
				window.parent.postMessage(payload, '*');
			}
		} catch (e) {}
		try {
			if (window.opener && !window.opener.closed) {
				window.opener.postMessage(payload, '*');
			}
		} catch (e) {}
	};

	bridgeHeartbeat = () => {
		const active = _.where(this.state.balls, { active: true })[0];
		if (active && active.letter && active.number) {
			this.broadcastCurrentCall(active);
			return;
		}
		this.notifyBridgeReady();
	};

	/*
	 *  Reset Game Function
	 *  Map out the original balls array and update
	 *  active and called statuses to false
	 */
	resetGame = () => {
		this.cancelSpeech();
		if (this.state.running === true) {
			clearInterval(this.state.interval);
		}
		let resetBalls = this.state.balls;
		_.map(resetBalls, (ball, index) => {
			resetBalls[index].active = false;
			resetBalls[index].called = false;
		});
		if (this.state.showAlert === true) {
			this.closeAlert();
		}
		this.pushLiveCallReset();
		if (this.tableOpeningTimeout) clearTimeout(this.tableOpeningTimeout);
		this.setState({ balls: resetBalls, newGame: true, running: false, interval: 0, gameId: (parseInt(this.state.gameId, 10) || 1) + 1, tableEmpty: false, tableOpen: true, tableOpening: false, playPhase: 'ready', callHistory: [], sessionAction: 'load_next_set' }, () => {
			if (this.isSharedHost && !this.isViewerMode && this.sharedSessionLoaded) this.pushSharedSession();
		});
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

	clearTable = () => {
		this.cancelSpeech();
		if (this.state.running === true) {
			clearInterval(this.state.interval);
		}
		let clearedBalls = this.state.balls;
		_.map(clearedBalls, (ball, index) => {
			clearedBalls[index].active = false;
			clearedBalls[index].called = false;
		});
		if (this.state.showAlert === true) {
			this.closeAlert();
		}
		this.pushLiveCallReset();
		if (this.tableOpeningTimeout) clearTimeout(this.tableOpeningTimeout);
		this.setState({ balls: clearedBalls, newGame: true, running: false, tableEmpty: false, tableOpen: true, tableOpening: false, playPhase: 'cleared', callHistory: [], sessionAction: 'clear_felt' }, () => {
			if (this.isSharedHost && !this.isViewerMode && this.sharedSessionLoaded) this.pushSharedSession();
		});
	};

	closeTable = () => {
		this.cancelSpeech();
		if (this.state.running === true) {
			clearInterval(this.state.interval);
		}
		let closedBalls = this.state.balls;
		_.map(closedBalls, (ball, index) => {
			closedBalls[index].active = false;
			closedBalls[index].called = false;
		});
		if (this.state.showAlert === true) {
			this.closeAlert();
		}
		this.pushLiveCallReset();
		if (this.tableOpeningTimeout) clearTimeout(this.tableOpeningTimeout);
		this.setState({ balls: closedBalls, newGame: true, running: false, tableEmpty: true, tableOpen: false, tableOpening: false, playPhase: 'idle', callHistory: [], sessionAction: 'close_table', accessCodeInput: '', accessCodeVerified: false }, () => {
			if (this.isSharedHost && !this.isViewerMode && this.sharedSessionLoaded) this.pushSharedSession();
		});
	};

	hostTable = () => {
		this.cancelSpeech();
		if (this.state.running === true) {
			clearInterval(this.state.interval);
		}
		let openedBalls = this.state.balls;
		_.map(openedBalls, (ball, index) => {
			openedBalls[index].active = false;
			openedBalls[index].called = false;
		});
		if (this.state.showAlert === true) {
			this.closeAlert();
		}
		this.pushLiveCallReset();
		if (this.tableOpeningTimeout) clearTimeout(this.tableOpeningTimeout);
		this.setState({ balls: openedBalls, newGame: true, running: false, tableEmpty: true, tableOpen: true, tableOpening: false, playPhase: 'hosted', callHistory: [], sessionAction: 'host_table' }, () => {
			if (this.isSharedHost && !this.isViewerMode && this.sharedSessionLoaded) this.pushSharedSession();
		});
	};

	openTable = () => {
		this.cancelSpeech();
		if (this.state.running === true) {
			clearInterval(this.state.interval);
		}
		if (this.state.showAlert === true) {
			this.closeAlert();
		}
		this.pushLiveCallReset();
		if (this.tableOpeningTimeout) clearTimeout(this.tableOpeningTimeout);
		this.setState({ tableEmpty: true, tableOpen: true, tableOpening: true, running: false, newGame: true, playPhase: 'opening', callHistory: [], sessionAction: 'open_table' }, () => {
			if (this.isSharedHost && !this.isViewerMode && this.sharedSessionLoaded) this.pushSharedSession();
			this.tableOpeningTimeout = setTimeout(() => {
				this.setState({ tableEmpty: false, tableOpen: true, tableOpening: false, playPhase: 'on_deck', callHistory: [], sessionAction: 'open_table_ready' }, () => {
					if (this.isSharedHost && !this.isViewerMode && this.sharedSessionLoaded) this.pushSharedSession();
				});
			}, 10000);
		});
	};

	setTable = () => {
		if (this.state.showAlert === true) {
			this.closeAlert();
		}
		if (this.tableOpeningTimeout) clearTimeout(this.tableOpeningTimeout);
		this.setState({ tableEmpty: false, tableOpen: true, tableOpening: false, playPhase: 'ready', callHistory: [], sessionAction: 'set_table' }, () => {
			if (this.isSharedHost && !this.isViewerMode && this.sharedSessionLoaded) this.pushSharedSession();
		});
	};

	handleOpenTable = () => {
		this.confirmAction(
			'Host',
			'Open the table and make the host present before live play begins. The table will remain open until the round is started, cleared, or the next set is loaded. Confirm to continue.',
			this.hostTable,
			'Host'
		);
	};

	handleTableOpen = () => {
		this.confirmAction(
			'Open Table',
			'Open the table for players and begin the join process before the board goes live. Confirm to continue.',
			this.openTable,
			'Open Table'
		);
	};

	handleOpenPlay = () => {
		this.confirmAction(
			'Open Play',
			'Open live play for the card set now on the table. Table and set changes will remain locked until the round is stopped or closed. Confirm to continue.',
			this.startGame,
			'Open Play'
		);
	};

	handleHoldDraw = () => {
		this.confirmAction(
			'Hold Draw',
			'Place the live draw on hold for the current round. The table will stay locked in its current state until play resumes. Confirm to continue.',
			this.pauseGame,
			'Hold Draw'
		);
	};

	handleResumeDraw = () => {
		this.confirmAction(
			'Resume Draw',
			'Resume the live draw and continue the current round. Table and set changes will remain restricted during live play. Confirm to continue.',
			this.resumeGame,
			'Resume Draw'
		);
	};

	handleCallNextBall = () => {
		this.confirmAction(
			'Call Next Ball',
			'Call the next ball while no live round is in play. This will update the board state. Confirm to continue.',
			this.callNumber,
			'Call Next Ball'
		);
	};

	handleLoadNextSet = () => {
		this.confirmAction(
			'Load Next Set',
			'Close the current table, reset the board, and load the next available card set for play. Use only after the round is closed or cleared. Confirm to continue.',
			this.resetGame,
			'Load Next Set'
		);
	};

	handleBingo = () => {
		this.confirmAction(
			'Bingo',
			'Close the current round on a bingo call and move the table into its post-win state. Confirm to continue.',
			this.callBingo,
			'Bingo'
		);
	};

	handleClearFelt = () => {
		this.confirmAction(
			'Clear Felt',
			'Clear all cards from the table and remove the current play setup. Use only when live play is not active. Confirm to continue.',
			this.clearTable,
			'Clear Felt'
		);
	};

	handleCloseTable = () => {
		this.confirmAction(
			'Close Table',
			'Close the table and return to the closed state. No game will remain in session until the table is hosted or opened again. Confirm to continue.',
			this.closeTable,
			'Close Table'
		);
	};

	handleSetTable = () => {
		this.confirmAction(
			'Set the Felt',
			'Load the next eligible card set to the table and make it live for play. Only one live set can be on the table at a time. Confirm to continue.',
			this.setTable,
			'Set the Felt'
		);
	};

	handleAccessCodeChange = (event) => {
		const digitsOnly = String(event.target.value || '').replace(/\D/g, '').slice(0, 8);
		this.setState({
			accessCodeInput: digitsOnly,
			accessCodeVerified: digitsOnly === getCentralDateAccessCode(),
		});
	};

	startGame = () => {
		if (this.state.newGame) {
			this.say("Let's Play Bingo!");
		}
		if (this.tableOpeningTimeout) clearTimeout(this.tableOpeningTimeout);
		if (this.state.interval) {
			clearInterval(this.state.interval);
		}
		const nextInterval = setInterval(this.callNumber, this.state.delay);
		this.setState({ newGame: false, running: true, interval: nextInterval, tableEmpty: false, tableOpen: true, tableOpening: false, playPhase: 'active', sessionAction: 'open_play' }, () => {
			this.callNumber();
			if (this.isSharedHost && !this.isViewerMode && this.sharedSessionLoaded) this.pushSharedSession();
		});
	};

	callBingo = () => {
		this.cancelSpeech();
		if (this.state.running === true) {
			clearInterval(this.state.interval);
		}
		let bingoBalls = this.state.balls;
		_.map(bingoBalls, (ball, index) => {
			bingoBalls[index].active = false;
		});
		this.setState({ balls: bingoBalls, newGame: true, running: false, interval: 0, tableEmpty: false, tableOpen: true, tableOpening: false, playPhase: 'bingo', sessionAction: 'bingo' }, () => {
			if (this.isSharedHost && !this.isViewerMode && this.sharedSessionLoaded) this.pushSharedSession();
		});
	};

	pauseGame = () => {
		if (this.state.interval) {
			clearInterval(this.state.interval);
		}
		this.setState({ newGame: false, running: false, interval: 0, tableEmpty: false, tableOpen: true, tableOpening: false, playPhase: 'paused', sessionAction: 'hold_draw' }, () => {
			if (this.isSharedHost && !this.isViewerMode && this.sharedSessionLoaded) this.pushSharedSession();
		});
	};

	resumeGame = () => {
		if (this.state.interval) {
			clearInterval(this.state.interval);
		}
		const nextInterval = setInterval(this.callNumber, this.state.delay);
		this.setState({ newGame: false, running: true, interval: nextInterval, tableEmpty: false, tableOpen: true, tableOpening: false, playPhase: 'active', sessionAction: 'resume_draw' }, () => {
			if (this.isSharedHost && !this.isViewerMode && this.sharedSessionLoaded) this.pushSharedSession();
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
				tableEmpty: false,
				tableOpen: true,
				tableOpening: false,
				playPhase: this.state.playPhase || 'active',
				sessionAction: this.state.sessionAction || 'resume_draw',
			});
		} else {
			this.setState({ delay: e.target.value, tableEmpty: false, tableOpen: true, tableOpening: false, playPhase: this.state.playPhase || 'ready', sessionAction: this.state.sessionAction || 'set_table' });
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
		this.setState({ balls: balls, tableEmpty: false, tableOpen: true, tableOpening: false, playPhase: this.state.playPhase || 'active', callHistory: nextCallHistory, sessionAction: this.state.running ? (this.state.sessionAction || 'resume_draw') : (this.state.sessionAction || 'call_next_ball') }, () => {
			if (this.isSharedHost && !this.isViewerMode && this.sharedSessionLoaded) this.pushSharedSession();
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
		return this.state.showBackdrop ? 'show' : 'hide';
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

	getTableViewState = () => {
		const balls = this.state.balls || {};
		const hasCalledBall = Object.keys(balls).some((key) => {
			const ball = balls[key];
			return ball && (ball.called || ball.active);
		});
		if (!!this.state.tableOpening) return 'opening';
		if (!!this.state.tableOpen && !!this.state.tableEmpty) return 'open';
		if (!!this.state.tableOpen && !this.state.tableEmpty) return 'board';
		if (!!this.state.tableEmpty || (!this.state.running && !!this.state.newGame && !hasCalledBall)) {
			return 'closed';
		}
		return 'board';
	};

	/*
	 *  Render Method
	 *  Displays the bingo page
	 */
	render() {
		const tableViewState = this.getTableViewState();
		const showAccessCodeGate = tableViewState === 'closed' && !this.state.accessCodeVerified;
		const showAccessDenied = showAccessCodeGate && this.state.accessCodeInput.length === 8;
		const ballList = Object.keys(this.state.balls || {}).map((key) => this.state.balls[key]).filter(Boolean);
		const activeBall = ballList.find((ball) => ball && ball.active) || null;
		const showIdleTable = tableViewState !== 'board';
		const showHostButton = tableViewState === 'closed' && this.state.accessCodeVerified;
		const showHostedOnlyControls = this.state.playPhase === 'hosted';
		const showOnDeckControls =
			tableViewState === 'board' &&
			!this.state.running &&
			this.state.playPhase === 'on_deck';
		const showReadyControls =
			tableViewState === 'board' &&
			!this.state.running &&
			this.state.playPhase === 'ready';
		const showActiveControls =
			tableViewState === 'board' &&
			this.state.running &&
			this.state.playPhase === 'active';
		const showPausedControls =
			tableViewState === 'board' &&
			!this.state.running &&
			this.state.playPhase === 'paused';
		const showBingoControls =
			tableViewState === 'board' &&
			!this.state.running &&
			this.state.playPhase === 'bingo';
		const showClearedControls =
			tableViewState === 'board' &&
			!this.state.running &&
			this.state.playPhase === 'cleared';
		const showFullBoardControls =
			tableViewState === 'board' && !showOnDeckControls && !showReadyControls && !showActiveControls && !showPausedControls && !showBingoControls && !showClearedControls;
		const idleTableTitle = tableViewState === 'opening' ? 'Table Open' : 'Table Closed';
		const idleTableCopy = tableViewState === 'opening'
			? 'Host has opened the Table.'
			: tableViewState === 'open'
				? 'Host has joined the Table.'
				: 'No game is currently in session.';
		const idleLoadingLabel = tableViewState === 'opening' ? 'Joining' : 'Loading';
		const totalCalls = Array.isArray(this.state.callHistory) ? this.state.callHistory.length : 0;
		const previousCall = totalCalls > 1
			? this.state.callHistory[totalCalls - 2]
			: null;
		const previousCallText = previousCall ? String(previousCall.number) : '—';
		const priorCallHistory = activeBall
			? this.state.callHistory.slice(Math.max(totalCalls - 10, 0), Math.max(totalCalls - 1, 0))
			: this.state.callHistory.slice(Math.max(totalCalls - 9, 0));
		const previousBallSlots = Array.from({ length: 9 }, (_, index) => priorCallHistory[index] || null);
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
		return (
			<div>
				<div id="backdrop" className={this.backdropClasses}></div>
				<div id="disclaimer" className={this.alertClasses}>
					<h4 className="no-margin">Bingo!</h4>
					<p className="small-text">All of the bingo balls have been called!</p>
					<p>
						<button className="lpb-btn lpb-btn-clear" onClick={this.handleClearFelt}>Clear Felt</button> |{' '}
						<button className="lpb-btn lpb-btn-set" onClick={this.handleSetTable}>Set the Felt</button> |{' '}
						<button onClick={this.closeAlert}>Close</button>
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

				<header>
					<div className="row">
						<div className="col c100">
							<div className="logo-block">
								<img className="logo" src={logo} alt="Let's Play Bingo Logo" />
							</div>
						</div>
					</div>
				</header>

				<section id="board">
					{showIdleTable ? (
						<div className="row">
							<div className="lpb-table-closed">
								<div className="lpb-table-closed-card">
									<img className="lpb-table-closed-logo" src={logo} alt="Let's Play Bingo Logo" />
									<div className="lpb-table-closed-title">{idleTableTitle}</div>
									<div className="lpb-table-closed-copy">{idleTableCopy}</div>
									{tableViewState === 'opening' || (tableViewState === 'open' && this.state.sessionAction === 'host_table') ? (
										<div className="lpb-table-open-loading" aria-live="polite">
											<span className="lpb-table-open-loading-label">{idleLoadingLabel}</span>
											<span className="lpb-table-open-loading-dots">
												<span></span>
												<span></span>
												<span></span>
											</span>
										</div>
									) : null}
								</div>
							</div>
						</div>
					) : (
						<>
							<div className="row lpb-board-shell">
								<div className="lpb-board-side lpb-board-stats">
									<div className="lpb-call-summary-wrap notranslate">
										<div className="lpb-call-summary">
											<div className="lpb-call-summary-item">
												<div className="lpb-call-summary-box">
													<SevenSegmentText text={String(totalCalls)} variant="box" />
												</div>
												<div className="lpb-call-summary-label">Calls</div>
											</div>
											<div className="lpb-call-summary-item">
												<div className="lpb-call-summary-box">
													<SevenSegmentText text={previousCallText} variant="box" />
												</div>
												<div className="lpb-call-summary-label">Previous</div>
											</div>
										</div>
										<div className="lpb-call-pattern-wrap">
											<Pattern />
										</div>
									</div>
								</div>
								<div className="lpb-board-center">
									<div className="lpb-board-center-panel">
										<BingoBoard balls={this.state.balls} />
									</div>
								</div>
								<div className="lpb-board-side lpb-board-ball">
									<div className="lpb-board-ball-panel">
										<BallDisplay balls={this.state.balls} />
										<div className="lpb-call-history-grid notranslate" aria-label="Previous nine balls">
											{previousBallSlots.map((ball, index) => (
												<div
													key={ball ? `${ball.letter}${ball.number}-${index}` : `empty-${index}`}
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
									</div>
								</div>
							</div>
						</>
					)}
				</section>

				<section id="buttons">
					<div className="row">
						<div className="col c100">
							{showAccessCodeGate ? (
								<div className="lpb-access-code-wrap">
									<label className="lpb-access-code-label" htmlFor="lpb-access-code">Access Code</label>
									<input
										id="lpb-access-code"
										className="lpb-access-code-input"
										type="password"
										inputMode="numeric"
										autoComplete="off"
										maxLength={8}
										value={this.state.accessCodeInput}
										onChange={this.handleAccessCodeChange}
									/>
									{showAccessDenied ? (
										<div className="lpb-access-code-denied">Access Denied</div>
									) : null}
								</div>
							) : null}
							{showHostButton ? (
								<button className="lpb-btn lpb-btn-host" onClick={this.handleOpenTable}>Host</button>
							) : null}
							{showHostedOnlyControls ? (
								<>
									<button className="lpb-btn lpb-btn-open-table" onClick={this.handleTableOpen}>Open Table</button>
									<button className="lpb-btn lpb-btn-close-table" onClick={this.handleCloseTable}>Close Table</button>
								</>
							) : null}
							{showOnDeckControls ? (
								<>
									<button className="lpb-btn lpb-btn-set" onClick={this.handleSetTable}>Set the Felt</button>
									<button className="lpb-btn lpb-btn-close-table" onClick={this.handleCloseTable}>Close Table</button>
								</>
							) : null}
							{showReadyControls ? (
								<>
									<button className="lpb-btn lpb-btn-open" onClick={this.handleOpenPlay}>Open Play</button>
									<button className="lpb-btn lpb-btn-close-table" onClick={this.handleCloseTable}>Close Table</button>
								</>
							) : null}
							{showActiveControls ? (
								<>
									<button className="lpb-btn lpb-btn-hold" onClick={this.handleHoldDraw}>Hold Draw</button>
									<button className="lpb-btn lpb-btn-next" onClick={this.handleCallNextBall}>Call Next Ball</button>
								</>
							) : null}
							{showPausedControls ? (
								<>
									<button className="lpb-btn lpb-btn-resume" onClick={this.handleResumeDraw}>Resume Draw</button>
									<button className="lpb-btn lpb-btn-reset" onClick={this.handleBingo}>Bingo</button>
									<button className="lpb-btn lpb-btn-clear" onClick={this.handleClearFelt}>Clear Felt</button>
								</>
							) : null}
							{showBingoControls ? (
								<>
									<button className="lpb-btn lpb-btn-reset" onClick={this.handleLoadNextSet}>Load Next Set</button>
									<button className="lpb-btn lpb-btn-close-table" onClick={this.handleCloseTable}>Close Table</button>
								</>
							) : null}
							{showClearedControls ? (
								<>
									<button className="lpb-btn lpb-btn-set" onClick={this.handleSetTable}>Set the Felt</button>
									<button className="lpb-btn lpb-btn-close-table" onClick={this.handleCloseTable}>Close Table</button>
								</>
							) : null}
							{showFullBoardControls ? (
								<>
									<button className="lpb-btn lpb-btn-open" onClick={this.handleOpenPlay}>Open Play</button>
									<button className="lpb-btn lpb-btn-hold" onClick={this.handleHoldDraw}>Hold Draw</button>
									<button className="lpb-btn lpb-btn-resume" onClick={this.handleResumeDraw}>Resume Draw</button>
									<button className="lpb-btn lpb-btn-open-table" onClick={this.handleTableOpen}>Open Table</button>
									<button
										className="lpb-btn lpb-btn-next"
										onClick={this.handleCallNextBall}
										disabled={this.state.running ? 'disabled' : ''}
									>
										Call Next Ball
									</button>
									<button className="lpb-btn lpb-btn-clear" onClick={this.handleClearFelt}>Clear Felt</button>
									<button className="lpb-btn lpb-btn-close-table" onClick={this.handleCloseTable}>Close Table</button>
									<button className="lpb-btn lpb-btn-set" onClick={this.handleSetTable}>Set the Felt</button>
								</>
							) : null}
						</div>
					</div>
				</section>

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
