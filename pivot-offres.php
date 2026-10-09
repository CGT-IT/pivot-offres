<?php
/**
 * Plugin Name:       PIVOT Offres V2
 * Description:       Publie les offres touristiques de PIVOT/Web (Tourisme Wallonie) : pages de listing paramétrables, recherche et pagination 100 % côté client, cartographie, pages détail optimisées SEO, multilingue fr/nl/en/de à partir des traductions renvoyées par PIVOT. Aucune offre n'est stockée en base de données.
 * Version:           2.12.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pivot-offres
 * Domain Path:       /languages
 * Update URI:        https://github.com/mdegembe/pivot-offres
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

define( 'PIVOT_VERSION', '2.12.0' );
define( 'PIVOT_FILE', __FILE__ );
define( 'PIVOT_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIVOT_URL', plugin_dir_url( __FILE__ ) );
define( 'PIVOT_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Mises à jour depuis les Releases GitHub. Branché avant les contrôles
 * préalables : un site bloqué par l'un d'eux doit pouvoir recevoir le correctif.
 */
require_once PIVOT_DIR . 'includes/updater.php';

pivot_register_updater();

/*
 * Contrôles préalables : PHP, extensions, conflits de noms, dossier de cache.
 * En cas de problème le plugin ne charge rien et affiche un message, plutôt
 * que de provoquer une erreur fatale.
 */
require_once PIVOT_DIR . 'includes/preflight.php';

$pivot_problems = pivot_preflight_problems();

if ( $pivot_problems ) {
	pivot_preflight_notice( $pivot_problems );
	return;
}

require_once PIVOT_DIR . 'includes/helpers.php';
require_once PIVOT_DIR . 'includes/class-pivot-i18n.php';
require_once PIVOT_DIR . 'includes/class-pivot-cache.php';
require_once PIVOT_DIR . 'includes/class-pivot-logger.php';
require_once PIVOT_DIR . 'includes/class-pivot-client.php';
require_once PIVOT_DIR . 'includes/class-pivot-parser.php';
require_once PIVOT_DIR . 'includes/class-pivot-thesaurus.php';
require_once PIVOT_DIR . 'includes/class-pivot-listings.php';
require_once PIVOT_DIR . 'includes/class-pivot-repository.php';
require_once PIVOT_DIR . 'includes/class-pivot-index-builder.php';
require_once PIVOT_DIR . 'includes/class-pivot-suggestions.php';
require_once PIVOT_DIR . 'includes/class-pivot-fields.php';
require_once PIVOT_DIR . 'includes/class-pivot-closures.php';
require_once PIVOT_DIR . 'includes/class-pivot-types.php';
require_once PIVOT_DIR . 'includes/class-pivot-rewrites.php';
require_once PIVOT_DIR . 'includes/class-pivot-rest.php';
require_once PIVOT_DIR . 'includes/class-pivot-seo.php';
require_once PIVOT_DIR . 'includes/class-pivot-sitemap.php';
require_once PIVOT_DIR . 'includes/class-pivot-templates.php';
require_once PIVOT_DIR . 'includes/class-pivot-shortcodes.php';
require_once PIVOT_DIR . 'includes/class-pivot-legacy-shortcodes.php';
require_once PIVOT_DIR . 'includes/class-pivot-cron.php';
require_once PIVOT_DIR . 'includes/class-pivot-pictos.php';
// La reprise de l'ancien plugin tourne à l'activation, y compris depuis
// WP-CLI, qui n'est pas is_admin() : elle est déclarée hors du bloc ci-dessous.
require_once PIVOT_DIR . 'includes/class-pivot-legacy-import.php';
// La visite guidée est pilotée depuis l'administration, mais son avancement est
// enregistré par une route REST, qui n'est pas is_admin() : la classe doit donc
// être déclarée en dehors du bloc ci-dessous.
require_once PIVOT_DIR . 'includes/class-pivot-onboarding.php';

