<?php
/**
 * Purge du cache de page de W3 Total Cache.
 *
 * W3 Total Cache garde le HTML des pages de listing et des fiches détail, et
 * le sert aux visiteurs sans passer par WordPress. Une page reconstruite ici
 * restait donc ancienne pour eux jusqu'à l'expiration de ce cache, alors que
 * l'administrateur connecté, qui le contourne, voyait la nouvelle.
 *
 * Sont vidées :
 * - à l'écriture d'un index : la page de listing dans chaque langue, ses
 *   pages de pagination, et les contenus qui l'insèrent par
 *   [pivot_offres listing="…"] ;
 * - pour les offres changées par le différentiel, pour toutes celles d'une page
 *   « complet avec offres liées » reconstruite en entier, et pour l'offre rafraîchie
 *   depuis la barre d'administration : leur fiche détail dans chaque langue.
 *
 * Sans W3 Total Cache, rien ne se passe.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Page_Cache {

	/** @var Pivot_Page_Cache|null */
	private static $instance = null;

	/** @var array|null Contenus qui portent [pivot_offres], lus une fois par requête. */
	private static $shortcode_posts = null;

	/**
	 * @return Pivot_Page_Cache
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'pivot_index_published', array( $this, 'listing_published' ), 10, 3 );
		add_action( 'pivot_offers_changed', array( $this, 'offers_changed' ), 10, 1 );
		add_action( 'pivot_offer_refreshed', array( $this, 'offer_refreshed' ), 10, 3 );
	}

	/**
	 * W3 Total Cache est-il là ?
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'w3tc_flush_url' ) && function_exists( 'w3tc_flush_post' );
	}

	/**
	 * Index écrit : la page de listing, sa pagination, ses insertions.
	 *
	 * @param array $listing  Configuration.
	 * @param int   $count    Nombre d'offres.
	 * @param int   $previous Nombre d'offres de l'index précédent.
	 */
	public function listing_published( $listing, $count, $previous ) {
		if ( ! self::available() ) {
			return;
		}

		// Des pages de pagination ont pu disparaître : elles sont vidées aussi.
		$urls  = self::listing_urls( $listing, max( (int) $count, (int) $previous ) );
		$posts = self::posts_inserting( (string) pivot_get( $listing, 'id', '' ) );

		self::flush( $urls, $posts, 'listing ' . pivot_get( $listing, 'id', '' ) );
	}

	/**
	 * Offres dont la fiche détail a changé : leurs fiches détail.
	 *
	 * @param array $changed Types d'offre, par code.
	 */
	public function offers_changed( $changed ) {
		if ( ! self::available() ) {
			return;
		}

		$urls = array();

		foreach ( (array) $changed as $code => $type ) {
			$urls = array_merge( $urls, self::detail_urls( (string) $code, (int) $type ) );
		}

		self::flush( $urls, array(), count( $changed ) . ' fiche(s) détail' );
	}

	/**
	 * Offre rafraîchie depuis la barre d'administration : sa fiche.
	 *
	 * @param string $code Code PIVOT.
	 * @param string $url  Adresse de la fiche demandée.
	 * @param int    $type Type d'offre, 0 s'il n'est pas connu.
	 */
	public function offer_refreshed( $code, $url, $type = 0 ) {
		if ( ! self::available() ) {
			return;
		}

		$urls   = self::detail_urls( $code, (int) $type );
		$urls[] = $url;

		self::flush( $urls, array(), 'offre ' . $code );
	}

	/**
	 * Adresses d'une page de listing : chaque langue, chaque page.
	 *
	 * @param array $listing Configuration.
	 * @param int   $count   Nombre d'offres à couvrir.
	 * @return string[]
	 */
	public static function listing_urls( $listing, $count ) {
		$per   = max( 1, (int) pivot_get( $listing, 'per_page', 12 ) );
		$pages = max( 1, (int) ceil( $count / $per ) );
		$urls  = array();

		foreach ( Pivot_I18n::languages() as $lang ) {
			$base = Pivot_Listings::url( $listing, $lang );

			if ( ! $base ) {
				continue;
			}

			$urls[] = $base;

			// Même chemin que la pagination de templates/listing.php.
			for ( $page = 2; $page <= $pages; $page++ ) {
				$urls[] = trailingslashit( $base ) . 'page/' . $page . '/';
			}
		}

		return $urls;
	}

	/**
	 * Adresses de la fiche d'une offre dans chaque langue.
	 *
	 * Avec et sans le type : les vignettes pointent vers la première forme, un
	 * lien ancien ou externe peut viser la seconde.
	 *
	 * @param string $code Code PIVOT.
	 * @param int    $type Type d'offre, 0 s'il n'est pas connu.
	 * @return string[]
	 */
	public static function detail_urls( $code, $type = 0 ) {
		$urls = array();

		foreach ( Pivot_I18n::languages() as $lang ) {
			$urls[] = Pivot_Rewrites::detail_url( $code, 0, $lang );

			if ( $type ) {
				$urls[] = Pivot_Rewrites::detail_url( $code, $type, $lang );
			}
		}

		return $urls;
	}

	/**
	 * Contenus publiés qui insèrent une page de listing par shortcode.
	 *
	 * Le shortcode se cherche dans le contenu et dans les données d'Elementor,
	 * où les guillemets sont échappés : listing=\"agenda\".
	 *
	 * @param string $listing_id Identifiant de la page de listing.
	 * @return int[]
	 */
	public static function posts_inserting( $listing_id ) {
		if ( '' === $listing_id ) {
			return array();
		}

		if ( null === self::$shortcode_posts ) {
			global $wpdb;

			$like = '%' . $wpdb->esc_like( '[' . Pivot_Shortcodes::TAG ) . '%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID AS id, post_content AS text FROM {$wpdb->posts}
					WHERE post_status = 'publish' AND post_type <> 'revision' AND post_content LIKE %s
					UNION ALL
					SELECT m.post_id AS id, m.meta_value AS text FROM {$wpdb->postmeta} m
					INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
					WHERE m.meta_key = '_elementor_data' AND p.post_status = 'publish' AND m.meta_value LIKE %s",
					$like,
					$like
				),
				ARRAY_A
			);

			self::$shortcode_posts = array();

			foreach ( (array) $rows as $row ) {
				$id = (int) $row['id'];

				self::$shortcode_posts[ $id ] = ( isset( self::$shortcode_posts[ $id ] ) ? self::$shortcode_posts[ $id ] . "\n" : '' ) . $row['text'];
			}
		}

		$pattern = '/listing\s*=\s*(?:\\\\?["\']|&quot;|&#0?34;)?' . preg_quote( $listing_id, '/' ) . '(?![\w-])/';
		$ids     = array();

		foreach ( self::$shortcode_posts as $id => $text ) {
			if ( preg_match( $pattern, $text ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Demande la purge à W3 Total Cache.
	 *
	 * W3 Total Cache met les adresses en file et les vide à la fin de la
	 * requête, ou avant une redirection.
	 *
	 * @param string[] $urls   Adresses.
	 * @param int[]    $posts  Contenus WordPress.
	 * @param string   $reason Origine, pour le journal.
	 */
	private static function flush( $urls, $posts, $reason ) {
		/**
		 * Adresses vidées du cache de page.
		 *
		 * @param string[] $urls   Adresses.
		 * @param string   $reason Origine de la purge.
		 */
		$urls = array_unique( array_filter( (array) apply_filters( 'pivot_page_cache_urls', $urls, $reason ) ) );

		foreach ( $urls as $url ) {
			w3tc_flush_url( $url );
		}

		foreach ( array_unique( (array) $posts ) as $post_id ) {
			w3tc_flush_post( (int) $post_id );
		}

		Pivot_Logger::debug(
			sprintf( 'Cache de page W3 Total Cache vidé (%1$s) : %2$d adresse(s), %3$d contenu(s).', $reason, count( $urls ), count( $posts ) ),
			array( 'service' => 'cache', 'cache_status' => 'purge' )
		);
	}
}
