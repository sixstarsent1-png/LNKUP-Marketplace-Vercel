<?php
/**
 * Organizer dashboard page template.
 *
 * Template Name: Organizer Dashboard
 *
 * @package LNKup_Theme
 */

lnkup_require_roles( array( 'lnkup_organizer', 'lnkup_admin', 'administrator' ) );

get_header();
?>
<script>
(function () {
  if (window.location.pathname.indexOf('organizer-dashboard') === -1) {
    return;
  }
  var raw = (window.location.hash || '').replace(/^#/, '');
  if (!raw) {
    return;
  }
  var section = raw.split('&')[0].split('?')[0];
  var params = new URLSearchParams(window.location.search);
  var map = { overview: 'home', payments: 'financial', import: 'create', 'create-event': 'create', 'create-opportunity': 'events' };
  var pricing = section.match(/^pricing-(\d+)$/);
  if (pricing) {
    params.set('view', 'events');
    params.set('event', pricing[1]);
    params.set('edit', '1');
  } else {
    var view = map[section] || section || 'home';
    var allowed = ['home', 'events', 'applications', 'financial', 'vendors', 'lists', 'invites', 'referrals', 'operations', 'intelligence', 'create', 'help'];
    if (allowed.indexOf(view) === -1) {
      view = 'home';
    }
    params.set('view', view);
  }
  raw.split('&').slice(1).forEach(function (part) {
    var bits = part.split('=');
    if (!bits[0]) {
      return;
    }
    var key = decodeURIComponent(bits[0]);
    var value = decodeURIComponent(bits.slice(1).join('=') || '');
    if (key === 'event' && params.get('view') === 'applications') {
      key = 'event_filter';
    }
    params.set(key, value);
  });
  window.location.replace(window.location.pathname + '?' + params.toString());
})();
</script>
<?php
lnkup_dashboard_shell_open( 'organizer', lnkup_dashboard_organizer_title() );
echo do_shortcode( '[lnkup_organizer_dashboard]' );
lnkup_dashboard_shell_close();
get_footer();
