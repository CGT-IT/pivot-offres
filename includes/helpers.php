<?php
/**
 * Fonctions utilitaires.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

/**
 * Accès défensif à une valeur imbriquée d'un tableau.
 *
 * Les réponses du webservice PIVOT sont irrégulières : d'une offre à l'autre,
 * les mêmes noeuds peuvent être absents. Toute lecture de donnée passe par
 * cette fonction afin de ne jamais produire de notice/warning PHP.
 *
 * @param mixed  $data    Tableau (ou autre) source.
 * @param string $path    Chemin façon "adresse.localite.fr" ou "0.nom".
 * @param mixed  $default Valeur de repli.
 * @return mixed
 */
function pivot_get( $data, $path = '', $default = null ) {
	if ( '' === $path || null === $path ) {
		return ( null === $data || '' === $data ) ? $default : $data;
	}

	$segments = is_array( $path ) ? $path : explode( '.', (string) $path );
	$current  = $data;

	foreach ( $segments as $segment ) {
		if ( is_array( $current ) && array_key_exists( $segment, $current ) ) {
			$current = $current[ $segment ];
			continue;
		}
		if ( is_object( $current ) && isset( $current->$segment ) ) {
			$current = $current->$segment;
			continue;
		}
		return $default;
	}

	if ( null === $current || '' === $current || array() === $current ) {
		return $default;
	}

	return $current;
}

/**
 * Vrai si la valeur pointée existe et n'est pas vide.
 *
 * @param mixed  $data Source.
 * @param string $path Chemin.
 * @return bool
 */
function pivot_has( $data, $path = '' ) {
	$sentinel = '__pivot_missing__';
	return $sentinel !== pivot_get( $data, $path, $sentinel );
}

/**
 * Échappe et affiche une valeur si elle existe, sinon ne produit rien.
 *
 * @param mixed  $data Source.
 * @param string $path Chemin.
 * @param string $before HTML placé avant la valeur.
 * @param string $after  HTML placé après la valeur.
 */
function pivot_echo( $data, $path = '', $before = '', $after = '' ) {
	$value = pivot_get( $data, $path );
	if ( null === $value || '' === $value ) {
		return;
	}
	if ( is_array( $value ) ) {
		return;
	}
	echo wp_kses_post( $before ) . esc_html( $value ) . wp_kses_post( $after );
}

/**
 * Réglages du plugin, fusionnés avec les valeurs par défaut.
 *
 * @param string|null $key     Clé souhaitée.
 * @param mixed       $default Valeur de repli.
 * @return mixed
 */
function pivot_settings( $key = null, $default = null ) {
	static $cache = null;

	if ( null === $cache ) {
		$defaults = array(
			'environment'        => 'stage',
			'detail_bases'       => array(),
			'hreflang'           => 1,
			'url_prod'           => 'https://pivotweb.tourismewallonie.be/PivotWeb-3.1',
			'url_stage'          => 'https://pivotweb-stage.tourismewallonie.be/PivotWeb-3.1',
			'wskey_prod'         => '',
			'wskey_stage'        => '',
			'timeout'            => 30,
			'ttl_index'          => 6 * HOUR_IN_SECONDS,
			'ttl_offer'          => 12 * HOUR_IN_SECONDS,
			'ttl_thesaurus'      => 30 * DAY_IN_SECONDS,
			'ttl_negative'       => 5 * MINUTE_IN_SECONDS,
			'batch_size'         => 100,
			'logs_enabled'       => 1,
			'logs_level'         => 'error',
			'logs_retention'     => 7,
			'thumb'              => 'THB_MW',
			'detail_base'        => 'offre',
			'legacy_mode'        => '301',
			'slug_mode'          => 'follow',
			'map_provider'       => 'leaflet',
			'map_tiles'          => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
			'map_attribution'    => '&copy; OpenStreetMap',
			'map_cluster'        => 1,
			'index_delivery'     => 'file',
			'schema_org'         => 1,
		);

		$stored = get_option( 'pivot_settings', array() );
		$cache  = wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
	}

	if ( null === $key ) {
		return $cache;
	}

	return array_key_exists( $key, $cache ) && '' !== $cache[ $key ] ? $cache[ $key ] : $default;
}

