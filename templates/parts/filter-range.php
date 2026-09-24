<?php
/**
 * Critère numérique d'une page de listing.
 *
 * Le visiteur tape un nombre, ou règle une jauge bornée par la plus petite et
 * la plus grande valeur des offres. La comparaison est fixée par
 * l'administrateur et se lit à côté du champ : « au moins », « au plus »,
 * « entre … et … ». Le filtrage se fait dans le navigateur, par
 * pivot-listing.js, qui s'appuie sur les attributs data-* posés ici.
 *
 * Pour personnaliser, copiez ce fichier dans votre thème sous
 * pivot-offres/parts/filter-range.php.
 *
 * @package Pivot_Offres
 *
 * @var array $filter Critère, tel que l'index le décrit.
 */

defined( 'ABSPATH' ) || exit;

$pivot_key = (string) pivot_get( $filter, 'key', '' );
$pivot_min = pivot_get( $filter, 'min' );
$pivot_max = pivot_get( $filter, 'max' );

// Aucune offre de la page n'a de valeur pour ce champ : rien à comparer.
if ( '' === $pivot_key || null === $pivot_min || null === $pivot_max ) {
	return;
}

$pivot_operator = (string) pivot_get( $filter, 'operator', 'gte' );
$pivot_slider   = 'slider' === pivot_get( $filter, 'widget', 'input' ) && 'eq' !== $pivot_operator;
$pivot_unit     = (string) pivot_get( $filter, 'unit', '' );
$pivot_legend   = 'pivot-l-' . $pivot_key;
$pivot_error    = 'pivot-e-' . $pivot_key;

$pivot_number = static function ( $number ) {
	$decimals = is_float( $number ) ? min( 2, strlen( substr( (string) strrchr( (string) $number, '.' ), 1 ) ) ) : 0;

	return number_format_i18n( $number, $decimals );
};

// Avec son unité, pour la jauge ; le champ de saisie l'affiche à côté.
$pivot_format = static function ( $number ) use ( $pivot_number, $pivot_unit ) {
	return '' !== $pivot_unit ? $pivot_number( $number ) . "\u{00a0}" . $pivot_unit : $pivot_number( $number );
};

// Une borne par champ. En saisie, l'intervalle se lit « entre … et … » ; en
// jauge, chaque curseur se nomme par ce qu'il borne.
$pivot_words = array(
	/* translators: devant le premier champ d'un intervalle : « entre [10] et [50] ». */
	'min' => 'between' === $pivot_operator && ! $pivot_slider ? __( 'entre', 'pivot-offres' ) : __( 'au moins', 'pivot-offres' ),
	/* translators: devant le second champ d'un intervalle : « entre [10] et [50] ». */
	'max' => 'between' === $pivot_operator && ! $pivot_slider ? __( 'et', 'pivot-offres' ) : __( 'au plus', 'pivot-offres' ),
	'eq'  => '',
);

$pivot_bounds  = array();
$pivot_invalid = false;

