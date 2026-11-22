/**
 * Organizer "Get started" view: save organization details, copy share links, manage saved
 * event setups, refresh progress. Stages are server-rendered; this only adds actions.
 */
(function () {
	'use strict';

	const cfg = window.lnkupStart || {};
	const root = document.querySelector('[data-testid="lnkup-organizer-start"]');
	if (!root || !cfg.root) {
		return;
	}

	async function fetchLNKupJson(path, options = {}) {
		try {
			const headers = { 'X-WP-Nonce': cfg.nonce || '', ...(options.headers || {}) };
			let body = options.body;
			if (body && !(body instanceof FormData)) {
				headers['Content-Type'] = 'application/json';
				body = JSON.stringify(body);
			}
			const response = await fetch(cfg.root + path, { credentials: 'same-origin', ...options, headers, body });
			const type = response.headers.get('content-type') || '';
			if (!type.includes('application/json')) {
				console.error('LNKup: expected JSON from', path, await response.text());
				throw new Error('Invalid server response.');
			}
			const json = await response.json();
			if (!response.ok) {
				throw new Error(json.message || json.code || 'Request failed.');
			}
			return { success: true, data: json };
		} catch (error) {
			console.error('LNKup API error:', error);
			return { success: false, error: error.message || 'Unable to load data.' };
		}
	}

	function el(tag, attrs = {}, text) {
		const node = document.createElement(tag);
		Object.entries(attrs).forEach(([k, v]) => {
			if (k === 'class') {
				node.className = v;
			} else if (k.startsWith('on') && typeof v === 'function') {
				node.addEventListener(k.slice(2), v);
			} else if (v !== false && v !== null && v !== undefined) {
				node.setAttribute(k, v === true ? '' : String(v));
			}
		});
		if (text !== undefined) {
			node.textContent = text;
		}
		return node;
	}

	// ------------------------------------------------------------------ Progress refresh.

	async function refreshProgress() {
		const res = await fetchLNKupJson('/organizer/activation?fresh=1');
		if (!res.success || !res.data || !res.data.data) {
			return;
		}
		const data = res.data.data;
		const pct = root.querySelector('[data-start-percent]');
		if (pct) {
			pct.textContent = data.percent + '%';
		}
		(data.stages || []).forEach((stage) => {
			const li = root.querySelector('[data-stage="' + stage.key + '"]');
			if (!li) {
				return;
			}
			li.className = 'lnkup-start__stage is-' + stage.status;
			const badge = li.querySelector('.lnkup-start__status');
			if (badge) {
				badge.textContent = stage.status === 'complete' ? 'Done' : stage.status === 'in_progress' ? 'In progress' : 'To do';
			}
		});
	}

	// ------------------------------------------------------------------ Organization form.

	const form = root.querySelector('[data-start-profile]');
	if (form) {
		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			const msg = form.querySelector('.lnkup-start__msg');
			const button = form.querySelector('button[type="submit"]');
			button.disabled = true;
			msg.textContent = 'Saving…';
			const values = {};
			['public_name', 'city', 'state', 'website', 'bio'].forEach((k) => {
				values[k] = (form.elements[k] && form.elements[k].value) || '';
			});
			const saved = await fetchLNKupJson('/organizer/profile', { method: 'POST', body: values });
			let error = saved.success ? '' : saved.error;
			const logo = form.elements.logo && form.elements.logo.files && form.elements.logo.files[0];
			if (!error && logo) {
				const data = new FormData();
				data.append('file', logo);
				const up = await fetchLNKupJson('/organizer/profile/logo', { method: 'POST', body: data });
				error = up.success ? '' : up.error;
			}
			button.disabled = false;
			msg.textContent = error ? "We couldn't save your organization. " + error : 'Saved.';
			msg.classList.toggle('is-error', !!error);
			if (!error) {
				refreshProgress();
			}
		});
	}

	// ------------------------------------------------------------------ Copy buttons.

	root.querySelectorAll('[data-copy]').forEach((button) => {
		button.addEventListener('click', async () => {
			const source = button.parentNode.querySelector('[data-copy-source]');
			if (!source) {
				return;
			}
			try {
				await navigator.clipboard.writeText(source.value);
			} catch (e) {
				source.select();
				document.execCommand('copy');
			}
			const label = button.textContent;
			button.textContent = 'Copied';
			setTimeout(() => { button.textContent = label; }, 1800);
		});
	});

	// ------------------------------------------------------------------ Saved setups.

	const setupsBox = root.querySelector('[data-start-setups]');

	async function loadSetups() {
		if (!setupsBox) {
			return;
		}
		setupsBox.textContent = 'Loading saved setups…';
		const res = await fetchLNKupJson('/organizer/setups');
		setupsBox.textContent = '';
		if (!res.success) {
			setupsBox.appendChild(el('p', { class: 'lnkup-muted' }, "We couldn't load your saved setups. Try refreshing."));
			return;
		}
		const items = (res.data && res.data.items) || [];
		const events = (res.data && res.data.events) || [];
		setupsBox.appendChild(el('h4', {}, 'Saved event setups'));
		if (!items.length) {
			setupsBox.appendChild(el('p', { class: 'lnkup-muted' }, 'Save an event’s booth types, prices and requirements to reuse them for your next event.'));
		} else {
			const list = el('ul', { class: 'lnkup-start__setup-list' });
			items.forEach((s) => {
				const li = el('li');
				li.appendChild(el('span', {}, s.name + ' · ' + s.slot_count + ' booth type' + (s.slot_count === 1 ? '' : 's')));
				li.appendChild(el('a', { class: 'lnkup-btn', href: s.create_url }, 'Use for a new event'));
				li.appendChild(el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost', onclick: async () => {
					if (!window.confirm('Delete this saved setup?')) {
						return;
					}
					const del = await fetchLNKupJson('/organizer/setups/' + encodeURIComponent(s.id), { method: 'DELETE' });
					if (del.success) {
						loadSetups();
					}
				} }, 'Delete'));
				list.appendChild(li);
			});
			setupsBox.appendChild(list);
		}
		if (events.length) {
			const select = el('select', { 'aria-label': 'Event to save as a setup' });
			events.forEach((e) => select.appendChild(el('option', { value: e.id }, e.title)));
			const name = el('input', { type: 'text', placeholder: 'Setup name (e.g. Monthly market)', 'aria-label': 'Setup name' });
			const msg = el('span', { class: 'lnkup-start__msg', role: 'status' });
			const save = el('button', { type: 'button', class: 'lnkup-btn', onclick: async () => {
				save.disabled = true;
				const r = await fetchLNKupJson('/organizer/setups', { method: 'POST', body: { event_id: Number(select.value), name: name.value } });
				save.disabled = false;
				if (!r.success) {
					msg.textContent = r.error;
					return;
				}
				loadSetups();
			} }, 'Save as setup');
			const row = el('div', { class: 'lnkup-start__actions' });
			row.append(select, name, save, msg);
			setupsBox.appendChild(row);
		}
	}

	loadSetups();
})();
