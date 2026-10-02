<?php
/**
 * Points d'entrée REST.
 *
 * L'index d'une page est normalement servi comme fichier statique. Cette route
 * sert de repli quand le dossier uploads n'est pas accessible en direct, et de
 * canal de progression pour la reconstruction côté administration.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Rest {

	const NAMESPACE_V1 = 'pivot/v1';

	/** @var Pivot_Rest|null */
	private static $instance = null;

	/**
	 * @return Pivot_Rest
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Déclare les routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/index/(?P<listing>[a-z0-9_\-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_index' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'listing' => array( 'sanitize_callback' => 'sanitize_key' ),
					'lang'    => array( 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);

		// Fichier GPX d'une offre, pour le tracé sur la carte d'un listing dont
		// l'index ne le connaît pas (niveau inférieur à « complet avec offres
		// liées »). Public, comme la fiche détail qui lit la même offre.
		register_rest_route(
			self::NAMESPACE_V1,
			'/gpx/(?P<code>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_gpx' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/fields',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_fields' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'type'    => array( 'sanitize_callback' => 'absint' ),
					'listing' => array( 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/suggestions/(?P<listing>[a-z0-9_\-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_suggestions' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'listing' => array( 'sanitize_callback' => 'sanitize_key' ),
					'force'   => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/onboarding',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_onboarding' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/build/(?P<listing>[a-z0-9_\-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'build_index' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'listing' => array( 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);
	}

	/**
	 * Liste les types d'offres et, si un type est demandé, ses champs.
	 *
	 * Alimente le sélecteur de champs de l'écran d'édition : l'administrateur
	 * choisit dans une liste au lieu de saisir une urn de mémoire.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function get_fields( $request ) {
		$lang    = Pivot_I18n::default_lang();
		$listing = Pivot_Listings::get( $request->get_param( 'listing' ) );

		// Les types réellement présents dans la page sont mis en avant.
		$present = $listing ? array_map( 'intval', (array) pivot_get( $listing, 'offer_types', array() ) ) : array();

		$types = array();

		foreach ( Pivot_Thesaurus::offer_types( $lang ) as $id => $label ) {
			$types[] = array(
				'id'      => (int) $id,
				'label'   => $label,
				'present' => in_array( (int) $id, $present, true ),
			);
		}

		$type_id = (int) $request->get_param( 'type' );

		// Sans type explicite, on ouvre sur le premier type de la page.
		if ( ! $type_id && $present ) {
			$type_id = (int) $present[0];
		}

		return rest_ensure_response(
			array(
				'types'    => $types,
				'selected' => $type_id,
				'fields'   => $type_id ? Pivot_Thesaurus::fields_for_type( $type_id, $lang ) : array(),
			)
		);
	}

	/**
	 * Propose des critères déduits d'un échantillon d'offres de la page.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_suggestions( $request ) {
		$listing = Pivot_Listings::get( $request->get_param( 'listing' ) );

		if ( ! $listing ) {
			return new WP_Error(
				'pivot_unknown_listing',
				__( 'Page de listing introuvable.', 'pivot-offres' ),
				array( 'status' => 404 )
			);
		}

		$analysis = Pivot_Suggestions::analyse( $listing, (bool) $request->get_param( 'force' ) );

		if ( is_wp_error( $analysis ) ) {
			return $analysis;
		}

		// Les critères déjà en place ne sont plus à proposer.
		$used = array();

		foreach ( (array) pivot_get( $listing, 'filters', array() ) as $filter ) {
			$used[] = 'spec' === pivot_get( $filter, 'source' )
				? 'spec:' . pivot_get( $filter, 'urn', '' )
				: pivot_get( $filter, 'source', '' );
		}

		$analysis['used'] = $used;

		return rest_ensure_response( $analysis );
	}

	/**
	 * Mémorise l'avancement de la visite guidée pour l'utilisateur courant.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function save_onboarding( $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$tour   = sanitize_key( (string) $request->get_param( 'tour' ) );

		if ( 'reset' === $action ) {
			Pivot_Onboarding::reset();
		} elseif ( $tour ) {
			Pivot_Onboarding::mark_seen( $tour );
		}

		return rest_ensure_response( array( 'seen' => Pivot_Onboarding::seen() ) );
	}

	/**
	 * Droits d'administration.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( pivot_capability() );
	}

	/**
	 * Adresse du fichier GPX d'une offre à tracé.
	 *
	 * L'offre est lue au niveau de la fiche détail, dans le même cache : le
	 * premier clic coûte un appel à PIVOT, les suivants et la fiche n'en
	 * coûtent plus. Seuls les types à tracé sont servis (Pivot_Templates::
	 * track_types()).
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function get_gpx( $request ) {
		$code  = strtoupper( (string) $request->get_param( 'code' ) );
		$offer = pivot_is_code( $code ) ? Pivot_Repository::get_offer( $code, array( 'content' => 3 ) ) : null;
		$gpx   = null;

		if ( $offer && ! is_wp_error( $offer ) && in_array( (int) pivot_get( $offer, 'type', 0 ), Pivot_Templates::track_types(), true ) ) {
			$gpx = Pivot_Templates::offer_gpx( $offer );
		}

		$response = rest_ensure_response( array( 'url' => $gpx ? $gpx['url'] : '' ) );
		$response->set_status( $gpx ? 200 : 404 );
		$response->header( 'Cache-Control', 'public, max-age=' . HOUR_IN_SECONDS );

		return $response;
	}

	/**
	 * Renvoie l'index d'une page dans une langue.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_index( $request ) {
		$listing = Pivot_Listings::get( $request->get_param( 'listing' ) );

		if ( ! $listing || empty( $listing['active'] ) ) {
			return new WP_Error(
				'pivot_unknown_listing',
				__( 'Page de listing introuvable.', 'pivot-offres' ),
				array( 'status' => 404 )
			);
		}

		$lang = Pivot_I18n::normalize( $request->get_param( 'lang' ) );

		if ( ! $lang || ! in_array( $lang, Pivot_I18n::enabled(), true ) ) {
			$lang = Pivot_I18n::default_lang();
		}

		$index = Pivot_Index_Builder::ensure( $listing, $lang );

		if ( ! $index ) {
			// La construction a été programmée, elle n'a pas lieu ici : cette
			// route est ouverte à tous, et y enchaîner des appels à PIVOT
			// permettait à n'importe qui de mobiliser un processus PHP pendant
			// vingt-cinq secondes et de marteler le service avec la clé du site.
			$response = new WP_REST_Response(
				array(
					'code'    => 'pivot_index_unavailable',
					'message' => __( 'L\'index de cette page n\'est pas encore disponible. Réessayez dans un instant.', 'pivot-offres' ),
					'data'    => array( 'status' => 503 ),
				),
				503
			);

			$response->header( 'Retry-After', '30' );
			$response->header( 'Cache-Control', 'no-store' );

			return $response;
		}

		$response = rest_ensure_response( $index );
		$response->header( 'Cache-Control', 'public, max-age=300' );

		return $response;
	}

	/**
	 * Reconstruit les index par tranches.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function build_index( $request ) {
		$listing_id = $request->get_param( 'listing' );

		if ( $request->get_param( 'restart' ) ) {
			Pivot_Index_Builder::delete_index( $listing_id );
		}

		$state = Pivot_Index_Builder::run( $listing_id, 15 );

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		return rest_ensure_response(
			array(
				'done'      => ! empty( $state['done'] ),
				'processed' => (int) pivot_get( $state, 'processed', 0 ),
				'total'     => (int) pivot_get( $state, 'total', 0 ),
				'page'      => (int) pivot_get( $state, 'page', 0 ),
				'pages'     => (int) pivot_get( $state, 'pages', 0 ),
				'languages' => Pivot_I18n::enabled(),
			)
		);
	}
}
