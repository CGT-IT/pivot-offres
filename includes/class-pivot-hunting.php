<?php
/**
 * Territoires de chasse sur les cartes : listings et fiches d'itinéraire.
 *
 * Source : « Chasse - Territoires et dates (version anonymisée) » du Service
 * public de Wallonie (DNF), licence CC-BY 4.0, synchronisée chaque jour :
 * https://geoportail.wallonie.be/catalogue/999ef018-235f-4a38-94c9-cc0c79da61b8.html
 *
 * Le navigateur du visiteur interroge lui-même le service ArcGIS du SPW
 * (il autorise les autres domaines) : rien ne passe par WordPress, rien n'est
 * mis en cache côté serveur. Tout le travail est dans
 * assets/js/pivot-hunting.js ; cette classe décide où l'activer et lui passe
 * sa configuration (pivotHuntingData).
 *
 * Aucun gabarit n'a à s'en occuper :
 * - listing : le script du plugin l'ajoute à #pivot-map, que le gabarit soit
 *   celui du plugin ou celui d'un thème ;
 * - fiche d'itinéraire : un crochet posé juste après Leaflet donne le panneau
 *   à toute carte Leaflet créée sur la page, celle d'un thème comprise
 *   (exclusion : data-pivot-hunting="off" sur le conteneur de la carte).
 *
 * Réglages : PIVOT → Réglages → Cartographie (hunting_listing,
 * hunting_detail), puis, pour un listing, son réglage « Territoires de
 * chasse » (auto, 1, 0).
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

/**
 * Territoires de chasse du SPW sur les cartes Leaflet du site.
 */
class Pivot_Hunting {

	/**
	 * Service ArcGIS du SPW (MapServer : couche 0 territoires, table 1 dates).
	 */
	const SERVICE = 'https://geoservices.wallonie.be/arcgis/rest/services/FAUNE_FLORE/CHASSE_TERRIT_ANONYM/MapServer';

	/**
	 * Fiche du jeu de données, citée dans l'attribution de la carte.
	 */
	const SOURCE = 'https://geoportail.wallonie.be/catalogue/999ef018-235f-4a38-94c9-cc0c79da61b8.html';

	/**
	 * Script déjà chargé sur la page.
	 *
	 * @var bool
	 */
	private static $enqueued = false;

	/**
	 * La carte d'un listing montre-t-elle les territoires de chasse ?
	 *
	 * Réglage général « sur les cartes des listings », puis réglage
	 * « hunting » de la page : 1 toujours, 0 jamais, auto (par défaut) quand la
	 * page contient des itinéraires — les types de
	 * Pivot_Templates::track_types(), relevés à la dernière construction de
	 * l'index.
	 *
	 * @param array $listing Configuration de la page.
	 * @return bool
	 */
	public static function listing_enabled( $listing ) {
		$mode = (string) pivot_get( $listing, 'hunting', 'auto' );

		if ( ! pivot_settings( 'hunting_listing', 1 ) || '0' === $mode ) {
			$enabled = false;
		} elseif ( '1' === $mode ) {
			$enabled = true;
		} else {
			$enabled = self::has_track_type( (array) pivot_get( $listing, 'offer_types', array() ) );
		}

		/**
		 * Affiche ou non les territoires de chasse sur une carte.
		 *
		 * @param bool   $enabled Choix des réglages.
		 * @param array  $subject Configuration du listing, ou offre de la fiche.
		 * @param string $kind    listing ou detail.
		 */
		return (bool) apply_filters( 'pivot_hunting_enabled', $enabled, $listing, 'listing' );
	}

	/**
	 * Les cartes d'une fiche montrent-elles les territoires de chasse ?
	 * Réglage général « sur les cartes des fiches d'itinéraire », pour les
	 * offres des types de Pivot_Templates::track_types().
	 *
	 * @param array $offer Offre normalisée.
	 * @return bool
	 */
	public static function detail_enabled( $offer ) {
		$enabled = pivot_settings( 'hunting_detail', 1 )
			&& self::has_track_type( array( (int) pivot_get( $offer, 'type', 0 ) ) );

		/** Ce filtre est documenté dans listing_enabled(). */
		return (bool) apply_filters( 'pivot_hunting_enabled', $enabled, $offer, 'detail' );
	}

	/**
	 * @param int[] $types Types d'offre.
	 * @return bool
	 */
	private static function has_track_type( $types ) {
		return (bool) array_intersect( array_map( 'intval', $types ), Pivot_Templates::track_types() );
	}

	/**
	 * Charge le script et sa configuration. Il n'a pas besoin de Leaflet pour
	 * se charger : il attend les cartes qu'on lui confie.
	 *
	 * @param string $lang    Langue de la page.
	 * @param array  $subject Configuration du listing, ou offre de la fiche.
	 */
	public static function enqueue( $lang, $subject = array() ) {
		if ( self::$enqueued ) {
			return;
		}

		self::$enqueued = true;

		wp_enqueue_script( 'pivot-hunting', PIVOT_URL . 'assets/js/pivot-hunting.js', array(), PIVOT_VERSION, true );
		wp_localize_script( 'pivot-hunting', 'pivotHuntingData', self::config( $lang, $subject ) );
	}

