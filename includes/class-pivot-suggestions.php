<?php
/**
 * Suggestions de critères de recherche.
 *
 * Plutôt que de demander à l'administrateur de connaître le modèle de données,
 * on lit un échantillon d'offres de sa requête et on en déduit les critères qui
 * ont un sens : ceux dont les valeurs sont assez nombreuses pour filtrer, assez
 * peu pour tenir dans une liste, et assez répandues pour concerner les offres.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Suggestions {

	const GROUP = 'build';

	/** Nombre d'offres examinées. Au-delà, la précision ne gagne plus rien. */
	const SAMPLE = 60;

	/** Au-delà de ce nombre de valeurs distinctes, un filtre devient inutilisable. */
	const MAX_VALUES = 80;

	/**
	 * Champs à ne jamais proposer : identifiants, coordonnées, textes libres.
	 *
	 * @return array
	 */
	private static function ignored_urns() {
		$urns = array(
			'urn:fld:url', 'urn:fld:copyr', 'urn:fld:nomofr', 'urn:fld:codecgt',
			'urn:fld:phone1', 'urn:fld:phone2', 'urn:fld:gsm', 'urn:fld:fax',
			'urn:fld:mail1', 'urn:fld:mail2', 'urn:fld:email',
			'urn:fld:web1', 'urn:fld:web2', 'urn:fld:latitude', 'urn:fld:longitude',
			// Numérique, mais c'est l'identifiant du type d'offre, pas une mesure.
			'urn:fld:typeofr',
		);

		/**
		 * Permet d'exclure d'autres champs des suggestions.
		 *
		 * @param array $urns Urns ignorées.
		 */
		return apply_filters( 'pivot_suggestion_ignored_urns', $urns );
	}

	/**
	 * Analyse la requête d'une page et propose des critères.
	 *
	 * @param array $listing Configuration de la page.
	 * @param bool  $force   Ignorer le cache.
	 * @return array|WP_Error
	 */
	public static function analyse( $listing, $force = false ) {
		$listing_id = pivot_get( $listing, 'id', '' );
		// Le suffixe suit le format des suggestions : une analyse mise en
		// cache avant les critères numériques n'en proposerait aucun.
		$key        = 'suggestions|' . $listing_id . '|' . pivot_get( $listing, 'query_code', '' ) . '|2';

		if ( ! $force ) {
			$cached = Pivot_Cache::get( self::GROUP, $key );

			if ( null !== $cached ) {
				return $cached;
			}
		}

		// L'échantillon est toujours demandé en mode complet : sans les specs,
		// il n'y aurait rien à analyser, même si la page est réglée sur résumé.
		$probe            = $listing;
		$probe['content'] = 2;

		$page = Pivot_Repository::query_first_page( $probe, self::SAMPLE );

		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$offers = (array) pivot_get( $page, 'offers', array() );
		$total  = count( $offers );

		if ( ! $total ) {
			return new WP_Error(
				'pivot_empty_sample',
				__( 'Cette requête ne renvoie aucune offre : impossible d\'en déduire des critères.', 'pivot-offres' )
			);
		}

		$stats = array();

		foreach ( $offers as $offer ) {
			self::collect_address( $offer, $stats );
			self::collect_specs( $offer, $stats );
		}

		$result = array(
			'sample'      => $total,
			'count'       => (int) pivot_get( $page, 'count', $total ),
			'generated'   => time(),
			'suggestions' => self::rank( $stats, $total ),
		);

		Pivot_Cache::set( self::GROUP, $key, $result, (int) pivot_settings( 'ttl_index', 6 * HOUR_IN_SECONDS ) );

		return $result;
	}

	/**
	 * Relève les données d'adresse, disponibles sans configuration.
	 *
	 * @param array $offer Offre normalisée.
	 * @param array $stats Accumulateur.
	 */
	private static function collect_address( $offer, &$stats ) {
		$sources = array(
			'type'     => array(
				'label'  => __( 'Type d\'offre', 'pivot-offres' ),
				'labels' => (array) pivot_get( $offer, 'type_labels', array() ),
			),
			'locality' => array(
				'label'  => __( 'Localité', 'pivot-offres' ),
				'labels' => (array) pivot_get( $offer, 'address.locality_labels', array() ),
			),
			'city'     => array(
				'label'  => __( 'Commune', 'pivot-offres' ),
				'labels' => (array) pivot_get( $offer, 'address.city_labels', array() ),
			),
			'province' => array(
				'label'  => __( 'Province', 'pivot-offres' ),
				'labels' => (array) pivot_get( $offer, 'address.province_labels', array() ),
			),
			'zip'      => array(
				'label' => __( 'Code postal', 'pivot-offres' ),
				'value' => pivot_get( $offer, 'address.zip', '' ),
			),
		);

		foreach ( $sources as $source => $data ) {
			$value = isset( $data['value'] )
				? $data['value']
				: Pivot_I18n::pick( $data['labels'], 'fr', '' );

			if ( ! $value ) {
				continue;
			}

			self::add( $stats, $source, array(
				'source'     => $source,
				'urn'        => '',
				'label'      => $data['label'],
				'field_type' => 'Choice',
			), $value );
		}
	}

	/**
	 * Relève les champs PIVOT d'une offre.
	 *
	 * @param array $offer Offre normalisée.
	 * @param array $stats Accumulateur.
	 */
	private static function collect_specs( $offer, &$stats ) {
		$ignored = self::ignored_urns();
		$type_id = (int) pivot_get( $offer, 'type', 0 );

		foreach ( (array) pivot_get( $offer, 'specs', array() ) as $spec ) {
			$urn = pivot_get( $spec, 'urn', '' );

			if ( ! $urn || in_array( $urn, $ignored, true ) ) {
				continue;
			}

			// Les descriptifs et les champs à suffixe de langue ne filtrent rien.
			if ( false !== strpos( $urn, 'desc' ) || preg_match( '/:(fr|nl|en|de)$/', $urn ) ) {
				continue;
			}

			if ( Pivot_Fields::is_excluded( $urn, $spec ) ) {
				continue;
			}

			$field_type = (string) pivot_get( $spec, 'type', '' );
			$value      = pivot_get( $spec, 'value' );
			$label      = Pivot_I18n::pick( pivot_get( $spec, 'value_labels', array() ), 'fr', '' );

			if ( 'Boolean' === $field_type ) {
				if ( ! in_array( strtolower( (string) $value ), array( 'true', '1', 'oui' ), true ) ) {
					continue;
				}
				$value = __( 'Oui', 'pivot-offres' );
			} else {
				$value = $label ? $label : $value;

				if ( ! is_string( $value ) || '' === trim( $value ) ) {
					continue;
				}

				$value = trim( $value );

				// Un texte long est une description, pas une valeur de filtre.
				if ( mb_strlen( $value ) > 60 ) {
					continue;
				}
			}

			$name = Pivot_I18n::pick( pivot_get( $spec, 'labels', array() ), 'fr', '' );

			if ( ! $name ) {
				$name = Pivot_Thesaurus::label( $urn, $type_id, 'fr' );
			}

			if ( ! $name ) {
				$name = $urn;
			}

			self::add( $stats, 'spec:' . $urn, array(
				'source'     => 'spec',
				'urn'        => $urn,
				'label'      => $name,
				'field_type' => $field_type,
			), $value );
		}
	}

	/**
	 * Enregistre une valeur observée.
	 *
	 * @param array  $stats Accumulateur.
	 * @param string $key   Clé interne.
	 * @param array  $meta  Description du champ.
	 * @param string $value Valeur observée.
	 */
	private static function add( &$stats, $key, $meta, $value ) {
		if ( ! isset( $stats[ $key ] ) ) {
			$stats[ $key ] = array_merge(
				$meta,
				array( 'offers' => array(), 'values' => array() )
			);
		}

		if ( ! isset( $stats[ $key ]['values'][ $value ] ) ) {
			$stats[ $key ]['values'][ $value ] = 0;
		}

		$stats[ $key ]['values'][ $value ]++;
	}

	/**
	 * Retient les champs exploitables et les classe par intérêt.
	 *
	 * @param array $stats  Données relevées.
	 * @param int   $sample Taille de l'échantillon.
	 * @return array
	 */
	private static function rank( $stats, $sample ) {
		$out = array();

		foreach ( $stats as $entry ) {
			$values   = $entry['values'];
			$distinct = count( $values );
			$hits     = array_sum( $values );
			$coverage = $sample > 0 ? min( 100, (int) round( ( $hits / $sample ) * 100 ) ) : 0;

			$boolean = 'Boolean' === $entry['field_type'];
			$numeric = in_array( $entry['field_type'], Pivot_Thesaurus::NUMERIC_TYPES, true );
			$bounds  = $numeric ? self::bounds( array_keys( $values ) ) : null;

			// Un nombre qui ne se lit pas comme tel, ou toujours le même, ne
			// se compare à rien.
			if ( $numeric && ( ! $bounds || $bounds[0] === $bounds[1] ) ) {
				continue;
			}

			// Une seule valeur pour tout le monde ne filtre rien ; trop de
			// valeurs distinctes non plus. Un nombre y échappe : il se compare,
			// il ne se choisit pas dans une liste, et cent prix différents font
			// une très bonne jauge.
			if ( ! $boolean && ! $numeric && ( $distinct < 2 || $distinct > self::MAX_VALUES ) ) {
				continue;
			}

			// Presque autant de valeurs distinctes que d'offres concernées :
			// c'est une référence ou un texte libre, pas un critère. Un filtre
			// dont chaque choix ne renvoie qu'une offre ne sert à rien.
			if ( ! $boolean && ! $numeric && $distinct >= 5 && $distinct > $hits * 0.8 ) {
				continue;
			}

			// Un champ présent sur une poignée d'offres n'aide personne.
			if ( $coverage < 10 ) {
				continue;
			}

			// Un booléen vrai partout, ou presque jamais, ne sépare rien.
			if ( $boolean && ( $coverage > 95 || $coverage < 5 ) ) {
				continue;
			}

			arsort( $values );

			$suggestion = array(
				'key'        => self::make_key( $entry ),
				'label'      => $entry['label'],
				'source'     => $entry['source'],
				'urn'        => $entry['urn'],
				'field_type' => $entry['field_type'],
				'control'    => self::control_for( $entry, $distinct ),
				'values'     => $distinct,
				'coverage'   => $coverage,
				'examples'   => $numeric ? array() : array_slice( array_keys( $values ), 0, 3 ),
				'score'      => self::score( $entry, $distinct, $coverage ),
			);

			if ( $numeric ) {
				$suggestion['operator'] = Pivot_Thesaurus::suggested_operator( $entry['field_type'] );
				$suggestion['min']      = $bounds[0];
				$suggestion['max']      = $bounds[1];
			}

			$out[] = $suggestion;
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $out, 0, 16 );
	}

	/**
	 * Intérêt d'un critère : il doit couvrir beaucoup d'offres et offrir un
	 * nombre de choix confortable — quelques valeurs, pas cinquante.
	 *
	 * @param array $entry    Champ.
	 * @param int   $distinct Valeurs distinctes.
	 * @param int   $coverage Couverture en pourcentage.
	 * @return int
	 */
	private static function score( $entry, $distinct, $coverage ) {
		$score = $coverage;

		// Le nombre de valeurs n'a pas de sens pour une jauge : un critère
		// numérique vaut par sa couverture, et passe derrière les bons choix.
		if ( in_array( $entry['field_type'], Pivot_Thesaurus::NUMERIC_TYPES, true ) ) {
			return (int) $score + 30;
		}

		if ( $distinct >= 2 && $distinct <= 12 ) {
			$score += 40;
		} elseif ( $distinct <= 30 ) {
			$score += 15;
		}

		// Les données d'adresse fonctionnent sans réglage et parlent à tout le
		// monde : elles passent devant.
		if ( 'spec' !== $entry['source'] ) {
			$score += 25;
		}

		if ( in_array( $entry['field_type'], array( 'Choice', 'MultiChoice', 'Boolean' ), true ) ) {
			$score += 20;
		}

		return (int) $score;
	}

	/**
	 * Contrôle le mieux adapté.
	 *
	 * @param array $entry    Champ.
	 * @param int   $distinct Valeurs distinctes.
	 * @return string
	 */
	private static function control_for( $entry, $distinct ) {
		if ( 'Boolean' === $entry['field_type'] ) {
			return 'toggle';
		}

		if ( 'MultiChoice' === $entry['field_type'] ) {
			return 'multiselect';
		}

		if ( in_array( $entry['field_type'], Pivot_Thesaurus::NUMERIC_TYPES, true ) ) {
			return 'range';
		}

		// Peu de valeurs : les cases à cocher se lisent d'un coup d'œil et
		// permettent de cumuler. Au-delà, la liste déroulante reste plus sobre.
		if ( $distinct <= 6 ) {
			return 'multiselect';
		}

		return 'select';
	}

	/**
	 * Plus petite et plus grande des valeurs lisibles comme des nombres.
	 *
	 * @param array $values Valeurs observées.
	 * @return array|null array( min, max ), ou null si aucune n'est un nombre.
	 */
	private static function bounds( $values ) {
		$numbers = array_filter(
			array_map( 'pivot_parse_number', $values ),
			static function ( $number ) {
				return null !== $number;
			}
		);

		return $numbers ? array( min( $numbers ), max( $numbers ) ) : null;
	}

	/**
	 * Clé d'URL proposée.
	 *
	 * @param array $entry Champ.
	 * @return string
	 */
	private static function make_key( $entry ) {
		if ( 'spec' !== $entry['source'] ) {
			return $entry['source'];
		}

		$tail = str_replace( 'urn:fld:', '', $entry['urn'] );

		return sanitize_key( str_replace( ':', '_', $tail ) );
	}
}
