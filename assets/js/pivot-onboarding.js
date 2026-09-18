/**
 * Visite guidée de l'administration.
 *
 * Un projecteur assombrit la page autour de l'élément concerné, une bulle
 * explique l'étape. Aucune dépendance : l'écran d'administration charge déjà
 * assez de code comme ça.
 */
( function () {
	'use strict';

	var config = window.pivotOnboarding || {};

	if ( ! config.tours || ! config.tours.length ) {
		return;
	}

	var ADMIN_BAR = 46; // Barre d'administration, en mode fixe.
	var MARGIN = 12;

	var steps = [];
	var index = 0;
	var nodes = {};
	var lastFocus = null;
	var current = null; // Visite en cours.

	function text( key ) {
		return ( config.i18n && config.i18n[ key ] ) || '';
	}

	function escapeHtml( value ) {
		return String( value === undefined || value === null ? '' : value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	/**
	 * Ne garde que les étapes dont la cible est réellement présente.
	 * Une étape sans cible est une étape d'introduction, toujours affichée.
	 */
	function resolveSteps( tour ) {
		return tour.steps
			.map( function ( step ) {
				var node = null;

				if ( step.target ) {
					node = document.querySelector( step.target );

					if ( node && ! isVisible( node ) ) {
						node = null;
					}
				}

				return { step: step, node: node };
			} )
			.filter( function ( entry ) {
				return ! entry.step.target || entry.node;
			} );
	}

	function isVisible( node ) {
		var rect = node.getBoundingClientRect();
		return !! ( node.offsetWidth || node.offsetHeight || rect.width || rect.height );
	}

	/* --------------------------------------------------------- ossature */

	function build() {
		var root = document.createElement( 'div' );
		root.className = 'pivot-tour';
		root.setAttribute( 'role', 'region' );
		root.setAttribute( 'aria-label', text( 'region' ) );

		root.innerHTML =
			'<div class="pivot-tour-spotlight"></div>' +
			'<div class="pivot-tour-bubble" role="dialog" aria-modal="true" aria-labelledby="pivot-tour-title">' +
			'<button type="button" class="pivot-tour-close" aria-label="' + escapeHtml( text( 'close' ) ) + '">&times;</button>' +
			'<h2 id="pivot-tour-title" class="pivot-tour-title"></h2>' +
			'<p class="pivot-tour-text"></p>' +
			'<div class="pivot-tour-foot">' +
			'<span class="pivot-tour-progress" aria-live="polite"></span>' +
			'<span class="pivot-tour-actions">' +
			'<button type="button" class="button-link pivot-tour-skip"></button>' +
			'<button type="button" class="button pivot-tour-prev"></button>' +
			'<button type="button" class="button button-primary pivot-tour-next"></button>' +
			'</span></div></div>';

		document.body.appendChild( root );

		nodes = {
			root: root,
			spotlight: root.querySelector( '.pivot-tour-spotlight' ),
			bubble: root.querySelector( '.pivot-tour-bubble' ),
			title: root.querySelector( '.pivot-tour-title' ),
			text: root.querySelector( '.pivot-tour-text' ),
			progress: root.querySelector( '.pivot-tour-progress' ),
			prev: root.querySelector( '.pivot-tour-prev' ),
			next: root.querySelector( '.pivot-tour-next' ),
			skip: root.querySelector( '.pivot-tour-skip' ),
			close: root.querySelector( '.pivot-tour-close' )
		};

		nodes.skip.textContent = text( 'skip' );
		nodes.prev.textContent = text( 'previous' );

		nodes.next.addEventListener( 'click', function () {
			if ( index >= steps.length - 1 ) {
				stop( true );
			} else {
				go( index + 1 );
			}
		} );

		nodes.prev.addEventListener( 'click', function () {
			go( index - 1 );
		} );

		nodes.skip.addEventListener( 'click', function () {
			stop( true );
		} );

		nodes.close.addEventListener( 'click', function () {
			stop( true );
		} );

		document.addEventListener( 'keydown', onKey );
		window.addEventListener( 'resize', reposition );
		window.addEventListener( 'scroll', reposition, true );
	}

	function onKey( event ) {
		if ( ! nodes.root || ! nodes.root.classList.contains( 'is-open' ) ) {
			return;
		}

		if ( 'Escape' === event.key ) {
			event.preventDefault();
			stop( true );
			return;
		}

		if ( 'ArrowRight' === event.key && index < steps.length - 1 ) {
			event.preventDefault();
			go( index + 1 );
			return;
		}

		if ( 'ArrowLeft' === event.key && index > 0 ) {
			event.preventDefault();
			go( index - 1 );
			return;
		}

		// Le focus reste dans la bulle : la visite est modale.
		if ( 'Tab' === event.key ) {
			var focusable = nodes.bubble.querySelectorAll( 'button:not([disabled])' );

			if ( ! focusable.length ) {
				return;
			}

			var first = focusable[ 0 ];
			var last = focusable[ focusable.length - 1 ];

			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		}
	}

	/* ------------------------------------------------------ déroulement */

	function start( tour, automatic ) {
		current = tour;

		// Une visite ouverte d'elle-même est considérée comme vue dès cet
		// instant. Attendre qu'elle soit terminée la ferait revenir à chaque
		// chargement dès que la personne quitte la page en cours de route.
		if ( automatic ) {
			remember( tour.id );
		}

		// Certaines visites ont besoin que l'écran soit dans un certain état :
		// une visite du sous-formulaire n'a rien à montrer sans critère.
		prepare( tour, function () {
			steps = resolveSteps( tour );

			if ( ! steps.length ) {
				return;
			}

			if ( ! nodes.root ) {
				build();
			}

			lastFocus = document.activeElement;
			nodes.root.classList.add( 'is-open' );
			go( 0 );
		} );
	}

	/**
	 * Met l'écran en état avant de lancer la visite, puis rappelle la suite.
	 */
	function prepare( tour, done ) {
		var recipe = tour.prepare;

		if ( ! recipe || ! recipe.click ) {
			done();
			return;
		}

		if ( recipe.unless && document.querySelector( recipe.unless ) ) {
			done();
			return;
		}

		var button = document.querySelector( recipe.click );

		if ( ! button ) {
			done();
			return;
		}

		button.click();

		// Le temps que le nouvel élément soit dans le document.
		window.setTimeout( done, 60 );
	}

	function stop( keep ) {
		if ( ! nodes.root ) {
			return;
		}

		nodes.root.classList.remove( 'is-open' );

		if ( lastFocus && lastFocus.focus ) {
			lastFocus.focus();
		}

		if ( keep && current ) {
			remember( current.id );
		}
	}

	function go( to ) {
		index = Math.max( 0, Math.min( steps.length - 1, to ) );

		var entry = steps[ index ];
		var step = entry.step;

		nodes.title.textContent = step.title || '';
		nodes.text.textContent = step.text || '';
		nodes.progress.textContent = text( 'progress' )
			.replace( '%1$d', index + 1 )
			.replace( '%2$d', steps.length );

		nodes.prev.hidden = 0 === index;
		nodes.next.textContent = index >= steps.length - 1 ? text( 'finish' ) : text( 'next' );
		nodes.skip.hidden = index >= steps.length - 1;

		if ( entry.node ) {
			scrollIntoView( entry.node );
		}

		// Le calcul de position attend la fin du défilement.
		window.requestAnimationFrame( function () {
			window.setTimeout( function () {
				reposition();
				nodes.next.focus();
			}, 180 );
		} );
	}

	function scrollIntoView( node ) {
		var rect = node.getBoundingClientRect();
		var viewport = window.innerHeight;

		if ( rect.top >= ADMIN_BAR + MARGIN && rect.bottom <= viewport - 200 ) {
			return; // Déjà bien placé : ne pas bouger l'écran pour rien.
		}

		var target = window.pageYOffset + rect.top - ( viewport / 2 ) + ( rect.height / 2 );
		var reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		window.scrollTo( {
			top: Math.max( 0, target ),
			behavior: reduced ? 'auto' : 'smooth'
		} );
	}

	function reposition() {
		if ( ! nodes.root || ! nodes.root.classList.contains( 'is-open' ) ) {
			return;
		}

		var entry = steps[ index ];

		if ( ! entry.node ) {
			nodes.spotlight.hidden = true;
			centerBubble();
			return;
		}

		var rect = entry.node.getBoundingClientRect();

		nodes.spotlight.hidden = false;
		nodes.spotlight.style.top = ( rect.top - 6 ) + 'px';
		nodes.spotlight.style.left = ( rect.left - 6 ) + 'px';
		nodes.spotlight.style.width = ( rect.width + 12 ) + 'px';
		nodes.spotlight.style.height = ( rect.height + 12 ) + 'px';

		placeBubble( rect );
	}

	function centerBubble() {
		var bubble = nodes.bubble;

		bubble.className = 'pivot-tour-bubble is-centered';
		bubble.style.top = '';
		bubble.style.left = '';
	}

	/**
	 * Place la bulle sous la cible, ou au-dessus s'il n'y a pas la place.
	 */
	function placeBubble( rect ) {
		var bubble = nodes.bubble;

		bubble.className = 'pivot-tour-bubble';
		bubble.style.top = '0px';
		bubble.style.left = '0px';

		var size = bubble.getBoundingClientRect();
		var below = rect.bottom + MARGIN;
		var above = rect.top - size.height - MARGIN;
		var top;

		if ( below + size.height <= window.innerHeight - MARGIN ) {
			top = below;
			bubble.classList.add( 'is-below' );
		} else if ( above >= ADMIN_BAR + MARGIN ) {
			top = above;
			bubble.classList.add( 'is-above' );
		} else {
			// Ni dessus ni dessous : on colle la bulle dans la zone visible.
			top = Math.max( ADMIN_BAR + MARGIN, window.innerHeight - size.height - MARGIN );
			bubble.classList.add( 'is-floating' );
		}

		var left = rect.left + ( rect.width / 2 ) - ( size.width / 2 );
		left = Math.max( MARGIN, Math.min( left, window.innerWidth - size.width - MARGIN ) );

		bubble.style.top = Math.round( top ) + 'px';
		bubble.style.left = Math.round( left ) + 'px';

		// La flèche suit la cible, même quand la bulle a été recadrée.
		var arrow = rect.left + ( rect.width / 2 ) - left;
		bubble.style.setProperty( '--pivot-arrow', Math.max( 18, Math.min( arrow, size.width - 18 ) ) + 'px' );
	}

	/* ------------------------------------------------------ persistance */

	var STORAGE_KEY = 'pivotOnboardingSeen';

	/**
	 * Retient une visite : côté serveur, et dans le navigateur en second
	 * rideau, pour que l'appel réseau ne soit jamais un point de rupture.
	 */
	function remember( tourId ) {
		if ( localSeen().indexOf( tourId ) === -1 ) {
			var list = localSeen();
			list.push( tourId );

			try {
				window.localStorage.setItem( STORAGE_KEY, JSON.stringify( list ) );
			} catch ( error ) {
				// Stockage indisponible : le serveur reste la source de vérité.
			}
		}

		save( tourId );
	}

	function localSeen() {
		try {
			var raw = window.localStorage.getItem( STORAGE_KEY );
			var list = raw ? JSON.parse( raw ) : [];
			return Array.isArray( list ) ? list : [];
		} catch ( error ) {
			return [];
		}
	}

	function save( tourId ) {
		if ( ! config.restBase ) {
			return;
		}

		window
			.fetch( config.restBase + '/onboarding', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce
				},
				body: JSON.stringify( { tour: tourId, action: 'seen' } )
			} )
			.catch( function () {
				// Sans enregistrement, la visite se reproposera : sans gravité.
			} );
	}

	/* ------------------------------------------------ bouton de rappel */

	function addReplayButton( tour ) {
		var heading = document.querySelector( '.pivot-wrap h1' );

		if ( ! heading ) {
			return;
		}

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button pivot-tour-replay';
		button.textContent = tour.label || text( 'replay' );

		button.addEventListener( 'click', function () {
			start( tour );
		} );

		heading.appendChild( document.createTextNode( ' ' ) );
		heading.appendChild( button );
	}

	function isSeen( tourId ) {
		if ( config.seen && config.seen.indexOf( tourId ) !== -1 ) {
			return true;
		}

		return localSeen().indexOf( tourId ) !== -1;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var auto = null;

		config.tours.forEach( function ( tour ) {
			if ( tour.auto && ! auto ) {
				auto = tour;
				addReplayButton( tour );
			}

			// Une visite à la demande s'attache au bouton prévu pour elle.
			if ( tour.trigger ) {
				Array.prototype.forEach.call(
					document.querySelectorAll( tour.trigger ),
					function ( node ) {
						node.addEventListener( 'click', function ( event ) {
							event.preventDefault();
							start( tour );
						} );
					}
				);
			}
		} );

		// La visite d'un écran ne s'ouvre d'elle-même qu'une fois par personne.
		if ( auto && ! isSeen( auto.id ) ) {
			window.setTimeout( function () {
				start( auto, true );
			}, 600 );
		}
	} );
} )();
