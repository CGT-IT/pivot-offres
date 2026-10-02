<?php
/**
 * Critère de date d'une page de listing.
 *
 * Le visiteur choisit une ou deux dates dans le calendrier de son navigateur,
 * borné par la première et la dernière date des offres. La comparaison est
 * fixée par l'administrateur et se lit à côté du champ : « du … au … »,
 * « à partir du … », « jusqu'au … », « le … ». Le filtrage se fait dans le
 * navigateur, par pivot-listing.js, qui s'appuie sur les attributs data-*
 * posés ici.
 *
 * Pour personnaliser, copiez ce fichier dans votre thème sous
 * pivot-offres/parts/filter-date.php.
 *
 * @package Pivot_Offres
 *
 * @var array $filter Critère, tel que l'index le décrit.
 */

defined( 'ABSPATH' ) || exit;

$pivot_key = (string) pivot_get( $filter, 'key', '' );
$pivot_min = (string) pivot_get( $filter, 'min', '' );
$pivot_max = (string) pivot_get( $filter, 'max', '' );

// Aucune offre de la page n'a de date pour ce champ : rien à comparer.
if ( '' === $pivot_key || '' === $pivot_min || '' === $pivot_max ) {
	return;
}

$pivot_operator = (string) pivot_get( $filter, 'operator', 'between' );
$pivot_legend   = 'pivot-l-' . $pivot_key;

// Le mot qui précède chaque champ dit ce qu'il borne.
$pivot_words = array(
	'min' => 'between' === $pivot_operator
		/* translators: devant la première date d'un intervalle : « du [10/10/2026] au [31/10/2026] ». */
		? _x( 'du', 'critère de date', 'pivot-offres' )
		/* translators: devant une date : « à partir du [10/10/2026] ». */
		: _x( 'à partir du', 'critère de date', 'pivot-offres' ),
	'max' => 'between' === $pivot_operator
		/* translators: devant la seconde date d'un intervalle : « du [10/10/2026] au [31/10/2026] ». */
		? _x( 'au', 'critère de date', 'pivot-offres' )
		/* translators: devant une date : « jusqu'au [31/10/2026] ». */
		: _x( 'jusqu\'au', 'critère de date', 'pivot-offres' ),
	/* translators: devant une date : « le [10/10/2026] ». */
	'eq'  => _x( 'le', 'critère de date', 'pivot-offres' ),
);
?>
<div class="pivot-field pivot-field-date"
	data-dates="<?php echo esc_attr( $pivot_key ); ?>"
	data-operator="<?php echo esc_attr( $pivot_operator ); ?>"
	data-match="<?php echo esc_attr( (string) pivot_get( $filter, 'match', 'overlap' ) ); ?>">
	<fieldset>
		<legend id="<?php echo esc_attr( $pivot_legend ); ?>"><?php echo esc_html( pivot_get( $filter, 'label', $pivot_key ) ); ?></legend>

		<div class="pivot-dates">
			<?php
			foreach ( Pivot_Listings::range_params( $filter ) as $pivot_bound => $pivot_param ) :
				$pivot_raw  = isset( $_GET[ $pivot_param ] ) ? sanitize_text_field( wp_unslash( $_GET[ $pivot_param ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
				$pivot_date = pivot_parse_date( $pivot_raw );
				$pivot_id   = 'pivot-f-' . $pivot_key . '-' . $pivot_bound;
				$pivot_word = 'pivot-w-' . $pivot_key . '-' . $pivot_bound;
				?>
				<span class="pivot-dates-word" id="<?php echo esc_attr( $pivot_word ); ?>"><?php echo esc_html( $pivot_words[ $pivot_bound ] ); ?></span>
				<input type="date"
					id="<?php echo esc_attr( $pivot_id ); ?>"
					name="<?php echo esc_attr( $pivot_param ); ?>"
					value="<?php echo esc_attr( $pivot_date ? pivot_date_iso( $pivot_date ) : '' ); ?>"
					min="<?php echo esc_attr( $pivot_min ); ?>"
					max="<?php echo esc_attr( $pivot_max ); ?>"
					data-bound="<?php echo esc_attr( $pivot_bound ); ?>"
					aria-labelledby="<?php echo esc_attr( $pivot_legend . ' ' . $pivot_word ); ?>" />
			<?php endforeach; ?>
		</div>
	</fieldset>
</div>
