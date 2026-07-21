'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function createHarness(classes) {
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
				FakeMutationObserver.instance.notify(this.node, oldValue);
			}
		}
	}

	class FakeNode {
		constructor(names, attributes) {
			this.classList = new FakeClassList(this, names);
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
		constructor(parent, kind) {
			this.parent = parent;
			this.kind = kind;
		}

		closest(selector) {
			if (selector === 'a') return this;
			if (selector === '.flux') return this.parent;
			return null;
		}

		matches(selector) {
			return this.kind === 'title'
				? selector.includes('.item.titleAuthorSummaryDate a.title')
				: selector.includes('.item.link a');
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

	const entry = new FakeNode(classes, {'data-entry': '42', 'data-feed': '303'});
	const stream = new FakeNode([], {});
	const documentListeners = {};
	const windowListeners = {};
	const beacons = [];
	let now = 0;
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
		hidden: false,
		getElementById: id => id === 'stream' ? stream : null,
		querySelector: selector => selector === '.flux.current.active'
			&& entry.classList.contains('current') && entry.classList.contains('active') ? entry : null,
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

	return {
		entry,
		beacons,
		document,
		documentListeners,
		windowListeners,
		intersectionObserver: FakeIntersectionObserver.instance,
		setNow(value) { now = value; },
		publisherLink: new FakeAnchor(entry, 'icon'),
		publisherTitle: new FakeAnchor(entry, 'title'),
	};
}

function delay(milliseconds) {
	return new Promise(resolve => setTimeout(resolve, milliseconds));
}

async function collapsedVisibilityDoesNotStartTimer() {
	const harness = createHarness(['flux', 'not_read']);
	harness.intersectionObserver.callback([{
		isIntersecting: true,
		intersectionRatio: 1,
		target: harness.entry,
	}]);
	harness.setNow(5000);
	harness.windowListeners.pagehide();
	await delay(350);
	assert.equal(harness.beacons.length, 0, 'a visible but collapsed entry must not start active-time tracking');
}

async function collapsedPublisherLinkIsRecorded() {
	const harness = createHarness(['flux']);
	harness.documentListeners.click({target: harness.publisherLink});
	await delay(350);
	assert.equal(harness.beacons.length, 1, 'a publisher-link click must be recorded without expanding the entry');
	const event = harness.beacons[0].payload.events[0];
	assert.equal(event.link_opened, true);
	assert.equal(event.time_spent_ms, null, 'a link-only event must not claim measured reading time');
}

async function modifiedCollapsedTitleIsRecorded() {
	const harness = createHarness(['flux']);
	harness.documentListeners.click({target: harness.publisherTitle, metaKey: true, button: 0});
	await delay(350);
	assert.equal(harness.beacons.length, 1, 'a modified publisher-title click must be recorded without expanding the entry');
	assert.equal(harness.beacons[0].payload.events[0].link_opened, true);
}

async function collapsingEntryEndsTimer() {
	const harness = createHarness(['flux', 'active', 'not_read']);
	harness.intersectionObserver.callback([{
		isIntersecting: true,
		intersectionRatio: 1,
		target: harness.entry,
	}]);
	harness.documentListeners['freshrss:openArticle']({target: harness.entry});
	harness.setNow(4000);
	harness.entry.classList.remove('active');
	await delay(0);
	harness.setNow(10000);
	harness.windowListeners.pagehide();
	await delay(350);
	assert.equal(harness.beacons.length, 1, 'collapsing an entry should finalize one telemetry event');
	assert.equal(harness.beacons[0].payload.events[0].time_spent_ms, 4000, 'time must end when the entry is collapsed');
}

async function instantNavigationRecordsZeroTime() {
	const harness = createHarness(['flux', 'active', 'not_read']);
	harness.intersectionObserver.callback([{
		isIntersecting: true,
		intersectionRatio: 1,
		target: harness.entry,
	}]);
	harness.documentListeners['freshrss:openArticle']({target: harness.entry});
	harness.entry.classList.remove('active');
	await delay(350);
	assert.equal(harness.beacons.length, 1, 'leaving immediately must still create telemetry');
	assert.equal(harness.beacons[0].payload.events[0].time_spent_ms, 0, 'an immediate navigation should record a measured 0s interval');
}

async function openingEntryQueuesProvisionalZero() {
	const harness = createHarness(['flux', 'active', 'not_read']);
	harness.intersectionObserver.callback([{
		isIntersecting: true,
		intersectionRatio: 1,
		target: harness.entry,
	}]);
	harness.documentListeners['freshrss:openArticle']({target: harness.entry});
	await delay(350);
	assert.equal(harness.beacons.length, 1, 'opening an unread entry must create telemetry before mark-read completes');
	assert.equal(harness.beacons[0].payload.events[0].time_spent_ms, 0, 'the provisional measured interval should be 0 ms');
}

async function hidingPageEndsTimingSession() {
	const harness = createHarness(['flux', 'active', 'not_read']);
	harness.intersectionObserver.callback([{
		isIntersecting: true,
		intersectionRatio: 1,
		target: harness.entry,
	}]);
	harness.documentListeners['freshrss:openArticle']({target: harness.entry});
	harness.setNow(3000);
	harness.document.hidden = true;
	harness.documentListeners.visibilitychange();
	await delay(0);
	harness.document.hidden = false;
	harness.setNow(10000);
	harness.documentListeners.visibilitychange();
	harness.windowListeners.pagehide();
	await delay(350);
	assert.equal(harness.beacons.length, 1, 'hiding the page should finalize one telemetry event');
	assert.equal(harness.beacons[0].payload.events[0].time_spent_ms, 3000, 'returning to the same entry must not silently restart a finished session');
}

async function initiallyExpandedEntryStartsTimer() {
	const harness = createHarness(['flux', 'current', 'active', 'not_read']);
	harness.setNow(2000);
	harness.windowListeners.pagehide();
	await delay(350);
	assert.equal(harness.beacons.length, 1, 'an entry rendered expanded should start timing without another click');
	assert.equal(harness.beacons[0].payload.events[0].time_spent_ms, 2000);
}

Promise.allSettled([
	collapsedVisibilityDoesNotStartTimer(),
	collapsedPublisherLinkIsRecorded(),
	modifiedCollapsedTitleIsRecorded(),
	collapsingEntryEndsTimer(),
	instantNavigationRecordsZeroTime(),
	openingEntryQueuesProvisionalZero(),
	hidingPageEndsTimingSession(),
	initiallyExpandedEntryStartsTimer(),
]).then(results => {
	const failures = results.filter(result => result.status === 'rejected');
	if (failures.length > 0) {
		failures.forEach(failure => console.error(failure.reason));
		process.exitCode = 1;
		return;
	}
	console.log('Collapsed entry activation behavior passed.');
});
