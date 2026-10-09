<?php
/**
 * EXEMPLE — fiche détail d'un hébergement.
 *
 * Ce fichier ne fait rien tel quel : il est rangé dans templates/examples/,
 * que le plugin ne consulte jamais. Copiez-le dans votre thème sous
 * pivot-offres/detail-hebergement.php pour qu'il s'applique à toute la famille,
 * ou sous detail-type-1.php pour le seul type Hôtel.
 *
 * Il montre trois choses utiles :
 *
 *  - lire un champ précis plutôt que de dérouler toutes les catégories ;
 *  - mettre en avant ce qui compte pour la famille — ici la capacité et le
 *    classement — avant le reste ;
 *  - garder pivot_get() partout, puisque d'une offre à l'autre les mêmes
 *    champs sont présents ou absents.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

$templates = Pivot_Templates::instance();
$offer     = $templates->current_offer();

if ( ! $offer ) {
	get_header();
	echo '<div class="pivot-detail"><p>' . esc_html__( 'Cette offre n\'est plus disponible.', 'pivot-offres' ) . '</p></div>';
	get_footer();
	return;
}

$lang    = Pivot_I18n::current();
$name    = Pivot_Templates::offer_name( $offer, $lang );
$gallery = $templates->offer_gallery( $offer );
$groups  = $templates->grouped_specs( $offer, $lang );

// Les champs mis en avant. Adaptez ces urns à votre modèle de données : le
// catalogue est visible depuis l'écran d'édition d'un critère.
$highlights = array(
	'urn:fld:nbrech' => __( 'Chambres', 'pivot-offres' ),
	'urn:fld:capac'  => __( 'Capacité', 'pivot-offres' ),
	'urn:fld:catdec' => __( 'Classement', 'pivot-offres' ),
);

get_header();
?>

<article class="pivot-detail pivot-detail-hebergement">

	<header class="pivot-detail-header">
		<h1 class="pivot-detail-title"><?php echo esc_html( $name ); ?></h1>
		<p class="pivot-detail-address"><?php echo esc_html( $templates->offer_address_line( $offer, $lang ) ); ?></p>
	</header>

	<?php if ( $gallery ) : ?>
		<div class="pivot-gallery">
			<?php foreach ( $gallery as $index => $media ) : ?>
				<?php $media_code = pivot_get( $media, 'code' ); ?>
				<?php if ( ! $media_code ) { continue; } ?>
				<figure class="pivot-gallery-item<?php echo 0 === $index ? ' is-primary' : ''; ?>">
					<img src="<?php echo esc_url( pivot_image_url( $media_code, 0 === $index ? 'THB_LW' : 'THB_MW' ) ); ?>"
						alt="<?php echo esc_attr( $templates->media_title( $media, $name, $lang ) ); ?>"
						loading="<?php echo 0 === $index ? 'eager' : 'lazy'; ?>" decoding="async" />
				</figure>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php
	// Les points saillants, lus un par un. Pivot_Fields::find_all descend dans
	// les objets et récupère toutes les occurrences d'un champ.
	$found = array();

	foreach ( $highlights as $urn => $label ) {
		$specs = Pivot_Fields::find_all( $offer, $urn );

		if ( ! $specs ) {
			continue;
		}

		// En texte : la liste tient sur une ligne par point.
		$value = Pivot_Fields::text( Pivot_Fields::render( $specs[0], $lang ), pivot_get( $specs[0], 'type', '' ) );

		if ( '' !== $value ) {
			$found[ $label ] = $value;
		}
	}

	if ( $found ) :
		?>
		<ul class="pivot-highlights">
			<?php foreach ( $found as $label => $value ) : ?>
				<li><strong><?php echo esc_html( $label ); ?></strong> <?php echo esc_html( $value ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php $description = Pivot_Templates::offer_description( $offer, 0, $lang ); ?>

	<?php if ( $description ) : ?>
		<section class="pivot-detail-description">
			<h2 class="screen-reader-text"><?php esc_html_e( 'Description', 'pivot-offres' ); ?></h2>
			<?php echo wpautop( esc_html( $description ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- texte échappé. ?>
		</section>
	<?php endif; ?>

	<?php foreach ( $groups as $group ) : ?>
		<?php if ( empty( $group['rows'] ) ) { continue; } ?>
		<section class="pivot-spec-group">
			<h2><?php echo esc_html( pivot_get( $group, 'label', '' ) ); ?></h2>
			<dl class="pivot-spec-list">
				<?php foreach ( $group['rows'] as $row ) : ?>
					<div class="pivot-spec">
						<dt><?php echo esc_html( pivot_get( $row, 'label', '' ) ); ?></dt>
						<dd><?php echo Pivot_Fields::html( pivot_get( $row, 'value', '' ), pivot_get( $row, 'type', '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé ou filtré par Pivot_Fields::html(). ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
		</section>
	<?php endforeach; ?>

</article>

<?php
get_footer();
