'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class FakeClassList {
	constructor(node, names) {
		this.node = node;
		this.names = new Set(names);
	}

	contains(name) {
		return this.names.has(name);
	}

	remove(name) {
		const oldValue = Array.from(this.names).join(' ');
		this.names.delete(name);
		if (oldValue !== Array.from(this.names).join(' ')) {
			FakeMutationObserver.instance?.notify(this.node, oldValue);
		}
	}
}

class FakeNode {
	constructor(classes, attributes) {
		this.classList = new FakeClassList(this, classes);
		this.attributes = attributes;
	}

	getAttribute(name) {
		return this.attributes[name] || null;
	}

	matches(selector) {
		return selector === '.flux[data-entry]'
			&& this.classList.contains('flux')
			&& this.getAttribute('data-entry') !== null;
	}

	querySelector() {
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

	notify(target, oldValue) {
		queueMicrotask(() => this.callback([{
			type: 'attributes',
			attributeName: 'class',
			oldValue,
			target,
		}]));
	}
}

class FakeIntersectionObserver {
	constructor() {}
	observe() {}
}

class FakeBlob {
	constructor(parts) {
		this.body = parts.join('');
	}

	text() {
		return Promise.resolve(this.body);
	}
}

const entry = new FakeNode(['flux', 'not_read'], {
	'data-entry': '42',
	'data-feed': '303',
});
const stream = new FakeNode([], {});
const documentListeners = {};
const windowListeners = {};
const beacons = [];
const config = {
	tracking_enabled: true,
	display_analytics: false,
	tracked_feed_ids: [303],
	csrf: 'fixture',
	urls: {record: '/record', summary: '/summary'},
	i18n: {},
};

const document = {
	readyState: 'complete',
	getElementById: id => id === 'stream' ? stream : null,
	querySelector: () => null,
	querySelectorAll: selector => selector === '.flux[data-entry]' ? [entry] : [],
	addEventListener: (type, callback) => { documentListeners[type] = callback; },
};
const navigator = {
	sendBeacon: (url, blob) => {
		blob.text().then(body => beacons.push({url, payload: JSON.parse(body)}));
		return true;
	},
};
const window = {
	context: {extensions: {interaction_analytics: config}},
	document,
	navigator,
	Blob: FakeBlob,
	IntersectionObserver: FakeIntersectionObserver,
	MutationObserver: FakeMutationObserver,
	performance: {now: () => 0},
	setTimeout,
	clearTimeout,
	fetch: () => Promise.reject(new Error('summary is disabled in this fixture')),
	addEventListener: (type, callback) => { windowListeners[type] = callback; },
};

const source = fs.readFileSync(path.join(__dirname, '..', 'static', 'interactionAnalytics.js'), 'utf8');
vm.runInNewContext(source, {
	window,
	document,
	navigator,
	Blob: FakeBlob,
	IntersectionObserver: FakeIntersectionObserver,
	MutationObserver: FakeMutationObserver,
	performance: window.performance,
	setTimeout,
	clearTimeout,
	console,
});

entry.classList.remove('not_read');

setTimeout(() => {
	assert.equal(beacons.length, 1, 'class transition should flush one telemetry event');
	assert.equal(beacons[0].url, '/record');
	assert.equal(beacons[0].payload.events[0].entry_id, '42');
	assert.equal(beacons[0].payload.events[0].feed_id, 303);
	assert.equal(beacons[0].payload.events[0].link_opened, false);
	assert.ok(beacons[0].payload.events[0].first_read_at > 0);
	assert.ok(documentListeners.visibilitychange === undefined || typeof documentListeners.visibilitychange === 'function');
	assert.ok(windowListeners.pagehide === undefined || typeof windowListeners.pagehide === 'function');
	console.log('FreshRSS 1.29.1 class-transition fallback passed.');
}, 350);
