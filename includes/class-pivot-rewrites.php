<?php
/**
 * Règles de réécriture et résolution des URL.
 *
 * Trois familles d'URL :
 *  - les pages de listing, à l'adresse choisie par l'administrateur, avec un
 *    chemin propre à chaque langue ;
 *  - les fiches détail optimisées : /offre/nom-de-loffre-CODE/ ;
 *  - l'ancienne forme /details/CODEPIVOT&type=IDTYPE, conservée pour ne pas
 *    casser le maillage interne ni les liens entrants.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Rewrites {

	/** @var Pivot_Rewrites|null */
	private static $instance = null;

	/** @var array|null Contexte de la requête courante. */
	private $context = null;

	/**
	 * @return Pivot_Rewrites
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_rules' ), 20 );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'parse_request', array( $this, 'parse_legacy_request' ), 5 );
		add_action( 'wp', array( $this, 'resolve_context' ) );
		add_action( 'admin_init', array( $this, 'maybe_flush' ) );
	}

	/**
	 * Préfixe des fiches détail dans une langue.
	 *
	 * @param string|null $lang Langue.
	 * @return string
	 */
	public static function detail_base( $lang = null ) {
		$lang  = $lang ? $lang : Pivot_I18n::current();
		$bases = pivot_settings( 'detail_bases', array() );

		$base = '';

		if ( is_array( $bases ) && isset( $bases[ $lang ] ) ) {
			$base = Pivot_Listings::sanitize_path( $bases[ $lang ] );
		}

		if ( ! $base ) {
			$base = Pivot_Listings::sanitize_path( pivot_settings( 'detail_base', 'offre' ) );
		}

		return $base ? $base : 'offre';
	}

	/**
	 * Chemins à déclarer, toutes langues confondues.
	 *
	 * Un chemin non préfixé est enregistré pour chaque langue, plus une
	 * variante préfixée par le code de langue. Cette redondance rend le plugin
	 * indifférent au mode d'URL choisi par WPML ou Polylang.
	 *
	 * @return array
	 */
	private function routes() {
		$routes = array();
		$langs  = Pivot_I18n::languages();

		// Fiches détail.
		$bases = array();

		foreach ( $langs as $lang ) {
			$bases[ self::detail_base( $lang ) ][] = $lang;
		}

		foreach ( $bases as $base => $base_langs ) {
			$routes[] = array(
				'regex' => '^' . preg_quote( $base, '#' ) . '/([^/]+)/?$',
				'query' => 'index.php?pivot_detail=$matches[1]'
					. ( 1 === count( $base_langs ) ? '&pivot_lang=' . $base_langs[0] : '' ),
			);

			foreach ( $base_langs as $lang ) {
				$routes[] = array(
					'regex' => '^' . $lang . '/' . preg_quote( $base, '#' ) . '/([^/]+)/?$',
					'query' => 'index.php?pivot_detail=$matches[1]&pivot_lang=' . $lang,
				);
			}
		}

		// Pages de listing.
		foreach ( Pivot_Listings::active() as $listing ) {
			$by_slug = array();

			foreach ( $langs as $lang ) {
				$slug = Pivot_Listings::slug( $listing, $lang );
				if ( $slug ) {
					$by_slug[ $slug ][] = $lang;
				}
			}

			foreach ( $by_slug as $slug => $slug_langs ) {
				$suffix = 1 === count( $slug_langs ) ? '&pivot_lang=' . $slug_langs[0] : '';

				$routes[] = array(
					'regex' => '^' . preg_quote( $slug, '#' ) . '/?$',
					'query' => 'index.php?pivot_listing=' . rawurlencode( $listing['id'] ) . $suffix,
				);

				$routes[] = array(
					'regex' => '^' . preg_quote( $slug, '#' ) . '/page/([0-9]{1,6})/?$',
					'query' => 'index.php?pivot_listing=' . rawurlencode( $listing['id'] ) . '&pivot_page=$matches[1]' . $suffix,
				);

				foreach ( $slug_langs as $lang ) {
					$routes[] = array(
						'regex' => '^' . $lang . '/' . preg_quote( $slug, '#' ) . '/?$',
						'query' => 'index.php?pivot_listing=' . rawurlencode( $listing['id'] ) . '&pivot_lang=' . $lang,
					);

					$routes[] = array(
						'regex' => '^' . $lang . '/' . preg_quote( $slug, '#' ) . '/page/([0-9]{1,6})/?$',
						'query' => 'index.php?pivot_listing=' . rawurlencode( $listing['id'] ) . '&pivot_page=$matches[1]&pivot_lang=' . $lang,
					);
				}
			}
		}

		// Ancienne forme, avec et sans préfixe de langue.
		$routes[] = array(
			'regex' => '^details/([^/&]+)/?$',
			'query' => 'index.php?pivot_legacy=$matches[1]',
		);

		foreach ( $langs as $lang ) {
			$routes[] = array(
				'regex' => '^' . $lang . '/details/([^/&]+)/?$',
				'query' => 'index.php?pivot_legacy=$matches[1]&pivot_lang=' . $lang,
			);
		}

		return $routes;
	}

	/**
	 * Déclare les règles auprès de WordPress.
	 */
	public function register_rules() {
		foreach ( $this->routes() as $route ) {
			add_rewrite_rule( $route['regex'], $route['query'], 'top' );
		}
	}

	/**
	 * Variables de requête reconnues.
	 *
	 * @param array $vars Variables existantes.
	 * @return array
	 */
	public function register_query_vars( $vars ) {
		$vars[] = 'pivot_listing';
		$vars[] = 'pivot_detail';
		$vars[] = 'pivot_legacy';
		$vars[] = 'pivot_page';
		$vars[] = 'pivot_lang';
		// Préfixée, comme les autres : déclarer « type » en variable publique la
		// rendait reconnue sur tout le site et exposait le plugin aux collisions
		// avec les thèmes et les autres extensions. La forme ancienne ?type=ID
		// reste honorée, elle est lue directement dans $_GET plus bas.
		$vars[] = 'pivot_type';

		return $vars;
	}

	/**
	 * Intercepte /details/CODE&type=ID avant l'analyse standard.
	 *
	 * L'esperluette dans un chemin n'est pas une syntaxe d'URL valide : le
	 * segment arrive tel quel et doit être découpé à la main.
	 *
	 * @param WP $wp Objet requête.
	 */
	public function parse_legacy_request( $wp ) {
		$request = isset( $wp->request ) ? (string) $wp->request : '';

		if ( '' === $request ) {
			$uri     = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			$request = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		}

		// Coupe court avant tout le reste. Ce filtre tourne sur chaque requête
		// publique du site, et la détection du préfixe de langue ci-dessous
		// interroge l'extension de traduction puis compile une expression
		// régulière — un travail inutile sur l'immense majorité des pages, qui
		// n'ont rien à voir avec les anciennes adresses. Le test porte sur la
		// présence du segment n'importe où, pour laisser passer /nl/details/…
		// dont le préfixe n'a pas encore été retiré.
		if ( false === stripos( $request, 'details/' ) ) {
			return;
		}

		$lang = '';

		// Retire un éventuel préfixe de langue.
		if ( preg_match( '#^(' . implode( '|', Pivot_I18n::languages() ) . ')/(.*)$#i', $request, $prefix_match ) ) {
			$lang    = strtolower( $prefix_match[1] );
			$request = $prefix_match[2];
		}

		if ( 0 !== stripos( $request, 'details/' ) ) {
			return;
		}

		$rest = rawurldecode( substr( $request, strlen( 'details/' ) ) );
		$code = $rest;
		$type = 0;

		if ( preg_match( '/^([^&?]+)[&?](.*)$/', $rest, $matches ) ) {
			$code = $matches[1];
			parse_str( str_replace( '&amp;', '&', $matches[2] ), $extra );
			if ( isset( $extra['type'] ) ) {
				$type = (int) $extra['type'];
			}
		}

		if ( ! $type && isset( $_GET['type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$type = (int) $_GET['type']; // phpcs:ignore WordPress.Security.NonceVerification
		}

		$code = trim( $code, '/' );

		if ( ! pivot_is_code( $code ) ) {
			return;
		}

		$wp->query_vars['pivot_legacy'] = $code;
		$wp->query_vars['pivot_type']   = $type;

		if ( $lang ) {
			$wp->query_vars['pivot_lang'] = $lang;
		}

		// Ce n'est pas une page WordPress : on neutralise l'analyse normale.
		unset( $wp->query_vars['error'], $wp->query_vars['pagename'], $wp->query_vars['name'] );
	}

	/**
	 * Détermine ce que la requête courante doit afficher.
	 */
	public function resolve_context() {
		if ( is_admin() ) {
			return;
		}

		$lang = Pivot_I18n::code( get_query_var( 'pivot_lang' ) );

		if ( $lang ) {
			Pivot_I18n::set_current( $lang );
		}

		$legacy = get_query_var( 'pivot_legacy' );

		if ( $legacy ) {
			$this->handle_legacy( $legacy, (int) get_query_var( 'pivot_type' ) );
			return;
		}

		$detail = get_query_var( 'pivot_detail' );

		if ( $detail ) {
			$this->context = array(
				'kind' => 'detail',
				'code' => self::code_from_slug( $detail ),
				'slug' => $detail,
				'lang' => Pivot_I18n::current(),
			);
			return;
		}

		$listing_id = get_query_var( 'pivot_listing' );

		if ( ! $listing_id ) {
			return;
		}

		$listing = Pivot_Listings::get( $listing_id );

		if ( $listing && ! empty( $listing['active'] ) ) {
			$this->context = array(
				'kind'    => 'listing',
				'listing' => $listing,
				'page'    => max( 1, (int) get_query_var( 'pivot_page' ) ),
				'lang'    => Pivot_I18n::current(),
			);
		}
	}

	/**
	 * Traite une ancienne URL : redirection 301 ou affichage direct.
	 *
	 * L'offre est chargée avant de rediriger : sans son nom, on enverrait vers
	 * /offre/CODE/ qui redirigerait à son tour vers l'adresse complète. Une
	 * chaîne de redirections dilue le référencement et coûte un aller-retour au
	 * visiteur. L'appel est mis en cache et sert ensuite à la page elle-même.
	 *
	 * @param string $code Code PIVOT.
	 * @param int    $type Identifiant de type d'offre.
	 */
	private function handle_legacy( $code, $type ) {
		$lang = Pivot_I18n::current();

		if ( '200' === pivot_settings( 'legacy_mode', '301' ) ) {
			$this->context = array(
				'kind'   => 'detail',
				'code'   => $code,
				'legacy' => true,
				'type'   => $type,
				'lang'   => $lang,
			);
			return;
		}

		$offer = Pivot_Repository::get_offer( $code, array( 'content' => 3 ) );

		if ( is_wp_error( $offer ) ) {
			// Offre inconnue : mieux vaut une 404 franche qu'une redirection
			// vers une adresse qui n'existe pas davantage.
			$this->context = array(
				'kind' => 'detail',
				'code' => $code,
				'lang' => $lang,
			);
			return;
		}

		$target = self::detail_url(
			$code,
			(int) pivot_get( $offer, 'type', $type ),
			Pivot_Templates::offer_name( $offer, $lang ),
			$lang
		);

		Pivot_Logger::debug(
			sprintf( 'Redirection 301 de /details/%s vers %s', $code, $target ),
			array( 'service' => 'redirect', 'endpoint' => '/details/' . $code )
		);

		wp_redirect( $target, 301 ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/**
	 * Contexte courant, null hors des pages du plugin.
	 *
	 * @return array|null
	 */
	public function context() {
		return $this->context;
	}

	/**
	 * URL canonique d'une fiche détail.
	 *
	 * Le code PIVOT termine toujours le segment : l'URL reste résoluble même
	 * si le nom de l'offre change dans PIVOT.
	 *
	 * @param string      $code Code PIVOT.
	 * @param int         $type Type d'offre.
	 * @param string      $name Nom de l'offre dans la langue visée.
	 * @param string|null $lang Langue.
	 * @return string
	 */
	public static function detail_url( $code, $type = 0, $name = '', $lang = null ) {
		$lang = $lang ? $lang : Pivot_I18n::current();
		$code = strtoupper( trim( (string) $code ) );
		$slug = Pivot_Slugs::slug_for( $code, $lang, $name );

		/**
		 * Permet de réécrire le segment d'URL des fiches détail.
		 *
		 * @param string $slug Segment.
		 * @param string $code Code PIVOT.
		 * @param int    $type Type d'offre.
		 * @param string $name Nom de l'offre.
		 * @param string $lang Langue.
		 */
		$slug = apply_filters( 'pivot_detail_slug', $slug, $code, $type, $name, $lang );

		return Pivot_I18n::url( self::detail_base( $lang ) . '/' . $slug, $lang );
	}

	/**
	 * Ancienne URL d'une offre.
	 *
	 * @param string $code Code PIVOT.
	 * @param int    $type Type d'offre.
	 * @return string
	 */
	public static function legacy_url( $code, $type = 0 ) {
		$url = home_url( '/details/' . rawurlencode( $code ) );

		if ( $type ) {
			$url .= '&type=' . (int) $type;
		}

		return $url;
	}

	/**
	 * Extrait le code PIVOT d'un segment d'URL.
	 *
	 * Le code termine le segment, mais le nom de l'offre le précède et contient
	 * lui aussi des tirets : on teste les suffixes du plus long au plus court,
	 * en privilégiant la forme stricte LETTRES-CHIFFRES-ALPHANUM.
	 *
	 * @param string $slug Segment.
	 * @return string
	 */
	public static function code_from_slug( $slug ) {
		$slug = trim( (string) $slug, '/' );

		if ( '' === $slug ) {
			return '';
		}

		/**
		 * Forme stricte d'un code d'offre, par exemple ALD-01-00096Z.
		 *
		 * @param string $pattern Expression régulière.
		 */
		$strict = apply_filters( 'pivot_code_pattern', '/^[A-Z]{2,6}-[0-9]{2}-[A-Z0-9]{3,12}(-[A-Z0-9]{1,12})?$/i' );

		$segments = explode( '-', $slug );
		$count    = count( $segments );
		$fallback = '';

		for ( $take = min( 5, $count ); $take >= 2; $take-- ) {
			$candidate = strtoupper( implode( '-', array_slice( $segments, $count - $take ) ) );

			if ( preg_match( $strict, $candidate ) ) {
				return $candidate;
			}

			// Repli : forme générique, mais exigeante — préfixe alphabétique,
			// au moins deux séparateurs et deux chiffres. Sans cela « pas-de-
			// code-ici » passerait pour un code. La boucle va du plus long au
			// plus court, donc le candidat le plus court l'emporte.
			$plausible = preg_match( '/^[A-Z]{2,6}(-[A-Z0-9]{1,12}){2,4}$/i', $candidate )
				&& preg_match_all( '/\d/', $candidate ) >= 2;

			if ( $plausible && pivot_is_code( $candidate ) ) {
				$fallback = $candidate;
			}
		}

		return $fallback;
	}

	/**
	 * Demande un rafraîchissement des permaliens.
	 */
	public function schedule_flush() {
		update_option( 'pivot_flush_rewrites', 1, false );
	}

	/**
	 * Applique le rafraîchissement demandé.
	 */
	public function maybe_flush() {
		if ( ! get_option( 'pivot_flush_rewrites' ) ) {
			return;
		}

		delete_option( 'pivot_flush_rewrites' );
		$this->register_rules();
		flush_rewrite_rules( false );
	}
}
