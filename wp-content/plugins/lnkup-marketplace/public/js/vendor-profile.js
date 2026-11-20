(function () {
  var ajax = window.lnkupAjax || {};
  var growthBase = ajax.growthRestUrl || '';

  function toast(message, type) {
    if (window.lnkupShowToast) {
      window.lnkupShowToast(message, type || 'success');
    }
  }

  function syncBusinessNameFields() {
    var source = document.getElementById('business_name');
    if (!source) {
      return;
    }
    var val = source.value.trim();
    document.querySelectorAll('.lnkup-profile-business-name-field').forEach(function (el) {
      el.value = val;
    });
  }

  function updatePreviewFromStrength(data) {
    if (!data) {
      return;
    }
    var strengthEl = document.getElementById('lnkup-preview-strength');
    if (strengthEl) {
      strengthEl.textContent = (data.score || 0) + '% complete · ' + (data.tier || '');
    }
    (data.sections || []).forEach(function (section) {
      var article = document.querySelector('[data-profile-section="' + section.key + '"]');
      if (!article) {
        return;
      }
      article.className = 'lnkup-profile-section lnkup-profile-section--' + (section.status || 'missing').replace(/_/g, '-');
      var pill = article.querySelector('[data-section-status]');
      if (pill) {
        pill.textContent = section.status_label || '';
      }
    });
  }

  function updatePreviewFields(form) {
    var nameInput = form.querySelector('[name="business_name"]') || document.getElementById('business_name');
    if (nameInput) {
      var nameEl = document.getElementById('lnkup-preview-name');
      if (nameEl) {
        nameEl.textContent = nameInput.value.trim() || 'Your business';
      }
    }
    var city = form.querySelector('[name="city"]');
    var state = form.querySelector('[name="state"]');
    if (city || state) {
      var locEl = document.getElementById('lnkup-preview-location');
      if (locEl) {
        var c = city ? city.value.trim() : '';
        var s = state ? state.value.trim() : '';
        locEl.textContent = c + (s ? (c ? ', ' : '') + s : '');
      }
    }
  }

  function postSectionForm(form) {
    syncBusinessNameFields();
    var data = new FormData(form);
    if (!data.get('business_name')) {
      var bn = document.getElementById('business_name');
      if (bn && bn.value.trim()) {
        data.set('business_name', bn.value.trim());
      }
    }
    data.set('action', 'lnkup_save_profile');
    return fetch(ajax.url, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  function bindSectionForms(root) {
    root.querySelectorAll('.lnkup-profile-section-form').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var btn = form.querySelector('[data-section-save]');
        var saved = form.querySelector('[data-section-saved]');
        var errBox = form.querySelector('[data-section-error]');
        if (btn) {
          btn.disabled = true;
        }
        if (errBox) {
          errBox.hidden = true;
        }
        postSectionForm(form).then(function (res) {
          if (btn) {
            btn.disabled = false;
          }
          var msg = (res.data && res.data.message) ? res.data.message : (res.success ? 'Saved.' : 'Error');
          if (res.success) {
            if (saved) {
              saved.hidden = false;
              setTimeout(function () { saved.hidden = true; }, 2500);
            }
            toast(msg, 'success');
            updatePreviewFields(form);
            syncBusinessNameFields();
            if (res.data && res.data.profile_strength) {
              updatePreviewFromStrength(res.data.profile_strength);
            }
          } else {
            if (errBox) {
              errBox.textContent = msg;
              errBox.hidden = false;
            }
            toast(msg, 'error');
          }
        }).catch(function () {
          if (btn) {
            btn.disabled = false;
          }
          toast('Something went wrong. Please try again.', 'error');
        });
      });
    });
  }

  function bindDocUpload(root) {
    root.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-doc-upload]');
      if (!btn) {
        return;
      }
      e.preventDefault();
      var type = btn.getAttribute('data-doc-upload');
      var form = document.getElementById('lnkup-document-upload-form');
      var typeField = document.getElementById('document_type');
      var fileInput = document.getElementById('lnkup_document');
      if (!form || !typeField || !fileInput) {
        return;
      }
      typeField.value = type;
      fileInput.click();
    });

    var fileInput = document.getElementById('lnkup_document');
    if (fileInput) {
      fileInput.addEventListener('change', function () {
        if (!fileInput.files || !fileInput.files.length) {
          return;
        }
        var form = document.getElementById('lnkup-document-upload-form');
        if (!form) {
          return;
        }
        if (typeof window.lnkupUploadDocumentForm === 'function') {
          window.lnkupUploadDocumentForm(form);
          return;
        }
        var data = new FormData(form);
        fetch(ajax.url, { method: 'POST', body: data, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            var msg = (res.data && res.data.message) ? res.data.message : (res.success ? 'Document uploaded.' : 'Upload failed.');
            toast(msg, res.success ? 'success' : 'error');
            if (res.success) {
              window.location.reload();
            }
          })
          .catch(function () {
            toast('Upload failed — check your connection and try again.', 'error');
          });
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('lnkup-vendor-profile-editor');
    if (!root) {
      return;
    }
    bindSectionForms(root);
    bindDocUpload(root);
    var businessName = document.getElementById('business_name');
    if (businessName) {
      businessName.addEventListener('input', syncBusinessNameFields);
    }
  });
})();
