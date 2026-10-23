/**
 * Storedash free-shipping bar — block cart / checkout.
 *
 * Inserts the server-rendered bar (from <template id="storedash-fs-bar-tpl">)
 * above the cart / checkout block and recomputes it from the wc/store/cart
 * data store on every change. Display only: never writes to the cart.
 *
 * @since 1.29.0
 */
( function () {
	'use strict';

	var cfg = window.storedashFsBar || null;
	if ( ! cfg || ! ( cfg.threshold > 0 ) ) {
		return;
	}

	var ROOT = 'storedash-fs-bar';
	var HOSTS = [ '.wp-block-woocommerce-cart', '.wp-block-woocommerce-checkout' ];
	var lastKey = '';

	function findHost() {
		for ( var i = 0; i < HOSTS.length; i++ ) {
			var el = document.querySelector( HOSTS[ i ] );
			if ( el ) {
				return el;
			}
		}
		return null;
	}

	function insertBar() {
		if ( document.querySelector( '.' + ROOT ) ) {
			return true;
		}
		var tpl = document.getElementById( cfg.templateId || 'storedash-fs-bar-tpl' );
		var host = findHost();
		if ( ! tpl || ! host || ! host.parentNode ) {
			return false;
		}
		host.parentNode.insertBefore( tpl.content.cloneNode( true ), host );
		return true;
	}

	function formatAmount( minor, totals ) {
		var unit = parseInt( totals.currency_minor_unit, 10 );
		if ( isNaN( unit ) || unit < 0 ) {
			unit = 2;
		}
		var value = ( minor / Math.pow( 10, unit ) ).toFixed( unit );
		var parts = value.split( '.' );
		var thousand = totals.currency_thousand_separator || '';
		var decimal = totals.currency_decimal_separator || '.';
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, thousand );
		var out = parts.length > 1 ? parts[ 0 ] + decimal + parts[ 1 ] : parts[ 0 ];
		return ( totals.currency_prefix || '' ) + out + ( totals.currency_suffix || '' );
	}

	function setText( node, template, amountText ) {
		node.textContent = '';
		var pieces = String( template ).split( '{amount}' );
		for ( var i = 0; i < pieces.length; i++ ) {
			if ( i > 0 ) {
				var span = document.createElement( 'span' );
				span.className = ROOT + '__amount';
				span.textContent = amountText;
				node.appendChild( span );
			}
			node.appendChild( document.createTextNode( pieces[ i ] ) );
		}
	}

	function update() {
		if ( ! window.wp || ! wp.data || typeof wp.data.select !== 'function' ) {
			return;
		}
		var store = wp.data.select( 'wc/store/cart' );
		if ( ! store || typeof store.getCartTotals !== 'function' ) {
			return;
		}
		var totals = store.getCartTotals() || {};
		var data = typeof store.getCartData === 'function' ? store.getCartData() : null;
		var itemsCount = data && typeof data.itemsCount === 'number' ? data.itemsCount : ( data && data.items ? data.items.length : -1 );

		var items = parseInt( totals.total_items, 10 ) || 0;
		var itemsTax = parseInt( totals.total_items_tax, 10 ) || 0;
		var discount = parseInt( totals.total_discount, 10 ) || 0;
		var discountTax = parseInt( totals.total_discount_tax, 10 ) || 0;
		var unit = parseInt( totals.currency_minor_unit, 10 );
		if ( isNaN( unit ) || unit < 0 ) {
			unit = 2;
		}

		var key = [ items, itemsTax, discount, discountTax, itemsCount, unit ].join( '|' );
		if ( key === lastKey ) {
			return;
		}
		lastKey = key;

		if ( ! insertBar() ) {
			lastKey = '';
			return;
		}
		var bar = document.querySelector( '.' + ROOT );
		if ( ! bar ) {
			return;
		}

		// Same arithmetic as Threshold_Calculator::compute(), in minor units.
		var subtotal = items + ( cfg.includeTax ? itemsTax : 0 );
		if ( cfg.includeTax ) {
			subtotal -= discountTax;
		}
		if ( ! cfg.ignoreDiscounts ) {
			subtotal -= discount;
		}
		var threshold = Math.round( Number( cfg.threshold ) * Math.pow( 10, unit ) );
		var remaining = Math.max( 0, threshold - subtotal );
		var unlocked = threshold > 0 && subtotal >= threshold;
		var progress = threshold > 0 ? Math.min( 100, Math.max( 0, ( subtotal / threshold ) * 100 ) ) : 100;
		var empty = itemsCount === 0 || ( itemsCount < 0 && items === 0 );
		var hidden = empty || ( unlocked && cfg.hideWhenUnlocked );

		bar.classList.toggle( 'is-unlocked', unlocked );
		bar.classList.toggle( 'is-hidden', hidden );

		var text = bar.querySelector( '.' + ROOT + '__text' );
		var fill = bar.querySelector( '.' + ROOT + '__fill' );
		if ( text ) {
			if ( unlocked ) {
				text.textContent = cfg.copyUnlocked || '';
			} else {
				setText( text, cfg.copyRemaining || '', formatAmount( remaining, totals ) );
			}
		}
		if ( fill ) {
			fill.style.width = progress.toFixed( 1 ) + '%';
		}
	}

	function boot() {
		insertBar();
		if ( window.wp && wp.data && typeof wp.data.subscribe === 'function' ) {
			try {
				wp.data.subscribe( update );
				update();
			} catch ( e ) {
				// Display only: never break the page.
			}
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