if ( is_admin() ) {
	require_once PIVOT_DIR . 'admin/class-pivot-settings.php';
	require_once PIVOT_DIR . 'admin/class-pivot-listing-edit.php';
	require_once PIVOT_DIR . 'admin/class-pivot-tools.php';
	require_once PIVOT_DIR . 'admin/class-pivot-types-admin.php';
	require_once PIVOT_DIR . 'admin/class-pivot-fields-admin.php';
	require_once PIVOT_DIR . 'admin/class-pivot-shortcode-admin.php';
	require_once PIVOT_DIR . 'admin/class-pivot-admin.php';
}

/**
 * Chargeur principal.
 */
final class Pivot_Offres {

	/** @var Pivot_Offres|null */
	private static $instance = null;

	/**
	 * Instance unique.
	 *
	 * @return Pivot_Offres
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// WordPress 6.7 exige que les traductions soient chargées à init au plus
		// tôt : les charger sur plugins_loaded déclenche un avertissement.
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		add_action( 'init', array( $this, 'boot' ), 5 );
		// Les reprises de version se font en administration : une page publique
		// n'a pas à déplacer des fichiers.
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ), 1 );
	}

	/**
	 * Applique les reprises liées à un changement de version.
	 *
	 * Le numéro installé est comparé au numéro courant : tant qu'ils diffèrent,
	 * les reprises tournent une fois, puis le numéro est enregistré.
	 */
	public function maybe_upgrade() {
		$installed = (string) get_option( 'pivot_version', '' );

		if ( PIVOT_VERSION === $installed ) {
			return;
		}

		// Efface le cache laissé en clair sous uploads par les versions
		// antérieures ; il se reconstruit depuis PIVOT.
		$report = Pivot_Cache::purge_legacy_store();

		if ( $report['deleted'] ) {
			Pivot_Logger::info(
				sprintf( 'Ancien cache supprimé : %d fichier(s) en clair.', $report['deleted'] ),
				array( 'service' => 'cache' )
			);
		}

		Pivot_Cache::ensure_directory();

		if ( version_compare( $installed, '2.6.0', '<' ) ) {
			self::drop_old_detail_urls();
		}

		// Pages de l'ancien plugin PIVOT, si l'activation ne les a pas reprises :
		// activé pendant que l'ancien l'était encore, ce plugin s'arrête au
		// contrôle de collisions, et son hook d'activation n'est jamais
		// enregistré. Sans effet une fois la reprise faite. Une erreur ici
		// bloquerait toute l'administration : elle est consignée, pas propagée.
		try {
			Pivot_Legacy_Import::maybe_run();
		} catch ( Throwable $e ) {
			Pivot_Logger::error(
				'Reprise de l\'ancien plugin interrompue : ' . $e->getMessage(),
				array( 'service' => 'import' )
			);
		}

		update_option( 'pivot_version', PIVOT_VERSION, false );
	}

	/**
	 * Retire ce qui servait aux anciennes adresses de fiches.
	 *
	 * Jusqu'en 2.5, une fiche était publiée sous /offre/nom-CODE/, vers
	 * laquelle /details/ et une table de 301 redirigeaient. Depuis 2.6.0,
	 * /details/CODE&type=ID est l'adresse des fiches : la table, les réglages
	 * d'URL et le relevé des renommages n'ont plus d'usage. Les index portent
	 * encore les anciennes adresses : ils sont reconstruits.
	 */
	private static function drop_old_detail_urls() {
		delete_option( 'pivot_redirects' );
		delete_option( 'pivot_redirects_count' );

		// Tourne à admin_init priorité 1, avant que Pivot_Settings n'accroche
		// son nettoyage de formulaire à cette option : elle est réécrite telle
		// quelle.
		$settings = get_option( 'pivot_settings', array() );

		if ( is_array( $settings ) ) {
			unset( $settings['detail_base'], $settings['detail_bases'], $settings['legacy_mode'], $settings['slug_mode'] );
			update_option( 'pivot_settings', $settings );
		}

		$listings = get_option( Pivot_Listings::OPTION, array() );

		if ( is_array( $listings ) ) {
			foreach ( array_keys( $listings ) as $id ) {
				unset( $listings[ $id ]['renamed'] );
			}

			update_option( Pivot_Listings::OPTION, $listings, false );
		}

		foreach ( Pivot_Listings::active() as $listing_id => $listing ) {
			// Un index jamais construit ne porte aucune ancienne adresse. Sur
			// une installation neuve — version installée vide —, ce sont les
			// pages que la reprise de l'ancien plugin vient de créer : elle a
			// déjà échelonné leur construction.
			if ( empty( $listing['index_built'] ) ) {
				continue;
			}

			Pivot_Index_Builder::invalidate( $listing_id );
			wp_schedule_single_event( time() + 5, 'pivot_continue_index', array( $listing_id ) );
		}

		Pivot_Rewrites::instance()->schedule_flush();
	}

