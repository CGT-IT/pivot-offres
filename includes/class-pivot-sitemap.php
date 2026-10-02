<?php
/**
 * Plan du site XML des pages du plugin.
 *
 * Les pages de listing et les fiches ne sont pas des contenus WordPress : le
 * plan du site du cœur (wp-sitemap.xml) les ignorait, et les moteurs ne
 * trouvaient les fiches qu'en suivant la pagination des listes. Ce fournisseur
 * y ajoute deux plans, wp-sitemap-pivot-listings-1.xml et
 * wp-sitemap-pivot-offers-1.xml, dans toutes les langues du site.
 *
 * Les adresses viennent des index déjà construits : aucun appel à PIVOT. Une
 * offre publiée par plusieurs pages de listing n'est écrite qu'une fois.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

// Plan du site du cœur : WordPress 5.5 et suivants.
if ( ! class_exists( 'WP_Sitemaps_Provider' ) ) {
	return;
}

class Pivot_Sitemap extends WP_Sitemaps_Provider {

	/** @var array|null Adresses par sous-type, calculées une fois par requête. */
	private $urls = null;

	public function __construct() {
		$this->name        = 'pivot';
		$this->object_type = 'pivot';
	}

	/**
	 * Déclare le fournisseur auprès du plan du site de WordPress.
	 *
	 * Sans effet quand le plan du site est désactivé — site fermé aux
	 * moteurs, ou extension SEO qui publie le sien.
	 */
	public static function register() {
		if ( ! pivot_settings( 'sitemap', 1 ) || ! function_exists( 'wp_register_sitemap_provider' ) ) {
			return;
		}

		wp_register_sitemap_provider( 'pivot', new self() );
	}

	/**
	 * Sous-types : pages de listing et fiches.
	 *
	 * @return array
	 */
	public function get_object_subtypes() {
		return array(
			'listings' => (object) array( 'name' => 'listings' ),
			'offers'   => (object) array( 'name' => 'offers' ),
		);
	}

	/**
	 * Adresses d'une page du plan.
	 *
	 * @param int    $page_num       Page, à partir de 1.
	 * @param string $object_subtype listings ou offers.
	 * @return array
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		$max = wp_sitemaps_get_max_urls( $this->object_type );

		return array_slice( $this->urls( $object_subtype ), ( max( 1, (int) $page_num ) - 1 ) * $max, $max );
	}

	/**
	 * Nombre de pages d'un sous-type.
	 *
	 * @param string $object_subtype listings ou offers.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		return (int) ceil( count( $this->urls( $object_subtype ) ) / wp_sitemaps_get_max_urls( $this->object_type ) );
	}

	/**
	 * Adresses d'un sous-type.
	 *
	 * @param string $subtype listings ou offers.
	 * @return array
	 */
	private function urls( $subtype ) {
		if ( null === $this->urls ) {
			$this->urls = $this->collect();
		}

		return isset( $this->urls[ $subtype ] ) ? $this->urls[ $subtype ] : array();
	}

	/**
	 * Relève les adresses dans les index de toutes les pages et langues.
	 *
	 * @return array listings et offers, listes d'entrées array( loc, lastmod ).
	 */
	private function collect() {
		$listings = array();
		$offers   = array();

		foreach ( Pivot_Listings::active() as $listing ) {
			$id = (string) pivot_get( $listing, 'id', '' );

			foreach ( Pivot_I18n::languages() as $lang ) {
				$index = Pivot_Index_Builder::read( $id, $lang );
				$index = is_array( $index ) ? $index : array();
				$url   = Pivot_Listings::url( $listing, $lang );

				if ( $url && ! isset( $listings[ $url ] ) ) {
					$listings[ $url ] = array( 'loc' => $url );

					if ( pivot_get( $index, 'generated' ) ) {
						$listings[ $url ]['lastmod'] = gmdate( 'c', (int) $index['generated'] );
					}
				}

				foreach ( (array) pivot_get( $index, 'items', array() ) as $item ) {
					$loc = (string) pivot_get( $item, 'u', '' );

					// Sans type connu, l'adresse de l'entrée n'est pas la
					// canonique de la fiche : un plan du site ne doit donner
					// que des adresses canoniques.
					if ( '' === $loc || ! pivot_get( $item, 't' ) || isset( $offers[ $loc ] ) ) {
						continue;
					}

					$offers[ $loc ] = array( 'loc' => $loc );
				}
			}

			// Un index décodé pèse plusieurs mégaoctets : on ne garde en
			// mémoire que les adresses relevées.
			Pivot_Index_Builder::forget( $id );
		}

		/**
		 * Permet de modifier les adresses publiées dans le plan du site.
		 *
		 * @param array $urls listings et offers : listes d'entrées array( 'loc' => URL, 'lastmod' => date ISO ).
		 */
		return apply_filters(
			'pivot_sitemap_urls',
			array(
				'listings' => array_values( $listings ),
				'offers'   => array_values( $offers ),
			)
		);
	}
}
