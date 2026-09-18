<?php
/**
 * Tâches planifiées.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Cron {

	/** @var Pivot_Cron|null */
	private static $instance = null;

	/**
	 * @return Pivot_Cron
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( 'pivot_refresh_indexes', array( $this, 'refresh_indexes' ) );
		add_action( 'pivot_continue_index', array( $this, 'continue_index' ) );
		add_action( 'pivot_daily_maintenance', array( $this, 'maintenance' ) );

		// La planification est faite à l'activation. Le filet de sécurité, pour
		// les installations où elle aurait été perdue, se pose en administration
		// seulement : appelé depuis le constructeur, il valait deux lectures de
		// la table des tâches sur chaque requête publique, chaque appel REST et
		// chaque admin-ajax déclenché par un autre plugin.
		add_action( 'admin_init', array( __CLASS__, 'schedule_events' ) );
	}

	/**
	 * Ajoute un intervalle de 15 minutes.
	 *
	 * @param array $schedules Intervalles existants.
	 * @return array
	 */
	public function add_schedules( $schedules ) {
		if ( ! isset( $schedules['pivot_quarter_hour'] ) ) {
			$schedules['pivot_quarter_hour'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Toutes les 15 minutes (PIVOT)', 'pivot-offres' ),
			);
		}
		return $schedules;
	}

	/**
	 * Planifie les événements récurrents.
	 */
	public static function schedule_events() {
		if ( ! wp_next_scheduled( 'pivot_refresh_indexes' ) ) {
			wp_schedule_event( time() + 300, 'pivot_quarter_hour', 'pivot_refresh_indexes' );
		}

		if ( ! wp_next_scheduled( 'pivot_daily_maintenance' ) ) {
			wp_schedule_event( time() + 600, 'daily', 'pivot_daily_maintenance' );
		}
	}

	/**
	 * Retire les événements.
	 */
	public static function clear_events() {
		wp_clear_scheduled_hook( 'pivot_refresh_indexes' );
		wp_clear_scheduled_hook( 'pivot_daily_maintenance' );
		wp_clear_scheduled_hook( 'pivot_purge_logs' );

		foreach ( array_keys( Pivot_Listings::all() ) as $listing_id ) {
			wp_clear_scheduled_hook( 'pivot_continue_index', array( $listing_id ) );
		}
	}

	/**
	 * Reconstruit les index périmés, un seul à la fois pour rester léger.
	 */
	public function refresh_indexes() {
		foreach ( Pivot_Listings::active() as $listing ) {
			if ( Pivot_Index_Builder::is_fresh( $listing ) ) {
				continue;
			}

			$result = Pivot_Index_Builder::run( $listing['id'], 25 );

			// Une page en échec ne doit pas retenir les autres. La valeur de
			// retour n'était pas regardée : la tâche s'arrêtait sur la première
			// page non fraîche, et si celle-ci était cassée — code de requête
			// invalide, jeton refusé — elle la reprenait toutes les quinze
			// minutes sans jamais atteindre les suivantes, qui restaient
			// périmées indéfiniment.
			if ( is_wp_error( $result ) ) {
				continue;
			}

			// Un index par passage : la tâche revient dans 15 minutes.
			return;
		}
	}

	/**
	 * Poursuit une construction interrompue.
	 *
	 * @param string $listing_id Identifiant.
	 */
	public function continue_index( $listing_id ) {
		Pivot_Index_Builder::run( $listing_id, 25 );
	}

	/**
	 * Entretien quotidien : journaux et fichiers de cache expirés.
	 */
	public function maintenance() {
		$logs  = Pivot_Logger::purge();
		$files = Pivot_Cache::purge_expired();

		Pivot_Logger::debug(
			sprintf( 'Entretien quotidien : %d lignes de journal et %d fichiers de cache supprimés.', $logs, $files ),
			array( 'service' => 'maintenance' )
		);
	}
}
