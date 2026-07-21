'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class FakeFormData {
	constructor(form) {
		this.values = new Map();
		for (const input of form.inputs) {
			if (input.checked || input.type === 'hidden') {
				this.append(input.name, input.value);
			}
		}
	}

	append(name, value) {
		const values = this.values.get(name) || [];
		values.push(value);
		this.values.set(name, values);
	}

	set(name, value) {
		this.values.set(name, [value]);
	}

	delete(name) {
		this.values.delete(name);
	}

	get(name) {
		return (this.values.get(name) || [null])[0];
	}
}

function makeElement(properties = {}) {
	return Object.assign({
		hidden: false,
		disabled: false,
		removed: false,
		remove() { this.removed = true; },
	}, properties);
}

async function runScenario(mode) {
	const feedId = mode === 'selected' ? '303' : '397';
	const csrf = makeElement({type: 'hidden', name: '_csrf', value: 'fixture'});
	const feed = makeElement({type: 'checkbox', name: 'feed_ids[]', value: feedId, checked: mode === 'selected'});
	const selectedButton = makeElement({type: 'submit', name: '', value: ''});
	const allButton = makeElement({type: 'submit', name: 'all', value: '1'});
	const submitter = mode === 'all' ? allButton : selectedButton;
	const status = makeElement({textContent: '', dataset: {}});
	const row = makeElement({dataset: {feedId}});
	const deleteOption = makeElement({dataset: {feedId}});
	const table = makeElement();
	const noData = makeElement({hidden: true});
	const exportForm = makeElement();
	const form = makeElement({
		action: '/delete',
		inputs: [csrf, feed],
		dataset: {
			confirmSelected: 'Delete selected?',
			confirmAll: 'Delete all?',
			successSelected: 'Selected deleted.',
			successAll: 'All deleted.',
			selectFeeds: 'Select a feed.',
			deleteFailed: 'Delete failed.',
		},
		addEventListener(type, callback) {
			assert.equal(type, 'submit');
			this.submitHandler = callback;
		},
		querySelectorAll(selector) {
			if (selector === 'input[name="feed_ids[]"]:checked') return feed.checked ? [feed] : [];
			if (selector === 'button[type="submit"]') return [selectedButton, allButton];
			if (selector === '.interaction-analytics-delete-option[data-feed-id]') return [deleteOption];
			return [];
		},
	});
	const document = {
		getElementById(id) {
			return {
				'interaction-delete-form': form,
				'interaction-delete-status': status,
				'interaction-export-form': exportForm,
				'interaction-analytics-summary': table,
				'interaction-analytics-no-data': noData,
			}[id] || null;
		},
		querySelectorAll(selector) {
			return selector === '.interaction-analytics-summary tbody tr[data-feed-id]' ? [row] : [];
		},
	};
	const requests = [];
	const confirmations = [];
	const window = {
		document,
		FormData: FakeFormData,
		confirm(message) {
			confirmations.push(message);
			return true;
		},
		fetch(url, options) {
			requests.push({url, options});
			return Promise.resolve({ok: true, json: () => Promise.resolve({ok: true})});
		},
	};

	const source = fs.readFileSync(path.join(__dirname, '..', 'static', 'configure.js'), 'utf8');
	vm.runInNewContext(source, {window, document, FormData: FakeFormData, console});

	let prevented = false;
	form.submitHandler({
		submitter,
		preventDefault() { prevented = true; },
	});
	await new Promise(resolve => setTimeout(resolve, 0));

	assert.equal(prevented, true, `${mode} deletion must stay on the configuration page`);
	if (mode === 'empty') {
		assert.equal(requests.length, 0, 'empty selected deletion must not make a request');
		assert.deepEqual(confirmations, []);
		assert.equal(row.removed, false);
		assert.equal(status.dataset.state, 'error');
		return;
	}
	assert.equal(requests.length, 1, `${mode} deletion must use one background request`);
	assert.equal(requests[0].options.body.get('ajax'), '1');
	assert.equal(requests[0].options.body.get('all'), mode === 'all' ? '1' : null);
	assert.deepEqual(confirmations, [mode === 'all' ? 'Delete all?' : 'Delete selected?']);
	assert.equal(row.removed, true, `${mode} deletion must update the summary`);
	assert.equal(status.dataset.state, 'success');
}

Promise.all([runScenario('selected'), runScenario('all'), runScenario('empty')]).then(() => {
	console.log('Delete actions stay on the configuration page.');
});
