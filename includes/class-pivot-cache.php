<?php
/**
 * Cache fichier.
 *
 * Les offres ne sont jamais stockées en base de données : tout transite par des
 * fichiers JSON, doublés par l'object cache mémoire de la requête en cours.
 * Chaque entrée porte sa propre date d'expiration.
 *
 * Le stockage est coupé en deux, parce que les deux moitiés n'ont pas du tout
 * le même public :
 *
 *  - `index` est **servi au navigateur** : ces fichiers doivent rester sous
 *    uploads/, à une adresse stable et devinable, c'est leur raison d'être.
 *  - tout le reste — offres normalisées, état de construction, thésaurus,
 *    réponses brutes, registre des adresses — n'a **aucune raison d'être
 *    accessible par le Web**. Ces fichiers vivent sous wp-content/, hors de
 *    l'arborescence publiée.
 *
 * Auparavant tout était sous uploads/ et le nom de fichier était le md5 de la
 * clé. Les clés étant bâties sur des identifiants publics (le code d'une offre,
 * l'identifiant d'une page), les adresses se calculaient : n'importe qui
 * pouvait lire une offre complète — y compris les champs que l'administrateur
 * avait masqués — ou récupérer le jeton de pagination PIVOT dans l'état de
 * construction. Le seul rempart était un .htaccess, sans effet sous nginx.
 *
 * Les noms de fichiers sont désormais salés avec un secret propre au site, si
 * bien qu'ils ne se devinent plus, même pour la moitié publique.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Cache {

	const DIRNAME = 'pivot-cache';

	/** Dossier du stockage privé, sous wp-content/. */
	const PRIVATE_DIRNAME = 'pivot-cache-private';

	/** Option portant le sel des noms de fichiers. */
	const SECRET_OPTION = 'pivot_cache_secret';

	/** @var array Cache mémoire de la requête courante. */
	private static $memory = array();

	/** @var string|null Sel des noms de fichiers, mémoïsé. */
	private static $secret = null;

	/** @var array Dossiers déjà vérifiés pendant cette requête. */
	private static $ensured = array();

	/**
	 * Groupes servis directement au navigateur.
	 *
	 * @return array
	 */
	public static function public_groups() {
		return array( 'index' );
	}

	/**
	 * Tous les groupes, publics et privés.
	 *
	 * @return array
	 */
	public static function groups() {
		return array( 'index', 'offers', 'thesaurus', 'build', 'raw', 'registry' );
	}

	/**
	 * Un groupe est-il servi au navigateur ?
	 *
	 * Le groupe vide désigne la racine publique, par compatibilité.
	 *
	 * @param string $group Groupe.
	 * @return bool
	 */
	public static function is_public( $group ) {
		return '' === $group || in_array( $group, self::public_groups(), true );
	}

	/**
	 * Racine du stockage dont relève un groupe.
	 *
	 * @param string $group Groupe.
	 * @return string
	 */
	private static function base( $group ) {
		if ( self::is_public( $group ) ) {
			$uploads = wp_get_upload_dir();

			return trailingslashit( $uploads['basedir'] ) . self::DIRNAME;
		}

		/**
		 * Emplacement du stockage privé.
		 *
		 * Doit rester hors de l'arborescence servie par le serveur web.
		 *
		 * @param string $path Chemin absolu, sans barre oblique finale.
		 */
		return apply_filters(
			'pivot_private_cache_dir',
			trailingslashit( WP_CONTENT_DIR ) . self::PRIVATE_DIRNAME
		);
	}

	/**
	 * Sel des noms de fichiers.
	 *
	 * Un secret propre au site, et non wp_salt() : la rotation des sels de
	 * WordPress est une opération d'hygiène courante, et elle rendrait alors
	 * le registre des adresses introuvable — or lui n'est pas reconstructible.
	 *
	 * @return string
	 */
	private static function secret() {
		if ( null !== self::$secret ) {
			return self::$secret;
		}

		$secret = (string) get_option( self::SECRET_OPTION, '' );

		if ( '' === $secret ) {
			$secret = wp_generate_password( 32, false, false );
			update_option( self::SECRET_OPTION, $secret, true );
		}

		self::$secret = $secret;

		return $secret;
	}

	/**
	 * Chemin absolu du dossier de cache (créé si nécessaire).
	 *
	 * @param string $group Sous-dossier (index, offers, thesaurus, build, raw, registry).
	 * @return string
	 */
	public static function directory( $group = '' ) {
		$base = self::base( $group );
		$path = $group ? trailingslashit( $base ) . sanitize_key( $group ) : $base;

		// Un seul test par dossier et par requête : directory() est appelée à
		// chaque lecture comme à chaque écriture.
		if ( ! isset( self::$ensured[ $path ] ) ) {
			if ( ! file_exists( $path ) ) {
				wp_mkdir_p( $path );
			}
			self::$ensured[ $path ] = true;
		}

		return trailingslashit( $path );
	}

	/**
	 * URL publique du dossier de cache.
	 *
	 * N'a de sens que pour un groupe public : le stockage privé n'est pas servi.
	 *
	 * @param string $group Sous-dossier.
	 * @return string Chaîne vide pour un groupe privé.
	 */
	public static function url( $group = '' ) {
		if ( ! self::is_public( $group ) ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['baseurl'] ) . self::DIRNAME;

		return trailingslashit( $group ? trailingslashit( $base ) . sanitize_key( $group ) : $base );
	}

	/**
	 * Crée les dossiers et pose les garde-fous.
	 *
	 * Côté public on empêche seulement le listing et l'exécution de PHP : les
	 * index doivent rester téléchargeables. Côté privé on refuse tout, et le
	 * dossier est de toute façon hors de l'arborescence publiée — la règle n'est
	 * qu'une ceinture de plus, sans effet sous nginx.
	 */
	public static function ensure_directory() {
		// set() l'appelle à chaque écriture : une fois par requête suffit.
		static $done = false;

		if ( $done ) {
			return;
		}

		$done = true;

		// Racine publique : uploads/pivot-cache/.
		self::write_guard(
			self::directory(),
			"Options -Indexes\n"
			. "<FilesMatch \"\\.(php|phtml)$\">\n"
			. "Require all denied\n"
			. "</FilesMatch>\n"
		);

		// Racine privée : wp-content/pivot-cache-private/.
		self::write_guard(
			trailingslashit( self::base( 'offers' ) ),
			"Options -Indexes\n"
			. "Require all denied\n"
		);

		foreach ( self::groups() as $group ) {
			$dir = self::directory( $group );

			// Un index.php par sous-dossier : le listing reste muet même si la
			// directive Options -Indexes n'est pas honorée.
			if ( ! file_exists( $dir . 'index.php' ) ) {
				file_put_contents( $dir . 'index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore
			}
		}
	}

	/**
	 * Pose index.php et .htaccess dans une racine de stockage.
	 *
	 * @param string $base  Dossier, avec barre oblique finale.
	 * @param string $rules Contenu du .htaccess.
	 */
	private static function write_guard( $base, $rules ) {
		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
		}

		if ( ! file_exists( $base . 'index.php' ) ) {
			file_put_contents( $base . 'index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore
		}

		if ( ! file_exists( $base . '.htaccess' ) ) {
			file_put_contents( $base . '.htaccess', $rules ); // phpcs:ignore
		}
	}

	/**
	 * Nom de fichier d'une clé.
	 *
	 * Salé : sans cela le nom est le md5 d'une clé bâtie sur des identifiants
	 * publics, donc calculable par n'importe qui.
	 *
	 * @param string $key Clé.
	 * @return string
	 */
	private static function filename_for( $key ) {
		return md5( self::secret() . '|' . $key );
	}

	/**
	 * Chemin du fichier d'une entrée.
	 *
	 * @param string $group Groupe.
	 * @param string $key   Clé.
	 * @return string
	 */
	private static function path( $group, $key ) {
		return self::directory( $group ) . self::filename_for( $key ) . '.json';
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

		// « Tout vider » épargne le registre des adresses : lui seul n'est pas
		// reconstructible depuis PIVOT. En mode slug figé, le purger rendrait à
		// chaque fiche une adresse dérivée de son nom actuel — exactement ce que
		// ce mode existe pour empêcher. Il reste purgeable explicitement, par
		// flush( 'registry' ).
		$groups = $group ? array( $group ) : array( 'index', 'offers', 'thesaurus', 'build', 'raw' );

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

		// `index` est écarté : il ne contient que des fichiers bruts, sans
		// enveloppe ni date d'expiration, et les décoder coûterait cher pour
		// rien. `registry` l'est aussi : il n'expire jamais.
		foreach ( array( 'offers', 'raw', 'thesaurus', 'build' ) as $group ) {
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
		foreach ( self::groups() as $group ) {
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
	 * Prend un verrou exclusif.
	 *
	 * Sert à empêcher deux reconstructions simultanées de la même page. La prise
	 * repose sur fopen() en mode « x », qui échoue si le fichier existe déjà :
	 * la création est atomique, deux requêtes concurrentes ne peuvent pas
	 * l'obtenir toutes les deux. C'est le seul mécanisme portable ici, l'object
	 * cache de WordPress n'étant pas forcément persistant d'une requête à l'autre.
	 *
	 * @param string $name Nom du verrou.
	 * @param int    $ttl  Durée au-delà de laquelle le verrou est réputé abandonné.
	 * @return bool Vrai si le verrou a été pris.
	 */
	public static function acquire_lock( $name, $ttl = 60 ) {
		$file = self::lock_path( $name );

		// Un verrou plus vieux que sa durée de vie appartient à un processus
		// interrompu — épuisement du temps d'exécution, redémarrage de PHP. Sans
		// cette reprise, une seule interruption bloquerait la page pour toujours.
		if ( file_exists( $file ) && ( time() - (int) @filemtime( $file ) ) > $ttl ) { // phpcs:ignore
			@unlink( $file ); // phpcs:ignore
		}

		$handle = @fopen( $file, 'xb' ); // phpcs:ignore

		if ( false === $handle ) {
			return false;
		}

		fwrite( $handle, (string) time() ); // phpcs:ignore
		fclose( $handle ); // phpcs:ignore

		return true;
	}

	/**
	 * Rend un verrou.
	 *
	 * @param string $name Nom du verrou.
	 */
	public static function release_lock( $name ) {
		$file = self::lock_path( $name );

		if ( file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore
		}
	}

	/**
	 * Un verrou est-il tenu, et encore valide ?
	 *
	 * @param string $name Nom du verrou.
	 * @param int    $ttl  Durée au-delà de laquelle le verrou est réputé abandonné.
	 * @return bool
	 */
	public static function is_locked( $name, $ttl = 60 ) {
		$file = self::lock_path( $name );

		if ( ! file_exists( $file ) ) {
			return false;
		}

		return ( time() - (int) @filemtime( $file ) ) <= $ttl; // phpcs:ignore
	}

	/**
	 * Chemin du fichier d'un verrou.
	 *
	 * @param string $name Nom du verrou.
	 * @return string
	 */
	private static function lock_path( $name ) {
		return self::directory( 'build' ) . 'lock-' . self::filename_for( $name ) . '.lock';
	}

	/**
	 * Efface le cache écrit par une version antérieure.
	 *
	 * Avant la 2.5.1, tout vivait sous uploads/pivot-cache/ sous un nom en md5
	 * non salé, donc calculable par quiconque. Ces fichiers sont supprimés et
	 * non déplacés : le cache se reconstruit entièrement depuis PIVOT, et les
	 * conserver reviendrait à garder en ligne précisément ce que l'on cherche à
	 * en retirer. Le premier passage sur une page de listing reconstruit son
	 * index ; offres, thésaurus et registre des adresses se remplissent à la
	 * demande.
	 *
	 * Idempotente : sans ancien fichier, elle ne fait rien.
	 *
	 * @return array Compte rendu : nombre de fichiers effacés.
	 */
	public static function purge_legacy_store() {
		$uploads  = wp_get_upload_dir();
		$old_base = trailingslashit( trailingslashit( $uploads['basedir'] ) . self::DIRNAME );
		$report   = array( 'deleted' => 0 );

		// Les groupes désormais privés n'ont plus rien à faire sous uploads.
		foreach ( array( 'offers', 'thesaurus', 'build', 'raw' ) as $group ) {
			$dir = $old_base . $group;

			if ( ! is_dir( $dir ) ) {
				continue;
			}

			foreach ( (array) glob( trailingslashit( $dir ) . '*' ) as $file ) {
				if ( is_file( $file ) && @unlink( $file ) ) { // phpcs:ignore
					$report['deleted']++;
				}
			}

			@rmdir( $dir ); // phpcs:ignore
		}

		// Dans le groupe public, les entrées à enveloppe (registre des adresses)
		// portaient un nom en md5 non salé. Les index de listing, eux, ont un
		// nom lisible « listing-<page>-<langue>.json » : on n'y touche pas.
		foreach ( array( 'slugs', 'slug-changes' ) as $key ) {
			$old = $old_base . 'index/' . md5( $key ) . '.json';

			if ( is_file( $old ) && @unlink( $old ) ) { // phpcs:ignore
				$report['deleted']++;
			}
		}

		self::$memory = array();

		return $report;
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
