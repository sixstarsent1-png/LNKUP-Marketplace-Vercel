/**
 * LNKup "Apply" embed for organizer websites.
 *
 *   <div data-lnkup-apply="123"></div>
 *   <script src="https://YOUR-LNKUP-SITE/wp-content/plugins/lnkup-marketplace/public/js/embed.js" async></script>
 *
 * Renders a small branded card with an Apply button that opens LNKup in a new tab. Uses only
 * the public card endpoint; no iframe, no cookies, no form on the host page.
 */
(function () {
	'use strict';

	const script = document.currentScript || Array.from(document.scripts).find((s) => /\/public\/js\/embed\.js(\?|$)/.test(s.src));
	if (!script || !script.src) {
		return;
	}
	const site = script.src.split('/wp-content/')[0];

	function text(tag, value, style) {
		const el = document.createElement(tag);
		el.textContent = value;
		if (style) {
			el.setAttribute('style', style);
		}
		return el;
	}

	function fallback(host, id) {
		host.textContent = '';
		const a = text('a', 'Apply as a vendor on LNKup', 'display:inline-block;padding:10px 18px;border-radius:10px;background:#c8f542;color:#0b0b0c;font:700 14px/1.2 -apple-system,Segoe UI,Roboto,Arial,sans-serif;text-decoration:none;');
		a.href = site + '/opportunities/?apply=' + encodeURIComponent(id);
		a.target = '_blank';
		a.rel = 'noopener';
		host.appendChild(a);
	}

	function money(n) {
		if (n === null || n === undefined) {
			return '';
		}
		return Number(n) === 0 ? 'Free booth' : '$' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
	}

	function render(host, card) {
		host.textContent = '';
		const box = document.createElement('div');
		box.setAttribute('style', 'box-sizing:border-box;max-width:420px;padding:16px 18px;border:1px solid #e3e3e0;border-radius:16px;background:#fff;color:#0b0b0c;font:14px/1.4 -apple-system,Segoe UI,Roboto,Arial,sans-serif;');
		box.appendChild(text('div', card.event_title || '', 'font-weight:800;font-size:16px;'));
		if (card.title && card.title !== card.event_title) {
			box.appendChild(text('div', card.title, 'color:#3a3b40;'));
		}
		const meta = [card.event_date, [card.city, card.state].filter(Boolean).join(', '), money(card.vendor_fee), card.spots_left > 0 ? card.spots_left + ' spots left' : '', card.status_label || ''].filter(Boolean).join(' · ');
		if (meta) {
			box.appendChild(text('div', meta, 'color:#6b6c72;margin-top:4px;'));
		}
		if (card.deadline) {
			box.appendChild(text('div', 'Apply by ' + card.deadline, 'color:#6b6c72;'));
		}
		const a = text('a', 'Apply as a vendor', 'display:inline-block;margin-top:12px;padding:10px 18px;border-radius:10px;background:#c8f542;color:#0b0b0c;font-weight:700;text-decoration:none;');
		a.href = card.apply_url;
		a.target = '_blank';
		a.rel = 'noopener';
		box.appendChild(a);
		box.appendChild(text('div', 'Powered by LNKup' + (card.organizer_name ? ' · ' + card.organizer_name : ''), 'margin-top:10px;font-size:11px;color:#8a8b90;'));
		host.appendChild(box);
	}

	function mount(host) {
		const id = parseInt(host.getAttribute('data-lnkup-apply'), 10);
		if (!id || host.getAttribute('data-lnkup-mounted')) {
			return;
		}
		host.setAttribute('data-lnkup-mounted', '1');
		fetch(site + '/wp-json/lnkup/v1/public/opportunities/' + id + '/card', { credentials: 'omit' })
			.then((r) => (r.ok && (r.headers.get('content-type') || '').includes('application/json') ? r.json() : Promise.reject(new Error('unavailable'))))
			.then((json) => (json && json.data ? render(host, json.data) : fallback(host, id)))
			.catch(() => fallback(host, id));
	}

	document.querySelectorAll('[data-lnkup-apply]').forEach(mount);
})();
