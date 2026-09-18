<?php
/**
 * Familles de types d'offres.
 *
 * PIVOT distingue une centaine de types. Écrire un gabarit par type serait
 * ingérable ; les regrouper en familles permet d'en écrire quelques-uns.
 *
 * Les familles ne sont pas inventées ici : PIVOT les déclare lui-même dans son
 * thesaurus, service /family, qui associe chaque type à sa famille. C'est la
 * seule source. L'écran Types d'offres permet de corriger le rattachement d'un
 * type qui mérite un traitement à part ; seules ces corrections sont
 * enregistrées, tout le reste continue de suivre PIVOT.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Types {

	const OPTION = 'pivot_types';

	/** @var array|null Familles et rattachements, mémorisés pour la requête. */
	private static $map = null;

	/**
	 * Lit les familles du thesaurus, une seule fois par requête.
	 *
	 * Renvoie deux tableaux : les familles, slug => traductions du libellé, et
	 * le rattachement de chaque type, id => slug. Ce dernier évite de parcourir
	 * toutes les familles à chaque offre pendant la construction d'un index.
	 *
	 * @return array
	 */
	private static function resolve() {
		if ( null !== self::$map ) {
			return self::$map;
		}

		$families = array();
		$types    = array();

		foreach ( Pivot_Thesaurus::families() as $family ) {
			$labels = (array) pivot_get( $family, 'labels', array() );
			$slug   = self::slug( $labels, pivot_get( $family, 'urn', '' ) );

			if ( ! $slug ) {
				continue;
			}

			$families[ $slug ] = $labels;

			foreach ( (array) pivot_get( $family, 'types', array() ) as $type_id ) {
				$types[ (int) $type_id ] = $slug;
			}
		}

		self::$map = array( 'families' => $families, 'types' => $types );

		return self::$map;
	}

	/**
	 * Familles déclarées par PIVOT.
	 *
	 * Les clés sont stables ; les valeurs suivent la langue de lecture.
	 *
	 * @return array slug => nom lisible.
	 */
	public static function families() {
		$map      = self::resolve();
		$families = array();

		foreach ( $map['families'] as $slug => $labels ) {
			$label = Pivot_I18n::pick( $labels );

			$families[ $slug ] = $label ? $label : $slug;
		}

		// Les types qu'aucune famille ne réclame tombent ici, et y restent :
		// leurs fiches passent par le gabarit commun.
		$families['autre'] = __( 'Autre', 'pivot-offres' );

		/**
		 * Permet d'ajouter ou de renommer des familles.
		 *
		 * @param array $families Familles disponibles.
		 */
		return apply_filters( 'pivot_type_families', $families );
	}

	/**
	 * Le thesaurus a-t-il répondu ?
	 *
	 * Sans réponse, aucune famille n'est connue. Le plugin ne les remplace pas
	 * par une liste maison : des slugs inventés ne correspondraient pas aux
	 * gabarits écrits pour les vraies familles, et le rattachement obtenu
	 * serait faux sans que rien ne le signale.
	 *
	 * @return bool
	 */
	public static function families_available() {
		$map = self::resolve();

		return (bool) $map['families'];
	}

	/**
	 * Oublie les familles mémorisées.
	 */
	public static function flush() {
		self::$map = null;
	}

	/**
	 * Slug d'une famille, utilisé dans le nom des gabarits.
	 *
	 * Ce slug entre dans un nom de fichier : il ne peut pas dépendre de la
	 * langue affichée, sinon le gabarit cherché changerait d'une version du
	 * site à l'autre et il faudrait en écrire un par langue. Il dérive donc
	 * toujours du libellé français, langue de référence de PIVOT — et de l'urn
	 * si ce libellé manque, plutôt que d'une autre langue.
	 *
	 * @param array  $labels Traductions du libellé de la famille.
	 * @param string $urn    Urn de repli.
	 * @return string
	 */
	private static function slug( $labels, $urn = '' ) {
		$label = is_array( $labels ) && ! empty( $labels['fr'] ) ? (string) $labels['fr'] : '';
		$slug  = $label ? pivot_slugify( $label, 40 ) : '';

		if ( $slug ) {
			return $slug;
		}

		return $urn ? sanitize_key( str_replace( ':', '-', $urn ) ) : '';
	}

	/**
	 * Corrections enregistrées : type => famille imposée.
	 *
	 * N'y figurent que les types dont la famille a été changée à la main.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Enregistre les corrections.
	 *
	 * @param array $types Configuration.
	 */
	public static function save( $types ) {
		update_option( self::OPTION, $types, false );
	}

	/**
	 * Famille d'un type d'offre.
	 *
	 * @param int $type_id Identifiant du type.
	 * @return string
	 */
	public static function family( $type_id ) {
		$type_id = (int) $type_id;
		$all     = self::all();

		if ( ! empty( $all[ $type_id ]['family'] ) ) {
			return sanitize_key( $all[ $type_id ]['family'] );
		}

		$family = self::from_thesaurus( $type_id );

		/**
		 * Permet de forcer la famille d'un type d'offre.
		 *
		 * @param string $family  Famille retenue.
		 * @param int    $type_id Identifiant du type.
		 */
		return apply_filters( 'pivot_type_family', $family, $type_id );
	}

	/**
	 * Famille d'un type, telle que PIVOT la déclare.
	 *
	 * @param int $type_id Identifiant du type.
	 * @return string Slug, ou « autre » si aucune famille ne réclame ce type.
	 */
	public static function from_thesaurus( $type_id ) {
		$map     = self::resolve();
		$type_id = (int) $type_id;

		return isset( $map['types'][ $type_id ] ) ? $map['types'][ $type_id ] : 'autre';
	}

	/**
	 * La famille de ce type a-t-elle été corrigée à la main ?
	 *
	 * @param int $type_id Identifiant du type.
	 * @return bool
	 */
	public static function is_overridden( $type_id ) {
		$all = self::all();

		return ! empty( $all[ (int) $type_id ]['family'] );
	}

	/**
	 * Champs supplémentaires à embarquer dans les vignettes d'un type.
	 *
	 * Les dates d'un événement, la capacité d'un hébergement : ce dont un
	 * gabarit de vignette a besoin sans pouvoir rappeler PIVOT, puisque la
	 * pagination et la recherche se font dans le navigateur.
	 *
	 * Ce filtre transporte la donnée ; il n'affiche rien. C'est le gabarit de
	 * vignette qui décide de ce qui se voit et sous quelle forme. Les deux
	 * s'écrivent côte à côte, dans le thème :
	 *
	 *     add_filter( 'pivot_card_fields', function ( $fields, $type_id ) {
	 *         if ( 'evenement' === Pivot_Types::family( $type_id ) ) {
	 *             $fields[] = 'urn:obj:date';
	 *             $fields[] = 'urn:fld:lieuevt';
	 *         }
	 *         return $fields;
	 *     }, 10, 2 );
	 *
	 * @param int $type_id Identifiant du type.
	 * @return array Urns.
	 */
	public static function card_fields( $type_id ) {
		/**
		 * Champs supplémentaires embarqués dans les vignettes d'un type.
		 *
		 * @param array $fields  Urns.
		 * @param int   $type_id Identifiant du type.
		 */
		$fields = apply_filters( 'pivot_card_fields', array(), (int) $type_id );

		return array_values( array_filter( (array) $fields ) );
	}

	/**
	 * Types d'offres rencontrés dans les pages de listing.
	 *
	 * @return array id => libellé.
	 */
	public static function present() {
		$ids = array();

		foreach ( Pivot_Listings::all() as $listing ) {
			foreach ( (array) pivot_get( $listing, 'offer_types', array() ) as $id ) {
				$id = (int) $id;

				if ( $id && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
		}

		sort( $ids );

		$out = array();

		foreach ( $ids as $id ) {
			$out[ $id ] = Pivot_Thesaurus::offer_type_label( $id );
		}

		return $out;
	}

	/**
	 * Nom lisible d'une famille.
	 *
	 * @param string $family Identifiant de famille.
	 * @return string
	 */
	public static function family_name( $family ) {
		$families = self::families();

		return isset( $families[ $family ] ) ? $families[ $family ] : $family;
	}
}
