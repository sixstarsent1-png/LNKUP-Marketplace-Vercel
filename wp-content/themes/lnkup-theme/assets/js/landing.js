document.addEventListener('DOMContentLoaded', function () {
  var launchBar = document.getElementById('lnkup-launch-bar');
  var dismissBtn = document.getElementById('lnkup-launch-bar-dismiss');
  var storageKey = 'lnkup_launch_bar_dismissed';

  if (launchBar && dismissBtn) {
    try {
      if (localStorage.getItem(storageKey) === '1') {
        launchBar.hidden = true;
      }
    } catch (e) {
      /* ignore */
    }
    dismissBtn.addEventListener('click', function () {
      launchBar.hidden = true;
      try {
        localStorage.setItem(storageKey, '1');
      } catch (err) {
        /* ignore */
      }
    });
  }

  var countdownRoot = document.getElementById('launch-countdown');
  if (countdownRoot && window.lnkupLaunch && window.lnkupLaunch.launchIso) {
    var launchMs = Date.parse(window.lnkupLaunch.launchIso);
    var units = countdownRoot.querySelectorAll('[data-unit]');

    function pad(n) {
      return n < 10 ? '0' + n : String(n);
    }

    function tick() {
      var diff = launchMs - Date.now();
      if (diff <= 0) {
        units.forEach(function (el) {
          el.textContent = '00';
        });
        return;
      }
      var totalSeconds = Math.floor(diff / 1000);
      var days = Math.floor(totalSeconds / 86400);
      var hours = Math.floor((totalSeconds % 86400) / 3600);
      var minutes = Math.floor((totalSeconds % 3600) / 60);
      var seconds = totalSeconds % 60;
      units.forEach(function (el) {
        var unit = el.getAttribute('data-unit');
        if (unit === 'days') el.textContent = pad(days);
        if (unit === 'hours') el.textContent = pad(hours);
        if (unit === 'minutes') el.textContent = pad(minutes);
        if (unit === 'seconds') el.textContent = pad(seconds);
      });
      window.requestAnimationFrame(function () {
        setTimeout(tick, 1000);
      });
    }
    tick();
  }

  if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    var revealEls = document.querySelectorAll('.lnkup-reveal');
    if (revealEls.length && 'IntersectionObserver' in window) {
      var observer = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              entry.target.classList.add('is-visible');
              observer.unobserve(entry.target);
            }
          });
        },
        { threshold: 0.12, rootMargin: '0px 0px -40px 0px' }
      );
      revealEls.forEach(function (el) {
        observer.observe(el);
      });
    } else {
      revealEls.forEach(function (el) {
        el.classList.add('is-visible');
      });
    }
  } else {
    document.querySelectorAll('.lnkup-reveal').forEach(function (el) {
      el.classList.add('is-visible');
    });
  }

  document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
    anchor.addEventListener('click', function (e) {
      var id = anchor.getAttribute('href');
      if (!id || id.length < 2) return;
      var target = document.querySelector(id);
      if (!target) return;
      e.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  var roleToggle = document.querySelector('.lnkup-signup-form__role-toggle');
  if (roleToggle) {
    roleToggle.addEventListener('change', function (e) {
      if (e.target && e.target.name === 'lnkup_role') {
        roleToggle.querySelectorAll('.lnkup-signup-form__role-option').forEach(function (opt) {
          opt.classList.toggle('is-active', opt.contains(e.target));
        });
      }
    });
  }

  var floatersRoot = document.getElementById('lnkup-hero-floaters');
  if (floatersRoot && !window.matchMedia('(prefers-reduced-motion: reduce)').matches && window.matchMedia('(min-width: 1024px)').matches) {
    var floaters = floatersRoot.querySelectorAll('.lnkup-hero__floater');
    document.querySelector('.lnkup-hero')?.addEventListener('mousemove', function (e) {
      var rect = floatersRoot.getBoundingClientRect();
      var x = (e.clientX - rect.left) / rect.width - 0.5;
      var y = (e.clientY - rect.top) / rect.height - 0.5;
      floaters.forEach(function (el, i) {
        var depth = (i + 1) * 4;
        el.style.transform = 'translate(' + x * depth + 'px, ' + y * depth + 'px)';
      });
    });
  }
});
