<?php
/**
 * Contrôles préalables au chargement.
 *
 * Ce fichier s'exécute très tôt, avant l'action init. Il ne traduit donc
 * aucune chaîne : la détection renvoie des identifiants, et la mise en mots
 * n'a lieu qu'au moment de l'affichage, quand les traductions sont chargées.
 * C'est ce qui évite l'avertissement « Translation loading … triggered too
 * early » introduit par WordPress 6.7, et la cascade d'erreurs d'en-têtes
 * qu'il provoque.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

/**
 * Vérifie que l'environnement peut accueillir le plugin.
 *
 * @param bool $check_collisions Tester les conflits de noms. À n'activer que
 *                               depuis le fichier principal, avant les require :
 *                               une fois le plugin chargé, ses propres noms sont
 *                               évidemment déclarés.
 * @return array Liste de problèmes, chacun sous la forme array( id, data ).
 */
function pivot_preflight_problems( $check_collisions = true ) {
	$problems = array();

	// 1. Version de PHP.
	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		$problems[] = array( 'id' => 'php_version', 'data' => PHP_VERSION );
	}

	// 2. Extensions indispensables à la lecture des réponses du webservice.
	foreach ( array( 'simplexml', 'libxml', 'json' ) as $extension ) {
		if ( ! extension_loaded( $extension ) ) {
			$problems[] = array( 'id' => 'extension', 'data' => $extension );
		}
	}

	// 3. Conflit de noms : un autre plugin, un thème ou du code existant occupe
	// déjà un nom que ce plugin va déclarer. C'est la cause la plus fréquente
	// d'erreur fatale sur un site qui exploite déjà PIVOT.
	if ( $check_collisions ) {
		$functions = array( 'pivot_get', 'pivot_has', 'pivot_echo', 'pivot_settings', 'pivot_lang', 'pivot_normalize', 'pivot_slugify', 'pivot_is_code', 'pivot_image_url', 'pivot_picto_url', 'pivot_capability', 'pivot_service_url', 'pivot_ws_key' );

		// Les classes déclarées dans le fichier principal sont compilées avant
		// l'exécution de la moindre ligne : seules celles des fichiers inclus
		// plus loin sont testables ici.
		$classes = array( 'Pivot_I18n', 'Pivot_Cache', 'Pivot_Logger', 'Pivot_Client', 'Pivot_Parser', 'Pivot_Thesaurus', 'Pivot_Listings', 'Pivot_Repository', 'Pivot_Index_Builder', 'Pivot_Rewrites', 'Pivot_Redirects', 'Pivot_Rest', 'Pivot_Seo', 'Pivot_Templates', 'Pivot_Cron' );

		$taken = array();

		foreach ( $functions as $function ) {
			if ( function_exists( $function ) ) {
				$taken[] = $function . '()';
			}
		}

		foreach ( $classes as $class ) {
			if ( class_exists( $class, false ) ) {
				$taken[] = $class;
			}
		}

		if ( $taken ) {
			$problems[] = array( 'id' => 'name_conflict', 'data' => implode( ', ', $taken ) );
		}
	}

	// 4. Dossier des fichiers envoyés : sans écriture, aucun cache possible.
	// Ce test n'a d'utilité que dans l'administration, où il peut être lu ;
	// l'y restreindre évite aussi de résoudre le dossier à chaque page publique.
	if ( ! is_admin() ) {
		return $problems;
	}

	$uploads = wp_get_upload_dir();

	if ( ! empty( $uploads['error'] ) ) {
		$problems[] = array( 'id' => 'uploads_error', 'data' => $uploads['error'] );
	} elseif ( ! is_writable( $uploads['basedir'] ) ) {
		$problems[] = array( 'id' => 'uploads_readonly', 'data' => $uploads['basedir'] );
	}

	// 5. Stockage privé : offres, état de construction et thésaurus vivent sous
	// wp-content/, hors de l'arborescence publiée. Sans écriture à cet endroit,
	// le plugin ne peut rien mettre en cache — et surtout, il ne faut pas qu'il
	// se rabatte silencieusement sur uploads/, qui est servi par le serveur web.
	// Nom écrit en clair, et non Pivot_Cache::PRIVATE_DIRNAME : ce fichier est
	// chargé avant toutes les classes du plugin — c'est même sa raison d'être,
	// puisqu'il vérifie qu'aucune d'elles n'entre en collision.
	$private = trailingslashit( WP_CONTENT_DIR ) . 'pivot-cache-private';
	$probe   = is_dir( $private ) ? $private : WP_CONTENT_DIR;

	if ( ! is_writable( $probe ) ) {
		$problems[] = array( 'id' => 'private_readonly', 'data' => $probe );
	}

	return $problems;
}

