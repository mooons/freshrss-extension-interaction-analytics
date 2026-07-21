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

	closest(selector) {
		return selector === '.flux' ? this : null;
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
	static instance = null;

	constructor(callback) {
		this.callback = callback;
		FakeIntersectionObserver.instance = this;
	}

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

const entry = new FakeNode(['flux', 'active', 'not_read'], {
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
let now = 0;

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
	performance: {now: () => now},
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

FakeIntersectionObserver.instance.callback([{
	isIntersecting: true,
	intersectionRatio: 1,
	target: entry,
}]);
documentListeners['freshrss:openArticle']({target: entry});
now = 5000;
entry.classList.remove('not_read');

queueMicrotask(() => {
	// FreshRSS auto-marks the entry read around this point, but the user
	// remains in the article until the browser is hidden at 10 seconds.
	now = 7000;
	FakeIntersectionObserver.instance.callback([{
		isIntersecting: true,
		intersectionRatio: 1,
		target: entry,
	}]);
	now = 10000;
	windowListeners.pagehide();
});

setTimeout(() => {
	assert.equal(beacons.length, 1, 'leaving the browser should flush one telemetry event');
	assert.equal(beacons[0].payload.events[0].entry_id, '42');
	assert.equal(beacons[0].payload.events[0].time_spent_ms, 10000, 'time should end at browser leave, not first read');
	console.log('Active-time leave boundary passed.');
}, 350);
