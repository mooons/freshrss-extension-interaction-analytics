(function () {
	'use strict';

	var config = null;
	var initialized = false;
	var observer = null;
	var mutationObserver = null;
	var visible = new Map();
	var analytics = new Map();
	var records = new Map();
	var pending = new Map();
	var active = null;
	var flushTimer = 0;

	function getConfig() {
		return window.context && window.context.extensions
			? window.context.extensions.interaction_analytics
			: null;
	}

	function entryId(node) {
		return node && node.getAttribute('data-entry');
	}

	function feedId(node) {
		return node && parseInt(node.getAttribute('data-feed') || '0', 10);
	}

	function isTrackedFeed(node) {
		return !!config && config.tracking_enabled && config.tracked_feed_ids.indexOf(feedId(node)) !== -1;
	}

	function isEligible(node) {
		return !!node && node.classList.contains('flux') && node.classList.contains('not_read') && isTrackedFeed(node);
	}

	function ensureRecord(node) {
		var id = entryId(node);
		if (!id || (!isTrackedFeed(node) && !analytics.has(id))) {
			return null;
		}
		if (!analytics.has(id) && !records.has(id) && !node.classList.contains('not_read')) {
			return null;
		}
		if (!records.has(id)) {
			var stored = analytics.get(id) || {};
			records.set(id, {
				entry_id: id,
				feed_id: feedId(node),
				total_ms: stored.time_spent_ms === null || stored.time_spent_ms === undefined ? 0 : stored.time_spent_ms,
				pending_ms: 0,
				first_read_at: stored.first_read_at || null,
				link_opened: stored.link_opened === undefined ? null : stored.link_opened,
				source: stored.source || 'web',
				measurement: stored.measurement || 'full'
			});
		}
		return records.get(id);
	}

	function queueRecord(record) {
		if (!record || !config || !config.tracking_enabled) {
			return;
		}
		if (!record.pending_ms && record.first_read_at === null && record.link_opened === null) {
			return;
		}
		var queued = pending.get(record.entry_id);
		if (!queued) {
			queued = {
				entry_id: record.entry_id,
				feed_id: record.feed_id,
				time_spent_ms: 0,
				first_read_at: null,
				link_opened: null
			};
			pending.set(record.entry_id, queued);
		}
		queued.time_spent_ms += record.pending_ms;
		queued.first_read_at = record.first_read_at || queued.first_read_at;
		if (record.link_opened !== null) {
			queued.link_opened = record.link_opened;
		}
		record.pending_ms = 0;
	}

	function flush() {
		if (flushTimer) {
			window.clearTimeout(flushTimer);
			flushTimer = 0;
		}
		if (!config || !config.tracking_enabled || pending.size === 0) {
			return;
		}
		var events = Array.from(pending.values());
		pending.clear();
		var body = JSON.stringify({_csrf: config.csrf, events: events});
		if (navigator.sendBeacon) {
			var blob = new Blob([body], {type: 'application/json'});
			if (navigator.sendBeacon(config.urls.record, blob)) {
				return;
			}
		}
		window.fetch(config.urls.record, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true,
			headers: {'Content-Type': 'application/json'},
			body: body
		}).catch(function () {});
	}

	function scheduleFlush() {
		if (!flushTimer) {
			flushTimer = window.setTimeout(flush, 250);
		}
	}

	function pauseActive() {
		if (!active) {
			return;
		}
		var record = ensureRecord(active.node);
		if (record) {
			var elapsed = Math.max(0, Math.round(window.performance.now() - active.started_at));
			record.total_ms += elapsed;
			record.pending_ms += elapsed;
			queueRecord(record);
			scheduleFlush();
		}
		active = null;
	}

	function switchActive(node) {
		var id = entryId(node);
		if (active && active.id === id) {
			return;
		}
		pauseActive();
		if (isEligible(node)) {
			ensureRecord(node);
			active = {id: id, node: node, started_at: window.performance.now()};
		}
	}

	function chooseActive() {
		var current = document.querySelector('.flux.current');
		if (isEligible(current)) {
			switchActive(current);
			return;
		}
		var best = null;
		var bestRatio = 0;
		visible.forEach(function (ratio, node) {
			if (isEligible(node) && ratio > bestRatio) {
				best = node;
				bestRatio = ratio;
			}
		});
		switchActive(best);
	}

	function markRead(node) {
		var record = ensureRecord(node);
		if (!record || record.first_read_at !== null) {
			return;
		}
		if (active && active.id === entryId(node)) {
			pauseActive();
		}
		record.first_read_at = Date.now();
		if (record.link_opened === null) {
			record.link_opened = false;
		}
		queueRecord(record);
		scheduleFlush();
		if (config.display_analytics) {
			renderBadge(node, record);
		}
	}

	function isPublisherLink(anchor, node) {
		if (!anchor || !node || anchor.closest('.website, .manage, .share, .labels, .text')) {
			return false;
		}
		return anchor.matches('.go_website, .item.titleAuthorSummaryDate a.title, .item.link a');
	}

	function formatTime(milliseconds) {
		var seconds = Math.max(0, Math.round(milliseconds / 1000));
		if (seconds < 60) {
			return seconds + 's';
		}
		var minutes = Math.floor(seconds / 60);
		return minutes + 'm ' + (seconds % 60) + 's';
	}

	function renderBadge(node, data) {
		if (!config.display_analytics || !node) {
			return;
		}
		var old = node.querySelector('.interaction-analytics-badges');
		if (old) {
			old.remove();
		}
		var badges = document.createElement('span');
		badges.className = 'interaction-analytics-badges';
		var sourceText = data.measurement === 'read-state-only' ? config.i18n.greader_source : config.i18n.web_source;
		var readText = data.first_read_at ? ' · first read ' + data.first_read_at : '';
		badges.title = sourceText + readText;
		badges.setAttribute('aria-label', sourceText + readText);
		var timeSpent = data.time_spent_ms;
		if (timeSpent === null || timeSpent === undefined) {
			timeSpent = data.total_ms;
		}
		if (timeSpent !== null && timeSpent !== undefined) {
			var time = document.createElement('span');
			time.className = 'interaction-analytics-badge';
			time.textContent = '⏱ ' + formatTime(timeSpent);
			time.title = config.i18n.time.replace('%s', formatTime(timeSpent));
			badges.appendChild(time);
		}
		var link = document.createElement('span');
		link.className = 'interaction-analytics-badge';
		if (data.link_opened === true) {
			link.dataset.state = 'opened';
			link.textContent = '↗ opened';
			link.title = config.i18n.opened;
		} else if (data.link_opened === false) {
			link.dataset.state = 'not-opened';
			link.textContent = '↗ not opened';
			link.title = config.i18n.not_opened;
		} else {
			link.dataset.state = 'unknown';
			link.textContent = '↗ ?';
			link.title = config.i18n.unknown;
		}
		badges.appendChild(link);
		var title = node.querySelector('.flux_header .titleAuthorSummaryDate .title, .flux_content h1.title .go_website');
		if (title && title.parentElement) {
			title.parentElement.appendChild(badges);
		}
	}

	function renderAll() {
		if (!config.display_analytics) {
			return;
		}
		document.querySelectorAll('.flux[data-entry]').forEach(function (node) {
			var id = entryId(node);
			if (analytics.has(id)) {
				renderBadge(node, analytics.get(id));
			}
		});
	}

	function requestSummary() {
		if (!config.display_analytics) {
			return;
		}
		var ids = Array.from(document.querySelectorAll('.flux[data-entry]')).map(entryId).filter(Boolean);
		ids = Array.from(new Set(ids)).filter(function (id) { return !analytics.has(id); });
		if (!ids.length) {
			return;
		}
		window.fetch(config.urls.summary + '&ids=' + encodeURIComponent(ids.join(',')), {
			credentials: 'same-origin',
			headers: {'Accept': 'application/json'}
		}).then(function (response) { return response.ok ? response.json() : null; }).then(function (payload) {
			if (!payload || !payload.entries) {
				return;
			}
			Object.keys(payload.entries).forEach(function (id) { analytics.set(id, payload.entries[id]); });
			renderAll();
		}).catch(function () {});
	}

	function observeNode(node) {
		if (observer && node.matches && node.matches('.flux[data-entry]')) {
			observer.observe(node);
		}
	}

	function setup() {
		if (initialized || !getConfig()) {
			return;
		}
		initialized = true;
		config = getConfig();
		config.tracked_feed_ids = (config.tracked_feed_ids || []).map(Number);
		document.querySelectorAll('.flux[data-entry]').forEach(observeNode);
		observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (item) {
				if (item.isIntersecting) {
					visible.set(item.target, item.intersectionRatio);
				} else {
					visible.delete(item.target);
				}
			});
			chooseActive();
		});
		document.querySelectorAll('.flux[data-entry]').forEach(observeNode);
		mutationObserver = new MutationObserver(function (mutations) {
			mutations.forEach(function (mutation) {
				mutation.addedNodes.forEach(function (node) {
					if (node.nodeType === 1) {
						if (node.matches('.flux[data-entry]')) observeNode(node);
						node.querySelectorAll('.flux[data-entry]').forEach(observeNode);
					}
				});
			});
			requestSummary();
		});
		var stream = document.getElementById('stream');
		if (stream) mutationObserver.observe(stream, {childList: true, subtree: true});
		document.addEventListener('freshrss:openArticle', function (event) {
			var node = event.target && event.target.closest ? event.target.closest('.flux') : null;
			if (node) switchActive(node);
		});
		document.addEventListener('freshrss:entryStateChange', function (event) {
			if (!event.detail || !event.detail.isRead) return;
			var node = document.getElementById('flux_' + event.detail.id);
			if (node) markRead(node);
		});
		document.addEventListener('click', function (event) {
			var anchor = event.target.closest ? event.target.closest('a') : null;
			var node = anchor && anchor.closest ? anchor.closest('.flux') : null;
			if (!isPublisherLink(anchor, node) || !isTrackedFeed(node)) return;
			var record = ensureRecord(node);
			if (!record) return;
			record.link_opened = true;
			queueRecord(record);
			scheduleFlush();
			if (config.display_analytics) renderBadge(node, record);
		});
		document.addEventListener('visibilitychange', function () {
			if (document.hidden) {
				pauseActive();
				flush();
			} else {
				chooseActive();
			}
		});
		window.addEventListener('pagehide', function () { pauseActive(); flush(); });
		if (config.display_analytics) requestSummary();
		chooseActive();
	}

	if (document.readyState !== 'loading' && getConfig()) {
		setup();
	} else {
		document.addEventListener('freshrss:globalContextLoaded', setup, {once: true});
	}
}());
