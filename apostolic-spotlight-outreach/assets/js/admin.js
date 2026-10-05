/* Apostolic Spotlight Outreach – admin interactions. No dependencies. */
( function () {
	'use strict';
	var cfg = window.ASOAdmin || { confirm: 'Are you sure?', copied: 'Copied!' };

	// Confirm dangerous actions.
	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest( '[data-aso-confirm]' );
		if ( el && ! window.confirm( el.getAttribute( 'data-aso-confirm' ) || cfg.confirm ) ) {
			e.preventDefault();
			e.stopPropagation();
		}
	}, true );

	// Copy to clipboard.
	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest( '[data-aso-copy]' );
		if ( ! el ) {
			return;
		}
		var text = el.getAttribute( 'data-aso-copy' );
		var done = function () {
			var old = el.innerHTML;
			el.textContent = cfg.copied;
			setTimeout( function () { el.innerHTML = old; }, 1400 );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done );
		} else {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			document.body.appendChild( ta );
			ta.select();
			document.execCommand( 'copy' );
			document.body.removeChild( ta );
			done();
		}
	} );

	// Bulk bar: show campaign picker for "Add to Campaign".
	document.querySelectorAll( '[data-aso-bulk-action]' ).forEach( function ( sel ) {
		var camp = sel.parentNode.querySelector( '[data-aso-bulk-campaign]' );
		var sync = function () {
			if ( camp ) {
				camp.hidden = sel.value !== 'add_to_campaign';
			}
		};
		sel.addEventListener( 'change', sync );
		sync();
	} );

	// Churches table: "select all N matching filters".
	var form = document.querySelector( '[data-aso-churches-form]' );
	if ( form ) {
		var banner = form.querySelector( '[data-aso-select-all-banner]' );
		var allInput = form.querySelector( '[data-aso-all-matching]' );
		var pageSel = form.querySelector( '[data-aso-page-selected]' );
		var allBtn = form.querySelector( '[data-aso-select-all-matching]' );
		var boxes = function () { return form.querySelectorAll( 'tbody input[name="church_ids[]"]' ); };
		var update = function () {
			var list = boxes();
			var checked = Array.prototype.filter.call( list, function ( b ) { return b.checked; } ).length;
			if ( allInput.value === '1' && checked !== list.length ) {
				allInput.value = '';
			}
			if ( checked && checked === list.length && banner ) {
				banner.hidden = false;
				if ( allInput.value !== '1' ) {
					pageSel.textContent = checked + ' selected on this page.';
					if ( allBtn ) { allBtn.hidden = false; }
				}
			} else if ( banner ) {
				banner.hidden = true;
			}
		};
		form.addEventListener( 'change', function ( e ) {
			if ( e.target.matches( 'input[type="checkbox"]' ) ) {
				setTimeout( update, 0 );
			}
		} );
		if ( allBtn ) {
			allBtn.addEventListener( 'click', function () {
				allInput.value = '1';
				pageSel.textContent = 'All ' + allBtn.getAttribute( 'data-total' ) + ' matching churches are selected.';
				allBtn.hidden = true;
			} );
		}
	}

	// Toggle-all checkboxes (enrollment review).
	document.querySelectorAll( '[data-aso-toggle-all]' ).forEach( function ( master ) {
		master.addEventListener( 'change', function () {
			document.querySelectorAll( 'input[name="' + master.getAttribute( 'data-aso-toggle-all' ) + '"]' ).forEach( function ( b ) {
				b.checked = master.checked;
			} );
		} );
	} );

	// Campaign editor: add follow-up step.
	var addStep = document.querySelector( '[data-aso-add-step]' );
	if ( addStep ) {
		addStep.addEventListener( 'click', function () {
			var wrap = document.querySelector( '[data-aso-steps]' );
			var tpl = document.querySelector( '[data-aso-step-template]' );
			var i = wrap.querySelectorAll( '[data-aso-step]' ).length;
			var html = tpl.innerHTML.replace( /__I__/g, String( i + 100 ) ).replace( /__N__/g, String( i + 1 ) );
			wrap.insertAdjacentHTML( 'beforeend', html );
		} );
	}

	// Template editor: insert variable at cursor in last-focused field.
	var lastField = null;
	document.querySelectorAll( '[data-aso-var-target]' ).forEach( function ( f ) {
		f.addEventListener( 'focus', function () { lastField = f; } );
		if ( ! lastField && f.tagName === 'TEXTAREA' ) { lastField = f; }
	} );
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-aso-insert]' );
		if ( ! btn || ! lastField ) {
			return;
		}
		var v = btn.getAttribute( 'data-aso-insert' );
		var s = lastField.selectionStart || 0;
		var en = lastField.selectionEnd || 0;
		lastField.value = lastField.value.slice( 0, s ) + v + lastField.value.slice( en );
		lastField.focus();
		lastField.selectionStart = lastField.selectionEnd = s + v.length;
	} );
}() );
