/**
 * Shared helpers for the organizer event panels (readiness, tasks, documents, my tasks).
 *
 * Modules call LnkupOps.register(panel, render) and the core mounts every
 * [data-ops-panel] element once the DOM is ready. DOM is built with textContent only.
 */
(function () {
	'use strict';

	var cfg = window.lnkupOps || {};
	var ERROR_COPY = "We couldn't load this section. Try refreshing or contact support.";
	var modules = {};

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
		return el('div', { class: 'lnkup-ops2__loading', role: 'status' }, [el('span', { class: 'lnkup-ops2__spinner', 'aria-hidden': 'true' }), el('span', { text: 'Loading…' })]);
	}

	function errorCard(message, retry) {
		return el('div', { class: 'lnkup-empty-state lnkup-empty-state--error', role: 'alert' }, [
			el('p', { text: message || ERROR_COPY }),
			retry ? el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Try again', onclick: retry }) : null
		]);
	}

	function empty(message) {
		return el('div', { class: 'lnkup-empty-state' }, [el('p', { text: message })]);
	}

	function busy(button, on) {
		if (!button) {
			return;
		}
		button.disabled = on;
		button.setAttribute('aria-busy', on ? 'true' : 'false');
	}

	function badge(text, tone) {
		return text ? el('span', { class: 'lnkup-ops2__badge' + (tone ? ' is-' + tone : ''), text: text }) : null;
	}

	function flash(target, message, isError) {
		var existing = target.querySelector(':scope > .lnkup-ops2__flash');
		if (existing) {
			existing.remove();
		}
		target.prepend(el('p', { class: 'lnkup-ops2__flash' + (isError ? ' is-error' : ''), role: isError ? 'alert' : 'status', text: message }));
	}

	function field(label, input) {
		return el('label', { class: 'lnkup-ops2__field' }, [el('span', { text: label }), input]);
	}

	/**
	 * Small dialog; returns a close function. Escape and backdrop click close it.
	 */
	function modal(title, body, footer) {
		var dialog = el('div', { class: 'lnkup-ops2__modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': title });
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
		dialog.appendChild(el('div', { class: 'lnkup-ops2__modal-card' }, [el('h2', { text: title }), body, el('div', { class: 'lnkup-ops2__modal-actions' }, footer(close))]));
		dialog.addEventListener('click', function (event) {
			if (event.target === dialog) {
				close();
			}
		});
		document.body.appendChild(dialog);
		var first = dialog.querySelector('textarea, input, select, button');
		if (first) {
			first.focus();
		}
		return close;
	}

	function formatDate(value) {
		if (!value) {
			return '';
		}
		var d = new Date(String(value).replace(' ', 'T'));
		return isNaN(d.getTime()) ? String(value) : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
	}

	function register(panel, render) {
		modules[panel] = render;
	}

	function mount() {
		document.querySelectorAll('[data-ops-panel]').forEach(function (root) {
			var render = modules[root.getAttribute('data-ops-panel')];
			if (!render || root.getAttribute('data-ops-mounted')) {
				return;
			}
			root.setAttribute('data-ops-mounted', '1');
			render(root, Number(root.getAttribute('data-event-id') || 0));
		});
	}

	window.LnkupOps = {
		cfg: cfg,
		api: api,
		el: el,
		replace: replace,
		loading: loading,
		errorCard: errorCard,
		empty: empty,
		busy: busy,
		badge: badge,
		flash: flash,
		field: field,
		modal: modal,
		formatDate: formatDate,
		register: register,
		ERROR_COPY: ERROR_COPY
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', mount);
	} else {
		setTimeout(mount, 0);
	}
})();
