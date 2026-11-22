/**
 * Event readiness panel: GET/POST lnkup/v1/events/{id}/readiness.
 * "Verified" comes from LNKup's own records; "Confirmed" is a person's confirmation where the
 * system cannot decide; a failing system check stays incomplete no matter what.
 */
(function () {
	'use strict';

	var O = window.LnkupOps;
	if (!O) {
		return;
	}
	var el = O.el;
	var STATE = {
		verified: ['Verified', 'good'],
		confirmed: ['Confirmed', 'info'],
		incomplete: ['Incomplete', 'warn'],
		not_applicable: ['Not applicable', '']
	};

	function countdown(days) {
		if (days === null || days === undefined) {
			return 'No event date set';
		}
		if (days < 0) {
			return 'Event has passed';
		}
		if (days === 0) {
			return 'Event is today';
		}
		return days === 1 ? '1 day to go' : days + ' days to go';
	}

	O.register('readiness', function render(root, eventId) {
		async function load() {
			O.replace(root, O.loading());
			var res = await O.api('/events/' + eventId + '/readiness');
			if (!res.ok) {
				O.replace(root, O.errorCard(res.status === 403 ? res.error : O.ERROR_COPY, res.status === 403 ? null : load));
				return;
			}
			draw(res.data);
		}

		async function mark(key, state, button) {
			var note = '';
			if (state === 'confirmed' || state === 'not_applicable') {
				note = window.prompt(state === 'confirmed' ? 'Optional note: how was this handled?' : 'Optional note: why does this not apply?') || '';
			}
			O.busy(button, true);
			var res = await O.api('/events/' + eventId + '/readiness/' + key, { method: 'POST', body: { state: state, note: note } });
			O.busy(button, false);
			if (!res.ok) {
				O.flash(root, res.error, true);
				return;
			}
			res.data.can_edit = true;
			draw(res.data);
		}

		function draw(data) {
			var wrap = el('div', { class: 'lnkup-ops2__readiness' });
			wrap.appendChild(el('div', { class: 'lnkup-ops2__summary' }, [
				el('div', {}, [el('strong', { class: 'lnkup-ops2__big', text: data.percent + '%' }), el('span', { class: 'lnkup-muted', text: ' ready' })]),
				el('div', { class: 'lnkup-ops2__meter', role: 'progressbar', 'aria-label': 'Event readiness', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(data.percent) }, [el('span', { style: 'width:' + data.percent + '%' })]),
				el('span', { class: 'lnkup-muted', text: countdown(data.days_until) }),
				data.tasks && data.tasks.open ? el('span', { class: 'lnkup-muted', text: data.tasks.open + ' open tasks' + (data.tasks.overdue ? ', ' + data.tasks.overdue + ' overdue' : '') }) : null
			]));
			if ((data.next_actions || []).length) {
				wrap.appendChild(el('div', { class: 'lnkup-ops2__next' }, [el('h3', { text: 'Next steps' }), el('ol', {}, data.next_actions.map(function (item) {
					return el('li', {}, [el('a', { href: item.action_url, text: item.label }), el('span', { class: 'lnkup-muted', text: ' — ' + item.evidence })]);
				}))]));
			}
			var list = el('ul', { class: 'lnkup-ops2__checklist' });
			(data.items || []).forEach(function (item) {
				var meta = STATE[item.state] || [item.state, ''];
				var controls = el('div', { class: 'lnkup-ops2__inline' });
				if (data.can_edit) {
					if (item.manual_allowed && item.state !== 'confirmed') {
						var confirmBtn = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Confirm' });
						confirmBtn.addEventListener('click', function () { mark(item.key, 'confirmed', confirmBtn); });
						controls.appendChild(confirmBtn);
					}
					if (item.na_allowed && item.state !== 'not_applicable' && item.state !== 'verified') {
						var naBtn = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Not applicable' });
						naBtn.addEventListener('click', function () { mark(item.key, 'not_applicable', naBtn); });
						controls.appendChild(naBtn);
					}
					if (item.mark) {
						var clear = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Undo' });
						clear.addEventListener('click', function () { mark(item.key, 'clear', clear); });
						controls.appendChild(clear);
					}
				}
				list.appendChild(el('li', { class: 'lnkup-ops2__check is-' + item.state, 'data-item': item.key }, [
					el('div', { class: 'lnkup-ops2__check-head' }, [el('strong', { text: item.label }), O.badge(meta[0], meta[1])]),
					el('p', { class: 'lnkup-muted', text: item.evidence }),
					item.mark ? el('p', { class: 'lnkup-ops2__mark', text: (item.mark.state === 'confirmed' ? 'Confirmed by ' : 'Marked not applicable by ') + item.mark.by + (item.mark.note ? ': ' + item.mark.note : '') + (item.system === 'fail' && item.mark.state === 'confirmed' ? ' (LNKup still sees open items above)' : '') }) : null,
					el('div', { class: 'lnkup-ops2__inline' }, [item.action_url ? el('a', { href: item.action_url, class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Open' }) : null, controls])
				]));
			});
			wrap.appendChild(list);
			wrap.appendChild(el('p', { class: 'lnkup-muted', text: 'Verified items are checked from your event records. Confirmed items were confirmed by a person because LNKup cannot check them.' }));
			O.replace(root, wrap);
		}

		load();
	});
})();
