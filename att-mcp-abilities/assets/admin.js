/**
 * ATT MCP Abilities — admin behaviour for the MCP screens.
 *  - Settings: write/PHP confirmations, per-group "Toggle All", addon dimming.
 *  - Connect: client tabs, copy buttons, Application Password create/revoke
 *    through core's REST API (wp.apiFetch adds the wp_rest nonce).
 */
( function () {
	'use strict';

	var cfg  = window.attMcpAdmin || { i18n: {}, placeholder: 'replace-with-your-application-password' };
	var i18n = cfg.i18n || {};

	function $all( selector, root ) {
		return Array.prototype.slice.call( ( root || document ).querySelectorAll( selector ) );
	}

	/* ----- Settings screen ------------------------------------------------ */

	function initSettings() {
		$all( 'input[data-access="write"]' ).forEach( function ( cb ) {
			cb.addEventListener( 'change', function () {
				if ( this.checked && ! window.confirm( i18n.confirmWrite ) ) {
					this.checked = false;
				}
			} );
		} );

		$all( 'input[data-confirm-php]' ).forEach( function ( cb ) {
			cb.addEventListener( 'change', function () {
				if ( this.checked && ! window.confirm( i18n.confirmPhp ) ) {
					this.checked = false;
				}
			} );
		} );

		$all( '.att-toggle-all' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var boxes = $all( 'input[data-group="' + this.getAttribute( 'data-group' ) + '"]' );
				var allOn = boxes.every( function ( b ) { return b.checked; } );
				boxes.forEach( function ( b ) { b.checked = ! allOn; } );
			} );
		} );

		$all( '.att-addon-master' ).forEach( function ( master ) {
			master.addEventListener( 'change', function () {
				var body = document.querySelector( '[data-addon-body="' + this.getAttribute( 'data-addon' ) + '"]' );
				if ( body ) {
					body.classList.toggle( 'att-dim', ! this.checked );
				}
			} );
		} );
	}

	/* ----- Connect screen: tabs + copy ----------------------------------- */

	function initTabs() {
		$all( '.att-tab-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				$all( '.att-tab-btn' ).forEach( function ( b ) {
					b.classList.remove( 'att-tab-active' );
					b.setAttribute( 'aria-selected', 'false' );
				} );
				$all( '.att-tab-panel' ).forEach( function ( p ) { p.classList.remove( 'att-tab-panel-active' ); } );
				btn.classList.add( 'att-tab-active' );
				btn.setAttribute( 'aria-selected', 'true' );
				var panel = document.getElementById( 'att-tab-' + btn.getAttribute( 'data-tab' ) );
				if ( panel ) {
					panel.classList.add( 'att-tab-panel-active' );
				}
			} );
		} );
	}

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );
			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();
			try {
				document.execCommand( 'copy' ) ? resolve() : reject();
			} catch ( e ) {
				reject( e );
			}
			document.body.removeChild( area );
		} );
	}

	function initCopy() {
		document.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest ? event.target.closest( '.att-copy-btn' ) : null;
			if ( ! btn ) {
				return;
			}
			var target = document.getElementById( btn.getAttribute( 'data-copy-target' ) );
			if ( ! target ) {
				return;
			}
			var label = btn.querySelector( '.att-copy-label' );
			var orig  = label ? label.textContent : '';
			copyText( target.textContent ).then( function () {
				if ( label ) {
					label.textContent = i18n.copied;
				}
				btn.classList.add( 'is-copied' );
				window.setTimeout( function () {
					if ( label ) {
						label.textContent = orig;
					}
					btn.classList.remove( 'is-copied' );
				}, 2500 );
			} ).catch( function () {
				window.alert( i18n.copyFailed );
			} );
		} );
	}

	/* ----- Connect screen: Application Passwords -------------------------- */

	function fillConfigs( password ) {
		$all( 'pre[data-att-config]' ).forEach( function ( pre ) {
			pre.textContent = pre.textContent.split( cfg.placeholder ).join( password );
		} );
	}

	function addRow( item ) {
		var tbody = document.getElementById( 'att-mcp-pw-rows' );
		if ( ! tbody ) {
			return;
		}
		var empty = tbody.querySelector( '.att-pw-empty' );
		if ( empty ) {
			empty.parentNode.removeChild( empty );
		}
		var tr     = document.createElement( 'tr' );
		var name   = document.createElement( 'td' );
		var made   = document.createElement( 'td' );
		var used   = document.createElement( 'td' );
		var action = document.createElement( 'td' );
		var btn    = document.createElement( 'button' );

		name.textContent = item.name;
		made.textContent = item.created ? String( item.created ).slice( 0, 10 ) : '';
		used.textContent = '—';
		btn.type = 'button';
		btn.className = 'button button-link-delete att-pw-revoke';
		btn.setAttribute( 'data-uuid', item.uuid );
		btn.textContent = document.querySelector( '.att-pw-revoke' ) ? document.querySelector( '.att-pw-revoke' ).textContent : 'Revoke';
		action.appendChild( btn );
		tr.appendChild( name );
		tr.appendChild( made );
		tr.appendChild( used );
		tr.appendChild( action );
		tbody.insertBefore( tr, tbody.firstChild );
	}

	function showError( message ) {
		var box = document.getElementById( 'att-mcp-pw-error' );
		if ( box ) {
			box.textContent = message;
			box.hidden = ! message;
		}
	}

	function initPasswords() {
		var generate = document.getElementById( 'att-mcp-pw-generate' );
		if ( ! generate || ! window.wp || ! window.wp.apiFetch ) {
			return;
		}

		generate.addEventListener( 'click', function () {
			var input = document.getElementById( 'att-mcp-pw-name' );
			var name  = input ? input.value.trim() : '';
			if ( ! name ) {
				showError( i18n.nameRequired );
				return;
			}
			showError( '' );
			var label = generate.textContent;
			generate.disabled = true;
			generate.textContent = i18n.generating;

			window.wp.apiFetch( {
				path: '/wp/v2/users/me/application-passwords',
				method: 'POST',
				data: { name: name, app_id: generate.getAttribute( 'data-app-id' ) }
			} ).then( function ( item ) {
				var value = document.getElementById( 'att-mcp-pw-value' );
				if ( value ) {
					value.textContent = item.password;
				}
				var result = document.getElementById( 'att-mcp-pw-result' );
				if ( result ) {
					result.hidden = false;
				}
				fillConfigs( item.password );
				addRow( item );
			} ).catch( function ( error ) {
				showError( i18n.generateError + ' ' + ( error && error.message ? error.message : '' ) );
			} ).then( function () {
				generate.disabled = false;
				generate.textContent = label;
			} );
		} );

		document.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest ? event.target.closest( '.att-pw-revoke' ) : null;
			if ( ! btn || ! window.confirm( i18n.confirmRevoke ) ) {
				return;
			}
			btn.disabled = true;
			window.wp.apiFetch( {
				path: '/wp/v2/users/me/application-passwords/' + encodeURIComponent( btn.getAttribute( 'data-uuid' ) ),
				method: 'DELETE'
			} ).then( function () {
				var row = btn.closest( 'tr' );
				if ( row ) {
					row.classList.add( 'att-pw-revoked' );
				}
				btn.textContent = i18n.revoked;
			} ).catch( function ( error ) {
				btn.disabled = false;
				showError( error && error.message ? error.message : '' );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initSettings();
		initTabs();
		initCopy();
		initPasswords();
	} );
}() );
