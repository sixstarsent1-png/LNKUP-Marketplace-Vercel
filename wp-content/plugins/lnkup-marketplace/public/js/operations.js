(function () {
  var ajax = window.lnkupAjax || {};
  var base = ajax.operationsRestUrl || '';
  var packetsBase = ajax.packetsRestUrl || '';
  var nonce = ajax.restNonce || '';
  var pollTimer = null;
  var refreshSeq = 0;

  /**
   * Safe JSON fetch — rejects HTML error pages and non-OK responses.
   */
  function safeJsonFetch(url, options) {
    options = options || {};
    var headers = Object.assign({ 'X-WP-Nonce': nonce }, options.headers || {});
    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }
    return fetch((url.indexOf('http') === 0 ? '' : base) + url, Object.assign({ credentials: 'same-origin', headers: headers }, options))
      .then(function (r) {
        var ct = r.headers.get('content-type') || '';
        if (!r.ok || ct.indexOf('application/json') === -1) {
          return r.text().then(function (body) {
            console.error('Operations API error', r.status, body.slice(0, 300));
            var err = new Error('api_error');
            if (ct.indexOf('application/json') !== -1) {
              try {
                var parsed = JSON.parse(body);
                if (parsed && parsed.message) {
                  err = new Error(parsed.message);
                  err.code = parsed.code || '';
                }
              } catch (e) {}
            }
            throw err;
          });
        }
        return r.json();
      });
  }

  /**
   * Operations REST fetch helper.
   */
  function opsFetch(path, options) {
    return safeJsonFetch(path, options);
  }

  function packetsFetch(path, options) {
    options = options || {};
    var headers = Object.assign({ 'X-WP-Nonce': nonce }, options.headers || {});
    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }
    var url = path.indexOf('http') === 0 ? path : packetsBase + path;
    return fetch(url, Object.assign({ credentials: 'same-origin', headers: headers }, options))
      .then(function (r) {
        var ct = r.headers.get('content-type') || '';
        if (!r.ok || ct.indexOf('application/json') === -1) {
          return r.text().then(function (body) {
            console.error('Packets API error', r.status, body.slice(0, 300));
            throw new Error('packets_api_error');
          });
        }
        return r.json();
      });
  }

  window.lnkupOpsFetch = opsFetch;
  window.lnkupPacketsFetch = packetsFetch;

  /**
   * Escape HTML for safe insertion.
   */
  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  /** Relative `<time>` markup for a log timestamp (escaped raw text without the OS helpers). */
  function opsWhen(value) {
    if (!value) {
      return '';
    }
    return window.LNKupOS && window.LNKupOS.timeHtml ? window.LNKupOS.timeHtml(value) : escapeHtml(value);
  }

  /**
   * Render QR code image into container.
   */
  function renderQrCodes() {
    document.querySelectorAll('.lnkup-ops-qr[data-qr]').forEach(function (el) {
      if (el.querySelector('img')) {
        return;
      }
      var token = el.getAttribute('data-qr');
      if (!token) {
        return;
      }
      var src = window.LNKupQR ? window.LNKupQR.dataUrl(token, 200) : '';
      if (!src) {
        return;
      }
      var img = document.createElement('img');
      img.src = src;
      img.alt = 'Vendor QR Pass';
      el.appendChild(img);
    });
  }
  window.lnkupRenderQrCodes = renderQrCodes;

  /**
   * Activate packet section within a packet wrapper.
   */
  function activatePacketSection(wrap, section) {
    if (!wrap || !section) {
      return;
    }
    wrap.querySelectorAll('.lnkup-ops-packet-tab').forEach(function (t) {
      t.classList.toggle('is-active', t.getAttribute('data-section') === section);
    });
    wrap.querySelectorAll('.lnkup-ops-packet__panel').forEach(function (p) {
      p.classList.toggle('is-active', p.getAttribute('data-section-panel') === section);
    });
    var mobile = wrap.querySelector('.lnkup-ops-packet__mobile-select');
    if (mobile) {
      mobile.value = section;
    }
  }

  /**
   * Bind packet tab switching and engagement tracking.
   */
  function initPacketTabs() {
    var urlSection = '';
    try {
      var params = new URLSearchParams(window.location.search);
      urlSection = params.get('section') || '';
    } catch (e) {}

    document.querySelectorAll('.lnkup-ops-packet').forEach(function (wrap) {
      var appId = wrap.getAttribute('data-app-id');
      if (urlSection) {
        activatePacketSection(wrap, urlSection);
      }
      wrap.querySelectorAll('.lnkup-ops-packet-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
          var section = tab.getAttribute('data-section');
          activatePacketSection(wrap, section);
          if (appId && section) {
            opsFetch('/applications/' + appId + '/packet/engage', {
              method: 'POST',
              body: { section: section, action: 'viewed' }
            }).catch(function () {});
          }
        });
      });
      var mobile = wrap.querySelector('.lnkup-ops-packet__mobile-select');
      if (mobile) {
        mobile.addEventListener('change', function () {
          activatePacketSection(wrap, mobile.value);
          if (appId && mobile.value) {
            opsFetch('/applications/' + appId + '/packet/engage', {
              method: 'POST',
              body: { section: mobile.value, action: 'viewed' }
            }).catch(function () {});
          }
        });
      }
      wrap.querySelectorAll('.lnkup-ops-packet-ack').forEach(function (btn) {
        btn.addEventListener('click', function () {
          if (btn.disabled) {
            return;
          }
          var section = btn.getAttribute('data-section');
          var label = btn.textContent;
          btn.disabled = true;
          btn.textContent = 'Saving…';
          opsFetch('/applications/' + appId + '/packet/engage', {
            method: 'POST',
            body: { section: section, action: 'acknowledged' }
          }).then(function () {
            btn.textContent = 'Accepted';
          }).catch(function () {
            btn.disabled = false;
            btn.textContent = label;
            btn.setAttribute('title', 'Could not save — try again.');
          });
        });
      });
    });
    renderQrCodes();
  }

  /**
   * Load vendor operations home summary — augment PHP-rendered overview when present.
   */
  function loadVendorHome() {
    var el = document.getElementById('lnkup-vendor-ops-home');
    if (!el || !base) {
      return;
    }
    var appId = el.getAttribute('data-app-id');
    var hasOverview = el.querySelector('.lnkup-ops-overview-grid');
      if (hasOverview) {
      renderQrCodes();
      if (appId) {
        opsFetch('/vendor-home?application_id=' + encodeURIComponent(appId)).then(function (data) {
          if (!data || data.application_id !== parseInt(appId, 10)) {
            return;
          }
          var badge = document.getElementById('lnkup-ops-unread-broadcasts');
          if (data.unread_broadcasts) {
            if (!badge) {
              var card = el.querySelector('.lnkup-ops-vendor-card');
              if (card) {
                badge = document.createElement('p');
                badge.id = 'lnkup-ops-unread-broadcasts';
                badge.className = 'lnkup-badge lnkup-badge--accent';
                card.appendChild(badge);
              }
            }
            if (badge) {
              badge.textContent = data.unread_broadcasts + ' new message' + (data.unread_broadcasts === 1 ? '' : 's');
            }
          } else if (badge) {
            badge.remove();
          }
        }).catch(function () {});
      }
      return;
    }
    var path = '/vendor-home';
    opsFetch(path).then(function (data) {
      if (!data || !data.event_title) {
        el.innerHTML = '<p class="lnkup-muted">No upcoming event operations.</p>';
        return;
      }
      var html = '<div class="lnkup-ops-vendor-home">';
      html += '<article class="lnkup-ops-vendor-card">';
      html += '<h3>' + escapeHtml(data.event_title) + '</h3>';
      html += '<p class="lnkup-muted">' + escapeHtml(data.event_date || '') + '</p>';
      if (data.readiness_percent != null) {
        html += '<p><strong>' + escapeHtml(String(data.readiness_percent)) + '%</strong> ready</p>';
      }
      if (data.countdown_label) {
        html += '<p>' + escapeHtml(data.countdown_label) + '</p>';
      }
      if (data.unread_broadcasts) {
        html += '<p class="lnkup-badge lnkup-badge--accent">' + escapeHtml(String(data.unread_broadcasts)) + ' new messages</p>';
      }
      html += '</article>';
      html += '<div class="lnkup-ops-overview-grid">';
      html += renderOpsDetailCard('Booth', data.booth ? (data.booth.booth_number || '') + ' ' + (data.booth.booth_type_label || '') : 'Not assigned yet');
      html += renderOpsDetailCard('Parking', data.parking ? (data.parking.label || '') : 'Not assigned yet');
      html += renderOpsDetailCard('Load-in', data.loadin ? (data.loadin.label || '') : 'Not scheduled yet');
      if (data.qr_pass) {
        html += '<div class="lnkup-ops-panel lnkup-ops-panel--qr"><h4 class="lnkup-ops-panel__title">Check-in QR</h4>';
        html += '<div class="lnkup-ops-qr lnkup-ops-qr--compact" data-qr="' + escapeHtml(data.qr_pass) + '"></div></div>';
      }
      if (data.weather && typeof data.weather === 'object' && (data.weather.summary || data.weather.forecast_status)) {
        html += '<div class="lnkup-ops-panel lnkup-ops-panel--weather" data-testid="lnkup-vendor-weather-note">';
        html += '<h4 class="lnkup-ops-panel__title">Weather</h4>';
        if (data.weather.forecast_status) {
          html += '<p class="lnkup-weather-vendor-note__status">' + escapeHtml(data.weather.forecast_status) + '</p>';
        }
        if (data.weather.summary) {
          html += '<p class="lnkup-muted">' + escapeHtml(data.weather.summary) + '</p>';
        }
        if (data.weather.recommendations && data.weather.recommendations.length) {
          html += '<ul class="lnkup-weather-recs">';
          data.weather.recommendations.forEach(function (rec) {
            html += '<li>' + escapeHtml(rec) + '</li>';
          });
          html += '</ul>';
        }
        if (data.weather.contingency) {
          html += '<p class="lnkup-muted">' + escapeHtml(data.weather.contingency) + '</p>';
        }
        html += '</div>';
      }
      html += '</div></div>';
      el.innerHTML = html;
      renderQrCodes();
    }).catch(function () {
      el.innerHTML = '<p class="lnkup-muted">We couldn\'t load this section. Try refreshing or contact support.</p>';
    });
  }

  function renderOpsDetailCard(title, body) {
    return '<div class="lnkup-ops-panel"><h4 class="lnkup-ops-panel__title">' + escapeHtml(title) +
      '</h4><p class="lnkup-muted">' + escapeHtml(body) + '</p></div>';
  }

  /**
   * Load vendor support request history.
   */
  function loadVendorSupportHistory() {
    var panel = document.getElementById('lnkup-vendor-support-history');
    if (!panel) {
      return;
    }
    var wrap = document.getElementById('lnkup-vendor-support-panel');
    var appId = wrap ? wrap.getAttribute('data-app-id') : '';
    if (!appId) {
      if (window.LNKupUI && LNKupUI.panelEmpty) {
        LNKupUI.panelEmpty(panel, {
          title: 'No application selected',
          desc: 'Choose an event to view support requests.',
          compact: true
        });
      } else {
        panel.innerHTML = '<p class="lnkup-muted">No application selected.</p>';
      }
      return;
    }
    if (window.LNKupUI && LNKupUI.panelSkeleton) {
      LNKupUI.panelSkeleton(panel, 1);
    }
    opsFetch('/applications/' + appId + '/support').then(function (res) {
      var items = (res && res.items) ? res.items : [];
      if (!items.length) {
        if (window.LNKupUI && LNKupUI.panelEmpty) {
          LNKupUI.panelEmpty(panel, {
            title: 'No support requests yet',
            desc: 'Need help on event day? Start a new request above.',
            compact: true
          });
        } else {
          panel.innerHTML = '<p class="lnkup-muted">No support requests yet.</p>';
        }
        return;
      }
      var html = '';
      items.forEach(function (item) {
        html += '<div class="lnkup-ops-support-item lnkup-surface--secondary">';
        html += '<div class="lnkup-ops-support-item__meta">';
        html += '<span class="lnkup-badge">' + escapeHtml(item.category_label || item.category || '') + '</span>';
        html += '<span class="lnkup-muted">' + escapeHtml(item.status || '') + '</span>';
        html += '<span class="lnkup-muted">' + opsWhen(item.created_at) + '</span>';
        html += '</div>';
        html += '<p>' + escapeHtml(item.message || '') + '</p>';
        if (item.assigned_staff) {
          html += '<p class="lnkup-muted">Assigned: ' + escapeHtml(item.assigned_staff) + '</p>';
        }
        html += '</div>';
      });
      panel.innerHTML = html;
    }).catch(function () {
      if (window.LNKupUI && LNKupUI.panelError) {
        LNKupUI.panelError(panel, {
          title: 'Could not load support history',
          desc: 'Try refreshing or contact support.'
        });
      } else {
        panel.innerHTML = '<p class="lnkup-muted">Could not load support history.</p>';
      }
    });
  }

  /**
   * Append ops widgets to organizer CRM grid.
   */
  function loadOpsWidgets() {
    var grid = document.getElementById('lnkup-crm-widgets-grid');
    if (!grid || !base) {
      return;
    }
    opsFetch('/dashboard/widgets').then(function (data) {
      if (!data) {
        return;
      }
      var html = '';
      if (data.open_support != null) {
        html += '<div class="lnkup-crm-widget lnkup-crm-widget--ops"><h3>Open Support</h3><p class="lnkup-crm-widget__value">' + escapeHtml(String(data.open_support)) + '</p></div>';
      }
      if (data.open_incidents != null) {
        html += '<div class="lnkup-crm-widget lnkup-crm-widget--ops"><h3>Open Incidents</h3><p class="lnkup-crm-widget__value">' + escapeHtml(String(data.open_incidents)) + '</p></div>';
      }
      if (data.today_events && data.today_events.length) {
        html += '<div class="lnkup-crm-widget lnkup-crm-widget--wide lnkup-crm-widget--ops"><h3>Today\'s Events</h3><ul class="lnkup-crm-widget-list">';
        data.today_events.forEach(function (ev) {
          var url = (ajax.organizerDashboardUrl || '') + '?event=' + ev.event_id + '&ops=1';
          html += '<li><a href="' + escapeHtml(url) + '">' + escapeHtml(ev.title) + '</a>';
          if (ev.kpis) {
            html += ' — ' + escapeHtml(String(ev.kpis.checked_in || 0)) + ' checked in';
          }
          html += '</li>';
        });
        html += '</ul></div>';
      }
      if (html) {
        grid.insertAdjacentHTML('beforeend', html);
      }
    }).catch(function () {});
  }

  function updateOpsUrl(root, tabKey) {
    if (!root || !tabKey || !window.history || !window.URLSearchParams) {
      return;
    }
    var eventId = root.getAttribute('data-event-id');
    if (!eventId) {
      return;
    }
    var params = new URLSearchParams(window.location.search);
    params.set('event', eventId);
    params.set('ops', '1');
    params.set('ops_tab', tabKey);
    var next = window.location.pathname + '?' + params.toString();
    window.history.replaceState({}, '', next);
  }

  function showOpsSection(root, sectionName, activateFirst) {
    root.querySelectorAll('.lnkup-ops-section').forEach(function (btn) {
      btn.classList.toggle('is-active', btn.getAttribute('data-section') === sectionName);
    });
    root.querySelectorAll('.lnkup-ops-tab[data-section]').forEach(function (tab) {
      var visible = tab.getAttribute('data-section') === sectionName;
      tab.classList.toggle('is-section-visible', visible);
    });
    var mobileSelect = root.querySelector('#lnkup-ops-section-select');
    if (mobileSelect) {
      mobileSelect.value = sectionName;
    }
    if (activateFirst) {
      var firstTab = root.querySelector('.lnkup-ops-tab[data-section="' + sectionName + '"].is-section-visible');
      if (firstTab) {
        activateOpsTab(root, firstTab.getAttribute('data-ops-tab'), false);
      }
    }
  }

  function activateOpsTab(root, key, updateUrl) {
    var tab = root.querySelector('.lnkup-ops-tab[data-ops-tab="' + key + '"]');
    var panel = root.querySelector('[data-ops-panel="' + key + '"]');
    if (!tab || !panel) {
      return;
    }
    root.querySelectorAll('.lnkup-ops-tab').forEach(function (t) {
      t.classList.remove('is-active');
      t.setAttribute('aria-selected', 'false');
    });
    root.querySelectorAll('.lnkup-ops-tab-panel').forEach(function (p) { p.classList.remove('is-active'); });
    tab.classList.add('is-active');
    tab.setAttribute('aria-selected', 'true');
    panel.classList.add('is-active');
    showOpsSection(root, tab.getAttribute('data-section') || 'command', false);
    if (false !== updateUrl) {
      updateOpsUrl(root, key);
    }
    document.dispatchEvent(new CustomEvent('lnkup-ops-panel-open', {
      detail: {
        tab: key,
        section: tab.getAttribute('data-section') || 'command',
        eventId: root.getAttribute('data-event-id')
      }
    }));

    if (key === 'booths') {
      loadBooths(root);
    } else if (key === 'parking') {
      loadParking(root);
    } else if (key === 'loadin') {
      loadLoadin(root);
    } else if (key === 'vendor-pass') {
      loadVendorPassBuilder(root);
    } else if (key === 'support') {
      loadSupport(root);
    } else if (key === 'incidents') {
      loadIncidents(root);
    } else if (key === 'comms') {
      loadBroadcasts(root);
    } else if (key === 'analytics') {
      loadAnalytics(root);
    } else if (key === 'map') {
      initVenueMap(root);
    } else if (key === 'map-builder' || key === 'live-map') {
      if (window.lnkupMapsInitShells) {
        window.lnkupMapsInitShells();
      }
    }
  }

  /**
   * Switch command center tabs.
   */
  function initOpsTabs(root) {
    root.querySelectorAll('.lnkup-ops-section').forEach(function (btn) {
      btn.addEventListener('click', function () {
        showOpsSection(root, btn.getAttribute('data-section'), true);
      });
    });
    var sectionSelect = root.querySelector('#lnkup-ops-section-select');
    if (sectionSelect) {
      sectionSelect.addEventListener('change', function () {
        showOpsSection(root, sectionSelect.value, true);
      });
    }
    root.querySelectorAll('.lnkup-ops-goto-section').forEach(function (btn) {
      btn.addEventListener('click', function () {
        showOpsSection(root, btn.getAttribute('data-ops-section'), true);
      });
    });
    root.querySelectorAll('.lnkup-ops-goto-tab').forEach(function (btn) {
      btn.addEventListener('click', function () {
        activateOpsTab(root, btn.getAttribute('data-ops-tab'));
      });
    });
    root.querySelectorAll('.lnkup-ops-tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        activateOpsTab(root, tab.getAttribute('data-ops-tab'));
      });
    });

    var initialKey = '';
    try {
      var params = new URLSearchParams(window.location.search);
      initialKey = params.get('ops_tab') || params.get('tab') || '';
    } catch (e) {}
    var initialTab = root.querySelector('.lnkup-ops-tab[data-ops-tab="' + initialKey + '"]') || root.querySelector('.lnkup-ops-tab.is-active');
    if (initialTab) {
      activateOpsTab(root, initialTab.getAttribute('data-ops-tab'), false);
    }
  }

  function renderPassCompleteness(el, data) {
    if (!el || !data) {
      return;
    }
    var items = data.items || [];
    var html = '<p><strong>' + escapeHtml(String(data.complete || 0)) + ' / ' + escapeHtml(String(data.total || items.length)) + '</strong> ' + escapeHtml('complete') + '</p>';
    html += '<ul class="lnkup-ops-pass-completeness__list">';
    items.forEach(function (item) {
      html += '<li class="lnkup-ops-pass-completeness__item' + (item.complete ? ' is-complete' : '') + '">' +
        (item.complete ? '✓ ' : '○ ') + escapeHtml(item.label || '') + '</li>';
    });
    html += '</ul>';
    el.innerHTML = html;
  }

  function loadVendorPassBuilder(root) {
    var eventId = root.getAttribute('data-event-id');
    var completenessEl = root.querySelector('#lnkup-ops-pass-completeness');
    if (!eventId || !completenessEl) {
      return;
    }
    packetsFetch('/event/' + eventId + '/completeness').then(function (data) {
      renderPassCompleteness(completenessEl, data);
    }).catch(function () {
      if (window.LNKupUI && LNKupUI.panelError) {
        LNKupUI.panelError(completenessEl, { onRetry: function () { loadVendorPassBuilder(root); } });
      }
    });
  }

  function initVendorPassForm(root) {
    var form = root.querySelector('#lnkup-ops-vendor-pass-form');
    if (!form || form.dataset.bound) {
      return;
    }
    form.dataset.bound = '1';
    var eventId = root.getAttribute('data-event-id');
    var packetId = root.getAttribute('data-pass-packet-id');
    var statusEl = root.querySelector('#lnkup-ops-pass-save-status');
    var previewBtn = root.querySelector('#lnkup-ops-pass-preview');

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(form);
      var body = {
        venue_address: fd.get('venue_address') || '',
        vendor_gate: fd.get('vendor_gate') || '',
        loadin_entrance: fd.get('loadin_entrance') || '',
        load_in_time: fd.get('load_in_time') || '',
        load_out_time: fd.get('load_out_time') || '',
        parking_info: fd.get('parking_info') || '',
        vendor_rules: fd.get('vendor_rules') || '',
        setup_restrictions: fd.get('setup_restrictions') || '',
        prohibited_items: fd.get('prohibited_items') || '',
        refund_policy: fd.get('refund_policy') || '',
        weather_placeholder: fd.get('weather_placeholder') || '',
        rain_plan: fd.get('rain_plan') || '',
        heat_plan: fd.get('heat_plan') || '',
        day_of_contact: {
          name: fd.get('day_of_name') || '',
          phone: fd.get('day_of_phone') || '',
          email: fd.get('day_of_email') || ''
        }
      };
      var savePath = packetId ? '/' + packetId + '/pass-details' : '/event/' + eventId + '/pass-details';
      packetsFetch(savePath, { method: 'POST', body: body }).then(function (res) {
        if (statusEl) {
          statusEl.textContent = (window.lnkupCopy && lnkupCopy.pass_builder_saved) || 'Pass details saved';
        }
        if (res && res.completeness) {
          renderPassCompleteness(root.querySelector('#lnkup-ops-pass-completeness'), res.completeness);
        } else {
          loadVendorPassBuilder(root);
        }
      }).catch(function () {
        if (statusEl) {
          statusEl.textContent = 'Could not save pass details.';
        }
      });
    });

    if (previewBtn) {
      previewBtn.addEventListener('click', function () {
        if (!packetId) {
          if (statusEl) {
            statusEl.textContent = 'Preview is available after a vendor pays and a pass is created.';
          }
          return;
        }
        packetsFetch('/' + packetId + '/preview').then(function (pass) {
          var html = '<div class="lnkup-ops-pass-preview-modal">';
          html += '<p><strong>' + escapeHtml(pass.event && pass.event.title ? pass.event.title : '') + '</strong></p>';
          if (pass.event && pass.event.address) {
            html += '<p>' + escapeHtml(pass.event.address) + '</p>';
          }
          if (pass.booth && pass.booth.assignment) {
            html += '<p>Booth: ' + escapeHtml(pass.booth.assignment) + '</p>';
          }
          if (pass.qr_pass && pass.qr_pass.code) {
            html += '<p>Pass ID: <code>' + escapeHtml(pass.qr_pass.code) + '</code></p>';
          }
          html += '</div>';
          var overlay = document.createElement('div');
          overlay.className = 'lnkup-ops-support-slide';
          overlay.innerHTML = '<div class="lnkup-ops-support-slide__panel"><h3>Pass preview</h3>' + html +
            '<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-ops-form-cancel">Close</button></div>';
          document.body.appendChild(overlay);
          overlay.querySelector('.lnkup-ops-form-cancel').addEventListener('click', function () {
            overlay.remove();
          });
          overlay.addEventListener('click', function (e) {
            if (e.target === overlay) {
              overlay.remove();
            }
          });
        }).catch(function () {
          if (statusEl) {
            statusEl.textContent = 'Could not load preview.';
          }
        });
      });
    }
  }

  /**
   * Refresh KPIs and feed via polling.
   */
  function refreshCommandCenter(root) {
    var eventId = root.getAttribute('data-event-id');
    if (!eventId) {
      return;
    }
    var seq = ++refreshSeq;
    opsFetch('/events/' + eventId).then(function (snap) {
      if (seq !== refreshSeq) {
        return;
      }
      var stale = document.getElementById('lnkup-ops-refresh-error');
      if (stale) {
        stale.remove();
      }
      if (!snap || snap.code) {
        return;
      }
      var kpis = document.getElementById('lnkup-ops-kpis');
      var feed = document.getElementById('lnkup-ops-feed');
      if (snap.kpis && kpis && snap.kpis.lifecycle && snap.kpis.lifecycle.cards) {
        var life = snap.kpis.lifecycle;
        var lifeHtml = '<p class="vos-eyebrow" data-testid="lnkup-ops-phase">' + escapeHtml(life.phase_label || '') + '</p><div class="lnkup-ops-kpis vos-metrics" data-testid="lnkup-ops-lifecycle-kpis">';
        life.cards.forEach(function (c) {
          lifeHtml += '<div class="vos-metric"><span class="vos-metric__label">' + escapeHtml(c.label) + '</span><span class="vos-metric__value oos-fraction">' + escapeHtml(String(c.value)) + '</span><span class="vos-metric__hint oos-denominator">' + escapeHtml(c.denominator) + '</span></div>';
        });
        kpis.innerHTML = lifeHtml + '</div>';
      } else if (snap.kpis && kpis) {
        var readinessVal = (snap.kpis.readiness_percent != null ? snap.kpis.readiness_percent : 0) + '%';
        var cards = [
          ['Approved', snap.kpis.approved_vendors, ''],
          ['Paid', snap.kpis.paid_vendors, ''],
          ['Readiness', readinessVal, snap.kpis.readiness_ready_label || ''],
          ['Checked In', snap.kpis.checked_in, ''],
          ['Late', snap.kpis.late_vendors, ''],
          ['Missing', snap.kpis.missing_vendors, ''],
          ['Support', snap.kpis.support_requests, ''],
          ['Check-Ins', snap.kpis.avg_checkin_time, ''],
          ['Setup %', (snap.kpis.setup_completion_pct || 0) + '%', '']
        ];
        var html = '<div class="lnkup-ops-kpis lnkup-grid lnkup-grid--4">';
        cards.forEach(function (c) {
          html += '<div class="lnkup-stat-card lnkup-ops-kpi"><p class="lnkup-stat-card__value">' + escapeHtml(String(c[1])) + '</p><p class="lnkup-stat-card__label">' + escapeHtml(c[0]) + '</p>';
          if (c[2]) {
            html += '<p class="lnkup-stat-card__sub lnkup-muted">' + escapeHtml(String(c[2])) + '</p>';
          }
          html += '</div>';
        });
        html += '</div>';
        kpis.innerHTML = html;
      }
      if (snap.feed && feed) {
        var feedHtml = '<ul class="lnkup-ops-feed" id="lnkup-ops-feed-list">';
        snap.feed.forEach(function (item) {
          feedHtml += '<li><strong>' + escapeHtml(item.label || '') + '</strong> <span class="lnkup-muted">' + opsWhen(item.created_at) + '</span></li>';
        });
        feedHtml += '</ul>';
        feed.innerHTML = feedHtml;
      }
      if (snap.weather && typeof snap.weather === 'object' && snap.weather.event_id) {
        var weatherPanel = document.getElementById('lnkup-ops-weather-panel');
        if (weatherPanel && window.lnkupWeatherRenderCard) {
          window.lnkupWeatherRenderCard(snap.weather, weatherPanel);
        }
      }
    }).catch(function () {
      if (seq !== refreshSeq) {
        return;
      }
      var kpis = document.getElementById('lnkup-ops-kpis');
      var note = document.getElementById('lnkup-ops-refresh-error');
      if (!note && kpis && kpis.parentNode) {
        note = document.createElement('p');
        note.id = 'lnkup-ops-refresh-error';
        note.className = 'lnkup-alert lnkup-alert--error';
        kpis.parentNode.insertBefore(note, kpis);
      }
      if (note) {
        note.textContent = 'Could not refresh. ';
        var retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'lnkup-btn lnkup-btn--sm';
        retry.textContent = 'Retry';
        retry.addEventListener('click', function () {
          refreshCommandCenter(root);
        });
        note.appendChild(retry);
      }
    });
    opsFetch('/events/' + eventId + '/compliance').then(function (res) {
      var compliance = document.getElementById('lnkup-ops-compliance');
      if (!compliance || !res || !res.alerts) {
        return;
      }
      if (!res.alerts.length) {
        compliance.innerHTML = '<p class="lnkup-muted">No compliance alerts.</p>';
        return;
      }
      if (res.groups && res.groups.length) {
        var groupHtml = '<ul class="lnkup-ops-alerts" data-testid="lnkup-ops-compliance-groups">';
        res.groups.forEach(function (g) {
          var names = (g.vendors || []).filter(Boolean).slice(0, 3);
          var more = Math.max(0, (g.count || 0) - names.length);
          groupHtml += '<li class="lnkup-ops-alert lnkup-ops-alert--' + escapeHtml(g.severity || 'medium') + '"><strong>' + escapeHtml(g.requirement || '') + '</strong> · ' +
            escapeHtml(g.owner_label || '') + ' · ' + escapeHtml(g.count + (g.count === 1 ? ' vendor' : ' vendors')) +
            '<br><span class="lnkup-muted">' + escapeHtml(names.join(', ') + (more ? ' +' + more + ' more' : '')) + '</span></li>';
        });
        compliance.innerHTML = groupHtml + '</ul>';
        return;
      }
      var alertHtml = '<ul class="lnkup-ops-alerts">';
      res.alerts.forEach(function (alert) {
        var sev = alert.severity || 'medium';
        alertHtml += '<li class="lnkup-ops-alert lnkup-ops-alert--' + escapeHtml(sev) + '">' + escapeHtml(alert.message || '');
        if (alert.next_action) {
          alertHtml += ' <span class="lnkup-muted">— ' + escapeHtml(alert.next_action) + '</span>';
        }
        alertHtml += '</li>';
      });
      alertHtml += '</ul>';
      compliance.innerHTML = alertHtml;
    }).catch(function () {});
  }

  /**
   * Kanban drag-and-drop status updates.
   */
  function initKanban(root) {
    var eventId = root.getAttribute('data-event-id');
    var dragAppId = null;

    root.querySelectorAll('.lnkup-ops-kanban__card').forEach(function (card) {
      card.addEventListener('dragstart', function (e) {
        dragAppId = card.getAttribute('data-app-id');
        card.classList.add('is-dragging');
        if (e.dataTransfer) {
          e.dataTransfer.effectAllowed = 'move';
        }
      });
      card.addEventListener('dragend', function () {
        card.classList.remove('is-dragging');
        dragAppId = null;
      });
    });

    root.querySelectorAll('.lnkup-ops-kanban__col').forEach(function (col) {
      col.addEventListener('dragover', function (e) {
        e.preventDefault();
        col.classList.add('is-drag-over');
      });
      col.addEventListener('dragleave', function () {
        col.classList.remove('is-drag-over');
      });
      col.addEventListener('drop', function (e) {
        e.preventDefault();
        col.classList.remove('is-drag-over');
        if (!dragAppId) {
          return;
        }
        var status = col.getAttribute('data-ops-col');
        var appId = dragAppId;
        var card = root.querySelector('.lnkup-ops-kanban__card[data-app-id="' + appId + '"]');
        var origin = card ? card.parentNode : null;
        opsFetch('/events/' + eventId + '/vendors/' + appId + '/status', {
          method: 'PUT',
          body: { status: status }
        }).then(function (res) {
          if (!res || res.success === false || res.code) {
            return;
          }
          if (card) {
            col.querySelector('.lnkup-ops-kanban__cards').appendChild(card);
          }
          refreshCommandCenter(root);
        }).catch(function () {
          if (card && origin) {
            origin.appendChild(card);
          }
        });
      });
    });
  }

  function statusChip(val) {
    var label = String(val || 'unknown').replace(/_/g, ' ');
    return '<span class="lnkup-badge lnkup-badge--outline">' + escapeHtml(label) + '</span>';
  }

  function renderStats(el, stats) {
    if (!el || !stats || !stats.length) {
      return;
    }
    var html = '<div class="lnkup-ops-inline-stats">';
    stats.forEach(function (stat) {
      html += '<span class="lnkup-ops-inline-stats__item"><strong>' + escapeHtml(String(stat.value)) + '</strong> ' + escapeHtml(stat.label) + '</span>';
    });
    html += '</div>';
    el.innerHTML = html;
  }

  /**
   * Render data table with mobile cards.
   */
  function renderTable(el, rows, columns, options) {
    options = options || {};
    if (!rows || !rows.length) {
      if (window.LNKupUI) {
        LNKupUI.panelEmpty(el, { title: options.emptyTitle || 'No records yet', desc: options.emptyDesc || 'Activity will appear here once vendors check in.', compact: true });
      } else {
        el.innerHTML = '<p class="lnkup-muted">' + escapeHtml(options.emptyTitle || 'No records yet.') + '</p>';
      }
      return;
    }
    var html = '<div class="lnkup-ops-table-wrap"><table class="lnkup-ops-table"><thead><tr>';
    columns.forEach(function (c) { html += '<th scope="col">' + escapeHtml(c.label) + '</th>'; });
    html += '</tr></thead><tbody>';
    rows.forEach(function (row) {
      html += '<tr>';
      columns.forEach(function (c) {
        var raw = row[c.key] != null ? row[c.key] : '';
        if (c.render) {
          html += '<td>' + c.render(raw, row) + '</td>';
        } else {
          html += '<td>' + escapeHtml(String(raw)) + '</td>';
        }
      });
      html += '</tr>';
    });
    html += '</tbody></table></div>';

    html += '<div class="lnkup-ops-card-list">';
    rows.forEach(function (row) {
      html += '<article class="lnkup-ops-card">';
      columns.forEach(function (c) {
        var raw = row[c.key] != null ? row[c.key] : '';
        var rendered = c.render ? c.render(raw, row) : escapeHtml(String(raw));
        html += '<div class="lnkup-ops-card__row"><span class="lnkup-muted">' + escapeHtml(c.label) + '</span><span>' + rendered + '</span></div>';
      });
      html += '</article>';
    });
    html += '</div>';
    el.innerHTML = html;
  }

  /* ---------------- Booths (Organizer OS 2.0) ---------------- */

  var boothState = { rows: [], mode: '', boothId: 0, appId: 0 };

  function bookedVendors(root) {
    try {
      return JSON.parse(root.getAttribute('data-booked-vendors') || '[]') || [];
    } catch (e) {
      return [];
    }
  }

  function boothError(scope, message) {
    var box = scope && scope.querySelector('[data-oos-booth-error]');
    if (!box) {
      return;
    }
    box.textContent = message || '';
    box.className = 'vos-notice vos-notice--danger';
    box.hidden = !message;
  }

  function osBoothFetch(root, path, body) {
    return window.LNKupOS.fetchJson('operations/events/' + encodeURIComponent(root.getAttribute('data-event-id')) + path, body ? { method: 'POST', body: body } : {});
  }

  function renderBoothsOS(root, rows) {
    var el = root.querySelector('#lnkup-ops-booths-list');
    var statsEl = root.querySelector('#lnkup-ops-booths-stats');
    var waitingEl = root.querySelector('#lnkup-ops-booths-unassigned');
    var vendors = bookedVendors(root);
    var byApp = {};
    vendors.forEach(function (v) { byApp[v.application_id] = v; });
    var assignedApps = {};
    rows.forEach(function (r) { if (r.application_id) { assignedApps[r.application_id] = r; } });
    var blocked = rows.filter(function (r) { return !r.application_id && r.booth_type === 'reserved'; }).length;
    var assigned = rows.filter(function (r) { return r.application_id; }).length;
    var waiting = vendors.filter(function (v) { return !assignedApps[v.application_id]; });
    var waitingPaid = waiting.filter(function (v) { return v.paid; }).length;
    statsEl.innerHTML = '<div class="vos-metrics" data-testid="lnkup-booth-summary">' +
      [['Total booths', rows.length], ['Available', rows.length - assigned - blocked], ['Assigned', assigned], ['Blocked (reserved)', blocked], ['Paid vendors without a booth', waitingPaid]].map(function (m) {
        return '<div class="vos-metric"><span class="vos-metric__label">' + escapeHtml(m[0]) + '</span><span class="vos-metric__value">' + escapeHtml(String(m[1])) + '</span></div>';
      }).join('') + '</div>';

    if (!rows.length) {
      el.innerHTML = '<div class="vos-empty"><p class="vos-empty__title">No booths yet</p><p class="vos-empty__desc">Create booths in bulk (for example A01–A20) or add them one at a time. You don’t need a map.</p></div>';
    } else {
      var html = '<div class="vos-table-wrap vos-table--cards" data-testid="lnkup-booth-table"><table class="vos-table"><caption class="vos-sr">Booths</caption><thead><tr><th scope="col">Booth</th><th scope="col">Type</th><th scope="col">Size</th><th scope="col">Utilities</th><th scope="col">Vendor</th><th scope="col" class="vos-actions">Actions</th></tr></thead><tbody>';
      rows.forEach(function (r) {
        var utilities = [r.has_power ? 'Power' : '', r.has_water ? 'Water' : ''].filter(Boolean).join(' · ') || '—';
        var vendor = r.application_id
          ? escapeHtml(r.vendor_name || (byApp[r.application_id] && byApp[r.application_id].name) || 'Booked vendor')
          : (r.booth_type === 'reserved' ? '<span class="lnkup-muted">Blocked</span>' : '<span class="lnkup-muted">Available</span>');
        var actions = '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-oos-booth-assign="' + r.id + '">' + (r.application_id ? 'Change' : 'Assign') + '</button>';
        if (r.application_id) {
          actions += ' <button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-oos-booth-swap="' + r.id + '">Swap</button>' +
            ' <button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" data-oos-booth-unassign="' + r.id + '">Unassign</button>';
        }
        html += '<tr data-booth-id="' + r.id + '"><td class="vos-cell-lead" data-label="Booth"><strong>' + escapeHtml(r.booth_number) + '</strong></td><td data-label="Type">' + escapeHtml(r.booth_type_label || r.booth_type || '') + '</td><td data-label="Size">' + escapeHtml(r.booth_size || '—') + '</td><td data-label="Utilities">' + escapeHtml(utilities) + '</td><td data-label="Vendor">' + vendor + '</td><td class="vos-actions" data-label="Actions">' + actions + '</td></tr>';
      });
      el.innerHTML = html + '</tbody></table></div>';
    }

    if (waitingEl) {
      if (!waiting.length) {
        waitingEl.innerHTML = '';
      } else {
        waitingEl.innerHTML = '<h3 class="vos-section-head__title" style="font-size:16px;margin:16px 0 8px">Booked vendors without a booth</h3><ul class="vos-timeline">' + waiting.map(function (v) {
          return '<li><span>' + escapeHtml(v.name) + (v.paid ? '' : ' <span class="lnkup-muted">(approved, not paid yet)</span>') + '</span><button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-oos-vendor-assign="' + v.application_id + '">Assign booth</button></li>';
        }).join('') + '</ul>';
      }
    }
  }

  function openBoothDrawer(root, mode, id, trigger) {
    var drawer = document.getElementById('oos-booth-assign');
    if (!drawer || !window.LNKupOS) {
      return;
    }
    boothState.mode = mode;
    boothState.boothId = mode === 'vendor' ? 0 : id;
    boothState.appId = mode === 'vendor' ? id : 0;
    var vendors = bookedVendors(root);
    var title = drawer.querySelector('h2');
    var body = drawer.querySelector('[data-oos-booth-assign-body]');
    boothError(drawer, '');
    var current = boothState.rows.filter(function (r) { return r.id === boothState.boothId; })[0];
    var html = '';
    if (mode === 'booth') {
      title.textContent = 'Assign booth ' + (current ? current.booth_number : '');
      html = '<label class="vos-field"><span>Vendor</span><select name="application_id"><option value="">Choose a booked vendor</option>' + vendors.map(function (v) {
        var has = boothState.rows.filter(function (r) { return r.application_id === v.application_id; })[0];
        return '<option value="' + v.application_id + '"' + (current && current.application_id === v.application_id ? ' selected' : '') + '>' + escapeHtml(v.name + (has ? ' — now at ' + has.booth_number : '') + (v.paid ? '' : ' (not paid yet)')) + '</option>';
      }).join('') + '</select><span class="vos-field__hint">A vendor holds one booth per event. Choosing a vendor who already has a booth moves them here.</span></label>';
    } else if (mode === 'vendor') {
      var vendor = vendors.filter(function (v) { return v.application_id === boothState.appId; })[0];
      title.textContent = 'Assign a booth to ' + (vendor ? vendor.name : 'vendor');
      html = '<label class="vos-field"><span>Booth</span><select name="booth_id"><option value="">Choose an available booth</option>' + boothState.rows.filter(function (r) { return !r.application_id; }).map(function (r) {
        return '<option value="' + r.id + '">' + escapeHtml(r.booth_number + ' · ' + (r.booth_type_label || '') + (r.booth_size ? ' · ' + r.booth_size : '')) + '</option>';
      }).join('') + '</select></label>';
    } else {
      title.textContent = 'Swap booth ' + (current ? current.booth_number : '');
      html = '<label class="vos-field"><span>Swap with</span><select name="other_booth"><option value="">Choose another assigned booth</option>' + boothState.rows.filter(function (r) { return r.application_id && r.id !== boothState.boothId; }).map(function (r) {
        return '<option value="' + r.id + '">' + escapeHtml(r.booth_number + ' · ' + (r.vendor_name || 'Booked vendor')) + '</option>';
      }).join('') + '</select><span class="vos-field__hint">Both vendors get updated passes.</span></label>';
    }
    body.innerHTML = html;
    window.LNKupOS.open(drawer, trigger);
  }

  function reloadBooths(root) {
    var el = root.querySelector('#lnkup-ops-booths-list');
    if (el) {
      el.dataset.loaded = '';
    }
    loadBooths(root);
  }

  function bindBoothsOS(root) {
    if (root.dataset.boothsBound === '1' || !window.LNKupOS) {
      return;
    }
    root.dataset.boothsBound = '1';
    document.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target : null;
      if (!t) {
        return;
      }
      var assign = t.closest('[data-oos-booth-assign]');
      if (assign) {
        openBoothDrawer(root, 'booth', parseInt(assign.getAttribute('data-oos-booth-assign'), 10), assign);
        return;
      }
      var vendor = t.closest('[data-oos-vendor-assign]');
      if (vendor) {
        openBoothDrawer(root, 'vendor', parseInt(vendor.getAttribute('data-oos-vendor-assign'), 10), vendor);
        return;
      }
      var swap = t.closest('[data-oos-booth-swap]');
      if (swap) {
        openBoothDrawer(root, 'swap', parseInt(swap.getAttribute('data-oos-booth-swap'), 10), swap);
        return;
      }
      var unassign = t.closest('[data-oos-booth-unassign]');
      if (unassign) {
        if (!window.confirm('Remove the vendor from this booth? Their booking stays in place.')) {
          return;
        }
        unassign.disabled = true;
        osBoothFetch(root, '/booths/' + unassign.getAttribute('data-oos-booth-unassign') + '/unassign', {}).then(function (res) {
          if (!res.ok) {
            unassign.disabled = false;
            window.LNKupOS.toast((res.data && res.data.message) || 'Could not unassign this booth.', 'danger');
            return;
          }
          window.LNKupOS.toast('Booth unassigned.', 'success');
          reloadBooths(root);
        });
        return;
      }
      var save = t.closest('[data-oos-booth-assign-save]');
      if (save) {
        var drawer = save.closest('[data-vos-overlay]');
        var request;
        if (boothState.mode === 'swap') {
          var other = parseInt((drawer.querySelector('[name="other_booth"]') || {}).value, 10);
          if (!other) { boothError(drawer, 'Choose the booth to swap with.'); return; }
          request = osBoothFetch(root, '/booths/swap', { booth_a: boothState.boothId, booth_b: other });
        } else {
          var boothId = boothState.mode === 'vendor' ? parseInt((drawer.querySelector('[name="booth_id"]') || {}).value, 10) : boothState.boothId;
          var appId = boothState.mode === 'vendor' ? boothState.appId : parseInt((drawer.querySelector('[name="application_id"]') || {}).value, 10);
          if (!boothId || !appId) { boothError(drawer, boothState.mode === 'vendor' ? 'Choose a booth.' : 'Choose a vendor.'); return; }
          request = osBoothFetch(root, '/booths/' + boothId + '/assign', { application_id: appId });
        }
        save.disabled = true;
        request.then(function (res) {
          save.disabled = false;
          if (!res.ok) {
            boothError(drawer, (res.data && res.data.message) || 'Could not save. Try again.');
            return;
          }
          window.LNKupOS.close(drawer, true);
          window.LNKupOS.toast('Booth saved.', 'success');
          reloadBooths(root);
        });
        return;
      }
      var bulkSave = t.closest('[data-oos-booth-bulk-save]');
      if (bulkSave) {
        runBulkBooths(root, bulkSave);
      }
    });
    document.addEventListener('input', function (e) {
      if (e.target.closest && e.target.closest('[data-oos-booth-bulk-form]')) {
        previewBulkBooths(e.target.closest('[data-oos-booth-bulk-form]'));
      }
    });
    document.addEventListener('vos:open', function (e) {
      if (e.target && e.target.id === 'oos-booth-bulk') {
        previewBulkBooths(e.target.querySelector('[data-oos-booth-bulk-form]'));
      }
    });
  }

  function bulkNumbers(form) {
    var prefix = String(form.elements.prefix.value || '').trim().toUpperCase().replace(/[^A-Z0-9-]/g, '');
    var from = parseInt(form.elements.from.value, 10);
    var to = parseInt(form.elements.to.value, 10);
    if (!(from >= 1) || !(to >= from) || to - from > 199) {
      return { error: 'Use a range like 1 to 20 (up to 200 booths at once).' };
    }
    var width = Math.max(2, String(to).length);
    var list = [];
    for (var n = from; n <= to; n++) {
      list.push(prefix + String(n).padStart(width, '0'));
    }
    var existing = {};
    boothState.rows.forEach(function (r) { existing[String(r.booth_number).toUpperCase()] = true; });
    var dupes = list.filter(function (num) { return existing[num]; });
    return { list: list, dupes: dupes };
  }

  function previewBulkBooths(form) {
    if (!form) {
      return;
    }
    var preview = form.querySelector('[data-oos-booth-preview]');
    var res = bulkNumbers(form);
    if (res.error) {
      preview.textContent = res.error;
      return;
    }
    preview.textContent = 'Will create ' + res.list.length + ' booths: ' + res.list[0] + (res.list.length > 1 ? '–' + res.list[res.list.length - 1] : '') + '.' +
      (res.dupes.length ? ' ' + res.dupes.length + ' already exist and will be skipped (' + res.dupes.slice(0, 5).join(', ') + (res.dupes.length > 5 ? '…' : '') + ').' : '');
  }

  function runBulkBooths(root, btn) {
    var dialog = btn.closest('[data-vos-overlay]');
    var form = dialog.querySelector('[data-oos-booth-bulk-form]');
    var res = bulkNumbers(form);
    if (res.error) {
      boothError(dialog, res.error);
      return;
    }
    var todo = res.list.filter(function (num) { return res.dupes.indexOf(num) === -1; });
    if (!todo.length) {
      boothError(dialog, 'All of these booth numbers already exist.');
      return;
    }
    boothError(dialog, '');
    btn.disabled = true;
    var created = 0;
    var failed = [];
    var base = {
      booth_type: form.elements.booth_type.value,
      booth_size: form.elements.booth_size.value,
      has_power: form.elements.has_power.checked,
      has_water: form.elements.has_water.checked
    };
    var chain = Promise.resolve();
    todo.forEach(function (num) {
      chain = chain.then(function () {
        return osBoothFetch(root, '/booths', Object.assign({ booth_number: num }, base)).then(function (r) {
          if (r.ok) { created++; } else { failed.push(num); }
        });
      });
    });
    chain.then(function () {
      btn.disabled = false;
      window.LNKupOS.close(dialog, true);
      window.LNKupOS.toast('Created ' + created + ' booths' + (failed.length ? '. Not created: ' + failed.join(', ') : '.'), failed.length ? 'warning' : 'success');
      reloadBooths(root);
    });
  }

  function loadBooths(root) {
    var el = root.querySelector('#lnkup-ops-booths-list');
    var statsEl = root.querySelector('#lnkup-ops-booths-stats');
    if (!el) {
      return;
    }
    if (el.dataset.loaded === '1') {
      return;
    }
    if (window.LNKupOS && document.getElementById('oos-booth-assign')) {
      bindBoothsOS(root);
      el.innerHTML = '<p class="lnkup-muted">Loading booths…</p>';
      osBoothFetch(root, '/booths').then(function (res) {
        if (!res.ok) {
          el.innerHTML = '<div class="vos-empty"><p class="vos-empty__title">Booths could not load</p><button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-oos-booth-retry>Retry</button></div>';
          var retry = el.querySelector('[data-oos-booth-retry]');
          if (retry) { retry.addEventListener('click', function () { loadBooths(root); }); }
          return;
        }
        el.dataset.loaded = '1';
        boothState.rows = (res.data && res.data.items) || [];
        renderBoothsOS(root, boothState.rows);
      });
      return;
    }
    if (window.LNKupUI) {
      LNKupUI.panelSkeleton(el, 2);
    }
    opsFetch('/events/' + root.getAttribute('data-event-id') + '/booths').then(function (res) {
      var rows = res.items || [];
      el.dataset.loaded = '1';
      renderStats(statsEl, [
        { label: 'total booths', value: rows.length },
        { label: 'assigned', value: rows.filter(function (r) { return r.application_id; }).length }
      ]);
      renderTable(el, res.items || [], [
        { key: 'booth_number', label: 'Booth' },
        { key: 'booth_type_label', label: 'Type' },
        { key: 'vendor_name', label: 'Vendor' },
        { key: 'application_id', label: 'App' }
      ], {
        emptyTitle: 'No booths yet',
        emptyDesc: 'No booths yet — placeholders are created when you publish an event.'
      });
    }).catch(function () {
      if (window.LNKupUI) {
        LNKupUI.panelError(el, { onRetry: function () { loadBooths(root); } });
      }
    });
  }

  function loadParking(root) {
    var el = root.querySelector('#lnkup-ops-parking-list');
    var statsEl = root.querySelector('#lnkup-ops-parking-stats');
    if (!el) {
      return;
    }
    if (el.dataset.loaded === '1') {
      return;
    }
    if (window.LNKupUI) {
      LNKupUI.panelSkeleton(el, 2);
    }
    opsFetch('/events/' + root.getAttribute('data-event-id') + '/parking').then(function (res) {
      var rows = res.items || [];
      el.dataset.loaded = '1';
      renderStats(statsEl, [
        { label: 'parking assignments', value: rows.length },
        { label: 'with gate', value: rows.filter(function (r) { return r.gate; }).length }
      ]);
      var parkingNames = {};
      bookedVendors(root).forEach(function (v) { parkingNames[v.application_id] = v.name; });
      renderTable(el, res.items || [], [
        { key: 'lot', label: 'Lot' },
        { key: 'row_label', label: 'Row' },
        { key: 'space_number', label: 'Space' },
        { key: 'application_id', label: 'Vendor', render: function (raw) { return raw ? escapeHtml(parkingNames[raw] || 'Booked vendor') : '<span class="lnkup-muted">Open</span>'; } },
        { key: 'gate', label: 'Gate' }
      ], {
        emptyTitle: 'No parking assignments yet'
      });
    }).catch(function () {
      if (window.LNKupUI) {
        LNKupUI.panelError(el, { onRetry: function () { loadParking(root); } });
      }
    });
  }

  function loadLoadin(root) {
    var el = root.querySelector('#lnkup-ops-loadin-list');
    var statsEl = root.querySelector('#lnkup-ops-loadin-stats');
    if (!el) {
      return;
    }
    if (el.dataset.loaded === '1') {
      return;
    }
    if (window.LNKupUI) {
      LNKupUI.panelSkeleton(el, 2);
    }
    opsFetch('/events/' + root.getAttribute('data-event-id') + '/loadin').then(function (res) {
      var rows = res.windows || [];
      el.dataset.loaded = '1';
      renderStats(statsEl, [
        { label: 'windows', value: rows.length },
        { label: 'total slots', value: rows.reduce(function (sum, row) { return sum + (parseInt(row.max_slots, 10) || 0); }, 0) }
      ]);
      renderTable(el, res.windows || [], [
        { key: 'label', label: 'Window' },
        { key: 'gate', label: 'Gate' },
        { key: 'max_slots', label: 'Capacity' },
        { key: 'assigned_count', label: 'Assigned' },
        { key: 'slots_remaining', label: 'Open', render: function (raw, row) {
          var open = Number(raw || 0);
          return open > 0 ? escapeHtml(String(open)) : '<span class="lnkup-status lnkup-status--warning">Full</span>';
        } }
      ], {
        emptyTitle: 'No load-in windows yet'
      });
    }).catch(function () {
      if (window.LNKupUI) {
        LNKupUI.panelError(el, { onRetry: function () { loadLoadin(root); } });
      }
    });
  }

  function loadSupport(root) {
    var el = root.querySelector('#lnkup-ops-support-list');
    if (!el) {
      return;
    }
    if (el.dataset.loaded === '1') {
      return;
    }
    if (window.LNKupUI) {
      LNKupUI.panelSkeleton(el, 2);
    }
    opsFetch('/support?event_id=' + root.getAttribute('data-event-id')).then(function (res) {
      el.dataset.loaded = '1';
      renderTable(el, res.items || [], [
        { key: 'category', label: 'Category' },
        { key: 'priority', label: 'Priority', render: function (value) { return statusChip(value); } },
        { key: 'status', label: 'Status', render: function (value) { return statusChip(value); } }
      ], {
        emptyTitle: 'No open requests'
      });
    }).catch(function () {
      if (window.LNKupUI) {
        LNKupUI.panelError(el, { onRetry: function () { loadSupport(root); } });
      }
    });
  }

  function loadIncidents(root) {
    var el = root.querySelector('#lnkup-ops-incidents-list');
    if (!el) {
      return;
    }
    if (el.dataset.loaded === '1') {
      return;
    }
    if (window.LNKupUI) {
      LNKupUI.panelSkeleton(el, 2);
    }
    opsFetch('/events/' + root.getAttribute('data-event-id') + '/incidents').then(function (res) {
      el.dataset.loaded = '1';
      renderTable(el, res.items || [], [
        { key: 'type', label: 'Type', render: function (value) { return statusChip(value); } },
        { key: 'location', label: 'Location' },
        { key: 'status', label: 'Status', render: function (value) { return statusChip(value); } }
      ], {
        emptyTitle: 'No incidents logged'
      });
    }).catch(function () {
      if (window.LNKupUI) {
        LNKupUI.panelError(el, { onRetry: function () { loadIncidents(root); } });
      }
    });
  }

  function loadBroadcasts(root) {
    var el = root.querySelector('#lnkup-ops-broadcasts-list');
    if (!el) {
      return;
    }
    if (window.LNKupUI) {
      LNKupUI.panelSkeleton(el, 2);
    }
    opsFetch('/events/' + root.getAttribute('data-event-id') + '/broadcasts').then(function (res) {
      el.dataset.loaded = '1';
      renderTable(el, res.items || [], [
        { key: 'message', label: 'Message' },
        { key: 'sent_at', label: 'Sent' }
      ], {
        emptyTitle: 'No broadcasts yet'
      });
    }).catch(function () {
      if (window.LNKupUI) {
        LNKupUI.panelError(el, { onRetry: function () { loadBroadcasts(root); } });
      }
    });
  }

  function loadAnalytics(root) {
    var el = root.querySelector('#lnkup-ops-analytics');
    if (!el) {
      return;
    }
    if (el.dataset.loaded === '1') {
      return;
    }
    if (window.LNKupUI) {
      LNKupUI.panelSkeleton(el, 2);
    }
    opsFetch('/events/' + root.getAttribute('data-event-id') + '/analytics').then(function (data) {
      if (!data) {
        el.innerHTML = '<p class="lnkup-muted">No analytics available.</p>';
        return;
      }
      el.dataset.loaded = '1';
      var html = '';
      Object.keys(data).forEach(function (key) {
        html += '<div class="lnkup-ops-analytics-card"><strong>' + escapeHtml(String(data[key])) + '</strong><span>' + escapeHtml(key.replace(/_/g, ' ')) + '</span></div>';
      });
      el.innerHTML = html;
    }).catch(function () {
      if (window.LNKupUI) {
        LNKupUI.panelError(el, { onRetry: function () { loadAnalytics(root); } });
      }
    });
  }

  function initReadinessFilters(root) {
    var panel = root.querySelector('#lnkup-ops-readiness-panel');
    if (!panel || panel.querySelector('.lnkup-ops-readiness-filters')) {
      return;
    }
    var table = panel.querySelector('.lnkup-ops-readiness-table');
    if (!table) {
      return;
    }
    var wrap = document.createElement('div');
    wrap.className = 'lnkup-ops-readiness-filters';
    wrap.innerHTML = '<button type="button" class="lnkup-btn lnkup-btn--sm is-active" data-tier="">All</button>' +
      '<button type="button" class="lnkup-btn lnkup-btn--sm" data-tier="ready">Ready</button>' +
      '<button type="button" class="lnkup-btn lnkup-btn--sm" data-tier="on_track">On Track</button>' +
      '<button type="button" class="lnkup-btn lnkup-btn--sm" data-tier="at_risk">At Risk</button>';
    table.parentNode.insertBefore(wrap, table);
    wrap.querySelectorAll('button').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var tier = btn.getAttribute('data-tier');
        wrap.querySelectorAll('button').forEach(function (b) {
          b.classList.toggle('is-active', b === btn);
        });
        table.querySelectorAll('tbody tr').forEach(function (tr) {
          var pill = tr.querySelector('.lnkup-ops-readiness__pill');
          var keep = !tier || (pill && pill.className.indexOf('lnkup-ops-readiness__pill--' + tier) !== -1);
          tr.style.display = keep ? '' : 'none';
        });
      });
    });
  }

  /**
   * Venue map booth search and pin detail.
   */
  function initVenueMap(root) {
    var map = root.querySelector('#lnkup-ops-venue-map');
    if (!map) {
      return;
    }
    var search = map.querySelector('.lnkup-ops-map-search');
    var detail = map.querySelector('#lnkup-ops-map-detail');
    if (search) {
      search.addEventListener('input', function () {
        var q = search.value.trim().toLowerCase();
        map.querySelectorAll('.lnkup-ops-map__pin').forEach(function (pin) {
          var num = (pin.getAttribute('data-booth-number') || '').toLowerCase();
          var match = !q || num.indexOf(q) !== -1;
          pin.classList.toggle('is-highlight', !!q && match);
          pin.style.opacity = match ? '1' : '0.3';
        });
      });
    }
    map.querySelectorAll('.lnkup-ops-map__pin').forEach(function (pin) {
      pin.addEventListener('click', function () {
        if (!detail) {
          return;
        }
        detail.hidden = false;
        detail.innerHTML = '<strong>Booth ' + escapeHtml(pin.getAttribute('data-booth-number') || '') + '</strong>';
        var vendor = pin.getAttribute('data-vendor');
        if (vendor) {
          detail.innerHTML += '<p>' + escapeHtml(vendor) + '</p>';
        }
        if (pin.getAttribute('data-app-id') && pin.getAttribute('data-app-id') !== '0') {
          detail.innerHTML += '<p>Application #' + escapeHtml(pin.getAttribute('data-app-id')) + '</p>';
        }
      });
    });
  }

  /**
   * Scan page vendor lookup: filter by name/booth and check in without a pass code.
   * The server applies the same payment and document gates as a QR scan.
   */
  function initCheckinLookup() {
    var root = document.getElementById('lnkup-qr-scan');
    var lookup = root ? root.querySelector('[data-checkin-lookup]') : null;
    if (!lookup) {
      return;
    }
    var eventId = root.getAttribute('data-event-id');
    var search = lookup.querySelector('[data-checkin-search]');
    if (search) {
      search.addEventListener('input', function () {
        var q = search.value.trim().toLowerCase();
        lookup.querySelectorAll('[data-checkin-row]').forEach(function (row) {
          row.hidden = q !== '' && (row.getAttribute('data-search') || '').indexOf(q) === -1;
        });
      });
    }
    lookup.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-checkin-app]');
      if (!btn || btn.disabled) {
        return;
      }
      var row = btn.closest('[data-checkin-row]');
      var label = row ? row.querySelector('[data-checkin-label]') : null;
      btn.disabled = true;
      opsFetch('/events/' + encodeURIComponent(eventId) + '/vendors/' + encodeURIComponent(btn.getAttribute('data-checkin-app')) + '/status', {
        method: 'PUT',
        body: { status: 'checked_in' }
      }).then(function (res) {
        if (label) {
          label.textContent = (res && res.message) ? res.message : 'Checked in';
        }
        btn.remove();
      }).catch(function (err) {
        btn.disabled = false;
        if (label) {
          label.textContent = (err && err.message && err.message !== 'api_error') ? err.message : 'Could not check in. Try again.';
        }
      });
    });
  }

  /**
   * QR scan page: native camera scanning where the browser supports BarcodeDetector, manual entry everywhere.
   */
  function initQrScan() {
    var root = document.getElementById('lnkup-qr-scan');
    if (!root) {
      return;
    }
    var eventId = root.getAttribute('data-event-id');
    var result = document.getElementById('lnkup-qr-result');
    var form = document.getElementById('lnkup-qr-manual');
    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var field = form.querySelector('[name="token"]');
        var token = field.value.trim();
        if (!token) {
          return;
        }
        submitScan(eventId, token, result).then(function () {
          field.value = '';
          field.focus();
        });
      });
    }
    initQrCamera(root, eventId, result);
    loadRecentCheckins(eventId);
  }

  function initQrCamera(root, eventId, resultEl) {
    var status = root.querySelector('[data-qr-camera-status]');
    var video = root.querySelector('[data-qr-video]');
    var startBtn = root.querySelector('[data-qr-camera-start]');
    var stopBtn = root.querySelector('[data-qr-camera-stop]');
    var supported = 'BarcodeDetector' in window && navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function';
    if (!supported || !video || !startBtn) {
      if (status) {
        status.textContent = 'Camera scanning is not available in this browser. Type the pass code below instead.';
      }
      var manual = document.getElementById('lnkup-qr-token');
      if (manual) {
        manual.focus();
      }
      return;
    }
    var detector = null;
    var stream = null;
    var running = false;
    var busy = false;
    var lastToken = '';
    var lastAt = 0;

    function stop() {
      running = false;
      if (stream) {
        stream.getTracks().forEach(function (track) { track.stop(); });
        stream = null;
      }
      video.hidden = true;
      startBtn.hidden = false;
      if (stopBtn) {
        stopBtn.hidden = true;
      }
    }

    function tick() {
      if (!running) {
        return;
      }
      if (busy || video.readyState < 2) {
        window.requestAnimationFrame(tick);
        return;
      }
      busy = true;
      detector.detect(video).then(function (codes) {
        var token = codes && codes.length ? String(codes[0].rawValue || '').trim() : '';
        var now = Date.now();
        if (!token || (token === lastToken && now - lastAt < 4000)) {
          busy = false;
          return;
        }
        lastToken = token;
        lastAt = now;
        return submitScan(eventId, token, resultEl).then(function () {
          busy = false;
        });
      }).catch(function () {
        busy = false;
      }).then(function () {
        window.requestAnimationFrame(tick);
      });
    }

    window.BarcodeDetector.getSupportedFormats().then(function (formats) {
      if (formats.indexOf('qr_code') === -1) {
        throw new Error('no_qr');
      }
      detector = new window.BarcodeDetector({ formats: ['qr_code'] });
      if (status) {
        status.textContent = 'Point the camera at a vendor pass. Each pass is checked in once.';
      }
      startBtn.hidden = false;
    }).catch(function () {
      if (status) {
        status.textContent = 'Camera scanning is not available in this browser. Type the pass code below instead.';
      }
    });

    startBtn.addEventListener('click', function () {
      startBtn.disabled = true;
      navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (media) {
        stream = media;
        video.srcObject = media;
        video.hidden = false;
        startBtn.hidden = true;
        if (stopBtn) {
          stopBtn.hidden = false;
        }
        return video.play();
      }).then(function () {
        running = true;
        window.requestAnimationFrame(tick);
      }).catch(function () {
        stop();
        if (status) {
          status.textContent = 'We could not open the camera. Allow camera access, or type the pass code below.';
        }
      }).then(function () {
        startBtn.disabled = false;
      });
    });
    if (stopBtn) {
      stopBtn.addEventListener('click', stop);
    }
    window.addEventListener('pagehide', stop);
  }

  function renderScanCard(resultEl, state, title, detail) {
    resultEl.className = 'lnkup-ops-scan-result is-' + state;
    resultEl.innerHTML = '';
    var icon = document.createElement('span');
    icon.className = 'lnkup-ops-scan-result__icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = state === 'success' ? '\u2713' : (state === 'warning' ? '!' : '\u2715');
    var body = document.createElement('div');
    var strong = document.createElement('strong');
    strong.textContent = title;
    body.appendChild(strong);
    if (detail) {
      var p = document.createElement('p');
      p.textContent = detail;
      body.appendChild(p);
    }
    resultEl.appendChild(icon);
    resultEl.appendChild(body);
  }

  /** Clear titles for each check-in outcome; the server message stays as the detail line. */
  var SCAN_OUTCOMES = {
    already_checked_in: ['warning', 'Already checked in'],
    wrong_event: ['error', 'Wrong event — this pass is for a different event'],
    invalid_booking: ['error', 'Booking not eligible for check-in'],
    not_found: ['error', 'Pass not recognized'],
    payment_pending: ['warning', 'Payment still pending'],
    documents: ['warning', 'Documents need attention'],
    event_completed: ['error', 'Event is closed for check-in'],
    forbidden: ['error', 'You can’t check in vendors for this event']
  };

  function submitScan(eventId, token, resultEl) {
    return opsFetch('/qr/scan', {
      method: 'POST',
      body: { token: token, event_id: parseInt(eventId, 10) }
    }).then(function (res) {
      if (!resultEl) {
        return;
      }
      var outcome = res && SCAN_OUTCOMES[res.reason || res.code];
      if (outcome && res.reason !== 'already_checked_in') {
        renderScanCard(resultEl, outcome[0], outcome[1], (res && res.message) || '');
        return;
      }
      if (res && res.reason === 'already_checked_in') {
        renderScanCard(resultEl, 'warning', 'Already checked in', [res.business_name || '', res.checked_in_at ? 'at ' + String(res.checked_in_at).slice(11, 16) : ''].filter(Boolean).join(' · '));
        return;
      }
      if (!res || res.code || res.success === false) {
        renderScanCard(resultEl, 'error', (res && res.message) || 'Scan failed.', '');
        return;
      }
      var detail = [res.business_name || '', res.booth ? 'Booth ' + res.booth : ''].filter(Boolean).join(' · ');
      renderScanCard(resultEl, 'success', res.message || 'Vendor checked in.', detail);
      loadRecentCheckins(eventId);
    }).catch(function (err) {
      if (!resultEl) {
        return;
      }
      var message = (err && err.message && err.message !== 'api_error') ? err.message : 'Scan request failed. Try again.';
      var known = err && SCAN_OUTCOMES[err.code];
      if (known) {
        renderScanCard(resultEl, known[0], known[1], message);
        return;
      }
      renderScanCard(resultEl, 'error', message, '');
    });
  }

  function loadRecentCheckins(eventId) {
    var wrap = document.getElementById('lnkup-qr-recent');
    if (!wrap) {
      return;
    }
    opsFetch('/events/' + encodeURIComponent(eventId) + '/feed').then(function (res) {
      var items = ((res && res.items) || []).filter(function (item) {
        return item.activity_type === 'checked_in';
      }).slice(0, 10);
      if (!items.length) {
        wrap.innerHTML = '<p class="lnkup-muted">No check-ins yet. Scanned vendors show up here.</p>';
        return;
      }
      var list = document.createElement('ul');
      list.className = 'lnkup-qr-recent-list';
      items.forEach(function (item) {
        var li = document.createElement('li');
        var name = document.createElement('strong');
        name.textContent = (item.payload && item.payload.business_name) || item.label || 'Vendor';
        var when = document.createElement('span');
        when.className = 'lnkup-muted';
        when.innerHTML = opsWhen(item.created_at);
        li.appendChild(name);
        li.appendChild(when);
        list.appendChild(li);
      });
      wrap.innerHTML = '';
      wrap.appendChild(list);
    }).catch(function () {
      wrap.innerHTML = '';
      var p = document.createElement('p');
      p.className = 'lnkup-muted';
      p.textContent = 'Recent check-ins could not load. ';
      var retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'lnkup-btn lnkup-btn--sm lnkup-btn--ghost';
      retry.textContent = 'Retry';
      retry.addEventListener('click', function () { loadRecentCheckins(eventId); });
      p.appendChild(retry);
      wrap.appendChild(p);
    });
  }

  /**
   * Support request slide-over for vendors.
   */
  function initSupportForm() {
    document.querySelectorAll('.lnkup-ops-support-open').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var appId = btn.getAttribute('data-app-id');
        var overlay = document.createElement('div');
        overlay.className = 'lnkup-ops-support-slide';
        overlay.innerHTML = '<div class="lnkup-ops-support-slide__panel"><h3>Request Support</h3>' +
          '<form id="lnkup-ops-support-form" class="lnkup-ops-form">' +
          '<input type="hidden" name="application_id" value="' + escapeHtml(appId) + '" />' +
          '<label>Category<select name="category"><option value="booth">Booth</option><option value="parking">Parking</option><option value="setup">Setup</option><option value="other">Other</option></select></label>' +
          '<label>Message<textarea name="message" rows="4" required></textarea></label>' +
          '<button type="submit" class="lnkup-btn lnkup-btn--primary">Submit</button>' +
          '<button type="button" class="lnkup-btn lnkup-btn--ghost" id="lnkup-ops-support-cancel">Cancel</button>' +
          '</form></div>';
        document.body.appendChild(overlay);
        overlay.querySelector('#lnkup-ops-support-cancel').addEventListener('click', function () {
          overlay.remove();
        });
        overlay.addEventListener('click', function (e) {
          if (e.target === overlay) {
            overlay.remove();
          }
        });
        overlay.querySelector('#lnkup-ops-support-form').addEventListener('submit', function (e) {
          e.preventDefault();
          var form = e.target;
          var submit = form.querySelector('[type="submit"]');
          if (submit && submit.disabled) {
            return;
          }
          if (submit) {
            submit.disabled = true;
          }
          var fd = new FormData(form);
          opsFetch('/support', {
            method: 'POST',
            body: {
              application_id: parseInt(fd.get('application_id'), 10),
              category: fd.get('category'),
              message: fd.get('message')
            }
          }).then(function () {
            overlay.remove();
            loadVendorSupportHistory();
          }).catch(function (err) {
            if (submit) {
              submit.disabled = false;
            }
            var box = form.querySelector('.lnkup-ops-form-error');
            if (!box) {
              box = document.createElement('p');
              box.className = 'lnkup-ops-form-error lnkup-alert lnkup-alert--error';
              box.setAttribute('role', 'alert');
              form.appendChild(box);
            }
            box.textContent = (err && err.message) || 'We could not send your request. Try again.';
          });
        });
      });
    });
  }

  /**
   * Slide-over form for organizer ops actions (prefilled from event meta).
   */
  function openOpsForm(title, fieldsHtml, onSubmit) {
    var overlay = document.createElement('div');
    overlay.className = 'lnkup-ops-support-slide';
    overlay.innerHTML = '<div class="lnkup-ops-support-slide__panel"><h3>' + escapeHtml(title) + '</h3>' +
      '<form class="lnkup-ops-form lnkup-ops-add-form">' + fieldsHtml +
      '<button type="submit" class="lnkup-btn lnkup-btn--primary">Save</button>' +
      '<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-ops-form-cancel">Cancel</button>' +
      '</form></div>';
    document.body.appendChild(overlay);
    overlay.querySelector('.lnkup-ops-form-cancel').addEventListener('click', function () {
      overlay.remove();
    });
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) {
        overlay.remove();
      }
    });
    overlay.querySelector('form').addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(e.target);
      var body = {};
      fd.forEach(function (val, key) {
        body[key] = val;
      });
      onSubmit(body, overlay);
    });
    return overlay;
  }

  var opsDefaultsCache = {};

  function loadOpsDefaults(eventId) {
    if (opsDefaultsCache[eventId]) {
      return Promise.resolve(opsDefaultsCache[eventId]);
    }
    return opsFetch('/events/' + eventId + '/defaults').then(function (data) {
      opsDefaultsCache[eventId] = data || {};
      return opsDefaultsCache[eventId];
    }).catch(function () {
      return {};
    });
  }

  /**
   * Command center init with polling.
   */
  function initCommandCenter() {
    var root = document.getElementById('lnkup-event-ops');
    if (!root) {
      return;
    }
    var eventId = root.getAttribute('data-event-id');
    loadOpsDefaults(eventId);
    initOpsTabs(root);
    initKanban(root);
    initVenueMap(root);
    initVendorPassForm(root);

    var broadcastForm = root.querySelector('#lnkup-ops-broadcast-form');
    if (broadcastForm) {
      broadcastForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(broadcastForm);
        opsFetch('/events/' + root.getAttribute('data-event-id') + '/broadcasts', {
          method: 'POST',
          body: {
            audience: fd.get('audience'),
            message: fd.get('message')
          }
        }).then(function () {
          broadcastForm.reset();
          var wrap = root.querySelector('#lnkup-ops-broadcasts-list');
          if (wrap) {
            wrap.dataset.loaded = '';
          }
          loadBroadcasts(root);
        });
      });
    }

    var newIncident = root.querySelector('#lnkup-ops-new-incident');
    if (newIncident) {
      newIncident.addEventListener('click', function () {
        openOpsForm('Log Incident',
          '<label>Type<select name="type"><option value="safety">Safety</option><option value="medical">Medical</option><option value="logistics" selected>Logistics</option><option value="other">Other</option></select></label>' +
          '<label>Location<input type="text" name="location" value="" /></label>' +
          '<label>Notes<textarea name="notes" rows="3"></textarea></label>',
          function (body, overlay) {
            opsFetch('/events/' + eventId + '/incidents', {
              method: 'POST',
              body: { type: body.type, location: body.location || '', notes: body.notes || '' }
            }).then(function () {
              overlay.remove();
              var wrap = root.querySelector('#lnkup-ops-incidents-list');
              if (wrap) {
                wrap.dataset.loaded = '';
              }
              loadIncidents(root);
            });
          }
        );
      });
    }

    var mobileIncident = root.querySelector('#lnkup-ops-mobile-incident');
    if (mobileIncident) {
      mobileIncident.addEventListener('click', function () {
        newIncident && newIncident.click();
      });
    }
    var mobileBroadcast = root.querySelector('#lnkup-ops-mobile-broadcast');
    if (mobileBroadcast) {
      mobileBroadcast.addEventListener('click', function () {
        root.querySelector('[data-ops-tab="comms"]').click();
      });
    }

    var addBooth = root.querySelector('#lnkup-ops-add-booth');
    if (addBooth) {
      addBooth.addEventListener('click', function () {
        loadOpsDefaults(eventId).then(function (d) {
          openOpsForm('Add Booth',
            '<label>Booth number<input type="text" name="booth_number" value="' + escapeHtml(d.booth_number || '') + '" required /></label>' +
            '<label>Type<select name="booth_type"><option value="standard"' + (d.booth_type === 'standard' ? ' selected' : '') + '>Standard</option><option value="corner"' + (d.booth_type === 'corner' ? ' selected' : '') + '>Corner</option><option value="premium"' + (d.booth_type === 'premium' ? ' selected' : '') + '>Premium</option></select></label>' +
            '<label><input type="checkbox" name="has_power" value="1"' + (d.has_power ? ' checked' : '') + ' /> Power available</label>',
            function (body, overlay) {
              opsFetch('/events/' + eventId + '/booths', {
                method: 'POST',
                body: {
                  booth_number: body.booth_number,
                  booth_type: body.booth_type || 'standard',
                  has_power: !!body.has_power
                }
              }).then(function () {
                overlay.remove();
                var wrap = root.querySelector('#lnkup-ops-booths-list');
                if (wrap) {
                  wrap.dataset.loaded = '';
                }
                loadBooths(root);
              });
            }
          );
        });
      });
    }
    var addParking = root.querySelector('#lnkup-ops-add-parking');
    if (addParking) {
      addParking.addEventListener('click', function () {
        loadOpsDefaults(eventId).then(function (d) {
          openOpsForm('Add Parking',
            '<label>Lot<input type="text" name="lot" value="' + escapeHtml(d.lot || '') + '" required /></label>' +
            '<label>Row<input type="text" name="row_label" value="' + escapeHtml(d.row_label || '1') + '" /></label>' +
            '<label>Space<input type="text" name="space_number" value="' + escapeHtml(d.space_number || '1') + '" /></label>' +
            '<label>Gate<input type="text" name="gate" value="' + escapeHtml(d.gate || '') + '" /></label>' +
            '<label>Instructions<textarea name="instructions" rows="3">' + escapeHtml(d.instructions || '') + '</textarea></label>',
            function (body, overlay) {
              opsFetch('/events/' + eventId + '/parking', {
                method: 'POST',
                body: {
                  lot: body.lot,
                  row_label: body.row_label || '1',
                  space_number: body.space_number || '1',
                  gate: body.gate || '',
                  instructions: body.instructions || ''
                }
              }).then(function () {
                overlay.remove();
                var wrap = root.querySelector('#lnkup-ops-parking-list');
                if (wrap) {
                  wrap.dataset.loaded = '';
                }
                loadParking(root);
              });
            }
          );
        });
      });
    }
    var addLoadin = root.querySelector('#lnkup-ops-add-loadin');
    if (addLoadin) {
      addLoadin.addEventListener('click', function () {
        loadOpsDefaults(eventId).then(function (d) {
          openOpsForm('Add Load-in Window',
            '<label>Gate<input type="text" name="gate" value="' + escapeHtml(d.gate || '') + '" required /></label>' +
            '<label>Start<input type="datetime-local" name="start_at" value="' + escapeHtml(d.start_at || '') + '" required /></label>' +
            '<label>End<input type="datetime-local" name="end_at" value="' + escapeHtml(d.end_at || '') + '" required /></label>' +
            '<label>Max slots<input type="number" name="max_slots" min="1" value="' + escapeHtml(String(d.max_slots || 10)) + '" /></label>',
            function (body, overlay) {
              opsFetch('/events/' + eventId + '/loadin', {
                method: 'POST',
                body: {
                  gate: body.gate,
                  start_at: (body.start_at || '').replace('T', ' '),
                  end_at: (body.end_at || '').replace('T', ' '),
                  max_slots: parseInt(body.max_slots, 10) || 10
                }
              }).then(function () {
                overlay.remove();
                var wrap = root.querySelector('#lnkup-ops-loadin-list');
                if (wrap) {
                  wrap.dataset.loaded = '';
                }
                loadLoadin(root);
              });
            }
          );
        });
      });
    }

    if (pollTimer) {
      clearInterval(pollTimer);
    }
    initReadinessFilters(root);
    refreshCommandCenter(root);
    pollTimer = setInterval(function () {
      if (document.visibilityState === 'hidden') {
        return;
      }
      refreshCommandCenter(root);
    }, 15000);
    if (!root.dataset.visibilityBound) {
      root.dataset.visibilityBound = '1';
      document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
          refreshCommandCenter(root);
        }
      });
    }
  }

  /**
   * Vendor operations hub — venue map on map tab.
   */
  function initVendorOps() {
    var root = document.getElementById('lnkup-vendor-ops');
    if (!root) {
      return;
    }
    var mapPanel = root.querySelector('[data-ops-panel="map"]');
    if (mapPanel && mapPanel.classList.contains('is-active')) {
      initVenueMap(mapPanel);
      var vendorPin = mapPanel.querySelector('.lnkup-ops-map__pin[data-app-id="' + root.getAttribute('data-app-id') + '"]');
      if (vendorPin) {
        vendorPin.classList.add('is-highlight');
      }
    }
    root.querySelectorAll('.lnkup-vendor-ops__tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        if (tab.getAttribute('data-ops-tab') === 'map') {
          setTimeout(function () {
            var panel = root.querySelector('[data-ops-panel="map"]');
            if (panel) {
              initVenueMap(panel);
            }
          }, 50);
        }
      });
    });
    loadVendorSupportHistory();
  }

  document.addEventListener('DOMContentLoaded', function () {
    initPacketTabs();
    loadVendorHome();
    loadOpsWidgets();
    initCommandCenter();
    initVendorOps();
    initQrScan();
    initCheckinLookup();
    initSupportForm();
  });
})();
