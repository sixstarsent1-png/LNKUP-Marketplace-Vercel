(function () {
  var ajax = window.lnkupAjax || {};
  var repBase = ajax.reputationRestUrl || '';
  var nonce = ajax.restNonce || '';

  var REVIEW_CATEGORIES = [
    ['communication', 'Communication — responsive and clear during prep'],
    ['professionalism', 'Professionalism — followed event procedures'],
    ['preparedness', 'Preparedness — ready for the agreed setup'],
    ['event_coordination', 'Event coordination — how smoothly coordination went']
  ];

  var POSITIVE_TAGS = [
    ['on_time', 'On time'],
    ['great_setup', 'Great setup'],
    ['responsive', 'Responsive'],
    ['followed_guidelines', 'Followed guidelines'],
    ['easy_to_work_with', 'Easy to work with'],
    ['crowd_favorite', 'Crowd favorite'],
    ['professional_presentation', 'Professional presentation']
  ];

  /**
   * Reputation REST fetch helper.
   */
  function repFetch(path, options) {
    options = options || {};
    var headers = { 'X-WP-Nonce': nonce };
    if (options.body && typeof options.body === 'object') {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }
    return fetch(repBase + path, Object.assign({ credentials: 'same-origin', headers: headers }, options))
      .then(function (r) {
        return r.json().then(function (data) {
          if (!r.ok) {
            throw data;
          }
          return data;
        });
      });
  }

  /**
   * Ensure review modal exists in DOM.
   */
  function ensureReviewModal() {
    var modal = document.getElementById('lnkup-rep-review-modal');
    if (modal) {
      return modal;
    }
    modal = document.createElement('div');
    modal.id = 'lnkup-rep-review-modal';
    modal.className = 'lnkup-rep-modal';
    modal.hidden = true;
    modal.innerHTML =
      '<div class="lnkup-rep-modal__inner">' +
      '<button type="button" class="lnkup-rep-modal__close" aria-label="Close">&times;</button>' +
      '<h2>Review Vendor</h2>' +
      '<p class="lnkup-muted" id="lnkup-rep-review-eligibility"></p>' +
      '<form id="lnkup-rep-review-form">' +
      '<input type="hidden" name="application_id" id="lnkup-rep-review-app-id" />' +
      '<label>Overall rating</label>' +
      '<div class="lnkup-rep-modal__stars-input" id="lnkup-rep-overall-stars"></div>' +
      '<div class="lnkup-rep-modal__cats" id="lnkup-rep-category-ratings"></div>' +
      '<fieldset class="lnkup-rep-modal__tags" id="lnkup-rep-positive-tags"><legend>What went well (optional)</legend></fieldset>' +
      '<div class="lnkup-form-row"><label for="lnkup-rep-public-text">Public review</label>' +
      '<textarea id="lnkup-rep-public-text" name="public_text" rows="3" maxlength="2000" placeholder="Describe the working relationship. Avoid personal details or traits unrelated to the event."></textarea></div>' +
      '<p class="lnkup-muted">Reviews must reflect this booking only. Personal attacks, private information or discriminatory content will be removed.</p>' +
      '<div class="lnkup-form-row"><label for="lnkup-rep-private-note">Private note (only you and LNKup staff can see; never published)</label>' +
      '<textarea id="lnkup-rep-private-note" name="private_note" rows="2"></textarea></div>' +
      '<label><input type="checkbox" id="lnkup-rep-late-arrival" name="late_arrival" /> Late arrival</label><br />' +
      '<label><input type="checkbox" id="lnkup-rep-issue-flagged" name="issue_flagged" /> Flag issue</label>' +
      '<div style="margin-top:1rem;display:flex;gap:0.5rem;">' +
      '<button type="submit" class="lnkup-btn lnkup-btn--primary">Submit review</button>' +
      '<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-rep-modal__close">Cancel</button>' +
      '</div></form></div>';
    document.body.appendChild(modal);

    modal.addEventListener('click', function (e) {
      if (e.target === modal || e.target.classList.contains('lnkup-rep-modal__close')) {
        modal.hidden = true;
      }
    });

    var starsWrap = document.getElementById('lnkup-rep-overall-stars');
    for (var i = 1; i <= 5; i++) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.setAttribute('data-star', String(i));
      btn.textContent = '★';
      btn.addEventListener('click', function (ev) {
        var val = parseInt(ev.currentTarget.getAttribute('data-star'), 10);
        modal.setAttribute('data-overall-rating', String(val));
        starsWrap.querySelectorAll('button').forEach(function (b) {
          b.classList.toggle('is-active', parseInt(b.getAttribute('data-star'), 10) <= val);
        });
      });
      starsWrap.appendChild(btn);
    }

    var catsWrap = document.getElementById('lnkup-rep-category-ratings');
    REVIEW_CATEGORIES.forEach(function (pair) {
      var row = document.createElement('div');
      row.className = 'lnkup-rep-modal__cat';
      row.innerHTML =
        '<label><span>' + pair[1] + '</span>' +
        '<select name="cat_' + pair[0] + '" data-cat="' + pair[0] + '">' +
        '<option value="">—</option><option value="1">1</option><option value="2">2</option>' +
        '<option value="3">3</option><option value="4">4</option><option value="5">5</option></select></label>';
      catsWrap.appendChild(row);
    });

    var tagsWrap = document.getElementById('lnkup-rep-positive-tags');
    POSITIVE_TAGS.forEach(function (pair) {
      var label = document.createElement('label');
      label.className = 'lnkup-checkbox';
      var input = document.createElement('input');
      input.type = 'checkbox';
      input.value = pair[0];
      input.setAttribute('data-tag', pair[0]);
      label.appendChild(input);
      label.appendChild(document.createTextNode(' ' + pair[1]));
      tagsWrap.appendChild(label);
    });

    document.getElementById('lnkup-rep-review-form').addEventListener('submit', function (e) {
      e.preventDefault();
      submitReview(modal);
    });

    return modal;
  }

  /**
   * Open review modal for application.
   */
  function openReviewModal(appId) {
    if (!repBase) {
      return;
    }
    var modal = ensureReviewModal();
    modal.hidden = false;
    modal.setAttribute('data-overall-rating', '0');
    document.getElementById('lnkup-rep-review-app-id').value = appId;
    document.getElementById('lnkup-rep-review-eligibility').textContent = 'Checking eligibility…';
    document.getElementById('lnkup-rep-public-text').value = '';
    document.getElementById('lnkup-rep-private-note').value = '';
    document.getElementById('lnkup-rep-late-arrival').checked = false;
    document.getElementById('lnkup-rep-issue-flagged').checked = false;
    document.getElementById('lnkup-rep-overall-stars').querySelectorAll('button').forEach(function (b) {
      b.classList.remove('is-active');
    });
    document.getElementById('lnkup-rep-category-ratings').querySelectorAll('select').forEach(function (s) {
      s.value = '';
    });
    document.getElementById('lnkup-rep-positive-tags').querySelectorAll('input').forEach(function (i) {
      i.checked = false;
    });

    repFetch('/applications/' + appId + '/review-eligibility').then(function (res) {
      var note = document.getElementById('lnkup-rep-review-eligibility');
      var form = document.getElementById('lnkup-rep-review-form');
      if (res && res.allowed) {
        note.textContent = '';
        form.querySelector('button[type="submit"]').disabled = false;
      } else {
        note.textContent = (res && res.reason) ? res.reason : 'You cannot review this booking yet.';
        form.querySelector('button[type="submit"]').disabled = true;
      }
    }).catch(function () {
      document.getElementById('lnkup-rep-review-eligibility').textContent = 'Could not verify eligibility.';
    });
  }

  /**
   * Submit organizer review.
   */
  function submitReview(modal) {
    var appId = document.getElementById('lnkup-rep-review-app-id').value;
    var overall = parseInt(modal.getAttribute('data-overall-rating') || '0', 10);
    if (!overall) {
      alert('Please select an overall rating.');
      return;
    }
    var categoryRatings = {};
    document.getElementById('lnkup-rep-category-ratings').querySelectorAll('select[data-cat]').forEach(function (sel) {
      if (sel.value) {
        categoryRatings[sel.getAttribute('data-cat')] = parseInt(sel.value, 10);
      }
    });
    repFetch('/reviews', {
      method: 'POST',
      body: {
        application_id: parseInt(appId, 10),
        overall_rating: overall,
        category_ratings: categoryRatings,
        positive_tags: Array.prototype.map.call(document.querySelectorAll('#lnkup-rep-positive-tags input:checked'), function (i) {
          return i.value;
        }),
        public_text: document.getElementById('lnkup-rep-public-text').value,
        private_note: document.getElementById('lnkup-rep-private-note').value,
        late_arrival: document.getElementById('lnkup-rep-late-arrival').checked,
        issue_flagged: document.getElementById('lnkup-rep-issue-flagged').checked
      }
    }).then(function () {
      modal.hidden = true;
      alert('Review submitted.');
      location.reload();
    }).catch(function (err) {
      alert((err && err.message) ? err.message : 'Could not submit review.');
    });
  }

  /**
   * Report a review (vendor).
   */
  function reportReview(reviewId) {
    var reason = prompt('What is inaccurate or inappropriate about this review? (at least 10 characters)');
    if (!reason) {
      return;
    }
    repFetch('/reviews/' + reviewId + '/report', {
      method: 'POST',
      body: { reason: reason, reason_code: 'other' }
    }).then(function () {
      alert('Report submitted. The review stays visible while our team checks it against our review standards.');
    }).catch(function (err) {
      alert((err && err.message) ? err.message : 'Could not submit report.');
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('click', function (e) {
      var reviewBtn = e.target.closest('.lnkup-crm-review-open');
      if (reviewBtn) {
        openReviewModal(reviewBtn.getAttribute('data-app-id'));
        return;
      }
      var reportBtn = e.target.closest('.lnkup-rep-report-review');
      if (reportBtn) {
        reportReview(reportBtn.getAttribute('data-review-id'));
      }
    });
  });
})();
