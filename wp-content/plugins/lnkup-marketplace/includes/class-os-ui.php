<?php
/**
 * LNKup OS design layer shared by the vendor and organizer workspaces.
 *
 * Every method returns escaped HTML. Arguments documented as "escaped HTML" must
 * already be escaped by the caller.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Shared UI building blocks, body classes and asset loading.
 */
class LNKup_OS_UI {

	const BODY_CLASS           = 'lnkup-os';
	const VENDOR_BODY_CLASS    = 'lnkup-vos';
	const ORGANIZER_BODY_CLASS = 'lnkup-oos';

	/**
	 * Register hooks once for both workspaces.
	 */
	public static function init(): void {
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 99 );
	}

	/**
	 * Vendor-facing pages that use the OS layer.
	 */
	public static function is_vendor_surface(): bool {
		if ( is_admin() ) {
			return false;
		}
		if ( is_page( array( 'vendor-dashboard', 'opportunities', 'favorites', 'vendor-profile' ) ) ) {
			return true;
		}
		if ( is_page( 'notification-center' ) && class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_vendor() && ! LNKup_Roles::is_organizer() ) {
			return true;
		}
		return is_singular( array( 'lnkup_event', 'lnkup_opportunity' ) );
	}

	/**
	 * Organizer-facing pages that use the OS layer.
	 */
	public static function is_organizer_surface(): bool {
		if ( is_admin() ) {
			return false;
		}
		if ( is_page( 'organizer-dashboard' ) ) {
			return true;
		}
		return is_page( 'notification-center' ) && class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_organizer();
	}

	/**
	 * Which workspace the current request renders: vendor, organizer, or ''.
	 */
	public static function surface(): string {
		if ( self::is_organizer_surface() ) {
			return 'organizer';
		}
		return self::is_vendor_surface() ? 'vendor' : '';
	}

	/**
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		$surface = self::surface();
		if ( '' === $surface ) {
			return $classes;
		}
		$classes[] = self::BODY_CLASS;
		$classes[] = 'organizer' === $surface ? self::ORGANIZER_BODY_CLASS : self::VENDOR_BODY_CLASS;
		return $classes;
	}

	/**
	 * Asset version for a plugin-relative file.
	 *
	 * @param string $path Plugin-relative path.
	 */
	private static function version( string $path ): string {
		return function_exists( 'lnkup_asset_version' ) ? (string) lnkup_asset_version( $path ) : (string) LNKUP_VERSION;
	}

	/**
	 * Load the shared layer, then the workspace layer, after every other stylesheet.
	 */
	public static function enqueue(): void {
		$surface = self::surface();
		if ( '' === $surface ) {
			return;
		}
		wp_enqueue_style( 'lnkup-os', LNKUP_PLUGIN_URL . 'public/css/lnkup-os.css', array(), self::version( 'public/css/lnkup-os.css' ) );
		wp_enqueue_script( 'lnkup-os', LNKUP_PLUGIN_URL . 'public/js/lnkup-os.js', array(), self::version( 'public/js/lnkup-os.js' ), true );
		if ( 'organizer' === $surface ) {
			wp_enqueue_style( 'lnkup-organizer-os', LNKUP_PLUGIN_URL . 'public/css/organizer-os.css', array( 'lnkup-os' ), self::version( 'public/css/organizer-os.css' ) );
			wp_enqueue_script( 'lnkup-organizer-os', LNKUP_PLUGIN_URL . 'public/js/organizer-os.js', array( 'lnkup-os' ), self::version( 'public/js/organizer-os.js' ), true );
		} else {
			wp_enqueue_style( 'lnkup-vendor-os', LNKUP_PLUGIN_URL . 'public/css/vendor-os.css', array( 'lnkup-os' ), self::version( 'public/css/vendor-os.css' ) );
		}
		if ( is_user_logged_in() ) {
			wp_localize_script(
				'lnkup-os',
				'lnkupOS',
				array(
					'root'      => esc_url_raw( rest_url( 'lnkup/v1/' ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'role'      => $surface,
					'dashboard' => esc_url_raw( function_exists( 'lnkup_page_url_helper' ) ? lnkup_page_url_helper( 'organizer' === $surface ? 'organizer-dashboard' : 'vendor-dashboard' ) : home_url( '/' ) ),
					'tzOffset'  => (int) round( (float) get_option( 'gmt_offset', 0 ) * 60 ),
				)
			);
		}
	}

	/**
	 * Page header: optional eyebrow, title, subtitle, actions.
	 *
	 * @param array{title:string,subtitle?:string,eyebrow?:string,actions?:string,testid?:string} $args Header args. `actions` must already be escaped HTML.
	 */
	public static function page_header( array $args ): string {
		$testid = isset( $args['testid'] ) ? ' data-testid="' . esc_attr( (string) $args['testid'] ) . '"' : '';
		$html   = '<header class="vos-page-header"' . $testid . '><div class="vos-page-header__copy">';
		if ( ! empty( $args['eyebrow'] ) ) {
			$html .= '<p class="vos-eyebrow">' . esc_html( (string) $args['eyebrow'] ) . '</p>';
		}
		$html .= '<h1 class="vos-page-header__title">' . esc_html( (string) $args['title'] ) . '</h1>';
		if ( ! empty( $args['subtitle'] ) ) {
			$html .= '<p class="vos-page-header__subtitle">' . esc_html( (string) $args['subtitle'] ) . '</p>';
		}
		$html .= '</div>';
		if ( ! empty( $args['actions'] ) ) {
			$html .= '<div class="vos-page-header__actions">' . $args['actions'] . '</div>';
		}
		return $html . '</header>';
	}

	/**
	 * One metric tile. Links when `href` is set so counts open the matching view.
	 *
	 * @param array{label:string,value:string,unit?:string,hint?:string,href?:string,tone?:string,key?:string} $m Metric.
	 */
	public static function metric( array $m ): string {
		$tone  = isset( $m['tone'] ) ? ' vos-metric--' . sanitize_html_class( (string) $m['tone'] ) : '';
		$key   = isset( $m['key'] ) ? ' data-stat-key="' . esc_attr( (string) $m['key'] ) . '"' : '';
		$inner = '<span class="vos-metric__label">' . esc_html( (string) $m['label'] ) . '</span>'
			. '<span class="vos-metric__value">' . esc_html( (string) $m['value'] )
			. ( ! empty( $m['unit'] ) ? '<span class="vos-metric__unit">' . esc_html( (string) $m['unit'] ) . '</span>' : '' )
			. '</span>'
			. ( ! empty( $m['hint'] ) ? '<span class="vos-metric__hint">' . esc_html( (string) $m['hint'] ) . '</span>' : '' );
		if ( ! empty( $m['href'] ) ) {
			return '<a class="vos-metric' . $tone . '" href="' . esc_url( (string) $m['href'] ) . '"' . $key . '>' . $inner . '</a>';
		}
		return '<div class="vos-metric' . $tone . '"' . $key . '>' . $inner . '</div>';
	}

	/**
	 * Metric grid.
	 *
	 * @param array<int,array> $metrics Metrics.
	 * @param string           $testid  Test id.
	 */
	public static function metrics( array $metrics, string $testid = '' ): string {
		$html = '<div class="vos-metrics"' . ( $testid ? ' data-testid="' . esc_attr( $testid ) . '"' : '' ) . '>';
		foreach ( $metrics as $m ) {
			$html .= self::metric( $m );
		}
		return $html . '</div>';
	}

	/**
	 * Section heading row with optional action link.
	 *
	 * @param string $title        Heading.
	 * @param string $action_label Link label.
	 * @param string $action_href  Link URL.
	 * @param string $id           Heading id.
	 */
	public static function section_head( string $title, string $action_label = '', string $action_href = '', string $id = '' ): string {
		$html = '<div class="vos-section-head"><h2 class="vos-section-head__title"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>' . esc_html( $title ) . '</h2>';
		if ( $action_label && $action_href ) {
			$html .= '<a class="vos-link" href="' . esc_url( $action_href ) . '">' . esc_html( $action_label ) . '</a>';
		}
		return $html . '</div>';
	}

	/**
	 * Status label with an icon glyph so color is never the only signal.
	 *
	 * @param string $label Customer-facing label.
	 * @param string $tone  success|warning|danger|info|neutral.
	 */
	public static function status( string $label, string $tone = 'neutral' ): string {
		$icons = array(
			'success' => '✓',
			'warning' => '!',
			'danger'  => '×',
			'info'    => '•',
			'neutral' => '•',
		);
		$tone = isset( $icons[ $tone ] ) ? $tone : 'neutral';
		return '<span class="vos-status vos-status--' . esc_attr( $tone ) . '"><span class="vos-status__icon" aria-hidden="true">' . esc_html( $icons[ $tone ] ) . '</span>' . esc_html( $label ) . '</span>';
	}

	/**
	 * Tone for a stored application status.
	 *
	 * @param string $status   Stored status.
	 * @param bool   $payable  Payment still collectible.
	 */
	public static function tone_for_status( string $status, bool $payable = false ): string {
		if ( $payable ) {
			return 'warning';
		}
		if ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
			return 'success';
		}
		if ( in_array( $status, array( 'rejected', 'cancelled' ), true ) ) {
			return 'danger';
		}
		if ( in_array( $status, array( 'info_requested', 'payment_required', 'approved' ), true ) ) {
			return 'warning';
		}
		return 'info';
	}

	/**
	 * Formatted money.
	 *
	 * @param float $amount Amount in dollars.
	 */
	public static function money( float $amount ): string {
		return function_exists( 'lnkup_currency' ) ? (string) lnkup_currency( $amount ) : '$' . number_format( $amount, 2 );
	}

	/**
	 * Event date as "Sat, May 29, 2027".
	 *
	 * @param string $date Y-m-d.
	 */
	public static function date( string $date ): string {
		$ts = $date ? strtotime( $date ) : false;
		return $ts ? (string) date_i18n( 'D, M j, Y', $ts ) : '';
	}

	/**
	 * Whole days until an event date, or null when past/unknown.
	 *
	 * @param string $date Y-m-d.
	 */
	public static function days_until( string $date ): ?int {
		if ( '' === $date ) {
			return null;
		}
		$today = strtotime( current_time( 'Y-m-d' ) );
		$ts    = strtotime( substr( $date, 0, 10 ) );
		if ( false === $ts || false === $today || $ts < $today ) {
			return null;
		}
		return (int) round( ( $ts - $today ) / DAY_IN_SECONDS );
	}

	/**
	 * Relative time ("3 hours ago") with the exact time in a title attribute.
	 *
	 * @param int $ts Unix timestamp (site-local, as from current_time( 'timestamp' )).
	 */
	public static function relative_time( int $ts ): string {
		if ( $ts <= 0 ) {
			return '';
		}
		$label = sprintf( /* translators: %s: human time difference */ __( '%s ago', 'lnkup-marketplace' ), human_time_diff( $ts, (int) current_time( 'timestamp' ) ) );
		return '<time datetime="' . esc_attr( gmdate( 'c', $ts ) ) . '" title="' . esc_attr( (string) date_i18n( 'M j, Y g:i a', $ts ) ) . '">' . esc_html( $label ) . '</time>';
	}

	/**
	 * Event image markup with the Event Media fallback chain.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $alt      Alt text.
	 * @param int    $opp_id   Opportunity post ID.
	 */
	public static function event_image( int $event_id, string $alt, int $opp_id = 0 ): string {
		if ( ! class_exists( 'LNKup_Event_Media' ) || ! function_exists( 'lnkup_event_media_html' ) ) {
			return '<span class="vos-media-fallback" aria-hidden="true">' . esc_html( self::initials( $alt ) ) . '</span>';
		}
		$media    = LNKup_Event_Media::resolve( $event_id, $opp_id );
		$fallback = ! empty( $media['uploaded'] ) ? LNKup_Event_Media::fallback( $event_id ) : array();
		$html     = lnkup_event_media_html( $media, $alt, array( 'fallback' => $fallback ) );
		return '' !== $html ? $html : '<span class="vos-media-fallback" aria-hidden="true">' . esc_html( self::initials( $alt ) ) . '</span>';
	}

	/**
	 * Two-letter initials.
	 *
	 * @param string $text Source text.
	 */
	public static function initials( string $text ): string {
		$out = '';
		foreach ( preg_split( '/\s+/', trim( $text ) ) ?: array() as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$out .= strtoupper( substr( $part, 0, 1 ) );
			if ( strlen( $out ) >= 2 ) {
				break;
			}
		}
		return '' !== $out ? $out : 'L';
	}

	/**
	 * Organizer public name for an event.
	 *
	 * @param int $event_id Event post ID.
	 */
	public static function organizer_name( int $event_id ): string {
		if ( ! $event_id ) {
			return '';
		}
		$uid  = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		$name = $uid ? (string) get_user_meta( $uid, '_lnkup_organizer_public_name', true ) : '';
		if ( '' === $name && $uid ) {
			$user = get_userdata( $uid );
			$name = $user ? (string) $user->display_name : '';
		}
		return function_exists( 'lnkup_plain_text' ) ? lnkup_plain_text( $name ) : $name;
	}

	/**
	 * Compact empty state.
	 *
	 * @param string $title     Title.
	 * @param string $desc      Description.
	 * @param string $cta_label CTA label.
	 * @param string $cta_href  CTA URL.
	 */
	public static function empty_state( string $title, string $desc = '', string $cta_label = '', string $cta_href = '' ): string {
		$html = '<div class="vos-empty"><p class="vos-empty__title">' . esc_html( $title ) . '</p>';
		if ( $desc ) {
			$html .= '<p class="vos-empty__desc">' . esc_html( $desc ) . '</p>';
		}
		if ( $cta_label && $cta_href ) {
			$html .= '<a class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" href="' . esc_url( $cta_href ) . '">' . esc_html( $cta_label ) . '</a>';
		}
		return $html . '</div>';
	}

	/**
	 * Progress bar with accessible value.
	 *
	 * @param int    $percent 0-100.
	 * @param string $label   Accessible label.
	 */
	public static function progress( int $percent, string $label ): string {
		$percent = max( 0, min( 100, $percent ) );
		return '<div class="vos-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) $percent ) . '" aria-label="' . esc_attr( $label ) . '"><span style="width:' . esc_attr( (string) $percent ) . '%"></span></div>';
	}

	/**
	 * Event identity: thumbnail, canonical title, date · venue · place, statuses.
	 *
	 * @param array{id:int,title:string,date_label?:string,venue?:string,place?:string,publication_label?:string,publication_tone?:string,chronology_label?:string,chronology_tone?:string} $summary Event summary from LNKup_Organizer_Presentation.
	 * @param array{size?:string,href?:string,status?:bool,heading?:string}                                                                                                                     $args    Options.
	 */
	public static function event_identity( array $summary, array $args = array() ): string {
		$size    = ( $args['size'] ?? 'sm' ) === 'lg' ? ' vos-event-id--lg' : '';
		$title   = (string) ( $summary['title'] ?? '' );
		$meta    = array_filter( array( (string) ( $summary['date_label'] ?? '' ), (string) ( $summary['venue'] ?? '' ), (string) ( $summary['place'] ?? '' ) ) );
		$tag     = in_array( $args['heading'] ?? '', array( 'h1', 'h2', 'h3' ), true ) ? $args['heading'] : 'p';
		$title_h = ! empty( $args['href'] )
			? '<a class="vos-event-id__title" href="' . esc_url( (string) $args['href'] ) . '">' . esc_html( $title ) . '</a>'
			: '<span class="vos-event-id__title">' . esc_html( $title ) . '</span>';
		$html  = '<div class="vos-event-id' . $size . '" data-event-id="' . esc_attr( (string) (int) ( $summary['id'] ?? 0 ) ) . '">';
		$html .= '<span class="vos-event-id__media">' . self::event_image( (int) ( $summary['id'] ?? 0 ), $title ) . '</span>';
		$html .= '<div class="vos-event-id__copy"><' . $tag . ' class="vos-event-id__heading" style="margin:0">' . $title_h . '</' . $tag . '>';
		if ( $meta ) {
			$html .= '<p class="vos-event-id__meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>';
		}
		if ( ! array_key_exists( 'status', $args ) || $args['status'] ) {
			$html .= '<div class="vos-event-id__status">';
			if ( ! empty( $summary['publication_label'] ) ) {
				$html .= self::status( (string) $summary['publication_label'], (string) ( $summary['publication_tone'] ?? 'neutral' ) );
			}
			if ( ! empty( $summary['chronology_label'] ) ) {
				$html .= self::status( (string) $summary['chronology_label'], (string) ( $summary['chronology_tone'] ?? 'neutral' ) );
			}
			$html .= '</div>';
		}
		return $html . '</div></div>';
	}

	/**
	 * Capacity bar: confirmed, held and open segments with a text legend.
	 *
	 * @param array{total:int,confirmed:int,held:int,open:int,waitlisted?:int} $capacity Capacity breakdown.
	 * @param string                                                         $label    Accessible label.
	 */
	public static function capacity_bar( array $capacity, string $label = '' ): string {
		$total     = max( 0, (int) ( $capacity['total'] ?? 0 ) );
		$confirmed = max( 0, (int) ( $capacity['confirmed'] ?? 0 ) );
		$held      = max( 0, (int) ( $capacity['held'] ?? 0 ) );
		$open      = max( 0, (int) ( $capacity['open'] ?? 0 ) );
		$wait      = max( 0, (int) ( $capacity['waitlisted'] ?? 0 ) );
		$base      = max( 1, $total, $confirmed + $held );
		$label     = '' !== $label ? $label : __( 'Vendor capacity', 'lnkup-marketplace' );
		$summary   = sprintf( /* translators: 1: confirmed, 2: held, 3: open, 4: total */ __( '%1$d confirmed, %2$d held, %3$d open of %4$d spaces', 'lnkup-marketplace' ), $confirmed, $held, $open, $total );
		$html      = '<div class="vos-capacity" data-testid="lnkup-capacity">';
		$html     .= '<div class="vos-capacity__bar" role="img" aria-label="' . esc_attr( $label . ': ' . $summary ) . '">';
		$html     .= '<span class="vos-capacity__seg vos-capacity__seg--confirmed" style="width:' . esc_attr( (string) round( 100 * $confirmed / $base, 2 ) ) . '%"></span>';
		$html     .= '<span class="vos-capacity__seg vos-capacity__seg--held" style="width:' . esc_attr( (string) round( 100 * $held / $base, 2 ) ) . '%"></span>';
		$html     .= '</div><ul class="vos-capacity__legend">';
		$html     .= '<li><span class="vos-capacity__key vos-capacity__key--confirmed" aria-hidden="true"></span>' . esc_html__( 'Confirmed', 'lnkup-marketplace' ) . ' <strong data-cap="confirmed">' . esc_html( (string) $confirmed ) . '</strong></li>';
		$html     .= '<li><span class="vos-capacity__key vos-capacity__key--held" aria-hidden="true"></span>' . esc_html__( 'Held (approved, unpaid)', 'lnkup-marketplace' ) . ' <strong data-cap="held">' . esc_html( (string) $held ) . '</strong></li>';
		$html     .= '<li><span class="vos-capacity__key vos-capacity__key--open" aria-hidden="true"></span>' . esc_html__( 'Open', 'lnkup-marketplace' ) . ' <strong data-cap="open">' . esc_html( (string) $open ) . '</strong></li>';
		if ( $wait > 0 ) {
			$html .= '<li>' . esc_html__( 'Waitlisted', 'lnkup-marketplace' ) . ' <strong data-cap="waitlisted">' . esc_html( (string) $wait ) . '</strong></li>';
		}
		return $html . '</ul></div>';
	}

	/**
	 * Data table with sticky header, optional sort links, row selection, bulk bar and a
	 * labeled card fallback under 768px.
	 *
	 * @param array<string,array{label:string,class?:string,sort?:string,lead?:bool}> $columns Column key => definition.
	 * @param array<int,array{cells:array<string,string>,id?:int|string,attrs?:string,label?:string}> $rows Rows; cell values are escaped HTML.
	 * @param array{caption?:string,testid?:string,id?:string,selectable?:bool,select_name?:string,bulk?:string,empty?:string,sort?:string,order?:string,base_url?:string,cards?:bool,footer?:string} $args Options. `bulk`, `empty` and `footer` are escaped HTML.
	 */
	public static function data_table( array $columns, array $rows, array $args = array() ): string {
		if ( ! $rows && isset( $args['empty'] ) ) {
			return (string) $args['empty'];
		}
		$selectable = ! empty( $args['selectable'] );
		$name       = (string) ( $args['select_name'] ?? 'ids[]' );
		$id         = (string) ( $args['id'] ?? '' );
		$cards      = ! array_key_exists( 'cards', $args ) || $args['cards'];
		$sort       = (string) ( $args['sort'] ?? '' );
		$order      = 'asc' === ( $args['order'] ?? '' ) ? 'asc' : 'desc';
		$base       = (string) ( $args['base_url'] ?? '' );
		$html       = '<div class="vos-table-wrap' . ( $cards ? ' vos-table--cards' : '' ) . '"' . ( $selectable ? ' data-vos-select' : '' ) . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ( ! empty( $args['testid'] ) ? ' data-testid="' . esc_attr( (string) $args['testid'] ) . '"' : '' ) . '>';
		$html      .= '<table class="vos-table">';
		if ( ! empty( $args['caption'] ) ) {
			$html .= '<caption class="vos-sr">' . esc_html( (string) $args['caption'] ) . '</caption>';
		}
		$html .= '<thead><tr>';
		if ( $selectable ) {
			$html .= '<th scope="col" class="vos-check"><input type="checkbox" data-vos-select-all aria-label="' . esc_attr__( 'Select all rows', 'lnkup-marketplace' ) . '"></th>';
		}
		foreach ( $columns as $key => $col ) {
			$class    = isset( $col['class'] ) ? ' class="' . esc_attr( (string) $col['class'] ) . '"' : '';
			$sort_key = (string) ( $col['sort'] ?? '' );
			if ( '' !== $sort_key && '' !== $base ) {
				$is_current = $sort_key === $sort;
				$next       = $is_current && 'asc' === $order ? 'desc' : 'asc';
				$aria       = $is_current ? ' aria-sort="' . ( 'asc' === $order ? 'ascending' : 'descending' ) . '"' : '';
				$href       = add_query_arg( array( 'sort' => $sort_key, 'order' => $next ), $base );
				$html      .= '<th scope="col"' . $class . $aria . '><a href="' . esc_url( $href ) . '">' . esc_html( (string) $col['label'] ) . '</a></th>';
			} else {
				$label = esc_html( (string) $col['label'] );
				$html .= '<th scope="col"' . $class . '>' . ( ! empty( $col['sr_only'] ) ? '<span class="vos-sr">' . $label . '</span>' : $label ) . '</th>';
			}
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$row_id = isset( $row['id'] ) ? (string) $row['id'] : '';
			$html  .= '<tr' . ( '' !== $row_id ? ' data-row-id="' . esc_attr( $row_id ) . '"' : '' ) . ( isset( $row['attrs'] ) ? ' ' . $row['attrs'] : '' ) . '>';
			if ( $selectable ) {
				$label = (string) ( $row['label'] ?? $row_id );
				$html .= '<td class="vos-check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="' . esc_attr( $row_id ) . '" data-vos-select-row aria-label="' . esc_attr( sprintf( /* translators: %s: row label */ __( 'Select %s', 'lnkup-marketplace' ), $label ) ) . '"></td>';
			}
			foreach ( $columns as $key => $col ) {
				$classes = array();
				if ( ! empty( $col['class'] ) ) {
					$classes[] = (string) $col['class'];
				}
				if ( ! empty( $col['lead'] ) ) {
					$classes[] = 'vos-cell-lead';
				}
				$html .= '<td' . ( $classes ? ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' : '' ) . ' data-label="' . esc_attr( (string) $col['label'] ) . '">' . ( $row['cells'][ $key ] ?? '' ) . '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table></div>';
		if ( $selectable && ! empty( $args['bulk'] ) ) {
			$html .= '<div class="vos-bulk-bar" data-vos-bulk-bar hidden role="region" aria-label="' . esc_attr__( 'Bulk actions', 'lnkup-marketplace' ) . '"><span class="vos-bulk-bar__count" data-vos-bulk-count aria-live="polite"></span>' . $args['bulk'] . '</div>';
		}
		if ( ! empty( $args['footer'] ) ) {
			$html .= '<div class="vos-table-foot">' . $args['footer'] . '</div>';
		}
		return $html;
	}

	/**
	 * Right-side drawer shell, opened by `LNKupOS.drawer.open( id )` or `[data-vos-open="id"]`.
	 *
	 * @param string $id    Element id.
	 * @param string $title Heading.
	 * @param string $body  Escaped HTML.
	 * @param string $foot  Escaped HTML.
	 * @param array{wide?:bool,testid?:string} $args Options.
	 */
	public static function drawer_shell( string $id, string $title, string $body, string $foot = '', array $args = array() ): string {
		$title_id = $id . '-title';
		$html     = '<div class="vos-drawer' . ( ! empty( $args['wide'] ) ? ' vos-drawer--wide' : '' ) . '" id="' . esc_attr( $id ) . '" hidden data-vos-overlay="drawer"' . ( ! empty( $args['testid'] ) ? ' data-testid="' . esc_attr( (string) $args['testid'] ) . '"' : '' ) . '>';
		$html    .= '<div class="vos-drawer__backdrop" data-vos-close></div>';
		$html    .= '<section class="vos-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="' . esc_attr( $title_id ) . '">';
		$html    .= '<header class="vos-drawer__head"><h2 id="' . esc_attr( $title_id ) . '">' . esc_html( $title ) . '</h2><button type="button" class="vos-drawer__close" data-vos-close aria-label="' . esc_attr__( 'Close', 'lnkup-marketplace' ) . '">×</button></header>';
		$html    .= '<div class="vos-drawer__body">' . $body . '</div>';
		if ( '' !== $foot ) {
			$html .= '<footer class="vos-drawer__foot">' . $foot . '</footer>';
		}
		return $html . '</section></div>';
	}

	/**
	 * Viewport-centered dialog shell, opened by `LNKupOS.dialog.open( id )` or `[data-vos-open="id"]`.
	 *
	 * @param string $id    Element id.
	 * @param string $title Heading.
	 * @param string $body  Escaped HTML.
	 * @param string $foot  Escaped HTML.
	 * @param array{testid?:string} $args Options.
	 */
	public static function dialog_shell( string $id, string $title, string $body, string $foot = '', array $args = array() ): string {
		$title_id = $id . '-title';
		$html     = '<div class="vos-dialog" id="' . esc_attr( $id ) . '" hidden data-vos-overlay="dialog"' . ( ! empty( $args['testid'] ) ? ' data-testid="' . esc_attr( (string) $args['testid'] ) . '"' : '' ) . '>';
		$html    .= '<div class="vos-dialog__backdrop" data-vos-close></div>';
		$html    .= '<section class="vos-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="' . esc_attr( $title_id ) . '">';
		$html    .= '<header class="vos-dialog__head"><h2 id="' . esc_attr( $title_id ) . '">' . esc_html( $title ) . '</h2><button type="button" class="vos-dialog__close" data-vos-close aria-label="' . esc_attr__( 'Close', 'lnkup-marketplace' ) . '">×</button></header>';
		$html    .= '<div class="vos-dialog__body">' . $body . '</div>';
		if ( '' !== $foot ) {
			$html .= '<footer class="vos-dialog__foot">' . $foot . '</footer>';
		}
		return $html . '</section></div>';
	}

	/**
	 * Disclosure menu.
	 *
	 * @param string $label Summary label.
	 * @param array<int,array{label:string,href?:string,hint?:string,attrs?:string}> $items Items; `attrs` is escaped attribute HTML for button items.
	 * @param array{class?:string,testid?:string,aria_label?:string} $args Options.
	 */
	public static function menu( string $label, array $items, array $args = array() ): string {
		$summary_class = (string) ( $args['class'] ?? 'lnkup-btn lnkup-btn--outline lnkup-btn--sm' );
		$html          = '<details class="vos-menu"' . ( ! empty( $args['testid'] ) ? ' data-testid="' . esc_attr( (string) $args['testid'] ) . '"' : '' ) . '>';
		$html         .= '<summary class="' . esc_attr( $summary_class ) . '"' . ( ! empty( $args['aria_label'] ) ? ' aria-label="' . esc_attr( (string) $args['aria_label'] ) . '"' : '' ) . '>' . esc_html( $label ) . '</summary><div class="vos-menu__panel">';
		foreach ( $items as $item ) {
			$inner = esc_html( (string) $item['label'] ) . ( ! empty( $item['hint'] ) ? '<span class="vos-menu__hint">' . esc_html( (string) $item['hint'] ) . '</span>' : '' );
			if ( ! empty( $item['href'] ) ) {
				$html .= '<a class="vos-menu__item" href="' . esc_url( (string) $item['href'] ) . '">' . $inner . '</a>';
			} else {
				$html .= '<button type="button" class="vos-menu__item" ' . ( $item['attrs'] ?? '' ) . '>' . $inner . '</button>';
			}
		}
		return $html . '</div></details>';
	}
}

LNKup_OS_UI::init();
