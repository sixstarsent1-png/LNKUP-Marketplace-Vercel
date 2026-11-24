/**
 * LNKup list embeds for organizer websites.
 *
 *   <div data-lnkup-list="event:123" data-lnkup-link="abc123"></div>   open vendor spots for an event
 *   <div data-lnkup-list="tour:45"></div>                               upcoming tour stops
 *   <script src="https://YOUR-LNKUP-SITE/wp-content/plugins/lnkup-marketplace/public/js/embed-list.js" async></script>
 *
 * Uses public LNKup endpoints only (no cookies, no iframe, no form on the host page). Links open
 * LNKup in a new tab; data-lnkup-link adds the organizer's tracked link code to the apply URLs.
 */
(function () {
	'use strict';

	const script = document.currentScript || Array.from(document.scripts).find((s) => /\/public\/js\/embed-list\.js(\?|$)/.test(s.src));
	if (!script || !script.src) {
		return;
	}
	const site = script.src.split('/wp-content/')[0];
	const font = '14px/1.4 -apple-system,Segoe UI,Roboto,Arial,sans-serif';

	function el(tag, value, style) {
		const node = document.createElement(tag);
		if (value) {
			node.textContent = value;
		}
		if (style) {
			node.setAttribute('style', style);
		}
		return node;
	}

	function link(href, label, style) {
		const a = el('a', label, style);
		a.href = href;
		a.target = '_blank';
		a.rel = 'noopener';
		return a;
	}

	function fallback(host) {
		host.textContent = '';
		host.appendChild(link(site + '/opportunities/', 'Find vendor opportunities on LNKup', 'display:inline-block;padding:10px 18px;border-radius:10px;background:#c8f542;color:#0b0b0c;font:700 ' + font + ';text-decoration:none;'));
	}

	function place(item) {
		return [item.date || item.event_date, [item.city, item.state].filter(Boolean).join(', ')].filter(Boolean).join(' · ');
	}

	function render(host, kind, data) {
		host.textContent = '';
		const box = el('div', '', 'box-sizing:border-box;max-width:520px;padding:16px 18px;border:1px solid #e3e3e0;border-radius:16px;background:#fff;color:#0b0b0c;font:' + font + ';');
		const head = kind === 'tour' ? data.tour : data.event;
		box.appendChild(el('div', head ? head.title : '', 'font-weight:800;font-size:16px;'));
		const items = data.items || [];
		if (!items.length) {
			box.appendChild(el('p', kind === 'tour' ? 'No upcoming stops are listed right now.' : 'No vendor spots are open right now.', 'color:#6b6c72;margin:8px 0 0;'));
		}
		const list = el('ul', '', 'list-style:none;margin:10px 0 0;padding:0;display:grid;gap:8px;');
		items.slice(0, 12).forEach((item) => {
			const li = el('li', '', 'display:flex;gap:10px;align-items:center;justify-content:space-between;padding:10px 0;border-top:1px solid #eeeee9;');
			const text = el('div', '');
			text.appendChild(el('div', kind === 'tour' ? item.title : item.title, 'font-weight:700;'));
			const meta = [place(item), item.status_label].filter(Boolean).join(' · ');
			if (meta) {
				text.appendChild(el('div', meta, 'color:#6b6c72;font-size:13px;'));
			}
			li.appendChild(text);
			li.appendChild(link(kind === 'tour' ? item.url : item.apply_url, kind === 'tour' ? 'View' : 'Apply', 'flex:0 0 auto;padding:8px 14px;border-radius:10px;background:#c8f542;color:#0b0b0c;font-weight:700;text-decoration:none;'));
			list.appendChild(li);
		});
		box.appendChild(list);
		box.appendChild(el('div', 'Powered by LNKup', 'margin-top:10px;font-size:11px;color:#8a8b90;'));
		host.appendChild(box);
	}

	function mount(host) {
		const spec = String(host.getAttribute('data-lnkup-list') || '');
		const match = spec.match(/^(event|tour):(\d+)$/);
		if (!match || host.getAttribute('data-lnkup-mounted')) {
			return;
		}
		host.setAttribute('data-lnkup-mounted', '1');
		const code = String(host.getAttribute('data-lnkup-link') || '').replace(/[^A-Za-z0-9]/g, '');
		const path = match[1] === 'event' ? '/wp-json/lnkup/v1/public/embeds/events/' + match[2] + '/opportunities' : '/wp-json/lnkup/v1/public/embeds/tours/' + match[2] + '/stops';
		fetch(site + path + (code ? '?lnk=' + encodeURIComponent(code) : ''), { credentials: 'omit' })
			.then((r) => (r.ok && (r.headers.get('content-type') || '').includes('application/json') ? r.json() : Promise.reject(new Error('unavailable'))))
			.then((json) => (json && json.data ? render(host, match[1], json.data) : fallback(host)))
			.catch(() => fallback(host));
	}

	document.querySelectorAll('[data-lnkup-list]').forEach(mount);
})();
