(function () {
	'use strict';

	var config = null;
	var initialized = false;
	var modelVersion = 0;
	var scores = new Map();
	var feedback = new Map();
	var requested = new Set();
	var mutationObserver = null;

	function getConfig() {
		return window.context && window.context.extensions
			? window.context.extensions.interaction_analytics
			: null;
	}

	function entryId(node) {
		return node && node.getAttribute('data-entry');
	}

	function feedId(node) {
		return node ? parseInt(node.getAttribute('data-feed') || '0', 10) : 0;
	}

	function tracked(node) {
		return config.tracked_feed_ids.indexOf(feedId(node)) !== -1;
	}

	function titlePlacement(node) {
		var title = node.querySelector('.flux_header .titleAuthorSummaryDate .title');
		if (!title) {
			title = node.querySelector('.flux_content h1.title .go_website');
		}
		return title && title.parentElement ? {parent: title.parentElement, title: title} : null;
	}

	function titleParent(node) {
		var placement = titlePlacement(node);
		return placement ? placement.parent : null;
	}

	function reasonText(score) {
		var parts = [];
		if (score.positive_reasons && score.positive_reasons.length) {
			parts.push('+ ' + score.positive_reasons.join(', '));
		}
		if (score.negative_reasons && score.negative_reasons.length) {
			parts.push('− ' + score.negative_reasons.join(', '));
		}
		return parts.length ? config.i18n.interest_reasons + ': ' + parts.join(' · ') : '';
	}

	function feedbackButton(value, label, selected) {
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'interaction-interest-feedback';
		button.dataset.interestFeedback = String(value);
		button.textContent = label;
		button.title = label;
		button.setAttribute('aria-label', label);
		button.setAttribute('aria-pressed', selected ? 'true' : 'false');
		return button;
	}

	function render(node) {
		if (!node || !tracked(node)) {
			return;
		}
		var placement = titlePlacement(node);
		if (!placement) {
			return;
		}
		var parent = placement.parent;
		var old = parent.querySelector('.interaction-interest');
		if (old) {
			old.remove();
		}
		parent.classList.remove('interaction-interest-title-layout');
		var id = entryId(node);
		var score = scores.get(id);
		if (!score && !config.interest.explicit_feedback) {
			return;
		}
		var container = document.createElement('span');
		container.className = 'interaction-interest';
		if (score && config.interest.display_mode === 'score') {
			var percentage = Math.max(0, Math.min(100, Math.round(score.score * 100)));
			var badge = document.createElement('span');
			badge.className = 'interaction-interest-score';
			badge.dataset.band = score.band || 'unrated';
			badge.textContent = config.i18n.interest_score.replace('%s', percentage + '%');
			var reasons = reasonText(score);
			badge.title = badge.textContent + ' (' + Number(score.score).toFixed(4) + ') · model ' + modelVersion
				+ (reasons ? ' · ' + reasons : '');
			badge.setAttribute('aria-label', badge.title);
			container.appendChild(badge);
		}
		if (config.interest.explicit_feedback) {
			var current = feedback.has(id) ? feedback.get(id) : null;
			container.appendChild(feedbackButton(1, config.i18n.more_like_this, current === 1));
			container.appendChild(feedbackButton(-1, config.i18n.less_like_this, current === -1));
			if (current === 1 || current === -1) {
				container.appendChild(feedbackButton(0, config.i18n.clear_feedback, false));
			}
		}
		parent.classList.add('interaction-interest-title-layout');
		parent.insertBefore(container, placement.title);
	}

	function renderAll() {
		document.querySelectorAll('.flux[data-entry]').forEach(render);
	}

	function requestScores() {
		var ids = Array.from(document.querySelectorAll('.flux[data-entry]'))
			.filter(tracked)
			.map(entryId)
			.filter(function (id) { return id && !requested.has(id); });
		ids = Array.from(new Set(ids)).slice(0, 250);
		if (!ids.length) {
			return;
		}
		ids.forEach(function (id) { requested.add(id); });
		window.fetch(config.urls.scores + '&ids=' + encodeURIComponent(ids.join(',')), {
			credentials: 'same-origin',
			headers: {'Accept': 'application/json'},
		}).then(function (response) {
			return response.ok ? response.json() : null;
		}).then(function (payload) {
			if (!payload) {
				return;
			}
			var versionChanged = modelVersion && payload.model_version && modelVersion !== payload.model_version;
			if (versionChanged) {
				scores.clear();
				requested.clear();
				ids.forEach(function (id) { requested.add(id); });
			}
			modelVersion = payload.model_version || modelVersion;
			Object.keys(payload.entries || {}).forEach(function (id) {
				scores.set(id, payload.entries[id]);
			});
			Object.keys(payload.feedback || {}).forEach(function (id) {
				feedback.set(id, payload.feedback[id]);
			});
			renderAll();
			if (versionChanged) {
				requestScores();
			}
		}).catch(function () {
			ids.forEach(function (id) { requested.delete(id); });
		});
	}

	function showFeedbackError(node) {
		var parent = titleParent(node);
		var container = parent && parent.querySelector('.interaction-interest');
		if (!container) return;
		var error = document.createElement('span');
		error.className = 'interaction-interest-error';
		error.textContent = config.i18n.feedback_failed;
		error.setAttribute('role', 'alert');
		container.appendChild(error);
	}

	function recordFeedback(event) {
		var button = event.target && event.target.closest ? event.target.closest('[data-interest-feedback]') : null;
		if (!button) {
			return;
		}
		var node = button.closest('.flux');
		if (!node || !tracked(node)) {
			return;
		}
		event.preventDefault();
		if (event.stopPropagation) event.stopPropagation();
		var id = entryId(node);
		var next = parseInt(button.dataset.interestFeedback, 10);
		var previous = feedback.has(id) ? feedback.get(id) : null;
		feedback.set(id, next === 0 ? null : next);
		button.setAttribute('aria-pressed', next === 0 ? 'false' : 'true');
		render(node);
		var body = new URLSearchParams();
		body.set('_csrf', config.csrf);
		body.set('entry_id', id);
		body.set('feedback', String(next));
		window.fetch(config.urls.feedback, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
			body: body,
		}).then(function (response) {
			return response.json().catch(function () { return null; }).then(function (payload) {
				if (!response.ok || !payload || !payload.ok) throw new Error('feedback failed');
				return payload;
			});
		}).then(function (payload) {
			feedback.set(id, payload.feedback);
			scores.delete(id);
			requested.delete(id);
			render(node);
			requestScores();
		}).catch(function () {
			feedback.set(id, previous);
			render(node);
			showFeedbackError(node);
		});
	}

	function setup() {
		if (initialized) {
			return;
		}
		config = getConfig();
		if (!config || !config.interest || !config.interest.enabled) {
			return;
		}
		initialized = true;
		config.tracked_feed_ids = (config.tracked_feed_ids || []).map(Number);
		document.addEventListener('click', recordFeedback);
		mutationObserver = new MutationObserver(function (mutations) {
			var hasNewEntries = mutations.some(function (mutation) {
				return mutation.type === 'childList' && mutation.addedNodes && mutation.addedNodes.length > 0;
			});
			if (hasNewEntries) requestScores();
		});
		var stream = document.getElementById('stream');
		if (stream) {
			mutationObserver.observe(stream, {childList: true, subtree: true});
		}
		requestScores();
	}

	document.addEventListener('freshrss:globalContextLoaded', setup);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', setup);
	} else {
		setup();
	}
}());
