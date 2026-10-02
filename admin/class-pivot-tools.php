<?php
/**
 * Écran « Cache et outils ».
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Tools {

	/**
	 * Affiche l'écran.
	 */
	public static function render() {
		if ( ! current_user_can( pivot_capability() ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas accès à cet écran.', 'pivot-offres' ) );
		}

		self::handle_actions();

		echo '<div class="wrap pivot-wrap">';
		echo '<h1>' . esc_html__( 'Cache et outils', 'pivot-offres' ) . '</h1>';

		settings_errors( 'pivot_tools' );

		self::section_cache();
		self::section_legacy();
		self::section_diagnostics();

		echo '</div>';
	}

	/**
	 * Traite les actions du formulaire.
	 */
	private static function handle_actions() {
		if ( empty( $_POST ) || ! isset( $_POST['_wpnonce'] ) ) {
			return;
		}

		if ( ! isset( $_POST['pivot_action'] ) ) {
			return;
		}

		check_admin_referer( 'pivot_tools' );

		$action = sanitize_key( wp_unslash( $_POST['pivot_action'] ) );

		switch ( $action ) {
			case 'flush_all':
				$count = Pivot_Cache::flush();
				add_settings_error(
					'pivot_tools',
					'flushed',
					sprintf(
						/* translators: %d: nombre de fichiers. */
						__( 'Cache vidé : %d fichiers supprimés. Les prochaines visites rechargeront les données depuis PIVOT.', 'pivot-offres' ),
						$count
					),
					'updated'
				);
				break;

			case 'flush_thesaurus':
				$count = Pivot_Thesaurus::flush();
				add_settings_error(
					'pivot_tools',
					'thesaurus_flushed',
					sprintf(
						/* translators: %d: nombre de fichiers. */
						__( 'Cache du thesaurus réinitialisé : %d fichiers supprimés.', 'pivot-offres' ),
						$count
					),
					'updated'
				);
				break;

			case 'flush_offers':
				$count = Pivot_Cache::flush( 'offers' );
				add_settings_error(
					'pivot_tools',
					'offers_flushed',
					sprintf(
						/* translators: %d: nombre de fichiers. */
						__( 'Cache des fiches vidé : %d fichiers supprimés.', 'pivot-offres' ),
						$count
					),
					'updated'
				);
				break;

			case 'rebuild_indexes':
				foreach ( array_keys( Pivot_Listings::active() ) as $listing_id ) {
					Pivot_Index_Builder::invalidate( $listing_id );
					wp_schedule_single_event( time() + 5, 'pivot_continue_index', array( $listing_id ) );
				}
				add_settings_error(
					'pivot_tools',
					'rebuild_scheduled',
					__( 'Reconstruction lancée pour toutes les pages actives. Elle se poursuit en arrière-plan.', 'pivot-offres' ),
					'updated'
				);
				break;

			case 'legacy_import':
				$report = Pivot_Legacy_Import::run();
				$lines  = Pivot_Legacy_Import::messages( $report );
				$text   = sprintf(
					/* translators: 1 : pages ajoutées, 2 : filtres ajoutés, 3 : pages déjà reprises. */
					__( 'Reprise terminée : %1$d page(s) et %2$d filtre(s) ajoutés, %3$d page(s) déjà reprise(s).', 'pivot-offres' ),
					$report['pages'],
					$report['filters'],
					$report['already']
				);

				// settings_errors() publie le message sans l'échapper : les titres
				// de page qu'il cite viennent de la base.
				add_settings_error(
					'pivot_tools',
					'legacy_import',
					implode( '<br />', array_map( 'esc_html', array_merge( array( $text ), $lines ) ) ),
					$lines ? 'warning' : 'updated'
				);
				break;

			case 'reset_onboarding':
				Pivot_Onboarding::reset();
				add_settings_error(
					'pivot_tools',
					'onboarding_reset',
					__( 'Les visites guidées vous seront reproposées à votre prochaine visite de chaque écran.', 'pivot-offres' ),
					'updated'
				);
				break;
		}
	}

	/**
	 * Bloc « Cache ».
	 */
	private static function section_cache() {
		$stats = Pivot_Cache::stats();

		$labels = array(
			'index'     => __( 'Index des pages de listing', 'pivot-offres' ),
			'offers'    => __( 'Fiches détail', 'pivot-offres' ),
			'thesaurus' => __( 'Thesaurus', 'pivot-offres' ),
			'build'     => __( 'États de construction', 'pivot-offres' ),
			'raw'       => __( 'Réponses brutes', 'pivot-offres' ),
		);

		echo '<h2>' . esc_html__( 'Cache', 'pivot-offres' ) . '</h2>';
		echo '<p>' . esc_html__( 'Les offres ne sont jamais écrites en base de données : elles vivent dans ces fichiers, renouvelés automatiquement.', 'pivot-offres' ) . '</p>';

		echo '<table class="widefat striped pivot-cache-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Contenu', 'pivot-offres' ) . '</th>';
		echo '<th>' . esc_html__( 'Fichiers', 'pivot-offres' ) . '</th>';
		echo '<th>' . esc_html__( 'Poids', 'pivot-offres' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $labels as $group => $label ) {
			$entry = pivot_get( $stats, $group, array( 'files' => 0, 'size' => 0 ) );
			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td>' . esc_html( (int) pivot_get( $entry, 'files', 0 ) ) . '</td>';
			echo '<td>' . esc_html( size_format( (int) pivot_get( $entry, 'size', 0 ) ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<form method="post" class="pivot-tool-actions">';
		wp_nonce_field( 'pivot_tools' );
		echo '<p>';
		printf(
			'<button type="submit" name="pivot_action" value="flush_thesaurus" class="button button-primary">%s</button> ',
			esc_html__( 'Réinitialiser le cache du thesaurus', 'pivot-offres' )
		);
		printf(
			'<button type="submit" name="pivot_action" value="flush_offers" class="button">%s</button> ',
			esc_html__( 'Vider les fiches détail', 'pivot-offres' )
		);
		printf(
			'<button type="submit" name="pivot_action" value="rebuild_indexes" class="button">%s</button> ',
			esc_html__( 'Reconstruire tous les index', 'pivot-offres' )
		);
		printf(
			'<button type="submit" name="pivot_action" value="flush_all" class="button button-link-delete">%s</button>',
			esc_html__( 'Tout vider', 'pivot-offres' )
		);
		echo '</p>';
		echo '</form>';
	}

	/**
	 * Bloc « Ancien plugin PIVOT », tant que ses tables sont en base.
	 */
	private static function section_legacy() {
		if ( ! Pivot_Legacy_Import::tables_present() ) {
			return;
		}

		$state = Pivot_Legacy_Import::state();

		echo '<hr />';
		echo '<h2>' . esc_html__( 'Ancien plugin PIVOT', 'pivot-offres' ) . '</h2>';
		echo '<p>' . esc_html__( 'Les pages et les filtres de l\'ancien plugin sont encore en base. La reprise recrée ici ceux qui manquent : une page déjà reprise, ou dont l\'adresse est prise, est laissée de côté. Les tables de l\'ancien plugin ne sont pas modifiées.', 'pivot-offres' ) . '</p>';

		if ( 'done' === $state['status'] ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1 : durée, 2 : nombre de pages. */
						__( 'Dernière reprise il y a %1$s. Pages reprises depuis l\'ancien plugin : %2$d.', 'pivot-offres' ),
						human_time_diff( (int) $state['time'] ),
						count( (array) $state['map'] )
					)
				)
			);
		}

		echo '<form method="post" class="pivot-tool-actions">';
		wp_nonce_field( 'pivot_tools' );
		printf(
			'<p><button type="submit" name="pivot_action" value="legacy_import" class="button">%s</button></p>',
			esc_html__( 'Reprendre les pages de l\'ancien plugin', 'pivot-offres' )
		);
		echo '</form>';
	}

	/**
	 * Bloc « Diagnostic ».
	 */
	private static function section_diagnostics() {
		echo '<hr />';
		echo '<h2>' . esc_html__( 'Aide', 'pivot-offres' ) . '</h2>';
		echo '<p>' . esc_html__( 'Les visites guidées s\'ouvrent une seule fois par écran et par personne. Le bouton présent en haut de chaque écran permet de les revoir à tout moment ; celui-ci les remet à zéro pour votre compte.', 'pivot-offres' ) . '</p>';
		echo '<form method="post" class="pivot-tool-actions">';
		wp_nonce_field( 'pivot_tools' );
		printf(
			'<p><button type="submit" name="pivot_action" value="reset_onboarding" class="button">%s</button></p>',
			esc_html__( 'Revoir les visites guidées', 'pivot-offres' )
		);
		echo '</form>';

		echo '<hr />';
		echo '<h2>' . esc_html__( 'Diagnostic', 'pivot-offres' ) . '</h2>';

		$rows = array(
			__( 'Version du plugin', 'pivot-offres' ) => PIVOT_VERSION,
			__( 'Environnement actif', 'pivot-offres' ) => 'prod' === pivot_settings( 'environment' ) ? __( 'Production', 'pivot-offres' ) : __( 'Stage', 'pivot-offres' ),
			__( 'URL du service', 'pivot-offres' )      => pivot_service_url(),
			__( 'Clé enregistrée', 'pivot-offres' )     => pivot_ws_key() ? __( 'Oui', 'pivot-offres' ) : __( 'Non', 'pivot-offres' ),
			__( 'Index publiés', 'pivot-offres' )       => Pivot_Cache::directory( 'index' ),
			__( 'Cache privé', 'pivot-offres' )         => Pivot_Cache::directory( 'offers' ),
			__( 'Racine du site', 'pivot-offres' )      => Pivot_I18n::site_root(),
			__( 'Pages actives', 'pivot-offres' )       => (string) count( Pivot_Listings::active() ),
			__( 'Gestion des langues', 'pivot-offres' ) => self::language_mode_label(),
			__( 'Langues publiées', 'pivot-offres' )    => implode( ', ', Pivot_I18n::languages() ),
			__( 'Prochaine reconstruction', 'pivot-offres' ) => wp_next_scheduled( 'pivot_refresh_indexes' )
				? wp_date( 'd/m/Y H:i', (int) wp_next_scheduled( 'pivot_refresh_indexes' ) )
				: __( 'non planifiée', 'pivot-offres' ),
		);

		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:280px">' . esc_html( $label ) . '</th><td><code>' . esc_html( $value ) . '</code></td></tr>';
		}
		echo '</tbody></table>';

		// Les URL réellement produites : le moyen le plus direct de vérifier que
		// chaque langue reçoit bien une adresse distincte.
		if ( ! Pivot_I18n::is_multilingual() ) {
			return;
		}

		echo '<h3>' . esc_html__( 'URL par langue', 'pivot-offres' ) . '</h3>';
		echo '<table class="widefat striped"><tbody>';

		$seen       = array();
		$duplicates = array();

		foreach ( Pivot_I18n::languages() as $lang ) {
			$url       = Pivot_Rewrites::detail_url( 'ALD-01-00096Z', 11, $lang );
			$duplicate = in_array( $url, $seen, true );

			if ( $duplicate ) {
				$duplicates[] = strtoupper( $lang );
			} else {
				$seen[] = $url;
			}

			echo '<tr><th style="width:280px">' . esc_html( Pivot_I18n::name( $lang ) );

			if ( ! in_array( $lang, Pivot_I18n::CONTENT_LANGS, true ) ) {
				echo ' <span class="pivot-badge">' . esc_html__( 'contenus non traduits par PIVOT', 'pivot-offres' ) . '</span>';
			}

			echo '</th><td><code>' . esc_html( $url ) . '</code>';

			if ( $duplicate ) {
				echo ' <span class="pivot-badge pivot-badge-off">' . esc_html__( 'identique', 'pivot-offres' ) . '</span>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		if ( $duplicates ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: 1 : liste de langues, 2 : nom de l'extension. */
				esc_html__( 'Les langues %1$s reçoivent la même adresse que la langue par défaut : %2$s ne distingue pas les URL que le plugin fabrique, car elles ne correspondent à aucun contenu WordPress. Vérifiez sa configuration d\'URL, ou fournissez l\'adresse attendue avec le filtre pivot_language_url. En attendant, une seule balise hreflang est publiée plutôt que plusieurs identiques.', 'pivot-offres' ),
				'<strong>' . esc_html( implode( ', ', $duplicates ) ) . '</strong>',
				esc_html( Pivot_I18n::provider_name() )
			);
			echo '</p></div>';
		}
	}

	/**
	 * Qui gère les langues du site, en clair.
	 *
	 * @return string
	 */
	private static function language_mode_label() {
		return Pivot_I18n::provider_name();
	}
}
