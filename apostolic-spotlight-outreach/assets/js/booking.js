/* Apostolic Spotlight – public scheduler. No dependencies. */
( function () {
	'use strict';

	var cfg = window.ASOBooking;
	var root = document.getElementById( 'aso-booking' );
	if ( ! cfg || ! root ) {
		return;
	}

	var viewerTz = ( Intl.DateTimeFormat().resolvedOptions().timeZone ) || cfg.ownerTz;
	var tz = viewerTz;
	var slots = [];
	var selectedDay = null;

	var $ = function ( sel ) { return root.querySelector( sel ); };
	var pick = $( '[data-step="pick"]' );
	var form = $( '[data-step="details"]' );
	var daysEl = $( '[data-days]' );
	var timesEl = $( '[data-times]' );
	var statusEl = $( '[data-status]' );
	var formStatus = $( '[data-form-status]' );
	var tzLabel = $( '[data-tz-label]' );

	function fmt( date, opts ) {
		opts = Object.assign( { timeZone: tz }, opts );
		return new Intl.DateTimeFormat( undefined, opts ).format( date );
	}
	function dayKey( date ) {
		// YYYY-MM-DD in the display timezone.
		var p = new Intl.DateTimeFormat( 'en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' } ).format( date );
		return p;
	}
	function prettyTz( name ) {
		return name.replace( /_/g, ' ' );
	}

	function renderTz() {
		tzLabel.textContent = '';
		tzLabel.appendChild( document.createTextNode( cfg.i18n.timesIn + ' ' + prettyTz( tz ) + ' ' ) );
		if ( viewerTz !== cfg.ownerTz ) {
			var other = tz === viewerTz ? cfg.ownerTz : viewerTz;
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'aso-link';
			b.textContent = '(' + cfg.i18n.change + ' → ' + prettyTz( other ) + ')';
			b.addEventListener( 'click', function () {
				tz = other;
				renderTz();
				renderDays();
			} );
			tzLabel.appendChild( b );
		}
	}

	function grouped() {
		var groups = {};
		var order = [];
		slots.forEach( function ( iso ) {
			var d = new Date( iso );
			var k = dayKey( d );
			if ( ! groups[ k ] ) {
				groups[ k ] = [];
				order.push( k );
			}
			groups[ k ].push( d );
		} );
		return { groups: groups, order: order };
	}

	function renderDays() {
		var g = grouped();
		daysEl.innerHTML = '';
		timesEl.innerHTML = '';
		if ( ! g.order.length ) {
			statusEl.textContent = cfg.i18n.noSlots;
			return;
		}
		statusEl.textContent = '';
		if ( ! selectedDay || ! g.groups[ selectedDay ] ) {
			selectedDay = g.order[ 0 ];
		}
		g.order.forEach( function ( k ) {
			var first = g.groups[ k ][ 0 ];
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'aso-day' + ( k === selectedDay ? ' is-active' : '' );
			btn.setAttribute( 'role', 'option' );
			btn.setAttribute( 'aria-selected', k === selectedDay ? 'true' : 'false' );
			btn.innerHTML = '<span class="aso-day__dow"></span><span class="aso-day__date"></span><span class="aso-day__count"></span>';
			btn.querySelector( '.aso-day__dow' ).textContent = fmt( first, { weekday: 'short' } );
			btn.querySelector( '.aso-day__date' ).textContent = fmt( first, { month: 'short', day: 'numeric' } );
			btn.querySelector( '.aso-day__count' ).textContent = g.groups[ k ].length + ( g.groups[ k ].length === 1 ? ' time' : ' times' );
			btn.addEventListener( 'click', function () {
				selectedDay = k;
				renderDays();
			} );
			daysEl.appendChild( btn );
		} );
		renderTimes( g.groups[ selectedDay ] );
	}

	function renderTimes( list ) {
		timesEl.innerHTML = '';
		var h = document.createElement( 'p' );
		h.className = 'aso-times__heading';
		h.textContent = fmt( list[ 0 ], { weekday: 'long', month: 'long', day: 'numeric' } );
		timesEl.appendChild( h );
		list.forEach( function ( d ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'aso-time';
			btn.textContent = fmt( d, { hour: 'numeric', minute: '2-digit' } );
			btn.addEventListener( 'click', function () { choose( d ); } );
			timesEl.appendChild( btn );
		} );
	}

	function choose( d ) {
		form.elements.start.value = d.toISOString();
		form.elements.timezone.value = viewerTz;
		$( '[data-selected]' ).textContent = cfg.i18n.selected + ' ' + fmt( d, { weekday: 'long', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' } ) + ' · ' + cfg.duration + ' min';
		pick.hidden = true;
		form.hidden = false;
		formStatus.textContent = '';
		var first = form.querySelector( 'input[name="pastor_name"]' );
		if ( first ) { first.focus(); }
	}

	function load() {
		statusEl.textContent = cfg.i18n.loading;
		fetch( cfg.slotsUrl, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( data.error ) {
					statusEl.textContent = data.error;
					return;
				}
				slots = data.slots || [];
				renderDays();
			} )
			.catch( function () { statusEl.textContent = cfg.i18n.error; } );
	}

	$( '[data-back]' ).addEventListener( 'click', function () {
		form.hidden = true;
		pick.hidden = false;
	} );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		if ( ! form.checkValidity() ) {
			form.reportValidity();
			return;
		}
		var btn = $( '[data-submit]' );
		btn.disabled = true;
		btn.textContent = cfg.i18n.booking;
		formStatus.textContent = '';
		var body = {};
		new FormData( form ).forEach( function ( v, k ) { body[ k ] = v; } );
		fetch( cfg.bookUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( body )
		} )
			.then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, data: j }; } ); } )
			.then( function ( res ) {
				if ( res.ok && res.data.redirect ) {
					window.location.href = res.data.redirect;
					return;
				}
				formStatus.textContent = ( res.data && res.data.message ) || cfg.i18n.error;
				btn.disabled = false;
				btn.textContent = cfg.i18n.confirm;
				if ( res.data && res.data.code === 'aso_slot_taken' ) {
					form.hidden = true;
					pick.hidden = false;
					load();
				}
			} )
			.catch( function () {
				formStatus.textContent = cfg.i18n.error;
				btn.disabled = false;
				btn.textContent = cfg.i18n.confirm;
			} );
	} );

	renderTz();
	load();
}() );
