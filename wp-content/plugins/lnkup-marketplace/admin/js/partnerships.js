(function () {
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.matches || !form.matches('.lnkup-partnerships-admin form[data-lnkup-confirm]')) {
      return;
    }
    if (!window.confirm(form.getAttribute('data-lnkup-confirm'))) {
      event.preventDefault();
      return;
    }
    var button = form.querySelector('button, [type="submit"]');
    if (button) {
      button.disabled = true;
    }
  });
})();
