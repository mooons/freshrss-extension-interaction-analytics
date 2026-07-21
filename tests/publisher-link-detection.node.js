'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class FakeClassList {
	constructor(names) {
		this.names = new Set(names);
	}

	contains(name) {
		return this.names.has(name);
	}
}

class FakeNode {
	constructor(classes, attributes) {
		this.classList = new FakeClassList(classes);
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

class FakeAnchor {
	constructor(parent) {
		this.parent = parent;
	}

	closest(selector) {
		if (selector === 'a') return this;
		return selector === '.flux' ? this.parent : null;
	}

	// This is the selector that the pre-fix implementation incorrectly used
	// for a normal-view title toggle.
	matches(selector) {
		return selector.includes('.item.titleAuthorSummaryDate a.title');
	}
}

class FakeMutationObserver {
	constructor() {}
	observe() {}
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

documentListeners.click({target: new FakeAnchor(entry)});

setTimeout(() => {
	assert.equal(beacons.length, 0, 'normal-view title toggles must not mark the publisher link opened');
	console.log('Normal-view title link detection passed.');
}, 350);
