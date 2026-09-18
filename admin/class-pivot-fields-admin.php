<?php
/**
 * Écran « Champs affichés ».
 *
 * PIVOT renvoie beaucoup plus de champs qu'une fiche ne devrait en montrer :
 * identifiants internes, données de gestion, doublons de descriptif. Le plugin
 * en masque une série par défaut ; cet écran permet d'ajuster cette liste sans
 * toucher au code.
 *
 * La frontière avec le travail du développeur reste la même que pour les
 * gabarits : ici on décide **quelles données sont montrées**, pas la façon dont
 * elles s'affichent.
 *
 * Deux choses n'y figurent pas :
 *
 *  - les champs écartés partout (filtres de catégorisation, Cirkwi, champs
 *    dépréciés) : ils ne sont pas dans le catalogue, donc pas filtrables non
 *    plus. Ils sont listés à part, en lecture seule, pour qu'on ne les cherche
 *    pas ailleurs ;
 *  - la mise en page, qui appartient aux gabarits.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Fields_Admin {

	/** @var Pivot_Fields_Admin|null */
	private static $instance = null;

	/**
	 * @return Pivot_Fields_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'handle_save' ) );
	}

	/**
	 * Enregistre le formulaire.
	 *
	 * Ne sont conservés que les écarts par rapport aux règles livrées : un
	 * champ laissé tel quel n'est pas enregistré, et suivra donc les règles du
	 * plugin même si elles changent.
	 */
	public function handle_save() {
		if ( ! isset( $_POST['pivot_fields_nonce'] ) || ! current_user_can( pivot_capability() ) ) {
			return;
		}

		check_admin_referer( 'pivot_save_fields', 'pivot_fields_nonce' );

		if ( isset( $_POST['pivot_reset'] ) ) {
			Pivot_Fields::save_rules( array() );
			self::back( 'reset' );
		}

		$posted  = isset( $_POST['pivot_visible'] ) ? (array) wp_unslash( $_POST['pivot_visible'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$visible = array();

		foreach ( $posted as $urn ) {
			$urn = self::clean_urn( $urn );

			if ( '' !== $urn ) {
				$visible[ $urn ] = true;
			}
		}

		$known = array();

		foreach ( (array) ( isset( $_POST['pivot_known'] ) ? wp_unslash( $_POST['pivot_known'] ) : array() ) as $urn ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$urn = self::clean_urn( $urn );

			if ( '' !== $urn ) {
				$known[] = $urn;
			}
		}

		// Deux passes. Les catégories d'abord : masquer une catégorie doit
		// enregistrer une seule règle, pas ses vingt champs un par un.
		//
		// Les champs sont ensuite évalués **à la lumière de ces catégories**,
		// sinon rétablir un champ dans une catégorie masquée ne produirait
		// aucun écart à enregistrer, et le champ resterait masqué malgré la
		// case cochée.
		$rules = array( 'hidden' => array(), 'shown' => array() );

		foreach ( array( true, false ) as $categories_first ) {
			foreach ( $known as $urn ) {
				$is_category = 0 === stripos( $urn, 'urn:cat:' );

				if ( $is_category !== $categories_first ) {
					continue;
				}

				$entry = self::entry_for( $urn );

				// Une urn absente du catalogue ne peut pas être évaluée : sa
				// catégorie est inconnue, donc les règles d'arbre ne
				// s'appliqueraient pas et on enregistrerait un écart faux. Cas
				// d'un formulaire périmé, ou d'un champ retiré du thesaurus
				// entre l'affichage et l'envoi.
				if ( ! $entry ) {
					continue;
				}

				$wanted = isset( $visible[ $urn ] );

				$reference = $categories_first
					? Pivot_Fields::is_hidden_by_default( $urn, $entry )
					: Pivot_Fields::is_hidden_with( $urn, $entry, $rules );

				if ( $wanted === ! $reference ) {
					continue;
				}

				$rules[ $wanted ? 'shown' : 'hidden' ][] = $urn;
			}
		}

		Pivot_Fields::save_rules( $rules );

		self::back( 'saved' );
	}

	/**
	 * Retourne à l'écran avec un message.
	 *
	 * @param string $message Identifiant du message.
	 */
	private static function back( $message ) {
		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'pivot-fields', 'message' => $message ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Contexte minimal d'un champ, pour que les règles d'arbre s'appliquent.
	 *
	 * @param string $urn Urn.
	 * @return array
	 */
	private static function entry_for( $urn ) {
		$catalogue = self::catalogue();

		return (array) pivot_get( $catalogue, array( 'entries', $urn ), array() );
	}

	/**
	 * Nettoie une urn reçue du formulaire.
	 *
	 * @param string $urn Valeur brute.
	 * @return string
	 */
	private static function clean_urn( $urn ) {
		$urn = trim( (string) $urn );

		return preg_match( '/^[a-z0-9:_-]+$/i', $urn ) ? $urn : '';
	}

	/**
	 * Catalogue des champs rencontrés, groupés par catégorie.
	 *
	 * Construit à partir de la structure logique des types réellement présents
	 * dans vos pages. Un type dont aucune page ne parle n'encombre pas l'écran.
	 *
	 * @param string|null $lang Langue des libellés.
	 * @return array
	 */
	private static function catalogue( $lang = null ) {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$groups   = array();
		$entries  = array();
		$excluded = array();

		foreach ( array_keys( Pivot_Types::present() ) as $type_id ) {
			$structure = Pivot_Thesaurus::type_structure( $type_id );

			foreach ( $structure as $urn => $entry ) {
				if ( 0 !== strpos( $urn, 'urn:fld:' ) && 0 !== strpos( $urn, 'urn:obj:' ) ) {
					continue;
				}

				$label = Pivot_I18n::pick( pivot_get( $entry, 'labels', array() ), $lang, '' );
				$label = $label ? $label : $urn;

				// Écartés partout : montrés à part, sans case à cocher.
				if ( Pivot_Fields::is_excluded( $urn, $entry ) ) {
					$excluded[ $urn ] = $label;
					continue;
				}

				$cat    = (string) pivot_get( $entry, 'cat', '' );
				$cat    = $cat ? $cat : 'urn:cat:autres';
				$subcat = (string) pivot_get( $entry, 'subcat', '' );

				if ( ! isset( $groups[ $cat ] ) ) {
					$groups[ $cat ] = array(
						'urn'     => $cat,
						'label'   => self::label_of( $structure, $cat, $lang ),
						'order'   => (int) pivot_get( $structure, array( $cat, 'order' ), 999 ),
						'fields'  => array(),
						'subcats' => array(),
					);

					$entries[ $cat ] = array( 'cat' => $cat );
				}

				// Les sous-catégories comptent : plusieurs règles livrées
				// portent sur elles, pas sur la catégorie entière.
				if ( $subcat ) {
					if ( ! isset( $groups[ $cat ]['subcats'][ $subcat ] ) ) {
						$groups[ $cat ]['subcats'][ $subcat ] = array(
							'urn'    => $subcat,
							'label'  => self::label_of( $structure, $subcat, $lang ),
							'order'  => (int) pivot_get( $structure, array( $subcat, 'order' ), 999 ),
							'fields' => array(),
						);

						$entries[ $subcat ] = array( 'cat' => $cat, 'subcat' => $subcat );
					}

					$bucket = &$groups[ $cat ]['subcats'][ $subcat ]['fields'];
				} else {
					$bucket = &$groups[ $cat ]['fields'];
				}

				if ( ! isset( $bucket[ $urn ] ) ) {
					$entries[ $urn ] = $entry;

					$bucket[ $urn ] = array(
						'urn'   => $urn,
						'label' => $label,
						'order' => (int) pivot_get( $entry, 'order', 999 ),
					);
				}

				unset( $bucket );
			}
		}

		uasort( $groups, array( __CLASS__, 'compare' ) );

		foreach ( $groups as $cat => $group ) {
			uasort( $groups[ $cat ]['fields'], array( __CLASS__, 'compare' ) );
			uasort( $groups[ $cat ]['subcats'], array( __CLASS__, 'compare' ) );

			foreach ( $group['subcats'] as $subcat => $sub ) {
				uasort( $groups[ $cat ]['subcats'][ $subcat ]['fields'], array( __CLASS__, 'compare' ) );
			}
		}

		asort( $excluded );

		$cache = array( 'groups' => $groups, 'entries' => $entries, 'excluded' => $excluded );

		return $cache;
	}

	/**
	 * Tri par ordre déclaré, puis par libellé.
	 *
	 * @param array $a Première entrée.
	 * @param array $b Seconde entrée.
	 * @return int
	 */
	private static function compare( $a, $b ) {
		if ( $a['order'] === $b['order'] ) {
			return strcmp( (string) $a['label'], (string) $b['label'] );
		}

		return $a['order'] - $b['order'];
	}

	/**
	 * Libellé d'une catégorie, à défaut son urn.
	 *
	 * @param array       $structure Structure du type.
	 * @param string      $urn       Urn de la catégorie.
	 * @param string|null $lang      Langue.
	 * @return string
	 */
	private static function label_of( $structure, $urn, $lang ) {
		$label = Pivot_I18n::pick( pivot_get( $structure, array( $urn, 'labels' ), array() ), $lang, '' );

		return $label ? $label : $urn;
	}

	/**
	 * Affiche l'écran.
	 */
	public static function render() {
		if ( ! current_user_can( pivot_capability() ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas accès à cet écran.', 'pivot-offres' ) );
		}

		$catalogue = self::catalogue();
		$rules     = Pivot_Fields::rules();
		$reglages  = count( $rules['hidden'] ) + count( $rules['shown'] );

		echo '<div class="wrap pivot-wrap pivot-fields">';
		echo '<h1>' . esc_html__( 'Champs affichés', 'pivot-offres' ) . '</h1>';

		$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'saved' === $message || 'reset' === $message ) {
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html(
					'reset' === $message
						? __( 'Réglages effacés : les règles d\'origine s\'appliquent à nouveau.', 'pivot-offres' )
						: __( 'Réglages enregistrés.', 'pivot-offres' )
				)
			);

			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Les fiches détail suivent immédiatement. Les vignettes et les descriptifs des listings viennent des index : reconstruisez-les pour que le changement s\'y voie.', 'pivot-offres' )
				. ' <a href="' . esc_url( admin_url( 'admin.php?page=pivot-tools' ) ) . '">'
				. esc_html__( 'Aller à Cache et outils', 'pivot-offres' ) . '</a></p></div>';
		}

		echo '<p>' . esc_html__( 'Décochez un champ pour qu\'il cesse d\'apparaître sur les fiches et les vignettes. Il reste utilisable comme critère de recherche : masquer n\'est pas exclure.', 'pivot-offres' ) . '</p>';
		echo '<p>' . esc_html__( 'Décocher une catégorie masque tout ce qu\'elle contient en une seule règle. Vous pouvez ensuite rétablir un champ précis à l\'intérieur.', 'pivot-offres' ) . '</p>';

		if ( ! $catalogue['groups'] ) {
			echo '<div class="pivot-empty"><p>' . esc_html__( 'Aucun champ connu pour l\'instant.', 'pivot-offres' ) . '</p><p>'
				. esc_html__( 'La liste vient de la structure des types rencontrés dans vos pages de listing : elle se remplit après la première construction d\'index.', 'pivot-offres' )
				. '</p></div></div>';
			return;
		}

		echo '<form method="post" class="pivot-fields-form">';
		wp_nonce_field( 'pivot_save_fields', 'pivot_fields_nonce' );

		printf(
			'<p class="pivot-field-search"><label>%1$s <input type="search" id="pivot-field-filter" class="regular-text" placeholder="%2$s" /></label></p>',
			esc_html__( 'Chercher un champ', 'pivot-offres' ),
			esc_attr__( 'libellé ou urn', 'pivot-offres' )
		);

		$compte = array( 'total' => 0, 'masques' => 0 );

		foreach ( $catalogue['groups'] as $group ) {
			echo '<div class="pivot-field-group">';

			self::render_toggle( $group, $catalogue, $rules, 'h2' );

			echo '<ul class="pivot-field-list">';
			foreach ( $group['fields'] as $field ) {
				self::render_field( $field, $catalogue, $rules, $compte );
			}
			echo '</ul>';

			foreach ( $group['subcats'] as $sub ) {
				echo '<div class="pivot-field-subgroup">';
				self::render_toggle( $sub, $catalogue, $rules, 'h3' );

				echo '<ul class="pivot-field-list">';
				foreach ( $sub['fields'] as $field ) {
					self::render_field( $field, $catalogue, $rules, $compte );
				}
				echo '</ul></div>';
			}

			echo '</div>';
		}

		printf(
			'<p class="description">%1$s%2$s</p>',
			esc_html(
				sprintf(
					/* translators: 1 : nombre de champs masqués, 2 : nombre total de champs. */
					__( '%1$d champs masqués sur %2$d.', 'pivot-offres' ),
					$compte['masques'],
					$compte['total']
				)
			),
			$reglages
				? ' ' . esc_html(
					sprintf(
						/* translators: %d : nombre de réglages enregistrés. */
						_n( '%d réglage s\'écarte des règles d\'origine.', '%d réglages s\'écartent des règles d\'origine.', $reglages, 'pivot-offres' ),
						$reglages
					)
				)
				: ''
		);

		echo '<p class="submit">';
		submit_button( __( 'Enregistrer les champs affichés', 'pivot-offres' ), 'primary', 'submit', false );

		if ( $reglages ) {
			printf(
				' <button type="submit" name="pivot_reset" value="1" class="button button-secondary">%s</button>',
				esc_html__( 'Revenir aux règles d\'origine', 'pivot-offres' )
			);
		}

		echo '</p>';
		echo '</form>';

		self::render_excluded( $catalogue['excluded'] );

		echo '</div>';
	}

	/**
	 * Case d'une catégorie ou d'une sous-catégorie.
	 *
	 * @param array  $group     Groupe.
	 * @param array  $catalogue Catalogue complet.
	 * @param array  $rules     Réglages enregistrés.
	 * @param string $tag       Balise de titre.
	 */
	private static function render_toggle( $group, $catalogue, $rules, $tag ) {
		$urn      = $group['urn'];
		$hidden   = Pivot_Fields::is_hidden( $urn, pivot_get( $catalogue, array( 'entries', $urn ), array() ) );
		$adjusted = in_array( $urn, $rules['hidden'], true ) || in_array( $urn, $rules['shown'], true );

		printf(
			'<%1$s class="pivot-field-toggle"><label><input type="checkbox" class="pivot-toggle-all" name="pivot_visible[]" value="%2$s"%3$s /> %4$s</label>'
			. '<input type="hidden" name="pivot_known[]" value="%2$s" />'
			. ' <span class="pivot-type-id">%2$s</span>%5$s</%1$s>',
			esc_attr( $tag ),
			esc_attr( $urn ),
			checked( ! $hidden, true, false ),
			esc_html( $group['label'] ),
			$adjusted ? ' <span class="pivot-type-origin pivot-type-origin-manual">' . esc_html__( 'réglé ici', 'pivot-offres' ) . '</span>' : ''
		);
	}

	/**
	 * Case d'un champ.
	 *
	 * @param array $field     Champ.
	 * @param array $catalogue Catalogue complet.
	 * @param array $rules     Réglages enregistrés.
	 * @param array $compte    Compteurs, modifiés au passage.
	 */
	private static function render_field( $field, $catalogue, $rules, &$compte ) {
		$urn      = $field['urn'];
		$hidden   = Pivot_Fields::is_hidden( $urn, pivot_get( $catalogue, array( 'entries', $urn ), array() ) );
		$adjusted = in_array( $urn, $rules['hidden'], true ) || in_array( $urn, $rules['shown'], true );

		++$compte['total'];
		$compte['masques'] += $hidden ? 1 : 0;

		printf(
			'<li><label><input type="checkbox" name="pivot_visible[]" value="%1$s"%2$s /> %3$s</label>'
			. '<input type="hidden" name="pivot_known[]" value="%1$s" />'
			. ' <code>%1$s</code>%4$s</li>',
			esc_attr( $urn ),
			checked( ! $hidden, true, false ),
			esc_html( $field['label'] ),
			$adjusted ? ' <span class="pivot-type-origin pivot-type-origin-manual">' . esc_html__( 'réglé ici', 'pivot-offres' ) . '</span>' : ''
		);
	}

	/**
	 * Champs écartés partout, en lecture seule.
	 *
	 * Les montrer évite de les chercher : ils ne sont ni affichables ni
	 * filtrables, et ce n'est pas un oubli.
	 *
	 * @param array $excluded urn => libellé.
	 */
	private static function render_excluded( $excluded ) {
		if ( ! $excluded ) {
			return;
		}

		echo '<div class="pivot-field-group pivot-field-excluded">';
		echo '<h2>' . esc_html__( 'Champs écartés partout', 'pivot-offres' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'Filtres de catégorisation propres à chaque opérateur, champs Cirkwi et champs dépréciés. Ils ne décrivent pas l\'offre : ils ne sont ni affichables ni utilisables comme critère, et ne se règlent pas ici.', 'pivot-offres' )
			. '</p>';

		echo '<ul class="pivot-field-list">';

		foreach ( $excluded as $urn => $label ) {
			printf( '<li>%1$s <code>%2$s</code></li>', esc_html( $label ), esc_html( $urn ) );
		}

		echo '</ul></div>';
	}
}
