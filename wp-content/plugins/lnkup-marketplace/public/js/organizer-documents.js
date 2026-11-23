/**
 * Event documents panel (organizer_document_review_enabled): lnkup/v1/events/{id}/documents and
 * /applications/{id}/documents/*. Decisions apply to this event only; files open only through
 * the vendor's Passport share.
 */
(function () {
	'use strict';

	var O = window.LnkupOps;
	if (!O) {
		return;
	}
	var el = O.el;
	var STATUS = {
		missing: ['Missing', 'warn'],
		requested: ['Requested', 'info'],
		submitted: ['Ready for review', 'info'],
		accepted: ['Accepted', 'good'],
		rejected: ['Rejected', 'warn'],
		replacement_requested: ['Replacement requested', 'warn'],
		expired: ['Expired', 'warn'],
		waived: ['Waived', '']
	};
	var NEEDS_REQUEST = ['missing', 'requested', 'expired', 'rejected', 'replacement_requested'];

	O.register('documents', function render(root, eventId) {
		var filter = '';

		async function load() {
			O.replace(root, O.loading());
			var res = await O.api('/events/' + eventId + '/documents' + (filter ? '?status=' + encodeURIComponent(filter) : ''));
			if (!res.ok) {
				O.replace(root, O.errorCard(res.status === 403 ? res.error : O.ERROR_COPY, res.status === 403 ? null : load));
				return;
			}
			draw(res.data);
		}

		function reason(title, label, cb) {
			var text = el('textarea', { rows: '3', maxlength: '1000' });
			var send = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--primary', text: label });
			var close = O.modal(title, O.field('Message to the vendor', text), function (closeFn) {
				return [el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost', text: 'Cancel', onclick: closeFn }), send];
			});
			send.addEventListener('click', function () {
				if (text.value.trim().length < 3) {
					text.focus();
					return;
				}
				close();
				cb(text.value);
			});
		}

		async function post(appId, path, body, button) {
			O.busy(button, true);
			var res = await O.api('/applications/' + appId + '/documents' + path, { method: 'POST', body: body });
			O.busy(button, false);
			if (!res.ok) {
				O.flash(root, res.error, true);
				return;
			}
			load();
		}

		function draw(data) {
			var wrap = el('div', { class: 'lnkup-ops2__documents' });
			var counts = data.counts || {};
			var chips = el('div', { class: 'lnkup-ops2__chips', role: 'group', 'aria-label': 'Filter documents' });
			[['', 'All']].concat(Object.keys(STATUS).map(function (key) { return [key, STATUS[key][0]]; })).forEach(function (pair) {
				var count = pair[0] ? counts[pair[0]] || 0 : null;
				if (pair[0] && !count && filter !== pair[0]) {
					return;
				}
				chips.appendChild(el('button', {
					type: 'button',
					class: 'lnkup-ops2__chip' + (filter === pair[0] ? ' is-active' : ''),
					'aria-pressed': filter === pair[0] ? 'true' : 'false',
					text: pair[1] + (count !== null ? ' (' + count + ')' : ''),
					onclick: function () { filter = pair[0]; load(); }
				}));
			});
			wrap.appendChild(chips);
			if (!data.is_owner) {
				wrap.appendChild(el('p', { class: 'lnkup-muted', text: 'Vendors share files with the event owner. You can review status here; the owner can open shared files.' }));
			}
			var items = data.items || [];
			if (!items.length) {
				wrap.appendChild(O.empty(filter ? 'No documents with this status.' : 'No required documents for approved vendors yet. Required documents are set on each vendor slot.'));
				O.replace(root, wrap);
				return;
			}
			var table = el('table', { class: 'lnkup-ops2__table' }, [el('thead', {}, [el('tr', {}, [
				el('th', { scope: 'col', text: 'Vendor' }),
				el('th', { scope: 'col', text: 'Document' }),
				el('th', { scope: 'col', text: 'Status' }),
				el('th', { scope: 'col', text: 'Actions' })
			])])]);
			var body = el('tbody');
			items.forEach(function (row) {
				var meta = STATUS[row.status] || [row.status, ''];
				var actions = el('div', { class: 'lnkup-ops2__inline' });
				if (row.download_url) {
					actions.appendChild(el('a', { class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', href: row.download_url, target: '_blank', rel: 'noopener', text: 'Open file' }));
				}
				if (data.can_review) {
					if (row.document_id && ['submitted', 'rejected', 'replacement_requested'].indexOf(row.status) !== -1) {
						var accept = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--primary lnkup-btn--sm', text: 'Accept' });
						accept.addEventListener('click', function () { post(row.application_id, '/' + row.type + '/review', { decision: 'accept' }, accept); });
						actions.appendChild(accept);
					}
					if (row.document_id && ['submitted', 'accepted'].indexOf(row.status) !== -1) {
						actions.appendChild(el('button', {
							type: 'button',
							class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm',
							text: 'Needs changes…',
							onclick: function () {
								reason(row.label + ' for ' + row.vendor_name, 'Ask for a new version', function (text) {
									post(row.application_id, '/' + row.type + '/review', { decision: 'replacement', reason: text }, null);
								});
							}
						}));
					}
					if (NEEDS_REQUEST.indexOf(row.status) !== -1) {
						var ask = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: row.status === 'requested' ? 'Ask again' : 'Request' });
						ask.addEventListener('click', function () { post(row.application_id, '/request', { types: [row.type] }, ask); });
						actions.appendChild(ask);
					}
				}
				if (data.can_waive && row.status !== 'waived' && row.status !== 'accepted') {
					actions.appendChild(el('button', {
						type: 'button',
						class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm',
						text: 'Waive…',
						onclick: function () {
							reason('Waive ' + row.label + ' for ' + row.vendor_name, 'Waive requirement', function (text) {
								post(row.application_id, '/' + row.type + '/waive', { reason: text }, null);
							});
						}
					}));
				}
				body.appendChild(el('tr', { 'data-app-id': String(row.application_id), 'data-type': row.type }, [
					el('td', { text: row.vendor_name || ('Application #' + row.application_id) }),
					el('td', {}, [el('div', { text: row.label }), row.expires_on ? el('div', { class: 'lnkup-muted', text: 'Expires ' + row.expires_on }) : null]),
					el('td', {}, [O.badge(meta[0], meta[1]), row.reason ? el('div', { class: 'lnkup-muted', text: row.reason }) : null, row.document_id && !row.shared ? el('div', { class: 'lnkup-muted', text: 'Not shared with you yet' }) : null]),
					el('td', {}, [actions])
				]));
			});
			table.appendChild(body);
			wrap.appendChild(el('div', { class: 'lnkup-ops2__table-wrap' }, [table]));
			wrap.appendChild(el('p', { class: 'lnkup-muted', text: 'Your decisions apply to this event only. LNKup\'s own document verification is not changed.' }));
			O.replace(root, wrap);
		}

		load();
	});
})();