	/**
	 * Fiche d'itinéraire : toute carte Leaflet créée sur la page reçoit le
	 * panneau, sans que le thème ait à l'appeler.
	 *
	 * Un crochet L.Map.addInitHook, posé juste après Leaflet (handle
	 * « leaflet », enregistré ici avec les fichiers du plugin si le thème ne
	 * l'a pas déjà fait), range chaque carte dans window.pivotHuntingQueue,
	 * que pivot-hunting.js vide. Leaflet n'est pas chargé pour autant : le
	 * crochet ne s'imprime que si le thème met « leaflet » dans sa page.
	 *
	 * @param string $lang  Langue de la page.
	 * @param array  $offer Offre normalisée.
	 */
	public static function enqueue_detail( $lang, $offer ) {
		if ( 'leaflet' !== pivot_settings( 'map_provider', 'leaflet' ) || ! self::detail_enabled( $offer ) ) {
			return;
		}

		if ( ! wp_script_is( 'leaflet', 'registered' ) ) {
			wp_register_style( 'leaflet', PIVOT_URL . 'assets/vendor/leaflet/leaflet.css', array(), Pivot_Templates::LEAFLET_VERSION );
			wp_register_script( 'leaflet', PIVOT_URL . 'assets/vendor/leaflet/leaflet.js', array(), Pivot_Templates::LEAFLET_VERSION, true );
		}

		wp_add_inline_script(
			'leaflet',
			'if(window.L&&L.Map){L.Map.addInitHook(function(){(window.pivotHuntingQueue=window.pivotHuntingQueue||[]).push(this);});}'
		);

		self::enqueue( $lang, $offer );
	}

	/**
	 * Configuration du script (pivotHuntingData).
	 *
	 * @param string $lang    Langue de la page.
	 * @param array  $subject Configuration du listing, ou offre de la fiche.
	 * @return array service, source, info, lang, style, i18n.
	 */
	public static function config( $lang = '', $subject = array() ) {
		/**
		 * Style des territoires : options d'un L.Path de Leaflet, pour une
		 * chasse avec fermeture des chemins (closed) et sans (open). Les
		 * contours portent aussi les classes pivot-hunting-closed et
		 * pivot-hunting-open.
		 *
		 * @param array $style   closed, open.
		 * @param array $subject Configuration du listing, ou offre de la fiche.
		 */
		$style = apply_filters(
			'pivot_hunting_style',
			array(
				'closed' => array(
					'color'       => '#c62828',
					'weight'      => 2,
					'opacity'     => 0.9,
					'fillColor'   => '#e53935',
					'fillOpacity' => 0.28,
				),
				'open'   => array(
					'color'       => '#e65100',
					'weight'      => 2,
					'opacity'     => 0.9,
					'dashArray'   => '6 4',
					'fillColor'   => '#fb8c00',
					'fillOpacity' => 0.12,
				),
			),
			$subject
		);

		/**
		 * Page d'information sur les chasses et les fermetures, en lien sous la
		 * légende. Vide pour ne pas en mettre.
		 *
		 * @param string $url     Adresse.
		 * @param array  $subject Configuration du listing, ou offre de la fiche.
		 */
		$info = apply_filters( 'pivot_hunting_info_url', 'https://partageonslaforet.be/', $subject );

		return array(
			'service' => esc_url_raw( untrailingslashit( (string) apply_filters( 'pivot_hunting_service', self::SERVICE, $subject ) ) ),
			'source'  => self::SOURCE,
			'info'    => $info ? esc_url_raw( $info ) : '',
			'lang'    => (string) $lang,
			'style'   => (array) $style,
			'i18n'    => array(
				'title'     => __( 'Chasse', 'pivot-offres' ),
				'show'      => __( 'Territoires de chasse', 'pivot-offres' ),
				'date'      => __( 'Date des chasses', 'pivot-offres' ),
				'previous'  => __( 'Jour précédent', 'pivot-offres' ),
				'next'      => __( 'Jour suivant', 'pivot-offres' ),
				'loading'   => __( 'Chargement des chasses…', 'pivot-offres' ),
				/* translators: 1 : territoires où l'on chasse ; 2 : ceux dont les chemins sont fermés. */
				'count'     => __( '%1$d territoire(s) en chasse sur la carte, dont %2$d avec chemins fermés', 'pivot-offres' ),
				'none'      => __( 'Aucune chasse déclarée ce jour-là.', 'pivot-offres' ),
				'noneHere'  => __( 'Aucune chasse sur cette partie de la carte ce jour-là.', 'pivot-offres' ),
				'error'     => __( 'Les territoires de chasse n\'ont pas pu être chargés.', 'pivot-offres' ),
				'closed'    => __( 'Chasse avec fermeture : chemins fermés au public', 'pivot-offres' ),
				'open'      => __( 'Chasse sans fermeture : prudence', 'pivot-offres' ),
				'battue'    => __( 'Battue', 'pivot-offres' ),
				'affut'     => __( 'Chasse à l\'affût', 'pivot-offres' ),
				'autre'     => __( 'Autre chasse', 'pivot-offres' ),
				/* translators: %s : numéro DNF du territoire. */
				'territory' => __( 'Territoire n° %s', 'pivot-offres' ),
				'notice'    => __( 'Données du SPW (DNF), à titre indicatif. Respectez la signalisation sur place.', 'pivot-offres' ),
				'more'      => __( 'Fermetures et conseils', 'pivot-offres' ),
				'credit'    => __( 'Territoires de chasse © SPW', 'pivot-offres' ),
			),
		);
	}
}
