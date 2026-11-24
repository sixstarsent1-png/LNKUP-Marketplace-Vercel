/* global lnkupCommsCenter */
( function () {
	'use strict';

	var cfg = window.lnkupCommsCenter || {};
	var t = cfg.i18n || {};
	var root = document.getElementById( 'lnkup-comms-center' );
	if ( ! root ) {
		return;
	}

	var state = {
		role: cfg.role || 'vendor',
		tab: cfg.tab || 'all',
		page: 1,
		items: [],
		hasMore: false,
		tabs: [],
		counts: {},
		invites: null
	};

	/**
	 * Fetch JSON from the LNKup REST API. Never throws; returns { ok, data, error }.
	 */
	async function api( path, options ) {
		try {
			var response = await fetch( cfg.restUrl + path, Object.assign( {
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' }
			}, options || {} ) );
			var type = response.headers.get( 'content-type' ) || '';
			if ( type.indexOf( 'application/json' ) === -1 ) {
				console.error( 'LNKup: expected JSON from ' + path );
				return { ok: false, error: t.error };
			}
			var json = await response.json();
			if ( ! response.ok ) {
				return { ok: false, error: ( json && json.message ) || t.error, status: response.status };
			}
			return { ok: true, data: json };
		} catch ( err ) {
			console.error( 'LNKup API error:', err );
			return { ok: false, error: t.error };
		}
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			var value = attrs[ key ];
			if ( key === 'text' ) {
				node.textContent = value;
			} else if ( key === 'className' ) {
				node.className = value;
			} else if ( key.indexOf( 'on' ) === 0 && typeof value === 'function' ) {
				node.addEventListener( key.slice( 2 ), value );
			} else if ( value === true ) {
				node.setAttribute( key, '' );
			} else if ( value !== false && value != null ) {
				node.setAttribute( key, value );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
			}
		} );
		return node;
	}

	function format( template, value ) {
		return String( template || '' ).replace( '%s', value );
	}

	function relativeTime( iso ) {
		var then = Date.parse( iso );
		if ( ! then ) {
			return '';
		}
		var minutes = Math.round( ( Date.now() - then ) / 60000 );
		if ( minutes < 1 ) {
			return 'now';
		}
		if ( minutes < 60 ) {
			return minutes + 'm';
		}
		if ( minutes < 1440 ) {
			return Math.round( minutes / 60 ) + 'h';
		}
		return new Date( then ).toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
	}

	function isToday( iso ) {
		var d = new Date( Date.parse( iso ) );
		var now = new Date();
		return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
	}

	function stateCard( message, isError, retry ) {
		return el( 'div', { className: 'lnkup-comms-card lnkup-comms-state' + ( isError ? ' is-error' : '' ), role: isError ? 'alert' : null }, [
			el( 'p', { text: message } ),
			retry ? el( 'button', { type: 'button', className: 'lnkup-comms-btn is-secondary', text: t.retry, onclick: retry } ) : null
		] );
	}

	function header() {
		var roleSwitch = null;
		if ( ( cfg.roles || [] ).length > 1 ) {
			var select = el( 'select', { 'aria-label': t.viewAs, onchange: function ( e ) {
				state.role = e.target.value;
				state.tab = 'all';
				load( true );
			} } );
			cfg.roles.forEach( function ( role ) {
				select.appendChild( el( 'option', { value: role, selected: role === state.role, text: ( t.roles || {} )[ role ] || role } ) );
			} );
			roleSwitch = el( 'label', { className: 'lnkup-comms-center__role' }, [ el( 'span', { text: t.viewAs } ), select ] );
		}
		return el( 'div', { className: 'lnkup-comms-center__head' }, [
			el( 'h2', { text: t.title } ),
			el( 'div', { className: 'lnkup-comms-actions' }, [
				roleSwitch,
				el( 'button', { type: 'button', className: 'lnkup-comms-btn is-secondary', text: t.markAll, 'data-testid': 'lnkup-center-mark-all', disabled: ! ( state.counts[ state.tab ] > 0 ), onclick: markAll } ),
				el( 'a', { className: 'lnkup-comms-center__settings', href: cfg.settingsUrl, text: t.settings } )
			] )
		] );
	}

	function tabBar() {
		var nav = el( 'div', { className: 'lnkup-comms-center__tabs', role: 'tablist' } );
		state.tabs.forEach( function ( tab ) {
			var count = state.counts[ tab ] || 0;
			nav.appendChild( el( 'button', {
				type: 'button',
				role: 'tab',
				className: 'lnkup-comms-center__tab' + ( tab === state.tab ? ' is-active' : '' ),
				'aria-selected': tab === state.tab ? 'true' : 'false',
				'data-tab': tab,
				onclick: function () {
					state.tab = tab;
					load( true );
				}
			}, [ ( t.tabs || {} )[ tab ] || tab, count > 0 ? el( 'span', { className: 'lnkup-comms-badge', text: String( count ) } ) : null ] ) );
		} );
		return nav;
	}

	function itemRow( item ) {
		var unread = ! item.readAt;
		return el( 'li', { className: 'lnkup-comms-center__item' + ( unread ? ' is-unread' : '' ) }, [
			el( 'button', { type: 'button', className: 'lnkup-comms-center__link', 'data-testid': 'lnkup-center-item', onclick: function () {
				open( item );
			} }, [
				unread ? el( 'span', { className: 'lnkup-comms-center__dot', 'aria-label': t.unread } ) : null,
				el( 'span', { className: 'lnkup-comms-center__text' }, [
					el( 'strong', { text: item.title } ),
					item.message ? el( 'span', { className: 'lnkup-comms-desc', text: item.message } ) : null
				] ),
				el( 'time', { className: 'lnkup-comms-note', datetime: item.createdAt, text: relativeTime( item.createdAt ) } )
			] )
		] );
	}

	function list() {
		if ( ! state.items.length ) {
			return state.tab === 'invitations' ? null : stateCard( state.tab === 'all' ? t.empty : t.emptyTab, false );
		}
		var today = state.items.filter( function ( i ) { return isToday( i.createdAt ); } );
		var earlier = state.items.filter( function ( i ) { return ! isToday( i.createdAt ); } );
		var card = el( 'div', { className: 'lnkup-comms-card' } );
		[ [ t.today, today ], [ t.earlier, earlier ] ].forEach( function ( group ) {
			if ( ! group[ 1 ].length ) {
				return;
			}
			card.appendChild( el( 'h3', { className: 'lnkup-comms-center__group', text: group[ 0 ] } ) );
			card.appendChild( el( 'ul', { className: 'lnkup-comms-center__list' }, group[ 1 ].map( itemRow ) ) );
		} );
		if ( state.hasMore ) {
			card.appendChild( el( 'div', { className: 'lnkup-comms-actions' }, [ el( 'button', { type: 'button', className: 'lnkup-comms-btn is-secondary', text: t.loadMore, onclick: function () {
				state.page += 1;
				load( false );
			} } ) ] ) );
		}
		return card;
	}

	function invitationCards() {
		if ( state.tab !== 'invitations' || state.role !== 'vendor' || state.invites === null ) {
			return null;
		}
		var card = el( 'div', { className: 'lnkup-comms-card', 'data-testid': 'lnkup-center-invitations' }, [ el( 'h3', { text: t.invitations } ) ] );
		if ( ! state.invites.length ) {
			card.appendChild( el( 'p', { className: 'lnkup-comms-note', text: t.noInvites } ) );
			return card;
		}
		state.invites.forEach( function ( invite ) {
			var status = el( 'p', { className: 'lnkup-comms-status', role: 'status' } );
			var meta = [ invite.event_date, [ invite.city, invite.state ].filter( Boolean ).join( ', ' ), invite.opportunity_title ].filter( Boolean ).join( ' · ' );
			var actions = el( 'div', { className: 'lnkup-comms-actions' } );
			function respond( accept, button ) {
				button.disabled = true;
				api( 'vendor/invites/' + invite.id + '/respond', { method: 'POST', body: JSON.stringify( { accept: accept } ) } ).then( function ( res ) {
					if ( ! res.ok ) {
						button.disabled = false;
						status.className = 'lnkup-comms-status is-error';
						status.textContent = res.error;
						return;
					}
					if ( accept && res.data && res.data.apply_url ) {
						window.location.href = res.data.apply_url;
						return;
					}
					actions.replaceChildren();
					status.textContent = t.declined;
				} );
			}
			if ( invite.can_respond ) {
				actions.appendChild( el( 'button', { type: 'button', className: 'lnkup-comms-btn', text: t.accept, 'data-testid': 'lnkup-invite-accept', onclick: function ( e ) { respond( true, e.currentTarget ); } } ) );
				actions.appendChild( el( 'button', { type: 'button', className: 'lnkup-comms-btn is-secondary', text: t.decline, 'data-testid': 'lnkup-invite-decline', onclick: function ( e ) { respond( false, e.currentTarget ); } } ) );
			}
			card.appendChild( el( 'article', { className: 'lnkup-comms-center__invite' }, [
				el( 'strong', { text: invite.event_title || invite.opportunity_title } ),
				meta ? el( 'span', { className: 'lnkup-comms-desc', text: meta } ) : null,
				invite.organizer_name ? el( 'span', { className: 'lnkup-comms-desc', text: format( t.invitedBy, invite.organizer_name ) } ) : null,
				invite.message ? el( 'p', { text: invite.message } ) : null,
				invite.expires_at ? el( 'span', { className: 'lnkup-comms-note', text: format( t.expires, new Date( Date.parse( invite.expires_at.replace( ' ', 'T' ) + 'Z' ) ).toLocaleDateString() ) } ) : null,
				actions,
				status
			] ) );
		} );
		return card;
	}

	function render() {
		root.replaceChildren( header(), tabBar(), invitationCards() || document.createTextNode( '' ), list() || document.createTextNode( '' ) );
	}

	async function open( item ) {
		if ( ! item.readAt ) {
			api( 'comms/inbox/' + encodeURIComponent( item.id ) + '/read', { method: 'POST' } );
		}
		if ( item.href ) {
			window.location.href = item.href;
			return;
		}
		if ( window.lnkupShowToast ) {
			window.lnkupShowToast( t.unavailable, 'info' );
		}
	}

	async function markAll() {
		var res = await api( 'comms/inbox/read-all?role=' + encodeURIComponent( state.role ) + '&tab=' + encodeURIComponent( state.tab ), { method: 'POST' } );
		if ( res.ok ) {
			load( true );
		}
	}

	async function load( reset ) {
		if ( reset ) {
			state.page = 1;
			state.items = [];
			root.replaceChildren( el( 'div', { className: 'lnkup-comms-card lnkup-comms-state lnkup-comms-skeleton', text: t.loading } ) );
		}
		var query = '?role=' + encodeURIComponent( state.role ) + '&tab=' + encodeURIComponent( state.tab ) + '&page=' + state.page + '&per_page=20';
		var requests = [ api( 'comms/inbox' + query ), api( 'comms/inbox/count?role=' + encodeURIComponent( state.role ) ) ];
		if ( state.tab === 'invitations' && state.role === 'vendor' ) {
			requests.push( api( 'vendor/invites' ) );
		}
		var results = await Promise.all( requests );
		if ( ! results[ 0 ].ok ) {
			root.replaceChildren( stateCard( results[ 0 ].error || t.error, true, function () { load( true ); } ) );
			return;
		}
		var data = results[ 0 ].data || {};
		state.tabs = data.tabs || [ 'all' ];
		state.items = state.items.concat( data.items || [] );
		state.hasMore = !! data.hasMore;
		state.counts = ( results[ 1 ].ok && results[ 1 ].data && results[ 1 ].data.byTab ) || {};
		state.invites = results[ 2 ] ? ( results[ 2 ].ok ? ( results[ 2 ].data.items || [] ) : [] ) : null;
		render();
	}

	load( true );
}() );
