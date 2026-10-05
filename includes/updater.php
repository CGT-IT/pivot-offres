<?php
/**
 * Mises à jour depuis GitHub.
 *
 * Le plugin n'est pas publié sur wordpress.org : WordPress ne sait donc pas
 * qu'une nouvelle version existe. La bibliothèque Plugin Update Checker
 * (lib/plugin-update-checker, licence MIT) interroge les Releases du dépôt et
 * injecte la mise à jour dans le mécanisme natif : elle apparaît dans
 * Extensions et Tableau de bord → Mises à jour, avec ses notes de version.
 *
 * Seul le zip `pivot-offres.zip` joint à la Release est installé. Il est
 * construit par .github/workflows/release.yml, avec `pivot-offres/` pour
 * dossier racine. Une Release sans ce zip n'est pas proposée.
 *
 * Le dépôt est public : aucun jeton n'est nécessaire. S'il redevenait privé,
 * il faudrait appeler $checker->setAuthentication() avec un jeton en lecture.
 *
 * Ce fichier s'exécute avant les contrôles préalables, pour qu'un site bloqué
 * par l'un d'eux puisse quand même recevoir la version qui le corrige.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

/**
 * Branche le contrôle des mises à jour.
 *
 * Rien n'est branché dans un dossier de développement (présence de `.git`) :
 * une mise à jour y remplacerait le dossier de travail et effacerait le dépôt
 * et les changements non commités. Définir PIVOT_UPDATER_FORCE pour passer
 * outre, par exemple pour tester.
 *
 * @return void
 */
function pivot_register_updater() {
	if ( file_exists( PIVOT_DIR . '.git' ) && ! ( defined( 'PIVOT_UPDATER_FORCE' ) && PIVOT_UPDATER_FORCE ) ) {
		return;
	}

	require_once PIVOT_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

	$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/mdegembe/pivot-offres/',
		PIVOT_FILE,
		'pivot-offres'
	);

	$checker->setBranch( 'master' );
	$checker->getVcsApi()->enableReleaseAssets(
		'/^pivot-offres\.zip$/',
		\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS
	);
}
