(function () {
  var ajax = window.lnkupAjax || {};
  var base = ajax.growthRestUrl || '';
  var nonce = ajax.restNonce || '';

  var API_ERROR_MSG = 'We couldn\'t load this section. Try refreshing or contact support.';

  /**
   * Safe JSON fetch — rejects HTML error pages and non-OK responses.
   */
  function safeJsonFetch(path, options) {
    options = options || {};
    var headers = Object.assign({ 'X-WP-Nonce': nonce }, options.headers || {});
    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }
    return fetch(base + path, Object.assign({ credentials: 'same-origin', headers: headers }, options))
      .then(function (r) {
        var ct = r.headers.get('content-type') || '';
        if (!r.ok || ct.indexOf('application/json') === -1) {
          return r.text().then(function (body) {
            console.error('Growth API error', r.status, body.slice(0, 300));
            throw new Error('api_error');
          });
        }
        return r.json();
      });
  }

  /**
   * Growth REST fetch helper.
   */
  function growthFetch(path, options) {
    return safeJsonFetch(path, options).then(function (payload) {
      return unwrapGrowth(payload);
    });
  }

  /**
   * Unwrap { success, data } envelopes from growth REST.
   */
  function unwrapGrowth(payload) {
    if (!payload || typeof payload !== 'object') {
      return {};
    }
    if (payload.data && typeof payload.data === 'object' && !Array.isArray(payload.data)) {
      return payload.data;
    }
    return payload;
  }

  function renderErrorCard(message) {
    if (window.LNKupUI) {
      return '<div class="lnkup-empty-state lnkup-empty-state--compact"><p class="lnkup-empty-state__title">' +
        escapeHtml(message || API_ERROR_MSG) + '</p></div>';
    }
    return '<div class="lnkup-growth-error-card"><p>' + escapeHtml(message || API_ERROR_MSG) + '</p></div>';
  }

  function failPanel(panel, message) {
    if (!panel) {
      return;
    }
    if (window.LNKupUI) {
      LNKupUI.panelError(panel, {
        title: message || API_ERROR_MSG,
        onRetry: function () {
          panel.dataset.loaded = '';
          panel.dispatchEvent(new CustomEvent('lnkup-growth-panel-open', { bubbles: true }));
        }
      });
      return;
    }
    panel.innerHTML = renderErrorCard(message);
    panel.dataset.loaded = '1';
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function currency(val) {
    var n = parseFloat(val) || 0;
    return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
  }

  function kpiCard(label, value, trend, animateRaw) {
    var anim = animateRaw != null ? ' data-animate-count="' + escapeHtml(String(animateRaw)) + '"' : '';
    var trendHtml = trend ? '<p class="lnkup-stat-card__trend">' + escapeHtml(trend) + '</p>' : '';
    return '<div class="lnkup-stat-card lnkup-growth-kpi lnkup-micro-lift"><div class="lnkup-stat-card__content">' +
      '<p class="lnkup-stat-card__label">' + escapeHtml(label) + '</p>' +
      '<p class="lnkup-stat-card__value"' + anim + '>' + escapeHtml(value) + '</p>' + trendHtml + '</div></div>';
  }

  function renderFunnel(f) {
    if (!f) return '';
    var steps = [
      { label: 'Submitted', pct: 100 },
      { label: 'Approved', pct: Math.min(100, parseFloat(f.approval_rate) || 0) },
      { label: 'Booked', pct: Math.min(100, (parseFloat(f.approval_rate) || 0) * 0.85) }
    ];
    var html = '<div class="lnkup-growth-funnel" role="img" aria-label="Application funnel">';
    steps.forEach(function (step) {
      html += '<div class="lnkup-growth-funnel__step"><span class="lnkup-growth-funnel__bar" style="width:' + step.pct + '%"></span><span class="lnkup-growth-funnel__label">' + escapeHtml(step.label) + '</span></div>';
    });
    html += '</div><p class="lnkup-type-meta">Approval: ' + (f.approval_rate || 0) + '% · Rejection: ' + (f.rejection_rate || 0) + '%</p>';
    return html;
  }

  function barChart(items, valueKey) {
    if (window.LNKupUI && window.LNKupUI.renderBarChart) {
      return window.LNKupUI.renderBarChart(items, { valueKey: valueKey || 'amount', labelKey: 'date', ariaLabel: 'Revenue chart' });
    }
    if (!items || !items.length) {
      return '<p class="lnkup-muted">No data yet.</p>';
    }
    var max = Math.max.apply(null, items.map(function (i) { return parseFloat(i[valueKey]) || 0; })) || 1;
    var html = '<div class="lnkup-growth-chart">';
    items.slice(-20).forEach(function (item) {
      var h = Math.max(4, Math.round(((parseFloat(item[valueKey]) || 0) / max) * 100));
      html += '<div class="lnkup-growth-chart__bar" style="height:' + h + '%" title="' + escapeHtml(item.date || item.month || item.label || '') + '"></div>';
    });
    html += '</div>';
    return html;
  }

  function bindTabs(shell) {
    if (!shell || shell.dataset.growthTabsBound) {
      return;
    }
    shell.dataset.growthTabsBound = '1';
    shell.querySelectorAll('.lnkup-growth-tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        var name = tab.getAttribute('data-tab');
        shell.querySelectorAll('.lnkup-growth-tab').forEach(function (t) { t.classList.remove('is-active'); });
        shell.querySelectorAll('.lnkup-growth-panel').forEach(function (p) { p.classList.remove('is-active'); });
        tab.classList.add('is-active');
        var panel = shell.querySelector('[data-panel="' + name + '"]');
        if (panel) {
          panel.classList.add('is-active');
          panel.dispatchEvent(new CustomEvent('lnkup-growth-panel-open', { bubbles: true }));
        }
      });
    });
  }

  function loadKpiStrip(data) {
    var strip = document.getElementById('lnkup-growth-kpis');
    if (!strip || !data || !data.kpis) {
      return;
    }
    var k = data.kpis;
    strip.innerHTML =
      kpiCard('Lifetime Revenue', currency(k.lifetime_revenue), '', k.lifetime_revenue) +
      kpiCard('This Month', currency(k.revenue_this_month), '', k.revenue_this_month) +
      kpiCard('Vendor Score', String(k.vendor_score || 0), '', k.vendor_score) +
      kpiCard('Growth Rate', (k.growth_rate || 0) + '%', '', k.growth_rate) +
      kpiCard('Profile', (k.profile_strength || 0) + '%', '', k.profile_strength) +
      kpiCard('Events', String(k.completed_events || 0), '', k.completed_events) +
      kpiCard('Success Rate', (k.application_success_rate || 0) + '%', '', k.application_success_rate) +
      kpiCard('Cities', String(k.cities_served || 0), '', k.cities_served);
    if (window.LNKupUI && LNKupUI.animateKpiCards) {
      LNKupUI.animateKpiCards(strip);
    }
  }

  function loadOverview(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    var kpiStrip = document.getElementById('lnkup-growth-kpis');
    if (kpiStrip && window.LNKupUI) {
      LNKupUI.panelSkeleton(kpiStrip, 4);
    }
    panel.innerHTML = '<div class="lnkup-growth-skeleton"></div>';
    growthFetch('/dashboard').then(function (data) {
      panel.dataset.loaded = '1';
      loadKpiStrip(data);
      var html = '';
      if (data.kpis) {
        var score = data.kpis.vendor_score || 0;
        var prof = data.kpis.profile_strength || 0;
        html += '<div class="lnkup-growth-health-hero lnkup-panel">';
        html += '<div class="lnkup-compliance-ring" style="--progress:' + Math.min(100, Math.max(0, score)) + '%" role="img" aria-label="Business health ' + score + ' percent">';
        html += '<span class="lnkup-compliance-ring__value">' + score + '</span></div>';
        html += '<div class="lnkup-growth-health-hero__copy"><h3 class="lnkup-growth-card__title">Business health</h3>';
        html += '<p class="lnkup-muted">Profile strength ' + prof + '% · Vendor score ' + score + '</p></div></div>';
      }
      html += '<div class="lnkup-growth-grid">';
      if (data.insights && data.insights.length) {
        html += '<div class="lnkup-growth-card"><h3 class="lnkup-growth-card__title">Personalized Insights</h3>';
        data.insights.forEach(function (t) {
          html += '<p class="lnkup-growth-insight__reason">' + escapeHtml(t) + '</p>';
        });
        html += '</div>';
      }
      if (data.recommendations && data.recommendations.length) {
        html += '<div class="lnkup-growth-card"><h3 class="lnkup-growth-card__title">Recommendations</h3>';
        data.recommendations.forEach(function (r) {
          html += '<div class="lnkup-growth-insight"><p class="lnkup-growth-insight__title">' + escapeHtml(r.title) +
            '</p><p class="lnkup-growth-insight__reason">' + escapeHtml(r.reason) + '</p></div>';
        });
        html += '</div>';
      }
      if (data.benchmarks) {
        html += '<div class="lnkup-growth-card"><h3 class="lnkup-growth-card__title">Benchmarks</h3>';
        Object.keys(data.benchmarks).forEach(function (key) {
          var b = data.benchmarks[key];
          var cls = b.percentile >= 75 ? ' lnkup-growth-benchmark--top' : '';
          html += '<div class="lnkup-growth-benchmark' + cls + '"><span class="lnkup-growth-benchmark__label">' +
            escapeHtml(key.replace(/_/g, ' ')) + '</span><span>' + escapeHtml(b.label) + '</span></div>';
        });
        html += '</div>';
      }
      if (data.achievements && data.achievements.earned) {
        html += '<div class="lnkup-growth-card"><h3 class="lnkup-growth-card__title">Achievements</h3>';
        data.achievements.earned.forEach(function (a) {
          html += '<span class="lnkup-growth-badge">' + escapeHtml(a.label) + '</span>';
        });
        if (data.achievements.next) {
          html += '<p class="lnkup-muted">Next: ' + escapeHtml(data.achievements.next.label) +
            ' (' + data.achievements.next.progress + '%)</p>';
        }
        html += '</div>';
      }
      html += '</div>';
      if (data.empty_state || ((!data.insights || !data.insights.length) && (!data.recommendations || !data.recommendations.length))) {
        if (window.LNKupUI) {
          var emptyMount = document.createElement('div');
          LNKupUI.panelEmpty(emptyMount, {
            title: LNKupUI.t('empty_growth', 'Growth insights coming soon'),
            desc: LNKupUI.t('empty_growth_desc', 'Apply to events and complete bookings to unlock personalized growth insights.'),
            ctaLabel: 'Find events',
            ctaHref: ajax.opportunitiesUrl || '/opportunities/',
            secondaryCtaLabel: 'Edit profile',
            secondaryCtaHref: '/vendor-profile/'
          });
          html = emptyMount.innerHTML + html;
        } else {
          html = '<div class="lnkup-empty-state lnkup-empty-state--compact"><p class="lnkup-empty-state__title">Growth insights coming soon</p><p class="lnkup-empty-state__desc">Apply to events and complete bookings to unlock personalized growth insights.</p></div>' + html;
        }
      }
      panel.innerHTML = html;
      growthFetch('/profile-strength').then(function (strength) {
        if (!strength || !strength.items) {
          return;
        }
        var checklist = '<div class="lnkup-growth-card"><h3 class="lnkup-growth-card__title">Growth checklist</h3><div class="lnkup-growth-checklist">';
        strength.items.slice(0, 6).forEach(function (item) {
          checklist += '<div class="lnkup-growth-checklist__item' + (item.complete ? ' is-done' : '') + '">' +
            (item.complete ? '✓' : '○') + ' ' + escapeHtml(item.label) + '</div>';
        });
        checklist += '</div></div>';
        panel.innerHTML = panel.innerHTML + checklist;
      }).catch(function () {});
    }).catch(function () {
      failPanel(panel);
    });
  }

  function loadRevenueGroup(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.innerHTML = '<div class="lnkup-growth-skeleton"></div>';
    Promise.all([growthFetch('/revenue'), growthFetch('/performance'), growthFetch('/applications')]).then(function (res) {
      panel.dataset.loaded = '1';
      var data = res[0] || {};
      var k = res[1] || {};
      var f = res[2] || {};
      var html = '<div class="lnkup-growth-card"><h3 class="lnkup-growth-card__title">Revenue Over Time</h3>';
      html += barChart(data.over_time, 'amount') + '</div>';
      html += '<div class="lnkup-growth-card"><h3 class="lnkup-growth-card__title">By Month</h3>';
      html += barChart(data.by_month, 'amount') + '</div>';
      html += '<div class="lnkup-growth-grid">';
      ['applications_submitted', 'applications_approved', 'applications_rejected', 'completed_bookings'].forEach(function (key) {
        html += '<div class="lnkup-growth-card"><span class="lnkup-muted">' + escapeHtml(key.replace(/_/g, ' ')) +
          '</span><br><strong>' + escapeHtml(String(k[key] || 0)) + '</strong></div>';
      });
      html += '</div>';
      if (f) {
        html += '<div class="lnkup-growth-card"><h3 class="lnkup-growth-card__title">Application Funnel</h3>';
        html += renderFunnel(f) + '</div>';
      }
      if (!data.over_time || !data.over_time.length) {
        html = '<div data-growth-revenue-empty></div>' + html;
      }
      panel.innerHTML = html;
      var revEmpty = panel.querySelector('[data-growth-revenue-empty]');
      if (revEmpty && window.LNKupUI) {
        LNKupUI.panelEmpty(revEmpty, {
          title: 'No revenue data yet',
          desc: 'Revenue charts appear after you complete paid bookings.',
          ctaLabel: 'Find events',
          ctaHref: (ajax.opportunitiesUrl || '/opportunities/')
        });
      }
    }).catch(function () {
      failPanel(panel);
    });
  }

  function loadMarketsGroup(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.innerHTML = '<div class="lnkup-growth-skeleton"></div>';
    Promise.all([
      growthFetch('/market-expansion'),
      growthFetch('/market-insights'),
      growthFetch('/relationships'),
      growthFetch('/roi'),
      growthFetch('/calendar')
    ]).then(function (res) {
      panel.dataset.loaded = '1';
      var exp = res[0] || {};
      var ins = res[1] || {};
      var rel = res[2] || {};
      var roiItems = Array.isArray(res[3]) ? res[3] : [];
      var cal = Array.isArray(res[4]) ? res[4] : [];
      var feedBase = ajax.opportunitiesUrl || '/';
      var citiesExplored = (exp.suggested_cities || []).length;
      var bestSeason = (ins.best_seasons && ins.best_seasons[0]) ? ins.best_seasons[0] : '—';
      var html = '<div class="lnkup-growth-kpi-strip">';
      html += '<div class="lnkup-growth-kpi"><p class="lnkup-growth-kpi__label">Cities to explore</p><p class="lnkup-growth-kpi__value">' + citiesExplored + '</p></div>';
      html += '<div class="lnkup-growth-kpi"><p class="lnkup-growth-kpi__label">Avg fee (markets)</p><p class="lnkup-growth-kpi__value">' + currency(ins.avg_vendor_fee) + '</p></div>';
      html += '<div class="lnkup-growth-kpi"><p class="lnkup-growth-kpi__label">Best season</p><p class="lnkup-growth-kpi__value">' + escapeHtml(bestSeason) + '</p></div>';
      html += '</div><div class="lnkup-growth-grid lnkup-growth-markets-grid">';

      if (exp.suggested_cities && exp.suggested_cities.length) {
        html += '<div class="lnkup-growth-card"><h3 class="lnkup-growth-card__title">Suggested Cities</h3><ul class="lnkup-growth-markets-list">';
        exp.suggested_cities.forEach(function (c) {
          var cityLabel = c.city || c.label || '';
          var link = feedBase + (feedBase.indexOf('?') >= 0 ? '&' : '?') + 'market=' + encodeURIComponent(cityLabel);
          html += '<li><strong>' + escapeHtml(cityLabel) + '</strong> — ' + escapeHtml(c.reason || '') +
            ' <a class="lnkup-growth-markets-link" href="' + escapeHtml(link) + '">Find events</a></li>';
        });
        html += '</ul></div>';
      }

      var hasTrends = (ins.trending_cities && ins.trending_cities.length) ||
        (ins.trending_categories && ins.trending_categories.length) ||
        (ins.fastest_growing && ins.fastest_growing.length);
      if (hasTrends) {
        html += '<div class="lnkup-growth-card"><h3 class="lnkup-growth-card__title">Market Trends</h3>';
        if (ins.trending_cities && ins.trending_cities.length) {
          html += '<p class="lnkup-muted">Trending cities</p><div class="lnkup-growth-chip-scroll">';
          ins.trending_cities.forEach(function (c) {
            var label = c.label || c.city || '';
            var href = feedBase + (feedBase.indexOf('?') >= 0 ? '&' : '?') + 'market=' + encodeURIComponent(label);
            html += '<a class="lnkup-growth-chip" href="' + escapeHtml(href) + '">' + escapeHtml(label) + '</a>';
          });
          html += '</div>';
        }
        if (ins.trending_categories && ins.trending_categories.length) {
          html += '<p class="lnkup-muted" style="margin-top:0.75rem">Trending categories</p><div class="lnkup-growth-chip-scroll">';
          ins.trending_categories.forEach(function (cat) {
            var name = cat.category || cat.name || cat.label || '';
            var href = feedBase + (feedBase.indexOf('?') >= 0 ? '&' : '?') + 'event_type=' + encodeURIComponent(name);
            html += '<a class="lnkup-growth-chip" href="' + escapeHtml(href) + '">' + escapeHtml(name) + '</a>';
          });
          html += '</div>';
        }
        if (ins.fastest_growing && ins.fastest_growing.length) {
          html += '<p class="lnkup-muted" style="margin-top:0.75rem">Fastest growing</p><ul class="lnkup-growth-markets-list">';
          ins.fastest_growing.forEach(function (item) {
            html += '<li>' + escapeHtml(item.title || item.name || item.label || '') + '</li>';
          });
          html += '</ul>';
        }
        html += '</div>';
      }

      var organizers = rel.top_organizers || [];
      if (organizers.length) {
        html += '<div class="lnkup-growth-card"><h3 class="lnkup-growth-card__title">Organizer Relationships</h3><div class="lnkup-growth-rel-cards">';
        organizers.slice(0, 6).forEach(function (o) {
          html += '<div class="lnkup-growth-rel-card"><strong>' + escapeHtml(o.name || o.organizer_name || '') + '</strong>';
          if (o.is_favorite) html += ' <span aria-label="Favorite">★</span>';
          html += '<p class="lnkup-muted">' + (o.bookings || 0) + ' events · ' + currency(o.revenue) + '</p>';
          if (o.last_booking) {
            html += '<p class="lnkup-muted">Last applied ' + escapeHtml(String(o.last_booking).slice(0, 10)) + '</p>';
          }
          html += '</div>';
        });
        html += '</div></div>';
      }

      if (roiItems.length) {
        html += '<div class="lnkup-growth-card"><h3 class="lnkup-growth-card__title">ROI Highlights</h3><div class="lnkup-growth-roi-list">';
        roiItems.slice(0, 6).forEach(function (item) {
          var roi = item.roi || {};
          var margin = Math.min(100, Math.max(0, roi.profit_margin || 0));
          html += '<div class="lnkup-growth-roi-row"><span class="lnkup-growth-roi-row__title">' + escapeHtml(item.event_title || '') +
            '</span><div class="lnkup-growth-roi-bar"><span style="width:' + margin + '%"></span></div>' +
            '<span class="lnkup-growth-roi-row__val">' + currency(roi.estimated_profit) + '</span></div>';
        });
        html += '</div></div>';
      }

      if (cal.length) {
        html += '<div class="lnkup-growth-card"><h3 class="lnkup-growth-card__title">Upcoming Calendar</h3><ul class="lnkup-growth-markets-list">';
        cal.slice(0, 5).forEach(function (ev) {
          var dt = ev.date || ev.event_date || '';
          html += '<li><strong>' + escapeHtml(ev.title || ev.event_title || '') + '</strong>';
          if (dt) html += ' <span class="lnkup-muted">' + escapeHtml(String(dt).slice(0, 10)) + '</span>';
          html += '</li>';
        });
        html += '</ul></div>';
      }

      if (!html.match(/lnkup-growth-card/)) {
        html += '<div class="lnkup-growth-card"><p class="lnkup-muted">Apply to events in new cities to expand your markets.</p></div>';
      }
      html += '</div>';
      panel.innerHTML = html;
    }).catch(function () {
      failPanel(panel);
    });
  }

  function loadProfileExportsGroup(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.innerHTML = '<div class="lnkup-growth-skeleton"></div>';
    Promise.all([growthFetch('/profile-strength')]).then(function (res) {
      var data = res[0] || {};
      panel.dataset.loaded = '1';
      var html = '<div class="lnkup-growth-ring" style="--pct:' + (data.score || 0) + '"><span class="lnkup-growth-ring__value">' +
        (data.score || 0) + '%</span><span class="lnkup-growth-ring__label">Complete</span></div>';
      html += '<div class="lnkup-growth-checklist" style="margin-top:1rem">';
      (data.items || []).forEach(function (item) {
        html += '<div class="lnkup-growth-checklist__item' + (item.complete ? ' is-done' : '') + '">' +
          (item.complete ? '✓' : '○') + ' ' + escapeHtml(item.label) + '</div>';
      });
      html += '</div><div class="lnkup-growth-grid" style="margin-top:1.5rem">';
      ['revenue', 'events', 'performance', 'tax_summary'].forEach(function (type) {
        html += '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-growth-export-btn" data-type="' +
          type + '">Export ' + escapeHtml(type.replace(/_/g, ' ')) + ' (CSV)</button>';
      });
      html += '</div>';
      panel.innerHTML = html;
      panel.querySelectorAll('.lnkup-growth-export-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var type = btn.getAttribute('data-type');
          growthFetch('/export/' + type).then(function (exportRes) {
            if (!exportRes || !exportRes.csv) {
              return;
            }
            var blob = new Blob([exportRes.csv], { type: 'text/csv' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = exportRes.filename || 'lnkup-export.csv';
            a.click();
          }).catch(function () {
            if (window.LNKupUI) {
              LNKupUI.panelError(panel, { title: 'Could not export this report.' });
            } else {
              alert('Could not export this report.');
            }
          });
        });
      });
    }).catch(function () {
      failPanel(panel);
    });
  }

  function loadRevenue(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.innerHTML = '<div class="lnkup-growth-skeleton"></div>';
    growthFetch('/revenue').then(function (data) {
      panel.dataset.loaded = '1';
      var html = '<div class="lnkup-growth-card"><h3 class="lnkup-growth-card__title">Revenue Over Time</h3>';
      html += barChart(data.over_time, 'amount') + '</div>';
      html += '<div class="lnkup-growth-card"><h3 class="lnkup-growth-card__title">By Month</h3>';
      html += barChart(data.by_month, 'amount') + '</div>';
      if (data.summary) {
        html += '<div class="lnkup-growth-grid"><div class="lnkup-growth-card"><strong>Avg/Event</strong><br>' +
          currency(data.summary.average_per_event) + '</div>';
        html += '<div class="lnkup-growth-card"><strong>Highest</strong><br>' + currency(data.summary.highest) + '</div>';
        html += '<div class="lnkup-growth-card"><strong>Growth</strong><br>' + (data.summary.growth_pct || 0) + '%</div></div>';
      }
      panel.innerHTML = html;
    }).catch(function () {
      failPanel(panel);
    });
  }

  function loadPerformance(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.innerHTML = '<div class="lnkup-growth-skeleton"></div>';
    Promise.all([growthFetch('/performance'), growthFetch('/applications')]).then(function (res) {
      panel.dataset.loaded = '1';
      var k = res[0];
      var f = res[1];
      var html = '<div class="lnkup-growth-grid">';
      ['applications_submitted', 'applications_approved', 'applications_rejected', 'completed_bookings', 'canceled_bookings', 'waitlists'].forEach(function (key) {
        html += '<div class="lnkup-growth-card"><span class="lnkup-muted">' + escapeHtml(key.replace(/_/g, ' ')) +
          '</span><br><strong>' + escapeHtml(String(k[key] || 0)) + '</strong></div>';
      });
      html += '</div>';
      if (f) {
        html += '<div class="lnkup-growth-card"><h3 class="lnkup-growth-card__title">Application Funnel</h3>';
        html += '<p>Approval: ' + (f.approval_rate || 0) + '% · Rejection: ' + (f.rejection_rate || 0) +
          '% · Avg approval: ' + (f.avg_approval_hours || 0) + 'h</p></div>';
      }
      panel.innerHTML = html;
    }).catch(function () {
      failPanel(panel);
    });
  }

  function loadGoals(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.innerHTML = '<div class="lnkup-growth-skeleton"></div>';
    Promise.all([growthFetch('/goals'), growthFetch('/achievements')]).then(function (res) {
      panel.dataset.loaded = '1';
      var goals = res[0] || [];
      var ach = res[1] || {};
      var html = '<div class="lnkup-growth-grid">';
      goals.forEach(function (g) {
        html += '<div class="lnkup-growth-card"><h3 class="lnkup-growth-card__title">' + escapeHtml(g.title) +
          '</h3><div class="lnkup-growth-ring" style="--pct:' + g.progress_pct + '"><span class="lnkup-growth-ring__value">' +
          g.progress_pct + '%</span></div><p class="lnkup-muted">' + g.current_value + ' / ' + g.target_value + ' ' +
          escapeHtml(g.unit || '') + '</p></div>';
      });
      html += '</div>';
      if (ach.earned) {
        html += '<h3>Achievement Badges</h3>';
        ach.earned.forEach(function (a) {
          html += '<span class="lnkup-growth-badge">' + escapeHtml(a.label) + '</span>';
        });
      }
      panel.innerHTML = html || '<p class="lnkup-muted">No goals yet.</p>';
    });
  }

  function loadMarkets(panel) {
    loadMarketsGroup(panel);
  }

  function loadRelationships(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.innerHTML = '<div class="lnkup-growth-skeleton"></div>';
    growthFetch('/relationships').then(function (data) {
      panel.dataset.loaded = '1';
      var html = '<table class="lnkup-growth-table"><thead><tr><th>Organizer</th><th>Bookings</th><th>Revenue</th></tr></thead><tbody>';
      (data.top_organizers || []).forEach(function (o) {
        html += '<tr><td>' + escapeHtml(o.name) + (o.is_favorite ? ' ★' : '') + '</td><td>' + o.bookings +
          '</td><td>' + currency(o.revenue) + '</td></tr>';
      });
      html += '</tbody></table>';
      panel.innerHTML = html;
    });
  }

  function loadRoi(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.innerHTML = '<div class="lnkup-growth-skeleton"></div>';
    growthFetch('/roi').then(function (items) {
      panel.dataset.loaded = '1';
      if (!items || !items.length) {
        panel.innerHTML = '<p class="lnkup-muted">Complete events to track ROI and profit estimates.</p>';
        return;
      }
      var html = '<table class="lnkup-growth-table"><thead><tr><th>Event</th><th>Revenue</th><th>Profit</th><th>Rating</th></tr></thead><tbody>';
      items.forEach(function (item) {
        var roi = item.roi || {};
        html += '<tr><td>' + escapeHtml(item.event_title) + '</td><td>' + currency(roi.gross_revenue) +
          '</td><td>' + currency(roi.estimated_profit) + '</td><td>' + escapeHtml(roi.worth_it_rating || '—') + '</td></tr>';
      });
      html += '</tbody></table><p class="lnkup-muted">ROI ratings are private — organizers cannot see them.</p>';
      panel.innerHTML = html;
    });
  }

  function loadCalendar(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.innerHTML = '<div class="lnkup-growth-skeleton"></div>';
    growthFetch('/calendar').then(function (items) {
      panel.dataset.loaded = '1';
      var html = '';
      (items || []).forEach(function (item) {
        html += '<div class="lnkup-growth-calendar-item"><span class="lnkup-growth-calendar-item__date">' +
          escapeHtml(item.date) + '</span><span><strong>' + escapeHtml(item.type) + '</strong> — ' +
          escapeHtml(item.title) + '</span></div>';
      });
      panel.innerHTML = html || '<p class="lnkup-muted">No upcoming calendar items.</p>';
    });
  }

  function loadProfile(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.innerHTML = '<div class="lnkup-growth-skeleton"></div>';
    growthFetch('/profile-strength').then(function (data) {
      panel.dataset.loaded = '1';
      var items = data.items || data.sections || [];
      var html = '<div class="lnkup-growth-ring" style="--pct:' + (data.score || 0) + '"><span class="lnkup-growth-ring__value">' +
        (data.score || 0) + '%</span><span class="lnkup-growth-ring__label">' + escapeHtml(data.tier || 'Complete') + '</span></div>';
      html += '<div class="lnkup-growth-checklist" style="margin-top:1rem">';
      items.forEach(function (item) {
        var done = item.complete || item.status === 'complete';
        var label = item.label || item.title || '';
        html += '<div class="lnkup-growth-checklist__item' + (done ? ' is-done' : '') + '">' +
          (done ? '✓' : '○') + ' ' + escapeHtml(label) + '</div>';
      });
      html += '</div>';
      panel.innerHTML = html;
    });
  }

  function loadExports(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    panel.dataset.loaded = '1';
    var types = ['revenue', 'events', 'performance', 'tax_summary'];
    var html = '<div class="lnkup-growth-grid">';
    types.forEach(function (type) {
      html += '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-growth-export-btn" data-type="' +
        type + '">Export ' + escapeHtml(type.replace(/_/g, ' ')) + ' (CSV)</button>';
    });
    html += '</div><p class="lnkup-muted">PDF exports coming soon.</p>';
    panel.innerHTML = html;
    panel.querySelectorAll('.lnkup-growth-export-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var type = btn.getAttribute('data-type');
        growthFetch('/export/' + type).then(function (res) {
          if (!res || !res.csv) {
            return;
          }
          var blob = new Blob([res.csv], { type: 'text/csv' });
          var a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          a.download = res.filename || 'lnkup-export.csv';
          a.click();
        });
      });
    });
  }

  function init() {
    var shell = document.getElementById('lnkup-vendor-growth');
    if (!shell) {
      return;
    }
    bindTabs(shell);
    var loaders = {
      overview: loadOverview,
      revenue: loadRevenueGroup,
      markets: loadMarketsGroup,
      'profile-exports': loadProfileExportsGroup
    };
    shell.addEventListener('lnkup-growth-panel-open', function (e) {
      var panel = e.target;
      if (!panel || !panel.dataset.panel) {
        return;
      }
      var fn = loaders[panel.dataset.panel];
      if (fn) {
        fn(panel);
      }
    });
    var overview = document.getElementById('lnkup-growth-overview');
    if (overview) {
      loadOverview(overview);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
