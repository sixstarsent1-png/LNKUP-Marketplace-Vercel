/**
 * Organizer dashboard → Requests (review workspace, behind application_review_v2_enabled).
 *
 * Queue: lnkup/v1/applications/review/queue. Side panel: /applications/{id}/workspace.
 * Every decision is validated on the server; the actions shown come from the server too.
 * DOM is built with textContent only; server strings are never injected as HTML.
 */
(function () {
	'use strict';

	var cfg = window.lnkupReview || {};
	var root = document.getElementById('lnkup-review-app');
	if (!root || !cfg.root) {
		return;
	}

	var ERROR_COPY = "We couldn't load this section. Try refreshing or contact support.";
	var BULK_LABELS = { approve: 'Approve', reject: 'Decline', waitlist: 'Waitlist', review: 'Mark in review' };
	var PAYMENT_LABELS = { paid: 'Paid', due: 'Payment due', processing: 'Payment processing', not_due: '' };
	var REC_LABELS = { approve: 'Approve', decline: 'Decline', waitlist: 'Waitlist', abstain: 'No recommendation' };
	var BOARD_GROUPS = ['pending', 'info', 'waitlisted', 'booking', 'confirmed', 'declined'];

	var params = new URLSearchParams(window.location.search);
	// Links from Home, Events and the classic list use ?filter=; map them to review groups.
	var FILTER_GROUPS = { pending: 'pending', needs_info: 'info', approved: 'booking', unpaid: 'booking', paid: 'confirmed', waitlisted: 'waitlisted', rejected: 'declined' };
	// Inside an event workspace the table is scoped to that event and the Event column is hidden.
	var scopedEvent = root.getAttribute('data-scope') === 'event' ? (root.getAttribute('data-event-id') || '') : '';
	var state = {
		group: params.get('group') || FILTER_GROUPS[params.get('filter') || ''] || 'pending',
		event_id: scopedEvent || params.get('event_id') || params.get('event_filter') || '',
		assigned: params.get('assigned') || '',
		search: params.get('q') || '',
		missing_docs: params.get('missing_docs') === '1' || params.get('filter') === 'missing_docs',
		layout: params.get('layout') === 'board' ? 'board' : 'table',
		page: Math.max(1, parseInt(params.get('page') || '1', 10) || 1),
		selected: {},
		data: null,
		panelId: 0
	};

	/* ---------- helpers ---------- */

	async function api(path, options) {
		options = options || {};
		try {
			var response = await fetch(cfg.root + path, {
				method: options.method || 'GET',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
				body: options.body ? JSON.stringify(options.body) : undefined
			});
			var type = response.headers.get('content-type') || '';
			if (type.indexOf('application/json') === -1) {
				console.error('LNKup: expected JSON from', path, await response.text());
				return { ok: false, error: 'Invalid server response.' };
			}
			var json = await response.json();
			if (!response.ok) {
				return { ok: false, status: response.status, error: json.message || 'Request failed.', code: json.code || '' };
			}
			return { ok: true, data: json };
		} catch (error) {
			console.error('LNKup API error:', error);
			return { ok: false, error: 'Network error. Check your connection and try again.' };
		}
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			var value = attrs[key];
			if (key === 'text') {
				node.textContent = value;
			} else if (key === 'class') {
				node.className = value;
			} else if (key.indexOf('on') === 0) {
				node.addEventListener(key.slice(2), value);
			} else if (value !== null && value !== undefined && value !== false) {
				node.setAttribute(key, value === true ? '' : value);
			}
		});
		(children || []).forEach(function (child) {
			if (child !== null && child !== undefined && child !== false) {
				node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
			}
		});
		return node;
	}

	function replace(target, node) {
		target.innerHTML = '';
		target.appendChild(node);
	}

	function loading() {
		return el('div', { class: 'lnkup-review__loading', role: 'status' }, [el('span', { class: 'lnkup-review__spinner', 'aria-hidden': 'true' }), el('span', { text: 'Loading…' })]);
	}

	function errorCard(message, retry) {
		return el('div', { class: 'lnkup-empty-state lnkup-empty-state--error', role: 'alert' }, [
			el('p', { text: message || ERROR_COPY }),
			retry ? el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Try again', onclick: retry }) : null
		]);
	}

	function busy(button, on) {
		if (!button) {
			return;
		}
		button.disabled = on;
		button.setAttribute('aria-busy', on ? 'true' : 'false');
	}

	function badge(text, tone) {
		return text ? el('span', { class: 'lnkup-review__badge' + (tone ? ' is-' + tone : ''), text: text }) : null;
	}

	function syncUrl() {
		var url = new URL(window.location.href);
		var set = function (key, value) {
			if (value) {
				url.searchParams.set(key, value);
			} else {
				url.searchParams.delete(key);
			}
		};
		set('group', state.group);
		set('event_id', state.event_id);
		set('assigned', state.assigned);
		set('q', state.search);
		set('missing_docs', state.missing_docs ? '1' : '');
		set('layout', state.layout === 'board' ? 'board' : '');
		set('page', state.page > 1 ? String(state.page) : '');
		url.searchParams.delete('filter');
		url.searchParams.delete('event_filter');
		if (window.history && window.history.replaceState) {
			window.history.replaceState({}, '', url.toString());
		}
	}

	function queuePath() {
		var q = new URLSearchParams();
		q.set('per_page', state.layout === 'board' ? '50' : '25');
		q.set('page', String(state.page));
		if (state.layout !== 'board' && state.group) {
			q.set('group', state.group);
		}
		if (state.event_id) {
			q.set('event_id', state.event_id);
		}
		if (state.assigned) {
			q.set('assigned', state.assigned);
		}
		if (state.search) {
			q.set('search', state.search);
		}
		if (state.missing_docs) {
			q.set('missing_docs', '1');
		}
		return '/applications/review/queue?' + q.toString();
	}

	/* ---------- queue ---------- */

	var layout = el('div', { class: 'lnkup-review__layout' });
	var toolbar = el('div', { class: 'lnkup-review__toolbar' });
	var chips = el('div', { class: 'lnkup-review__chips', role: 'group', 'aria-label': 'Filter by status' });
	var bulkBar = el('div', { class: 'lnkup-review__bulk', hidden: true, role: 'region', 'aria-label': 'Bulk actions' });
	var listRoot = el('div', { class: 'lnkup-review__list' });
	var pager = el('nav', { class: 'lnkup-review__pager', 'aria-label': 'Pages' });
	var panel = el('aside', { class: 'lnkup-review__panel', hidden: true, 'aria-label': 'Application review', tabindex: '-1' });
	layout.appendChild(el('div', { class: 'lnkup-review__main' }, [toolbar, chips, bulkBar, listRoot, pager]));
	layout.appendChild(panel);
	replace(root, layout);

	async function loadQueue() {
		syncUrl();
		replace(listRoot, loading());
		var res = await api(queuePath());
		if (!res.ok) {
			replace(listRoot, errorCard(res.status === 403 ? res.error : ERROR_COPY, res.status === 403 ? null : loadQueue));
			if (res.status === 403 && state.event_id) {
				state.event_id = '';
			}
			return;
		}
		state.data = res.data;
		renderToolbar();
		renderChips();
		renderList();
		renderPager();
		renderBulkBar();
	}

	function renderToolbar() {
		toolbar.innerHTML = '';
		var events = (state.data && state.data.events) || [];
		var eventSelect = el('select', { 'aria-label': 'Event' }, [el('option', { value: '', text: 'All events' })]);
		events.forEach(function (ev) {
			var opt = el('option', { value: String(ev.id), text: ev.title + (ev.date ? ' · ' + ev.date : '') });
			if (String(ev.id) === String(state.event_id)) {
				opt.selected = true;
			}
			eventSelect.appendChild(opt);
		});
		eventSelect.addEventListener('change', function () {
			state.event_id = eventSelect.value;
			state.page = 1;
			loadQueue();
		});
		var assigned = el('select', { 'aria-label': 'Reviewer' }, [
			el('option', { value: '', text: 'Any reviewer' }),
			el('option', { value: 'me', text: 'Assigned to me' })
		]);
		assigned.value = state.assigned === 'me' ? 'me' : '';
		assigned.addEventListener('change', function () {
			state.assigned = assigned.value;
			state.page = 1;
			loadQueue();
		});
		var search = el('input', { type: 'search', placeholder: 'Search vendor or answer', value: state.search, 'aria-label': 'Search applications' });
		var timer = null;
		search.addEventListener('input', function () {
			clearTimeout(timer);
			timer = setTimeout(function () {
				state.search = search.value.trim();
				state.page = 1;
				loadQueue();
			}, 350);
		});
		var docs = el('input', { type: 'checkbox' });
		docs.checked = state.missing_docs;
		docs.addEventListener('change', function () {
			state.missing_docs = docs.checked;
			state.page = 1;
			loadQueue();
		});
		var layoutBtn = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', 'aria-pressed': state.layout === 'board' ? 'true' : 'false', text: state.layout === 'board' ? 'Table view' : 'Board view' });
		layoutBtn.addEventListener('click', function () {
			state.layout = state.layout === 'board' ? 'table' : 'board';
			state.page = 1;
			state.selected = {};
			loadQueue();
		});
		toolbar.appendChild(el('div', { class: 'lnkup-review__filters' }, [
			events.length && !scopedEvent ? eventSelect : null,
			assigned,
			search,
			el('label', { class: 'lnkup-review__check' }, [docs, el('span', { text: 'Missing documents' })]),
			layoutBtn
		]));
	}

	function renderChips() {
		chips.innerHTML = '';
		if (state.layout === 'board') {
			chips.hidden = true;
			return;
		}
		chips.hidden = false;
		var groups = (state.data && state.data.groups) || {};
		var counts = (state.data && state.data.counts) || {};
		Object.keys(groups).forEach(function (key) {
			var active = state.group === key;
			chips.appendChild(el('button', {
				type: 'button',
				class: 'lnkup-review__chip' + (active ? ' is-active' : ''),
				'aria-pressed': active ? 'true' : 'false',
				text: groups[key] + ' (' + (counts[key] || 0) + ')',
				onclick: function () {
					state.group = key;
					state.page = 1;
					state.selected = {};
					loadQueue();
				}
			}));
		});
	}

	function emptyState() {
		var filtered = state.event_id || state.assigned || state.search || state.missing_docs;
		return el('div', { class: 'lnkup-empty-state' }, [
			el('p', { text: filtered ? 'No applications match these filters.' : 'Applications will appear here once vendors apply to your opportunities.' }),
			filtered ? el('button', {
				type: 'button',
				class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm',
				text: 'Clear filters',
				onclick: function () {
					state.event_id = '';
					state.assigned = '';
					state.search = '';
					state.missing_docs = false;
					state.page = 1;
					loadQueue();
				}
			}) : null
		]);
	}

	function reviewSummary(item) {
		var review = item.review || {};
		if (review.average !== null && review.average !== undefined) {
			return review.average.toFixed(1) + ' / 5 · ' + review.completed + (review.completed === 1 ? ' review' : ' reviews');
		}
		if (review.completed) {
			return review.my_complete ? review.completed + ' submitted' : 'Score after you review';
		}
		return 'Not scored';
	}

	function selectBox(item) {
		var box = el('input', { type: 'checkbox', 'aria-label': 'Select ' + item.vendor_name });
		box.checked = !!state.selected[item.id];
		box.addEventListener('click', function (event) {
			event.stopPropagation();
		});
		box.addEventListener('change', function () {
			if (box.checked) {
				state.selected[item.id] = item.vendor_name;
			} else {
				delete state.selected[item.id];
			}
			renderBulkBar();
		});
		return box;
	}

	function canBulk() {
		var items = (state.data && state.data.items) || [];
		return items.some(function (item) {
			return (item.actions || []).indexOf('approve') !== -1 || (item.actions || []).indexOf('reject') !== -1 || (item.actions || []).indexOf('waitlist') !== -1;
		});
	}

	function renderList() {
		var items = (state.data && state.data.items) || [];
		if (!items.length) {
			replace(listRoot, emptyState());
			return;
		}
		if (state.layout === 'board') {
			renderBoard(items);
			return;
		}
		var bulk = canBulk();
		var table = el('table', { class: 'lnkup-review__table' });
		table.appendChild(el('thead', {}, [el('tr', {}, [
			bulk ? el('th', { scope: 'col' }, [el('span', { class: 'screen-reader-text', text: 'Select' })]) : null,
			el('th', { scope: 'col', text: 'Vendor' }),
			scopedEvent ? null : el('th', { scope: 'col', text: 'Event' }),
			el('th', { scope: 'col', text: 'Status' }),
			el('th', { scope: 'col', text: 'Review' }),
			el('th', { scope: 'col', text: 'Submitted' })
		])]));
		var body = el('tbody');
		items.forEach(function (item) {
			var open = el('button', { type: 'button', class: 'lnkup-review__open', text: item.vendor_name || ('Application #' + item.id), onclick: function () { openPanel(item.id); } });
			var tr = el('tr', { class: state.panelId === item.id ? 'is-open' : '', 'data-app-id': String(item.id) }, [
				bulk ? el('td', {}, [selectBox(item)]) : null,
				el('td', {}, [
					open,
					el('div', { class: 'lnkup-review__meta' }, [
						item.category ? el('span', { text: item.category }) : null,
						item.returning ? badge('Returning', 'good') : null,
						item.assigned_to_me ? badge('Assigned to you', 'info') : null
					])
				]),
				scopedEvent ? null : el('td', {}, [el('div', { text: item.event_title }), el('div', { class: 'lnkup-muted', text: item.event_date || '' })]),
				el('td', {}, [
					badge(item.status_label, item.group),
					PAYMENT_LABELS[item.payment_state] ? el('div', { class: 'lnkup-muted', text: PAYMENT_LABELS[item.payment_state] }) : null,
					item.waitlist_position ? el('div', { class: 'lnkup-muted', text: 'Waitlist #' + item.waitlist_position }) : null
				]),
				el('td', { text: reviewSummary(item) }),
				el('td', { class: 'lnkup-muted', text: (item.submitted_at || '').slice(0, 10) })
			]);
			body.appendChild(tr);
		});
		table.appendChild(body);
		replace(listRoot, el('div', { class: 'lnkup-review__table-wrap' }, [table]));
	}

	function renderBoard(items) {
		var groups = (state.data && state.data.groups) || {};
		var board = el('div', { class: 'lnkup-review__board' });
		BOARD_GROUPS.forEach(function (key) {
			var cards = items.filter(function (item) { return item.group === key; });
			var col = el('section', { class: 'lnkup-review__column', 'aria-label': groups[key] || key }, [
				el('h3', { class: 'lnkup-review__column-title', text: (groups[key] || key) + ' · ' + cards.length })
			]);
			if (!cards.length) {
				col.appendChild(el('p', { class: 'lnkup-muted', text: 'Nothing here.' }));
			}
			cards.forEach(function (item) {
				col.appendChild(el('button', { type: 'button', class: 'lnkup-review__card', onclick: function () { openPanel(item.id); } }, [
					el('strong', { text: item.vendor_name || ('Application #' + item.id) }),
					el('span', { class: 'lnkup-muted', text: item.event_title }),
					el('span', { class: 'lnkup-muted', text: reviewSummary(item) })
				]));
			});
			board.appendChild(col);
		});
		var note = state.data && state.data.total > items.length ? el('p', { class: 'lnkup-muted', text: 'Showing the newest ' + items.length + ' of ' + state.data.total + '. Filter by event or switch to table view to see the rest.' }) : null;
		replace(listRoot, el('div', {}, [note, board]));
	}

	function renderPager() {
		pager.innerHTML = '';
		var pages = (state.data && state.data.pages) || 0;
		if (state.layout === 'board' || pages < 2) {
			return;
		}
		var prev = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Previous', disabled: state.page <= 1 });
		var next = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Next', disabled: state.page >= pages });
		prev.addEventListener('click', function () { state.page -= 1; loadQueue(); });
		next.addEventListener('click', function () { state.page += 1; loadQueue(); });
		pager.appendChild(prev);
		pager.appendChild(el('span', { class: 'lnkup-muted', text: 'Page ' + state.page + ' of ' + pages + ' · ' + state.data.total + ' total' }));
		pager.appendChild(next);
	}

	/* ---------- bulk ---------- */

	function renderBulkBar() {
		var ids = Object.keys(state.selected);
		bulkBar.innerHTML = '';
		bulkBar.hidden = !ids.length;
		if (!ids.length) {
			return;
		}
		bulkBar.appendChild(el('strong', { text: ids.length + ' selected' }));
		Object.keys(BULK_LABELS).forEach(function (action) {
			bulkBar.appendChild(el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--sm ' + (action === 'approve' ? 'lnkup-btn--primary' : 'lnkup-btn--outline'), text: BULK_LABELS[action] + '…', onclick: function () { bulkPreview(action); } }));
		});
		bulkBar.appendChild(el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Clear', onclick: function () { state.selected = {}; renderList(); renderBulkBar(); } }));
	}

	function modal(title, body, footer) {
		var dialog = el('div', { class: 'lnkup-review__modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': title });
		var close = function () {
			dialog.remove();
			document.removeEventListener('keydown', onKey);
		};
		var onKey = function (event) {
			if (event.key === 'Escape') {
				close();
			}
		};
		document.addEventListener('keydown', onKey);
		dialog.appendChild(el('div', { class: 'lnkup-review__modal-card' }, [
			el('h2', { text: title }),
			body,
			el('div', { class: 'lnkup-review__modal-actions' }, footer(close))
		]));
		dialog.addEventListener('click', function (event) {
			if (event.target === dialog) {
				close();
			}
		});
		document.body.appendChild(dialog);
		var first = dialog.querySelector('button, textarea, select, input');
		if (first) {
			first.focus();
		}
		return close;
	}

	async function bulkPreview(action) {
		var ids = Object.keys(state.selected).map(Number);
		var body = el('div', {}, [loading()]);
		var confirmBtn = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--primary', text: 'Confirm', disabled: true });
		var reason = action === 'reject' ? el('textarea', { rows: '3', maxlength: '2000', placeholder: 'Optional note shared with declined vendors' }) : null;
		var close = modal(BULK_LABELS[action] + ' ' + ids.length + ' applications', body, function (closeFn) {
			return [el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost', text: 'Cancel', onclick: closeFn }), confirmBtn];
		});
		var res = await api('/applications/bulk/preview', { method: 'POST', body: { action: action, ids: ids } });
		if (!res.ok) {
			replace(body, errorCard(res.error));
			return;
		}
		var preview = res.data;
		var wrap = el('div');
		wrap.appendChild(el('p', { text: preview.eligible.length + ' will change' + (preview.events.length ? ' across: ' + preview.events.join(', ') : '') + '.' }));
		if (preview.eligible.length) {
			wrap.appendChild(el('ul', { class: 'lnkup-review__preview' }, preview.eligible.map(function (row) {
				return el('li', { text: row.vendor_name + ' — ' + row.event_title });
			})));
		}
		if (preview.ineligible.length) {
			wrap.appendChild(el('p', { class: 'lnkup-review__warn', text: preview.ineligible.length + ' will be skipped:' }));
			wrap.appendChild(el('ul', { class: 'lnkup-review__preview is-skipped' }, preview.ineligible.map(function (row) {
				return el('li', { text: row.vendor_name + ' — ' + row.reason });
			})));
		}
		if (reason) {
			wrap.appendChild(el('label', { class: 'lnkup-review__field' }, [el('span', { text: 'Message to vendors' }), reason]));
		}
		replace(body, wrap);
		if (!preview.token) {
			confirmBtn.textContent = 'Nothing to change';
			return;
		}
		confirmBtn.disabled = false;
		confirmBtn.addEventListener('click', async function () {
			busy(confirmBtn, true);
			var applied = await api('/applications/bulk/apply', { method: 'POST', body: { token: preview.token, rejection_reason: reason ? reason.value : '' } });
			busy(confirmBtn, false);
			if (!applied.ok) {
				replace(body, errorCard(applied.error));
				return;
			}
			if (applied.data.job_id) {
				replace(body, el('p', { role: 'status', text: 'Updating ' + applied.data.total + ' applications in the background…' }));
				confirmBtn.hidden = true;
				pollJob(applied.data.job_id, body, close);
				return;
			}
			showBulkResult(applied.data, body, confirmBtn);
		});
	}

	async function pollJob(jobId, body, close) {
		var res = await api('/applications/bulk/jobs/' + encodeURIComponent(jobId));
		if (!res.ok) {
			replace(body, errorCard(res.error));
			return;
		}
		if (res.data.status !== 'done') {
			replace(body, el('p', { role: 'status', text: res.data.remaining + ' left to update…' }));
			setTimeout(function () { pollJob(jobId, body, close); }, 3000);
			return;
		}
		showBulkResult(res.data, body, null);
	}

	function showBulkResult(data, body, confirmBtn) {
		var summary = data.summary || { updated: 0, skipped: 0 };
		var failed = (data.results || []).filter(function (row) { return !row.ok; });
		replace(body, el('div', { role: 'status' }, [
			el('p', { text: summary.updated + ' updated' + (summary.skipped ? ', ' + summary.skipped + ' skipped' : '') + '.' }),
			failed.length ? el('ul', { class: 'lnkup-review__preview is-skipped' }, failed.map(function (row) { return el('li', { text: row.vendor_name + ' — ' + row.message }); })) : null
		]));
		if (confirmBtn) {
			confirmBtn.hidden = true;
		}
		state.selected = {};
		loadQueue();
	}

	/* ---------- side panel ---------- */

	function closePanel() {
		panel.hidden = true;
		panel.innerHTML = '';
		state.panelId = 0;
		layout.classList.remove('has-panel');
		var row = listRoot.querySelector('tr.is-open');
		if (row) {
			row.classList.remove('is-open');
		}
	}

	async function openPanel(appId) {
		state.panelId = appId;
		layout.classList.add('has-panel');
		panel.hidden = false;
		replace(panel, loading());
		listRoot.querySelectorAll('tr[data-app-id]').forEach(function (row) {
			row.classList.toggle('is-open', Number(row.getAttribute('data-app-id')) === appId);
		});
		var res = await api('/applications/' + appId + '/workspace');
		if (state.panelId !== appId) {
			return;
		}
		if (!res.ok) {
			replace(panel, el('div', {}, [panelHead('Application'), errorCard(res.error, function () { openPanel(appId); })]));
			return;
		}
		renderPanel(res.data);
		panel.focus();
	}

	function panelHead(title, sub) {
		return el('div', { class: 'lnkup-review__panel-head' }, [
			el('div', {}, [el('h2', { text: title }), sub ? el('p', { class: 'lnkup-muted', text: sub }) : null]),
			el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', 'aria-label': 'Close', text: 'Close', onclick: closePanel })
		]);
	}

	/** Previous / next application within the current filtered list (filters are kept). */
	function panelNav(appId) {
		var items = (state.data && state.data.items) || [];
		var ids = items.map(function (item) { return item.id; });
		var at = ids.indexOf(appId);
		if (at === -1 || ids.length < 2) {
			return null;
		}
		var prevId = at > 0 ? ids[at - 1] : 0;
		var nextId = at < ids.length - 1 ? ids[at + 1] : 0;
		var prev = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: '← Previous', disabled: !prevId, 'data-testid': 'lnkup-review-prev' });
		var next = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Next →', disabled: !nextId, 'data-testid': 'lnkup-review-next' });
		prev.addEventListener('click', function () { if (prevId) { openPanel(prevId); } });
		next.addEventListener('click', function () { if (nextId) { openPanel(nextId); } });
		return el('nav', { class: 'lnkup-review__nav', 'aria-label': 'Move between applications' }, [
			prev,
			el('span', { class: 'lnkup-muted', text: (at + 1) + ' of ' + ids.length }),
			next
		]);
	}

	function section(title, children) {
		return el('section', { class: 'lnkup-review__section' }, [el('h3', { text: title })].concat(children));
	}

	function flash(target, message, isError) {
		var existing = target.querySelector(':scope > .lnkup-review__flash');
		if (existing) {
			existing.remove();
		}
		target.prepend(el('p', { class: 'lnkup-review__flash' + (isError ? ' is-error' : ''), role: isError ? 'alert' : 'status', text: message }));
	}

	function renderPanel(ws) {
		var actions = ws.actions || [];
		var wrap = el('div', { class: 'lnkup-review__panel-body' });
		wrap.appendChild(panelHead(ws.vendor_name || ('Application #' + ws.id), ws.event_title + (ws.opportunity ? ' · ' + ws.opportunity : '')));
		var nav = panelNav(ws.id);
		if (nav) {
			wrap.appendChild(nav);
		}
		wrap.appendChild(el('div', { class: 'lnkup-review__meta' }, [
			badge(ws.status_label, ws.group),
			PAYMENT_LABELS[ws.payment_state] ? badge(PAYMENT_LABELS[ws.payment_state], ws.payment_state === 'paid' ? 'good' : 'warn') : null,
			ws.returning ? badge('Returning vendor', 'good') : null,
			ws.waitlist_position ? badge('Waitlist #' + ws.waitlist_position) : null
		]));
		if (ws.waitlist_offer) {
			wrap.appendChild(el('p', { class: 'lnkup-review__note', text: 'Spot offered by ' + ws.waitlist_offer.offered_by + '. Payment due by ' + ws.waitlist_offer.expires_at + ' UTC.' }));
		}

		wrap.appendChild(decisionBar(ws, actions));

		var profile = ws.profile || {};
		wrap.appendChild(section('Vendor', [
			el('dl', { class: 'lnkup-review__facts' }, [
				fact('Category', ws.category || (Array.isArray(profile.category) ? profile.category.join(', ') : profile.category)),
				fact('Products', profile.products),
				fact('Cities served', Array.isArray(profile.cities_served) ? profile.cities_served.join(', ') : profile.cities_served),
				fact('Setup needs', profile.setup_needs),
				fact('Contact', [profile.contact_email, profile.contact_phone].filter(Boolean).join(' · '))
			]),
			ws.profile_id ? el('a', { href: cfg.dashboard + (cfg.dashboard.indexOf('?') === -1 ? '?' : '&') + 'vendor=' + ws.profile_id, text: 'Open vendor profile' }) : null
		]));

		if (ws.message) {
			wrap.appendChild(section('Message', [el('p', { class: 'lnkup-review__pre', text: ws.message })]));
		}
		var answers = ws.answers || [];
		if (answers.length || Object.keys(answers).length) {
			wrap.appendChild(section('Answers', [answerList(answers)]));
		}
		if (ws.document_review) {
			wrap.appendChild(documentReviewSection(ws));
		} else if (!ws.documents_hidden) {
			var docs = ws.documents || [];
			wrap.appendChild(section('Documents', [docs.length ? el('ul', { class: 'lnkup-review__list-plain' }, docs.map(function (doc) {
				return el('li', {}, [el('span', { text: doc.label || doc.type || 'Document' }), ' ', badge(doc.status || '', doc.status === 'approved' ? 'good' : (doc.status === 'missing' || doc.status === 'expired' ? 'warn' : ''))]);
			})) : el('p', { class: 'lnkup-muted', text: 'No documents required for this slot.' })]));
		}

		if (ws.status === 'waitlisted' && actions.indexOf('assign_reviewer') !== -1 && ws.opportunity_id) {
			wrap.appendChild(waitlistSection(ws));
		}
		wrap.appendChild(reviewersSection(ws, actions));
		if (actions.indexOf('score') !== -1) {
			wrap.appendChild(scoreForm(ws));
		}
		if (actions.indexOf('assign_reviewer') !== -1) {
			wrap.appendChild(rubricSection(ws));
		}
		wrap.appendChild(reviewsSection(ws));

		var past = ws.past_with_you || [];
		wrap.appendChild(section('History with you', [past.length ? el('ul', { class: 'lnkup-review__list-plain' }, past.map(function (row) {
			return el('li', { text: row.event_title + (row.event_date ? ' (' + row.event_date + ')' : '') + ' — ' + row.status_label });
		})) : el('p', { class: 'lnkup-muted', text: 'First application to your events.' })]));

		var info = ws.info_history || [];
		if (info.length) {
			wrap.appendChild(section('Information requests', [el('ul', { class: 'lnkup-review__list-plain' }, info.map(function (entry) {
				return el('li', {}, [el('strong', { text: entry.type === 'request' ? 'You asked: ' : 'Vendor replied: ' }), el('span', { text: entry.message }), el('div', { class: 'lnkup-muted', text: entry.at || '' })]);
			}))]));
		}
		var history = ws.status_history || [];
		if (history.length) {
			wrap.appendChild(section('Status history', [el('ol', { class: 'lnkup-review__list-plain' }, history.slice().reverse().map(function (row) {
				return el('li', { text: (row.label || row.to) + ' · ' + row.at + (row.by ? ' · ' + row.by : '') + (row.reason ? ' (' + row.reason.replace(/_/g, ' ') + ')' : '') });
			}))]));
		}
		replace(panel, wrap);
	}

	var DOC_STATUS = {
		missing: ['Missing', 'warn'],
		requested: ['Requested', 'info'],
		submitted: ['Ready for review', 'info'],
		accepted: ['Accepted', 'good'],
		rejected: ['Rejected', 'warn'],
		replacement_requested: ['Replacement requested', 'warn'],
		expired: ['Expired', 'warn'],
		waived: ['Waived', '']
	};

	/**
	 * Per-event document review (organizer_document_review_enabled). Decisions apply to this event only.
	 */
	function documentReviewSection(ws) {
		var review = ws.document_review || { items: [] };
		var items = review.items || [];
		var sec = section('Documents for this event', []);
		if (!items.length) {
			sec.appendChild(el('p', { class: 'lnkup-muted', text: 'No documents are required for this vendor slot.' }));
			return sec;
		}
		var refresh = function (rows) {
			ws.document_review.items = rows;
			var next = documentReviewSection(ws);
			sec.replaceWith(next);
		};
		var post = async function (path, body, button) {
			busy(button, true);
			var res = await api('/applications/' + ws.id + '/documents' + path, { method: 'POST', body: body });
			busy(button, false);
			if (!res.ok) {
				flash(sec, res.error, true);
				return;
			}
			refresh(res.data.items || []);
			loadQueue();
		};
		var list = el('ul', { class: 'lnkup-review__list-plain lnkup-review__docs' });
		var missing = [];
		items.forEach(function (row) {
			var meta = DOC_STATUS[row.status] || [row.status, ''];
			var li = el('li', { class: 'lnkup-review__doc' }, [
				el('strong', { text: row.label }),
				badge(meta[0], meta[1]),
				row.expires_on ? el('span', { class: 'lnkup-muted', text: 'Expires ' + row.expires_on }) : null,
				row.download_url ? el('a', { href: row.download_url, target: '_blank', rel: 'noopener', text: 'Open shared file' }) : (row.document_id && !row.shared ? el('span', { class: 'lnkup-muted', text: 'Not shared with you yet' }) : null),
				row.reason ? el('div', { class: 'lnkup-muted', text: 'Note: ' + row.reason }) : null
			]);
			if (review.can_review && row.document_id && ['submitted', 'rejected', 'replacement_requested', 'accepted'].indexOf(row.status) !== -1) {
				var accept = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--primary lnkup-btn--sm', text: 'Accept' });
				accept.addEventListener('click', function () { post('/' + row.type + '/review', { decision: 'accept' }, accept); });
				var reject = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Needs changes…' });
				reject.addEventListener('click', function () {
					var reason = el('textarea', { rows: '3', maxlength: '1000', placeholder: 'What should the vendor fix?' });
					var send = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--primary', text: 'Ask for a new version' });
					var close = modal(row.label + ': needs changes', el('label', { class: 'lnkup-review__field' }, [el('span', { text: 'Message to the vendor' }), reason]), function (closeFn) {
						return [el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost', text: 'Cancel', onclick: closeFn }), send];
					});
					send.addEventListener('click', function () {
						if (reason.value.trim().length < 3) {
							reason.focus();
							return;
						}
						close();
						post('/' + row.type + '/review', { decision: 'replacement', reason: reason.value }, null);
					});
				});
				li.appendChild(el('div', { class: 'lnkup-review__inline' }, [row.status !== 'accepted' ? accept : null, reject]));
			}
			if (['missing', 'requested', 'expired', 'rejected', 'replacement_requested'].indexOf(row.status) !== -1) {
				missing.push(row.type);
			}
			list.appendChild(li);
		});
		sec.appendChild(list);
		if (review.can_review && missing.length) {
			var ask = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Request missing documents' });
			ask.addEventListener('click', function () { post('/request', { types: missing }, ask); });
			sec.appendChild(ask);
		}
		return sec;
	}

	function fact(label, value) {
		if (!value) {
			return null;
		}
		return el('div', {}, [el('dt', { text: label }), el('dd', { text: String(value) })]);
	}

	function answerList(answers) {
		var list = el('dl', { class: 'lnkup-review__facts' });
		var rows = Array.isArray(answers) ? answers : Object.keys(answers).map(function (key) { return { label: key, value: answers[key] }; });
		rows.forEach(function (row) {
			var value = row.value !== undefined ? row.value : row.answer;
			if (Array.isArray(value)) {
				value = value.join(', ');
			} else if (value && typeof value === 'object') {
				value = JSON.stringify(value);
			}
			list.appendChild(fact(row.label || row.question || row.key || 'Answer', value === undefined || value === null || value === '' ? '—' : value));
		});
		return list;
	}

	function decisionBar(ws, actions) {
		var bar = el('div', { class: 'lnkup-review__decisions' });
		var run = async function (button, body) {
			busy(button, true);
			var res = await api('/applications/' + ws.id + '/decision', { method: 'POST', body: body });
			busy(button, false);
			if (!res.ok) {
				flash(bar, res.error, true);
				return;
			}
			renderPanel(res.data);
			flash(panel.querySelector('.lnkup-review__panel-body'), 'Saved. ' + (res.data.status_label || ''), false);
			loadQueue();
		};
		if (actions.indexOf('approve') !== -1) {
			var approve = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--primary lnkup-btn--sm', text: 'Approve' });
			approve.addEventListener('click', function () { run(approve, { action: 'approve' }); });
			bar.appendChild(approve);
		}
		if (actions.indexOf('waitlist') !== -1) {
			var wait = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Waitlist' });
			wait.addEventListener('click', function () { run(wait, { action: 'waitlist' }); });
			bar.appendChild(wait);
		}
		if (actions.indexOf('request_info') !== -1) {
			bar.appendChild(el('button', {
				type: 'button',
				class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm',
				text: 'Request info…',
				onclick: function () {
					var message = el('textarea', { rows: '4', maxlength: '2000', required: true, placeholder: 'What do you need from this vendor?' });
					var send = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--primary', text: 'Send request' });
					var close = modal('Request more information', el('label', { class: 'lnkup-review__field' }, [el('span', { text: 'Message' }), message]), function (closeFn) {
						return [el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost', text: 'Cancel', onclick: closeFn }), send];
					});
					send.addEventListener('click', async function () {
						if (message.value.trim().length < 3) {
							message.focus();
							return;
						}
						close();
						await run(null, { action: 'request_info', info_message: message.value });
					});
				}
			}));
		}
		if (actions.indexOf('reject') !== -1) {
			bar.appendChild(el('button', {
				type: 'button',
				class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm',
				text: 'Decline…',
				onclick: function () {
					var reason = el('textarea', { rows: '3', maxlength: '2000', placeholder: 'Optional note shared with the vendor' });
					var confirmBtn = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--primary', text: 'Decline application' });
					var close = modal('Decline ' + (ws.vendor_name || 'this vendor') + '?', el('label', { class: 'lnkup-review__field' }, [el('span', { text: 'Note' }), reason]), function (closeFn) {
						return [el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost', text: 'Cancel', onclick: closeFn }), confirmBtn];
					});
					confirmBtn.addEventListener('click', async function () {
						close();
						await run(null, { action: 'reject', rejection_reason: reason.value });
					});
				}
			}));
		}
		if (actions.indexOf('review') !== -1) {
			var mark = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Mark in review' });
			mark.addEventListener('click', function () { run(mark, { action: 'review' }); });
			bar.appendChild(mark);
		}
		if (cfg.offers && actions.indexOf('offer_spot') !== -1) {
			bar.appendChild(offerControl(ws));
		}
		if (cfg.offers && actions.indexOf('revoke_offer') !== -1) {
			var revoke = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Withdraw offer' });
			revoke.addEventListener('click', async function () {
				if (!window.confirm('Withdraw this offer? The vendor goes back to the waitlist and the payment link stops working.')) {
					return;
				}
				busy(revoke, true);
				var res = await api('/applications/' + ws.id + '/waitlist-offer', { method: 'DELETE' });
				busy(revoke, false);
				if (!res.ok) {
					flash(bar, res.error, true);
					return;
				}
				renderPanel(res.data);
				loadQueue();
			});
			bar.appendChild(revoke);
		}
		if (!bar.childNodes.length) {
			bar.appendChild(el('p', { class: 'lnkup-muted', text: actions.indexOf('score') !== -1 ? 'You can score this application. Decisions are made by the event team.' : 'No decisions are available for this application right now.' }));
		}
		return bar;
	}

	function offerControl(ws) {
		var hours = el('select', { 'aria-label': 'Time to pay' }, [
			el('option', { value: '24', text: '24 hours to pay' }),
			el('option', { value: '48', text: '48 hours to pay', selected: true }),
			el('option', { value: '72', text: '3 days to pay' }),
			el('option', { value: '168', text: '7 days to pay' })
		]);
		var offer = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--primary lnkup-btn--sm', text: 'Offer open spot' });
		var box = el('div', { class: 'lnkup-review__offer' }, [hours, offer]);
		offer.addEventListener('click', async function () {
			if (!window.confirm('Offer an open spot to ' + (ws.vendor_name || 'this vendor') + '? They are notified and must pay before the deadline. Nothing is charged automatically.')) {
				return;
			}
			busy(offer, true);
			var res = await api('/applications/' + ws.id + '/waitlist-offer', { method: 'POST', body: { hours: Number(hours.value) } });
			busy(offer, false);
			if (!res.ok) {
				flash(box.parentNode || box, res.status === 409 ? res.error : ERROR_COPY, true);
				return;
			}
			renderPanel(res.data);
			loadQueue();
		});
		return box;
	}

	/**
	 * Waitlist order for this vendor slot; moving a vendor saves the whole order (POST waitlist/reorder).
	 */
	function waitlistSection(ws) {
		var sec = section('Waitlist order', [el('p', { class: 'lnkup-muted', text: 'Loading the waitlist…' })]);
		var draw = function (items) {
			var ids = items.map(function (row) { return row.id; });
			var index = ids.indexOf(ws.id);
			var list = el('ol', { class: 'lnkup-review__list-plain lnkup-review__waitlist' }, items.map(function (row) {
				return el('li', { class: row.id === ws.id ? 'is-current' : '' }, [
					el('span', { text: (row.vendor_name || ('#' + row.id)) + (row.id === ws.id ? ' (this vendor)' : '') }),
					row.category ? el('span', { class: 'lnkup-muted', text: row.category }) : null
				]);
			}));
			var move = async function (delta, button) {
				var target = index + delta;
				if (index < 0 || target < 0 || target >= ids.length) {
					return;
				}
				var order = ids.slice();
				order.splice(index, 1);
				order.splice(target, 0, ws.id);
				busy(button, true);
				var res = await api('/opportunities/' + ws.opportunity_id + '/waitlist/reorder', { method: 'POST', body: { order: order } });
				busy(button, false);
				if (!res.ok) {
					flash(sec, res.error, true);
					return;
				}
				ws.waitlist_position = order.indexOf(ws.id) + 1;
				draw(res.data.items || []);
				loadQueue();
			};
			var up = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Move up', disabled: index <= 0 });
			var down = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Move down', disabled: index < 0 || index >= ids.length - 1 });
			up.addEventListener('click', function () { move(-1, up); });
			down.addEventListener('click', function () { move(1, down); });
			var body = el('div', {}, [
				el('p', { class: 'lnkup-muted', text: index >= 0 ? 'Position ' + (index + 1) + ' of ' + ids.length + '. Offer open spots in this order.' : 'This vendor is not on the waitlist.' }),
				list,
				el('div', { class: 'lnkup-review__inline' }, [up, down])
			]);
			while (sec.childNodes.length > 1) {
				sec.removeChild(sec.lastChild);
			}
			sec.appendChild(body);
		};
		api('/opportunities/' + ws.opportunity_id + '/waitlist').then(function (res) {
			if (!res.ok) {
				while (sec.childNodes.length > 1) {
					sec.removeChild(sec.lastChild);
				}
				sec.appendChild(el('p', { class: 'lnkup-muted', text: res.error }));
				return;
			}
			draw(res.data.items || []);
		});
		return sec;
	}

	/**
	 * Event scoring rubric (shown to people who can decide applications). Saving bumps the rubric
	 * version; scores already submitted keep the version they were given against.
	 */
	function rubricSection(ws) {
		var rubric = ws.rubric || { criteria: [] };
		var summary = rubric.criteria.map(function (c) { return c.label + (c.weight > 1 ? ' ×' + c.weight : ''); }).join(' · ');
		var edit = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: rubric.is_default ? 'Customize rubric' : 'Edit rubric' });
		edit.addEventListener('click', function () {
			var rows = el('div', { class: 'lnkup-review__rubric-rows' });
			var addRow = function (criterion) {
				var label = el('input', { type: 'text', maxlength: '80', value: criterion ? criterion.label : '', 'aria-label': 'Criterion' });
				var weight = el('select', { 'aria-label': 'Weight' }, [1, 2, 3, 4, 5].map(function (n) {
					return el('option', { value: String(n), text: n === 1 ? 'Weight 1' : 'Weight ' + n, selected: criterion && Number(criterion.weight) === n });
				}));
				var remove = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Remove' });
				var row = el('div', { class: 'lnkup-review__rubric-row', 'data-key': criterion ? criterion.key : '' }, [label, weight, remove]);
				remove.addEventListener('click', function () { row.remove(); });
				rows.appendChild(row);
			};
			rubric.criteria.forEach(addRow);
			var add = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Add criterion', onclick: function () { addRow(null); } });
			var save = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--primary', text: 'Save rubric' });
			var body = el('div', {}, [
				el('p', { class: 'lnkup-muted', text: 'Reviewers score each criterion from 1 to 5. Higher weights count more in the average. Scores guide decisions; they never approve or decline on their own.' }),
				rows,
				add
			]);
			var close = modal('Scoring rubric for ' + (ws.event_title || 'this event'), body, function (closeFn) {
				return [el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost', text: 'Cancel', onclick: closeFn }), save];
			});
			save.addEventListener('click', async function () {
				var criteria = Array.prototype.map.call(rows.querySelectorAll('.lnkup-review__rubric-row'), function (row) {
					return { key: row.getAttribute('data-key') || '', label: row.querySelector('input').value.trim(), weight: Number(row.querySelector('select').value) };
				}).filter(function (c) { return c.label; });
				busy(save, true);
				var res = await api('/events/' + ws.event_id + '/rubric', { method: 'PUT', body: { criteria: criteria } });
				busy(save, false);
				if (!res.ok) {
					flash(body, res.error, true);
					return;
				}
				close();
				openPanel(ws.id);
			});
		});
		return section('Scoring rubric', [
			el('p', { class: 'lnkup-muted', text: (rubric.is_default ? 'Default: ' : 'This event: ') + summary }),
			edit
		]);
	}

	function reviewersSection(ws, actions) {
		var children = [];
		var reviewers = ws.reviewers || [];
		children.push(reviewers.length ? el('ul', { class: 'lnkup-review__list-plain' }, reviewers.map(function (person) {
			var li = el('li', {}, [el('span', { text: person.name })]);
			if (actions.indexOf('assign_reviewer') !== -1) {
				var remove = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Remove', 'aria-label': 'Remove ' + person.name });
				remove.addEventListener('click', async function () {
					busy(remove, true);
					var res = await api('/applications/' + ws.id + '/reviewers/' + person.id, { method: 'DELETE' });
					busy(remove, false);
					if (res.ok) {
						renderPanel(res.data);
						loadQueue();
					}
				});
				li.appendChild(remove);
			}
			return li;
		})) : el('p', { class: 'lnkup-muted', text: 'No reviewers assigned.' }));
		var sec = section('Reviewers', children);
		if (actions.indexOf('assign_reviewer') !== -1) {
			var select = el('select', { 'aria-label': 'Add reviewer' }, [el('option', { value: '', text: 'Add a reviewer…' })]);
			var add = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Assign', disabled: true });
			select.addEventListener('change', function () { add.disabled = !select.value; });
			add.addEventListener('click', async function () {
				busy(add, true);
				var res = await api('/applications/' + ws.id + '/reviewers', { method: 'POST', body: { user_id: Number(select.value) } });
				busy(add, false);
				if (!res.ok) {
					flash(sec, res.error, true);
					return;
				}
				renderPanel(res.data);
				loadQueue();
			});
			sec.appendChild(el('div', { class: 'lnkup-review__inline' }, [select, add]));
			api('/events/' + ws.event_id + '/reviewers').then(function (res) {
				if (!res.ok) {
					return;
				}
				var assigned = (ws.reviewers || []).map(function (p) { return p.id; });
				(res.data.items || []).forEach(function (person) {
					if (assigned.indexOf(person.id) === -1) {
						select.appendChild(el('option', { value: String(person.id), text: person.name }));
					}
				});
				if (select.options.length < 2) {
					select.appendChild(el('option', { value: '', text: 'Invite reviewers from Team & Trust', disabled: true }));
				}
			});
		}
		return sec;
	}

	function scoreForm(ws) {
		var rubric = ws.rubric || { criteria: [] };
		var mine = ws.my_review || { scores: {}, recommendation: '', comment: '', complete: false };
		var form = el('form', { class: 'lnkup-review__score' });
		var inputs = {};
		rubric.criteria.forEach(function (criterion) {
			var group = el('fieldset', { class: 'lnkup-review__criterion' }, [el('legend', { text: criterion.label + (criterion.weight > 1 ? ' (×' + criterion.weight + ')' : '') })]);
			var row = el('div', { class: 'lnkup-review__scale' });
			for (var i = 1; i <= 5; i++) {
				var id = 'lnkup-score-' + ws.id + '-' + criterion.key + '-' + i;
				var radio = el('input', { type: 'radio', name: criterion.key, value: String(i), id: id });
				if (Number(mine.scores[criterion.key]) === i) {
					radio.checked = true;
				}
				row.appendChild(el('label', { for: id, class: 'lnkup-review__scale-option' }, [radio, el('span', { text: String(i) })]));
			}
			inputs[criterion.key] = group;
			group.appendChild(row);
			form.appendChild(group);
		});
		var rec = el('select', {}, [el('option', { value: '', text: 'Recommendation (optional)' })].concat(Object.keys(REC_LABELS).map(function (key) {
			return el('option', { value: key, text: REC_LABELS[key], selected: mine.recommendation === key });
		})));
		var comment = el('textarea', { rows: '3', maxlength: '4000', placeholder: 'Private note for your team' });
		comment.value = mine.comment || '';
		var save = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Save draft' });
		var submit = el('button', { type: 'submit', class: 'lnkup-btn lnkup-btn--primary lnkup-btn--sm', text: mine.complete ? 'Update review' : 'Submit review' });
		form.appendChild(el('label', { class: 'lnkup-review__field' }, [el('span', { text: 'Recommendation' }), rec]));
		form.appendChild(el('label', { class: 'lnkup-review__field' }, [el('span', { text: 'Comment' }), comment]));
		form.appendChild(el('p', { class: 'lnkup-muted', text: 'Scores guide your team. They never approve or decline an application on their own.' }));
		form.appendChild(el('div', { class: 'lnkup-review__inline' }, [save, submit]));
		var collect = function () {
			var scores = {};
			rubric.criteria.forEach(function (criterion) {
				var checked = form.querySelector('input[name="' + criterion.key + '"]:checked');
				if (checked) {
					scores[criterion.key] = Number(checked.value);
				}
			});
			return scores;
		};
		var send = async function (complete, button) {
			busy(button, true);
			var res = await api('/applications/' + ws.id + '/score', { method: 'POST', body: { scores: collect(), recommendation: rec.value, comment: comment.value, complete: complete } });
			busy(button, false);
			if (!res.ok) {
				flash(form, res.error, true);
				return;
			}
			if (!complete) {
				flash(form, 'Draft saved.', false);
				return;
			}
			await openPanel(ws.id);
			var body = panel.querySelector('.lnkup-review__panel-body');
			if (body) {
				flash(body, 'Review submitted.', false);
			}
			loadQueue();
		};
		save.addEventListener('click', function () { send(false, save); });
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			send(true, submit);
		});
		return section(rubric.is_default ? 'Your score' : 'Your score (event rubric)', [form]);
	}

	function reviewsSection(ws) {
		var reviews = ws.reviews || [];
		var summary = ws.review || {};
		if (!reviews.length) {
			if (summary.completed && !summary.my_complete) {
				return section('Team reviews', [el('p', { class: 'lnkup-muted', text: summary.completed + ' submitted. Submit your own score to see theirs.' })]);
			}
			return section('Team reviews', [el('p', { class: 'lnkup-muted', text: 'No completed reviews yet.' })]);
		}
		var head = summary.average !== null && summary.average !== undefined ? el('p', {}, [el('strong', { text: 'Average ' + summary.average.toFixed(1) + ' / 5' }), el('span', { class: 'lnkup-muted', text: ' from ' + reviews.length + (reviews.length === 1 ? ' review' : ' reviews') })]) : null;
		return section('Team reviews', [head, el('ul', { class: 'lnkup-review__list-plain' }, reviews.map(function (review) {
			return el('li', {}, [
				el('strong', { text: review.reviewer.name }),
				' ',
				el('span', { text: (review.weighted_score !== null ? review.weighted_score.toFixed(1) + ' / 5' : '—') + (review.recommendation ? ' · ' + (REC_LABELS[review.recommendation] || review.recommendation) : '') }),
				review.comment ? el('div', { class: 'lnkup-muted', text: review.comment }) : null
			]);
		}))]);
	}

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && !panel.hidden && !document.querySelector('.lnkup-review__modal')) {
			closePanel();
		}
	});

	loadQueue();
	var openId = parseInt(params.get('app') || '0', 10);
	if (openId) {
		openPanel(openId);
	}
})();