foreach ( Pivot_Listings::range_params( $filter ) as $pivot_bound => $pivot_param ) {
	$pivot_raw   = isset( $_GET[ $pivot_param ] ) ? sanitize_text_field( wp_unslash( $_GET[ $pivot_param ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$pivot_value = pivot_parse_number( $pivot_raw );

	$pivot_bounds[ $pivot_bound ] = array(
		'id'      => 'pivot-f-' . $pivot_key . '-' . $pivot_bound,
		'word'    => 'pivot-w-' . $pivot_key . '-' . $pivot_bound,
		'unit'    => 'pivot-u-' . $pivot_key . '-' . $pivot_bound,
		'param'   => $pivot_param,
		'raw'     => $pivot_raw,
		'value'   => $pivot_value,
		'invalid' => '' !== $pivot_raw && null === $pivot_value,
		// Position d'une jauge qui ne restreint rien : en butée.
		'neutral' => 'max' === $pivot_bound ? $pivot_max : $pivot_min,
	);

	$pivot_invalid = $pivot_invalid || $pivot_bounds[ $pivot_bound ]['invalid'];
}

// Texte de la jauge au premier affichage ; le script le tient ensuite à jour.
$pivot_output = '';

if ( $pivot_slider ) {
	$pivot_low  = isset( $pivot_bounds['min'] ) ? $pivot_bounds['min']['value'] : null;
	$pivot_high = isset( $pivot_bounds['max'] ) ? $pivot_bounds['max']['value'] : null;
	$pivot_low  = ( null !== $pivot_low && $pivot_low > $pivot_min ) ? min( $pivot_low, $pivot_max ) : null;
	$pivot_high = ( null !== $pivot_high && $pivot_high < $pivot_max ) ? max( $pivot_high, $pivot_min ) : null;

	if ( null !== $pivot_low && null !== $pivot_high ) {
		/* translators: 1 : borne basse, 2 : borne haute, avec leur unité. */
		$pivot_output = sprintf( __( 'entre %1$s et %2$s', 'pivot-offres' ), $pivot_format( $pivot_low ), $pivot_format( $pivot_high ) );
	} elseif ( null !== $pivot_low ) {
		/* translators: %s : valeur, avec son unité. */
		$pivot_output = sprintf( __( 'au moins %s', 'pivot-offres' ), $pivot_format( $pivot_low ) );
	} elseif ( null !== $pivot_high ) {
		/* translators: %s : valeur, avec son unité. */
		$pivot_output = sprintf( __( 'au plus %s', 'pivot-offres' ), $pivot_format( $pivot_high ) );
	} else {
		$pivot_output = __( 'Indifférent', 'pivot-offres' );
	}
}
?>
<div class="pivot-field pivot-field-range<?php echo $pivot_slider ? ' is-slider' : ''; ?>"
	data-range="<?php echo esc_attr( $pivot_key ); ?>"
	data-operator="<?php echo esc_attr( $pivot_operator ); ?>"
	data-unit="<?php echo esc_attr( $pivot_unit ); ?>"
	<?php
	if ( $pivot_slider ) {
		printf(
			'data-any="%s" data-format-min="%s" data-format-max="%s" data-format-both="%s"',
			esc_attr__( 'Indifférent', 'pivot-offres' ),
			/* translators: %s : valeur, avec son unité. */
			esc_attr__( 'au moins %s', 'pivot-offres' ),
			/* translators: %s : valeur, avec son unité. */
			esc_attr__( 'au plus %s', 'pivot-offres' ),
			/* translators: 1 : borne basse, 2 : borne haute, avec leur unité. */
			esc_attr__( 'entre %1$s et %2$s', 'pivot-offres' )
		);
	}
	?>>
	<fieldset>
		<legend id="<?php echo esc_attr( $pivot_legend ); ?>"><?php echo esc_html( pivot_get( $filter, 'label', $pivot_key ) ); ?></legend>

		<?php if ( $pivot_slider ) : ?>
			<output class="pivot-range-output" aria-live="polite"><?php echo esc_html( $pivot_output ); ?></output>

			<?php
			foreach ( $pivot_bounds as $pivot_bound => $pivot_data ) :
				$pivot_value = null === $pivot_data['value']
					? $pivot_data['neutral']
					: max( $pivot_min, min( $pivot_max, $pivot_data['value'] ) );
				?>
				<span class="screen-reader-text" id="<?php echo esc_attr( $pivot_data['word'] ); ?>"><?php echo esc_html( $pivot_words[ $pivot_bound ] ); ?></span>
				<input type="range"
					id="<?php echo esc_attr( $pivot_data['id'] ); ?>"
					name="<?php echo esc_attr( $pivot_data['param'] ); ?>"
					min="<?php echo esc_attr( $pivot_min ); ?>"
					max="<?php echo esc_attr( $pivot_max ); ?>"
					step="<?php echo esc_attr( pivot_get( $filter, 'step', 1 ) ); ?>"
					value="<?php echo esc_attr( $pivot_value ); ?>"
					data-bound="<?php echo esc_attr( $pivot_bound ); ?>"
					data-neutral="<?php echo esc_attr( $pivot_data['neutral'] ); ?>"
					aria-valuetext="<?php echo esc_attr( $pivot_format( $pivot_value ) ); ?>"
					aria-labelledby="<?php echo esc_attr( $pivot_legend . ' ' . $pivot_data['word'] ); ?>" />
			<?php endforeach; ?>

		<?php else : ?>
			<div class="pivot-range">
				<?php
				foreach ( $pivot_bounds as $pivot_bound => $pivot_data ) :
					// Nom lu par un lecteur d'écran : « Prix, au plus, € ».
					$pivot_labels = $pivot_legend;
					$pivot_hint   = 'eq' === $pivot_bound
						? $pivot_number( $pivot_min ) . ' – ' . $pivot_number( $pivot_max )
						: $pivot_number( 'max' === $pivot_bound ? $pivot_max : $pivot_min );

					if ( '' !== $pivot_words[ $pivot_bound ] ) :
						$pivot_labels .= ' ' . $pivot_data['word'];
						?>
						<span class="pivot-range-word" id="<?php echo esc_attr( $pivot_data['word'] ); ?>"><?php echo esc_html( $pivot_words[ $pivot_bound ] ); ?></span>
					<?php endif; ?>

					<input type="text" inputmode="decimal" autocomplete="off"
						id="<?php echo esc_attr( $pivot_data['id'] ); ?>"
						name="<?php echo esc_attr( $pivot_data['param'] ); ?>"
						value="<?php echo esc_attr( $pivot_data['raw'] ); ?>"
						placeholder="<?php echo esc_attr( $pivot_hint ); ?>"
						data-bound="<?php echo esc_attr( $pivot_bound ); ?>"
						aria-labelledby="<?php echo esc_attr( $pivot_labels . ( '' !== $pivot_unit ? ' ' . $pivot_data['unit'] : '' ) ); ?>"
						aria-describedby="<?php echo esc_attr( $pivot_error ); ?>"
						<?php echo $pivot_data['invalid'] ? 'aria-invalid="true"' : ''; ?> />

					<?php if ( '' !== $pivot_unit ) : ?>
						<span class="pivot-range-unit" id="<?php echo esc_attr( $pivot_data['unit'] ); ?>"><?php echo esc_html( $pivot_unit ); ?></span>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>

			<p class="pivot-field-error" id="<?php echo esc_attr( $pivot_error ); ?>" role="alert"<?php echo $pivot_invalid ? '' : ' hidden'; ?>>
				<?php esc_html_e( 'Saisissez un nombre, par exemple 12 ou 9,50.', 'pivot-offres' ); ?>
			</p>
		<?php endif; ?>
	</fieldset>
</div>
