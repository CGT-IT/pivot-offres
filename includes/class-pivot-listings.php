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
	 * Critères convertis par le dernier enregistrement, voir value_filters().
	 *
	 * @var array
	 */
	private static $converted = array();

	/** @var array|null Liste normalisée, mémoïsée pour la requête en cours. */
	private static $all_memo = null;

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
			'columns'          => 4,
			'show_map'         => 0,
			'map_zoom'         => 9,
			'map_center'       => '',
			'sort_field'       => '',
			'sort_mode'        => 'ASC',
			'search_enabled'   => 1,
			'search_label'     => '',
			'search_labels'    => array(),
			'filters'          => array(),
			'filter_groups'    => array(),
			'cache_ttl'        => 0,
			'intro'            => '',
			'intros'           => array(),
			'image'            => '',
			'image_id'         => 0,
			'seo_title'        => '',
			'seo_titles'       => array(),
			'seo_description'  => '',
			'seo_descriptions' => array(),
			'active'           => 1,
			'index_built'      => 0,
			'index_count'      => 0,
			'index_checked'    => 0,
			'index_changes'    => 0,
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
		// Reconstruit à chaque appel, avec un wp_parse_args par page, alors que
		// la méthode est sollicitée plusieurs fois par requête : à init pour les
		// règles de réécriture, puis à la résolution du contexte, puis par les
		// gabarits et l'en-tête SEO. L'option n'est pas autochargée, donc chaque
		// appel valait aussi une requête SQL.
		if ( null !== self::$all_memo ) {
			return self::$all_memo;
		}

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			self::$all_memo = array();

			return self::$all_memo;
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

		self::$all_memo = $out;

		return $out;
	}

	/**
	 * Oublie la liste mémoïsée, après toute écriture.
	 */
	private static function forget() {
		self::$all_memo = null;
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
	 * Texte d'introduction prêt à afficher : paragraphes et shortcodes.
	 *
	 * L'éditeur enregistre le texte sans balises <p>, comme le contenu d'un
	 * article. Le filtrage passe avant les shortcodes : leur sortie (un
	 * formulaire, un script) n'a pas à le subir.
	 *
	 * @param array       $listing Configuration.
	 * @param string|null $lang    Langue.
	 * @return string
	 */
	public static function intro_html( $listing, $lang = null ) {
		$intro = self::intro( $listing, $lang );

		if ( '' === trim( (string) $intro ) ) {
			return '';
		}

		return do_shortcode( shortcode_unautop( wpautop( wp_kses_post( $intro ) ) ) );
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
	 * Sans traduction saisie, un critère sur un champ PIVOT prend le nom que
	 * PIVOT donne au champ dans cette langue, comme le faisait l'ancien plugin :
	 * « Balade et randonnée » devient « Walk and hike ». Dans la langue par
	 * défaut, le libellé saisi fait toujours foi.
	 *
	 * Appelée à la construction de l'index, en arrière-plan : le thesaurus
	 * peut y être lu sans ralentir l'affichage.
	 *
	 * @param array       $filter Définition du filtre.
	 * @param string|null $lang   Langue.
	 * @return string
	 */
	public static function filter_label( $filter, $lang = null ) {
		$lang   = $lang ? $lang : Pivot_I18n::current();
		$custom = Pivot_I18n::custom( pivot_get( $filter, 'labels', array() ), $lang, '' );

		if ( '' !== $custom ) {
			return $custom;
		}

		$urn = (string) pivot_get( $filter, 'urn', '' );

		// PIVOT ne traduit que dans ses quatre langues : ailleurs, il
		// renverrait le libellé de la langue par défaut, pas une traduction.
		if ( '' !== $urn
			&& 'spec' === pivot_get( $filter, 'source', 'spec' )
			&& Pivot_I18n::default_lang() !== $lang
			&& Pivot_I18n::content_lang( $lang ) === $lang ) {
			$pivot = (string) pivot_get( Pivot_Thesaurus::label_set( Pivot_Fields::base_urn( $urn ) ), $lang, '' );

			if ( '' !== $pivot ) {
				return $pivot;
			}
		}

		return pivot_get( $filter, 'label', pivot_get( $filter, 'key', '' ) );
	}

	/**
	 * Nom d'un groupe de critères dans une langue.
	 *
	 * @param array       $listing Configuration.
	 * @param string      $group   Nom du groupe, dans la langue par défaut.
	 * @param string|null $lang    Langue.
	 * @return string
	 */
	public static function filter_group_label( $listing, $group, $lang = null ) {
		$lang = $lang ? $lang : Pivot_I18n::current();
		return Pivot_I18n::custom( pivot_get( $listing, array( 'filter_groups', $group ), array() ), $lang, $group );
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

		// Image d'en-tête : l'adresse fait foi. Quand elle désigne un fichier de
		// la médiathèque, son identifiant est retenu une fois pour toutes, afin
		// que le gabarit publie ses tailles intermédiaires sans le rechercher à
		// chaque affichage.
		$image = esc_url_raw( trim( (string) $config['image'] ) );

		$filters = self::sanitize_filters( self::value_filters( $config['filters'], $config['filter_groups'] ) );

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
			'columns'          => max( 1, min( 6, (int) $config['columns'] ) ),
			'show_map'         => empty( $config['show_map'] ) ? 0 : 1,
			'map_zoom'         => max( 1, min( 18, (int) $config['map_zoom'] ) ),
			'map_center'       => self::sanitize_latlng( $config['map_center'] ),
			'sort_field'       => sanitize_text_field( $config['sort_field'] ),
			'sort_mode'        => 'DESC' === strtoupper( (string) $config['sort_mode'] ) ? 'DESC' : 'ASC',
			'search_enabled'   => empty( $config['search_enabled'] ) ? 0 : 1,
			'search_label'     => sanitize_text_field( $config['search_label'] ),
			'search_labels'    => self::sanitize_translations( $config['search_labels'], 'sanitize_text_field' ),
			'filters'          => $filters,
			'filter_groups'    => self::sanitize_filter_groups( $config['filter_groups'], $filters ),
			'cache_ttl'        => max( 0, (int) $config['cache_ttl'] ),
			'intro'            => wp_kses_post( $config['intro'] ),
			'intros'           => self::sanitize_translations( $config['intros'], 'wp_kses_post' ),
			'image'            => $image,
			'image_id'         => $image ? (int) attachment_url_to_postid( $image ) : 0,
			'seo_title'        => sanitize_text_field( $config['seo_title'] ),
			'seo_titles'       => self::sanitize_translations( $config['seo_titles'], 'sanitize_text_field' ),
			'seo_description'  => sanitize_textarea_field( $config['seo_description'] ),
			'seo_descriptions' => self::sanitize_translations( $config['seo_descriptions'], 'sanitize_textarea_field' ),
			'active'           => empty( $config['active'] ) ? 0 : 1,
			'index_built'      => $existing ? (int) $existing['index_built'] : 0,
			'index_count'      => $existing ? (int) $existing['index_count'] : 0,
			'index_checked'    => $existing ? (int) $existing['index_checked'] : 0,
			'index_changes'    => $existing ? (int) $existing['index_changes'] : 0,
			'facet_values'     => $existing ? (array) $existing['facet_values'] : array(),
			'offer_types'      => $existing ? (array) $existing['offer_types'] : array(),
			'created'          => $existing && $existing['created'] ? (int) $existing['created'] : time(),
			'updated'          => time(),
		);

		$all        = self::all();
		$all[ $id ] = $clean;

		update_option( self::OPTION, $all, false );

		self::forget();

		Pivot_Rewrites::instance()->schedule_flush();

		// Tout ce que l'index recopie doit provoquer sa reconstruction. Le titre,
		// le nombre d'offres par page et l'affichage de la carte sont écrits dans
		// le fichier d'index et relus par le JavaScript : sans eux dans cette
		// liste, modifier le titre d'une page laissait l'ancien s'afficher
		// jusqu'à l'expiration du cache, six heures plus tard par défaut.
		//
		// Les groupes de critères n'en font pas partie : les gabarits les lisent
		// dans la configuration, au moment d'afficher les critères.
		$structure_changed = ! $existing
			|| $existing['query_code'] !== $clean['query_code']
			|| wp_json_encode( $existing['query_params'] ) !== wp_json_encode( $clean['query_params'] )
			|| (int) $existing['content'] !== (int) $clean['content']
			|| (int) $existing['per_page'] !== (int) $clean['per_page']
			|| (int) $existing['show_map'] !== (int) $clean['show_map']
			|| $existing['title'] !== $clean['title']
			|| wp_json_encode( $existing['titles'] ) !== wp_json_encode( $clean['titles'] )
			|| self::indexed_filters( $existing['filters'] ) !== self::indexed_filters( $clean['filters'] );

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

		self::forget();

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

		self::forget();
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
			'range'       => __( 'Nombre à comparer', 'pivot-offres' ),
			'date'        => __( 'Date ou période', 'pivot-offres' ),
		);
	}

	/**
	 * Comparaisons possibles pour un critère de date.
	 *
	 * Mêmes clés que pour un nombre — les paramètres d'URL s'en déduisent de
	 * la même façon —, mais des mots de calendrier.
	 *
	 * @return array
	 */
	public static function date_operators() {
		return array(
			'between' => __( 'Entre deux dates (du … au …)', 'pivot-offres' ),
			'gte'     => __( 'À partir d\'une date', 'pivot-offres' ),
			'lte'     => __( 'Jusqu\'à une date', 'pivot-offres' ),
			'eq'      => __( 'À une date précise', 'pivot-offres' ),
		);
	}

	/**
	 * Ce qu'un critère de date compare, selon le champ choisi.
	 *
	 * - `overlap` : l'objet date entier (urn:obj:date). Une période retenue
	 *   dès qu'elle touche l'intervalle demandé : l'événement a lieu pendant.
	 * - `start` : la date de début de chaque période.
	 * - `end` : la date de fin de chaque période.
	 * - `point` : une date isolée, comparée telle quelle.
	 *
	 * @param array $filter Définition du filtre.
	 * @return string
	 */
	public static function date_match( $filter ) {
		$urn = Pivot_Fields::base_urn( (string) pivot_get( $filter, 'urn', '' ) );

		if ( 0 === strpos( $urn, 'urn:obj:' ) ) {
			return 'overlap';
		}

		if ( 'urn:fld:date:datedeb' === $urn ) {
			return 'start';
		}

		if ( 'urn:fld:date:datefin' === $urn ) {
			return 'end';
		}

		return 'point';
	}

	/**
	 * Comparaisons possibles pour un critère numérique.
	 *
	 * L'administrateur la choisit : le visiteur ne saisit qu'un nombre, et lit
	 * à côté ce qu'il signifie (« au moins 3 »).
	 *
	 * @return array
	 */
	public static function filter_operators() {
		return array(
			'gte'     => __( 'Au moins (≥)', 'pivot-offres' ),
			'lte'     => __( 'Au plus (≤)', 'pivot-offres' ),
			'between' => __( 'Entre deux valeurs', 'pivot-offres' ),
			'eq'      => __( 'Égal à (=)', 'pivot-offres' ),
		);
	}

	/**
	 * Affichages possibles pour un critère numérique.
	 *
	 * @return array
	 */
	public static function filter_widgets() {
		return array(
			'input'  => __( 'Champ de saisie', 'pivot-offres' ),
			'slider' => __( 'Jauge (curseur)', 'pivot-offres' ),
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
	 * Champ d'une urn de valeur : urn:val:class:3star donne urn:fld:class.
	 *
	 * Même calcul que l'ancien plugin et que Pivot_Legacy_Import.
	 *
	 * @param string $urn Urn saisie.
	 * @return string Urn du champ, ou chaîne vide si ce n'est pas une valeur.
	 */
	public static function value_field( $urn ) {
		$urn = Pivot_Fields::base_urn( trim( (string) $urn ) );

		return preg_match( '/^urn:val:(.+):[^:]+$/', $urn, $matches ) ? 'urn:fld:' . $matches[1] : '';
	}

	/**
	 * Critères convertis par le dernier enregistrement.
	 *
	 * @return array Liste de array( label, from, to ).
	 */
	public static function converted_filters() {
		return self::$converted;
	}

	/**
	 * Ramène sur leur champ les critères posés sur une valeur.
	 *
	 * Un critère « 3 étoiles » sur urn:val:class:3star ne trouve rien : les
	 * offres portent le champ urn:fld:class, dont c'est une valeur. L'ancien
	 * plugin acceptait ces urn, la saisie les reproduit donc naturellement.
	 * Le critère passe sur le champ, une liste dont les valeurs se calculent
	 * à partir des offres ; plusieurs critères du même champ n'en font qu'un.
	 * Il prend le nom de son groupe — « Classement », pour des cases
	 * « 1 étoile », « 2 étoiles » — ou, sans groupe, celui du champ PIVOT.
	 *
	 * @param mixed $filters Critères bruts.
	 * @param mixed $groups  Traductions des groupes, nom => langue => libellé.
	 * @return mixed
	 */
	private static function value_filters( $filters, $groups ) {
		self::$converted = array();

		if ( ! is_array( $filters ) ) {
			return $filters;
		}

		$groups  = is_array( $groups ) ? $groups : array();
		$default = Pivot_I18n::default_lang();
		$out     = array();
		$merged  = array();

		foreach ( $filters as $filter ) {
			$field = is_array( $filter ) && 'spec' === pivot_get( $filter, 'source', 'spec' )
				? self::value_field( pivot_get( $filter, 'urn', '' ) )
				: '';

			if ( '' === $field ) {
				$out[] = $filter;
				continue;
			}

			self::$converted[] = array(
				'label' => sanitize_text_field( pivot_get( $filter, 'label', '' ) ),
				'from'  => sanitize_text_field( pivot_get( $filter, 'urn', '' ) ),
				'to'    => $field,
			);

			if ( isset( $merged[ $field ] ) ) {
				continue;
			}

			$merged[ $field ] = true;

			$group  = trim( (string) pivot_get( $filter, 'group', '' ) );
			$labels = Pivot_Thesaurus::label_set( $field );

			if ( '' !== $group ) {
				$filter['label']  = $group;
				$filter['labels'] = (array) pivot_get( $groups, $group, array() );
				$filter['group']  = '';

				// Groupe non traduit mais nommé comme le champ : les
				// traductions de PIVOT conviennent.
				if ( ! array_filter( $filter['labels'] ) && $labels && 0 === strcasecmp( $group, Pivot_I18n::pick( $labels, $default ) ) ) {
					$filter['labels'] = array_diff_key( $labels, array( $default => true ) );
				}
			} elseif ( $labels ) {
				$filter['label']  = Pivot_I18n::pick( $labels, $default );
				$filter['labels'] = array_diff_key( $labels, array( $default => true ) );
			}

			$filter['urn']          = $field;
			$filter['key']          = ''; // Recalculée d'après le champ.
			$filter['value_labels'] = array();

			if ( ! in_array( pivot_get( $filter, 'type', '' ), array( 'select', 'multiselect' ), true ) ) {
				$filter['type'] = 'multiselect';
			}

			$out[] = $filter;
		}

		return $out;
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
						? str_replace( ':', '_', str_replace( array( 'urn:fld:', 'urn:obj:' ), '', $urn ) )
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

			$entry = array(
				'key'          => $key,
				'label'        => $label,
				'labels'       => self::sanitize_translations( pivot_get( $filter, 'labels', array() ), 'sanitize_text_field' ),
				'group'        => sanitize_text_field( pivot_get( $filter, 'group', '' ) ),
				'type'         => $type,
				'source'       => $source,
				'urn'          => $urn,
				'value_labels' => self::sanitize_value_labels( pivot_get( $filter, 'value_labels', array() ) ),
				'placeholder'  => sanitize_text_field( pivot_get( $filter, 'placeholder', '' ) ),
			);

			if ( 'range' === $type ) {
				$entry = array_merge( $entry, self::sanitize_range( $filter ) );
			}

			if ( 'date' === $type ) {
				// L'écran d'édition pose la comparaison d'une date dans son propre
				// champ : celui des nombres, masqué, arrive aussi dans l'envoi.
				$operator = pivot_get( $filter, 'date_operator', pivot_get( $filter, 'operator', 'between' ) );

				$entry['operator'] = isset( self::date_operators()[ $operator ] ) ? $operator : 'between';
			}

			$clean[] = $entry;
		}

		return $clean;
	}

	/**
	 * Traductions des groupes de critères.
	 *
	 * Seuls les groupes que portent encore des critères sont gardés : un groupe
	 * renommé ou vidé ne laisse pas de traductions orphelines.
	 *
	 * @param mixed $groups  Nom du groupe => traductions lang => texte.
	 * @param array $filters Critères nettoyés.
	 * @return array
	 */
	private static function sanitize_filter_groups( $groups, $filters ) {
		$named = array();

		foreach ( is_array( $groups ) ? $groups : array() as $name => $labels ) {
			$named[ sanitize_text_field( (string) $name ) ] = $labels;
		}

		$clean = array();

		foreach ( $filters as $filter ) {
			$group = $filter['group'];

			if ( '' === $group || ! isset( $named[ $group ] ) || isset( $clean[ $group ] ) ) {
				continue;
			}

			$labels = self::sanitize_translations( $named[ $group ], 'sanitize_text_field' );

			if ( $labels ) {
				$clean[ $group ] = $labels;
			}
		}

		return $clean;
	}

	/**
	 * Critères tels que l'index les recopie, pour savoir s'il faut le
	 * reconstruire : sans leur groupe, qui ne sert qu'à l'affichage.
	 *
	 * @param array $filters Critères.
	 * @return string
	 */
	private static function indexed_filters( $filters ) {
		$out = array();

		foreach ( (array) $filters as $filter ) {
			unset( $filter['group'] );
			$out[] = $filter;
		}

		return (string) wp_json_encode( $out );
	}

	/**
	 * Réglages propres à un critère numérique.
	 *
	 * @param array $filter Données brutes.
	 * @return array
	 */
	private static function sanitize_range( $filter ) {
		$operator = pivot_get( $filter, 'operator', 'gte' );
		if ( ! isset( self::filter_operators()[ $operator ] ) ) {
			$operator = 'gte';
		}

		// Une jauge ne vise pas une valeur exacte : elle glisse d'une valeur à
		// l'autre, et « égal à 37 » y serait introuvable.
		$widget = pivot_get( $filter, 'widget', 'input' );
		if ( ! isset( self::filter_widgets()[ $widget ] ) || 'eq' === $operator ) {
			$widget = 'input';
		}

		return array(
			'operator' => $operator,
			'widget'   => $widget,
			'unit'     => mb_substr( sanitize_text_field( pivot_get( $filter, 'unit', '' ) ), 0, 12 ),
		);
	}

	/**
	 * Clés d'URL d'un critère.
	 *
	 * Un critère numérique en porte deux, une par borne : ?prix_max=50,
	 * ?chambres_min=3. L'égalité garde la clé nue : ?etoiles=4. Un critère de
	 * date suit la même règle : ?date_min=2026-10-01&date_max=2026-10-31.
	 *
	 * @param array $filter Définition du filtre.
	 * @return array Borne (min, max, eq) => nom du paramètre.
	 */
	public static function range_params( $filter ) {
		$key = (string) pivot_get( $filter, 'key', '' );

		switch ( pivot_get( $filter, 'operator', 'gte' ) ) {
			case 'eq':
				return array( 'eq' => $key );
			case 'lte':
				return array( 'max' => $key . '_max' );
			case 'between':
				return array( 'min' => $key . '_min', 'max' => $key . '_max' );
			default:
				return array( 'min' => $key . '_min' );
		}
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
