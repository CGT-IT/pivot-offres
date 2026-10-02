<?php
/**
 * Alerte de fermeture d'une fiche : fermée aujourd'hui, ou prochaine fermeture.
 *
 * Les dates viennent des zones de fermeture liées à l'offre
 * (Pivot_Closures). L'état est celui du jour où le serveur rend la page ;
 * assets/js/pivot-closures.js le recalcule à la date du visiteur, pour une
 * fiche servie par un cache de page.
 *
 * Pour personnaliser, copiez ce fichier dans votre thème sous
 * pivot-offres/parts/closures-alert.php.
 *
 * @package Pivot_Offres
 *
 * @var array  $offer Offre normalisée.
 * @var string $lang  Langue.
 */

defined( 'ABSPATH' ) || exit;

$pivot_periods = Pivot_Closures::periods( $offer );

if ( ! $pivot_periods ) {
	return;
}

$pivot_status = Pivot_Closures::status( $pivot_periods );
$pivot_kinds  = Pivot_Closures::kinds( $pivot_periods );
$pivot_type   = (int) pivot_get( $offer, 'type', 0 );

Pivot_Closures::enqueue();
?>
<div class="pivot-closure-alerts"<?php echo Pivot_Closures::attributes( $pivot_periods ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé. ?>>

	<div class="pivot-closure-alert is-closed" role="alert"<?php echo Pivot_Closures::when( 'today', $pivot_status ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé. ?>>
		<p class="pivot-closure-alert-title">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput -- phrase échappée, date échappée par date_tag().
			printf( esc_html( Pivot_Closures::closed_text( $pivot_type ) ), Pivot_Closures::date_tag( 'today', $pivot_status ) );
			?>
		</p>
		<p class="pivot-closure-alert-kinds">
			<?php foreach ( $pivot_kinds as $pivot_kind ) : ?>
				<span class="pivot-closure-kind"<?php echo Pivot_Closures::when( 'today', $pivot_status, $pivot_kind ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé. ?>><?php echo esc_html( Pivot_Closures::kind_label( $pivot_kind, $lang ) ); ?></span>
			<?php endforeach; ?>
		</p>
	</div>

	<div class="pivot-closure-alert is-upcoming"<?php echo Pivot_Closures::when( 'open next', $pivot_status ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé. ?>>
		<p class="pivot-closure-alert-title">
			<?php foreach ( $pivot_kinds as $pivot_kind ) : ?>
				<span class="pivot-closure-impact"<?php echo Pivot_Closures::when( 'upcoming', $pivot_status, $pivot_kind ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé. ?>><?php echo esc_html( Pivot_Closures::impact_label( $pivot_kind ) ); ?></span>
			<?php endforeach; ?>
		</p>
		<p>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput -- phrase échappée, date échappée par date_tag().
			printf( esc_html( Pivot_Closures::open_text( $pivot_type ) ), Pivot_Closures::date_tag( 'next', $pivot_status ) );
			?>
		</p>
	</div>

	<p class="pivot-closure-alert-more"<?php echo Pivot_Closures::when( 'upcoming', $pivot_status ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé. ?>>
		<a href="#<?php echo esc_attr( Pivot_Closures::ANCHOR ); ?>"><?php esc_html_e( 'Voir les dates de fermeture', 'pivot-offres' ); ?></a>
	</p>

</div>
