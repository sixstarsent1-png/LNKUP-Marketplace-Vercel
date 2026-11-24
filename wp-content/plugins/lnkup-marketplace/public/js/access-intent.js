/**
 * Guest access gate funnel + post-sign-in intent restore (LNKup_Access_Policy).
 *
 * - Records one authentication_gate_displayed per page for guests (sessionStorage dedupe).
 * - Records authentication_gate_clicked / guest_registration_started on gate CTA clicks.
 * - After sign-in (?lnkup_restored=1) records authentication_intent_restored, scrolls back to the
 *   vendor options the guest asked to see, and strips the marker so refreshes do not re-count.
 */
(function () {
	'use strict';

	var cfg = window.lnkupAccess || {};
	if (!cfg.trackUrl) {
		return;
	}

	function track(type, data) {
		try {
			var headers = { 'Content-Type': 'application/json' };
			if (cfg.nonce) {
				headers['X-WP-Nonce'] = cfg.nonce;
			}
			fetch(cfg.trackUrl, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: headers,
				body: JSON.stringify({ event_type: type, data: data || {}, opportunity_id: (data && data.opportunity_id) || 0 })
			}).catch(function () {});
		} catch (e) {
			/* analytics must never break the page */
		}
	}

	function pageKind() {
		if (document.querySelector('[data-testid="lnkup-public-event"]')) {
			return 'event';
		}
		if (document.querySelector('[data-testid="lnkup-market-opportunities"]')) {
			return 'market';
		}
		return document.body && document.body.classList.contains('home') ? 'home' : 'feed';
	}

	function onGateClick(event) {
		var link = event.target.closest ? event.target.closest('[data-lnkup-gate], [data-lnkup-gate-action]') : null;
		if (!link) {
			return;
		}
		var action = link.getAttribute('data-lnkup-gate-action') || 'sign_in';
		var surface = link.getAttribute('data-lnkup-gate') || (link.closest('[data-lnkup-gate]') || link).getAttribute('data-lnkup-gate') || 'card';
		var data = { surface: surface, page: pageKind(), action: action };
		track(action === 'sign_up' ? 'guest_registration_started' : 'authentication_gate_clicked', data);
	}

	function recordDisplayed() {
		var gates = document.querySelectorAll('[data-lnkup-gate]');
		if (!gates.length) {
			return;
		}
		var key = 'lnkup_gate_seen:' + window.location.pathname;
		try {
			if (window.sessionStorage.getItem(key)) {
				return;
			}
			window.sessionStorage.setItem(key, '1');
		} catch (e) {
			/* private mode: count once per load */
		}
		var page = pageKind();
		track('authentication_gate_displayed', { page: page, count: gates.length });
		if (page === 'event' && document.querySelector('[data-testid="lnkup-guest-gate"]')) {
			track('guest_restricted_section_viewed', { page: page, surface: 'event' });
		}
	}

	function restoreIntent() {
		var params = new URLSearchParams(window.location.search);
		if (params.get('lnkup_restored') !== '1') {
			return;
		}
		var intent = params.get('lnkup_intent') || '';
		track('authentication_intent_restored', { intent: intent, page: pageKind() });
		if (intent === 'view_pricing' || intent === 'apply') {
			var target = document.getElementById('lnkup-vendor-options');
			if (target && !params.get('apply')) {
				target.scrollIntoView({ block: 'start' });
			}
		}
		params.delete('lnkup_restored');
		params.delete('lnkup_intent');
		var query = params.toString();
		var clean = window.location.pathname + (query ? '?' + query : '') + window.location.hash;
		if (window.history && window.history.replaceState) {
			window.history.replaceState(null, '', clean);
		}
	}

	document.addEventListener('click', onGateClick, true);
	if (cfg.isGuest) {
		recordDisplayed();
	} else {
		restoreIntent();
	}
})();
