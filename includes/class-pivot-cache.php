<?php
/**
 * Cache fichier.
 *
 * Les offres ne sont jamais stockées en base de données : tout transite par des
 * fichiers JSON dans wp-content/uploads/pivot-cache/, doublés par l'object cache
 * mémoire de la requête en cours. Chaque entrée porte sa propre date d'expiration.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Cache {

	const DIRNAME = 'pivot-cache';

	/** @var array Cache mémoire de la requête courante. */
	private static $memory = array();

	/**
	 * Chemin absolu du dossier de cache (créé si nécessaire).
	 *
	 * @param string $group Sous-dossier (offers, index, thesaurus, build).
	 * @return string
	 */
	public static function directory( $group = '' ) {
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] ) . self::DIRNAME;
		$path    = $group ? trailingslashit( $base ) . sanitize_key( $group ) : $base;

		if ( ! file_exists( $path ) ) {
			wp_mkdir_p( $path );
		}

		return trailingslashit( $path );
	}

	/**
	 * URL publique du dossier de cache.
	 *
	 * @param string $group Sous-dossier.
	 * @return string
	 */
	public static function url( $group = '' ) {
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['baseurl'] ) . self::DIRNAME;
		return trailingslashit( $group ? trailingslashit( $base ) . sanitize_key( $group ) : $base );
	}

	/**
	 * Crée le dossier et pose les garde-fous (pas d'indexation, pas de listing).
	 */
	public static function ensure_directory() {
		$base = self::directory();

		$index = $base . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore
		}

		$htaccess = $base . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "Options -Indexes\n"
				. "<FilesMatch \"\\.(php|phtml)$\">\n"
				. "Require all denied\n"
				. "</FilesMatch>\n";
			file_put_contents( $htaccess, $rules ); // phpcs:ignore
		}

		foreach ( array( 'offers', 'index', 'thesaurus', 'build', 'raw' ) as $group ) {
			self::directory( $group );
		}
	}

	/**
	 * Chemin du fichier d'une entrée.
	 *
	 * @param string $group Groupe.
	 * @param string $key   Clé.
	 * @return string
	 */
	private static function path( $group, $key ) {
		return self::directory( $group ) . md5( $key ) . '.json';
	}

	/**
	 * Lecture d'une entrée.
	 *
	 * @param string $group Groupe.
	 * @param string $key   Clé.
	 * @return mixed|null Null si absente ou expirée.
	 */
	public static function get( $group, $key ) {
		$memo = $group . '|' . $key;
		if ( array_key_exists( $memo, self::$memory ) ) {
			return self::$memory[ $memo ];
		}

		$file = self::path( $group, $key );
		if ( ! is_readable( $file ) ) {
			return null;
		}

		$raw = file_get_contents( $file ); // phpcs:ignore
		if ( false === $raw || '' === $raw ) {
			return null;
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) || ! isset( $payload['expires'] ) ) {
			return null;
		}

		if ( 0 !== (int) $payload['expires'] && (int) $payload['expires'] < time() ) {
			return null;
		}

		$value                  = isset( $payload['data'] ) ? $payload['data'] : null;
		self::$memory[ $memo ] = $value;

		return $value;
	}

	/**
	 * Écriture d'une entrée.
	 *
	 * @param string $group Groupe.
	 * @param string $key   Clé.
	 * @param mixed  $value Valeur sérialisable en JSON.
	 * @param int    $ttl   Durée de vie en secondes (0 = pas d'expiration).
	 * @return bool
	 */
	public static function set( $group, $key, $value, $ttl = 3600 ) {
		self::ensure_directory();

		$payload = array(
			'key'     => $key,
			'created' => time(),
			'expires' => $ttl > 0 ? time() + (int) $ttl : 0,
			'data'    => $value,
		);

		$json = wp_json_encode( $payload );
		if ( false === $json ) {
			return false;
		}

		$file = self::path( $group, $key );
		$tmp  = $file . '.' . wp_generate_password( 6, false ) . '.tmp';

		if ( false === file_put_contents( $tmp, $json, LOCK_EX ) ) { // phpcs:ignore
			return false;
		}

		if ( ! rename( $tmp, $file ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return false;
		}

		self::$memory[ $group . '|' . $key ] = $value;

		return true;
	}

	/**
	 * Suppression d'une entrée.
	 *
	 * @param string $group Groupe.
	 * @param string $key   Clé.
	 * @return bool
	 */
	public static function delete( $group, $key ) {
		unset( self::$memory[ $group . '|' . $key ] );
		$file = self::path( $group, $key );
		return file_exists( $file ) ? @unlink( $file ) : true; // phpcs:ignore
	}

	/**
	 * Vide un groupe complet (ou tout le cache si $group est vide).
	 *
	 * @param string $group Groupe à vider.
	 * @return int Nombre de fichiers supprimés.
	 */
	public static function flush( $group = '' ) {
		self::$memory = array();
		$count        = 0;

		$groups = $group ? array( $group ) : array( 'offers', 'index', 'thesaurus', 'build', 'raw' );

		foreach ( $groups as $name ) {
			$dir = self::directory( $name );
			foreach ( (array) glob( $dir . '*.json' ) as $file ) {
				if ( @unlink( $file ) ) { // phpcs:ignore
					$count++;
				}
			}
		}

		/**
		 * Déclenché après une purge de cache.
		 *
		 * @param string $group Groupe purgé ('' = tous).
		 * @param int    $count Nombre de fichiers supprimés.
		 */
		do_action( 'pivot_cache_flushed', $group, $count );

		return $count;
	}

	/**
	 * Supprime les entrées expirées.
	 *
	 * @return int Nombre de fichiers supprimés.
	 */
	public static function purge_expired() {
		$count = 0;
		foreach ( array( 'offers', 'raw', 'thesaurus' ) as $group ) {
			$dir = self::directory( $group );
			foreach ( (array) glob( $dir . '*.json' ) as $file ) {
				$raw = file_get_contents( $file ); // phpcs:ignore
				if ( false === $raw ) {
					continue;
				}
				$payload = json_decode( $raw, true );
				$expires = is_array( $payload ) && isset( $payload['expires'] ) ? (int) $payload['expires'] : 0;
				if ( $expires > 0 && $expires < time() && @unlink( $file ) ) { // phpcs:ignore
					$count++;
				}
			}
		}
		return $count;
	}

	/**
	 * Statistiques d'occupation, pour l'écran Outils.
	 *
	 * @return array
	 */
	public static function stats() {
		$stats = array();
		foreach ( array( 'offers', 'index', 'thesaurus', 'raw', 'build' ) as $group ) {
			$dir   = self::directory( $group );
			$files = (array) glob( $dir . '*' );
			$size  = 0;
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					$size += (int) filesize( $file );
				}
			}
			$stats[ $group ] = array(
				'files' => count( array_filter( $files, 'is_file' ) ),
				'size'  => $size,
			);
		}
		return $stats;
	}

	/**
	 * Écrit un fichier brut (index JSON servi directement au navigateur).
	 *
	 * @param string $group    Groupe.
	 * @param string $filename Nom de fichier (sans chemin).
	 * @param string $contents Contenu.
	 * @return string|false Chemin du fichier ou false.
	 */
	public static function put_file( $group, $filename, $contents ) {
		self::ensure_directory();
		$dir  = self::directory( $group );
		$file = $dir . sanitize_file_name( $filename );
		$tmp  = $file . '.' . wp_generate_password( 6, false ) . '.tmp';

		if ( false === file_put_contents( $tmp, $contents, LOCK_EX ) ) { // phpcs:ignore
			return false;
		}
		if ( ! rename( $tmp, $file ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return false;
		}
		return $file;
	}
}
