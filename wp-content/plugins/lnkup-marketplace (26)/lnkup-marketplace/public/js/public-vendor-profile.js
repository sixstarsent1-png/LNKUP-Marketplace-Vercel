(function () {
  'use strict';

  var root = document.querySelector('[data-pub-gallery]');
  if (!root) {
    return;
  }

  var jsonEl = root.querySelector('[data-gallery-json]');
  var images = [];
  try {
    images = JSON.parse(jsonEl ? jsonEl.textContent : '[]') || [];
  } catch (e) {
    images = [];
  }

  var lightbox = root.querySelector('[data-gallery-lightbox]');
  var lightboxImg = root.querySelector('[data-gallery-lightbox-img]');

  function openAt(index) {
    if (!lightbox || !lightboxImg || !images[index]) {
      return;
    }
    lightboxImg.src = images[index];
    lightbox.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closeLightbox() {
    if (!lightbox) {
      return;
    }
    lightbox.hidden = true;
    document.body.style.overflow = '';
  }

  root.querySelectorAll('[data-gallery-index]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openAt(parseInt(btn.getAttribute('data-gallery-index'), 10) || 0);
    });
  });

  var closeBtn = root.querySelector('[data-gallery-close]');
  if (closeBtn) {
    closeBtn.addEventListener('click', closeLightbox);
  }
  if (lightbox) {
    lightbox.addEventListener('click', function (e) {
      if (e.target === lightbox) {
        closeLightbox();
      }
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeLightbox();
    }
  });
})();
