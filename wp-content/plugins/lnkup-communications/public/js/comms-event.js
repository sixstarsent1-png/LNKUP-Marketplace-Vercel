/* global lnkupCommsEvent */
( function () {
	'use strict';

	var cfg = window.lnkupCommsEvent || {};
	var t = cfg.i18n || {};
	var root = document.getElementById( 'lnkup-comms-event' );
	if ( ! root || ! cfg.eventId ) {
		return;
	}
	var base = 'comms/events/' + encodeURIComponent( cfg.eventId ) + '/';

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

	function errorCard( message ) {
		return el( 'div', { className: 'lnkup-comms-card lnkup-comms-state is-error', role: 'alert' }, [ el( 'p', { text: message } ) ] );
	}

	function historyCard( items ) {
		var card = el( 'div', { className: 'lnkup-comms-card' }, [ el( 'h3', { text: t.history } ) ] );
		if ( ! items.length ) {
			card.appendChild( el( 'p', { className: 'lnkup-comms-note', text: t.noHistory } ) );
			return card;
		}
		var list = el( 'ul', { className: 'lnkup-comms-history' } );
		items.forEach( function ( item ) {
			list.appendChild( el( 'li', {}, [
				el( 'strong', { text: item.title } ),
				el( 'span', { className: 'lnkup-comms-desc', text: item.type + ' · ' + item.group + ' · ' + item.sent + '/' + item.recipients + ' · ' + new Date( item.createdAt ).toLocaleString() } ),
				el( 'p', { text: item.body } )
			] ) );
		} );
		card.appendChild( list );
		return card;
	}

	function render( audiences, templates, history ) {
		var form = el( 'form', { className: 'lnkup-comms-card lnkup-comms-form' } );
		var group = el( 'select', { name: 'group', id: 'lnkup-comms-group' } );
		audiences.groups.forEach( function ( g ) {
			group.appendChild( el( 'option', { value: g.key, text: g.label + ' (' + g.count + ')', disabled: g.count === 0 } ) );
		} );
		var firstEnabled = audiences.groups.find( function ( g ) {
			return g.count > 0;
		} );
		if ( firstEnabled ) {
			group.value = firstEnabled.key;
		}
		var type = el( 'select', { name: 'template', id: 'lnkup-comms-type' } );
		templates.forEach( function ( tpl ) {
			type.appendChild( el( 'option', { value: tpl.key, text: tpl.label } ) );
		} );
		var title = el( 'input', { type: 'text', name: 'title', id: 'lnkup-comms-title', maxlength: '120', required: true } );
		var body = el( 'textarea', { name: 'body', id: 'lnkup-comms-body', maxlength: '1000', rows: '5', required: true } );
		var email = el( 'input', { type: 'checkbox', name: 'email', id: 'lnkup-comms-ch-email' } );
		var push = el( 'input', { type: 'checkbox', name: 'push', id: 'lnkup-comms-ch-push' } );
		email.checked = true;
		push.checked = true;
		var previewBox = el( 'div', { className: 'lnkup-comms-preview', role: 'status' } );
		var previewBtn = el( 'button', { type: 'button', className: 'lnkup-comms-btn is-secondary', text: t.preview } );
		var sendBtn = el( 'button', { type: 'submit', className: 'lnkup-comms-btn', text: t.send } );
		var lastPreview = null;

		function applyTemplate() {
			var tpl = templates.find( function ( item ) {
				return item.key === type.value;
			} );
			if ( tpl ) {
				title.value = tpl.title || title.value;
				body.value = tpl.body || body.value;
			}
		}
		type.addEventListener( 'change', applyTemplate );
		applyTemplate();

		function channels() {
			var list = [ 'inbox' ];
			if ( email.checked ) {
				list.push( 'email' );
			}
			if ( push.checked ) {
				list.push( 'push' );
			}
			return list;
		}

		function payload() {
			return { group: group.value, template: type.value, title: title.value, body: body.value, channels: channels() };
		}

		async function runPreview() {
			previewBox.replaceChildren( el( 'p', { text: t.loading } ) );
			var result = await api( base + 'preview', { method: 'POST', body: JSON.stringify( payload() ) } );
			if ( ! result.ok ) {
				previewBox.replaceChildren( errorCard( result.error ) );
				lastPreview = null;
				return null;
			}
			var p = result.data;
			lastPreview = p;
			var excluded = Object.keys( p.excluded || {} ).reduce( function ( sum, key ) {
				return sum + ( p.excluded[ key ] || 0 );
			}, 0 );
			previewBox.replaceChildren(
				el( 'p', {}, [ el( 'strong', { text: p.recipients + ' ' + t.recipients.toLowerCase() } ), document.createTextNode( ' · ' + p.inbox + ' ' + t.inbox + ' · ' + p.email + ' ' + t.email.toLowerCase() + ' · ' + p.push + ' ' + t.push.toLowerCase() ) ] ),
				excluded ? el( 'p', { className: 'lnkup-comms-desc', text: t.excluded + ': ' + excluded } ) : null,
				p.sample && p.sample.length ? el( 'p', { className: 'lnkup-comms-desc', text: p.sample.join( ', ' ) + ( p.recipients > p.sample.length ? '…' : '' ) } ) : null,
				! p.allowed ? errorCard( t.notAllowed ) : null,
				p.rateLimit ? errorCard( p.rateLimit ) : null
			);
			return p;
		}
		previewBtn.addEventListener( 'click', runPreview );

		form.addEventListener( 'submit', async function ( event ) {
			event.preventDefault();
			var p = lastPreview || await runPreview();
			if ( ! p || ! p.allowed || p.rateLimit || ! p.recipients ) {
				return;
			}
			if ( ! window.confirm( t.confirm.replace( '%d', p.recipients ) ) ) {
				return;
			}
			sendBtn.disabled = true;
			sendBtn.textContent = t.sending;
			var result = await api( base + 'send', { method: 'POST', body: JSON.stringify( payload() ) } );
			sendBtn.disabled = false;
			sendBtn.textContent = t.send;
			if ( ! result.ok ) {
				previewBox.replaceChildren( errorCard( result.error ) );
				return;
			}
			previewBox.replaceChildren( el( 'p', { className: 'lnkup-comms-success', text: t.sent } ) );
			lastPreview = null;
			var refreshed = await api( base + 'history' );
			if ( refreshed.ok ) {
				historySlot.replaceChildren( historyCard( refreshed.data.items || [] ) );
			}
		} );
		[ group, type, title, body, email, push ].forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				lastPreview = null;
			} );
		} );

		form.append(
			el( 'h2', { text: audiences.eventName } ),
			el( 'label', { for: 'lnkup-comms-group', text: t.recipients } ), group,
			el( 'label', { for: 'lnkup-comms-type', text: t.type } ), type,
			el( 'label', { for: 'lnkup-comms-title', text: t.title } ), title,
			el( 'label', { for: 'lnkup-comms-body', text: t.message } ), body,
			el( 'fieldset', { className: 'lnkup-comms-row lnkup-comms-inline' }, [
				el( 'legend', { text: t.channels } ),
				email, el( 'label', { for: 'lnkup-comms-ch-email', text: t.email } ),
				push, el( 'label', { for: 'lnkup-comms-ch-push', text: t.push } )
			] ),
			el( 'div', { className: 'lnkup-comms-actions' }, [ previewBtn, sendBtn ] ),
			previewBox
		);
		var historySlot = el( 'div', {}, [ historyCard( history ) ] );
		root.replaceChildren( form, historySlot );
		if ( ! firstEnabled ) {
			sendBtn.disabled = true;
			previewBtn.disabled = true;
			previewBox.replaceChildren( el( 'p', { className: 'lnkup-comms-note', text: t.noVendors } ) );
		}
	}

	( async function load() {
		var results = await Promise.all( [ api( base + 'audiences' ), api( base + 'templates' ), api( base + 'history' ) ] );
		var failed = results.find( function ( r ) {
			return ! r.ok;
		} );
		if ( failed ) {
			root.replaceChildren( errorCard( failed.error || t.error ) );
			return;
		}
		render( results[ 0 ].data, results[ 1 ].data.templates || [], results[ 2 ].data.items || [] );
	} )();
} )();
