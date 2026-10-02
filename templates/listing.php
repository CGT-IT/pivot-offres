<?php
/**
 * Gabarit d'une page de listing.
 *
 * La première page est rendue côté serveur à partir de l'index de la langue
 * courante : moteurs de recherche et visiteurs sans JavaScript voient des
 * offres et des liens. Le script prend ensuite la main pour la recherche, les
 * filtres, la carte et la pagination, sans jamais rappeler PIVOT.
 *
 * Pour personnaliser, copiez ce fichier dans votre thème sous
 * pivot-offres/listing.php.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

$pivot_templates = Pivot_Templates::instance();
$pivot_context   = $pivot_templates->context();
$pivot_listing   = pivot_get( $pivot_context, 'listing', array() );
$pivot_lang      = Pivot_I18n::current();
$pivot_page      = max( 1, (int) pivot_get( $pivot_context, 'page', 1 ) );

$pivot_index = Pivot_Index_Builder::ensure( $pivot_listing, $pivot_lang );

// null ne veut pas dire « aucune offre » mais « index pas encore construit » :
// la reconstruction vient d'être programmée. Les deux cas méritent des mots
// différents, et celui-ci ne doit surtout pas être mis en cache par un proxy.
$pivot_pending = ( null === $pivot_index );

if ( $pivot_pending ) {
	nocache_headers();
	header( 'Retry-After: 30' );
}

$pivot_items   = (array) pivot_get( $pivot_index, 'items', array() );
$pivot_filters = (array) pivot_get( $pivot_index, 'filters', array() );
$pivot_total   = count( $pivot_items );
$pivot_per     = max( 1, (int) pivot_get( $pivot_listing, 'per_page', 12 ) );
$pivot_columns = max( 1, min( 6, (int) pivot_get( $pivot_listing, 'columns', 4 ) ) );
$pivot_pages   = $pivot_total ? (int) ceil( $pivot_total / $pivot_per ) : 1;
$pivot_slice   = array_slice( $pivot_items, ( $pivot_page - 1 ) * $pivot_per, $pivot_per );
$pivot_map     = ! empty( $pivot_listing['show_map'] ) && 'none' !== pivot_settings( 'map_provider', 'leaflet' );
$pivot_search  = ! empty( $pivot_listing['search_enabled'] );
$pivot_base    = Pivot_Listings::url( $pivot_listing, $pivot_lang );
$pivot_intro   = Pivot_Listings::intro_html( $pivot_listing, $pivot_lang );
$pivot_image   = (string) pivot_get( $pivot_listing, 'image', '' );

get_header();
?>

<div class="pivot-listing" id="pivot-listing"
	data-listing="<?php echo esc_attr( pivot_get( $pivot_listing, 'id', '' ) ); ?>"
	data-lang="<?php echo esc_attr( $pivot_lang ); ?>">

	<?php if ( $pivot_image ) : ?>
		<figure class="pivot-listing-banner">
			<?php
			// Image décorative : le titre qui suit dit tout. Premier élément
			// visible de la page, elle est chargée en priorité.
			$pivot_image_id = (int) pivot_get( $pivot_listing, 'image_id', 0 );

			if ( $pivot_image_id && wp_attachment_is_image( $pivot_image_id ) ) {
				echo wp_get_attachment_image(
					$pivot_image_id,
					'full',
					false,
					array(
						'alt'           => '',
						'sizes'         => '100vw',
						'loading'       => false,
						'fetchpriority' => 'high',
					)
				);
			} else {
				printf( '<img src="%s" alt="" fetchpriority="high" decoding="async" />', esc_url( $pivot_image ) );
			}
			?>
		</figure>
	<?php endif; ?>

	<header class="pivot-listing-header">
		<h1 class="pivot-listing-title"><?php echo esc_html( Pivot_Listings::title( $pivot_listing, $pivot_lang ) ); ?></h1>

		<?php if ( $pivot_intro ) : ?>
			<div class="pivot-listing-intro"><?php echo $pivot_intro; // phpcs:ignore WordPress.Security.EscapeOutput -- filtré par Pivot_Listings::intro_html(). ?></div>
		<?php endif; ?>
	</header>

	<?php if ( $pivot_search || $pivot_filters ) : ?>
		<form class="pivot-criteria" id="pivot-criteria" method="get" action="<?php echo esc_url( $pivot_base ); ?>">
			<h2 class="pivot-criteria-title screen-reader-text"><?php esc_html_e( 'Critères de recherche', 'pivot-offres' ); ?></h2>

			<?php if ( $pivot_search ) : ?>
				<p class="pivot-field pivot-field-search">
					<label for="pivot-q"><?php echo esc_html( Pivot_Listings::search_label( $pivot_listing, $pivot_lang ) ); ?></label>
					<input type="search" id="pivot-q" name="q" autocomplete="off"
						placeholder="<?php esc_attr_e( 'Nom, localité, mot-clé…', 'pivot-offres' ); ?>"
						value="<?php echo esc_attr( isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification ?>" />
				</p>
			<?php endif; ?>

			<div class="pivot-filters-zone" id="pivot-filters">
				<?php
				// Un critère sans clé, une liste sans valeur, un nombre ou une date
				// qu'aucune offre ne renseigne n'ont rien à proposer. Écartés avant
				// de former les groupes, ils ne laissent pas un intertitre vide.
				$pivot_shown = array();

				foreach ( $pivot_filters as $pivot_filter ) {
					$pivot_type = pivot_get( $pivot_filter, 'type', 'select' );

					if ( ! pivot_get( $pivot_filter, 'key', '' )
						|| ( in_array( $pivot_type, array( 'select', 'multiselect' ), true ) && ! pivot_get( $pivot_filter, 'options' ) )
						|| ( in_array( $pivot_type, array( 'range', 'date' ), true ) && '' === (string) pivot_get( $pivot_filter, 'min', '' ) ) ) {
						continue;
					}

					$pivot_shown[] = $pivot_filter;
				}

				// Les critères d'un même groupe s'affichent ensemble, sous son nom.
				foreach ( Pivot_Templates::filter_groups( $pivot_shown, $pivot_listing, $pivot_lang ) as $pivot_group ) :
					if ( $pivot_group['label'] ) :
						?>
						<fieldset class="pivot-filter-group" data-group="<?php echo esc_attr( $pivot_group['key'] ); ?>">
							<legend class="pivot-filter-group-title"><?php echo esc_html( $pivot_group['label'] ); ?></legend>
						<?php
					endif;

					foreach ( $pivot_group['filters'] as $pivot_filter ) :
						$pivot_key     = pivot_get( $pivot_filter, 'key', '' );
						$pivot_type    = pivot_get( $pivot_filter, 'type', 'select' );
						$pivot_options = (array) pivot_get( $pivot_filter, 'options', array() );
						$pivot_current = isset( $_GET[ $pivot_key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $pivot_key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

						// Un nombre se compare au lieu de se choisir : son contrôle a
						// son propre gabarit, surchargeable par le thème.
						if ( 'range' === $pivot_type ) {
							$pivot_templates->part( 'filter-range', array( 'filter' => $pivot_filter ) );
							continue;
						}

						// Une date se choisit dans un calendrier, pour une période
						// ou une borne.
						if ( 'date' === $pivot_type ) {
							$pivot_templates->part( 'filter-date', array( 'filter' => $pivot_filter ) );
							continue;
						}
						?>
						<div class="pivot-field pivot-field-<?php echo esc_attr( $pivot_type ); ?>" data-filter="<?php echo esc_attr( $pivot_key ); ?>">
							<?php if ( 'multiselect' === $pivot_type ) : ?>
								<fieldset>
									<legend><?php echo esc_html( pivot_get( $pivot_filter, 'label', $pivot_key ) ); ?></legend>
									<?php foreach ( $pivot_options as $pivot_option ) : ?>
										<label class="pivot-check">
											<input type="checkbox" name="<?php echo esc_attr( $pivot_key ); ?>[]"
												value="<?php echo esc_attr( pivot_get( $pivot_option, 'v', '' ) ); ?>" />
											<span><?php echo esc_html( pivot_get( $pivot_option, 'l', '' ) ); ?></span>
											<?php if ( pivot_get( $pivot_option, 'n' ) ) : ?>
												<em class="pivot-count"><?php echo esc_html( (int) $pivot_option['n'] ); ?></em>
											<?php endif; ?>
										</label>
									<?php endforeach; ?>
								</fieldset>

							<?php elseif ( 'text' === $pivot_type ) : ?>
								<label for="pivot-f-<?php echo esc_attr( $pivot_key ); ?>"><?php echo esc_html( pivot_get( $pivot_filter, 'label', $pivot_key ) ); ?></label>
								<input type="text" id="pivot-f-<?php echo esc_attr( $pivot_key ); ?>"
									name="<?php echo esc_attr( $pivot_key ); ?>"
									value="<?php echo esc_attr( $pivot_current ); ?>"
									placeholder="<?php echo esc_attr( pivot_get( $pivot_filter, 'placeholder', '' ) ); ?>" />

							<?php elseif ( 'toggle' === $pivot_type ) : ?>
								<label class="pivot-check">
									<input type="checkbox" name="<?php echo esc_attr( $pivot_key ); ?>" value="1" <?php checked( $pivot_current, '1' ); ?> />
									<span><?php echo esc_html( pivot_get( $pivot_filter, 'label', $pivot_key ) ); ?></span>
								</label>

							<?php else : ?>
								<label for="pivot-f-<?php echo esc_attr( $pivot_key ); ?>"><?php echo esc_html( pivot_get( $pivot_filter, 'label', $pivot_key ) ); ?></label>
								<select id="pivot-f-<?php echo esc_attr( $pivot_key ); ?>" name="<?php echo esc_attr( $pivot_key ); ?>">
									<option value=""><?php esc_html_e( 'Toutes', 'pivot-offres' ); ?></option>
									<?php foreach ( $pivot_options as $pivot_option ) : ?>
										<option value="<?php echo esc_attr( pivot_get( $pivot_option, 'v', '' ) ); ?>" <?php selected( $pivot_current, pivot_get( $pivot_option, 'v', '' ) ); ?>>
											<?php
											echo esc_html( pivot_get( $pivot_option, 'l', '' ) );
											if ( pivot_get( $pivot_option, 'n' ) ) {
												echo ' (' . esc_html( (int) $pivot_option['n'] ) . ')';
											}
											?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
						</div>
						<?php
					endforeach;

					if ( $pivot_group['label'] ) :
						?>
						</fieldset>
						<?php
					endif;
				endforeach;
				?>
			</div>

			<p class="pivot-criteria-actions">
				<button type="submit" class="pivot-button"><?php esc_html_e( 'Filtrer', 'pivot-offres' ); ?></button>
				<button type="reset" class="pivot-button pivot-button-ghost" id="pivot-reset"><?php esc_html_e( 'Effacer les filtres', 'pivot-offres' ); ?></button>
			</p>
		</form>
	<?php endif; ?>

	<?php if ( $pivot_map ) : ?>
		<section class="pivot-map-zone" aria-label="<?php esc_attr_e( 'Carte des offres', 'pivot-offres' ); ?>">
			<div id="pivot-map" class="pivot-map"
				data-zoom="<?php echo esc_attr( (int) pivot_get( $pivot_listing, 'map_zoom', 9 ) ); ?>"
				data-center="<?php echo esc_attr( pivot_get( $pivot_listing, 'map_center', '' ) ); ?>"></div>
		</section>
	<?php endif; ?>

	<p class="pivot-results-count" id="pivot-count" role="status">
		<?php
		printf(
			/* translators: %d : nombre d'offres. */
			esc_html( _n( '%d offre', '%d offres', $pivot_total, 'pivot-offres' ) ),
			(int) $pivot_total
		);
		?>
	</p>

	<?php // Colonnes par palier d'écran : voir pivot-cols-N dans assets/css/pivot.css. ?>
	<div class="pivot-grid pivot-cols-<?php echo (int) $pivot_columns; ?>" id="pivot-grid">
		<?php
		if ( $pivot_slice ) {
			foreach ( $pivot_slice as $pivot_item ) {
				$pivot_templates->part( 'card', array( 'item' => $pivot_item ) );
			}
		} elseif ( $pivot_pending ) {
			echo '<p class="pivot-pending">' . esc_html__( 'Les offres de cette page sont en cours de préparation. Rechargez la page dans un instant.', 'pivot-offres' ) . '</p>';
		} else {
			echo '<p class="pivot-empty-results">' . esc_html__( 'Aucune offre à afficher pour le moment.', 'pivot-offres' ) . '</p>';
		}
		?>
	</div>

	<?php if ( $pivot_pages > 1 ) : ?>
		<nav class="pivot-pagination" id="pivot-pagination" aria-label="<?php esc_attr_e( 'Pagination des offres', 'pivot-offres' ); ?>">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => trailingslashit( $pivot_base ) . 'page/%#%/',
						'format'    => '',
						'current'   => $pivot_page,
						'total'     => $pivot_pages,
						'prev_text' => esc_html__( 'Précédent', 'pivot-offres' ),
						'next_text' => esc_html__( 'Suivant', 'pivot-offres' ),
					)
				)
			);
			?>
		</nav>
	<?php endif; ?>

</div>

<?php
get_footer();
