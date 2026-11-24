(function () {
  'use strict';

  function restBase() {
    if (window.lnkupAjax && window.lnkupAjax.verificationRestUrl) {
      return window.lnkupAjax.verificationRestUrl.replace(/\/$/, '');
    }
    return '/wp-json/lnkup/v1/verification';
  }

  function nonce() {
    return (window.lnkupRest && window.lnkupRest.nonce) || (window.lnkupAjax && window.lnkupAjax.restNonce) || '';
  }

  async function fetchVerification(path) {
    try {
      const response = await fetch(restBase() + path, {
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce()
        }
      });
      const contentType = response.headers.get('content-type') || '';
      if (!contentType.includes('application/json')) {
        throw new Error('Invalid response');
      }
      const json = await response.json();
      if (!response.ok) {
        throw new Error(json.message || json.code || 'Request failed');
      }
      return json;
    } catch (err) {
      console.error('LNKup verification error:', err);
      return null;
    }
  }

  function badgeHtml(label, accent) {
    return '<span class="lnkup-trust-badge' + (accent ? ' lnkup-trust-badge--accent' : '') + '">' + escapeHtml(label) + '</span>';
  }

  function escapeHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function hydrateVendorTrustCard(card) {
    const loading = card.querySelector('.lnkup-trust-card__loading');
    const content = card.querySelector('.lnkup-trust-card__content');
    const error = card.querySelector('.lnkup-trust-card__error');

    fetchVerification('/vendor').then(function (data) {
      if (loading) loading.hidden = true;
      if (!data || !data.level) {
        if (error) error.hidden = false;
        return;
      }
      if (content) content.hidden = false;

      const statusEl = card.querySelector('[data-trust-status]');
      const bar = card.querySelector('[data-trust-percent-bar]');
      const badgesEl = card.querySelector('[data-trust-badges]');
      const missingEl = card.querySelector('[data-trust-missing]');
      const docsNote = card.querySelector('[data-trust-docs-note]');
      const cta = card.querySelector('[data-trust-cta]');
      const milestoneEl = card.querySelector('[data-trust-next-milestone]');

      const percent = Number(data.percent_complete || 0);
      if (statusEl) {
        statusEl.textContent = (data.label || data.level);
      }
      if (milestoneEl) {
        const missing = (data.missing_items || [])[0];
        milestoneEl.textContent = missing
          ? ('Next: ' + missing)
          : (percent >= 100 ? 'All milestones unlocked' : percent + '% to next level');
      }
      if (bar) {
        bar.style.width = percent + '%';
        const barWrap = card.querySelector('.lnkup-trust-card__bar');
        if (barWrap) barWrap.setAttribute('aria-valuenow', String(percent));
      }
      if (badgesEl) {
        var earned = Array.isArray(data.badges) ? data.badges.filter(Boolean) : [];
        badgesEl.innerHTML = earned.length
          ? '<p class="lnkup-type-label">Unlocked</p>' + earned.slice(0, 6).map(function (b) {
            return badgeHtml(b, /verified|top rated|documents ready/i.test(b));
          }).join('')
          : '';
      }
      if (missingEl) {
        const items = (data.missing_items || []).slice(0, 4);
        missingEl.innerHTML = items.map(function (item) {
          return '<li>' + escapeHtml(item) + '</li>';
        }).join('');
        missingEl.hidden = !items.length;
      }
      if (docsNote) {
        const required = data.required_documents || [];
        if (!required.length) {
          docsNote.textContent = 'No required documents for your current opportunities.';
          docsNote.hidden = false;
        } else if ((data.recommended_items || []).length) {
          docsNote.textContent = data.recommended_items[0];
          docsNote.hidden = false;
        }
      }
      if (cta && data.next_action) {
        cta.textContent = data.next_action;
      }
    });
  }

  function hydrateOrganizerTrustStrip(strip) {
    fetchVerification('/organizer').then(function (data) {
      if (!data || !data.level) {
        strip.hidden = true;
        strip.innerHTML = '';
        return;
      }
      strip.hidden = false;
      strip.removeAttribute('hidden');
      const badges = (data.badges || []).slice(0, 3).map(function (b) {
        return badgeHtml(b, true);
      }).join('');
      strip.innerHTML =
        '<span class="lnkup-organizer-trust-strip__label">' + escapeHtml(data.label) + ' · ' + Number(data.percent_complete || 0) + '%</span>' +
        '<span class="lnkup-organizer-trust-strip__meta">' + escapeHtml(data.public_summary || '') + '</span>' +
        '<span class="lnkup-trust-badges-inline">' + badges + '</span>';
    }).catch(function () {
      strip.hidden = true;
      strip.innerHTML = '';
    });
  }

  function initDocumentModeRadios() {
    const container = document.querySelector('[data-testid="lnkup-document-mode"]');
    if (!container) return;
    const customList = document.getElementById('lnkup-document-custom-list');
    const radios = container.querySelectorAll('input[name="document_mode"]');
    function sync() {
      const selected = container.querySelector('input[name="document_mode"]:checked');
      if (customList) {
        customList.hidden = !selected || selected.value !== 'custom';
      }
    }
    radios.forEach(function (radio) {
      radio.addEventListener('change', sync);
    });
    sync();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#lnkup-vendor-trust-card').forEach(hydrateVendorTrustCard);
    document.querySelectorAll('[data-testid="lnkup-organizer-trust-strip"]').forEach(hydrateOrganizerTrustStrip);
    initDocumentModeRadios();
  });
})();
