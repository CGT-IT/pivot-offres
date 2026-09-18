<?php
/**
 * Écrans d'administration.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Admin {

	/** @var Pivot_Admin|null */
	private static $instance = null;

	/**
	 * @return Pivot_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_notices', array( $this, 'activation_notice' ) );
		add_action( 'admin_notices', array( $this, 'language_change_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . PIVOT_BASENAME, array( $this, 'action_links' ) );

		Pivot_Settings::instance();
		Pivot_Listing_Edit::instance();
		Pivot_Tools::instance();
		Pivot_Types_Admin::instance();
		Pivot_Fields_Admin::instance();
		Pivot_Onboarding::instance();
	}

	/**
	 * Signale une étape d'activation qui a échoué.
	 */
	public function activation_notice() {
		$failures = get_option( 'pivot_activation_failures' );

		if ( ! $failures || ! current_user_can( pivot_capability() ) ) {
			return;
		}

		echo '<div class="notice notice-warning is-dismissible"><p><strong>';
		esc_html_e( 'PIVOT Offres est actif, mais une étape d\'installation a échoué :', 'pivot-offres' );
		echo '</strong></p><ul style="list-style:disc;margin-left:2em">';

		foreach ( (array) $failures as $failure ) {
			echo '<li>' . esc_html( $failure ) . '</li>';
		}

		echo '</ul><p>';
		esc_html_e( 'Désactivez puis réactivez le plugin après avoir corrigé le problème.', 'pivot-offres' );
		echo '</p></div>';
	}

	/**
	 * Signale l'arrivée ou la reconfiguration d'une extension de traduction.
	 *
	 * C'est le moment où les index et les URL deviennent périmés : mieux vaut
	 * le dire que laisser l'administrateur découvrir des pages incohérentes.
	 */
	public function language_change_notice() {
		$change = get_option( 'pivot_lang_changed' );

		if ( ! $change || ! current_user_can( pivot_capability() ) ) {
			return;
		}

		$languages = array_map( 'strtoupper', (array) pivot_get( $change, 'languages', array() ) );

		echo '<div class="notice notice-info is-dismissible"><p><strong>';
		esc_html_e( 'La configuration linguistique du site a changé.', 'pivot-offres' );
		echo '</strong></p><p>';

		printf(
			/* translators: 1 : nom de l'extension, 2 : liste des langues. */
			esc_html__( 'PIVOT Offres suit désormais %1$s, pour les langues %2$s. Les index ont été marqués comme périmés : ils se reconstruisent d\'eux-mêmes, ou immédiatement depuis Cache et outils.', 'pivot-offres' ),
			'<strong>' . esc_html( pivot_get( $change, 'provider', '' ) ) . '</strong>',
			'<strong>' . esc_html( implode( ', ', $languages ) ) . '</strong>'
		);

		echo '</p><p>';
		esc_html_e( 'Pensez à traduire le titre et l\'URL de chaque page de listing, ainsi que le libellé de vos critères : sans traduction saisie, ils reprennent la langue par défaut.', 'pivot-offres' );
		echo '</p><p>';

		printf(
			'<a href="%s" class="button">%s</a> <a href="%s" class="button">%s</a>',
			esc_url( admin_url( 'admin.php?page=pivot-listings' ) ),
			esc_html__( 'Voir les pages de listing', 'pivot-offres' ),
			esc_url( admin_url( 'options-permalink.php' ) ),
			esc_html__( 'Enregistrer les permaliens', 'pivot-offres' )
		);

		echo '</p></div>';

		delete_option( 'pivot_lang_changed' );
	}

	/**
	 * Menus.
	 */
	public function menu() {
		$cap = pivot_capability();

		add_menu_page(
			__( 'PIVOT Offres', 'pivot-offres' ),
			__( 'PIVOT', 'pivot-offres' ),
			$cap,
			'pivot-listings',
			array( $this, 'render_listings' ),
			'dashicons-location-alt',
			58
		);

		add_submenu_page(
			'pivot-listings',
			__( 'Pages de listing', 'pivot-offres' ),
			__( 'Pages de listing', 'pivot-offres' ),
			$cap,
			'pivot-listings',
			array( $this, 'render_listings' )
		);

		add_submenu_page(
			'pivot-listings',
			__( 'Ajouter une page', 'pivot-offres' ),
			__( 'Ajouter une page', 'pivot-offres' ),
			$cap,
			'pivot-listing-edit',
			array( 'Pivot_Listing_Edit', 'render' )
		);

		add_submenu_page(
			'pivot-listings',
			__( 'Insérer des offres', 'pivot-offres' ),
			__( 'Shortcode', 'pivot-offres' ),
			$cap,
			'pivot-shortcode',
			array( 'Pivot_Shortcode_Admin', 'render' )
		);

		add_submenu_page(
			'pivot-listings',
			__( 'Types d\'offres', 'pivot-offres' ),
			__( 'Types d\'offres', 'pivot-offres' ),
			$cap,
			'pivot-types',
			array( 'Pivot_Types_Admin', 'render' )
		);

		add_submenu_page(
			'pivot-listings',
			__( 'Champs affichés', 'pivot-offres' ),
			__( 'Champs affichés', 'pivot-offres' ),
			$cap,
			'pivot-fields',
			array( 'Pivot_Fields_Admin', 'render' )
		);

		add_submenu_page(
			'pivot-listings',
			__( 'Réglages PIVOT', 'pivot-offres' ),
			__( 'Réglages', 'pivot-offres' ),
			$cap,
			'pivot-settings',
			array( 'Pivot_Settings', 'render' )
		);

		add_submenu_page(
			'pivot-listings',
			__( 'Cache et redirections', 'pivot-offres' ),
			__( 'Cache et outils', 'pivot-offres' ),
			$cap,
			'pivot-tools',
			array( 'Pivot_Tools', 'render' )
		);

		add_submenu_page(
			'pivot-listings',
			__( 'Journal des appels', 'pivot-offres' ),
			__( 'Journal', 'pivot-offres' ),
			$cap,
			'pivot-logs',
			array( $this, 'render_logs' )
		);
	}

	/**
	 * Liens rapides depuis la liste des extensions.
	 *
	 * @param array $links Liens existants.
	 * @return array
	 */
	public function action_links( $links ) {
		$custom = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=pivot-settings' ) ) . '">' . esc_html__( 'Réglages', 'pivot-offres' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=pivot-listings' ) ) . '">' . esc_html__( 'Pages de listing', 'pivot-offres' ) . '</a>',
		);

		return array_merge( $custom, $links );
	}

	/**
	 * Feuilles de style et scripts d'administration.
	 *
	 * @param string $hook Écran courant.
	 */
	public function assets( $hook ) {
		if ( false === strpos( $hook, 'pivot-' ) ) {
			return;
		}

		wp_enqueue_style( 'pivot-admin', PIVOT_URL . 'assets/css/pivot-admin.css', array(), PIVOT_VERSION );
		wp_enqueue_script( 'pivot-admin', PIVOT_URL . 'assets/js/pivot-admin.js', array( 'wp-api-fetch' ), PIVOT_VERSION, true );

		wp_localize_script(
			'pivot-admin',
			'pivotAdmin',
			array(
				'restBase' => rest_url( Pivot_Rest::NAMESPACE_V1 ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'listing'  => isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '',
				'i18n'     => array(
					'building'    => __( 'Reconstruction en cours…', 'pivot-offres' ),
					'done'        => __( 'Index reconstruit.', 'pivot-offres' ),
					'failed'      => __( 'La reconstruction a échoué.', 'pivot-offres' ),
					'progress'    => __( '%1$d offres traitées sur %2$d', 'pivot-offres' ),
					'confirmRm'   => __( 'Supprimer cette page de listing ? Les offres PIVOT ne sont pas touchées.', 'pivot-offres' ),
					'loading'     => __( 'Chargement des champs…', 'pivot-offres' ),
					'loadFailed'  => __( 'Les champs n\'ont pas pu être chargés. Vérifiez la connexion à PIVOT dans les réglages.', 'pivot-offres' ),
					'noField'     => __( 'Aucun champ ne correspond.', 'pivot-offres' ),
					'chooseType'  => __( 'Choisissez un type d\'offre pour voir ses champs.', 'pivot-offres' ),
					'inThisPage'  => __( 'présent dans cette page', 'pivot-offres' ),
					'copied'      => __( 'Copié', 'pivot-offres' ),
					'analysing'   => __( 'Analyse des offres…', 'pivot-offres' ),
					'sugAdd'      => __( 'Ajouter', 'pivot-offres' ),
					'sugAdded'    => __( 'Ajouté', 'pivot-offres' ),
					'sugSummary'  => __( '%1$d valeurs · %2$d %% des offres', 'pivot-offres' ),
					'sugBoolean'  => __( 'oui / non · %d %% des offres', 'pivot-offres' ),
					'sugBasis'    => __( 'Déduit de %1$d offres analysées sur %2$d.', 'pivot-offres' ),
					'sugNone'     => __( 'Aucun critère ne se dégage de ces offres. Ajoutez-en un sur mesure.', 'pivot-offres' ),
					'sugFailed'   => __( 'L\'analyse a échoué : %s', 'pivot-offres' ),
					'sugNeedFull' => __( 'Richesse des données passée sur « Complet » : ce critère porte sur un champ PIVOT.', 'pivot-offres' ),
				),
			)
		);
	}

	/**
	 * Écran « Pages de listing ».
	 */
	public function render_listings() {
		if ( ! current_user_can( pivot_capability() ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas accès à cet écran.', 'pivot-offres' ) );
		}

		$this->handle_listing_actions();

		$listings = Pivot_Listings::all();

		echo '<div class="wrap pivot-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Pages de listing', 'pivot-offres' ) . '</h1>';
		printf(
			' <a href="%s" class="page-title-action">%s</a>',
			esc_url( admin_url( 'admin.php?page=pivot-listing-edit' ) ),
			esc_html__( 'Ajouter une page', 'pivot-offres' )
		);
		echo '<hr class="wp-header-end" />';

		settings_errors( 'pivot_listings' );

		if ( ! $listings ) {
			echo '<div class="pivot-empty">';
			echo '<p>' . esc_html__( 'Aucune page de listing pour l\'instant.', 'pivot-offres' ) . '</p>';
			echo '<p>' . esc_html__( 'Une page de listing associe une URL de votre site à une requête pré-programmée PIVOT. Créez-en une pour publier vos premières offres.', 'pivot-offres' ) . '</p>';
			printf(
				'<p><a href="%s" class="button button-primary">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=pivot-listing-edit' ) ),
				esc_html__( 'Créer la première page', 'pivot-offres' )
			);
			echo '</div>';
			echo '</div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped pivot-listings-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Titre', 'pivot-offres' ) . '</th>';
		echo '<th>' . esc_html__( 'URL', 'pivot-offres' ) . '</th>';
		echo '<th>' . esc_html__( 'Requête', 'pivot-offres' ) . '</th>';
		echo '<th>' . esc_html__( 'Par page', 'pivot-offres' ) . '</th>';
		echo '<th>' . esc_html__( 'Carte', 'pivot-offres' ) . '</th>';
		echo '<th>' . esc_html__( 'Filtres', 'pivot-offres' ) . '</th>';
		echo '<th>' . esc_html__( 'Langues', 'pivot-offres' ) . '</th>';
		echo '<th>' . esc_html__( 'Index', 'pivot-offres' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $listings as $listing ) {
			$edit_url = add_query_arg(
				array(
					'page' => 'pivot-listing-edit',
					'id'   => $listing['id'],
				),
				admin_url( 'admin.php' )
			);

			$delete_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'   => 'pivot-listings',
						'action' => 'delete',
						'id'     => $listing['id'],
					),
					admin_url( 'admin.php' )
				),
				'pivot_delete_listing_' . $listing['id']
			);

			$rebuild_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'   => 'pivot-listings',
						'action' => 'rebuild',
						'id'     => $listing['id'],
					),
					admin_url( 'admin.php' )
				),
				'pivot_rebuild_listing_' . $listing['id']
			);

			echo '<tr>';

			echo '<td><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $listing['title'] ) . '</a></strong>';
			if ( empty( $listing['active'] ) ) {
				echo ' <span class="pivot-badge pivot-badge-off">' . esc_html__( 'inactive', 'pivot-offres' ) . '</span>';
			}
			echo '<div class="row-actions">';
			echo '<span class="edit"><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Modifier', 'pivot-offres' ) . '</a> | </span>';
			echo '<span><a href="' . esc_url( $rebuild_url ) . '">' . esc_html__( 'Reconstruire l\'index', 'pivot-offres' ) . '</a> | </span>';
			echo '<span class="trash"><a class="pivot-confirm-delete" href="' . esc_url( $delete_url ) . '">' . esc_html__( 'Supprimer', 'pivot-offres' ) . '</a></span>';
			echo '</div></td>';

			echo '<td><a href="' . esc_url( Pivot_Listings::url( $listing, Pivot_I18n::default_lang() ) ) . '" target="_blank" rel="noopener">/' . esc_html( $listing['slug'] ) . '/</a></td>';
			echo '<td><code>' . esc_html( $listing['query_code'] ) . '</code></td>';
			echo '<td>' . esc_html( $listing['per_page'] ) . '</td>';
			echo '<td>' . ( empty( $listing['show_map'] ) ? '—' : esc_html__( 'Oui', 'pivot-offres' ) ) . '</td>';
			echo '<td>' . esc_html( count( (array) $listing['filters'] ) ) . '</td>';

			echo '<td>';
			foreach ( Pivot_I18n::enabled() as $lang ) {
				$translated = $lang === Pivot_I18n::default_lang() || pivot_get( $listing, array( 'slugs', $lang ) );
				printf(
					'<span class="pivot-lang-tag%1$s" title="%2$s">%3$s</span> ',
					$translated ? '' : ' is-missing',
					esc_attr( $translated ? __( 'URL propre à cette langue', 'pivot-offres' ) : __( 'Pas d\'URL traduite : le chemin par défaut est réutilisé', 'pivot-offres' ) ),
					esc_html( strtoupper( $lang ) )
				);
			}
			echo '</td>';

			echo '<td>';
			if ( ! empty( $listing['index_built'] ) ) {
				printf(
					/* translators: 1: nombre d'offres, 2: date relative. */
					esc_html__( '%1$d offres, il y a %2$s', 'pivot-offres' ),
					(int) $listing['index_count'],
					esc_html( human_time_diff( (int) $listing['index_built'] ) )
				);
			} else {
				echo '<em>' . esc_html__( 'jamais construit', 'pivot-offres' ) . '</em>';
			}
			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Suppression et reconstruction depuis la liste.
	 */
	private function handle_listing_actions() {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';

		if ( ! $action || ! $id ) {
			return;
		}

		if ( 'delete' === $action ) {
			check_admin_referer( 'pivot_delete_listing_' . $id );

			if ( Pivot_Listings::delete( $id ) ) {
				add_settings_error( 'pivot_listings', 'deleted', __( 'Page de listing supprimée.', 'pivot-offres' ), 'updated' );
			}
			return;
		}

		if ( 'rebuild' === $action ) {
			check_admin_referer( 'pivot_rebuild_listing_' . $id );

			Pivot_Index_Builder::delete_index( $id );
			$state = Pivot_Index_Builder::run( $id, 20 );

			if ( is_wp_error( $state ) ) {
				add_settings_error( 'pivot_listings', 'rebuild_failed', $state->get_error_message(), 'error' );
				return;
			}

			if ( ! empty( $state['done'] ) ) {
				add_settings_error(
					'pivot_listings',
					'rebuilt',
					sprintf(
						/* translators: %d: nombre d'offres. */
						__( 'Index reconstruit : %d offres.', 'pivot-offres' ),
						(int) pivot_get( $state, 'processed', 0 )
					),
					'updated'
				);
			} else {
				add_settings_error(
					'pivot_listings',
					'rebuilding',
					__( 'La reconstruction se poursuit en arrière-plan. Rechargez cette page dans une minute pour voir le total.', 'pivot-offres' ),
					'info'
				);
			}
		}
	}

	/**
	 * Écran « Journal ».
	 */
	public function render_logs() {
		if ( ! current_user_can( pivot_capability() ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas accès à cet écran.', 'pivot-offres' ) );
		}

		if ( isset( $_POST['pivot_clear_logs'] ) ) {
			check_admin_referer( 'pivot_clear_logs' );
			$removed = Pivot_Logger::truncate();
			add_settings_error(
				'pivot_logs',
				'cleared',
				sprintf(
					/* translators: %d: nombre de lignes. */
					__( 'Journal vidé : %d lignes supprimées.', 'pivot-offres' ),
					$removed
				),
				'updated'
			);
		}

		$level    = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page = 50;

		$result = Pivot_Logger::query(
			array(
				'level'    => $level,
				'search'   => $search,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);

		echo '<div class="wrap pivot-wrap">';
		echo '<h1>' . esc_html__( 'Journal des appels PIVOT', 'pivot-offres' ) . '</h1>';

		settings_errors( 'pivot_logs' );

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: nombre de jours. */
					__( 'Les entrées sont conservées %d jours, puis supprimées automatiquement.', 'pivot-offres' ),
					(int) pivot_settings( 'logs_retention', 7 )
				)
			)
		);

		if ( ! pivot_settings( 'logs_enabled', 0 ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'La journalisation est désactivée dans les réglages : aucune nouvelle entrée n\'est enregistrée.', 'pivot-offres' ) . '</p></div>';
		}

		echo '<form method="get" class="pivot-log-filters">';
		echo '<input type="hidden" name="page" value="pivot-logs" />';
		echo '<select name="level">';
		echo '<option value="">' . esc_html__( 'Tous les niveaux', 'pivot-offres' ) . '</option>';
		foreach ( array(
			'debug' => __( 'Débogage', 'pivot-offres' ),
			'info'  => __( 'Information', 'pivot-offres' ),
			'warn'  => __( 'Avertissement', 'pivot-offres' ),
			'error' => __( 'Erreur', 'pivot-offres' ),
		) as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $level, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select> ';
		printf(
			'<input type="search" name="s" value="%s" placeholder="%s" /> ',
			esc_attr( $search ),
			esc_attr__( 'Rechercher une URL ou un message', 'pivot-offres' )
		);
		submit_button( __( 'Filtrer', 'pivot-offres' ), 'secondary', '', false );
		echo '</form>';

		echo '<table class="wp-list-table widefat fixed striped pivot-logs-table">';
		echo '<thead><tr>';
		echo '<th style="width:150px">' . esc_html__( 'Date', 'pivot-offres' ) . '</th>';
		echo '<th style="width:90px">' . esc_html__( 'Niveau', 'pivot-offres' ) . '</th>';
		echo '<th style="width:90px">' . esc_html__( 'Service', 'pivot-offres' ) . '</th>';
		echo '<th>' . esc_html__( 'Appel', 'pivot-offres' ) . '</th>';
		echo '<th style="width:70px">' . esc_html__( 'HTTP', 'pivot-offres' ) . '</th>';
		echo '<th style="width:80px">' . esc_html__( 'Durée', 'pivot-offres' ) . '</th>';
		echo '<th style="width:80px">' . esc_html__( 'Poids', 'pivot-offres' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $result['items'] ) {
			echo '<tr><td colspan="7">' . esc_html__( 'Aucune entrée pour ces critères.', 'pivot-offres' ) . '</td></tr>';
		}

		foreach ( $result['items'] as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
			echo '<td><span class="pivot-level pivot-level-' . esc_attr( $row['level'] ) . '">' . esc_html( $row['level'] ) . '</span></td>';
			echo '<td>' . esc_html( $row['service'] ) . '</td>';
			echo '<td><code class="pivot-endpoint">' . esc_html( $row['endpoint'] ) . '</code>';
			if ( ! empty( $row['message'] ) && 'OK' !== $row['message'] ) {
				echo '<br /><span class="pivot-log-message">' . esc_html( $row['message'] ) . '</span>';
			}
			if ( ! empty( $row['cache_status'] ) ) {
				echo ' <span class="pivot-badge">' . esc_html( $row['cache_status'] ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . ( $row['http_code'] ? esc_html( $row['http_code'] ) : '—' ) . '</td>';
			echo '<td>' . ( $row['duration_ms'] ? esc_html( $row['duration_ms'] ) . ' ms' : '—' ) . '</td>';
			echo '<td>' . ( $row['bytes'] ? esc_html( size_format( (int) $row['bytes'] ) ) : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$pages = (int) ceil( $result['total'] / $per_page );

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $page,
						'total'     => $pages,
						'prev_text' => '‹',
						'next_text' => '›',
					)
				)
			);
			echo '</div></div>';
		}

		echo '<form method="post" class="pivot-clear-logs">';
		wp_nonce_field( 'pivot_clear_logs' );
		submit_button( __( 'Vider le journal', 'pivot-offres' ), 'delete', 'pivot_clear_logs', false );
		echo '</form>';

		echo '</div>';
	}
}
