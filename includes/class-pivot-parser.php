<?php
/**
 * Transformation des documents XML PIVOT en tableaux PHP normalisés.
 *
 * PIVOT renvoie les libellés de champs, les libellés de valeurs, les localités
 * et les communes dans les quatre langues. Le parseur les conserve toutes :
 * une offre mise en cache sert donc toutes les langues du site, et la langue
 * n'est choisie qu'au moment de l'affichage.
 *
 * Aucun noeud n'est présupposé : une clé absente est simplement omise, et les
 * gabarits lisent avec pivot_get().
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Parser {

	const NS = 'http://pivot.tourismewallonie.be/files/xsd/pivot/3.1';

	/**
	 * Charge une chaîne XML.
	 *
	 * @param string $xml Document.
	 * @return SimpleXMLElement|WP_Error
	 */
	public static function load( $xml ) {
		if ( ! is_string( $xml ) || '' === trim( $xml ) ) {
			return new WP_Error( 'pivot_empty_xml', __( 'Le service a renvoyé un document vide.', 'pivot-offres' ) );
		}

		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();

		$doc = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOBLANKS );

		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $doc ) {
			$first = $errors ? trim( $errors[0]->message ) : __( 'Document XML illisible.', 'pivot-offres' );
			return new WP_Error( 'pivot_bad_xml', $first );
		}

		return $doc;
	}

	/**
	 * Enfants d'un noeud dans l'espace de noms PIVOT.
	 *
	 * @param SimpleXMLElement $node Noeud.
	 * @param string|null      $name Nom d'enfant recherché.
	 * @return array
	 */
	private static function children( $node, $name = null ) {
		if ( ! $node instanceof SimpleXMLElement ) {
			return array();
		}

		$children = $node->children( self::NS );

		if ( ! $children || ! count( $children ) ) {
			// Certains documents sont servis sans préfixe de namespace.
			$children = $node->children();
		}

		if ( ! $children ) {
			return array();
		}

		$out = array();

		foreach ( $children as $child_name => $child ) {
			if ( null !== $name && $child_name !== $name ) {
				continue;
			}
			$out[] = $child;
		}

		return $out;
	}

	/**
	 * Premier enfant portant ce nom.
	 *
	 * @param SimpleXMLElement $node Noeud.
	 * @param string           $name Nom.
	 * @return SimpleXMLElement|null
	 */
	private static function child( $node, $name ) {
		$list = self::children( $node, $name );
		return $list ? $list[0] : null;
	}

	/**
	 * Valeur texte d'un enfant, ou null.
	 *
	 * @param SimpleXMLElement $node Noeud.
	 * @param string           $name Nom.
	 * @return string|null
	 */
	private static function text( $node, $name ) {
		$child = self::child( $node, $name );

		if ( null === $child ) {
			return null;
		}

		$value = trim( (string) $child );

		return '' === $value ? null : $value;
	}

	/**
	 * Attribut d'un noeud, ou null.
	 *
	 * @param SimpleXMLElement $node Noeud.
	 * @param string           $name Nom d'attribut.
	 * @return string|null
	 */
	private static function attr( $node, $name ) {
		if ( ! $node instanceof SimpleXMLElement ) {
			return null;
		}

		$attributes = $node->attributes();

		if ( isset( $attributes[ $name ] ) ) {
			$value = trim( (string) $attributes[ $name ] );
			return '' === $value ? null : $value;
		}

		return null;
	}

	/**
	 * Série de noeuds TStringML transformée en tableau lang => valeur.
	 *
	 * @param SimpleXMLElement $node Noeud parent.
	 * @param string           $name Nom des enfants (label, localite, ...).
	 * @return array
	 */
	private static function multilang( $node, $name ) {
		$out = array();

		foreach ( self::children( $node, $name ) as $item ) {
			$lang  = self::attr( $item, 'lang' );
			$value = self::text( $item, 'value' );

			if ( null === $value ) {
				$value = trim( (string) $item );
			}

			if ( '' === $value || null === $value ) {
				continue;
			}

			$out[ $lang ? strtolower( substr( $lang, 0, 2 ) ) : 'fr' ] = $value;
		}

		return $out;
	}

	/**
	 * Choisit une traduction (conservé pour compatibilité).
	 *
	 * @param array  $labels Tableau lang => valeur.
	 * @param string $lang   Langue souhaitée.
	 * @return string
	 */
	public static function pick_lang( $labels, $lang = null ) {
		return Pivot_I18n::pick( $labels, $lang );
	}

	/**
	 * Parse un document <offres>.
	 *
	 * @param string $xml Document XML.
	 * @return array|WP_Error
	 */
	public static function parse_offers( $xml ) {
		$doc = self::load( $xml );

		if ( is_wp_error( $doc ) ) {
			return $doc;
		}

		$result = array(
			'count'        => (int) self::attr( $doc, 'count' ),
			'itemsPerPage' => (int) self::attr( $doc, 'itemsPerPage' ),
			'pagesCount'   => (int) self::attr( $doc, 'pagesCount' ),
			'token'        => (string) self::attr( $doc, 'token' ),
			'offers'       => array(),
		);

		foreach ( self::children( $doc, 'offre' ) as $node ) {
			$offer = self::parse_offer_node( $node );
			if ( $offer ) {
				$result['offers'][] = $offer;
			}
		}

		return $result;
	}

	/**
	 * Parse un document contenant une offre unique.
	 *
	 * @param string $xml Document XML.
	 * @return array|WP_Error
	 */
	public static function parse_single_offer( $xml ) {
		$parsed = self::parse_offers( $xml );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( empty( $parsed['offers'] ) ) {
			return new WP_Error( 'pivot_offer_not_found', __( 'Aucune offre dans la réponse du service.', 'pivot-offres' ) );
		}

		return $parsed['offers'][0];
	}

	/**
	 * Normalise un noeud <offre>.
	 *
	 * @param SimpleXMLElement $node  Noeud.
	 * @param int              $depth Profondeur de récursion.
	 * @return array
	 */
	public static function parse_offer_node( $node, $depth = 0 ) {
		$offer = array();

		$code = self::attr( $node, 'codeCgt' );
		if ( $code ) {
			$offer['code'] = $code;
		}

		foreach ( array( 'dateCreation' => 'created', 'dateModification' => 'modified' ) as $source => $target ) {
			$value = self::attr( $node, $source );
			if ( $value ) {
				$offer[ $target ] = $value;
			}
		}

		$operation = self::attr( $node, 'operation' );
		if ( null !== $operation ) {
			$offer['operation'] = (int) $operation;
		}

		// Le noeud nom porte la dénomination française ; les autres langues,
		// quand elles existent, sont des champs spécifiques.
		$name = self::text( $node, 'nom' );
		if ( $name ) {
			$offer['name'] = $name;
		}

		$active = self::text( $node, 'estActive' );
		if ( null !== $active ) {
			$offer['active'] = (int) $active;
		}

		$type_node = self::child( $node, 'typeOffre' );
		if ( $type_node ) {
			$offer['type'] = (int) self::attr( $type_node, 'idTypeOffre' );

			$type_labels = self::multilang( $type_node, 'label' );
			if ( $type_labels ) {
				$offer['type_labels'] = $type_labels;
			}
		}

		foreach ( array( 'adresse1' => 'address', 'adresse2' => 'address2' ) as $source => $target ) {
			$address_node = self::child( $node, $source );
			if ( ! $address_node ) {
				continue;
			}
			$address = self::parse_address( $address_node );
			if ( $address ) {
				$offer[ $target ] = $address;
			}
		}

		$specs = array();
		foreach ( self::children( $node, 'spec' ) as $spec_node ) {
			$spec = self::parse_spec( $spec_node );
			if ( $spec ) {
				$specs[] = $spec;
			}
		}

		if ( $specs ) {
			$offer['specs']  = $specs;
			$offer['by_urn'] = self::index_specs( $specs );
		}

		if ( $depth < 2 ) {
			$relations = array();

			foreach ( self::children( $node, 'relOffre' ) as $rel_node ) {
				$urn = self::attr( $rel_node, 'urn' );
				if ( ! $urn ) {
					continue;
				}

				$child_node = self::child( $rel_node, 'offre' );
				if ( ! $child_node ) {
					continue;
				}

				$linked = self::parse_offer_node( $child_node, $depth + 1 );
				if ( $linked ) {
					$relations[ $urn ][] = $linked;
				}
			}

			if ( $relations ) {
				$offer['relations'] = $relations;

				$media = self::extract_media( $relations );
				if ( $media ) {
					$offer['media'] = $media;
				}
			}
		}

		return $offer;
	}

	/**
	 * Normalise un noeud adresse.
	 *
	 * @param SimpleXMLElement $node Noeud.
	 * @return array
	 */
	private static function parse_address( $node ) {
		$address = array();

		$map = array(
			'rue'        => 'street',
			'numero'     => 'number',
			'boite'      => 'box',
			'cp'         => 'zip',
			'lieuDit'    => 'place',
			'lieuPrecis' => 'place_details',
			'province'   => 'province',
			'pays'       => 'country',
		);

		foreach ( $map as $source => $target ) {
			$value = self::text( $node, $source );
			if ( null !== $value ) {
				$address[ $target ] = $value;
			}
		}

		$id_ins = self::text( $node, 'idIns' );
		if ( null !== $id_ins ) {
			$address['idIns'] = (int) $id_ins;
		}

		// Localité et commune sont traduites par PIVOT.
		foreach ( array( 'localite' => 'locality', 'commune' => 'city' ) as $source => $target ) {
			$labels = self::multilang( $node, $source );
			if ( $labels ) {
				$address[ $target . '_labels' ] = $labels;
			}
		}

		$province_urn = self::child( $node, 'provinceUrn' );
		if ( $province_urn ) {
			$labels = self::multilang( $province_urn, 'label' );
			if ( $labels ) {
				$address['province_labels'] = $labels;
			}
			$urn = self::attr( $province_urn, 'urn' );
			if ( $urn ) {
				$address['province_urn'] = $urn;
			}
		}

		$country_urn = self::child( $node, 'paysUrn' );
		if ( $country_urn ) {
			$labels = self::multilang( $country_urn, 'label' );
			if ( $labels ) {
				$address['country_labels'] = $labels;
			}
			$urn = self::attr( $country_urn, 'urn' );
			if ( $urn ) {
				$address['country_urn'] = $urn;
			}
		}

		foreach ( array( 'latitude' => 'lat', 'longitude' => 'lng', 'altitude' => 'alt' ) as $source => $target ) {
			$value = self::text( $node, $source );

			if ( null === $value ) {
				continue;
			}

			$float = (float) $value;

			// PIVOT renvoie 0.0 pour « non géolocalisé ».
			if ( 'alt' !== $target && abs( $float ) < 0.000001 ) {
				continue;
			}

			$address[ $target ] = $float;
		}

		$organisme = self::child( $node, 'organisme' );
		if ( $organisme ) {
			$label = self::text( $organisme, 'label' );
			if ( $label ) {
				$address['organisation'] = $label;
			}
			$id = self::attr( $organisme, 'idMdt' );
			if ( $id ) {
				$address['organisation_id'] = (int) $id;
			}
		}

		$parc = self::child( $node, 'parcNaturel' );
		if ( $parc ) {
			$label = self::text( $parc, 'label' );
			if ( $label ) {
				$address['natural_park'] = $label;
			}
		}

		return $address;
	}

	/**
	 * Normalise un noeud spec, récursivement pour les champs structurés.
	 *
	 * @param SimpleXMLElement $node Noeud.
	 * @return array
	 */
	private static function parse_spec( $node ) {
		$urn = self::attr( $node, 'urn' );

		if ( ! $urn ) {
			return array();
		}

		$spec = array( 'urn' => $urn );

		$value = self::text( $node, 'value' );
		if ( null !== $value ) {
			$spec['value'] = $value;
		}

		$type = self::text( $node, 'type' );
		if ( $type ) {
			$spec['type'] = $type;
		}

		$order = self::text( $node, 'order' );
		if ( null !== $order ) {
			$spec['order'] = (int) $order;
		}

		foreach ( array( 'urnCat' => 'cat', 'urnSubCat' => 'subcat' ) as $source => $target ) {
			$found = self::text( $node, $source );
			if ( $found ) {
				$spec[ $target ] = $found;
			}
		}

		// Toutes les traductions sont conservées.
		foreach ( array(
			'label'         => 'labels',
			'urnCatLabel'   => 'cat_labels',
			'urnSubCatLabel' => 'subcat_labels',
			'valueLabel'    => 'value_labels',
		) as $source => $target ) {
			$labels = self::multilang( $node, $source );
			if ( $labels ) {
				$spec[ $target ] = $labels;
			}
		}

		$children = array();
		foreach ( self::children( $node, 'spec' ) as $child_node ) {
			$child = self::parse_spec( $child_node );
			if ( $child ) {
				$children[] = $child;
			}
		}

		if ( $children ) {
			$spec['children'] = $children;
			$spec['by_urn']   = self::index_specs( $children );
		}

		return $spec;
	}

	/**
	 * Index urn => liste de specs : une urn peut apparaître plusieurs fois.
	 *
	 * @param array $specs Liste de specs.
	 * @return array
	 */
	private static function index_specs( $specs ) {
		$index = array();

		foreach ( $specs as $spec ) {
			if ( isset( $spec['urn'] ) ) {
				$index[ $spec['urn'] ][] = $spec;
			}
		}

		return $index;
	}

	/**
	 * Extrait les médias des relations d'offre.
	 *
	 * @param array $relations Relations normalisées.
	 * @return array
	 */
	private static function extract_media( $relations ) {
		$media = array();

		$buckets = array(
			'urn:lnk:media:defaut'  => 'default',
			'urn:link:media:defaut' => 'default',
			'urn:lnk:media:autre'   => 'others',
			'urn:link:media:autre'  => 'others',
		);

		foreach ( $buckets as $urn => $bucket ) {
			if ( empty( $relations[ $urn ] ) ) {
				continue;
			}

			foreach ( $relations[ $urn ] as $linked ) {
				$item = self::media_from_offer( $linked );

				if ( ! $item ) {
					continue;
				}

				if ( 'default' === $bucket && empty( $media['default'] ) ) {
					$media['default'] = $item;
				} else {
					$media['others'][] = $item;
				}
			}
		}

		return $media;
	}

	/**
	 * Construit une entrée média à partir d'une offre de type 268.
	 *
	 * @param array $offer Offre média normalisée.
	 * @return array
	 */
	private static function media_from_offer( $offer ) {
		$code = pivot_get( $offer, 'code' );

		if ( ! $code ) {
			return array();
		}

		$item = array( 'code' => $code );

		$name = pivot_get( $offer, 'name' );
		if ( $name ) {
			$item['title'] = $name;
		}

		foreach ( array(
			'urn:fld:url'    => 'url',
			'urn:fld:copyr'  => 'copyright',
			'urn:fld:typmed' => 'media_type',
		) as $urn => $key ) {
			$value = pivot_get( $offer, array( 'by_urn', $urn, 0, 'value' ) );
			if ( $value ) {
				$item[ $key ] = $value;
			}
		}

		// Intitulés traduits du média, quand ils sont exposés en champ
		// spécifique : l'urn est alors préfixée par la langue.
		foreach ( Pivot_I18n::SUPPORTED as $lang ) {
			$urn = 'fr' === $lang ? 'urn:fld:nommed' : $lang . ':urn:fld:nommed';

			$translated = pivot_get( $offer, array( 'by_urn', $urn, 0, 'value' ) );
			if ( $translated ) {
				$item['titles'][ $lang ] = $translated;
			}
		}

		return $item;
	}

	/**
	 * Parse un document <thesaurus> contenant des noeuds spec.
	 *
	 * @param string $xml Document.
	 * @return array|WP_Error Table à plat urn => définition.
	 */
	public static function parse_thesaurus_specs( $xml ) {
		$doc = self::load( $xml );

		if ( is_wp_error( $doc ) ) {
			return $doc;
		}

		$flat = array();

		foreach ( self::children( $doc, 'spec' ) as $node ) {
			self::flatten_thesaurus_spec( $node, $flat, null, null );
		}

		return $flat;
	}

	/**
	 * Aplatit récursivement la structure logique d'un type d'offre.
	 *
	 * @param SimpleXMLElement $node   Noeud.
	 * @param array            $flat   Accumulateur.
	 * @param string|null      $cat    Catégorie courante.
	 * @param string|null      $subcat Sous-catégorie courante.
	 */
	private static function flatten_thesaurus_spec( $node, &$flat, $cat, $subcat ) {
		$urn = self::attr( $node, 'urn' );

		if ( ! $urn ) {
			return;
		}

		$entry = array( 'urn' => $urn );

		$labels = self::multilang( $node, 'label' );
		if ( $labels ) {
			$entry['labels'] = $labels;
		}

		$abstract = self::multilang( $node, 'abstract' );
		if ( $abstract ) {
			$entry['abstracts'] = $abstract;
		}

		$type = self::text( $node, 'type' );
		if ( $type ) {
			$entry['type'] = $type;
		}

		$order = self::attr( $node, 'order' );
		if ( null !== $order ) {
			$entry['order'] = (int) $order;
		}

		// Les champs dynamiques sont les filtres de catégorisation propres à
		// chaque opérateur : ils servent au marquage, pas à l'affichage.
		if ( 'true' === self::text( $node, 'dynamic' ) ) {
			$entry['dynamic'] = true;
		}

		$user_global = self::attr( $node, 'userGlobalId' );
		if ( null !== $user_global ) {
			$entry['user_global'] = $user_global;
		}

		// PIVOT pose l'attribut sur presque chaque noeud, le plus souvent à
		// "false" : c'est sa valeur qui compte, pas sa présence.
		if ( 'true' === strtolower( (string) self::attr( $node, 'deprecated' ) ) ) {
			$entry['deprecated'] = true;
		}

		foreach ( self::children( $node, 'picto' ) as $picto ) {
			$modifier = self::attr( $picto, 'modifier' );
			$url      = self::text( $picto, 'url' );
			if ( $url ) {
				$entry['picto'][ $modifier ? $modifier : 'default' ] = $url;
			}
		}

		if ( $cat ) {
			$entry['cat'] = $cat;
		}
		if ( $subcat ) {
			$entry['subcat'] = $subcat;
		}

		$flat[ $urn ] = $entry;

		$next_cat    = $cat;
		$next_subcat = $subcat;

		if ( 0 === strpos( $urn, 'urn:cat:' ) ) {
			if ( null === $cat ) {
				$next_cat = $urn;
			} elseif ( null === $subcat ) {
				$next_subcat = $urn;
			}
		}

		foreach ( self::children( $node, 'spec' ) as $child ) {
			self::flatten_thesaurus_spec( $child, $flat, $next_cat, $next_subcat );
		}
	}

	/**
	 * Parse la liste des familles de types d'offres (thesaurus/family).
	 *
	 * Chaque famille porte son urn, ses libellés traduits et les types qui la
	 * composent.
	 *
	 * @param string $xml Document.
	 * @return array|WP_Error urn de famille => définition.
	 */
	public static function parse_families( $xml ) {
		$doc = self::load( $xml );

		if ( is_wp_error( $doc ) ) {
			return $doc;
		}

		$families = array();

		foreach ( self::children( $doc, 'spec' ) as $node ) {
			$urn = self::attr( $node, 'urn' );

			if ( ! $urn || 0 !== strpos( $urn, 'urn:fam:' ) ) {
				continue;
			}

			$entry = array( 'urn' => $urn, 'types' => array() );

			$labels = self::multilang( $node, 'label' );
			if ( $labels ) {
				$entry['labels'] = $labels;
			}

			foreach ( self::children( $node, 'spec' ) as $child ) {
				$child_urn = self::attr( $child, 'urn' );

				if ( $child_urn && preg_match( '/^urn:typ:(\d+)$/', $child_urn, $matches ) ) {
					$entry['types'][] = (int) $matches[1];
				}
			}

			$families[ $urn ] = $entry;
		}

		return $families;
	}

	/**
	 * Parse la liste des localités (thesaurus/tins).
	 *
	 * @param string $xml Document.
	 * @return array|WP_Error
	 */
	public static function parse_tins( $xml ) {
		$doc = self::load( $xml );

		if ( is_wp_error( $doc ) ) {
			return $doc;
		}

		$out = array();

		foreach ( self::children( $doc, 'tins' ) as $node ) {
			$id = self::attr( $node, 'idIns' );

			if ( ! $id ) {
				continue;
			}

			$entry = array( 'id' => (int) $id );

			$zip = self::text( $node, 'cp' );
			if ( $zip ) {
				$entry['zip'] = $zip;
			}

			foreach ( array( 'localite' => 'locality', 'commune' => 'city' ) as $source => $target ) {
				$labels = self::multilang( $node, $source );
				if ( $labels ) {
					$entry[ $target . '_labels' ] = $labels;
				}
			}

			foreach ( array( 'province' => 'province', 'arrondissement' => 'district' ) as $source => $target ) {
				$value = self::text( $node, $source );
				if ( $value ) {
					$entry[ $target ] = $value;
				}
			}

			foreach ( array( 'latitude' => 'lat', 'longitude' => 'lng' ) as $source => $target ) {
				$value = self::text( $node, $source );
				if ( null !== $value ) {
					$entry[ $target ] = (float) $value;
				}
			}

			$out[ (int) $id ] = $entry;
		}

		return $out;
	}
}
