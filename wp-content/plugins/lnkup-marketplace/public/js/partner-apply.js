(function () {
  var config = window.lnkupPartnerApply || {};
  var API_ERROR = 'We couldn\'t submit your application. Please try again or contact support.';

  function setStatus(el, message, ok) {
    if (!el) {
      return;
    }
    el.textContent = message;
    el.className = 'lnkup-connector-interest-status ' + (ok ? 'is-success' : 'is-error');
  }

  function bind(form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var status = form.querySelector('[data-apply-status]');
      var button = form.querySelector('[type="submit"]');
      var payload = {};
      new FormData(form).forEach(function (value, key) {
        payload[key] = String(value).trim();
      });
      payload.disclosures = form.querySelector('[name="disclosures"]').checked ? 1 : 0;
      if (!payload.name || !payload.email || !payload.city) {
        setStatus(status, 'Please add your name, business email and city.', false);
        return;
      }
      if (form.getAttribute('data-program') === 'strategic_partner' && !payload.organization) {
        setStatus(status, 'Please add your organization.', false);
        return;
      }
      if (!payload.disclosures) {
        setStatus(status, 'Please agree to the program terms and disclosures.', false);
        return;
      }
      if (button) {
        button.disabled = true;
      }
      setStatus(status, 'Submitting…', true);
      fetch(config.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload)
      }).then(function (response) {
        var type = response.headers.get('content-type') || '';
        if (type.indexOf('application/json') === -1) {
          throw new Error(API_ERROR);
        }
        return response.json().then(function (body) {
          if (!response.ok) {
            throw new Error(body.message || API_ERROR);
          }
          return body;
        });
      }).then(function (body) {
        setStatus(status, body.message || 'Thanks! We received your application.', true);
        form.reset();
      }).catch(function (error) {
        console.error('LNKup partner application error:', error);
        setStatus(status, error && error.message ? error.message : API_ERROR, false);
      }).finally(function () {
        if (button) {
          button.disabled = false;
        }
      });
    });
  }

  document.querySelectorAll('[data-testid="lnkup-partner-apply-form"]').forEach(bind);
})();
