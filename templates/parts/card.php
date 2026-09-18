<?php
/**
 * Vignette d'une offre, rendue par le serveur.
 *
 * Le rendu JavaScript de pivot-listing.js produit la même structure : styles
 * et micro-données restent cohérents dans les deux cas.
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
$pivot_schema = Pivot_Seo::schema_type( (int) pivot_get( $item, 't', 0 ) );
?>
<article class="pivot-card" itemscope itemtype="https://schema.org/<?php echo esc_attr( $pivot_schema ); ?>">

	<?php if ( $pivot_image ) : ?>
		<div class="pivot-card-media">
			<a href="<?php echo esc_url( $pivot_url ); ?>" tabindex="-1" aria-hidden="true">
				<img src="<?php echo esc_url( $pivot_image ); ?>"
					alt="<?php echo esc_attr( $pivot_name ); ?>"
					loading="lazy" decoding="async" itemprop="image" />
			</a>
		</div>
	<?php endif; ?>

	<div class="pivot-card-body">
		<?php if ( $pivot_type ) : ?>
			<p class="pivot-card-type"><?php echo esc_html( $pivot_type ); ?></p>
		<?php endif; ?>

		<h2 class="pivot-card-title" itemprop="name">
			<a href="<?php echo esc_url( $pivot_url ); ?>" itemprop="url"><?php echo esc_html( $pivot_name ); ?></a>
		</h2>

		<?php if ( $pivot_zip || $pivot_loc ) : ?>
			<p class="pivot-card-place" itemprop="address" itemscope itemtype="https://schema.org/PostalAddress">
				<?php if ( $pivot_zip ) : ?>
					<span itemprop="postalCode"><?php echo esc_html( $pivot_zip ); ?></span>
				<?php endif; ?>
				<?php if ( $pivot_loc ) : ?>
					<span itemprop="addressLocality"><?php echo esc_html( $pivot_loc ); ?></span>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( $pivot_desc ) : ?>
			<p class="pivot-card-excerpt" itemprop="description"><?php echo esc_html( $pivot_desc ); ?></p>
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
