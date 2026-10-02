<?php
/**
 * Zones de fermeture liées à une offre.
 *
 * PIVOT décrit les fermetures d'un itinéraire — chasse, travaux, inondation —
 * par des offres à part, de type « Zone de fermeture » (33), liées à
 * l'itinéraire. Chaque zone porte son type de fermeture (urn:fld:typeferm) et
 * ses jours de fermeture, en objets urn:obj:date. Un itinéraire peut traverser
 * plusieurs zones : leurs dates s'additionnent.
 *
 * Les zones n'arrivent avec leurs dates qu'au niveau « complet avec offres
 * liées » : c'est celui de la fiche détail, et celui que doit avoir une page
 * de listing pour que ses vignettes les montrent.
 *
 * Fermé aujourd'hui ou non ne se décide pas ici une fois pour toutes : une
 * vignette est rendue dans l'index pour plusieurs jours, et une fiche peut
 * sortir d'un cache de page. Le serveur écrit les périodes dans l'attribut
 * data-pivot-closures, avec l'état du jour où il rend la page ;
 * assets/js/pivot-closures.js le recalcule à la date du visiteur.
 *
 * Le balisage est libre. Dans un élément qui porte data-pivot-closures, le
 * script reconnaît :
 *
 *   data-pivot-closure="today"        affiché si l'offre est fermée aujourd'hui
 *   data-pivot-closure="upcoming"     affiché s'il reste une fermeture, aujourd'hui compris
 *   data-pivot-closure="open"         affiché si l'offre n'est pas fermée aujourd'hui
 *   data-pivot-closure="next"         affiché s'il y a une fermeture après aujourd'hui
 *   data-pivot-closure="none"         affiché s'il ne reste aucune fermeture
 *   data-pivot-closure-kind="pchasse" restreint la condition à un type de fermeture
 *   data-pivot-closure-date="today"   reçoit la date du jour, en toutes lettres
 *   data-pivot-closure-date="next"    reçoit la date de la prochaine fermeture
 *   data-pivot-closure-end="AAAAMMJJ" masqué une fois cette date passée
 *   data-pivot-closure-group          masqué quand toutes ses lignes sont passées
 *
 * Plusieurs conditions séparées par une espace doivent toutes être remplies :
 * « open next » se lit « ouvert aujourd'hui, et fermé un jour prochain ».
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Closures {

	/** Type d'offre PIVOT « Zone de fermeture ». */
	const OFFER_TYPE = 33;

	/** Champ qui porte le type de fermeture d'une zone. */
	const KIND_URN = 'urn:fld:typeferm';

	/** Préfixe des valeurs de ce champ : urn:val:typeferm:pchasse. */
	const KIND_PREFIX = 'urn:val:typeferm:';

	/** Type retenu pour une zone qui ne déclare pas le sien. */
	const OTHER_KIND = 'autre';

	/** Ancre du bloc des dates sur la fiche, visée par le « + » des vignettes. */
	const ANCHOR = 'pivot-closures';

	/**
	 * Types d'offre lus comme des zones de fermeture.
	 *
	 * @return array
	 */
	public static function offer_types() {
		/**
		 * Types d'offre lus comme des zones de fermeture.
		 *
		 * @param array $types Par défaut, le type 33.
		 */
		return array_map( 'intval', (array) apply_filters( 'pivot_closure_offer_types', array( self::OFFER_TYPE ) ) );
	}

	/**
	 * Aujourd'hui, à l'heure du site.
	 *
	 * @return int AAAAMMJJ.
	 */
	public static function today() {
		return (int) current_time( 'Ymd' );
	}

	/* ----------------------------------------------------------- lecture */

	/**
	 * Zones de fermeture liées à une offre.
	 *
	 * Lues dans toutes les relations de l'offre, quelle que soit l'urn du lien
	 * (urn:lnk:offre:enfant le plus souvent) : c'est le type de l'offre liée
	 * qui fait la zone. Une zone liée deux fois ne compte qu'une fois.
	 *
	 * @param array       $offer Offre normalisée.
	 * @param string|null $lang  Langue du nom des zones.
	 * @return array Liste de array( code, name, kind, periods ), periods en array( début, fin ) AAAAMMJJ.
	 */
	public static function zones( $offer, $lang = null ) {
		$types = self::offer_types();
		$zones = array();

		foreach ( (array) pivot_get( $offer, 'relations', array() ) as $linked ) {
			foreach ( (array) $linked as $zone ) {
				$code = (string) pivot_get( $zone, 'code', '' );

				if ( '' === $code || isset( $zones[ $code ] ) || ! in_array( (int) pivot_get( $zone, 'type', 0 ), $types, true ) ) {
					continue;
				}

				$periods = Pivot_Fields::date_periods( $zone, 'urn:obj:date' );

				if ( ! $periods ) {
					continue;
				}

				$zones[ $code ] = array(
					'code'    => $code,
					'name'    => Pivot_Templates::offer_name( $zone, $lang ),
					'kind'    => self::kind_of( $zone ),
					'periods' => $periods,
				);
			}
		}

		return array_values( $zones );
	}

	/**
	 * Type de fermeture d'une zone : la fin de son urn, « pchasse ».
	 *
	 * @param array $zone Zone de fermeture.
	 * @return string « autre » si la zone n'en déclare pas.
	 */
	public static function kind_of( $zone ) {
		$value = (string) pivot_get( Pivot_Fields::find_all( $zone, self::KIND_URN ), array( 0, 'value' ), '' );
		$kind  = 0 === strpos( $value, self::KIND_PREFIX ) ? sanitize_key( substr( $value, strlen( self::KIND_PREFIX ) ) ) : '';

		return '' !== $kind ? $kind : self::OTHER_KIND;
	}

	/**
	 * Périodes de fermeture d'une offre, toutes zones confondues.
	 *
	 * C'est la forme compacte qui voyage dans l'index et dans l'attribut
	 * data-pivot-closures : une entrée par période et par type de fermeture,
	 * les jours qui se touchent ou se chevauchent réunis.
	 *
	 * @param array    $offer Offre normalisée.
	 * @param int|null $from  Première date gardée (AAAAMMJJ) : aujourd'hui par défaut, 0 pour tout garder.
	 * @return array Liste de array( début, fin, type ), triée.
	 */
	public static function periods( $offer, $from = null ) {
		$from    = null === $from ? self::today() : (int) $from;
		$by_kind = array();

		foreach ( self::zones( $offer ) as $zone ) {
			foreach ( $zone['periods'] as $period ) {
				if ( $from && $period[1] < $from ) {
					continue;
				}

				$by_kind[ $zone['kind'] ][] = $period;
			}
		}

		$out = array();

		foreach ( $by_kind as $kind => $periods ) {
			foreach ( self::merge( $periods ) as $period ) {
				$out[] = array( $period[0], $period[1], (string) $kind );
			}
		}

		usort( $out, array( __CLASS__, 'compare' ) );

		return $out;
	}

	/**
	 * Calendrier des fermetures, pour la fiche : une ligne par période.
	 *
	 * Deux zones fermées le même jour font une seule ligne, qui les nomme
	 * toutes deux.
	 *
	 * @param array       $offer Offre normalisée.
	 * @param string|null $lang  Langue.
	 * @param int|null    $from  Première date gardée : aujourd'hui par défaut.
	 * @return array Liste de array( start, end, kinds (type => libellé), zones (code => nom) ).
	 */
	public static function schedule( $offer, $lang = null, $from = null ) {
		$lang = $lang ? $lang : Pivot_I18n::current();
		$from = null === $from ? self::today() : (int) $from;
		$rows = array();

		foreach ( self::zones( $offer, $lang ) as $zone ) {
			foreach ( $zone['periods'] as $period ) {
				if ( $from && $period[1] < $from ) {
					continue;
				}

				$key = $period[0] . '-' . $period[1];

				if ( ! isset( $rows[ $key ] ) ) {
					$rows[ $key ] = array(
						'start' => $period[0],
						'end'   => $period[1],
						'kinds' => array(),
						'zones' => array(),
					);
				}

				$rows[ $key ]['kinds'][ $zone['kind'] ] = self::kind_label( $zone['kind'], $lang );
				$rows[ $key ]['zones'][ $zone['code'] ] = $zone['name'];
			}
		}

		$rows = array_values( $rows );

		usort(
			$rows,
			static function ( $a, $b ) {
				return array( $a['start'], $a['end'] ) <=> array( $b['start'], $b['end'] );
			}
		);

		return $rows;
	}

	/**
	 * Calendrier regroupé par mois, pour une liste plus lisible.
	 *
	 * @param array $rows Lignes de schedule().
	 * @return array Mois (AAAAMM) => array( label, end, rows ), end étant la dernière date du mois.
	 */
	public static function by_month( $rows ) {
		$months = array();

		foreach ( $rows as $row ) {
			$key = intdiv( (int) $row['start'], 100 );

			if ( ! isset( $months[ $key ] ) ) {
				$months[ $key ] = array(
					'label' => self::format( $key * 100 + 1, 'F Y' ),
					'end'   => 0,
					'rows'  => array(),
				);
			}

			$months[ $key ]['end']    = max( $months[ $key ]['end'], (int) $row['end'] );
			$months[ $key ]['rows'][] = $row;
		}

		return $months;
	}

	/**
	 * État d'une offre à une date.
	 *
	 * @param array    $periods Périodes compactes, de periods().
	 * @param int|null $date    Date (AAAAMMJJ) : aujourd'hui par défaut.
	 * @return array closed, upcoming, next (AAAAMMJJ ou 0), kinds (fermés ce jour), upcoming_kinds.
	 */
	public static function status( $periods, $date = null ) {
		$date   = null === $date ? self::today() : (int) $date;
		$status = array(
			'closed'         => false,
			'upcoming'       => false,
			'next'           => 0,
			'kinds'          => array(),
			'upcoming_kinds' => array(),
		);

		foreach ( (array) $periods as $period ) {
			if ( ! is_array( $period ) || count( $period ) < 2 || (int) $period[1] < $date ) {
				continue;
			}

			$kind = isset( $period[2] ) ? (string) $period[2] : self::OTHER_KIND;

			$status['upcoming']                = true;
			$status['upcoming_kinds'][ $kind ] = true;

			if ( (int) $period[0] <= $date ) {
				$status['closed']         = true;
				$status['kinds'][ $kind ] = true;
			} elseif ( ! $status['next'] || (int) $period[0] < $status['next'] ) {
				$status['next'] = (int) $period[0];
			}
		}

		return $status;
	}

	/**
	 * Une condition d'affichage est-elle remplie ?
	 *
	 * Même lecture que le script : voir l'en-tête du fichier.
	 *
	 * @param string $roles  Conditions, séparées par une espace.
	 * @param array  $status État, de status().
	 * @param string $kind   Type de fermeture visé, ou vide pour tous.
	 * @return bool
	 */
	public static function matches( $roles, $status, $kind = '' ) {
		$closed   = '' === $kind ? $status['closed'] : isset( $status['kinds'][ $kind ] );
		$upcoming = '' === $kind ? $status['upcoming'] : isset( $status['upcoming_kinds'][ $kind ] );

		foreach ( preg_split( '/\s+/', trim( (string) $roles ) ) as $role ) {
			switch ( $role ) {
				case 'today':
					$ok = $closed;
					break;
				case 'upcoming':
					$ok = $upcoming;
					break;
				case 'open':
					$ok = ! $status['closed'];
					break;
				case 'next':
					$ok = (bool) $status['next'];
					break;
				case 'none':
					$ok = ! $status['upcoming'];
					break;
				default:
					$ok = true;
			}

			if ( ! $ok ) {
				return false;
			}
		}

		return true;
	}

	/* ---------------------------------------------------------- affichage */

	/**
	 * Attributs de l'élément qui porte les périodes.
	 *
	 * @param array $periods Périodes compactes.
	 * @return string Attribut prêt à écrire, précédé d'une espace.
	 */
	public static function attributes( $periods ) {
		return ' data-pivot-closures="' . esc_attr( wp_json_encode( array_values( (array) $periods ) ) ) . '"';
	}

	/**
	 * Attributs d'un élément conditionnel, avec son état du jour.
	 *
	 *     <p<?php echo Pivot_Closures::when( 'today', $status ); ?>>…</p>
	 *
	 * @param string $roles  Conditions, séparées par une espace.
	 * @param array  $status État, de status().
	 * @param string $kind   Type de fermeture visé, ou vide.
	 * @return string Attributs, précédés d'une espace.
	 */
	public static function when( $roles, $status, $kind = '' ) {
		$out = ' data-pivot-closure="' . esc_attr( $roles ) . '"';

		if ( '' !== $kind ) {
			$out .= ' data-pivot-closure-kind="' . esc_attr( $kind ) . '"';
		}

		return self::matches( $roles, $status, $kind ) ? $out : $out . ' hidden';
	}

	/**
	 * Date en toutes lettres, que le script remplacera par la date du visiteur.
	 *
	 * @param string $which  today ou next.
	 * @param array  $status État, de status().
	 * @return string Balise <span>.
	 */
	public static function date_tag( $which, $status ) {
		$date = 'next' === $which ? (int) $status['next'] : self::today();

		return '<span data-pivot-closure-date="' . esc_attr( $which ) . '">' . esc_html( $date ? self::format( $date ) : '' ) . '</span>';
	}

	/**
	 * Écrit une date AAAAMMJJ dans la langue de la page.
	 *
	 * Par défaut « jeudi 1 octobre », et l'année en plus quand ce n'est pas
	 * l'année en cours : le script écrit la même chose.
	 *
	 * @param int    $date   AAAAMMJJ.
	 * @param string $format Format de date PHP ; vide pour le format par défaut.
	 * @return string
	 */
	public static function format( $date, $format = '' ) {
		$time = DateTime::createFromFormat( '!Ymd', sprintf( '%08d', (int) $date ), wp_timezone() );

		if ( ! $time ) {
			return '';
		}

		if ( '' === $format ) {
			$format = self::day_format( intdiv( (int) $date, 10000 ) !== intdiv( self::today(), 10000 ) );
		}

		return (string) wp_date( $format, $time->getTimestamp(), wp_timezone() );
	}

	/**
	 * Format d'un jour de fermeture, traduisible : « l j F » en français,
	 * « l, F j » en anglais américain.
	 *
	 * @param bool $with_year Avec l'année.
	 * @return string Format de date PHP.
	 */
	public static function day_format( $with_year = false ) {
		/* translators: format de date PHP d'un jour de fermeture, « jeudi 1 octobre ». */
		$short = _x( 'l j F', 'jour de fermeture', 'pivot-offres' );
		/* translators: format de date PHP d'un jour de fermeture, avec l'année : « jeudi 1 octobre 2026 ». */
		$long = _x( 'l j F Y', 'jour de fermeture', 'pivot-offres' );

		return $with_year ? $long : $short;
	}

	/**
	 * Une période en toutes lettres : « samedi 17 octobre », ou
	 * « Du samedi 17 octobre au dimanche 18 octobre ».
	 *
	 * @param int    $start  Début, AAAAMMJJ.
	 * @param int    $end    Fin, AAAAMMJJ.
	 * @param string $format Format de date PHP ; vide pour celui de format().
	 * @return string
	 */
	public static function format_period( $start, $end, $format = '' ) {
		if ( (int) $start === (int) $end ) {
			return self::format( $start, $format );
		}

		return sprintf(
			/* translators: 1 : date de début, 2 : date de fin. */
			__( 'Du %1$s au %2$s', 'pivot-offres' ),
			self::format( $start, $format ),
			self::format( $end, $format )
		);
	}

	/**
	 * Première lettre en capitale, pour un début de ligne.
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	public static function ucfirst( $text ) {
		if ( ! function_exists( 'mb_substr' ) ) {
			return ucfirst( $text );
		}

		return mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
	}

	/**
	 * Libellé PIVOT d'un type de fermeture : « Période de chasse ».
	 *
	 * @param string      $kind Type, « pchasse ».
	 * @param string|null $lang Langue.
	 * @return string
	 */
	public static function kind_label( $kind, $lang = null ) {
		$label = self::OTHER_KIND !== $kind ? (string) Pivot_Thesaurus::label( self::KIND_PREFIX . $kind, self::OFFER_TYPE, $lang ) : '';

		return '' !== $label ? $label : __( 'Fermeture', 'pivot-offres' );
	}

	/**
	 * Libellé court d'un type de fermeture, pour une pastille : « Impact chasse ».
	 *
	 * @param string $kind Type, « pchasse ».
	 * @return string
	 */
	public static function impact_label( $kind ) {
		$labels = array(
			'pchasse'   => __( 'Impact chasse', 'pivot-offres' ),
			'travaux'   => __( 'Impact travaux', 'pivot-offres' ),
			'innond'    => __( 'Impact inondation', 'pivot-offres' ),
			'sanitaire' => __( 'Impact sanitaire', 'pivot-offres' ),
		);

		/**
		 * Libellé court d'un type de fermeture.
		 *
		 * @param string $label Libellé.
		 * @param string $kind  Type, « pchasse » ; « autre » si la zone n'en déclare pas.
		 */
		return (string) apply_filters( 'pivot_closure_impact_label', isset( $labels[ $kind ] ) ? $labels[ $kind ] : __( 'Fermetures prévues', 'pivot-offres' ), $kind );
	}

	/**
	 * Phrase « fermé ce … », où %s reçoit la date.
	 *
	 * @param int $type_id Type de l'offre : un itinéraire a sa tournure.
	 * @return string
	 */
	public static function closed_text( $type_id = 0 ) {
		/* translators: %s : date du jour, « jeudi 1 octobre ». */
		$text = 8 === (int) $type_id ? __( 'L\'itinéraire est fermé ce %s', 'pivot-offres' ) : __( 'Fermé ce %s', 'pivot-offres' );

		/**
		 * Phrase « fermé ce … » d'une vignette ou d'une fiche.
		 *
		 * @param string $text    Phrase, %s recevant la date.
		 * @param int    $type_id Type de l'offre.
		 */
		return (string) apply_filters( 'pivot_closure_closed_text', $text, (int) $type_id );
	}

	/**
	 * Phrase « pas fermé aujourd'hui, mais bientôt », où %s reçoit la date de
	 * la prochaine fermeture.
	 *
	 * @param int $type_id Type de l'offre : un itinéraire a sa tournure.
	 * @return string
	 */
	public static function open_text( $type_id = 0 ) {
		/* translators: %s : date de la prochaine fermeture, « samedi 17 octobre ». */
		$text = 8 === (int) $type_id ? __( 'L\'itinéraire est accessible aujourd\'hui. Prochaine fermeture : %s.', 'pivot-offres' ) : __( 'Aucune fermeture aujourd\'hui. Prochaine fermeture : %s.', 'pivot-offres' );

		/**
		 * Phrase « prochaine fermeture » d'une fiche.
		 *
		 * @param string $text    Phrase, %s recevant la date.
		 * @param int    $type_id Type de l'offre.
		 */
		return (string) apply_filters( 'pivot_closure_open_text', $text, (int) $type_id );
	}

	/**
	 * Types de fermeture présents dans des périodes, dans leur ordre d'apparition.
	 *
	 * @param array $periods Périodes compactes.
	 * @return array
	 */
	public static function kinds( $periods ) {
		$kinds = array();

		foreach ( (array) $periods as $period ) {
			$kinds[ isset( $period[2] ) ? (string) $period[2] : self::OTHER_KIND ] = true;
		}

		return array_keys( $kinds );
	}

	/**
	 * Pastilles d'une vignette : « fermé ce … » et « Impact chasse + ».
	 *
	 * Rien n'est écrit sans fermeture à venir. Le « + » mène au bloc des dates
	 * de la fiche.
	 *
	 * @param array $periods Périodes compactes ($item['cl']).
	 * @param array $args    url (fiche), type (type d'offre), class (classe en plus).
	 * @return string HTML.
	 */
	public static function badges( $periods, $args = array() ) {
		$periods = (array) $periods;

		if ( ! $periods ) {
			return '';
		}

		$args   = wp_parse_args(
			$args,
			array(
				'url'   => '',
				'type'  => 0,
				'class' => '',
			)
		);
		$status = self::status( $periods );

		self::enqueue();

		$html  = '<div class="' . esc_attr( trim( 'pivot-closures ' . $args['class'] ) ) . '"' . self::attributes( $periods ) . '>';
		$html .= '<p class="pivot-closure-today"' . self::when( 'today', $status ) . '>';
		$html .= sprintf( esc_html( self::closed_text( $args['type'] ) ), self::date_tag( 'today', $status ) );
		$html .= '</p>';

		foreach ( self::kinds( $periods ) as $kind ) {
			$label = self::impact_label( $kind );
			$tag   = $args['url'] ? 'a' : 'span';
			$href  = $args['url'] ? ' href="' . esc_url( $args['url'] . '#' . self::ANCHOR ) . '"' : '';
			$title = $args['url'] ? ' title="' . esc_attr__( 'Voir les dates de fermeture', 'pivot-offres' ) . '"' : '';

			$html .= '<' . $tag . ' class="pivot-closure-impact"' . $href . $title . self::when( 'upcoming', $status, $kind ) . '>';
			$html .= esc_html( $label ) . ' <span class="pivot-closure-more" aria-hidden="true">+</span>';
			$html .= '</' . $tag . '>';
		}

		return $html . '</div>';
	}

	/**
	 * Chaînes du rendu JavaScript des vignettes communes.
	 *
	 * @param array $types Types d'offre de la page.
	 * @return array
	 */
	public static function i18n( $types = array() ) {
		$closed = array( '' => self::closed_text() );

		foreach ( (array) $types as $type ) {
			$closed[ (int) $type ] = self::closed_text( (int) $type );
		}

		$impacts = array();

		foreach ( array( 'pchasse', 'travaux', 'innond', 'sanitaire', self::OTHER_KIND ) as $kind ) {
			$impacts[ $kind ] = self::impact_label( $kind );
		}

		return array(
			'closedOn' => $closed,
			'impacts'  => $impacts,
			'more'     => __( 'Voir les dates de fermeture', 'pivot-offres' ),
			'anchor'   => self::ANCHOR,
		);
	}

	/**
	 * Charge le script qui tient l'état à la date du visiteur.
	 *
	 * Appelée par les pages du plugin et par chaque rendu de pastilles, pour
	 * les vignettes insérées ailleurs (shortcode, offres voisines). Sans effet
	 * hors d'une page publique : pendant la construction d'un index, rien
	 * n'est à charger.
	 */
	public static function enqueue() {
		if ( is_admin() || ( ! did_action( 'wp_enqueue_scripts' ) && ! doing_action( 'wp_enqueue_scripts' ) ) ) {
			return;
		}

		wp_enqueue_script( 'pivot-closures', PIVOT_URL . 'assets/js/pivot-closures.js', array(), PIVOT_VERSION, true );
	}

	/* ------------------------------------------------------------- outils */

	/**
	 * Réunit les périodes qui se chevauchent ou se touchent.
	 *
	 * @param array $periods Liste de array( début, fin ).
	 * @return array
	 */
	private static function merge( $periods ) {
		sort( $periods );

		$out = array();

		foreach ( $periods as $period ) {
			$last = count( $out ) - 1;

			if ( $last >= 0 && $period[0] <= self::next_day( $out[ $last ][1] ) ) {
				$out[ $last ][1] = max( $out[ $last ][1], $period[1] );
				continue;
			}

			$out[] = array( (int) $period[0], (int) $period[1] );
		}

		return $out;
	}

	/**
	 * Lendemain d'une date AAAAMMJJ.
	 *
	 * @param int $date Date.
	 * @return int
	 */
	private static function next_day( $date ) {
		$time = DateTime::createFromFormat( '!Ymd', sprintf( '%08d', (int) $date ) );

		return $time ? (int) $time->modify( '+1 day' )->format( 'Ymd' ) : (int) $date + 1;
	}

	/**
	 * Ordre des périodes : début, puis fin, puis type.
	 *
	 * @param array $a Période.
	 * @param array $b Période.
	 * @return int
	 */
	private static function compare( $a, $b ) {
		return $a <=> $b;
	}
}
