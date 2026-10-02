<?php
/**
 * Écran « Shortcode ».
 *
 * Construit le shortcode d'insertion à partir de contrôles, plutôt que de
 * laisser retenir une syntaxe. L'aperçu affiche le résultat réel, avec les
 * styles du site.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Shortcode_Admin {

	/** @var Pivot_Shortcode_Admin|null */
	private static $instance = null;

	/**
	 * @return Pivot_Shortcode_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Affiche l'écran.
	 */
	public static function render() {
		if ( ! current_user_can( pivot_capability() ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas accès à cet écran.', 'pivot-offres' ) );
		}

		$listings = Pivot_Listings::active();
		$preview  = '';
		$values   = array();
		$source   = 'listing';

		if ( isset( $_POST['pivot_shortcode_nonce'] ) && check_admin_referer( 'pivot_preview_shortcode', 'pivot_shortcode_nonce' ) ) {
			// Les réglages sont relus et réinjectés dans le formulaire :
			// l'aperçu ne doit pas faire perdre ce qu'on était en train d'essayer.
			foreach ( (array) ( isset( $_POST['pivot_sc'] ) ? wp_unslash( $_POST['pivot_sc'] ) : array() ) as $att => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$values[ sanitize_key( $att ) ] = sanitize_text_field( $value );
			}

			if ( ! empty( $_POST['pivot_sc_source'] ) ) {
				$source = sanitize_key( wp_unslash( $_POST['pivot_sc_source'] ) );
			}

			$code    = isset( $_POST['shortcode'] ) ? wp_unslash( $_POST['shortcode'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$preview = do_shortcode( wp_kses_post( $code ) );
		}

		$get = static function ( $att, $default = '' ) use ( $values ) {
			return isset( $values[ $att ] ) && '' !== $values[ $att ] ? $values[ $att ] : $default;
		};

		wp_enqueue_style( 'pivot-offres', PIVOT_URL . 'assets/css/pivot.css', array(), PIVOT_VERSION );

		echo '<div class="wrap pivot-wrap pivot-shortcode-builder">';
		echo '<h1>' . esc_html__( 'Insérer des offres dans une page', 'pivot-offres' ) . '</h1>';
		echo '<p>' . esc_html__( 'Réglez ce que vous voulez afficher, copiez le shortcode obtenu et collez-le dans une page ou un article. Il produit une liste de vignettes : ni carte, ni critères de recherche.', 'pivot-offres' ) . '</p>';

		echo '<form method="post" class="pivot-sc-form">';
		wp_nonce_field( 'pivot_preview_shortcode', 'pivot_shortcode_nonce' );

		echo '<table class="form-table" role="presentation"><tbody>';

		// Source.
		echo '<tr><th scope="row">' . esc_html__( 'Quelles offres', 'pivot-offres' ) . '</th><td>';

		self::radio( 'source', 'listing', __( 'Celles d\'une page de listing', 'pivot-offres' ), 'listing' === $source );
		printf( '<div class="pivot-sc-source" data-source="listing"%s>', 'listing' === $source ? '' : ' hidden' );

		if ( $listings ) {
			echo '<select class="pivot-sc-field" data-att="listing" name="pivot_sc[listing]">';

			foreach ( $listings as $listing ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $listing['id'] ),
					selected( $get( 'listing' ), $listing['id'], false ),
					esc_html( Pivot_Listings::title( $listing ) )
				);
			}

			echo '</select>';
			echo '<p class="description">' . esc_html__( 'Reprend l\'index déjà construit : aucun appel supplémentaire à PIVOT.', 'pivot-offres' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Aucune page de listing active pour l\'instant.', 'pivot-offres' ) . '</p>';
		}

		echo '</div>';

		self::radio( 'source', 'query', __( 'Celles d\'une requête PIVOT', 'pivot-offres' ), 'query' === $source );
		printf( '<div class="pivot-sc-source" data-source="query"%s>', 'query' === $source ? '' : ' hidden' );
		printf(
			'<input type="text" class="regular-text code pivot-sc-field" data-att="query" name="pivot_sc[query]" value="%s" placeholder="%s" />',
			esc_attr( $get( 'query' ) ),
			esc_attr( 'QRY-00-0000-0000' )
		);
		echo '</div>';

		self::radio( 'source', 'codes', __( 'Une sélection que je choisis', 'pivot-offres' ), 'codes' === $source );
		printf( '<div class="pivot-sc-source" data-source="codes"%s>', 'codes' === $source ? '' : ' hidden' );
		printf(
			'<input type="text" class="large-text code pivot-sc-field" data-att="codes" name="pivot_sc[codes]" value="%s" placeholder="%s" />',
			esc_attr( $get( 'codes' ) ),
			esc_attr( 'ALD-01-00096Z, CHB-01-000RV1' )
		);
		echo '<p class="description">' . esc_html__( 'Codes séparés par des virgules. L\'ordre saisi est l\'ordre affiché.', 'pivot-offres' ) . '</p>';
		echo '</div>';

		echo '</td></tr>';

		// Mise en forme.
		echo '<tr><th scope="row">' . esc_html__( 'Présentation', 'pivot-offres' ) . '</th><td>';
		printf(
			'<label>%s <input type="number" min="1" max="48" value="%s" class="small-text pivot-sc-field" data-att="nombre" name="pivot_sc[nombre]" /></label> ',
			esc_html__( 'Offres', 'pivot-offres' ),
			esc_attr( $get( 'nombre', '3' ) )
		);
		printf(
			'<label>%s <input type="number" min="1" max="6" value="%s" class="small-text pivot-sc-field" data-att="colonnes" name="pivot_sc[colonnes]" /></label> ',
			esc_html__( 'Colonnes', 'pivot-offres' ),
			esc_attr( $get( 'colonnes', '3' ) )
		);

		echo '<label>' . esc_html__( 'Ordre', 'pivot-offres' ) . ' <select class="pivot-sc-field" data-att="tri" name="pivot_sc[tri]">';

		foreach ( array(
			'defaut'    => __( 'Celui de PIVOT', 'pivot-offres' ),
			'nom'       => __( 'Alphabétique', 'pivot-offres' ),
			'aleatoire' => __( 'Aléatoire', 'pivot-offres' ),
		) as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $get( 'tri', 'defaut' ), $value, false ),
				esc_html( $label )
			);
		}

		echo '</select></label>';
		echo '</td></tr>';

		// Restriction.
		echo '<tr><th scope="row">' . esc_html__( 'Restreindre', 'pivot-offres' ) . '</th><td>';
		printf(
			'<input type="text" class="regular-text code pivot-sc-field" data-att="filtre" name="pivot_sc[filtre]" value="%s" placeholder="%s" />',
			esc_attr( $get( 'filtre' ) ),
			esc_attr__( 'province:namur', 'pivot-offres' )
		);
		echo '<p class="description">' . esc_html__( 'Facultatif. Forme cle:valeur, plusieurs séparées par une barre verticale. Les clés sont celles des critères de la page choisie.', 'pivot-offres' ) . '</p>';

		self::filter_hints( $listings );

		echo '</td></tr>';

		// Habillage.
		echo '<tr><th scope="row">' . esc_html__( 'Habillage', 'pivot-offres' ) . '</th><td>';
		printf(
			'<p><label>%s <input type="text" class="regular-text pivot-sc-field" data-att="titre" name="pivot_sc[titre]" value="%s" placeholder="%s" /></label></p>',
			esc_html__( 'Titre', 'pivot-offres' ),
			esc_attr( $get( 'titre' ) ),
			esc_attr__( 'Nos coups de cœur', 'pivot-offres' )
		);
		printf(
			'<p><label><input type="checkbox" class="pivot-sc-field" data-att="lien" data-on="oui" name="pivot_sc[lien]" value="oui"%s /> %s</label></p>',
			checked( $get( 'lien' ), 'oui', false ),
			esc_html__( 'Ajouter un lien vers la page de listing', 'pivot-offres' )
		);
		echo '</td></tr>';

		echo '</tbody></table>';

		// Résultat.
		echo '<h2>' . esc_html__( 'Votre shortcode', 'pivot-offres' ) . '</h2>';
		echo '<p class="pivot-sc-result">';
		echo '<input type="text" name="shortcode" id="pivot-sc-output" class="large-text code" readonly onfocus="this.select()" value="" /> ';
		printf(
			'<button type="button" class="button pivot-sc-copy">%s</button> ',
			esc_html__( 'Copier', 'pivot-offres' )
		);
		printf(
			'<button type="submit" class="button button-primary">%s</button>',
			esc_html__( 'Aperçu', 'pivot-offres' )
		);
		echo '<span class="pivot-sc-copied" role="status"></span>';
		echo '</p>';

		echo '</form>';

		if ( $preview ) {
			echo '<h2>' . esc_html__( 'Aperçu', 'pivot-offres' ) . '</h2>';
			echo '<div class="pivot-sc-preview">' . $preview . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p class="description">' . esc_html__( 'Rendu avec les styles de l\'extension. Votre thème peut le présenter différemment.', 'pivot-offres' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Un bouton radio de source.
	 *
	 * @param string $name    Nom du groupe.
	 * @param string $value   Valeur.
	 * @param string $label   Libellé.
	 * @param bool   $checked Coché par défaut.
	 */
	private static function radio( $name, $value, $label, $checked = false ) {
		printf(
			'<p><label><input type="radio" name="pivot_sc_%1$s" class="pivot-sc-source-choice" value="%2$s"%3$s /> %4$s</label></p>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Rappel des critères disponibles, page par page.
	 *
	 * @param array $listings Pages de listing actives.
	 */
	private static function filter_hints( $listings ) {
		$hints = array();

		foreach ( $listings as $listing ) {
			$keys = array();

			foreach ( (array) pivot_get( $listing, 'filters', array() ) as $filter ) {
				$key = pivot_get( $filter, 'key', '' );

				if ( ! $key ) {
					continue;
				}

				$values = (array) pivot_get( $listing, array( 'facet_values', $key ), array() );
				$sample = array_slice( $values, 0, 4 );

				$line = $sample
					? $key . ' : ' . implode( ', ', $sample ) . ( count( $values ) > 4 ? '…' : '' )
					: $key;

				// Une date fixe se périme : on rappelle la forme relative.
				if ( 'date' === pivot_get( $filter, 'type' ) ) {
					$line .= ' · ' . $key . ':aujourdhui..+30';
				}

				$keys[] = $line;
			}

			if ( $keys ) {
				$hints[ Pivot_Listings::title( $listing ) ] = $keys;
			}
		}

		if ( ! $hints ) {
			return;
		}

		echo '<details class="pivot-sc-hints"><summary>' . esc_html__( 'Clés et valeurs disponibles', 'pivot-offres' ) . '</summary>';

		foreach ( $hints as $title => $keys ) {
			echo '<p><strong>' . esc_html( $title ) . '</strong><br />';

			foreach ( $keys as $line ) {
				echo '<code>' . esc_html( $line ) . '</code><br />';
			}

			echo '</p>';
		}

		echo '</details>';
	}
}
