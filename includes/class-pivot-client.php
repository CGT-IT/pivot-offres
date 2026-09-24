<?php
/**
 * Client HTTP PIVOT/Web.
 *
 * Gère les paramètres matriciels (;name=value), l'authentification par header
 * ws_key, la compression gzip, la journalisation et la détection d'erreur
 * (le service répond en text/plain avec un code != 200).
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Client {

	/**
	 * Construit une URL de service avec ses paramètres matriciels.
	 *
	 * @param string $path   Chemin relatif, ex. 'query/QRY-00-0000-0000'.
	 * @param array  $matrix Paramètres matriciels. Une valeur en tableau est répétée.
	 * @return string
	 */
	public static function build_url( $path, $matrix = array() ) {
		$path = ltrim( (string) $path, '/' );

		// Les segments sont encodés séparément : les ':' des urn restent lisibles.
		$segments = array_map(
			static function ( $segment ) {
				return str_replace( '%3A', ':', rawurlencode( $segment ) );
			},
			explode( '/', $path )
		);

		$url = pivot_service_url() . '/' . implode( '/', $segments );

		foreach ( $matrix as $name => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$values = is_array( $value ) ? $value : array( $value );
			foreach ( $values as $single ) {
				$url .= ';' . rawurlencode( $name ) . '=' . str_replace( '%3A', ':', rawurlencode( $single ) );
			}
		}

		return $url;
	}

	/**
	 * Requête GET.
	 *
	 * @param string $path   Chemin du service.
	 * @param array  $matrix Paramètres matriciels.
	 * @param array  $args   auth (bool), accept (string), timeout (int), service (string).
	 * @return array|WP_Error array{body:string, code:int, headers:array}
	 */
	public static function get( $path, $matrix = array(), $args = array() ) {
		return self::request( 'GET', $path, $matrix, null, $args );
	}

	/**
	 * Requête POST (service Recherche).
	 *
	 * @param string $path   Chemin du service.
	 * @param array  $matrix Paramètres matriciels.
	 * @param string $body   Corps de la requête.
	 * @param array  $args   Options.
	 * @return array|WP_Error
	 */
	public static function post( $path, $matrix = array(), $body = '', $args = array() ) {
		return self::request( 'POST', $path, $matrix, $body, $args );
	}

	/**
	 * Requête DELETE (réinitialisation du cache différentiel d'une requête).
	 *
	 * @param string $path   Chemin du service.
	 * @param array  $matrix Paramètres matriciels.
	 * @param array  $args   Options.
	 * @return array|WP_Error
	 */
	public static function delete( $path, $matrix = array(), $args = array() ) {
		return self::request( 'DELETE', $path, $matrix, null, $args );
	}

	/**
	 * Exécute la requête.
	 *
	 * @param string      $method HTTP.
	 * @param string      $path   Chemin.
	 * @param array       $matrix Paramètres matriciels.
	 * @param string|null $body   Corps.
	 * @param array       $args   Options.
	 * @return array|WP_Error
	 */
	/** Échecs de transport consécutifs avant ouverture du disjoncteur. */
	const CIRCUIT_THRESHOLD = 3;

	/** Durée d'ouverture du disjoncteur, en secondes. */
	const CIRCUIT_COOLDOWN = 180;

	/**
	 * Le disjoncteur est-il ouvert ?
	 *
	 * @return bool
	 */
	private static function circuit_open() {
		$state = Pivot_Cache::get( 'build', 'circuit' );

		if ( ! is_array( $state ) ) {
			return false;
		}

		if ( (int) pivot_get( $state, 'failures', 0 ) < self::CIRCUIT_THRESHOLD ) {
			return false;
		}

		$since = (int) pivot_get( $state, 'opened', 0 );

		return $since > 0 && ( time() - $since ) < self::CIRCUIT_COOLDOWN;
	}

	/**
	 * Compte un échec de transport.
	 */
	private static function record_failure() {
		$state    = Pivot_Cache::get( 'build', 'circuit' );
		$failures = is_array( $state ) ? (int) pivot_get( $state, 'failures', 0 ) : 0;
		$failures++;

		Pivot_Cache::set(
			'build',
			'circuit',
			array(
				'failures' => $failures,
				'opened'   => $failures >= self::CIRCUIT_THRESHOLD ? time() : 0,
			),
			self::CIRCUIT_COOLDOWN * 4
		);

		if ( self::CIRCUIT_THRESHOLD === $failures ) {
			Pivot_Logger::error(
				sprintf(
					'PIVOT injoignable après %1$d tentatives : appels suspendus pendant %2$d secondes.',
					$failures,
					self::CIRCUIT_COOLDOWN
				),
				array( 'service' => 'client' )
			);
		}
	}

	/**
	 * Remet le compteur à zéro : le service répond.
	 */
	private static function record_success() {
		if ( null !== Pivot_Cache::get( 'build', 'circuit' ) ) {
			Pivot_Cache::delete( 'build', 'circuit' );
		}
	}

	private static function request( $method, $path, $matrix, $body, $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'auth'         => true,
				'accept'       => 'application/xml',
				'content_type' => 'application/xml',
				'timeout'      => (int) pivot_settings( 'timeout', 30 ),
				'service'      => strtok( ltrim( (string) $path, '/' ), '/' ),
				// Le test de connexion doit joindre le service même quand le
				// disjoncteur est ouvert : c'est précisément à ce moment-là que
				// l'administrateur s'en sert.
				'bypass_circuit' => false,
			)
		);

		$url = self::build_url( $path, $matrix );

		// Disjoncteur : quand PIVOT ne répond plus, chaque appel coûte jusqu'à
		// trente secondes d'attente. Sans cela, une panne du service se traduit
		// par des pages qui mettent une demi-minute à s'afficher, encore et
		// encore. Après plusieurs échecs d'affilée on cesse d'essayer pendant
		// quelques minutes et on répond tout de suite.
		if ( empty( $args['bypass_circuit'] ) && self::circuit_open() ) {
			return new WP_Error(
				'pivot_circuit_open',
				__( 'Le service PIVOT ne répond pas. Nouvelle tentative dans quelques minutes.', 'pivot-offres' )
			);
		}

		$headers = array(
			'Accept'          => $args['accept'],
			'Accept-Encoding' => 'gzip',
		);

		if ( $args['auth'] ) {
			$key = pivot_ws_key();
			if ( '' === $key ) {
				return new WP_Error(
					'pivot_missing_key',
					__( 'Aucune clé ws_key n\'est enregistrée pour cet environnement. Ajoutez-la dans Réglages PIVOT.', 'pivot-offres' )
				);
			}
			$headers['ws_key'] = $key;
		}

		if ( 'POST' === $method ) {
			$headers['Content-Type'] = $args['content_type'];
		}

		$request = array(
			'method'      => $method,
			'timeout'     => max( 5, (int) $args['timeout'] ),
			'redirection' => 3,
			'headers'     => $headers,
			'sslverify'   => apply_filters( 'pivot_sslverify', true ),
			'user-agent'  => 'WordPress/PivotOffres ' . PIVOT_VERSION . '; ' . home_url( '/' ),
		);

		if ( null !== $body && '' !== $body ) {
			$request['body'] = $body;
		}

		$start    = microtime( true );
		$response = wp_remote_request( $url, $request );
		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			// Seules les pannes de transport — injoignable, délai dépassé —
			// comptent pour le disjoncteur. Un 404 sur une offre absente est une
			// réponse valide du service, pas une panne.
			self::record_failure();

			Pivot_Logger::error(
				$response->get_error_message(),
				array(
					'service'     => $args['service'],
					'endpoint'    => $url,
					'duration_ms' => $duration,
					'context'     => array( 'method' => $method ),
				)
			);
			return $response;
		}

		self::record_success();

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$payload = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			// PIVOT renvoie le message d'erreur en text/plain.
			$message = trim( wp_strip_all_tags( $payload ) );
			$message = $message ? mb_substr( $message, 0, 500 ) : sprintf( 'HTTP %d', $code );

			Pivot_Logger::error(
				$message,
				array(
					'service'     => $args['service'],
					'endpoint'    => $url,
					'http_code'   => $code,
					'duration_ms' => $duration,
					'bytes'       => strlen( $payload ),
					'context'     => array( 'method' => $method ),
				)
			);

			return new WP_Error( 'pivot_http_' . $code, $message, array( 'status' => $code ) );
		}

		Pivot_Logger::info(
			'OK',
			array(
				'service'      => $args['service'],
				'endpoint'     => $url,
				'http_code'    => $code,
				'duration_ms'  => $duration,
				'bytes'        => strlen( $payload ),
				'cache_status' => 'miss',
				'context'      => array( 'method' => $method ),
			)
		);

		return array(
			'body'    => $payload,
			'code'    => $code,
			'headers' => wp_remote_retrieve_headers( $response ),
		);
	}

	/**
	 * Test de connexion utilisé par l'écran de réglages.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function test_connection() {
		$thesaurus = self::get(
			'thesaurus/typeofr',
			array( 'fmt' => 'xml' ),
			array( 'auth' => false, 'timeout' => 15, 'bypass_circuit' => true )
		);

		if ( is_wp_error( $thesaurus ) ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %s: message d'erreur. */
					__( 'Le service thesaurus est injoignable : %s', 'pivot-offres' ),
					$thesaurus->get_error_message()
				),
			);
		}

		if ( '' === pivot_ws_key() ) {
			return array(
				'ok'      => false,
				'message' => __( 'Le service répond, mais aucune clé ws_key n\'est enregistrée pour cet environnement.', 'pivot-offres' ),
			);
		}

		$query = self::get(
			'query/QRY-00-0000-0000',
			array( 'fmt' => 'xml' ),
			array( 'timeout' => 20, 'service' => 'query' )
		);

		if ( is_wp_error( $query ) ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %s: message d'erreur. */
					__( 'Thesaurus accessible, mais l\'appel authentifié a échoué : %s', 'pivot-offres' ),
					$query->get_error_message()
				),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( 'Connexion établie : le thesaurus et un appel authentifié répondent tous les deux.', 'pivot-offres' ),
		);
	}
}
