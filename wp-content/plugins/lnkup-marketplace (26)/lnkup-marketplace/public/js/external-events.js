(function () {
  'use strict';

  var cfg = window.lnkupAjax || {};
  var copy = window.lnkupUiCopy || {};
  var restBase = (cfg.externalEventsRestUrl || '').replace(/\/$/, '');

  function t(key, fallback) {
    return copy[key] || fallback || key;
  }

  function api(path, options) {
    options = options || {};
    var url = restBase + path;
    var headers = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    };
    if (cfg.restNonce) {
      headers['X-WP-Nonce'] = cfg.restNonce;
    }
    return fetch(url, {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers: headers,
      body: options.body ? JSON.stringify(options.body) : undefined,
    }).then(function (res) {
      var ct = res.headers.get('content-type') || '';
      if (!ct.includes('application/json')) {
        throw new Error('invalid_json');
      }
      return res.json().then(function (data) {
        if (!res.ok) {
          var err = new Error(data.message || 'request_failed');
          err.status = res.status;
          err.data = data;
          throw err;
        }
        return data;
      });
    });
  }

  function isModalOpen() {
    var modal = document.getElementById('lnkup-external-interest-modal');
    return modal && !modal.hidden;
  }

  function ensureModal() {
    var existing = document.getElementById('lnkup-external-interest-modal');
    if (existing) {
      return existing;
    }
    var modal = document.createElement('div');
    modal.id = 'lnkup-external-interest-modal';
    modal.className = 'lnkup-modal lnkup-modal--external-interest';
    modal.hidden = true;
    modal.innerHTML =
      '<div class="lnkup-modal__backdrop" data-close="1"></div>' +
      '<div class="lnkup-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="lnkup-external-interest-title">' +
      '<button type="button" class="lnkup-modal__close" data-close="1" aria-label="Close">&times;</button>' +
      '<div class="lnkup-external-interest__header">' +
      '<h2 class="lnkup-external-interest__title" id="lnkup-external-interest-title">' +
      t('request_interest_modal_title', 'Request a vendor opportunity') +
      '</h2>' +
      '<p class="lnkup-external-interest__event" id="lnkup-external-interest-event"></p>' +
      '</div>' +
      '<p class="lnkup-external-interest__notice" id="lnkup-external-interest-notice">' +
      t(
        'external_event_disclaimer',
        'This listing is from a public event source. LNKup does not manage bookings for this event until the organizer claims it.'
      ) +
      '</p>' +
      '<form id="lnkup-external-interest-form" class="lnkup-external-interest__form">' +
      '<input type="hidden" name="external_event_id" id="lnkup-external-interest-id" value="" />' +
      '<label class="lnkup-external-interest__field">' +
      '<span>' +
      t('apply_category_label', 'Category you are applying under') +
      '</span>' +
      '<input type="text" name="vendor_category" required autocomplete="organization-title" />' +
      '</label>' +
      '<div class="lnkup-external-interest__grid lnkup-external-interest__grid--split">' +
      '<label class="lnkup-external-interest__field">' +
      '<span>' +
      t('apply_booth_type_label', 'Booth type') +
      '</span>' +
      '<input type="text" name="booth_type" autocomplete="off" />' +
      '</label>' +
      '<label class="lnkup-external-interest__field">' +
      '<span>' +
      t('apply_power_needs_label', 'Power needs') +
      '</span>' +
      '<input type="text" name="power_needs" autocomplete="off" placeholder="e.g. 20A" />' +
      '</label>' +
      '</div>' +
      '<label class="lnkup-external-interest__field">' +
      '<span>' +
      t('apply_message_label', 'Application message') +
      '</span>' +
      '<textarea name="message" rows="3" placeholder=""></textarea>' +
      '</label>' +
      '<label class="lnkup-external-interest__consent">' +
      '<input type="checkbox" name="consent" value="1" required />' +
      '<span>' +
      t('external_interest_consent_label', 'I agree to notify the organizer via LNKup') +
      '</span>' +
      '</label>' +
      '<p class="lnkup-alert lnkup-alert--error" id="lnkup-external-interest-error" hidden></p>' +
      '<p class="lnkup-alert lnkup-alert--success" id="lnkup-external-interest-success" hidden></p>' +
      '<div class="lnkup-modal__actions">' +
      '<button type="submit" class="lnkup-btn lnkup-btn--primary">' +
      t('request_vendor_opportunity', 'Request vendor opportunity') +
      '</button>' +
      '</div>' +
      '</form></div>';
    document.body.appendChild(modal);
    return modal;
  }

  function getEventTitle(externalId) {
    var card = document.querySelector('.lnkup-event-card--external[data-external-event-id="' + externalId + '"]');
    if (!card) {
      return '';
    }
    var titleEl = card.querySelector('.lnkup-event-card__title');
    return titleEl ? titleEl.textContent.trim() : '';
  }

  function openModal(externalId) {
    if (!cfg.isLoggedIn) {
      window.location.href = cfg.registrationUrl || '/sign-up/?role=vendor';
      return;
    }
    if (!cfg.isVendor) {
      window.alert(t('apply_logged_out', 'Create a vendor profile to request opportunities.'));
      return;
    }
    var modal = ensureModal();
    var form = document.getElementById('lnkup-external-interest-form');
    var subtitle = document.getElementById('lnkup-external-interest-event');
    var eventTitle = getEventTitle(externalId);

    if (form) {
      form.reset();
    }
    document.getElementById('lnkup-external-interest-id').value = String(externalId);
    if (subtitle) {
      subtitle.textContent = eventTitle || t('external_interest_modal_subtitle', 'External event listing');
    }
    document.getElementById('lnkup-external-interest-error').hidden = true;
    document.getElementById('lnkup-external-interest-success').hidden = true;

    modal.hidden = false;
    document.body.classList.add('lnkup-modal-open');

    var firstInput = form ? form.querySelector('input[name="vendor_category"]') : null;
    if (firstInput) {
      window.setTimeout(function () {
        firstInput.focus();
      }, 50);
    }
  }

  function closeModal() {
    var modal = document.getElementById('lnkup-external-interest-modal');
    if (modal) {
      modal.hidden = true;
    }
    document.body.classList.remove('lnkup-modal-open');
  }

  function markCardRequested(externalId) {
    var card = document.querySelector('[data-external-event-id="' + externalId + '"]');
    if (!card) {
      return;
    }
    card.setAttribute('data-has-interest', '1');
    var btn = card.querySelector('.lnkup-btn--external-interest');
    if (btn) {
      btn.disabled = true;
      btn.textContent = t('request_interest_existing', 'Already requested');
    }
  }

  function trackView(externalId) {
    if (!restBase) {
      return;
    }
    api('/external-events/track/view', {
      method: 'POST',
      body: { external_event_id: externalId },
    }).catch(function () {});
  }

  function observeViews() {
    if (!('IntersectionObserver' in window)) {
      return;
    }
    var seen = {};
    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) {
            return;
          }
          var id = entry.target.getAttribute('data-external-event-id');
          if (!id || seen[id]) {
            return;
          }
          seen[id] = true;
          trackView(id);
        });
      },
      { threshold: 0.35 }
    );
    document.querySelectorAll('.lnkup-event-card--external').forEach(function (el) {
      observer.observe(el);
    });
  }

  function handleSubmit(e) {
    e.preventDefault();
    var form = e.target;
    var externalId = form.external_event_id.value;
    var errEl = document.getElementById('lnkup-external-interest-error');
    var okEl = document.getElementById('lnkup-external-interest-success');
    errEl.hidden = true;
    okEl.hidden = true;

    api('/external-events/' + externalId + '/interest', {
      method: 'POST',
      body: {
        vendor_category: form.vendor_category.value,
        booth_type: form.booth_type.value,
        power_needs: form.power_needs.value,
        message: form.message.value,
        consent: form.consent.checked ? 1 : 0,
      },
    })
      .then(function (data) {
        okEl.textContent = data.message || t('request_interest_success', 'Request submitted.');
        okEl.hidden = false;
        markCardRequested(externalId);
      })
      .catch(function (err) {
        if (err.status === 409) {
          okEl.textContent = err.data && err.data.message ? err.data.message : t('request_interest_existing', 'Already requested.');
          okEl.hidden = false;
          markCardRequested(externalId);
          return;
        }
        if (err.data && err.data.code === 'no_profile') {
          window.location.href = cfg.vendorProfileUrl || cfg.registrationUrl || '/vendor-profile/';
          return;
        }
        errEl.textContent = (err.data && err.data.message) || t('error_load_section', 'Something went wrong.');
        errEl.hidden = false;
      });
  }

  function initClaimConfirmation() {
    var panel = document.getElementById('lnkup-external-claim-panel');
    if (!panel) {
      return;
    }
    var btn = document.getElementById('lnkup-external-claim-submit');
    var status = document.getElementById('lnkup-external-claim-status');
    if (!btn) {
      return;
    }
    btn.addEventListener('click', function () {
      btn.disabled = true;
      var externalId = panel.getAttribute('data-external-event-id');
      var token = panel.getAttribute('data-claim-token');
      api('/external-events/' + externalId + '/claim', {
        method: 'POST',
        body: { token: token },
      })
        .then(function (data) {
          status.className = 'lnkup-alert lnkup-alert--success';
          status.textContent = data.message || t('organizer_claim_success', 'Event claimed.');
          status.hidden = false;
          if (data.dashboard_url) {
            setTimeout(function () {
              window.location.href = data.dashboard_url;
            }, 1200);
          }
        })
        .catch(function (err) {
          status.className = 'lnkup-alert lnkup-alert--error';
          status.textContent = (err.data && err.data.message) || t('error_load_section', 'Could not claim event.');
          status.hidden = false;
          btn.disabled = false;
        });
    });
  }

  function initClaimLanding() {
    var root = document.querySelector('.lnkup-claim-landing');
    if (!root) {
      return;
    }
    var btn = document.getElementById('lnkup-claim-event-inline');
    var status = document.getElementById('lnkup-claim-event-status');
    if (!btn || !status) {
      return;
    }
    btn.addEventListener('click', function () {
      btn.disabled = true;
      var externalId = root.getAttribute('data-external-id');
      var token = root.getAttribute('data-token');
      api('/external-events/' + externalId + '/claim', {
        method: 'POST',
        body: { token: token },
      })
        .then(function (data) {
          status.className = 'lnkup-alert lnkup-alert--success';
          status.textContent = data.message || t('claim_event_success', 'Event claimed.');
          if (data.dashboard_url) {
            setTimeout(function () {
              window.location.href = data.dashboard_url;
            }, 1200);
          }
        })
        .catch(function (err) {
          status.className = 'lnkup-alert lnkup-alert--error';
          status.textContent = (err.data && err.data.message) || t('error_load_section', 'Could not claim event.');
          btn.disabled = false;
        });
    });
  }

  function initPendingInterests() {
    var root = document.getElementById('lnkup-external-pending-interests');
    if (!root || !restBase) {
      return;
    }
    api('/external-events/organizer/pending-interests')
      .then(function (data) {
        var items = data.items || [];
        if (!items.length) {
          root.innerHTML = '<p class="lnkup-muted">' + t('organizer_claim_pending_requests', 'Pending vendor interest') + ': none yet.</p>';
          return;
        }
        var html = '<ul class="lnkup-external-pending-list">';
        items.forEach(function (item) {
          html +=
            '<li class="lnkup-external-pending-item">' +
            '<strong>' +
            (item.vendor_business_name || 'Vendor') +
            '</strong> — ' +
            (item.event_title || '') +
            '<span class="lnkup-muted">' +
            (item.vendor_category ? ' · ' + item.vendor_category : '') +
            '</span></li>';
        });
        html += '</ul>';
        root.innerHTML = html;
      })
      .catch(function () {
        root.innerHTML = '<p class="lnkup-alert lnkup-alert--error">' + t('error_load_section', 'Could not load pending interest.') + '</p>';
      });
  }

  document.addEventListener('click', function (e) {
    var target = e.target;
    if (!(target instanceof Element)) {
      return;
    }
    if (target.matches('[data-close="1"]') || target.closest('[data-close="1"]')) {
      if (isModalOpen()) {
        closeModal();
      }
      return;
    }
    var interestBtn = target.closest('.lnkup-btn--external-interest');
    if (interestBtn && !interestBtn.disabled) {
      var id = interestBtn.getAttribute('data-external-event-id');
      if (id) {
        openModal(id);
      }
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isModalOpen()) {
      closeModal();
    }
  });

  document.addEventListener('submit', function (e) {
    if (e.target && e.target.id === 'lnkup-external-interest-form') {
      handleSubmit(e);
    }
  });

  function init() {
    observeViews();
    initClaimConfirmation();
    initClaimLanding();
    initPendingInterests();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  document.addEventListener('lnkup:marketplace-results', function () {
    observeViews();
  });
})();
