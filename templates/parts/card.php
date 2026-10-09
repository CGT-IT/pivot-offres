<?php
/**
 * Vignette d'une offre, rendue par le serveur.
 *
 * Le rendu JavaScript de pivot-listing.js produit la même structure : les
 * styles restent cohérents dans les deux cas. Pas de micro-données : la liste
 * est décrite en JSON-LD dans l'en-tête (Pivot_Seo), et une vignette
 * d'événement balisée Event sans ses dates était signalée comme invalide.
 *
 * @package Pivot_Offres
 *
 * @var array $item Entrée d'index, déjà traduite dans la langue de la page.
 */

defined( 'ABSPATH' ) || exit;

$pivot_code = pivot_get( $item, 'c', '' );

if ( ! $pivot_code ) {
	return;
}

$pivot_url    = pivot_get( $item, 'u', '' );
$pivot_name   = pivot_get( $item, 'n', '' );
$pivot_image  = pivot_get( $item, 'i', '' );
$pivot_type   = pivot_get( $item, 'tl', '' );
$pivot_zip    = pivot_get( $item, 'z', '' );
$pivot_loc    = pivot_get( $item, 'l', '' );
$pivot_desc   = pivot_get( $item, 'd', '' );

// Fermé aujourd'hui, fermetures à venir : par les zones de fermeture liées.
$pivot_closures = Pivot_Closures::badges(
	pivot_get( $item, 'cl', array() ),
	array(
		'url'  => $pivot_url,
		'type' => (int) pivot_get( $item, 't', 0 ),
	)
);
?>
<article class="pivot-card">

	<?php if ( $pivot_image ) : ?>
		<div class="pivot-card-media">
			<a href="<?php echo esc_url( $pivot_url ); ?>" tabindex="-1" aria-hidden="true">
				<?php // Les proportions du cadre (.pivot-card-media, 4/3) : la place est réservée avant l'image. ?>
				<img src="<?php echo esc_url( $pivot_image ); ?>"
					alt="<?php echo esc_attr( $pivot_name ); ?>"
					width="400" height="300"
					loading="lazy" decoding="async" />
			</a>
			<?php echo $pivot_closures; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé par Pivot_Closures::badges(). ?>
		</div>
	<?php endif; ?>

	<div class="pivot-card-body">
		<?php if ( ! $pivot_image ) : ?>
			<?php echo $pivot_closures; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé par Pivot_Closures::badges(). ?>
		<?php endif; ?>

		<?php if ( $pivot_type ) : ?>
			<p class="pivot-card-type"><?php echo esc_html( $pivot_type ); ?></p>
		<?php endif; ?>

		<h2 class="pivot-card-title">
			<a href="<?php echo esc_url( $pivot_url ); ?>"><?php echo esc_html( $pivot_name ); ?></a>
		</h2>

		<?php if ( $pivot_zip || $pivot_loc ) : ?>
			<p class="pivot-card-place"><?php echo esc_html( trim( $pivot_zip . ' ' . $pivot_loc ) ); ?></p>
		<?php endif; ?>

		<?php if ( $pivot_desc ) : ?>
			<p class="pivot-card-excerpt"><?php echo esc_html( $pivot_desc ); ?></p>
		<?php endif; ?>

		<?php
		/*
		 * Les champs propres à un type — dates d'un événement, capacité d'un
		 * hébergement — n'apparaissent pas ici : le gabarit commun ne sait pas
		 * où les mettre, et une liste de définitions ajoutée en bas de vignette
		 * n'est pas une mise en page.
		 *
		 * Ils voyagent dans l'index, sous $item['x'], et c'est un gabarit de
		 * famille qui les place : voir templates/examples/card-evenement.php.
		 */
		?>

		<p class="pivot-card-action">
			<a class="pivot-button pivot-button-ghost" href="<?php echo esc_url( $pivot_url ); ?>">
				<?php esc_html_e( 'Voir la fiche', 'pivot-offres' ); ?>
				<span class="screen-reader-text"><?php echo esc_html( ' — ' . $pivot_name ); ?></span>
			</a>
		</p>
	</div>

</article>
