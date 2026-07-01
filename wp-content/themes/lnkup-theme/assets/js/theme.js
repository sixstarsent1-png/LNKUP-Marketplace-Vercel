document.addEventListener('DOMContentLoaded', function () {
  var toggle = document.getElementById('lnkup-mobile-nav-toggle');
  var nav = document.getElementById('lnkup-mobile-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
      nav.hidden = open;
    });
  }
});