/**
 * Met un problème en mots.
 *
 * À n'appeler qu'à partir de l'action init : c'est ici que les traductions
 * entrent en jeu.
 *
 * @param array $problem Problème renvoyé par pivot_preflight_problems().
 * @return string
 */
function pivot_preflight_message( $problem ) {
	$id   = isset( $problem['id'] ) ? $problem['id'] : '';
	$data = isset( $problem['data'] ) ? $problem['data'] : '';

	switch ( $id ) {
		case 'php_version':
			return sprintf(
				/* translators: %s : version de PHP installée. */
				__( 'PIVOT Offres demande PHP 7.4 ou plus récent. Ce serveur exécute PHP %s. Demandez la mise à jour à votre hébergeur.', 'pivot-offres' ),
				$data
			);

		case 'extension':
			$reasons = array(
				'simplexml' => __( 'SimpleXML, qui sert à lire les réponses de PIVOT', 'pivot-offres' ),
				'libxml'    => __( 'libxml, qui sert à analyser le XML', 'pivot-offres' ),
				'json'      => __( 'JSON, qui sert à écrire les fichiers de cache', 'pivot-offres' ),
			);

			return sprintf(
				/* translators: %s : description de l'extension manquante. */
				__( 'L\'extension PHP « %s » est absente de ce serveur.', 'pivot-offres' ),
				isset( $reasons[ $data ] ) ? $reasons[ $data ] : $data
			);

		case 'name_conflict':
			return sprintf(
				/* translators: %s : liste de noms déjà déclarés. */
				__( 'Ces noms sont déjà déclarés sur le site : %s. Un autre plugin, votre thème ou votre code PIVOT existant les utilise. Désactivez-le, ou renommez-le, avant d\'activer PIVOT Offres.', 'pivot-offres' ),
				$data
			);

		case 'uploads_error':
			return sprintf(
				/* translators: %s : message d'erreur de WordPress. */
				__( 'Le dossier des fichiers envoyés est inutilisable : %s', 'pivot-offres' ),
				$data
			);

		case 'uploads_readonly':
			return sprintf(
				/* translators: %s : chemin du dossier. */
				__( 'Le dossier %s n\'est pas accessible en écriture. Le plugin y range son cache.', 'pivot-offres' ),
				$data
			);

		case 'private_readonly':
			return sprintf(
				/* translators: %s : chemin du dossier. */
				__( 'Le dossier %s n\'est pas accessible en écriture. Le plugin y range les données qui ne doivent pas être servies par le Web : offres, thésaurus, état de construction.', 'pivot-offres' ),
				$data
			);
	}

	return (string) $data;
}

/**
 * Signale les problèmes détectés dans l'administration.
 *
 * L'affichage est différé à admin_notices : à ce moment les traductions sont
 * chargées et aucun en-tête n'a été envoyé prématurément.
 *
 * @param array $problems Liste des problèmes.
 */
function pivot_preflight_notice( $problems ) {
	if ( ! is_admin() ) {
		return;
	}

	add_action(
		'admin_notices',
		function () use ( $problems ) {
			echo '<div class="notice notice-error"><p><strong>';
			esc_html_e( 'PIVOT Offres n\'a pas pu démarrer.', 'pivot-offres' );
			echo '</strong></p><ul style="list-style:disc;margin-left:2em">';

			foreach ( $problems as $problem ) {
				echo '<li>' . esc_html( pivot_preflight_message( $problem ) ) . '</li>';
			}

			echo '</ul></div>';
		}
	);
}
