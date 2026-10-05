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
	'pivot_types',               // Pivot_Types::OPTION
	'pivot_field_rules',         // Pivot_Fields::RULES_OPTION
	'pivot_cache_secret',        // Pivot_Cache::SECRET_OPTION
	'pivot_version',
	'pivot_flush_rewrites',
	'pivot_lang_fingerprint',
	'pivot_lang_changed',
	'pivot_activation_failures',
	'pivot_legacy_import',       // Pivot_Legacy_Import::OPTION
	'pivot_pictos_empty',        // Pivot_Pictos::EMPTY_OPTION
	'pivot_pictos_run',          // Pivot_Pictos::RUN_OPTION
	'pivot_pictos_state',        // Pivot_Pictos::STATE_OPTION
	// Table de redirections des versions antérieures à la 2.6.0, au cas où
	// la reprise de version n'aurait pas tourné.
	'pivot_redirects',
	'pivot_redirects_count',
);

// Tâches planifiées propres à une page de listing.
//
// Elles portent l'identifiant de la page en argument, et se lisent donc dans
// `pivot_listings` : il faut les retirer **avant** de supprimer l'option, sinon
// les événements survivent sans que plus rien ne sache les nommer.
$pivot_listings = get_option( 'pivot_listings', array() );

if ( is_array( $pivot_listings ) ) {
	foreach ( array_keys( $pivot_listings ) as $pivot_listing_id ) {
		wp_clear_scheduled_hook( 'pivot_continue_index', array( $pivot_listing_id ) );
	}
}

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

// Fichiers de cache : la moitié publique sous uploads, la moitié privée sous
// wp-content. Les deux racines doivent partir.
$pivot_uploads = wp_get_upload_dir();
$pivot_dirs    = array(
	trailingslashit( $pivot_uploads['basedir'] ) . 'pivot-cache',   // Pivot_Cache::DIRNAME
	trailingslashit( WP_CONTENT_DIR ) . 'pivot-cache-private',      // Pivot_Cache::PRIVATE_DIRNAME
);

foreach ( $pivot_dirs as $pivot_dir ) {
	if ( ! is_dir( $pivot_dir ) ) {
		continue;
	}

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
wp_clear_scheduled_hook( 'pivot_nightly_sync' );
wp_clear_scheduled_hook( 'pivot_nightly_retry' );
wp_clear_scheduled_hook( 'pivot_sync_pictos' );
