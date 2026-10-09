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
 * Entre deux reconstructions complètes, l'index se tient à jour par le mode
 * différentiel de PIVOT (query/CODE/diff) : chaque nuit, seules les offres
 * entrées, modifiées ou sorties de la requête sont redemandées. Les fiches
 * neutres de la dernière construction sont gardées dans le cache privé pour
 * cela ; tout écart détecté ramène à une reconstruction complète.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Index_Builder {

	const GROUP       = 'index';
	const STATE_GROUP = 'build';

	/** Échecs consécutifs du différentiel avant de le réinitialiser chez PIVOT. */
	const SYNC_FAILURES = 3;

	/** Au-delà de ce nombre de changements, on reconstruit plutôt que d'appliquer. */
	const SYNC_MAX_CHANGES = 50;

	/** Idem, en proportion des offres de la page. */
	const SYNC_MAX_RATIO = 0.2;

	/**
	 * Version du contenu des fiches neutres.
	 *
	 * À augmenter quand une fiche gagne une donnée que le différentiel ne
	 * saurait ajouter aux fiches gardées — il ne relit que les offres
	 * modifiées. Elle entre dans l'empreinte : la mise à jour de nuit suivante
	 * fait alors une reconstruction complète.
	 *
	 * 2 : fermetures à venir (clé cl), par les zones de fermeture liées.
	 * 3 : fichier GPX (clé g), pour le tracé sur la carte du listing.
	 */
	const RECORD_FORMAT = 3;

	/** @var array Index déjà décodés pendant cette requête. */
	private static $read_memo = array();

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
		$ttl   = self::index_ttl( $listing );

		// Un différentiel sans changement ne réécrit pas les fichiers : c'est
		// la date de cette vérification qui fait foi. Sans elle, un index
		// confirmé chaque nuit mais inchangé depuis une semaine passerait pour
		// périmé et serait reconstruit en pleine journée.
		$checked = self::diff_enabled( $listing ) ? (int) pivot_get( $listing, 'index_checked', 0 ) : 0;

		foreach ( $langs as $code ) {
			$path = self::path( $id, $code );

			if ( ! file_exists( $path ) || ( time() - max( (int) filemtime( $path ), $checked ) ) >= $ttl ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Durée de vie d'un index : réglage de la page, sinon réglage général.
	 *
	 * Avec le différentiel, c'est l'intervalle entre deux reconstructions
	 * complètes de sécurité.
	 *
	 * @param array $listing Configuration.
	 * @return int Secondes.
	 */
	public static function index_ttl( $listing ) {
		$ttl = (int) pivot_get( $listing, 'cache_ttl', 0 );

		if ( $ttl <= 0 ) {
			$ttl = (int) pivot_settings( 'ttl_index', 6 * HOUR_IN_SECONDS );
		}

		return $ttl;
	}

	/**
	 * Lecture d'un index.
	 *
	 * @param string      $listing_id Identifiant.
	 * @param string|null $lang       Langue.
	 * @return array|null
	 */
	public static function read( $listing_id, $lang = null ) {
		$lang = $lang ? $lang : Pivot_I18n::current();
		$memo = $listing_id . '|' . $lang;

		// Une page de listing lisait le même fichier deux fois : une fois pour la
		// grille, une fois depuis l'en-tête SEO pour en tirer un simple compteur.
		// Sur l'index le plus gros de ce site, cela faisait deux décodages de
		// 419 Ko — quelque 20 000 entrées de tableau PHP matérialisées deux fois
		// pour afficher douze cartes. Les échecs sont mémorisés aussi : un index
		// absent ou tronqué ne doit pas être relu à chaque appel.
		if ( array_key_exists( $memo, self::$read_memo ) ) {
			return self::$read_memo[ $memo ];
		}

		$path = self::path( $listing_id, $lang );
		$data = null;

		if ( is_readable( $path ) ) {
			$decoded = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore
			$data    = is_array( $decoded ) ? $decoded : null;

			if ( null === $data ) {
				// Fichier illisible : le signaler et l'écarter, plutôt que de
				// servir une page vide en silence à chaque requête.
				Pivot_Logger::error(
					sprintf( 'Index illisible, fichier écarté : %s', self::filename( $listing_id, $lang ) ),
					array( 'service' => 'index' )
				);

				@unlink( $path ); // phpcs:ignore
			}
		}

		self::$read_memo[ $memo ] = $data;

		return $data;
	}

	/**
	 * Oublie les index décodés, après écriture ou suppression.
	 *
	 * @param string $listing_id Identifiant, vide pour tout oublier.
	 */
	public static function forget( $listing_id = '' ) {
		if ( '' === $listing_id ) {
			self::$read_memo = array();

			return;
		}

		foreach ( array_keys( self::$read_memo ) as $memo ) {
			if ( 0 === strpos( $memo, $listing_id . '|' ) ) {
				unset( self::$read_memo[ $memo ] );
			}
		}
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
		self::forget_sync( $listing_id );

		self::forget( $listing_id );
	}

	/**
	 * Reconstruit en arrière-plan les index de toutes les pages actives.
	 *
	 * Les anciens index restent servis jusqu'à ce que les nouveaux les
	 * remplacent.
	 */
	public static function rebuild_all() {
		foreach ( array_keys( Pivot_Listings::active() ) as $listing_id ) {
			self::invalidate( $listing_id );
			wp_schedule_single_event( time() + 5, 'pivot_continue_index', array( $listing_id ) );
		}
	}

	/**
	 * Marque les index comme périmés sans interrompre le service.
	 *
	 * @param string $listing_id Identifiant.
	 */
	public static function invalidate( $listing_id ) {
		Pivot_Cache::delete( self::STATE_GROUP, 'state|' . $listing_id );

		// Les fiches gardées pour le différentiel ne correspondent plus à ce
		// qu'on attend de l'index : la reconstruction repartira de zéro.
		Pivot_Cache::delete( self::STATE_GROUP, self::sync_key( $listing_id ) );
		Pivot_Listings::update_meta( $listing_id, array( 'index_checked' => 0 ) );

		foreach ( Pivot_I18n::all_known() as $lang ) {
			$path = self::path( $listing_id, $lang );
			if ( file_exists( $path ) ) {
				@touch( $path, time() - YEAR_IN_SECONDS ); // phpcs:ignore
			}
		}

		self::forget( $listing_id );
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

		// Un seul constructeur à la fois par page. Sans ce verrou, N visiteurs
		// arrivant ensemble sur un index absent ouvraient chacun leur session de
		// pagination chez PIVOT et écrivaient tous la même clé d'état : le
		// dernier écrasait les autres, et l'index pouvait être publié à partir
		// d'un jeu d'offres incomplet. Le verrou expire un peu après le budget,
		// pour qu'une interruption ne bloque pas la page indéfiniment.
		$lock = self::lock_name( $listing_id );

		if ( ! Pivot_Cache::acquire_lock( $lock, $budget + 60 ) ) {
			return new WP_Error(
				'pivot_build_locked',
				__( 'Une reconstruction de cette page est déjà en cours.', 'pivot-offres' )
			);
		}

		try {
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
					// L'état sur disque porte un jeton que PIVOT vient de
					// refuser — le plus souvent parce qu'il a expiré. Le laisser
					// en place condamnait la page : chaque tentative suivante
					// reprenait le même jeton mort et échouait pareil, sans que
					// rien ne reparte jamais de zéro. On efface, on replanifie,
					// et la prochaine exécution rouvre une pagination propre.
					self::clear_state( $listing_id );
					self::schedule_continue( $listing_id );

					Pivot_Logger::error(
						sprintf(
							'Construction de « %1$s » interrompue : %2$s. État remis à zéro.',
							$listing_id,
							$state->get_error_message()
						),
						array( 'service' => 'index' )
					);

					return $state;
				}
			}

			if ( empty( $state['done'] ) ) {
				self::save_state( $listing_id, $state );
				self::schedule_continue( $listing_id );
			}

			return $state;
		} finally {
			Pivot_Cache::release_lock( $lock );
		}
	}

	/**
	 * Nom du verrou de construction d'une page.
	 *
	 * @param string $listing_id Identifiant.
	 * @return string
	 */
	private static function lock_name( $listing_id ) {
		return 'build|' . $listing_id;
	}

	/**
	 * Un index déjà publié contient-il au moins une offre ?
	 *
	 * @param string $listing_id Identifiant.
	 * @return bool
	 */
	private static function has_populated_index( $listing_id ) {
		foreach ( Pivot_I18n::languages() as $lang ) {
			$existing = self::read( $listing_id, $lang );

			if ( is_array( $existing ) && (int) pivot_get( $existing, 'count', 0 ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Oublie l'état de construction, sans toucher aux index déjà publiés.
	 *
	 * @param string $listing_id Identifiant.
	 */
	private static function clear_state( $listing_id ) {
		Pivot_Cache::delete( self::STATE_GROUP, 'state|' . $listing_id );
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

		// Référence du différentiel posée avant le téléchargement, pas après.
		$anchored = self::diff_enabled( $listing ) && self::anchor( $listing );

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
			'warmed'    => 0,
			'records'   => array(),
			'keymap'    => array(),
			'anchored'  => $anchored,
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

		$page = Pivot_Repository::query_page( $state['token'], $next, (int) pivot_get( $listing, 'content', 2 ) );

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
		// Une page réglée sur « complet avec offres liées » reçoit chaque offre
		// exactement comme la fiche détail la demande : on la range au
		// passage, au lieu de faire payer un appel à PIVOT au premier visiteur
		// de chaque fiche. Aux niveaux inférieurs, les offres liées arrivent
		// sans leurs champs — médias sans titre ni crédit, contact vide — et
		// ne suffiraient pas à la fiche.
		$warm = 3 === (int) pivot_get( $listing, 'content', 2 );
		$ttl  = $warm ? self::detail_ttl( $listing ) : 0;

		foreach ( (array) $offers as $offer ) {
			$record = self::build_record( $listing, $offer, $state );

			// Indexées par code : le différentiel remplace ou retire une fiche
			// sans parcourir les autres.
			if ( $record && pivot_get( $record, 'c' ) ) {
				$state['records'][ (string) $record['c'] ] = $record;
				$state['processed']++;
			}

			if ( $warm && Pivot_Repository::remember_offer( $offer, 3, $ttl ) ) {
				$state['warmed'] = (int) pivot_get( $state, 'warmed', 0 ) + 1;
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
					// Une vignette affiche du texte : le HTML d'un TextML s'y réduit.
					$value = Pivot_Fields::text( Pivot_Fields::render( $spec, $lang ), pivot_get( $spec, 'type', '' ) );

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

		// Fermetures à venir, par les zones de fermeture liées : la vignette
		// dit si l'offre est fermée le jour où on la regarde, ce que seul le
		// navigateur sait. Les périodes déjà passées restent dehors.
		$closures = Pivot_Closures::periods( $offer );

		if ( $closures ) {
			$record['cl'] = $closures;
		}

		// Tracé GPX d'un itinéraire, que la carte du listing propose
		// d'afficher. Connu au seul niveau « complet avec offres liées » : en
		// deçà, le navigateur le demande au clic (route REST pivot/v1/gpx).
		$gpx = Pivot_Templates::offer_gpx( $offer );

		if ( $gpx ) {
			$record['g'] = $gpx['url'];
		}

		// Facettes : une clé stable par valeur, et ses traductions.
		foreach ( (array) pivot_get( $listing, 'filters', array() ) as $filter ) {
			$key = pivot_get( $filter, 'key' );

			if ( ! $key ) {
				continue;
			}

			// Un critère numérique compare des nombres : ils sont gardés tels
			// quels, sans clé stable ni libellé. En clé stable, « 9.50 »
			// deviendrait « 9-50 », qu'aucune comparaison ne saurait lire.
			if ( 'range' === pivot_get( $filter, 'type' ) ) {
				$numbers = self::numeric_values( $filter, $offer, $record );

				if ( $numbers ) {
					$record['facets'][ $key ] = $numbers;
				}
				continue;
			}

			// Un critère de date compare des périodes : [début, fin] en
			// AAAAMMJJ, une par période de l'offre.
			if ( 'date' === pivot_get( $filter, 'type' ) ) {
				$periods = self::date_values( $filter, $offer );

				if ( $periods ) {
					$record['facets'][ $key ] = $periods;
				}
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
	 * Valeurs numériques d'un critère pour une offre.
	 *
	 * Une valeur qui n'est pas un nombre est ignorée : l'offre n'a alors pas
	 * de valeur pour ce critère, comme si le champ était absent.
	 *
	 * @param array $filter Définition du filtre.
	 * @param array $offer  Offre normalisée.
	 * @param array $record Fiche neutre en cours.
	 * @return array Nombres distincts.
	 */
	private static function numeric_values( $filter, $offer, $record ) {
		$numbers = array();

		foreach ( self::filter_entries( $filter, $offer, $record ) as $entry ) {
			$number = pivot_parse_number( pivot_get( $entry, 'raw', '' ) );

			if ( null !== $number && ! in_array( $number, $numbers, true ) ) {
				$numbers[] = $number;
			}
		}

		return $numbers;
	}

	/**
	 * Périodes d'une offre pour un critère de date.
	 *
	 * Quand le critère porte sur la période entière, les périodes qui se
	 * chevauchent ou se suivent sont fusionnées : un événement donné jour
	 * par jour tient en une seule entrée, et la réponse reste la même — une
	 * période touche l'intervalle demandé si et seulement si leur réunion le
	 * touche. Comparer un début ou une fin demande au contraire de les garder
	 * toutes.
	 *
	 * @param array $filter Définition du filtre.
	 * @param array $offer  Offre normalisée.
	 * @return array Liste de array( début, fin ).
	 */
	private static function date_values( $filter, $offer ) {
		$periods = Pivot_Fields::date_periods( $offer, (string) pivot_get( $filter, 'urn', '' ) );

		if ( count( $periods ) < 2 || 'overlap' !== Pivot_Listings::date_match( $filter ) ) {
			return $periods;
		}

		$merged = array( array_shift( $periods ) );

		foreach ( $periods as $period ) {
			$last = count( $merged ) - 1;
			$next = (int) gmdate( 'Ymd', strtotime( pivot_date_iso( $merged[ $last ][1] ) . ' +1 day UTC' ) );

			if ( $period[0] <= $next ) {
				$merged[ $last ][1] = max( $merged[ $last ][1], $period[1] );
			} else {
				$merged[] = $period;
			}
		}

		return $merged;
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
		$own   = self::has_own_field( $offer, $base );

		foreach ( (array) pivot_get( $offer, 'specs', array() ) as $spec ) {
			$spec_urn  = (string) pivot_get( $spec, 'urn', '' );
			$spec_base = Pivot_Fields::base_urn( $spec_urn );

			if ( ! self::spec_belongs( $spec, $spec_base, $base, $own ) ) {
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

	/**
	 * L'offre porte-t-elle le champ lui-même, toutes langues confondues ?
	 *
	 * @param array  $offer Offre normalisée.
	 * @param string $base  Urn sans préfixe.
	 * @return bool
	 */
	private static function has_own_field( $offer, $base ) {
		foreach ( (array) pivot_get( $offer, 'specs', array() ) as $spec ) {
			if ( Pivot_Fields::base_urn( (string) pivot_get( $spec, 'urn', '' ) ) === $base ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Le champ d'une offre alimente-t-il le critère posé sur une urn ?
	 *
	 * Le champ lui-même. À défaut, ses cases à cocher : un choix multiple
	 * (spécialités culinaires…) arrive en booléens enfants, sans champ
	 * parent. Quand le champ est là, ses enfants sont des champs distincts —
	 * urn:fld:class:value (le classement en chiffres), la mention
	 * « Superior » — et doubleraient les entrées de sa liste.
	 *
	 * @param array  $spec      Champ de l'offre.
	 * @param string $spec_base Son urn, sans préfixe de langue.
	 * @param string $base      Urn du critère, sans préfixe de langue.
	 * @param bool   $own       L'offre porte-t-elle le champ lui-même ?
	 * @return bool
	 */
	private static function spec_belongs( $spec, $spec_base, $base, $own ) {
		if ( $spec_base === $base ) {
			return true;
		}

		return ! $own
			&& 0 === strpos( $spec_base, $base . ':' )
			&& 'Boolean' === pivot_get( $spec, 'type', '' );
	}

	/**
	 * Entrées de facette issues d'un champ PIVOT.
	 *
	 * @param array $filter Définition du filtre.
	 * @param array $offer  Offre normalisée.
	 * @return array
	 */
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
		$own     = self::has_own_field( $offer, $urn );

		$entries = array();

		foreach ( (array) pivot_get( $offer, 'specs', array() ) as $spec ) {
			$spec_urn = pivot_get( $spec, 'urn', '' );

			if ( Pivot_Fields::urn_lang( $spec_urn ) !== $variant ) {
				continue;
			}

			$spec_urn = Pivot_Fields::base_urn( $spec_urn );

			if ( ! self::spec_belongs( $spec, $spec_urn, $urn, $own ) ) {
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

			if ( self::is_excluded_value( $value ) ) {
				continue;
			}

			if ( ! $labels && 0 === strpos( $value, 'urn:' ) ) {
				$labels = Pivot_Thesaurus::label_set( $value );
			}

			$entries[] = array( 'raw' => $value, 'labels' => $labels );
		}

		return $entries;
	}

	/**
	 * Cette valeur doit-elle rester hors des choix d'un critère ?
	 *
	 * Un classement échu ne qualifie plus l'offre : le proposer au visiteur
	 * ne mènerait qu'à des offres qui ne sont plus classées. L'offre reste
	 * dans le listing, sans valeur pour ce critère.
	 *
	 * @param string $value Valeur d'origine (urn ou texte).
	 * @return bool
	 */
	private static function is_excluded_value( $value ) {
		static $excluded = null;

		if ( null === $excluded ) {
			/**
			 * Valeurs écartées des critères de recherche.
			 *
			 * @param array $values Urns de valeurs.
			 */
			$excluded = array_flip( (array) apply_filters( 'pivot_excluded_facet_values', array( 'urn:val:class:echue' ) ) );
		}

		return isset( $excluded[ $value ] );
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

		// Une moisson vide n'écrase jamais un index garni.
		//
		// PIVOT peut répondre correctement mais sans offre : requête mal cadrée,
		// incident passager, quota. Dans ce cas `pagesCount` vaut 1, start()
		// tombait directement ici, et l'index publié devenait `count: 0` —
		// la page de listing se vidait jusqu'à ce que quelqu'un s'en aperçoive.
		// Reconstruire à vide se demande explicitement, en supprimant d'abord
		// l'index (c'est ce que fait « recommencer » depuis l'administration).
		if ( ! $records && self::has_populated_index( $listing_id ) ) {
			Pivot_Logger::error(
				sprintf(
					'Reconstruction de « %s » sans aucune offre : index précédent conservé.',
					$listing_id
				),
				array( 'service' => 'index' )
			);

			$state['done'] = true;

			self::clear_state( $listing_id );

			return $state;
		}

		self::publish(
			$listing,
			$records,
			array(
				'index_built'   => time(),
				'index_checked' => time(),
				'index_changes' => 0,
			)
		);

		// Fiches gardées pour appliquer les différentiels des nuits suivantes.
		if ( self::diff_enabled( $listing ) ) {
			self::save_records( $listing_id, $records, (array) pivot_get( $state, 'keymap', array() ) );
			self::save_sync_state(
				$listing_id,
				array(
					'anchored'  => ! empty( $state['anchored'] ),
					'signature' => self::signature( $listing ),
					'fails'     => 0,
				)
			);
		} else {
			self::forget_sync( $listing_id );
		}

		$message = sprintf(
			'Index « %1$s » reconstruit : %2$d offres, %3$d langue(s).',
			$listing_id,
			count( $records ),
			count( Pivot_I18n::enabled() )
		);

		if ( ! empty( $state['warmed'] ) ) {
			$message .= sprintf( ' %d fiche(s) détail mise(s) en cache.', (int) $state['warmed'] );
		}

		Pivot_Logger::info(
			$message,
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
	 * Écrit les fichiers d'index de toutes les langues à partir des fiches neutres.
	 *
	 * Ne parle pas à PIVOT : sert à la fin d'une reconstruction complète comme
	 * après l'application d'un différentiel.
	 *
	 * @param array $listing Configuration.
	 * @param array $records Fiches neutres.
	 * @param array $meta    Champs techniques enregistrés en plus sur la page.
	 */
	private static function publish( $listing, $records, $meta = array() ) {
		$listing_id = pivot_get( $listing, 'id', '' );
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
				self::forget( $listing_id );
			}
		}

		// Valeurs découvertes, proposées à l'administrateur pour la traduction.
		// Un critère numérique n'a rien à traduire : on n'en retient que
		// l'étendue, écrite comme le shortcode l'attend (« 1..165 »). De même
		// pour une date : « 2026-01-10..2026-12-20 ».
		$ranges = array();
		$dates  = array();

		foreach ( (array) pivot_get( $listing, 'filters', array() ) as $filter ) {
			if ( 'range' === pivot_get( $filter, 'type' ) && pivot_get( $filter, 'key' ) ) {
				$ranges[ $filter['key'] ] = true;
			}

			if ( 'date' === pivot_get( $filter, 'type' ) && pivot_get( $filter, 'key' ) ) {
				$dates[ $filter['key'] ] = true;
			}
		}

		foreach ( $records as $record ) {
			foreach ( (array) pivot_get( $record, 'facets', array() ) as $key => $values ) {
				if ( isset( $dates[ $key ] ) ) {
					foreach ( $values as $period ) {
						$dates[ $key ] = true === $dates[ $key ]
							? $period
							: array( min( $dates[ $key ][0], $period[0] ), max( $dates[ $key ][1], $period[1] ) );
					}
					continue;
				}

				if ( isset( $ranges[ $key ] ) ) {
					foreach ( $values as $value ) {
						$ranges[ $key ] = true === $ranges[ $key ]
							? array( $value, $value )
							: array( min( $ranges[ $key ][0], $value ), max( $ranges[ $key ][1], $value ) );
					}
					continue;
				}

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

		foreach ( $ranges as $key => $bounds ) {
			if ( is_array( $bounds ) ) {
				$facets[ $key ] = array( $bounds[0] . '..' . $bounds[1] );
			}
		}

		foreach ( $dates as $key => $bounds ) {
			if ( is_array( $bounds ) ) {
				$facets[ $key ] = array( pivot_date_iso( $bounds[0] ) . '..' . pivot_date_iso( $bounds[1] ) );
			}
		}

		Pivot_Listings::update_meta(
			$listing_id,
			array_merge(
				$meta,
				array(
					'index_count'  => count( $records ),
					'facet_values' => $facets,
					'offer_types'  => $types,
				)
			)
		);
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

		foreach ( array( 't', 'z', 'r', 'nu', 'lat', 'lng', 'i', 'cl', 'g' ) as $key ) {
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

		$item['u'] = Pivot_Rewrites::detail_url( $code, (int) pivot_get( $record, 't', 0 ), $lang );

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
	 * WordPress ne bascule pas vers la locale qu'il croit courante. Or WPML
	 * filtre cette locale (langue de l'administration, cookie) sans que les
	 * traductions chargées suivent : après le retour de la langue précédente,
	 * WordPress recharge la locale du site, et l'index anglais sortait avec
	 * les textes français, le néerlandais avec ceux de l'index d'avant. La
	 * bascule est donc forcée : le filtre ne répond qu'à la comparaison qui
	 * ouvre switch_to_locale(), pas aux appels faits pendant la bascule.
	 *
	 * @param string $lang Langue.
	 */
	private static function switch_lang( $lang ) {
		Pivot_I18n::set_current( $lang );

		if ( function_exists( 'switch_to_locale' ) ) {
			$force = static function () use ( &$force ) {
				remove_filter( 'pre_determine_locale', $force );

				return 'pivot-switch';
			};

			add_filter( 'pre_determine_locale', $force );
			switch_to_locale( Pivot_I18n::locale( $lang ) );
			remove_filter( 'pre_determine_locale', $force );
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
			// Un nombre nu ne dit rien à la recherche : taper « 4 » ne doit pas
			// ramener tous les hôtels de quatre chambres. Une date non plus.
			if ( in_array( pivot_get( $filters, array( $key, 'type' ) ), array( 'range', 'date' ), true ) ) {
				continue;
			}

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

			if ( 'range' === $entry['type'] ) {
				$out[] = array_merge( $entry, self::range_facet( $filter, $records ) );
				continue;
			}

			if ( 'date' === $entry['type'] ) {
				$out[] = array_merge( $entry, self::date_facet( $filter, $records ) );
				continue;
			}

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
	 * Étendue d'un critère numérique, pour borner la jauge et guider la saisie.
	 *
	 * Le pas vaut 1, ou 0,1 quand les valeurs sont décimales et resserrées
	 * (une note sur 20, une distance de quelques kilomètres). Les bornes sont
	 * arrondies sur ce pas : sans quoi une jauge qui part de 9,5 par pas de 1
	 * ne pourrait jamais atteindre 740.
	 *
	 * @param array $filter  Définition du filtre.
	 * @param array $records Fiches neutres.
	 * @return array
	 */
	private static function range_facet( $filter, $records ) {
		$numbers = array();

		foreach ( $records as $record ) {
			foreach ( (array) pivot_get( $record, array( 'facets', pivot_get( $filter, 'key' ) ), array() ) as $value ) {
				if ( is_int( $value ) || is_float( $value ) ) {
					$numbers[] = $value;
				}
			}
		}

		$facet = array(
			'operator' => pivot_get( $filter, 'operator', 'gte' ),
			'widget'   => pivot_get( $filter, 'widget', 'input' ),
			'unit'     => pivot_get( $filter, 'unit', '' ),
		);

		if ( ! $numbers ) {
			return $facet;
		}

		$min      = min( $numbers );
		$max      = max( $numbers );
		$decimals = count( array_filter( $numbers, 'is_float' ) ) > 0;
		$scale    = ( $decimals && $max - $min < 10 ) ? 10 : 1;

		// Calcul en dixièmes entiers : 9.5 / 0.1 donne 94,999… en virgule
		// flottante, et la borne basse tomberait à 9,4.
		$facet['min']  = pivot_parse_number( floor( round( $min * $scale, 6 ) ) / $scale );
		$facet['max']  = pivot_parse_number( ceil( round( $max * $scale, 6 ) ) / $scale );
		$facet['step'] = 10 === $scale ? 0.1 : 1;

		return $facet;
	}

	/**
	 * Étendue d'un critère de date, pour borner le calendrier du navigateur.
	 *
	 * Les bornes sont écrites au format ISO, celui des champs de date HTML.
	 *
	 * @param array $filter  Définition du filtre.
	 * @param array $records Fiches neutres.
	 * @return array
	 */
	private static function date_facet( $filter, $records ) {
		$min = null;
		$max = null;

		foreach ( $records as $record ) {
			foreach ( (array) pivot_get( $record, array( 'facets', pivot_get( $filter, 'key' ) ), array() ) as $period ) {
				if ( ! is_array( $period ) || 2 !== count( $period ) ) {
					continue;
				}

				$min = null === $min ? $period[0] : min( $min, $period[0] );
				$max = null === $max ? $period[1] : max( $max, $period[1] );
			}
		}

		$facet = array(
			'operator' => pivot_get( $filter, 'operator', 'between' ),
			'match'    => Pivot_Listings::date_match( $filter ),
		);

		if ( null !== $min ) {
			$facet['min'] = pivot_date_iso( $min );
			$facet['max'] = pivot_date_iso( $max );
		}

		return $facet;
	}

	/* ----------------------------------------------------------- différentiel */

	/**
	 * La page se tient-elle à jour par différentiel ?
	 *
	 * PIVOT tient un seul différentiel par clé et par requête. Deux pages sur
	 * la même requête se voleraient les changements : la première à valider les
	 * ferait disparaître pour la seconde. Elles restent donc en reconstruction
	 * complète.
	 *
	 * @param array $listing Configuration.
	 * @return bool
	 */
	public static function diff_enabled( $listing ) {
		if ( ! pivot_settings( 'diff_enabled', 1 ) || '' === (string) pivot_get( $listing, 'query_code', '' ) ) {
			return false;
		}

		return ! self::shares_query( $listing );
	}

	/**
	 * Une autre page active interroge-t-elle la même requête ?
	 *
	 * @param array $listing Configuration.
	 * @return bool
	 */
	public static function shares_query( $listing ) {
		return (bool) self::query_siblings( $listing );
	}

	/**
	 * Autres pages qui interrogent la même requête.
	 *
	 * @param array $listing     Configuration.
	 * @param bool  $active_only Pages publiées seulement : les seules qui
	 *                           consomment le différentiel.
	 * @return array Configurations, par identifiant.
	 */
	public static function query_siblings( $listing, $active_only = true ) {
		$id    = (string) pivot_get( $listing, 'id', '' );
		$query = strtoupper( trim( (string) pivot_get( $listing, 'query_code', '' ) ) );
		$out   = array();

		if ( '' === $query ) {
			return $out;
		}

		foreach ( $active_only ? Pivot_Listings::active() : Pivot_Listings::all() as $other_id => $other ) {
			if ( (string) $other['id'] !== $id && strtoupper( (string) $other['query_code'] ) === $query ) {
				$out[ $other_id ] = $other;
			}
		}

		return $out;
	}

	/**
	 * Une reconstruction complète est-elle due ?
	 *
	 * Sans ancrage réussi, avec une configuration qui a changé depuis, ou
	 * quand l'intervalle de sécurité est écoulé : les offres liées (médias,
	 * contacts) peuvent changer sans que la date de l'offre principale bouge,
	 * et le différentiel ne les signale pas.
	 *
	 * @param array $listing Configuration.
	 * @return bool
	 */
	public static function needs_full_rebuild( $listing ) {
		$sync = self::sync_state( pivot_get( $listing, 'id', '' ) );

		if ( empty( $sync['anchored'] ) || pivot_get( $sync, 'signature' ) !== self::signature( $listing ) ) {
			return true;
		}

		return ( time() - (int) pivot_get( $listing, 'index_built', 0 ) ) >= self::index_ttl( $listing );
	}

	/**
	 * Mise à jour de nuit : différentiel, ou reconstruction complète si elle
	 * est due ou si le différentiel n'est pas exploitable.
	 *
	 * @param string $listing_id Identifiant.
	 * @param int    $budget     Secondes accordées à une reconstruction.
	 * @return array|WP_Error Compte rendu, ou état de la reconstruction.
	 */
	public static function nightly( $listing_id, $budget = 25 ) {
		$listing = Pivot_Listings::get( $listing_id );

		if ( ! $listing ) {
			return new WP_Error( 'pivot_unknown_listing', __( 'Page de listing introuvable.', 'pivot-offres' ) );
		}

		if ( self::needs_full_rebuild( $listing ) ) {
			return self::run( $listing_id, $budget );
		}

		$result = self::sync( $listing_id );

		if ( ! is_wp_error( $result ) && 'full' === $result['status'] ) {
			return self::run( $listing_id, $budget );
		}

		return $result;
	}

	/**
	 * Applique le différentiel de PIVOT à l'index d'une page.
	 *
	 * Un premier appel en `content=0` dit ce qui a changé : quelques centaines
	 * d'octets quand rien n'a bougé, ce qui est le cas presque toutes les
	 * nuits. S'il y a des changements, un second appel les rapporte au niveau
	 * de détail de la page ; c'est celui-là que l'on valide, une fois l'index
	 * réécrit. Si la validation se perd, le différentiel suivant rapporte les
	 * mêmes changements, qui sont réappliqués sans dommage.
	 *
	 * @param string $listing_id Identifiant.
	 * @return array|WP_Error status : unchanged, updated, building ou full.
	 */
	public static function sync( $listing_id ) {
		$listing = Pivot_Listings::get( $listing_id );

		if ( ! $listing ) {
			return new WP_Error( 'pivot_unknown_listing', __( 'Page de listing introuvable.', 'pivot-offres' ) );
		}

		if ( ! self::diff_enabled( $listing ) ) {
			return new WP_Error( 'pivot_diff_disabled', __( 'Le différentiel n\'est pas actif pour cette page.', 'pivot-offres' ) );
		}

		$lock = self::lock_name( $listing_id );

		if ( ! Pivot_Cache::acquire_lock( $lock, 180 ) ) {
			return new WP_Error(
				'pivot_build_locked',
				__( 'Une reconstruction de cette page est déjà en cours.', 'pivot-offres' )
			);
		}

		try {
			// Une reconstruction complète inachevée a posé sa propre référence :
			// elle se termine d'abord.
			$progress = self::progress( $listing_id );

			if ( is_array( $progress ) && ! empty( $progress['token'] ) && empty( $progress['done'] ) ) {
				return array( 'status' => 'building', 'changes' => 0 );
			}

			$probe = Pivot_Repository::query_diff( $listing, 0 );

			if ( is_wp_error( $probe ) ) {
				return self::sync_failed( $listing, $probe );
			}

			$changes = (array) pivot_get( $probe, 'offers', array() );

			if ( ! $changes ) {
				self::sync_succeeded( $listing_id, 0 );

				return array( 'status' => 'unchanged', 'changes' => 0 );
			}

			$store = self::records_store( $listing_id );

			if ( null === $store ) {
				return self::desync( $listing, 'fiches locales introuvables' );
			}

			$reason = self::diff_mismatch( $changes, $store['records'] );

			if ( $reason ) {
				return self::desync( $listing, $reason );
			}

			$diff = Pivot_Repository::query_diff( $listing, max( 1, (int) pivot_get( $listing, 'content', 2 ) ) );

			if ( is_wp_error( $diff ) ) {
				return self::sync_failed( $listing, $diff );
			}

			// Relu : des changements ont pu arriver entre les deux appels.
			$offers = (array) pivot_get( $diff, 'offers', array() );
			$reason = self::diff_mismatch( $offers, $store['records'] );

			if ( $reason ) {
				return self::desync( $listing, $reason );
			}

			$records = $store['records'];
			$state   = array( 'keymap' => $store['keymap'] );
			$warm    = 3 === (int) pivot_get( $listing, 'content', 2 );
			$ttl     = $warm ? self::detail_ttl( $listing ) : 0;
			$counts  = array( 0, 0, 0 );

			foreach ( $offers as $offer ) {
				$code      = (string) pivot_get( $offer, 'code', '' );
				$operation = (int) pivot_get( $offer, 'operation', 1 );

				if ( '' === $code || $operation < 0 || $operation > 2 ) {
					continue;
				}

				$counts[ $operation ]++;

				if ( 2 === $operation ) {
					unset( $records[ $code ] );
					continue;
				}

				$record = self::build_record( $listing, $offer, $state );

				if ( $record ) {
					$records[ $code ] = $record;
				}

				// La fiche détail suit : réécrite si l'offre vient d'arriver au
				// bon niveau de détail, oubliée sinon pour être relue à jour.
				if ( $warm ) {
					Pivot_Repository::remember_offer( $offer, 3, $ttl );
				} else {
					Pivot_Repository::forget_offer( $code );
				}
			}

			self::publish(
				$listing,
				$records,
				array(
					'index_checked' => time(),
					'index_changes' => count( $offers ),
				)
			);

			self::save_records( $listing_id, $records, (array) pivot_get( $state, 'keymap', array() ) );

			$ack = Pivot_Repository::query_ack( $listing );

			if ( is_wp_error( $ack ) ) {
				Pivot_Logger::warn(
					sprintf(
						'Différentiel de « %1$s » appliqué mais non validé chez PIVOT (%2$s) : il sera réappliqué au prochain passage.',
						$listing_id,
						$ack->get_error_message()
					),
					array( 'service' => 'index' )
				);
			}

			self::sync_succeeded( $listing_id, count( $offers ), false );

			Pivot_Logger::info(
				sprintf(
					'Index « %1$s » mis à jour par différentiel : %2$d ajout(s), %3$d modification(s), %4$d retrait(s).',
					$listing_id,
					$counts[0],
					$counts[1],
					$counts[2]
				),
				array( 'service' => 'index', 'cache_status' => 'diff', 'context' => array( 'listing' => $listing_id ) )
			);

			return array( 'status' => 'updated', 'changes' => count( $offers ) );
		} finally {
			Pivot_Cache::release_lock( $lock );
		}
	}

	/**
	 * Le différentiel contredit-il les fiches locales ?
	 *
	 * @param array $offers  Offres du différentiel.
	 * @param array $records Fiches locales, par code.
	 * @return string Motif, ou chaîne vide si tout concorde.
	 */
	private static function diff_mismatch( $offers, $records ) {
		$limit = max( self::SYNC_MAX_CHANGES, (int) ceil( count( $records ) * self::SYNC_MAX_RATIO ) );

		if ( count( $offers ) > $limit ) {
			return sprintf( '%d changements', count( $offers ) );
		}

		$known_added = 0;

		foreach ( $offers as $offer ) {
			$code      = (string) pivot_get( $offer, 'code', '' );
			$operation = (int) pivot_get( $offer, 'operation', 1 );
			$known     = isset( $records[ $code ] );

			// Modifiée ou retirée sans qu'on la connaisse : la référence de
			// PIVOT est en avance sur nos fiches.
			if ( ! $known && $operation > 0 ) {
				return sprintf( 'offre %s inconnue', $code );
			}

			if ( $known && 0 === $operation ) {
				$known_added++;
			}
		}

		// Une offre déjà connue peut revenir « ajoutée » quand la validation
		// précédente s'est perdue. La moitié des offres d'un coup, c'est que
		// PIVOT n'a plus de référence : il renvoie tout, et les offres sorties
		// entre-temps ne seraient jamais signalées.
		if ( $known_added && $known_added >= count( $records ) / 2 ) {
			return 'référence perdue chez PIVOT';
		}

		return '';
	}

	/**
	 * Le différentiel n'est pas exploitable : on repartira d'une
	 * reconstruction complète, qui repose la référence.
	 *
	 * @param array  $listing Configuration.
	 * @param string $reason  Motif, pour le journal.
	 * @return array
	 */
	private static function desync( $listing, $reason ) {
		$listing_id = pivot_get( $listing, 'id', '' );
		$sync       = self::sync_state( $listing_id );

		$sync['anchored'] = false;
		self::save_sync_state( $listing_id, $sync );

		Pivot_Logger::warn(
			sprintf( 'Différentiel de « %1$s » écarté (%2$s) : reconstruction complète.', $listing_id, $reason ),
			array( 'service' => 'index' )
		);

		return array( 'status' => 'full', 'changes' => 0 );
	}

	/**
	 * Compte un échec du différentiel.
	 *
	 * Au troisième d'affilée, le différentiel est réinitialisé chez PIVOT et
	 * la page repart d'une reconstruction complète.
	 *
	 * @param array    $listing Configuration.
	 * @param WP_Error $error   Erreur rencontrée.
	 * @return array|WP_Error
	 */
	private static function sync_failed( $listing, $error ) {
		$listing_id    = pivot_get( $listing, 'id', '' );
		$sync          = self::sync_state( $listing_id );
		$sync['fails'] = (int) pivot_get( $sync, 'fails', 0 ) + 1;

		if ( $sync['fails'] < self::SYNC_FAILURES ) {
			self::save_sync_state( $listing_id, $sync );

			Pivot_Logger::error(
				sprintf(
					'Différentiel de « %1$s » indisponible (%2$s) : nouvel essai plus tard.',
					$listing_id,
					$error->get_error_message()
				),
				array( 'service' => 'index' )
			);

			return $error;
		}

		Pivot_Repository::query_clear( $listing );

		$sync['fails']    = 0;
		$sync['anchored'] = false;
		self::save_sync_state( $listing_id, $sync );

		Pivot_Logger::error(
			sprintf(
				'Différentiel de « %1$s » en échec %2$d fois (%3$s) : réinitialisé chez PIVOT, reconstruction complète.',
				$listing_id,
				self::SYNC_FAILURES,
				$error->get_error_message()
			),
			array( 'service' => 'index' )
		);

		return array( 'status' => 'full', 'changes' => 0 );
	}

	/**
	 * Enregistre une vérification réussie.
	 *
	 * @param string $listing_id Identifiant.
	 * @param int    $changes    Changements appliqués.
	 * @param bool   $refresh    Rafraîchir les métadonnées de la page (déjà
	 *                           fait par publish() quand l'index est réécrit).
	 */
	private static function sync_succeeded( $listing_id, $changes, $refresh = true ) {
		$sync = self::sync_state( $listing_id );

		if ( ! empty( $sync['fails'] ) ) {
			$sync['fails'] = 0;
			self::save_sync_state( $listing_id, $sync );
		}

		if ( $refresh ) {
			Pivot_Listings::update_meta(
				$listing_id,
				array(
					'index_checked' => time(),
					'index_changes' => (int) $changes,
				)
			);
		}
	}

	/**
	 * Pose la référence du différentiel.
	 *
	 * Appelée au début d'une reconstruction complète, avant le téléchargement :
	 * une offre modifiée pendant la construction — qui peut durer plusieurs
	 * minutes, par tranches — ressortira au différentiel suivant. Posée après,
	 * elle serait perdue.
	 *
	 * @param array $listing Configuration.
	 * @return bool
	 */
	private static function anchor( $listing ) {
		$diff = Pivot_Repository::query_diff( $listing, 0 );
		$ack  = is_wp_error( $diff ) ? $diff : Pivot_Repository::query_ack( $listing );

		if ( is_wp_error( $ack ) ) {
			Pivot_Logger::error(
				sprintf(
					'Différentiel de « %1$s » non ancré (%2$s) : la page restera en reconstruction complète.',
					pivot_get( $listing, 'id', '' ),
					$ack->get_error_message()
				),
				array( 'service' => 'index' )
			);

			return false;
		}

		return true;
	}

	/**
	 * Durée de vie d'une fiche détail préchauffée.
	 *
	 * Tenue à jour par le différentiel, elle peut vivre jusqu'à la
	 * reconstruction complète suivante, qui la réécrit.
	 *
	 * @param array $listing Configuration.
	 * @return int Secondes.
	 */
	private static function detail_ttl( $listing ) {
		$ttl = (int) pivot_settings( 'ttl_offer', 12 * HOUR_IN_SECONDS );

		if ( self::diff_enabled( $listing ) ) {
			$ttl = max( $ttl, self::index_ttl( $listing ) + 2 * DAY_IN_SECONDS );
		}

		return $ttl;
	}

	/**
	 * Empreinte de ce dont dépendent les fiches neutres.
	 *
	 * Si elle change, les fiches gardées ne correspondent plus à la page : la
	 * configuration de la page, les langues, et le format des fiches.
	 *
	 * @param array $listing Configuration.
	 * @return string
	 */
	private static function signature( $listing ) {
		return md5(
			(string) wp_json_encode(
				array(
					(string) pivot_get( $listing, 'query_code', '' ),
					(array) pivot_get( $listing, 'query_params', array() ),
					(int) pivot_get( $listing, 'content', 2 ),
					(array) pivot_get( $listing, 'filters', array() ),
					Pivot_I18n::enabled(),
					self::RECORD_FORMAT,
				)
			)
		);
	}

	/**
	 * État du différentiel d'une page : ancrage, empreinte, échecs.
	 *
	 * @param string $listing_id Identifiant.
	 * @return array
	 */
	public static function sync_state( $listing_id ) {
		$state = Pivot_Cache::get( self::STATE_GROUP, self::sync_key( $listing_id ) );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param string $listing_id Identifiant.
	 * @param array  $state      État.
	 */
	private static function save_sync_state( $listing_id, $state ) {
		Pivot_Cache::set( self::STATE_GROUP, self::sync_key( $listing_id ), $state, 0 );
	}

	/**
	 * Fiches neutres gardées pour le différentiel.
	 *
	 * @param string $listing_id Identifiant.
	 * @return array|null array{records:array, keymap:array}, null si absentes.
	 */
	private static function records_store( $listing_id ) {
		$store = Pivot_Cache::get( self::STATE_GROUP, self::records_key( $listing_id ) );

		if ( ! is_array( $store ) || ! isset( $store['records'] ) || ! is_array( $store['records'] ) ) {
			return null;
		}

		return array(
			'records' => $store['records'],
			'keymap'  => isset( $store['keymap'] ) ? (array) $store['keymap'] : array(),
		);
	}

	/**
	 * @param string $listing_id Identifiant.
	 * @param array  $records    Fiches neutres, par code.
	 * @param array  $keymap     Clés stables des valeurs de facettes.
	 */
	private static function save_records( $listing_id, $records, $keymap ) {
		// Sans expiration ni mémorisation : relues une fois par nuit au plus,
		// et plusieurs mégaoctets sur les grosses pages.
		Pivot_Cache::set(
			self::STATE_GROUP,
			self::records_key( $listing_id ),
			array(
				'records' => $records,
				'keymap'  => $keymap,
			),
			0,
			false
		);
	}

	/**
	 * Oublie le différentiel d'une page.
	 *
	 * @param string $listing_id Identifiant.
	 */
	private static function forget_sync( $listing_id ) {
		Pivot_Cache::delete( self::STATE_GROUP, self::sync_key( $listing_id ) );
		Pivot_Cache::delete( self::STATE_GROUP, self::records_key( $listing_id ) );
	}

	/**
	 * @param string $listing_id Identifiant.
	 * @return string
	 */
	private static function sync_key( $listing_id ) {
		return 'sync|' . $listing_id;
	}

	/**
	 * @param string $listing_id Identifiant.
	 * @return string
	 */
	private static function records_key( $listing_id ) {
		return 'records|' . $listing_id;
	}

	/**
	 * Planifie la poursuite d'une construction interrompue.
	 *
	 * @param string $listing_id Identifiant.
	 * @param int    $delay      Délai en secondes.
	 */
	private static function schedule_continue( $listing_id, $delay = 30 ) {
		if ( ! wp_next_scheduled( 'pivot_continue_index', array( $listing_id ) ) ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), 'pivot_continue_index', array( $listing_id ) );
		}
	}

	/**
	 * Fournit l'index de la langue demandée.
	 *
	 * Ne construit jamais dans la requête en cours. La construction enchaîne des
	 * appels à PIVOT — onze pour un millier d'offres — et tenait la page du
	 * visiteur vingt-cinq secondes, parfois cinquante lorsqu'un appel démarrait
	 * juste avant la fin du budget. Pire : si le budget expirait avant la fin,
	 * aucun fichier n'était écrit, et la page s'affichait vide après cette
	 * attente ; le visiteur suivant recommençait à zéro.
	 *
	 * Désormais l'absence d'index programme le travail et rend la main tout de
	 * suite. L'appelant distingue les deux cas : null signifie « pas encore
	 * disponible », à traduire par un message d'attente et non par « aucune
	 * offre ».
	 *
	 * Un index périmé, lui, continue d'être servi pendant que la reconstruction
	 * part en tâche de fond : mieux vaut une donnée d'hier qu'une page vide.
	 *
	 * @param array       $listing Configuration.
	 * @param string|null $lang    Langue.
	 * @return array|null Null si l'index n'est pas encore disponible.
	 */
	public static function ensure( $listing, $lang = null ) {
		$listing_id = pivot_get( $listing, 'id', '' );
		$lang       = $lang ? $lang : Pivot_I18n::current();
		$path       = self::path( $listing_id, $lang );

		if ( ! file_exists( $path ) ) {
			self::schedule_continue( $listing_id, 1 );

			return null;
		}

		if ( ! self::is_fresh( $listing, $lang ) ) {
			self::schedule_continue( $listing_id, 5 );
		}

		return self::read( $listing_id, $lang );
	}
}
