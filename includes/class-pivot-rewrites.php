<?php
/**
 * Règles de réécriture et résolution des URL.
 *
 * Deux familles d'URL :
 *  - les pages de listing, à l'adresse choisie par l'administrateur, avec un
 *    chemin propre à chaque langue ;
 *  - les fiches détail : /details/CODEPIVOT&type=IDTYPE.
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
		add_action( 'parse_request', array( $this, 'parse_detail_request' ), 5 );
		add_action( 'wp', array( $this, 'resolve_context' ) );
		add_filter( 'redirect_canonical', array( $this, 'keep_detail_url' ) );
		add_action( 'admin_init', array( $this, 'maybe_flush' ) );
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

		// Fiches détail, avec et sans préfixe de langue. La forme avec &type=
		// ne passe pas par ces règles : voir parse_detail_request().
		$routes[] = array(
			'regex' => '^details/([^/&]+)/?$',
			'query' => 'index.php?pivot_detail=$matches[1]',
		);

		foreach ( $langs as $lang ) {
			$routes[] = array(
				'regex' => '^' . $lang . '/details/([^/&]+)/?$',
				'query' => 'index.php?pivot_detail=$matches[1]&pivot_lang=' . $lang,
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
		$vars[] = 'pivot_page';
		$vars[] = 'pivot_lang';

		return $vars;
	}

	/**
	 * Intercepte /details/CODE&type=ID avant l'analyse standard.
	 *
	 * L'esperluette dans un chemin n'est pas une syntaxe d'URL valide : le
	 * segment arrive tel quel et doit être découpé à la main. Seul le code sert
	 * à retrouver l'offre ; le type qui le suit n'est pas lu.
	 *
	 * @param WP $wp Objet requête.
	 */
	public function parse_detail_request( $wp ) {
		$request = isset( $wp->request ) ? (string) $wp->request : '';

		if ( '' === $request ) {
			$uri     = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			$request = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		}

		// Coupe court avant tout le reste. Ce filtre tourne sur chaque requête
		// publique du site, et la détection du préfixe de langue ci-dessous
		// interroge l'extension de traduction puis compile une expression
		// régulière — un travail inutile sur l'immense majorité des pages, qui
		// n'ont rien à voir avec les fiches. Le test porte sur la
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
		$code = trim( (string) preg_split( '/[&?]/', $rest )[0], '/' );

		if ( ! pivot_is_code( $code ) ) {
			return;
		}

		$wp->query_vars['pivot_detail'] = strtoupper( $code );

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

		$detail = get_query_var( 'pivot_detail' );

		if ( $detail ) {
			$this->context = array(
				'kind' => 'detail',
				'code' => strtoupper( $detail ),
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
	 * Laisse l'adresse d'une fiche telle qu'elle a été demandée.
	 *
	 * WordPress ajouterait sinon une barre oblique finale, en 301 :
	 * /details/CODE&type=ID deviendrait /details/CODE&type=ID/.
	 *
	 * @param string|false $redirect_url Redirection calculée par WordPress.
	 * @return string|false
	 */
	public function keep_detail_url( $redirect_url ) {
		if ( $this->context && 'detail' === $this->context['kind'] ) {
			return false;
		}

		return $redirect_url;
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
	 * URL d'une fiche détail : /details/CODE&type=ID.
	 *
	 * @param string      $code Code PIVOT.
	 * @param int         $type Type d'offre ; omis de l'URL s'il est inconnu.
	 * @param string|null $lang Langue.
	 * @return string
	 */
	public static function detail_url( $code, $type = 0, $lang = null ) {
		$lang = $lang ? $lang : Pivot_I18n::current();
		$code = strtoupper( trim( (string) $code ) );
		$url  = Pivot_I18n::url( 'details/' . rawurlencode( $code ), $lang );

		// Le type se colle au code, sans barre oblique finale. Une chaîne de
		// requête ajoutée par l'extension de traduction (?lang=nl) reste après.
		$parts = explode( '?', $url, 2 );
		$url   = untrailingslashit( $parts[0] ) . ( $type ? '&type=' . (int) $type : '' );

		return isset( $parts[1] ) ? $url . '?' . $parts[1] : $url;
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
