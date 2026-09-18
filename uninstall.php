<?php
/**
 * Désinstallation.
 *
 * Supprime les réglages, la table de journal et les fichiers de cache.
 * Les offres restent bien sûr intactes dans PIVOT : le plugin n'en a jamais
 * détenu de copie en base de données.
 *
 * @package Pivot_Offres
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Options.
//
// Cette liste doit couvrir toute option écrite par le plugin. Les classes qui
// en déclarent une par constante sont faciles à oublier : `pivot_types` l'a été
// jusqu'en 2.4.0. En ajouter une ailleurs sans l'inscrire ici laisse une trace
// après désinstallation.
$pivot_options = array(
	'pivot_settings',            // Pivot_Settings::OPTION
	'pivot_listings',            // Pivot_Listings::OPTION
	'pivot_redirects',           // Pivot_Redirects::OPTION
	'pivot_types',               // Pivot_Types::OPTION
	'pivot_field_rules',         // Pivot_Fields::RULES_OPTION
	'pivot_flush_rewrites',
	'pivot_lang_fingerprint',
	'pivot_lang_changed',
	'pivot_activation_failures',
);

foreach ( $pivot_options as $pivot_option ) {
	delete_option( $pivot_option );
}

// Préférences par utilisateur : visites guidées vues.
// Clé déclarée par Pivot_Onboarding::META.
delete_metadata( 'user', 0, 'pivot_onboarding_seen', '', true );

// Table de journal.
global $wpdb;
$pivot_table = $wpdb->prefix . 'pivot_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$pivot_table}" ); // phpcs:ignore

// Fichiers de cache.
$pivot_uploads = wp_get_upload_dir();
$pivot_dir     = trailingslashit( $pivot_uploads['basedir'] ) . 'pivot-cache';

if ( is_dir( $pivot_dir ) ) {
	$pivot_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $pivot_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $pivot_iterator as $pivot_file ) {
		if ( $pivot_file->isDir() ) {
			@rmdir( $pivot_file->getPathname() ); // phpcs:ignore
		} else {
			@unlink( $pivot_file->getPathname() ); // phpcs:ignore
		}
	}

	@rmdir( $pivot_dir ); // phpcs:ignore
}

// Tâches planifiées.
wp_clear_scheduled_hook( 'pivot_refresh_indexes' );
wp_clear_scheduled_hook( 'pivot_daily_maintenance' );
wp_clear_scheduled_hook( 'pivot_purge_logs' );
