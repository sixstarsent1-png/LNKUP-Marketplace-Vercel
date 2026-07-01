(function () {
  var ajax = window.lnkupAjax || {};
  var base = ajax.financialRestUrl || '';
  var nonce = ajax.restNonce || '';
  var stripeKey = ajax.stripePublicKey || '';
  var stripe = null;
  var elements = null;
  var paymentElement = null;
  var currentPaymentId = 0;
  var currentIntentId = '';

  function finFetch(path, options) {
    options = options || {};
    var headers = { 'X-WP-Nonce': nonce };
    if (options.body && typeof options.body === 'object') {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }
    return fetch(base + path, Object.assign({ credentials: 'same-origin', headers: headers }, options))
      .then(function (r) { return r.json(); });
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function friendlyCheckoutError() {
    if (window.LNKupUI && LNKupUI.t) {
      return LNKupUI.t('checkout_error_generic', 'We could not complete this payment. Try again or use a different payment method.');
    }
    var copy = window.lnkupUiCopy || {};
    return copy.checkout_error_generic || 'We could not complete this payment. Try again or use a different payment method.';
  }

  function formatMoney(n) {
    return '$' + Number(n || 0).toFixed(2);
  }

  function getModal() {
    var modal = document.getElementById('lnkup-checkout-modal');
    if (modal) {
      return modal;
    }
    modal = document.createElement('div');
    modal.id = 'lnkup-checkout-modal';
    modal.className = 'lnkup-checkout-modal';
    modal.innerHTML = '<div class="lnkup-checkout-modal__backdrop"></div><div class="lnkup-checkout-modal__dialog" role="dialog" aria-modal="true"><button type="button" class="lnkup-checkout-modal__close" aria-label="Close">&times;</button><div class="lnkup-checkout-steps"><span class="lnkup-checkout-step is-active" data-step="1">1. Summary</span><span class="lnkup-checkout-step" data-step="2">2. Payment</span><span class="lnkup-checkout-step" data-step="3">3. Confirm</span></div><div class="lnkup-checkout-body"></div></div>';
    document.body.appendChild(modal);
    modal.querySelector('.lnkup-checkout-modal__backdrop').addEventListener('click', closeModal);
    modal.querySelector('.lnkup-checkout-modal__close').addEventListener('click', closeModal);
    return modal;
  }

  function closeModal() {
    var modal = document.getElementById('lnkup-checkout-modal');
    if (modal) {
      modal.classList.remove('is-open');
    }
    if (paymentElement) {
      try { paymentElement.unmount(); } catch (e) { /* noop */ }
      paymentElement = null;
    }
  }

  function setStep(step) {
    var modal = getModal();
    modal.querySelectorAll('.lnkup-checkout-step').forEach(function (el) {
      el.classList.toggle('is-active', el.getAttribute('data-step') === String(step));
    });
  }

  function renderSummary(data) {
    var b = data.breakdown || {};
    var html = '<div class="lnkup-checkout-summary">';
    if (data.event && data.event.image) {
      html += '<img class="lnkup-checkout-summary__image" src="' + escapeHtml(data.event.image) + '" alt="">';
    }
    html += '<h2>' + escapeHtml(data.event && data.event.title) + '</h2>';
    html += '<p class="lnkup-muted">Organizer: ' + escapeHtml(data.organizer && data.organizer.name) + '</p>';
    if (data.invoice_number) {
      html += '<p>Invoice ' + escapeHtml(data.invoice_number) + '</p>';
    }
    html += '<div class="lnkup-checkout-breakdown">';
    html += '<div class="lnkup-checkout-line"><span>Booth reservation</span><span>' + formatMoney(b.booth_reservation_total != null ? b.booth_reservation_total : (Number(b.vendor_fee || 0) + Number(b.vendor_checkout_fee || 0))) + '</span></div>';
    html += '<div class="lnkup-checkout-line"><span>Processing</span><span>' + formatMoney(b.processing_fee) + '</span></div>';
    if (b.tax) {
      html += '<div class="lnkup-checkout-line"><span>Tax</span><span>' + formatMoney(b.tax) + '</span></div>';
    }
    if (b.credit_applied > 0) {
      html += '<div class="lnkup-checkout-line lnkup-checkout-line--credit"><span>Credits applied</span><span>-' + formatMoney(b.credit_applied) + '</span></div>';
    }
    html += '<div class="lnkup-checkout-line lnkup-checkout-line--total"><span>Total</span><span>' + formatMoney(b.total) + '</span></div>';
    html += '</div>';
    if (b.credit_available > 0 && !b.credit_applied) {
      html += '<label class="lnkup-checkout-credit"><input type="checkbox" id="lnkup-apply-wallet"> Apply wallet balance (' + formatMoney(b.credit_available) + ')</label>';
    }
    html += '<p class="lnkup-muted">' + escapeHtml(data.unlock_estimate || '') + '</p>';
    html += '<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-checkout-next" data-next="payment">Continue to payment</button>';
    html += '</div>';
    return html;
  }

  function renderPaymentStep() {
    return '<div class="lnkup-checkout-payment"><div id="lnkup-payment-element" class="lnkup-checkout-element"></div><div id="lnkup-checkout-errors" class="lnkup-checkout-errors" role="alert"></div><button type="button" class="lnkup-btn lnkup-btn--primary" id="lnkup-checkout-submit">Pay now</button></div>';
  }

  function renderConfirm() {
    return '<div class="lnkup-checkout-confirm"><div class="lnkup-checkout-confirm__icon">✓</div><h2>Payment complete</h2><p>Your packet will unlock automatically.</p><button type="button" class="lnkup-btn lnkup-btn--primary" id="lnkup-checkout-done">Done</button></div>';
  }

  function loadStripeJs() {
    return new Promise(function (resolve, reject) {
      if (window.Stripe) {
        resolve(window.Stripe);
        return;
      }
      var script = document.createElement('script');
      script.src = 'https://js.stripe.com/v3/';
      script.onload = function () { resolve(window.Stripe); };
      script.onerror = reject;
      document.head.appendChild(script);
    });
  }

  function mountPaymentElement(clientSecret, publishableKey) {
    var key = publishableKey || stripeKey;
    if (!key) {
      return Promise.reject(new Error('Stripe is not configured.'));
    }
    return loadStripeJs().then(function (StripeCtor) {
      stripe = StripeCtor(key);
      elements = stripe.elements({ clientSecret: clientSecret });
      paymentElement = elements.create('payment');
      paymentElement.mount('#lnkup-payment-element');
    });
  }

  function openCheckout(paymentId) {
    currentPaymentId = paymentId;
    var modal = getModal();
    var body = modal.querySelector('.lnkup-checkout-body');
    body.innerHTML = '<div class="lnkup-fin-skeleton"></div>';
    modal.classList.add('is-open');
    setStep(1);
    finFetch('/checkout/' + paymentId + '/summary').then(function (data) {
      if (data.code) {
        body.innerHTML = '<p class="lnkup-checkout-errors">' + escapeHtml(friendlyCheckoutError()) + '</p>';
        return;
      }
      body.innerHTML = renderSummary(data);
      var creditToggle = document.getElementById('lnkup-apply-wallet');
      if (creditToggle) {
        creditToggle.addEventListener('change', function () {
          if (creditToggle.checked) {
            finFetch('/checkout/' + paymentId + '/apply-credits', { method: 'POST', body: {} })
              .then(function (updated) {
                if (!updated.code) {
                  body.innerHTML = renderSummary(updated);
                  bindSummaryActions(paymentId);
                }
              });
          }
        });
      }
      bindSummaryActions(paymentId);
    });
  }

  function bindSummaryActions(paymentId) {
    var btn = document.querySelector('.lnkup-checkout-next');
    if (!btn) {
      return;
    }
    btn.addEventListener('click', function () {
      var body = document.querySelector('.lnkup-checkout-body');
      body.innerHTML = '<div class="lnkup-fin-skeleton"></div>';
      setStep(2);
      finFetch('/checkout/' + paymentId + '/prepare', { method: 'POST', body: {} }).then(function (prep) {
        if (prep.code) {
          body.innerHTML = '<p class="lnkup-checkout-errors">' + escapeHtml(friendlyCheckoutError()) + '</p>';
          return;
        }
        if (prep.mode === 'simulation' && prep.simulate_url) {
          window.location.href = prep.simulate_url;
          return;
        }
        if (prep.mode === 'stripe' && prep.url) {
          window.location.href = prep.url;
          return;
        }
        currentIntentId = prep.intent_id || '';
        body.innerHTML = renderPaymentStep();
        mountPaymentElement(prep.client_secret, prep.publishable_key).catch(function (err) {
          document.getElementById('lnkup-checkout-errors').textContent = err.message || 'Could not load payment form.';
        });
        document.getElementById('lnkup-checkout-submit').addEventListener('click', function () {
          var errEl = document.getElementById('lnkup-checkout-errors');
          errEl.textContent = '';
          if (!stripe || !elements) {
            if (prep.simulate_url) {
              window.location.href = prep.simulate_url;
            }
            return;
          }
          stripe.confirmPayment({
            elements: elements,
            confirmParams: { return_url: buildCheckoutReturnUrl(paymentId) },
            redirect: 'if_required'
          }).then(function (result) {
            if (result.error) {
              errEl.textContent = result.error.message;
              return;
            }
            var intentId = result.paymentIntent ? result.paymentIntent.id : currentIntentId;
            finalizeCheckoutOnServer(paymentId, intentId).then(function (res) {
              if (res.code) {
                errEl.textContent = res.message || 'Payment received but confirmation failed. Refresh the page.';
                return;
              }
              setStep(3);
              body.innerHTML = renderConfirm();
              document.getElementById('lnkup-checkout-done').addEventListener('click', function () {
                closeModal();
                window.location.reload();
              });
            });
          });
        });
      });
    });
  }

  function buildCheckoutReturnUrl(paymentId) {
    var url = new URL(window.location.href);
    url.searchParams.set('lnkup_checkout_return', '1');
    url.searchParams.set('lnkup_checkout_payment', String(paymentId));
    return url.toString();
  }

  function finalizeCheckoutOnServer(paymentId, intentId) {
    return finFetch('/checkout/' + paymentId + '/confirm', {
      method: 'POST',
      body: { intent_id: intentId || currentIntentId }
    });
  }

  function cleanCheckoutReturnParams() {
    var url = new URL(window.location.href);
    ['lnkup_checkout_return', 'lnkup_checkout_payment', 'payment_intent', 'payment_intent_client_secret', 'redirect_status'].forEach(function (key) {
      url.searchParams.delete(key);
    });
    window.history.replaceState({}, '', url.toString());
  }

  function handleStripeCheckoutReturn() {
    if (!base) {
      return;
    }
    var params = new URLSearchParams(window.location.search);
    if (params.get('lnkup_checkout_return') !== '1') {
      return;
    }
    var paymentId = params.get('lnkup_checkout_payment');
    var intentId = params.get('payment_intent');
    var redirectStatus = params.get('redirect_status');
    if (!paymentId || !intentId) {
      return;
    }
    if (redirectStatus !== 'succeeded' && redirectStatus !== 'processing') {
      cleanCheckoutReturnParams();
      return;
    }
    finalizeCheckoutOnServer(paymentId, intentId).then(function (res) {
      cleanCheckoutReturnParams();
      if (!res.code) {
        window.location.reload();
      }
    });
  }

  document.addEventListener('DOMContentLoaded', handleStripeCheckoutReturn);

  document.addEventListener('click', function (e) {
    var payBtn = e.target.closest('.lnkup-pay-btn');
    if (payBtn) {
      e.preventDefault();
      var paymentId = payBtn.getAttribute('data-payment-id');
      if (paymentId && base) {
        openCheckout(paymentId);
      }
    }
  });

  window.lnkupCheckout = { open: openCheckout, close: closeModal };
})();
