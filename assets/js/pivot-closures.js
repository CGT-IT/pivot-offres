/**
 * Fermetures d'une offre, à la date du visiteur.
 *
 * Une vignette est rendue dans l'index pour plusieurs jours, une fiche peut
 * sortir d'un cache de page : l'état écrit par le serveur est celui du jour
 * où il a rendu la page. Ce script le recalcule à partir des périodes que
 * porte l'attribut data-pivot-closures — [début, fin, type], en AAAAMMJJ.
 *
 * Le balisage reconnu est décrit dans includes/class-pivot-closures.php. Le
 * script repasse sur la grille d'un listing à chaque rendu (pivot:rendered),
 * et expose window.pivotClosures.refresh( racine ) pour un contenu ajouté
 * autrement.
 */
( function () {
	'use strict';

	var OTHER = 'autre';
	var locale = ( document.documentElement.getAttribute( 'lang' ) || 'fr' ).replace( '_', '-' );
	var formatters = {};

	/**
	 * Aujourd'hui, à l'heure du visiteur, en AAAAMMJJ.
	 */
	function today() {
		var now = new Date();

		return now.getFullYear() * 10000 + ( now.getMonth() + 1 ) * 100 + now.getDate();
	}

	/**
	 * « jeudi 1 octobre », et l'année quand ce n'est pas l'année en cours :
	 * la même forme que Pivot_Closures::format().
	 */
	function format( value ) {
		var year = Math.floor( value / 10000 );
		var date = new Date( year, Math.floor( value / 100 ) % 100 - 1, value % 100 );
		var withYear = year !== new Date().getFullYear();
		var key = withYear ? 'year' : 'short';

		if ( ! formatters[ key ] ) {
			var options = { weekday: 'long', day: 'numeric', month: 'long' };

			if ( withYear ) {
				options.year = 'numeric';
			}

			try {
				formatters[ key ] = new Intl.DateTimeFormat( locale, options );
			} catch ( error ) {
				formatters[ key ] = new Intl.DateTimeFormat( undefined, options );
			}
		}

		return formatters[ key ].format( date );
	}

	function periodsOf( node ) {
		try {
			var periods = JSON.parse( node.getAttribute( 'data-pivot-closures' ) || '[]' );

			return Array.isArray( periods ) ? periods : [];
		} catch ( error ) {
			return [];
		}
	}

	/**
	 * État à une date : mêmes clés que Pivot_Closures::status().
	 */
	function status( periods, date ) {
		var out = { closed: false, upcoming: false, next: 0, kinds: {}, upcomingKinds: {} };

		periods.forEach( function ( period ) {
			if ( ! Array.isArray( period ) || period.length < 2 || Number( period[ 1 ] ) < date ) {
				return;
			}

			var kind = period[ 2 ] || OTHER;
			var start = Number( period[ 0 ] );

			out.upcoming = true;
			out.upcomingKinds[ kind ] = true;

			if ( start <= date ) {
				out.closed = true;
				out.kinds[ kind ] = true;
			} else if ( ! out.next || start < out.next ) {
				out.next = start;
			}
		} );

		return out;
	}

	/**
	 * Une condition d'affichage est-elle remplie ? Voir Pivot_Closures::matches().
	 */
	function matches( roles, state, kind ) {
		var closed = kind ? !! state.kinds[ kind ] : state.closed;
		var upcoming = kind ? !! state.upcomingKinds[ kind ] : state.upcoming;

		return roles.split( /\s+/ ).every( function ( role ) {
			switch ( role ) {
				case 'today':
					return closed;
				case 'upcoming':
					return upcoming;
				case 'open':
					return ! state.closed;
				case 'next':
					return !! state.next;
				case 'none':
					return ! state.upcoming;
				default:
					return true;
			}
		} );
	}

	/**
	 * Ce qui appartient à ce bloc, et non à un bloc imbriqué.
	 */
	function own( node, selector ) {
		return Array.prototype.filter.call( node.querySelectorAll( selector ), function ( element ) {
			return element.closest( '[data-pivot-closures]' ) === node;
		} );
	}

	function apply( node ) {
		var date = today();
		var state = status( periodsOf( node ), date );

		own( node, '[data-pivot-closure]' ).forEach( function ( element ) {
			element.hidden = ! matches(
				element.getAttribute( 'data-pivot-closure' ) || '',
				state,
				element.getAttribute( 'data-pivot-closure-kind' ) || ''
			);
		} );

		own( node, '[data-pivot-closure-date]' ).forEach( function ( element ) {
			var which = element.getAttribute( 'data-pivot-closure-date' );
			var value = which === 'next' ? state.next : date;

			element.textContent = value ? format( value ) : '';
		} );

		// Lignes d'un calendrier : une date passée disparaît, celle du jour
		// est signalée.
		own( node, '[data-pivot-closure-end]' ).forEach( function ( element ) {
			var end = Number( element.getAttribute( 'data-pivot-closure-end' ) );
			var start = Number( element.getAttribute( 'data-pivot-closure-start' ) ) || end;

			element.hidden = end < date;
			element.classList.toggle( 'is-today', start <= date && date <= end );
		} );

		own( node, '[data-pivot-closure-group]' ).forEach( function ( group ) {
			group.hidden = ! Array.prototype.some.call( group.querySelectorAll( '[data-pivot-closure-end]' ), function ( row ) {
				return ! row.hidden;
			} );
		} );

		node.classList.toggle( 'is-closed', state.closed );
		node.classList.toggle( 'has-closures', state.upcoming );
	}

	function refresh( root ) {
		var scope = root && root.querySelectorAll ? root : document;

		if ( scope.matches && scope.matches( '[data-pivot-closures]' ) ) {
			apply( scope );
		}

		Array.prototype.forEach.call( scope.querySelectorAll( '[data-pivot-closures]' ), apply );
	}

	window.pivotClosures = { refresh: refresh, status: status, format: format };

	document.addEventListener( 'pivot:rendered', function ( event ) {
		refresh( event.target );
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			refresh( document );
		} );
	} else {
		refresh( document );
	}
} )();