	/**
	 * Traductions.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'pivot-offres', false, dirname( PIVOT_BASENAME ) . '/languages' );
	}

	/**
	 * Démarrage des sous-systèmes.
	 */
	public function boot() {
		Pivot_I18n::bootstrap();
		Pivot_Logger::instance();
		Pivot_Rewrites::instance();
		Pivot_Templates::instance();
		Pivot_Shortcodes::instance();
		Pivot_Legacy_Shortcodes::instance();
		Pivot_Seo::instance();
		Pivot_Rest::instance();
		Pivot_Cron::instance();
		Pivot_Pictos::instance();

		if ( is_admin() ) {
			Pivot_Admin::instance();
		}
	}
}

Pivot_Offres::instance();

/**
 * Activation : crée la table de logs et le dossier de cache, reprend les pages
 * de l'ancien plugin PIVOT et rafraîchit les permaliens.
 */
function pivot_activate() {
	// Le plugin est déjà chargé à ce stade : inutile de tester les collisions,
	// ses propres noms sont forcément déclarés.
	$problems = pivot_preflight_problems( false );

	if ( $problems ) {
		// Message lisible dans l'écran d'activation, au lieu d'un écran blanc.
		$lines = array();

		foreach ( $problems as $problem ) {
			$lines[] = esc_html( pivot_preflight_message( $problem ) );
		}

		wp_die(
			'<h1>' . esc_html__( 'Activation impossible', 'pivot-offres' ) . '</h1><p>'
			. esc_html__( 'PIVOT Offres ne peut pas fonctionner sur ce site :', 'pivot-offres' )
			. '</p><ul><li>' . implode( '</li><li>', $lines ) . '</li></ul>',
			esc_html__( 'Activation impossible', 'pivot-offres' ),
			array( 'back_link' => true )
		);
	}

	// Chaque étape est isolée : un échec est signalé, il ne casse pas l'activation.
	// L'empreinte linguistique de départ : les changements ultérieurs seront
	// détectés par rapport à elle.
	update_option( 'pivot_lang_fingerprint', Pivot_I18n::fingerprint(), false );

	// La reprise de l'ancien plugin vient en dernier : elle écrit dans le cache
	// et le journal, et ses pages doivent exister avant le rafraîchissement
	// des permaliens qui suit.
	$steps = array(
		'table de journal'            => array( 'Pivot_Logger', 'create_table' ),
		'dossier de cache'            => array( 'Pivot_Cache', 'ensure_directory' ),
		'ancien cache'                => array( 'Pivot_Cache', 'purge_legacy_store' ),
		'tâches planifiées'           => array( 'Pivot_Cron', 'schedule_events' ),
		'pictogrammes'                => array( 'Pivot_Pictos', 'schedule' ),
		'reprise de l\'ancien plugin' => array( 'Pivot_Legacy_Import', 'maybe_run' ),
	);

	$failures = array();

	foreach ( $steps as $label => $callback ) {
		try {
			call_user_func( $callback );
		} catch ( Throwable $e ) {
			$failures[] = $label . ' : ' . $e->getMessage();
		}
	}

	try {
		Pivot_Rewrites::instance()->register_rules();
		flush_rewrite_rules();
	} catch ( Throwable $e ) {
		$failures[] = 'permaliens : ' . $e->getMessage();
	}

	if ( $failures ) {
		update_option( 'pivot_activation_failures', $failures, false );
	} else {
		delete_option( 'pivot_activation_failures' );
	}
}
register_activation_hook( __FILE__, 'pivot_activate' );

/**
 * Désactivation : nettoie les tâches planifiées et les permaliens.
 */
function pivot_deactivate() {
	Pivot_Cron::clear_events();
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'pivot_deactivate' );
