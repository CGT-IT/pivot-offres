<?php
/**
 * Accès aux offres, avec cache fichier.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Repository {

	const GROUP = 'offers';

	/**
	 * Détail d'une offre.
	 *
	 * @param string $code    Code PIVOT.
	 * @param array  $args    content (1|2|3), refresh (bool).
	 * @return array|WP_Error
	 */
	public static function get_offer( $code, $args = array() ) {
		$code = trim( (string) $code );

		if ( ! pivot_is_code( $code ) ) {
			return new WP_Error( 'pivot_bad_code', __( 'Code d\'offre invalide.', 'pivot-offres' ) );
		}

		$args = wp_parse_args(
			$args,
			array(
				'content'     => 3,
				'refresh'     => false,
				// Ne pas interroger PIVOT en cas d'absence du cache. Pour les
				// appelants dont l'appel n'est qu'un confort : mieux vaut un
				// résultat approximatif tout de suite qu'un résultat exact après
				// trente secondes d'attente.
				'cached_only' => false,
			)
		);

		// Une seule entrée de cache par offre : elle contient toutes les langues.
		$key = sprintf( 'offer|%s|c%d', $code, (int) $args['content'] );

		if ( ! $args['refresh'] ) {
			$cached = Pivot_Cache::get( self::GROUP, $key );
			if ( null !== $cached ) {
				if ( isset( $cached['__error'] ) ) {
					return new WP_Error( 'pivot_offer_unavailable', (string) $cached['__error'] );
				}
				Pivot_Logger::debug(
					'Offre servie depuis le cache.',
					array( 'service' => 'offer', 'endpoint' => 'offer/' . $code, 'cache_status' => 'hit' )
				);
				return $cached;
			}
		}

		if ( $args['cached_only'] ) {
			return new WP_Error( 'pivot_offer_not_cached', __( 'Offre absente du cache.', 'pivot-offres' ) );
		}

		$matrix = array(
			'fmt'     => 'xml',
			'content' => (int) $args['content'],
			'thumb'   => pivot_settings( 'thumb', 'THB_MW' ),
		);

		$response = Pivot_Client::get( 'offer/' . $code, $matrix, array( 'service' => 'offer' ) );

		if ( is_wp_error( $response ) ) {
			// Cache négatif court : évite de marteler le service sur une 404.
			Pivot_Cache::set(
				self::GROUP,
				$key,
				array( '__error' => $response->get_error_message() ),
				(int) pivot_settings( 'ttl_negative', 5 * MINUTE_IN_SECONDS )
			);
			return $response;
		}

		$offer = Pivot_Parser::parse_single_offer( $response['body'] );

		if ( is_wp_error( $offer ) ) {
			return $offer;
		}

		Pivot_Cache::set( self::GROUP, $key, $offer, (int) pivot_settings( 'ttl_offer', 12 * HOUR_IN_SECONDS ) );

		return $offer;
	}

	/**
	 * Purge le cache d'une offre.
	 *
	 * @param string $code Code PIVOT.
	 */
	public static function forget_offer( $code ) {
		foreach ( array( 1, 2, 3 ) as $content ) {
			Pivot_Cache::delete( self::GROUP, sprintf( 'offer|%s|c%d', $code, $content ) );
		}
	}

	/**
	 * Première page paginée d'une requête pré-programmée.
	 *
	 * Retourne aussi le token de pagination nécessaire aux pages suivantes.
	 *
	 * @param array $listing Configuration de la page de listing.
	 * @param int   $per_page Nombre d'offres par appel.
	 * @return array|WP_Error
	 */
	public static function query_first_page( $listing, $per_page ) {
		$matrix = array(
			'fmt'          => 'xml',
			'content'      => (int) pivot_get( $listing, 'content', 2 ),
			'itemsperpage' => max( 1, (int) $per_page ),
			'thumb'        => pivot_settings( 'thumb', 'THB_MW' ),
		);

		$params = self::matrix_params( $listing );
		if ( $params ) {
			$matrix['param'] = $params;
		}

		$path = 'query/' . pivot_get( $listing, 'query_code', '' ) . '/paginated';

		$response = Pivot_Client::get( $path, $matrix, array( 'service' => 'query' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return Pivot_Parser::parse_offers( $response['body'] );
	}

	/**
	 * Page suivante d'une requête paginée.
	 *
	 * @param string $token Jeton retourné par le premier appel.
	 * @param int    $page  Numéro de page (1-based).
	 * @return array|WP_Error
	 */
	public static function query_page( $token, $page ) {
		$path     = 'query/paginated/' . $token . '/' . max( 1, (int) $page );
		$response = Pivot_Client::get( $path, array( 'fmt' => 'xml', 'thumb' => pivot_settings( 'thumb', 'THB_MW' ) ), array( 'service' => 'query' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return Pivot_Parser::parse_offers( $response['body'] );
	}

	/**
	 * Exécution complète et non paginée d'une requête (petits jeux de données).
	 *
	 * @param array $listing Configuration.
	 * @return array|WP_Error
	 */
	public static function query_all( $listing ) {
		$matrix = array(
			'fmt'     => 'xml',
			'content' => (int) pivot_get( $listing, 'content', 2 ),
			'thumb'   => pivot_settings( 'thumb', 'THB_MW' ),
		);

		$params = self::matrix_params( $listing );
		if ( $params ) {
			$matrix['param'] = $params;
		}

		$response = Pivot_Client::get(
			'query/' . pivot_get( $listing, 'query_code', '' ),
			$matrix,
			array( 'service' => 'query', 'timeout' => max( 60, (int) pivot_settings( 'timeout', 30 ) ) )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return Pivot_Parser::parse_offers( $response['body'] );
	}

	/**
	 * Paramètres matriciels « param » d'une requête paramétrable.
	 *
	 * @param array $listing Configuration.
	 * @return array
	 */
	private static function matrix_params( $listing ) {
		$out = array();
		foreach ( (array) pivot_get( $listing, 'query_params', array() ) as $param ) {
			$name  = pivot_get( $param, 'name' );
			$value = pivot_get( $param, 'value' );
			if ( ! $name || null === $value ) {
				continue;
			}
			$out[] = $name . ':' . $value;
		}
		return $out;
	}

	/**
	 * Nombre d'offres retournées par une requête, sans construire d'index.
	 *
	 * Sert au diagnostic dans l'écran d'édition.
	 *
	 * @param string $query_code Code de requête.
	 * @return int|WP_Error
	 */
	public static function count( $query_code ) {
		$response = Pivot_Client::get(
			'query/' . $query_code,
			array( 'fmt' => 'xml', 'content' => 0 ),
			array( 'service' => 'query' )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = Pivot_Parser::parse_offers( $response['body'] );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		return (int) pivot_get( $parsed, 'count', 0 );
	}
}
