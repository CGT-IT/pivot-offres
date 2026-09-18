/**
 * Fiche détail : lien de retour vers la page d'où vient le visiteur.
 *
 * Le serveur rend une page identique pour tous, afin qu'elle reste plaçable
 * derrière un cache de page. La provenance est une affaire propre à chaque
 * visiteur : c'est donc ici qu'elle est traitée, à partir de document.referrer.
 *
 * Sans JavaScript, le fil d'Ariane reste complet et cohérent ; seul le lien de
 * retour vers une page de listing précise n'apparaît pas — ce qui est correct,
 * puisque le serveur ne peut pas savoir d'où l'on vient.
 */
( function () {
	'use strict';

	var config = window.pivotDetailData || {};
	var slots = document.querySelectorAll( '[data-pivot-origin]' );

	if ( ! slots.length || ! Array.isArray( config.origins ) || ! config.origins.length ) {
		return;
	}

	var referrer = document.referrer || '';

	if ( ! referrer ) {
		return;
	}

	var from;

	try {
		from = new URL( referrer, window.location.href );
	} catch ( e ) {
		return;
	}

	// Une provenance extérieure au site ne dit rien d'utile.
	if ( from.origin !== window.location.origin ) {
		return;
	}

	// « /liste/page/3/ » ramène à « /liste/ ».
	var path = from.pathname.replace( /\/page\/\d+\/?$/, '/' );

	if ( path.charAt( path.length - 1 ) !== '/' ) {
		path += '/';
	}

	var match = null;

	config.origins.forEach( function ( candidate ) {
		var candidatePath;

		try {
			candidatePath = new URL( candidate.url, window.location.href ).pathname;
		} catch ( e ) {
			return;
		}

		if ( candidatePath.charAt( candidatePath.length - 1 ) !== '/' ) {
			candidatePath += '/';
		}

		if ( candidatePath === path ) {
			match = candidate;
		}
	} );

	if ( ! match ) {
		return;
	}

	Array.prototype.forEach.call( slots, function ( slot ) {
		var link = slot.querySelector( 'a' );

		if ( ! link ) {
			link = document.createElement( 'a' );
			slot.appendChild( link );
		}

		link.setAttribute( 'href', match.url );

		// Le gabarit peut imposer son propre libellé, « ← Retour aux
		// résultats » par exemple : on ne le remplace que s'il est vide.
		if ( ! link.textContent.trim() ) {
			link.textContent = match.title;
		}

		slot.hidden = false;
	} );
}() );
