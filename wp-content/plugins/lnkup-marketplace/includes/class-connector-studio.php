<?php
/**
 * City Connector Content Studio (templates + submissions).
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Connector_Studio {

	const TEMPLATE_CPT = 'lnkup_cn_template';
	const POST_CPT     = 'lnkup_cn_post';
	const SEED_OPTION  = 'lnkup_connector_templates_seeded';

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_types' ), 6 );
		add_action( 'init', array( __CLASS__, 'maybe_seed' ), 20 );
	}

	/**
	 * Register private CPTs.
	 */
	public static function register_types() {
		register_post_type(
			self::TEMPLATE_CPT,
			array(
				'label'               => __( 'Connector templates', 'lnkup-marketplace' ),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'supports'            => array( 'title', 'editor' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
		register_post_type(
			self::POST_CPT,
			array(
				'label'               => __( 'Connector posts', 'lnkup-marketplace' ),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'supports'            => array( 'title', 'author' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Allowed social hosts.
	 */
	public static function allowed_hosts() {
		return array(
			'instagram.com',
			'www.instagram.com',
			'tiktok.com',
			'www.tiktok.com',
			'vm.tiktok.com',
			'facebook.com',
			'www.facebook.com',
			'fb.watch',
			'linkedin.com',
			'www.linkedin.com',
			'lnkd.in',
			'youtube.com',
			'www.youtube.com',
			'youtu.be',
			'm.facebook.com',
		);
	}

	/**
	 * Validate a published social URL.
	 *
	 * @param string $url URL.
	 */
	public static function validate_social_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( ! $url ) {
			return new WP_Error( 'invalid_url', __( 'Enter a published video URL.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$host = $host ? strtolower( $host ) : '';
		if ( ! in_array( $host, self::allowed_hosts(), true ) ) {
			return new WP_Error( 'invalid_host', __( 'Use an Instagram, TikTok, LinkedIn, Facebook, or YouTube URL.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return $url;
	}

	/**
	 * Categories.
	 */
	public static function categories() {
		return array(
			'recruit_organizers'   => __( 'Recruit organizers', 'lnkup-marketplace' ),
			'recruit_vendors'      => __( 'Recruit vendors', 'lnkup-marketplace' ),
			'explain_lnkup'        => __( 'Explain LNKup', 'lnkup-marketplace' ),
			'local_opportunities'  => __( 'Promote local opportunities', 'lnkup-marketplace' ),
			'city_roundup'         => __( 'City event roundup', 'lnkup-marketplace' ),
			'vendor_success'       => __( 'Vendor success story', 'lnkup-marketplace' ),
			'organizer_pain'       => __( 'Organizer pain-point video', 'lnkup-marketplace' ),
			'earning_opportunity'  => __( 'Connector earning opportunity', 'lnkup-marketplace' ),
			'behind_scenes'        => __( 'Behind the scenes', 'lnkup-marketplace' ),
			'local_spotlight'      => __( 'Local business spotlight', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Seed default templates once.
	 */
	public static function maybe_seed() {
		if ( get_option( self::SEED_OPTION ) ) {
			return;
		}
		foreach ( self::seed_definitions() as $def ) {
			$existing = get_posts(
				array(
					'post_type'      => self::TEMPLATE_CPT,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_lnkup_seed_key',
					'meta_value'     => $def['seed_key'],
				)
			);
			if ( $existing ) {
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => self::TEMPLATE_CPT,
					'post_status'  => 'publish',
					'post_title'   => $def['title'],
					'post_content' => $def['script'],
				)
			);
			if ( is_wp_error( $id ) || ! $id ) {
				continue;
			}
			foreach ( $def as $key => $value ) {
				if ( 'title' === $key || 'script' === $key ) {
					continue;
				}
				if ( is_array( $value ) ) {
					$value = wp_json_encode( $value );
				}
				update_post_meta( $id, '_lnkup_' . $key, $value );
			}
		}
		update_option( self::SEED_OPTION, LNKUP_VERSION, false );
	}

	/**
	 * Default video + story templates.
	 */
	public static function seed_definitions() {
		$disclosure = __( 'Earnings are performance-based and not guaranteed. LNKup does not pay for posting a video.', 'lnkup-marketplace' );
		return array(
			array(
				'seed_key'     => 'vendor_problem',
				'title'        => __( 'Vendor problem', 'lnkup-marketplace' ),
				'objective'    => __( 'Show vendors why scattered event hunting fails.', 'lnkup-marketplace' ),
				'audience'     => 'vendor',
				'platforms'    => array( 'instagram_reels', 'tiktok', 'facebook_reels' ),
				'duration'     => '30s',
				'format'       => 'problem',
				'category'     => 'recruit_vendors',
				'growth_styles'=> array( 'video', 'relationship' ),
				'featured'     => 1,
				'active'       => 1,
				'hook'         => __( 'If you’re a vendor and you keep finding events after applications close, this is for you.', 'lnkup-marketplace' ),
				'script'       => __( "If you’re a vendor and you keep finding events after applications close, this is for you.\nVendor opportunities are scattered across social media.\nYou keep filling out different forms for every organizer.\nLNKup puts legitimate booth opportunities in one marketplace.\nYou can apply, track acceptance, and manage payments in one place.\nJoin LNKup through my link and start finding opportunities in your city.", 'lnkup-marketplace' ),
				'shots'        => array(
					__( '0–2s: face to camera, hook on screen', 'lnkup-marketplace' ),
					__( '2–12s: cut B-roll of a market or closed application post', 'lnkup-marketplace' ),
					__( '12–25s: talking head — one marketplace', 'lnkup-marketplace' ),
					__( '25–30s: CTA + referral link reminder', 'lnkup-marketplace' ),
				),
				'broll'        => __( 'Vendor booths, application forms, phone scrolling Instagram events.', 'lnkup-marketplace' ),
				'onscreen'     => __( 'Stop missing booth deadlines', 'lnkup-marketplace' ),
				'caption'      => __( 'Vendors: stop hunting events after they close. LNKup lists real booth opportunities in one place. Link in bio. Not financial advice — earnings are not guaranteed.', 'lnkup-marketplace' ),
				'cta'          => __( 'Join LNKup through my link and start finding opportunities in your city.', 'lnkup-marketplace' ),
				'cover'        => __( 'Vendors: events close before you apply', 'lnkup-marketplace' ),
				'disclosure'   => $disclosure,
				'story_frames' => array(
					__( 'You find the event after apps close.', 'lnkup-marketplace' ),
					__( 'LNKup lists booth opportunities in one place.', 'lnkup-marketplace' ),
					__( 'Tap the link — create your vendor profile.', 'lnkup-marketplace' ),
				),
			),
			array(
				'seed_key'     => 'organizer_problem',
				'title'        => __( 'Organizer problem', 'lnkup-marketplace' ),
				'objective'    => __( 'Show organizers a better vendor workflow.', 'lnkup-marketplace' ),
				'audience'     => 'organizer',
				'platforms'    => array( 'instagram_reels', 'facebook_reels', 'tiktok' ),
				'duration'     => '35s',
				'format'       => 'problem',
				'category'     => 'organizer_pain',
				'growth_styles'=> array( 'video', 'relationship', 'community' ),
				'featured'     => 1,
				'active'       => 1,
				'hook'         => __( 'If you organize events and your DMs are full of vendors asking the same questions, listen to this.', 'lnkup-marketplace' ),
				'script'       => __( "If you organize events and your DMs are full of vendors asking the same questions, listen to this.\nYou can publish a vendor opportunity.\nReview applicants.\nCollect documents.\nApprove vendors.\nTrack payments.\nManage it in one place.\nYour ticketing platform can stay unchanged.\nUse my link to create your organizer account and open your first opportunity.", 'lnkup-marketplace' ),
				'shots'        => array(
					__( 'Hook on camera', 'lnkup-marketplace' ),
					__( 'Screen or stills of an inbox full of vendor DMs', 'lnkup-marketplace' ),
					__( 'Walk through: publish, review, docs, pay', 'lnkup-marketplace' ),
					__( 'CTA', 'lnkup-marketplace' ),
				),
				'broll'        => __( 'Event setup, vendor row, phone inbox.', 'lnkup-marketplace' ),
				'onscreen'     => __( 'Stop managing vendors in DMs', 'lnkup-marketplace' ),
				'caption'      => __( 'Organizers: publish one opportunity instead of answering the same vendor questions all week. Ticketing can stay where it is. Link in bio.', 'lnkup-marketplace' ),
				'cta'          => __( 'Use my link to create your organizer account and open your first opportunity.', 'lnkup-marketplace' ),
				'cover'        => __( 'Vendor DMs overflowing?', 'lnkup-marketplace' ),
				'disclosure'   => $disclosure,
				'story_frames' => array(
					__( 'Same vendor questions, every week.', 'lnkup-marketplace' ),
					__( 'Publish one LNKup opportunity.', 'lnkup-marketplace' ),
					__( 'Open your organizer account — link.', 'lnkup-marketplace' ),
				),
			),
			array(
				'seed_key'     => 'connector_opportunity',
				'title'        => __( 'City Connector opportunity', 'lnkup-marketplace' ),
				'objective'    => __( 'Recruit other connectors without promising income.', 'lnkup-marketplace' ),
				'audience'     => 'connector',
				'platforms'    => array( 'instagram_reels', 'tiktok' ),
				'duration'     => '30s',
				'format'       => 'talking_head',
				'category'     => 'earning_opportunity',
				'growth_styles'=> array( 'video', 'community' ),
				'featured'     => 1,
				'active'       => 1,
				'hook'         => __( 'What if the event relationships you already have could create recurring income?', 'lnkup-marketplace' ),
				'script'       => __( "What if the event relationships you already have could create recurring income?\nLNKup is selecting Founding City Connectors.\nYou introduce organizers and vendors.\nQualifying platform activity is tracked.\nYou can earn from qualifying booth revenue.\nEarnings are performance-based and not guaranteed.\nApply to build LNKup in your city.", 'lnkup-marketplace' ),
				'shots'        => array(
					__( 'Talking head hook', 'lnkup-marketplace' ),
					__( 'B-roll of you at a local event', 'lnkup-marketplace' ),
					__( 'Honest earnings disclaimer on screen', 'lnkup-marketplace' ),
					__( 'Apply CTA', 'lnkup-marketplace' ),
				),
				'broll'        => __( 'Local events, handshakes, city skyline.', 'lnkup-marketplace' ),
				'onscreen'     => __( 'Performance-based. Not guaranteed.', 'lnkup-marketplace' ),
				'caption'      => __( 'Founding City Connectors introduce organizers and vendors. Pay is a share of qualifying booth activity — not a salary. Apply through my link.', 'lnkup-marketplace' ),
				'cta'          => __( 'Apply to build LNKup in your city.', 'lnkup-marketplace' ),
				'cover'        => __( 'Event relationships → tracked activity', 'lnkup-marketplace' ),
				'disclosure'   => $disclosure,
				'story_frames' => array(
					__( 'You already know organizers.', 'lnkup-marketplace' ),
					__( 'LNKup tracks qualifying booth activity.', 'lnkup-marketplace' ),
					__( 'Apply — no guaranteed income.', 'lnkup-marketplace' ),
				),
			),
			array(
				'seed_key'     => 'city_roundup',
				'title'        => __( 'Local opportunity roundup', 'lnkup-marketplace' ),
				'objective'    => __( 'Highlight event types vendors should watch this month.', 'lnkup-marketplace' ),
				'audience'     => 'vendor',
				'platforms'    => array( 'instagram_reels', 'tiktok', 'facebook_reels', 'youtube_shorts' ),
				'duration'     => '40s',
				'format'       => 'roundup',
				'category'     => 'city_roundup',
				'growth_styles'=> array( 'video', 'event', 'community' ),
				'featured'     => 1,
				'active'       => 1,
				'hook'         => __( 'Here are three types of event opportunities local vendors should be looking for this month.', 'lnkup-marketplace' ),
				'script'       => __( "Here are three types of event opportunities local vendors should be looking for this month.\nPop-up markets.\nFestivals.\nFood-truck events.\nAlso watch campus events and community celebrations.\nUse my LNKup link to create your vendor profile and find opportunities.", 'lnkup-marketplace' ),
				'shots'        => array(
					__( 'Hook', 'lnkup-marketplace' ),
					__( 'Card 1 pop-ups', 'lnkup-marketplace' ),
					__( 'Card 2 festivals', 'lnkup-marketplace' ),
					__( 'Card 3 food trucks', 'lnkup-marketplace' ),
					__( 'CTA', 'lnkup-marketplace' ),
				),
				'broll'        => __( 'Markets, festival gates, food trucks, campus quads.', 'lnkup-marketplace' ),
				'onscreen'     => __( 'This month in {city}', 'lnkup-marketplace' ),
				'caption'      => __( 'Vendors in {city}: pop-ups, festivals, food-truck events. Create your LNKup profile and apply in one place. Link in bio.', 'lnkup-marketplace' ),
				'cta'          => __( 'Use my LNKup link to create your vendor profile and find opportunities.', 'lnkup-marketplace' ),
				'cover'        => __( '3 event types to watch', 'lnkup-marketplace' ),
				'disclosure'   => $disclosure,
				'story_frames' => array(
					__( 'Vendors: this month’s event types.', 'lnkup-marketplace' ),
					__( 'Markets, festivals, food trucks.', 'lnkup-marketplace' ),
					__( 'Create your vendor profile — link.', 'lnkup-marketplace' ),
				),
			),
			array(
				'seed_key'     => 'behind_platform',
				'title'        => __( 'Behind the platform', 'lnkup-marketplace' ),
				'objective'    => __( 'Screen-record the organizer workflow.', 'lnkup-marketplace' ),
				'audience'     => 'organizer',
				'platforms'    => array( 'instagram_reels', 'youtube_shorts', 'facebook_reels' ),
				'duration'     => '45s',
				'format'       => 'screen',
				'category'     => 'behind_scenes',
				'growth_styles'=> array( 'video' ),
				'featured'     => 0,
				'active'       => 1,
				'hook'         => __( 'Here’s how organizers can stop managing vendors through DMs and spreadsheets.', 'lnkup-marketplace' ),
				'script'       => __( "Here’s how organizers can stop managing vendors through DMs and spreadsheets.\nThis is the organizer dashboard.\nCreate an opportunity.\nReview applications.\nApprove vendors.\nTrack payments.\nCreate your organizer account through my link.", 'lnkup-marketplace' ),
				'shots'        => array(
					__( 'Hook over screen recording', 'lnkup-marketplace' ),
					__( 'Dashboard overview', 'lnkup-marketplace' ),
					__( 'Opportunity create', 'lnkup-marketplace' ),
					__( 'Applications + approvals', 'lnkup-marketplace' ),
					__( 'CTA', 'lnkup-marketplace' ),
				),
				'broll'        => __( 'Clean screen recording of the organizer dashboard only. No private vendor data.', 'lnkup-marketplace' ),
				'onscreen'     => __( 'One dashboard for vendors', 'lnkup-marketplace' ),
				'caption'      => __( 'A quick look at how organizers publish opportunities and review vendors on LNKup. Create your account through my link.', 'lnkup-marketplace' ),
				'cta'          => __( 'Create your organizer account through my link.', 'lnkup-marketplace' ),
				'cover'        => __( 'DMs → one dashboard', 'lnkup-marketplace' ),
				'disclosure'   => $disclosure,
				'story_frames' => array(
					__( 'Spreadsheets for vendors?', 'lnkup-marketplace' ),
					__( 'Publish, review, approve, track.', 'lnkup-marketplace' ),
					__( 'Organizer signup — link.', 'lnkup-marketplace' ),
				),
			),
			array(
				'seed_key'     => 'story_sequence',
				'title'        => __( 'Three-frame Story', 'lnkup-marketplace' ),
				'objective'    => __( 'Story sequence with a link sticker.', 'lnkup-marketplace' ),
				'audience'     => 'both',
				'platforms'    => array( 'instagram_stories', 'facebook_stories' ),
				'duration'     => '15s',
				'format'       => 'talking_head',
				'category'     => 'explain_lnkup',
				'growth_styles'=> array( 'video', 'community' ),
				'featured'     => 1,
				'active'       => 1,
				'hook'         => __( 'Vendors and organizers in {city} — one marketplace for booths.', 'lnkup-marketplace' ),
				'script'       => __( "Frame 1: Finding booths (or vendors) is a mess.\nFrame 2: LNKup lists opportunities and applications in one place.\nFrame 3: Tap the link sticker to join through my referral.", 'lnkup-marketplace' ),
				'shots'        => array(
					__( 'Frame 1 problem', 'lnkup-marketplace' ),
					__( 'Frame 2 product', 'lnkup-marketplace' ),
					__( 'Frame 3 link sticker', 'lnkup-marketplace' ),
				),
				'broll'        => __( 'City clip or booth photo.', 'lnkup-marketplace' ),
				'onscreen'     => __( 'Tap the link', 'lnkup-marketplace' ),
				'caption'      => __( 'Story sequence — add your referral link sticker on frame 3.', 'lnkup-marketplace' ),
				'cta'          => __( 'Tap the link to join LNKup.', 'lnkup-marketplace' ),
				'cover'        => __( 'LNKup in {city}', 'lnkup-marketplace' ),
				'disclosure'   => $disclosure,
				'story_frames' => array(
					__( 'Problem or opportunity in your city.', 'lnkup-marketplace' ),
					__( 'What LNKup does.', 'lnkup-marketplace' ),
					__( 'Referral-link CTA.', 'lnkup-marketplace' ),
				),
			),
		);
	}

	/**
	 * Read template meta into an array.
	 *
	 * @param int $id Post ID.
	 */
	public static function template_row( $id ) {
		$post = get_post( $id );
		if ( ! $post || self::TEMPLATE_CPT !== $post->post_type ) {
			return null;
		}
		$json_keys = array( 'platforms', 'shots', 'growth_styles', 'story_frames' );
		$row       = array(
			'id'    => (int) $post->ID,
			'title' => get_the_title( $post ),
			'script'=> $post->post_content,
		);
		$map = array(
			'seed_key', 'objective', 'audience', 'platforms', 'duration', 'format', 'category',
			'growth_styles', 'featured', 'active', 'hook', 'shots', 'broll', 'onscreen',
			'caption', 'cta', 'cover', 'disclosure', 'story_frames', 'city', 'campaign_dates',
		);
		foreach ( $map as $key ) {
			$val = get_post_meta( $post->ID, '_lnkup_' . $key, true );
			if ( in_array( $key, $json_keys, true ) && is_string( $val ) && $val && '{' !== $val[0] && '[' === $val[0] ) {
				$decoded = json_decode( $val, true );
				$val     = is_array( $decoded ) ? $decoded : $val;
			} elseif ( in_array( $key, $json_keys, true ) && is_string( $val ) ) {
				$decoded = json_decode( $val, true );
				if ( is_array( $decoded ) ) {
					$val = $decoded;
				}
			}
			$row[ $key ] = $val;
		}
		$row['featured'] = ! empty( $row['featured'] );
		$row['active']   = ! isset( $row['active'] ) || '' === $row['active'] || ! empty( $row['active'] );
		return $row;
	}

	/**
	 * Active templates.
	 */
	public static function templates() {
		$ids = get_posts(
			array(
				'post_type'      => self::TEMPLATE_CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$row = self::template_row( $id );
			if ( $row && ! empty( $row['active'] ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Inject city + referral URLs into template copy.
	 *
	 * @param array $template  Template.
	 * @param array $connector Connector row.
	 */
	public static function hydrate_template( $template, $connector ) {
		$city  = (string) ( $connector['city'] ?? '' ) ?: __( 'your city', 'lnkup-marketplace' );
		$links = LNKup_Connector::build_referral_links( $connector, 't' . (int) $template['id'] );
		$repl  = array(
			'{city}'      => $city,
			'{organizer}' => $links['organizer'] ?? '',
			'{vendor}'    => $links['vendor'] ?? '',
		);
		foreach ( array( 'hook', 'script', 'caption', 'cta', 'cover', 'onscreen', 'objective' ) as $field ) {
			if ( ! empty( $template[ $field ] ) && is_string( $template[ $field ] ) ) {
				$template[ $field ] = strtr( $template[ $field ], $repl );
			}
		}
		$template['links']     = $links;
		$template['checklist'] = array(
			__( 'Film vertical 9:16.', 'lnkup-marketplace' ),
			__( 'Hook in the first two seconds.', 'lnkup-marketplace' ),
			__( 'Add on-screen captions.', 'lnkup-marketplace' ),
			__( 'Put the referral link in bio, Story sticker, or pinned comment.', 'lnkup-marketplace' ),
			__( 'Do not promise income.', 'lnkup-marketplace' ),
			__( 'Submit the published URL here after you post.', 'lnkup-marketplace' ),
		);
		$template['platform_tips'] = array(
			'instagram_reels' => __( 'Strong hook in 2 seconds. Cover text. Matching Story with a link.', 'lnkup-marketplace' ),
			'tiktok'          => __( 'Conversational. Local problem first. Pin a comment with the link.', 'lnkup-marketplace' ),
			'facebook_reels'  => __( 'Community tone. Share into relevant groups with a landing CTA.', 'lnkup-marketplace' ),
			'stories'         => __( 'Three frames + link sticker wording.', 'lnkup-marketplace' ),
		);
		return $template;
	}

	/**
	 * Weekly mission = featured template matching styles.
	 *
	 * @param array $connector Connector row.
	 */
	public static function mission( $connector ) {
		$styles = LNKup_Connector::growth_styles_for( $connector );
		$best   = null;
		foreach ( self::templates() as $tpl ) {
			if ( empty( $tpl['featured'] ) ) {
				continue;
			}
			$tpl_styles = is_array( $tpl['growth_styles'] ) ? $tpl['growth_styles'] : array();
			if ( $styles && $tpl_styles && ! array_intersect( $styles, $tpl_styles ) ) {
				continue;
			}
			$best = $tpl;
			break;
		}
		if ( ! $best ) {
			$all = self::templates();
			$best = $all ? $all[0] : null;
		}
		if ( ! $best ) {
			return null;
		}
		$hydrated = self::hydrate_template( $best, $connector );
		return array(
			'title'       => __( 'This Week’s Mission', 'lnkup-marketplace' ),
			'template_id' => $hydrated['id'],
			'platform'    => is_array( $hydrated['platforms'] ) ? ( $hydrated['platforms'][0] ?? '' ) : '',
			'audience'    => $hydrated['audience'],
			'duration'    => $hydrated['duration'],
			'hook'        => $hydrated['hook'],
			'format'      => $hydrated['format'],
			'cta'         => $hydrated['cta'],
			'body'        => $hydrated['hook'],
		);
	}

	/**
	 * Studio homepage payload.
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function dashboard( $connector_id ) {
		$connector = LNKup_Connector::get( $connector_id );
		if ( ! $connector ) {
			return new WP_Error( 'not_found', __( 'Connector not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$on   = LNKup_Connector::get_onboarding( $connector_id );
		$tpls = array();
		foreach ( self::templates() as $tpl ) {
			$tpls[] = array(
				'id'       => $tpl['id'],
				'title'    => $tpl['title'],
				'hook'     => $tpl['hook'],
				'category' => $tpl['category'],
				'format'   => $tpl['format'],
				'audience' => $tpl['audience'],
				'duration' => $tpl['duration'],
				'platforms'=> $tpl['platforms'],
				'featured' => $tpl['featured'],
			);
		}
		return array(
			'mission'         => self::mission( $connector ),
			'recommendations' => array_slice( $tpls, 0, 4 ),
			'categories'      => self::categories(),
			'templates'       => $tpls,
			'posts'           => self::list_posts( $connector ),
			'growth_styles'   => LNKup_Connector::growth_styles_for( $connector ),
			'city'            => $connector['city'] ?? '',
			'onboarding'      => array(
				'phase'    => $on['current_phase'] ?? 1,
				'progress' => $on['progress'] ?? 0,
			),
			'disclaimer'      => __( 'Conversions use your referral attribution. A published video does not create commission by itself.', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Full builder payload.
	 *
	 * @param int $connector_id Connector ID.
	 * @param int $template_id  Template ID.
	 */
	public static function get_template( $connector_id, $template_id ) {
		$connector = LNKup_Connector::get( $connector_id );
		$row       = self::template_row( $template_id );
		if ( ! $connector || ! $row ) {
			return new WP_Error( 'not_found', __( 'Template not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return self::hydrate_template( $row, $connector );
	}

	/**
	 * List the connector's content submissions.
	 *
	 * @param array $connector Connector row.
	 */
	public static function list_posts( $connector ) {
		$user_id = (int) ( $connector['user_id'] ?? 0 );
		$ids     = get_posts(
			array(
				'post_type'      => self::POST_CPT,
				'post_status'    => 'any',
				'author'         => $user_id,
				'posts_per_page' => 50,
				'fields'         => 'ids',
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$out[] = self::post_row( $id, $connector );
		}
		return $out;
	}

	/**
	 * Submission DTO.
	 *
	 * @param int   $id        Post ID.
	 * @param array $connector Connector row.
	 */
	public static function post_row( $id, $connector ) {
		$cc     = 'p' . absint( $id );
		$clicks = self::count_content_clicks( (int) $connector['id'], $cc );
		return array(
			'id'            => (int) $id,
			'title'         => get_the_title( $id ),
			'status'        => (string) get_post_meta( $id, '_lnkup_content_status', true ) ?: 'draft',
			'platform'      => (string) get_post_meta( $id, '_lnkup_platform', true ),
			'url'           => (string) get_post_meta( $id, '_lnkup_published_url', true ),
			'published_at'  => (string) get_post_meta( $id, '_lnkup_published_at', true ),
			'audience'      => (string) get_post_meta( $id, '_lnkup_audience', true ),
			'city'          => (string) get_post_meta( $id, '_lnkup_city', true ),
			'campaign'      => (string) get_post_meta( $id, '_lnkup_campaign', true ),
			'content_type'  => (string) get_post_meta( $id, '_lnkup_content_type', true ),
			'manual_views'  => (int) get_post_meta( $id, '_lnkup_manual_views', true ),
			'template_id'   => (int) get_post_meta( $id, '_lnkup_template_id', true ),
			'clicks'        => $clicks,
			'reminder_at'   => (string) get_post_meta( $id, '_lnkup_reminder_at', true ),
		);
	}

	/**
	 * Count content_click events for a cc key.
	 *
	 * @param int    $connector_id Connector ID.
	 * @param string $cc           Tag.
	 */
	public static function count_content_clicks( $connector_id, $cc ) {
		global $wpdb;
		$table = LNKup_Connector_Tables::events_table();
		$like  = '%' . $wpdb->esc_like( sanitize_key( $cc ) ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE connector_id = %d AND event_type = 'content_click' AND metadata LIKE %s",
				absint( $connector_id ),
				$like
			)
		);
	}

	/**
	 * Create or update a submission (idempotent on URL).
	 *
	 * @param int   $connector_id Connector ID.
	 * @param array $input        Fields.
	 */
	public static function save_post( $connector_id, $input ) {
		$connector = LNKup_Connector::get( $connector_id );
		if ( ! $connector ) {
			return new WP_Error( 'not_found', __( 'Connector not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$user_id = (int) ( $connector['user_id'] ?? 0 );
		$status  = sanitize_key( (string) ( $input['status'] ?? 'planned' ) );
		if ( ! in_array( $status, array( 'draft', 'planned', 'published', 'needs_review', 'archived' ), true ) ) {
			$status = 'planned';
		}
		$url = '';
		if ( ! empty( $input['url'] ) ) {
			$valid = self::validate_social_url( (string) $input['url'] );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$url = $valid;
		}
		if ( 'published' === $status && ! $url ) {
			return new WP_Error( 'invalid_url', __( 'A published video needs a public URL.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$existing_id = absint( $input['id'] ?? 0 );
		if ( $url ) {
			$key = $connector_id . ':' . md5( strtolower( $url ) );
			$found = get_posts(
				array(
					'post_type'      => self::POST_CPT,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_lnkup_idempotency_key',
					'meta_value'     => $key,
				)
			);
			if ( $found ) {
				$existing_id = (int) $found[0];
			}
		}
		if ( $existing_id ) {
			$existing = get_post( $existing_id );
			if ( ! $existing || self::POST_CPT !== $existing->post_type || (int) $existing->post_author !== $user_id ) {
				if ( ! LNKup_Capabilities::user_has_manage_all() ) {
					return new WP_Error( 'forbidden', __( 'You can only edit your own content.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
				}
			}
			wp_update_post(
				array(
					'ID'         => $existing_id,
					'post_title' => sanitize_text_field( (string) ( $input['title'] ?? $existing->post_title ) ),
				)
			);
			$id = $existing_id;
		} else {
			$id = wp_insert_post(
				array(
					'post_type'   => self::POST_CPT,
					'post_status' => 'publish',
					'post_author' => $user_id,
					'post_title'  => sanitize_text_field( (string) ( $input['title'] ?? __( 'Studio post', 'lnkup-marketplace' ) ) ),
				)
			);
			if ( is_wp_error( $id ) || ! $id ) {
				return new WP_Error( 'save_failed', __( 'Could not save that post.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
			}
		}
		$fields = array(
			'_lnkup_content_status' => $status,
			'_lnkup_platform'       => sanitize_key( (string) ( $input['platform'] ?? '' ) ),
			'_lnkup_audience'       => sanitize_key( (string) ( $input['audience'] ?? '' ) ),
			'_lnkup_city'           => sanitize_text_field( (string) ( $input['city'] ?? $connector['city'] ?? '' ) ),
			'_lnkup_campaign'       => sanitize_text_field( (string) ( $input['campaign'] ?? '' ) ),
			'_lnkup_content_type'   => sanitize_key( (string) ( $input['content_type'] ?? '' ) ),
			'_lnkup_template_id'    => absint( $input['template_id'] ?? 0 ),
			'_lnkup_reminder_at'    => sanitize_text_field( (string) ( $input['reminder_at'] ?? '' ) ),
		);
		if ( $url ) {
			$fields['_lnkup_published_url']  = $url;
			$fields['_lnkup_idempotency_key'] = $connector_id . ':' . md5( strtolower( $url ) );
			if ( 'published' === $status ) {
				$fields['_lnkup_published_at'] = sanitize_text_field( (string) ( $input['published_at'] ?? current_time( 'mysql' ) ) );
			}
		}
		if ( isset( $input['manual_views'] ) ) {
			$fields['_lnkup_manual_views'] = absint( $input['manual_views'] );
		}
		foreach ( $fields as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		if ( 'published' === $status ) {
			LNKup_Connector::mark_onboarding_step( $connector_id, 'published_video' );
		}
		return self::post_row( $id, $connector );
	}

	/**
	 * Analytics (program totals + tagged clicks).
	 *
	 * @param int $connector_id Connector ID.
	 */
	public static function analytics( $connector_id ) {
		$connector = LNKup_Connector::get( $connector_id );
		if ( ! $connector ) {
			return new WP_Error( 'not_found', __( 'Connector not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$posts   = self::list_posts( $connector );
		$published = 0;
		$clicks    = 0;
		$by_plat   = array();
		$by_type   = array();
		foreach ( $posts as $post ) {
			if ( 'published' === $post['status'] ) {
				++$published;
			}
			$clicks += (int) $post['clicks'];
			$plat = $post['platform'] ?: 'unknown';
			$by_plat[ $plat ] = ( $by_plat[ $plat ] ?? 0 ) + (int) $post['clicks'];
			$type = $post['content_type'] ?: 'unknown';
			$by_type[ $type ] = ( $by_type[ $type ] ?? 0 ) + (int) $post['clicks'];
		}
		$funnel   = LNKup_Connector::get_funnel( $connector_id );
		$earnings = LNKup_Connector_Rewards::balances_for_connector( $connector_id );
		$regs     = (int) ( $funnel['counts']['registrations'] ?? 0 );
		$best_plat = '';
		$best_n    = -1;
		foreach ( $by_plat as $k => $n ) {
			if ( $n > $best_n ) {
				$best_n    = $n;
				$best_plat = $k;
			}
		}
		$best_type = '';
		$best_tn   = -1;
		foreach ( $by_type as $k => $n ) {
			if ( $n > $best_tn ) {
				$best_tn   = $n;
				$best_type = $k;
			}
		}
		return array(
			'published_videos'       => $published,
			'tracked_clicks'         => $clicks,
			'registrations'          => $regs,
			'activated_organizers'   => (int) ( $funnel['counts']['activated'] ?? 0 ),
			'activated_vendors'      => 0,
			'qualifying_bookings'    => (int) ( $funnel['counts']['bookings'] ?? 0 ),
			'conversion_rate'        => ( $clicks >= 5 && $regs ) ? (int) round( ( $regs / $clicks ) * 100 ) : null,
			'earnings_approved'      => (float) ( $earnings['approved'] ?? 0 ),
			'best_platform'          => $best_plat,
			'best_content_type'      => $best_type,
			'posts'                  => $posts,
			'disclaimer'             => __( 'Registrations, bookings, and earnings are program totals from referral attribution — not a claim that a specific video caused them. Posting a video does not create a commission.', 'lnkup-marketplace' ),
			'empty'                  => $published < 1,
			'empty_copy'             => __( 'Publish your first video and add the URL here to begin tracking your content activity.', 'lnkup-marketplace' ),
		);
	}
}
