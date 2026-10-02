<?php
/**
 * Reprise des pages de l'ancien plugin PIVOT.
 *
 * L'ancien plugin « Pivot » rangeait ses pages de listing dans la table
 * {prefix}pivot_pages et leurs filtres dans {prefix}pivot_filter. Cette classe
 * les recrée dans le registre de ce plugin, pour que les mêmes adresses
 * répondent dès la bascule.
 *
 * Les deux plugins ne peuvent pas être actifs ensemble — ils déclarent tous
 * deux pivot_settings() — : les tables sont lues directement, sans le code de
 * l'ancien. Elles ne sont jamais modifiées, ce qui permet de relancer la
 * reprise, ou de revenir à l'ancien plugin.
 *
 * Tout se passe en base, sans le moindre appel à PIVOT : la reprise tourne
 * pendant l'activation, où une attente réseau figerait l'écran des extensions.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Legacy_Import {

	/** Option témoin : état, correspondances et bilan de la reprise. */
	const OPTION = 'pivot_legacy_import';

	/** Statut « traduction terminée » de WPML String Translation. */
	const WPML_COMPLETE = 10;

	/** Recherche par nom : la recherche libre de ce plugin la couvre déjà. */
	const NAME_URN = 'urn:fld:nomofr';

	/** Champs d'adresse de l'ancien plugin, et la source qui les remplace ici. */
	const ADDRESS_SOURCES = array(
		'urn:fld:adrcom' => 'city',
		'urn:fld:adrloc' => 'locality',
	);

	/** Dates de début et de fin d'une période d'événement. */
	const PERIOD_URNS = array( 'urn:fld:date:datedeb', 'urn:fld:date:datefin' );

	/** Comparaisons de l'ancien plugin et leur équivalent ici. */
	const OPERATORS = array(
		'greaterequal' => 'gte',
		'lesserequal'  => 'lte',
		'equal'        => 'eq',
		'between'      => 'between',
	);

	/* ------------------------------------------------------------- état */

	/**
	 * Les pages de l'ancien plugin sont-elles encore en base ?
	 *
	 * @return bool
	 */
	public static function tables_present() {
		return self::table_exists( 'pivot_pages' );
	}

	/**
	 * Une table existe-t-elle, préfixe compris ?
	 *
	 * @param string $name Nom sans préfixe.
	 * @return bool
	 */
	private static function table_exists( $name ) {
		global $wpdb;

		$table = $wpdb->prefix . $name;

		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * État enregistré de la reprise.
	 *
	 * @return array status (done|skipped), time, map (ancien id => id de page),
	 *               report, notice.
	 */
	public static function state() {
		$state = get_option( self::OPTION, array() );

		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'status' => '',
				'time'   => 0,
				'map'    => array(),
				'report' => array(),
				'notice' => 0,
			)
		);
	}

	/**
	 * Le bilan a été affiché : il ne l'est plus.
	 */
	public static function notice_seen() {
		$state           = self::state();
		$state['notice'] = 0;

		update_option( self::OPTION, $state, false );
	}

	/* ---------------------------------------------------------- reprise */

	/**
	 * Reprise automatique, à l'activation et à la première mise en route.
	 *
	 * Elle ne tourne qu'une fois : le témoin posé, la relance se fait depuis
	 * Cache et outils. Elle laisse aussi un registre déjà garni tel quel : des
	 * pages recréées à la main ne doivent pas se retrouver en double.
	 */
	public static function maybe_run() {
		if ( false !== get_option( self::OPTION, false ) || ! self::tables_present() ) {
			return;
		}

		if ( Pivot_Listings::all() ) {
			update_option(
				self::OPTION,
				array(
					'status' => 'skipped',
					'time'   => time(),
					'map'    => array(),
					'report' => array(),
					'notice' => 1,
				),
				false
			);
			return;
		}

		self::run( true );
	}

	/**
	 * Reprend les pages et leurs filtres.
	 *
	 * Une page déjà reprise, ou dont l'adresse est prise, est laissée de côté :
	 * la méthode peut être rappelée sans créer de doublon.
	 *
	 * @param bool $automatic Reprise automatique : elle reprend aussi la clé
	 *                        ws_key et annonce son bilan au prochain écran.
	 *                        La relance manuelle affiche le sien elle-même.
	 * @return array Bilan : pages, filters, already, key (environnement de la
	 *               clé reprise, vide sinon), warnings.
	 */
	public static function run( $automatic = false ) {
		global $wpdb;

		$state  = self::state();
		$map    = (array) $state['map'];
		$report = array(
			'pages'    => 0,
			'filters'  => 0,
			'already'  => 0,
			'key'      => '',
			'warnings' => array(),
		);

		if ( ! self::tables_present() ) {
			return $report;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pages = (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}pivot_pages ORDER BY id ASC", ARRAY_A );

		// L'ordre est celui dans lequel l'ancien plugin affichait les filtres.
		$rows = self::table_exists( 'pivot_filter' )
			? (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}pivot_filter ORDER BY filter_group ASC, filter_title ASC, id ASC", ARRAY_A )
			: array();
		// phpcs:enable

		$by_page = array();

		foreach ( $rows as $row ) {
			$by_page[ (int) $row['page_id'] ][] = $row;
		}

		$imported = array();

		foreach ( $pages as $page ) {
			$legacy_id = (int) $page['id'];
			$filters   = isset( $by_page[ $legacy_id ] ) ? $by_page[ $legacy_id ] : array();

			unset( $by_page[ $legacy_id ] );

			if ( isset( $map[ $legacy_id ] ) && Pivot_Listings::get( $map[ $legacy_id ] ) ) {
				$report['already']++;
				continue;
			}

			$warnings = array();
			$config   = self::page_config( $page, $filters, $warnings );
			$saved    = Pivot_Listings::save( $config );

			if ( is_wp_error( $saved ) ) {
				$report['warnings'][] = array(
					'code'   => 'not_imported',
					'page'   => $config['title'] ? $config['title'] : $config['slug'],
					'detail' => $saved->get_error_message(),
				);
				continue;
			}

			$map[ $legacy_id ] = $saved;
			$imported[]        = $saved;

			$report['pages']++;
			$report['filters'] += count( (array) pivot_get( Pivot_Listings::get( $saved ), 'filters', array() ) );
			$report['warnings'] = array_merge( $report['warnings'], $warnings );
		}

		// Ce qui reste appartient à des pages supprimées dans l'ancien plugin.
		$orphans = array_sum( array_map( 'count', $by_page ) );

		if ( $orphans ) {
			$report['warnings'][] = array(
				'code'  => 'orphans',
				'count' => $orphans,
			);
		}

		if ( $automatic ) {
			$report['key'] = self::import_key();
		}

		self::schedule_builds( $imported );

		update_option(
			self::OPTION,
			array(
				'status' => 'done',
				'time'   => time(),
				'map'    => $map,
				'report' => $report,
				'notice' => $automatic ? 1 : 0,
			),
			false
		);

		Pivot_Logger::info(
			sprintf(
				'Reprise de l\'ancien plugin : %1$d page(s) et %2$d filtre(s) repris, %3$d page(s) déjà présente(s).',
				$report['pages'],
				$report['filters'],
				$report['already']
			),
			array( 'service' => 'import' )
		);

		return $report;
	}

	/**
	 * Programme la construction des index repris.
	 *
	 * Une construction travaille jusqu'à 25 secondes par passage : lancées à
	 * la même seconde, trente pages passeraient toutes dans le même appel de
	 * WP-Cron. Elles sont donc échelonnées d'une minute.
	 *
	 * @param array $listing_ids Identifiants des pages reprises.
	 */
	private static function schedule_builds( $listing_ids ) {
		$delay = 30;

		foreach ( $listing_ids as $listing_id ) {
			$listing = Pivot_Listings::get( $listing_id );

			if ( empty( $listing['active'] ) ) {
				continue;
			}

			wp_schedule_single_event( time() + $delay, 'pivot_continue_index', array( $listing_id ) );
			$delay += MINUTE_IN_SECONDS;
		}
	}

	/* --------------------------------------------------------------- pages */

	/**
	 * Configuration d'une page, au format de Pivot_Listings::save().
	 *
	 * @param array $page     Ligne de pivot_pages.
	 * @param array $rows     Ses lignes de pivot_filter.
	 * @param array $warnings Avertissements, complétés au passage.
	 * @return array
	 */
	private static function page_config( $page, $rows, &$warnings ) {
		$title = self::text( pivot_get( $page, 'title', '' ) );
		$query = trim( (string) pivot_get( $page, 'query', '' ) );
		$path  = trim( (string) pivot_get( $page, 'path', '' ), '/ ' );
		$intro = self::text( pivot_get( $page, 'description', '' ) );
		$mode  = strtoupper( trim( (string) pivot_get( $page, 'sortMode', '' ) ) );
		$field = trim( (string) pivot_get( $page, 'sortField', '' ) );

		// L'ancien formulaire enregistrait le champ de tri avec un format
		// numérique : laissé vide, il est devenu « 0 ».
		if ( '0' === $field ) {
			$field = '';
		}

		if ( 'SHUFFLE' === $mode ) {
			$field      = '';
			$warnings[] = array( 'code' => 'shuffle', 'page' => $title );
		}

		if ( '' !== trim( (string) pivot_get( $page, 'shortcode', '' ) ) ) {
			$warnings[] = array( 'code' => 'shortcode', 'page' => $title );
		}

		// Les adresses du plugin passent avant celles de WordPress : une page
		// publiée au même chemin deviendrait inaccessible.
		$wp_page = $path ? get_page_by_path( $path ) : null;
		$active  = ! ( $wp_page && 'publish' === get_post_status( $wp_page ) );

		if ( ! $active ) {
			$warnings[] = array( 'code' => 'inactive', 'page' => $title, 'path' => $path );
		}

		$filters = self::convert_filters( $rows, (int) $page['id'], $title, $warnings );

		return array(
			'title'          => $title,
			'titles'         => self::wpml_translations( $title, 'title-for-' . $query ),
			'slug'           => $path,
			'query_code'     => $query,
			'content'        => 2,
			'per_page'       => self::per_page( self::columns( $page ) ),
			'columns'        => self::columns( $page ),
			'show_map'       => ! empty( $page['map'] ),
			'sort_field'     => $field,
			'sort_mode'      => 'DESC' === $mode ? 'DESC' : 'ASC',
			'search_enabled' => 1,
			'intro'          => $intro,
			'intros'         => '' !== $intro ? self::wpml_translations( $intro, 'description-for-' . $query ) : array(),
			'image'          => trim( (string) pivot_get( $page, 'image', '' ) ),
			'active'         => $active,
			'filters'        => $filters,
			'filter_groups'  => self::group_translations( $filters ),
		);
	}

	/**
	 * Traductions des groupes que portent les critères repris.
	 *
	 * @param array $filters Critères convertis.
	 * @return array Nom du groupe => traductions.
	 */
	private static function group_translations( $filters ) {
		$out = array();

		foreach ( $filters as $filter ) {
			$group = (string) pivot_get( $filter, 'group', '' );

			if ( '' !== $group && ! isset( $out[ $group ] ) ) {
				$label         = self::group_label( array( $group ), '' );
				$out[ $group ] = $label['labels'];
			}
		}

		return array_filter( $out );
	}

	/**
	 * Colonnes d'une ancienne page.
	 *
	 * Hors de 1 à 6, son _set_nb_col() en affichait quatre.
	 *
	 * @param array $page Ligne de pivot_pages.
	 * @return int
	 */
	private static function columns( $page ) {
		$columns = (int) pivot_get( $page, 'nbcol', 4 );

		return $columns >= 1 && $columns <= 6 ? $columns : 4;
	}

	/**
	 * Offres par page, d'après le nombre de colonnes de l'ancien plugin.
	 *
	 * Même règle que son _define_nb_offers_per_page() : des lignes pleines.
	 *
	 * @param int $columns Colonnes.
	 * @return int
	 */
	private static function per_page( $columns ) {
		if ( 6 === $columns ) {
			return 18;
		}

		return 5 === $columns ? 15 : 12;
	}

	/* ------------------------------------------------------------- filtres */

	/**
	 * Convertit les filtres d'une page.
	 *
	 * L'ancien plugin affichait une case à cocher par valeur. Ici, un filtre
	 * porte sur un champ et propose les valeurs présentes dans les offres : les
	 * cases d'un même type d'offre ou d'un même champ sont donc réunies en un
	 * seul filtre. Les cases oui/non restent une bascule chacune, sous
	 * l'intertitre de leur groupe.
	 *
	 * @param array  $rows     Lignes de pivot_filter.
	 * @param int    $page_id  Identifiant de l'ancienne page.
	 * @param string $title    Titre de la page, pour les avertissements.
	 * @param array  $warnings Avertissements, complétés au passage.
	 * @return array Filtres au format de Pivot_Listings::save().
	 */
	private static function convert_filters( $rows, $page_id, $title, &$warnings ) {
		// L'ancien plugin réunissait la date de début et la date de fin dans une
		// seule condition « l'événement a lieu pendant » quand les deux étaient
		// présentes : c'est ce que fait ici une période sur urn:obj:date.
		$period_urns = array();

		foreach ( $rows as $row ) {
			if ( in_array( trim( (string) $row['urn'] ), self::PERIOD_URNS, true ) ) {
				$period_urns[ trim( (string) $row['urn'] ) ] = true;
			}
		}

		$has_period = count( $period_urns ) === count( self::PERIOD_URNS );

		// Chaque ligne rejoint un emplacement ; l'ordre des emplacements est
		// celui de leur première ligne.
		$slots = array();

		foreach ( $rows as $row ) {
			$slot = self::slot( $row, $has_period );

			if ( null === $slot ) {
				continue;
			}

			if ( 'unsupported' === $slot['kind'] ) {
				$warnings[] = array(
					'code'   => 'unsupported',
					'page'   => $title,
					'filter' => self::text( $row['filter_title'] ),
				);
				continue;
			}

			if ( ! isset( $slots[ $slot['key'] ] ) ) {
				$slots[ $slot['key'] ] = $slot + array( 'rows' => array() );
			}

			$slots[ $slot['key'] ]['rows'][] = $row;
		}

		$filters = array();

		foreach ( $slots as $slot ) {
			$filters[] = self::build_filter( $slot, $page_id, $title, $warnings );
		}

		return $filters;
	}

	/**
	 * Emplacement d'une ancienne ligne de filtre.
	 *
	 * @param array $row        Ligne de pivot_filter.
	 * @param bool  $has_period La page porte une date de début et une date de fin.
	 * @return array|null key, kind et urn ; null pour une ligne sans objet ici.
	 */
	private static function slot( $row, $has_period ) {
		$urn      = trim( (string) $row['urn'] );
		$type     = trim( (string) $row['type'] );
		$operator = trim( (string) $row['operator'] );

		if ( self::NAME_URN === $urn ) {
			return null;
		}

		if ( array_key_exists( $urn, self::ADDRESS_SOURCES ) ) {
			return array( 'key' => 'address:' . $urn, 'kind' => 'address', 'urn' => $urn );
		}

		// Ne se traduit par aucun contrôle d'ici : une bascule lirait la valeur
		// du champ, pas sa présence.
		if ( 'notempty' === $operator ) {
			return array( 'key' => 'unsupported', 'kind' => 'unsupported', 'urn' => $urn );
		}

		// Type d'offre : l'ancien plugin cherchait le numéro de chaque case
		// cochée dans urn:fld:typeofr.
		if ( 'Type' === $type ) {
			return array( 'key' => 'type', 'kind' => 'type', 'urn' => '' );
		}

		// Valeur d'un champ à choix : même calcul du champ parent que l'ancien
		// _construct_filters_array(), urn:val:class:3star donne urn:fld:class.
		if ( 'Value' === $type ) {
			$parent = preg_replace( '/:.*?:/', ':fld:', substr( $urn, 0, (int) strrpos( $urn, ':' ) ), 1 );

			return array( 'key' => 'values:' . $parent, 'kind' => 'values', 'urn' => $parent );
		}

		if ( 'exist' === $operator || 'Boolean' === $type ) {
			return array( 'key' => 'toggle:' . $row['id'], 'kind' => 'toggle', 'urn' => $urn );
		}

		if ( 'Date' === $type ) {
			if ( $has_period && in_array( $urn, self::PERIOD_URNS, true ) ) {
				return array( 'key' => 'period', 'kind' => 'period', 'urn' => 'urn:obj:date' );
			}

			return array( 'key' => 'date:' . $urn, 'kind' => 'date', 'urn' => $urn );
		}

		if ( in_array( $type, Pivot_Thesaurus::NUMERIC_TYPES, true ) ) {
			return array( 'key' => 'range:' . $urn, 'kind' => 'range', 'urn' => $urn );
		}

		return array( 'key' => 'field:' . $urn, 'kind' => 'field', 'urn' => $urn );
	}

	/**
	 * Filtre d'un emplacement.
	 *
	 * @param array  $slot     Emplacement et ses lignes.
	 * @param int    $page_id  Identifiant de l'ancienne page.
	 * @param string $title    Titre de la page, pour les avertissements.
	 * @param array  $warnings Avertissements, complétés au passage.
	 * @return array
	 */
	private static function build_filter( $slot, $page_id, $title, &$warnings ) {
		$rows   = $slot['rows'];
		$first  = $rows[0];
		$groups = array();

		foreach ( $rows as $row ) {
			$groups[] = self::text( pivot_get( $row, 'filter_group', '' ) );
		}

		// Un critère qui garde le libellé de sa ligne s'affiche sous l'intertitre
		// de son groupe, comme dans l'ancien formulaire. Celui qui réunit
		// plusieurs lignes prend le nom du groupe pour libellé : le lui donner
		// aussi pour groupe l'afficherait deux fois.
		$own = array( 'group' => self::text( pivot_get( $first, 'filter_group', '' ) ) ) + self::row_label( $first, $page_id );

		switch ( $slot['kind'] ) {
			case 'address':
				return array(
					'source' => self::ADDRESS_SOURCES[ $slot['urn'] ],
					'type'   => 'select',
				) + $own;

			case 'type':
				// Libellé écrit en français, sans __() : il est enregistré, et
				// doit l'être dans la langue par défaut quelle que soit celle de
				// l'administrateur. Les autres langues viennent des fichiers de
				// traduction du plugin.
				return array(
					'source' => 'type',
					'type'   => 'multiselect',
				) + self::group_label( $groups, 'Type d\'offre' );

			case 'values':
				$label = self::group_label( $groups, '' );

				if ( '' === $label['label'] ) {
					$label      = self::label( $slot['urn'] );
					$warnings[] = array( 'code' => 'unlabeled', 'page' => $title, 'urn' => $slot['urn'] );
				}

				return array(
					'source' => 'spec',
					'urn'    => $slot['urn'],
					'type'   => 'multiselect',
				) + $label;

			case 'toggle':
				return array(
					'source' => 'spec',
					'urn'    => $slot['urn'],
					'type'   => 'toggle',
				) + $own;

			case 'period':
				return array(
					'source'   => 'spec',
					'urn'      => 'urn:obj:date',
					'type'     => 'date',
					'operator' => 'between',
				) + self::group_label( $groups, 'Dates' );

			case 'date':
				return array(
					'source'   => 'spec',
					'urn'      => $slot['urn'],
					'type'     => 'date',
					'operator' => self::operator( $first['operator'], 'between' ),
				) + $own;

			case 'range':
				$operators = array();

				foreach ( $rows as $row ) {
					$operators[] = self::operator( $row['operator'], 'gte' );
				}

				$operators = array_unique( $operators );

				// Un minimum et un maximum sur le même champ : une seule jauge,
				// entre deux valeurs.
				if ( in_array( 'between', $operators, true ) || ( in_array( 'gte', $operators, true ) && in_array( 'lte', $operators, true ) ) ) {
					$operator = 'between';
				} else {
					$operator = reset( $operators );
				}

				$label = count( $rows ) > 1 ? self::group_label( $groups, '' ) : array( 'label' => '' );

				return array(
					'source'   => 'spec',
					'urn'      => $slot['urn'],
					'type'     => 'range',
					'operator' => $operator,
				) + ( '' !== $label['label'] ? $label : $own );

			default:
				$control = Pivot_Thesaurus::suggested_control( trim( (string) $first['type'] ) );
				$filter  = array(
					'source' => 'spec',
					'urn'    => $slot['urn'],
					'type'   => $control,
				);

				if ( in_array( $control, array( 'range', 'date' ), true ) ) {
					$filter['operator'] = self::operator( $first['operator'], 'date' === $control ? 'between' : 'gte' );
				}

				return $filter + $own;
		}
	}

	/**
	 * Comparaison d'ici correspondant à celle de l'ancien plugin.
	 *
	 * @param string $operator Opérateur de l'ancien plugin.
	 * @param string $fallback Repli.
	 * @return string
	 */
	private static function operator( $operator, $fallback ) {
		$operator = trim( (string) $operator );

		return array_key_exists( $operator, self::OPERATORS ) ? self::OPERATORS[ $operator ] : $fallback;
	}

	/* ----------------------------------------------------------- libellés */

	/**
	 * Libellé d'une ancienne ligne de filtre, avec ses traductions.
	 *
	 * @param array $row     Ligne de pivot_filter.
	 * @param int   $page_id Identifiant de l'ancienne page.
	 * @return array label, labels.
	 */
	private static function row_label( $row, $page_id ) {
		$columns = array();

		foreach ( array( 'nl', 'en', 'de' ) as $lang ) {
			$columns[ $lang ] = pivot_get( $row, 'filter_title_' . $lang, '' );
		}

		return self::label(
			pivot_get( $row, 'filter_title', '' ),
			'filter-title-' . trim( (string) $row['urn'] ) . '-' . $page_id,
			$columns
		);
	}

	/**
	 * Libellé d'un filtre qui réunit plusieurs lignes : leur groupe.
	 *
	 * Deux groupes sur un même champ — « Classement Gîte » et « Classement
	 * Meublé » — donnent leur début commun, « Classement ».
	 *
	 * @param array  $groups   Groupes des lignes réunies.
	 * @param string $fallback Libellé de repli, en français ; vide pour aucun.
	 * @return array label, labels.
	 */
	private static function group_label( $groups, $fallback ) {
		$group = self::common_words( $groups );

		if ( '' !== $group ) {
			// Nom sous lequel l'ancien plugin déclarait un groupe dans WPML.
			return self::label( $group, 'filter-group-' . preg_replace( '/[^a-zA-Z]+/', '', $group ) );
		}

		return '' !== $fallback ? self::label( $fallback ) : array( 'label' => '', 'labels' => array() );
	}

	/**
	 * Début commun de plusieurs textes, mot à mot.
	 *
	 * @param array $texts Textes.
	 * @return string Le début commun ; à défaut, le premier texte.
	 */
	private static function common_words( $texts ) {
		$texts = array_values( array_unique( array_filter( array_map( 'trim', $texts ), 'strlen' ) ) );

		if ( ! $texts ) {
			return '';
		}

		$words = explode( ' ', $texts[0] );

		foreach ( array_slice( $texts, 1 ) as $text ) {
			$other = explode( ' ', $text );
			$keep  = 0;

			while ( isset( $words[ $keep ], $other[ $keep ] ) && $words[ $keep ] === $other[ $keep ] ) {
				$keep++;
			}

			$words = array_slice( $words, 0, $keep );
		}

		$common = trim( implode( ' ', $words ) );

		return '' !== $common ? $common : $texts[0];
	}

	/**
	 * Un libellé et ses traductions.
	 *
	 * Par ordre de préférence : la traduction saisie dans WPML, celle des
	 * colonnes de l'ancienne table, puis celle du plugin lui-même quand le
	 * libellé est l'un des siens (« Commune », « Dates »…).
	 *
	 * @param string $text    Libellé dans la langue par défaut.
	 * @param string $name    Nom de chaîne WPML attendu.
	 * @param array  $columns Traductions de l'ancienne table, lang => texte.
	 * @return array label, labels.
	 */
	private static function label( $text, $name = '', $columns = array() ) {
		$text   = self::text( $text );
		$labels = self::wpml_translations( $text, $name );

		foreach ( $columns as $lang => $value ) {
			$value = self::text( $value );

			if ( '' !== $value && ! isset( $labels[ $lang ] ) ) {
				$labels[ $lang ] = $value;
			}
		}

		foreach ( self::plugin_translations( $text ) as $lang => $value ) {
			if ( ! isset( $labels[ $lang ] ) ) {
				$labels[ $lang ] = $value;
			}
		}

		unset( $labels[ Pivot_I18n::default_lang() ] );

		return array(
			'label'  => $text,
			'labels' => $labels,
		);
	}

	/**
	 * Texte enregistré par l'ancien plugin, débarrassé de ses antislashs.
	 *
	 * Ses formulaires écrivaient $_POST tel quel : « Chambres d\'hôtes ».
	 *
	 * @param mixed $value Valeur brute.
	 * @return string
	 */
	private static function text( $value ) {
		return trim( stripslashes( (string) $value ) );
	}

	/* -------------------------------------------------------- traductions */

	/**
	 * Traductions WPML d'un texte déclaré par l'ancien plugin.
	 *
	 * L'ancien plugin déclarait ses textes dans WPML String Translation, sous
	 * le contexte « pivot » : title-for-{requête}, description-for-{requête},
	 * filter-title-{urn}-{page}, filter-group-{groupe}. Le nom seul ne suffit
	 * pas : deux pages peuvent partager une requête, et le nom reste alors
	 * attaché au titre de la dernière enregistrée. La chaîne est donc cherchée
	 * d'après son texte d'origine ; celle qui porte le nom attendu passe devant.
	 *
	 * @param string $value Texte d'origine.
	 * @param string $name  Nom de chaîne attendu.
	 * @return array lang => traduction, langue par défaut exclue.
	 */
	private static function wpml_translations( $value, $name = '' ) {
		$value = trim( (string) $value );
		$index = self::wpml_index();

		if ( '' === $value || ! isset( $index[ $value ] ) ) {
			return array();
		}

		$default = Pivot_I18n::default_lang();
		$out     = array();

		// Deux passes : la chaîne au nom attendu d'abord, les autres ensuite.
		foreach ( array( true, false ) as $named ) {
			foreach ( $index[ $value ] as $entry ) {
				if ( ( $entry['name'] === $name ) !== $named ) {
					continue;
				}

				$lang = Pivot_I18n::code( $entry['lang'] );

				if ( $lang && $lang !== $default && ! isset( $out[ $lang ] ) ) {
					$out[ $lang ] = $entry['text'];
				}
			}
		}

		return $out;
	}

	/**
	 * Traductions terminées du contexte « pivot », rangées par texte d'origine.
	 *
	 * Lues en une requête : une par libellé en coûterait une centaine.
	 *
	 * @return array texte d'origine => liste de array( name, lang, text ).
	 */
	private static function wpml_index() {
		global $wpdb;
		static $index = null;

		if ( null !== $index ) {
			return $index;
		}

		$index = array();

		if ( ! self::table_exists( 'icl_strings' ) || ! self::table_exists( 'icl_string_translations' ) ) {
			return $index;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.name, s.value AS original, t.language, t.value AS translation
				FROM {$wpdb->prefix}icl_strings s
				INNER JOIN {$wpdb->prefix}icl_string_translations t ON t.string_id = s.id
				WHERE s.context = %s AND t.status = %d AND t.value <> ''
				ORDER BY s.id DESC",
				'pivot',
				self::WPML_COMPLETE
			),
			ARRAY_A
		);
		// phpcs:enable

		foreach ( (array) $rows as $row ) {
			$index[ trim( (string) $row['original'] ) ][] = array(
				'name' => (string) $row['name'],
				'lang' => (string) $row['language'],
				'text' => trim( (string) $row['translation'] ),
			);
		}

		return $index;
	}

	/**
	 * Traductions d'un libellé du plugin, lues dans ses propres fichiers.
	 *
	 * Pendant l'activation, les traductions ne sont pas chargées, et une seule
	 * langue le serait : chaque fichier est lu directement.
	 *
	 * @param string $text Libellé source, en français.
	 * @return array lang => traduction.
	 */
	private static function plugin_translations( $text ) {
		static $catalogs = array();

		$out = array();

		if ( '' === $text || ! class_exists( 'MO' ) ) {
			return $out;
		}

		foreach ( Pivot_I18n::languages() as $lang ) {
			// Le français est la langue source des libellés.
			if ( 'fr' === $lang ) {
				continue;
			}

			if ( ! array_key_exists( $lang, $catalogs ) ) {
				$file              = PIVOT_DIR . 'languages/pivot-offres-' . Pivot_I18n::locale( $lang ) . '.mo';
				$catalog           = new MO();
				$catalogs[ $lang ] = is_readable( $file ) && $catalog->import_from_file( $file ) ? $catalog : null;
			}

			if ( ! $catalogs[ $lang ] ) {
				continue;
			}

			$translated = $catalogs[ $lang ]->translate( $text );

			if ( '' !== $translated && $translated !== $text ) {
				$out[ $lang ] = $translated;
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------ clé ws_key */

	/**
	 * Reprend la clé ws_key de l'ancien plugin, si aucune n'est saisie ici.
	 *
	 * L'environnement suit : celui par défaut est « stage », alors que
	 * l'ancien plugin interrogeait la production, sauf adresse contraire.
	 *
	 * @return string Environnement de la clé reprise ; vide si rien n'est repris.
	 */
	private static function import_key() {
		// L'option est écrite telle quelle, comme dans les reprises de version.
		// Une fois le formulaire des réglages déclaré, son nettoyage serait
		// appliqué, et remettrait à zéro toutes les cases absentes du tableau :
		// plan du site, hreflang, différentiel…
		if ( has_filter( 'sanitize_option_pivot_settings' ) ) {
			return '';
		}

		$key      = trim( (string) get_option( 'pivot_key', '' ) );
		$settings = get_option( 'pivot_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		if ( '' === $key || '' !== trim( (string) pivot_get( $settings, 'wskey_prod', '' ) ) || '' !== trim( (string) pivot_get( $settings, 'wskey_stage', '' ) ) ) {
			return '';
		}

		$environment = false !== strpos( (string) get_option( 'pivot_uri', '' ), '-stage.' ) ? 'stage' : 'prod';
		$changed     = pivot_get( $settings, 'environment', 'stage' ) !== $environment;

		$settings[ 'wskey_' . $environment ] = sanitize_text_field( $key );
		$settings['environment']             = $environment;

		update_option( 'pivot_settings', $settings );

		// Même règle que l'écran des réglages : changer de serveur périme les
		// caches.
		if ( $changed ) {
			Pivot_Cache::flush();
		}

		return $environment;
	}

	/* -------------------------------------------------------------- bilan */

	/**
	 * Avertissements du bilan, mis en mots au moment de l'affichage.
	 *
	 * Le bilan est enregistré sous forme de codes : écrit pendant l'activation,
	 * avant le chargement des traductions, il serait sinon toujours en français.
	 *
	 * @param array $report Bilan renvoyé par run().
	 * @return array Lignes de texte brut, à échapper.
	 */
	public static function messages( $report ) {
		$lines = array();

		foreach ( (array) pivot_get( $report, 'warnings', array() ) as $warning ) {
			$page = (string) pivot_get( $warning, 'page', '' );

			switch ( pivot_get( $warning, 'code', '' ) ) {
				case 'inactive':
					$lines[] = sprintf(
						/* translators: 1 : titre de la page, 2 : chemin d'URL. */
						__( '« %1$s » : une page WordPress est déjà publiée à l\'adresse /%2$s/. La page de listing est reprise, mais désactivée.', 'pivot-offres' ),
						$page,
						pivot_get( $warning, 'path', '' )
					);
					break;

				case 'shuffle':
					$lines[] = sprintf(
						/* translators: %s : titre de la page. */
						__( '« %s » : l\'ordre aléatoire n\'existe pas ici. Les offres suivent l\'ordre renvoyé par PIVOT.', 'pivot-offres' ),
						$page
					);
					break;

				case 'shortcode':
					$lines[] = sprintf(
						/* translators: %s : titre de la page. */
						__( '« %s » : le shortcode de présentation n\'est pas repris.', 'pivot-offres' ),
						$page
					);
					break;

				case 'unsupported':
					$lines[] = sprintf(
						/* translators: 1 : titre de la page, 2 : libellé du filtre. */
						__( '« %1$s » : le filtre « %2$s » n\'a pas d\'équivalent et n\'est pas repris.', 'pivot-offres' ),
						$page,
						pivot_get( $warning, 'filter', '' )
					);
					break;

				case 'unlabeled':
					$lines[] = sprintf(
						/* translators: 1 : titre de la page, 2 : urn du champ. */
						__( '« %1$s » : le filtre sur %2$s n\'avait pas de nom de groupe. Donnez-lui un libellé.', 'pivot-offres' ),
						$page,
						pivot_get( $warning, 'urn', '' )
					);
					break;

				case 'not_imported':
					$lines[] = sprintf(
						/* translators: 1 : titre de la page, 2 : motif. */
						__( '« %1$s » n\'a pas été reprise : %2$s', 'pivot-offres' ),
						$page,
						pivot_get( $warning, 'detail', '' )
					);
					break;

				case 'orphans':
					$count   = (int) pivot_get( $warning, 'count', 0 );
					$lines[] = sprintf(
						/* translators: %d : nombre de filtres. */
						_n( '%d filtre rattaché à une page supprimée a été ignoré.', '%d filtres rattachés à des pages supprimées ont été ignorés.', $count, 'pivot-offres' ),
						$count
					);
					break;
			}
		}

		return $lines;
	}
}
