(function () {
  var ajax = window.lnkupAjax || {};
  var repBase = ajax.reputationRestUrl || '';
  var nonce = ajax.restNonce || '';

  var REVIEW_CATEGORIES = [
    ['professionalism', 'Professionalism'],
    ['communication', 'Communication'],
    ['setup_readiness', 'Setup readiness'],
    ['arrival_time', 'Arrival time'],
    ['quality', 'Product/service quality'],
    ['cleanliness', 'Cleanliness'],
    ['engagement', 'Customer engagement'],
    ['would_book_again', 'Would book again']
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
      '<div class="lnkup-form-row"><label for="lnkup-rep-public-text">Public review</label>' +
      '<textarea id="lnkup-rep-public-text" name="public_text" rows="3"></textarea></div>' +
      '<div class="lnkup-form-row"><label for="lnkup-rep-private-note">Private note (vendor cannot see)</label>' +
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
    var reason = prompt('Why are you reporting this review?');
    if (!reason) {
      return;
    }
    repFetch('/reviews/' + reviewId + '/report', {
      method: 'POST',
      body: { reason: reason }
    }).then(function () {
      alert('Report submitted. Our team will review it.');
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
