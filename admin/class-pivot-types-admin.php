<?php
/**
 * Écran « Types d'offres ».
 *
 * Associe chaque type rencontré dans vos pages à une famille — celle qui décide
 * du gabarit utilisé pour la fiche et pour la vignette — et montre, pour
 * chacun, quel gabarit s'applique réellement aujourd'hui.
 *
 * L'écran ne construit aucun visuel : ce que montre une fiche ou une vignette
 * est écrit dans un gabarit, par un développeur. Il sert à savoir où écrire.
 *
 * Seuls les types réellement présents sont proposés : la liste complète de
 * PIVOT en compte une centaine dont la plupart ne vous concernent pas.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Types_Admin {

	/** @var Pivot_Types_Admin|null */
	private static $instance = null;

	/**
	 * @return Pivot_Types_Admin
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
	 * Seuls les écarts sont conservés. Enregistrer la famille de tous les
	 * types les figerait : PIVOT peut reclasser un type, et la valeur du jour
	 * de l'enregistrement l'emporterait pour toujours.
	 */
	public function handle_save() {
		if ( ! isset( $_POST['pivot_types_nonce'] ) || ! current_user_can( pivot_capability() ) ) {
			return;
		}

		check_admin_referer( 'pivot_save_types', 'pivot_types_nonce' );

		// Sans réponse du thesaurus, aucune famille n'est connue : enregistrer
		// reviendrait à inscrire « autre » partout, définitivement.
		if ( ! Pivot_Types::families_available() ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'pivot-types', 'message' => 'unavailable' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$raw   = isset( $_POST['pivot_types'] ) ? wp_unslash( $_POST['pivot_types'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$clean = array();

		foreach ( (array) $raw as $type_id => $config ) {
			$type_id = (int) $type_id;
			$family  = sanitize_key( pivot_get( $config, 'family', '' ) );

			if ( ! $type_id || ! $family ) {
				continue;
			}

			// La famille de PIVOT n'a pas à être recopiée : la laisser vide,
			// c'est continuer à la suivre.
			if ( Pivot_Types::from_thesaurus( $type_id ) === $family ) {
				continue;
			}

			$clean[ $type_id ] = array( 'family' => $family );
		}

		Pivot_Types::save( $clean );

		wp_safe_redirect( add_query_arg( array( 'page' => 'pivot-types', 'message' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Affiche l'écran.
	 */
	public static function render() {
		if ( ! current_user_can( pivot_capability() ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas accès à cet écran.', 'pivot-offres' ) );
		}

		$types     = Pivot_Types::present();
		$families  = Pivot_Types::families();
		$available = Pivot_Types::families_available();

		echo '<div class="wrap pivot-wrap pivot-types">';
		echo '<h1>' . esc_html__( 'Types d\'offres', 'pivot-offres' ) . '</h1>';

		$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'saved' === $message ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Familles enregistrées.', 'pivot-offres' ) . '</p></div>';
		} elseif ( 'unavailable' === $message ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Rien n\'a été enregistré : les familles de PIVOT n\'ont pas pu être lues.', 'pivot-offres' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'La famille décide du gabarit utilisé pour la fiche et pour la vignette. Elle vient du thesaurus de PIVOT ; vous pouvez la corriger ici pour un type qui mérite un traitement à part.', 'pivot-offres' ) . '</p>';
		echo '<p>' . esc_html__( 'Cet écran ne compose aucun visuel : il indique quel fichier de gabarit s\'applique, pour que votre développeur sache où écrire. Le gabarit le plus précis disponible l\'emporte toujours.', 'pivot-offres' ) . '</p>';

		if ( ! $available ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Les familles n\'ont pas pu être lues dans le thesaurus de PIVOT. Sans elles, tous les types passent par le gabarit commun.', 'pivot-offres' )
				. '</p><p>'
				. esc_html__( 'Vérifiez la connexion dans Réglages, puis réinitialisez le cache du thesaurus depuis Cache et outils. Aucune famille de remplacement n\'est inventée : elle ne correspondrait à aucun de vos gabarits.', 'pivot-offres' )
				. '</p></div>';
		}

		if ( ! $types ) {
			echo '<div class="pivot-empty"><p>' . esc_html__( 'Aucun type rencontré pour l\'instant.', 'pivot-offres' ) . '</p><p>'
				. esc_html__( 'Les types apparaissent ici après la première construction d\'index d\'une page de listing : ce sont ceux que vos requêtes renvoient réellement.', 'pivot-offres' )
				. '</p></div></div>';
			return;
		}

		echo '<form method="post">';
		wp_nonce_field( 'pivot_save_types', 'pivot_types_nonce' );

		foreach ( $types as $type_id => $label ) {
			self::render_type( $type_id, $label, $families, $available );
		}

		if ( $available ) {
			submit_button( __( 'Enregistrer les familles', 'pivot-offres' ) );
		}

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Un type d'offre.
	 *
	 * @param int    $type_id   Identifiant.
	 * @param string $label     Libellé.
	 * @param array  $families  Familles disponibles.
	 * @param bool   $available Le thesaurus a-t-il répondu ?
	 */
	private static function render_type( $type_id, $label, $families, $available ) {
		$family     = Pivot_Types::family( $type_id );
		$from_pivot = Pivot_Types::from_thesaurus( $type_id );
		$overridden = Pivot_Types::is_overridden( $type_id );

		echo '<div class="pivot-type-card">';

		printf(
			'<h2>%1$s <span class="pivot-type-id">%2$s</span></h2>',
			esc_html( $label ? $label : sprintf( 'Type %d', $type_id ) ),
			esc_html( 'urn:typ:' . $type_id )
		);

		echo '<p class="pivot-type-family"><label>' . esc_html__( 'Famille', 'pivot-offres' ) . ' ';
		printf( '<select name="pivot_types[%d][family]"%s>', (int) $type_id, $available ? '' : ' disabled' );

		foreach ( $families as $key => $name ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $family, $key, false ),
				esc_html( $name )
			);
		}

		echo '</select></label> ';

		// D'où vient la famille retenue : de PIVOT, ou d'une correction.
		if ( $overridden ) {
			printf(
				'<span class="pivot-type-origin pivot-type-origin-manual">%s</span>',
				esc_html(
					sprintf(
						/* translators: %s : nom de la famille déclarée par PIVOT. */
						__( 'corrigée ici — PIVOT classe ce type dans « %s »', 'pivot-offres' ),
						Pivot_Types::family_name( $from_pivot )
					)
				)
			);
		} elseif ( $available ) {
			echo '<span class="pivot-type-origin">' . esc_html__( 'telle que PIVOT la déclare', 'pivot-offres' ) . '</span>';
		}

		echo '</p>';

		self::render_templates( $type_id, $family );
		self::render_card_fields( $type_id );

		echo '</div>';
	}

	/**
	 * Les gabarits cherchés, et celui qui s'applique.
	 *
	 * @param int    $type_id Identifiant.
	 * @param string $family  Famille retenue.
	 */
	private static function render_templates( $type_id, $family ) {
		$rows = array(
			__( 'Fiche détail', 'pivot-offres' ) => array(
				'names' => array(
					sprintf( 'detail-type-%d.php', $type_id ),
					$family ? sprintf( 'detail-%s.php', $family ) : '',
					'detail.php',
				),
				'used'  => Pivot_Templates::locate( 'detail', $type_id ),
			),
			__( 'Vignette', 'pivot-offres' )     => array(
				'names' => array(
					sprintf( 'parts/card-type-%d.php', $type_id ),
					$family ? sprintf( 'parts/card-%s.php', $family ) : '',
					'parts/card.php',
				),
				'used'  => Pivot_Templates::card_file( $type_id ),
			),
		);

		echo '<table class="pivot-type-templates"><tbody>';

		foreach ( $rows as $title => $row ) {
			$parts = array();

			foreach ( array_filter( $row['names'] ) as $name ) {
				$parts[] = '<code>' . esc_html( $name ) . '</code>';
			}

			echo '<tr><th>' . esc_html( $title ) . '</th><td>';
			echo wp_kses_post( implode( ' <span aria-hidden="true">→</span> ', $parts ) );

			// Ce qui est réellement chargé : le thème, ou le plugin.
			$used  = (string) $row['used'];
			$theme = 0 !== strpos( $used, PIVOT_DIR );

			printf(
				'<span class="pivot-type-used %1$s">%2$s</span>',
				esc_attr( $theme ? 'pivot-type-used-theme' : '' ),
				esc_html(
					$theme
						/* translators: %s : nom du fichier de gabarit. */
						? sprintf( __( 'en service : %s, dans votre thème', 'pivot-offres' ), basename( $used ) )
						: __( 'en service : le gabarit commun du plugin', 'pivot-offres' )
				)
			);

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'À placer dans un dossier pivot-offres/ de votre thème. Le premier fichier trouvé est utilisé.', 'pivot-offres' )
		);
	}

	/**
	 * Champs supplémentaires déclarés pour les vignettes de ce type.
	 *
	 * Ils sont déclarés par le code, pas ici. Les afficher évite de chercher
	 * pourquoi une donnée manque dans une vignette — ou pourquoi une donnée
	 * embarquée ne se voit pas.
	 *
	 * @param int $type_id Identifiant.
	 */
	private static function render_card_fields( $type_id ) {
		$fields = Pivot_Types::card_fields( $type_id );

		if ( ! $fields ) {
			return;
		}

		$parts = array();

		foreach ( $fields as $urn ) {
			$parts[] = '<code>' . esc_html( $urn ) . '</code>';
		}

		printf(
			'<p class="description pivot-type-extras">%1$s %2$s<br />%3$s</p>',
			esc_html__( 'Champs embarqués dans la vignette :', 'pivot-offres' ),
			wp_kses_post( implode( ', ', $parts ) ),
			esc_html__( 'Déclarés par le filtre pivot_card_fields. Ils voyagent dans l\'index ; c\'est au gabarit de vignette de les afficher.', 'pivot-offres' )
		);
	}
}
