/**
 * Interactions des écrans d'administration :
 * ajout/retrait de critères et reconstruction d'un index avec progression.
 */
( function () {
	'use strict';

	var settings = window.pivotAdmin || {};

	// Fabrique de lignes de critère, exposée une fois le formulaire prêt :
	// le panneau de suggestions s'en sert pour poser un critère complet.
	var filterFactory = null;

	/**
	 * Renseigne un champ d'une ligne, si la valeur proposée existe.
	 */
	function fill( row, selector, value ) {
		var node = row.querySelector( selector );

		if ( ! node || value === undefined || value === null || '' === value ) {
			return;
		}

		if ( 'SELECT' === node.tagName && ! node.querySelector( 'option[value="' + value + '"]' ) ) {
			return;
		}

		node.value = value;
	}

	/* ------------------------------------------------- critères de recherche */

	function initFilters() {
		var container = document.querySelector( '.pivot-filters' );
		var addButton = document.querySelector( '.pivot-add-filter' );
		var template = document.getElementById( 'pivot-filter-template' );

		if ( ! container || ! addButton || ! template ) {
			return;
		}

		filterFactory = function ( prefill ) {
			var index = parseInt( container.getAttribute( 'data-next-index' ), 10 ) || 0;
			var html = template.innerHTML.replace( /__index__/g, index );
			var wrapper = document.createElement( 'div' );

			wrapper.innerHTML = html.trim();

			var row = wrapper.firstElementChild;
			if ( ! row ) {
				return null;
			}

			container.appendChild( row );
			container.setAttribute( 'data-next-index', index + 1 );

			if ( prefill ) {
				fill( row, 'input[name*="[label]"]', prefill.label );
				fill( row, '.pivot-filter-source', prefill.source );
				fill( row, '.pivot-urn-input', prefill.urn || '' );
				fill( row, 'select[name*="[type]"]', prefill.control );
				fill( row, 'select[name*="[operator]"]', prefill.operator );
				fill( row, 'select[name*="[date_operator]"]', prefill.operator );
				fill( row, 'input[name*="[key]"]', prefill.key || '' );
			}

			toggleUrnField( row );
			toggleRangeOptions( row );

			return row;
		};

		addButton.addEventListener( 'click', function () {
			var row = filterFactory( null );

			if ( row ) {
				var firstInput = row.querySelector( 'input' );
				if ( firstInput ) {
					firstInput.focus();
				}
			}
		} );

		container.addEventListener( 'click', function ( event ) {
			if ( ! event.target.classList.contains( 'pivot-remove-filter' ) ) {
				return;
			}
			event.preventDefault();
			var row = event.target.closest( '.pivot-filter-row' );
			if ( row ) {
				row.parentNode.removeChild( row );
			}
		} );

		container.addEventListener( 'change', function ( event ) {
			if ( event.target.classList.contains( 'pivot-filter-source' ) ) {
				toggleUrnField( event.target.closest( '.pivot-filter-row' ) );
			}

			if ( event.target.matches( 'select[name*="[type]"]' ) ) {
				toggleRangeOptions( event.target.closest( '.pivot-filter-row' ) );
			}

			if ( event.target.classList.contains( 'pivot-group-input' ) ) {
				refreshGroupSuggestions( container );
			}
		} );

		Array.prototype.forEach.call( container.querySelectorAll( '.pivot-filter-row' ), function ( row ) {
			toggleUrnField( row );
			toggleRangeOptions( row );
		} );

		initFieldPicker( container );
	}

	/**
	 * Propose dans chaque critère les groupes déjà saisis dans les autres.
	 */
	function refreshGroupSuggestions( container ) {
		var list = document.getElementById( 'pivot-group-suggestions' );
		var seen = {};

		if ( ! list ) {
			return;
		}

		list.innerHTML = '';

		Array.prototype.forEach.call( container.querySelectorAll( '.pivot-group-input' ), function ( input ) {
			var value = input.value.trim();

			if ( value && ! seen[ value ] ) {
				seen[ value ] = true;

				var option = document.createElement( 'option' );
				option.value = value;
				list.appendChild( option );
			}
		} );
	}

	/**
	 * Comparaison, affichage et unité ne concernent que le contrôle numérique ;
	 * le critère de date a sa propre comparaison.
	 */
	function toggleRangeOptions( row ) {
		if ( ! row ) {
			return;
		}

		var control = row.querySelector( 'select[name*="[type]"]' );
		var options = row.querySelector( '.pivot-filter-range' );
		var dates = row.querySelector( '.pivot-filter-date' );

		if ( control && options ) {
			options.hidden = control.value !== 'range';
		}

		if ( control && dates ) {
			dates.hidden = control.value !== 'date';
		}
	}

	/**
	 * L'urn n'a de sens que pour la source « champ PIVOT ».
	 */
	function toggleUrnField( row ) {
		if ( ! row ) {
			return;
		}

		var source = row.querySelector( '.pivot-filter-source' );
		var urnField = row.querySelector( '.pivot-filter-urn' );

		if ( ! source || ! urnField ) {
			return;
		}

		urnField.hidden = source.value !== 'spec';
	}



	/* ------------------------------------------------ critères suggérés */

	/**
	 * Le serveur analyse un échantillon d'offres de la requête et renvoie les
	 * champs qui feraient de bons critères. Ici on se contente de les présenter
	 * et de poser le critère complet en un clic.
	 */
	function initSuggestions() {
		var panel = document.querySelector( '.pivot-suggestions' );

		if ( ! panel ) {
			return;
		}

		var listing = panel.getAttribute( 'data-listing' );

		if ( ! listing ) {
			return;
		}

		var status = panel.querySelector( '.pivot-suggestions-status' );
		var list = panel.querySelector( '.pivot-suggestions-list' );
		var refresh = panel.querySelector( '.pivot-suggestions-refresh' );

		function load( force ) {
			status.hidden = false;
			status.textContent = text( 'analysing' );
			list.innerHTML = '';

			window
				.fetch(
					settings.restBase + '/suggestions/' + encodeURIComponent( listing ) + ( force ? '?force=1' : '' ),
					{ credentials: 'same-origin', headers: { 'X-WP-Nonce': settings.nonce } }
				)
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						if ( ! response.ok ) {
							throw new Error( data && data.message ? data.message : 'HTTP ' + response.status );
						}
						return data;
					} );
				} )
				.then( function ( data ) {
					render( data );
				} )
				.catch( function ( error ) {
					status.textContent = text( 'sugFailed' ).replace( '%s', error.message || '' );
				} );
		}

		function render( data ) {
			var suggestions = ( data.suggestions || [] ).filter( function ( item ) {
				var id = 'spec' === item.source ? 'spec:' + item.urn : item.source;
				return ( data.used || [] ).indexOf( id ) === -1;
			} );

			status.hidden = false;
			status.textContent = text( 'sugBasis' )
				.replace( '%1$d', data.sample )
				.replace( '%2$d', data.count );

			if ( ! suggestions.length ) {
				list.innerHTML = '<p class="pivot-suggestions-empty">' + escapeHtml( text( 'sugNone' ) ) + '</p>';
				return;
			}

			list.innerHTML = suggestions.map( card ).join( '' );
		}

		function card( item ) {
			var summary;

			if ( 'toggle' === item.control ) {
				summary = text( 'sugBoolean' ).replace( '%d', item.coverage );
			} else if ( 'range' === item.control ) {
				summary = text( 'sugRange' )
					.replace( '%1$s', Number( item.min ).toLocaleString() )
					.replace( '%2$s', Number( item.max ).toLocaleString() )
					.replace( '%3$d', item.coverage );
			} else if ( 'date' === item.control ) {
				summary = text( 'sugDates' )
					.replace( '%1$s', formatDate( item.min ) )
					.replace( '%2$s', formatDate( item.max ) )
					.replace( '%3$d', item.coverage );
			} else {
				summary = text( 'sugSummary' ).replace( '%1$d', item.values ).replace( '%2$d', item.coverage );
			}

			// « %% » est l'échappement de gettext pour « % » : sprintf le résout
			// en PHP, pas ici.
			summary = summary.replace( /%%/g, '%' );

			var html = '<div class="pivot-suggestion" data-suggestion="' + escapeHtml( JSON.stringify( item ) ) + '">';
			html += '<div class="pivot-suggestion-main">';
			html += '<span class="pivot-suggestion-label">' + escapeHtml( item.label ) + '</span>';
			html += '<span class="pivot-suggestion-meta">' + escapeHtml( summary ) + '</span>';

			if ( item.examples && item.examples.length ) {
				html += '<span class="pivot-suggestion-examples">' + escapeHtml( item.examples.join( ' · ' ) ) + '</span>';
			}

			html += '</div>';
			html += '<button type="button" class="button pivot-suggestion-add">' + escapeHtml( text( 'sugAdd' ) ) + '</button>';
			html += '</div>';

			return html;
		}

		list.addEventListener( 'click', function ( event ) {
			if ( ! event.target.classList.contains( 'pivot-suggestion-add' ) ) {
				return;
			}

			var node = event.target.closest( '.pivot-suggestion' );
			var item;

			try {
				item = JSON.parse( node.getAttribute( 'data-suggestion' ) );
			} catch ( error ) {
				return;
			}

			if ( ! filterFactory ) {
				return;
			}

			var row = filterFactory( item );

			if ( ! row ) {
				return;
			}

			// Un critère sur un champ PIVOT ne remonte rien en mode résumé :
			// on corrige le réglage plutôt que de laisser un filtre vide.
			if ( 'spec' === item.source ) {
				var content = document.querySelector( 'select[name="pivot_listing[content]"]' );

				if ( content && '1' === content.value ) {
					content.value = '2';
					notify( text( 'sugNeedFull' ) );
				}
			}

			node.classList.add( 'is-added' );
			event.target.disabled = true;
			event.target.textContent = text( 'sugAdded' );

			row.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		} );

		if ( refresh ) {
			refresh.addEventListener( 'click', function () {
				load( true );
			} );
		}

		load( false );
	}

	/**
	 * Date ISO mise en forme dans la langue de l'administration : « 10/10/2026 ».
	 */
	function formatDate( iso ) {
		var parts = String( iso || '' ).split( '-' );

		if ( parts.length !== 3 ) {
			return String( iso || '' );
		}

		return new Date( Number( parts[ 0 ] ), Number( parts[ 1 ] ) - 1, Number( parts[ 2 ] ) ).toLocaleDateString();
	}

	/**
	 * Message discret, sous le panneau de suggestions.
	 */
	function notify( message ) {
		var panel = document.querySelector( '.pivot-suggestions' );

		if ( ! panel ) {
			return;
		}

		var note = panel.querySelector( '.pivot-suggestions-note' );

		if ( ! note ) {
			note = document.createElement( 'p' );
			note.className = 'pivot-suggestions-note';
			note.setAttribute( 'role', 'status' );
			panel.appendChild( note );
		}

		note.textContent = message;
	}

	/* ------------------------------------------------ sélecteur de champs */

	/**
	 * Le catalogue de champs vient du thesaurus PIVOT, via la route REST.
	 * Il est mis en cache par type d'offre : rouvrir le sélecteur ne relance
	 * aucun appel.
	 */
	var fieldCache = {};
	var typeCache = null;

	function text( key ) {
		return ( settings.i18n && settings.i18n[ key ] ) || '';
	}

	function fetchFields( typeId ) {
		var key = typeId || 'default';

		if ( fieldCache[ key ] ) {
			return Promise.resolve( fieldCache[ key ] );
		}

		var url = settings.restBase + '/fields?listing=' + encodeURIComponent( settings.listing || '' );

		if ( typeId ) {
			url += '&type=' + encodeURIComponent( typeId );
		}

		return window
			.fetch( url, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': settings.nonce }
			} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} )
			.then( function ( data ) {
				fieldCache[ key ] = data;

				if ( ! typeCache ) {
					typeCache = data.types || [];
				}

				// La réponse sans type explicite correspond au type retenu :
				// on la range aussi sous sa vraie clé.
				if ( data.selected ) {
					fieldCache[ data.selected ] = data;
				}

				return data;
			} );
	}

	function escapeHtml( value ) {
		return String( value === undefined || value === null ? '' : value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function fillTypeSelect( select, types, selected ) {
		if ( ! types || ! types.length ) {
			return;
		}

		var present = types.filter( function ( t ) { return t.present; } );
		var others = types.filter( function ( t ) { return ! t.present; } );

		function option( t ) {
			return '<option value="' + t.id + '"' + ( Number( t.id ) === Number( selected ) ? ' selected' : '' ) + '>' +
				escapeHtml( t.label ) + ( t.present ? ' — ' + escapeHtml( text( 'inThisPage' ) ) : '' ) +
				'</option>';
		}

		var html = '';

		// Les types présents dans la page passent devant : ce sont les seuls
		// dont les champs produiront réellement des valeurs.
		if ( present.length ) {
			html += '<optgroup label="' + escapeHtml( text( 'inThisPage' ) ) + '">' +
				present.map( option ).join( '' ) + '</optgroup>';
		}

		if ( others.length ) {
			html += '<optgroup label="—">' + others.map( option ).join( '' ) + '</optgroup>';
		}

		select.innerHTML = html;
	}

	function renderFields( container, fields, query ) {
		var needle = ( query || '' ).trim().toLowerCase();

		var matching = ( fields || [] ).filter( function ( field ) {
			if ( ! needle ) {
				return true;
			}
			return (
				field.label.toLowerCase().indexOf( needle ) !== -1 ||
				field.urn.toLowerCase().indexOf( needle ) !== -1 ||
				( field.cat || '' ).toLowerCase().indexOf( needle ) !== -1
			);
		} );

		if ( ! matching.length ) {
			container.innerHTML = '<p class="pivot-field-empty">' + escapeHtml( text( 'noField' ) ) + '</p>';
			return;
		}

		var html = '';
		var lastCat = null;

		matching.forEach( function ( field ) {
			if ( field.cat !== lastCat ) {
				lastCat = field.cat;
				html += '<p class="pivot-field-cat">' + escapeHtml( field.cat || '—' ) + '</p>';
			}

			html += '<button type="button" class="pivot-field-item" role="option"' +
				' data-urn="' + escapeHtml( field.urn ) + '"' +
				' data-label="' + escapeHtml( field.label ) + '"' +
				' data-control="' + escapeHtml( field.control ) + '"' +
				' data-operator="' + escapeHtml( field.operator || '' ) + '">' +
				'<span class="pivot-field-label">' + escapeHtml( field.label ) + '</span>' +
				'<code>' + escapeHtml( field.urn ) + '</code>' +
				'<span class="pivot-field-type-tag">' + escapeHtml( field.type || '' ) + '</span>' +
				'</button>';
		} );

		container.innerHTML = html;
	}

	function updateDatalist( fields ) {
		var list = document.getElementById( 'pivot-urn-suggestions' );

		if ( ! list || ! fields ) {
			return;
		}

		list.innerHTML = fields
			.map( function ( f ) {
				return '<option value="' + escapeHtml( f.urn ) + '">' + escapeHtml( f.label ) + '</option>';
			} )
			.join( '' );
	}

	function loadInto( row, typeId ) {
		var results = row.querySelector( '.pivot-field-results' );
		var select = row.querySelector( '.pivot-field-type' );
		var search = row.querySelector( '.pivot-field-search' );

		results.innerHTML = '<p class="pivot-field-empty">' + escapeHtml( text( 'loading' ) ) + '</p>';

		fetchFields( typeId )
			.then( function ( data ) {
				fillTypeSelect( select, data.types || typeCache, data.selected || typeId );

				if ( ! data.fields || ! data.fields.length ) {
					results.innerHTML = '<p class="pivot-field-empty">' + escapeHtml( text( 'chooseType' ) ) + '</p>';
					return;
				}

				row.pivotFields = data.fields;
				renderFields( results, data.fields, search ? search.value : '' );
				updateDatalist( data.fields );
			} )
			.catch( function () {
				results.innerHTML = '<p class="pivot-field-empty">' + escapeHtml( text( 'loadFailed' ) ) + '</p>';
			} );
	}

	function initFieldPicker( container ) {
		container.addEventListener( 'click', function ( event ) {
			var row = event.target.closest( '.pivot-filter-row' );

			if ( ! row ) {
				return;
			}

			// Ouvrir le sélecteur.
			if ( event.target.classList.contains( 'pivot-pick-field' ) ) {
				event.preventDefault();
				var picker = row.querySelector( '.pivot-field-picker' );
				picker.hidden = ! picker.hidden;

				if ( ! picker.hidden && ! row.pivotFields ) {
					loadInto( row, row.querySelector( '.pivot-field-type' ).value );
				}

				if ( ! picker.hidden ) {
					var search = row.querySelector( '.pivot-field-search' );
					if ( search ) {
						search.focus();
					}
				}
				return;
			}

			if ( event.target.classList.contains( 'pivot-close-picker' ) ) {
				event.preventDefault();
				row.querySelector( '.pivot-field-picker' ).hidden = true;
				return;
			}

			// Choisir un champ : on remplit l'urn, le libellé s'il est vide,
			// et on propose le contrôle adapté au type du champ.
			var item = event.target.closest( '.pivot-field-item' );

			if ( ! item ) {
				return;
			}

			event.preventDefault();

			var urnInput = row.querySelector( '.pivot-urn-input' );
			var labelInput = row.querySelector( 'input[name*="[label]"]' );
			var control = row.querySelector( 'select[name*="[type]"]' );
			var source = row.querySelector( '.pivot-filter-source' );

			if ( urnInput ) {
				urnInput.value = item.getAttribute( 'data-urn' );
			}

			if ( labelInput && ! labelInput.value.trim() ) {
				labelInput.value = item.getAttribute( 'data-label' );
			}

			if ( source && source.value !== 'spec' ) {
				source.value = 'spec';
				toggleUrnField( row );
			}

			if ( control ) {
				var suggested = item.getAttribute( 'data-control' );
				if ( suggested && control.querySelector( 'option[value="' + suggested + '"]' ) ) {
					control.value = suggested;
				}
			}

			fill( row, 'select[name*="[operator]"]', item.getAttribute( 'data-operator' ) );
			fill( row, 'select[name*="[date_operator]"]', item.getAttribute( 'data-operator' ) );
			toggleRangeOptions( row );

			row.querySelector( '.pivot-field-picker' ).hidden = true;
		} );

		container.addEventListener( 'change', function ( event ) {
			if ( ! event.target.classList.contains( 'pivot-field-type' ) ) {
				return;
			}

			var row = event.target.closest( '.pivot-filter-row' );
			row.pivotFields = null;
			loadInto( row, event.target.value );
		} );

		container.addEventListener( 'input', function ( event ) {
			if ( ! event.target.classList.contains( 'pivot-field-search' ) ) {
				return;
			}

			var row = event.target.closest( '.pivot-filter-row' );

			if ( row.pivotFields ) {
				renderFields( row.querySelector( '.pivot-field-results' ), row.pivotFields, event.target.value );
			}
		} );
	}



	/* -------------------------------------------- constructeur de shortcode */

	function initShortcodeBuilder() {
		var form = document.querySelector( '.pivot-sc-form' );

		if ( ! form ) {
			return;
		}

		var output = document.getElementById( 'pivot-sc-output' );
		var copied = form.querySelector( '.pivot-sc-copied' );

		function currentSource() {
			var checked = form.querySelector( '.pivot-sc-source-choice:checked' );
			return checked ? checked.value : 'listing';
		}

		function build() {
			var source = currentSource();
			var atts = [];

			// N'apparaissent que les attributs qui s'écartent du défaut : un
			// shortcode court se relit et se modifie plus facilement.
			var defaults = { nombre: '6', colonnes: '3', tri: 'defaut' };

			Array.prototype.forEach.call( form.querySelectorAll( '.pivot-sc-field' ), function ( field ) {
				var att = field.getAttribute( 'data-att' );
				var value;

				if ( field.type === 'checkbox' ) {
					value = field.checked ? field.getAttribute( 'data-on' ) : '';
				} else {
					value = ( field.value || '' ).trim();
				}

				// Un champ de source qui n'est pas la source choisie est ignoré.
				var holder = field.closest( '.pivot-sc-source' );
				if ( holder && holder.getAttribute( 'data-source' ) !== source ) {
					return;
				}

				if ( ! value || defaults[ att ] === value ) {
					return;
				}

				atts.push( att + '="' + value.replace( /"/g, "'" ) + '"' );
			} );

			output.value = atts.length ? '[pivot_offres ' + atts.join( ' ' ) + ']' : '[pivot_offres]';
		}

		function toggleSources() {
			var source = currentSource();

			Array.prototype.forEach.call( form.querySelectorAll( '.pivot-sc-source' ), function ( node ) {
				node.hidden = node.getAttribute( 'data-source' ) !== source;
			} );
		}

		form.addEventListener( 'change', function () {
			toggleSources();
			build();
		} );

		form.addEventListener( 'input', build );

		var copy = form.querySelector( '.pivot-sc-copy' );

		if ( copy ) {
			copy.addEventListener( 'click', function () {
				output.select();

				var done = function () {
					if ( copied ) {
						copied.textContent = text( 'copied' );
						window.setTimeout( function () { copied.textContent = ''; }, 2500 );
					}
				};

				if ( window.navigator.clipboard ) {
					window.navigator.clipboard.writeText( output.value ).then( done, done );
				} else {
					document.execCommand( 'copy' );
					done();
				}
			} );
		}

		toggleSources();
		build();
	}

	/* -------------------------------------------------- reconstruction index */

	function initRebuild() {
		var button = document.querySelector( '.pivot-rebuild' );

		if ( ! button ) {
			return;
		}

		var status = document.querySelector( '.pivot-rebuild-status' );
		var progress = document.querySelector( '.pivot-progress' );
		var bar = document.querySelector( '.pivot-progress-bar' );

		button.addEventListener( 'click', function () {
			var listing = button.getAttribute( 'data-listing' );

			button.disabled = true;

			if ( progress ) {
				progress.hidden = false;
			}

			if ( status ) {
				status.textContent = settings.i18n ? settings.i18n.building : '';
			}

			step( listing, true );

			function step( id, restart ) {
				window
					.fetch( settings.restBase + '/build/' + id, {
						method: 'POST',
						credentials: 'same-origin',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': settings.nonce
						},
						body: JSON.stringify( { restart: !! restart } )
					} )
					.then( function ( response ) {
						if ( ! response.ok ) {
							throw new Error( 'HTTP ' + response.status );
						}
						return response.json();
					} )
					.then( function ( data ) {
						var ratio = data.pages ? Math.min( 100, Math.round( ( data.page / data.pages ) * 100 ) ) : 100;

						if ( bar ) {
							bar.style.width = ratio + '%';
						}

						if ( status && settings.i18n ) {
							status.textContent = settings.i18n.progress
								.replace( '%1$d', data.processed )
								.replace( '%2$d', data.total || data.processed );
						}

						if ( ! data.done ) {
							step( id, false );
							return;
						}

						if ( bar ) {
							bar.style.width = '100%';
						}

						if ( status && settings.i18n ) {
							status.textContent = settings.i18n.done + ' ' + data.processed;
						}

						button.disabled = false;
					} )
					.catch( function () {
						if ( status && settings.i18n ) {
							status.textContent = settings.i18n.failed;
						}
						button.disabled = false;
					} );
			}
		} );
	}

	/* ------------------------------------------------------- image d'en-tête */

	/**
	 * Le champ garde l'adresse de l'image : la médiathèque ne fait que la
	 * remplir. Sans elle, l'adresse se saisit à la main.
	 */
	function initImagePicker() {
		var field = document.querySelector( '.pivot-image-field' );

		if ( ! field ) {
			return;
		}

		var input = field.querySelector( '.pivot-image-url' );
		var preview = field.querySelector( '.pivot-image-preview' );
		var pick = field.querySelector( '.pivot-image-pick' );
		var clear = field.querySelector( '.pivot-image-clear' );
		var frame = null;

		function refresh() {
			var url = input.value.trim();

			preview.hidden = '' === url;
			clear.hidden = '' === url;

			if ( url ) {
				preview.src = url;
			} else {
				preview.removeAttribute( 'src' );
			}
		}

		input.addEventListener( 'change', refresh );

		clear.addEventListener( 'click', function () {
			input.value = '';
			refresh();
		} );

		if ( ! window.wp || ! window.wp.media ) {
			pick.hidden = true;
			refresh();
			return;
		}

		pick.addEventListener( 'click', function () {
			if ( ! frame ) {
				frame = window.wp.media( {
					title: text( 'imageTitle' ),
					button: { text: text( 'imageChoose' ) },
					library: { type: 'image' },
					multiple: false
				} );

				frame.on( 'select', function () {
					input.value = frame.state().get( 'selection' ).first().get( 'url' );
					refresh();
				} );
			}

			frame.open();
		} );

		refresh();
	}

	/* ------------------------------------------------------------ suppression */

	function initConfirm() {
		Array.prototype.forEach.call( document.querySelectorAll( '.pivot-confirm-delete' ), function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				var message = settings.i18n ? settings.i18n.confirmRm : '';
				if ( message && ! window.confirm( message ) ) {
					event.preventDefault();
				}
			} );
		} );
	}

	/**
	 * Écran « Champs affichés ».
	 *
	 * Deux comportements : la case d'une catégorie commande celles de ses
	 * champs, et une zone de recherche masque les lignes hors sujet.
	 *
	 * Le premier n'est pas cosmétique. Le serveur lit l'état réel des cases
	 * pour décider ce qu'il enregistre : sans cette propagation, décocher une
	 * catégorie laisserait ses champs cochés, et chaque champ serait enregistré
	 * comme un rétablissement individuel.
	 */
	function initFieldRules() {
		var form = document.querySelector( '.pivot-fields-form' );

		if ( ! form ) {
			return;
		}

		function scope( toggle ) {
			var group = toggle.closest( '.pivot-field-subgroup' ) || toggle.closest( '.pivot-field-group' );

			return group ? group.querySelectorAll( 'input[name="pivot_visible[]"]' ) : [];
		}

		function refresh( toggle ) {
			var boxes   = scope( toggle );
			var total   = 0;
			var coches  = 0;

			Array.prototype.forEach.call( boxes, function ( box ) {
				if ( box === toggle ) {
					return;
				}
				total++;
				if ( box.checked ) {
					coches++;
				}
			} );

			// Une catégorie dont seule une partie des champs est visible :
			// l'état intermédiaire évite de faire croire à un « tout masqué ».
			toggle.indeterminate = total > 0 && coches > 0 && coches < total;
		}

		Array.prototype.forEach.call( form.querySelectorAll( '.pivot-toggle-all' ), function ( toggle ) {
			refresh( toggle );

			toggle.addEventListener( 'change', function () {
				var etat = toggle.checked;

				Array.prototype.forEach.call( scope( toggle ), function ( box ) {
					if ( box !== toggle ) {
						box.checked = etat;
					}
				} );

				toggle.indeterminate = false;
			} );
		} );

		form.addEventListener( 'change', function ( event ) {
			if ( ! event.target.matches || ! event.target.matches( 'input[name="pivot_visible[]"]' ) ) {
				return;
			}

			if ( event.target.classList.contains( 'pivot-toggle-all' ) ) {
				return;
			}

			Array.prototype.forEach.call( form.querySelectorAll( '.pivot-toggle-all' ), refresh );
		} );

		var search = document.getElementById( 'pivot-field-filter' );

		if ( ! search ) {
			return;
		}

		search.addEventListener( 'input', function () {
			var terme = search.value.trim().toLowerCase();

			Array.prototype.forEach.call( form.querySelectorAll( '.pivot-field-list li' ), function ( line ) {
				line.hidden = '' !== terme && -1 === line.textContent.toLowerCase().indexOf( terme );
			} );

			// Une catégorie dont plus rien ne correspond n'a pas à rester.
			Array.prototype.forEach.call( form.querySelectorAll( '.pivot-field-group, .pivot-field-subgroup' ), function ( group ) {
				var visibles = group.querySelectorAll( '.pivot-field-list li:not([hidden])' );

				group.hidden = '' !== terme && 0 === visibles.length;
			} );
		} );
	}

	/* ------------------------------------------ sections de l'écran d'édition */

	var SECTIONS_KEY = 'pivotListingSections';

	function readSectionState() {
		try {
			return JSON.parse( window.localStorage.getItem( SECTIONS_KEY ) ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function writeSectionState( state ) {
		try {
			window.localStorage.setItem( SECTIONS_KEY, JSON.stringify( state ) );
		} catch ( e ) {
			// Stockage indisponible : l'état ne survit pas au rechargement.
		}
	}

	function initSections() {
		var sections = Array.prototype.slice.call( document.querySelectorAll( '.pivot-section' ) );
		var nav = document.querySelector( '.pivot-section-nav' );

		if ( ! sections.length ) {
			return;
		}

		var state = readSectionState();
		var toggleAll = nav ? nav.querySelector( '.pivot-sections-toggle' ) : null;

		function isCollapsed( section ) {
			return section.classList.contains( 'is-collapsed' );
		}

		function syncToggleAll() {
			if ( ! toggleAll ) {
				return;
			}

			var allCollapsed = sections.every( isCollapsed );

			toggleAll.textContent = toggleAll.getAttribute( allCollapsed ? 'data-expand' : 'data-collapse' );
		}

		function setCollapsed( section, collapsed, save ) {
			section.classList.toggle( 'is-collapsed', collapsed );
			section.querySelector( '.pivot-section-toggle' ).setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );

			if ( save ) {
				state[ section.getAttribute( 'data-section' ) ] = collapsed ? 1 : 0;
				writeSectionState( state );
			}

			syncToggleAll();
		}

		function reveal( node ) {
			var section = node && node.closest ? node.closest( '.pivot-section' ) : null;

			if ( section && isCollapsed( section ) ) {
				setCollapsed( section, false, true );
			}
		}

		sections.forEach( function ( section ) {
			var id = section.getAttribute( 'data-section' );
			var collapsed = id in state ? !! state[ id ] : 'collapsed' === section.getAttribute( 'data-default' );

			setCollapsed( section, collapsed, false );

			section.querySelector( '.pivot-section-toggle' ).addEventListener( 'click', function () {
				setCollapsed( section, ! isCollapsed( section ), true );
			} );
		} );

		if ( toggleAll ) {
			toggleAll.addEventListener( 'click', function () {
				var collapse = ! sections.every( isCollapsed );

				sections.forEach( function ( section ) {
					setCollapsed( section, collapse, true );
				} );
			} );
		}

		// La visite guidée et la validation du formulaire doivent pouvoir
		// atteindre un champ d'une section repliée.
		document.addEventListener( 'pivot:reveal', function ( event ) {
			reveal( event.target );
		} );

		var form = document.getElementById( 'pivot-listing-form' );

		if ( form ) {
			form.addEventListener( 'invalid', function ( event ) {
				reveal( event.target );
			}, true );
		}

		if ( window.location.hash ) {
			reveal( document.querySelector( window.location.hash.replace( /[^#\w-]/g, '' ) ) );
		}

		if ( ! nav ) {
			return;
		}

		var links = Array.prototype.slice.call( nav.querySelectorAll( 'a[href^="#pivot-section-"]' ) );
		var list = nav.querySelector( 'ul' );

		links.forEach( function ( link ) {
			link.addEventListener( 'click', function () {
				reveal( document.querySelector( link.getAttribute( 'href' ) ) );
			} );
		} );

		// Section courante : la dernière dont le haut a passé l'endroit où
		// une ancre la pose. WordPress y compte la barre d'administration
		// (scroll-padding-top), même sur mobile où elle ne reste pas en haut :
		// le seuil se lit donc dans les styles plutôt que sur le bandeau.
		var ticking = false;

		function anchorOffset() {
			var padding = parseFloat( window.getComputedStyle( document.documentElement ).scrollPaddingTop ) || 0;
			var margin = parseFloat( window.getComputedStyle( sections[ 0 ] ).scrollMarginTop ) || 0;

			return Math.max( padding + margin, nav.getBoundingClientRect().bottom );
		}

		function spy() {
			ticking = false;

			var limit = anchorOffset() + 24;
			var currentId = sections[ 0 ].id;
			var atBottom = window.innerHeight + window.pageYOffset >= document.documentElement.scrollHeight - 2;

			sections.forEach( function ( section ) {
				if ( section.getBoundingClientRect().top <= limit ) {
					currentId = section.id;
				}
			} );

			if ( atBottom ) {
				currentId = sections[ sections.length - 1 ].id;
			}

			links.forEach( function ( link ) {
				var active = link.getAttribute( 'href' ) === '#' + currentId;

				link.classList.toggle( 'is-current', active );

				if ( active ) {
					link.setAttribute( 'aria-current', 'true' );

					// Sur un écran étroit, l'onglet actif reste visible dans le bandeau.
					if ( list.scrollWidth > list.clientWidth ) {
						var left = link.offsetLeft - list.offsetLeft;

						if ( left < list.scrollLeft || left + link.offsetWidth > list.scrollLeft + list.clientWidth ) {
							list.scrollLeft = left - 12;
						}
					}
				} else {
					link.removeAttribute( 'aria-current' );
				}
			} );
		}

		function requestSpy() {
			if ( ! ticking ) {
				ticking = true;
				window.requestAnimationFrame( spy );
			}
		}

		window.addEventListener( 'scroll', requestSpy, { passive: true } );
		window.addEventListener( 'resize', requestSpy );
		// Replier une section déplace celles qui suivent sans faire défiler.
		document.addEventListener( 'click', requestSpy );

		spy();
	}

	/* ---------------------------------------- recherche des pages de listing */

	/**
	 * Minuscules sans accents, comme Pivot_Admin::normalize_search().
	 */
	function normalizeSearch( text ) {
		return String( text ).normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).toLowerCase();
	}

	function initListingsSearch() {
		var form = document.querySelector( '.pivot-listings-search' );
		var table = document.querySelector( '.pivot-listings-table' );

		if ( ! form || ! table ) {
			return;
		}

		var input = form.querySelector( 'input[type="search"]' );
		var count = document.querySelector( '.pivot-listings-count' );
		var tbody = table.tBodies[ 0 ];
		var none = tbody.querySelector( '.pivot-listings-none' );

		// Ordre d'origine : le serveur a rendu les pages trouvées en premier.
		var rows = Array.prototype.slice.call( tbody.querySelectorAll( '.pivot-listing-row' ) );
		var order = rows.slice().sort( function ( a, b ) {
			return a.getAttribute( 'data-order' ) - b.getAttribute( 'data-order' );
		} );

		function apply() {
			var value = input.value.trim();
			var terms = normalizeSearch( value ).split( /\s+/ ).filter( Boolean );
			var shown = [];
			var hidden = [];

			order.forEach( function ( row ) {
				var haystack = row.getAttribute( 'data-search' ) || '';
				var match = terms.every( function ( term ) {
					return -1 !== haystack.indexOf( term );
				} );

				row.hidden = ! match;
				( match ? shown : hidden ).push( row );
			} );

			// Pages masquées en fin de tableau : les bandes alternées restent
			// régulières sur les lignes visibles.
			shown.concat( hidden ).forEach( function ( row ) {
				tbody.appendChild( row );
			} );

			if ( none ) {
				none.hidden = shown.length > 0;
			}

			if ( count ) {
				count.textContent = terms.length
					? count.getAttribute( 'data-filtered' ).replace( '%1$d', shown.length ).replace( '%2$d', rows.length )
					: count.getAttribute( 'data-total' );
			}

			// L'adresse garde la recherche : un rechargement ou un retour
			// depuis l'écran d'édition la retrouve.
			if ( window.history && window.history.replaceState ) {
				var url = new URL( window.location.href );

				if ( value ) {
					url.searchParams.set( 's', value );
				} else {
					url.searchParams.delete( 's' );
				}

				window.history.replaceState( null, '', url.toString() );
			}
		}

		input.addEventListener( 'input', apply );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			apply();
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initListingsSearch();
		initSections();
		initFilters();
		initSuggestions();
		initShortcodeBuilder();
		initRebuild();
		initImagePicker();
		initConfirm();
		initFieldRules();
	} );
} )();
