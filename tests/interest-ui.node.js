'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class FakeElement {
	constructor(tag = 'div', parent = null) {
		this.tagName = tag.toUpperCase();
		this.parentElement = parent;
		this.children = [];
		this.dataset = {};
		this.attributes = {};
		this.className = '';
		this.textContent = '';
		this.title = '';
		this.classList = {
			add: (...names) => {
				const classes = new Set(this.className.split(/\s+/).filter(Boolean));
				names.forEach(name => classes.add(name));
				this.className = Array.from(classes).join(' ');
			},
			remove: (...names) => {
				const removed = new Set(names);
				this.className = this.className.split(/\s+/).filter(name => name && !removed.has(name)).join(' ');
			},
			contains: name => this.className.split(/\s+/).includes(name),
		};
	}

	appendChild(child) {
		child.parentElement = this;
		this.children.push(child);
		return child;
	}

	insertBefore(child, reference) {
		child.parentElement = this;
		const index = this.children.indexOf(reference);
		if (index === -1) {
			this.children.push(child);
		} else {
			this.children.splice(index, 0, child);
		}
		return child;
	}

	remove() {
		if (this.parentElement) {
			this.parentElement.children = this.parentElement.children.filter(child => child !== this);
		}
	}

	setAttribute(name, value) {
		this.attributes[name] = String(value);
	}

	getAttribute(name) {
		return this.attributes[name] ?? null;
	}

	matches(selector) {
		if (selector === '.flux[data-entry]') return this.className.includes('flux') && !!this.attributes['data-entry'];
		if (selector === '[data-interest-feedback]') return this.dataset.interestFeedback !== undefined;
		return false;
	}

	closest(selector) {
		if (selector === '[data-interest-feedback]' && this.dataset.interestFeedback !== undefined) return this;
		if (selector === '.flux') {
			let node = this;
			while (node && !node.className.includes('flux')) node = node.parentElement;
			return node;
		}
		return null;
	}

	querySelector(selector) {
		if (selector === '.flux_header .titleAuthorSummaryDate .title, .flux_content h1.title .go_website') {
			return this.titleNode || null;
		}
		if (selector === '.flux_header .titleAuthorSummaryDate .title') {
			return this.view === 'reader' ? null : this.titleNode || null;
		}
		if (selector === '.flux_content h1.title .go_website') {
			return this.view === 'reader' ? this.titleNode || null : null;
		}
		const match = child => {
			if (selector === '.interaction-interest') return child.className === 'interaction-interest';
			if (selector === '.interaction-interest-score') return child.className === 'interaction-interest-score';
			if (selector === '[data-interest-feedback="1"]') return child.dataset.interestFeedback === '1';
			return false;
		};
		const stack = [...this.children];
		while (stack.length) {
			const child = stack.shift();
			if (match(child)) return child;
			stack.push(...child.children);
		}
		return null;
	}

	querySelectorAll() {
		return [];
	}
}

class FakeMutationObserver {
	static instance = null;
	constructor(callback) {
		this.callback = callback;
		FakeMutationObserver.instance = this;
	}
	observe() {}
}

function makeEntry(id, view = 'normal') {
	const entry = new FakeElement();
	entry.className = 'flux';
	entry.view = view;
	entry.attributes['data-entry'] = id;
	entry.attributes['data-feed'] = '7';
	const titleParent = new FakeElement('div', entry);
	titleParent.className = view === 'reader' ? 'title' : 'item titleAuthorSummaryDate';
	const title = new FakeElement('a', titleParent);
	titleParent.appendChild(title);
	entry.titleNode = title;
	entry.appendChild(titleParent);
	return entry;
}

const entries = [makeEntry('1', 'normal'), makeEntry('2', 'reader'), makeEntry('3', 'global')];
const stream = new FakeElement();
const listeners = {};
const requests = [];
let serverModelVersion = 4;
const config = {
	tracking_enabled: true,
	tracked_feed_ids: [7],
	csrf: 'csrf-value',
	urls: {scores: '/scores', feedback: '/feedback'},
	interest: {enabled: true, display_mode: 'score', explicit_feedback: true},
	i18n: {
		interest_score: 'Interest %s', interest_reasons: 'Reasons', more_like_this: 'More like this',
		less_like_this: 'Less like this', clear_feedback: 'Clear feedback', feedback_failed: 'Failed',
	},
};
const document = {
	readyState: 'complete',
	createElement: tag => new FakeElement(tag),
	getElementById: id => id === 'stream' ? stream : null,
	querySelectorAll: selector => selector === '.flux[data-entry]' ? entries : [],
	addEventListener: (type, callback) => { listeners[type] = callback; },
};
const fetch = (url, options = {}) => {
	requests.push({url, options});
	if (url.startsWith('/scores')) {
		const ids = decodeURIComponent(url.split('ids=')[1] || '').split(',').filter(Boolean);
		const available = {
			'1': {score: 0.87, band: 'normal', positive_reasons: ['liked'], negative_reasons: [], cached: false},
			'2': {score: 0.12, band: 'low', positive_reasons: [], negative_reasons: ['boring'], cached: false},
			'3': {score: 0.55, band: 'unrated', positive_reasons: [], negative_reasons: [], cached: false},
			'4': {score: 0.66, band: 'normal', positive_reasons: ['new'], negative_reasons: [], cached: false},
			'5': {score: 0.44, band: 'unrated', positive_reasons: [], negative_reasons: ['later'], cached: false},
		};
		const scoreEntries = {};
		ids.forEach(id => { if (available[id]) scoreEntries[id] = available[id]; });
		return Promise.resolve({
			ok: true,
			json: () => Promise.resolve({
				status: 'ok', model_version: serverModelVersion,
				entries: scoreEntries,
				feedback: Object.fromEntries(ids.map(id => [id, id === '2' ? -1 : null])),
			}),
		});
	}
	return Promise.resolve({ok: true, json: () => Promise.resolve({ok: true, feedback: 1})});
};
const window = {
	context: {extensions: {interaction_analytics: config}},
	document,
	fetch,
	MutationObserver: FakeMutationObserver,
	setTimeout,
	clearTimeout,
};

