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
			['public_name', 'city', 'state', 'website', 'bio', 'instagram', 'tiktok', 'service_cities', 'category'].forEach((k) => {
				if (form.elements[k]) {
					values[k] = form.elements[k].value || '';
				}
			});
			const saved = await fetchLNKupJson('/organizer/profile', { method: 'POST', body: values });
			let error = saved.success ? '' : saved.error;
			for (const [field, path] of [['logo', '/organizer/profile/logo'], ['cover', '/organizer/profile/cover']]) {
				const file = form.elements[field] && form.elements[field].files && form.elements[field].files[0];
				if (!error && file) {
					const data = new FormData();
					data.append('file', file);
					const up = await fetchLNKupJson(path, { method: 'POST', body: data });
					error = up.success ? '' : up.error;
				}
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

	// ------------------------------------------------------------------ Guided setup (v2).

	if (root.dataset.onboardingV2 !== '1') {
		return;
	}

	function saveOnboarding(changes) {
		return fetchLNKupJson('/organizer/onboarding', { method: 'PATCH', body: changes });
	}

	function applyFlow(flow) {
		if (!flow || !flow.steps) {
			return;
		}
		flow.steps.forEach((step) => {
			if (step.key === 'first_event' && step.status !== 'complete') {
				const li = root.querySelector('[data-stage="first_event"]');
				const link = li && li.querySelector('.lnkup-start__actions a.lnkup-btn');
				const title = li && li.querySelector('h3');
				if (link && step.cta_url) {
					link.href = step.cta_url;
				}
				if (title && step.title) {
					title.textContent = step.title;
				}
			}
			if (step.key === 'format') {
				const li = root.querySelector('[data-stage="format"]');
				const badge = li && li.querySelector('.lnkup-start__status');
				if (li) {
					li.className = 'lnkup-start__stage is-' + (step.status === 'complete' ? 'complete' : 'not_started');
				}
				if (badge) {
					badge.textContent = step.status === 'complete' ? 'Done' : step.skipped ? 'Skipped' : 'To do';
				}
			}
		});
	}

	const formatsForm = root.querySelector('[data-start-formats]');
	if (formatsForm) {
		formatsForm.addEventListener('change', (event) => {
			const label = event.target.closest('.lnkup-start__format');
			if (label) {
				label.classList.toggle('is-selected', event.target.checked);
			}
		});
		formatsForm.addEventListener('submit', async (event) => {
			event.preventDefault();
			const msg = formatsForm.querySelector('.lnkup-start__msg');
			const formats = Array.from(formatsForm.querySelectorAll('input[name="formats"]:checked')).map((input) => input.value);
			if (!formats.length) {
				msg.textContent = 'Pick at least one, or skip for now.';
				msg.classList.add('is-error');
				return;
			}
			const button = formatsForm.querySelector('button[type="submit"]');
			button.disabled = true;
			msg.textContent = 'Saving…';
			const res = await saveOnboarding({ formats: formats, current_step: 'first_event' });
			button.disabled = false;
			msg.classList.toggle('is-error', !res.success);
			if (!res.success) {
				msg.textContent = "We couldn't save that. " + res.error;
				return;
			}
			msg.textContent = 'Saved. Your next step is ready below.';
			applyFlow(res.data && res.data.data);
			const next = root.querySelector('[data-stage="first_event"]');
			if (next) {
				next.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		});
	}

	root.querySelectorAll('[data-start-skip]').forEach((button) => {
		button.addEventListener('click', async () => {
			const msg = button.parentNode.querySelector('.lnkup-start__msg');
			button.disabled = true;
			const res = await saveOnboarding({ skip: button.getAttribute('data-start-skip') });
			button.disabled = false;
			if (msg) {
				msg.textContent = res.success ? 'Okay — you can come back to this anytime.' : "We couldn't save that. " + res.error;
				msg.classList.toggle('is-error', !res.success);
			}
			if (res.success) {
				applyFlow(res.data && res.data.data);
			}
		});
	});

	const payoutButton = root.querySelector('[data-start-payout]');
	if (payoutButton) {
		payoutButton.addEventListener('click', async () => {
			const msg = payoutButton.parentNode.querySelector('.lnkup-start__msg');
			const label = payoutButton.textContent;
			payoutButton.disabled = true;
			payoutButton.textContent = 'Opening payout setup…';
			const res = await fetchLNKupJson('/financial/connect/onboarding-link', { method: 'POST', body: {} });
			if (res.success && res.data && res.data.url) {
				window.location.href = res.data.url;
				return;
			}
			payoutButton.disabled = false;
			payoutButton.textContent = label;
			if (msg) {
				msg.textContent = (res.data && res.data.message) || "We couldn't open payout setup. Try again from Money.";
				msg.classList.add('is-error');
			}
		});
	}

	const focus = root.dataset.focusStep;
	if (focus) {
		const target = root.querySelector('[data-stage="' + (focus === 'share' ? 'publication' : focus) + '"]');
		if (target) {
			target.classList.add('is-focused');
			target.scrollIntoView({ block: 'start' });
			const field = target.querySelector('input:not([type="hidden"]), select, textarea, button');
			if (field) {
				field.focus({ preventScroll: true });
			}
		}
		saveOnboarding({ current_step: focus });
	}
})();
