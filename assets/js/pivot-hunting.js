/**
 * Territoires de chasse du Service public de Wallonie sur une carte Leaflet.
 *
 * Panneau replié en haut à droite de la carte : son bouton « Chasse » porte
 * le nombre de territoires en chasse visibles ; déplié, il permet de masquer
 * les territoires, de choisir un autre jour, et donne la légende et la source.
 * Les territoires du jour sont dessinés dès l'ouverture.
 *
 * Configuration : pivotHuntingData (Pivot_Hunting::config()). Le navigateur
 * interroge lui-même le service ArcGIS du SPW : la table 1 donne les
 * territoires d'une date (clé KEYG, mode de chasse, fermeture des chemins),
 * la couche 0 leurs contours en GeoJSON WGS84. Chaque date lue reste en
 * mémoire le temps de la page.
 *
 * Deux façons d'y venir :
 * - le script du listing appelle PivotHunting.attach( map ) ;
 * - sur une fiche d'itinéraire, Pivot_Hunting ajoute après Leaflet un crochet
 *   (L.Map.addInitHook) qui range chaque carte créée dans
 *   window.pivotHuntingQueue : les cartes des thèmes reçoivent le panneau sans
 *   une ligne de code de leur part. Une carte dont le conteneur porte
 *   data-pivot-hunting="off" en est exclue.
 *
 * @package Pivot_Offres
 */
