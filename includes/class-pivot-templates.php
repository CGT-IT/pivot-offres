<?php
/**
 * Rendu des pages du plugin et aides d'affichage.
 *
 * Les libellés viennent des traductions renvoyées par PIVOT ; la langue est
 * choisie ici, à l'affichage, jamais au moment de la mise en cache.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Templates {

	/** @var Pivot_Templates|null */
	private static $instance = null;

	/** @var array|null Offre chargée pour la requête courante. */
	private $offer = null;

	/** @var bool L'offre a-t-elle déjà été demandée ? */
	private $offer_loaded = false;

	/**
	 * @return Pivot_Templates
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp', array( $this, 'prepare' ), 20 );
		add_action( 'template_redirect', array( $this, 'canonical_redirect' ), 5 );
		add_filter( 'template_include', array( $this, 'template' ), 99 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Contexte courant.
	 *
	 * @return array|null
	 */
	public function context() {
		return Pivot_Rewrites::instance()->context();
	}

	/**
	 * Langue de la page en cours.
	 *
	 * @return string
	 */
	public function lang() {
		return Pivot_I18n::current();
	}

	/**
	 * Prépare la requête : statut HTTP et langue d'affichage.
	 */
	public function prepare() {
		$context = $this->context();

		if ( ! $context ) {
			return;
		}

		global $wp_query;

		if ( 'detail' === $context['kind'] && ! $this->current_offer() ) {
			// Offre inconnue : vraie 404, pas une page vide indexable.
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		$wp_query->is_404      = false;
		$wp_query->is_home     = false;
		$wp_query->is_singular = false;
		$wp_query->is_archive  = false;

		status_header( 200 );
	}

	/**
	 * Ramène toute variante d'URL vers l'adresse canonique de la fiche.
	 *
	 * Une offre n'a qu'une adresse par langue : celle qui porte son nom suivi
	 * de son code. Les formes abrégées, ou celles construites avec un ancien
	 * nom, sont redirigées en 301 pour que le référencement se concentre sur
	 * une seule URL.
	 */
	public function canonical_redirect() {
		$context = $this->context();

		if ( ! $context || 'detail' !== $context['kind'] ) {
			return;
		}

		// En mode compatibilité, l'ancienne adresse doit répondre elle-même.
		if ( ! empty( $context['legacy'] ) ) {
			return;
		}

		$offer = $this->current_offer();

		if ( ! $offer ) {
			return;
		}

		$lang      = Pivot_I18n::current();
		$canonical = Pivot_Rewrites::detail_url(
			pivot_get( $offer, 'code' ),
			(int) pivot_get( $offer, 'type', 0 ),
			self::offer_name( $offer, $lang ),
			$lang
		);

		$request = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		// La comparaison porte sur le dernier segment seulement. Comparer le
		// chemin entier provoquerait une boucle : les extensions de traduction
		// retirent leur segment de langue de la requête avant que WordPress ne
		// l'analyse, si bien que l'adresse demandée ne ressemble jamais à
		// l'adresse canonique. Le préfixe de langue et la base sont leur
		// affaire ; le nôtre est que le slug porte le nom de l'offre.
		$target_slug  = self::last_segment( (string) wp_parse_url( $canonical, PHP_URL_PATH ) );
		$current_slug = self::last_segment( (string) wp_parse_url( $request, PHP_URL_PATH ) );

		if ( '' === $target_slug || $target_slug === $current_slug ) {
			return;
		}

		$query = (string) wp_parse_url( $request, PHP_URL_QUERY );

		if ( $query ) {
			$canonical .= ( false === strpos( $canonical, '?' ) ? '?' : '&' ) . $query;
		}

		Pivot_Logger::debug(
			sprintf( 'Redirection canonique : %1$s vers %2$s', $current_slug, $canonical ),
			array( 'service' => 'redirect', 'endpoint' => $request )
		);

		wp_redirect( $canonical, 301 ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/**
	 * Dernier segment d'un chemin, décodé et en minuscules.
	 *
	 * @param string $path Chemin.
	 * @return string
	 */
	private static function last_segment( $path ) {
		$path = trim( rawurldecode( (string) $path ), '/' );

		if ( '' === $path ) {
			return '';
		}

		$parts = explode( '/', $path );

		return strtolower( (string) end( $parts ) );
	}

	/**
	 * Choisit le gabarit.
	 *
	 * Un thème peut surcharger en plaçant pivot-listing.php ou pivot-detail.php
	 * à sa racine, ou dans un sous-dossier pivot-offres/.
	 *
	 * @param string $template Gabarit calculé par WordPress.
	 * @return string
	 */
	public function template( $template ) {
		$context = $this->context();

		if ( ! $context ) {
			return $template;
		}

		if ( 'detail' === $context['kind'] && ! $this->current_offer() ) {
			return $template;
		}

		if ( 'listing' === $context['kind'] ) {
			$theme = locate_template( array( 'pivot-offres/listing.php', 'pivot-listing.php' ) );

			return $theme ? $theme : PIVOT_DIR . 'templates/listing.php';
		}

		$offer = $this->current_offer();

		return self::locate(
			'detail',
			(int) pivot_get( $offer, 'type', 0 ),
			pivot_get( $offer, 'code', '' )
		);
	}

	/**
	 * Trouve le gabarit le plus spécifique disponible.
	 *
	 * L'ordre va du plus précis au plus général : une offre nommément désignée,
	 * puis son type PIVOT, puis sa famille — hébergement, événement,
	 * itinéraire —, puis le gabarit commun. Un thème n'écrit que ce qu'il veut
	 * distinguer et hérite du reste.
	 *
	 *   pivot-offres/detail-chb-01-000rv1.php
	 *   pivot-offres/detail-type-11.php
	 *   pivot-offres/detail-hebergement.php
	 *   pivot-offres/detail.php
	 *
	 * @param string $base    Nom de base : detail, ou parts/card.
	 * @param int    $type_id Type d'offre.
	 * @param string $code    Code PIVOT.
	 * @return string Chemin du gabarit.
	 */
	public static function locate( $base, $type_id = 0, $code = '' ) {
		$family = $type_id ? Pivot_Types::family( $type_id ) : '';
		$names  = array();

		if ( $code ) {
			$names[] = $base . '-' . strtolower( $code );
		}

		if ( $type_id ) {
			$names[] = $base . '-type-' . (int) $type_id;
		}

		if ( $family ) {
			$names[] = $base . '-' . $family;
		}

		$names[] = $base;

		/**
		 * Permet d'ajouter ou de réordonner les gabarits candidats.
		 *
		 * @param array  $names   Noms testés, du plus précis au plus général.
		 * @param string $base    Nom de base.
		 * @param int    $type_id Type d'offre.
		 * @param string $code    Code PIVOT.
		 */
		$names = apply_filters( 'pivot_template_hierarchy', $names, $base, $type_id, $code );

		$candidates = array();

		foreach ( $names as $name ) {
			$candidates[] = 'pivot-offres/' . $name . '.php';
		}

		// Forme historique, à la racine du thème.
		$candidates[] = 'pivot-' . $base . '.php';

		$theme = locate_template( $candidates );

		if ( $theme ) {
			return $theme;
		}

		foreach ( $names as $name ) {
			$file = PIVOT_DIR . 'templates/' . $name . '.php';

			if ( file_exists( $file ) ) {
				return $file;
			}
		}

		return PIVOT_DIR . 'templates/' . $base . '.php';
	}

	/**
	 * Gabarit commun des vignettes, celui que le plugin fournit.
	 *
	 * @return string
	 */
	public static function default_card_file() {
		return PIVOT_DIR . 'templates/parts/card.php';
	}

	/**
	 * Gabarit de vignette retenu pour un type d'offre.
	 *
	 * @param int    $type_id Type d'offre.
	 * @param string $code    Code PIVOT.
	 * @return string Chemin du gabarit.
	 */
	public static function card_file( $type_id = 0, $code = '' ) {
		return self::locate( 'parts/card', $type_id, $code );
	}

	/**
	 * Gabarit de vignette propre au thème, s'il y en a un.
	 *
	 * C'est ce test qui décide si l'index doit embarquer la vignette rendue :
	 * un site qui n'a rien surchargé garde l'index tel qu'il était.
	 *
	 * @param int    $type_id Type d'offre.
	 * @param string $code    Code PIVOT.
	 * @return string Chemin du gabarit, vide si c'est le gabarit commun.
	 */
	public static function custom_card_file( $type_id = 0, $code = '' ) {
		$file = self::card_file( $type_id, $code );

		return self::default_card_file() === $file ? '' : $file;
	}

	/**
	 * Rend une vignette et renvoie son HTML.
	 *
	 * La langue d'affichage n'est pas gérée ici : l'appelant bascule la locale
	 * avant d'enchaîner les vignettes d'une même langue, sinon un gabarit qui
	 * traduit ses propres libellés les sortirait tous dans la langue du site.
	 *
	 * @param array  $item Entrée d'index.
	 * @param string $file Gabarit à utiliser ; résolu si vide.
	 * @return string
	 */
	public static function render_card( $item, $file = '' ) {
		if ( ! $file ) {
			$file = self::card_file(
				(int) pivot_get( $item, 't', 0 ),
				pivot_get( $item, 'c', '' )
			);
		}

		if ( ! $file || ! file_exists( $file ) ) {
			return '';
		}

		ob_start();

		include $file;

		return trim( (string) ob_get_clean() );
	}

	/**
	 * Charge feuilles de style et scripts.
	 */
	public function enqueue() {
		$context = $this->context();

		if ( ! $context ) {
			return;
		}

		wp_enqueue_style( 'pivot-offres', PIVOT_URL . 'assets/css/pivot.css', array(), PIVOT_VERSION );

		if ( 'listing' !== $context['kind'] ) {
			return;
		}

		$listing  = $context['listing'];
		$lang     = $this->lang();
		$show_map = ! empty( $listing['show_map'] ) && 'leaflet' === pivot_settings( 'map_provider', 'leaflet' );

		if ( $show_map ) {
			wp_enqueue_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
			wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );

			if ( pivot_settings( 'map_cluster', 1 ) ) {
				wp_enqueue_style( 'leaflet-markercluster', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css', array( 'leaflet' ), '1.5.3' );
				wp_enqueue_style( 'leaflet-markercluster-default', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css', array( 'leaflet' ), '1.5.3' );
				wp_enqueue_script( 'leaflet-markercluster', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js', array( 'leaflet' ), '1.5.3', true );
			}
		}

		wp_enqueue_script(
			'pivot-listing',
			PIVOT_URL . 'assets/js/pivot-listing.js',
			$show_map ? array( 'leaflet' ) : array(),
			PIVOT_VERSION,
			true
		);

		$rest_url = rest_url( Pivot_Rest::NAMESPACE_V1 . '/index/' . $listing['id'] );
		$rest_url = add_query_arg( 'lang', $lang, $rest_url );

		wp_localize_script(
			'pivot-listing',
			'pivotListingData',
			array(
				'listing'    => $listing['id'],
				'lang'       => $lang,
				'indexUrl'   => 'rest' === pivot_settings( 'index_delivery', 'file' )
					? $rest_url
					: Pivot_Index_Builder::url( $listing['id'], $lang ),
				'restUrl'    => $rest_url,
				'perPage'    => (int) $listing['per_page'],
				'page'       => (int) pivot_get( $context, 'page', 1 ),
				'baseUrl'    => Pivot_Listings::url( $listing, $lang ),
				'showMap'    => $show_map ? 1 : 0,
				'mapZoom'    => (int) $listing['map_zoom'],
				'mapCenter'  => $listing['map_center'],
				'mapTiles'   => pivot_settings( 'map_tiles' ),
				'mapAttr'    => pivot_settings( 'map_attribution' ),
				'mapCluster' => pivot_settings( 'map_cluster', 1 ) ? 1 : 0,
				'search'     => (int) $listing['search_enabled'],
				'i18n'       => array(
					'results'      => __( 'offre(s)', 'pivot-offres' ),
					'noResult'     => __( 'Aucune offre ne correspond à votre recherche.', 'pivot-offres' ),
					'resetFilters' => __( 'Effacer les filtres', 'pivot-offres' ),
					'loading'      => __( 'Chargement des offres…', 'pivot-offres' ),
					'error'        => __( 'Les offres n\'ont pas pu être chargées. Rechargez la page dans quelques instants.', 'pivot-offres' ),
					'previous'     => __( 'Page précédente', 'pivot-offres' ),
					'next'         => __( 'Page suivante', 'pivot-offres' ),
					'page'         => __( 'Page', 'pivot-offres' ),
					'seeOffer'     => __( 'Voir la fiche', 'pivot-offres' ),
					'allOption'    => __( 'Toutes', 'pivot-offres' ),
				),
			)
		);
	}

	/**
	 * Offre demandée par l'URL courante.
	 *
	 * @return array|null
	 */
	public function current_offer() {
		if ( $this->offer_loaded ) {
			return $this->offer;
		}

		$this->offer_loaded = true;
		$context            = $this->context();

		if ( ! $context || 'detail' !== $context['kind'] ) {
			return null;
		}

		$code = pivot_get( $context, 'code', '' );

		if ( ! $code ) {
			return null;
		}

		$offer = Pivot_Repository::get_offer( $code, array( 'content' => 3 ) );

		if ( is_wp_error( $offer ) ) {
			Pivot_Logger::warn(
				sprintf( 'Offre %s indisponible : %s', $code, $offer->get_error_message() ),
				array( 'service' => 'offer', 'endpoint' => 'offer/' . $code )
			);
			return null;
		}

		$this->offer = $offer;

		return $this->offer;
	}

	/* ----------------------------------------------------- lecture d'offre */

	/**
	 * Nom de l'offre dans une langue.
	 *
	 * Le noeud nom de PIVOT porte la dénomination française. Les dénominations
	 * traduites vivent dans des champs dont l'urn est préfixée par la langue —
	 * `nl:urn:fld:nomofr`. La liste des urns essayées reste filtrable.
	 *
	 * @param array       $offer Offre normalisée.
	 * @param string|null $lang  Langue.
	 * @return string
	 */
	public static function offer_name( $offer, $lang = null ) {
		$lang = $lang ? $lang : Pivot_I18n::current();

		/**
		 * Urns candidates pour la dénomination traduite.
		 *
		 * @param array  $urns Liste d'urns, avec {lang} remplacé par la langue.
		 * @param string $lang Langue demandée.
		 */
		$candidates = apply_filters(
			'pivot_name_urns',
			array( '{lang}:urn:fld:nomofr', '{lang}:urn:fld:nom' ),
			$lang
		);

		foreach ( $candidates as $pattern ) {
			$urn = str_replace( '{lang}', $lang, $pattern );

			// La forme nue porte le français : l'essayer pour une autre langue
			// renverrait la dénomination française en se croyant traduit.
			$value = Pivot_Fields::urn_lang( $urn ) === $lang || 'fr' === $lang
				? pivot_get( $offer, array( 'by_urn', $urn, 0, 'value' ) )
				: '';

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return (string) pivot_get( $offer, 'name', '' );
	}

	/**
	 * Descriptif exploitable d'une offre, dans une langue.
	 *
	 * @param array       $offer Offre normalisée.
	 * @param int         $words Nombre de mots (0 = texte complet).
	 * @param string|null $lang  Langue.
	 * @return string
	 */
	public static function offer_description( $offer, $words = 0, $lang = null ) {
		$lang = $lang ? $lang : Pivot_I18n::current();

		/**
		 * Urns candidates pour le descriptif, dans l'ordre de préférence.
		 *
		 * La variante préfixée par la langue est essayée avant la forme nue,
		 * qui porte le français : `nl:urn:fld:descmarket` avant
		 * `urn:fld:descmarket`.
		 *
		 * Une seule urn par défaut : c'est le descriptif que les organismes
		 * remplissent. PIVOT en expose d'autres niveaux, mais les enchaîner
		 * revenait à préférer un champ vide dans la bonne langue à un champ
		 * rempli. Ajoutez-en si vos données en portent.
		 *
		 * @param array  $urns Liste d'urns.
		 * @param string $lang Langue demandée.
		 */
		$candidates = apply_filters(
			'pivot_description_urns',
			array( 'urn:fld:descmarket' ),
			$lang
		);

		// Deux passes : les urns portant la langue demandée d'abord, toutes
		// préférences de champ confondues, puis les formes nues.
		//
		// Sans cela, l'ordre des candidats l'emporterait sur la langue : dès
		// qu'un site déclare plusieurs urns par ce filtre, la première remplie
		// en français gagnerait contre la deuxième remplie en néerlandais.
		$tiers = array( array(), array() );

		foreach ( $candidates as $urn ) {
			foreach ( Pivot_Fields::urn_variants( $urn, $lang ) as $candidate ) {
				$tier = Pivot_Fields::urn_lang( $candidate ) ? 0 : 1;

				$tiers[ $tier ][] = $candidate;
			}
		}

		$text = '';

		foreach ( $tiers as $tier ) {
			foreach ( $tier as $candidate ) {
				$spec = pivot_get( $offer, array( 'by_urn', $candidate, 0 ) );

				if ( ! $spec ) {
					continue;
				}

				// Certains descriptifs portent leurs traductions en valueLabel.
				$translated = Pivot_I18n::pick( pivot_get( $spec, 'value_labels', array() ), $lang, '' );
				$value      = $translated ? $translated : pivot_get( $spec, 'value', '' );

				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$text = $value;
					break 2;
				}
			}
		}

		if ( ! $text ) {
			$text = self::scan_description( $offer, $lang );
		}

		if ( ! $text ) {
			return '';
		}

		$text = wp_strip_all_tags( $text, true );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		return $words > 0 ? wp_trim_words( $text, $words, '…' ) : $text;
	}

	/**
	 * Repli : premier champ textuel dont l'urn évoque un descriptif.
	 *
	 * @param array  $offer Offre.
	 * @param string $lang  Langue.
	 * @return string
	 */
	private static function scan_description( $offer, $lang ) {
		// visible_specs a déjà écarté les champs masqués et choisi, pour chaque
		// champ, la version de la bonne langue.
		foreach ( self::visible_specs( $offer, $lang ) as $spec ) {
			$urn = Pivot_Fields::base_urn( pivot_get( $spec, 'urn', '' ) );

			if ( false === strpos( $urn, 'desc' ) ) {
				continue;
			}

			$value = pivot_get( $spec, 'value' );

			if ( is_string( $value ) && strlen( trim( $value ) ) > 30 ) {
				return $value;
			}

			foreach ( (array) pivot_get( $spec, 'children', array() ) as $child ) {
				$child_value = pivot_get( $child, 'value' );

				if ( is_string( $child_value ) && strlen( trim( $child_value ) ) > 30 ) {
					return $child_value;
				}
			}
		}

		return '';
	}

	/**
	 * Première valeur trouvée parmi une liste d'urns.
	 *
	 * Les urns préfixées par la langue sont reconnues : donner
	 * `urn:fld:descmarket` trouve aussi `nl:urn:fld:descmarket`.
	 *
	 * @param array       $offer Offre.
	 * @param array       $urns  Urns candidates.
	 * @param string|null $lang  Langue voulue.
	 * @return string
	 */
	public function first_spec_value( $offer, $urns, $lang = null ) {
		foreach ( (array) $urns as $urn ) {
			$specs = Pivot_Fields::localized_specs( $offer, $urn, $lang );
			$value = pivot_get( $specs, array( 0, 'value' ) );

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return '';
	}

	/**
	 * Toutes les valeurs d'une urn, traduites.
	 *
	 * @param array       $offer Offre.
	 * @param string      $urn   Urn.
	 * @param string|null $lang  Langue.
	 * @return array
	 */
	public function spec_values( $offer, $urn, $lang = null ) {
		$out = array();

		foreach ( Pivot_Fields::localized_specs( $offer, $urn, $lang ) as $spec ) {
			$label = Pivot_I18n::pick( pivot_get( $spec, 'value_labels', array() ), $lang, '' );
			$value = $label ? $label : pivot_get( $spec, 'value' );

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$out[] = trim( $value );
			}
		}

		return $out;
	}

	/**
	 * Localité de l'offre dans la langue courante.
	 *
	 * @param array       $offer Offre.
	 * @param string|null $lang  Langue.
	 * @return string
	 */
	public function offer_locality( $offer, $lang = null ) {
		return Pivot_I18n::pick( pivot_get( $offer, 'address.locality_labels', array() ), $lang, '' );
	}

	/**
	 * Commune de l'offre dans la langue courante.
	 *
	 * @param array       $offer Offre.
	 * @param string|null $lang  Langue.
	 * @return string
	 */
	public function offer_city( $offer, $lang = null ) {
		return Pivot_I18n::pick( pivot_get( $offer, 'address.city_labels', array() ), $lang, '' );
	}

	/**
	 * Libellé du type d'offre dans la langue courante.
	 *
	 * @param array       $offer Offre.
	 * @param string|null $lang  Langue.
	 * @return string
	 */
	public function offer_type_label( $offer, $lang = null ) {
		$labels = pivot_get( $offer, 'type_labels', array() );
		$label  = Pivot_I18n::pick( $labels, $lang, '' );

		if ( $label ) {
			return $label;
		}

		return Pivot_Thesaurus::offer_type_label( (int) pivot_get( $offer, 'type', 0 ), $lang );
	}

	/**
	 * Image principale d'une offre.
	 *
	 * @param array  $offer Offre.
	 * @param string $thumb Taille prédéfinie.
	 * @return string
	 */
	public function offer_image( $offer, $thumb = null ) {
		$media_code = pivot_get( $offer, 'media.default.code' );

		if ( ! $media_code ) {
			$media_code = pivot_get( $offer, 'media.others.0.code' );
		}

		if ( ! $media_code ) {
			$media_code = pivot_get( $offer, 'code' );
		}

		return $media_code ? pivot_image_url( $media_code, $thumb ) : '';
	}

	/**
	 * Médias image utilisables.
	 *
	 * @param array $offer Offre.
	 * @return array
	 */
	public function offer_gallery( $offer ) {
		$gallery = array();
		$seen    = array();

		$default = pivot_get( $offer, 'media.default' );

		if ( $default ) {
			$gallery[] = $default;
			$seen[]    = pivot_get( $default, 'code' );
		}

		foreach ( (array) pivot_get( $offer, 'media.others', array() ) as $media ) {
			$code = pivot_get( $media, 'code' );

			if ( ! $code || in_array( $code, $seen, true ) ) {
				continue;
			}

			$gallery[] = $media;
			$seen[]    = $code;
		}

		return $gallery;
	}

	/**
	 * Intitulé d'un média dans la langue courante.
	 *
	 * @param array       $media    Média.
	 * @param string      $fallback Repli.
	 * @param string|null $lang     Langue.
	 * @return string
	 */
	public function media_title( $media, $fallback = '', $lang = null ) {
		$title = Pivot_I18n::pick( pivot_get( $media, 'titles', array() ), $lang, '' );

		if ( $title ) {
			return $title;
		}

		$title = pivot_get( $media, 'title', '' );

		return $title ? $title : $fallback;
	}

	/**
	 * Adresse formatée sur une ligne.
	 *
	 * @param array       $offer Offre.
	 * @param string|null $lang  Langue.
	 * @return string
	 */
	public function offer_address_line( $offer, $lang = null ) {
		$parts  = array();
		$street = pivot_get( $offer, 'address.street' );
		$number = pivot_get( $offer, 'address.number' );

		if ( $street ) {
			$parts[] = $number && '-' !== $number ? $street . ' ' . $number : $street;
		}

		$zip      = pivot_get( $offer, 'address.zip' );
		$locality = $this->offer_locality( $offer, $lang );

		if ( $zip || $locality ) {
			$parts[] = trim( (string) $zip . ' ' . $locality );
		}

		return implode( ', ', array_filter( $parts ) );
	}

	/**
	 * Champs d'une offre montrables au visiteur, une seule version par champ.
	 *
	 * Deux tris successifs :
	 *
	 *  - les champs masqués sont écartés (`Pivot_Fields::is_hidden`) ;
	 *  - les champs dont PIVOT préfixe l'urn par une langue existent en
	 *    plusieurs exemplaires — `urn:fld:descmarket` et
	 *    `nl:urn:fld:descmarket` sont le même champ. Sans regroupement, une
	 *    fiche néerlandaise afficherait le descriptif deux fois, en deux
	 *    langues. On ne garde que la meilleure version disponible.
	 *
	 * @param array       $offer Offre.
	 * @param string|null $lang  Langue.
	 * @return array Liste de specs.
	 */
	public static function visible_specs( $offer, $lang = null ) {
		$lang     = $lang ? $lang : Pivot_I18n::current();
		$variants = array();
		$order    = array();

		foreach ( (array) pivot_get( $offer, 'specs', array() ) as $spec ) {
			$urn = (string) pivot_get( $spec, 'urn', '' );

			if ( ! $urn || Pivot_Fields::is_hidden( $urn, $spec ) ) {
				continue;
			}

			$base = Pivot_Fields::base_urn( $urn );

			if ( ! isset( $variants[ $base ] ) ) {
				$variants[ $base ] = array();
				$order[]           = $base;
			}

			$variants[ $base ][ Pivot_Fields::urn_lang( $urn ) ][] = $spec;
		}

		$out = array();

		foreach ( $order as $base ) {
			$found = $variants[ $base ];

			// Un champ non préfixé n'a qu'une version : rien à départager.
			if ( array( '' ) === array_keys( $found ) ) {
				$out = array_merge( $out, $found[''] );
				continue;
			}

			foreach ( Pivot_Fields::urn_variants( $base, $lang ) as $candidate ) {
				$key = Pivot_Fields::urn_lang( $candidate );

				if ( ! empty( $found[ $key ] ) ) {
					$out = array_merge( $out, $found[ $key ] );
					continue 2;
				}
			}

			$out = array_merge( $out, reset( $found ) );
		}

		return $out;
	}

	/**
	 * Regroupe les champs d'une offre par catégorie, dans la langue courante.
	 *
	 * Les libellés proviennent de la réponse quand elle les porte, sinon du
	 * thesaurus mis en cache.
	 *
	 * @param array       $offer Offre.
	 * @param string|null $lang  Langue.
	 * @return array
	 */
	public function grouped_specs( $offer, $lang = null ) {
		$lang      = $lang ? $lang : $this->lang();
		$type_id   = (int) pivot_get( $offer, 'type', 0 );
		$structure = $type_id ? Pivot_Thesaurus::type_structure( $type_id ) : array();
		$groups    = array();

		foreach ( self::visible_specs( $offer, $lang ) as $spec ) {
			$urn = pivot_get( $spec, 'urn', '' );

			$value = Pivot_Fields::render( $spec, $lang );

			if ( '' === $value ) {
				continue;
			}

			$cat       = pivot_get( $spec, 'cat', 'urn:cat:autres' );
			$cat_label = Pivot_I18n::pick( pivot_get( $spec, 'cat_labels', array() ), $lang, '' );

			if ( ! $cat_label ) {
				$cat_label = Pivot_I18n::pick( pivot_get( $structure, array( $cat, 'labels' ), array() ), $lang, '' );
			}

			if ( ! $cat_label ) {
				$cat_label = __( 'Informations', 'pivot-offres' );
			}

			$subcat       = pivot_get( $spec, 'subcat', '' );
			$subcat_label = Pivot_I18n::pick( pivot_get( $spec, 'subcat_labels', array() ), $lang, '' );

			if ( ! $subcat_label && $subcat ) {
				$subcat_label = Pivot_I18n::pick( pivot_get( $structure, array( $subcat, 'labels' ), array() ), $lang, '' );
			}

			// Le thesaurus décrit le champ sous son urn nue : une variante
			// préfixée par la langue n'y a pas d'entrée propre.
			$base = Pivot_Fields::base_urn( $urn );

			$label = Pivot_I18n::pick( pivot_get( $spec, 'labels', array() ), $lang, '' );

			if ( ! $label ) {
				$label = Pivot_I18n::pick( pivot_get( $structure, array( $base, 'labels' ), array() ), $lang, '' );
			}

			if ( ! $label ) {
				$label = $base;
			}

			if ( ! isset( $groups[ $cat ] ) ) {
				$groups[ $cat ] = array(
					'label' => $cat_label,
					'order' => (int) pivot_get( $structure, array( $cat, 'order' ), 999 ),
					'rows'  => array(),
				);
			}

			$groups[ $cat ]['rows'][] = array(
				'urn'    => $base,
				'label'  => $label,
				'value'  => $value,
				'subcat' => $subcat_label,
				'order'  => (int) pivot_get( $spec, 'order', 999 ),
			);
		}

		uasort(
			$groups,
			static function ( $a, $b ) {
				return $a['order'] <=> $b['order'];
			}
		);

		foreach ( $groups as &$group ) {
			usort(
				$group['rows'],
				static function ( $a, $b ) {
					return $a['order'] <=> $b['order'];
				}
			);
		}
		unset( $group );

		/**
		 * Permet de réorganiser l'affichage des champs d'une offre.
		 *
		 * @param array  $groups Groupes calculés.
		 * @param array  $offer  Offre normalisée.
		 * @param string $lang   Langue.
		 */
		return apply_filters( 'pivot_grouped_specs', $groups, $offer, $lang );
	}

	/**
	 * Valeur affichable d'un champ, y compris structuré.
	 *
	 * Conservé pour les gabarits de thème qui l'appellent ; la logique vit
	 * désormais dans Pivot_Fields, partagée avec les vignettes.
	 *
	 * @param array  $spec Champ.
	 * @param string $lang Langue.
	 * @return string
	 */
	public function render_spec_value( $spec, $lang = null ) {
		return Pivot_Fields::render( $spec, $lang );
	}

	/**
	 * Page de listing d'origine, déduite du référent.
	 *
	 * @return array|null
	 */
	public function origin_listing() {
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';

		if ( $referer ) {
			$path = trim( (string) wp_parse_url( $referer, PHP_URL_PATH ), '/' );
			$path = preg_replace( '#/page/\d+$#', '', $path );
			$path = preg_replace( '#^(' . implode( '|', Pivot_I18n::languages() ) . ')/#', '', $path );

			foreach ( Pivot_Listings::active() as $listing ) {
				foreach ( Pivot_I18n::enabled() as $lang ) {
					if ( Pivot_Listings::slug( $listing, $lang ) === $path ) {
						return $listing;
					}
				}
			}
		}

		$active = Pivot_Listings::active();

		return 1 === count( $active ) ? reset( $active ) : null;
	}

	/**
	 * URL équivalentes de la page courante dans les autres langues.
	 *
	 * @return array lang => URL.
	 */
	public function alternate_urls() {
		$context = $this->context();
		$out     = array();

		if ( ! $context ) {
			return $out;
		}

		if ( 'listing' === $context['kind'] ) {
			foreach ( Pivot_I18n::languages() as $lang ) {
				$url = Pivot_Listings::url( $context['listing'], $lang );

				if ( $url ) {
					$out[ $lang ] = $url;
				}
			}

			return self::distinct( $out );
		}

		$offer = $this->current_offer();

		if ( ! $offer ) {
			return $out;
		}

		foreach ( Pivot_I18n::languages() as $lang ) {
			$out[ $lang ] = Pivot_Rewrites::detail_url(
				pivot_get( $offer, 'code' ),
				(int) pivot_get( $offer, 'type', 0 ),
				self::offer_name( $offer, $lang ),
				$lang
			);
		}

		return self::distinct( $out );
	}

	/**
	 * Ne garde qu'une langue par URL.
	 *
	 * Quand l'extension de traduction ne distingue pas une langue, son URL est
	 * identique à celle de la langue par défaut. Publier deux alternates vers la
	 * même adresse serait un signal faux pour les moteurs : on n'en garde qu'un.
	 *
	 * @param array $urls Tableau lang => URL.
	 * @return array
	 */
	private static function distinct( $urls ) {
		$seen = array();
		$out  = array();

		foreach ( $urls as $lang => $url ) {
			if ( in_array( $url, $seen, true ) ) {
				continue;
			}

			$seen[]        = $url;
			$out[ $lang ] = $url;
		}

		return $out;
	}

	/**
	 * Charge une partie de gabarit surchargeable par le thème.
	 *
	 * @param string $name Nom du fichier dans templates/parts.
	 * @param array  $vars Variables exposées au gabarit.
	 */
	public function part( $name, $vars = array() ) {
		// Une vignette suit la même hiérarchie que la fiche : un thème peut
		// afficher les dates d'un événement sans toucher aux autres.
		if ( 'card' === $name && isset( $vars['item'] ) ) {
			$file = self::card_file(
				(int) pivot_get( $vars['item'], 't', 0 ),
				pivot_get( $vars['item'], 'c', '' )
			);
		} else {
			$file = locate_template( array( 'pivot-offres/parts/' . $name . '.php' ) );

			if ( ! $file ) {
				$file = PIVOT_DIR . 'templates/parts/' . $name . '.php';
			}
		}

		if ( ! file_exists( $file ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DontExtract
		extract( $vars, EXTR_SKIP );

		include $file;
	}
}
