<?php
/**
 * Accès au service thesaurus.
 *
 * Le thesaurus fournit les libellés des types d'offres, des champs et des
 * valeurs de champs dans les quatre langues. Une seule entrée de cache par
 * document sert donc toutes les langues du site : la traduction est choisie à
 * la lecture, pas à l'écriture.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Thesaurus {

	const GROUP = 'thesaurus';

	/**
	 * Récupère et met en cache un document du thesaurus.
	 *
	 * @param string   $path   Chemin relatif après /thesaurus.
	 * @param callable $parser Fonction de normalisation.
	 * @param array    $matrix Paramètres matriciels.
	 * @return mixed|WP_Error
	 */
	private static function fetch( $path, $parser, $matrix = array(), $auth = false ) {
		// La clé d'authentification peut entrer dans les paramètres : on ne la
		// laisse pas apparaître en clair dans le nom d'entrée du cache.
		$key    = 'thesaurus/' . $path . '|' . md5( wp_json_encode( $matrix ) . ( $auth ? '|auth' : '' ) );
		$cached = Pivot_Cache::get( self::GROUP, $key );

		if ( null !== $cached ) {
			// Un échec est mémorisé brièvement, comme pour les offres : sans
			// cela, pendant une panne du thésaurus, chaque valeur de facette non
			// résolue relançait un appel de trente secondes — et il y en a des
			// centaines dans une seule construction d'index.
			if ( is_array( $cached ) && isset( $cached['__error'] ) ) {
				return new WP_Error( 'pivot_thesaurus_unavailable', (string) $cached['__error'] );
			}

			Pivot_Logger::debug(
				'Thesaurus servi depuis le cache.',
				array( 'service' => 'thesaurus', 'endpoint' => $path, 'cache_status' => 'hit' )
			);
			return $cached;
		}

		$matrix   = wp_parse_args( $matrix, array( 'fmt' => 'xml' ) );
		$response = Pivot_Client::get(
			'thesaurus/' . ltrim( $path, '/' ),
			$matrix,
			array( 'auth' => $auth, 'service' => 'thesaurus' )
		);

		if ( is_wp_error( $response ) ) {
			self::remember_failure( $key, $response );

			return $response;
		}

		$parsed = call_user_func( $parser, $response['body'] );

		if ( is_wp_error( $parsed ) ) {
			self::remember_failure( $key, $parsed );

			return $parsed;
		}

		Pivot_Cache::set( self::GROUP, $key, $parsed, (int) pivot_settings( 'ttl_thesaurus', 30 * DAY_IN_SECONDS ) );

		return $parsed;
	}

	/**
	 * Mémorise brièvement un échec, pour ne pas le rejouer à chaque appel.
	 *
	 * @param string   $key   Clé de cache.
	 * @param WP_Error $error Erreur rencontrée.
	 */
	private static function remember_failure( $key, $error ) {
		Pivot_Cache::set(
			self::GROUP,
			$key,
			array( '__error' => $error->get_error_message() ),
			(int) pivot_settings( 'ttl_negative', 5 * MINUTE_IN_SECONDS )
		);
	}

	/**
	 * Types d'offres : id => tableau de traductions.
	 *
	 * @return array
	 */
	public static function offer_type_labels() {
		$specs = self::fetch( 'typeofr', array( 'Pivot_Parser', 'parse_thesaurus_specs' ) );

		if ( is_wp_error( $specs ) ) {
			return array();
		}

		$types = array();

		foreach ( $specs as $urn => $entry ) {
			if ( ! preg_match( '/^urn:typ:(\d+)$/', $urn, $matches ) ) {
				continue;
			}
			$types[ (int) $matches[1] ] = (array) pivot_get( $entry, 'labels', array() );
		}

		ksort( $types );

		return $types;
	}

	/**
	 * Types d'offres dans une langue : id => libellé.
	 *
	 * @param string|null $lang Langue.
	 * @return array
	 */
	public static function offer_types( $lang = null ) {
		$types = array();

		foreach ( self::offer_type_labels() as $id => $labels ) {
			$types[ $id ] = Pivot_I18n::pick( $labels, $lang, 'Type ' . $id );
		}

		return $types;
	}

	/**
	 * Traductions du libellé d'un type d'offre.
	 *
	 * @param int $type_id Identifiant.
	 * @return array
	 */
	public static function offer_type_label_set( $type_id ) {
		$types = self::offer_type_labels();
		return isset( $types[ (int) $type_id ] ) ? $types[ (int) $type_id ] : array();
	}

	/**
	 * Libellé d'un type d'offre dans une langue.
	 *
	 * @param int         $type_id Identifiant.
	 * @param string|null $lang    Langue.
	 * @return string
	 */
	public static function offer_type_label( $type_id, $lang = null ) {
		return Pivot_I18n::pick( self::offer_type_label_set( $type_id ), $lang );
	}

	/**
	 * Familles de types d'offres, telles que PIVOT les définit.
	 *
	 * @return array urn de famille => définition.
	 */
	public static function families() {
		$families = self::fetch( 'family', array( 'Pivot_Parser', 'parse_families' ) );

		return is_wp_error( $families ) ? array() : $families;
	}

	/**
	 * Famille à laquelle appartient un type d'offre.
	 *
	 * @param int $type_id Identifiant du type.
	 * @return array Définition de la famille, vide si inconnue.
	 */
	public static function family_of_type( $type_id ) {
		$type_id = (int) $type_id;

		foreach ( self::families() as $family ) {
			if ( in_array( $type_id, (array) pivot_get( $family, 'types', array() ), true ) ) {
				return $family;
			}
		}

		return array();
	}

	/**
	 * Structure logique complète d'un type d'offre.
	 *
	 * @param int $type_id Identifiant.
	 * @return array urn => définition (libellés multilingues inclus).
	 */
	public static function type_structure( $type_id ) {
		$type_id = (int) $type_id;

		if ( $type_id <= 0 ) {
			return array();
		}

		// PIVOT réduit les champs dynamiques à ceux de l'utilisateur global lié
		// à la clé. Sans elle, la structure contient les filtres de tous les
		// opérateurs : des centaines de champs qui ne vous concernent pas.
		$matrix = array();
		$key    = pivot_ws_key();

		if ( $key ) {
			$matrix['WS_KEY'] = $key;
		}

		$specs = self::fetch(
			'typeofr/' . $type_id,
			array( 'Pivot_Parser', 'parse_thesaurus_specs' ),
			$matrix,
			(bool) $key
		);

		return is_wp_error( $specs ) ? array() : $specs;
	}

	/**
	 * Définition d'une urn isolée.
	 *
	 * @param string $urn Urn.
	 * @return array
	 */
	public static function urn( $urn ) {
		if ( ! $urn ) {
			return array();
		}

		$specs = self::fetch( 'urn/' . $urn, array( 'Pivot_Parser', 'parse_thesaurus_specs' ) );

		if ( is_wp_error( $specs ) || ! isset( $specs[ $urn ] ) ) {
			return array();
		}

		return $specs[ $urn ];
	}

	/**
	 * Traductions du libellé d'une urn.
	 *
	 * @param string $urn     Urn.
	 * @param int    $type_id Type d'offre, pour chercher d'abord dans sa structure.
	 * @return array
	 */
	public static function label_set( $urn, $type_id = 0 ) {
		if ( ! $urn ) {
			return array();
		}

		if ( $type_id ) {
			$structure = self::type_structure( $type_id );
			if ( isset( $structure[ $urn ]['labels'] ) ) {
				return (array) $structure[ $urn ]['labels'];
			}
		}

		return (array) pivot_get( self::urn( $urn ), 'labels', array() );
	}

	/**
	 * Libellé d'une urn dans une langue.
	 *
	 * @param string      $urn     Urn.
	 * @param int         $type_id Type d'offre.
	 * @param string|null $lang    Langue.
	 * @return string
	 */
	public static function label( $urn, $type_id = 0, $lang = null ) {
		return Pivot_I18n::pick( self::label_set( $urn, $type_id ), $lang );
	}

	/**
	 * Champs filtrables d'un type d'offre, prêts à alimenter le sélecteur
	 * de l'écran d'administration.
	 *
	 * Chaque entrée porte son urn, ses libellés traduits, sa catégorie et le
	 * contrôle de filtre le plus adapté à son type PIVOT.
	 *
	 * @param int         $type_id Identifiant du type d'offre.
	 * @param string|null $lang    Langue des libellés.
	 * @return array
	 */
	public static function fields_for_type( $type_id, $lang = null ) {
		$structure = self::type_structure( $type_id );

		if ( ! $structure ) {
			return array();
		}

		$fields = array();

		foreach ( $structure as $urn => $entry ) {
			// Seuls les champs sont proposés : les catégories et les valeurs de
			// champs ne sont pas des cibles de filtre.
			if ( 0 !== strpos( $urn, 'urn:fld:' ) ) {
				continue;
			}

			// Filtres de catégorisation, filtres Cirkwi, champs dépréciés :
			// ils encombrent le catalogue sans jamais servir à l'affichage.
			if ( Pivot_Fields::is_excluded( $urn, $entry ) ) {
				continue;
			}

			$label = Pivot_I18n::pick( pivot_get( $entry, 'labels', array() ), $lang, '' );

			if ( ! $label ) {
				$label = $urn;
			}

			$cat       = pivot_get( $entry, 'cat', '' );
			$cat_label = $cat
				? Pivot_I18n::pick( pivot_get( $structure, array( $cat, 'labels' ), array() ), $lang, '' )
				: '';

			$type = (string) pivot_get( $entry, 'type', '' );

			$fields[] = array(
				'urn'     => $urn,
				'label'   => $label,
				'type'    => $type,
				'cat'     => $cat_label,
				'control' => self::suggested_control( $type ),
			);
		}

		usort(
			$fields,
			static function ( $a, $b ) {
				$byCat = strcoll( $a['cat'], $b['cat'] );
				return 0 !== $byCat ? $byCat : strcoll( $a['label'], $b['label'] );
			}
		);

		return $fields;
	}

	/**
	 * Contrôle de filtre le mieux adapté à un type de champ PIVOT.
	 *
	 * @param string $type Type déclaré dans le thesaurus.
	 * @return string
	 */
	public static function suggested_control( $type ) {
		$map = array(
			'Boolean'     => 'toggle',
			'Choice'      => 'select',
			'MultiChoice' => 'multiselect',
			'Text'        => 'text',
			'Textarea'    => 'text',
			'Url'         => 'text',
			'Email'       => 'text',
			'Integer'     => 'text',
			'Float'       => 'text',
			'Date'        => 'text',
		);

		/**
		 * Permet d'ajuster le contrôle proposé pour un type de champ.
		 *
		 * @param array $map Correspondances type PIVOT / contrôle de filtre.
		 */
		$map = apply_filters( 'pivot_field_control_map', $map );

		return isset( $map[ $type ] ) ? $map[ $type ] : 'select';
	}

	/**
	 * Valeurs possibles d'un champ à choix.
	 *
	 * @param string $urn     Urn du champ.
	 * @param int    $type_id Type d'offre.
	 * @return array urn de valeur => tableau de traductions.
	 */
	public static function field_values( $urn, $type_id = 0 ) {
		$structure = $type_id ? self::type_structure( $type_id ) : array();

		if ( ! $structure ) {
			$structure = self::fetch( 'urn/' . $urn, array( 'Pivot_Parser', 'parse_thesaurus_specs' ) );

			if ( is_wp_error( $structure ) ) {
				return array();
			}
		}

		$values = array();
		$prefix = $urn . ':';

		foreach ( $structure as $candidate => $entry ) {
			if ( $candidate === $urn || 0 !== strpos( $candidate, $prefix ) ) {
				continue;
			}

			$values[ $candidate ] = (array) pivot_get( $entry, 'labels', array() );
		}

		return $values;
	}

	/**
	 * Liste des localités.
	 *
	 * @return array
	 */
	public static function localities() {
		$tins = self::fetch( 'tins', array( 'Pivot_Parser', 'parse_tins' ) );
		return is_wp_error( $tins ) ? array() : $tins;
	}

	/**
	 * Vide le cache du thesaurus.
	 *
	 * @return int Nombre de fichiers supprimés.
	 */
	public static function flush() {
		$count = Pivot_Cache::flush( self::GROUP );

		// Les familles sont mémorisées pour la requête : sans cet oubli, la
		// page qui vient de vider le cache continuerait de les afficher.
		Pivot_Types::flush();

		Pivot_Logger::info(
			sprintf( 'Cache thesaurus réinitialisé (%d entrées).', $count ),
			array( 'service' => 'thesaurus', 'cache_status' => 'flush' )
		);

		return $count;
	}
}