/**
 * URL racine du service PIVOT actif (prod ou stage).
 *
 * @return string
 */
function pivot_service_url() {
	$env = pivot_settings( 'environment', 'stage' );
	$url = 'prod' === $env ? pivot_settings( 'url_prod' ) : pivot_settings( 'url_stage' );
	return untrailingslashit( (string) $url );
}

/**
 * Clé d'authentification de l'environnement actif.
 *
 * @return string
 */
function pivot_ws_key() {
	$env = pivot_settings( 'environment', 'stage' );
	return (string) ( 'prod' === $env ? pivot_settings( 'wskey_prod' ) : pivot_settings( 'wskey_stage' ) );
}

/**
 * Langue d'affichage courante (code ISO 639-1 accepté par PIVOT).
 *
 * @return string
 */
function pivot_lang() {
	return Pivot_I18n::current();
}

/**
 * Normalise une chaîne pour la recherche : minuscules, sans accent, sans ponctuation.
 *
 * @param string $string Chaîne d'entrée.
 * @return string
 */
function pivot_normalize( $string ) {
	$string = (string) $string;
	if ( '' === $string ) {
		return '';
	}
	$string = remove_accents( $string );
	$string = strtolower( $string );
	$string = preg_replace( '/[^a-z0-9]+/', ' ', $string );
	return trim( preg_replace( '/\s+/', ' ', (string) $string ) );
}

/**
 * Construit un segment d'URL lisible à partir d'un libellé.
 *
 * @param string $text Texte source.
 * @param int    $max  Longueur maximale.
 * @return string
 */
function pivot_slugify( $text, $max = 70 ) {
	$slug = sanitize_title( remove_accents( (string) $text ) );
	if ( strlen( $slug ) > $max ) {
		$slug = substr( $slug, 0, $max );
		$slug = rtrim( $slug, '-' );
	}
	return $slug;
}

/**
 * Vérifie qu'un code ressemble à un codeCgt PIVOT (ex. ALD-01-00096Z).
 *
 * @param string $code Code à tester.
 * @return bool
 */
function pivot_is_code( $code ) {
	return (bool) preg_match( '/^[A-Z0-9]{2,5}(-[A-Z0-9]{2,8}){1,4}$/i', (string) $code );
}

/**
 * URL de la vignette d'une offre via le service img (aucune authentification requise).
 *
 * @param string $code_cgt Code de l'offre ou du média.
 * @param string $thumb    Taille prédéfinie (THB_SF, THB_MW, ...) ou vide.
 * @param array  $args     Paramètres matriciels supplémentaires (w, h).
 * @return string
 */
function pivot_image_url( $code_cgt, $thumb = null, $args = array() ) {
	if ( ! $code_cgt ) {
		return '';
	}
	$url = pivot_service_url() . '/img/' . rawurlencode( $code_cgt );

	if ( null === $thumb ) {
		$thumb = pivot_settings( 'thumb', 'THB_MW' );
	}
	if ( $thumb ) {
		$url .= ';thumb=' . rawurlencode( $thumb );
	}
	foreach ( $args as $name => $value ) {
		if ( '' === $value || null === $value ) {
			continue;
		}
		$url .= ';' . rawurlencode( $name ) . '=' . rawurlencode( $value );
	}

	/**
	 * Filtre l'URL d'image générée.
	 *
	 * @param string $url      URL finale.
	 * @param string $code_cgt Code de l'offre.
	 */
	return apply_filters( 'pivot_image_url', $url, $code_cgt );
}

/**
 * URL d'un pictogramme d'urn.
 *
 * @param string $urn Urn (champ ou valeur de champ).
 * @param int    $h   Hauteur souhaitée.
 * @return string
 */
function pivot_picto_url( $urn, $h = 32 ) {
	if ( ! $urn ) {
		return '';
	}
	return pivot_service_url() . '/img/' . rawurlencode( $urn ) . ';h=' . (int) $h;
}

/**
 * Capacité requise pour administrer le plugin.
 *
 * @return string
 */
function pivot_capability() {
	return apply_filters( 'pivot_admin_capability', 'manage_options' );
}
