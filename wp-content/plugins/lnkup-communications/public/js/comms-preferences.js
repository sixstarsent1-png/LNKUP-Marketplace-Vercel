/* global lnkupComms */
( function () {
	'use strict';

	var cfg = window.lnkupComms || {};
	var t = cfg.i18n || {};
	var root = document.getElementById( 'lnkup-comms-preferences' );
	if ( ! root ) {
		return;
	}

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
				return { ok: false, error: ( json && json.message ) || t.error };
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
			if ( key === 'text' ) {
				node.textContent = attrs[ key ];
			} else if ( key === 'className' ) {
				node.className = attrs[ key ];
			} else if ( attrs[ key ] === true ) {
				node.setAttribute( key, '' );
			} else if ( attrs[ key ] !== false && attrs[ key ] != null ) {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( child );
			}
		} );
		return node;
	}

	function state( message, isError ) {
		root.replaceChildren( el( 'div', { className: 'lnkup-comms-card lnkup-comms-state' + ( isError ? ' is-error' : '' ), role: isError ? 'alert' : null }, [ el( 'p', { text: message } ) ] ) );
	}

	function select( name, value, options, label ) {
		var node = el( 'select', { name: name, 'aria-label': label } );
		options.forEach( function ( opt ) {
			var option = el( 'option', { value: opt, text: t[ opt ] || opt } );
			if ( opt === value ) {
				option.selected = true;
			}
			node.appendChild( option );
		} );
		return node;
	}

	function render( prefs ) {
		if ( ! prefs.categories || ! prefs.categories.length ) {
			state( t.empty );
			return;
		}
		var form = el( 'form', { className: 'lnkup-comms-card lnkup-comms-form', 'data-testid': 'lnkup-comms-preferences-form', 'aria-label': t.category } );
		var table = el( 'table', { className: 'lnkup-comms-table' } );
		table.appendChild( el( 'thead', {}, [ el( 'tr', {}, [
			el( 'th', { scope: 'col', text: t.category } ),
			el( 'th', { scope: 'col', text: t.email } ),
			el( 'th', { scope: 'col', text: t.push } )
		] ) ] ) );
		var body = el( 'tbody' );
		prefs.categories.forEach( function ( cat ) {
			var label = el( 'th', { scope: 'row' }, [
				el( 'span', { className: 'lnkup-comms-cat', text: cat.label } ),
				cat.promotional ? el( 'span', { className: 'lnkup-comms-badge', text: t.promo } ) : null,
				el( 'span', { className: 'lnkup-comms-desc', text: cat.description } )
			] );
			var emailCell = cat.locked ? el( 'td', { text: t.locked } ) : el( 'td', {}, [ select( 'email:' + cat.key, cat.email, prefs.emailModes, cat.label + ' — ' + t.email ) ] );
			var pushCell = cat.locked ? el( 'td', { text: t.locked } ) : el( 'td', {}, [ select( 'push:' + cat.key, cat.push, [ 'on', 'off' ], cat.label + ' — ' + t.push ) ] );
			body.appendChild( el( 'tr', {}, [ label, emailCell, pushCell ] ) );
		} );
		table.appendChild( body );

		var master = el( 'input', { type: 'checkbox', name: 'pushEnabled', id: 'lnkup-comms-push-master' } );
		master.checked = !! prefs.pushEnabled;
		var quiet = el( 'input', { type: 'checkbox', name: 'quietEnabled', id: 'lnkup-comms-quiet' } );
		quiet.checked = !! ( prefs.quietHours && prefs.quietHours.enabled );
		var tz = el( 'select', { name: 'timezone', id: 'lnkup-comms-tz' } );
		( cfg.timezones || [] ).forEach( function ( zone ) {
			var option = el( 'option', { value: zone, text: zone } );
			if ( zone === prefs.timezone ) {
				option.selected = true;
			}
			tz.appendChild( option );
		} );
		var status = el( 'p', { className: 'lnkup-comms-status', role: 'status' } );
		var save = el( 'button', { type: 'submit', className: 'lnkup-comms-btn', text: t.save } );

		form.append(
			el( 'div', { className: 'lnkup-comms-table-wrap' }, [ table ] ),
			el( 'div', { className: 'lnkup-comms-row' }, [ master, el( 'label', { for: 'lnkup-comms-push-master', text: t.pushMaster } ) ] ),
			el( 'div', { className: 'lnkup-comms-row' }, [ quiet, el( 'label', { for: 'lnkup-comms-quiet', text: t.quiet } ) ] ),
			el( 'div', { className: 'lnkup-comms-row lnkup-comms-inline' }, [
				el( 'label', { text: t.from }, [ el( 'input', { type: 'time', name: 'quietStart', value: ( prefs.quietHours || {} ).start || '22:00' } ) ] ),
				el( 'label', { text: t.to }, [ el( 'input', { type: 'time', name: 'quietEnd', value: ( prefs.quietHours || {} ).end || '08:00' } ) ] ),
				el( 'label', { for: 'lnkup-comms-tz', text: t.timezone } ),
				tz
			] ),
			el( 'p', { className: 'lnkup-comms-note', text: t.note } ),
			el( 'div', { className: 'lnkup-comms-actions' }, [ save, status ] )
		);

		form.addEventListener( 'submit', async function ( event ) {
			event.preventDefault();
			save.disabled = true;
			save.textContent = t.saving;
			status.textContent = '';
			var data = new FormData( form );
			var categories = prefs.categories.filter( function ( cat ) {
				return ! cat.locked;
			} ).map( function ( cat ) {
				return { key: cat.key, email: data.get( 'email:' + cat.key ), push: data.get( 'push:' + cat.key ) };
			} );
			var result = await api( 'comms/preferences', {
				method: 'PATCH',
				body: JSON.stringify( {
					categories: categories,
					pushEnabled: master.checked,
					quietHours: { enabled: quiet.checked, start: data.get( 'quietStart' ), end: data.get( 'quietEnd' ) },
					timezone: data.get( 'timezone' )
				} )
			} );
			save.disabled = false;
			save.textContent = t.save;
			status.textContent = result.ok ? t.saved : ( result.error || t.saveError );
			status.classList.toggle( 'is-error', ! result.ok );
		} );

		root.replaceChildren( form );
	}

	( async function load() {
		var result = await api( 'comms/preferences' );
		if ( ! result.ok ) {
			state( result.error || t.error, true );
			return;
		}
		render( result.data );
	} )();
} )();
