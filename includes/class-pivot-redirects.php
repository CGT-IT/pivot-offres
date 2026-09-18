<?php
/**
 * Table de redirections 301.
 *
 * Deux usages :
 *  - redirections générées par lot à partir des offres d'une requête, pour
 *    faire pointer d'anciennes URL (quelle que soit leur forme) vers les
 *    nouvelles pages détail ;
 *  - redirections saisies à la main.
 *
 * La table ne contient que des URL, jamais de contenu d'offre.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Redirects {

	const OPTION = 'pivot_redirects';

	/** Compteur autochargé, pour ne pas charger la table sur chaque page. */
	const COUNT_OPTION = 'pivot_redirects_count';

	/** @var Pivot_Redirects|null */
	private static $instance = null;

	/**
	 * @return Pivot_Redirects
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
	}

	/**
	 * Toutes les redirections : source normalisée => cible.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Enregistre la table complète.
	 *
	 * @param array $map Table source => cible.
	 */
	public static function save( $map ) {
		update_option( self::OPTION, $map, false );

		// Compteur autochargé, lu à chaque page publique à la place de la table
		// elle-même. Voir maybe_redirect().
		update_option( self::COUNT_OPTION, count( (array) $map ), true );
	}

	/**
	 * Nombre de redirections enregistrées, sans charger la table.
	 *
	 * @return int
	 */
	private static function count() {
		$count = get_option( self::COUNT_OPTION, false );

		if ( false === $count ) {
			// Première lecture depuis la mise à jour : on calcule une fois.
			$count = count( self::all() );
			update_option( self::COUNT_OPTION, $count, true );
		}

		return (int) $count;
	}

	/**
	 * Normalise un chemin pour la comparaison.
	 *
	 * @param string $path Chemin ou URL.
	 * @return string
	 */
	public static function normalize_path( $path ) {
		$path = (string) $path;

		if ( false !== strpos( $path, '://' ) ) {
			$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		}

		$path = strtok( $path, '?' );
		$path = rawurldecode( (string) $path );
		$path = '/' . trim( (string) $path, '/' );

		return strtolower( $path );
	}

	/**
	 * Ajoute une redirection.
	 *
	 * @param string $from Source.
	 * @param string $to   Cible.
	 * @return bool
	 */
	public static function add( $from, $to ) {
		$from = self::normalize_path( $from );
		$to   = esc_url_raw( $to );

		if ( '/' === $from || '' === $to ) {
			return false;
		}

		// Une redirection vers elle-même provoquerait une boucle.
		if ( self::normalize_path( $to ) === $from ) {
			return false;
		}

		$map          = self::all();
		$map[ $from ] = $to;

		self::save( $map );

		return true;
	}

	/**
	 * Supprime une redirection.
	 *
	 * @param string $from Source.
	 */
	public static function remove( $from ) {
		$map  = self::all();
		$from = self::normalize_path( $from );

		if ( isset( $map[ $from ] ) ) {
			unset( $map[ $from ] );
			self::save( $map );
		}
	}

	/**
	 * Applique la redirection si le chemin demandé est connu.
	 */
	public function maybe_redirect() {
		if ( is_admin() ) {
			return;
		}

		// La table n'est pas autochargée : la charger revient à une requête SQL
		// plus une désérialisation complète, sur chaque page publique, pour un
		// unique isset(). Or elle contient une entrée par offre et par langue —
		// des milliers sur un site fourni. Le compteur, lui, est autochargé et
		// tient dans la requête que WordPress fait de toute façon.
		if ( ! self::count() ) {
			return;
		}

		$map = self::all();
		if ( ! $map ) {
			return;
		}

		$request = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path    = self::normalize_path( $request );

		if ( ! isset( $map[ $path ] ) ) {
			return;
		}

		$target = self::freshen( $map[ $path ] );

		// wp_safe_redirect, et non wp_redirect : cette table associe d'anciennes
		// adresses du site à leurs nouvelles, elle n'a pas vocation à envoyer
		// ailleurs. Une cible hors du domaine est presque toujours une erreur de
		// saisie — et à défaut, elle transformerait le site en tremplin de
		// redirection. Le refus est journalisé plutôt que silencieux.
		$host = wp_parse_url( $target, PHP_URL_HOST );

		if ( $host && ! in_array( strtolower( $host ), self::allowed_hosts(), true ) ) {
			Pivot_Logger::error(
				sprintf( 'Redirection refusée : %1$s pointe hors du site, vers %2$s.', $path, $target ),
				array( 'service' => 'redirect', 'endpoint' => $path )
			);

			return;
		}

		Pivot_Logger::debug(
			sprintf( 'Redirection 301 : %s vers %s', $path, $target ),
			array( 'service' => 'redirect', 'endpoint' => $path )
		);

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Domaines vers lesquels la table peut rediriger.
	 *
	 * @return array
	 */
	private static function allowed_hosts() {
		$hosts = array( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );

		/**
		 * Permet d'autoriser des domaines supplémentaires pour la table de
		 * redirections, par exemple un site partenaire.
		 *
		 * @param array $hosts Domaines autorisés, en minuscules.
		 */
		return array_filter( (array) apply_filters( 'pivot_redirect_allowed_hosts', $hosts ) );
	}

	/**
	 * Remet une cible à jour si elle désigne une fiche du plugin.
	 *
	 * Une offre renommée dans PIVOT change d'adresse. Sans ce recalcul, la
	 * table enverrait vers l'ancienne, qui redirigerait à son tour : deux sauts
	 * là où un seul suffit. Le code présent dans l'adresse permet de retrouver
	 * l'offre et de reconstruire son adresse actuelle.
	 *
	 * @param string $target Cible enregistrée.
	 * @return string
	 */
	private static function freshen( $target ) {
		$path = self::normalize_path( $target );
		$code = Pivot_Rewrites::code_from_slug( basename( $path ) );

		if ( ! $code ) {
			return $target;
		}

		// La langue se déduit du préfixe de fiche présent dans l'adresse.
		$lang = Pivot_I18n::default_lang();

		foreach ( Pivot_I18n::languages() as $candidate ) {
			if ( false !== strpos( $path, '/' . Pivot_Rewrites::detail_base( $candidate ) . '/' ) ) {
				$lang = $candidate;
				break;
			}
		}

		// Lecture du cache seulement : ce recalcul n'économise qu'un saut de
		// redirection. Interroger PIVOT ici bloquait la redirection elle-même
		// jusqu'à trente secondes, pour un gain que le visiteur ne voit pas. À
		// défaut de cache, la cible enregistrée fait très bien l'affaire : elle
		// redirigera une seconde fois, et c'est tout.
		$offer = Pivot_Repository::get_offer( $code, array( 'content' => 3, 'cached_only' => true ) );

		if ( is_wp_error( $offer ) ) {
			return $target;
		}

		return Pivot_Rewrites::detail_url(
			$code,
			(int) pivot_get( $offer, 'type', 0 ),
			Pivot_Templates::offer_name( $offer, $lang ),
			$lang
		);
	}

	/**
	 * Génère par lot les redirections des offres d'une requête.
	 *
	 * Pour chaque offre retournée par la requête, une entrée est créée depuis
	 * l'ancien format /details/CODE (avec et sans &type=) vers la nouvelle URL.
	 *
	 * @param string $query_code Code de requête pré-programmée.
	 * @param array  $args       pattern (gabarit d'ancienne URL), dry_run.
	 * @return array|WP_Error Résumé : created, skipped, sample.
	 */
	public static function generate_from_query( $query_code, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'patterns' => array(),
				'pattern'  => '',
				'lang'     => '',
				'dry_run'  => false,
			)
		);

		$patterns = self::sanitize_patterns( $args );

		if ( ! $patterns ) {
			return new WP_Error(
				'pivot_no_pattern',
				__( 'Indiquez au moins un gabarit d\'ancienne adresse.', 'pivot-offres' )
			);
		}

		$response = Pivot_Client::get(
			'query/' . $query_code,
			array( 'fmt' => 'xml', 'content' => 1 ),
			array( 'service' => 'query', 'timeout' => max( 60, (int) pivot_settings( 'timeout', 30 ) ) )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = Pivot_Parser::parse_offers( $response['body'] );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$map     = self::all();
		$created = 0;
		$skipped = 0;
		$native  = 0;
		$sample  = array();
		$pending = array();

		foreach ( (array) pivot_get( $parsed, 'offers', array() ) as $offer ) {
			$code = pivot_get( $offer, 'code' );

			if ( ! $code ) {
				$skipped++;
				continue;
			}

			$type = (int) pivot_get( $offer, 'type', 0 );

			// Un gabarit par langue : l'ancienne adresse néerlandaise pointe
			// vers la fiche néerlandaise, la française vers la française.
			foreach ( $patterns as $lang => $pattern ) {
				$name = Pivot_Templates::offer_name( $offer, $lang );
				$to   = Pivot_Rewrites::detail_url( $code, $type, $name, $lang );

				$from = self::normalize_path(
					str_replace(
						array( '{code}', '{type}', '{slug}', '{lang}' ),
						array( $code, $type, pivot_slugify( $name ), $lang ),
						$pattern
					)
				);

				// La forme /details/CODE est déjà reconnue par le plugin : une
				// entrée de table ferait double emploi.
				if ( 0 === strpos( $from, '/details/' ) || preg_match( '#^/[a-z]{2}/details/#', $from ) ) {
					$native++;
					continue;
				}

				if ( '/' === $from || self::normalize_path( $to ) === $from ) {
					$skipped++;
					continue;
				}

				if ( count( $sample ) < 12 ) {
					$sample[] = array( 'from' => $from, 'to' => $to, 'name' => $name, 'lang' => $lang );
				}

				if ( isset( $map[ $from ] ) && $map[ $from ] === $to ) {
					$skipped++;
					continue;
				}

				$map[ $from ]     = $to;
				$pending[ $from ] = $to;
				$created++;
			}
		}

		if ( ! $args['dry_run'] ) {
			self::save( $map );
			Pivot_Logger::info(
				sprintf( '%d redirections générées depuis la requête %s.', $created, $query_code ),
				array( 'service' => 'redirect' )
			);
		}

		return array(
			'created' => $created,
			'skipped' => $skipped,
			'native'  => $native,
			'langs'   => array_keys( $patterns ),
			'query'   => $query_code,
			'total'   => count( $map ),
			'sample'  => $sample,
			'pending' => $args['dry_run'] ? $pending : array(),
			'dry_run' => (bool) $args['dry_run'],
			'time'    => time(),
		);
	}

	/**
	 * Gabarits retenus, par langue.
	 *
	 * Accepte la forme récente — un gabarit par langue — et l'ancienne, un
	 * gabarit unique accompagné d'une langue de destination.
	 *
	 * @param array $args Arguments reçus.
	 * @return array lang => gabarit.
	 */
	private static function sanitize_patterns( $args ) {
		$languages = Pivot_I18n::languages();
		$patterns  = array();

		foreach ( (array) $args['patterns'] as $lang => $pattern ) {
			$lang    = Pivot_I18n::code( $lang );
			$pattern = trim( (string) $pattern );

			if ( $lang && $pattern && in_array( $lang, $languages, true ) ) {
				$patterns[ $lang ] = $pattern;
			}
		}

		if ( $patterns ) {
			return $patterns;
		}

		$pattern = trim( (string) $args['pattern'] );

		if ( ! $pattern ) {
			return array();
		}

		$lang = Pivot_I18n::code( $args['lang'] );

		if ( ! $lang || ! in_array( $lang, $languages, true ) ) {
			$lang = Pivot_I18n::default_lang();
		}

		return array( $lang => $pattern );
	}

	/* ------------------------------------------------- simulation en attente */

	/**
	 * Clé de la simulation en attente, propre à chaque utilisateur.
	 *
	 * @return string
	 */
	private static function preview_key() {
		return 'redirect-preview|' . get_current_user_id();
	}

	/**
	 * Met une simulation de côté, en attendant validation ou refus.
	 *
	 * @param array $result Résultat de generate_from_query().
	 */
	public static function store_preview( $result ) {
		Pivot_Cache::set( 'build', self::preview_key(), $result, DAY_IN_SECONDS );
	}

	/**
	 * Simulation en attente, s'il y en a une.
	 *
	 * @return array
	 */
	public static function get_preview() {
		$preview = Pivot_Cache::get( 'build', self::preview_key() );

		return is_array( $preview ) && ! empty( $preview['pending'] ) ? $preview : array();
	}

	/**
	 * Oublie la simulation en attente.
	 */
	public static function clear_preview() {
		Pivot_Cache::delete( 'build', self::preview_key() );
	}

	/**
	 * Enregistre la simulation en attente.
	 *
	 * Rien n'est recalculé : les paires ont été établies au moment de la
	 * simulation, il n'y a aucune raison de réinterroger PIVOT pour confirmer.
	 *
	 * @return array{applied:int,total:int}
	 */
	public static function apply_preview() {
		$preview = self::get_preview();

		if ( ! $preview ) {
			return array( 'applied' => 0, 'total' => count( self::all() ) );
		}

		$map     = self::all();
		$applied = 0;

		foreach ( (array) $preview['pending'] as $from => $to ) {
			if ( isset( $map[ $from ] ) && $map[ $from ] === $to ) {
				continue;
			}

			$map[ $from ] = $to;
			$applied++;
		}

		self::save( $map );
		self::clear_preview();

		Pivot_Logger::info(
			sprintf( '%d redirections enregistrées après validation.', $applied ),
			array( 'service' => 'redirect' )
		);

		return array( 'applied' => $applied, 'total' => count( $map ) );
	}

	/**
	 * Exporte la table au format demandé.
	 *
	 * @param string $format csv|htaccess|nginx.
	 * @return string
	 */
	public static function export( $format = 'csv' ) {
		$map  = self::all();
		$home = untrailingslashit( home_url() );
		$out  = array();

		foreach ( $map as $from => $to ) {
			$target = str_replace( $home, '', $to );

			switch ( $format ) {
				case 'htaccess':
					$out[] = 'Redirect 301 ' . $from . ' ' . $to;
					break;
				case 'nginx':
					$out[] = 'rewrite ^' . preg_quote( $from, '/' ) . '/?$ ' . $target . ' permanent;';
					break;
				default:
					$out[] = '"' . str_replace( '"', '""', $from ) . '","' . str_replace( '"', '""', $to ) . '"';
			}
		}

		if ( 'csv' === $format ) {
			array_unshift( $out, '"source","cible"' );
		}

		return implode( "\n", $out );
	}
}
