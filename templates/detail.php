<?php
/**
 * Gabarit d'une fiche détail.
 *
 * Les libellés et les valeurs viennent des traductions renvoyées par PIVOT.
 * Toutes les données sont lues avec pivot_get() : une offre incomplète produit
 * simplement une fiche plus courte, jamais un avertissement PHP.
 *
 * Pour personnaliser, copiez ce fichier dans votre thème sous
 * pivot-offres/detail.php.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

$pivot_templates = Pivot_Templates::instance();
$pivot_offer     = $pivot_templates->current_offer();

if ( ! $pivot_offer ) {
	get_header();
	echo '<div class="pivot-detail"><p>' . esc_html__( 'Cette offre n\'est plus disponible.', 'pivot-offres' ) . '</p></div>';
	get_footer();
	return;
}

$pivot_lang    = Pivot_I18n::current();
$pivot_code    = pivot_get( $pivot_offer, 'code', '' );
$pivot_name    = Pivot_Templates::offer_name( $pivot_offer, $pivot_lang );
$pivot_type    = $pivot_templates->offer_type_label( $pivot_offer, $pivot_lang );
$pivot_desc    = Pivot_Templates::offer_description( $pivot_offer, 0, $pivot_lang );
$pivot_address = $pivot_templates->offer_address_line( $pivot_offer, $pivot_lang );
$pivot_local   = $pivot_templates->offer_locality( $pivot_offer, $pivot_lang );
$pivot_gallery = $pivot_templates->offer_gallery( $pivot_offer );
$pivot_groups  = $pivot_templates->grouped_specs( $pivot_offer, $pivot_lang );
$pivot_origin  = $pivot_templates->origin_listing();
$pivot_lat     = pivot_get( $pivot_offer, 'address.lat' );
$pivot_lng     = pivot_get( $pivot_offer, 'address.lng' );
$pivot_schema  = Pivot_Seo::schema_type( (int) pivot_get( $pivot_offer, 'type', 0 ) );

$pivot_phone = $pivot_templates->first_spec_value( $pivot_offer, array( 'urn:fld:phone1', 'urn:fld:phone2', 'urn:fld:gsm' ) );
$pivot_mail  = $pivot_templates->first_spec_value( $pivot_offer, array( 'urn:fld:mail1', 'urn:fld:mail2', 'urn:fld:email' ) );
$pivot_web   = $pivot_templates->first_spec_value( $pivot_offer, array( 'urn:fld:web1', 'urn:fld:web2' ) );

get_header();
?>

<article class="pivot-detail" itemscope itemtype="https://schema.org/<?php echo esc_attr( $pivot_schema ); ?>" lang="<?php echo esc_attr( Pivot_I18n::hreflang( $pivot_lang ) ); ?>">

	<nav class="pivot-breadcrumb" aria-label="<?php esc_attr_e( 'Fil d\'Ariane', 'pivot-offres' ); ?>">
		<a href="<?php echo esc_url( Pivot_I18n::url( '', $pivot_lang ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
		<span aria-hidden="true">›</span>
		<?php if ( $pivot_origin ) : ?>
			<a href="<?php echo esc_url( Pivot_Listings::url( $pivot_origin, $pivot_lang ) ); ?>"><?php echo esc_html( Pivot_Listings::title( $pivot_origin, $pivot_lang ) ); ?></a>
			<span aria-hidden="true">›</span>
		<?php else : ?>
			<?php
			// Rempli par le navigateur quand le visiteur arrive d'une page de
			// listing : le serveur rend la même page pour tout le monde.
			?>
			<span class="pivot-breadcrumb-origin" data-pivot-origin hidden></span>
		<?php endif; ?>
		<span aria-current="page"><?php echo esc_html( $pivot_name ); ?></span>
	</nav>

	<header class="pivot-detail-header">
		<?php if ( $pivot_type ) : ?>
			<p class="pivot-detail-type"><?php echo esc_html( $pivot_type ); ?></p>
		<?php endif; ?>

		<h1 class="pivot-detail-title" itemprop="name"><?php echo esc_html( $pivot_name ); ?></h1>

		<?php if ( $pivot_address ) : ?>
			<p class="pivot-detail-address" itemprop="address" itemscope itemtype="https://schema.org/PostalAddress">
				<?php pivot_echo( $pivot_offer, 'address.street', '<span itemprop="streetAddress">', '</span> ' ); ?>
				<?php pivot_echo( $pivot_offer, 'address.zip', '<span itemprop="postalCode">', '</span> ' ); ?>
				<?php if ( $pivot_local ) : ?>
					<span itemprop="addressLocality"><?php echo esc_html( $pivot_local ); ?></span>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</header>

	<?php if ( $pivot_gallery ) : ?>
		<div class="pivot-gallery">
			<?php foreach ( $pivot_gallery as $pivot_index => $pivot_media ) : ?>
				<?php
				$pivot_media_code = pivot_get( $pivot_media, 'code' );

				if ( ! $pivot_media_code ) {
					continue;
				}

				$pivot_thumb = pivot_image_url( $pivot_media_code, 0 === $pivot_index ? 'THB_LW' : 'THB_MW' );
				$pivot_alt   = $pivot_templates->media_title( $pivot_media, $pivot_name, $pivot_lang );
				?>
				<figure class="pivot-gallery-item<?php echo 0 === $pivot_index ? ' is-primary' : ''; ?>">
					<img src="<?php echo esc_url( $pivot_thumb ); ?>"
						alt="<?php echo esc_attr( $pivot_alt ); ?>"
						loading="<?php echo 0 === $pivot_index ? 'eager' : 'lazy'; ?>"
						decoding="async"
						<?php echo 0 === $pivot_index ? 'itemprop="image"' : ''; ?> />
					<?php if ( pivot_get( $pivot_media, 'copyright' ) ) : ?>
						<figcaption class="pivot-credit">© <?php echo esc_html( $pivot_media['copyright'] ); ?></figcaption>
					<?php endif; ?>
				</figure>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="pivot-detail-layout">

		<div class="pivot-detail-main">

			<?php if ( $pivot_desc ) : ?>
				<section class="pivot-detail-description" itemprop="description">
					<?php echo wp_kses_post( wpautop( $pivot_desc ) ); ?>
				</section>
			<?php endif; ?>

			<?php foreach ( $pivot_groups as $pivot_group ) : ?>
				<?php if ( empty( $pivot_group['rows'] ) ) { continue; } ?>
				<section class="pivot-spec-group">
					<h2><?php echo esc_html( pivot_get( $pivot_group, 'label', '' ) ); ?></h2>
					<dl class="pivot-spec-list">
						<?php foreach ( $pivot_group['rows'] as $pivot_row ) : ?>
							<div class="pivot-spec">
								<dt><?php echo esc_html( pivot_get( $pivot_row, 'label', '' ) ); ?></dt>
								<dd><?php echo esc_html( pivot_get( $pivot_row, 'value', '' ) ); ?></dd>
							</div>
						<?php endforeach; ?>
					</dl>
				</section>
			<?php endforeach; ?>

		</div>

		<aside class="pivot-detail-side">

			<?php if ( $pivot_phone || $pivot_mail || $pivot_web ) : ?>
				<section class="pivot-contact">
					<h2><?php esc_html_e( 'Contact', 'pivot-offres' ); ?></h2>
					<ul>
						<?php if ( $pivot_phone ) : ?>
							<li>
								<a href="tel:<?php echo esc_attr( preg_replace( '/[^\d+]/', '', $pivot_phone ) ); ?>" itemprop="telephone">
									<?php echo esc_html( $pivot_phone ); ?>
								</a>
							</li>
						<?php endif; ?>

						<?php if ( $pivot_mail && is_email( $pivot_mail ) ) : ?>
							<li>
								<a href="mailto:<?php echo esc_attr( antispambot( $pivot_mail ) ); ?>" itemprop="email">
									<?php echo esc_html( antispambot( $pivot_mail ) ); ?>
								</a>
							</li>
						<?php endif; ?>

						<?php if ( $pivot_web ) : ?>
							<li>
								<a href="<?php echo esc_url( $pivot_web ); ?>" target="_blank" rel="noopener nofollow" itemprop="sameAs">
									<?php esc_html_e( 'Site web', 'pivot-offres' ); ?>
								</a>
							</li>
						<?php endif; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php if ( null !== $pivot_lat && null !== $pivot_lng ) : ?>
				<section class="pivot-detail-map-zone" itemprop="geo" itemscope itemtype="https://schema.org/GeoCoordinates">
					<meta itemprop="latitude" content="<?php echo esc_attr( $pivot_lat ); ?>" />
					<meta itemprop="longitude" content="<?php echo esc_attr( $pivot_lng ); ?>" />
					<h2><?php esc_html_e( 'Situation', 'pivot-offres' ); ?></h2>
					<p>
						<a class="pivot-button pivot-button-ghost"
							href="https://www.openstreetmap.org/?mlat=<?php echo esc_attr( $pivot_lat ); ?>&amp;mlon=<?php echo esc_attr( $pivot_lng ); ?>#map=16/<?php echo esc_attr( $pivot_lat ); ?>/<?php echo esc_attr( $pivot_lng ); ?>"
							target="_blank" rel="noopener">
							<?php esc_html_e( 'Voir sur la carte', 'pivot-offres' ); ?>
						</a>
					</p>
				</section>
			<?php endif; ?>

			<?php if ( $pivot_origin ) : ?>
				<p class="pivot-back">
					<a href="<?php echo esc_url( Pivot_Listings::url( $pivot_origin, $pivot_lang ) ); ?>">
						<?php esc_html_e( '← Retour aux résultats', 'pivot-offres' ); ?>
					</a>
				</p>
			<?php else : ?>
				<?php // Le libellé est déjà là : le navigateur n'a que l'adresse à poser. ?>
				<p class="pivot-back" data-pivot-origin hidden>
					<a href=""><?php esc_html_e( '← Retour aux résultats', 'pivot-offres' ); ?></a>
				</p>
			<?php endif; ?>

			<?php if ( $pivot_code ) : ?>
				<p class="pivot-reference"><?php esc_html_e( 'Référence PIVOT', 'pivot-offres' ); ?> : <code><?php echo esc_html( $pivot_code ); ?></code></p>
			<?php endif; ?>

		</aside>

	</div>

</article>

<?php
get_footer();
