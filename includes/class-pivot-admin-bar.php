<?php
/**
 * Bouton « Rafraîchir l'offre » de la barre d'administration.
 *
 * Sur une fiche détail, il retire l'offre du cache et recharge la page, qui la
 * redemande à PIVOT. C'est le recours quand une modification faite dans PIVOT
 * n'est pas encore visible : traduction ajoutée, photo remplacée, offre
 * modifiée depuis la dernière mise à jour de nuit.
 *
 * Seule la fiche détail est concernée. Les vignettes viennent de l'index de
 * chaque page de listing, qui se reconstruit à part.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Admin_Bar {

	const ARG = 'pivot_refresh';

	/** @var Pivot_Admin_Bar|null */
	private static $instance = null;

	/**
	 * @return Pivot_Admin_Bar
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Après resolve_context (priorité 10), avant que Pivot_Templates::prepare()
		// (priorité 20) ne charge l'offre depuis le cache qu'on va vider.
		add_action( 'wp', array( $this, 'maybe_refresh' ), 15 );
		add_action( 'admin_bar_menu', array( $this, 'node' ), 80 );
	}

	/**
	 * Code de l'offre affichée, si la page est une fiche détail.
	 *
	 * @return string
	 */
	private static function current_code() {
		$context = Pivot_Rewrites::instance()->context();

		return $context && 'detail' === $context['kind'] ? (string) $context['code'] : '';
	}

	/**
	 * Adresse complète de la page en cours.
	 *
	 * Complète et non relative : add_query_arg() lirait un chemin sans « ? »
	 * mais avec « = », comme /details/CODE&type=8, comme une chaîne de requête.
	 *
	 * @return string
	 */
	private static function current_url() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = wp_parse_url( home_url() );
		$host = $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );

		return set_url_scheme( 'http://' . $host . $uri );
	}

	/**
	 * Retire l'offre du cache, puis recharge la fiche sans le paramètre.
	 */
	public function maybe_refresh() {
		if ( ! isset( $_GET[ self::ARG ] ) ) {
			return;
		}

		$code = self::current_code();

		if ( ! $code || ! current_user_can( pivot_listing_capability() ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET[ self::ARG ] ) );

		if ( ! wp_verify_nonce( $nonce, 'pivot_refresh_offer_' . $code ) ) {
			return;
		}

		// Le type d'offre fait partie de l'adresse des fiches : lu avant d'oublier
		// l'offre, pour retrouver la fiche dans chaque langue.
		$cached = Pivot_Repository::get_offer( $code, array( 'cached_only' => true ) );
		$type   = is_wp_error( $cached ) ? 0 : (int) pivot_get( $cached, 'type', 0 );

		Pivot_Repository::forget_offer( $code );

		$url = remove_query_arg( self::ARG, self::current_url() );

		Pivot_Logger::info(
			sprintf( 'Offre %s retirée du cache depuis la barre d\'administration.', $code ),
			array( 'service' => 'offer', 'endpoint' => 'offer/' . $code, 'cache_status' => 'purge' )
		);

		/**
		 * Une offre vient d'être retirée du cache à la demande.
		 *
		 * Pivot_Page_Cache y vide la fiche du cache de W3 Total Cache ; un
		 * autre cache de page s'y branche de la même façon.
		 *
		 * @param string $code Code PIVOT.
		 * @param string $url  Adresse de la fiche, dans la langue en cours.
		 * @param int    $type Type d'offre, 0 s'il n'est pas connu.
		 */
		do_action( 'pivot_offer_refreshed', $code, $url, $type );

		nocache_headers();
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Ajoute le bouton à la barre d'administration.
	 *
	 * @param WP_Admin_Bar $bar Barre d'administration.
	 */
	public function node( $bar ) {
		$code = self::current_code();

		if ( ! $code || ! current_user_can( pivot_listing_capability() ) ) {
			return;
		}

		$cached = Pivot_Repository::offer_cached_at( $code );

		$bar->add_node(
			array(
				'id'    => 'pivot-refresh-offer',
				'title' => '<span class="ab-icon dashicons dashicons-update" aria-hidden="true" style="top:2px"></span><span class="ab-label">' . esc_html__( 'Rafraîchir l\'offre', 'pivot-offres' ) . '</span>',
				'href'  => add_query_arg( self::ARG, wp_create_nonce( 'pivot_refresh_offer_' . $code ), self::current_url() ),
				'meta'  => array(
					'title' => $cached
						? sprintf(
							/* translators: 1 : code de l'offre, 2 : durée écoulée (« 3 heures »). */
							__( 'Redemander l\'offre %1$s à PIVOT. Version en cache depuis %2$s.', 'pivot-offres' ),
							$code,
							human_time_diff( $cached )
						)
						/* translators: %s : code de l'offre. */
						: sprintf( __( 'Redemander l\'offre %s à PIVOT.', 'pivot-offres' ), $code ),
				),
			)
		);
	}
}
