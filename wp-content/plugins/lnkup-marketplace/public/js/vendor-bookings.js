/**
 * My Bookings filters: submit the server-rendered filter form as soon as a select or date changes.
 * Everything else on the page is rendered by WordPress.
 */
(function () {
  'use strict';

  document.addEventListener('change', function (e) {
    var form = e.target && e.target.closest ? e.target.closest('[data-bookings-filters]') : null;
    if (!form || !/^(SELECT|INPUT)$/.test(e.target.tagName)) {
      return;
    }
    if (e.target.type === 'date' && e.target.value && !/^\d{4}-\d{2}-\d{2}$/.test(e.target.value)) {
      return;
    }
    form.setAttribute('aria-busy', 'true');
    form.submit();
  });
})();
