/**
 * Recherche, filtres, pagination et carte d'une page de listing.
 *
 * Toutes les offres de la page sont chargées une seule fois depuis un index
 * JSON pré-calculé. Chaque interaction du visiteur est ensuite traitée dans le
 * navigateur : aucun appel au webservice PIVOT n'est déclenché.
 */
( function () {
	'use strict';

	var config = window.pivotListingData || {};
	var root = document.getElementById( 'pivot-listing' );

	if ( ! root || ! config.indexUrl ) {
		return;
	}

	var grid = document.getElementById( 'pivot-grid' );
	// Un thème à grille Bootstrap pose chaque vignette dans une colonne
	// (« col-12 col-sm-6 col-lg-4 ») : il en donne les classes ici, et fait de
	// même dans son rendu serveur.
	var columnClass = grid ? ( grid.getAttribute( 'data-column-class' ) || '' ).trim() : '';
	var countNode = document.getElementById( 'pivot-count' );
	var paginationNode = document.getElementById( 'pivot-pagination' );
	var form = document.getElementById( 'pivot-criteria' );
	var searchInput = document.getElementById( 'pivot-q' );
	var resetButton = document.getElementById( 'pivot-reset' );
	var mapNode = document.getElementById( 'pivot-map' );

	var state = {
		items: [],
		filtered: [],
		page: parseInt( config.page, 10 ) || 1,
		perPage: parseInt( config.perPage, 10 ) || 12,
		query: '',
		filters: {},
		// Critères numériques : clé => { min, max }, l'une des bornes pouvant
		// valoir null. Une égalité pose les deux à la même valeur.
		ranges: {},
		// Critères de date : même forme, en entiers AAAAMMJJ.
		dates: {}
	};

	var map = null;
	var markerLayer = null;

	// Tracé GPX affiché sur la carte : un seul à la fois. Les tracés lus sont
	// gardés, par code d'offre, le temps de la page.
	var track = { layer: null, code: '', loaded: {} };

	// Critère de date => ce qu'il compare : overlap, start, end ou point.
	var dateMatches = {};

	/* ---------------------------------------------------------------- outils */

	function normalize( value ) {
		if ( ! value ) {
			return '';
		}
		return String( value )
			.normalize( 'NFD' )
			.replace( /[\u0300-\u036f]/g, '' )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, ' ' )
			.trim();
	}

	function escapeHtml( value ) {
		if ( value === null || value === undefined ) {
			return '';
		}
		return String( value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function debounce( fn, wait ) {
		var timer = null;
		return function () {
			var context = this;
			var args = arguments;
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				fn.apply( context, args );
			}, wait );
		};
	}

	function text( key ) {
		return ( config.i18n && config.i18n[ key ] ) || '';
	}

	/* ------------------------------------------------- critères numériques */

	function rangeFields() {
		return form ? form.querySelectorAll( '[data-range]' ) : [];
	}

	/**
	 * Lit un nombre tapé par le visiteur : « 9,50 » comme « 9.50 », espaces de
	 * groupement ignorés. null pour un champ vide, NaN pour autre chose qu'un
	 * nombre.
	 */
	function parseNumber( value ) {
		var clean = String( value === null || value === undefined ? '' : value )
			.replace( /[\s\u00a0\u202f]/g, '' )
			.replace( ',', '.' );

		if ( ! clean ) {
			return null;
		}

		return /^[-+]?(\d+(\.\d*)?|\.\d+)$/.test( clean ) ? parseFloat( clean ) : NaN;
	}

	function formatValue( field, value ) {
		var unit = field.getAttribute( 'data-unit' );
		var formatted;

		try {
			formatted = new Intl.NumberFormat( config.lang || undefined, { maximumFractionDigits: 2 } ).format( value );
		} catch ( error ) {
			formatted = String( value );
		}

		return unit ? formatted + '\u00a0' + unit : formatted;
	}

	/**
	 * Signale une saisie qui n'est pas un nombre, sans la corriger : le
	 * visiteur voit ce qu'il a tapé et pourquoi le critère ne s'applique pas.
	 */
	function setInvalid( input, invalid ) {
		var field = input.closest( '[data-range]' );

		if ( invalid ) {
			input.setAttribute( 'aria-invalid', 'true' );
		} else {
			input.removeAttribute( 'aria-invalid' );
		}

		var message = field && field.querySelector( '.pivot-field-error' );

		if ( message ) {
			message.hidden = ! field.querySelector( '[aria-invalid="true"]' );
		}
	}

	/**
	 * Bornes demandées par un critère numérique, ou null s'il n'est pas
	 * utilisé. Une jauge laissée en butée ne restreint rien ; une saisie
	 * illisible est ignorée, et signalée.
	 */
	function readRange( field ) {
		var bounds = { min: null, max: null };

		Array.prototype.forEach.call( field.querySelectorAll( '[data-bound]' ), function ( input ) {
			var bound = input.getAttribute( 'data-bound' );
			var value;

			if ( input.type === 'range' ) {
				value = parseFloat( input.value );

				if ( value === parseFloat( input.getAttribute( 'data-neutral' ) ) ) {
					value = null;
				}
			} else {
				value = parseNumber( input.value );
				setInvalid( input, Number.isNaN( value ) );

				if ( Number.isNaN( value ) ) {
					value = null;
				}
			}

			if ( value === null ) {
				return;
			}

			if ( bound === 'eq' ) {
				bounds.min = value;
				bounds.max = value;
			} else {
				bounds[ bound ] = value;
			}
		} );

		if ( bounds.min === null && bounds.max === null ) {
			return null;
		}

		// « entre 50 et 10 » : le visiteur a inversé les bornes, pas changé
		// d'avis.
		if ( bounds.min !== null && bounds.max !== null && bounds.min > bounds.max ) {
			bounds = { min: bounds.max, max: bounds.min };
		}

		return bounds;
	}

	/**
	 * Texte d'une jauge : « au moins 3 », « entre 10 € et 50 € », « Indifférent ».
	 */
	function updateOutput( field ) {
		var output = field.querySelector( '.pivot-range-output' );

		if ( ! output ) {
			return;
		}

		Array.prototype.forEach.call( field.querySelectorAll( 'input[type="range"]' ), function ( input ) {
			input.setAttribute( 'aria-valuetext', formatValue( field, parseFloat( input.value ) ) );
		} );

		var bounds = readRange( field ) || { min: null, max: null };
		var label;

		if ( bounds.min !== null && bounds.max !== null ) {
			label = field.getAttribute( 'data-format-both' )
				.replace( '%1$s', formatValue( field, bounds.min ) )
				.replace( '%2$s', formatValue( field, bounds.max ) );
		} else if ( bounds.min !== null ) {
			label = field.getAttribute( 'data-format-min' ).replace( '%s', formatValue( field, bounds.min ) );
		} else if ( bounds.max !== null ) {
			label = field.getAttribute( 'data-format-max' ).replace( '%s', formatValue( field, bounds.max ) );
		} else {
			label = field.getAttribute( 'data-any' );
		}

		output.textContent = label;
	}

	/**
	 * Les deux curseurs d'un intervalle ne se croisent pas : celui qu'on
	 * pousse entraîne l'autre.
	 */
	function keepOrder( input ) {
		var field = input.closest( '[data-range]' );
		var low = field && field.querySelector( 'input[type="range"][data-bound="min"]' );
		var high = field && field.querySelector( 'input[type="range"][data-bound="max"]' );

		if ( ! low || ! high || parseFloat( low.value ) <= parseFloat( high.value ) ) {
			return;
		}

		if ( input === low ) {
			high.value = low.value;
		} else {
			low.value = high.value;
		}
	}

	/**
	 * Une valeur de l'offre tombe-t-elle entre les bornes ? Une offre sans
	 * valeur pour ce champ ne répond pas au critère.
	 */
	function inRange( values, bounds ) {
		for ( var i = 0; i < values.length; i++ ) {
			var value = typeof values[ i ] === 'number' ? values[ i ] : parseNumber( values[ i ] );

			if ( value === null || Number.isNaN( value ) ) {
				continue;
			}

			if ( ( bounds.min === null || value >= bounds.min ) && ( bounds.max === null || value <= bounds.max ) ) {
				return true;
			}
		}

		return false;
	}

	/* ------------------------------------------------------ critères de date */

	function dateFields() {
		return form ? form.querySelectorAll( '[data-dates]' ) : [];
	}

	/**
	 * Lit une date : « 2026-10-10 » comme l'envoie un champ de date, ou
	 * « 10/10/2026 » tapé dans un navigateur qui n'en propose pas. Rend un
	 * entier AAAAMMJJ, comme ceux de l'index, ou null.
	 */
	function parseDate( value ) {
		var clean = String( value === null || value === undefined ? '' : value ).trim();
		var match = clean.match( /^(\d{4})-(\d{1,2})-(\d{1,2})$/ );
		var year, month, day;

		if ( match ) {
			year = Number( match[ 1 ] );
			month = Number( match[ 2 ] );
			day = Number( match[ 3 ] );
		} else {
			match = clean.match( /^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/ );

			if ( ! match ) {
				return null;
			}

			day = Number( match[ 1 ] );
			month = Number( match[ 2 ] );
			year = Number( match[ 3 ] );
		}

		// Le 31/02 n'existe pas : Date le reporterait au 3 mars.
		var date = new Date( year, month - 1, day );

		if ( date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day ) {
			return null;
		}

		return year * 10000 + month * 100 + day;
	}

	function isoDate( value ) {
		var text = String( value );

		return text.slice( 0, 4 ) + '-' + text.slice( 4, 6 ) + '-' + text.slice( 6, 8 );
	}

	/**
	 * Aujourd'hui, à l'heure du visiteur.
	 */
	function today() {
		var now = new Date();

		return now.getFullYear() * 10000 + ( now.getMonth() + 1 ) * 100 + now.getDate();
	}

	/**
	 * Dates demandées par un critère, ou null s'il n'est pas utilisé.
	 */
	function readDates( field ) {
		var bounds = { min: null, max: null };

		Array.prototype.forEach.call( field.querySelectorAll( '[data-bound]' ), function ( input ) {
			var bound = input.getAttribute( 'data-bound' );
			var value = parseDate( input.value );

			if ( value === null ) {
				return;
			}

			if ( bound === 'eq' ) {
				bounds.min = value;
				bounds.max = value;
			} else {
				bounds[ bound ] = value;
			}
		} );

		if ( bounds.min === null && bounds.max === null ) {
			return null;
		}

		// « du 31 au 10 » : les dates ont été saisies à l'envers.
		if ( bounds.min !== null && bounds.max !== null && bounds.min > bounds.max ) {
			bounds = { min: bounds.max, max: bounds.min };
		}

		return bounds;
	}

	/**
	 * Une période de l'offre répond-elle aux dates demandées ?
	 *
	 * L'index donne les périodes en [début, fin]. Selon le champ choisi par
	 * l'administrateur, on compare la période entière (elle touche
	 * l'intervalle : l'offre a lieu pendant), sa date de début, sa date de
	 * fin, ou une date isolée.
	 *
	 * Sans première date, une période déjà terminée ne compte pas :
	 * « jusqu'au 31 octobre » se lit « d'aujourd'hui au 31 octobre ». Sans
	 * cela, un spectacle joué en mars et en décembre répondrait présent pour
	 * octobre, au titre de mars.
	 */
	function inPeriods( periods, bounds, match ) {
		var floor = bounds.min === null && match !== 'point' ? today() : null;

		function within( value ) {
			return ( bounds.min === null || value >= bounds.min ) && ( bounds.max === null || value <= bounds.max );
		}

		for ( var i = 0; i < periods.length; i++ ) {
			var period = periods[ i ];

			if ( ! Array.isArray( period ) || period.length !== 2 ) {
				continue;
			}

			if ( floor !== null && period[ 1 ] < floor ) {
				continue;
			}

			var found;

			if ( match === 'start' || match === 'point' ) {
				found = within( period[ 0 ] );
			} else if ( match === 'end' ) {
				found = within( period[ 1 ] );
			} else {
				found = ( bounds.max === null || period[ 0 ] <= bounds.max ) && ( bounds.min === null || period[ 1 ] >= bounds.min );
			}

			if ( found ) {
				return true;
			}
		}

		return false;
	}

	/* ------------------------------------------------------------ chargement */

	function loadIndex() {
		announce( text( 'loading' ) );

		fetchJson( config.indexUrl )
			.catch( function () {
				// Le fichier statique peut être indisponible : on retente par REST.
				return config.restUrl ? fetchJson( config.restUrl ) : Promise.reject();
			} )
			.then( function ( data ) {
				state.items = ( data && data.items ) || [];
				if ( data && data.perPage ) {
					state.perPage = parseInt( data.perPage, 10 ) || state.perPage;
				}
				readStateFromUrl();
				apply( false );
			} )
			.catch( function () {
				announce( text( 'error' ) );
			} );
	}

	function fetchJson( url ) {
		return window.fetch( url, { credentials: 'same-origin' } ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}
			return response.json();
		} );
	}

	/* ------------------------------------------------------------------ état */

	function readStateFromUrl() {
		var params = new URLSearchParams( window.location.search );

		state.query = params.get( 'q' ) || '';
		state.filters = {};
		state.ranges = {};
		state.dates = {};

		if ( searchInput ) {
			searchInput.value = state.query;
		}

		var pathMatch = window.location.pathname.match( /\/page\/(\d+)\/?$/ );
		state.page = pathMatch ? parseInt( pathMatch[ 1 ], 10 ) : parseInt( params.get( 'page' ), 10 ) || 1;

		if ( ! form ) {
			return;
		}

		Array.prototype.forEach.call( form.querySelectorAll( '[data-filter]' ), function ( field ) {
			var key = field.getAttribute( 'data-filter' );
			var values = params.getAll( key ).concat( params.getAll( key + '[]' ) );

			if ( ! values.length ) {
				return;
			}

			state.filters[ key ] = values;

			var select = field.querySelector( 'select' );
			if ( select ) {
				select.value = values[ 0 ];
				return;
			}

			var textField = field.querySelector( 'input[type="text"]' );
			if ( textField ) {
				textField.value = values[ 0 ];
				return;
			}

			Array.prototype.forEach.call( field.querySelectorAll( 'input[type="checkbox"]' ), function ( box ) {
				box.checked = values.indexOf( box.value ) !== -1 || ( box.value === '1' && values.indexOf( '1' ) !== -1 );
			} );
		} );

		Array.prototype.forEach.call( rangeFields(), function ( field ) {
			Array.prototype.forEach.call( field.querySelectorAll( '[data-bound]' ), function ( input ) {
				var raw = params.get( input.name );

				if ( input.type === 'range' ) {
					var value = parseNumber( raw );
					// Le navigateur ramène de lui-même une valeur hors bornes.
					input.value = value === null || Number.isNaN( value ) ? input.getAttribute( 'data-neutral' ) : value;
				} else {
					input.value = raw || '';
				}
			} );

			var bounds = readRange( field );

			if ( bounds ) {
				state.ranges[ field.getAttribute( 'data-range' ) ] = bounds;
			}

			updateOutput( field );
		} );

		Array.prototype.forEach.call( dateFields(), function ( field ) {
			Array.prototype.forEach.call( field.querySelectorAll( '[data-bound]' ), function ( input ) {
				var value = parseDate( params.get( input.name ) );
				input.value = value === null ? '' : isoDate( value );
			} );

			var bounds = readDates( field );

			if ( bounds ) {
				state.dates[ field.getAttribute( 'data-dates' ) ] = bounds;
			}
		} );
	}

	function readStateFromForm() {
		state.query = searchInput ? searchInput.value : '';
		state.filters = {};
		state.ranges = {};
		state.dates = {};

		if ( ! form ) {
			return;
		}

		Array.prototype.forEach.call( form.querySelectorAll( '[data-filter]' ), function ( field ) {
			var key = field.getAttribute( 'data-filter' );
			var values = [];

			var select = field.querySelector( 'select' );
			if ( select && select.value ) {
				values.push( select.value );
			}

			var textField = field.querySelector( 'input[type="text"]' );
			if ( textField && textField.value.trim() ) {
				values.push( textField.value.trim() );
			}

			Array.prototype.forEach.call( field.querySelectorAll( 'input[type="checkbox"]:checked' ), function ( box ) {
				values.push( box.value );
			} );

			if ( values.length ) {
				state.filters[ key ] = values;
			}
		} );

		Array.prototype.forEach.call( rangeFields(), function ( field ) {
			var bounds = readRange( field );

			if ( bounds ) {
				state.ranges[ field.getAttribute( 'data-range' ) ] = bounds;
			}

			updateOutput( field );
		} );

		Array.prototype.forEach.call( dateFields(), function ( field ) {
			var bounds = readDates( field );

			if ( bounds ) {
				state.dates[ field.getAttribute( 'data-dates' ) ] = bounds;
			}
		} );
	}

	/**
	 * Paramètres d'URL pilotés par la page.
	 *
	 * Tout ce qui n'est pas dans cette liste appartient à quelqu'un d'autre et
	 * doit survivre à la réécriture de l'adresse.
	 */
	function ownedKeys() {
		var keys = [ 'q', 'page' ];

		if ( form ) {
			Array.prototype.forEach.call( form.querySelectorAll( '[data-filter]' ), function ( field ) {
				var key = field.getAttribute( 'data-filter' );

				if ( key ) {
					keys.push( key, key + '[]' );
				}
			} );

			Array.prototype.forEach.call( form.querySelectorAll( '[data-range] [data-bound], [data-dates] [data-bound]' ), function ( input ) {
				keys.push( input.name );
			} );
		}

		return keys;
	}

	function writeStateToUrl( replace ) {
		// On repart de l'adresse courante, et non d'une chaîne vide : sinon les
		// paramètres de campagne (utm_*, gclid, fbclid, suivi d'affiliation…)
		// seraient effacés dès le premier rendu, avant même que les scripts de
		// mesure n'aient pu les lire, et l'attribution des campagnes serait
		// faussée.
		var params = new URLSearchParams( window.location.search );

		ownedKeys().forEach( function ( key ) {
			params.delete( key );
		} );

		if ( state.query ) {
			params.set( 'q', state.query );
		}

		Object.keys( state.filters ).forEach( function ( key ) {
			state.filters[ key ].forEach( function ( value ) {
				params.append( key, value );
			} );
		} );

		// Une borne par paramètre, sous le nom de son champ : ?prix_max=50.
		Array.prototype.forEach.call( rangeFields(), function ( field ) {
			var bounds = state.ranges[ field.getAttribute( 'data-range' ) ];

			if ( ! bounds ) {
				return;
			}

			Array.prototype.forEach.call( field.querySelectorAll( '[data-bound]' ), function ( input ) {
				var value = input.getAttribute( 'data-bound' ) === 'max' ? bounds.max : bounds.min;

				if ( value !== null ) {
					params.set( input.name, String( value ) );
				}
			} );
		} );

		// Une date par paramètre, au format ISO : ?date_min=2026-10-01.
		Array.prototype.forEach.call( dateFields(), function ( field ) {
			var bounds = state.dates[ field.getAttribute( 'data-dates' ) ];

			if ( ! bounds ) {
				return;
			}

			Array.prototype.forEach.call( field.querySelectorAll( '[data-bound]' ), function ( input ) {
				var value = input.getAttribute( 'data-bound' ) === 'max' ? bounds.max : bounds.min;

				if ( value !== null ) {
					params.set( input.name, isoDate( value ) );
				}
			} );
		} );

		// La pagination s'écrit dans le chemin, « /page/2/ », comme celle rendue
		// par le serveur. Elle s'écrivait ici « ?page=2 » : en arrivant sur
		// /liste/page/2/ le script réécrivait aussitôt l'adresse en /liste/?page=2,
		// ce qui donnait deux URL pour un même contenu, dont une seule connue de
		// la balise canonique.
		var base = config.baseUrl.replace( /\/+$/, '' ) + '/';

		if ( state.page > 1 ) {
			base += 'page/' + state.page + '/';
		}

		var query = params.toString();
		var url = base + ( query ? '?' + query : '' );

		if ( replace ) {
			window.history.replaceState( null, '', url );
		} else {
			window.history.pushState( null, '', url );
		}
	}

	/* ------------------------------------------------------------- filtrage */

	function matches( item ) {
		if ( state.query ) {
			var needles = normalize( state.query ).split( ' ' ).filter( Boolean );
			var haystack = item.s || normalize( [ item.n, item.l, item.m, item.z, item.tl, item.d ].join( ' ' ) );

			for ( var i = 0; i < needles.length; i++ ) {
				if ( haystack.indexOf( needles[ i ] ) === -1 ) {
					return false;
				}
			}
		}

		var keys = Object.keys( state.filters );

		for ( var k = 0; k < keys.length; k++ ) {
			var key = keys[ k ];
			var wanted = state.filters[ key ];
			var owned = ( item.f && item.f[ key ] ) || [];

			if ( ! owned.length ) {
				return false;
			}

			var found = false;

			for ( var w = 0; w < wanted.length; w++ ) {
				var needle = normalize( wanted[ w ] );

				for ( var o = 0; o < owned.length; o++ ) {
					var candidate = normalize( owned[ o ] );
					if ( candidate === needle || candidate.indexOf( needle ) !== -1 ) {
						found = true;
						break;
					}
				}

				if ( found ) {
					break;
				}
			}

			if ( ! found ) {
				return false;
			}
		}

		var rangeKeys = Object.keys( state.ranges );

		for ( var r = 0; r < rangeKeys.length; r++ ) {
			if ( ! inRange( ( item.f && item.f[ rangeKeys[ r ] ] ) || [], state.ranges[ rangeKeys[ r ] ] ) ) {
				return false;
			}
		}

		var dateKeys = Object.keys( state.dates );

		for ( var d = 0; d < dateKeys.length; d++ ) {
			if ( ! inPeriods( ( item.f && item.f[ dateKeys[ d ] ] ) || [], state.dates[ dateKeys[ d ] ], dateMatches[ dateKeys[ d ] ] ) ) {
				return false;
			}
		}

		return true;
	}

	function apply( pushUrl ) {
		dateMatches = {};

		Array.prototype.forEach.call( dateFields(), function ( field ) {
			dateMatches[ field.getAttribute( 'data-dates' ) ] = field.getAttribute( 'data-match' ) || 'overlap';
		} );

		state.filtered = state.items.filter( matches );

		var pages = Math.max( 1, Math.ceil( state.filtered.length / state.perPage ) );
		if ( state.page > pages ) {
			state.page = pages;
		}

		renderCount();
		renderGrid();
		renderPagination( pages );
		renderMap();

		// La grille vient d'être réécrite : un script qui décore les vignettes
		// (pivot-closures.js, celui du thème) s'y raccroche ici.
		if ( grid && typeof window.CustomEvent === 'function' ) {
			grid.dispatchEvent( new window.CustomEvent( 'pivot:rendered', { bubbles: true } ) );
		}

		writeStateToUrl( ! pushUrl );
	}

	/* --------------------------------------------------------------- rendus */

	function renderCount() {
		if ( ! countNode ) {
			return;
		}
		countNode.textContent = state.filtered.length + ' ' + text( 'results' );
	}

	function announce( message ) {
		if ( countNode ) {
			countNode.textContent = message;
		}
	}

	function renderGrid() {
		if ( ! grid ) {
			return;
		}

		var start = ( state.page - 1 ) * state.perPage;
		var slice = state.filtered.slice( start, start + state.perPage );

		if ( ! slice.length ) {
			grid.innerHTML = '<p class="pivot-empty-results">' + escapeHtml( text( 'noResult' ) ) + '</p>';
			return;
		}

		grid.innerHTML = slice.map( column ).join( '' );
	}

	function column( item ) {
		if ( ! columnClass ) {
			return card( item );
		}

		return '<div class="' + escapeHtml( columnClass ) + '">' + card( item ) + '</div>';
	}

	function card( item ) {
		// Vignette rendue par le serveur, à partir du gabarit du thème pour ce
		// type d'offre. Elle est reprise telle quelle : le rendu ci-dessous ne
		// sert que pour le gabarit commun, dont il reproduit la structure.
		if ( item.h ) {
			return item.h;
		}

		var url = escapeHtml( item.u || '#' );
		var name = escapeHtml( item.n || '' );
		var html = '<article class="pivot-card">';

		if ( item.i ) {
			html += '<div class="pivot-card-media">';
			html += '<a href="' + url + '" tabindex="-1" aria-hidden="true">';
			html += '<img src="' + escapeHtml( item.i ) + '" alt="' + name + '" loading="lazy" decoding="async" />';
			html += '</a>' + closures( item ) + '</div>';
		}

		html += '<div class="pivot-card-body">';

		if ( ! item.i ) {
			html += closures( item );
		}

		if ( item.tl ) {
			html += '<p class="pivot-card-type">' + escapeHtml( item.tl ) + '</p>';
		}

		html += '<h2 class="pivot-card-title"><a href="' + url + '">' + name + '</a></h2>';

		if ( item.z || item.l ) {
			html += '<p class="pivot-card-place">' + escapeHtml( [ item.z, item.l ].filter( Boolean ).join( ' ' ) ) + '</p>';
		}

		if ( item.d ) {
			html += '<p class="pivot-card-excerpt">' + escapeHtml( item.d ) + '</p>';
		}

		html += '<p class="pivot-card-action"><a class="pivot-button pivot-button-ghost" href="' + url + '">' + escapeHtml( text( 'seeOffer' ) ) + '</a></p>';
		html += '</div></article>';

		return html;
	}

	/**
	 * Pastilles de fermeture d'une vignette commune, comme
	 * Pivot_Closures::badges() les écrit. Elles sortent masquées :
	 * pivot-closures.js les révèle selon la date du visiteur, dès l'événement
	 * pivot:rendered.
	 */
	function closures( item ) {
		if ( ! Array.isArray( item.cl ) || ! item.cl.length ) {
			return '';
		}

		var i18n = config.closures || {};
		var closedOn = i18n.closedOn || {};
		var impacts = i18n.impacts || {};
		var kinds = [];

		item.cl.forEach( function ( period ) {
			var kind = period[ 2 ] || 'autre';

			if ( kinds.indexOf( kind ) === -1 ) {
				kinds.push( kind );
			}
		} );

		var html = '<div class="pivot-closures" data-pivot-closures="' + escapeHtml( JSON.stringify( item.cl ) ) + '">';

		html += '<p class="pivot-closure-today" data-pivot-closure="today" hidden>' +
			escapeHtml( closedOn[ item.t ] || closedOn[ '' ] || '%s' ).replace( '%s', '<span data-pivot-closure-date="today"></span>' ) +
			'</p>';

		kinds.forEach( function ( kind ) {
			html += '<a class="pivot-closure-impact" href="' + escapeHtml( ( item.u || '' ) + '#' + ( i18n.anchor || '' ) ) + '"' +
				' title="' + escapeHtml( i18n.more || '' ) + '" data-pivot-closure="upcoming" data-pivot-closure-kind="' + escapeHtml( kind ) + '" hidden>' +
				escapeHtml( impacts[ kind ] || impacts.autre || '' ) + ' <span class="pivot-closure-more" aria-hidden="true">+</span></a>';
		} );

		return html + '</div>';
	}

	function renderPagination( pages ) {
		if ( ! paginationNode ) {
			return;
		}

		if ( pages <= 1 ) {
			paginationNode.innerHTML = '';
			paginationNode.hidden = true;
			return;
		}

		paginationNode.hidden = false;

		var html = '';
		var window_ = 2;

		if ( state.page > 1 ) {
			html += '<button type="button" class="pivot-page prev" data-page="' + ( state.page - 1 ) + '">‹</button>';
		}

		for ( var page = 1; page <= pages; page++ ) {
			var near = Math.abs( page - state.page ) <= window_;
			var edge = page === 1 || page === pages;

			if ( ! near && ! edge ) {
				if ( page === 2 || page === pages - 1 ) {
					html += '<span class="pivot-page-dots">…</span>';
				}
				continue;
			}

			html += '<button type="button" class="pivot-page' + ( page === state.page ? ' is-current' : '' ) +
				'" data-page="' + page + '"' + ( page === state.page ? ' aria-current="page"' : '' ) + '>' + page + '</button>';
		}

		if ( state.page < pages ) {
			html += '<button type="button" class="pivot-page next" data-page="' + ( state.page + 1 ) + '">›</button>';
		}

		paginationNode.innerHTML = html;
	}

	/* ---------------------------------------------------------------- carte */

	function renderMap() {
		if ( ! mapNode || ! config.showMap || typeof window.L === 'undefined' ) {
			return;
		}

		if ( ! map ) {
			map = window.L.map( mapNode, { scrollWheelZoom: false } );

			window.L.tileLayer( config.mapTiles || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
				attribution: config.mapAttr || '',
				maxZoom: 19
			} ).addTo( map );

			var center = ( config.mapCenter || '' ).split( ',' );
			if ( center.length === 2 ) {
				map.setView( [ parseFloat( center[ 0 ] ), parseFloat( center[ 1 ] ) ], parseInt( config.mapZoom, 10 ) || 9 );
			} else {
				map.setView( [ 50.45, 4.85 ], parseInt( config.mapZoom, 10 ) || 8 );
			}
		}

		if ( markerLayer ) {
			map.removeLayer( markerLayer );
		}

		markerLayer = config.mapCluster && window.L.markerClusterGroup
			? window.L.markerClusterGroup()
			: window.L.layerGroup();

		var bounds = [];

		state.filtered.forEach( function ( item ) {
			if ( typeof item.lat !== 'number' || typeof item.lng !== 'number' ) {
				return;
			}

			var marker = window.L.marker( [ item.lat, item.lng ] );
			var popup = '<div class="pivot-map-popup"><strong>' + escapeHtml( item.n || '' ) + '</strong>';

			if ( item.z || item.l ) {
				popup += '<br />' + escapeHtml( [ item.z, item.l ].filter( Boolean ).join( ' ' ) );
			}

			if ( item.u ) {
				popup += '<br /><a href="' + escapeHtml( item.u ) + '">' + escapeHtml( text( 'seeOffer' ) ) + '</a>';
			}

			if ( hasTrack( item ) ) {
				popup += '<p class="pivot-map-track"><button type="button" class="pivot-button pivot-map-track-button" data-pivot-track aria-pressed="false">' +
					escapeHtml( text( 'showTrack' ) ) + '</button></p>';
			}

			marker.bindPopup( popup + '</div>' );

			// La bulle est recréée à chaque ouverture : son bouton aussi.
			marker.on( 'popupopen', function ( event ) {
				bindTrackButton( event.popup.getElement(), item );
			} );

			markerLayer.addLayer( marker );
			bounds.push( [ item.lat, item.lng ] );
		} );

		markerLayer.addTo( map );

		if ( bounds.length && ! config.mapCenter ) {
			map.fitBounds( bounds, { padding: [ 24, 24 ], maxZoom: 14 } );
		}
	}

	/* ------------------------------------------------------- tracé GPX */

	/**
	 * La bulle d'une offre propose-t-elle son tracé ?
	 *
	 * Oui si l'index connaît son GPX (page en « complet avec offres
	 * liées »). Sinon, sur la foi du type — un itinéraire —, quand la page
	 * sait le chercher au clic (config.tracks.lookup).
	 */
	function hasTrack( item ) {
		var tracks = config.tracks || {};

		if ( item.g ) {
			return true;
		}

		return !! tracks.lookup && ( tracks.types || [] ).indexOf( Number( item.t ) ) !== -1;
	}

	function trackUrl( item ) {
		if ( item.g ) {
			return Promise.resolve( item.g );
		}

		return fetchJson( config.tracks.lookup + encodeURIComponent( item.c ) ).then( function ( data ) {
			if ( ! data || ! data.url ) {
				throw new Error( 'GPX absent' );
			}

			item.g = data.url;

			return data.url;
		} );
	}

	/**
	 * Lignes d'un fichier GPX : une par segment de trace, à défaut une par
	 * route. Chaque ligne est une liste de [lat, lng].
	 */
	function parseGpx( xml ) {
		var doc = new window.DOMParser().parseFromString( xml, 'application/xml' );

		function lines( container, point ) {
			var out = [];

			Array.prototype.forEach.call( doc.getElementsByTagNameNS( '*', container ), function ( segment ) {
				var points = [];

				Array.prototype.forEach.call( segment.getElementsByTagNameNS( '*', point ), function ( node ) {
					var lat = parseFloat( node.getAttribute( 'lat' ) );
					var lng = parseFloat( node.getAttribute( 'lon' ) );

					if ( isFinite( lat ) && isFinite( lng ) ) {
						points.push( [ lat, lng ] );
					}
				} );

				if ( points.length > 1 ) {
					out.push( points );
				}
			} );

			return out;
		}

		var tracks = lines( 'trkseg', 'trkpt' );

		return tracks.length ? tracks : lines( 'rte', 'rtept' );
	}

	function loadTrack( item ) {
		if ( ! track.loaded[ item.c ] ) {
			track.loaded[ item.c ] = trackUrl( item )
				.then( function ( url ) {
					return window.fetch( url, { credentials: 'omit' } );
				} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'HTTP ' + response.status );
					}
					return response.text();
				} )
				.then( function ( xml ) {
					var found = parseGpx( xml );

					if ( ! found.length ) {
						throw new Error( 'GPX vide' );
					}
					return found;
				} );

			// Un échec n'est pas retenu : un nouveau clic retente.
			track.loaded[ item.c ].catch( function () {
				delete track.loaded[ item.c ];
			} );
		}

		return track.loaded[ item.c ];
	}

	function hideTrack() {
		if ( track.layer ) {
			map.removeLayer( track.layer );
		}

		var code = track.code;

		track.layer = null;
		track.code = '';

		if ( code ) {
			announceTrack( code, false );
		}
	}

	/**
	 * @param {Object}      item      Offre.
	 * @param {Array}       found     Lignes du tracé.
	 * @param {HTMLElement} popupNode Bulle ouverte, à garder visible au-dessus du tracé.
	 */
	function drawTrack( item, found, popupNode ) {
		var style = ( config.tracks && config.tracks.style ) || {};
		var weight = Number( style.weight ) || 4;

		hideTrack();

		// Un liseré blanc sous le trait : lisible sur tous les fonds de carte.
		track.layer = window.L.featureGroup( [
			window.L.polyline( found, { color: '#fff', weight: weight + 4, opacity: 0.85, interactive: false, className: 'pivot-map-track-casing' } ),
			window.L.polyline( found, {
				color: style.color || '#c2185b',
				weight: weight,
				opacity: style.opacity || 0.9,
				interactive: false,
				className: 'pivot-map-track-line'
			} )
		] ).addTo( map );

		track.code = item.c;
		// La bulle reste ouverte au-dessus du pointeur : on lui garde sa
		// hauteur en haut de la carte, sans quoi elle en sortirait.
		var top = popupNode && popupNode.offsetHeight ? popupNode.offsetHeight + 48 : 32;

		map.fitBounds( track.layer.getBounds(), { paddingTopLeft: [ 32, top ], paddingBottomRight: [ 32, 32 ] } );
		announceTrack( item.c, true );
	}

	/**
	 * Un tracé vient d'apparaître ou de disparaître : pivot:track, sur la
	 * carte, pour un script de thème.
	 */
	function announceTrack( code, shown ) {
		if ( typeof window.CustomEvent === 'function' ) {
			mapNode.dispatchEvent( new window.CustomEvent( 'pivot:track', { bubbles: true, detail: { code: code, shown: shown } } ) );
		}
	}

	function setTrackButton( button, key, busy ) {
		button.textContent = text( key );
		button.disabled = !! busy;
		button.setAttribute( 'aria-pressed', key === 'hideTrack' ? 'true' : 'false' );
		button.classList.toggle( 'is-error', key === 'noTrack' );
	}

	function bindTrackButton( popupNode, item ) {
		var button = popupNode && popupNode.querySelector( '[data-pivot-track]' );

		if ( ! button ) {
			return;
		}

		setTrackButton( button, track.code === item.c ? 'hideTrack' : 'showTrack', false );

		button.addEventListener( 'click', function () {
			if ( track.code === item.c ) {
				hideTrack();
				setTrackButton( button, 'showTrack', false );
				return;
			}

			setTrackButton( button, 'loadingTrack', true );

			loadTrack( item ).then(
				function ( found ) {
					drawTrack( item, found, popupNode );
					setTrackButton( button, 'hideTrack', false );
				},
				function () {
					setTrackButton( button, 'noTrack', true );
				}
			);
		} );
	}

	/* ------------------------------------------------------------ écouteurs */

	function goToPage( page ) {
		state.page = page;
		apply( true );

		if ( grid ) {
			grid.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			var firstLink = grid.querySelector( 'a' );
			if ( firstLink ) {
				firstLink.focus( { preventScroll: true } );
			}
		}
	}

	function bind() {
		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				readStateFromForm();
				state.page = 1;
				apply( true );
			} );

			// Une jauge filtre quand on la lâche, pas à chaque cran : la grille
			// ne se redessine qu'une fois.
			form.addEventListener( 'change', function ( event ) {
				if ( event.target.matches( 'select, input[type="checkbox"], input[type="range"], input[type="date"]' ) ) {
					readStateFromForm();
					state.page = 1;
					apply( true );
				}
			} );

			// Son texte, lui, suit le curseur.
			form.addEventListener( 'input', function ( event ) {
				if ( event.target.matches( 'input[type="range"]' ) ) {
					keepOrder( event.target );
					updateOutput( event.target.closest( '[data-range]' ) );
				}
			} );

			form.addEventListener(
				'input',
				debounce( function ( event ) {
					if ( event.target.matches( 'input[type="search"], input[type="text"]' ) ) {
						readStateFromForm();
						state.page = 1;
						apply( true );
					}
				}, 250 )
			);
		}

		if ( resetButton ) {
			resetButton.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				if ( form ) {
					form.reset();
					Array.prototype.forEach.call( form.querySelectorAll( 'select' ), function ( select ) {
						select.value = '';
					} );
					Array.prototype.forEach.call( form.querySelectorAll( 'input' ), function ( field ) {
						if ( field.type === 'checkbox' || field.type === 'radio' ) {
							field.checked = false;
						} else if ( field.type === 'range' ) {
							// Vide, une jauge se placerait au milieu : on la remet en butée.
							field.value = field.getAttribute( 'data-neutral' );
						} else {
							field.value = '';
						}
					} );
					Array.prototype.forEach.call( rangeFields(), function ( field ) {
						Array.prototype.forEach.call( field.querySelectorAll( '[aria-invalid]' ), function ( input ) {
							setInvalid( input, false );
						} );
						updateOutput( field );
					} );
				}
				state.query = '';
				state.filters = {};
				state.ranges = {};
				state.dates = {};
				state.page = 1;
				apply( true );
			} );
		}

		if ( paginationNode ) {
			paginationNode.addEventListener( 'click', function ( event ) {
				var button = event.target.closest( '[data-page]' );
				if ( ! button ) {
					return;
				}
				event.preventDefault();
				goToPage( parseInt( button.getAttribute( 'data-page' ), 10 ) || 1 );
			} );
		}

		window.addEventListener( 'popstate', function () {
			readStateFromUrl();
			apply( false );
		} );
	}

	bind();
	loadIndex();
} )();
