<?php
/**
 * Écran « Cache et outils ».
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Tools {

	/** @var Pivot_Tools|null */
	private static $instance = null;

	/** @var array Résultat de la dernière génération, rendu sous le formulaire. */
	private static $generation = array();

	/**
	 * @return Pivot_Tools
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'handle_export' ) );
	}

	/**
	 * Téléchargement de la table de redirections.
	 */
	public function handle_export() {
		if ( ! isset( $_POST['pivot_export_redirects'] ) ) {
			return;
		}

		if ( ! current_user_can( pivot_capability() ) ) {
			return;
		}

		check_admin_referer( 'pivot_tools' );

		$format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : 'csv';
		$body   = Pivot_Redirects::export( $format );

		$extensions = array( 'csv' => 'csv', 'htaccess' => 'txt', 'nginx' => 'conf' );
		$extension  = isset( $extensions[ $format ] ) ? $extensions[ $format ] : 'txt';

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="pivot-redirections.' . $extension . '"' );

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

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
		self::section_redirects();
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

			case 'generate_redirects':
				$query    = isset( $_POST['query_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['query_code'] ) ) ) : '';
				$dry_run  = ! empty( $_POST['dry_run'] );
				$patterns = array();

				foreach ( (array) ( isset( $_POST['patterns'] ) ? wp_unslash( $_POST['patterns'] ) : array() ) as $code => $pattern ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					$patterns[ sanitize_key( $code ) ] = sanitize_text_field( $pattern );
				}

				if ( ! $query ) {
					add_settings_error( 'pivot_tools', 'no_query', __( 'Indiquez le code de la requête à traiter.', 'pivot-offres' ), 'error' );
					break;
				}

				$result = Pivot_Redirects::generate_from_query(
					$query,
					array( 'patterns' => $patterns, 'dry_run' => $dry_run )
				);

				if ( is_wp_error( $result ) ) {
					add_settings_error( 'pivot_tools', 'generate_failed', $result->get_error_message(), 'error' );
					break;
				}

				// Le détail est rendu sous forme de tableau : un message d'une
				// seule ligne resterait illisible.
				self::$generation = $result;

				if ( $dry_run ) {
					// La simulation attend d'être validée ou refusée. Rien ne
					// justifierait de réinterroger PIVOT pour confirmer.
					Pivot_Redirects::store_preview( $result );
				} else {
					Pivot_Redirects::clear_preview();
				}

				add_settings_error(
					'pivot_tools',
					'generated',
					$dry_run
						? sprintf(
							/* translators: %d : nombre de redirections. */
							__( 'Simulation terminée : %d redirections seraient créées. Rien n\'a été enregistré.', 'pivot-offres' ),
							(int) $result['created']
						)
						: sprintf(
							/* translators: 1 : créées, 2 : total. */
							__( '%1$d redirections enregistrées. La table en compte %2$d au total.', 'pivot-offres' ),
							(int) $result['created'],
							(int) $result['total']
						),
					'updated'
				);
				break;

			case 'apply_redirects':
				$applied = Pivot_Redirects::apply_preview();

				add_settings_error(
					'pivot_tools',
					'redirects_applied',
					sprintf(
						/* translators: 1 : enregistrées, 2 : total. */
						__( '%1$d redirections enregistrées. La table en compte %2$d au total.', 'pivot-offres' ),
						(int) $applied['applied'],
						(int) $applied['total']
					),
					'updated'
				);
				break;

			case 'discard_redirects':
				Pivot_Redirects::clear_preview();
				add_settings_error(
					'pivot_tools',
					'redirects_discarded',
					__( 'Simulation abandonnée. Rien n\'a été enregistré.', 'pivot-offres' ),
					'updated'
				);
				break;

			case 'add_redirect':
				$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
				$to   = isset( $_POST['to'] ) ? esc_url_raw( wp_unslash( $_POST['to'] ) ) : '';

				if ( Pivot_Redirects::add( $from, $to ) ) {
					add_settings_error( 'pivot_tools', 'redirect_added', __( 'Redirection ajoutée.', 'pivot-offres' ), 'updated' );
				} else {
					add_settings_error( 'pivot_tools', 'redirect_failed', __( 'Redirection refusée : vérifiez que la source et la cible sont différentes et non vides.', 'pivot-offres' ), 'error' );
				}
				break;

			case 'remove_redirect':
				$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
				Pivot_Redirects::remove( $from );
				add_settings_error( 'pivot_tools', 'redirect_removed', __( 'Redirection supprimée.', 'pivot-offres' ), 'updated' );
				break;

			case 'clear_renames':
				Pivot_Slugs::clear_changes();
				add_settings_error( 'pivot_tools', 'renames_cleared', __( 'Relevé des renommages effacé.', 'pivot-offres' ), 'updated' );
				break;

			case 'reset_slugs':
				Pivot_Slugs::flush();
				add_settings_error(
					'pivot_tools',
					'slugs_flushed',
					__( 'Registre des adresses vidé. Les fiches repartiront du nom actuel de chaque offre à la prochaine construction d\'index.', 'pivot-offres' ),
					'updated'
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

			case 'clear_redirects':
				Pivot_Redirects::save( array() );
				add_settings_error( 'pivot_tools', 'redirects_cleared', __( 'Table de redirections vidée.', 'pivot-offres' ), 'updated' );
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
			'registry'  => __( 'Registre des adresses', 'pivot-offres' ),
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
		echo '<p class="description">' . esc_html__( 'Tout vider épargne le registre des adresses : lui seul ne se reconstruit pas depuis PIVOT, et le perdre changerait l\'adresse des fiches lorsque les slugs sont figés.', 'pivot-offres' ) . '</p>';
		echo '</form>';
	}

	/**
	 * Bloc « Redirections ».
	 */
	private static function section_redirects() {
		$map = Pivot_Redirects::all();

		echo '<hr />';
		echo '<h2>' . esc_html__( 'Redirections 301', 'pivot-offres' ) . '</h2>';
		echo '<p>' . esc_html__( 'La forme /details/CODEPIVOT&type=IDTYPE est déjà reconnue sans configuration. Cet outil sert à couvrir d\'autres anciennes adresses, par lot, à partir des offres d\'une requête.', 'pivot-offres' ) . '</p>';

		echo '<form method="post">';
		wp_nonce_field( 'pivot_tools' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Requête à parcourir', 'pivot-offres' ) . '</th><td>';
		echo '<input type="text" name="query_code" class="regular-text code" placeholder="QRY-00-0000-0000" />';
		echo '<p class="description">' . esc_html__( 'Toutes les offres retournées par cette requête recevront une redirection vers leur nouvelle fiche.', 'pivot-offres' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Gabarits des anciennes URL', 'pivot-offres' ) . '</th><td>';

		foreach ( Pivot_I18n::languages() as $code ) {
			$placeholder = ( $code === Pivot_I18n::default_lang() ? '' : '/' . $code )
				. '/ancienne-rubrique/{code}&type={type}';

			if ( Pivot_I18n::is_multilingual() ) {
				printf( '<p><label><span class="pivot-lang-tag">%s</span> ', esc_html( strtoupper( $code ) ) );
			} else {
				echo '<p><label>';
			}

			printf(
				'<input type="text" name="patterns[%s]" value="" class="large-text code" placeholder="%s" /></label></p>',
				esc_attr( $code ),
				esc_attr( $placeholder )
			);
		}

		echo '<p class="description">' . esc_html__( 'Jetons disponibles : {code}, {type}, {slug}, {lang}. Chaque gabarit pointe vers la fiche dans sa langue : une seule passe suffit pour tout le site. Une langue laissée vide est ignorée.', 'pivot-offres' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Inutile de viser /details/ : cette forme est déjà reconnue par le plugin, les entrées correspondantes sont ignorées.', 'pivot-offres' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Mode', 'pivot-offres' ) . '</th><td>';
		echo '<label><input type="checkbox" name="dry_run" value="1" checked /> ' . esc_html__( 'Simuler sans enregistrer', 'pivot-offres' ) . '</label>';
		echo '</td></tr>';

		echo '</tbody></table>';
		printf(
			'<p><button type="submit" name="pivot_action" value="generate_redirects" class="button button-primary">%s</button></p>',
			esc_html__( 'Générer les redirections', 'pivot-offres' )
		);
		echo '</form>';

		self::render_generation();

		echo '<h3>' . esc_html__( 'Ajouter une redirection', 'pivot-offres' ) . '</h3>';
		echo '<form method="post" class="pivot-inline-form">';
		wp_nonce_field( 'pivot_tools' );
		printf(
			'<input type="text" name="from" class="regular-text code" placeholder="%s" /> → <input type="url" name="to" class="regular-text code" placeholder="%s" /> ',
			esc_attr__( '/ancienne-page', 'pivot-offres' ),
			esc_attr( home_url( '/offre/nom-ald-01-00096z/' ) )
		);
		printf(
			'<button type="submit" name="pivot_action" value="add_redirect" class="button">%s</button>',
			esc_html__( 'Ajouter', 'pivot-offres' )
		);
		echo '</form>';

		printf(
			'<h3>%s</h3>',
			esc_html(
				sprintf(
					/* translators: %d: nombre de redirections. */
					__( 'Table actuelle (%d)', 'pivot-offres' ),
					count( $map )
				)
			)
		);

		if ( $map ) {
			$preview = array_slice( $map, 0, 50, true );

			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Source', 'pivot-offres' ) . '</th>';
			echo '<th>' . esc_html__( 'Cible', 'pivot-offres' ) . '</th>';
			echo '<th style="width:100px"></th>';
			echo '</tr></thead><tbody>';

			foreach ( $preview as $from => $to ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( $from ) . '</code></td>';
				echo '<td><a href="' . esc_url( $to ) . '" target="_blank" rel="noopener">' . esc_html( $to ) . '</a></td>';
				echo '<td><form method="post">';
				wp_nonce_field( 'pivot_tools' );
				printf( '<input type="hidden" name="from" value="%s" />', esc_attr( $from ) );
				printf(
					'<button type="submit" name="pivot_action" value="remove_redirect" class="button-link">%s</button>',
					esc_html__( 'Retirer', 'pivot-offres' )
				);
				echo '</form></td>';
				echo '</tr>';
			}

			echo '</tbody></table>';

			if ( count( $map ) > count( $preview ) ) {
				printf(
					'<p class="description">%s</p>',
					esc_html(
						sprintf(
							/* translators: %d: nombre restant. */
							__( '%d autres redirections ne sont pas affichées. Exportez la table pour la consulter en entier.', 'pivot-offres' ),
							count( $map ) - count( $preview )
						)
					)
				);
			}

			echo '<form method="post" class="pivot-tool-actions">';
			wp_nonce_field( 'pivot_tools' );
			echo '<p><select name="format">';
			echo '<option value="csv">CSV</option>';
			echo '<option value="htaccess">.htaccess</option>';
			echo '<option value="nginx">nginx</option>';
			echo '</select> ';
			printf(
				'<button type="submit" name="pivot_export_redirects" value="1" class="button">%s</button> ',
				esc_html__( 'Exporter', 'pivot-offres' )
			);
			printf(
				'<button type="submit" name="pivot_action" value="clear_redirects" class="button button-link-delete">%s</button>',
				esc_html__( 'Vider la table', 'pivot-offres' )
			);
			echo '</p></form>';
		} else {
			echo '<p>' . esc_html__( 'Aucune redirection enregistrée.', 'pivot-offres' ) . '</p>';
		}
	}

	/**
	 * Détail de la dernière génération, sous forme de tableau.
	 */
	private static function render_generation() {
		$result = self::$generation;

		// Une simulation faite lors d'un chargement précédent reste proposée :
		// on ne perd pas son travail en rechargeant la page.
		if ( ! $result ) {
			$result = Pivot_Redirects::get_preview();
		}

		if ( ! $result ) {
			return;
		}

		printf(
			'<h4>%s</h4>',
			esc_html(
				! empty( $result['dry_run'] )
					? __( 'Aperçu de ce qui serait créé', 'pivot-offres' )
					: __( 'Redirections créées', 'pivot-offres' )
			)
		);

		$counts = array();

		$counts[] = sprintf(
			/* translators: %d : nombre. */
			_n( '%d redirection', '%d redirections', (int) $result['created'], 'pivot-offres' ),
			(int) $result['created']
		);

		if ( ! empty( $result['native'] ) ) {
			$counts[] = sprintf(
				/* translators: %d : nombre. */
				_n( '%d adresse déjà gérée par le plugin, ignorée', '%d adresses déjà gérées par le plugin, ignorées', (int) $result['native'], 'pivot-offres' ),
				(int) $result['native']
			);
		}

		if ( ! empty( $result['skipped'] ) ) {
			$counts[] = sprintf(
				/* translators: %d : nombre. */
				_n( '%d inchangée', '%d inchangées', (int) $result['skipped'], 'pivot-offres' ),
				(int) $result['skipped']
			);
		}

		$langs = array_map( array( 'Pivot_I18n', 'name' ), (array) pivot_get( $result, 'langs', array() ) );

		printf(
			'<p class="description">%s — %s</p>',
			esc_html( implode( ' · ', $counts ) ),
			esc_html(
				sprintf(
					/* translators: %s : liste de langues. */
					__( 'destinations en %s', 'pivot-offres' ),
					implode( ', ', $langs )
				)
			)
		);

		if ( empty( $result['sample'] ) ) {
			return;
		}

		$show_lang = count( (array) pivot_get( $result, 'langs', array() ) ) > 1;

		echo '<table class="widefat striped pivot-generation-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Offre', 'pivot-offres' ) . '</th>';

		if ( $show_lang ) {
			echo '<th style="width:70px">' . esc_html__( 'Langue', 'pivot-offres' ) . '</th>';
		}

		echo '<th>' . esc_html__( 'Ancienne adresse', 'pivot-offres' ) . '</th>';
		echo '<th>' . esc_html__( 'Nouvelle adresse', 'pivot-offres' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $result['sample'] as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( pivot_get( $row, 'name', '' ) ) . '</td>';

			if ( $show_lang ) {
				echo '<td><span class="pivot-lang-tag">' . esc_html( strtoupper( pivot_get( $row, 'lang', '' ) ) ) . '</span></td>';
			}

			echo '<td><code>' . esc_html( pivot_get( $row, 'from', '' ) ) . '</code></td>';
			echo '<td><a href="' . esc_url( pivot_get( $row, 'to', '' ) ) . '" target="_blank" rel="noopener"><code>'
				. esc_html( pivot_get( $row, 'to', '' ) ) . '</code></a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( (int) $result['created'] > count( $result['sample'] ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d : nombre de lignes non affichées. */
						__( '%d autres ne sont pas affichées ici.', 'pivot-offres' ),
						(int) $result['created'] - count( $result['sample'] )
					)
				)
			);
		}

		if ( empty( $result['pending'] ) ) {
			return;
		}

		echo '<form method="post" class="pivot-generation-decision">';
		wp_nonce_field( 'pivot_tools' );

		printf(
			'<button type="submit" name="pivot_action" value="apply_redirects" class="button button-primary">%s</button> ',
			esc_html(
				sprintf(
					/* translators: %d : nombre de redirections. */
					_n( 'Enregistrer cette redirection', 'Enregistrer ces %d redirections', (int) $result['created'], 'pivot-offres' ),
					(int) $result['created']
				)
			)
		);

		printf(
			'<button type="submit" name="pivot_action" value="discard_redirects" class="button">%s</button>',
			esc_html__( 'Abandonner', 'pivot-offres' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Rien n\'a encore été écrit. La simulation reste disponible tant que vous n\'avez pas tranché, même si vous quittez cet écran.', 'pivot-offres' )
		);

		echo '</form>';
	}

	/**
	 * Bloc « Offres renommées ».
	 */
	private static function section_renames() {
		$changes = array_reverse( Pivot_Slugs::changes() );
		$frozen  = 'freeze' === pivot_settings( 'slug_mode', 'follow' );

		echo '<hr />';
		echo '<h2>' . esc_html__( 'Offres renommées', 'pivot-offres' ) . '</h2>';

		if ( ! $changes ) {
			echo '<p>' . esc_html__( 'Aucun changement de dénomination relevé depuis la mise en service. Les renommages faits dans PIVOT apparaîtront ici après la reconstruction de l\'index.', 'pivot-offres' ) . '</p>';
		} else {
			echo '<p>';
			echo esc_html(
				$frozen
					? __( 'Ces offres ont changé de nom dans PIVOT. Leur adresse est figée : elle n\'a pas bougé.', 'pivot-offres' )
					: __( 'Ces offres ont changé de nom dans PIVOT, et leur adresse a suivi. L\'ancienne redirige en 301 vers la nouvelle ; pensez à prévenir vos partenaires si vous leur aviez communiqué un lien.', 'pivot-offres' )
			);
			echo '</p>';

			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Date', 'pivot-offres' ) . '</th>';
			echo '<th>' . esc_html__( 'Ancien nom', 'pivot-offres' ) . '</th>';
			echo '<th>' . esc_html__( 'Nouveau nom', 'pivot-offres' ) . '</th>';
			echo '<th>' . esc_html__( 'Adresse', 'pivot-offres' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( array_slice( $changes, 0, 30 ) as $change ) {
				echo '<tr>';
				echo '<td>' . esc_html( wp_date( 'd/m/Y H:i', (int) pivot_get( $change, 'time', time() ) ) ) . '</td>';
				echo '<td>' . esc_html( pivot_get( $change, 'old_name', '' ) ) . '</td>';
				echo '<td>' . esc_html( pivot_get( $change, 'name', '' ) );

				if ( Pivot_I18n::is_multilingual() ) {
					echo ' <span class="pivot-lang-tag">' . esc_html( strtoupper( pivot_get( $change, 'lang', '' ) ) ) . '</span>';
				}

				echo '</td><td>';

				if ( ! empty( $change['frozen'] ) ) {
					echo '<code>' . esc_html( pivot_get( $change, 'to', '' ) ) . '</code> ';
					echo '<span class="pivot-badge">' . esc_html__( 'inchangée', 'pivot-offres' ) . '</span>';
				} else {
					echo '<code>' . esc_html( pivot_get( $change, 'from', '' ) ) . '</code><br />→ <code>'
						. esc_html( pivot_get( $change, 'to', '' ) ) . '</code>';
				}

				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}

		echo '<form method="post" class="pivot-tool-actions">';
		wp_nonce_field( 'pivot_tools' );
		echo '<p>';

		if ( $changes ) {
			printf(
				'<button type="submit" name="pivot_action" value="clear_renames" class="button">%s</button> ',
				esc_html__( 'Effacer ce relevé', 'pivot-offres' )
			);
		}

		printf(
			'<button type="submit" name="pivot_action" value="reset_slugs" class="button button-link-delete">%s</button>',
			esc_html__( 'Réinitialiser le registre des adresses', 'pivot-offres' )
		);

		echo '</p></form>';
	}

	/**
	 * Bloc « Diagnostic ».
	 */
	private static function section_diagnostics() {
		self::section_renames();

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
			$url       = Pivot_Rewrites::detail_url( 'ALD-01-00096Z', 11, 'Exemple', $lang );
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
