<?php
/**
 * Bloc de vignettes du shortcode [pivot_offres], et des anciens
 * [pivot_shortcode…] qu'il rend.
 *
 * Chaque vignette passe par le gabarit de vignette (parts/card.php et ses
 * variantes par type), comme sur les pages de listing. Un thème qui pose ses
 * vignettes dans des colonnes Bootstrap sur ces pages fait de même ici, pour
 * que les deux aient le même visuel.
 *
 * Pour personnaliser, copiez ce fichier dans votre thème sous
 * pivot-offres/parts/inline.php.
 *
 * @package Pivot_Offres
 *
 * @var array  $items      Entrées d'index à afficher.
 * @var int    $columns    Colonnes, de 1 à 6.
 * @var string $title      Titre du bloc, vide sans titre.
 * @var string $class      Classe CSS supplémentaire, vide sans classe.
 * @var string $more_url   Lien vers la page de listing, vide sans lien.
 * @var string $more_label Libellé de ce lien.
 * @var string $lang       Langue.
 */

defined( 'ABSPATH' ) || exit;

$pivot_classes = array( 'pivot-inline', 'pivot-cols-' . (int) $columns );

if ( $class ) {
	$pivot_classes[] = $class;
}
?>
<div class="<?php echo esc_attr( implode( ' ', $pivot_classes ) ); ?>">
	<?php if ( $title ) : ?>
		<h2 class="pivot-inline-title"><?php echo esc_html( $title ); ?></h2>
	<?php endif; ?>
	<div class="pivot-grid">
		<?php foreach ( $items as $pivot_item ) : ?>
			<?php Pivot_Templates::instance()->part( 'card', array( 'item' => $pivot_item ) ); ?>
		<?php endforeach; ?>
	</div>
	<?php if ( $more_url ) : ?>
		<p class="pivot-inline-more"><a class="pivot-button" href="<?php echo esc_url( $more_url ); ?>"><?php echo esc_html( $more_label ); ?></a></p>
	<?php endif; ?>
</div>
