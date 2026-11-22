/**
 * Public tour page: city filter and the vendor "interested in the whole tour" toggle.
 * Interest is only a signal to the organizer; it never creates an application or booking.
 */
(function () {
  'use strict';

  var cfg = window.lnkupTourPublic || {};
  var page = document.querySelector('[data-testid="lnkup-tour-public"]');
  if (!page) {
    return;
  }

  var filter = page.querySelector('[data-tour-city-filter]');
  if (filter) {
    filter.addEventListener('change', function () {
      var city = filter.value;
      var shown = 0;
      Array.prototype.forEach.call(page.querySelectorAll('.lnkup-tour-stop-card'), function (card) {
        var match = !city || card.getAttribute('data-city') === city;
        card.hidden = !match;
        shown += match ? 1 : 0;
      });
      var empty = page.querySelector('[data-tour-filter-empty]');
      if (empty) {
        empty.hidden = shown > 0;
      }
    });
  }

  var button = page.querySelector('[data-tour-interest]');
  var status = page.querySelector('[data-tour-interest-status]');
  if (!button || !cfg.root) {
    return;
  }

  function say(message) {
    if (status) {
      status.textContent = message;
      status.hidden = false;
    }
  }

  button.addEventListener('click', function () {
    var tourId = button.getAttribute('data-tour-interest');
    var on = button.getAttribute('data-interested') === '1';
    button.disabled = true;
    fetch(cfg.root.replace(/\/$/, '') + '/portfolio/tours/' + encodeURIComponent(tourId) + '/interest/me', {
      method: on ? 'DELETE' : 'POST',
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce || '', 'Content-Type': 'application/json' },
      body: on ? undefined : '{}'
    }).then(function (res) {
      var type = res.headers.get('content-type') || '';
      if (type.indexOf('application/json') === -1) {
        throw new Error('Unexpected response');
      }
      return res.json().then(function (data) {
        return { ok: res.ok, data: data };
      });
    }).then(function (r) {
      button.disabled = false;
      if (!r.ok) {
        say((r.data && r.data.message) || 'We could not save that. Try again.');
        return;
      }
      var interested = !!r.data.interested;
      button.setAttribute('data-interested', interested ? '1' : '0');
      button.setAttribute('aria-pressed', interested ? 'true' : 'false');
      button.textContent = interested ? 'Interested in this tour' : 'I\'m interested in the whole tour';
      say(interested ? 'The organizer can now invite you to specific dates. This is not an application yet.' : 'Interest removed.');
    }).catch(function (err) {
      console.error('LNKup tour interest error', err);
      button.disabled = false;
      say('We could not save that. Check your connection and try again.');
    });
  });
})();
