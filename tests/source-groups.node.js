'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function row(feedName, checked = true) {
	const input = {value: '', defaultValue: ''};
	const checkbox = {checked};
	const status = {textContent: ''};
	return {
		dataset: {feedName}, input, checkbox, status,
		querySelector(selector) {
			return selector === 'input[type="text"]' ? input
				: selector === 'input[type="checkbox"]' ? checkbox : status;
		},
	};
}
const rows = [row('Keylol'), row('Keylol Full'), row('Other feed'), row('<Inactive>', false)];
const handlers = {};
const formHandlers = {};
const list = {
	dataset: {sharedWith: 'Shares with: %s', onlyFeed: 'Only this feed', separate: 'Separate', notTracked: 'Not tracked'},
	querySelectorAll() { return rows; },
	addEventListener(event, handler) { handlers[event] = handler; },
	closest() { return {addEventListener(event, handler) { formHandlers[event] = handler; }}; },
};
const warning = {hidden: true};
const suggestions = {
	options: [],
	replaceChildren() { this.options = []; },
	appendChild(option) { this.options.push(option.value); },
};
const document = {
	getElementById(id) {
		return {'interaction-source-groups': list, 'interaction-source-groups-retrain': warning,
			'interaction-source-group-names': suggestions}[id] || null;
	},
	createElement() { return {}; },
};
vm.runInNewContext(fs.readFileSync(`${__dirname}/../static/configure.js`, 'utf8'), {
	document, window: {setTimeout(handler) { handler(); }},
});
assert.equal(rows[0].status.textContent, 'Separate');
assert.equal(warning.hidden, true);
rows[0].input.value = 'New website';
handlers.input();
assert.ok(!suggestions.options.includes('New website'), 'Typing must not rebuild and close the native suggestion popup');
handlers.focusin();
assert.ok(suggestions.options.includes('New website'), 'A newly named group must be suggested on the next field');
rows[0].input.value = 'Keylol';
handlers.input();
assert.equal(rows[0].status.textContent, 'Only this feed');
assert.equal(warning.hidden, false);
assert.ok(suggestions.options.includes('Keylol'));
rows[1].input.value = '  ＫＥＹＬＯＬ  ';
handlers.input();
assert.equal(rows[0].status.textContent, 'Shares with: Keylol Full');
assert.equal(rows[1].status.textContent, 'Shares with: Keylol');
rows[1].checkbox.checked = false;
handlers.change();
assert.equal(rows[0].status.textContent, 'Only this feed');
assert.equal(rows[1].status.textContent, 'Not tracked');
assert.equal(rows[1].input.value, '  ＫＥＹＬＯＬ  ', 'Disabling tracking must retain the group');
rows[0].input.value = rows[0].input.defaultValue = 'Keylol';
rows[1].input.value = rows[1].input.defaultValue = '';
formHandlers.reset();
assert.equal(warning.hidden, true, 'Reset must update the unsaved-change warning');
rows[0].input.value = 'keylol';
handlers.input();
assert.equal(warning.hidden, true, 'Case-only changes must not request retraining');
rows[0].input.value = '';
handlers.input();
assert.equal(rows[0].status.textContent, 'Separate');
assert.equal(warning.hidden, false, 'Leaving a saved group must request retraining');
console.log('Source-group settings UX passed.');
