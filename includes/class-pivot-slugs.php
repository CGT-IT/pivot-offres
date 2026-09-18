<?php
/**
 * Registre des adresses de fiches.
 *
 * Le code PIVOT termine toujours le segment d'URL : une fiche renommée reste
 * donc résoluble à son ancienne adresse, qui redirige en 301 vers la nouvelle.
 * Ce registre sert à deux choses de plus :
 *
 *  - savoir quelles offres ont changé de nom, pour le signaler ;
 *  - permettre, en option, de figer l'adresse au premier nom référencé.
 *
 * Il ne contient que des noms et des segments d'URL, jamais de contenu d'offre.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Slugs {

	// Groupe privé : le registre vivait autrefois dans « index », servi au
	// navigateur, où ses 800 Ko d'état interne n'avaient rien à faire.
	const GROUP = 'registry';

	/** Nombre de changements conservés pour consultation. */
	const MAX_CHANGES = 200;

	/** @var array|null Registre chargé. */
	private static $map = null;

	/** @var array Observations de la construction en cours. */
	private static $pending = array();

	/**
	 * Segment d'URL naturel d'une offre : son nom suivi de son code.
	 *
	 * @param string $code Code PIVOT.
	 * @param string $name Nom de l'offre.
	 * @return string
	 */
	public static function natural( $code, $name ) {
		$code = strtolower( trim( (string) $code ) );

		return $name ? pivot_slugify( $name ) . '-' . $code : $code;
	}

	/**
	 * Segment d'URL à utiliser pour une offre.
	 *
	 * En mode « suivre le nom », c'est le segment naturel. En mode « figer »,
	 * c'est celui enregistré au premier référencement : l'adresse ne bouge plus,
	 * même si la dénomination change dans PIVOT.
	 *
	 * @param string $code Code PIVOT.
	 * @param string $lang Langue.
	 * @param string $name Nom de l'offre.
	 * @return string
	 */
	public static function slug_for( $code, $lang, $name ) {
		$natural = self::natural( $code, $name );

		if ( 'freeze' !== pivot_settings( 'slug_mode', 'follow' ) ) {
			return $natural;
		}

		$entry = self::entry( $code, $lang );

		return ! empty( $entry['slug'] ) ? $entry['slug'] : $natural;
	}

	/**
	 * Entrée du registre pour une offre et une langue.
	 *
	 * @param string $code Code PIVOT.
	 * @param string $lang Langue.
	 * @return array
	 */
	public static function entry( $code, $lang ) {
		$map = self::load();
		$key = self::key( $code, $lang );

		return isset( $map[ $key ] ) ? $map[ $key ] : array();
	}

	/**
	 * Clé de registre.
	 *
	 * @param string $code Code PIVOT.
	 * @param string $lang Langue.
	 * @return string
	 */
	private static function key( $code, $lang ) {
		return $lang . '|' . strtoupper( trim( (string) $code ) );
	}

	/**
	 * Charge le registre.
	 *
	 * @return array
	 */
	private static function load() {
		if ( null === self::$map ) {
			$stored   = Pivot_Cache::get( self::GROUP, 'slugs' );
			self::$map = is_array( $stored ) ? $stored : array();
		}

		return self::$map;
	}

	/**
	 * Note le nom actuel d'une offre pendant une construction d'index.
	 *
	 * @param string $code Code PIVOT.
	 * @param string $lang Langue.
	 * @param string $name Nom de l'offre.
	 */
	public static function observe( $code, $lang, $name ) {
		if ( ! $code ) {
			return;
		}

		self::$pending[ self::key( $code, $lang ) ] = array(
			'code'    => strtoupper( trim( (string) $code ) ),
			'lang'    => $lang,
			'name'    => (string) $name,
			'natural' => self::natural( $code, $name ),
		);
	}

	/**
	 * Enregistre les observations et relève les changements de nom.
	 *
	 * @return int Nombre de renommages détectés.
	 */
	public static function commit() {
		if ( ! self::$pending ) {
			return 0;
		}

		$map     = self::load();
		$changes = self::changes();
		$freeze  = 'freeze' === pivot_settings( 'slug_mode', 'follow' );
		$found   = 0;

		foreach ( self::$pending as $key => $entry ) {
			// Première rencontre : on retient l'adresse telle qu'elle est.
			if ( empty( $map[ $key ] ) ) {
				$map[ $key ] = array(
					'code'    => $entry['code'],
					'lang'    => $entry['lang'],
					'name'    => $entry['name'],
					'natural' => $entry['natural'],
					'slug'    => $entry['natural'],
					'since'   => time(),
				);
				continue;
			}

			$known = $map[ $key ];

			if ( $known['natural'] === $entry['natural'] ) {
				$map[ $key ]['name'] = $entry['name'];
				continue;
			}

			$found++;

			$changes[] = array(
				'code'     => $entry['code'],
				'lang'     => $entry['lang'],
				'old_name' => pivot_get( $known, 'name', '' ),
				'name'     => $entry['name'],
				'from'     => pivot_get( $known, 'slug', pivot_get( $known, 'natural', '' ) ),
				'to'       => $freeze ? pivot_get( $known, 'slug', $entry['natural'] ) : $entry['natural'],
				'frozen'   => $freeze,
				'time'     => time(),
			);

			$map[ $key ]['name']    = $entry['name'];
			$map[ $key ]['natural'] = $entry['natural'];

			// En mode « figer », l'adresse publiée ne bouge pas.
			if ( ! $freeze ) {
				$map[ $key ]['slug']  = $entry['natural'];
				$map[ $key ]['since'] = time();
			}
		}

		self::$map     = $map;
		self::$pending = array();

		Pivot_Cache::set( self::GROUP, 'slugs', $map, 0 );

		if ( $changes ) {
			// Seuls les plus récents sont conservés.
			$changes = array_slice( $changes, -self::MAX_CHANGES );
			Pivot_Cache::set( self::GROUP, 'slug-changes', $changes, 0 );
		}

		if ( $found ) {
			Pivot_Logger::info(
				sprintf( '%d offre(s) ont changé de dénomination dans PIVOT.', $found ),
				array( 'service' => 'index' )
			);
		}

		return $found;
	}

	/**
	 * Changements de nom relevés, du plus ancien au plus récent.
	 *
	 * @return array
	 */
	public static function changes() {
		$stored = Pivot_Cache::get( self::GROUP, 'slug-changes' );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Oublie les changements relevés.
	 */
	public static function clear_changes() {
		Pivot_Cache::delete( self::GROUP, 'slug-changes' );
	}

	/**
	 * Vide le registre.
	 *
	 * En mode « figer », cela libère les adresses : elles repartiront du nom
	 * actuel de chaque offre à la prochaine construction d'index.
	 */
	public static function flush() {
		self::$map = null;
		Pivot_Cache::delete( self::GROUP, 'slugs' );
		self::clear_changes();
	}
}
