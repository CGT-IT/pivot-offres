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

	/** Tentatives de nuit au plus, reprises comprises. */
	const NIGHTLY_ATTEMPTS = 6;

	/** Délai entre deux reprises de la mise à jour de nuit. */
	const NIGHTLY_RETRY = 15 * MINUTE_IN_SECONDS;

	private function __construct() {
		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( 'pivot_refresh_indexes', array( $this, 'refresh_indexes' ) );
		add_action( 'pivot_continue_index', array( $this, 'continue_index' ) );
		add_action( 'pivot_daily_maintenance', array( $this, 'maintenance' ) );
		add_action( 'pivot_nightly_sync', array( $this, 'nightly_sync' ) );
		add_action( 'pivot_nightly_retry', array( $this, 'nightly_sync' ) );
		add_action( 'update_option_pivot_settings', array( __CLASS__, 'settings_updated' ), 10, 2 );

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

		if ( ! wp_next_scheduled( 'pivot_nightly_sync' ) ) {
			wp_schedule_event( self::next_nightly( pivot_settings( 'sync_time', '04:00' ) ), 'daily', 'pivot_nightly_sync' );
		}
	}

	/**
	 * Prochaine occurrence d'une heure de la journée, dans le fuseau du site.
	 *
	 * @param string $time Heure « HH:MM ».
	 * @return int Horodatage.
	 */
	public static function next_nightly( $time ) {
		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', (string) $time, $parts ) ) {
			$parts = array( '04:00', '04', '00' );
		}

		$next = new DateTime( 'now', wp_timezone() );
		$next->setTime( (int) $parts[1], (int) $parts[2] );

		if ( $next->getTimestamp() <= time() ) {
			$next->modify( '+1 day' );
		}

		return $next->getTimestamp();
	}

	/**
	 * Replace la mise à jour de nuit quand son heure change.
	 *
	 * @param mixed $old_value Réglages précédents.
	 * @param mixed $value     Nouveaux réglages.
	 */
	public static function settings_updated( $old_value, $value ) {
		$old = is_array( $old_value ) ? (string) pivot_get( $old_value, 'sync_time', '04:00' ) : '04:00';
		$new = is_array( $value ) ? (string) pivot_get( $value, 'sync_time', '04:00' ) : '04:00';

		if ( $old === $new && wp_next_scheduled( 'pivot_nightly_sync' ) ) {
			return;
		}

		wp_clear_scheduled_hook( 'pivot_nightly_sync' );
		wp_schedule_event( self::next_nightly( $new ), 'daily', 'pivot_nightly_sync' );
	}

	/**
	 * Retire les événements.
	 */
	public static function clear_events() {
		wp_clear_scheduled_hook( 'pivot_refresh_indexes' );
		wp_clear_scheduled_hook( 'pivot_daily_maintenance' );
		wp_clear_scheduled_hook( 'pivot_purge_logs' );
		wp_clear_scheduled_hook( 'pivot_nightly_sync' );
		wp_clear_scheduled_hook( 'pivot_nightly_retry' );

		foreach ( array_keys( Pivot_Listings::all() ) as $listing_id ) {
			wp_clear_scheduled_hook( 'pivot_continue_index', array( $listing_id ) );
		}
	}

	/**
	 * Mise à jour de nuit des pages en différentiel.
	 *
	 * Une vérification par page, quelques centaines d'octets quand rien n'a
	 * changé. Les pages qui échouent — PIVOT injoignable, verrou tenu — sont
	 * reprises un quart d'heure plus tard, six tentatives au plus par nuit.
	 */
	public function nightly_sync() {
		if ( ! pivot_settings( 'diff_enabled', 1 ) ) {
			return;
		}

		$run = Pivot_Cache::get( 'build', 'nightly' );

		// Une nuit dure moins de 20 heures : au-delà, c'est la suivante.
		if ( ! is_array( $run ) || ( time() - (int) pivot_get( $run, 'started', 0 ) ) > 20 * HOUR_IN_SECONDS ) {
			$run = array(
				'started'  => time(),
				'attempts' => 0,
			);
		}

		$run['attempts']++;

		$started = microtime( true );
		$pending = false;

		foreach ( Pivot_Listings::active() as $listing ) {
			if ( ! Pivot_Index_Builder::diff_enabled( $listing ) ) {
				continue;
			}

			// Déjà vue cette nuit, lors d'un passage précédent.
			if ( (int) pivot_get( $listing, 'index_checked', 0 ) >= (int) $run['started'] ) {
				continue;
			}

			if ( ( microtime( true ) - $started ) > 60 ) {
				$pending = true;
				break;
			}

			$result = Pivot_Index_Builder::nightly( $listing['id'] );

			if ( is_wp_error( $result ) ) {
				$pending = true;
			}
		}

		Pivot_Cache::set( 'build', 'nightly', $run, DAY_IN_SECONDS );

		if ( $pending && $run['attempts'] < self::NIGHTLY_ATTEMPTS && ! wp_next_scheduled( 'pivot_nightly_retry' ) ) {
			wp_schedule_single_event( time() + self::NIGHTLY_RETRY, 'pivot_nightly_retry' );
		}
	}

	/**
	 * Reconstruit les index périmés, un seul à la fois pour rester léger.
	 *
	 * Avec le différentiel, un index vérifié chaque nuit reste frais : seuls
	 * les index absents ou invalidés — configuration modifiée, cache vidé —
	 * sont reconstruits en journée.
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
