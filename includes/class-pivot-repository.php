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
		$key = self::offer_key( $code, $args['content'] );

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
			Pivot_Cache::delete( self::GROUP, self::offer_key( $code, $content ) );
		}
	}

	/**
	 * Range une offre déjà reçue de PIVOT, sans nouvel appel.
	 *
	 * Sert à la construction d'un index : une requête exécutée au niveau de
	 * détail de la fiche renvoie chaque offre telle que get_offer() la
	 * demanderait. La ranger ici épargne au premier visiteur de la fiche un
	 * aller-retour d'une demi-seconde.
	 *
	 * @param array $offer   Offre normalisée.
	 * @param int   $content Niveau de détail avec lequel elle a été obtenue.
	 * @param int   $ttl     Durée de vie ; 0 pour la durée des fiches détail.
	 * @return bool Vrai si l'offre a été écrite.
	 */
	public static function remember_offer( $offer, $content, $ttl = 0 ) {
		$code = trim( (string) pivot_get( $offer, 'code', '' ) );

		if ( ! pivot_is_code( $code ) ) {
			return false;
		}

		// La route des fiches met le code en capitales avant de le chercher.
		// Écrite sous une autre casse, l'entrée ne serait jamais relue.
		//
		// Pas de mémorisation : une construction range des centaines d'offres
		// dans la même requête, et n'en relit aucune.
		return Pivot_Cache::set(
			self::GROUP,
			self::offer_key( strtoupper( $code ), $content ),
			$offer,
			$ttl > 0 ? (int) $ttl : (int) pivot_settings( 'ttl_offer', 12 * HOUR_IN_SECONDS ),
			false
		);
	}

	/**
	 * Clé de cache d'une offre.
	 *
	 * @param string $code    Code PIVOT.
	 * @param int    $content Niveau de détail.
	 * @return string
	 */
	private static function offer_key( $code, $content ) {
		return sprintf( 'offer|%s|c%d', $code, (int) $content );
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
	 * Différentiel d'une requête : les offres entrées, modifiées ou sorties
	 * depuis la dernière réception validée par query_ack().
	 *
	 * Chaque offre porte `operation` : 0 ajout, 1 modification, 2 retrait. Sans
	 * réception validée — premier appel, après query_clear(), ou si PIVOT a
	 * perdu sa référence — toutes les offres reviennent en ajout.
	 *
	 * Le résultat est retenu par PIVOT comme « dernier appel » : c'est lui que
	 * query_ack() fera passer en référence.
	 *
	 * @param array $listing Configuration.
	 * @param int   $content Richesse des offres renvoyées (0 : codes et dates).
	 * @return array|WP_Error Même forme que query_page().
	 */
	public static function query_diff( $listing, $content = 0 ) {
		$matrix = array(
			'fmt'     => 'xml',
			'content' => (int) $content,
		);

		if ( $content > 0 ) {
			$matrix['thumb'] = pivot_settings( 'thumb', 'THB_MW' );
		}

		$params = self::matrix_params( $listing );
		if ( $params ) {
			$matrix['param'] = $params;
		}

		$response = Pivot_Client::get(
			'query/' . pivot_get( $listing, 'query_code', '' ) . '/diff',
			$matrix,
			array( 'service' => 'query', 'timeout' => max( 60, (int) pivot_settings( 'timeout', 30 ) ) )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return Pivot_Parser::parse_offers( $response['body'] );
	}

	/**
	 * Valide le dernier différentiel reçu : il devient la référence du suivant.
	 *
	 * @param array $listing Configuration.
	 * @return true|WP_Error
	 */
	public static function query_ack( $listing ) {
		$response = Pivot_Client::get(
			'query/' . pivot_get( $listing, 'query_code', '' ) . '/ack',
			array(),
			array( 'service' => 'query' )
		);

		return self::boolean_result( $response, 'pivot_ack_refused' );
	}

	/**
	 * Réinitialise le cache différentiel d'une requête chez PIVOT.
	 *
	 * Le différentiel suivant renverra toutes les offres de la requête.
	 *
	 * @param array $listing Configuration.
	 * @return true|WP_Error
	 */
	public static function query_clear( $listing ) {
		$response = Pivot_Client::delete(
			'query/' . pivot_get( $listing, 'query_code', '' ) . '/clear',
			array(),
			array( 'service' => 'query' )
		);

		return self::boolean_result( $response, 'pivot_clear_refused' );
	}

	/**
	 * Lit une réponse `<result><value>true</value></result>`.
	 *
	 * @param array|WP_Error $response Réponse du client.
	 * @param string         $code     Code d'erreur si PIVOT répond false.
	 * @return true|WP_Error
	 */
	private static function boolean_result( $response, $code ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! preg_match( '#<value>\s*true\s*</value>#i', (string) $response['body'] ) ) {
			return new WP_Error( $code, __( 'PIVOT a refusé l\'opération sur le différentiel.', 'pivot-offres' ) );
		}

		return true;
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
