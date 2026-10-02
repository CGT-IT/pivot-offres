<?php
/**
 * Écran de création et de modification d'une page de listing.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Listing_Edit {

	/** @var Pivot_Listing_Edit|null */
	private static $instance = null;

	/**
	 * @return Pivot_Listing_Edit
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'handle_save' ) );
	}

	/**
	 * Enregistre le formulaire.
	 */
	public function handle_save() {
		if ( ! isset( $_POST['pivot_listing_nonce'] ) || ! current_user_can( pivot_capability() ) ) {
			return;
		}

		check_admin_referer( 'pivot_save_listing', 'pivot_listing_nonce' );

		$raw = isset( $_POST['pivot_listing'] ) ? wp_unslash( $_POST['pivot_listing'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! is_array( $raw ) ) {
			return;
		}

		$config = array(
			'id'               => isset( $raw['id'] ) ? sanitize_key( $raw['id'] ) : '',
			'title'            => pivot_get( $raw, 'title', '' ),
			'titles'           => (array) pivot_get( $raw, 'titles', array() ),
			'slug'             => pivot_get( $raw, 'slug', '' ),
			'slugs'            => (array) pivot_get( $raw, 'slugs', array() ),
			'query_code'       => pivot_get( $raw, 'query_code', '' ),
			'content'          => (int) pivot_get( $raw, 'content', 2 ),
			'per_page'         => (int) pivot_get( $raw, 'per_page', 12 ),
			'columns'          => (int) pivot_get( $raw, 'columns', 4 ),
			'show_map'         => ! empty( $raw['show_map'] ),
			'map_zoom'         => (int) pivot_get( $raw, 'map_zoom', 9 ),
			'map_center'       => pivot_get( $raw, 'map_center', '' ),
			'sort_field'       => pivot_get( $raw, 'sort_field', '' ),
			'sort_mode'        => pivot_get( $raw, 'sort_mode', 'ASC' ),
			'search_enabled'   => ! empty( $raw['search_enabled'] ),
			'search_label'     => pivot_get( $raw, 'search_label', '' ),
			'search_labels'    => (array) pivot_get( $raw, 'search_labels', array() ),
			'cache_ttl'        => (int) pivot_get( $raw, 'cache_ttl', 0 ) * MINUTE_IN_SECONDS,
			'intro'            => pivot_get( $raw, 'intro', '' ),
			'intros'           => (array) pivot_get( $raw, 'intros', array() ),
			'image'            => pivot_get( $raw, 'image', '' ),
			'seo_title'        => pivot_get( $raw, 'seo_title', '' ),
			'seo_titles'       => (array) pivot_get( $raw, 'seo_titles', array() ),
			'seo_description'  => pivot_get( $raw, 'seo_description', '' ),
			'seo_descriptions' => (array) pivot_get( $raw, 'seo_descriptions', array() ),
			'active'           => ! empty( $raw['active'] ),
			'filters'          => isset( $raw['filters'] ) && is_array( $raw['filters'] ) ? array_values( $raw['filters'] ) : array(),
			'filter_groups'    => self::group_labels( pivot_get( $raw, 'filter_groups', array() ) ),
			'query_params'     => $this->parse_params( pivot_get( $raw, 'query_params', '' ) ),
		);

		$saved = Pivot_Listings::save( $config );

		if ( is_wp_error( $saved ) ) {
			add_settings_error( 'pivot_listing', 'save_failed', $saved->get_error_message(), 'error' );
			return;
		}

		// Critères ramenés sur leur champ : expliqué après la redirection.
		$converted = Pivot_Listings::converted_filters();

		if ( $converted ) {
			set_transient( 'pivot_listing_converted_' . get_current_user_id(), $converted, 5 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'pivot-listing-edit',
					'id'      => $saved,
					'message' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Transforme « nom=valeur » (une par ligne) en tableau de paramètres.
	 *
	 * @param string $raw Texte saisi.
	 * @return array
	 */
	private function parse_params( $raw ) {
		$out = array();

		foreach ( (array) preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || false === strpos( $line, '=' ) ) {
				continue;
			}

			list( $name, $value ) = array_map( 'trim', explode( '=', $line, 2 ) );

			if ( '' !== $name ) {
				$out[] = array( 'name' => $name, 'value' => $value );
			}
		}

		return $out;
	}

	/**
	 * Affiche l'écran.
	 */
	public static function render() {
		if ( ! current_user_can( pivot_capability() ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas accès à cet écran.', 'pivot-offres' ) );
		}

		$id      = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		$listing = $id ? Pivot_Listings::get( $id ) : null;
		$is_new  = ! $listing;

		if ( ! $listing ) {
			$listing = Pivot_Listings::defaults();
		}

		echo '<div class="wrap pivot-wrap pivot-listing-edit">';
		echo '<h1>' . esc_html( $is_new ? __( 'Ajouter une page de listing', 'pivot-offres' ) : __( 'Modifier la page de listing', 'pivot-offres' ) ) . '</h1>';

		settings_errors( 'pivot_listing' );

		if ( isset( $_GET['message'] ) && 'saved' === $_GET['message'] ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Page enregistrée.', 'pivot-offres' ) . '</p></div>';
			self::converted_notice();
		}

		self::section_nav( $is_new );

		echo '<form method="post" id="pivot-listing-form" action="' . esc_url( admin_url( 'admin.php?page=pivot-listing-edit' ) ) . '">';
		wp_nonce_field( 'pivot_save_listing', 'pivot_listing_nonce' );
		printf( '<input type="hidden" name="pivot_listing[id]" value="%s" />', esc_attr( $listing['id'] ) );

		self::section_general( $listing, $is_new );
		self::section_source( $listing );
		self::section_display( $listing );
		self::section_filters( $listing );
		self::section_seo( $listing );
		self::section_translations( $listing );

		submit_button( $is_new ? __( 'Créer la page', 'pivot-offres' ) : __( 'Enregistrer les modifications', 'pivot-offres' ) );

		echo '</form>';

		if ( ! $is_new ) {
			self::section_index_status( $listing );
			self::section_shortcode( $listing );
		}

		echo '</div>';
	}

	/**
	 * Explique les critères posés sur une valeur et ramenés sur leur champ.
	 */
	private static function converted_notice() {
		$key       = 'pivot_listing_converted_' . get_current_user_id();
		$converted = get_transient( $key );

		if ( ! $converted || ! is_array( $converted ) ) {
			return;
		}

		delete_transient( $key );

		echo '<div class="notice notice-warning"><p>';
		esc_html_e( 'Certains critères visaient une valeur PIVOT plutôt qu\'un champ : ils ne pouvaient rien trouver, les offres portant le champ et non la valeur. Ils ont été ramenés sur leur champ, dont les valeurs se calculent à partir des offres :', 'pivot-offres' );
		echo '</p><ul class="ul-disc">';

		foreach ( $converted as $entry ) {
			printf(
				'<li>%1$s <code>%2$s</code> → <code>%3$s</code></li>',
				esc_html( pivot_get( $entry, 'label', '' ) ),
				esc_html( pivot_get( $entry, 'from', '' ) ),
				esc_html( pivot_get( $entry, 'to', '' ) )
			);
		}

		echo '</ul><p>' . esc_html__( 'Les critères d\'un même champ n\'en forment plus qu\'un : choisir « 3 étoiles » se fait désormais dans sa liste.', 'pivot-offres' ) . '</p></div>';
	}

	/* ---------------------------------------------------------- navigation */

	/**
	 * Sections de l'écran, dans l'ordre d'affichage.
	 *
	 * @param bool $is_new Création ?
	 * @return array Identifiant => intitulé.
	 */
	private static function sections( $is_new ) {
		$sections = array(
			'general'      => __( 'Identité', 'pivot-offres' ),
			'source'       => __( 'Offres affichées', 'pivot-offres' ),
			'display'      => __( 'Mise en page', 'pivot-offres' ),
			'filters'      => __( 'Critères de recherche', 'pivot-offres' ),
			'seo'          => __( 'Référencement', 'pivot-offres' ),
			'translations' => __( 'Traductions', 'pivot-offres' ),
			'index'        => __( 'Index', 'pivot-offres' ),
			'shortcode'    => __( 'Shortcode', 'pivot-offres' ),
		);

		if ( count( Pivot_I18n::enabled() ) < 2 ) {
			unset( $sections['translations'] );
		}

		if ( $is_new ) {
			unset( $sections['index'], $sections['shortcode'] );
		}

		return $sections;
	}

	/**
	 * Bandeau de navigation qui suit le défilement.
	 *
	 * Le bouton d'enregistrement y reste à portée de main ; il vise le
	 * formulaire par son identifiant, le bandeau étant hors de celui-ci pour
	 * rester visible jusqu'en bas de l'écran.
	 *
	 * @param bool $is_new Création ?
	 */
	private static function section_nav( $is_new ) {
		echo '<nav class="pivot-section-nav" aria-label="' . esc_attr__( 'Sections de la page', 'pivot-offres' ) . '"><ul>';

		foreach ( self::sections( $is_new ) as $id => $label ) {
			printf(
				'<li><a href="#pivot-section-%1$s">%2$s</a></li>',
				esc_attr( $id ),
				esc_html( $label )
			);
		}

		echo '</ul><div class="pivot-section-nav-actions">';
		printf(
			'<button type="button" class="button-link pivot-sections-toggle" data-collapse="%1$s" data-expand="%2$s">%1$s</button>',
			esc_attr__( 'Tout replier', 'pivot-offres' ),
			esc_attr__( 'Tout déplier', 'pivot-offres' )
		);
		printf(
			'<button type="submit" form="pivot-listing-form" class="button button-primary">%s</button>',
			esc_html( $is_new ? __( 'Créer la page', 'pivot-offres' ) : __( 'Enregistrer', 'pivot-offres' ) )
		);
		echo '</div></nav>';
	}

	/**
	 * Ouvre une section repliable.
	 *
	 * Repliée, la section garde sa mise en page (hauteur nulle, contenu
	 * invisible) plutôt que display: none : l'éditeur visuel, initialisé
	 * dans une iframe, ne supporte pas d'être construit hors de la page.
	 *
	 * @param string $id        Identifiant, tel que dans sections().
	 * @param string $title     Intitulé.
	 * @param string $meta      Résumé affiché à côté de l'intitulé.
	 * @param bool   $collapsed Repliée par défaut, tant que l'utilisateur n'a rien choisi.
	 */
	private static function section_open( $id, $title, $meta = '', $collapsed = false ) {
		printf(
			'<section class="pivot-section" id="pivot-section-%1$s" data-section="%1$s"%2$s>',
			esc_attr( $id ),
			$collapsed ? ' data-default="collapsed"' : ''
		);
		printf(
			'<h2 class="pivot-section-title"><button type="button" class="pivot-section-toggle" aria-expanded="true" aria-controls="pivot-section-body-%1$s"><span class="pivot-section-label">%2$s</span>%3$s<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button></h2>',
			esc_attr( $id ),
			esc_html( $title ),
			'' !== $meta ? '<span class="pivot-section-meta">' . esc_html( $meta ) . '</span>' : ''
		);
		printf( '<div class="pivot-section-body" id="pivot-section-body-%s">', esc_attr( $id ) );
	}

	/**
	 * Ferme une section ouverte par section_open().
	 */
	private static function section_close() {
		echo '</div></section>';
	}

	/* ------------------------------------------------------------ sections */

	/**
	 * Identité de la page.
	 *
	 * @param array $listing Configuration.
	 * @param bool  $is_new  Création ?
	 */
	private static function section_general( $listing, $is_new ) {
		$default = Pivot_I18n::default_lang();

		self::section_open( 'general', __( 'Identité de la page', 'pivot-offres' ) );

		if ( Pivot_I18n::is_multilingual() ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s : nom de la langue par défaut. */
						__( 'Ces champs concernent la langue par défaut (%s). Les autres langues se remplissent plus bas, dans la section Traductions.', 'pivot-offres' ),
						Pivot_I18n::name( $default )
					)
				)
			);
		}

		echo '<table class="form-table" role="presentation"><tbody>';

		self::row(
			__( 'Titre', 'pivot-offres' ),
			sprintf(
				'<input type="text" name="pivot_listing[title]" value="%s" class="regular-text" required />',
				esc_attr( $listing['title'] )
			),
			__( 'Affiché en haut de la page et repris par défaut comme titre pour les moteurs de recherche.', 'pivot-offres' )
		);

		self::row(
			__( 'URL', 'pivot-offres' ),
			sprintf(
				'<code>%s</code><input type="text" name="pivot_listing[slug]" value="%s" class="regular-text" placeholder="hebergements" />',
				esc_html( trailingslashit( home_url() ) ),
				esc_attr( $listing['slug'] )
			),
			__( 'Un ou plusieurs segments, par exemple sejourner/hotels. L\'adresse est créée par le plugin : aucune page WordPress à publier.', 'pivot-offres' )
		);

		self::row(
			__( 'Publication', 'pivot-offres' ),
			sprintf(
				'<label><input type="checkbox" name="pivot_listing[active]" value="1"%s /> %s</label>',
				checked( (int) $listing['active'], 1, false ),
				esc_html__( 'Rendre cette page accessible aux visiteurs', 'pivot-offres' )
			)
		);

		if ( ! $is_new ) {
			$links = array();

			foreach ( Pivot_I18n::enabled() as $lang ) {
				$url = Pivot_Listings::url( $listing, $lang );

				if ( ! $url ) {
					continue;
				}

				$links[] = sprintf(
					'<a href="%1$s" target="_blank" rel="noopener" class="button">%2$s</a>',
					esc_url( $url ),
					esc_html( sprintf( '%s — %s', __( 'Ouvrir', 'pivot-offres' ), strtoupper( $lang ) ) )
				);
			}

			self::row( __( 'Aperçu', 'pivot-offres' ), implode( ' ', $links ) );
		}

		echo '</tbody></table>';

		self::section_close();
	}

	/**
	 * Source des offres.
	 *
	 * @param array $listing Configuration.
	 */
	private static function section_source( $listing ) {
		self::section_open( 'source', __( 'Offres affichées', 'pivot-offres' ), (string) $listing['query_code'] );
		echo '<table class="form-table" role="presentation"><tbody>';

		self::row(
			__( 'Code de requête', 'pivot-offres' ),
			sprintf(
				'<input type="text" name="pivot_listing[query_code]" value="%s" class="regular-text code" placeholder="QRY-00-0000-0000" required />',
				esc_attr( $listing['query_code'] )
			),
			__( 'Code de la requête pré-programmée créée dans PIVOT. C\'est elle qui détermine quelles offres apparaissent ici.', 'pivot-offres' )
		);

		$params = array();

		foreach ( (array) $listing['query_params'] as $param ) {
			$params[] = pivot_get( $param, 'name', '' ) . '=' . pivot_get( $param, 'value', '' );
		}

		self::row(
			__( 'Paramètres de la requête', 'pivot-offres' ),
			sprintf(
				'<textarea name="pivot_listing[query_params]" rows="3" class="large-text code" placeholder="radius=10">%s</textarea>',
				esc_textarea( implode( "\n", $params ) )
			),
			__( 'Une paire nom=valeur par ligne, si la requête PIVOT attend des paramètres variables (long, lat, radius…).', 'pivot-offres' )
		);

		$content_options = array(
			1 => __( 'Résumé — nom, adresse, géolocalisation, média par défaut', 'pivot-offres' ),
			2 => __( 'Complet — tous les champs de l\'offre, nécessaire pour filtrer sur un champ PIVOT', 'pivot-offres' ),
			3 => __( 'Complet avec offres liées — met aussi en cache les fiches détail', 'pivot-offres' ),
		);

		$select = '<select name="pivot_listing[content]">';

		foreach ( $content_options as $value => $label ) {
			$select .= sprintf(
				'<option value="%d"%s>%s</option>',
				$value,
				selected( (int) $listing['content'], $value, false ),
				esc_html( $label )
			);
		}

		$select .= '</select>';

		self::row(
			__( 'Richesse des données', 'pivot-offres' ),
			$select,
			__( 'Le mode résumé construit l\'index plus vite ; le mode complet est indispensable dès qu\'un filtre porte sur un champ PIVOT. Avec les offres liées, la construction est environ trois fois plus longue, mais chaque fiche détail est déjà en cache à la première visite.', 'pivot-offres' )
			. ' ' . __( 'Les fermetures d\'un itinéraire (zones de fermeture : chasse, travaux…) ne s\'affichent sur les vignettes qu\'avec les offres liées : en deçà, PIVOT n\'en donne que le code.', 'pivot-offres' )
		);

		self::row(
			__( 'Tri', 'pivot-offres' ),
			sprintf(
				'<input type="text" name="pivot_listing[sort_field]" value="%s" class="regular-text code" placeholder="urn:fld:nomofr" /> <select name="pivot_listing[sort_mode]"><option value="ASC"%s>%s</option><option value="DESC"%s>%s</option></select>',
				esc_attr( $listing['sort_field'] ),
				selected( $listing['sort_mode'], 'ASC', false ),
				esc_html__( 'croissant', 'pivot-offres' ),
				selected( $listing['sort_mode'], 'DESC', false ),
				esc_html__( 'décroissant', 'pivot-offres' )
			),
			__( 'Urn du champ de tri. Laissez vide pour conserver l\'ordre renvoyé par PIVOT.', 'pivot-offres' )
		);

		$minutes = (int) $listing['cache_ttl'] > 0 ? (int) round( $listing['cache_ttl'] / MINUTE_IN_SECONDS ) : 0;

		self::row(
			__( 'Durée de cache propre', 'pivot-offres' ),
			sprintf(
				'<input type="number" name="pivot_listing[cache_ttl]" value="%d" min="0" class="small-text" /> %s',
				$minutes,
				esc_html__( 'minutes', 'pivot-offres' )
			),
			__( 'Laissez 0 pour utiliser la durée définie dans les réglages généraux.', 'pivot-offres' )
		);

		echo '</tbody></table>';

		self::section_close();
	}

	/**
	 * Mise en page.
	 *
	 * @param array $listing Configuration.
	 */
	private static function section_display( $listing ) {
		self::section_open( 'display', __( 'Mise en page', 'pivot-offres' ) );
		echo '<table class="form-table" role="presentation"><tbody>';

		self::row(
			__( 'Offres par page', 'pivot-offres' ),
			sprintf(
				'<input type="number" name="pivot_listing[per_page]" value="%d" min="1" max="100" class="small-text" />',
				(int) $listing['per_page']
			),
			__( 'La pagination est gérée dans le navigateur : changer de page ne déclenche aucun appel à PIVOT.', 'pivot-offres' )
		);

		$select = '<select name="pivot_listing[columns]">';

		for ( $count = 1; $count <= 6; $count++ ) {
			$select .= sprintf(
				'<option value="%1$d"%2$s>%1$d</option>',
				$count,
				selected( (int) $listing['columns'], $count, false )
			);
		}

		$select .= '</select>';

		self::row(
			__( 'Colonnes', 'pivot-offres' ),
			$select,
			__( 'Vignettes par ligne sur un grand écran, à partir de 1200 pixels de large. En dessous, la grille se resserre : trois colonnes au plus sur un écran moyen, deux sur tablette, une sur mobile. Un nombre d\'offres par page multiple du nombre de colonnes remplit toutes les lignes.', 'pivot-offres' )
		);

		self::row(
			__( 'Carte', 'pivot-offres' ),
			sprintf(
				'<label><input type="checkbox" name="pivot_listing[show_map]" value="1"%s /> %s</label>',
				checked( (int) $listing['show_map'], 1, false ),
				esc_html__( 'Afficher une carte qui pointe les offres de la page', 'pivot-offres' )
			),
			__( 'Seules les offres géolocalisées apparaissent sur la carte ; les autres restent dans la liste.', 'pivot-offres' )
		);

		self::row(
			__( 'Cadrage de la carte', 'pivot-offres' ),
			sprintf(
				'<input type="text" name="pivot_listing[map_center]" value="%s" class="regular-text" placeholder="50.4674,4.8720" /> <input type="number" name="pivot_listing[map_zoom]" value="%d" min="1" max="18" class="small-text" />',
				esc_attr( $listing['map_center'] ),
				(int) $listing['map_zoom']
			),
			__( 'Latitude, longitude et niveau de zoom initial. Laissez le centre vide pour cadrer automatiquement sur les offres affichées.', 'pivot-offres' )
		);

		self::row(
			__( 'Recherche libre', 'pivot-offres' ),
			sprintf(
				'<label><input type="checkbox" name="pivot_listing[search_enabled]" value="1"%s /> %s</label><br /><input type="text" name="pivot_listing[search_label]" value="%s" class="regular-text" placeholder="%s" />',
				checked( (int) $listing['search_enabled'], 1, false ),
				esc_html__( 'Proposer un champ de recherche', 'pivot-offres' ),
				esc_attr( $listing['search_label'] ),
				esc_attr__( 'Rechercher une offre, une localité…', 'pivot-offres' )
			)
		);

		$image = (string) pivot_get( $listing, 'image', '' );

		// Aperçu sans adresse quand il n'y a pas d'image : un src vide ne doit
		// rien charger.
		$preview = $image
			? sprintf( '<img class="pivot-image-preview" src="%s" alt="" />', esc_url( $image ) )
			: '<img class="pivot-image-preview" alt="" hidden />';

		self::row(
			__( 'Image d\'en-tête', 'pivot-offres' ),
			sprintf(
				'<div class="pivot-image-field"><input type="url" name="pivot_listing[image]" value="%1$s" class="large-text code pivot-image-url" placeholder="https://" /><span class="pivot-image-actions"><button type="button" class="button pivot-image-pick">%2$s</button> <button type="button" class="button-link pivot-image-clear">%3$s</button></span>%4$s</div>',
				esc_attr( $image ),
				esc_html__( 'Choisir dans la médiathèque', 'pivot-offres' ),
				esc_html__( 'Retirer l\'image', 'pivot-offres' ),
				$preview
			),
			__( 'Bandeau pleine largeur affiché au-dessus du titre, repris comme image de partage sur les réseaux sociaux. Format conseillé : 1920 × 400 pixels.', 'pivot-offres' )
		);

		self::editor_row(
			__( 'Texte d\'introduction', 'pivot-offres' ),
			'pivot_listing[intro]',
			$listing['intro'],
			8,
			__( 'Affiché sous le titre, avant les critères de recherche. Les shortcodes, par exemple <code>[wpforms id="12"]</code>, y sont interprétés.', 'pivot-offres' )
		);

		echo '</tbody></table>';

		self::section_close();
	}

	/**
	 * Critères de recherche.
	 *
	 * @param array $listing Configuration.
	 */
	private static function section_filters( $listing ) {
		$types   = Pivot_Listings::filter_types();
		$sources = Pivot_Listings::filter_sources();
		$filters = (array) $listing['filters'];
		$facets  = (array) $listing['facet_values'];

		self::section_open(
			'filters',
			__( 'Critères de recherche', 'pivot-offres' ),
			/* translators: %d : nombre de critères. */
			sprintf( _n( '%d critère', '%d critères', count( $filters ), 'pivot-offres' ), count( $filters ) )
		);
		echo '<p class="description">' . esc_html__( 'Chaque critère devient un contrôle affiché au-dessus des résultats. Les valeurs proposées sont calculées à partir des offres de la page, avec les libellés traduits que PIVOT renvoie.', 'pivot-offres' ) . '</p>';

		self::section_suggestions( $listing );

		echo '<div class="pivot-filters" data-next-index="' . esc_attr( count( $filters ) ) . '">';

		foreach ( $filters as $index => $filter ) {
			self::filter_row( $index, $filter, $types, $sources, $facets );
		}

		echo '</div>';

		// Datalist commune : l'autocomplétion du navigateur sur le champ urn.
		echo '<datalist id="pivot-urn-suggestions"></datalist>';

		// Groupes déjà utilisés, proposés dans chaque critère ; le script tient
		// la liste à jour pendant la saisie.
		$groups = array();

		foreach ( $filters as $filter ) {
			$group = (string) pivot_get( $filter, 'group', '' );

			if ( '' !== $group && ! in_array( $group, $groups, true ) ) {
				$groups[] = $group;
			}
		}

		echo '<datalist id="pivot-group-suggestions">';

		foreach ( $groups as $group ) {
			printf( '<option value="%s"></option>', esc_attr( $group ) );
		}

		echo '</datalist>';

		echo '<p class="pivot-filter-buttons">';
		echo '<button type="button" class="button pivot-add-filter">' . esc_html__( 'Ajouter un critère sur mesure', 'pivot-offres' ) . '</button> ';
		echo '<button type="button" class="button-link pivot-tour-start-filters">' . esc_html__( 'Comment régler un critère ?', 'pivot-offres' ) . '</button>';
		echo '</p>';

		self::section_groups( $listing, $groups );

		// Gabarit repris par le JavaScript pour les nouvelles lignes.
		echo '<script type="text/template" id="pivot-filter-template">';
		self::filter_row( '__index__', array(), $types, $sources, array() );
		echo '</script>';

		self::section_close();
	}

	/**
	 * Groupes de critères et leurs traductions.
	 *
	 * Le nom d'un groupe se saisit dans chacun de ses critères ; sa traduction
	 * une seule fois ici, pour tous.
	 *
	 * @param array $listing Configuration.
	 * @param array $groups  Groupes utilisés, dans l'ordre des critères.
	 */
	private static function section_groups( $listing, $groups ) {
		$langs   = Pivot_I18n::enabled();
		$default = Pivot_I18n::default_lang();
		$others  = array_values( array_diff( $langs, array( $default ) ) );

		echo '<div class="pivot-groups">';
		echo '<h3>' . esc_html__( 'Groupes de critères', 'pivot-offres' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Les critères qui portent le même groupe s\'affichent ensemble, sous son nom, à la place du premier d\'entre eux. Pratique pour réunir des cases à cocher : équipements, services, accessibilité…', 'pivot-offres' ) . '</p>';

		if ( ! $groups || ! $others ) {
			echo '</div>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Traduisez ici le nom de chaque groupe. Un groupe ajouté dans un critère apparaît dans ce tableau après enregistrement.', 'pivot-offres' ) . '</p>';
		echo '<table class="widefat striped pivot-groups-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Groupe', 'pivot-offres' ) . '</th>';

		foreach ( $others as $lang ) {
			printf( '<th scope="col">%s</th>', esc_html( strtoupper( $lang ) ) );
		}

		echo '</tr></thead><tbody>';

		foreach ( $groups as $index => $group ) {
			$name = 'pivot_listing[filter_groups][' . $index . ']';

			printf(
				'<tr><th scope="row">%1$s<input type="hidden" name="%2$s[name]" value="%3$s" /></th>',
				esc_html( $group ),
				esc_attr( $name ),
				esc_attr( $group )
			);

			foreach ( $others as $lang ) {
				printf(
					'<td><input type="text" name="%1$s[labels][%2$s]" value="%3$s" placeholder="%4$s" aria-label="%5$s" /></td>',
					esc_attr( $name ),
					esc_attr( $lang ),
					esc_attr( pivot_get( $listing, array( 'filter_groups', $group, $lang ), '' ) ),
					esc_attr( $group ),
					esc_attr( $group . ' (' . strtoupper( $lang ) . ')' )
				);
			}

			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Traductions des groupes, du formulaire vers la configuration.
	 *
	 * @param mixed $rows Lignes envoyées : name, labels.
	 * @return array Nom du groupe => traductions.
	 */
	private static function group_labels( $rows ) {
		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$group = trim( (string) pivot_get( $row, 'name', '' ) );

			if ( '' !== $group ) {
				$out[ $group ] = (array) pivot_get( $row, 'labels', array() );
			}
		}

		return $out;
	}

	/**
	 * Panneau des critères suggérés.
	 *
	 * Le contenu est calculé côté serveur à la demande : on ne pose ici que le
	 * réceptacle et son bouton de rafraîchissement.
	 *
	 * @param array $listing Configuration.
	 */
	private static function section_suggestions( $listing ) {
		$id = pivot_get( $listing, 'id', '' );

		echo '<div class="pivot-suggestions" data-listing="' . esc_attr( $id ) . '">';

		echo '<div class="pivot-suggestions-head">';
		echo '<h3>' . esc_html__( 'Critères suggérés', 'pivot-offres' ) . '</h3>';

		if ( $id ) {
			printf(
				'<button type="button" class="button-link pivot-suggestions-refresh">%s</button>',
				esc_html__( 'Réanalyser les offres', 'pivot-offres' )
			);
		}

		echo '</div>';

		if ( ! $id ) {
			echo '<p class="pivot-suggestions-empty">'
				. esc_html__( 'Enregistrez la page une première fois : les critères possibles seront déduits des offres que votre requête renvoie.', 'pivot-offres' )
				. '</p>';
		} else {
			echo '<p class="pivot-suggestions-status">' . esc_html__( 'Analyse des offres…', 'pivot-offres' ) . '</p>';
			echo '<div class="pivot-suggestions-list"></div>';
		}

		echo '</div>';
	}

	/**
	 * Une ligne de critère.
	 *
	 * @param int|string $index   Position.
	 * @param array      $filter  Données.
	 * @param array      $types   Types disponibles.
	 * @param array      $sources Sources disponibles.
	 * @param array      $facets  Valeurs découvertes lors du dernier index.
	 */
	private static function filter_row( $index, $filter, $types, $sources, $facets ) {
		$name    = 'pivot_listing[filters][' . $index . ']';
		$key     = pivot_get( $filter, 'key', '' );
		$default = Pivot_I18n::default_lang();

		echo '<div class="pivot-filter-row">';
		echo '<div class="pivot-filter-grid">';

		printf(
			'<label>%s<input type="text" name="%s[label]" value="%s" placeholder="%s" /></label>',
			esc_html__( 'Libellé', 'pivot-offres' ),
			esc_attr( $name ),
			esc_attr( pivot_get( $filter, 'label', '' ) ),
			esc_attr__( 'Localité', 'pivot-offres' )
		);

		printf(
			'<label>%s<input type="text" name="%s[group]" value="%s" class="pivot-group-input" placeholder="%s" list="pivot-group-suggestions" /></label>',
			esc_html__( 'Groupe', 'pivot-offres' ),
			esc_attr( $name ),
			esc_attr( pivot_get( $filter, 'group', '' ) ),
			esc_attr__( 'Équipements', 'pivot-offres' )
		);

		echo '<label>' . esc_html__( 'Source', 'pivot-offres' );
		printf( '<select name="%s[source]" class="pivot-filter-source">', esc_attr( $name ) );

		foreach ( $sources as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( pivot_get( $filter, 'source', 'spec' ), $value, false ),
				esc_html( $label )
			);
		}

		echo '</select></label>';

		// Masqué d'emblée hors « champ PIVOT », comme le fait ensuite le script.
		printf(
			'<label class="pivot-filter-urn"%5$s>%1$s<input type="text" name="%2$s[urn]" class="pivot-urn-input" value="%3$s" placeholder="urn:fld:catdec" list="pivot-urn-suggestions" />'
			. '<button type="button" class="button-link pivot-pick-field">%4$s</button></label>',
			esc_html__( 'Urn du champ', 'pivot-offres' ),
			esc_attr( $name ),
			esc_attr( pivot_get( $filter, 'urn', '' ) ),
			esc_html__( 'Parcourir les champs disponibles', 'pivot-offres' ),
			'spec' === pivot_get( $filter, 'source', 'spec' ) ? '' : ' hidden'
		);

		echo '<label>' . esc_html__( 'Contrôle', 'pivot-offres' );
		printf( '<select name="%s[type]">', esc_attr( $name ) );

		foreach ( $types as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( pivot_get( $filter, 'type', 'select' ), $value, false ),
				esc_html( $label )
			);
		}

		echo '</select></label>';

		printf(
			'<label>%s<input type="text" name="%s[key]" value="%s" placeholder="%s" /></label>',
			esc_html__( 'Clé d\'URL', 'pivot-offres' ),
			esc_attr( $name ),
			esc_attr( $key ),
			esc_attr__( 'calculée', 'pivot-offres' )
		);

		echo '</div>';

		self::range_options( $name, $filter );
		self::date_options( $name, $filter );

		// Traductions du libellé et des valeurs.
		$langs = Pivot_I18n::enabled();

		if ( count( $langs ) > 1 ) {
			echo '<details class="pivot-filter-i18n">';
			echo '<summary>' . esc_html__( 'Traductions de ce critère', 'pivot-offres' ) . '</summary>';

			echo '<p class="description">' . esc_html__( 'Les valeurs sont traduites par PIVOT. Complétez ci-dessous uniquement ce que vous voulez remplacer : une ligne « valeur|texte » par traduction à corriger.', 'pivot-offres' ) . '</p>';

			foreach ( $langs as $lang ) {
				if ( $lang === $default ) {
					continue;
				}

				$overrides = (array) pivot_get( $filter, array( 'value_labels', $lang ), array() );
				$lines     = array();

				foreach ( $overrides as $value => $label ) {
					$lines[] = $value . '|' . $label;
				}

				echo '<div class="pivot-filter-lang">';
				printf( '<span class="pivot-lang-tag">%s</span>', esc_html( strtoupper( $lang ) ) );

				printf(
					'<label>%s<input type="text" name="%s[labels][%s]" value="%s" placeholder="%s" /></label>',
					esc_html__( 'Libellé', 'pivot-offres' ),
					esc_attr( $name ),
					esc_attr( $lang ),
					esc_attr( pivot_get( $filter, array( 'labels', $lang ), '' ) ),
					esc_attr( pivot_get( $filter, 'label', '' ) )
				);

				printf(
					'<label>%s<textarea name="%s[value_labels][%s]" rows="3" placeholder="%s">%s</textarea></label>',
					esc_html__( 'Traductions de valeurs', 'pivot-offres' ),
					esc_attr( $name ),
					esc_attr( $lang ),
					esc_attr__( 'namur|Namen', 'pivot-offres' ),
					esc_textarea( implode( "\n", $lines ) )
				);

				echo '</div>';
			}

			// Valeurs réellement présentes dans le dernier index.
			if ( $key && ! empty( $facets[ $key ] ) ) {
				echo '<details class="pivot-facet-values">';
				printf(
					'<summary>%s</summary>',
					esc_html(
						sprintf(
							/* translators: %d : nombre de valeurs. */
							__( 'Valeurs disponibles pour ce critère (%d)', 'pivot-offres' ),
							count( $facets[ $key ] )
						)
					)
				);
				echo '<p class="pivot-facet-list">';

				foreach ( $facets[ $key ] as $value ) {
					echo '<code>' . esc_html( $value ) . '</code> ';
				}

				echo '</p></details>';
			}

			echo '</details>';
		}

		echo '<div class="pivot-field-picker" hidden>';
		echo '<div class="pivot-field-picker-head">';

		printf(
			'<label>%s <select class="pivot-field-type"><option value="">%s</option></select></label>',
			esc_html__( 'Type d\'offre', 'pivot-offres' ),
			esc_html__( 'chargement…', 'pivot-offres' )
		);

		printf(
			'<label class="pivot-field-search-wrap"><span class="screen-reader-text">%1$s</span>'
			. '<input type="search" class="pivot-field-search" placeholder="%1$s" /></label>',
			esc_attr__( 'Filtrer les champs', 'pivot-offres' )
		);

		printf(
			'<button type="button" class="button-link pivot-close-picker">%s</button>',
			esc_html__( 'Fermer', 'pivot-offres' )
		);

		echo '</div>';
		echo '<div class="pivot-field-results" role="listbox"></div>';
		echo '</div>';

		echo '<p class="pivot-filter-actions"><button type="button" class="button-link pivot-remove-filter">' . esc_html__( 'Retirer ce critère', 'pivot-offres' ) . '</button></p>';

		echo '</div>';
	}

	/**
	 * Réglages d'un critère numérique, affichés quand le contrôle l'est.
	 *
	 * @param string $name   Préfixe des champs.
	 * @param array  $filter Données.
	 */
	private static function range_options( $name, $filter ) {
		$hidden = 'range' === pivot_get( $filter, 'type', 'select' ) ? '' : ' hidden';

		echo '<div class="pivot-filter-grid pivot-filter-range"' . $hidden . '>'; // phpcs:ignore WordPress.Security.EscapeOutput

		$selects = array(
			'operator' => array( __( 'Comparaison', 'pivot-offres' ), Pivot_Listings::filter_operators(), 'gte' ),
			'widget'   => array( __( 'Affichage', 'pivot-offres' ), Pivot_Listings::filter_widgets(), 'input' ),
		);

		foreach ( $selects as $field => $select ) {
			list( $label, $options, $default ) = $select;

			printf( '<label>%s<select name="%s[%s]">', esc_html( $label ), esc_attr( $name ), esc_attr( $field ) );

			foreach ( $options as $value => $text ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $value ),
					selected( pivot_get( $filter, $field, $default ), $value, false ),
					esc_html( $text )
				);
			}

			echo '</select></label>';
		}

		printf(
			'<label>%s<input type="text" name="%s[unit]" value="%s" maxlength="12" placeholder="€, km, m…" /></label>',
			esc_html__( 'Unité', 'pivot-offres' ),
			esc_attr( $name ),
			esc_attr( pivot_get( $filter, 'unit', '' ) )
		);

		echo '<p class="description">' . esc_html__( 'Le visiteur saisit un nombre, ou règle la jauge entre la plus petite et la plus grande valeur des offres. Une offre sans valeur pour ce champ est écartée dès que le critère est utilisé. Une jauge ne sert pas l\'égalité : ce réglage bascule sur le champ de saisie.', 'pivot-offres' ) . '</p>';

		echo '</div>';
	}

	/**
	 * Réglages d'un critère de date, affichés quand le contrôle l'est.
	 *
	 * La comparaison porte son propre nom de champ : celle des nombres,
	 * masquée, part aussi avec le formulaire, et les deux se mélangeraient.
	 *
	 * @param string $name   Préfixe des champs.
	 * @param array  $filter Données.
	 */
	private static function date_options( $name, $filter ) {
		$is_date = 'date' === pivot_get( $filter, 'type', 'select' );

		echo '<div class="pivot-filter-grid pivot-filter-date"' . ( $is_date ? '' : ' hidden' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput

		printf( '<label>%s<select name="%s[date_operator]">', esc_html__( 'Comparaison', 'pivot-offres' ), esc_attr( $name ) );

		foreach ( Pivot_Listings::date_operators() as $value => $text ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $is_date ? pivot_get( $filter, 'operator', 'between' ) : 'between', $value, false ),
				esc_html( $text )
			);
		}

		echo '</select></label>';

		echo '<p class="description">' . esc_html__( 'Le visiteur choisit une ou deux dates dans un calendrier. Avec l\'urn urn:obj:date, le critère porte sur la période entière : une offre est retenue si l\'une de ses périodes touche les dates demandées. Avec urn:fld:date:datedeb ou urn:fld:date:datefin, il ne compare que la date de début ou la date de fin. Sans première date, une période déjà terminée ne compte pas : « jusqu\'au 31 octobre » se lit « d\'aujourd\'hui au 31 octobre ».', 'pivot-offres' ) . '</p>';

		echo '</div>';
	}

	/**
	 * Référencement.
	 *
	 * @param array $listing Configuration.
	 */
	private static function section_seo( $listing ) {
		self::section_open( 'seo', __( 'Référencement', 'pivot-offres' ) );
		echo '<table class="form-table" role="presentation"><tbody>';

		self::row(
			__( 'Titre pour les moteurs', 'pivot-offres' ),
			sprintf(
				'<input type="text" name="pivot_listing[seo_title]" value="%s" class="large-text" />',
				esc_attr( $listing['seo_title'] )
			),
			__( 'Laissez vide pour reprendre le titre de la page.', 'pivot-offres' )
		);

		self::row(
			__( 'Méta description', 'pivot-offres' ),
			sprintf(
				'<textarea name="pivot_listing[seo_description]" rows="3" class="large-text">%s</textarea>',
				esc_textarea( $listing['seo_description'] )
			),
			__( 'Environ 155 caractères.', 'pivot-offres' )
		);

		echo '</tbody></table>';

		self::section_close();
	}

	/**
	 * Traductions de la page.
	 *
	 * @param array $listing Configuration.
	 */
	private static function section_translations( $listing ) {
		$langs   = Pivot_I18n::enabled();
		$default = Pivot_I18n::default_lang();

		if ( count( $langs ) < 2 ) {
			return;
		}

		$others = array_diff( $langs, array( $default ) );

		self::section_open( 'translations', __( 'Traductions', 'pivot-offres' ), strtoupper( implode( ' · ', $others ) ), true );
		echo '<p class="description">' . esc_html__( 'Un champ laissé vide reprend la langue par défaut. Les offres elles-mêmes sont traduites par PIVOT : seuls les textes de la page sont à saisir ici.', 'pivot-offres' ) . '</p>';

		foreach ( $langs as $lang ) {
			if ( $lang === $default ) {
				continue;
			}

			printf(
				'<h3 class="pivot-lang-heading"><span class="pivot-lang-tag">%1$s</span> %2$s</h3>',
				esc_html( strtoupper( $lang ) ),
				esc_html( Pivot_I18n::name( $lang ) )
			);

			echo '<table class="form-table" role="presentation"><tbody>';

			self::row(
				__( 'Titre', 'pivot-offres' ),
				sprintf(
					'<input type="text" name="pivot_listing[titles][%s]" value="%s" class="regular-text" placeholder="%s" />',
					esc_attr( $lang ),
					esc_attr( pivot_get( $listing, array( 'titles', $lang ), '' ) ),
					esc_attr( $listing['title'] )
				)
			);

			self::row(
				__( 'URL', 'pivot-offres' ),
				sprintf(
					'<input type="text" name="pivot_listing[slugs][%s]" value="%s" class="regular-text" placeholder="%s" />',
					esc_attr( $lang ),
					esc_attr( pivot_get( $listing, array( 'slugs', $lang ), '' ) ),
					esc_attr( $listing['slug'] )
				),
				__( 'Chemin propre à cette langue. Vide : le chemin de la langue par défaut est réutilisé.', 'pivot-offres' )
			);

			self::row(
				__( 'Libellé de recherche', 'pivot-offres' ),
				sprintf(
					'<input type="text" name="pivot_listing[search_labels][%s]" value="%s" class="regular-text" placeholder="%s" />',
					esc_attr( $lang ),
					esc_attr( pivot_get( $listing, array( 'search_labels', $lang ), '' ) ),
					esc_attr( $listing['search_label'] )
				)
			);

			self::editor_row(
				__( 'Texte d\'introduction', 'pivot-offres' ),
				'pivot_listing[intros][' . $lang . ']',
				pivot_get( $listing, array( 'intros', $lang ), '' ),
				6
			);

			self::row(
				__( 'Titre pour les moteurs', 'pivot-offres' ),
				sprintf(
					'<input type="text" name="pivot_listing[seo_titles][%s]" value="%s" class="large-text" />',
					esc_attr( $lang ),
					esc_attr( pivot_get( $listing, array( 'seo_titles', $lang ), '' ) )
				)
			);

			self::row(
				__( 'Méta description', 'pivot-offres' ),
				sprintf(
					'<textarea name="pivot_listing[seo_descriptions][%s]" rows="3" class="large-text">%s</textarea>',
					esc_attr( $lang ),
					esc_textarea( pivot_get( $listing, array( 'seo_descriptions', $lang ), '' ) )
				)
			);

			echo '</tbody></table>';
		}

		self::section_close();
	}

	/**
	 * État de l'index.
	 *
	 * @param array $listing Configuration.
	 */
	private static function section_index_status( $listing ) {
		self::section_open(
			'index',
			__( 'Index de la page', 'pivot-offres' ),
			! empty( $listing['index_built'] )
				/* translators: %d : nombre d'offres. */
				? sprintf( _n( '%d offre', '%d offres', (int) $listing['index_count'], 'pivot-offres' ), (int) $listing['index_count'] )
				: ''
		);

		if ( ! empty( $listing['index_built'] ) ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1 : nombre d'offres, 2 : durée, 3 : nombre de langues. */
						__( '%1$d offres indexées en %3$d langue(s), dernière reconstruction il y a %2$s.', 'pivot-offres' ),
						(int) $listing['index_count'],
						human_time_diff( (int) $listing['index_built'] ),
						count( Pivot_I18n::enabled() )
					)
				)
			);
		} else {
			echo '<p>' . esc_html__( 'Cet index n\'a pas encore été construit. Il le sera à la première visite, ou dès maintenant avec le bouton ci-dessous.', 'pivot-offres' ) . '</p>';
		}

		self::diff_status( $listing );

		printf(
			'<p><button type="button" class="button button-secondary pivot-rebuild" data-listing="%s">%s</button> <span class="pivot-rebuild-status" role="status"></span></p>',
			esc_attr( $listing['id'] ),
			esc_html__( 'Reconstruire maintenant', 'pivot-offres' )
		);

		echo '<div class="pivot-progress" hidden><div class="pivot-progress-bar"></div></div>';

		self::section_close();
	}

	/**
	 * État de la mise à jour par différentiel.
	 *
	 * @param array $listing Configuration.
	 */
	private static function diff_status( $listing ) {
		if ( empty( $listing['id'] ) || ! pivot_settings( 'diff_enabled', 1 ) ) {
			return;
		}

		if ( Pivot_Index_Builder::shares_query( $listing ) ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'Une autre page active interroge la même requête PIVOT : le différentiel est désactivé pour les deux, et leurs index sont reconstruits entièrement.', 'pivot-offres' )
			);

			return;
		}

		$time = (string) pivot_settings( 'sync_time', '04:00' );

		if ( Pivot_Index_Builder::needs_full_rebuild( $listing ) ) {
			$line = sprintf(
				/* translators: %s : heure de la mise à jour de nuit. */
				__( 'Mise à jour par différentiel chaque nuit à %s. La prochaine sera une reconstruction complète, qui pose la référence du différentiel.', 'pivot-offres' ),
				$time
			);
		} elseif ( ! empty( $listing['index_checked'] ) ) {
			$line = sprintf(
				/* translators: 1 : heure de la mise à jour de nuit, 2 : durée, 3 : nombre de changements. */
				__( 'Mise à jour par différentiel chaque nuit à %1$s. Dernière vérification il y a %2$s : %3$d changement(s).', 'pivot-offres' ),
				$time,
				human_time_diff( (int) $listing['index_checked'] ),
				(int) pivot_get( $listing, 'index_changes', 0 )
			);
		} else {
			$line = sprintf(
				/* translators: %s : heure de la mise à jour de nuit. */
				__( 'Mise à jour par différentiel chaque nuit à %s.', 'pivot-offres' ),
				$time
			);
		}

		echo '<p>' . esc_html( $line ) . '</p>';
	}

	/**
	 * Le shortcode prêt à coller dans une page ou un article.
	 *
	 * @param array $listing Configuration.
	 */
	private static function section_shortcode( $listing ) {
		self::section_open( 'shortcode', __( 'Insérer ces offres ailleurs', 'pivot-offres' ) );
		echo '<p>' . esc_html__( 'Collez ce shortcode dans une page ou un article pour y afficher quelques vignettes de cette sélection, sans carte ni critères. Il réutilise l\'index déjà construit : aucun appel supplémentaire à PIVOT.', 'pivot-offres' ) . '</p>';

		printf(
			'<p><input type="text" class="large-text code" readonly onfocus="this.select()" value="%s" /></p>',
			esc_attr( sprintf( '[pivot_offres listing="%s" nombre="3" colonnes="3"]', $listing['id'] ) )
		);

		$filters = (array) pivot_get( $listing, 'filters', array() );

		if ( $filters ) {
			$first  = reset( $filters );
			$key    = pivot_get( $first, 'key', '' );
			$values = (array) pivot_get( $listing, array( 'facet_values', $key ), array() );
			$value  = $values ? reset( $values ) : 'valeur';

			echo '<p class="description">' . esc_html__( 'Pour restreindre à un critère :', 'pivot-offres' ) . '</p>';

			printf(
				'<p><input type="text" class="large-text code" readonly onfocus="this.select()" value="%s" /></p>',
				esc_attr( sprintf( '[pivot_offres listing="%1$s" filtre="%2$s:%3$s" nombre="3"]', $listing['id'], $key, $value ) )
			);
		}

		echo '<p class="description">' . esc_html__( 'Attributs : nombre, colonnes, tri (defaut, nom, aleatoire), titre, filtre, lien="oui" pour ajouter un lien vers cette page.', 'pivot-offres' ) . '</p>';

		self::section_close();
	}

	/**
	 * Ligne de tableau de réglage.
	 *
	 * @param string $label Libellé.
	 * @param string $field HTML du contrôle.
	 * @param string $help  Aide.
	 */
	private static function row( $label, $field, $help = '' ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo wp_kses( $field, self::allowed_html() );

		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * Ligne de formulaire avec l'éditeur visuel de WordPress.
	 *
	 * L'éditeur produit ses propres scripts et boutons : sa sortie ne passe
	 * pas par le filtrage de row(), qui les retirerait.
	 *
	 * @param string $label   Libellé.
	 * @param string $name    Nom du champ.
	 * @param string $content Contenu.
	 * @param int    $rows    Hauteur en lignes.
	 * @param string $help    Aide.
	 */
	private static function editor_row( $label, $name, $content, $rows = 8, $help = '' ) {
		// Identifiant d'éditeur : minuscules et soulignés seulement.
		$id = trim( preg_replace( '/[^a-z0-9_]+/', '_', strtolower( $name ) ), '_' );

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';

		wp_editor(
			(string) $content,
			$id,
			array(
				'textarea_name' => $name,
				'textarea_rows' => $rows,
				'media_buttons' => true,
			)
		);

		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * Balises autorisées dans les contrôles de formulaire.
	 *
	 * @return array
	 */
	private static function allowed_html() {
		return array(
			'input'    => array(
				'type'         => true,
				'name'         => true,
				'value'        => true,
				'class'        => true,
				'placeholder'  => true,
				'min'          => true,
				'max'          => true,
				'checked'      => true,
				'required'     => true,
				'id'           => true,
				'autocomplete' => true,
			),
			'select'   => array( 'name' => true, 'class' => true, 'id' => true ),
			'option'   => array( 'value' => true, 'selected' => true ),
			'textarea' => array( 'name' => true, 'rows' => true, 'class' => true, 'placeholder' => true, 'id' => true ),
			'label'    => array( 'for' => true, 'class' => true ),
			'code'     => array(),
			'br'       => array(),
			'strong'   => array(),
			'a'        => array( 'href' => true, 'class' => true, 'target' => true, 'rel' => true ),
			'button'   => array( 'type' => true, 'class' => true, 'data-listing' => true ),
			'span'     => array( 'class' => true, 'role' => true ),
			'div'      => array( 'class' => true, 'hidden' => true ),
			'img'      => array( 'class' => true, 'src' => true, 'alt' => true, 'hidden' => true ),
		);
	}
}
