/**
 * Validated city/state entry for WordPress forms (LNKup_Location_Service via REST).
 *
 * Opt-in: a city input with data-lnkup-location-city and a state input in the same form with
 * data-lnkup-location-state. Adds:
 * - debounced autocomplete (GET lnkup/v1/locations/suggest) with keyboard navigation;
 * - resolve on change (GET lnkup/v1/locations/resolve) with a "Did you mean …?" fix-up;
 * - a submit guard so an invalid or ambiguous location cannot be submitted.
 * The server re-validates every save; this only makes the right answer one tap away.
 */
(function () {
	'use strict';

	var cfg = window.lnkupLocation || {};
	if (!cfg.restUrl) {
		return;
	}
	var OK = ['verified', 'normalized'];
	var uid = 0;

	function get(path, params) {
		var url = new URL(cfg.restUrl.replace(/\/$/, '') + path, window.location.origin);
		Object.keys(params).forEach(function (key) {
			url.searchParams.set(key, params[key]);
		});
		return fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (res) {
			var type = res.headers.get('content-type') || '';
			if (!res.ok || type.indexOf('application/json') === -1) {
				throw new Error('lookup_failed');
			}
			return res.json();
		});
	}

	function debounce(fn, wait) {
		var timer = null;
		return function () {
			var args = arguments;
			clearTimeout(timer);
			timer = setTimeout(function () {
				fn.apply(null, args);
			}, wait);
		};
	}

	function setValue(input, value) {
		input.value = value;
		input.dispatchEvent(new Event('input', { bubbles: true }));
		input.dispatchEvent(new Event('change', { bubbles: true }));
	}

	function enhance(cityInput) {
		var form = cityInput.form || cityInput.closest('form');
		var stateInput = form ? form.querySelector('[data-lnkup-location-state]') : null;
		if (!form || !stateInput || cityInput.dataset.lnkupLocationReady) {
			return;
		}
		cityInput.dataset.lnkupLocationReady = '1';
		uid += 1;
		var listId = 'lnkup-loc-list-' + uid;
		var wrap = document.createElement('div');
		wrap.className = 'lnkup-loc';
		cityInput.parentNode.insertBefore(wrap, cityInput);
		wrap.appendChild(cityInput);
		var list = document.createElement('ul');
		list.id = listId;
		list.className = 'lnkup-loc__list';
		list.setAttribute('role', 'listbox');
		list.hidden = true;
		wrap.appendChild(list);
		var note = document.createElement('div');
		note.className = 'lnkup-loc__note';
		note.setAttribute('role', 'status');
		note.setAttribute('aria-live', 'polite');
		note.hidden = true;
		(stateInput.closest('label') || stateInput).insertAdjacentElement('afterend', note);

		cityInput.setAttribute('autocomplete', 'off');
		cityInput.setAttribute('role', 'combobox');
		cityInput.setAttribute('aria-autocomplete', 'list');
		cityInput.setAttribute('aria-controls', listId);
		cityInput.setAttribute('aria-expanded', 'false');

		var options = [];
		var active = -1;
		var status = cityInput.value || stateInput.value ? 'unknown' : '';

		function closeList() {
			list.hidden = true;
			list.innerHTML = '';
			options = [];
			active = -1;
			cityInput.setAttribute('aria-expanded', 'false');
			cityInput.removeAttribute('aria-activedescendant');
		}

		function choose(place) {
			setValue(cityInput, place.city);
			setValue(stateInput, place.state_code);
			status = 'verified';
			note.hidden = true;
			closeList();
		}

		function renderList(results) {
			list.innerHTML = '';
			options = results;
			active = -1;
			if (!results.length) {
				closeList();
				return;
			}
			results.forEach(function (place, index) {
				var li = document.createElement('li');
				li.id = listId + '-' + index;
				li.className = 'lnkup-loc__option';
				li.setAttribute('role', 'option');
				li.textContent = place.label || place.display;
				li.addEventListener('mousedown', function (event) {
					event.preventDefault();
					choose(place);
				});
				list.appendChild(li);
			});
			list.hidden = false;
			cityInput.setAttribute('aria-expanded', 'true');
		}

		function highlight(index) {
			var items = list.querySelectorAll('.lnkup-loc__option');
			items.forEach(function (item, i) {
				item.setAttribute('aria-selected', i === index ? 'true' : 'false');
			});
			active = index;
			if (items[index]) {
				cityInput.setAttribute('aria-activedescendant', items[index].id);
			}
		}

		function showNote(result) {
			note.innerHTML = '';
			if (!result || OK.indexOf(result.status) !== -1) {
				note.hidden = true;
				return;
			}
			var text = document.createElement('p');
			text.textContent = result.message || 'Choose a city and state from the list.';
			note.appendChild(text);
			(result.suggestions || []).slice(0, 4).forEach(function (place) {
				var button = document.createElement('button');
				button.type = 'button';
				button.className = 'lnkup-btn lnkup-btn--sm lnkup-btn--outline';
				button.textContent = 'Use ' + place.display;
				button.addEventListener('click', function () {
					choose(place);
					cityInput.focus();
				});
				note.appendChild(button);
			});
			note.hidden = false;
		}

		var lookup = debounce(function () {
			var q = (cityInput.value + ' ' + stateInput.value).trim();
			if (cityInput.value.trim().length < 2) {
				closeList();
				return;
			}
			get('/locations/suggest', { q: q, limit: 8 }).then(function (data) {
				if (document.activeElement === cityInput) {
					renderList((data && data.results) || []);
				}
			}).catch(closeList);
		}, 220);

		function resolveNow() {
			if (!cityInput.value.trim() && !stateInput.value.trim()) {
				status = '';
				showNote(null);
				return Promise.resolve();
			}
			return get('/locations/resolve', { city: cityInput.value, state: stateInput.value, country: '' }).then(function (result) {
				status = result.status;
				if (OK.indexOf(result.status) !== -1 && result.city) {
					if (cityInput.value !== result.city) {
						cityInput.value = result.city;
					}
					if (stateInput.value !== result.state_code) {
						stateInput.value = result.state_code;
					}
				}
				showNote(result);
			}).catch(function () {
				// Lookup unavailable: the server still validates on save.
				status = '';
			});
		}
		var resolve = debounce(resolveNow, 250);

		cityInput.addEventListener('input', function () {
			status = 'unknown';
			lookup();
		});
		cityInput.addEventListener('keydown', function (event) {
			if (list.hidden || !options.length) {
				return;
			}
			if (event.key === 'ArrowDown') {
				event.preventDefault();
				highlight(Math.min(options.length - 1, active + 1));
			} else if (event.key === 'ArrowUp') {
				event.preventDefault();
				highlight(Math.max(0, active - 1));
			} else if (event.key === 'Enter' && active >= 0) {
				event.preventDefault();
				choose(options[active]);
			} else if (event.key === 'Escape') {
				closeList();
			}
		});
		cityInput.addEventListener('blur', function () {
			setTimeout(closeList, 150);
			resolve();
		});
		stateInput.addEventListener('input', function () {
			status = 'unknown';
		});
		stateInput.addEventListener('change', function () {
			status = 'unknown';
			resolve();
		});

		var resolving = false;
		form.addEventListener('submit', function (event) {
			if (status === '' || OK.indexOf(status) !== -1) {
				return;
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			if (resolving) {
				return;
			}
			// Submitted before the lookup finished: check now and continue the submit when the place is valid.
			var submitter = event.submitter && event.submitter.form === form ? event.submitter : null;
			resolving = true;
			resolveNow().then(function () {
				resolving = false;
				if (status === '' || OK.indexOf(status) !== -1) {
					if (typeof form.requestSubmit === 'function') {
						form.requestSubmit(submitter);
					} else {
						form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
					}
					return;
				}
				cityInput.focus();
			});
		}, true);

		if (!form.querySelector('input[name="location_strict"]')) {
			var strict = document.createElement('input');
			strict.type = 'hidden';
			strict.name = 'location_strict';
			strict.value = '1';
			form.appendChild(strict);
		}
		if (status === 'unknown') {
			resolve();
		}
	}

	function scan(root) {
		(root || document).querySelectorAll('[data-lnkup-location-city]').forEach(enhance);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			scan();
		});
	} else {
		scan();
	}
	// Forms injected later (dashboard panels, modals).
	new MutationObserver(function (mutations) {
		mutations.forEach(function (mutation) {
			mutation.addedNodes.forEach(function (node) {
				if (node.nodeType === 1) {
					scan(node);
				}
			});
		});
	}).observe(document.documentElement, { childList: true, subtree: true });
})();
