<?php
/**
 * Journal des appels au webservice PIVOT.
 *
 * Les entrées sont conservées pendant la durée définie dans les réglages, puis
 * supprimées automatiquement par une tâche quotidienne.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Logger {

	/** @var Pivot_Logger|null */
	private static $instance = null;

	/** @var array Niveaux, du plus verbeux au plus discret. */
	private static $levels = array(
		'debug' => 10,
		'info'  => 20,
		'warn'  => 30,
		'error' => 40,
	);

	/**
	 * @return Pivot_Logger
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Le plugin ne planifie pas cet événement : la purge du journal est faite
		// par la maintenance quotidienne (Pivot_Cron::maintenance). L'accroche
		// reste déclarée pour deux raisons : les installations anciennes peuvent
		// encore avoir l'événement planifié, et elle sert de point d'entrée à qui
		// veut déclencher une purge par do_action( 'pivot_purge_logs' ).
		add_action( 'pivot_purge_logs', array( __CLASS__, 'purge' ) );
	}

	/**
	 * Nom complet de la table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pivot_logs';
	}

	/**
	 * Création / mise à jour du schéma.
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			level VARCHAR(10) NOT NULL DEFAULT 'info',
			service VARCHAR(40) NOT NULL DEFAULT '',
			endpoint VARCHAR(255) NOT NULL DEFAULT '',
			http_code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			duration_ms MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
			bytes INT UNSIGNED NOT NULL DEFAULT 0,
			cache_status VARCHAR(12) NOT NULL DEFAULT '',
			message TEXT NULL,
			context LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY level (level),
			KEY service (service)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Le niveau demandé doit-il être enregistré ?
	 *
	 * @param string $level Niveau.
	 * @return bool
	 */
	private static function should_log( $level ) {
		if ( ! pivot_settings( 'logs_enabled', 0 ) ) {
			return false;
		}
		$min     = pivot_settings( 'logs_level', 'info' );
		$min_val = isset( self::$levels[ $min ] ) ? self::$levels[ $min ] : 20;
		$val     = isset( self::$levels[ $level ] ) ? self::$levels[ $level ] : 20;
		return $val >= $min_val;
	}

	/**
	 * Enregistre une ligne de journal.
	 *
	 * @param string $level   debug|info|warn|error.
	 * @param string $message Message court.
	 * @param array  $args    Contexte : service, endpoint, http_code, duration_ms, bytes, cache_status, context.
	 */
	public static function log( $level, $message, $args = array() ) {
		if ( ! self::should_log( $level ) ) {
			return;
		}

		global $wpdb;

		$defaults = array(
			'service'      => '',
			'endpoint'     => '',
			'http_code'    => 0,
			'duration_ms'  => 0,
			'bytes'        => 0,
			'cache_status' => '',
			'context'      => array(),
		);
		$args     = wp_parse_args( $args, $defaults );

		// La clé d'authentification ne doit jamais apparaître dans le journal.
		$endpoint = preg_replace( '/WS_KEY=[^;\/\?&]+/i', 'WS_KEY=***', (string) $args['endpoint'] );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'created_at'   => current_time( 'mysql' ),
				'level'        => substr( (string) $level, 0, 10 ),
				'service'      => substr( (string) $args['service'], 0, 40 ),
				'endpoint'     => substr( $endpoint, 0, 255 ),
				'http_code'    => (int) $args['http_code'],
				'duration_ms'  => (int) $args['duration_ms'],
				'bytes'        => (int) $args['bytes'],
				'cache_status' => substr( (string) $args['cache_status'], 0, 12 ),
				'message'      => (string) $message,
				'context'      => $args['context'] ? wp_json_encode( $args['context'] ) : null,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/** Raccourcis. */
	public static function debug( $message, $args = array() ) {
		self::log( 'debug', $message, $args );
	}
	public static function info( $message, $args = array() ) {
		self::log( 'info', $message, $args );
	}
	public static function warn( $message, $args = array() ) {
		self::log( 'warn', $message, $args );
	}
	public static function error( $message, $args = array() ) {
		self::log( 'error', $message, $args );
	}

	/**
	 * Supprime les entrées plus anciennes que la rétention configurée.
	 *
	 * @return int Nombre de lignes supprimées.
	 */
	public static function purge() {
		global $wpdb;

		$days = max( 1, (int) pivot_settings( 'logs_retention', 7 ) );
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $days )
		);
	}

	/**
	 * Vide entièrement le journal.
	 *
	 * @return int
	 */
	public static function truncate() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Lecture paginée du journal.
	 *
	 * @param array $args level, service, search, per_page, page.
	 * @return array{items:array,total:int}
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'level'    => '',
				'service'  => '',
				'search'   => '',
				'per_page' => 50,
				'page'     => 1,
			)
		);

		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		if ( $args['level'] ) {
			$where[]  = 'level = %s';
			$params[] = $args['level'];
		}
		if ( $args['service'] ) {
			$where[]  = 'service = %s';
			$params[] = $args['service'];
		}
		if ( $args['search'] ) {
			$where[]  = '(endpoint LIKE %s OR message LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset    = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$list_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$items = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Supprime la table (désinstallation).
	 */
	public static function drop_table() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