( function () {
	'use strict';

	var data = window.pivotHuntingData || {};

	function text( key ) {
		return ( data.i18n && data.i18n[ key ] ) || '';
	}

	function escapeHtml( value ) {
		return String( value === null || value === undefined ? '' : value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	/** Aujourd'hui, à la date du visiteur : AAAA-MM-JJ. */
	function todayIso() {
		var now = new Date();
		var pad = function ( n ) {
			return ( n < 10 ? '0' : '' ) + n;
		};

		return now.getFullYear() + '-' + pad( now.getMonth() + 1 ) + '-' + pad( now.getDate() );
	}

	/** AAAA-MM-JJ décalé de quelques jours, sans fuseau horaire. */
	function shiftDate( iso, days ) {
		var parts = iso.split( '-' );

		return new Date( Date.UTC( +parts[ 0 ], +parts[ 1 ] - 1, +parts[ 2 ] + days ) ).toISOString().slice( 0, 10 );
	}

	/** Date en toutes lettres, dans la langue de la page. */
	function longDate( iso ) {
		var parts = iso.split( '-' );

		try {
			return new Intl.DateTimeFormat( data.lang || undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' } )
				.format( new Date( +parts[ 0 ], +parts[ 1 ] - 1, +parts[ 2 ] ) );
		} catch ( e ) {
			return iso;
		}
	}

	/* --------------------------------------------------------- données */

	// Dates lues, partagées par toutes les cartes de la page.
	var cache = {};

	function query( path, params ) {
		var body = Object.keys( params ).map( function ( key ) {
			return encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] );
		} ).join( '&' );

		// POST : la liste des territoires d'un samedi dépasse ce qu'une adresse
		// porte sans risque. Formulaire simple : pas de requête préalable CORS.
		return window.fetch( data.service + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}

			return response.json();
		} ).then( function ( result ) {
			if ( result && result.error ) {
				throw new Error( result.error.message || 'ArcGIS' );
			}

			return result;
		} );
	}

	/** « Battue / Treibjagd/ … » → battue, affut ou autre. */
	function mode( label ) {
		var value = String( label || '' ).toLowerCase();

		if ( value.indexOf( 'battue' ) === 0 ) {
			return 'battue';
		}

		return value.indexOf( 'affut' ) === 0 || value.indexOf( 'affût' ) === 0 ? 'affut' : 'autre';
	}

	/**
	 * Territoires d'une date : { features: GeoJSON, info: { KEYG: { closed,
	 * modes, lot } } }. Les dates du SPW sont à minuit UTC : une date du
	 * calendrier suffit pour les filtrer.
	 */
	function load( iso ) {
		if ( cache[ iso ] ) {
			return cache[ iso ];
		}

		var promise = query( '/1/query', {
			where: "DATE_CHASSE >= DATE '" + iso + "' AND DATE_CHASSE < DATE '" + shiftDate( iso, 1 ) + "'",
			outFields: 'KEYG,N_LOT,MODE_CHASSE_TXT,FERMETURE',
			returnGeometry: 'false',
			f: 'json'
		} ).then( function ( table ) {
			var info = {};

			( table.features || [] ).forEach( function ( row ) {
				var a = row.attributes || {};

				if ( ! a.KEYG ) {
					return;
				}

				var entry = info[ a.KEYG ] || ( info[ a.KEYG ] = { closed: false, modes: {}, lot: a.N_LOT || '' } );
				entry.closed = entry.closed || /^oui/i.test( a.FERMETURE || '' );
				entry.modes[ mode( a.MODE_CHASSE_TXT ) ] = true;
			} );

			var keys = Object.keys( info );

			if ( ! keys.length ) {
				return { features: [], info: info };
			}

			return query( '/0/query', {
				where: 'KEYG IN (' + keys.map( function ( key ) {
					return "'" + String( key ).replace( /'/g, "''" ) + "'";
				} ).join( ',' ) + ')',
				outFields: 'KEYG,N_LOT',
				outSR: '4326',
				maxAllowableOffset: '0.0001',
				geometryPrecision: '5',
				f: 'geojson'
			} ).then( function ( shapes ) {
				return { features: shapes.features || [], info: info };
			} );
		} );

		cache[ iso ] = promise;

		// Un échec ne reste pas en mémoire : un nouvel essai refait la requête.
		promise.catch( function () {
			delete cache[ iso ];
		} );

		return promise;
	}

	/* ------------------------------------------------------------ carte */

	function swatchStyle( style ) {
		style = style || {};

		return 'border-color:' + escapeHtml( style.color || '#c62828' ) + ';background:' + escapeHtml( style.fillColor || style.color || '#e53935' ) +
			';' + ( style.dashArray ? 'border-style:dashed;' : '' );
	}

	/**
	 * Ajoute les territoires de chasse et leur panneau à une carte Leaflet.
	 * Une seule fois par carte ; rien sans configuration ni Leaflet.
	 *
	 * @param {L.Map} map Carte.
	 * @return {Object|null} { show( iso ), hide(), date() } ou null.
	 */
	function attach( map ) {
		var L = window.L;

		if ( ! map || map._pivotHunting || ! data.service || ! L || ! L.Control || ! L.geoJSON || typeof window.fetch !== 'function' ) {
			return null;
		}

		var container = map.getContainer && map.getContainer();

		if ( container && container.getAttribute( 'data-pivot-hunting' ) === 'off' ) {
			return null;
		}

		var state = {
			layer: L.layerGroup(),
			request: 0,
			date: todayIso(),
			shown: true,
			loading: false,
			error: false,
			shapes: [],
			nodes: {}
		};

		map._pivotHunting = state;

		var credit = '<a href="' + escapeHtml( data.source ) + '" target="_blank" rel="noopener">' + escapeHtml( text( 'credit' ) ) + '</a> (CC-BY 4.0)';

		function setCredit( on ) {
			if ( map.attributionControl ) {
				map.attributionControl[ on ? 'addAttribution' : 'removeAttribution' ]( credit );
			}
		}

		/* --- panneau */

		function panel() {
			var id = 'pivot-hunting-' + Math.random().toString( 36 ).slice( 2, 8 );
			var box = L.DomUtil.create( 'div', 'pivot-hunting is-collapsed' );
			var html = '';

			html += '<button type="button" class="pivot-hunting-toggle" aria-expanded="false" aria-controls="' + id + '">';
			html += '<span class="pivot-hunting-label">' + escapeHtml( text( 'title' ) ) + '</span>';
			html += '<span class="pivot-hunting-badge" aria-hidden="true"></span></button>';
			html += '<div class="pivot-hunting-panel" id="' + id + '">';
			html += '<label class="pivot-hunting-show"><input type="checkbox" checked /> ' + escapeHtml( text( 'show' ) ) + '</label>';
			html += '<div class="pivot-hunting-day">';
			html += '<button type="button" class="pivot-hunting-step" data-step="-1" aria-label="' + escapeHtml( text( 'previous' ) ) + '" title="' + escapeHtml( text( 'previous' ) ) + '">‹</button>';
			html += '<input type="date" class="pivot-hunting-date" aria-label="' + escapeHtml( text( 'date' ) ) + '" value="' + state.date + '" min="' + state.date + '" />';
			html += '<button type="button" class="pivot-hunting-step" data-step="1" aria-label="' + escapeHtml( text( 'next' ) ) + '" title="' + escapeHtml( text( 'next' ) ) + '">›</button>';
			html += '</div>';
			html += '<p class="pivot-hunting-status" aria-live="polite"></p>';
			html += '<ul class="pivot-hunting-legend">';
			html += '<li><span class="pivot-hunting-swatch is-closed" style="' + swatchStyle( data.style && data.style.closed ) + '"></span>' + escapeHtml( text( 'closed' ) ) + '</li>';
			html += '<li><span class="pivot-hunting-swatch is-open" style="' + swatchStyle( data.style && data.style.open ) + '"></span>' + escapeHtml( text( 'open' ) ) + '</li>';
			html += '</ul>';
			html += '<p class="pivot-hunting-notice">' + escapeHtml( text( 'notice' ) );

			if ( data.info ) {
				html += ' <a href="' + escapeHtml( data.info ) + '" target="_blank" rel="noopener">' + escapeHtml( text( 'more' ) ) + '</a>';
			}

			html += '</p></div>';
			box.innerHTML = html;

			L.DomEvent.disableClickPropagation( box );
			L.DomEvent.disableScrollPropagation( box );

			var nodes = state.nodes;
			nodes.box = box;
			nodes.toggle = box.querySelector( '.pivot-hunting-toggle' );
			nodes.badge = box.querySelector( '.pivot-hunting-badge' );
			nodes.show = box.querySelector( '.pivot-hunting-show input' );
			nodes.date = box.querySelector( '.pivot-hunting-date' );
			nodes.status = box.querySelector( '.pivot-hunting-status' );
			nodes.steps = box.querySelectorAll( '.pivot-hunting-step' );

			nodes.toggle.addEventListener( 'click', function () {
				var open = nodes.toggle.getAttribute( 'aria-expanded' ) !== 'true';

				nodes.toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				box.classList.toggle( 'is-collapsed', ! open );
			} );

			nodes.show.addEventListener( 'change', function () {
				state.shown = nodes.show.checked;
				box.classList.toggle( 'is-off', ! state.shown );
				nodes.date.disabled = ! state.shown;
				Array.prototype.forEach.call( nodes.steps, function ( button ) {
					button.disabled = ! state.shown;
				} );

				if ( state.shown ) {
					state.layer.addTo( map );
					setCredit( true );
					show( state.date );
				} else {
					map.removeLayer( state.layer );
					setCredit( false );
					setStatus( '', '' );
				}
			} );

			nodes.date.addEventListener( 'change', function () {
				if ( /^\d{4}-\d{2}-\d{2}$/.test( nodes.date.value ) && nodes.date.value >= nodes.date.min ) {
					show( nodes.date.value );
				}
			} );

			Array.prototype.forEach.call( nodes.steps, function ( button ) {
				button.addEventListener( 'click', function () {
					var next = shiftDate( state.date, parseInt( button.getAttribute( 'data-step' ), 10 ) );

					if ( next >= nodes.date.min ) {
						nodes.date.value = next;
						show( next );
					}
				} );
			} );

			return box;
		}

		/** Texte d'état du panneau et pastille du bouton replié. */
		function setStatus( message, badge ) {
			state.nodes.status.textContent = message;
			state.nodes.badge.textContent = badge;
			state.nodes.box.classList.toggle( 'has-badge', badge !== '' );
		}

		/* --- dessin */

		function show( iso ) {
			var request = ++state.request;

			state.date = iso;
			state.loading = true;
			state.error = false;
			state.nodes.status.classList.remove( 'is-error' );
			setStatus( text( 'loading' ), '…' );

			load( iso ).then(
				function ( result ) {
					if ( request !== state.request || ! state.shown ) {
						return;
					}

					state.loading = false;
					draw( result );
				},
				function () {
					if ( request !== state.request ) {
						return;
					}

					state.loading = false;
					state.error = true;
					state.layer.clearLayers();
					state.shapes = [];
					state.nodes.status.classList.add( 'is-error' );
					setStatus( text( 'error' ), '!' );
				}
			);
		}

		function draw( result ) {
			var style = data.style || {};

			state.layer.clearLayers();
			state.shapes = [];

			// Les territoires sans fermeture d'abord : ceux qui ferment les
			// chemins passent par-dessus.
			var features = result.features.slice().sort( function ( a, b ) {
				var ia = result.info[ ( a.properties || {} ).KEYG ] || {};
				var ib = result.info[ ( b.properties || {} ).KEYG ] || {};

				return ( ia.closed ? 1 : 0 ) - ( ib.closed ? 1 : 0 );
			} );

			L.geoJSON( { type: 'FeatureCollection', features: features }, {
				style: function ( feature ) {
					var info = result.info[ feature.properties.KEYG ] || {};
					var base = ( info.closed ? style.closed : style.open ) || {};
					var options = {};

					Object.keys( base ).forEach( function ( key ) {
						options[ key ] = base[ key ];
					} );

					options.className = info.closed ? 'pivot-hunting-closed' : 'pivot-hunting-open';

					return options;
				},
				onEachFeature: function ( feature, layer ) {
					var key = feature.properties.KEYG;
					var info = result.info[ key ] || { modes: {} };
					var lot = info.lot || feature.properties.N_LOT;

					if ( layer.getBounds ) {
						state.shapes.push( { key: key, closed: !! info.closed, bounds: layer.getBounds() } );
					}

					var modes = Object.keys( info.modes ).map( function ( name ) {
						return text( name );
					} ).filter( Boolean ).join( ', ' );

					var popup = '<div class="pivot-hunting-popup"><strong>' + escapeHtml( modes || text( 'title' ) ) + '</strong>';
					popup += '<br />' + escapeHtml( text( info.closed ? 'closed' : 'open' ) );
					popup += '<br />' + escapeHtml( longDate( state.date ) );

					if ( lot ) {
						popup += '<br /><small>' + escapeHtml( text( 'territory' ).replace( '%s', lot ) ) + '</small>';
					}

					layer.bindPopup( popup + '</div>' );
				}
			} ).addTo( state.layer );

			count();
		}

		/**
		 * Les territoires que la carte montre, pas ceux de toute la Wallonie.
		 * Recompté à chaque déplacement de la carte.
		 */
		function count() {
			if ( ! state.shown || state.loading || state.error ) {
				return;
			}

			if ( ! state.shapes.length ) {
				setStatus( text( 'none' ), '0' );
				return;
			}

			// Carte pas encore cadrée (carte d'un thème en cours de création).
			if ( ! map._loaded ) {
				return;
			}

			var view = map.getBounds();
			var seen = {};
			var total = 0;
			var closed = 0;

			state.shapes.forEach( function ( shape ) {
				if ( seen[ shape.key ] || ! view.intersects( shape.bounds ) ) {
					return;
				}

				seen[ shape.key ] = true;
				total++;
				closed += shape.closed ? 1 : 0;
			} );

			setStatus(
				total ? text( 'count' ).replace( '%1$d', total ).replace( '%2$d', closed ) : text( 'noneHere' ),
				String( total )
			);
		}

		var Control = L.Control.extend( {
			options: { position: 'topright' },
			onAdd: panel
		} );

		map.addControl( new Control() );
		map.on( 'moveend', count );
		map.whenReady( count );
		state.layer.addTo( map );
		setCredit( true );
		show( state.date );

		return {
			show: function ( iso ) {
				state.nodes.date.value = iso;
				show( iso );
			},
			date: function () {
				return state.date;
			}
		};
	}

	window.PivotHunting = { attach: attach };

	// Cartes créées avant ce script (crochet ajouté après Leaflet sur une
	// fiche d'itinéraire), puis les suivantes, au fil de leur création.
	var queued = window.pivotHuntingQueue;

	window.pivotHuntingQueue = { push: attach };

	if ( Array.isArray( queued ) ) {
		queued.forEach( attach );
	}
}() );
