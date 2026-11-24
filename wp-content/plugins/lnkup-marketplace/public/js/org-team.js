/**
 * Organizer dashboard → Team & Trust.
 * Organization verification (lnkup/v1/organizer/verification*) and team access (lnkup/v1/org/*).
 * DOM is built with textContent only; server strings are never injected as HTML.
 */
(function () {
	'use strict';

	var cfg = window.lnkupOrgTeam || {};
	var verifyRoot = document.getElementById('lnkup-org-verify');
	var teamRoot = document.getElementById('lnkup-org-team-list');
	if (!verifyRoot || !teamRoot || !cfg.root) {
		return;
	}

	var ERROR_COPY = "We couldn't load this section. Try refreshing or contact support.";

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
			if (key === 'text') {
				node.textContent = attrs[key];
			} else if (key === 'class') {
				node.className = attrs[key];
			} else if (key.indexOf('on') === 0) {
				node.addEventListener(key.slice(2), attrs[key]);
			} else if (attrs[key] !== null && attrs[key] !== undefined && attrs[key] !== false) {
				node.setAttribute(key, attrs[key] === true ? '' : attrs[key]);
			}
		});
		(children || []).forEach(function (child) {
			if (child) {
				node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
			}
		});
		return node;
	}

	function replace(root, node) {
		root.innerHTML = '';
		root.appendChild(node);
	}

	function errorCard(message, retry) {
		return el('div', { class: 'lnkup-empty-state lnkup-empty-state--error', role: 'alert' }, [
			el('p', { text: message || ERROR_COPY }),
			retry ? el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Try again', onclick: retry }) : null
		]);
	}

	function flash(root, message, isError) {
		var existing = root.querySelector('.lnkup-org-team__flash');
		if (existing) {
			existing.remove();
		}
		root.prepend(el('p', { class: 'lnkup-org-team__flash' + (isError ? ' is-error' : ''), role: isError ? 'alert' : 'status', text: message }));
	}

	function field(label, input) {
		return el('label', { class: 'lnkup-org-team__field' }, [el('span', { text: label }), input]);
	}

	function busy(button, on) {
		button.disabled = on;
		button.setAttribute('aria-busy', on ? 'true' : 'false');
	}

	/* ---------- verification ---------- */

	async function loadVerification() {
		var res = await api('/organizer/verification');
		if (!res.ok && res.status === 403) {
			var section = verifyRoot.closest('section');
			if (section) {
				section.hidden = true;
			}
			return;
		}
		if (!res.ok) {
			replace(verifyRoot, errorCard(res.error, loadVerification));
			return;
		}
		renderVerification(res.data.indicators || {});
	}

	/**
	 * Put a form into its drawer and open it; without the drawer shell, show the form inline.
	 */
	function openInDrawer(drawerId, node, trigger, inlineWrap) {
		var drawer = document.getElementById(drawerId);
		var body = drawer && drawer.querySelector('[data-team-drawer-body]');
		var os = window.LNKupOS;
		if (!drawer || !body || !os || !os.open) {
			inlineWrap.appendChild(node);
			return;
		}
		var heading = node.querySelector('h3');
		if (heading) {
			heading.remove();
		}
		replace(body, node);
		os.open(drawer, trigger);
	}

	function closeDrawers() {
		['oos-team-domain-drawer', 'oos-team-review-drawer'].forEach(function (id) {
			var drawer = document.getElementById(id);
			if (drawer && !drawer.hidden && window.LNKupOS) {
				window.LNKupOS.close(drawer, true);
			}
		});
	}

	function statusRow(label, done, detail, action) {
		return el('li', { class: 'lnkup-org-team__indicator oos-verify-row' + (done ? ' is-done' : '') }, [
			el('span', { class: 'oos-verify-row__icon', 'aria-hidden': 'true', text: done ? '✓' : '!' }),
			el('span', { class: 'oos-verify-row__text' }, [el('strong', { text: label }), el('span', { class: 'lnkup-muted', text: detail })]),
			action || null
		]);
	}

	function renderVerification(ind) {
		closeDrawers();
		var wrap = el('div');
		var canRequest = ['not_started', 'info_required', 'declined', 'reverification_required'].indexOf(ind.status) !== -1;
		var domainBtn = null;
		var reviewBtn = null;
		if (!ind.domain_verified) {
			domainBtn = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: ind.domain_pending ? 'Continue' : 'Verify domain', 'data-testid': 'lnkup-team-verify-domain' });
			domainBtn.addEventListener('click', function () { openInDrawer('oos-team-domain-drawer', domainForm(ind.domain_pending), domainBtn, wrap); });
		}
		if (canRequest) {
			reviewBtn = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Request review', 'data-testid': 'lnkup-team-request-review' });
			reviewBtn.addEventListener('click', function () { openInDrawer('oos-team-review-drawer', requestForm(ind), reviewBtn, wrap); });
		}
		wrap.appendChild(el('ul', { class: 'lnkup-org-team__indicators oos-verify-rows' }, [
			statusRow('Payouts', !!ind.payout_connected, ind.payout_connected ? 'Payouts active' : 'Finish payout setup in Money', ind.payout_connected ? null : el('a', { class: 'lnkup-btn lnkup-btn--ghost lnkup-btn--sm', href: (cfg.moneyUrl || '?view=financial'), text: 'Open Money' })),
			statusRow('Website domain', !!ind.domain_verified, ind.domain_verified ? 'You control ' + ind.verified_domain : (ind.domain_pending ? 'Waiting for your confirmation' : 'Not verified yet'), domainBtn),
			statusRow('LNKup review', !!ind.identity_reviewed, ind.status === 'pending_review' ? 'In review. We will email you when it is done.' : (ind.status_label || 'Not started'), reviewBtn)
		]));
		wrap.appendChild(el('p', { class: 'lnkup-muted', text: 'Verification shows vendors what LNKup checked. It is not a guarantee that an event will take place.' }));
		replace(verifyRoot, wrap);
	}

	function domainForm(pending) {
		var box = el('div', { class: 'lnkup-org-team__sub' }, [el('h3', { text: 'Verify your website domain' })]);
		if (pending && pending.method === 'dns' && pending.record) {
			box.appendChild(el('p', { text: 'Add this TXT record at your domain provider, then select Check.' }));
			box.appendChild(el('pre', { class: 'lnkup-org-team__record', text: pending.record.type + '  ' + pending.record.host + '  "' + pending.record.value + '"' }));
			var check = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--primary lnkup-btn--sm', text: 'Check' });
			check.addEventListener('click', async function () {
				busy(check, true);
				var res = await api('/organizer/verification/domain/confirm', { method: 'POST', body: {} });
				busy(check, false);
				if (!res.ok) {
					flash(box, res.error, true);
					return;
				}
				renderVerification(res.data.indicators);
			});
			box.appendChild(check);
			return box;
		}
		if (pending && pending.method === 'email') {
			var code = el('input', { type: 'text', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '6', required: true });
			var confirm = el('button', { type: 'submit', class: 'lnkup-btn lnkup-btn--primary lnkup-btn--sm', text: 'Confirm' });
			var codeForm = el('form', { class: 'lnkup-org-team__form' }, [
				el('p', { text: 'We sent a 6-digit code to ' + pending.sent_to + '.' }),
				field('Code', code),
				confirm
			]);
			codeForm.addEventListener('submit', async function (event) {
				event.preventDefault();
				busy(confirm, true);
				var res = await api('/organizer/verification/domain/confirm', { method: 'POST', body: { code: code.value } });
				busy(confirm, false);
				if (!res.ok) {
					flash(codeForm, res.error, true);
					return;
				}
				renderVerification(res.data.indicators);
			});
			box.appendChild(codeForm);
			return box;
		}

		var domain = el('input', { type: 'text', placeholder: 'myfestival.com', required: true });
		var method = el('select', {}, [
			el('option', { value: 'email', text: 'Email a code to an address at this domain' }),
			el('option', { value: 'dns', text: 'Add a DNS TXT record' })
		]);
		var email = el('input', { type: 'email', placeholder: 'events@myfestival.com' });
		var emailField = field('Email at that domain', email);
		method.addEventListener('change', function () {
			emailField.hidden = method.value !== 'email';
		});
		var start = el('button', { type: 'submit', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Start' });
		var form = el('form', { class: 'lnkup-org-team__form' }, [field('Domain', domain), field('Method', method), emailField, start]);
		form.addEventListener('submit', async function (event) {
			event.preventDefault();
			busy(start, true);
			var res = await api('/organizer/verification/domain/start', { method: 'POST', body: { domain: domain.value, method: method.value, email: email.value } });
			busy(start, false);
			if (!res.ok) {
				flash(form, res.error, true);
				return;
			}
			loadVerification();
		});
		box.appendChild(form);
		return box;
	}

	function requestForm(ind) {
		var prior = ind.request || {};
		var name = el('input', { type: 'text', required: true, maxlength: '190', value: prior.organization_name || '' });
		var site = el('input', { type: 'url', placeholder: 'https://', value: prior.website || '' });
		var role = el('input', { type: 'text', maxlength: '120', placeholder: 'Founder, Director, Vendor Coordinator…', value: prior.role_at_org || '' });
		var notes = el('textarea', { rows: '3', maxlength: '2000' });
		notes.value = prior.notes || '';
		var submit = el('button', { type: 'submit', class: 'lnkup-btn lnkup-btn--primary lnkup-btn--sm', text: 'Request LNKup review' });
		var form = el('form', { class: 'lnkup-org-team__form lnkup-org-team__sub' }, [
			el('h3', { text: 'Request organization review' }),
			el('p', { class: 'lnkup-muted', text: 'No ID documents needed. We check your website, domain and public presence.' }),
			field('Organization name', name),
			field('Website', site),
			field('Your role', role),
			field('Anything we should know (optional)', notes),
			submit
		]);
		form.addEventListener('submit', async function (event) {
			event.preventDefault();
			busy(submit, true);
			var res = await api('/organizer/verification/request', { method: 'POST', body: { organization_name: name.value, website: site.value, role_at_org: role.value, notes: notes.value } });
			busy(submit, false);
			if (!res.ok) {
				flash(form, res.error, true);
				return;
			}
			renderVerification(res.data.indicators);
		});
		return form;
	}

	/* ---------- team ---------- */

	async function loadTeam() {
		var res = await api('/org/team');
		if (!res.ok && res.status === 403) {
			loadMemberships();
			return;
		}
		if (!res.ok) {
			replace(teamRoot, errorCard(res.error, loadTeam));
			return;
		}
		renderTeam(res.data);
		loadActivity(res.data.owner_id);
	}

	/**
	 * People who are on someone else's team (not an owner) see where they have access.
	 */
	async function loadMemberships() {
		var res = await api('/org/memberships');
		if (!res.ok) {
			replace(teamRoot, errorCard(res.error, loadMemberships));
			return;
		}
		var items = res.data.items || [];
		if (!items.length) {
			replace(teamRoot, el('div', { class: 'lnkup-empty-state' }, [el('p', { text: 'You are not on an organizer team yet. Ask the organizer to send you an invite.' })]));
			return;
		}
		var list = el('ul', { class: 'lnkup-org-team__members' });
		items.forEach(function (item) {
			list.appendChild(el('li', { class: 'lnkup-org-team__member' }, [
				el('strong', { text: item.org_name }),
				el('span', { class: 'lnkup-badge', text: item.role_label }),
				el('span', { class: 'lnkup-muted', text: item.scope_type === 'all' ? 'All events' : (item.scope_type === 'tour' ? 'One tour' : 'One event') })
			]));
		});
		replace(teamRoot, el('div', {}, [el('p', { class: 'lnkup-muted', text: 'Teams you belong to' }), list]));
	}

	var ACTION_LABELS = {
		org_member_invited: 'Invited a team member',
		org_invite_accepted: 'Accepted a team invite',
		org_member_added: 'Added a team member',
		org_member_changed: 'Changed team access',
		org_member_revoked: 'Removed a team member',
		application_reviewer_assigned: 'Assigned a reviewer',
		application_reviewer_removed: 'Removed a reviewer',
		application_scored: 'Scored an application',
		application_bulk_decision: 'Bulk application decision',
		review_rubric_saved: 'Updated the review rubric',
		waitlist_offer_created: 'Offered a spot to a waitlisted vendor',
		waitlist_offer_expired: 'Waitlist offer expired',
		waitlist_offer_revoked: 'Withdrew a waitlist offer'
	};

	async function loadActivity(ownerId) {
		var root = document.getElementById('lnkup-org-team-activity');
		if (!root) {
			return;
		}
		var section = root.closest('section');
		var res = await api('/org/team/audit?per_page=25&owner_id=' + encodeURIComponent(ownerId || ''));
		if (!res.ok) {
			if (res.status === 403 && section) {
				section.hidden = true;
				return;
			}
			replace(root, errorCard(res.error, function () { loadActivity(ownerId); }));
			return;
		}
		if (section) {
			section.hidden = false;
		}
		var items = res.data.items || [];
		if (!items.length) {
			replace(root, el('p', { class: 'lnkup-muted', text: 'Team changes and review decisions will appear here.' }));
			return;
		}
		var os = window.LNKupOS || {};
		var body = el('tbody', {});
		items.forEach(function (item) {
			var label = ACTION_LABELS[item.action] || item.action.replace(/_/g, ' ');
			var stamp = item.created_at + ' UTC';
			var when = os.parseDate ? os.parseDate(stamp) : null;
			body.appendChild(el('tr', {}, [
				el('td', { class: 'vos-table__primary', text: item.actor }),
				el('td', { text: label.charAt(0).toUpperCase() + label.slice(1) }),
				el('td', {}, [el('time', {
					class: 'lnkup-muted',
					datetime: when ? when.toISOString() : item.created_at,
					title: when && os.formatDate ? os.formatDate(when) : stamp,
					text: when && os.relativeTime ? os.relativeTime(when) : stamp
				})])
			]));
		});
		var table = el('table', { class: 'vos-table' }, [
			el('caption', { class: 'vos-sr', text: 'Team activity' }),
			el('thead', {}, [el('tr', {}, [
				el('th', { scope: 'col', text: 'Who' }),
				el('th', { scope: 'col', text: 'What' }),
				el('th', { scope: 'col', text: 'When' })
			])]),
			body
		]);
		replace(root, el('div', { class: 'vos-table-wrap' }, [table]));
	}

	function scopeSelect(data) {
		var select = el('select', {}, [el('option', { value: 'all|0', text: 'All events' })]);
		(data.tours || []).forEach(function (tour) {
			select.appendChild(el('option', { value: 'tour|' + tour.id, text: 'Tour: ' + tour.title }));
		});
		(data.events || []).forEach(function (event) {
			select.appendChild(el('option', { value: 'event|' + event.id, text: 'Event: ' + event.title }));
		});
		return select;
	}

	function renderTeam(data) {
		var wrap = el('div');
		if (!data.enabled) {
			wrap.appendChild(el('div', { class: 'lnkup-empty-state' }, [el('p', { text: 'Team access is not turned on for your account yet. Contact LNKup support to enable it.' })]));
			replace(teamRoot, wrap);
			return;
		}

		var members = data.members || [];
		if (!members.length) {
			wrap.appendChild(el('div', { class: 'lnkup-empty-state' }, [el('p', { text: 'No team members yet. Invite people who help run your events — they only see what their role allows.' })]));
		} else {
			var list = el('ul', { class: 'lnkup-org-team__members' });
			members.forEach(function (member) {
				var remove = el('button', { type: 'button', class: 'lnkup-btn lnkup-btn--outline lnkup-btn--sm', text: 'Remove' });
				remove.addEventListener('click', async function () {
					if (!window.confirm('Remove ' + member.name + '? Their access ends immediately.')) {
						return;
					}
					busy(remove, true);
					var res = await api('/org/team/' + member.id + '?owner_id=' + encodeURIComponent(data.owner_id), { method: 'DELETE', body: {} });
					busy(remove, false);
					if (!res.ok) {
						flash(wrap, res.error, true);
						return;
					}
					renderTeam(res.data);
				});
				list.appendChild(el('li', { class: 'lnkup-org-team__member' }, [
					el('div', {}, [el('strong', { text: member.name }), el('div', { class: 'lnkup-muted', text: member.email })]),
					el('div', {}, [el('span', { class: 'lnkup-badge', text: member.role_label }), el('div', { class: 'lnkup-muted', text: member.scope_label })]),
					remove
				]));
			});
			wrap.appendChild(list);
		}

		if ((data.invites || []).length) {
			wrap.appendChild(el('h3', { text: 'Pending invites' }));
			var invites = el('ul', { class: 'lnkup-org-team__members' });
			data.invites.forEach(function (invite) {
				invites.appendChild(el('li', { class: 'lnkup-org-team__member' }, [
					el('span', { text: invite.email }),
					el('span', { class: 'lnkup-muted', text: invite.role_label + ' · ' + invite.scope_label })
				]));
			});
			wrap.appendChild(invites);
		}

		var email = el('input', { type: 'email', required: true, placeholder: 'name@example.com' });
		var role = el('select', {});
		Object.keys(data.roles || {}).forEach(function (key) {
			role.appendChild(el('option', { value: key, text: data.roles[key] }));
		});
		var scope = scopeSelect(data);
		var send = el('button', { type: 'submit', class: 'lnkup-btn lnkup-btn--primary lnkup-btn--sm', text: 'Send invite' });
		var form = el('form', { class: 'lnkup-org-team__form lnkup-org-team__sub' }, [
			el('h3', { text: 'Invite a team member' }),
			field('Email', email),
			field('Role', role),
			field('Access to', scope),
			el('p', { class: 'lnkup-muted', text: 'Finance can see money and approve refunds. Event Managers handle applications and vendors. Vendor Managers review and decide applications. Application Reviewers score applications but cannot approve or decline. Operations Staff run event day. Only you can change payout settings.' }),
			send
		]);
		form.addEventListener('submit', async function (event) {
			event.preventDefault();
			var parts = scope.value.split('|');
			busy(send, true);
			var res = await api('/org/team?owner_id=' + encodeURIComponent(data.owner_id), { method: 'POST', body: { email: email.value, role: role.value, scope_type: parts[0], scope_id: Number(parts[1] || 0) } });
			busy(send, false);
			if (!res.ok) {
				flash(form, res.error, true);
				return;
			}
			loadTeam();
		});
		wrap.appendChild(form);
		replace(teamRoot, wrap);
	}

	async function acceptInvite() {
		var banner = el('div', { class: 'lnkup-org-team__flash', role: 'status', text: 'Accepting your team invite…' });
		teamRoot.parentNode.insertBefore(banner, teamRoot);
		var res = await api('/org/invites/accept', { method: 'POST', body: { token: cfg.inviteToken } });
		banner.textContent = res.ok ? 'You joined the team. Your access is ready.' : res.error;
		banner.className = 'lnkup-org-team__flash' + (res.ok ? '' : ' is-error');
		if (res.ok && cfg.homeUrl) {
			banner.appendChild(document.createTextNode(' '));
			banner.appendChild(el('a', { href: cfg.homeUrl, text: 'Go to My work' }));
		}
		if (res.ok) {
			loadTeam();
		}
		if (window.history && window.history.replaceState) {
			var url = new URL(window.location.href);
			url.searchParams.delete('lnkup_team_invite');
			window.history.replaceState({}, '', url.toString());
		}
	}

	if (cfg.inviteToken) {
		acceptInvite();
	}
	loadVerification();
	loadTeam();
})();
