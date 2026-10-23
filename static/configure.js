(function () {
	'use strict';

	var list = document.getElementById('interaction-source-groups');
	if (!list) return;
	var rows = Array.from(list.querySelectorAll('.interaction-analytics-feed-option'));
	var warning = document.getElementById('interaction-source-groups-retrain');
	var suggestions = document.getElementById('interaction-source-group-names');

	function name(value) {
		return value.normalize('NFKC').replace(/\s+/g, ' ').trim();
	}

	function refreshSuggestions() {
		var names = new Set();
		rows.forEach(function (row) {
			names.add(row.dataset.feedName);
			var group = name(row.querySelector('input[type="text"]').value);
			if (group) names.add(group);
		});
		suggestions.replaceChildren();
		names.forEach(function (value) {
			var option = document.createElement('option');
			option.value = value;
			suggestions.appendChild(option);
		});
	}

	function refresh() {
		var groups = new Map();
		var changed = false;
		rows.forEach(function (row) {
			var input = row.querySelector('input[type="text"]');
			var group = name(input.value);
			var key = group.toLowerCase();
			if (name(input.defaultValue).toLowerCase() !== key) changed = true;
			if (key && row.querySelector('input[type="checkbox"]').checked) {
				if (!groups.has(key)) groups.set(key, []);
				groups.get(key).push(row);
			}
		});
		rows.forEach(function (row) {
			var key = name(row.querySelector('input[type="text"]').value).toLowerCase();
			var peers = (groups.get(key) || []).filter(function (peer) { return peer !== row; });
			row.querySelector('.interaction-source-group-status').textContent = !row.querySelector('input[type="checkbox"]').checked
				? list.dataset.notTracked : !key ? list.dataset.separate : peers.length
					? list.dataset.sharedWith.replace('%s', peers.map(function (peer) { return peer.dataset.feedName; }).join(', '))
					: list.dataset.onlyFeed;
		});
		warning.hidden = !changed;
	}

	list.addEventListener('input', refresh);
	list.addEventListener('change', refresh);
	list.addEventListener('focusin', refreshSuggestions);
	list.closest('form').addEventListener('reset', function () { window.setTimeout(refresh, 0); });
	refreshSuggestions();
	refresh();
}());

(function () {
	'use strict';

	var form = document.getElementById('interaction-delete-form');
	if (!form) {
		return;
	}

	var status = document.getElementById('interaction-delete-status');
	var table = document.getElementById('interaction-analytics-summary');
	var noData = document.getElementById('interaction-analytics-no-data');
	var actions = document.getElementById('interaction-analytics-actions');
	form.dataset.enhanced = 'true';

	function selectedFeedIds() {
		return Array.prototype.map.call(
			form.querySelectorAll('input[name="feed_ids[]"]:checked'),
			function (input) { return input.value; }
		);
	}

	function setBusy(busy) {
		Array.prototype.forEach.call(form.querySelectorAll('button[type="submit"]'), function (button) {
			button.disabled = busy;
		});
	}

	function showStatus(state, message) {
		if (!status) {
			return;
		}
		status.dataset.state = state;
		status.className = 'interaction-analytics-delete-status alert ' + (state === 'success' ? 'alert-success' : 'alert-error');
		status.textContent = message;
		status.hidden = false;
	}

	function removeDeletedFeeds(feedIds, all) {
		var selected = {};
		feedIds.forEach(function (id) { selected[id] = true; });
		Array.prototype.forEach.call(
			document.querySelectorAll('.interaction-analytics-summary tbody tr[data-feed-id]'),
			function (row) {
				if (all || selected[row.dataset.feedId]) {
					row.remove();
				}
			}
		);
		Array.prototype.forEach.call(
			form.querySelectorAll('.interaction-analytics-delete-option[data-feed-id]'),
			function (option) {
				if (all || selected[option.dataset.feedId]) {
					option.remove();
				}
			}
		);

		if (document.querySelectorAll('.interaction-analytics-summary tbody tr[data-feed-id]').length === 0) {
			if (table) table.hidden = true;
			if (actions) actions.hidden = true;
			if (noData) noData.hidden = false;
		}
	}

	form.addEventListener('submit', function (event) {
		event.preventDefault();
		if (event.stopImmediatePropagation) {
			event.stopImmediatePropagation();
		}
		var all = !!event.submitter && event.submitter.name === 'all' && event.submitter.value === '1';
		var feedIds = selectedFeedIds();
		if (!all && feedIds.length === 0) {
			showStatus('error', form.dataset.selectFeeds);
			return;
		}

		var confirmation = all ? form.dataset.confirmAll : form.dataset.confirmSelected;
		if (!window.confirm(confirmation)) {
			return;
		}

		var body = new FormData(form);
		body.set('ajax', '1');
		if (all) {
			body.set('all', '1');
		} else {
			body.delete('all');
		}
		setBusy(true);

		window.fetch(form.action, {
			method: 'POST',
			body: body,
			credentials: 'same-origin',
			headers: {'Accept': 'application/json'},
		}).then(function (response) {
			return response.json().catch(function () {
				throw new Error(form.dataset.deleteFailed);
			}).then(function (payload) {
				if (!response.ok || !payload.ok) {
					throw new Error(payload.message || form.dataset.deleteFailed);
				}
				return payload;
			});
		}).then(function (payload) {
			removeDeletedFeeds(feedIds, all);
			showStatus('success', payload.message || (all ? form.dataset.successAll : form.dataset.successSelected));
		}).catch(function (error) {
			showStatus('error', error.message || form.dataset.deleteFailed);
		}).finally(function () {
			setBusy(false);
		});
	});
}());
