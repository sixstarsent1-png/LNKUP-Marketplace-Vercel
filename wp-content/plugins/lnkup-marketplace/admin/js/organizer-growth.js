/**
 * Organizer Growth admin app (wp-admin → LNKup → Organizer Growth).
 *
 * DOM is built with createElement/textContent only — lead data is never injected as HTML.
 */
(function () {
	'use strict';

	const config = window.lnkupGrowth || {};
	const app = document.getElementById('lnkup-og-app');
	if (!app || !config.root) {
		return;
	}

	const state = {
		boot: null,
		leadFilters: { page: 1, per_page: 25, orderby: 'score', order: 'desc' },
		selected: new Set(),
		views: [],
		currentImport: null,
	};

	// ------------------------------------------------------------------ Helpers.

	async function api(path, options = {}) {
		const opts = { method: 'GET', credentials: 'same-origin', ...options };
		opts.headers = { 'X-WP-Nonce': config.nonce, ...(options.headers || {}) };
		if (opts.body && !(opts.body instanceof FormData)) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(opts.body);
		}
		try {
			const response = await fetch(config.root + path, opts);
			const type = response.headers.get('content-type') || '';
			if (!type.includes('application/json')) {
				console.error('LNKup growth: expected JSON from', path, await response.text());
				return { ok: false, error: 'The server returned an unexpected response.' };
			}
			const json = await response.json();
			if (!response.ok) {
				return { ok: false, error: json.message || 'Request failed.', code: json.code, data: json.data };
			}
			return { ok: true, data: json };
		} catch (error) {
			console.error('LNKup growth API error', path, error);
			return { ok: false, error: 'Could not reach the server. Check your connection and try again.' };
		}
	}

	function qs(params) {
		const out = new URLSearchParams();
		Object.entries(params).forEach(([k, v]) => {
			if (v !== undefined && v !== null && v !== '' && v !== false) {
				out.set(k, v === true ? '1' : String(v));
			}
		});
		const s = out.toString();
		return s ? '?' + s : '';
	}

	function h(tag, attrs = {}, ...children) {
		const el = document.createElement(tag);
		Object.entries(attrs || {}).forEach(([key, value]) => {
			if (value === undefined || value === null || value === false) {
				return;
			}
			if (key === 'class') {
				el.className = value;
			} else if (key === 'text') {
				el.textContent = value;
			} else if (key.startsWith('on') && typeof value === 'function') {
				el.addEventListener(key.slice(2).toLowerCase(), value);
			} else if (key === 'dataset') {
				Object.assign(el.dataset, value);
			} else if (value === true) {
				el.setAttribute(key, '');
			} else {
				el.setAttribute(key, String(value));
			}
		});
		children.flat().forEach((child) => {
			if (child === null || child === undefined || child === false) {
				return;
			}
			el.appendChild(child instanceof Node ? child : document.createTextNode(String(child)));
		});
		return el;
	}

	function clear(el) {
		while (el.firstChild) {
			el.removeChild(el.firstChild);
		}
		return el;
	}

	function panel(name) {
		return app.querySelector('[data-og-panel="' + name + '"]');
	}

	function loading(el) {
		clear(el).appendChild(h('div', { class: 'lnkup-og-loading', 'aria-live': 'polite', text: 'Loading…' }));
	}

	function errorCard(el, message, retry) {
		clear(el).appendChild(
			h('div', { class: 'lnkup-og-error', role: 'alert' },
				h('p', { text: message || "We couldn't load this section. Try refreshing or contact support." }),
				retry ? h('button', { class: 'button', type: 'button', onclick: retry, text: 'Try again' }) : null
			)
		);
	}

	function empty(message, action) {
		return h('div', { class: 'lnkup-og-empty' }, h('p', { text: message }), action || null);
	}

	function toast(message, isError) {
		const el = app.querySelector('[data-og-toast]');
		el.textContent = message;
		el.classList.toggle('is-error', !!isError);
		el.hidden = false;
		clearTimeout(toast.timer);
		toast.timer = setTimeout(() => { el.hidden = true; }, 4500);
	}

	function money(n) {
		return '$' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	function label(key) {
		return String(key || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
	}

	function fmtDate(value) {
		if (!value) {
			return '—';
		}
		return String(value).slice(0, 16).replace('T', ' ');
	}

	function select(name, options, value, attrs = {}) {
		const el = h('select', { name, ...attrs });
		options.forEach((opt) => {
			const o = h('option', { value: opt.value, text: opt.label });
			if (String(opt.value) === String(value ?? '')) {
				o.selected = true;
			}
			el.appendChild(o);
		});
		return el;
	}

	function field(labelText, input, hint) {
		const id = 'og-f-' + Math.random().toString(36).slice(2, 9);
		input.id = id;
		return h('div', { class: 'lnkup-og-field' }, h('label', { for: id, text: labelText }), input, hint ? h('small', { text: hint }) : null);
	}

	function stageOptions(includeMilestones, blank) {
		const list = (state.boot.stages || []).filter((s) => includeMilestones || !s.milestone_only).map((s) => ({ value: s.key, label: s.label }));
		return blank ? [{ value: '', label: blank }].concat(list) : list;
	}

	function teamOptions(blank) {
		return [{ value: '', label: blank }].concat((state.boot.team || []).map((u) => ({ value: u.id, label: u.name })));
	}

	function connectorOptions(blank) {
		return [{ value: '', label: blank }].concat((state.boot.connectors || []).map((c) => ({ value: c.id, label: c.name + (c.city ? ' — ' + c.city : '') })));
	}

	function downloadCsv(filename, csv) {
		const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
		const url = URL.createObjectURL(blob);
		const a = h('a', { href: url, download: filename });
		document.body.appendChild(a);
		a.click();
		a.remove();
		setTimeout(() => URL.revokeObjectURL(url), 1000);
	}

	// ------------------------------------------------------------------ Tabs.

	const renderers = {
		overview: renderOverview,
		leads: renderLeads,
		pipeline: renderPipeline,
		tasks: renderTasks,
		outreach: renderOutreach,
		import: renderImport,
		settings: renderSettings,
	};

	function activate(tab) {
		if (!renderers[tab]) {
			tab = 'overview';
		}
		if (!state.boot.enabled && tab !== 'settings') {
			tab = 'settings';
		}
		app.querySelectorAll('[data-og-tab]').forEach((a) => {
			const on = a.dataset.ogTab === tab;
			a.classList.toggle('nav-tab-active', on);
			a.setAttribute('aria-selected', on ? 'true' : 'false');
		});
		app.querySelectorAll('[data-og-panel]').forEach((p) => {
			p.hidden = p.dataset.ogPanel !== tab;
		});
		renderers[tab](panel(tab));
	}

	async function boot() {
		const res = await api('/bootstrap');
		if (!res.ok) {
			app.querySelectorAll('[data-og-panel]').forEach((p) => { p.hidden = true; });
			const p = panel('overview');
			p.hidden = false;
			errorCard(p, res.error, boot);
			return;
		}
		state.boot = res.data;
		const flag = app.querySelector('[data-og-flag]');
		flag.hidden = state.boot.enabled;
		clear(flag).appendChild(h('p', { text: 'Organizer Growth is turned off. Enable it in Settings to start working leads.' }));
		window.addEventListener('hashchange', () => activate(location.hash.slice(1)));
		activate(location.hash.slice(1) || 'overview');
	}

	// ------------------------------------------------------------------ Overview.

	async function renderOverview(el, affiliation = '') {
		loading(el);
		const res = await api('/overview' + qs({ affiliation }));
		if (!res.ok) {
			errorCard(el, res.error, () => renderOverview(el, affiliation));
			return;
		}
		const d = res.data;
		const k = d.kpis;
		clear(el);
		el.appendChild(
			h('div', { class: 'lnkup-og-toolbar' },
				field('Organizers', select('affiliation', [
					{ value: '', label: 'All organizers' },
					{ value: 'independent', label: 'Independent only' },
					{ value: 'house', label: 'Six Stars / house-affiliated only' },
				], affiliation, { onchange: (e) => renderOverview(el, e.target.value) }))
			)
		);
		if (!k.total_prospects) {
			el.appendChild(empty('No organizer leads yet. Import your research spreadsheet or add a lead to get started.', h('a', { class: 'button button-primary', href: '#import', text: 'Import leads' })));
			return;
		}
		const cards = [
			['Total prospects', k.total_prospects], ['Qualified', k.qualified], ['Contacted', k.contacted], ['Replies', k.replies],
			['Meetings', k.meetings], ['Registered', k.registrations], ['Onboarding', k.onboarding], ['Published first event', k.published],
			['First paid booking', k.first_paid_booking], ['Activated', k.activated], ['Active (90 days)', k.active_organizers], ['Repeat organizers', k.repeat_organizers],
			['Referral-attributed', k.referral_attributed], ['Net platform fees', money(k.net_platform_fees)], ['Gross bookings', money(k.gross_bookings)],
		];
		el.appendChild(h('div', { class: 'lnkup-og-kpis', 'data-testid': 'og-kpis' }, cards.map(([l, v]) => h('div', { class: 'lnkup-og-kpi' }, h('strong', { text: String(v) }), h('span', { text: l })))));
		el.appendChild(h('p', { class: 'description' }, Object.values(d.definitions || {}).join(' ')));

		if ((d.onboarding_by_stage || []).length) {
			el.appendChild(h('h2', { text: 'Registered organizers — where setup is stuck' }));
			el.appendChild(table(['Setup stage', 'Organizations'], d.onboarding_by_stage.map((s) => [s.label, s.count]), { 'data-testid': 'og-onboarding-stages' }));
		}

		el.appendChild(h('h2', { text: 'Acquisition funnel' }));
		el.appendChild(table(['Step', 'Organizations', 'From previous step'], d.funnel.map((s) => [s.label, s.count, s.rate_from_previous === null ? '—' : s.rate_from_previous + '%'])));

		el.appendChild(h('h2', { text: 'By acquisition source' }));
		el.appendChild(d.sources.length ? table(['Source', 'Leads', 'Registered', 'Paid booking', 'Net platform fees', 'Gross bookings'], d.sources.map((s) => [label(s.source), s.leads, s.registered, s.paid, money(s.net_platform_fees), money(s.gross_bookings)])) : empty('No sources recorded yet.'));

		el.appendChild(h('h2', { text: 'Weekly trend (last 12 weeks)' }));
		el.appendChild(table(['Week of', 'New leads', 'Contacted', 'Registered', 'Published', 'Paid booking'], d.trends.map((t) => [t.week, t.leads, t.contacted, t.registered, t.published, t.paid])));

		el.appendChild(h('h2', { text: 'Not available yet' }));
		el.appendChild(h('ul', { class: 'lnkup-og-unavailable' }, Object.entries(d.unavailable || {}).map(([key, why]) => h('li', {}, h('strong', { text: label(key) + ': ' }), why))));
	}

	function table(headers, rows, attrs = {}) {
		return h('table', { class: 'widefat striped lnkup-og-table', ...attrs },
			h('thead', {}, h('tr', {}, headers.map((x) => h('th', { scope: 'col', text: x })))),
			h('tbody', {}, rows.map((r) => h('tr', {}, r.map((c) => h('td', {}, c instanceof Node ? c : String(c ?? '—'))))))
		);
	}

	// ------------------------------------------------------------------ Leads.

	async function renderLeads(el) {
		clear(el);
		const filterBar = h('form', { class: 'lnkup-og-filters', 'data-testid': 'og-lead-filters', onsubmit: (e) => { e.preventDefault(); readFilters(filterBar); state.leadFilters.page = 1; loadLeads(results); } });
		const f = state.leadFilters;
		filterBar.append(
			field('Search', h('input', { type: 'search', name: 'search', value: f.search || '', placeholder: 'Name, email, city, domain' })),
			field('Stage', select('stage', stageOptions(true, 'Any stage'), f.stage)),
			field('Outcome', select('outcome', [{ value: '', label: 'Open leads' }, { value: 'any', label: 'All' }].concat(state.boot.outcomes.map((o) => ({ value: o.key, label: o.label }))), f.outcome)),
			field('Tier', select('tier', [{ value: '', label: 'Any' }, { value: 'A', label: 'A' }, { value: 'B', label: 'B' }, { value: 'C', label: 'C' }], f.tier)),
			field('State', h('input', { type: 'text', name: 'state', value: f.state || '', size: 4 })),
			field('Owner', select('assigned_user_id', teamOptions('Anyone'), f.assigned_user_id)),
			field('Organizers', select('affiliation', [{ value: '', label: 'All' }, { value: 'independent', label: 'Independent' }, { value: 'house', label: 'House-affiliated' }], f.affiliation)),
			field('Tag', select('tag', [{ value: '', label: 'Any' }].concat((state.boot.tags || []).map((t) => ({ value: t.tag, label: t.tag + ' (' + t.count + ')' }))), f.tag)),
			h('label', { class: 'lnkup-og-check' }, h('input', { type: 'checkbox', name: 'follow_up_due', checked: !!f.follow_up_due }), ' Follow-up due'),
			h('label', { class: 'lnkup-og-check' }, h('input', { type: 'checkbox', name: 'possible_duplicates', checked: !!f.possible_duplicates }), ' Possible duplicates'),
			h('button', { class: 'button', type: 'submit', text: 'Apply filters' })
		);
		const viewsSelect = h('select', { 'aria-label': 'Saved views', onchange: (e) => applyView(e.target.value, filterBar, results) });
		const toolbar = h('div', { class: 'lnkup-og-toolbar' },
			h('button', { class: 'button button-primary', type: 'button', onclick: () => openLeadForm(), text: 'Add lead' }),
			viewsSelect,
			h('button', { class: 'button', type: 'button', onclick: () => saveView(filterBar, viewsSelect), text: 'Save view' }),
			h('button', { class: 'button', type: 'button', onclick: exportLeads, text: 'Export CSV' })
		);
		const results = h('div', { class: 'lnkup-og-results', 'data-testid': 'og-lead-results' });
		el.append(toolbar, filterBar, results);
		loadViews(viewsSelect);
		loadLeads(results);
	}

	function readFilters(form) {
		const data = new FormData(form);
		['search', 'stage', 'outcome', 'tier', 'state', 'assigned_user_id', 'affiliation', 'tag'].forEach((k) => { state.leadFilters[k] = data.get(k) || ''; });
		state.leadFilters.follow_up_due = data.get('follow_up_due') ? 1 : '';
		state.leadFilters.possible_duplicates = data.get('possible_duplicates') ? 1 : '';
	}

	async function loadViews(selectEl) {
		const res = await api('/views');
		clear(selectEl).appendChild(h('option', { value: '', text: 'Saved views…' }));
		if (!res.ok) {
			return;
		}
		state.views = res.data.items || [];
		state.views.forEach((v) => selectEl.appendChild(h('option', { value: v.id, text: v.name + (v.is_shared ? ' (shared)' : '') })));
	}

	function applyView(id, form, results) {
		const view = state.views.find((v) => String(v.id) === String(id));
		if (!view) {
			return;
		}
		state.leadFilters = { page: 1, per_page: 25, orderby: 'score', order: 'desc', ...view.filters };
		renderLeads(panel('leads'));
	}

	async function saveView(form, selectEl) {
		readFilters(form);
		const name = window.prompt('Name this view');
		if (!name) {
			return;
		}
		const filters = { ...state.leadFilters };
		delete filters.page;
		const res = await api('/views', { method: 'POST', body: { name, filters, is_shared: window.confirm('Share this view with the team?') } });
		if (!res.ok) {
			toast(res.error, true);
			return;
		}
		toast('View saved.');
		loadViews(selectEl);
	}

	async function exportLeads() {
		const filters = { ...state.leadFilters };
		delete filters.page;
		delete filters.per_page;
		const res = await api('/leads/export' + qs(filters));
		if (!res.ok) {
			toast(res.error, true);
			return;
		}
		downloadCsv(res.data.filename, res.data.csv);
	}

	async function loadLeads(results) {
		loading(results);
		const res = await api('/leads' + qs(state.leadFilters));
		if (!res.ok) {
			errorCard(results, res.error, () => loadLeads(results));
			return;
		}
		const d = res.data;
		clear(results);
		state.selected.clear();
		if (!d.items.length) {
			results.appendChild(empty('No leads match these filters.'));
			return;
		}
		const bulk = bulkBar(results);
		const headCheck = h('input', { type: 'checkbox', 'aria-label': 'Select all on this page', onchange: (e) => {
			results.querySelectorAll('input[data-lead-check]').forEach((c) => { c.checked = e.target.checked; toggleSelect(c.value, c.checked, bulk); });
		} });
		const sortBtn = (key, text) => h('button', { type: 'button', class: 'button-link', onclick: () => {
			state.leadFilters.order = state.leadFilters.orderby === key && state.leadFilters.order === 'desc' ? 'asc' : 'desc';
			state.leadFilters.orderby = key;
			loadLeads(results);
		}, text: text + (state.leadFilters.orderby === key ? (state.leadFilters.order === 'asc' ? ' ↑' : ' ↓') : '') });
		const rows = d.items.map((lead) => h('tr', { 'data-lead-row': lead.id },
			h('td', {}, h('input', { type: 'checkbox', value: lead.id, 'data-lead-check': '1', 'aria-label': 'Select ' + lead.name, onchange: (e) => toggleSelect(lead.id, e.target.checked, bulk) })),
			h('td', {}, h('button', { type: 'button', class: 'button-link lnkup-og-lead-link', onclick: () => openLead(lead.id), text: lead.name }), lead.possible_duplicate_of ? h('span', { class: 'lnkup-og-badge is-warn', text: 'Possible duplicate' }) : null, lead.is_house_affiliated ? h('span', { class: 'lnkup-og-badge', text: 'House' }) : null, lead.is_suppressed ? h('span', { class: 'lnkup-og-badge is-muted', text: 'Do not email' }) : null),
			h('td', { text: [lead.city, lead.state].filter(Boolean).join(', ') || '—' }),
			h('td', { text: lead.category || '—' }),
			h('td', {}, h('span', { class: 'lnkup-og-tier tier-' + (lead.tier || 'C'), text: lead.tier || '—' }), ' ', String(lead.score)),
			h('td', { text: lead.outcome_label ? lead.stage_label + ' · ' + lead.outcome_label : lead.stage_label }),
			h('td', { text: lead.assigned_user_name || '—' }),
			h('td', { text: fmtDate(lead.next_follow_up_at) }),
			h('td', { text: fmtDate(lead.last_contacted_at) })
		));
		results.appendChild(h('table', { class: 'widefat striped lnkup-og-table', 'data-testid': 'og-leads-table' },
			h('thead', {}, h('tr', {}, h('th', {}, headCheck), h('th', {}, sortBtn('name', 'Organizer')), h('th', {}, sortBtn('city', 'City')), h('th', { text: 'Category' }), h('th', {}, sortBtn('score', 'Score')), h('th', { text: 'Stage' }), h('th', { text: 'Owner' }), h('th', {}, sortBtn('next_follow_up_at', 'Next follow-up')), h('th', {}, sortBtn('last_contacted_at', 'Last contact')))),
			h('tbody', {}, rows)
		));
		results.appendChild(h('div', { class: 'lnkup-og-pager' },
			h('span', { text: d.total + ' leads · page ' + d.page + ' of ' + Math.max(1, d.pages) }),
			h('button', { class: 'button', type: 'button', disabled: d.page <= 1, onclick: () => { state.leadFilters.page = d.page - 1; loadLeads(results); }, text: 'Previous' }),
			h('button', { class: 'button', type: 'button', disabled: d.page >= d.pages, onclick: () => { state.leadFilters.page = d.page + 1; loadLeads(results); }, text: 'Next' })
		));
	}

	function toggleSelect(id, on, bulk) {
		if (on) {
			state.selected.add(Number(id));
		} else {
			state.selected.delete(Number(id));
		}
		bulk.hidden = state.selected.size === 0;
		bulk.querySelector('[data-count]').textContent = state.selected.size + ' selected';
	}

	function bulkBar(results) {
		const valueWrap = h('span');
		const action = select('action', [
			{ value: 'assign_user', label: 'Assign owner' },
			{ value: 'assign_connector', label: 'Assign City Connector' },
			{ value: 'stage', label: 'Move to stage' },
			{ value: 'outcome', label: 'Set outcome' },
			{ value: 'priority', label: 'Set priority' },
			{ value: 'add_tag', label: 'Add tag' },
			{ value: 'remove_tag', label: 'Remove tag' },
		], 'assign_user', { 'aria-label': 'Bulk action', onchange: () => renderValue() });
		function renderValue() {
			clear(valueWrap);
			const a = action.value;
			let input;
			if (a === 'assign_user') {
				input = select('value', teamOptions('Nobody'), '');
			} else if (a === 'assign_connector') {
				input = select('value', connectorOptions('No connector'), '');
			} else if (a === 'stage') {
				input = select('value', stageOptions(false), 'qualified');
			} else if (a === 'outcome') {
				input = select('value', [{ value: 'clear', label: 'Reopen (clear outcome)' }].concat(state.boot.outcomes.map((o) => ({ value: o.key, label: o.label }))), '');
			} else if (a === 'priority') {
				input = select('value', [{ value: 'A', label: 'A' }, { value: 'B', label: 'B' }, { value: 'C', label: 'C' }], 'A');
			} else {
				input = h('input', { type: 'text', name: 'value', placeholder: 'tag-name' });
			}
			input.setAttribute('aria-label', 'Bulk value');
			valueWrap.appendChild(input);
		}
		renderValue();
		const bar = h('div', { class: 'lnkup-og-bulk', hidden: true, 'data-testid': 'og-bulk-bar' },
			h('strong', { 'data-count': '1', text: '0 selected' }), action, valueWrap,
			h('button', { class: 'button button-primary', type: 'button', text: 'Apply to selected', onclick: async () => {
				const value = valueWrap.querySelector('[name=value]').value;
				const res = await api('/leads/bulk', { method: 'POST', body: { ids: Array.from(state.selected), action: action.value, value } });
				if (!res.ok) {
					toast(res.error, true);
					return;
				}
				const errs = Object.values(res.data.errors || {});
				toast(res.data.updated + ' updated' + (errs.length ? ' · ' + errs.length + ' skipped: ' + errs[0] : ''), errs.length > 0);
				loadLeads(results);
			} })
		);
		results.appendChild(bar);
		return bar;
	}

	// ------------------------------------------------------------------ Lead drawer.

	function drawer() {
		return app.querySelector('[data-og-drawer]');
	}

	function closeDrawer() {
		drawer().hidden = true;
		clear(drawer());
	}

	function drawerShell(title) {
		const d = clear(drawer());
		d.hidden = false;
		const body = h('div', { class: 'lnkup-og-drawer-body' });
		d.append(h('header', { class: 'lnkup-og-drawer-head' }, h('h2', { text: title }), h('button', { type: 'button', class: 'button-link', 'aria-label': 'Close', onclick: closeDrawer, text: '✕' })), body);
		return body;
	}

	const LEAD_FIELDS = [
		['name', 'Organizer or business name'], ['org_type', 'Organization type'], ['category', 'Category'], ['website', 'Website'], ['email', 'Public business email'],
		['phone', 'Public business phone'], ['instagram', 'Instagram'], ['facebook', 'Facebook'], ['city', 'City'], ['state', 'State'], ['service_area', 'Service area'],
		['event_frequency', 'Event frequency'], ['cities_served', 'Cities served (comma separated)'], ['event_categories', 'Event categories'], ['estimated_booths', 'Estimated vendor booths (sourced)'],
		['application_process', 'Known vendor application process'], ['next_event_date', 'Next confirmed event date'], ['application_url', 'Official vendor application URL'],
		['source_verified_at', 'Source verified on'], ['source', 'Lead source'], ['source_campaign', 'Source campaign'], ['referral_code', 'Referral code'],
		['assigned_user_id', 'Owner'], ['assigned_connector_id', 'City Connector'], ['priority', 'Priority'], ['contact_preference', 'Contact preference'],
		['parent_lead_id', 'Parent organization (lead ID)'], ['description', 'Company description'], ['qualification_notes', 'Qualification notes'],
	];

	function leadInput(key, value) {
		switch (key) {
			case 'org_type':
				return select(key, state.boot.org_types.map((t) => ({ value: t, label: label(t) })), value || 'organizer_org');
			case 'event_frequency':
				return select(key, [{ value: '', label: 'Unknown' }].concat(state.boot.frequencies.map((t) => ({ value: t, label: label(t) }))), value);
			case 'assigned_user_id':
				return select(key, teamOptions('Unassigned'), value || '');
			case 'assigned_connector_id':
				return select(key, connectorOptions('None'), value || '');
			case 'priority':
				return select(key, [{ value: '', label: 'Auto (from score)' }, { value: 'A', label: 'A' }, { value: 'B', label: 'B' }, { value: 'C', label: 'C' }], value);
			case 'contact_preference':
				return select(key, [{ value: '', label: 'Unknown' }, { value: 'email', label: 'Email' }, { value: 'phone', label: 'Phone' }, { value: 'instagram', label: 'Instagram DM' }, { value: 'facebook', label: 'Facebook message' }, { value: 'contact_form', label: 'Website contact form' }], value);
			case 'next_event_date':
				return h('input', { type: 'date', name: key, value: value || '' });
			case 'source_verified_at':
				return h('input', { type: 'date', name: key, value: value ? String(value).slice(0, 10) : '' });
			case 'estimated_booths':
			case 'parent_lead_id':
				return h('input', { type: 'number', min: 0, name: key, value: value || '' });
			case 'description':
			case 'qualification_notes':
				return h('textarea', { name: key, rows: 3, text: value || '' });
			default:
				return h('input', { type: key === 'email' ? 'email' : 'text', name: key, value: value || '' });
		}
	}

	function formValues(form) {
		const out = {};
		new FormData(form).forEach((v, k) => { out[k] = v; });
		return out;
	}

	function openLeadForm() {
		const body = drawerShell('Add organizer lead');
		const form = h('form', { class: 'lnkup-og-form', 'data-testid': 'og-lead-form', onsubmit: async (e) => {
			e.preventDefault();
			const values = formValues(form);
			values.source = values.source || 'manual';
			const res = await api('/leads', { method: 'POST', body: values });
			if (!res.ok) {
				if (res.code === 'lnkup_lead_duplicate' && res.data && res.data.duplicate_of) {
					toast(res.error + ' Opening the existing lead.', true);
					openLead(res.data.duplicate_of);
					return;
				}
				toast(res.error, true);
				return;
			}
			toast('Lead added.');
			renderDetail(res.data.lead);
		} });
		LEAD_FIELDS.forEach(([key, text]) => form.appendChild(field(text, leadInput(key, ''))));
		form.appendChild(h('button', { class: 'button button-primary', type: 'submit', text: 'Save lead' }));
		body.appendChild(form);
	}

	async function openLead(id) {
		const body = drawerShell('Lead');
		loading(body);
		const res = await api('/leads/' + id);
		if (!res.ok) {
			errorCard(body, res.error, () => openLead(id));
			return;
		}
		renderDetail(res.data.lead);
	}

	function refreshLists() {
		const visible = app.querySelector('[data-og-panel]:not([hidden])');
		if (visible && visible.dataset.ogPanel === 'pipeline') {
			renderPipeline(visible);
		} else if (visible && visible.dataset.ogPanel === 'leads') {
			loadLeads(visible.querySelector('.lnkup-og-results'));
		}
	}

	async function act(promise, success, lead) {
		const res = await promise;
		if (!res.ok) {
			toast(res.error, true);
			return null;
		}
		if (success) {
			toast(success);
		}
		const next = res.data.lead || null;
		if (next && next.activity) {
			renderDetail(next);
		} else if (lead) {
			openLead(lead.id);
		}
		refreshLists();
		return res.data;
	}

	function section(title, ...content) {
		return h('section', { class: 'lnkup-og-section' }, h('h3', { text: title }), ...content);
	}

	function renderDetail(lead) {
		const body = drawerShell(lead.name);
		body.dataset.leadId = lead.id;

		const badges = h('p', { class: 'lnkup-og-badges' },
			h('span', { class: 'lnkup-og-tier tier-' + (lead.tier || 'C'), text: 'Tier ' + (lead.tier || '—') + ' · ' + lead.score }),
			h('span', { class: 'lnkup-og-badge', text: lead.stage_label }),
			lead.outcome_label ? h('span', { class: 'lnkup-og-badge is-muted', text: lead.outcome_label }) : null,
			lead.is_house_affiliated ? h('span', { class: 'lnkup-og-badge', text: 'House-affiliated' }) : null,
			lead.is_suppressed ? h('span', { class: 'lnkup-og-badge is-muted', text: 'Do not email' }) : null,
			lead.contact_is_stale ? h('span', { class: 'lnkup-og-badge is-warn', text: 'Re-verify contact before outreach' }) : null
		);
		body.appendChild(badges);

		// Stage + outcome.
		const stageSel = select('stage', stageOptions(false), lead.stage, { 'aria-label': 'Pipeline stage' });
		const outcomeSel = select('outcome', [{ value: '', label: 'No outcome' }, { value: 'clear', label: 'Reopen' }].concat(state.boot.outcomes.map((o) => ({ value: o.key, label: o.label }))), '', { 'aria-label': 'Outcome' });
		const note = h('input', { type: 'text', name: 'note', placeholder: 'Reason / objection (optional)', 'aria-label': 'Stage note' });
		const milestoneStage = (state.boot.stages.find((s) => s.key === lead.stage) || {}).milestone_only;
		body.appendChild(section('Pipeline',
			milestoneStage ? h('p', { class: 'description', text: 'This stage was reached from verified marketplace activity.' }) : null,
			h('div', { class: 'lnkup-og-inline' }, stageSel, outcomeSel, note,
				h('button', { class: 'button', type: 'button', text: 'Update', 'data-testid': 'og-stage-save', onclick: () => act(api('/leads/' + lead.id + '/stage', { method: 'POST', body: { stage: stageSel.value === lead.stage ? '' : stageSel.value, outcome: outcomeSel.value, note: note.value } }), 'Pipeline updated.', lead) })
			),
			h('p', { class: 'description', text: 'First Event Published, First Application, First Paid Booking, Activated and Repeat Organizer are set automatically from real marketplace activity.' })
		));

		// Log contact.
		const contactForm = h('form', { class: 'lnkup-og-form lnkup-og-grid2', 'data-testid': 'og-contact-form', onsubmit: (e) => {
			e.preventDefault();
			act(api('/leads/' + lead.id + '/activity', { method: 'POST', body: formValues(contactForm) }), 'Logged.', lead);
		} },
			field('Channel', select('channel', ['note', 'email', 'phone', 'instagram_dm', 'facebook_dm', 'contact_form', 'meeting', 'in_person', 'other'].map((c) => ({ value: c, label: label(c) })), 'phone')),
			field('Outcome', select('outcome', ['', 'sent', 'no_answer', 'left_message', 'replied', 'meeting_booked', 'demo_done', 'not_interested', 'bounced', 'wrong_contact'].map((c) => ({ value: c, label: c ? label(c) : '—' })), '')),
			field('Summary', h('input', { type: 'text', name: 'summary', required: true })),
			field('When', h('input', { type: 'datetime-local', name: 'occurred_at' })),
			field('Next follow-up', h('input', { type: 'datetime-local', name: 'next_follow_up_at' })),
			field('Details', h('textarea', { name: 'details', rows: 2 })),
			h('button', { class: 'button button-primary', type: 'submit', text: 'Log activity' })
		);
		body.appendChild(section('Record outreach or a note', h('p', { class: 'description', text: 'Log calls, DMs and contact-form messages you sent yourself. LNKup does not send social messages.' }), contactForm));

		// Tasks.
		const taskForm = h('form', { class: 'lnkup-og-form lnkup-og-inline', onsubmit: (e) => {
			e.preventDefault();
			act(api('/leads/' + lead.id + '/tasks', { method: 'POST', body: formValues(taskForm) }), 'Task added.', lead);
		} },
			select('type', ['follow_up', 'call', 'meeting', 'demo', 'research', 'verify_contact'].map((c) => ({ value: c, label: label(c) })), 'follow_up', { 'aria-label': 'Task type' }),
			h('input', { type: 'text', name: 'title', placeholder: 'Task', 'aria-label': 'Task title' }),
			h('input', { type: 'datetime-local', name: 'due_at', required: true, 'aria-label': 'Due' }),
			select('assigned_user_id', teamOptions('Lead owner'), '', { 'aria-label': 'Assignee' }),
			h('button', { class: 'button', type: 'submit', text: 'Add task' })
		);
		const taskList = lead.tasks.length ? h('ul', { class: 'lnkup-og-list' }, lead.tasks.map((t) => h('li', { class: t.is_overdue ? 'is-overdue' : '' },
			h('span', { text: label(t.type) + ': ' + t.title + ' · due ' + fmtDate(t.due_at) + (t.assigned_name ? ' · ' + t.assigned_name : '') + (t.status === 'done' ? ' · done' : '') }),
			t.status === 'open' ? h('button', { class: 'button-link', type: 'button', text: 'Complete', onclick: () => act(api('/tasks/' + t.id + '/complete', { method: 'POST', body: {} }), 'Task completed.', lead) }) : null
		))) : h('p', { class: 'description', text: 'No tasks yet.' });
		body.appendChild(section('Follow-ups & meetings', taskList, taskForm));

		// Score.
		body.appendChild(section('Why this score',
			table(['Factor', 'Points', 'Detail'], (lead.score_explanation || []).map((x) => [x.label, x.points + ' / ' + x.max, x.detail]))
		));

		// Accounts + attribution.
		const linkInput = h('input', { type: 'email', placeholder: 'organizer@business.com', 'aria-label': 'Organizer account email' });
		const accounts = lead.accounts.length ? h('ul', { class: 'lnkup-og-list' }, lead.accounts.map((a) => h('li', {},
			h('span', {},
				a.name + ' <' + a.email + '> · linked by ' + label(a.link_method) + ' on ' + fmtDate(a.linked_at),
				a.activation ? h('span', { class: 'lnkup-og-activation', 'data-testid': 'og-account-activation' },
					h('br'),
					'Setup ' + a.activation.percent + '% — ',
					a.activation.stages.map((s) => (s.status === 'complete' ? '✓ ' : s.status === 'in_progress' ? '◐ ' : '○ ') + s.title).join(' · ')
				) : null
			),
			h('button', { class: 'button-link', type: 'button', text: 'Unlink', onclick: () => { if (window.confirm('Unlink this account? Milestones will be recalculated.')) { act(api('/leads/' + lead.id + '/link-account' + qs({ user_id: a.user_id }), { method: 'DELETE' }), 'Account unlinked.', lead); } } })
		))) : h('p', { class: 'description', text: 'No LNKup organizer account linked yet.' });
		const suggestions = lead.activity.filter((a) => a.type === 'account_suggested' && a.payload && a.payload.user_id);
		body.appendChild(section('Organizer accounts & attribution',
			accounts,
			suggestions.length ? h('div', { class: 'lnkup-og-notice' }, suggestions.slice(0, 3).map((s) => h('p', {}, s.summary + ' ', h('button', { class: 'button', type: 'button', text: 'Confirm link', onclick: () => act(api('/leads/' + lead.id + '/link-account', { method: 'POST', body: { user_id: s.payload.user_id } }), 'Account linked.', lead) })))) : null,
			h('div', { class: 'lnkup-og-inline' }, linkInput, h('button', { class: 'button', type: 'button', text: 'Link account', 'data-testid': 'og-link-account', onclick: () => act(api('/leads/' + lead.id + '/link-account', { method: 'POST', body: { email: linkInput.value } }), 'Account linked.', lead) })),
			h('p', { class: 'description', text: 'Source: ' + label(lead.source || 'unknown') + (lead.source_campaign ? ' · ' + lead.source_campaign : '') + (lead.referral_code ? ' · code ' + lead.referral_code : '') + (lead.import_id ? ' · import #' + lead.import_id : '') }),
			lead.milestones.length ? table(['Milestone', 'Reached', 'Status'], lead.milestones.map((m) => [label(m.milestone), fmtDate(m.achieved_at), m.status])) : null
		));

		// Duplicates.
		if (lead.duplicates.length) {
			body.appendChild(section('Possible duplicates', h('ul', { class: 'lnkup-og-list' }, lead.duplicates.map((m) => h('li', {},
				h('button', { class: 'button-link', type: 'button', text: 'Lead #' + m.lead_id, onclick: () => openLead(m.lead_id) }),
				' — ' + label(m.reason) + (m.hard ? ' (exact match)' : ' (similar name)') + ' ',
				h('button', { class: 'button', type: 'button', text: 'Merge this lead into #' + m.lead_id, onclick: () => { if (window.confirm('Merge "' + lead.name + '" into lead #' + m.lead_id + '? History and attribution move to that lead.')) { act(api('/leads/' + lead.id + '/merge', { method: 'POST', body: { into: m.lead_id } }), 'Leads merged.'); } } })
			))), lead.possible_duplicate_of ? h('button', { class: 'button', type: 'button', text: 'Not the same business', onclick: () => act(api('/leads/' + lead.id + '/not-duplicate', { method: 'POST', body: {} }), 'Flag cleared.', lead) }) : null));
		}

		// Known events.
		const evForm = h('form', { class: 'lnkup-og-inline', onsubmit: (e) => { e.preventDefault(); act(api('/leads/' + lead.id + '/events', { method: 'POST', body: formValues(evForm) }), 'Event added.', lead); } },
			h('input', { type: 'text', name: 'name', placeholder: 'Event name', required: true, 'aria-label': 'Event name' }),
			h('input', { type: 'date', name: 'event_date', 'aria-label': 'Event date' }),
			h('input', { type: 'url', name: 'url', placeholder: 'Public URL', 'aria-label': 'Event URL' }),
			h('label', { class: 'lnkup-og-check' }, h('input', { type: 'checkbox', name: 'verified', value: '1' }), ' Publicly verified'),
			h('button', { class: 'button', type: 'submit', text: 'Add event' })
		);
		body.appendChild(section('Known events', lead.events.length ? table(['Event', 'Date', 'City', 'Verified'], lead.events.map((e) => [e.url ? h('a', { href: e.url, target: '_blank', rel: 'noopener noreferrer', text: e.name }) : e.name, e.event_date || '—', e.city || '—', e.verified_at ? 'Yes' : 'No'])) : h('p', { class: 'description', text: 'No events recorded.' }), evForm));

		// Details form.
		const form = h('form', { class: 'lnkup-og-form lnkup-og-grid2', 'data-testid': 'og-lead-edit', onsubmit: (e) => {
			e.preventDefault();
			const values = formValues(form);
			values.tags = (values.tags || '').split(',').map((t) => t.trim()).filter(Boolean);
			act(api('/leads/' + lead.id, { method: 'PATCH', body: values }), 'Lead saved.', lead);
		} });
		LEAD_FIELDS.forEach(([key, text]) => form.appendChild(field(text, leadInput(key, lead[key]))));
		form.appendChild(field('Tags (comma separated)', h('input', { type: 'text', name: 'tags', value: (lead.tags || []).join(', ') })));
		form.appendChild(h('button', { class: 'button button-primary', type: 'submit', text: 'Save details' }));
		body.appendChild(section('Details', form));

		// Outreach history + suppression.
		body.appendChild(section('Email outreach',
			lead.outreach.length ? table(['Campaign', 'Status', 'Sent'], lead.outreach.map((o) => [o.campaign, label(o.status) + (o.skip_reason ? ' (' + label(o.skip_reason) + ')' : ''), fmtDate(o.sent_at)])) : h('p', { class: 'description', text: 'Not included in any campaign yet.' }),
			h('div', { class: 'lnkup-og-inline' },
				h('button', { class: 'button', type: 'button', text: 'Mark Do Not Contact', onclick: () => { if (window.confirm('Stop all outreach to this organizer?')) { act(api('/leads/' + lead.id + '/suppress', { method: 'POST', body: { reason: 'do_not_contact' } }), 'Marked Do Not Contact.', lead); } } }),
				h('button', { class: 'button', type: 'button', text: 'Record bounce', onclick: () => act(api('/leads/' + lead.id + '/suppress', { method: 'POST', body: { reason: 'bounce' } }), 'Bounce recorded.', lead) })
			)
		));

		// Activity.
		body.appendChild(section('History', lead.activity.length ? h('ol', { class: 'lnkup-og-timeline', 'data-testid': 'og-activity' }, lead.activity.map((a) => h('li', {},
			h('strong', { text: a.summary || label(a.type) }),
			h('span', { text: ' · ' + label(a.type) + (a.channel ? ' via ' + label(a.channel) : '') + (a.outcome ? ' (' + label(a.outcome) + ')' : '') + ' · ' + a.actor + ' · ' + fmtDate(a.occurred_at) })
		))) : h('p', { class: 'description', text: 'No history yet.' })));
	}

	// ------------------------------------------------------------------ Pipeline.

	async function renderPipeline(el) {
		loading(el);
		const filters = { ...state.leadFilters };
		delete filters.stage;
		delete filters.page;
		delete filters.per_page;
		const res = await api('/pipeline' + qs(filters));
		if (!res.ok) {
			errorCard(el, res.error, () => renderPipeline(el));
			return;
		}
		clear(el);
		const columns = res.data.columns;
		if (!columns.some((c) => c.count > 0)) {
			el.appendChild(empty('No open leads in the pipeline. Import or add leads first.', h('a', { class: 'button button-primary', href: '#import', text: 'Import leads' })));
			return;
		}
		el.appendChild(h('p', { class: 'description', text: 'Drag a card to move it. Columns marked "verified" fill automatically from real marketplace activity. Showing up to 25 leads per column; use Organizer Leads for the full list.' }));
		const board = h('div', { class: 'lnkup-og-board', 'data-testid': 'og-board' });
		columns.forEach((col) => {
			const list = h('div', { class: 'lnkup-og-col-list' });
			const column = h('div', { class: 'lnkup-og-col' + (col.milestone_only ? ' is-milestone' : ''), 'data-stage': col.key },
				h('header', {}, h('strong', { text: col.label }), h('span', { class: 'lnkup-og-count', text: String(col.count) }), col.milestone_only ? h('span', { class: 'lnkup-og-badge', text: 'verified' }) : null),
				list
			);
			if (!col.milestone_only) {
				column.addEventListener('dragover', (e) => { e.preventDefault(); column.classList.add('is-over'); });
				column.addEventListener('dragleave', () => column.classList.remove('is-over'));
				column.addEventListener('drop', async (e) => {
					e.preventDefault();
					column.classList.remove('is-over');
					const id = e.dataTransfer.getData('text/plain');
					const res2 = await api('/leads/' + id + '/stage', { method: 'POST', body: { stage: col.key } });
					if (!res2.ok) {
						toast(res2.error, true);
						return;
					}
					toast('Moved to ' + col.label + '.');
					renderPipeline(el);
				});
			}
			col.items.forEach((item) => {
				list.appendChild(h('button', { type: 'button', class: 'lnkup-og-card', draggable: 'true', 'data-lead-card': item.id,
					ondragstart: (e) => e.dataTransfer.setData('text/plain', String(item.id)),
					onclick: () => openLead(item.id) },
					h('strong', { text: item.name }),
					h('span', { text: [item.city, item.state].filter(Boolean).join(', ') }),
					h('span', { class: 'lnkup-og-tier tier-' + (item.tier || 'C'), text: (item.tier || '—') + ' · ' + item.score }),
					item.assigned_user_name ? h('span', { text: item.assigned_user_name }) : null
				));
			});
			if (col.count > col.items.length) {
				list.appendChild(h('p', { class: 'description', text: '+' + (col.count - col.items.length) + ' more' }));
			}
			board.appendChild(column);
		});
		el.appendChild(board);
	}

	// ------------------------------------------------------------------ Tasks.

	async function renderTasks(el, windowKey = 'overdue', mine = true) {
		loading(el);
		const res = await api('/tasks' + qs({ window: windowKey, assigned_user_id: mine ? state.boot.current_user_id : '' }));
		if (!res.ok) {
			errorCard(el, res.error, () => renderTasks(el, windowKey, mine));
			return;
		}
		clear(el);
		el.appendChild(h('div', { class: 'lnkup-og-toolbar' },
			select('window', [{ value: 'overdue', label: 'Overdue' }, { value: 'today', label: 'Due today' }, { value: 'upcoming', label: 'Upcoming' }, { value: 'all', label: 'All open' }], windowKey, { 'aria-label': 'Due window', onchange: (e) => renderTasks(el, e.target.value, mine) }),
			h('label', { class: 'lnkup-og-check' }, h('input', { type: 'checkbox', checked: mine, onchange: (e) => renderTasks(el, windowKey, e.target.checked) }), ' Only mine')
		));
		const items = res.data.items || [];
		if (!items.length) {
			el.appendChild(empty('Nothing here. Follow-ups you add on a lead appear in this list.'));
			return;
		}
		el.appendChild(table(['Due', 'Task', 'Organizer', 'Assignee', ''], items.map((t) => [
			h('span', { class: t.is_overdue ? 'is-overdue' : '', text: fmtDate(t.due_at) }),
			label(t.type) + ': ' + t.title,
			h('button', { class: 'button-link', type: 'button', text: t.lead_name, onclick: () => openLead(t.lead_id) }),
			t.assigned_name || '—',
			h('button', { class: 'button', type: 'button', text: 'Complete', onclick: async () => {
				const r = await api('/tasks/' + t.id + '/complete', { method: 'POST', body: {} });
				if (!r.ok) {
					toast(r.error, true);
					return;
				}
				toast('Task completed.');
				renderTasks(el, windowKey, mine);
			} }),
		])));
	}

	// ------------------------------------------------------------------ Outreach.

	async function renderOutreach(el) {
		loading(el);
		const [tpl, camps, supp] = await Promise.all([api('/templates'), api('/campaigns'), api('/suppression')]);
		if (!tpl.ok || !camps.ok) {
			errorCard(el, (tpl.ok ? camps : tpl).error, () => renderOutreach(el));
			return;
		}
		clear(el);
		if (!state.boot.outreach_enabled) {
			el.appendChild(h('div', { class: 'lnkup-og-notice', role: 'note' }, h('p', { text: 'Email outreach is off. You can still prepare templates and log manual outreach. Turn on "Organizer outreach emails" in Settings and add a sender postal address before sending.' })));
		}
		const templates = tpl.data.items || [];

		// New campaign.
		const audience = select('audience', [{ value: 'filters', label: 'Leads matching the current Organizer Leads filters' }, { value: 'view', label: 'A saved view' }, { value: 'ids', label: 'Specific lead IDs' }], 'filters', { 'aria-label': 'Audience' });
		const viewSel = h('select', { name: 'view_id', 'aria-label': 'Saved view' }, (state.views || []).map((v) => h('option', { value: v.id, text: v.name })));
		const idsInput = h('input', { type: 'text', name: 'lead_ids', placeholder: '12, 15, 40', 'aria-label': 'Lead IDs' });
		const form = h('form', { class: 'lnkup-og-form lnkup-og-grid2', 'data-testid': 'og-campaign-form', onsubmit: async (e) => {
			e.preventDefault();
			const v = formValues(form);
			const payload = { name: v.name, template_key: v.template_key, daily_cap: v.daily_cap };
			if (audience.value === 'ids') {
				payload.lead_ids = idsInput.value.split(/[\s,]+/).map(Number).filter(Boolean);
			} else if (audience.value === 'view') {
				const view = state.views.find((x) => String(x.id) === String(viewSel.value));
				payload.filters = view ? view.filters : {};
			} else {
				const f = { ...state.leadFilters };
				delete f.page;
				delete f.per_page;
				payload.filters = f;
			}
			const res = await api('/campaigns', { method: 'POST', body: payload });
			if (!res.ok) {
				toast(res.error, true);
				return;
			}
			const skipped = Object.entries(res.data.skipped || {}).map(([k, n]) => label(k) + ': ' + n).join(', ');
			toast(res.data.eligible + ' eligible recipients' + (skipped ? ' · skipped ' + skipped : '') + '. Review the campaign before sending.');
			openCampaign(res.data.campaign.id);
			renderOutreach(el);
		} },
			field('Campaign name', h('input', { type: 'text', name: 'name', required: true })),
			field('Template', select('template_key', templates.filter((t) => t.is_active).map((t) => ({ value: t.key, label: t.name })), 'first_intro')),
			field('Audience', audience),
			field('Saved view', viewSel),
			field('Lead IDs', idsInput),
			field('Daily cap', h('input', { type: 'number', name: 'daily_cap', min: 0, value: state.boot.settings.org_growth_campaign_daily_cap })),
			h('button', { class: 'button button-primary', type: 'submit', text: 'Create draft campaign', disabled: !state.boot.outreach_enabled })
		);
		el.appendChild(section('New campaign', h('p', { class: 'description', text: 'Creating a campaign never sends. Suppressed, unverified, recently-contacted and already-registered organizers are excluded and listed with the reason.' }), form));

		// Campaign list.
		const list = camps.data.items || [];
		el.appendChild(section('Campaigns', list.length ? table(['Campaign', 'Template', 'Status', 'Approval', 'Sent', 'Pending', 'Skipped', 'Registered', ''], list.map((c) => [
			c.name, c.template_key, label(c.status), label(c.approval_status || '—'), c.counts.sent, c.counts.pending, c.counts.skipped + c.counts.suppressed, c.counts.registered,
			h('button', { class: 'button', type: 'button', text: 'Open', onclick: () => openCampaign(c.id) }),
		]), { 'data-testid': 'og-campaigns' }) : empty('No campaigns yet.')));

		// Templates.
		el.appendChild(section('Templates', h('p', { class: 'description', text: 'Merge fields: ' + (tpl.data.merge_fields || []).map((f) => '{{' + f + '}}').join(' ') + '. A template only sends when every fact it uses is recorded on the lead; otherwise its fallback is used or the lead is skipped.' }),
			table(['Template', 'Subject', 'Needs', 'Fallback', ''], templates.map((t) => [t.name + (t.is_active ? '' : ' (inactive)'), t.subject, t.required_fields.join(', '), t.fallback_key || '—', h('button', { class: 'button', type: 'button', text: 'Edit / preview', onclick: () => openTemplate(t) })]))
		));

		// Suppression.
		const suppEmail = h('input', { type: 'text', placeholder: 'email@example.com or example.com', 'aria-label': 'Email or domain to suppress' });
		el.appendChild(section('Suppression list',
			h('p', { class: 'description', text: supp.ok ? Object.entries(supp.data.counts).map(([k, n]) => label(k) + ': ' + n).join(' · ') : 'Unavailable' }),
			h('div', { class: 'lnkup-og-inline' }, suppEmail, h('button', { class: 'button', type: 'button', text: 'Add to suppression', onclick: async () => {
				const v = suppEmail.value.trim();
				const res = await api('/suppression', { method: 'POST', body: v.includes('@') ? { email: v, reason: 'manual' } : { domain: v, reason: 'manual' } });
				toast(res.ok ? 'Suppressed.' : res.error, !res.ok);
				if (res.ok) {
					renderOutreach(el);
				}
			} }))
		));
	}

	function openTemplate(t) {
		const body = drawerShell('Template: ' + t.name);
		const preview = h('div', { class: 'lnkup-og-preview', 'data-testid': 'og-template-preview' });
		const leadId = h('input', { type: 'number', min: 1, placeholder: 'Lead ID', 'aria-label': 'Preview with lead ID' });
		const form = h('form', { class: 'lnkup-og-form', onsubmit: async (e) => {
			e.preventDefault();
			const res = await api('/templates/' + t.key, { method: 'PUT', body: formValues(form) });
			toast(res.ok ? 'Template saved.' : res.error, !res.ok);
		} },
			field('Name', h('input', { type: 'text', name: 'name', value: t.name })),
			field('Subject', h('input', { type: 'text', name: 'subject', value: t.subject })),
			field('Message', h('textarea', { name: 'body', rows: 12, text: t.body })),
			field('Fallback template key', h('input', { type: 'text', name: 'fallback_key', value: t.fallback_key })),
			h('label', { class: 'lnkup-og-check' }, h('input', { type: 'checkbox', name: 'is_active', value: '1', checked: t.is_active }), ' Active'),
			h('button', { class: 'button button-primary', type: 'submit', text: 'Save template' })
		);
		body.append(form, section('Preview with a lead', h('div', { class: 'lnkup-og-inline' }, leadId, h('button', { class: 'button', type: 'button', text: 'Preview', onclick: async () => {
			const res = await api('/templates/' + t.key + '/preview', { method: 'POST', body: { lead_id: leadId.value } });
			clear(preview);
			if (!res.ok) {
				preview.appendChild(h('p', { class: 'lnkup-og-error', text: res.error }));
				return;
			}
			preview.append(
				h('p', {}, h('strong', { text: 'Subject: ' }), res.data.subject),
				res.data.fallback_used ? h('p', { class: 'description', text: 'Fallback template used: ' + res.data.template_key }) : null,
				res.data.eligibility ? h('p', { class: 'lnkup-og-badge is-warn', text: 'This lead would be skipped: ' + label(res.data.eligibility) }) : null,
				h('pre', { text: res.data.text })
			);
		} })), preview));
	}

	async function openCampaign(id) {
		const body = drawerShell('Campaign');
		loading(body);
		const res = await api('/campaigns/' + id);
		if (!res.ok) {
			errorCard(body, res.error, () => openCampaign(id));
			return;
		}
		const c = res.data.campaign;
		clear(body);
		const actionBtn = (text, path, done) => h('button', { class: 'button', type: 'button', text, onclick: async () => {
			const r = await api('/campaigns/' + id + path, { method: 'POST', body: {} });
			toast(r.ok ? done : r.error, !r.ok);
			if (r.ok) {
				openCampaign(id);
			}
		} });
		body.append(
			h('h3', { text: c.name }),
			h('p', { text: 'Status: ' + label(c.status) + ' · Approval: ' + label(c.approval_status || 'not required') + ' · Daily cap: ' + (c.daily_cap || 'none') + ' · Created by ' + c.created_by_name }),
			h('p', { text: 'Sent: ' + c.counts.sent + ' · Pending: ' + c.counts.pending + ' · Skipped: ' + c.counts.skipped + ' · Suppressed: ' + c.counts.suppressed + ' · Unsubscribed: ' + c.counts.unsubscribed + ' · Failed: ' + c.counts.failed + ' · Registered: ' + c.counts.registered }),
			h('p', { class: 'description', text: '"Sent" means the mail server accepted the message. Opens are not tracked; replies and meetings are logged on each lead.' }),
			h('div', { class: 'lnkup-og-inline' },
				c.approval_status === 'pending' && c.created_by !== state.boot.current_user_id ? actionBtn('Approve', '/approve', 'Approved.') : null,
				c.approval_status === 'pending' && c.created_by === state.boot.current_user_id ? h('span', { class: 'description', text: 'Waiting for another admin to approve.' }) : null,
				['draft', 'cancelled'].includes(c.status) && c.approval_status !== 'pending' ? actionBtn('Send (queue)', '/queue', 'Queued — sending in batches within the daily limits.') : null,
				['queued', 'processing'].includes(c.status) ? actionBtn('Pause / cancel', '/cancel', 'Cancelled.') : null
			),
			table(['Organizer', 'Email', 'Status', 'Reason', 'Sent'], res.data.recipients.map((r) => [
				r.lead_id ? h('button', { class: 'button-link', type: 'button', text: r.name, onclick: () => openLead(r.lead_id) }) : r.name,
				r.email || '—', label(r.status), r.skip_reason ? label(r.skip_reason) : (r.error_message || '—'), fmtDate(r.sent_at),
			]))
		);
	}

	// ------------------------------------------------------------------ Import.

	async function renderImport(el) {
		clear(el);
		const status = h('div', { class: 'lnkup-og-import-step', 'data-testid': 'og-import-step' });
		const form = h('form', { class: 'lnkup-og-form lnkup-og-grid2', 'data-testid': 'og-import-form', onsubmit: async (e) => {
			e.preventDefault();
			const data = new FormData(form);
			if (!data.get('file') || !data.get('file').name) {
				toast('Choose a CSV file.', true);
				return;
			}
			loading(status);
			const res = await api('/import/upload', { method: 'POST', body: data });
			if (!res.ok) {
				errorCard(status, res.error);
				return;
			}
			renderMapping(status, res.data);
		} },
			field('CSV file', h('input', { type: 'file', name: 'file', accept: '.csv,.tsv,.txt,text/csv', required: true }), 'Excel or Google Sheets: File → Download → CSV. Max 5 MB.'),
			field('Source label', h('input', { type: 'text', name: 'source_label', placeholder: 'e.g. NC + GA organizer research' })),
			field('Research verified on', h('input', { type: 'date', name: 'verified_at' }), 'Used when a row has no verification date of its own. Outreach is blocked for contacts older than the staleness limit.'),
			h('div', { class: 'lnkup-og-inline' },
				h('button', { class: 'button button-primary', type: 'submit', text: 'Upload & map columns' }),
				h('button', { class: 'button', type: 'button', text: 'Download template', onclick: async () => {
					const r = await api('/import/template');
					if (r.ok) {
						downloadCsv(r.data.filename, r.data.csv);
					}
				} })
			)
		);
		el.append(section('Import organizer research', h('p', { class: 'description', text: 'Imported organizers become leads only. Nobody is contacted and no accounts are created.' }), form), status);
		const history = h('div');
		el.appendChild(section('Import history', history));
		loadImportHistory(history);
	}

	function renderMapping(status, data) {
		clear(status);
		const targets = [{ value: '', label: '— ignore —' }].concat(data.targets.map((t) => ({ value: t, label: label(t) })));
		const rows = data.headers.map((header) => [header, select('map:' + header, targets, data.mapping[header] || '', { 'aria-label': 'Map ' + header, 'data-header': header })]);
		const mapTable = table(['Column in file', 'Saves to'], rows);
		status.append(
			h('h3', { text: data.import.file_name + ' — ' + data.import.total_rows + ' rows' }),
			mapTable,
			h('button', { class: 'button button-primary', type: 'button', text: 'Run dry run', 'data-testid': 'og-import-dry-run', onclick: async () => {
				const mapping = {};
				mapTable.querySelectorAll('select[data-header]').forEach((s) => { mapping[s.dataset.header] = s.value; });
				const res = await api('/import/' + data.import.id + '/preview', { method: 'POST', body: { mapping } });
				if (!res.ok) {
					toast(res.error, true);
					return;
				}
				renderDryRun(status, res.data);
			} })
		);
	}

	function renderDryRun(status, d) {
		clear(status);
		const s = d.summary;
		status.append(
			h('h3', { text: 'Dry run — nothing has been saved yet' }),
			h('p', { 'data-testid': 'og-dry-run-summary', text: 'Will create ' + s.would_create + ' · link to existing accounts ' + s.existing_account + ' · flag possible duplicates ' + s.possible_duplicate + ' · skip duplicates ' + (s.duplicate + s.duplicate_in_file) + ' · errors ' + s.error + (d.previewed < d.total_rows ? ' (first ' + d.previewed + ' of ' + d.total_rows + ' rows checked)' : '') }),
			table(['Row', 'Organizer', 'City', 'Result', 'Score'], d.sample.map((r) => [r.row, r.name || '—', [r.city, r.state].filter(Boolean).join(', ') || '—', label(r.status) + (r.message ? ' — ' + r.message : ''), r.score === null ? '—' : r.tier + ' · ' + r.score])),
			h('button', { class: 'button button-primary', type: 'button', text: 'Import leads', 'data-testid': 'og-import-run', onclick: async () => {
				const res = await api('/import/' + d.import.id + '/run', { method: 'POST', body: {} });
				if (!res.ok) {
					toast(res.error, true);
					return;
				}
				pollImport(status, d.import.id);
			} })
		);
	}

	async function pollImport(status, id) {
		const res = await api('/import/' + id);
		if (!res.ok) {
			errorCard(status, res.error);
			return;
		}
		const imp = res.data.import;
		clear(status).append(
			h('h3', { text: 'Import #' + imp.id + ': ' + label(imp.status) }),
			h('p', { 'data-testid': 'og-import-result', text: imp.processed_rows + ' of ' + imp.total_rows + ' rows · created ' + imp.created_count + ' · duplicates ' + imp.duplicate_count + ' · errors ' + imp.error_count })
		);
		if (imp.status === 'running') {
			setTimeout(() => pollImport(status, id), 2500);
			return;
		}
		const problems = (res.data.rows || []).filter((r) => r.status === 'error' || r.status === 'duplicate');
		if (problems.length) {
			status.appendChild(table(['Row', 'Result', 'Details'], problems.slice(0, 100).map((r) => [r.row_num, label(r.status), r.message + (r.duplicate_of && Number(r.duplicate_of) ? ' (lead #' + r.duplicate_of + ')' : '')])));
		}
		const hist = panel('import').querySelector('.lnkup-og-history');
		if (hist) {
			loadImportHistory(hist.parentNode);
		}
		state.boot.tags = (await api('/bootstrap')).data?.tags || state.boot.tags;
	}

	async function loadImportHistory(el) {
		loading(el);
		const res = await api('/import');
		if (!res.ok) {
			errorCard(el, res.error, () => loadImportHistory(el));
			return;
		}
		clear(el);
		const items = res.data.items || [];
		if (!items.length) {
			el.appendChild(empty('No imports yet.'));
			return;
		}
		el.appendChild(h('div', { class: 'lnkup-og-history' }, table(['File', 'Source', 'Status', 'Rows', 'Created', 'Duplicates', 'Errors', 'Rolled back', 'By', ''], items.map((i) => [
			i.file_name, i.source_label || '—', label(i.status), i.total_rows, i.created_count, i.duplicate_count, i.error_count, i.rolled_back_count, i.created_by + ' · ' + fmtDate(i.created_at),
			['completed', 'failed'].includes(i.status) ? h('button', { class: 'button', type: 'button', text: 'Roll back', onclick: async () => {
				if (!window.confirm('Delete the leads this import created that nobody has worked yet?')) {
					return;
				}
				const r = await api('/import/' + i.id + '/rollback', { method: 'POST', body: {} });
				if (!r.ok) {
					toast(r.error, true);
					return;
				}
				toast('Removed ' + r.data.removed + ' leads' + (r.data.kept.length ? '; kept ' + r.data.kept.length + ' that already have activity.' : '.'));
				loadImportHistory(el);
			} }) : '—',
		]))));
	}

	// ------------------------------------------------------------------ Settings.

	async function renderSettings(el) {
		loading(el);
		const [settingsRes, scoringRes] = await Promise.all([api('/settings'), api('/scoring/config')]);
		if (!settingsRes.ok) {
			errorCard(el, settingsRes.error, () => renderSettings(el));
			return;
		}
		const s = settingsRes.data.settings;
		clear(el);
		const readiness = state.boot.readiness || [];
		if (readiness.length) {
			el.appendChild(section('Go-live readiness', h('ul', { class: 'lnkup-og-readiness', 'data-testid': 'og-readiness' }, readiness.map((r) => h('li', { class: r.ok ? 'is-ok' : 'is-todo' },
				h('strong', { text: (r.ok ? '✓ ' : '• ') + r.label }),
				h('span', { text: ' — ' + r.detail })
			)))));
		}
		const check = (name, text) => h('label', { class: 'lnkup-og-check' }, h('input', { type: 'checkbox', name, value: '1', checked: !!s[name] }), ' ' + text);
		const num = (name, text, hint) => field(text, h('input', { type: 'number', min: 0, name, value: s[name] }), hint);
		const form = h('form', { class: 'lnkup-og-form lnkup-og-grid2', 'data-testid': 'og-settings-form', onsubmit: async (e) => {
			e.preventDefault();
			const data = new FormData(form);
			const payload = {};
			['organizer_growth_enabled', 'organizer_growth_outreach_enabled'].forEach((k) => { payload[k] = data.get(k) ? true : false; });
			['org_growth_house_organizer_ids', 'org_growth_outreach_dedupe_days', 'org_growth_campaign_daily_cap', 'org_growth_approval_threshold', 'org_growth_contact_stale_days', 'org_growth_sender_name', 'org_growth_sender_postal_address', 'org_growth_import_max_rows', 'org_growth_activation_paid_bookings'].forEach((k) => { payload[k] = data.get(k) || ''; });
			const res = await api('/settings', { method: 'POST', body: payload });
			if (!res.ok) {
				toast(res.error, true);
				return;
			}
			toast('Settings saved.');
			const b = await api('/bootstrap');
			if (b.ok) {
				state.boot = b.data;
				app.querySelector('[data-og-flag]').hidden = state.boot.enabled;
			}
		} },
			check('organizer_growth_enabled', 'Organizer Growth enabled'),
			check('organizer_growth_outreach_enabled', 'Organizer outreach emails enabled'),
			field('House-affiliated organizer user IDs', h('input', { type: 'text', name: 'org_growth_house_organizer_ids', value: s.org_growth_house_organizer_ids || '' }), 'Six Stars and other house organizers, comma separated. Reported separately from independent organizers.'),
			num('org_growth_activation_paid_bookings', 'Paid bookings to count as Activated'),
			num('org_growth_outreach_dedupe_days', 'Do not re-email a lead within (days)'),
			num('org_growth_campaign_daily_cap', 'Default per-campaign daily cap'),
			num('org_growth_approval_threshold', 'Campaigns larger than this need a second approver'),
			num('org_growth_contact_stale_days', 'Contact data is stale after (days)'),
			num('org_growth_import_max_rows', 'Max rows per import'),
			field('Sender name', h('input', { type: 'text', name: 'org_growth_sender_name', value: s.org_growth_sender_name || '' })),
			field('Sender postal address (required for outreach)', h('textarea', { name: 'org_growth_sender_postal_address', rows: 3, text: s.org_growth_sender_postal_address || '' })),
			h('button', { class: 'button button-primary', type: 'submit', text: 'Save settings' })
		);
		el.appendChild(section('Module settings', form));

		if (scoringRes.ok) {
			const sc = scoringRes.data;
			const scoreForm = h('form', { class: 'lnkup-og-form lnkup-og-grid2', onsubmit: async (e) => {
				e.preventDefault();
				const data = new FormData(scoreForm);
				const weights = {};
				sc.factors.forEach((f) => { weights[f.key] = data.get('w:' + f.key); });
				const res = await api('/scoring/config', { method: 'POST', body: { weights, tier_a: data.get('tier_a'), tier_b: data.get('tier_b'), vendor_market_minimum: data.get('vendor_market_minimum') } });
				toast(res.ok ? 'Scoring saved. Leads are being rescored in the background.' : res.error, !res.ok);
			} },
				sc.factors.map((f) => field(f.label, h('input', { type: 'number', min: 0, max: 100, name: 'w:' + f.key, value: f.weight }))),
				field('Tier A at or above', h('input', { type: 'number', min: 0, name: 'tier_a', value: sc.tier_a })),
				field('Tier B at or above', h('input', { type: 'number', min: 0, name: 'tier_b', value: sc.tier_b })),
				field('Vendor profiles for "existing vendor market"', h('input', { type: 'number', min: 1, name: 'vendor_market_minimum', value: sc.vendor_market_minimum })),
				h('button', { class: 'button button-primary', type: 'submit', text: 'Save scoring' })
			);
			el.appendChild(section('Lead scoring weights (max ' + sc.max_score + ')', scoreForm));
		}
	}

	boot();
})();
