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
			'diff_enabled'       => 1,
			'sync_time'          => '04:00',
			'logs_enabled'       => 1,
			'logs_level'         => 'error',
			'logs_retention'     => 7,
			'thumb'              => 'THB_MW',
			'map_provider'       => 'leaflet',
			'map_tiles'          => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
			'map_attribution'    => '&copy; OpenStreetMap',
			'map_cluster'        => 1,
			'index_delivery'     => 'file',
			'schema_org'         => 1,
			'sitemap'            => 1,
			'llms_txt'           => 1,
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
 * Texte brut d'un fragment HTML de PIVOT, sur une ligne.
 *
 * wp_strip_all_tags() retire les balises sans rien mettre à leur place : deux
 * paragraphes se collaient (« un sens.Infos pratiques »), et les entités
 * restaient telles quelles (« &nbsp; » dans le JSON-LD). Les blocs et les
 * sauts de ligne deviennent donc des espaces, les entités sont décodées.
 *
 * @param string $html Fragment HTML.
 * @return string
 */
function pivot_plain_text( $html ) {
	$text = (string) preg_replace( '#<(?:br|hr|/?(?:p|div|li|ul|ol|h[1-6]|tr|td|th|table|blockquote|section|article))\b[^>]*>#i', ' ', (string) $html );
	$text = html_entity_decode( wp_strip_all_tags( $text, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	// Un &nbsp; décodé devient U+00A0, que \s ne reconnaît pas sans /u.
	return trim( (string) preg_replace( '/[\s\x{00A0}\x{202F}]+/u', ' ', $text ) );
}

/**
 * Lit un nombre, tel que PIVOT l'écrit ou tel qu'un visiteur le tape.
 *
 * PIVOT écrit « 9.50 » ; un visiteur belge tape plutôt « 9,50 », parfois
 * « 1 250 ». Les deux séparateurs décimaux sont donc admis, les espaces de
 * groupement ignorés. Tout le reste — unité, texte, heure « 3:30 » — n'est pas
 * un nombre.
 *
 * @param mixed $value Valeur brute.
 * @return int|float|null Entier si la valeur est ronde, null si ce n'est pas un nombre.
 */
function pivot_parse_number( $value ) {
	if ( is_int( $value ) ) {
		return $value;
	}

	if ( is_float( $value ) ) {
		if ( ! is_finite( $value ) ) {
			return null;
		}

		$number = $value;
	} elseif ( is_string( $value ) ) {
		$value = str_replace( array( ' ', "\xc2\xa0", "\xe2\x80\xaf" ), '', trim( $value ) );
		$value = str_replace( ',', '.', $value );

		if ( ! preg_match( '/^[-+]?(\d+(\.\d*)?|\.\d+)$/', $value ) ) {
			return null;
		}

		$number = (float) $value;
	} else {
		return null;
	}

	return ( floor( $number ) === $number && abs( $number ) < PHP_INT_MAX ) ? (int) $number : $number;
}

/**
 * Lit une date, telle que PIVOT l'écrit ou telle qu'un navigateur l'envoie.
 *
 * PIVOT écrit « 10/10/2026 » ; un champ de date HTML envoie « 2026-10-10 ».
 * Les deux sont admis, ainsi que les séparateurs « - » et « . » en ordre
 * jour-mois-année. Le résultat est un entier AAAAMMJJ : deux dates se
 * comparent alors comme deux nombres, et l'index reste compact.
 *
 * @param mixed $value Valeur brute.
 * @return int|null 20261010, ou null si ce n'est pas une date du calendrier.
 */
function pivot_parse_date( $value ) {
	if ( is_int( $value ) ) {
		$value = (string) $value;
	}

	if ( ! is_string( $value ) ) {
		return null;
	}

	$value = trim( $value );

	if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m ) || preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $m ) ) {
		list( , $year, $month, $day ) = $m;
	} elseif ( preg_match( '#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $value, $m ) ) {
		list( , $day, $month, $year ) = $m;
	} else {
		return null;
	}

	if ( ! checkdate( (int) $month, (int) $day, (int) $year ) ) {
		return null;
	}

	return (int) $year * 10000 + (int) $month * 100 + (int) $day;
}

