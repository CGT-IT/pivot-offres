<?php
/**
 * Construction de l'index client d'une page de listing.
 *
 * L'index est un fichier JSON compact contenant, pour chaque offre, uniquement
 * ce que la page affiche et filtre. C'est lui qui permet à la recherche, aux
 * filtres et à la pagination de fonctionner entièrement dans le navigateur,
 * sans un seul appel à PIVOT pendant la navigation.
 *
 * Les offres sont récupérées une seule fois, puis un index est écrit par
 * langue publiée : PIVOT renvoie déjà toutes les traductions dans la même
 * réponse.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Index_Builder {

	const GROUP       = 'index';
	const STATE_GROUP = 'build';

	/**
	 * Nom du fichier d'index d'une page dans une langue.
	 *
	 * @param string      $listing_id Identifiant.
	 * @param string|null $lang       Langue.
	 * @return string
	 */
	public static function filename( $listing_id, $lang = null ) {
		$lang = $lang ? $lang : Pivot_I18n::current();
		return 'listing-' . sanitize_key( $listing_id ) . '-' . sanitize_key( $lang ) . '.json';
	}

	/**
	 * Chemin absolu de l'index.
	 *
	 * @param string      $listing_id Identifiant.
	 * @param string|null $lang       Langue.
	 * @return string
	 */
	public static function path( $listing_id, $lang = null ) {
		return Pivot_Cache::directory( self::GROUP ) . self::filename( $listing_id, $lang );
	}

	/**
	 * URL publique de l'index, servi en fichier statique.
	 *
	 * @param string      $listing_id Identifiant.
	 * @param string|null $lang       Langue.
	 * @return string
	 */
	public static function url( $listing_id, $lang = null ) {
		$path = self::path( $listing_id, $lang );
		$url  = Pivot_Cache::url( self::GROUP ) . self::filename( $listing_id, $lang );

		if ( file_exists( $path ) ) {
			$url = add_query_arg( 'v', (int) filemtime( $path ), $url );
		}

		return $url;
	}

	/**
	 * L'index de la langue est-il disponible et à jour ?
	 *
	 * @param array       $listing Configuration.
	 * @param string|null $lang    Langue.
	 * @return bool
	 */
	public static function is_fresh( $listing, $lang = null ) {
		$id    = pivot_get( $listing, 'id', '' );
		$langs = $lang ? array( $lang ) : Pivot_I18n::enabled();

		$ttl = (int) pivot_get( $listing, 'cache_ttl', 0 );

		if ( $ttl <= 0 ) {
			$ttl = (int) pivot_settings( 'ttl_index', 6 * HOUR_IN_SECONDS );
		}

		foreach ( $langs as $code ) {
			$path = self::path( $id, $code );

			if ( ! file_exists( $path ) || ( time() - (int) filemtime( $path ) ) >= $ttl ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Lecture d'un index.
	 *
	 * @param string      $listing_id Identifiant.
	 * @param string|null $lang       Langue.
	 * @return array|null
	 */
	public static function read( $listing_id, $lang = null ) {
		$path = self::path( $listing_id, $lang );

		if ( ! is_readable( $path ) ) {
			return null;
		}

		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Supprime tous les index d'une page et son état de construction.
	 *
	 * @param string $listing_id Identifiant.
	 */
	public static function delete_index( $listing_id ) {
		foreach ( Pivot_I18n::all_known() as $lang ) {
			$path = self::path( $listing_id, $lang );
			if ( file_exists( $path ) ) {
				@unlink( $path ); // phpcs:ignore
			}
		}

		Pivot_Cache::delete( self::STATE_GROUP, 'state|' . $listing_id );
	}

	/**
	 * Marque les index comme périmés sans interrompre le service.
	 *
	 * @param string $listing_id Identifiant.
	 */
	public static function invalidate( $listing_id ) {
		Pivot_Cache::delete( self::STATE_GROUP, 'state|' . $listing_id );

		foreach ( Pivot_I18n::all_known() as $lang ) {
			$path = self::path( $listing_id, $lang );
			if ( file_exists( $path ) ) {
				@touch( $path, time() - YEAR_IN_SECONDS ); // phpcs:ignore
			}
		}
	}

	/**
	 * État courant de la construction.
	 *
	 * @param string $listing_id Identifiant.
	 * @return array|null
	 */
	public static function progress( $listing_id ) {
		return Pivot_Cache::get( self::STATE_GROUP, 'state|' . $listing_id );
	}

	/**
	 * Enregistre l'état.
	 *
	 * @param string $listing_id Identifiant.
	 * @param array  $state      État.
	 */
	private static function save_state( $listing_id, $state ) {
		Pivot_Cache::set( self::STATE_GROUP, 'state|' . $listing_id, $state, DAY_IN_SECONDS );
	}

	/**
	 * Construit ou poursuit l'index dans un budget de temps donné.
	 *
	 * @param string $listing_id Identifiant.
	 * @param int    $budget     Secondes maximum pour cet appel.
	 * @return array|WP_Error
	 */
	public static function run( $listing_id, $budget = 20 ) {
		$listing = Pivot_Listings::get( $listing_id );

		if ( ! $listing ) {
			return new WP_Error( 'pivot_unknown_listing', __( 'Page de listing introuvable.', 'pivot-offres' ) );
		}

		if ( ! pivot_get( $listing, 'query_code' ) ) {
			return new WP_Error( 'pivot_missing_query', __( 'Cette page n\'a pas de code de requête.', 'pivot-offres' ) );
		}

		$started = microtime( true );
		$state   = self::progress( $listing_id );

		if ( ! is_array( $state ) || empty( $state['token'] ) || ! empty( $state['done'] ) ) {
			$state = self::start( $listing );

			if ( is_wp_error( $state ) ) {
				return $state;
			}
		}

		while ( empty( $state['done'] ) && ( microtime( true ) - $started ) < $budget ) {
			$state = self::step( $listing, $state );

			if ( is_wp_error( $state ) ) {
				return $state;
			}
		}

		if ( empty( $state['done'] ) ) {
			self::save_state( $listing_id, $state );
			self::schedule_continue( $listing_id );
		}

		return $state;
	}

	/**
	 * Premier appel : ouvre la pagination côté PIVOT.
	 *
	 * @param array $listing Configuration.
	 * @return array|WP_Error
	 */
	private static function start( $listing ) {
		$listing_id = pivot_get( $listing, 'id', '' );
		$batch      = max( 10, (int) pivot_settings( 'batch_size', 100 ) );

		$page = Pivot_Repository::query_first_page( $listing, $batch );

		if ( is_wp_error( $page ) ) {
			Pivot_Logger::error(
				sprintf( 'Construction de l\'index « %s » impossible : %s', $listing_id, $page->get_error_message() ),
				array( 'service' => 'query', 'context' => array( 'listing' => $listing_id ) )
			);
			return $page;
		}

		$state = array(
			'listing'   => $listing_id,
			'token'     => (string) pivot_get( $page, 'token', '' ),
			'page'      => 1,
			'pages'     => max( 1, (int) pivot_get( $page, 'pagesCount', 1 ) ),
			'total'     => (int) pivot_get( $page, 'count', 0 ),
			'processed' => 0,
			'records'   => array(),
			'keymap'    => array(),
			'started'   => time(),
			'done'      => false,
		);

		$state = self::absorb( $listing, $state, pivot_get( $page, 'offers', array() ) );

		if ( ! $state['token'] || $state['pages'] <= 1 ) {
			return self::finish( $listing, $state );
		}

		self::save_state( $listing_id, $state );

		return $state;
	}

	/**
	 * Traite la page suivante.
	 *
	 * @param array $listing Configuration.
	 * @param array $state   État courant.
	 * @return array|WP_Error
	 */
	private static function step( $listing, $state ) {
		$next = (int) $state['page'] + 1;

		if ( $next > (int) $state['pages'] ) {
			return self::finish( $listing, $state );
		}

		$page = Pivot_Repository::query_page( $state['token'], $next );

		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$state['page'] = $next;
		$state         = self::absorb( $listing, $state, pivot_get( $page, 'offers', array() ) );

		if ( $next >= (int) $state['pages'] ) {
			return self::finish( $listing, $state );
		}

		self::save_state( pivot_get( $listing, 'id', '' ), $state );

		return $state;
	}

	/**
	 * Ajoute les offres d'une page à l'état.
	 *
	 * @param array $listing Configuration.
	 * @param array $state   État.
	 * @param array $offers  Offres normalisées.
	 * @return array
	 */
	private static function absorb( $listing, $state, $offers ) {
		foreach ( (array) $offers as $offer ) {
			$record = self::build_record( $listing, $offer, $state );

			if ( $record ) {
				$state['records'][] = $record;
				$state['processed']++;
			}
		}

		return $state;
	}

	/* ------------------------------------------------------------- collecte */

	/**
	 * Construit la fiche neutre d'une offre : toutes les langues à la fois.
	 *
	 * @param array $listing Configuration.
	 * @param array $offer   Offre normalisée.
	 * @param array $state   État, pour la table des clés stables.
	 * @return array
	 */
	private static function build_record( $listing, $offer, &$state ) {
		$code = pivot_get( $offer, 'code' );

		if ( ! $code ) {
			return array();
		}

		$langs  = Pivot_I18n::enabled();
		$record = array( 'c' => $code );

		$type = (int) pivot_get( $offer, 'type', 0 );

		if ( $type ) {
			$record['t'] = $type;
		}

		// Champs sans traduction.
		foreach ( array(
			'address.zip'    => 'z',
			'address.street' => 'r',
			'address.number' => 'nu',
		) as $path => $key ) {
			$value = pivot_get( $offer, $path );
			if ( $value ) {
				$record[ $key ] = $value;
			}
		}

		$lat = pivot_get( $offer, 'address.lat' );
		$lng = pivot_get( $offer, 'address.lng' );

		if ( null !== $lat && null !== $lng ) {
			$record['lat'] = round( (float) $lat, 6 );
			$record['lng'] = round( (float) $lng, 6 );
		}

		$media_code   = pivot_get( $offer, 'media.default.code', $code );
		$record['i']  = pivot_image_url( $media_code );

		// Champs traduits.
		$type_labels = (array) pivot_get( $offer, 'type_labels', array() );

		if ( ! $type_labels && $type ) {
			$type_labels = Pivot_Thesaurus::offer_type_label_set( $type );
		}

		foreach ( $langs as $lang ) {
			$name = Pivot_Templates::offer_name( $offer, $lang );

			if ( $name ) {
				$record['names'][ $lang ] = $name;
			}

			$type_label = Pivot_I18n::pick( $type_labels, $lang );
			if ( $type_label ) {
				$record['types'][ $lang ] = $type_label;
			}

			foreach ( array(
				'address.locality_labels' => 'localities',
				'address.city_labels'     => 'cities',
				'address.province_labels' => 'provinces',
			) as $path => $bucket ) {
				$value = Pivot_I18n::pick( pivot_get( $offer, $path, array() ), $lang );
				if ( $value ) {
					$record[ $bucket ][ $lang ] = $value;
				}
			}

			// La province n'est parfois disponible que sous forme de texte simple.
			if ( empty( $record['provinces'][ $lang ] ) && pivot_get( $offer, 'address.province' ) ) {
				$record['provinces'][ $lang ] = pivot_get( $offer, 'address.province' );
			}

			$description = Pivot_Templates::offer_description( $offer, 32, $lang );
			if ( $description ) {
				$record['descriptions'][ $lang ] = $description;
			}
		}

		// Champs supplémentaires propres au type : dates et lieu d'un événement,
		// capacité d'un hébergement. Ils voyagent avec la vignette pour qu'un
		// gabarit de famille puisse les afficher sans rappeler PIVOT.
		foreach ( Pivot_Types::card_fields( $type ) as $urn ) {
			$key = sanitize_key(
				str_replace(
					':',
					'_',
					str_replace( array( 'urn:fld:', 'urn:obj:' ), '', Pivot_Fields::base_urn( $urn ) )
				)
			);

			foreach ( $langs as $lang ) {
				// find_all descend dans les objets : un champ de date vit à
				// l'intérieur de urn:obj:date, et l'objet peut se répéter. La
				// langue est passée parce qu'une urn préfixée existe en
				// plusieurs versions, dont une seule doit sortir.
				$specs = Pivot_Fields::find_all( $offer, $urn, $lang );

				if ( ! $specs ) {
					continue;
				}

				$values = array();

				foreach ( $specs as $spec ) {
					$value = Pivot_Fields::render( $spec, $lang );

					if ( '' !== $value && ! in_array( $value, $values, true ) ) {
						$values[] = $value;
					}
				}

				if ( ! $values ) {
					continue;
				}

				$record['extras'][ $lang ][ $key ] = array(
					'label'  => Pivot_I18n::pick( pivot_get( $specs, array( 0, 'labels' ), array() ), $lang, '' ),
					'value'  => implode( ' · ', $values ),
					'values' => $values,
					'urn'    => Pivot_Fields::base_urn( $urn ),
				);
			}
		}

		// Facettes : une clé stable par valeur, et ses traductions.
		foreach ( (array) pivot_get( $listing, 'filters', array() ) as $filter ) {
			$key = pivot_get( $filter, 'key' );

			if ( ! $key ) {
				continue;
			}

			foreach ( self::filter_entries( $filter, $offer, $record ) as $entry ) {
				$stable = self::stable_key( $key, pivot_get( $entry, 'raw', '' ), $state );

				if ( ! $stable ) {
					continue;
				}

				if ( empty( $record['facets'][ $key ] ) || ! in_array( $stable, $record['facets'][ $key ], true ) ) {
					$record['facets'][ $key ][] = $stable;
				}

				$record['labels'][ $stable ] = (array) pivot_get( $entry, 'labels', array() );
			}
		}

		/**
		 * Permet d'enrichir la fiche neutre d'une offre avant traduction.
		 *
		 * @param array $record  Fiche neutre.
		 * @param array $offer   Offre normalisée.
		 * @param array $listing Configuration.
		 */
		return apply_filters( 'pivot_index_record', $record, $offer, $listing );
	}

	/**
	 * Clé stable d'une valeur de facette.
	 *
	 * La clé sert dans l'URL et dans le fichier d'index : elle ne dépend
	 * d'aucune langue, ce qui permet de partager un lien filtré entre versions
	 * linguistiques.
	 *
	 * @param string $filter_key Clé du filtre.
	 * @param string $raw        Valeur d'origine (urn ou texte).
	 * @param array  $state      État de construction.
	 * @return string
	 */
	private static function stable_key( $filter_key, $raw, &$state ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return '';
		}

		if ( isset( $state['keymap'][ $filter_key ]['raw'][ $raw ] ) ) {
			return $state['keymap'][ $filter_key ]['raw'][ $raw ];
		}

		if ( 0 === strpos( $raw, 'urn:' ) ) {
			$parts     = explode( ':', $raw );
			$candidate = pivot_slugify( implode( '-', array_slice( $parts, -2 ) ), 40 );
		} else {
			$candidate = pivot_slugify( $raw, 40 );
		}

		if ( '' === $candidate ) {
			$candidate = substr( md5( $raw ), 0, 8 );
		}

		$base = $candidate;
		$i    = 2;

		while ( isset( $state['keymap'][ $filter_key ]['used'][ $candidate ] ) ) {
			$candidate = $base . '-' . $i;
			$i++;
		}

		$state['keymap'][ $filter_key ]['raw'][ $raw ]        = $candidate;
		$state['keymap'][ $filter_key ]['used'][ $candidate ] = $raw;

		return $candidate;
	}

	/**
	 * Valeurs d'un filtre pour une offre, avec leurs traductions.
	 *
	 * @param array $filter Définition du filtre.
	 * @param array $offer  Offre normalisée.
	 * @param array $record Fiche neutre en cours.
	 * @return array Liste de array( raw, labels ).
	 */
	private static function filter_entries( $filter, $offer, $record ) {
		$source = pivot_get( $filter, 'source', 'spec' );

		switch ( $source ) {
			case 'type':
				$type = (int) pivot_get( $offer, 'type', 0 );

				if ( ! $type ) {
					return array();
				}

				$labels = (array) pivot_get( $offer, 'type_labels', array() );

				if ( ! $labels ) {
					$labels = Pivot_Thesaurus::offer_type_label_set( $type );
				}

				return array(
					array( 'raw' => 'urn:typ:' . $type, 'labels' => $labels ),
				);

			case 'locality':
				return self::address_entry( $offer, 'address.locality_labels' );

			case 'city':
				return self::address_entry( $offer, 'address.city_labels' );

			case 'province':
				$entry = self::address_entry( $offer, 'address.province_labels' );

				if ( $entry ) {
					return $entry;
				}

				$plain = pivot_get( $offer, 'address.province' );

				return $plain ? array( array( 'raw' => $plain, 'labels' => array() ) ) : array();

			case 'zip':
				$zip = pivot_get( $offer, 'address.zip' );
				return $zip ? array( array( 'raw' => $zip, 'labels' => array() ) ) : array();

			case 'spec':
			default:
				return self::spec_entries( $filter, $offer );
		}
	}

	/**
	 * Entrée de facette issue d'un champ d'adresse traduit.
	 *
	 * @param array  $offer Offre.
	 * @param string $path  Chemin des traductions.
	 * @return array
	 */
	private static function address_entry( $offer, $path ) {
		$labels = (array) pivot_get( $offer, $path, array() );

		if ( ! $labels ) {
			return array();
		}

		// La valeur française sert de clé stable : elle ne change pas d'une
		// langue d'affichage à l'autre.
		$raw = Pivot_I18n::pick( $labels, 'fr' );

		return $raw ? array( array( 'raw' => $raw, 'labels' => $labels ) ) : array();
	}

	/**
	 * Entrées de facette issues d'un champ PIVOT.
	 *
	 * @param array $filter Définition du filtre.
	 * @param array $offer  Offre normalisée.
	 * @return array
	 */
	/**
	 * Version linguistique retenue pour une facette.
	 *
	 * La forme nue quand elle existe — elle porte le français et ne bouge pas
	 * d'une page à l'autre. Sinon la première rencontrée, pour qu'un champ
	 * publié uniquement en versions traduites reste filtrable.
	 *
	 * @param array  $offer Offre normalisée.
	 * @param string $base  Urn sans préfixe.
	 * @return string Code de langue, ou chaîne vide pour la forme nue.
	 */
	private static function facet_variant( $offer, $base ) {
		$found = array();

		foreach ( (array) pivot_get( $offer, 'specs', array() ) as $spec ) {
			$spec_urn  = (string) pivot_get( $spec, 'urn', '' );
			$spec_base = Pivot_Fields::base_urn( $spec_urn );

			if ( $spec_base !== $base && 0 !== strpos( $spec_base, $base . ':' ) ) {
				continue;
			}

			$lang = Pivot_Fields::urn_lang( $spec_urn );

			if ( '' === $lang ) {
				return '';
			}

			$found[ $lang ] = true;
		}

		return $found ? (string) key( $found ) : '';
	}

	private static function spec_entries( $filter, $offer ) {
		$urn = pivot_get( $filter, 'urn' );

		if ( ! $urn ) {
			return array();
		}

		$urn = Pivot_Fields::base_urn( $urn );

		// Une facette porte la même clé dans toutes les langues : elle est
		// partagée d'une version linguistique à l'autre par l'URL. Si le champ
		// existe en plusieurs versions préfixées, en retenir plusieurs
		// donnerait autant de valeurs distinctes pour une seule réalité. On
		// n'en garde donc qu'une, la forme nue de préférence.
		$variant = self::facet_variant( $offer, $urn );

		$entries = array();

		foreach ( (array) pivot_get( $offer, 'specs', array() ) as $spec ) {
			$spec_urn = pivot_get( $spec, 'urn', '' );

			if ( Pivot_Fields::urn_lang( $spec_urn ) !== $variant ) {
				continue;
			}

			$spec_urn = Pivot_Fields::base_urn( $spec_urn );

			// Correspondance exacte, ou champ enfant pour les valeurs multiples.
			if ( $spec_urn !== $urn && 0 !== strpos( $spec_urn, $urn . ':' ) ) {
				continue;
			}

			$value  = pivot_get( $spec, 'value' );
			$labels = (array) pivot_get( $spec, 'value_labels', array() );

			if ( ! $labels && $spec_urn !== $urn ) {
				$labels = (array) pivot_get( $spec, 'labels', array() );
			}

			if ( 'Boolean' === pivot_get( $spec, 'type', '' ) ) {
				if ( in_array( strtolower( (string) $value ), array( 'true', '1', 'oui' ), true ) ) {
					$entries[] = array(
						'raw'    => $spec_urn,
						'labels' => $labels ? $labels : Pivot_Thesaurus::label_set( $spec_urn ),
					);
				}
				continue;
			}

			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}

			$value = trim( $value );

			if ( ! $labels && 0 === strpos( $value, 'urn:' ) ) {
				$labels = Pivot_Thesaurus::label_set( $value );
			}

			$entries[] = array( 'raw' => $value, 'labels' => $labels );
		}

		return $entries;
	}

	/* --------------------------------------------------------------- écriture */

	/**
	 * Transforme une offre en entrée d'affichage, hors construction d'index.
	 *
	 * Sert aux insertions ponctuelles — un shortcode dans une page — qui ont
	 * besoin des mêmes vignettes sans passer par un index complet.
	 *
	 * @param array  $offer   Offre normalisée.
	 * @param string $lang    Langue.
	 * @param array  $listing Configuration, facultative.
	 * @return array
	 */
	public static function item_from_offer( $offer, $lang = null, $listing = array() ) {
		$lang    = $lang ? $lang : Pivot_I18n::current();
		$listing = $listing ? $listing : array( 'id' => 'inline', 'filters' => array() );
		$state   = array( 'keymap' => array() );

		$record = self::build_record( $listing, $offer, $state );

		if ( ! $record ) {
			return array();
		}

		return self::render_item( $listing, $record, $lang );
	}

	/**
	 * Termine la construction : écrit un fichier d'index par langue.
	 *
	 * @param array $listing Configuration.
	 * @param array $state   État.
	 * @return array
	 */
	private static function finish( $listing, $state ) {
		$listing_id = pivot_get( $listing, 'id', '' );
		$records    = isset( $state['records'] ) ? $state['records'] : array();
		$facets     = array();
		$types      = array();

		// Les types d'offres réellement présents alimentent le sélecteur de
		// champs de l'écran d'édition.
		foreach ( $records as $record ) {
			$type = (int) pivot_get( $record, 't', 0 );

			if ( $type && ! in_array( $type, $types, true ) ) {
				$types[] = $type;
			}
		}

		sort( $types );

		// Gabarits de vignette propres au thème, résolus une seule fois par
		// offre : le fichier ne dépend pas de la langue, et interroger le
		// thème pour chaque offre de chaque langue coûterait cher pour rien.
		$cards = array();

		foreach ( $records as $record ) {
			$code = pivot_get( $record, 'c', '' );

			if ( $code ) {
				$cards[ $code ] = Pivot_Templates::custom_card_file( (int) pivot_get( $record, 't', 0 ), $code );
			}
		}

		$prerender = (bool) array_filter( $cards );

		foreach ( Pivot_I18n::languages() as $lang ) {
			$items = array();

			// Un gabarit de vignette traduit ses propres libellés : sans
			// bascule, l'index néerlandais sortirait avec les textes de la
			// langue du site.
			if ( $prerender ) {
				self::switch_lang( $lang );
			}

			foreach ( $records as $record ) {
				// Le registre suit les dénominations : c'est lui qui repère
				// qu'une offre a été renommée dans PIVOT.
				Pivot_Slugs::observe(
					pivot_get( $record, 'c', '' ),
					$lang,
					pivot_get( $record, array( 'names', $lang ), '' )
				);

				$item = self::render_item(
					$listing,
					$record,
					$lang,
					pivot_get( $cards, pivot_get( $record, 'c', '' ), '' )
				);

				if ( $item ) {
					$items[] = $item;
				}
			}

			if ( $prerender ) {
				self::restore_lang();
			}

			$index = array(
				'listing'   => $listing_id,
				'lang'      => $lang,
				'title'     => Pivot_Listings::title( $listing, $lang ),
				'generated' => time(),
				'perPage'   => (int) pivot_get( $listing, 'per_page', 12 ),
				'showMap'   => (int) pivot_get( $listing, 'show_map', 0 ),
				'count'     => count( $items ),
				'filters'   => self::build_facets( $listing, $records, $lang ),
				'items'     => $items,
			);

			/**
			 * Permet de modifier un index avant écriture.
			 *
			 * @param array  $index   Index complet.
			 * @param array  $listing Configuration.
			 * @param string $lang    Langue.
			 */
			$index = apply_filters( 'pivot_listing_index', $index, $listing, $lang );

			$json = wp_json_encode( $index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( false !== $json ) {
				Pivot_Cache::put_file( self::GROUP, self::filename( $listing_id, $lang ), $json );
			}
		}

		// Valeurs découvertes, proposées à l'administrateur pour la traduction.
		foreach ( $records as $record ) {
			foreach ( (array) pivot_get( $record, 'facets', array() ) as $key => $values ) {
				foreach ( $values as $value ) {
					if ( ! isset( $facets[ $key ] ) ) {
						$facets[ $key ] = array();
					}
					if ( ! in_array( $value, $facets[ $key ], true ) && count( $facets[ $key ] ) < 300 ) {
						$facets[ $key ][] = $value;
					}
				}
			}
		}

		foreach ( $facets as &$values ) {
			sort( $values );
		}
		unset( $values );

		$renamed = Pivot_Slugs::commit();

		Pivot_Listings::update_meta(
			$listing_id,
			array(
				'renamed'      => $renamed,
				'index_built'  => time(),
				'index_count'  => count( $records ),
				'facet_values' => $facets,
				'offer_types'  => $types,
			)
		);

		Pivot_Logger::info(
			sprintf(
				'Index « %1$s » reconstruit : %2$d offres, %3$d langue(s).',
				$listing_id,
				count( $records ),
				count( Pivot_I18n::enabled() )
			),
			array( 'service' => 'index', 'cache_status' => 'rebuild', 'context' => array( 'listing' => $listing_id ) )
		);

		$state['done']      = true;
		$state['records']   = array();
		$state['finished']  = time();
		$state['processed'] = count( $records );

		self::save_state( $listing_id, $state );

		return $state;
	}

	/**
	 * Traduit une fiche neutre en entrée d'index.
	 *
	 * Aucune clé n'est écrite si la donnée est absente : le JavaScript teste
	 * l'existence avant d'afficher.
	 *
	 * @param array  $listing Configuration.
	 * @param array  $record  Fiche neutre.
	 * @param string $lang    Langue.
	 * @param string $card    Gabarit de vignette propre au thème, s'il y en a un.
	 * @return array
	 */
	private static function render_item( $listing, $record, $lang, $card = '' ) {
		$code = pivot_get( $record, 'c' );

		if ( ! $code ) {
			return array();
		}

		$item = array( 'c' => $code );

		foreach ( array( 't', 'z', 'r', 'nu', 'lat', 'lng', 'i' ) as $key ) {
			$value = pivot_get( $record, $key );
			if ( null !== $value ) {
				$item[ $key ] = $value;
			}
		}

		foreach ( array(
			'names'        => 'n',
			'types'        => 'tl',
			'localities'   => 'l',
			'cities'       => 'm',
			'provinces'    => 'p',
			'descriptions' => 'd',
		) as $bucket => $key ) {
			$value = pivot_get( $record, array( $bucket, $lang ) );
			if ( $value ) {
				$item[ $key ] = $value;
			}
		}

		$item['u'] = Pivot_Rewrites::detail_url(
			$code,
			(int) pivot_get( $record, 't', 0 ),
			pivot_get( $item, 'n', '' ),
			$lang
		);

		$facets = (array) pivot_get( $record, 'facets', array() );

		if ( $facets ) {
			$item['f'] = $facets;
		}

		$extras = (array) pivot_get( $record, array( 'extras', $lang ), array() );

		if ( $extras ) {
			$item['x'] = $extras;
		}

		$item['s'] = self::haystack( $listing, $item, $record, $lang );

		/**
		 * Permet d'enrichir une entrée d'index.
		 *
		 * Ce filtre passe avant le rendu de la vignette : ce qu'il ajoute est
		 * donc visible du gabarit.
		 *
		 * @param array  $item    Entrée.
		 * @param array  $record  Fiche neutre.
		 * @param array  $listing Configuration.
		 * @param string $lang    Langue.
		 */
		$item = apply_filters( 'pivot_index_item', $item, $record, $listing, $lang );

		// Vignette rendue par le serveur, embarquée dans l'index.
		//
		// La recherche, les filtres et la pagination se font dans le
		// navigateur : le JavaScript réécrit la grille dès que l'index arrive,
		// y compris sur la première page. Sans cette clé, il reconstruirait un
		// balisage générique et le gabarit du thème ne durerait qu'un instant.
		//
		// N'est écrite que si le thème a surchargé la vignette de ce type :
		// ailleurs, l'index garde sa taille d'origine.
		if ( $card ) {
			$html = Pivot_Templates::render_card( $item, $card );

			if ( $html ) {
				$item['h'] = $html;
			}
		}

		return $item;
	}

	/**
	 * Bascule la langue d'affichage, le temps de rendre des vignettes.
	 *
	 * @param string $lang Langue.
	 */
	private static function switch_lang( $lang ) {
		Pivot_I18n::set_current( $lang );

		if ( function_exists( 'switch_to_locale' ) ) {
			switch_to_locale( Pivot_I18n::locale( $lang ) );
		}
	}

	/**
	 * Rend la langue d'affichage à sa détection normale.
	 */
	private static function restore_lang() {
		if ( function_exists( 'restore_previous_locale' ) ) {
			restore_previous_locale();
		}

		Pivot_I18n::set_current( null );
	}

	/**
	 * Chaîne de recherche normalisée pour une langue.
	 *
	 * @param array  $listing Configuration.
	 * @param array  $item    Entrée traduite.
	 * @param array  $record  Fiche neutre.
	 * @param string $lang    Langue.
	 * @return string
	 */
	private static function haystack( $listing, $item, $record, $lang ) {
		$parts = array();

		foreach ( array( 'n', 'l', 'm', 'p', 'z', 'tl', 'd', 'r' ) as $key ) {
			if ( isset( $item[ $key ] ) ) {
				$parts[] = $item[ $key ];
			}
		}

		$filters = array();

		foreach ( (array) pivot_get( $listing, 'filters', array() ) as $filter ) {
			$filters[ pivot_get( $filter, 'key', '' ) ] = $filter;
		}

		foreach ( (array) pivot_get( $record, 'facets', array() ) as $key => $values ) {
			foreach ( $values as $value ) {
				$parts[] = self::value_label( $record, $value, isset( $filters[ $key ] ) ? $filters[ $key ] : array(), $lang );
			}
		}

		return pivot_normalize( implode( ' ', $parts ) );
	}

	/**
	 * Libellé d'une valeur de facette : traduction imposée, sinon PIVOT.
	 *
	 * @param array  $record Fiche neutre.
	 * @param string $value  Clé stable.
	 * @param array  $filter Définition du filtre.
	 * @param string $lang   Langue.
	 * @return string
	 */
	private static function value_label( $record, $value, $filter, $lang ) {
		$labels    = (array) pivot_get( $record, array( 'labels', $value ), array() );
		$from_data = Pivot_I18n::pick( $labels, $lang, '' );

		return Pivot_Listings::filter_value_label( $filter, $lang, $value, $from_data ? $from_data : $value );
	}

	/**
	 * Agrège les valeurs disponibles pour chaque filtre, dans une langue.
	 *
	 * @param array  $listing Configuration.
	 * @param array  $records Fiches neutres.
	 * @param string $lang    Langue.
	 * @return array
	 */
	private static function build_facets( $listing, $records, $lang ) {
		$out = array();

		foreach ( (array) pivot_get( $listing, 'filters', array() ) as $filter ) {
			$key = pivot_get( $filter, 'key' );

			if ( ! $key ) {
				continue;
			}

			$entry = array(
				'key'         => $key,
				'label'       => Pivot_Listings::filter_label( $filter, $lang ),
				'type'        => pivot_get( $filter, 'type', 'select' ),
				'placeholder' => pivot_get( $filter, 'placeholder', '' ),
				'options'     => array(),
			);

			if ( in_array( $entry['type'], array( 'select', 'multiselect' ), true ) ) {
				$counts = array();
				$labels = array();

				foreach ( $records as $record ) {
					foreach ( (array) pivot_get( $record, array( 'facets', $key ), array() ) as $value ) {
						if ( ! isset( $counts[ $value ] ) ) {
							$counts[ $value ]  = 0;
							$labels[ $value ] = self::value_label( $record, $value, $filter, $lang );
						}
						$counts[ $value ]++;
					}
				}

				asort( $labels, SORT_NATURAL | SORT_FLAG_CASE );

				foreach ( $labels as $value => $label ) {
					$entry['options'][] = array(
						'v' => (string) $value,
						'l' => (string) $label,
						'n' => (int) $counts[ $value ],
					);
				}
			}

			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * Planifie la poursuite d'une construction interrompue.
	 *
	 * @param string $listing_id Identifiant.
	 */
	private static function schedule_continue( $listing_id ) {
		if ( ! wp_next_scheduled( 'pivot_continue_index', array( $listing_id ) ) ) {
			wp_schedule_single_event( time() + 30, 'pivot_continue_index', array( $listing_id ) );
		}
	}

	/**
	 * Fournit l'index de la langue demandée, en le construisant si besoin.
	 *
	 * À la première visite l'index est construit sur-le-champ ; s'il est
	 * seulement périmé, il reste servi et la reconstruction part en tâche de
	 * fond.
	 *
	 * @param array       $listing Configuration.
	 * @param string|null $lang    Langue.
	 * @return array|null
	 */
	public static function ensure( $listing, $lang = null ) {
		$listing_id = pivot_get( $listing, 'id', '' );
		$lang       = $lang ? $lang : Pivot_I18n::current();
		$path       = self::path( $listing_id, $lang );

		if ( ! file_exists( $path ) ) {
			self::run( $listing_id, 25 );
			return self::read( $listing_id, $lang );
		}

		if ( ! self::is_fresh( $listing, $lang ) && ! wp_next_scheduled( 'pivot_continue_index', array( $listing_id ) ) ) {
			wp_schedule_single_event( time() + 5, 'pivot_continue_index', array( $listing_id ) );
		}

		return self::read( $listing_id, $lang );
	}
}
