<?php
/**
 * Lecture et rendu des champs d'une offre.
 *
 * Trois besoins que le reste du plugin partage :
 *
 *  - écarter les champs qui n'ont rien à faire nulle part — filtres de
 *    catégorisation, filtres Cirkwi, champs dépréciés — et, séparément, ceux
 *    qui n'ont rien à faire à l'écran mais restent utiles pour filtrer ;
 *  - reconnaître les champs dont PIVOT préfixe l'urn par une langue :
 *    `nl:urn:fld:descmarket` est la version néerlandaise de
 *    `urn:fld:descmarket`, et non un champ distinct ;
 *  - lire et mettre en forme un champ, y compris structuré : un objet date
 *    porte ses dates et ses heures dans des champs enfants, pas dans sa
 *    propre valeur.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Fields {

	const RULES_OPTION = 'pivot_field_rules';

	/** @var array|null Réglages mémorisés pour la requête. */
	private static $rules = null;

	/**
	 * Réglages d'affichage saisis dans l'administration.
	 *
	 * Deux listes, qui ne contiennent que des **écarts** par rapport aux règles
	 * livrées : `hidden` les champs masqués en plus, `shown` les règles
	 * d'origine désactivées. Un champ auquel personne n'a touché n'y figure
	 * pas, et continue donc de suivre les règles du plugin même si elles
	 * changent à la mise à jour.
	 *
	 * Une entrée en `urn:cat:` vaut pour toute la catégorie ; une entrée en
	 * `urn:fld:` ou `urn:obj:` vaut pour ce seul champ.
	 *
	 * @return array
	 */
	public static function rules() {
		if ( null === self::$rules ) {
			$stored = get_option( self::RULES_OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			self::$rules = array(
				'hidden' => array_values( array_filter( (array) pivot_get( $stored, 'hidden', array() ) ) ),
				'shown'  => array_values( array_filter( (array) pivot_get( $stored, 'shown', array() ) ) ),
			);
		}

		return self::$rules;
	}

	/**
	 * Enregistre les réglages d'affichage.
	 *
	 * @param array $rules Listes `hidden` et `shown`.
	 */
	public static function save_rules( $rules ) {
		self::$rules = null;

		update_option(
			self::RULES_OPTION,
			array(
				'hidden' => array_values( array_unique( (array) pivot_get( $rules, 'hidden', array() ) ) ),
				'shown'  => array_values( array_unique( (array) pivot_get( $rules, 'shown', array() ) ) ),
			),
			false
		);
	}

	/**
	 * Une urn correspond-elle à l'une des entrées données ?
	 *
	 * Les entrées en `urn:cat:` désignent une catégorie entière, et sont donc
	 * comparées à la catégorie et à la sous-catégorie du champ autant qu'à son
	 * urn.
	 *
	 * @param string $urn        Urn du champ, sans préfixe de langue.
	 * @param array  $categories Catégorie et sous-catégorie, sans préfixe.
	 * @param array  $entries    Entrées à confronter.
	 * @return bool
	 */
	private static function matches( $urn, $categories, $entries ) {
		foreach ( (array) $entries as $entry ) {
			$entry = trim( (string) $entry );

			if ( '' === $entry ) {
				continue;
			}

			if ( 0 === strcasecmp( $urn, $entry ) ) {
				return true;
			}

			// Une catégorie vaut pour tout ce qu'elle contient.
			if ( 0 === stripos( $entry, 'urn:cat:' ) ) {
				if ( self::starts_with( $urn, $entry ) ) {
					return true;
				}

				foreach ( $categories as $category ) {
					if ( self::starts_with( $category, $entry ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Langue portée par l'urn, quand PIVOT la préfixe.
	 *
	 * La plupart des champs portent leurs traductions dans le thesaurus, sous
	 * une urn unique. Quelques-uns — les descriptifs, la dénomination — ont au
	 * contraire une urn par langue, préfixée : `nl:urn:fld:descmarket`. La
	 * version française n'est en général pas préfixée.
	 *
	 * @param string $urn Urn du champ.
	 * @return string Code de langue, ou chaîne vide.
	 */
	public static function urn_lang( $urn ) {
		if ( preg_match( '/^([a-z]{2}):(urn:.+)$/i', (string) $urn, $matches ) ) {
			$lang = strtolower( $matches[1] );

			if ( in_array( $lang, Pivot_I18n::CONTENT_LANGS, true ) ) {
				return $lang;
			}
		}

		return '';
	}

	/**
	 * Urn débarrassée de son préfixe de langue.
	 *
	 * C'est la forme sous laquelle une urn est comparée : listes d'exclusion,
	 * champs masqués, gabarits. Une règle écrite une fois vaut pour les quatre
	 * langues.
	 *
	 * @param string $urn Urn du champ.
	 * @return string
	 */
	public static function base_urn( $urn ) {
		$lang = self::urn_lang( $urn );

		return $lang ? substr( (string) $urn, strlen( $lang ) + 1 ) : (string) $urn;
	}

	/**
	 * Urn d'un champ dans une langue donnée.
	 *
	 * @param string $urn  Urn, préfixée ou non.
	 * @param string $lang Langue voulue.
	 * @return string
	 */
	public static function localized_urn( $urn, $lang ) {
		$base = self::base_urn( $urn );

		return $lang ? $lang . ':' . $base : $base;
	}

	/**
	 * Urns à essayer pour lire un champ dans une langue, par ordre de préférence.
	 *
	 * La variante de la langue demandée d'abord, la forme nue ensuite : elle
	 * porte le français, qui vaut mieux que rien quand la traduction manque.
	 *
	 * @param string      $urn  Urn, préfixée ou non.
	 * @param string|null $lang Langue voulue.
	 * @return array
	 */
	public static function urn_variants( $urn, $lang = null ) {
		$base = self::base_urn( $urn );
		$lang = $lang ? $lang : Pivot_I18n::current();
		$lang = Pivot_I18n::content_lang( $lang );

		$variants = array();

		if ( 'fr' !== $lang ) {
			$variants[] = $lang . ':' . $base;
		}

		$variants[] = $base;

		if ( 'fr' === $lang ) {
			// Quelques exports préfixent aussi le français.
			$variants[] = 'fr:' . $base;
		}

		return $variants;
	}

	/**
	 * Premières occurrences d'un champ dans la meilleure langue disponible.
	 *
	 * @param array       $source Offre ou champ structuré, portant un index by_urn.
	 * @param string      $urn    Urn recherchée.
	 * @param string|null $lang   Langue voulue.
	 * @return array Liste de specs, éventuellement vide.
	 */
	public static function localized_specs( $source, $urn, $lang = null ) {
		foreach ( self::urn_variants( $urn, $lang ) as $candidate ) {
			$specs = (array) pivot_get( $source, array( 'by_urn', $candidate ), array() );

			if ( $specs ) {
				return $specs;
			}
		}

		return array();
	}

	/**
	 * Ce champ doit-il être ignoré partout ?
	 *
	 * Les filtres de catégorisation servent au marquage interne des offres par
	 * chaque opérateur, et les filtres Cirkwi à l'export vers cette plateforme.
	 * Ni les uns ni les autres ne décrivent l'offre.
	 *
	 * Distinct de is_hidden() : ce qui est exclu ici disparaît aussi du
	 * catalogue des critères, donc de tout filtrage possible.
	 *
	 * @param string $urn   Urn du champ.
	 * @param array  $entry Définition, si elle est connue.
	 * @return bool
	 */
	public static function is_excluded( $urn, $entry = array() ) {
		$urn = self::base_urn( $urn );

		if ( '' === $urn ) {
			return true;
		}

		// Un champ dynamique est un filtre de catégorisation propre à un
		// opérateur : le thesaurus le signale lui-même.
		if ( ! empty( $entry['dynamic'] ) || ! empty( $entry['deprecated'] ) ) {
			return true;
		}

		$patterns = array(
			'filtcat',   // filtres de catégorisation
			'cirkwi',    // filtres et champs Cirkwi
			'urn:cat:filtre',
		);

		/**
		 * Fragments d'urn qui excluent un champ de tout affichage.
		 *
		 * @param array $patterns Fragments recherchés dans l'urn.
		 */
		$patterns = apply_filters( 'pivot_excluded_urn_patterns', $patterns );

		foreach ( $patterns as $pattern ) {
			if ( false !== stripos( $urn, $pattern ) ) {
				return true;
			}
		}

		// La catégorie d'appartenance compte autant que l'urn elle-même.
		foreach ( array( 'cat', 'subcat' ) as $key ) {
			$category = (string) pivot_get( $entry, $key, '' );

			if ( ! $category ) {
				continue;
			}

			foreach ( $patterns as $pattern ) {
				if ( false !== stripos( $category, $pattern ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Ce champ doit-il rester hors de l'écran ?
	 *
	 * Différent de is_excluded() : ces champs restent disponibles au filtrage
	 * et continuent d'alimenter le catalogue des critères. Ils ne sont
	 * simplement pas montrés au visiteur — identifiants internes, données de
	 * gestion, champs déjà affichés ailleurs sur la fiche.
	 *
	 * @param string $urn   Urn du champ.
	 * @param array  $entry Définition ou spec, si elle est connue.
	 * @return bool
	 */
	public static function is_hidden( $urn, $entry = array() ) {
		return self::evaluate_hidden( $urn, $entry, self::rules() );
	}

	/**
	 * Ce champ serait-il masqué avec ce jeu de réglages ?
	 *
	 * L'écran de configuration s'en sert pour évaluer un champ à la lumière des
	 * catégories que le même envoi de formulaire vient de masquer, avant que
	 * quoi que ce soit ne soit enregistré.
	 *
	 * @param string $urn   Urn du champ.
	 * @param array  $entry Définition ou spec.
	 * @param array  $rules Réglages hypothétiques.
	 * @return bool
	 */
	public static function is_hidden_with( $urn, $entry, $rules ) {
		return self::evaluate_hidden(
			$urn,
			$entry,
			array(
				'hidden' => (array) pivot_get( $rules, 'hidden', array() ),
				'shown'  => (array) pivot_get( $rules, 'shown', array() ),
			)
		);
	}

	/**
	 * Ce champ serait-il masqué sans aucun réglage d'administration ?
	 *
	 * L'écran de configuration s'en sert comme référence : il n'enregistre que
	 * les écarts, et a donc besoin de connaître l'état d'origine sans avoir à
	 * effacer les réglages pour le mesurer.
	 *
	 * @param string $urn   Urn du champ.
	 * @param array  $entry Définition ou spec, si elle est connue.
	 * @return bool
	 */
	public static function is_hidden_by_default( $urn, $entry = array() ) {
		return self::evaluate_hidden( $urn, $entry, array( 'hidden' => array(), 'shown' => array() ) );
	}

	/**
	 * Applique les règles de masquage.
	 *
	 * @param string $urn   Urn du champ.
	 * @param array  $entry Définition ou spec.
	 * @param array  $rules Réglages à appliquer.
	 * @return bool
	 */
	private static function evaluate_hidden( $urn, $entry, $rules ) {
		// Ce qui est écarté partout l'est aussi de l'écran.
		if ( self::is_excluded( $urn, $entry ) ) {
			return true;
		}

		$urn        = self::base_urn( $urn );
		$categories = array();

		foreach ( array( 'cat', 'subcat' ) as $key ) {
			$category = (string) pivot_get( $entry, $key, '' );

			if ( $category ) {
				$categories[] = self::base_urn( $category );
			}
		}

		// Un champ remis à l'écran depuis l'administration l'emporte sur toutes
		// les règles de masquage — mais jamais sur is_excluded(), traité plus
		// haut : ce qui est écarté du catalogue n'a pas d'affichage à rétablir.
		if ( self::matches( $urn, $categories, $rules['shown'] ) ) {
			return false;
		}

		// Les champs masqués depuis l'administration rejoignent les règles
		// livrées avant que les filtres passent : un développeur garde le
		// dernier mot sur la configuration.
		$added_urns  = array();
		$added_trees = array();

		foreach ( $rules['hidden'] as $added ) {
			if ( 0 === stripos( $added, 'urn:cat:' ) ) {
				$added_trees[] = $added;
			} else {
				$added_urns[] = self::base_urn( $added );
			}
		}

		/**
		 * Champs masqués au visiteur, désignés par leur urn exacte.
		 *
		 * Le préfixe de langue est retiré avant comparaison : une seule entrée
		 * couvre `urn:fld:nomreco` et ses variantes traduites.
		 *
		 * @param array $urns Urns, sans préfixe de langue.
		 */
		$urns = apply_filters(
			'pivot_hidden_urns',
			array_merge(
				array(
					'urn:fld:nomreco',       // dénomination de reconnaissance, usage administratif
					'urn:fld:statjur',       // statut juridique
					'urn:fld:class:title',   // libellé, valeur et rang du classement : données de gestion
					'urn:fld:class:value',
					'urn:fld:class:superior',
					'urn:fld:idautor',       // identifiant de l'autorité
					'urn:fld:dateech',       // date d'échéance
					'urn:fld:hsu',
					// Déjà affichés ailleurs sur la fiche, ou purement techniques.
					'urn:fld:url',
					'urn:fld:copyr',
					'urn:fld:nomofr',
					'urn:fld:codecgt',
				),
				$added_urns
			)
		);

		if ( in_array( $urn, $urns, true ) ) {
			return true;
		}

		/**
		 * Arbres masqués au visiteur : l'urn, sa catégorie ou sa sous-catégorie
		 * commençant par l'un de ces préfixes suffit.
		 *
		 * @param array $trees Préfixes d'urn.
		 */
		$trees = apply_filters(
			'pivot_hidden_urn_trees',
			array_merge(
				array(
					'urn:cat:ident',           // identification interne, idalt compris
					'urn:cat:accueil:attest',  // attestations
					'urn:cat:filtre',          // filtres de catégorisation, filtautre et filtcat compris
					'urn:cat:cirkwi',
					'urn:cat:link:contact',    // la fiche a son propre bloc de contact
					'urn:cat:qrcode',
					'urn:cat:hist',
					'urn:cat:xml',
					'urn:cat:note',
				),
				$added_trees
			)
		);

		foreach ( $trees as $tree ) {
			if ( self::starts_with( $urn, $tree ) ) {
				return true;
			}

			foreach ( $categories as $category ) {
				if ( self::starts_with( $category, $tree ) ) {
					return true;
				}
			}
		}

		/**
		 * Arbres dont on ne garde qu'une poignée de champs.
		 *
		 * PIVOT expose plusieurs niveaux de descriptif sous une même
		 * sous-catégorie et en sort un automatiquement : tout afficher
		 * donnerait la même offre décrite trois fois.
		 *
		 * @param array $kept Préfixe d'arbre => urns conservées, sans préfixe de langue.
		 */
		$kept = apply_filters(
			'pivot_hidden_tree_exceptions',
			array(
				'urn:cat:descmarket:descmarket' => array( 'urn:fld:descmarket' ),
			)
		);

		foreach ( $kept as $tree => $allowed ) {
			$inside = self::starts_with( $urn, $tree );

			foreach ( $categories as $category ) {
				$inside = $inside || self::starts_with( $category, $tree );
			}

			if ( $inside && ! in_array( $urn, (array) $allowed, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Une urn appartient-elle à un arbre ?
	 *
	 * Vrai pour l'arbre lui-même comme pour ce qu'il contient, mais pas pour
	 * un voisin dont le nom commence pareil : `urn:cat:noteperso` n'est pas
	 * sous `urn:cat:note`.
	 *
	 * @param string $urn  Urn à tester.
	 * @param string $tree Préfixe.
	 * @return bool
	 */
	private static function starts_with( $urn, $tree ) {
		if ( '' === $tree || '' === $urn ) {
			return false;
		}

		return 0 === strcasecmp( $urn, $tree ) || 0 === stripos( $urn, $tree . ':' );
	}

	/**
	 * Toutes les occurrences d'un champ dans une offre, enfants compris.
	 *
	 * Un champ peut se répéter — plusieurs périodes de dates — et se trouver à
	 * l'intérieur d'un objet.
	 *
	 * @param array       $offer Offre normalisée.
	 * @param string      $urn   Urn recherchée, préfixée ou non.
	 * @param string|null $lang  Langue voulue, pour les urns préfixées.
	 * @return array Liste de specs.
	 */
	public static function find_all( $offer, $urn, $lang = null ) {
		$base  = self::base_urn( $urn );
		$found = array();

		// Un champ à urn préfixée existe en plusieurs versions : on les range
		// par langue pour ne rendre que la bonne.
		self::walk(
			(array) pivot_get( $offer, 'specs', array() ),
			static function ( $spec ) use ( $base, &$found ) {
				$spec_urn = (string) pivot_get( $spec, 'urn', '' );

				if ( self::base_urn( $spec_urn ) === $base ) {
					$found[ self::urn_lang( $spec_urn ) ][] = $spec;
				}
			}
		);

		if ( ! $found ) {
			return array();
		}

		// Une seule version, sans préfixe : le cas courant.
		if ( array( '' ) === array_keys( $found ) ) {
			return $found[''];
		}

		foreach ( self::urn_variants( $base, $lang ) as $candidate ) {
			$key = self::urn_lang( $candidate );

			if ( ! empty( $found[ $key ] ) ) {
				return $found[ $key ];
			}
		}

		return reset( $found );
	}

	/**
	 * Parcourt récursivement une liste de champs.
	 *
	 * @param array    $specs    Champs.
	 * @param callable $callback Appelé pour chaque champ.
	 */
	public static function walk( $specs, $callback ) {
		foreach ( (array) $specs as $spec ) {
			call_user_func( $callback, $spec );

			$children = (array) pivot_get( $spec, 'children', array() );

			if ( $children ) {
				self::walk( $children, $callback );
			}
		}
	}

	/**
	 * Valeur affichable d'un champ.
	 *
	 * @param array  $spec Champ.
	 * @param string $lang Langue.
	 * @return string
	 */
	public static function render( $spec, $lang = null ) {
		$urn = (string) pivot_get( $spec, 'urn', '' );

		// Les objets date ont une mise en forme qui leur est propre : une
		// énumération « Date de début : … — Date de fin : … » serait illisible.
		if ( 0 === strpos( $urn, 'urn:obj:date' ) ) {
			$formatted = self::render_dates( $spec, $lang );

			if ( '' !== $formatted ) {
				return $formatted;
			}
		}

		$children = (array) pivot_get( $spec, 'children', array() );

		if ( $children ) {
			$parts = array();

			foreach ( $children as $child ) {
				if ( self::is_hidden( pivot_get( $child, 'urn', '' ), $child ) ) {
					continue;
				}

				$value = self::render( $child, $lang );

				if ( '' === $value ) {
					continue;
				}

				$label   = Pivot_I18n::pick( pivot_get( $child, 'labels', array() ), $lang, '' );
				$parts[] = $label ? $label . ' : ' . $value : $value;
			}

			return implode( ' — ', $parts );
		}

		$label = Pivot_I18n::pick( pivot_get( $spec, 'value_labels', array() ), $lang, '' );

		if ( $label ) {
			return $label;
		}

		$value = pivot_get( $spec, 'value' );

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		$value = trim( $value );

		if ( 'Boolean' === pivot_get( $spec, 'type', '' ) ) {
			return in_array( strtolower( $value ), array( 'true', '1', 'oui' ), true ) ? __( 'Oui', 'pivot-offres' ) : '';
		}

		// Une urn sans libellé n'apporte rien au visiteur.
		if ( 0 === strpos( $value, 'urn:' ) ) {
			return Pivot_Thesaurus::label( $value, 0, $lang );
		}

		return $value;
	}

	/**
	 * Met en forme un objet date.
	 *
	 * PIVOT range dans un même objet la date de début, la date de fin, jusqu'à
	 * deux plages horaires et un texte libre. Affichés bruts, ces champs
	 * donnent une suite d'étiquettes ; assemblés, ils donnent une phrase.
	 *
	 * @param array  $spec Objet date.
	 * @param string $lang Langue.
	 * @return string
	 */
	public static function render_dates( $spec, $lang = null ) {
		$get = static function ( $suffix ) use ( $spec ) {
			$value = pivot_get( $spec, array( 'by_urn', 'urn:fld:date:' . $suffix, 0, 'value' ), '' );

			return is_string( $value ) ? trim( $value ) : '';
		};

		$start  = $get( 'datedeb' );
		$end    = $get( 'datefin' );
		$detail = $get( 'detailouv' );

		if ( ! $start && ! $end ) {
			// Certaines offres ne remplissent que l'intervalle consolidé.
			$range = $get( 'daterange' );

			if ( $range && false !== strpos( $range, '-' ) ) {
				list( $start, $end ) = array_map( 'trim', explode( '-', $range, 2 ) );
			}
		}

		$parts = array();

		if ( $start && $end && $start !== $end ) {
			$parts[] = sprintf(
				/* translators: 1 : date de début, 2 : date de fin. */
				__( 'Du %1$s au %2$s', 'pivot-offres' ),
				$start,
				$end
			);
		} elseif ( $start || $end ) {
			$parts[] = $start ? $start : $end;
		}

		// Les plages horaires, dans l'ordre où PIVOT les enregistre.
		foreach ( array( array( 'houv1', 'hferm1' ), array( 'houv2', 'hferm2' ) ) as $slot ) {
			$open  = $get( $slot[0] );
			$close = $get( $slot[1] );

			if ( $open && $close ) {
				$parts[] = sprintf(
					/* translators: 1 : heure d'ouverture, 2 : heure de fermeture. */
					__( 'de %1$s à %2$s', 'pivot-offres' ),
					$open,
					$close
				);
			} elseif ( $open ) {
				$parts[] = sprintf(
					/* translators: %s : heure d'ouverture. */
					__( 'à partir de %s', 'pivot-offres' ),
					$open
				);
			}
		}

		if ( $detail ) {
			$parts[] = wp_strip_all_tags( $detail, true );
		}

		return implode( ', ', array_filter( $parts ) );
	}
}
