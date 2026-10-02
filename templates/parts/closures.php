<?php
/**
 * Dates de fermeture à venir d'une fiche, mois par mois.
 *
 * Une ligne par période ; deux zones de fermeture fermées le même jour font
 * une seule ligne, qui les nomme toutes deux. Une ligne passée est masquée
 * par assets/js/pivot-closures.js, celle du jour est signalée (is-today).
 *
 * Le bloc porte l'ancre visée par le « + » des vignettes
 * (Pivot_Closures::ANCHOR).
 *
 * Pour personnaliser, copiez ce fichier dans votre thème sous
 * pivot-offres/parts/closures.php.
 *
 * @package Pivot_Offres
 *
 * @var array  $offer Offre normalisée.
 * @var string $lang  Langue.
 */

defined( 'ABSPATH' ) || exit;

$pivot_rows = Pivot_Closures::schedule( $offer, $lang );

if ( ! $pivot_rows ) {
	return;
}

$pivot_periods = Pivot_Closures::periods( $offer );
$pivot_status  = Pivot_Closures::status( $pivot_periods );
$pivot_kinds   = Pivot_Closures::kinds( $pivot_periods );
$pivot_today   = Pivot_Closures::today();

Pivot_Closures::enqueue();
?>
<section id="<?php echo esc_attr( Pivot_Closures::ANCHOR ); ?>" class="pivot-closures-schedule"<?php echo Pivot_Closures::attributes( $pivot_periods ) . Pivot_Closures::when( 'upcoming', $pivot_status ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé. ?>>
	<h2><?php esc_html_e( 'Dates de fermeture', 'pivot-offres' ); ?></h2>

	<?php if ( 1 === count( $pivot_kinds ) ) : ?>
		<p class="pivot-closures-reason">
			<?php
			/* translators: %s : type de fermeture, « Période de chasse ». */
			printf( esc_html__( 'Motif : %s', 'pivot-offres' ), esc_html( Pivot_Closures::kind_label( $pivot_kinds[0], $lang ) ) );
			?>
		</p>
	<?php endif; ?>

	<?php foreach ( Pivot_Closures::by_month( $pivot_rows ) as $pivot_month ) : ?>
		<div class="pivot-closures-month" data-pivot-closure-group>
			<h3><?php echo esc_html( Pivot_Closures::ucfirst( $pivot_month['label'] ) ); ?></h3>
			<ul>
				<?php foreach ( $pivot_month['rows'] as $pivot_row ) : ?>
					<li class="pivot-closures-row<?php echo $pivot_row['start'] <= $pivot_today && $pivot_today <= $pivot_row['end'] ? ' is-today' : ''; ?>" data-pivot-closure-start="<?php echo (int) $pivot_row['start']; ?>" data-pivot-closure-end="<?php echo (int) $pivot_row['end']; ?>">
						<span class="pivot-closures-date"><?php echo esc_html( Pivot_Closures::ucfirst( Pivot_Closures::format_period( $pivot_row['start'], $pivot_row['end'], Pivot_Closures::day_format() ) ) ); ?></span>
						<span class="pivot-closures-today-tag"><?php esc_html_e( 'Aujourd\'hui', 'pivot-offres' ); ?></span>
						<?php if ( count( $pivot_kinds ) > 1 ) : ?>
							<span class="pivot-closures-kind"><?php echo esc_html( implode( ', ', $pivot_row['kinds'] ) ); ?></span>
						<?php endif; ?>
						<span class="pivot-closures-zones"><?php echo esc_html( implode( ' · ', $pivot_row['zones'] ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endforeach; ?>
</section>
