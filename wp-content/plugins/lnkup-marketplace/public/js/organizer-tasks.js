/**
 * Event tasks (tab "tasks") and My tasks (view "mytasks"): lnkup/v1/events/{id}/tasks,
 * lnkup/v1/tasks, lnkup/v1/tasks/{id}, lnkup/v1/task-templates.
 * The server decides who may edit; the UI only hides controls it reports as unavailable.
 */
(function () {
	'use strict';

	var O = window.LnkupOps;
	if (!O) {
		return;
	}
	var el = O.el;
	var PRIORITY = { high: ['High', 'warn'], normal: ['', ''], low: ['Low', ''] };
	var VIEWS = [['mine', 'Assigned to me'], ['due_soon', 'Due soon'], ['overdue', 'Overdue'], ['all', 'All open'], ['completed', 'Completed']];

	function dueLabel(task) {
		if (!task.due_at) {
			return 'No due date';
		}
		return (task.overdue ? 'Overdue · ' : 'Due ') + O.formatDate(task.due_at);
	}

	/**
	 * One task row with done toggle, details/comments and (when allowed) cancel.
	 */
	function taskRow(task, reload, withEvent) {
		var done = el('input', { type: 'checkbox', 'aria-label': 'Mark "' + task.title + '" done', disabled: !task.can_complete || task.status === 'cancelled' });
		done.checked = task.status === 'done';
		done.addEventListener('change', async function () {
			O.busy(done, true);
			var res = await O.api('/tasks/' + task.id, { method: 'PATCH', body: { status: done.checked ? 'done' : 'open' } });
			O.busy(done, false);
			if (!res.ok) {
				done.checked = !done.checked;
				window.alert(res.error);
				return;
			}
			reload();
		});
		var details = el('div', { class: 'lnkup-ops2__task-details', hidden: true });
		var toggle = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Details', 'aria-expanded': 'false' });
		toggle.addEventListener('click', async function () {
			var open = details.hidden;
			details.hidden = !open;
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (open) {
				O.replace(details, O.loading());
				var res = await O.api('/tasks/' + task.id);
				if (!res.ok) {
					O.replace(details, O.errorCard(res.error));
					return;
				}
				O.replace(details, detailBody(res.data, reload));
			}
		});
		var meta = PRIORITY[task.priority] || ['', ''];
		return el('li', { class: 'lnkup-ops2__task is-' + task.status + (task.overdue ? ' is-overdue' : ''), 'data-task-id': String(task.id) }, [
			el('div', { class: 'lnkup-ops2__task-main' }, [
				done,
				el('div', {}, [
					el('strong', { text: task.title }),
					el('div', { class: 'lnkup-muted' }, [
						withEvent && task.event_title ? task.event_title + ' · ' : '',
						dueLabel(task),
						task.assignee ? ' · ' + task.assignee.name : ' · Unassigned'
					])
				]),
				O.badge(meta[0], meta[1]),
				task.status === 'cancelled' ? O.badge('Cancelled', '') : null,
				toggle
			]),
			details
		]);
	}

	function detailBody(task, reload) {
		var wrap = el('div');
		if (task.description) {
			wrap.appendChild(el('p', { class: 'lnkup-ops2__pre', text: task.description }));
		}
		if (task.completed_by) {
			wrap.appendChild(el('p', { class: 'lnkup-muted', text: 'Done by ' + task.completed_by.name + ' · ' + O.formatDate(task.completed_at) }));
		}
		var comments = el('ul', { class: 'lnkup-ops2__comments' }, (task.comments || []).map(function (c) {
			return el('li', {}, [el('strong', { text: c.author.name }), ' ', el('span', { text: c.body }), el('div', { class: 'lnkup-muted', text: O.formatDate(c.created_at) })]);
		}));
		var input = el('textarea', { rows: '2', maxlength: '2000', placeholder: 'Add a comment' });
		var send = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Comment' });
		send.addEventListener('click', async function () {
			if (!input.value.trim()) {
				input.focus();
				return;
			}
			O.busy(send, true);
			var res = await O.api('/tasks/' + task.id + '/comments', { method: 'POST', body: { body: input.value } });
			O.busy(send, false);
			if (!res.ok) {
				O.flash(wrap, res.error, true);
				return;
			}
			task.comments = res.data.items;
			wrap.replaceWith(detailBody(task, reload));
		});
		wrap.appendChild(comments);
		wrap.appendChild(el('div', { class: 'lnkup-ops2__inline' }, [input, send]));
		if (task.can_edit && task.status !== 'cancelled') {
			var cancel = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Cancel task' });
			cancel.addEventListener('click', async function () {
				if (!window.confirm('Cancel this task?')) {
					return;
				}
				O.busy(cancel, true);
				var res = await O.api('/tasks/' + task.id, { method: 'DELETE' });
				O.busy(cancel, false);
				if (!res.ok) {
					O.flash(wrap, res.error, true);
					return;
				}
				reload();
			});
			wrap.appendChild(cancel);
		}
		return wrap;
	}

	O.register('tasks', function render(root, eventId) {
		async function load() {
			O.replace(root, O.loading());
			var res = await O.api('/events/' + eventId + '/tasks');
			if (!res.ok) {
				O.replace(root, O.errorCard(res.status === 403 ? res.error : O.ERROR_COPY, res.status === 403 ? null : load));
				return;
			}
			var data = res.data;
			var wrap = el('div', { class: 'lnkup-ops2__tasks' });
			if (data.can_edit) {
				wrap.appendChild(createForm(load));
				wrap.appendChild(templatePicker(load));
			}
			var items = data.items || [];
			wrap.appendChild(items.length
				? el('ul', { class: 'lnkup-ops2__task-list' }, items.map(function (task) { return taskRow(task, load, false); }))
				: O.empty(data.can_edit ? 'No tasks yet. Add the first one above, or apply a template.' : 'No tasks for this event yet.'));
			O.replace(root, wrap);
		}

		function createForm(reload) {
			var title = el('input', { type: 'text', required: true, maxlength: '190', placeholder: 'What needs to happen?' });
			var assignee = el('select', {}, [el('option', { value: '', text: 'Unassigned' })]);
			var due = el('input', { type: 'datetime-local' });
			var priority = el('select', {}, [el('option', { value: 'normal', text: 'Normal' }), el('option', { value: 'high', text: 'High' }), el('option', { value: 'low', text: 'Low' })]);
			var add = el('button', { type: 'submit', class: 'lnkup-btn lnkup-btn--primary lnkup-btn--sm', text: 'Add task' });
			var form = el('form', { class: 'lnkup-ops2__form' }, [O.field('Task', title), O.field('Assignee', assignee), O.field('Due', due), O.field('Priority', priority), add]);
			O.api('/events/' + eventId + '/task-assignees').then(function (res) {
				(res.ok ? res.data.items : []).forEach(function (person) {
					assignee.appendChild(el('option', { value: String(person.id), text: person.name }));
				});
			});
			form.addEventListener('submit', async function (event) {
				event.preventDefault();
				O.busy(add, true);
				var res = await O.api('/events/' + eventId + '/tasks', { method: 'POST', body: { title: title.value, assignee_user_id: Number(assignee.value || 0), due_at: due.value ? due.value.replace('T', ' ') + ':00' : '', priority: priority.value } });
				O.busy(add, false);
				if (!res.ok) {
					O.flash(form, res.error, true);
					return;
				}
				reload();
			});
			return form;
		}

		function templatePicker(reload) {
			var box = el('div', { class: 'lnkup-ops2__inline lnkup-ops2__templates', hidden: true });
			O.api('/task-templates').then(function (res) {
				if (!res.ok || !(res.data.items || []).length) {
					return;
				}
				var select = el('select', { 'aria-label': 'Task template' }, res.data.items.map(function (t) { return el('option', { value: String(t.id), text: t.name + ' (' + t.items.length + ' tasks)' }); }));
				var apply = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Apply template' });
				apply.addEventListener('click', async function () {
					O.busy(apply, true);
					var out = await O.api('/events/' + eventId + '/tasks/apply-template', { method: 'POST', body: { template_id: Number(select.value) } });
					O.busy(apply, false);
					if (!out.ok) {
						O.flash(box, out.error, true);
						return;
					}
					reload();
				});
				box.appendChild(select);
				box.appendChild(apply);
				box.hidden = false;
			});
			return box;
		}

		load();
	});

	O.register('mytasks', function render(root) {
		var params = new URLSearchParams(window.location.search);
		var view = params.get('tasks_view') || 'mine';
		var list = el('div');
		var tabs = el('div', { class: 'lnkup-ops2__chips', role: 'group', 'aria-label': 'Task views' });
		var templates = el('section', { class: 'lnkup-ops2__template-manager', hidden: true });
		O.replace(root, el('div', {}, [tabs, list, templates]));

		async function load() {
			O.replace(list, O.loading());
			var res = await O.api('/tasks?view=' + encodeURIComponent(view));
			if (!res.ok) {
				O.replace(list, O.errorCard(res.status === 403 ? res.error : O.ERROR_COPY, res.status === 403 ? null : load));
				return;
			}
			tabs.innerHTML = '';
			VIEWS.forEach(function (pair) {
				var active = pair[0] === view;
				tabs.appendChild(el('button', {
					type: 'button',
					class: 'lnkup-ops2__chip' + (active ? ' is-active' : ''),
					'aria-pressed': active ? 'true' : 'false',
					text: pair[1] + ' (' + (res.data.counts[pair[0]] || 0) + ')',
					onclick: function () {
						view = pair[0];
						var url = new URL(window.location.href);
						url.searchParams.set('tasks_view', view);
						window.history.replaceState({}, '', url.toString());
						load();
					}
				}));
			});
			var items = res.data.items || [];
			O.replace(list, items.length
				? el('ul', { class: 'lnkup-ops2__task-list' }, items.map(function (task) { return taskRow(task, load, true); }))
				: O.empty(view === 'mine' ? 'Nothing assigned to you right now.' : 'No tasks in this view.'));
		}

		async function loadTemplates() {
			var res = await O.api('/task-templates');
			if (!res.ok) {
				return;
			}
			templates.hidden = false;
			var rows = res.data.items || [];
			var name = el('input', { type: 'text', maxlength: '120', required: true, placeholder: 'e.g. Market day prep' });
			var lines = el('textarea', { rows: '5', required: true, placeholder: 'One task per line: title | days before the event\nConfirm vendor map | 14\nSend arrival instructions | 3' });
			var save = el('button', { type: 'submit', class: 'lnkup-btn lnkup-btn--primary lnkup-btn--sm', text: 'Save template' });
			var form = el('form', { class: 'lnkup-ops2__form' }, [O.field('Template name', name), O.field('Tasks', lines), save]);
			form.addEventListener('submit', async function (event) {
				event.preventDefault();
				var items = lines.value.split('\n').map(function (line) {
					var parts = line.split('|');
					return { title: parts[0].trim(), offset_days_before_event: Number((parts[1] || '0').trim()) || 0 };
				}).filter(function (item) { return item.title; });
				O.busy(save, true);
				var out = await O.api('/task-templates', { method: 'POST', body: { name: name.value, items: items } });
				O.busy(save, false);
				if (!out.ok) {
					O.flash(form, out.error, true);
					return;
				}
				loadTemplates();
			});
			O.replace(templates, el('div', {}, [
				el('h2', { class: 'lnkup-panel__title', text: 'Task templates' }),
				el('p', { class: 'lnkup-muted', text: 'Reuse the same prep list for every event. Apply a template from an event\'s Tasks tab; due dates are counted back from the event date.' }),
				rows.length ? el('ul', { class: 'lnkup-ops2__list-plain' }, rows.map(function (t) {
					var del = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', text: 'Delete' });
					del.addEventListener('click', async function () {
						if (!window.confirm('Delete template "' + t.name + '"? Tasks already created stay.')) {
							return;
						}
						await O.api('/task-templates/' + t.id, { method: 'DELETE' });
						loadTemplates();
					});
					return el('li', {}, [el('strong', { text: t.name }), el('span', { class: 'lnkup-muted', text: ' ' + t.items.length + ' tasks' }), del]);
				})) : null,
				form
			]));
		}

		load();
		loadTemplates();
	});
})();
