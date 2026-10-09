<?php
/**
 * Shortcodes de l'ancien plugin PIVOT.
 *
 * Les contenus d'un site repris de l'ancien plugin — pages, Elementor,
 * widgets, traductions WPML — gardent des [pivot_shortcode …]. Plutôt que de
 * réécrire ces contenus, les anciens noms restent déclarés et sont traduits à
 * l'affichage vers [pivot_offres] : rien n'est modifié en base.
 *
 * Les filtres que l'ancien plugin envoyait à PIVOT (filterurn, date1…) ne
 * sont pas repris : la sélection s'affiche sans eux, et un administrateur
 * voit le shortcode [pivot_offres] à poser à la place.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Legacy_Shortcodes {

	/**
	 * Anciens shortcodes, avec leurs nombres d'offres et de colonnes par
	 * défaut dans l'ancien plugin. Les carrousels deviennent des grilles.
	 */
	const DEFAULTS = array(
		'pivot_shortcode'              => array( 'nboffers' => 3, 'nbcol' => 4 ),
		'pivot_shortcode_slider'       => array( 'nboffers' => 6, 'nbcol' => 2 ),
		'pivot_shortcode_event'        => array( 'nboffers' => 3, 'nbcol' => 4 ),
		'pivot_shortcode_event_slider' => array( 'nboffers' => 6, 'nbcol' => 2 ),
	);

	/**
	 * Attributs des filtres envoyés à PIVOT, que pivot-offres ne sait pas
	 * transmettre.
	 */
	const FILTERS = array( 'filterurn', 'filtervalue', 'date1', 'value1', 'date2', 'value2' );

	/** @var Pivot_Legacy_Shortcodes|null */
	private static $instance = null;

	/**
	 * @return Pivot_Legacy_Shortcodes
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		foreach ( array_keys( self::DEFAULTS ) as $tag ) {
			if ( ! shortcode_exists( $tag ) ) {
				add_shortcode( $tag, array( $this, 'render' ) );
			}
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/**
	 * Charge la feuille de style quand le contenu affiché utilise un ancien
	 * shortcode, comme Pivot_Shortcodes::maybe_enqueue().
	 */
	public function maybe_enqueue() {
		$post = get_post();

		if ( ! $post ) {
			return;
		}

		foreach ( array_keys( self::DEFAULTS ) as $tag ) {
			if ( has_shortcode( (string) $post->post_content, $tag ) ) {
				wp_enqueue_style( 'pivot-offres', PIVOT_URL . 'assets/css/pivot.css', array(), PIVOT_VERSION );
				return;
			}
		}
	}

	/**
	 * Attributs de l'ancien shortcode, avec ses valeurs par défaut.
	 *
	 * @param string $tag Nom du shortcode.
	 * @return array
	 */
	public static function defaults( $tag ) {
		$counts = isset( self::DEFAULTS[ $tag ] ) ? self::DEFAULTS[ $tag ] : self::DEFAULTS['pivot_shortcode'];

		return array(
			'query'       => '',
			'type'        => '',
			'nboffers'    => $counts['nboffers'],
			'nbcol'       => $counts['nbcol'],
			'sortmode'    => '',
			'sortfield'   => '',
			'details'     => '',
			'filterurn'   => '',
			'operator'    => '',
			'filtervalue' => '',
			'date1'       => '',
			'operator1'   => '',
			'value1'      => '',
			'date2'       => '',
			'operator2'   => '',
			'value2'      => '',
		);
	}

	/**
	 * Attributs [pivot_offres] équivalents.
	 *
	 * « type » et « details » n'ont pas d'équivalent : la vignette suit le
	 * type de chaque offre. Un tri sur un autre champ que le nom garde
	 * l'ordre de PIVOT.
	 *
	 * @param array $atts Attributs de l'ancien shortcode, complétés.
	 * @return array
	 */
	public static function convert( $atts ) {
		$mode  = strtolower( trim( (string) $atts['sortmode'] ) );
		$field = strtolower( trim( (string) $atts['sortfield'] ) );
		$tri   = 'defaut';

		if ( 'shuffle' === $mode ) {
			$tri = 'aleatoire';
		} elseif ( 'asc' === $mode && 'urn:fld:nomofr' === $field ) {
			$tri = 'nom';
		}

		return array(
			'query'    => trim( (string) $atts['query'] ),
			'nombre'   => (int) $atts['nboffers'],
			'colonnes' => (int) $atts['nbcol'],
			'tri'      => $tri,
		);
	}

	/**
	 * Rend un ancien shortcode par [pivot_offres].
	 *
	 * @param array|string $atts    Attributs.
	 * @param string       $content Contenu (inutilisé).
	 * @param string       $tag     Nom du shortcode.
	 * @return string
	 */
	public function render( $atts, $content = '', $tag = 'pivot_shortcode' ) {
		$atts      = shortcode_atts( self::defaults( $tag ), $atts, $tag );
		$converted = self::convert( $atts );
		$output    = Pivot_Shortcodes::instance()->render( $converted );

		return $this->notice( $atts, $converted ) . $output;
	}

	/**
	 * Avertit un administrateur qu'un filtre de l'ancien shortcode a été
	 * ignoré, avec le shortcode [pivot_offres] à poser à la place.
	 *
	 * @param array $atts      Attributs de l'ancien shortcode.
	 * @param array $converted Attributs [pivot_offres].
	 * @return string
	 */
	private function notice( $atts, $converted ) {
		$ignored = array();

		foreach ( self::FILTERS as $name ) {
			if ( '' !== trim( (string) $atts[ $name ] ) ) {
				$ignored[] = $name;
			}
		}

		if ( ! $ignored || ! current_user_can( pivot_capability() ) ) {
			return '';
		}

		$shortcode = '[' . Pivot_Shortcodes::TAG;

		foreach ( $converted as $name => $value ) {
			if ( 'tri' === $name && 'defaut' === $value ) {
				continue;
			}

			$shortcode .= sprintf( ' %s="%s"', $name, $value );
		}

		$shortcode .= ']';

		return '<p class="pivot-inline-error"><strong>' . esc_html__( 'PIVOT Offres', 'pivot-offres' ) . '</strong> : '
			. esc_html(
				sprintf(
					/* translators: 1 : attributs ignorés ; 2 : shortcode équivalent. */
					__( 'ancien shortcode, filtre ignoré (%1$s). Portez ce filtre dans la requête PIVOT, puis remplacez le shortcode par %2$s.', 'pivot-offres' ),
					implode( ', ', $ignored ),
					$shortcode
				)
			) . '</p>';
	}
}
