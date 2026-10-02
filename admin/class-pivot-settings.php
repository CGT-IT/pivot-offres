<?php
/**
 * Écran de réglages.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Settings {

	const OPTION = 'pivot_settings';

	/** @var Pivot_Settings|null */
	private static $instance = null;

	/**
	 * @return Pivot_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	/**
	 * Déclare les sections et champs.
	 */
	public function register() {
		register_setting(
			'pivot_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'pivot_connection',
			__( 'Connexion à PIVOT', 'pivot-offres' ),
			function () {
				echo '<p>' . esc_html__( 'Choisissez l\'environnement interrogé et enregistrez la clé correspondante. Chaque environnement a sa propre clé : basculer de l\'un à l\'autre ne demande aucune autre modification.', 'pivot-offres' ) . '</p>';
			},
			'pivot-settings'
		);

		$this->field( 'environment', __( 'Environnement actif', 'pivot-offres' ), 'pivot_connection', 'select', array(
			'options' => array(
				'stage' => __( 'Stage (recette)', 'pivot-offres' ),
				'prod'  => __( 'Production', 'pivot-offres' ),
			),
		) );

		$this->field( 'url_stage', __( 'URL du service — stage', 'pivot-offres' ), 'pivot_connection', 'url' );
		$this->field( 'wskey_stage', __( 'Clé ws_key — stage', 'pivot-offres' ), 'pivot_connection', 'password' );
		$this->field( 'url_prod', __( 'URL du service — production', 'pivot-offres' ), 'pivot_connection', 'url' );
		$this->field( 'wskey_prod', __( 'Clé ws_key — production', 'pivot-offres' ), 'pivot_connection', 'password' );

		$this->field( 'timeout', __( 'Délai d\'attente (secondes)', 'pivot-offres' ), 'pivot_connection', 'number', array(
			'min'  => 5,
			'max'  => 300,
			'help' => __( 'Au-delà de ce délai, l\'appel est abandonné et l\'erreur est journalisée.', 'pivot-offres' ),
		) );

		add_settings_section(
			'pivot_languages',
			__( 'Langues', 'pivot-offres' ),
			function () {
				echo '<p>' . esc_html__( 'Le plugin ne gère pas les langues lui-même : il suit l\'extension de traduction installée sur le site. Les libellés des offres, eux, viennent toujours de PIVOT, qui les fournit en français, néerlandais, anglais et allemand.', 'pivot-offres' ) . '</p>';
			},
			'pivot-settings'
		);

		$this->field( 'detected_languages', __( 'Extension détectée', 'pivot-offres' ), 'pivot_languages', 'detected' );

		$this->field( 'hreflang', __( 'Liens alternates', 'pivot-offres' ), 'pivot_languages', 'checkbox', array(
			'label' => __( 'Publier les balises hreflang entre versions linguistiques', 'pivot-offres' ),
			'help'  => __( 'Les pages du plugin ne sont pas des articles WordPress : les extensions de traduction ne publient pas leurs alternates. Laissez cette case cochée sur un site multilingue.', 'pivot-offres' ),
		) );

		add_settings_section(
			'pivot_cache',
			__( 'Durées de cache', 'pivot-offres' ),
			function () {
				echo '<p>' . esc_html__( 'Aucune offre n\'est enregistrée en base de données. Tout est mis en cache dans des fichiers JSON, renouvelés automatiquement à l\'expiration des durées ci-dessous, et réinitialisables à tout moment depuis l\'écran Cache et outils.', 'pivot-offres' ) . '</p>';
			},
			'pivot-settings'
		);

		$this->field( 'ttl_index', __( 'Listes d\'offres', 'pivot-offres' ), 'pivot_cache', 'duration', array(
			'help' => __( 'Fréquence de reconstruction complète de l\'index qui alimente les pages de listing. Avec la mise à jour par différentiel, c\'est une reconstruction de sécurité, faite la nuit : 7 jours suffisent.', 'pivot-offres' ),
		) );

		$this->field( 'diff_enabled', __( 'Mise à jour par différentiel', 'pivot-offres' ), 'pivot_cache', 'checkbox', array(
			'label' => __( 'Chaque nuit, ne demander à PIVOT que les offres ajoutées, modifiées ou retirées', 'pivot-offres' ),
			'help'  => __( 'PIVOT tient un seul différentiel par clé et par requête. Décochez cette case sur une copie du site qui utilise la même clé : les deux sites se voleraient les changements.', 'pivot-offres' ),
		) );

		$this->field( 'sync_time', __( 'Heure de la mise à jour', 'pivot-offres' ), 'pivot_cache', 'time', array(
			'help' => __( 'Heure du site. WordPress lance ses tâches à la première visite qui suit : pour une heure exacte, faites appeler wp-cron.php par une tâche cron du serveur.', 'pivot-offres' ),
		) );

		$this->field( 'ttl_offer', __( 'Fiches détail', 'pivot-offres' ), 'pivot_cache', 'duration' );

		$this->field( 'ttl_thesaurus', __( 'Thesaurus', 'pivot-offres' ), 'pivot_cache', 'duration', array(
			'help' => __( 'Types d\'offres, structures de champs, libellés et localités. Ces données bougent rarement : une durée longue est recommandée.', 'pivot-offres' ),
		) );

		$this->field( 'ttl_negative', __( 'Erreurs', 'pivot-offres' ), 'pivot_cache', 'duration', array(
			'help' => __( 'Durée pendant laquelle une offre introuvable n\'est pas redemandée au service.', 'pivot-offres' ),
		) );

		$this->field( 'batch_size', __( 'Offres par appel', 'pivot-offres' ), 'pivot_cache', 'number', array(
			'min'  => 10,
			'max'  => 500,
			'help' => __( 'Taille des lots lors de la construction d\'un index. Réduisez cette valeur si votre hébergeur coupe les requêtes longues.', 'pivot-offres' ),
		) );

		$this->field( 'index_delivery', __( 'Livraison de l\'index au navigateur', 'pivot-offres' ), 'pivot_cache', 'select', array(
			'options' => array(
				'file' => __( 'Fichier statique (recommandé)', 'pivot-offres' ),
				'rest' => __( 'Route REST', 'pivot-offres' ),
			),
			'help'    => __( 'Le fichier statique évite de charger WordPress à chaque affichage de liste. Passez en REST si votre dossier uploads n\'est pas servi directement.', 'pivot-offres' ),
		) );

		add_settings_section(
			'pivot_display',
			__( 'Affichage', 'pivot-offres' ),
			function () {
				echo '<p>' . esc_html__( 'Réglages communs à toutes les pages générées par le plugin.', 'pivot-offres' ) . '</p>';
			},
			'pivot-settings'
		);

		$this->field( 'thumb', __( 'Taille des vignettes', 'pivot-offres' ), 'pivot_display', 'select', array(
			'options' => array(
				'THB_SF' => 'THB_SF — 150x150',
				'THB_SW' => 'THB_SW — 150 de large',
				'THB_MF' => 'THB_MF — 300x300',
				'THB_MW' => 'THB_MW — 300 de large',
				'THB_LF' => 'THB_LF — 480x480',
				'THB_LW' => 'THB_LW — 480 de large',
			),
		) );

		$this->field( 'schema_org', __( 'Données structurées', 'pivot-offres' ), 'pivot_display', 'checkbox', array(
			'label' => __( 'Publier le balisage schema.org (JSON-LD) sur les listes et les fiches', 'pivot-offres' ),
		) );

		$this->field( 'sitemap', __( 'Plan du site', 'pivot-offres' ), 'pivot_display', 'checkbox', array(
			'label' => __( 'Ajouter les pages de listing et les fiches au plan du site XML de WordPress', 'pivot-offres' ),
			'help'  => __( 'Les adresses sont relevées dans les index déjà construits, sans appel à PIVOT. Sans effet si une extension SEO remplace le plan du site de WordPress par le sien.', 'pivot-offres' ),
		) );

		$this->field( 'llms_txt', __( 'Fichier llms.txt', 'pivot-offres' ), 'pivot_display', 'checkbox', array(
			'label' => __( 'Publier /llms.txt, le sommaire du site à l\'usage des agents conversationnels', 'pivot-offres' ),
			'help'  => __( 'Liste les pages de listing dans chaque langue, avec leur description. Un fichier llms.txt déposé à la racine du site l\'emporte.', 'pivot-offres' ),
		) );

		add_settings_section(
			'pivot_map',
			__( 'Cartographie', 'pivot-offres' ),
			function () {
				echo '<p>' . esc_html__( 'La carte s\'affiche uniquement sur les pages de listing où vous l\'avez activée.', 'pivot-offres' ) . '</p>';
			},
			'pivot-settings'
		);

		$this->field( 'map_provider', __( 'Moteur de carte', 'pivot-offres' ), 'pivot_map', 'select', array(
			'options' => array(
				'leaflet' => __( 'Leaflet + fond de carte au choix', 'pivot-offres' ),
				'none'    => __( 'Aucune carte', 'pivot-offres' ),
			),
		) );

		$this->field( 'map_tiles', __( 'URL des tuiles', 'pivot-offres' ), 'pivot_map', 'text' );
		$this->field( 'map_attribution', __( 'Mention du fournisseur', 'pivot-offres' ), 'pivot_map', 'text' );

		$this->field( 'map_cluster', __( 'Regroupement', 'pivot-offres' ), 'pivot_map', 'checkbox', array(
			'label' => __( 'Regrouper les points proches en grappes', 'pivot-offres' ),
		) );

		add_settings_section(
			'pivot_logs',
			__( 'Journalisation', 'pivot-offres' ),
			function () {
				echo '<p>' . esc_html__( 'Le journal enregistre les appels au webservice pour faciliter le diagnostic. Il ne conserve jamais la clé ws_key.', 'pivot-offres' ) . '</p>';
			},
			'pivot-settings'
		);

		$this->field( 'logs_enabled', __( 'Activer le journal', 'pivot-offres' ), 'pivot_logs', 'checkbox', array(
			'label' => __( 'Enregistrer les appels à PIVOT', 'pivot-offres' ),
		) );

		$this->field( 'logs_level', __( 'Niveau enregistré', 'pivot-offres' ), 'pivot_logs', 'select', array(
			'options' => array(
				'debug' => __( 'Tout, y compris les lectures en cache', 'pivot-offres' ),
				'info'  => __( 'Appels réseau et erreurs', 'pivot-offres' ),
				'warn'  => __( 'Avertissements et erreurs', 'pivot-offres' ),
				'error' => __( 'Erreurs seulement', 'pivot-offres' ),
			),
		) );

		$this->field( 'logs_retention', __( 'Conservation (jours)', 'pivot-offres' ), 'pivot_logs', 'number', array(
			'min'  => 1,
			'max'  => 90,
			'help' => __( 'Une tâche quotidienne supprime les entrées plus anciennes.', 'pivot-offres' ),
		) );
	}

	/**
	 * Déclare un champ.
	 *
	 * @param string $key     Clé de réglage.
	 * @param string $label   Libellé.
	 * @param string $section Section.
	 * @param string $type    Type de contrôle.
	 * @param array  $args    Options.
	 */
	private function field( $key, $label, $section, $type = 'text', $args = array() ) {
		add_settings_field(
			$key,
			$label,
			array( $this, 'render_field' ),
			'pivot-settings',
			$section,
			array_merge( $args, array( 'key' => $key, 'type' => $type, 'label_for' => 'pivot-' . $key ) )
		);
	}

	/**
	 * Affiche un champ.
	 *
	 * @param array $args Arguments.
	 */
	public function render_field( $args ) {
		$key   = $args['key'];
		$type  = $args['type'];
		$id    = 'pivot-' . $key;
		$name  = self::OPTION . '[' . $key . ']';
		$value = pivot_settings( $key, '' );

		switch ( $type ) {
			case 'select':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
				foreach ( (array) $args['options'] as $option_value => $option_label ) {
					printf(
						'<option value="%s"%s>%s</option>',
						esc_attr( $option_value ),
						selected( (string) $value, (string) $option_value, false ),
						esc_html( $option_label )
					);
				}
				echo '</select>';
				break;

			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%s" name="%s" value="1"%s /> %s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (int) $value, 1, false ),
					esc_html( pivot_get( $args, 'label', '' ) )
				);
				break;

			case 'number':
				printf(
					'<input type="number" id="%s" name="%s" value="%s" min="%s" max="%s" class="small-text" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( pivot_get( $args, 'min', 0 ) ),
					esc_attr( pivot_get( $args, 'max', 999999 ) )
				);
				break;

			case 'duration':
				$this->render_duration( $id, $name, (int) $value );
				break;

			case 'time':
				printf(
					'<input type="time" id="%s" name="%s" value="%s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			case 'detected':
				$langs = Pivot_I18n::languages();

				echo '<div id="' . esc_attr( $id ) . '" class="pivot-detected-languages">';
				printf( '<p><strong>%s</strong></p>', esc_html( Pivot_I18n::provider_name() ) );

				if ( Pivot_I18n::is_multilingual() ) {
					echo '<p>';

					foreach ( $langs as $code ) {
						$content = in_array( $code, Pivot_I18n::CONTENT_LANGS, true );

						printf(
							'<span class="pivot-lang-tag%1$s" title="%2$s">%3$s</span> ',
							$content ? '' : ' is-missing',
							esc_attr(
								$content
									? __( 'PIVOT fournit les contenus dans cette langue', 'pivot-offres' )
									: __( 'PIVOT ne fournit pas cette langue : les contenus reprendront la langue par défaut', 'pivot-offres' )
							),
							esc_html( strtoupper( $code ) )
						);
					}

					echo '</p>';
					printf(
						'<p class="description">%s</p>',
						esc_html(
							sprintf(
								/* translators: %s : nom de la langue par défaut. */
								__( 'Langue par défaut : %s. Une page de listing et une fiche existent dans chacune de ces langues.', 'pivot-offres' ),
								Pivot_I18n::name( Pivot_I18n::default_lang() )
							)
						)
					);
				} else {
					printf(
						'<p class="description">%s</p>',
						esc_html__( 'Le site est monolingue. Installez et configurez une extension de traduction pour publier vos offres en plusieurs langues : le plugin la suivra sans autre réglage.', 'pivot-offres' )
					);
				}

				echo '</div>';
				break;

			case 'password':
				printf(
					'<input type="password" id="%s" name="%s" value="%s" class="regular-text" autocomplete="off" spellcheck="false" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			case 'url':
				printf(
					'<input type="url" id="%s" name="%s" value="%s" class="large-text code" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			default:
				printf(
					'<input type="text" id="%s" name="%s" value="%s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
		}

		if ( ! empty( $args['help'] ) ) {
			echo '<p class="description">' . wp_kses_post( $args['help'] ) . '</p>';
		}
	}

	/**
	 * Contrôle de durée : nombre + unité.
	 *
	 * @param string $id      Identifiant.
	 * @param string $name    Nom du champ.
	 * @param int    $seconds Valeur en secondes.
	 */
	private function render_duration( $id, $name, $seconds ) {
		$units = array(
			MINUTE_IN_SECONDS => __( 'minutes', 'pivot-offres' ),
			HOUR_IN_SECONDS   => __( 'heures', 'pivot-offres' ),
			DAY_IN_SECONDS    => __( 'jours', 'pivot-offres' ),
		);

		$unit = MINUTE_IN_SECONDS;
		foreach ( array( DAY_IN_SECONDS, HOUR_IN_SECONDS, MINUTE_IN_SECONDS ) as $candidate ) {
			if ( $seconds > 0 && 0 === $seconds % $candidate ) {
				$unit = $candidate;
				break;
			}
		}

		$amount = $seconds > 0 ? (int) round( $seconds / $unit ) : 0;

		printf(
			'<input type="number" id="%s" name="%s[amount]" value="%d" min="0" class="small-text" /> ',
			esc_attr( $id ),
			esc_attr( $name ),
			$amount
		);

		echo '<select name="' . esc_attr( $name ) . '[unit]">';
		foreach ( $units as $value => $label ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $value,
				selected( $unit, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Nettoie les réglages avant enregistrement.
	 *
	 * @param array $input Données du formulaire.
	 * @return array
	 */
	public function sanitize( $input ) {
		$current = get_option( self::OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		$clean   = $current;

		$text_keys = array( 'environment', 'thumb', 'map_provider', 'index_delivery', 'logs_level' );
		foreach ( $text_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = sanitize_text_field( $input[ $key ] );
			}
		}

		foreach ( array( 'url_prod', 'url_stage' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = esc_url_raw( trim( $input[ $key ] ) );
			}
		}

		foreach ( array( 'wskey_prod', 'wskey_stage' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = trim( sanitize_text_field( $input[ $key ] ) );
			}
		}

		foreach ( array( 'map_tiles', 'map_attribution' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = wp_kses_post( trim( $input[ $key ] ) );
			}
		}

		$clean['timeout']        = isset( $input['timeout'] ) ? max( 5, min( 300, (int) $input['timeout'] ) ) : 30;
		$clean['batch_size']     = isset( $input['batch_size'] ) ? max( 10, min( 500, (int) $input['batch_size'] ) ) : 100;
		$clean['logs_retention'] = isset( $input['logs_retention'] ) ? max( 1, min( 90, (int) $input['logs_retention'] ) ) : 7;

		foreach ( array( 'logs_enabled', 'map_cluster', 'schema_org', 'sitemap', 'llms_txt', 'hreflang', 'diff_enabled' ) as $key ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$sync_time          = isset( $input['sync_time'] ) ? trim( (string) $input['sync_time'] ) : '';
		$clean['sync_time'] = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $sync_time ) ? $sync_time : '04:00';

		foreach ( array( 'ttl_index', 'ttl_offer', 'ttl_thesaurus', 'ttl_negative' ) as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			$amount        = isset( $input[ $key ]['amount'] ) ? max( 0, (int) $input[ $key ]['amount'] ) : 0;
			$unit          = isset( $input[ $key ]['unit'] ) ? max( 1, (int) $input[ $key ]['unit'] ) : MINUTE_IN_SECONDS;
			$clean[ $key ] = $amount * $unit;
		}

		// Changer d'environnement invalide les caches : les données ne viennent plus du même serveur.
		if ( pivot_get( $current, 'environment' ) !== pivot_get( $clean, 'environment' ) ) {
			Pivot_Cache::flush();
			add_settings_error(
				'pivot_settings',
				'env_changed',
				__( 'Environnement changé : tous les caches ont été vidés.', 'pivot-offres' ),
				'info'
			);
		}

		return $clean;
	}

	/**
	 * Affiche l'écran.
	 */
	public static function render() {
		if ( ! current_user_can( pivot_capability() ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas accès à cet écran.', 'pivot-offres' ) );
		}

		$test = null;

		if ( isset( $_POST['pivot_test_connection'] ) ) {
			check_admin_referer( 'pivot_test_connection' );
			$test = Pivot_Client::test_connection();
		}

		echo '<div class="wrap pivot-wrap">';
		echo '<h1>' . esc_html__( 'Réglages PIVOT', 'pivot-offres' ) . '</h1>';

		settings_errors( 'pivot_settings' );

		if ( $test ) {
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				$test['ok'] ? 'success' : 'error',
				esc_html( $test['message'] )
			);
		}

		echo '<form method="post" action="options.php">';
		settings_fields( 'pivot_settings_group' );
		do_settings_sections( 'pivot-settings' );
		submit_button( __( 'Enregistrer les réglages', 'pivot-offres' ) );
		echo '</form>';

		echo '<hr />';
		echo '<h2>' . esc_html__( 'Vérifier la connexion', 'pivot-offres' ) . '</h2>';
		echo '<p>' . esc_html__( 'Envoie un appel libre au thesaurus puis un appel authentifié au service query, avec les réglages enregistrés.', 'pivot-offres' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'pivot_test_connection' );
		submit_button( __( 'Tester la connexion', 'pivot-offres' ), 'secondary', 'pivot_test_connection', false );
		echo '</form>';

		echo '</div>';
	}
}
