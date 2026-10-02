<?php
/**
 * Visite guidée de l'administration.
 *
 * Chaque écran a sa propre visite, composée d'étapes qui pointent un élément
 * réel de la page. L'avancement est enregistré par utilisateur : une visite
 * terminée ou passée ne se rouvre pas d'elle-même, et un bouton permet à
 * chacun de la revoir quand il veut.
 *
 * La classe vit dans includes/ et non dans admin/ : l'avancement est enregistré
 * par la route REST /pivot/v1/onboarding, or une requête REST n'est pas
 * is_admin(). Chargée depuis admin/ elle n'existait pas au moment de l'appel et
 * la route répondait une erreur 500 à chaque fois. Seul l'accrochage de la
 * visite guidée est propre à l'administration : il reste dans le constructeur,
 * que seul Pivot_Admin déclenche.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Onboarding {

	const META = 'pivot_onboarding_seen';

	/** @var Pivot_Onboarding|null */
	private static $instance = null;

	/**
	 * @return Pivot_Onboarding
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	/**
	 * Écran d'administration courant, au sens du plugin.
	 *
	 * @return string
	 */
	private static function current_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	}

	/**
	 * Visites déjà vues par un utilisateur.
	 *
	 * @param int $user_id Utilisateur, 0 pour l'utilisateur courant.
	 * @return array
	 */
	public static function seen( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		$seen    = get_user_meta( $user_id, self::META, true );

		return is_array( $seen ) ? $seen : array();
	}

	/**
	 * Marque une visite comme vue.
	 *
	 * @param string $tour    Identifiant de visite.
	 * @param int    $user_id Utilisateur.
	 */
	public static function mark_seen( $tour, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		$seen    = self::seen( $user_id );

		if ( ! in_array( $tour, $seen, true ) ) {
			$seen[] = $tour;
		}

		update_user_meta( $user_id, self::META, $seen );
	}

	/**
	 * Remet toutes les visites à zéro pour un utilisateur.
	 *
	 * @param int $user_id Utilisateur.
	 */
	public static function reset( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		delete_user_meta( $user_id, self::META );
	}

	/**
	 * Définition des visites.
	 *
	 * Une étape sans cible affichée au centre de l'écran sert d'introduction ou
	 * de conclusion. Une étape dont la cible est absente de la page est
	 * silencieusement sautée : les visites restent justes quel que soit l'état
	 * de l'écran.
	 *
	 * @return array
	 */
	public static function tours() {
		$tours = array(

			/* ------------------------------------------ Pages de listing */
			'listings'     => array(
				'screen' => 'pivot-listings',
				'auto'   => true,
				'label'  => __( 'Découvrir les pages de listing', 'pivot-offres' ),
				'steps'  => array(
					array(
						'title' => __( 'Bienvenue dans PIVOT Offres', 'pivot-offres' ),
						'text'  => __( 'Cette extension publie vos offres touristiques sans jamais les copier en base de données. Cette visite dure une minute et montre comment publier votre première page.', 'pivot-offres' ),
					),
					array(
						'target' => '.pivot-empty .button-primary, .page-title-action',
						'title'  => __( 'Créer une page', 'pivot-offres' ),
						'text'   => __( 'Une page de listing associe une adresse de votre site à une requête pré-programmée PIVOT. C\'est le point de départ : tout part d\'ici.', 'pivot-offres' ),
					),
					array(
						'target' => '.pivot-listings-table .pivot-listing-row:not([hidden])',
						'title'  => __( 'Vos pages', 'pivot-offres' ),
						'text'   => __( 'Chaque ligne résume une page : son adresse, sa requête, le nombre d\'offres par page, les langues publiées et la date du dernier index.', 'pivot-offres' ),
					),
					array(
						'target' => '.pivot-listings-table .pivot-listing-row:not([hidden]) .row-actions',
						'title'  => __( 'L\'index', 'pivot-offres' ),
						'text'   => __( 'L\'index est le fichier qui alimente la recherche du visiteur. Il se reconstruit tout seul, mais ce lien le force immédiatement — pratique après avoir modifié une requête dans PIVOT.', 'pivot-offres' ),
					),
					array(
						'target' => '#adminmenu a[href$="page=pivot-settings"]',
						'title'  => __( 'À faire en premier', 'pivot-offres' ),
						'text'   => __( 'Si ce n\'est pas encore fait, ouvrez les réglages pour saisir votre clé ws_key et tester la connexion. Sans elle, aucune offre ne remontera.', 'pivot-offres' ),
					),
				),
			),

			/* ------------------------------------------ Champs affichés */
			'fields'       => array(
				'screen' => 'pivot-fields',
				'auto'   => true,
				'label'  => __( 'Comprendre les champs affichés', 'pivot-offres' ),
				'steps'  => array(
					array(
						'title' => __( 'Ce que montre une fiche', 'pivot-offres' ),
						'text'  => __( 'PIVOT renvoie bien plus de champs qu\'une fiche ne devrait en montrer. Cet écran décide lesquels sont visibles par vos visiteurs.', 'pivot-offres' ),
					),
					array(
						'target' => '.pivot-field-group:first-child .pivot-field-list',
						'title'  => __( 'Masquer n\'est pas exclure', 'pivot-offres' ),
						'text'   => __( 'Un champ décoché disparaît des fiches et des vignettes, mais reste utilisable comme critère de recherche. Les champs déjà décochés sont ceux que l\'extension masque par défaut : vous pouvez les rétablir.', 'pivot-offres' ),
					),
					array(
						'target' => '.pivot-field-group:first-child .pivot-field-toggle',
						'title'  => __( 'Toute une catégorie d\'un coup', 'pivot-offres' ),
						'text'   => __( 'La case d\'une catégorie commande tous ses champs, et n\'enregistre qu\'une seule règle. Décochez-la, puis recochez le champ que vous voulez garder : c\'est la façon la plus courte d\'exprimer « tout sauf celui-ci ».', 'pivot-offres' ),
					),
					array(
						'target' => '#pivot-field-filter',
						'title'  => __( 'Retrouver un champ', 'pivot-offres' ),
						'text'   => __( 'Un type d\'offre peut compter plus de cent champs. Tapez quelques lettres du libellé ou de l\'urn pour ne garder que les lignes concernées.', 'pivot-offres' ),
					),
					array(
						'target' => '.pivot-field-excluded',
						'title'  => __( 'Ce qui ne se règle pas ici', 'pivot-offres' ),
						'text'   => __( 'Les filtres de catégorisation et les champs Cirkwi ne décrivent pas l\'offre. Ils sont écartés partout, y compris du filtrage, et sont listés ici seulement pour que vous ne les cherchiez pas ailleurs.', 'pivot-offres' ),
					),
					array(
						'target' => 'p.submit .button-primary',
						'title'  => __( 'Après l\'enregistrement', 'pivot-offres' ),
						'text'   => __( 'Les fiches détail suivent tout de suite. Les vignettes et les descriptifs des listings viennent des index : reconstruisez-les depuis Cache et outils pour que le changement s\'y voie.', 'pivot-offres' ),
					),
				),
			),

			/* ------------------------------------ Édition d'une page */
			'listing-edit' => array(
				'screen' => 'pivot-listing-edit',
				'auto'   => true,
				'label'  => __( 'Découvrir cet écran', 'pivot-offres' ),
				'steps'  => array(
					array(
						'title' => __( 'Configurer une page de listing', 'pivot-offres' ),
						'text'  => __( 'Cinq réglages suffisent pour une page qui fonctionne. Voyons-les dans l\'ordre.', 'pivot-offres' ),
					),
					array(
						'target' => 'input[name="pivot_listing[slug]"]',
						'title'  => __( 'L\'adresse de la page', 'pivot-offres' ),
						'text'   => __( 'Saisissez un ou plusieurs segments, par exemple sejourner/hotels. L\'adresse est créée par l\'extension : vous n\'avez aucune page WordPress à publier.', 'pivot-offres' ),
					),
					array(
						'target' => 'input[name="pivot_listing[query_code]"]',
						'title'  => __( 'La requête PIVOT', 'pivot-offres' ),
						'text'   => __( 'Collez ici le code de la requête pré-programmée créée dans PIVOT, de la forme QRY-00-0000-0000. C\'est elle qui détermine quelles offres apparaissent.', 'pivot-offres' ),
					),
					array(
						'target' => 'select[name="pivot_listing[content]"]',
						'title'  => __( 'Richesse des données', 'pivot-offres' ),
						'text'   => __( 'Le mode résumé construit l\'index plus vite. Passez au mode complet dès qu\'un critère de recherche porte sur un champ PIVOT : sans lui, ces champs ne sont pas renvoyés.', 'pivot-offres' ),
					),
					array(
						'target' => 'input[name="pivot_listing[show_map]"]',
						'title'  => __( 'La carte', 'pivot-offres' ),
						'text'   => __( 'Cochez pour afficher une carte qui pointe les offres de la page. Seules les offres géolocalisées y figurent ; les autres restent dans la liste.', 'pivot-offres' ),
					),
					array(
						'target'   => '.pivot-suggestions',
						'title'    => __( 'Les critères suggérés', 'pivot-offres' ),
						'text'     => __( 'Le plugin lit un échantillon de vos offres et propose les critères qui ont un sens : ceux dont les valeurs sont assez variées pour filtrer, et assez répandues pour concerner les offres. Un clic sur Ajouter pose le critère entièrement réglé.', 'pivot-offres' ),
						'optional' => true,
					),
					array(
						'target' => '.pivot-add-filter',
						'title'  => __( 'Un critère sur mesure', 'pivot-offres' ),
						'text'   => __( 'Si rien ne convient dans les suggestions, ce bouton ouvre un critère vierge à régler à la main.', 'pivot-offres' ),
					),
					array(
						'target' => '.pivot-tour-start-filters',
						'title'  => __( 'Un guide détaillé pour les critères', 'pivot-offres' ),
						'text'   => __( 'Un critère se règle sur cinq champs. Ce bouton ouvre une visite qui les passe un par un, avec ce que chacun change pour le visiteur.', 'pivot-offres' ),
					),
					array(
						'target' => 'p.submit .button-primary',
						'title'  => __( 'Enregistrer', 'pivot-offres' ),
						'text'   => __( 'À l\'enregistrement, l\'index se reconstruit si la requête ou les critères ont changé. La page est alors visible à l\'adresse choisie.', 'pivot-offres' ),
					),
				),
			),

			/* -------------------------------- Sous-formulaire d'un critère */
			'filters'      => array(
				'screen'  => 'pivot-listing-edit',
				'auto'    => false,
				'trigger' => '.pivot-tour-start-filters',
				'label'   => __( 'Comment régler un critère ?', 'pivot-offres' ),
				// Sans critère à l'écran, la visite n'aurait rien à montrer :
				// on en ajoute un pour la démonstration.
				'prepare' => array(
					'click'  => '.pivot-add-filter',
					'unless' => '.pivot-filter-row',
				),
				'steps'   => array(
					array(
						'title' => __( 'Anatomie d\'un critère', 'pivot-offres' ),
						'text'  => __( 'Un critère associe une donnée des offres à un contrôle affiché au visiteur. Les critères suggérés remplissent ces cinq champs pour vous ; cette visite explique ce que chacun fait, pour les cas où vous voulez les régler vous-même.', 'pivot-offres' ),
					),
					array(
						'target' => '.pivot-filter-row:first-child input[name*="[label]"]',
						'title'  => __( '1. Libellé', 'pivot-offres' ),
						'text'   => __( 'Le texte affiché au-dessus du contrôle, dans la langue par défaut. Les autres langues se saisissent plus bas, dans le repli « Traductions de ce critère ».', 'pivot-offres' ),
					),
					array(
						'target' => '.pivot-filter-row:first-child .pivot-group-input',
						'title'  => __( 'Groupe, facultatif', 'pivot-offres' ),
						'text'   => __( 'Les critères qui portent le même groupe s\'affichent ensemble, sous son nom : « Équipements » au-dessus de Terrasse, Parking et Wifi. Son nom se traduit une seule fois, dans le tableau « Groupes de critères » sous la liste.', 'pivot-offres' ),
					),
					array(
						'target' => '.pivot-filter-row:first-child .pivot-filter-source',
						'title'  => __( '2. Source', 'pivot-offres' ),
						'text'   => __( 'D\'où vient la donnée. Localité, commune, code postal, province et type d\'offre sont lus dans l\'adresse de l\'offre, sans rien configurer. « Champ PIVOT » ouvre tout le reste : catégorie, équipements, labels, capacités.', 'pivot-offres' ),
					),
					array(
						'target' => '.pivot-filter-row:first-child .pivot-filter-urn',
						'title'  => __( '3. Urn du champ', 'pivot-offres' ),
						'text'   => __( 'Uniquement pour la source « Champ PIVOT » : l\'identifiant du champ dans le modèle de données, de la forme urn:fld:catdec. Ce champ disparaît pour les autres sources.', 'pivot-offres' ),
					),
					array(
						'target' => '.pivot-filter-row:first-child .pivot-pick-field',
						'title'  => __( 'Ne retenez aucune urn', 'pivot-offres' ),
						'text'   => __( 'Ce lien ouvre le catalogue lu dans le thesaurus : les champs groupés par catégorie, avec leur libellé traduit. Les types d\'offres réellement présents dans cette page sont proposés en tête. Un clic remplit l\'urn, le libellé et le contrôle adapté.', 'pivot-offres' ),
					),
					array(
						'target' => '.pivot-filter-row:first-child select[name*="[type]"]',
						'title'  => __( '4. Contrôle', 'pivot-offres' ),
						'text'   => __( 'Ce que manipule le visiteur. Liste déroulante pour un choix unique, cases à cocher pour cumuler plusieurs valeurs, saisie libre pour une recherche partielle, interrupteur pour un champ oui/non, nombre à comparer pour une capacité, un prix ou une distance : au moins, au plus, entre deux valeurs. Le catalogue de champs le présélectionne correctement.', 'pivot-offres' ),
					),
					array(
						'target' => '.pivot-filter-row:first-child input[name*="[key]"]',
						'title'  => __( '5. Clé d\'URL', 'pivot-offres' ),
						'text'   => __( 'Le nom du paramètre dans l\'adresse : ?province=namur. Laissez vide, il est calculé. Cette clé est identique dans toutes les langues, ce qui rend un lien filtré partageable entre versions linguistiques.', 'pivot-offres' ),
					),
					array(
						'target'   => '.pivot-filter-row:first-child .pivot-filter-i18n',
						'title'    => __( 'Traduire le critère', 'pivot-offres' ),
						'text'     => __( 'PIVOT traduit déjà les valeurs. Ce repli ne sert qu\'à corriger : un libellé par langue, et une ligne « valeur|texte » par traduction à remplacer. Les valeurs disponibles y sont listées après la première construction de l\'index.', 'pivot-offres' ),
						'optional' => true,
					),
					array(
						'target' => '.pivot-filter-row:first-child .pivot-remove-filter',
						'title'  => __( 'Retirer un critère', 'pivot-offres' ),
						'text'   => __( 'Le critère disparaît du formulaire ; rien n\'est perdu tant que vous n\'enregistrez pas. Les offres, elles, ne sont jamais touchées.', 'pivot-offres' ),
					),
					array(
						'title' => __( 'Une fois enregistré', 'pivot-offres' ),
						'text'  => __( 'Un critère sur un champ PIVOT exige la richesse « Complet » plus haut, sinon le champ n\'est pas renvoyé et la liste de valeurs reste vide. Reconstruisez ensuite l\'index pour voir les valeurs apparaître.', 'pivot-offres' ),
					),
				),
			),

			/* ------------------------------------------------ Réglages */
			'settings'     => array(
				'screen' => 'pivot-settings',
				'auto'   => true,
				'label'  => __( 'Découvrir les réglages', 'pivot-offres' ),
				'steps'  => array(
					array(
						'target' => '#pivot-environment',
						'title'  => __( 'Stage ou production', 'pivot-offres' ),
						'text'   => __( 'Chaque environnement a son adresse et sa clé. Basculer de l\'un à l\'autre ne demande rien d\'autre : les caches sont vidés automatiquement.', 'pivot-offres' ),
					),
					array(
						'target' => '#pivot-wskey_stage',
						'title'  => __( 'La clé ws_key', 'pivot-offres' ),
						'text'   => __( 'Fournie par le CGT. Elle n\'apparaît jamais dans le journal. Une clé n\'accepte qu\'un seul opérateur touristique, et se désactive si elle est utilisée depuis plusieurs adresses IP.', 'pivot-offres' ),
					),
					array(
						'target' => 'input[name="pivot_test_connection"]',
						'title'  => __( 'Vérifier tout de suite', 'pivot-offres' ),
						'text'   => __( 'Ce bouton envoie un appel libre au thesaurus, puis un appel authentifié. Il vous dit précisément lequel des deux échoue.', 'pivot-offres' ),
					),
					array(
						'target' => '#pivot-detected_languages',
						'title'  => __( 'Les langues', 'pivot-offres' ),
						'text'   => __( 'Rien à choisir ici : le plugin suit l\'extension de traduction du site et publie une page par langue. PIVOT fournit les libellés en français, néerlandais, anglais et allemand ; une langue qu\'il ne couvre pas reprend la langue par défaut.', 'pivot-offres' ),
					),
					array(
						'target' => '#pivot-ttl_index',
						'title'  => __( 'Les durées de cache', 'pivot-offres' ),
						'text'   => __( 'Aucune offre n\'est écrite en base. Ces durées décident à quelle fréquence les fichiers de cache sont renouvelés ; vous pouvez toujours les vider à la main depuis Cache et outils.', 'pivot-offres' ),
					),
				),
			),
		);

		/**
		 * Permet d'ajouter, modifier ou retirer des visites guidées.
		 *
		 * @param array $tours Visites définies.
		 */
		return apply_filters( 'pivot_onboarding_tours', $tours );
	}

	/**
	 * Visites disponibles sur l'écran courant.
	 *
	 * @return array Liste de array( id, label, auto, trigger, prepare, steps ).
	 */
	public static function current_tours() {
		$screen = self::current_screen();
		$out    = array();

		foreach ( self::tours() as $id => $tour ) {
			if ( pivot_get( $tour, 'screen' ) !== $screen || empty( $tour['steps'] ) ) {
				continue;
			}

			$out[] = array(
				'id'      => $id,
				'label'   => pivot_get( $tour, 'label', '' ),
				'auto'    => ! empty( $tour['auto'] ),
				'trigger' => pivot_get( $tour, 'trigger', '' ),
				'prepare' => pivot_get( $tour, 'prepare', array() ),
				'steps'   => array_values( (array) $tour['steps'] ),
			);
		}

		return $out;
	}

	/**
	 * Charge le script et lui transmet la visite de l'écran.
	 *
	 * @param string $hook Écran courant.
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( $hook, 'pivot-' ) ) {
			return;
		}

		$tours = self::current_tours();

		if ( ! $tours ) {
			return;
		}

		wp_enqueue_style( 'pivot-onboarding', PIVOT_URL . 'assets/css/pivot-onboarding.css', array(), PIVOT_VERSION );
		wp_enqueue_script( 'pivot-onboarding', PIVOT_URL . 'assets/js/pivot-onboarding.js', array(), PIVOT_VERSION, true );

		wp_localize_script(
			'pivot-onboarding',
			'pivotOnboarding',
			array(
				'tours'    => $tours,
				'seen'     => array_values( self::seen() ),
				'restBase' => rest_url( Pivot_Rest::NAMESPACE_V1 ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'i18n'     => array(
					'next'     => __( 'Suivant', 'pivot-offres' ),
					'previous' => __( 'Précédent', 'pivot-offres' ),
					'finish'   => __( 'Terminer', 'pivot-offres' ),
					'skip'     => __( 'Passer le guide', 'pivot-offres' ),
					'close'    => __( 'Fermer le guide', 'pivot-offres' ),
					'replay'   => __( 'Revoir le guide', 'pivot-offres' ),
					'progress' => __( 'Étape %1$d sur %2$d', 'pivot-offres' ),
					'region'   => __( 'Visite guidée', 'pivot-offres' ),
				),
			)
		);
	}
}
