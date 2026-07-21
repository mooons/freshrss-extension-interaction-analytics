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
