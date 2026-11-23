(function () {
  var ajax = window.lnkupAjax || {};
  var base = ajax.connectorRestUrl || '';
  var nonce = ajax.restNonce || '';
  var API_ERROR = 'We couldn\'t load this section. Try refreshing or contact support.';
  var VIEWS = ['home', 'start', 'referrals', 'leads', 'cities', 'earnings', 'resources', 'profile', 'ranks', 'studio'];
  var ALIASES = {
    guide: 'resources',
    links: 'start',
    rewards: 'earnings',
    vendors: 'referrals',
    organizers: 'referrals',
    activity: 'referrals',
    create: 'studio'
  };
  var GROWTH_CARDS = [
    { id: 'relationship', title: 'Relationship Connector', body: 'Best for promoters and community leaders who prefer DMs, calls, and texts.' },
    { id: 'video', title: 'Video Creator', body: 'Best for Reels, TikToks, Stories, and short-form video.' },
    { id: 'community', title: 'Community Connector', body: 'Best for Facebook groups, campuses, chambers, and group chats.' },
    { id: 'event', title: 'Event Connector', body: 'Best for pop-ups, markets, nightlife, and in-person vendor events.' }
  ];
  var MILESTONE_LABELS = {
    clicked: 'Link clicked',
    registered: 'Registered',
    profile_completed: 'Profile completed',
    verified: 'Verified',
    opportunity_published: 'Opportunity published',
    first_payment: 'First payment',
    activated: 'Activated',
    reward_pending: 'Reward pending',
    reward_paid: 'Reward paid',
    connector_booking_fee: 'Booth booking',
    connector_vendor_signup: 'Vendor signup credit'
  };
  var STATUS_LABELS = {
    estimated: 'Estimated',
    pending_hold: 'Pending hold',
    awaiting_approval: 'Awaiting approval',
    pending: 'Pending',
    approved: 'Approved',
    paid: 'Paid',
    reversed: 'Reversed',
    expired: 'Expired',
    on_hold: 'Under review'
  };
  var STATUS_HELP = {
    estimated: 'Projected from booth GMV. Not payable until a booking completes and LNKup approves it.',
    pending_hold: 'The payment is in the dispute and refund hold. It is not available yet.',
    awaiting_approval: 'The hold has ended. LNKup still has to review and approve this reward.',
    pending: 'Held or waiting for LNKup approval. Not mixed with estimates.',
    approved: 'Approved and payable. Not paid until LNKup marks it paid.',
    paid: 'Marked paid by LNKup.',
    reversed: 'Removed after a refund or chargeback.',
    expired: 'Outside the introductory term for that organizer.',
    on_hold: 'LNKup is reviewing this reward. It is not payable until the review is resolved.'
  };
  var STUDIO_PLATFORMS = [
    { id: 'instagram', label: 'Instagram', placeholder: 'https://www.instagram.com/reel/...', hint: 'Paste an Instagram Reel, post, or Story link.' },
    { id: 'tiktok', label: 'TikTok', placeholder: 'https://www.tiktok.com/@name/video/...', hint: 'Paste a TikTok video link.' },
    { id: 'linkedin', label: 'LinkedIn', placeholder: 'https://www.linkedin.com/posts/...', hint: 'Paste a LinkedIn post or video link.' },
    { id: 'facebook', label: 'Facebook', placeholder: 'https://www.facebook.com/...', hint: 'Paste a Facebook Reel, video, or post link.' }
  ];
  var STUDIO_AUDIENCES = [
    { id: 'organizer', label: 'Organizers' },
    { id: 'vendor', label: 'Vendors' },
    { id: 'both', label: 'Both organizers and vendors' }
  ];
  var REFERRAL_FILTERS = [
    { id: 'all', label: 'All' },
    { id: 'organizer', label: 'Organizers' },
    { id: 'vendor', label: 'Vendors' },
    { id: 'registered', label: 'Registered' },
    { id: 'verified', label: 'Verified' },
    { id: 'published', label: 'Published' },
    { id: 'activated', label: 'Activated' },
    { id: 'inactive', label: 'Inactive' },
    { id: 'reward', label: 'Reward eligible' }
  ];
  var cache = { links: null, terms: null, lastFocus: null, trapHandler: null };

  function safeFetch(path, options) {
    options = options || {};
    var headers = { 'X-WP-Nonce': nonce };
    if (options.body) {
      headers['Content-Type'] = 'application/json';
    }
    return fetch(base + path, {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers: headers,
      body: options.body ? JSON.stringify(options.body) : undefined
    }).then(function (r) {
      var ct = r.headers.get('content-type') || '';
      if (!r.ok || ct.indexOf('application/json') === -1) {
        throw new Error('api_error');
      }
      return r.json();
    });
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function studioPlatform(id) {
    var key = String(id || '').toLowerCase().replace(/_/g, '');
    if (key.indexOf('instagram') !== -1 || key === 'ig' || key === 'reels') {
      return STUDIO_PLATFORMS[0];
    }
    if (key.indexOf('tiktok') !== -1) {
      return STUDIO_PLATFORMS[1];
    }
    if (key.indexOf('linkedin') !== -1) {
      return STUDIO_PLATFORMS[2];
    }
    if (key.indexOf('facebook') !== -1 || key.indexOf('fb') === 0) {
      return STUDIO_PLATFORMS[3];
    }
    return null;
  }

  function studioPlatformLabel(id) {
    var row = studioPlatform(id);
    return row ? row.label : (id ? String(id).replace(/_/g, ' ') : '');
  }

  function studioAudienceLabel(id) {
    var key = String(id || '').toLowerCase();
    if (key === 'vendor' || key === 'vendors') {
      return 'Vendors';
    }
    if (key === 'organizer' || key === 'organizers') {
      return 'Organizers';
    }
    if (key === 'both' || key === 'all') {
      return 'Both organizers and vendors';
    }
    return id ? String(id).replace(/_/g, ' ') : '';
  }

  function inferStudioPlatform(url) {
    var host = '';
    try {
      host = String(new URL(url).hostname || '').toLowerCase();
    } catch (err) {
      return '';
    }
    if (host.indexOf('instagram') !== -1) {
      return 'instagram';
    }
    if (host.indexOf('tiktok') !== -1) {
      return 'tiktok';
    }
    if (host.indexOf('linkedin') !== -1 || host === 'lnkd.in') {
      return 'linkedin';
    }
    if (host.indexOf('facebook') !== -1 || host === 'fb.watch') {
      return 'facebook';
    }
    return '';
  }

  function studioMetaLine(item) {
    item = item || {};
    var parts = [];
    var plat = studioPlatformLabel(item.platform || (item.platforms && item.platforms[0]) || '');
    var aud = studioAudienceLabel(item.audience);
    if (plat) {
      parts.push(plat);
    }
    if (aud) {
      parts.push(aud);
    }
    if (item.duration) {
      parts.push(String(item.duration));
    }
    return parts.join(' · ');
  }

  function studioPostStatusLabel(status) {
    var key = String(status || '').toLowerCase();
    if (key === 'planned') {
      return 'Planned';
    }
    if (key === 'published') {
      return 'Published';
    }
    if (key === 'draft') {
      return 'Draft';
    }
    if (key === 'needs_review') {
      return 'Needs review';
    }
    if (key === 'archived') {
      return 'Archived';
    }
    return status ? String(status).replace(/_/g, ' ') : '';
  }

  function studioCopyBlock(title, body, copyLabel) {
    var text = body == null ? '' : String(body);
    var html = '<section class="lnkup-connector-builder__block">';
    html += '<div class="lnkup-connector-builder__block-head">';
    html += '<h4>' + escapeHtml(title) + '</h4>';
    if (text) {
      html += '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-connector-copy" data-copy="' + escapeHtml(text) + '">' + escapeHtml(copyLabel) + '</button>';
    }
    html += '</div>';
    html += '<pre class="lnkup-connector-builder__text">' + escapeHtml(text) + '</pre>';
    html += '</section>';
    return html;
  }

  function studioPerfCard(label, value, help) {
    return '<div class="lnkup-connector-perf-card"><span class="lnkup-connector-kpi__label">' + escapeHtml(label) + '</span><strong>' + escapeHtml(value == null ? 0 : value) + '</strong><p class="lnkup-muted">' + escapeHtml(help) + '</p></div>';
  }

  function fillScript(body, links) {
    links = links || cache.links || {};
    var city = cache.city || links.city || '';
    return String(body || '')
      .replace(/\{city\}/g, city || 'your city')
      .replace(/\{organizer\}/g, links.organizer || '')
      .replace(/\{vendor\}/g, links.vendor || '');
  }

  function currency(val) {
    var n = parseFloat(val) || 0;
    return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  }

  function rootEl() {
    return document.getElementById('lnkup-connector-dashboard');
  }

  function liveRegion() {
    return document.querySelector('[data-connector-live]');
  }

  function announce(message) {
    var live = liveRegion();
    if (live) {
      live.textContent = message;
    }
  }

  function allowedViews() {
    var root = rootEl();
    var raw = root && root.getAttribute('data-views');
    return raw ? raw.split(',') : VIEWS;
  }

  function program() {
    var root = rootEl();
    return (root && root.getAttribute('data-program')) || 'city_connector';
  }

  function isCityConnector() {
    return program() === 'city_connector';
  }

  function normalizeView(view) {
    view = ALIASES[view] || view;
    return allowedViews().indexOf(view) !== -1 ? view : 'home';
  }

  function viewHref(view) {
    var url = new URL(window.location.href);
    url.searchParams.set('view', normalizeView(view));
    return url.pathname + url.search + url.hash;
  }

  function viewFromUrl() {
    var url = new URL(window.location.href);
    var raw = url.searchParams.get('view') || '';
    if (raw && (VIEWS.indexOf(raw) !== -1 || ALIASES[raw])) {
      return normalizeView(raw);
    }
    return normalizeView((rootEl() && rootEl().getAttribute('data-view')) || 'home');
  }

  function termsLine(terms) {
    terms = terms || {};
    var pct = terms.share_percent != null ? terms.share_percent : 10;
    var months = terms.term_months != null ? terms.term_months : 12;
    var line = terms.basis_label
      ? 'You receive ' + terms.basis_label + ' for ' + months + ' months.'
      : 'You receive ' + pct + '% of qualifying booth bookings for ' + months + ' months.';
    if (parseFloat(terms.share_cap) > 0) {
      line += ' Cap ' + currency(terms.share_cap) + ' per transaction.';
    }
    return line;
  }

  function statusLabel(status) {
    return STATUS_LABELS[status] || String(status || '').replace(/_/g, ' ');
  }

  function milestoneLabel(type) {
    return MILESTONE_LABELS[type] || String(type || '').replace(/_/g, ' ');
  }

  function programStatus(status) {
    var map = { pending: 'Pending', active: 'Active', paused: 'Paused', rejected: 'Inactive', inactive: 'Inactive' };
    return map[status] || status || 'Pending';
  }

  function markStep(step) {
    return safeFetch('/connector/onboarding/step', { method: 'POST', body: { step: step } }).catch(function () {
      return null;
    });
  }

  function rememberLinks(links, terms, city) {
    if (links) {
      cache.links = links;
    }
    if (terms) {
      cache.terms = terms;
    }
    if (city) {
      cache.city = city;
    }
  }

  function copyText(text, btn) {
    if (!text) {
      return Promise.resolve();
    }
    var label = btn ? (btn.getAttribute('data-copy-label') || btn.textContent || 'Copy') : 'Copy';
    function markCopied() {
      announce((btn && btn.getAttribute('data-copy-announce')) || 'Referral link copied');
      if (!btn) {
        return;
      }
      btn.classList.add('is-copied');
      btn.textContent = 'Copied';
      setTimeout(function () {
        btn.classList.remove('is-copied');
        btn.textContent = label;
      }, 2000);
    }
    var done = navigator.clipboard && navigator.clipboard.writeText
      ? navigator.clipboard.writeText(text)
      : new Promise(function (resolve) {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        resolve();
      });
    return done.then(markCopied);
  }

  function bindCopyButtons(scope) {
    if (!scope) {
      return;
    }
    scope.querySelectorAll('.lnkup-connector-copy').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var text = btn.getAttribute('data-copy') || btn.getAttribute('data-url') || '';
        copyText(text, btn).then(function () {
          var step = btn.getAttribute('data-onboard');
          if (step) {
            markStep(step);
          }
        });
      });
    });
  }

  function qrUrl(url) {
    return url ? 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent(url) : '';
  }

  function scriptTemplates(links, kind) {
    var org = (links && links.organizer) || '';
    var vendor = (links && links.vendor) || '';
    if (kind === 'vendor') {
      return [
        {
          id: 'vendor-ig',
          title: 'Vendor Instagram DM',
          body: 'Hey [Name], I’m a Founding City Partner with LNKup. LNKup helps vendors find local events, apply, and manage booth payments in one place. Want the vendor link?\n\n' + vendor,
          step: 'copied_vendor_link'
        },
        {
          id: 'vendor-text',
          title: 'Vendor text',
          body: 'I’m helping vendors in the city find booths through LNKup. Here’s my vendor referral link if you want in:\n\n' + vendor,
          step: 'copied_vendor_link'
        },
        {
          id: 'vendor-email',
          title: 'Vendor email',
          body: 'Subject: Vendor spots in [City]\n\nHi [Name], LNKup is where local organizers post vendor opportunities. You can apply and pay booth fees in one place. Here’s the tracked vendor link:\n\n' + vendor,
          step: 'copied_vendor_link'
        }
      ];
    }
    return [
      {
        id: 'ig',
        title: 'Instagram DM',
        body: 'Hey [Name], I’m a Founding City Partner with LNKup. LNKup helps event organizers find vendors, collect applications and payments, and manage vendor operations from one platform. You can keep your current ticketing system, and LNKup will personally help build your first opportunity. Want me to send you the organizer link?\n\n' + org,
        step: 'sent_intro'
      },
      {
        id: 'email',
        title: 'Email',
        body: 'Subject: Bring More Qualified Vendors to [Event Name]\n\nHi [Name], I’m reaching out as a LNKup City Partner. LNKup connects event organizers with food, retail and service vendors while managing applications, documents and vendor payments in one place. There is no need to change your current ticketing platform. If you send over your event flyer or existing vendor form, the LNKup team can help build your first opportunity.\n\n' + org,
        step: 'sent_intro'
      },
      {
        id: 'text',
        title: 'Text message',
        body: 'I’m working with LNKup to help local organizers fill vendor spaces and manage applications and payments. I thought it could be useful for your upcoming events. Can I send you the organizer link?\n\n' + org,
        step: 'sent_intro'
      }
    ];
  }

  function renderScripts(links, kind) {
    var html = '<section class="lnkup-connector-card" id="' + (kind === 'vendor' ? 'vendor-scripts' : 'scripts') + '"><h3>' + (kind === 'vendor' ? 'Vendor scripts' : 'Outreach scripts') + '</h3>';
    scriptTemplates(links, kind).forEach(function (msg) {
      html += '<div class="lnkup-connector-sample" data-testid="lnkup-connector-script-' + escapeHtml(msg.id) + '">' +
        '<p class="lnkup-connector-sample__title">' + escapeHtml(msg.title) + '</p>' +
        '<p class="lnkup-connector-sample__body">' + escapeHtml(msg.body) + '</p>' +
        '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-connector-copy" data-copy-label="Copy message" data-copy="' + escapeHtml(msg.body) + '" data-onboard="' + escapeHtml(msg.step) + '">Copy message</button>' +
        '</div>';
    });
    html += '</section>';
    return html;
  }

  function renderShareSheet(links) {
    links = links || cache.links || {};
    var org = links.organizer || '';
    var vendor = links.vendor || '';
    var orgQr = qrUrl(org);
    var vendorQr = qrUrl(vendor);
    var html = '<div class="lnkup-connector-sheet__backdrop" data-sheet-close></div>';
    html += '<div class="lnkup-connector-sheet__panel" role="document">';
    html += '<div class="lnkup-connector-sheet__head">';
    html += '<h2 id="lnkup-connector-share-title">Share Your Link</h2>';
    html += '<button type="button" class="lnkup-connector-sheet__close" data-sheet-close aria-label="Close">Close</button>';
    html += '</div>';
    html += '<div class="lnkup-connector-sheet__tabs" role="tablist">';
    html += '<button type="button" class="is-active" role="tab" aria-selected="true" data-share-tab="organizer">Organizers</button>';
    html += '<button type="button" role="tab" aria-selected="false" data-share-tab="vendor">Vendors</button>';
    html += '</div>';
    html += '<div class="lnkup-connector-sheet__pane" data-share-pane="organizer">';
    html += '<label class="lnkup-connector-link-row__label">Organizer referral URL</label>';
    html += '<div class="lnkup-connector-link-row__field">';
    html += '<input type="text" readonly class="lnkup-connector-link-input" data-testid="lnkup-connector-organizer-url" value="' + escapeHtml(org) + '" />';
    html += '<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-connector-copy" data-copy-label="Copy link" data-url="' + escapeHtml(org) + '" data-onboard="copied_link">Copy link</button>';
    html += '</div>';
    if (links.code) {
      html += '<p class="lnkup-muted">Referral code: <strong>' + escapeHtml(links.code) + '</strong></p>';
    }
    html += '<div class="lnkup-connector-sheet__actions">';
    html += '<button type="button" class="lnkup-btn lnkup-btn--outline" data-native-share="' + escapeHtml(org) + '" data-onboard="copied_link">Share</button>';
    html += '<a class="lnkup-btn lnkup-btn--outline" href="instagram://app" data-instagram data-onboard="sent_intro">Open Instagram</a>';
    html += '</div>';
    if (orgQr) {
      html += '<img class="lnkup-connector-qr" alt="Organizer referral QR code" width="180" height="180" src="' + escapeHtml(orgQr) + '" />';
      html += '<p><a class="lnkup-btn lnkup-btn--outline" href="' + escapeHtml(orgQr) + '" download="lnkup-organizer-qr.png">Download QR</a></p>';
    }
    html += renderScripts(links, 'organizer').replace('id="scripts"', 'id="share-scripts"');
    html += '</div>';
    html += '<div class="lnkup-connector-sheet__pane" data-share-pane="vendor" hidden>';
    html += '<label class="lnkup-connector-link-row__label">Vendor referral URL</label>';
    html += '<div class="lnkup-connector-link-row__field">';
    html += '<input type="text" readonly class="lnkup-connector-link-input" data-testid="lnkup-connector-vendor-url" value="' + escapeHtml(vendor) + '" />';
    html += '<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-connector-copy" data-copy-label="Copy link" data-url="' + escapeHtml(vendor) + '" data-onboard="copied_vendor_link">Copy link</button>';
    html += '</div>';
    html += '<p class="lnkup-muted">Vendor signups are tracked. They do not earn commission unless LNKup turns on a vendor reward rule.</p>';
    html += '<div class="lnkup-connector-sheet__actions">';
    html += '<button type="button" class="lnkup-btn lnkup-btn--outline" data-native-share="' + escapeHtml(vendor) + '" data-onboard="copied_vendor_link">Share</button>';
    html += '</div>';
    if (vendorQr) {
      html += '<img class="lnkup-connector-qr" alt="Vendor referral QR code" width="180" height="180" src="' + escapeHtml(vendorQr) + '" />';
      html += '<p><a class="lnkup-btn lnkup-btn--outline" href="' + escapeHtml(vendorQr) + '" download="lnkup-vendor-qr.png">Download QR</a></p>';
    }
    html += renderScripts(links, 'vendor');
    html += '</div></div>';
    return html;
  }

  function closeShareSheet() {
    var sheet = document.getElementById('lnkup-connector-share-sheet');
    if (!sheet) {
      return;
    }
    sheet.hidden = true;
    sheet.classList.remove('is-open');
    document.body.classList.remove('lnkup-connector-sheet-open');
    if (cache.trapHandler) {
      document.removeEventListener('keydown', cache.trapHandler);
      cache.trapHandler = null;
    }
    if (cache.lastFocus && cache.lastFocus.focus) {
      cache.lastFocus.focus();
    }
  }

  function trapFocus(sheet) {
    var focusable = sheet.querySelectorAll('a, button, input, textarea, select, [tabindex]:not([tabindex="-1"])');
    var list = Array.prototype.filter.call(focusable, function (el) {
      return !el.disabled && el.offsetParent !== null;
    });
    if (list[0]) {
      list[0].focus();
    }
    cache.trapHandler = function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeShareSheet();
        return;
      }
      if (e.key !== 'Tab' || !list.length) {
        return;
      }
      var first = list[0];
      var last = list[list.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    };
    document.addEventListener('keydown', cache.trapHandler);
  }

  function bindShareSheet(sheet) {
    bindCopyButtons(sheet);
    sheet.querySelectorAll('[data-sheet-close]').forEach(function (el) {
      el.addEventListener('click', closeShareSheet);
    });
    sheet.querySelectorAll('[data-share-tab]').forEach(function (tab) {
      tab.addEventListener('click', function () {
        var id = tab.getAttribute('data-share-tab');
        sheet.querySelectorAll('[data-share-tab]').forEach(function (t) {
          var on = t === tab;
          t.classList.toggle('is-active', on);
          t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        sheet.querySelectorAll('[data-share-pane]').forEach(function (pane) {
          pane.hidden = pane.getAttribute('data-share-pane') !== id;
        });
      });
    });
    sheet.querySelectorAll('[data-native-share]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var url = btn.getAttribute('data-native-share') || '';
        var step = btn.getAttribute('data-onboard');
        if (navigator.share && url) {
          navigator.share({ title: 'LNKup referral link', url: url }).then(function () {
            if (step) {
              markStep(step);
            }
          }).catch(function () {
            copyText(url, btn).then(function () {
              if (step) {
                markStep(step);
              }
            });
          });
        } else {
          copyText(url, btn).then(function () {
            if (step) {
              markStep(step);
            }
          });
        }
      });
    });
    sheet.querySelectorAll('[data-instagram]').forEach(function (link) {
      link.addEventListener('click', function () {
        var step = link.getAttribute('data-onboard');
        if (step) {
          markStep(step);
        }
        window.setTimeout(function () {
          if (!document.hidden) {
            window.location.href = 'https://instagram.com';
          }
        }, 400);
      });
    });
  }

  function openShareSheet() {
    var sheet = document.getElementById('lnkup-connector-share-sheet');
    if (!sheet) {
      return;
    }
    cache.lastFocus = document.activeElement;
    function show(links) {
      rememberLinks(links);
      sheet.innerHTML = renderShareSheet(links);
      sheet.hidden = false;
      sheet.classList.add('is-open');
      document.body.classList.add('lnkup-connector-sheet-open');
      bindShareSheet(sheet);
      trapFocus(sheet);
    }
    if (cache.links) {
      show(cache.links);
      return;
    }
    safeFetch('/connector/links').then(show).catch(function () {
      announce('We couldn’t load your referral links.');
    });
  }

  function renderBadge(terms) {
    terms = terms || {};
    if (terms.terms_pending) {
      return '<span class="lnkup-connector-badge">Commission terms pending</span>';
    }
    return '<span class="lnkup-connector-badge">' + escapeHtml((terms.share_percent || 10) + '% for ' + (terms.term_months || 12) + ' months') + '</span>';
  }

  function renderTermsPending() {
    return (
      '<section class="lnkup-connector-card" data-testid="lnkup-connector-terms-pending">' +
      '<h3>How You Get Paid</h3>' +
      '<p>Your referrals are tracked. Commission terms are shared when your agreement is finalized.</p>' +
      '<p class="lnkup-muted">Until terms are published, nothing is earned or owed. LNKup only pays on verified activity under approved terms. There is no base payment or guaranteed income.</p>' +
      '</section>'
    );
  }

  function cityLabel(connector) {
    connector = connector || {};
    if (connector.city && connector.state) {
      return connector.city + ', ' + connector.state;
    }
    return connector.city || '';
  }

  function renderHero(data, compact) {
    var connector = (data && data.connector) || {};
    var terms = (data && data.terms) || {};
    var onboarding = (data && data.onboarding) || {};
    var city = cityLabel(connector);
    var city_program = isCityConnector();
    var title = compact
      ? (city ? 'Grow LNKup in ' + city : 'Grow LNKup in your city')
      : 'Build LNKup in Your City';
    if (!city_program) {
      title = data.organization ? data.organization + ' × LNKup' : 'Refer organizers to LNKup';
    }
    var avatar = connector.avatar_url
      ? '<img class="lnkup-connector-avatar" src="' + escapeHtml(connector.avatar_url) + '" alt="" width="48" height="48" />'
      : '<span class="lnkup-connector-avatar lnkup-connector-avatar--fallback" aria-hidden="true">' + escapeHtml(connector.initials || 'CC') + '</span>';
    return (
      '<section class="lnkup-connector-hero' + (compact ? ' lnkup-connector-hero--compact' : '') + '" data-testid="lnkup-connector-get-started">' +
      '<p class="lnkup-connector-kicker">' + escapeHtml(city_program ? 'Founding City Partner' : (data.program_label || 'LNKup Partner')) + '</p>' +
      '<div class="lnkup-connector-hero__identity">' + avatar +
      '<div><h2 class="lnkup-connector-hero__title">' + escapeHtml(title) + '</h2>' +
      '<p class="lnkup-connector-hero__name">' + escapeHtml(connector.display_name || (city_program ? 'City Connector' : 'Partner')) + '</p></div></div>' +
      (compact ? '' : '<p class="lnkup-connector-hero__lead">Connect local event organizers with LNKup, help create more opportunities for vendors and earn from the activity you generate.</p>') +
      '<div class="lnkup-connector-hero__meta">' +
      '<span>' + escapeHtml(programStatus(connector.status)) + '</span>' +
      (data.code ? '<span>Code ' + escapeHtml(data.code) + '</span>' : '') +
      renderBadge(terms) +
      '</div>' +
      (city_program ? '<p class="lnkup-muted">Onboarding progress: ' + escapeHtml(onboarding.progress || 0) + '%</p>' : '') +
      '<div class="lnkup-connector-hero__actions">' +
      '<button type="button" class="lnkup-btn lnkup-btn--primary" data-connector-share data-testid="lnkup-connector-copy-organizer">Share Your Link</button>' +
      '<a class="lnkup-btn lnkup-btn--outline" href="' + escapeHtml(viewHref('earnings')) + '" data-view="earnings">View Earnings</a>' +
      '</div></section>'
    );
  }

  function renderNextAction(action) {
    if (!action) {
      return '';
    }
    var cta = action.open_share
      ? '<button type="button" class="lnkup-btn lnkup-btn--primary" data-connector-share>' + escapeHtml(action.cta_label || 'Share Your Link') + '</button>'
      : '<a class="lnkup-btn lnkup-btn--primary" href="' + escapeHtml(viewHref(action.cta_view || 'start')) + '" data-view="' + escapeHtml(action.cta_view || 'start') + '">' + escapeHtml(action.cta_label || 'Open next step') + '</a>';
    return '<section class="lnkup-connector-card lnkup-connector-nba" data-testid="lnkup-connector-nba">' +
      '<h3>Your Next Step</h3>' +
      '<p><strong>' + escapeHtml(action.title || '') + '</strong></p>' +
      '<p class="lnkup-muted">' + escapeHtml(action.description || '') + '</p>' +
      cta + '</section>';
  }

  function stepCta(step) {
    if (!step || step.done) {
      return '';
    }
    var label = step.cta_label || 'Continue Setup';
    if (label === 'Continue') {
      label = 'Continue Setup';
    }
    if (step.open_share) {
      return '<button type="button" class="lnkup-btn lnkup-btn--primary" data-connector-share>' + escapeHtml(label) + '</button>';
    }
    if (step.open_studio || step.cta_view === 'studio') {
      return '<a class="lnkup-btn lnkup-btn--primary" href="' + escapeHtml(viewHref('studio')) + '" data-view="studio">' + escapeHtml(label) + '</a>';
    }
    if (step.cta_view) {
      return '<a class="lnkup-btn lnkup-btn--primary" href="' + escapeHtml(viewHref(step.cta_view)) + '" data-view="' + escapeHtml(step.cta_view) + '">' + escapeHtml(label) + '</a>';
    }
    return '';
  }

  function renderGrowthStyles(selected) {
    selected = selected || [];
    var html = '<section class="lnkup-connector-card" data-testid="lnkup-connector-growth-styles"><h3>How do you want to grow LNKup?</h3>';
    html += '<p class="lnkup-muted">Select every style that fits. We will recommend tasks that match.</p>';
    html += '<div class="lnkup-connector-growth">';
    GROWTH_CARDS.forEach(function (card) {
      var on = selected.indexOf(card.id) !== -1;
      html += '<button type="button" class="lnkup-connector-growth__card' + (on ? ' is-selected' : '') + '" data-growth="' + card.id + '" aria-pressed="' + (on ? 'true' : 'false') + '">';
      html += '<strong>' + escapeHtml(card.title) + '</strong><span>' + escapeHtml(card.body) + '</span></button>';
    });
    html += '</div></section>';
    return html;
  }

  function renderLaunchPlan(data) {
    data = data || {};
    var phases = data.phases || [];
    var current = data.current_action || {};
    var phase = phases[(data.current_phase || 1) - 1] || phases[0] || {};
    var rank = data.rank || {};
    var html = '<section class="lnkup-connector-card lnkup-connector-launch" data-testid="lnkup-connector-launch-plan" id="lnkup-connector-get-started">';
    html += '<div class="lnkup-connector-card__head"><h3>Your Connector Launch Plan</h3>';
    html += stepCta(current) || '<button type="button" class="lnkup-btn lnkup-btn--primary" data-connector-share>Continue Setup</button>';
    html += '</div>';
    html += '<p class="lnkup-connector-launch__phase">' + escapeHtml(phase.label || 'Phase 1 of 3: Set Up') + '</p>';
    html += '<p><strong>' + escapeHtml(data.progress || 0) + '%</strong> · ' +
      escapeHtml((data.required_done || 0) + ' of ' + (data.required_total || 0) + ' required steps complete') + '</p>';
    if (rank.key) {
      html += '<p class="lnkup-muted">Current rank: ' + escapeHtml(rankName(rank.key)) + '</p>';
    } else {
      html += '<p class="lnkup-muted">Unranked until Set Up is complete.</p>';
    }
    if (current.eta) {
      html += '<p class="lnkup-muted">Next step: about ' + escapeHtml(current.eta) + '</p>';
    }
    html += '<p class="lnkup-muted">Earnings are performance-based. There is no guaranteed income and no base payment.</p>';
    html += '</section>';
    if (current.id) {
      html += '<section class="lnkup-connector-card lnkup-connector-launch__current" data-testid="lnkup-connector-current-action">';
      html += '<p class="lnkup-connector-launch__kind">' + (current.auto ? 'Tracked milestone' : 'Your next action') + '</p>';
      html += '<h3>' + escapeHtml(current.title || '') + '</h3>';
      html += '<p>' + escapeHtml(current.why || current.description || '') + '</p>';
      if (current.eta) {
        html += '<p class="lnkup-muted">About ' + escapeHtml(current.eta) + '</p>';
      }
      html += '<div class="lnkup-connector-launch__actions">' + stepCta(current);
      if (current.help) {
        html += '<p class="lnkup-muted">' + escapeHtml(current.help) + '</p>';
      }
      html += '</div></section>';
    }
    phases.forEach(function (block) {
      var completed = (block.steps || []).filter(function (s) { return s.done; });
      var upcoming = (block.steps || []).filter(function (s) { return !s.done && s.id !== current.id; });
      html += '<section class="lnkup-connector-card lnkup-connector-launch__phase-card">';
      html += '<h3>' + escapeHtml(block.name) + (block.any_one ? ' <span class="lnkup-muted">any one</span>' : '') + (block.auto ? ' <span class="lnkup-muted">tracked</span>' : '') + '</h3>';
      if (completed.length) {
        html += '<details class="lnkup-connector-launch__done"><summary>Completed (' + completed.length + ')</summary><ul class="lnkup-connector-launch__list">';
        completed.forEach(function (s) {
          html += '<li class="is-done"><span aria-hidden="true">✓</span><span>' + escapeHtml(s.title) +
            (s.completed_at ? ' <em class="lnkup-muted">' + escapeHtml(s.completed_at) + '</em>' : '') +
            '</span><a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="' + escapeHtml(viewHref(s.cta_view || 'start')) + '" data-view="' + escapeHtml(s.cta_view || 'start') + '">Review</a></li>';
        });
        html += '</ul></details>';
      }
      if (upcoming.length) {
        html += '<ul class="lnkup-connector-launch__list">';
        upcoming.forEach(function (s, i) {
          html += '<li class="is-' + escapeHtml(s.status || 'available') + '"><span class="lnkup-connector-launch__num">' + (i + 1) + '</span>';
          html += '<div><strong>' + escapeHtml(s.title) + '</strong><p class="lnkup-muted">' + escapeHtml(s.description || '') + '</p>';
          html += '<span class="lnkup-connector-launch__status">' + escapeHtml(s.status || 'available') + '</span>';
          if (s.unlock && s.status !== 'available') {
            html += '<p class="lnkup-muted">' + escapeHtml(s.unlock) + '</p>';
          }
          html += '</div></li>';
        });
        html += '</ul>';
      }
      html += '</section>';
    });
    return html;
  }

  function renderStudio(data, analytics) {
    data = data || {};
    analytics = analytics || {};
    if (data.city) {
      cache.city = data.city;
    }
    var html = '<section class="lnkup-connector-card" data-testid="lnkup-connector-studio"><h3>Create Your Next Post</h3>';
    html += '<p class="lnkup-muted">' + escapeHtml(data.disclaimer || 'A published video does not create commission by itself.') + '</p>';
    if (data.mission) {
      html += '<article class="lnkup-connector-mission"><h4>This Week’s Mission</h4>';
      html += '<p>' + escapeHtml(data.mission.body || data.mission.hook || '') + '</p>';
      html += '<p class="lnkup-muted">' + escapeHtml(studioMetaLine(data.mission)) + '</p>';
      html += '<button type="button" class="lnkup-btn lnkup-btn--primary" data-open-template="' + escapeHtml(data.mission.template_id) + '">Build This Video</button></article>';
    }
    html += '<div class="lnkup-connector-chips" role="tablist" aria-label="Template categories">';
    html += '<button type="button" class="lnkup-connector-chip is-active" data-studio-cat="">All</button>';
    Object.keys(data.categories || {}).forEach(function (key) {
      html += '<button type="button" class="lnkup-connector-chip" data-studio-cat="' + escapeHtml(key) + '">' + escapeHtml(data.categories[key]) + '</button>';
    });
    html += '</div>';
    html += '<div class="lnkup-connector-studio-grid">';
    (data.templates || data.recommendations || []).forEach(function (tpl) {
      html += '<article class="lnkup-connector-studio-card" data-template-cat="' + escapeHtml(tpl.category || '') + '"><h4>' + escapeHtml(tpl.title) + '</h4>';
      html += '<p class="lnkup-muted">' + escapeHtml(tpl.hook || '') + '</p>';
      html += '<button type="button" class="lnkup-btn lnkup-btn--outline" data-open-template="' + escapeHtml(tpl.id) + '">Open template</button></article>';
    });
    html += '</div>';
    html += '<div class="lnkup-connector-builder-mount" data-studio-builder></div></section>';
    var posts = data.posts || [];
    html += '<section class="lnkup-connector-card"><h3>Your posts</h3>';
    html += '<div class="lnkup-connector-chips" role="tablist" aria-label="Post months">';
    html += '<button type="button" class="lnkup-connector-chip is-active" data-post-month="">All</button>';
    var months = {};
    posts.forEach(function (post) {
      var m = String(post.published_at || '').slice(0, 7);
      if (m) {
        months[m] = true;
      }
    });
    Object.keys(months).forEach(function (m) {
      html += '<button type="button" class="lnkup-connector-chip" data-post-month="' + escapeHtml(m) + '">' + escapeHtml(m) + '</button>';
    });
    html += '</div>';
    if (!posts.length) {
      html += '<div class="lnkup-empty-state lnkup-empty-state--compact"><p>No posts yet. Open a template, mark it planned, or submit a published URL.</p></div>';
    } else {
      html += '<ul class="lnkup-connector-post-list">';
      posts.forEach(function (post) {
        var bits = [post.title || 'Studio post'];
        var plat = studioPlatformLabel(post.platform);
        var st = studioPostStatusLabel(post.status);
        if (plat) {
          bits.push(plat);
        }
        if (st) {
          bits.push(st);
        }
        bits.push(String(post.clicks || 0) + ' clicks');
        html += '<li data-post-month="' + escapeHtml(String(post.published_at || '').slice(0, 7)) + '"><strong>' + escapeHtml(bits[0]) + '</strong> · ' + escapeHtml(bits.slice(1).join(' · ')) + '</li>';
      });
      html += '</ul>';
    }
    html += '</section>';
    html += '<section class="lnkup-connector-card" data-testid="lnkup-connector-studio-analytics"><h3>Content performance</h3>';
    if (analytics.empty) {
      html += '<div class="lnkup-empty-state lnkup-empty-state--compact">';
      html += '<h4 class="lnkup-empty-state__title">No published posts yet</h4>';
      html += '<p class="lnkup-empty-state__desc">Add a live Instagram, TikTok, LinkedIn, or Facebook link so we can track referral clicks from your content.</p>';
      html += '<div class="lnkup-empty-state__actions"><button type="button" class="lnkup-btn lnkup-btn--outline" data-scroll-publish>Submit a published link</button></div>';
      html += '</div>';
    } else {
      html += '<div class="lnkup-connector-perf-grid">' +
        studioPerfCard('Published posts', analytics.published_videos, 'Videos you submitted with a live link.') +
        studioPerfCard('Tracked clicks', analytics.tracked_clicks, 'Referral-link clicks tagged from your posts.') +
        studioPerfCard('Registrations', analytics.registrations, 'People who signed up through your referral links.') +
        studioPerfCard('Bookings', analytics.qualifying_bookings, 'Qualifying booth bookings from those referrals.') +
        '</div>';
      html += '<p class="lnkup-muted lnkup-connector-perf-note">These totals come from your referral links, not a claim that one video caused them.</p>';
    }
    html += '<form class="lnkup-connector-publish" data-testid="lnkup-connector-publish-form" id="lnkup-connector-publish">';
    html += '<h4>Submit a published post</h4>';
    html += '<label>Platform<select name="platform" data-studio-platform required>';
    STUDIO_PLATFORMS.forEach(function (row) {
      html += '<option value="' + escapeHtml(row.id) + '">' + escapeHtml(row.label) + '</option>';
    });
    html += '</select></label>';
    html += '<label>Who this post is for<select name="audience" required>';
    STUDIO_AUDIENCES.forEach(function (row) {
      html += '<option value="' + escapeHtml(row.id) + '">' + escapeHtml(row.label) + '</option>';
    });
    html += '</select></label>';
    html += '<label>Link<input name="url" type="url" required data-studio-url placeholder="' + escapeHtml(STUDIO_PLATFORMS[0].placeholder) + '" /></label>';
    html += '<p class="lnkup-muted" data-studio-url-hint>' + escapeHtml(STUDIO_PLATFORMS[0].hint) + '</p>';
    html += '<button type="submit" class="lnkup-btn lnkup-btn--primary">Submit published post</button>';
    html += '<p class="lnkup-connector-interest-status" data-publish-status></p></form></section>';
    return html;
  }

  function renderStudioBuilder(tpl) {
    tpl = tpl || {};
    var shots = tpl.shots || [];
    var shotText = shots.join('\n');
    var html = '<div class="lnkup-connector-builder-backdrop" data-close-builder></div>';
    html += '<article class="lnkup-connector-builder" data-testid="lnkup-connector-builder" role="dialog" aria-modal="true" aria-labelledby="lnkup-connector-builder-title">';
    html += '<header class="lnkup-connector-builder__header">';
    html += '<div class="lnkup-connector-builder__intro">';
    html += '<h3 id="lnkup-connector-builder-title">' + escapeHtml(tpl.title || 'Video builder') + '</h3>';
    html += '<div class="lnkup-connector-builder__chips">';
    if (tpl.duration) {
      html += '<span class="lnkup-connector-builder__chip">' + escapeHtml(tpl.duration) + '</span>';
    }
    if (tpl.audience) {
      html += '<span class="lnkup-connector-builder__chip">' + escapeHtml(studioAudienceLabel(tpl.audience)) + '</span>';
    }
    html += '</div></div>';
    html += '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-connector-builder__close" data-close-builder aria-label="Close builder"><span aria-hidden="true">×</span> Close</button>';
    html += '</header>';
    html += '<div class="lnkup-connector-builder__body">';
    html += studioCopyBlock('Hook', tpl.hook || '', 'Copy Hook');
    html += studioCopyBlock('Talking script', tpl.script || '', 'Copy Script');
    html += studioCopyBlock('Caption', tpl.caption || '', 'Copy Caption');
    html += studioCopyBlock('On-screen', tpl.onscreen || '', 'Copy On-Screen Text');
    html += '<section class="lnkup-connector-builder__block">';
    html += '<div class="lnkup-connector-builder__block-head">';
    html += '<h4>Shot list</h4>';
    if (shotText) {
      html += '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-connector-copy" data-copy="' + escapeHtml(shotText) + '">Copy Shot List</button>';
    }
    html += '</div>';
    html += '<ul class="lnkup-connector-builder__shots">';
    shots.forEach(function (shot) {
      html += '<li>' + escapeHtml(shot) + '</li>';
    });
    html += '</ul></section>';
    if (tpl.cta) {
      html += '<p class="lnkup-muted">' + escapeHtml(tpl.cta) + '</p>';
    }
    if (tpl.disclosure) {
      html += '<p class="lnkup-muted">' + escapeHtml(tpl.disclosure) + '</p>';
    }
    html += '</div>';
    html += '<footer class="lnkup-connector-builder__footer">';
    if (tpl.links && tpl.links.organizer) {
      html += '<a class="lnkup-btn lnkup-btn--primary" href="' + escapeHtml(tpl.links.organizer) + '" target="_blank" rel="noopener">Open Referral Link</a>';
    }
    html += '<button type="button" class="lnkup-btn lnkup-btn--outline" data-download-shots="' + escapeHtml(shotText) + '">Download Shot List</button>';
    html += '<button type="button" class="lnkup-btn lnkup-btn--outline" data-plan-template="' + escapeHtml(tpl.id) + '">Mark as Planned</button>';
    html += '</footer></article>';
    return html;
  }

  function renderWhatYouDo() {
    return (
      '<section class="lnkup-connector-card" id="who-to-target">' +
      '<h3>What you do</h3>' +
      '<ol class="lnkup-connector-steps">' +
      '<li><strong>Find organizers</strong> who repeatedly need vendors: festivals, block parties, pop-up and farmers markets, food-truck events, HBCU and university events, conferences, chambers, municipal events, cultural groups, venues, and promoters.</li>' +
      '<li><strong>Make the introduction</strong> with your tracked organizer link — Instagram DMs, email, text, LinkedIn, in person, group chats, or venue relationships.</li>' +
      '<li><strong>LNKup handles onboarding.</strong> You make the introduction. LNKup helps the organizer build their first opportunity, onboard vendors, collect applications and process payments. Organizers can keep POSH, Eventbrite, or their current ticketing platform because LNKup manages vendor operations, not attendee tickets.</li>' +
      '<li><strong>Earn from activity.</strong> Registration alone does not generate earnings. The organizer must publish a legitimate opportunity and vendors must complete qualifying payments.</li>' +
      '</ol></section>'
    );
  }

  function renderToolkit(links, counts) {
    links = links || {};
    counts = counts || {};
    var html = '<section class="lnkup-connector-card" id="referral-tools"><h3>Referral toolkit</h3>';
    html += '<label class="lnkup-connector-link-row__label">Organizer referral URL</label>';
    html += '<div class="lnkup-connector-link-row__field">';
    html += '<input type="text" readonly class="lnkup-connector-link-input" data-testid="lnkup-connector-organizer-url" value="' + escapeHtml(links.organizer || '') + '" />';
    html += '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-connector-copy" data-copy-label="Copy link" data-url="' + escapeHtml(links.organizer || '') + '" data-onboard="copied_link">Copy link</button>';
    html += '<button type="button" class="lnkup-btn lnkup-btn--primary" data-connector-share>Share sheet</button>';
    html += '</div>';
    if (links.vendor) {
      html += '<label class="lnkup-connector-link-row__label">Vendor referral URL</label>';
      html += '<div class="lnkup-connector-link-row__field">';
      html += '<input type="text" readonly class="lnkup-connector-link-input" value="' + escapeHtml(links.vendor || '') + '" />';
      html += '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-connector-copy" data-copy-label="Copy link" data-url="' + escapeHtml(links.vendor || '') + '" data-onboard="copied_vendor_link">Copy vendor link</button>';
      html += '</div>';
    }
    if (links.code) {
      html += '<p class="lnkup-muted">Referral code: <strong>' + escapeHtml(links.code) + '</strong></p>';
    }
    html += '<div class="lnkup-connector-kpis lnkup-connector-kpis--compact">';
    html += kpi('Tracked clicks', counts.clicks);
    html += kpi('Organizer registrations', counts.registrations);
    html += kpi('Published opportunities', counts.opportunities);
    html += kpi('Activated organizers', counts.activated);
    html += kpi('Completed bookings', counts.bookings);
    html += '</div></section>';
    return html;
  }

  function kpi(label, value, help) {
    return '<div class="lnkup-connector-kpi"><span class="lnkup-connector-kpi__label">' + escapeHtml(label) + '</span><strong>' + escapeHtml(value == null ? 0 : value) + '</strong>' +
      (help ? '<button type="button" class="lnkup-connector-help" aria-expanded="false" data-help="' + escapeHtml(help) + '">What this means</button>' : '') +
      '</div>';
  }

  function renderHowPaid(terms, example) {
    terms = terms || {};
    var hold = terms.hold_days != null ? terms.hold_days : 30;
    var pct = terms.share_percent != null ? terms.share_percent : 10;
    var one = example && example.per_booking != null ? parseFloat(example.per_booking) || 0 : 250 * (pct / 100);
    return (
      '<section class="lnkup-connector-card" id="how-you-get-paid">' +
      '<h3>How You Get Paid</h3>' +
      '<ol class="lnkup-connector-steps">' +
      '<li>You refer a new organizer.</li>' +
      '<li>The organizer registers through your link or referral code.</li>' +
      '<li>The organizer becomes verified and publishes an opportunity.</li>' +
      '<li>Vendors apply and complete booth payments.</li>' +
      '<li>Your qualifying commission enters the ' + escapeHtml(hold) + '-day hold period.</li>' +
      '<li>LNKup reviews and approves the reward.</li>' +
      '<li>Approved earnings become payable.</li>' +
      '</ol>' +
      '<p class="lnkup-muted">' + escapeHtml(termsLine(terms)) + ' There is no base payment or guaranteed income. Estimates are not payable.</p>' +
      '<div class="lnkup-connector-example">' +
      '<p><strong>Example for a $250 vendor booth</strong> (estimate)</p>' +
      '<ul><li>Connector commission: ' + escapeHtml(currency(one)) + '</li><li>Twenty completed bookings: ' + escapeHtml(currency(one * 20)) + '</li><li>One hundred completed bookings: ' + escapeHtml(currency(one * 100)) + '</li></ul>' +
      '</div></section>'
    );
  }

  function renderCalculator() {
    return (
      '<section class="lnkup-connector-card" id="calculator">' +
      '<h3>Earnings calculator</h3>' +
      '<p class="lnkup-muted">Estimates only. Uses the same backend formula as rewards. Not available or paid earnings.</p>' +
      '<form class="lnkup-connector-calc" data-testid="lnkup-connector-calculator">' +
      '<label>Average booth price ($)<input name="booth_price" type="number" min="0" step="1" value="250" required /></label>' +
      '<label>Expected paid vendor bookings<input name="paid_bookings" type="number" min="0" step="1" value="20" required /></label>' +
      '<label>Active organizers<input name="organizers" type="number" min="1" step="1" value="1" required /></label>' +
      '<button type="submit" class="lnkup-btn lnkup-btn--primary">Estimate</button>' +
      '</form>' +
      '<div class="lnkup-connector-calc__out lnkup-muted" data-calc-out>Enter numbers to see an estimate.</div>' +
      '</section>'
    );
  }

  function pendingAmount(balances) {
    balances = balances || {};
    return (parseFloat(balances.pending_hold) || 0) + (parseFloat(balances.awaiting_approval) || 0);
  }

  function renderBalances(balances, opts) {
    balances = balances || {};
    opts = opts || {};
    var html = '<div class="lnkup-connector-kpis">';
    if (opts.estimated != null) {
      html += kpi('Estimated', currency(opts.estimated), STATUS_HELP.estimated);
    } else if (balances.estimated != null) {
      html += kpi('Estimated', currency(balances.estimated), STATUS_HELP.estimated);
    }
    html += kpi('Pending', currency(pendingAmount(balances)), STATUS_HELP.pending);
    html += kpi('Approved', currency(balances.approved), STATUS_HELP.approved);
    html += kpi('Paid', currency(balances.paid), STATUS_HELP.paid);
    html += '</div>';
    html += '<p class="lnkup-muted">Estimated planner numbers are never added to these balances.</p>';
    return html;
  }

  function renderLedger(rows, filter) {
    rows = rows || [];
    var filtered = rows.filter(function (row) {
      if (!filter || filter === 'all') {
        return true;
      }
      return (row.display_status || row.reward_status) === filter;
    });
    if (!filtered.length) {
      return '<div class="lnkup-empty-state lnkup-empty-state--compact"><p>No rewards yet.</p><p class="lnkup-muted">Completed booth payments from organizers you referred will show here after they settle.</p></div>';
    }
    var html = '<div class="lnkup-table-wrap lnkup-connector-table-wrap"><table class="lnkup-table"><thead><tr><th>Organizer</th><th>Event</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>';
    filtered.forEach(function (row) {
      html += '<tr>' +
        '<td data-label="Organizer">' + escapeHtml(row.related_name || '—') + '</td>' +
        '<td data-label="Event">' + escapeHtml(milestoneLabel(row.event_type)) + '</td>' +
        '<td data-label="Amount">' + escapeHtml(currency(row.net_amount != null ? row.net_amount : row.amount)) + '</td>' +
        '<td data-label="Status">' + escapeHtml(statusLabel(row.display_status || row.reward_status)) + '</td>' +
        '<td data-label="Date">' + escapeHtml(row.created_at) + '</td></tr>';
    });
    html += '</tbody></table></div>';
    return html;
  }

  function matchesFilter(card, filter) {
    var act = card.activation || {};
    if (filter === 'all') {
      return true;
    }
    if (filter === 'organizer') {
      return card.kind === 'organizer';
    }
    if (filter === 'vendor') {
      return card.kind === 'vendor';
    }
    if (filter === 'registered') {
      return true;
    }
    if (filter === 'verified') {
      return !!act.verified;
    }
    if (filter === 'published') {
      return !!act.published_opportunity;
    }
    if (filter === 'activated') {
      return !!act.activated;
    }
    if (filter === 'inactive') {
      return !act.activated;
    }
    if (filter === 'reward') {
      return !!card.reward_eligible;
    }
    return true;
  }

  function renderReferralCard(card) {
    var act = card.activation || {};
    var flags = '';
    if (card.kind === 'organizer') {
      flags = '<ul class="lnkup-connector-flags">' +
        '<li>' + (act.registered ? '✓' : '○') + ' Registered</li>' +
        '<li>' + (act.verified ? '✓' : '○') + ' Verified</li>' +
        '<li>' + (act.published_opportunity ? '✓' : '○') + ' Opportunity published</li>' +
        '<li>' + (act.first_payment ? '✓' : '○') + ' First payment</li>' +
        '<li>' + (act.activated ? '✓' : '○') + ' Activated</li>' +
        '</ul>';
    }
    return '<article class="lnkup-connector-card lnkup-connector-pipeline__card">' +
      '<h3>' + escapeHtml(card.display_name) + '</h3>' +
      '<p class="lnkup-muted">' + escapeHtml(card.kind === 'vendor' ? 'Vendor' : 'Organizer') + ' · ' + escapeHtml(card.city || 'City not listed') + ' · ' + escapeHtml(card.referral_date || '') + '</p>' +
      '<p><span class="lnkup-connector-badge">' + escapeHtml(milestoneLabel(card.milestone)) + '</span></p>' +
      '<p>Qualifying GMV ' + escapeHtml(currency(card.qualifying_gmv)) + '</p>' +
      '<p>Estimated ' + escapeHtml(currency(card.estimated_earnings)) + ' · Approved ' + escapeHtml(currency(card.approved_earnings)) + '</p>' +
      '<p class="lnkup-muted">' + escapeHtml(card.remaining_term || '') + '</p>' +
      '<details class="lnkup-connector-milestones"><summary>Milestones</summary>' + flags + '</details>' +
      '</article>';
  }

  function renderReferralTable(cards) {
    var html = '<div class="lnkup-table-wrap lnkup-connector-table-wrap lnkup-connector-table-wrap--desktop"><table class="lnkup-table"><thead><tr><th>Name</th><th>Type</th><th>Milestone</th><th>City</th><th>Estimated</th></tr></thead><tbody>';
    cards.forEach(function (card) {
      html += '<tr>' +
        '<td data-label="Name">' + escapeHtml(card.display_name) + '</td>' +
        '<td data-label="Type">' + escapeHtml(card.kind === 'vendor' ? 'Vendor' : 'Organizer') + '</td>' +
        '<td data-label="Milestone">' + escapeHtml(milestoneLabel(card.milestone)) + '</td>' +
        '<td data-label="City">' + escapeHtml(card.city || '—') + '</td>' +
        '<td data-label="Estimated">' + escapeHtml(currency(card.estimated_earnings)) + '</td></tr>';
    });
    html += '</tbody></table></div>';
    return html;
  }

  function renderPipeline(cards, vendorNote) {
    var all = cards || [];
    var html = '<div class="lnkup-connector-referrals" data-testid="lnkup-connector-filters">';
    html += '<div class="lnkup-connector-toolbar">';
    html += '<label class="lnkup-sr-only" for="lnkup-connector-search">Search referrals</label>';
    html += '<input id="lnkup-connector-search" class="lnkup-connector-search" type="search" placeholder="Search by name or city" />';
    html += '<div class="lnkup-connector-filters" role="group" aria-label="Referral filters">';
    REFERRAL_FILTERS.forEach(function (f, i) {
      html += '<button type="button" class="lnkup-connector-filter' + (i === 0 ? ' is-active' : '') + '" data-filter="' + escapeHtml(f.id) + '">' + escapeHtml(f.label) + '</button>';
    });
    html += '</div></div>';
    if (vendorNote) {
      html += '<p class="lnkup-muted">' + escapeHtml(vendorNote) + '</p>';
    }
    html += '<div data-referral-list></div></div>';
    return html;
  }

  function paintReferrals(container, cards) {
    var list = container.querySelector('[data-referral-list]');
    var search = (container.querySelector('.lnkup-connector-search') || {}).value || '';
    var filterBtn = container.querySelector('.lnkup-connector-filter.is-active');
    var filter = filterBtn ? filterBtn.getAttribute('data-filter') : 'all';
    var q = search.toLowerCase();
    var shown = (cards || []).filter(function (card) {
      if (!matchesFilter(card, filter)) {
        return false;
      }
      if (!q) {
        return true;
      }
      return String(card.display_name || '').toLowerCase().indexOf(q) !== -1 || String(card.city || '').toLowerCase().indexOf(q) !== -1;
    });
    if (!shown.length) {
      list.innerHTML = '<div class="lnkup-empty-state lnkup-empty-state--compact"><p>No referrals match these filters.</p><p class="lnkup-muted">Share your organizer link. Signups will appear here. They do not earn commission until bookings complete.</p><button type="button" class="lnkup-btn lnkup-btn--primary" data-connector-share>Share Your Link</button></div>';
      return;
    }
    var cardsHtml = '<div class="lnkup-connector-pipeline lnkup-connector-pipeline--cards">';
    shown.forEach(function (card) {
      cardsHtml += renderReferralCard(card);
    });
    cardsHtml += '</div>';
    list.innerHTML = renderReferralTable(shown) + cardsHtml;
  }

  function conversion(from, to) {
    from = parseInt(from, 10) || 0;
    to = parseInt(to, 10) || 0;
    if (from < 5) {
      return '—';
    }
    return Math.round((to / from) * 100) + '%';
  }

  function renderFunnel(counts) {
    counts = counts || {};
    var steps = [
      { key: 'clicks', label: 'Clicks', value: counts.clicks },
      { key: 'registrations', label: 'Registrations', value: counts.registrations },
      { key: 'opportunities', label: 'Published', value: counts.opportunities },
      { key: 'activated', label: 'Activated', value: counts.activated },
      { key: 'bookings', label: 'Bookings', value: counts.bookings }
    ];
    var total = steps.reduce(function (sum, s) { return sum + (parseInt(s.value, 10) || 0); }, 0);
    if (!total) {
      return '<section class="lnkup-connector-card" data-testid="lnkup-connector-funnel">' +
        '<h3>Funnel</h3>' +
        '<div class="lnkup-empty-state lnkup-empty-state--compact"><p>Your city network starts here.</p>' +
        '<button type="button" class="lnkup-btn lnkup-btn--primary" data-connector-share>Share Your Link</button></div></section>';
    }
    var html = '<section class="lnkup-connector-card" data-testid="lnkup-connector-funnel"><h3>Funnel</h3><ol class="lnkup-connector-funnel">';
    steps.forEach(function (step, i) {
      var prev = i > 0 ? steps[i - 1].value : null;
      html += '<li><strong>' + escapeHtml(step.value == null ? 0 : step.value) + '</strong><span>' + escapeHtml(step.label) + '</span>' +
        (prev != null ? '<em>' + escapeHtml(conversion(prev, step.value)) + '</em>' : '') + '</li>';
    });
    html += '</ol><p class="lnkup-muted">Conversion % shows only when the previous step has at least 5 people.</p></section>';
    return html;
  }

  function renderActivity(rows) {
    if (!rows || !rows.length) {
      return '<section class="lnkup-connector-card"><h3>Activity</h3><div class="lnkup-empty-state lnkup-empty-state--compact"><p>No activity yet.</p><p class="lnkup-muted">Clicks, signups, and bookings will land here as your city network grows.</p></div></section>';
    }
    var html = '<section class="lnkup-connector-card"><h3>Activity</h3><ol class="lnkup-connector-activity">';
    rows.forEach(function (row) {
      html += '<li><span class="lnkup-connector-activity__type">' + escapeHtml(milestoneLabel(row.event_type)) + '</span>' +
        '<span class="lnkup-muted">' + escapeHtml(row.created_at || '') + (row.city ? ' · ' + escapeHtml(row.city) : '') + '</span></li>';
    });
    html += '</ol></section>';
    return html;
  }

  function renderResources(data, links) {
    data = data || {};
    var html = '<div class="lnkup-connector-guide">';
    var groups = data.groups || {};
    Object.keys(groups).forEach(function (key) {
      var group = groups[key];
      html += '<section class="lnkup-connector-card"><h3>' + escapeHtml(group.label || key) + '</h3><ul class="lnkup-connector-methods">';
      (group.items || []).forEach(function (t) {
        html += '<li>' + escapeHtml(t) + '</li>';
      });
      html += '</ul></section>';
    });
    if (data.city) {
      cache.city = data.city;
    }
    if ((data.scripts || []).length) {
      html += '<section class="lnkup-connector-card" data-testid="lnkup-connector-scripts"><h3>Scripts</h3>';
      var buckets = [
        { key: 'organizer', title: 'Organizer outreach' },
        { key: 'vendor', title: 'Vendor outreach' },
        { key: 'both', title: 'Community posts' }
      ];
      buckets.forEach(function (bucket) {
        var items = data.scripts.filter(function (script) {
          return (script.audience || 'both') === bucket.key;
        });
        if (!items.length) {
          return;
        }
        html += '<h4 class="lnkup-connector-script-group">' + escapeHtml(bucket.title) + '</h4>';
        items.forEach(function (script) {
          var body = fillScript(script.body || '', links || {});
          html += '<article class="lnkup-connector-script"><h4>' + escapeHtml(script.label) + '</h4>';
          html += '<pre>' + escapeHtml(body) + '</pre>';
          html += '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-connector-copy" data-copy="' + escapeHtml(body) + '" data-copy-announce="Script copied." data-copy-label="Copy script">Copy script</button></article>';
        });
      });
      html += '</section>';
    } else {
      html += renderScripts(links || {}, 'organizer');
      html += renderScripts(links || {}, 'vendor');
    }
    html += '<section class="lnkup-connector-card" id="lnkup-resources-targets"><h3>Who to target</h3><ul class="lnkup-connector-methods">';
    (data.targets || []).forEach(function (t) {
      html += '<li>' + escapeHtml(t) + '</li>';
    });
    html += '</ul></section>';
    html += '<section class="lnkup-connector-card"><h3>Frequently asked questions</h3>';
    (data.faqs || []).forEach(function (faq) {
      html += '<details class="lnkup-connector-faq"><summary>' + escapeHtml(faq.q) + '</summary><p>' + escapeHtml(faq.a) + '</p></details>';
    });
    html += '</section>';
    html += '<section class="lnkup-connector-card"><h3>Program terms</h3>';
    if (data.terms_url) {
      html += '<p><a href="' + escapeHtml(data.terms_url) + '">Read program terms</a></p>';
    } else {
      html += '<p class="lnkup-muted">Performance-based. No base payment. Commission is a share of qualifying booth GMV for the configured term after an organizer’s first completed booking.</p>';
    }
    if (data.support_url) {
      html += '<p><a href="' + escapeHtml(data.support_url) + '">Contact LNKup support</a></p>';
      html += '<p><a href="' + escapeHtml(data.support_url) + '">Report an attribution issue</a></p>';
    } else {
      html += '<p><a href="mailto:hello@lnkup.com">Contact LNKup support</a></p>';
      html += '<p><a href="mailto:hello@lnkup.com?subject=Connector%20attribution%20issue">Report an attribution issue</a></p>';
    }
    html += '</section></div>';
    return html;
  }

  function renderProfile(connector, code) {
    connector = connector || {};
    return (
      '<section class="lnkup-connector-card"><h3>Profile</h3>' +
      '<div class="lnkup-connector-hero__identity lnkup-connector-profile__avatar">' +
      (connector.avatar_url
        ? '<img class="lnkup-connector-avatar" src="' + escapeHtml(connector.avatar_url) + '" alt="" width="48" height="48" />'
        : '<span class="lnkup-connector-avatar lnkup-connector-avatar--fallback" aria-hidden="true">' + escapeHtml(connector.initials || 'CC') + '</span>') +
      '<p class="lnkup-muted">Photo comes from your WordPress account.</p></div>' +
      '<dl class="lnkup-connector-readonly">' +
      '<div><dt>Market</dt><dd>' + escapeHtml(connector.market_slug || '—') + '</dd></div>' +
      '<div><dt>Status</dt><dd>' + escapeHtml(programStatus(connector.status)) + '</dd></div>' +
      '<div><dt>Code</dt><dd>' + escapeHtml(code || connector.code || '—') + '</dd></div>' +
      '</dl>' +
      '<form class="lnkup-connector-profile" data-testid="lnkup-connector-profile">' +
      '<label>Name<input name="display_name" required value="' + escapeHtml(connector.display_name || '') + '" /></label>' +
      '<label>City<input name="city" value="' + escapeHtml(connector.city || '') + '" /></label>' +
      '<label>Phone<input name="phone" value="' + escapeHtml(connector.phone || '') + '" /></label>' +
      '<label>Social URL<input name="social_url" type="url" value="' + escapeHtml(connector.social_url || '') + '" /></label>' +
      '<label>Payout preference<input name="payout_preference" value="' + escapeHtml(connector.payout_preference || '') + '" /></label>' +
      '<label>Bio<textarea name="bio" rows="3">' + escapeHtml(connector.bio || '') + '</textarea></label>' +
      '<fieldset class="lnkup-connector-growth-fieldset"><legend>Growth styles</legend>' +
      GROWTH_CARDS.map(function (card) {
        var on = (connector.growth_styles || []).indexOf(card.id) !== -1;
        return '<label class="lnkup-connector-check"><input type="checkbox" name="growth_styles[]" value="' + card.id + '"' + (on ? ' checked' : '') + ' /> ' + escapeHtml(card.title) + '</label>';
      }).join('') +
      '</fieldset>' +
      '<label class="lnkup-connector-check"><input type="checkbox" name="leaderboard_opt_in" value="1"' + (connector.leaderboard_opt_in ? ' checked' : '') + ' /> Show me on the city leaderboard</label>' +
      '<button type="submit" class="lnkup-btn lnkup-btn--primary">Save profile</button>' +
      '<p class="lnkup-connector-interest-status" data-profile-status></p>' +
      '</form></section>'
    );
  }

  function renderLeadForm(data) {
    var left = Math.max(0, (data.daily_limit || 0) - (data.submitted_today || 0));
    return (
      '<section class="lnkup-connector-card" data-testid="lnkup-connector-lead-form-card">' +
      '<h3>Submit an organizer lead</h3>' +
      '<p class="lnkup-muted">Introduce an independent organizer you know. LNKup checks for existing accounts and duplicates first. A lead is not an acquired organizer until they register, publish an event and complete a paid booking.</p>' +
      '<form class="lnkup-connector-profile" data-testid="lnkup-connector-lead-form" novalidate>' +
      '<label>Organizer or business name<input name="name" required maxlength="191" /></label>' +
      '<label>Public website<input name="website" type="url" placeholder="https://" /></label>' +
      '<label>Instagram<input name="instagram" placeholder="@handle" /></label>' +
      '<label>Business email<input name="email" type="email" /></label>' +
      '<label>City<input name="city" /></label>' +
      '<label>State<input name="state" maxlength="2" /></label>' +
      '<label>Event type<input name="event_type" placeholder="Market, festival, pop-up…" /></label>' +
      '<label>Event or application link<input name="application_url" type="url" placeholder="https://" /></label>' +
      '<label>Why they are a fit<textarea name="reason" rows="3"></textarea></label>' +
      '<label>How you know them<input name="source" placeholder="Worked their market in 2026" /></label>' +
      '<button type="submit" class="lnkup-btn lnkup-btn--primary"' + (left ? '' : ' disabled') + '>Submit lead</button>' +
      '<p class="lnkup-muted">' + escapeHtml(left) + ' of ' + escapeHtml(data.daily_limit || 0) + ' submissions left today.</p>' +
      '<p class="lnkup-connector-interest-status" data-lead-status role="status" aria-live="polite"></p>' +
      '</form></section>'
    );
  }

  function renderLeadList(data) {
    var items = data.items || [];
    var html = '<section class="lnkup-connector-card" data-testid="lnkup-connector-leads"><h3>My organizer leads</h3>';
    if (!items.length) {
      return html + '<div class="lnkup-empty-state lnkup-empty-state--compact"><p>No leads yet.</p><p class="lnkup-muted">Leads you submit or that LNKup assigns to you will appear here with their pipeline stage.</p></div></section>';
    }
    html += '<div class="lnkup-table-wrap lnkup-connector-table-wrap"><table class="lnkup-table"><thead><tr><th>Organizer</th><th>City</th><th>Stage</th><th>Submitted</th></tr></thead><tbody>';
    items.forEach(function (lead) {
      var stage = lead.outcome_label ? lead.stage_label + ' · ' + lead.outcome_label : lead.stage_label;
      html += '<tr>' +
        '<td data-label="Organizer">' + escapeHtml(lead.name) + '</td>' +
        '<td data-label="City">' + escapeHtml([lead.city, lead.state].filter(Boolean).join(', ') || '—') + '</td>' +
        '<td data-label="Stage">' + escapeHtml(stage || '—') + '</td>' +
        '<td data-label="Submitted">' + escapeHtml(lead.submitted_at) + '</td></tr>';
    });
    html += '</tbody></table></div>';
    var pages = Math.ceil((data.total || 0) / (data.per_page || 20));
    if (pages > 1) {
      html += '<div class="lnkup-connector-pager">' +
        '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-lead-page="' + (data.page - 1) + '"' + (data.page <= 1 ? ' disabled' : '') + '>Previous</button>' +
        ' <span class="lnkup-muted">Page ' + escapeHtml(data.page) + ' of ' + escapeHtml(pages) + '</span> ' +
        '<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" data-lead-page="' + (data.page + 1) + '"' + (data.page >= pages ? ' disabled' : '') + '>Next</button>' +
        '</div>';
    }
    return html + '</section>';
  }

  function renderLeads(data) {
    data = data || {};
    if (data.enabled === false) {
      return '<section class="lnkup-connector-card"><h3>Organizer Leads</h3><div class="lnkup-empty-state lnkup-empty-state--compact"><p>Lead submission is not open right now.</p><p class="lnkup-muted">Keep sharing your referral link. LNKup will let you know when lead submission opens.</p></div></section>';
    }
    return renderLeadForm(data) + '<div data-lead-list>' + renderLeadList(data) + '</div>';
  }

  function renderCities(data) {
    data = data || {};
    var items = data.items || [];
    var home = [data.city, data.state].filter(Boolean).join(', ');
    var html = '<section class="lnkup-connector-card" data-testid="lnkup-connector-cities"><h3>My Cities</h3>' +
      '<p class="lnkup-muted">Markets LNKup asked you to focus on. You can still refer organizers anywhere — referrals outside these cities count the same.</p>';
    if (home) {
      html += '<p><strong>Home market:</strong> ' + escapeHtml(home) + '</p>';
    }
    if (!items.length) {
      return html + '<div class="lnkup-empty-state lnkup-empty-state--compact"><p>No territories assigned yet.</p><p class="lnkup-muted">Your LNKup partner manager will assign cities, metros or states as your market grows.</p></div></section>';
    }
    var labels = { city: 'City', metro: 'Metro area', state: 'State', region: 'Region' };
    html += '<ul class="lnkup-connector-steps">';
    items.forEach(function (t) {
      html += '<li><strong>' + escapeHtml(labels[t.scope_type] || t.scope_type) + ':</strong> ' + escapeHtml(t.value) + '</li>';
    });
    return html + '</ul></section>';
  }

  function renderSupply(data) {
    data = data || {};
    var items = data.items || [];
    var signals = { recruit_organizers: 'Needs organizers', recruit_vendors: 'Needs vendors', balanced: 'Balanced', insufficient_data: 'Not enough data yet' };
    var html = '<h3>Vendor supply in your cities</h3>' +
      '<p class="lnkup-muted">Active LNKup vendors who serve each city and the open booths there' + (data.snapshot_date ? ' (as of ' + escapeHtml(data.snapshot_date) + ')' : '') + '. Cities with many vendors and few booths are the best places to bring new organizers.</p>';
    if (!items.length) {
      return html + '<div class="lnkup-empty-state lnkup-empty-state--compact"><p>No supply numbers for your cities yet.</p><p class="lnkup-muted">They appear after the next daily update once vendors or events exist there.</p></div>';
    }
    html += '<ul class="lnkup-connector-steps">';
    items.forEach(function (c) {
      html += '<li><strong>' + escapeHtml(c.label) + ':</strong> ' + escapeHtml(c.vendors_active) + ' active vendors · ' + escapeHtml(c.open_slots) + ' open booths · ' + escapeHtml(signals[c.signal] || c.signal) + '</li>';
    });
    return html + '</ul>';
  }

  function loadSupply(panel) {
    var box = panel.querySelector('[data-testid="lnkup-connector-supply"]');
    if (!box) {
      return;
    }
    safeFetch('/connector/supply').then(function (data) {
      box.innerHTML = renderSupply(data);
    }).catch(function () {
      box.innerHTML = '<h3>Vendor supply in your cities</h3><p class="lnkup-muted">Vendor supply numbers are not available right now. Try again later.</p>';
    });
  }

  function bindLeads(panel) {
    var form = panel.querySelector('[data-testid="lnkup-connector-lead-form"]');
    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var status = form.querySelector('[data-lead-status]');
        var btn = form.querySelector('[type="submit"]');
        var payload = {};
        new FormData(form).forEach(function (val, key) {
          payload[key] = String(val).trim();
        });
        if (!payload.name) {
          status.textContent = 'Add the organizer or business name.';
          status.className = 'lnkup-connector-interest-status is-error';
          return;
        }
        if (!payload.website && !payload.instagram && !payload.email) {
          status.textContent = 'Add a public website, Instagram or business email so LNKup can verify the organizer.';
          status.className = 'lnkup-connector-interest-status is-error';
          return;
        }
        if (btn) {
          btn.disabled = true;
        }
        fetch(base + '/connector/leads', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        }).then(function (r) {
          var ct = r.headers.get('content-type') || '';
          if (ct.indexOf('application/json') === -1) {
            throw new Error(API_ERROR);
          }
          return r.json().then(function (body) {
            if (!r.ok) {
              throw new Error(body.message || API_ERROR);
            }
            return body;
          });
        }).then(function (res) {
          status.textContent = res.message || 'Lead submitted.';
          status.className = 'lnkup-connector-interest-status ' + (res.status === 'submitted' ? 'is-success' : 'is-error');
          if (res.status === 'submitted') {
            form.reset();
            loadLeadPage(panel, 1);
          }
          announce(status.textContent);
        }).catch(function (err) {
          console.error('LNKup connector lead error:', err);
          status.textContent = err && err.message ? err.message : API_ERROR;
          status.className = 'lnkup-connector-interest-status is-error';
        }).finally(function () {
          if (btn) {
            btn.disabled = false;
          }
        });
      });
    }
    if (panel.dataset.leadPagerBound) {
      return;
    }
    panel.dataset.leadPagerBound = '1';
    panel.addEventListener('click', function (e) {
      var pager = e.target.closest('[data-lead-page]');
      if (!pager || pager.disabled) {
        return;
      }
      loadLeadPage(panel, parseInt(pager.getAttribute('data-lead-page'), 10) || 1);
    });
  }

  function loadLeadPage(panel, page) {
    var mount = panel.querySelector('[data-lead-list]');
    if (!mount) {
      return;
    }
    mount.innerHTML = '<div class="lnkup-skeleton lnkup-skeleton--card"></div>';
    safeFetch('/connector/leads?page=' + encodeURIComponent(page)).then(function (data) {
      mount.innerHTML = renderLeadList(data);
    }).catch(function (err) {
      console.error('LNKup connector leads error:', err);
      mount.innerHTML = '<div class="lnkup-empty-state lnkup-empty-state--compact"><p>' + escapeHtml(API_ERROR) + '</p></div>';
    });
  }

  function rankName(key) {
    var map = {
      city_connector: 'City Connector',
      market_builder: 'Market Builder',
      city_captain: 'City Captain',
      city_director: 'City Director',
      regional_partner: 'Regional Partner'
    };
    return map[key] || key || 'Unranked';
  }

  function renderRankBadge(key, founding) {
    var name = rankName(key);
    var html = '';
    if (key) {
      html += '<span class="lnkup-connector-rank-badge lnkup-connector-rank-badge--' + escapeHtml(key) + '" aria-label="' + escapeHtml(name) + '">' + escapeHtml(name) + '</span>';
    }
    if (founding) {
      html += '<span class="lnkup-connector-rank-badge lnkup-connector-rank-badge--founding" aria-label="Founding City Partner">Founding City Partner</span>';
    }
    return html || '<span class="lnkup-connector-rank-badge lnkup-connector-rank-badge--none" aria-label="Unranked">Unranked</span>';
  }

  function metricValue(row) {
    if (row.key === 'qualifying_gmv') {
      return currency(row.current) + ' / ' + currency(row.required);
    }
    return String(row.current) + ' / ' + String(row.required);
  }

  function remainingCopy(row) {
    if (row.complete) {
      return 'Complete';
    }
    if (row.key === 'qualifying_gmv') {
      return currency(row.remaining) + ' remaining';
    }
    return String(row.remaining) + ' remaining';
  }

  function renderProgressRows(rows) {
    rows = rows || [];
    if (!rows.length) {
      return '';
    }
    var html = '<ul class="lnkup-connector-progress">';
    rows.forEach(function (row) {
      html += '<li class="' + (row.complete ? 'is-complete' : '') + '">' +
        '<div class="lnkup-connector-progress__meta"><span>' + escapeHtml(row.label) + '</span>' +
        '<span>' + escapeHtml(metricValue(row)) + ' · ' + escapeHtml(remainingCopy(row)) + '</span></div>' +
        '<div class="lnkup-connector-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + escapeHtml(row.percent) + '" aria-label="' + escapeHtml(row.label + ' ' + row.percent + ' percent') + '">' +
        '<span style="width:' + escapeHtml(row.percent) + '%"></span></div></li>';
    });
    html += '</ul>';
    return html;
  }

  function remainingSentence(rank) {
    var next = rank && rank.next_rank;
    if (!next || !next.requirements) {
      return '';
    }
    var parts = [];
    next.requirements.forEach(function (row) {
      if (row.complete) {
        return;
      }
      if (row.key === 'activated_organizers') {
        parts.push(row.remaining + ' more activated organizers');
      } else if (row.key === 'qualifying_bookings') {
        parts.push(row.remaining + ' more completed bookings');
      } else if (row.key === 'qualifying_gmv') {
        parts.push(currency(row.remaining) + ' more qualifying GMV');
      } else if (row.key === 'recurring_organizers') {
        parts.push(row.remaining + ' more recurring organizers');
      } else if (row.key === 'approved_cities') {
        parts.push(row.remaining + ' more approved cities');
      }
    });
    if (!parts.length) {
      return 'You have unlocked every listed rank requirement.';
    }
    return 'You need ' + parts.join(', ').replace(/, ([^,]*)$/, ' and $1') + '.';
  }

  function renderRankCard(rank) {
    rank = rank || {};
    var current = rank.current_rank || {};
    var next = rank.next_rank;
    var html = '<section class="lnkup-connector-card lnkup-connector-rank-card" data-testid="lnkup-connector-rank-card">';
    html += '<div class="lnkup-connector-card__head"><h3>Your rank</h3>' +
      '<a class="lnkup-connector-card__link" href="' + escapeHtml(viewHref('ranks')) + '" data-view="ranks">View All Ranks</a></div>';
    html += '<div class="lnkup-connector-rank-card__badges">' + renderRankBadge(current.key, current.founding_partner) + '</div>';
    if (current.earned_at) {
      html += '<p class="lnkup-muted">Earned ' + escapeHtml(current.earned_at) + '</p>';
    }
    if (next) {
      html += '<p><strong>' + escapeHtml(next.progress_percent || 0) + '% toward ' + escapeHtml(next.name) + '</strong></p>';
      html += '<p class="lnkup-muted">' + escapeHtml((rank.next_action && rank.next_action.title) || remainingSentence(rank)) + '</p>';
      html += renderProgressRows(next.requirements);
    }
    if (rank.benefits && rank.benefits.length) {
      html += '<h4>Unlocked</h4><ul class="lnkup-connector-methods">';
      rank.benefits.forEach(function (b) {
        html += '<li>' + escapeHtml(b) + '</li>';
      });
      html += '</ul>';
    }
    html += '</section>';
    return html;
  }

  function renderRoadmap(data) {
    data = data || {};
    var html = '<section class="lnkup-connector-card" data-testid="lnkup-connector-roadmap"><h3>Rank roadmap</h3>';
    if (data.founding_partner) {
      html += '<p>' + renderRankBadge('', true) + '</p>';
    }
    html += '<div class="lnkup-connector-roadmap">';
    (data.ranks || []).forEach(function (rank) {
      html += '<article class="lnkup-connector-roadmap__card is-' + escapeHtml(rank.status) + '">' +
        '<div class="lnkup-connector-card__head"><h3>' + escapeHtml(rank.name) + '</h3>' +
        '<span class="lnkup-muted">' + escapeHtml(rank.status) + '</span></div>' +
        renderRankBadge(rank.key, false);
      if (rank.earned_at && rank.status !== 'locked') {
        html += '<p class="lnkup-muted">Earned ' + escapeHtml(rank.earned_at) + '</p>';
      }
      html += renderProgressRows(rank.requirements);
      html += '<p><strong>Bonus:</strong> ' + (rank.bonus > 0 ? escapeHtml(currency(rank.bonus)) + ' (one-time, pending approval)' : 'None') + '</p>';
      html += '<ul class="lnkup-connector-methods">';
      (rank.benefits || []).forEach(function (b) {
        html += '<li>' + escapeHtml(b) + '</li>';
      });
      html += '</ul></article>';
    });
    html += '</div></section>';
    return html;
  }

  function renderLeaderboard(data) {
    data = data || {};
    var html = '<section class="lnkup-connector-card" data-testid="lnkup-connector-leaderboard"><h3>City leaderboard</h3>';
    html += '<p class="lnkup-muted">' + escapeHtml(data.method || 'Ranked by verified rank, then bookings, then activated organizers. Earnings are never shown.') + '</p>';
    html += '<div class="lnkup-connector-filters" data-leaderboard-periods>';
    ['all', 'monthly', 'weekly'].forEach(function (p) {
      html += '<button type="button" class="lnkup-connector-filter' + (data.period === p ? ' is-active' : '') + '" data-period="' + p + '">' + (p === 'all' ? 'All time' : p.charAt(0).toUpperCase() + p.slice(1)) + '</button>';
    });
    html += '</div>';
    if (!data.enabled) {
      html += '<div class="lnkup-empty-state lnkup-empty-state--compact"><p>The leaderboard is turned off.</p></div>';
    } else if (!data.connectors || !data.connectors.length) {
      html += '<div class="lnkup-empty-state lnkup-empty-state--compact"><p>Not enough opted-in connectors in this city yet.</p></div>';
    } else {
      html += '<ol class="lnkup-connector-leaderboard">';
      data.connectors.forEach(function (row) {
        html += '<li><strong>' + escapeHtml(row.display_name) + '</strong> ' +
          renderRankBadge(row.rank_key, false) +
          '<span class="lnkup-muted">' + escapeHtml(row.activated_organizers) + ' activated · ' + escapeHtml(row.qualifying_bookings) + ' bookings</span></li>';
      });
      html += '</ol>';
    }
    html += '</section>';
    return html;
  }

  function maybeCelebrate(rank) {
    var cele = rank && rank.celebration;
    var sheet = document.getElementById('lnkup-connector-share-sheet');
    if (!cele || !sheet || sheet._celebrationShown === cele.key) {
      return;
    }
    sheet._celebrationShown = cele.key;
    cache.lastFocus = document.activeElement;
    var html = '<div class="lnkup-connector-sheet__backdrop" data-sheet-close></div>';
    html += '<div class="lnkup-connector-sheet__panel" role="document" data-testid="lnkup-connector-celebration">';
    html += '<div class="lnkup-connector-sheet__head"><h2 id="lnkup-connector-share-title">You earned ' + escapeHtml(cele.name) + '</h2>';
    html += '<button type="button" class="lnkup-connector-sheet__close" data-sheet-close aria-label="Close">Close</button></div>';
    html += '<p>' + renderRankBadge(cele.key, false) + '</p>';
    html += '<p>Unlocked benefits:</p><ul class="lnkup-connector-methods">';
    (cele.benefits || []).forEach(function (b) {
      html += '<li>' + escapeHtml(b) + '</li>';
    });
    html += '</ul>';
    if (cele.bonus > 0) {
      html += '<p class="lnkup-muted">Any cash bonus is pending LNKup verification. Rank does not change your commission rate.</p>';
    }
    html += '<div class="lnkup-connector-sheet__actions">';
    html += '<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-connector-copy" data-copy-label="Copy" data-copy="' + escapeHtml('I just reached ' + cele.name + ' as a LNKup City Connector.') + '">Share achievement</button>';
    html += '<button type="button" class="lnkup-btn lnkup-btn--outline" data-sheet-close>Dismiss</button>';
    html += '</div></div>';
    sheet.innerHTML = html;
    sheet.hidden = false;
    sheet.classList.add('is-open');
    document.body.classList.add('lnkup-connector-sheet-open');
    bindShareSheet(sheet);
    trapFocus(sheet);
    safeFetch('/connector/rank/acknowledge', { method: 'POST', body: {} }).catch(function () { return null; });
  }

  function renderSticky() {
    return '<div class="lnkup-connector-sticky" data-testid="lnkup-connector-sticky">' +
      '<button type="button" class="lnkup-btn lnkup-btn--primary" data-connector-share>Share Your Link</button>' +
      '</div>';
  }

  function failPanel(panel, message) {
    if (!panel) {
      return;
    }
    if (window.LNKupUI) {
      LNKupUI.panelError(panel, {
        title: message || API_ERROR,
        onRetry: function () {
          panel.dataset.loaded = '';
          loadPanel(panel);
        }
      });
      return;
    }
    panel.innerHTML = '<div class="lnkup-empty-state lnkup-empty-state--compact"><p>' + escapeHtml(message || API_ERROR) + '</p></div>';
    panel.dataset.loaded = '1';
  }

  function bindPanelExtras(panel) {
    bindCopyButtons(panel);
    panel.querySelectorAll('.lnkup-connector-help').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var open = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        var next = btn.nextElementSibling;
        if (!open) {
          if (!next || !next.classList.contains('lnkup-connector-help__text')) {
            var p = document.createElement('p');
            p.className = 'lnkup-connector-help__text lnkup-muted';
            p.textContent = btn.getAttribute('data-help') || '';
            btn.insertAdjacentElement('afterend', p);
          }
        } else if (next && next.classList.contains('lnkup-connector-help__text')) {
          next.remove();
        }
      });
    });
    var calc = panel.querySelector('[data-testid="lnkup-connector-calculator"]');
    if (calc) {
      calc.addEventListener('submit', function (e) {
        e.preventDefault();
        var out = panel.querySelector('[data-calc-out]');
        var fd = new FormData(calc);
        safeFetch('/connector/calculator', {
          method: 'POST',
          body: {
            booth_price: fd.get('booth_price'),
            paid_bookings: fd.get('paid_bookings'),
            organizers: fd.get('organizers')
          }
        }).then(function (res) {
          if (!out) {
            return;
          }
          out.innerHTML = 'Estimated GMV ' + escapeHtml(currency(res.gmv)) +
            ' · Estimated earnings ' + escapeHtml(currency(res.earnings)) +
            ' · Per organizer ' + escapeHtml(currency(res.per_organizer)) +
            ' <span class="lnkup-muted">(estimate only)</span>';
        }).catch(function () {
          if (out) {
            out.textContent = API_ERROR;
          }
        });
      });
    }
    var profile = panel.querySelector('[data-testid="lnkup-connector-profile"]');
    if (profile) {
      profile.addEventListener('submit', function (e) {
        e.preventDefault();
        var status = profile.querySelector('[data-profile-status]');
        var payload = {};
        new FormData(profile).forEach(function (val, key) {
          if (key === 'growth_styles[]') {
            return;
          }
          payload[key] = val;
        });
        payload.growth_styles = [];
        profile.querySelectorAll('[name="growth_styles[]"]:checked').forEach(function (box) {
          payload.growth_styles.push(box.value);
        });
        payload.leaderboard_opt_in = profile.querySelector('[name="leaderboard_opt_in"]') && profile.querySelector('[name="leaderboard_opt_in"]').checked ? 1 : 0;
        safeFetch('/connector/profile', { method: 'POST', body: payload }).then(function () {
          if (status) {
            status.textContent = 'Profile saved.';
            status.className = 'lnkup-connector-interest-status is-success';
          }
        }).catch(function () {
          if (status) {
            status.textContent = API_ERROR;
            status.className = 'lnkup-connector-interest-status is-error';
          }
        });
      });
    }
    if (panel.querySelector('#lnkup-resources-targets')) {
      markStep('reviewed_targets');
    }
    if (panel.getAttribute('data-view') === 'earnings') {
      markStep('reviewed_earnings');
    }
    panel.querySelectorAll('[data-growth]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        btn.classList.toggle('is-selected');
        btn.setAttribute('aria-pressed', btn.classList.contains('is-selected') ? 'true' : 'false');
        var selected = [];
        panel.querySelectorAll('[data-growth].is-selected').forEach(function (el) {
          selected.push(el.getAttribute('data-growth'));
        });
        safeFetch('/connector/profile', { method: 'POST', body: { growth_styles: selected } }).then(function () {
          panel.dataset.loaded = '';
          loadPanel(panel);
        }).catch(function () {
          return null;
        });
      });
    });
    function closeStudioBuilder() {
      var mount = panel.querySelector('[data-studio-builder]');
      if (mount) {
        mount.innerHTML = '';
        mount.classList.remove('is-open');
      }
      document.body.classList.remove('lnkup-connector-builder-open');
      if (cache.builderTrap) {
        document.removeEventListener('keydown', cache.builderTrap);
        cache.builderTrap = null;
      }
    }
    function bindStudioBuilder(mount) {
      bindCopyButtons(mount);
      mount.querySelectorAll('[data-close-builder]').forEach(function (el) {
        el.addEventListener('click', closeStudioBuilder);
      });
      mount.querySelectorAll('[data-download-shots]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var body = btn.getAttribute('data-download-shots') || '';
          var blob = new Blob([body], { type: 'text/plain' });
          var a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          a.download = 'lnkup-shot-list.txt';
          a.click();
          URL.revokeObjectURL(a.href);
        });
      });
      mount.querySelectorAll('[data-plan-template]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          safeFetch('/connector/studio/posts', {
            method: 'POST',
            body: { template_id: btn.getAttribute('data-plan-template'), status: 'planned', title: 'Planned video' }
          }).then(function () {
            announce('Marked as planned.');
            btn.textContent = 'Planned';
          }).catch(function () {
            announce(API_ERROR);
          });
        });
      });
      cache.builderTrap = function (e) {
        if (e.key === 'Escape') {
          e.preventDefault();
          closeStudioBuilder();
        }
      };
      document.addEventListener('keydown', cache.builderTrap);
    }
    panel.querySelectorAll('[data-open-template]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-open-template');
        var mount = panel.querySelector('[data-studio-builder]');
        if (!id || !mount) {
          return;
        }
        safeFetch('/connector/studio/templates/' + encodeURIComponent(id)).then(function (tpl) {
          mount.innerHTML = renderStudioBuilder(tpl);
          mount.classList.add('is-open');
          document.body.classList.add('lnkup-connector-builder-open');
          bindStudioBuilder(mount);
        }).catch(function () {
          announce(API_ERROR);
        });
      });
    });
    panel.querySelectorAll('[data-studio-cat]').forEach(function (chip) {
      chip.addEventListener('click', function () {
        var cat = chip.getAttribute('data-studio-cat') || '';
        panel.querySelectorAll('[data-studio-cat]').forEach(function (c) {
          c.classList.toggle('is-active', c === chip);
        });
        panel.querySelectorAll('[data-template-cat]').forEach(function (card) {
          card.hidden = !!(cat && card.getAttribute('data-template-cat') !== cat);
        });
      });
    });
    panel.querySelectorAll('[data-post-month]').forEach(function (chip) {
      if (chip.tagName !== 'BUTTON') {
        return;
      }
      chip.addEventListener('click', function () {
        var month = chip.getAttribute('data-post-month') || '';
        panel.querySelectorAll('button[data-post-month]').forEach(function (c) {
          c.classList.toggle('is-active', c === chip);
        });
        panel.querySelectorAll('li[data-post-month]').forEach(function (row) {
          row.hidden = !!(month && row.getAttribute('data-post-month') !== month);
        });
      });
    });
    function applyStudioUrlHint(form) {
      var select = form.querySelector('[data-studio-platform]');
      var input = form.querySelector('[data-studio-url]');
      var hint = form.querySelector('[data-studio-url-hint]');
      var row = studioPlatform(select && select.value) || STUDIO_PLATFORMS[0];
      if (input) {
        input.setAttribute('placeholder', row.placeholder);
      }
      if (hint) {
        hint.textContent = row.hint;
      }
    }
    var publish = panel.querySelector('[data-testid="lnkup-connector-publish-form"]');
    if (publish) {
      applyStudioUrlHint(publish);
      var platSelect = publish.querySelector('[data-studio-platform]');
      var urlInput = publish.querySelector('[data-studio-url]');
      if (platSelect) {
        platSelect.addEventListener('change', function () {
          applyStudioUrlHint(publish);
        });
      }
      if (urlInput) {
        urlInput.addEventListener('input', function () {
          var inferred = inferStudioPlatform(urlInput.value);
          if (inferred && platSelect && platSelect.value !== inferred) {
            platSelect.value = inferred;
            applyStudioUrlHint(publish);
          }
        });
      }
      publish.addEventListener('submit', function (e) {
        e.preventDefault();
        var status = publish.querySelector('[data-publish-status]');
        var fd = new FormData(publish);
        safeFetch('/connector/studio/posts', {
          method: 'POST',
          body: {
            platform: fd.get('platform'),
            url: fd.get('url'),
            audience: fd.get('audience'),
            status: 'published',
            title: 'Published video'
          }
        }).then(function () {
          if (status) {
            status.textContent = 'Published video submitted. Commission still comes from referral bookings, not this post.';
            status.className = 'lnkup-connector-interest-status is-success';
          }
        }).catch(function () {
          if (status) {
            status.textContent = 'Use an Instagram, TikTok, LinkedIn, Facebook, or YouTube URL.';
            status.className = 'lnkup-connector-interest-status is-error';
          }
        });
      });
    }
    panel.querySelectorAll('[data-scroll-publish]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var form = panel.querySelector('[data-testid="lnkup-connector-publish-form"]');
        if (!form) {
          return;
        }
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        form.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
        var field = form.querySelector('[data-studio-url]');
        if (field && field.focus) {
          field.focus();
        }
      });
    });
    var refs = panel.querySelector('.lnkup-connector-referrals');
    if (refs && refs._cards) {
      paintReferrals(refs, refs._cards);
      refs.querySelectorAll('.lnkup-connector-filter').forEach(function (btn) {
        btn.addEventListener('click', function () {
          refs.querySelectorAll('.lnkup-connector-filter').forEach(function (b) { b.classList.remove('is-active'); });
          btn.classList.add('is-active');
          paintReferrals(refs, refs._cards);
        });
      });
      var search = refs.querySelector('.lnkup-connector-search');
      if (search) {
        search.addEventListener('input', function () {
          paintReferrals(refs, refs._cards);
        });
      }
    }
    var ledgerFilter = panel.querySelector('[data-ledger-filter]');
    if (ledgerFilter && ledgerFilter._rows) {
      ledgerFilter.addEventListener('change', function () {
        var mount = panel.querySelector('[data-ledger-mount]');
        if (mount) {
          mount.innerHTML = renderLedger(ledgerFilter._rows, ledgerFilter.value);
        }
      });
    }
  }

  function loadPanel(panel) {
    if (!panel || panel.dataset.loaded) {
      return;
    }
    var view = panel.getAttribute('data-view');
    panel.innerHTML = '<div class="lnkup-skeleton lnkup-skeleton--card"></div>';
    var promise;
    if (view === 'home') {
      promise = Promise.all([
        safeFetch('/connector/dashboard'),
        safeFetch('/connector/funnel'),
        safeFetch('/connector/referrals')
      ]).then(function (pair) {
        var data = pair[0];
        var funnel = pair[1];
        var referrals = pair[2] || {};
        var stats = data.stats || {};
        rememberLinks(data.links, data.terms);
        var city_program = isCityConnector();
        panel.innerHTML = '<div class="lnkup-connector-panel">' +
          renderHero(data, true) +
          (city_program && data.rank ? renderRankCard(data.rank) : '') +
          (city_program ? renderNextAction(data.next_action) : '') +
          (data.terms && data.terms.terms_pending ? renderTermsPending() : '') +
          '<section class="lnkup-connector-card"><div class="lnkup-connector-card__head"><h3>Overview</h3>' +
          (city_program ? '<a class="lnkup-connector-card__link" href="' + escapeHtml(viewHref('start')) + '" data-view="start">Get Started</a>' : '') + '</div>' +
          renderBalances(data.earnings) +
          '<div class="lnkup-connector-kpis">' +
          kpi('Organizers', stats.organizer_signups) +
          kpi('Vendors', stats.vendor_signups) +
          kpi('Activated', stats.activated) +
          kpi('Bookings', stats.paid_bookings) +
          '</div></section>' +
          renderFunnel(funnel.counts) +
          renderActivity(referrals.activity) +
          renderSticky() +
          '</div>';
        if (city_program) {
          maybeCelebrate(data.rank);
        }
      });
    } else if (view === 'start') {
      promise = Promise.all([
        safeFetch('/connector/onboarding'),
        safeFetch('/connector/funnel')
      ]).then(function (pair) {
        var data = pair[0];
        var funnel = pair[1];
        rememberLinks(data.links, data.terms, data.connector && data.connector.city);
        data.onboarding = data.onboarding || { progress: data.progress || 0 };
        panel.innerHTML = '<div class="lnkup-connector-panel">' +
          renderHero(data, false) +
          renderLaunchPlan(data) +
          renderGrowthStyles(data.growth_styles || (data.connector && data.connector.growth_styles) || []) +
          renderSticky() +
          '</div>';
      });
    } else if (view === 'referrals') {
      promise = safeFetch('/connector/funnel').then(function (data) {
        var note = data.vendor_referrals_rewarded
          ? 'Vendor referrals can earn when that reward rule is on.'
          : 'Vendor referrals are tracked. They do not earn commission unless LNKup turns on a vendor reward rule.';
        var cards = (data.organizers || []).concat(data.vendors || []);
        panel.innerHTML = '<div class="lnkup-connector-panel"><section class="lnkup-connector-card"><h3>My Referrals</h3>' +
          renderPipeline(cards, note) +
          '</section>' + renderSticky() + '</div>';
        var refs = panel.querySelector('.lnkup-connector-referrals');
        if (refs) {
          refs._cards = cards;
        }
      });
    } else if (view === 'leads') {
      promise = safeFetch('/connector/leads').then(function (data) {
        panel.innerHTML = '<div class="lnkup-connector-panel">' + renderLeads(data) + renderSticky() + '</div>';
        bindLeads(panel);
      });
    } else if (view === 'cities') {
      promise = safeFetch('/connector/territories').then(function (data) {
        panel.innerHTML = '<div class="lnkup-connector-panel">' + renderCities(data) +
          '<section class="lnkup-connector-card" data-testid="lnkup-connector-supply"><h3>Vendor supply in your cities</h3><p class="lnkup-muted">Loading…</p></section></div>';
        loadSupply(panel);
      });
    } else if (view === 'earnings') {
      promise = safeFetch('/connector/earnings').then(function (data) {
        rememberLinks(null, data.terms);
        var pending = !!(data.terms && data.terms.terms_pending);
        panel.innerHTML = '<div class="lnkup-connector-panel">' +
          (pending ? renderTermsPending() : renderHowPaid(data.terms, data.example) + renderCalculator()) +
          '<section class="lnkup-connector-card"><h3>Balances</h3>' + renderBalances(data.balances) +
          '<p><strong>Next payout:</strong> ' + escapeHtml(data.next_payout || 'After LNKup approval') + '</p>' +
          '<a class="lnkup-btn lnkup-btn--outline" href="' + escapeHtml(viewHref('profile')) + '" data-view="profile">Add payout details</a>' +
          '</section>' +
          '<section class="lnkup-connector-card"><h3>Ledger</h3>' +
          '<label class="lnkup-connector-link-row__label" for="lnkup-ledger-filter">Status</label>' +
          '<select id="lnkup-ledger-filter" data-ledger-filter>' +
          '<option value="all">All</option>' +
          '<option value="pending_hold">Pending hold</option>' +
          '<option value="awaiting_approval">Awaiting approval</option>' +
          '<option value="on_hold">Under review</option>' +
          '<option value="approved">Approved</option>' +
          '<option value="paid">Paid</option>' +
          '<option value="reversed">Reversed</option>' +
          '</select>' +
          '<div data-ledger-mount>' + renderLedger(data.ledger) + '</div>' +
          (data.ledger_has_more ? '<button type="button" class="lnkup-btn lnkup-btn--outline" data-ledger-more data-testid="lnkup-connector-ledger-more">Show older commissions</button>' : '') +
          '</section>' +
          '</div>';
        var select = panel.querySelector('[data-ledger-filter]');
        if (select) {
          select._rows = data.ledger || [];
          select._page = data.ledger_page || 1;
        }
        var more = panel.querySelector('[data-ledger-more]');
        if (more && select) {
          more.addEventListener('click', function () {
            more.disabled = true;
            safeFetch('/connector/earnings?page=' + (select._page + 1)).then(function (next) {
              select._rows = select._rows.concat(next.ledger || []);
              select._page = next.ledger_page || select._page + 1;
              var mount = panel.querySelector('[data-ledger-mount]');
              if (mount) {
                mount.innerHTML = renderLedger(select._rows, select.value);
              }
              if (next.ledger_has_more) {
                more.disabled = false;
              } else {
                more.remove();
              }
            }).catch(function () {
              more.disabled = false;
              more.textContent = 'Could not load older commissions. Try again';
            });
          });
        }
      });
    } else if (view === 'resources') {
      promise = Promise.all([
        safeFetch('/connector/resources'),
        safeFetch('/connector/links')
      ]).then(function (pair) {
        rememberLinks(pair[1], pair[0].terms, (pair[0] && pair[0].city) || (pair[1] && pair[1].city));
        panel.innerHTML = '<div class="lnkup-connector-panel">' + renderResources(pair[0], pair[1]) + '</div>';
      });
    } else if (view === 'ranks') {
      promise = Promise.all([
        safeFetch('/connector/rank/roadmap'),
        safeFetch('/connector/leaderboard?period=all')
      ]).then(function (pair) {
        panel.innerHTML = '<div class="lnkup-connector-panel">' + renderRoadmap(pair[0]) + renderLeaderboard(pair[1]) + '</div>';
        function bindBoard(wrap) {
          if (!wrap) {
            return;
          }
          wrap.querySelectorAll('[data-period]').forEach(function (btn) {
            btn.addEventListener('click', function () {
              safeFetch('/connector/leaderboard?period=' + encodeURIComponent(btn.getAttribute('data-period') || 'all')).then(function (res) {
                var next = document.createElement('div');
                next.innerHTML = renderLeaderboard(res);
                var fresh = next.firstElementChild;
                wrap.replaceWith(fresh);
                bindBoard(fresh);
              }).catch(function () { return null; });
            });
          });
        }
        bindBoard(panel.querySelector('[data-testid="lnkup-connector-leaderboard"]'));
      });
    } else if (view === 'studio') {
      promise = Promise.all([
        safeFetch('/connector/studio'),
        safeFetch('/connector/studio/analytics')
      ]).then(function (pair) {
        panel.innerHTML = '<div class="lnkup-connector-panel">' + renderStudio(pair[0], pair[1]) + '</div>';
      });
    } else if (view === 'profile') {
      promise = safeFetch('/connector/onboarding').then(function (data) {
        rememberLinks(data.links, data.terms, data.connector && data.connector.city);
        panel.innerHTML = '<div class="lnkup-connector-panel">' + renderProfile(data.connector, data.code || (data.links && data.links.code)) + '</div>';
      });
    } else {
      panel.innerHTML = '';
      return;
    }
    promise.then(function () {
      panel.dataset.loaded = '1';
      bindPanelExtras(panel);
    }).catch(function () {
      failPanel(panel);
    });
  }

  function updateNav(view) {
    view = normalizeView(view);
    document.querySelectorAll('.lnkup-connector-nav a').forEach(function (link) {
      var active = (link.getAttribute('data-view') || '') === view;
      link.classList.toggle('is-active', active);
      if (active) {
        link.setAttribute('aria-current', 'page');
      } else {
        link.removeAttribute('aria-current');
      }
    });
    document.querySelectorAll('.lnkup-dashboard__nav-link[data-view], .lnkup-dashboard__bottom-link[data-view]').forEach(function (link) {
      var active = (link.getAttribute('data-view') || '') === view;
      link.classList.toggle('is-active', active);
      if (active) {
        link.setAttribute('aria-current', 'page');
      } else {
        link.removeAttribute('aria-current');
      }
    });
  }

  function applyView(view, opts) {
    opts = opts || {};
    view = normalizeView(view);
    var root = rootEl();
    var panel = root && root.querySelector('[data-connector-panel]');
    if (!root || !panel) {
      return;
    }
    root.setAttribute('data-view', view);
    panel.setAttribute('data-view', view);
    panel.dataset.loaded = '';
    updateNav(view);
    if (!opts.skipLoad) {
      loadPanel(panel);
    }
  }

  function setView(view, opts) {
    opts = opts || {};
    view = normalizeView(view);
    var url = new URL(window.location.href);
    url.searchParams.set('view', view);
    var next = url.pathname + url.search + url.hash;
    if (opts.replace) {
      history.replaceState({ view: view }, '', next);
    } else {
      history.pushState({ view: view }, '', next);
    }
    applyView(view);
  }

  function connectorViewFromLink(link) {
    if (!link) {
      return '';
    }
    var attr = link.getAttribute('data-view');
    if (attr && VIEWS.indexOf(attr) !== -1) {
      return attr;
    }
    var href = link.getAttribute('href');
    if (!href || href === '#') {
      return '';
    }
    try {
      var url = new URL(href, window.location.origin);
      if (url.pathname !== window.location.pathname) {
        return '';
      }
      return normalizeView(url.searchParams.get('view') || '');
    } catch (e) {
      return '';
    }
  }

  function initInterestForm() {
    var form = document.querySelector('[data-testid="lnkup-connector-interest-form"]');
    if (!form) {
      return;
    }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var status = form.querySelector('.lnkup-connector-interest-status');
      var btn = form.querySelector('[type="submit"]');
      if (btn) {
        btn.disabled = true;
      }
      var payload = {};
      new FormData(form).forEach(function (val, key) {
        payload[key] = val;
      });
      var restBase = (ajax.restUrl || '').replace(/\/marketplace\/?$/, '') || '/wp-json/lnkup/v1';
      fetch(restBase + '/connector/interest', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(function (r) {
        return r.json().then(function (body) {
          if (!r.ok) {
            throw new Error(body.message || API_ERROR);
          }
          return body;
        });
      }).then(function () {
        if (status) {
          status.textContent = 'Thanks! We received your application and will be in touch.';
          status.classList.add('is-success');
        }
        form.reset();
      }).catch(function (err) {
        if (status) {
          status.textContent = err.message || API_ERROR;
          status.classList.add('is-error');
        }
      }).finally(function () {
        if (btn) {
          btn.disabled = false;
        }
      });
    });
  }

  function init() {
    var root = rootEl();
    if (!root) {
      initInterestForm();
      return;
    }
    var view = viewFromUrl();
    var panel = root.querySelector('[data-connector-panel]');
    if (panel) {
      panel.setAttribute('data-view', view);
      applyView(view);
    }
    document.addEventListener('click', function (e) {
      var share = e.target.closest('[data-connector-share]');
      if (share) {
        e.preventDefault();
        openShareSheet();
        return;
      }
      var link = e.target.closest('a[data-view], .lnkup-connector-nav a, .lnkup-dashboard__nav-link[data-view], .lnkup-dashboard__bottom-link[data-view]');
      if (!link) {
        return;
      }
      var next = connectorViewFromLink(link);
      if (!next) {
        return;
      }
      e.preventDefault();
      setView(next);
    });
    window.addEventListener('popstate', function () {
      applyView(viewFromUrl());
    });
    initInterestForm();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
