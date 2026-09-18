<?php
/**
 * Shortcode d'insertion d'offres.
 *
 * Pose une liste de vignettes dans une page ou un article ordinaire : pas de
 * carte, pas de critères, rien à manipuler pour le visiteur. C'est un usage
 * éditorial — trois hébergements dans un article, les nouveautés en page
 * d'accueil — par opposition aux pages de listing, qui sont des outils de
 * recherche.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Shortcodes {

	const TAG   = 'pivot_offres';
	const GROUP = 'offers';

	/** @var Pivot_Shortcodes|null */
	private static $instance = null;

	/**
	 * @return Pivot_Shortcodes
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/**
	 * Charge la feuille de style quand le contenu affiché utilise le shortcode.
	 *
	 * Détecter en amont évite d'envoyer les styles dans le pied de page.
	 */
	public function maybe_enqueue() {
		$post = get_post();

		if ( $post && has_shortcode( (string) $post->post_content, self::TAG ) ) {
			wp_enqueue_style( 'pivot-offres', PIVOT_URL . 'assets/css/pivot.css', array(), PIVOT_VERSION );
		}
	}

	/**
	 * Attributs par défaut.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'listing'    => '',
			'query'      => '',
			'codes'      => '',
			'filtre'     => '',
			'nombre'     => 6,
			'colonnes'   => 3,
			'tri'        => 'defaut',
			'titre'      => '',
			'lien'       => 'non',
			'lien_texte' => '',
			'classe'     => '',
		);
	}

	/**
	 * Rend le shortcode.
	 *
	 * @param array $atts Attributs.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts( self::defaults(), $atts, self::TAG );
		$lang = Pivot_I18n::current();

		$atts['nombre']   = max( 1, min( 48, (int) $atts['nombre'] ) );
		$atts['colonnes'] = max( 1, min( 6, (int) $atts['colonnes'] ) );

		$items = $this->collect( $atts, $lang );

		if ( is_wp_error( $items ) ) {
			return $this->error( $items->get_error_message() );
		}

		if ( ! $items ) {
			return '';
		}

		// Au cas où la détection en amont n'ait pas vu le shortcode : bloc
		// réutilisable, widget, contenu construit par un autre plugin.
		wp_enqueue_style( 'pivot-offres', PIVOT_URL . 'assets/css/pivot.css', array(), PIVOT_VERSION );

		return $this->markup( $items, $atts, $lang );
	}

	/**
	 * Rassemble les offres à afficher.
	 *
	 * @param array  $atts Attributs.
	 * @param string $lang Langue.
	 * @return array|WP_Error
	 */
	private function collect( $atts, $lang ) {
		if ( $atts['codes'] ) {
			return $this->from_codes( $atts, $lang );
		}

		if ( $atts['listing'] ) {
			return $this->from_listing( $atts, $lang );
		}

		if ( $atts['query'] ) {
			return $this->from_query( $atts, $lang );
		}

		return new WP_Error(
			'pivot_shortcode_source',
			__( 'Indiquez une source : listing, query ou codes.', 'pivot-offres' )
		);
	}

	/**
	 * Sélection nommée : une liste de codes séparés par des virgules.
	 *
	 * @param array  $atts Attributs.
	 * @param string $lang Langue.
	 * @return array
	 */
	private function from_codes( $atts, $lang ) {
		$items = array();

		foreach ( array_filter( array_map( 'trim', explode( ',', $atts['codes'] ) ) ) as $code ) {
			if ( ! pivot_is_code( $code ) ) {
				continue;
			}

			$offer = Pivot_Repository::get_offer( $code, array( 'content' => 3 ) );

			if ( is_wp_error( $offer ) ) {
				Pivot_Logger::warn(
					sprintf( 'Shortcode : offre %s indisponible.', $code ),
					array( 'service' => 'offer', 'endpoint' => 'offer/' . $code )
				);
				continue;
			}

			$item = Pivot_Index_Builder::item_from_offer( $offer, $lang );

			if ( $item ) {
				$items[] = $item;
			}
		}

		// L'ordre saisi est intentionnel : on ne le trie pas.
		return array_slice( $items, 0, $atts['nombre'] );
	}

	/**
	 * Extrait d'une page de listing existante.
	 *
	 * Rien n'est redemandé à PIVOT : l'index de la page est déjà là.
	 *
	 * @param array  $atts Attributs.
	 * @param string $lang Langue.
	 * @return array|WP_Error
	 */
	private function from_listing( $atts, $lang ) {
		$listing = Pivot_Listings::get( $atts['listing'] );

		if ( ! $listing ) {
			return new WP_Error(
				'pivot_shortcode_listing',
				sprintf(
					/* translators: %s : identifiant de page. */
					__( 'Page de listing « %s » introuvable.', 'pivot-offres' ),
					$atts['listing']
				)
			);
		}

		$index = Pivot_Index_Builder::ensure( $listing, $lang );
		$items = (array) pivot_get( $index, 'items', array() );

		$items = $this->apply_filter( $items, $atts['filtre'] );
		$items = $this->sort( $items, $atts['tri'] );

		return array_slice( $items, 0, $atts['nombre'] );
	}

	/**
	 * Requête pré-programmée interrogée directement.
	 *
	 * Seules les offres nécessaires sont demandées, et le résultat est mis en
	 * cache : un shortcode ne déclenche pas un appel par affichage de page.
	 *
	 * @param array  $atts Attributs.
	 * @param string $lang Langue.
	 * @return array|WP_Error
	 */
	private function from_query( $atts, $lang ) {
		$query = strtoupper( trim( $atts['query'] ) );
		$key   = sprintf( 'shortcode|%s|%s|%d|%s', $query, $lang, $atts['nombre'], $atts['tri'] );

		$cached = Pivot_Cache::get( self::GROUP, $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Une marge est demandée : le tri par nom ou l'aléatoire n'a de sens
		// que sur un ensemble un peu plus large que la sélection affichée.
		$fetch = 'defaut' === $atts['tri'] ? $atts['nombre'] : min( 60, $atts['nombre'] * 4 );

		$virtual = array(
			'id'         => 'shortcode',
			'query_code' => $query,
			'content'    => 2,
			'filters'    => array(),
		);

		$page = Pivot_Repository::query_first_page( $virtual, $fetch );

		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$items = array();

		foreach ( (array) pivot_get( $page, 'offers', array() ) as $offer ) {
			$item = Pivot_Index_Builder::item_from_offer( $offer, $lang, $virtual );

			if ( $item ) {
				$items[] = $item;
			}
		}

		$items = $this->apply_filter( $items, $atts['filtre'] );
		$items = $this->sort( $items, $atts['tri'] );
		$items = array_slice( $items, 0, $atts['nombre'] );

		Pivot_Cache::set( self::GROUP, $key, $items, (int) pivot_settings( 'ttl_index', 6 * HOUR_IN_SECONDS ) );

		return $items;
	}

	/**
	 * Restreint la sélection, par exemple filtre="province:namur|type:hotel".
	 *
	 * @param array  $items  Entrées.
	 * @param string $filtre Expression de filtre.
	 * @return array
	 */
	private function apply_filter( $items, $filtre ) {
		$filtre = trim( (string) $filtre );

		if ( '' === $filtre ) {
			return $items;
		}

		$criteria = array();

		foreach ( explode( '|', $filtre ) as $pair ) {
			$parts = array_map( 'trim', explode( ':', $pair, 2 ) );

			if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
				continue;
			}

			$criteria[ $parts[0] ][] = pivot_normalize( $parts[1] );
		}

		if ( ! $criteria ) {
			return $items;
		}

		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $criteria ) {
					foreach ( $criteria as $key => $wanted ) {
						$owned = array_map( 'pivot_normalize', (array) pivot_get( $item, array( 'f', $key ), array() ) );

						if ( ! array_intersect( $wanted, $owned ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}

	/**
	 * Ordonne la sélection.
	 *
	 * @param array  $items Entrées.
	 * @param string $tri   defaut|nom|aleatoire.
	 * @return array
	 */
	private function sort( $items, $tri ) {
		if ( 'nom' === $tri ) {
			usort(
				$items,
				static function ( $a, $b ) {
					return strcoll( pivot_get( $a, 'n', '' ), pivot_get( $b, 'n', '' ) );
				}
			);
		} elseif ( 'aleatoire' === $tri ) {
			shuffle( $items );
		}

		return $items;
	}

	/**
	 * Construit le HTML.
	 *
	 * Les vignettes passent par le même gabarit que les pages de listing :
	 * styles et micro-données restent identiques, et un thème qui a surchargé
	 * la vignette voit sa version reprise ici aussi.
	 *
	 * @param array  $items Entrées.
	 * @param array  $atts  Attributs.
	 * @param string $lang  Langue.
	 * @return string
	 */
	private function markup( $items, $atts, $lang ) {
		$classes = array( 'pivot-inline', 'pivot-cols-' . (int) $atts['colonnes'] );

		if ( $atts['classe'] ) {
			$classes[] = sanitize_html_class( $atts['classe'] );
		}

		ob_start();

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';

		if ( $atts['titre'] ) {
			echo '<h2 class="pivot-inline-title">' . esc_html( $atts['titre'] ) . '</h2>';
		}

		echo '<div class="pivot-grid">';

		foreach ( $items as $item ) {
			Pivot_Templates::instance()->part( 'card', array( 'item' => $item ) );
		}

		echo '</div>';

		if ( 'oui' === $atts['lien'] && $atts['listing'] ) {
			$listing = Pivot_Listings::get( $atts['listing'] );
			$url     = $listing ? Pivot_Listings::url( $listing, $lang ) : '';

			if ( $url ) {
				$label = $atts['lien_texte'] ? $atts['lien_texte'] : __( 'Voir toutes les offres', 'pivot-offres' );

				printf(
					'<p class="pivot-inline-more"><a class="pivot-button" href="%s">%s</a></p>',
					esc_url( $url ),
					esc_html( $label )
				);
			}
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * Message d'erreur, visible des seuls administrateurs.
	 *
	 * Un visiteur n'a rien à faire d'un problème de configuration.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private function error( $message ) {
		if ( ! current_user_can( pivot_capability() ) ) {
			return '';
		}

		return '<p class="pivot-inline-error"><strong>' . esc_html__( 'PIVOT Offres', 'pivot-offres' ) . '</strong> : '
			. esc_html( $message ) . '</p>';
	}
}
