<?php
/**
 * EXEMPLE — vignette d'un événement.
 *
 * Ce fichier ne fait rien tel quel : il est rangé dans templates/examples/,
 * que le plugin ne consulte jamais. Copiez-le dans votre thème sous
 * pivot-offres/parts/card-evenement.php pour qu'il s'applique à tous les types
 * de la famille « événement », ou sous parts/card-type-10.php pour un seul type.
 *
 * Les champs propres au type — ici les dates et le lieu — n'arrivent dans la
 * vignette que si vous les déclarez. La pagination et la recherche se faisant
 * dans le navigateur, une vignette ne peut pas interroger PIVOT au moment de
 * s'afficher : ce qu'elle montre doit voyager avec elle, dans l'index.
 *
 * À placer dans functions.php :
 *
 *     add_filter( 'pivot_card_fields', function ( $fields, $type_id ) {
 *         if ( 'evenement' === Pivot_Types::family( $type_id ) ) {
 *             $fields[] = 'urn:obj:date';
 *             $fields[] = 'urn:fld:lieuevt';
 *         }
 *         return $fields;
 *     }, 10, 2 );
 *
 * Les clés disponibles dans $item['x'] sont l'urn sans son préfixe :
 * urn:obj:date devient « date », urn:fld:lieuevt devient « lieuevt ».
 *
 * @package Pivot_Offres
 *
 * @var array $item Entrée d'index, déjà traduite dans la langue de la page.
 */

defined( 'ABSPATH' ) || exit;

$code = pivot_get( $item, 'c', '' );

if ( ! $code ) {
	return;
}

$url    = pivot_get( $item, 'u', '' );
$name   = pivot_get( $item, 'n', '' );
$image  = pivot_get( $item, 'i', '' );
$place  = pivot_get( $item, 'x.lieuevt.value', pivot_get( $item, 'l', '' ) );
$dates  = (array) pivot_get( $item, 'x.date.values', array() );
$schema = Pivot_Seo::schema_type( (int) pivot_get( $item, 't', 0 ) );
?>
<article class="pivot-card pivot-card-evenement" itemscope itemtype="https://schema.org/<?php echo esc_attr( $schema ); ?>">

	<?php if ( $image ) : ?>
		<div class="pivot-card-media">
			<a href="<?php echo esc_url( $url ); ?>" tabindex="-1" aria-hidden="true">
				<img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $name ); ?>"
					loading="lazy" decoding="async" itemprop="image" />
			</a>
		</div>
	<?php endif; ?>

	<div class="pivot-card-body">

		<?php if ( $dates ) : ?>
			<p class="pivot-card-dates">
				<?php
				// Un événement peut avoir plusieurs périodes : on met la
				// première en avant et on signale les autres.
				echo esc_html( $dates[0] );

				if ( count( $dates ) > 1 ) {
					printf(
						' <span class="pivot-card-more-dates">%s</span>',
						esc_html(
							sprintf(
								/* translators: %d : nombre d'autres périodes. */
								_n( '+ %d autre date', '+ %d autres dates', count( $dates ) - 1, 'pivot-offres' ),
								count( $dates ) - 1
							)
						)
					);
				}
				?>
			</p>
		<?php endif; ?>

		<h2 class="pivot-card-title" itemprop="name">
			<a href="<?php echo esc_url( $url ); ?>" itemprop="url"><?php echo esc_html( $name ); ?></a>
		</h2>

		<?php if ( $place ) : ?>
			<p class="pivot-card-place" itemprop="location"><?php echo esc_html( $place ); ?></p>
		<?php endif; ?>

		<?php if ( pivot_get( $item, 'd' ) ) : ?>
			<p class="pivot-card-excerpt" itemprop="description"><?php echo esc_html( $item['d'] ); ?></p>
		<?php endif; ?>

		<p class="pivot-card-action">
			<a class="pivot-button pivot-button-ghost" href="<?php echo esc_url( $url ); ?>">
				<?php esc_html_e( 'Voir la fiche', 'pivot-offres' ); ?>
				<span class="screen-reader-text"><?php echo esc_html( ' — ' . $name ); ?></span>
			</a>
		</p>

	</div>

</article>