const source = fs.readFileSync(path.join(__dirname, '..', 'static', 'interestModel.js'), 'utf8');
const cssSource = fs.readFileSync(path.join(__dirname, '..', 'static', 'interactionAnalytics.css'), 'utf8');
vm.runInNewContext(source, {window, document, fetch, MutationObserver: FakeMutationObserver, URLSearchParams, setTimeout, clearTimeout, console});

setTimeout(() => {
	const scoreRequests = requests.filter(request => request.url.startsWith('/scores'));
	assert.equal(scoreRequests.length, 1, 'visible entries must be fetched in one score batch');
	assert.match(scoreRequests[0].url, /ids=1%2C2%2C3/, 'normal, reader, and global entries must share one score batch');
	assert.equal(entries[0].querySelector('.interaction-interest-score').textContent, 'Interest 87%');
	assert.equal(entries[1].querySelector('.interaction-interest-score').textContent, 'Interest 12%');
	assert.equal(entries[2].querySelector('.interaction-interest-score').textContent, 'Interest 55%');
	entries.forEach(entry => {
		const title = entry.titleNode;
		const controls = entry.querySelector('.interaction-interest');
		assert.equal(
			title.parentElement.children.indexOf(controls) + 1,
			title.parentElement.children.indexOf(title),
			'interest controls must be inserted immediately to the left of the title in every view'
		);
		assert.equal(
			title.parentElement.classList.contains('interaction-interest-title-layout'),
			true,
			'the title container must opt into the non-overlapping interest layout'
		);
	});
	assert.match(
		cssSource,
		/grid-template-areas:\s*"interest title date"/,
		'normal and global views must reserve a dedicated grid area left of the title'
	);
	assert.match(
		cssSource,
		/\.interaction-interest\s*\{[^}]*position:\s*relative;[^}]*z-index:\s*1;/s,
		'interest controls must paint above the adjacent title link so both feedback buttons remain clickable'
	);

	const loaded = makeEntry('4');
	entries.push(loaded);
	FakeMutationObserver.instance.callback([{type: 'childList', addedNodes: [loaded]}]);
	setTimeout(() => {
		const loadedRequests = requests.filter(request => request.url.startsWith('/scores'));
		assert.equal(loadedRequests.length, 2, 'load-more must request only one additional batch');
		assert.match(loadedRequests[1].url, /ids=4$/, 'load-more must request only the newly loaded ID');
		assert.equal(loaded.querySelector('.interaction-interest-score').textContent, 'Interest 66%');

		serverModelVersion = 5;
		const later = makeEntry('5');
		entries.push(later);
		FakeMutationObserver.instance.callback([{type: 'childList', addedNodes: [later]}]);
		setTimeout(() => {
			const refreshedRequests = requests.filter(request => request.url.startsWith('/scores'));
			assert.equal(refreshedRequests.length, 4, 'a new model version must refresh previously requested entries once');
			assert.match(refreshedRequests[2].url, /ids=5$/, 'the version change is discovered from the newly loaded entry');
			assert.match(refreshedRequests[3].url, /ids=1%2C2%2C3%2C4$/, 'stale page-cache entries must be requested against the new model');

			const positive = entries[0].querySelector('[data-interest-feedback="1"]');
			listeners.click({target: positive, preventDefault() {}});
			setTimeout(() => {
				const feedbackRequest = requests.find(request => request.url === '/feedback');
				assert.ok(feedbackRequest, 'feedback must use one authenticated POST');
				assert.match(feedbackRequest.options.body.toString(), /entry_id=1/);
				assert.match(feedbackRequest.options.body.toString(), /feedback=1/);
				assert.equal(positive.attributes['aria-pressed'], 'true', 'feedback state must update optimistically');
				console.log('Interest score and feedback UI passed.');
			}, 0);
		}, 0);
	}, 0);
}, 0);
