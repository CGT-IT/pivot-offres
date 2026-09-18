<?php
/**
 * Registre des pages de listing.
 *
 * Une page est une configuration : URL, code de requête, carte, pagination,
 * filtres. Chaque champ visible par le visiteur peut être traduit langue par
 * langue ; ce qui n'est pas traduit reprend la langue par défaut.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Listings {

	const OPTION = 'pivot_listings';

	/**
	 * Configuration par défaut.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'id'               => '',
			'title'            => '',
			'titles'           => array(),
			'slug'             => '',
			'slugs'            => array(),
			'query_code'       => '',
			'query_params'     => array(),
			'content'          => 2,
			'per_page'         => 12,
			'show_map'         => 0,
			'map_zoom'         => 9,
			'map_center'       => '',
			'sort_field'       => '',
			'sort_mode'        => 'ASC',
			'search_enabled'   => 1,
			'search_label'     => '',
			'search_labels'    => array(),
			'filters'          => array(),
			'cache_ttl'        => 0,
			'intro'            => '',
			'intros'           => array(),
			'seo_title'        => '',
			'seo_titles'       => array(),
			'seo_description'  => '',
			'seo_descriptions' => array(),
			'active'           => 1,
			'index_built'      => 0,
			'index_count'      => 0,
			'facet_values'     => array(),
			'offer_types'      => array(),
			'created'          => 0,
			'updated'          => 0,
		);
	}

	/**
	 * Toutes les pages.
	 *
	 * @return array id => configuration.
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$out = array();

		foreach ( $stored as $id => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}
			$config       = wp_parse_args( $config, self::defaults() );
			$config['id'] = (string) $id;
			$out[ $id ]   = $config;
		}

		return $out;
	}

	/**
	 * Pages publiées et exploitables.
	 *
	 * @return array
	 */
	public static function active() {
		return array_filter(
			self::all(),
			static function ( $config ) {
				return ! empty( $config['active'] ) && ! empty( $config['slug'] ) && ! empty( $config['query_code'] );
			}
		);
	}

	/**
	 * Une page par identifiant.
	 *
	 * @param string $id Identifiant.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/* --------------------------------------------------------- traductions */

	/**
	 * Titre dans une langue.
	 *
	 * @param array       $listing Configuration.
	 * @param string|null $lang    Langue.
	 * @return string
	 */
	public static function title( $listing, $lang = null ) {
		$lang = $lang ? $lang : Pivot_I18n::current();
		return Pivot_I18n::custom( pivot_get( $listing, 'titles', array() ), $lang, pivot_get( $listing, 'title', '' ) );
	}

	/**
	 * Chemin d'URL dans une langue.
	 *
	 * @param array       $listing Configuration.
	 * @param string|null $lang    Langue.
	 * @return string
	 */
	public static function slug( $listing, $lang = null ) {
		$lang = $lang ? $lang : Pivot_I18n::current();
		return Pivot_I18n::custom( pivot_get( $listing, 'slugs', array() ), $lang, pivot_get( $listing, 'slug', '' ) );
	}

	/**
	 * Texte d'introduction dans une langue.
	 *
	 * @param array       $listing Configuration.
	 * @param string|null $lang    Langue.
	 * @return string
	 */
	public static function intro( $listing, $lang = null ) {
		$lang = $lang ? $lang : Pivot_I18n::current();
		return Pivot_I18n::custom( pivot_get( $listing, 'intros', array() ), $lang, pivot_get( $listing, 'intro', '' ) );
	}

	/**
	 * Titre SEO dans une langue.
	 *
	 * @param array       $listing Configuration.
	 * @param string|null $lang    Langue.
	 * @return string
	 */
	public static function seo_title( $listing, $lang = null ) {
		$lang  = $lang ? $lang : Pivot_I18n::current();
		$title = Pivot_I18n::custom( pivot_get( $listing, 'seo_titles', array() ), $lang, pivot_get( $listing, 'seo_title', '' ) );
		return $title ? $title : self::title( $listing, $lang );
	}

	/**
	 * Méta description dans une langue.
	 *
	 * @param array       $listing Configuration.
	 * @param string|null $lang    Langue.
	 * @return string
	 */
	public static function seo_description( $listing, $lang = null ) {
		$lang = $lang ? $lang : Pivot_I18n::current();
		return Pivot_I18n::custom( pivot_get( $listing, 'seo_descriptions', array() ), $lang, pivot_get( $listing, 'seo_description', '' ) );
	}

	/**
	 * Libellé du champ de recherche dans une langue.
	 *
	 * @param array       $listing Configuration.
	 * @param string|null $lang    Langue.
	 * @return string
	 */
	public static function search_label( $listing, $lang = null ) {
		$lang  = $lang ? $lang : Pivot_I18n::current();
		$label = Pivot_I18n::custom( pivot_get( $listing, 'search_labels', array() ), $lang, pivot_get( $listing, 'search_label', '' ) );
		return $label ? $label : __( 'Rechercher', 'pivot-offres' );
	}

	/**
	 * Libellé d'un filtre dans une langue.
	 *
	 * @param array       $filter Définition du filtre.
	 * @param string|null $lang   Langue.
	 * @return string
	 */
	public static function filter_label( $filter, $lang = null ) {
		$lang = $lang ? $lang : Pivot_I18n::current();
		return Pivot_I18n::custom(
			pivot_get( $filter, 'labels', array() ),
			$lang,
			pivot_get( $filter, 'label', pivot_get( $filter, 'key', '' ) )
		);
	}

	/**
	 * Traduction imposée pour une valeur de filtre.
	 *
	 * Elle prend le pas sur le libellé renvoyé par PIVOT : c'est le moyen de
	 * corriger une traduction absente ou inadaptée sans toucher aux données.
	 *
	 * @param array  $filter   Définition du filtre.
	 * @param string $lang     Langue.
	 * @param string $value    Valeur stable.
	 * @param string $fallback Libellé PIVOT.
	 * @return string
	 */
	public static function filter_value_label( $filter, $lang, $value, $fallback = '' ) {
		$overrides = pivot_get( $filter, array( 'value_labels', $lang ), array() );

		if ( is_array( $overrides ) && isset( $overrides[ $value ] ) && '' !== trim( (string) $overrides[ $value ] ) ) {
			return (string) $overrides[ $value ];
		}

		return $fallback;
	}

	/* ------------------------------------------------------- enregistrement */

	/**
	 * Enregistre une page.
	 *
	 * @param array $config Configuration brute.
	 * @return string|WP_Error Identifiant enregistré.
	 */
	public static function save( $config ) {
		$config  = wp_parse_args( is_array( $config ) ? $config : array(), self::defaults() );
		$langs   = Pivot_I18n::enabled();
		$default = Pivot_I18n::default_lang();

		$title = sanitize_text_field( $config['title'] );

		if ( '' === $title ) {
			return new WP_Error( 'pivot_missing_title', __( 'Donnez un titre à la page de listing.', 'pivot-offres' ) );
		}

		$slug = self::sanitize_path( $config['slug'] ? $config['slug'] : $title );

		if ( '' === $slug ) {
			return new WP_Error( 'pivot_missing_slug', __( 'Indiquez l\'URL de la page, par exemple hebergements ou sejourner/hotels.', 'pivot-offres' ) );
		}

		$query_code = strtoupper( sanitize_text_field( $config['query_code'] ) );

		if ( '' === $query_code ) {
			return new WP_Error( 'pivot_missing_query', __( 'Indiquez le code de la requête pré-programmée, par exemple QRY-00-0000-0000.', 'pivot-offres' ) );
		}

		$id = $config['id'] ? sanitize_key( $config['id'] ) : self::unique_id( $slug );

		// Traductions des chemins d'URL.
		$slugs = array( $default => $slug );

		foreach ( $langs as $lang ) {
			if ( $lang === $default ) {
				continue;
			}
			$candidate = self::sanitize_path( pivot_get( $config, array( 'slugs', $lang ), '' ) );
			if ( $candidate ) {
				$slugs[ $lang ] = $candidate;
			}
		}

		// Aucune page ne peut partager un chemin avec une autre, quelle que soit la langue.
		foreach ( self::all() as $existing_id => $existing ) {
			if ( $existing_id === $id ) {
				continue;
			}

			$taken = array_values( (array) pivot_get( $existing, 'slugs', array() ) );
			$taken[] = pivot_get( $existing, 'slug', '' );

			foreach ( $slugs as $candidate ) {
				if ( in_array( $candidate, $taken, true ) ) {
					return new WP_Error(
						'pivot_duplicate_slug',
						sprintf(
							/* translators: %s : chemin d'URL déjà utilisé. */
							__( 'L\'URL « %s » est déjà utilisée par une autre page de listing.', 'pivot-offres' ),
							$candidate
						)
					);
				}
			}
		}

		$existing = self::get( $id );

		$clean = array(
			'id'               => $id,
			'title'            => $title,
			'titles'           => self::sanitize_translations( $config['titles'], 'sanitize_text_field' ),
			'slug'             => $slug,
			'slugs'            => $slugs,
			'query_code'       => $query_code,
			'query_params'     => self::sanitize_params( $config['query_params'] ),
			'content'          => in_array( (int) $config['content'], array( 1, 2, 3 ), true ) ? (int) $config['content'] : 2,
			'per_page'         => max( 1, min( 100, (int) $config['per_page'] ) ),
			'show_map'         => empty( $config['show_map'] ) ? 0 : 1,
			'map_zoom'         => max( 1, min( 18, (int) $config['map_zoom'] ) ),
			'map_center'       => self::sanitize_latlng( $config['map_center'] ),
			'sort_field'       => sanitize_text_field( $config['sort_field'] ),
			'sort_mode'        => 'DESC' === strtoupper( (string) $config['sort_mode'] ) ? 'DESC' : 'ASC',
			'search_enabled'   => empty( $config['search_enabled'] ) ? 0 : 1,
			'search_label'     => sanitize_text_field( $config['search_label'] ),
			'search_labels'    => self::sanitize_translations( $config['search_labels'], 'sanitize_text_field' ),
			'filters'          => self::sanitize_filters( $config['filters'] ),
			'cache_ttl'        => max( 0, (int) $config['cache_ttl'] ),
			'intro'            => wp_kses_post( $config['intro'] ),
			'intros'           => self::sanitize_translations( $config['intros'], 'wp_kses_post' ),
			'seo_title'        => sanitize_text_field( $config['seo_title'] ),
			'seo_titles'       => self::sanitize_translations( $config['seo_titles'], 'sanitize_text_field' ),
			'seo_description'  => sanitize_textarea_field( $config['seo_description'] ),
			'seo_descriptions' => self::sanitize_translations( $config['seo_descriptions'], 'sanitize_textarea_field' ),
			'active'           => empty( $config['active'] ) ? 0 : 1,
			'index_built'      => $existing ? (int) $existing['index_built'] : 0,
			'index_count'      => $existing ? (int) $existing['index_count'] : 0,
			'facet_values'     => $existing ? (array) $existing['facet_values'] : array(),
			'offer_types'      => $existing ? (array) $existing['offer_types'] : array(),
			'created'          => $existing && $existing['created'] ? (int) $existing['created'] : time(),
			'updated'          => time(),
		);

		$all        = self::all();
		$all[ $id ] = $clean;

		update_option( self::OPTION, $all, false );

		Pivot_Rewrites::instance()->schedule_flush();

		$structure_changed = ! $existing
			|| $existing['query_code'] !== $clean['query_code']
			|| (int) $existing['content'] !== (int) $clean['content']
			|| wp_json_encode( $existing['filters'] ) !== wp_json_encode( $clean['filters'] );

		if ( $structure_changed ) {
			Pivot_Index_Builder::invalidate( $id );
		}

		return $id;
	}

	/**
	 * Supprime une page et ses index.
	 *
	 * @param string $id Identifiant.
	 * @return bool
	 */
	public static function delete( $id ) {
		$all = self::all();

		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}

		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );

		Pivot_Index_Builder::delete_index( $id );
		Pivot_Rewrites::instance()->schedule_flush();

		return true;
	}

	/**
	 * Met à jour quelques champs techniques.
	 *
	 * @param string $id     Identifiant.
	 * @param array  $fields Champs à écraser.
	 */
	public static function update_meta( $id, $fields ) {
		$all = self::all();

		if ( ! isset( $all[ $id ] ) ) {
			return;
		}

		foreach ( $fields as $key => $value ) {
			$all[ $id ][ $key ] = $value;
		}

		update_option( self::OPTION, $all, false );
	}

	/* ---------------------------------------------------------- nettoyage */

	/**
	 * Nettoie un chemin d'URL multi-segments.
	 *
	 * @param string $path Chemin brut.
	 * @return string
	 */
	public static function sanitize_path( $path ) {
		$path     = trim( (string) $path, "/ \t\n\r" );
		$segments = array_filter( explode( '/', $path ) );
		$clean    = array();

		foreach ( $segments as $segment ) {
			$slug = sanitize_title( $segment );
			if ( $slug ) {
				$clean[] = $slug;
			}
		}

		return implode( '/', $clean );
	}

	/**
	 * Nettoie un tableau lang => valeur.
	 *
	 * @param mixed    $values   Données brutes.
	 * @param callable $callback Fonction de nettoyage.
	 * @return array
	 */
	private static function sanitize_translations( $values, $callback ) {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$clean = array();

		foreach ( $values as $lang => $value ) {
			$lang = Pivot_I18n::code( $lang );

			if ( ! $lang ) {
				continue;
			}

			$value = call_user_func( $callback, $value );

			if ( '' === trim( (string) $value ) ) {
				continue;
			}

			$clean[ $lang ] = $value;
		}

		return $clean;
	}

	/**
	 * Identifiant unique dérivé du chemin.
	 *
	 * @param string $slug Chemin.
	 * @return string
	 */
	private static function unique_id( $slug ) {
		$base = sanitize_key( str_replace( '/', '-', $slug ) );
		$base = $base ? $base : 'listing';
		$all  = self::all();
		$id   = $base;
		$i    = 2;

		while ( isset( $all[ $id ] ) ) {
			$id = $base . '-' . $i;
			$i++;
		}

		return $id;
	}

	/**
	 * Paramètres passés à la requête pré-programmée.
	 *
	 * @param mixed $params Données brutes.
	 * @return array
	 */
	private static function sanitize_params( $params ) {
		if ( ! is_array( $params ) ) {
			return array();
		}

		$clean = array();

		foreach ( $params as $param ) {
			$name  = sanitize_text_field( pivot_get( $param, 'name', '' ) );
			$value = sanitize_text_field( pivot_get( $param, 'value', '' ) );

			if ( '' === $name ) {
				continue;
			}

			$clean[] = array( 'name' => $name, 'value' => $value );
		}

		return $clean;
	}

	/**
	 * Nettoie un couple latitude/longitude.
	 *
	 * @param string $value Valeur brute.
	 * @return string
	 */
	private static function sanitize_latlng( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || ! preg_match( '/^-?\d{1,3}(\.\d+)?\s*,\s*-?\d{1,3}(\.\d+)?$/', $value ) ) {
			return '';
		}

		return preg_replace( '/\s+/', '', $value );
	}

	/**
	 * Types de contrôle disponibles pour un filtre.
	 *
	 * @return array
	 */
	public static function filter_types() {
		return array(
			'select'      => __( 'Liste déroulante (un choix)', 'pivot-offres' ),
			'multiselect' => __( 'Cases à cocher (plusieurs choix)', 'pivot-offres' ),
			'text'        => __( 'Saisie libre', 'pivot-offres' ),
			'toggle'      => __( 'Interrupteur oui/non', 'pivot-offres' ),
		);
	}

	/**
	 * Sources de données disponibles pour un filtre.
	 *
	 * @return array
	 */
	public static function filter_sources() {
		return array(
			'type'     => __( 'Type d\'offre', 'pivot-offres' ),
			'locality' => __( 'Localité', 'pivot-offres' ),
			'city'     => __( 'Commune', 'pivot-offres' ),
			'zip'      => __( 'Code postal', 'pivot-offres' ),
			'province' => __( 'Province', 'pivot-offres' ),
			'spec'     => __( 'Champ PIVOT (urn)', 'pivot-offres' ),
		);
	}

	/**
	 * Nettoie la définition des filtres.
	 *
	 * @param mixed $filters Données brutes.
	 * @return array
	 */
	private static function sanitize_filters( $filters ) {
		if ( ! is_array( $filters ) ) {
			return array();
		}

		$types   = self::filter_types();
		$sources = self::filter_sources();
		$clean   = array();
		$used    = array();

		foreach ( $filters as $filter ) {
			$label = sanitize_text_field( pivot_get( $filter, 'label', '' ) );

			if ( '' === $label ) {
				continue;
			}

			$source = pivot_get( $filter, 'source', 'spec' );
			if ( ! isset( $sources[ $source ] ) ) {
				$source = 'spec';
			}

			$urn = sanitize_text_field( pivot_get( $filter, 'urn', '' ) );
			if ( 'spec' === $source && '' === $urn ) {
				continue;
			}

			$type = pivot_get( $filter, 'type', 'select' );
			if ( ! isset( $types[ $type ] ) ) {
				$type = 'select';
			}

			$key = sanitize_key( pivot_get( $filter, 'key', '' ) );

			if ( '' === $key ) {
				$key = sanitize_key(
					'spec' === $source
						? str_replace( ':', '_', str_replace( 'urn:fld:', '', $urn ) )
						: $source
				);
			}

			$base = $key ? $key : 'f';
			$i    = 2;

			while ( in_array( $key, $used, true ) ) {
				$key = $base . '-' . $i;
				$i++;
			}

			$used[] = $key;

			$clean[] = array(
				'key'          => $key,
				'label'        => $label,
				'labels'       => self::sanitize_translations( pivot_get( $filter, 'labels', array() ), 'sanitize_text_field' ),
				'type'         => $type,
				'source'       => $source,
				'urn'          => $urn,
				'value_labels' => self::sanitize_value_labels( pivot_get( $filter, 'value_labels', array() ) ),
				'placeholder'  => sanitize_text_field( pivot_get( $filter, 'placeholder', '' ) ),
			);
		}

		return $clean;
	}

	/**
	 * Traductions imposées des valeurs : lang => texte « valeur|libellé ».
	 *
	 * @param mixed $raw Données brutes.
	 * @return array lang => array( valeur => libellé ).
	 */
	private static function sanitize_value_labels( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();

		foreach ( $raw as $lang => $text ) {
			$lang = Pivot_I18n::code( $lang );

			if ( ! $lang ) {
				continue;
			}

			if ( is_array( $text ) ) {
				foreach ( $text as $value => $label ) {
					$value = sanitize_text_field( $value );
					$label = sanitize_text_field( $label );
					if ( '' !== $value && '' !== $label ) {
						$out[ $lang ][ $value ] = $label;
					}
				}
				continue;
			}

			foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
				$line = trim( $line );

				if ( '' === $line || false === strpos( $line, '|' ) ) {
					continue;
				}

				list( $value, $label ) = array_map( 'trim', explode( '|', $line, 2 ) );
				$value                 = sanitize_text_field( $value );
				$label                 = sanitize_text_field( $label );

				if ( '' !== $value && '' !== $label ) {
					$out[ $lang ][ $value ] = $label;
				}
			}
		}

		return $out;
	}

	/**
	 * URL publique d'une page dans une langue.
	 *
	 * @param array|string $listing Configuration ou identifiant.
	 * @param string|null  $lang    Langue.
	 * @return string
	 */
	public static function url( $listing, $lang = null ) {
		if ( is_string( $listing ) ) {
			$listing = self::get( $listing );
		}

		if ( ! $listing ) {
			return '';
		}

		$lang = $lang ? $lang : Pivot_I18n::current();
		$slug = self::slug( $listing, $lang );

		if ( ! $slug ) {
			return '';
		}

		return Pivot_I18n::url( $slug, $lang );
	}
}