/**
 * Écrit une date AAAAMMJJ au format ISO, celui des champs de date HTML.
 *
 * @param int $date Date lue par pivot_parse_date().
 * @return string 2026-10-10.
 */
function pivot_date_iso( $date ) {
	$date = (int) $date;

	return sprintf( '%04d-%02d-%02d', intdiv( $date, 10000 ), intdiv( $date, 100 ) % 100, $date % 100 );
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
 * Vérifie qu'un code ressemble à un codeCgt PIVOT.
 *
 * Deux séparateurs coexistent dans PIVOT : le tiret (ALD-01-00096Z) et le
 * soulignement (CGT_0001_00000087).
 *
 * @param string $code Code à tester.
 * @return bool
 */
function pivot_is_code( $code ) {
	return (bool) preg_match( '/^[A-Z0-9]{2,5}([-_][A-Z0-9]{2,8}){1,4}$/i', (string) $code );
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
 * La copie locale (uploads/pivot-cache/pictos/) quand elle existe, sinon
 * PIVOT. Voir Pivot_Pictos et le filtre pivot_pictos.
 *
 * @param string           $urn   Urn (champ, valeur de champ, type d'offre).
 * @param string|array|int $usage Usage déclaré (« class », « equipment »,
 *                                « signal », « pin »…), paramètres matriciels
 *                                (array( 'h' => 20, 'c' => 'FFFFFF' )), ou
 *                                hauteur en pixels.
 * @return string Vide si PIVOT n'a pas de pictogramme pour cette urn.
 */
function pivot_picto_url( $urn, $usage = 32 ) {
	return Pivot_Pictos::url( $urn, $usage );
}

/**
 * Balise <img> d'un pictogramme d'urn, avec sa taille.
 *
 * À préférer à un <img src="pivot_picto_url()"> écrit à la main : width et
 * height réservent la place du pictogramme avant qu'il n'arrive.
 *
 * @param string           $urn   Urn.
 * @param string|array|int $usage Usage, paramètres ou hauteur (voir pivot_picto_url()).
 * @param array            $attrs Autres attributs : class, alt, title…
 * @return string HTML, vide si PIVOT n'a pas de pictogramme pour cette urn.
 */
function pivot_picto_img( $urn, $usage = 32, $attrs = array() ) {
	return pivot_picto_img_url( Pivot_Pictos::url( $urn, $usage ), $attrs );
}

/**
 * Balise <img> d'un pictogramme dont on a déjà l'adresse (index, classement).
 *
 * La taille vient de Pivot_Pictos::size_of_url(). Une hauteur imposée dans
 * $attrs donne la largeur correspondante.
 *
 * @param string $url   Adresse du pictogramme.
 * @param array  $attrs Autres attributs : class, alt, title, height…
 * @return string HTML, vide sans adresse.
 */
function pivot_picto_img_url( $url, $attrs = array() ) {
	if ( ! $url ) {
		return '';
	}

	$attrs = (array) $attrs;

	list( $width, $height ) = Pivot_Pictos::size_of_url( $url );

	if ( isset( $attrs['height'] ) && ! isset( $attrs['width'] ) && $width && $height ) {
		$attrs['width'] = (int) round( $width * (int) $attrs['height'] / $height );
	}

	$attrs += array(
		'width'    => $width ? $width : null,
		'height'   => $height ? $height : null,
		'alt'      => '',
		'decoding' => 'async',
	);

	$html = '<img src="' . esc_url( $url ) . '"';

	foreach ( $attrs as $name => $value ) {
		if ( null === $value || false === $value || 'src' === $name ) {
			continue;
		}

		$html .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
	}

	return $html . ' />';
}

/**
 * PIVOT n'a-t-il qu'une image transparente pour cette urn ?
 *
 * Relevé lors du téléchargement des pictogrammes : de quoi afficher en texte
 * un équipement sans pictogramme.
 *
 * @param string $urn Urn.
 * @return bool
 */
function pivot_picto_is_empty( $urn ) {
	return Pivot_Pictos::is_empty( $urn );
}

/**
 * Capacité requise pour administrer le plugin.
 *
 * @return string
 */
function pivot_capability() {
	return apply_filters( 'pivot_admin_capability', 'manage_options' );
}
