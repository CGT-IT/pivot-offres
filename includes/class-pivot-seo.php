<?php
/**
 * Optimisation des pages générées par le plugin.
 *
 * Titre, méta description, canonique, alternates hreflang, Open Graph et
 * données structurées schema.org. Chaque donnée est testée avant d'être
 * écrite : une offre sans adresse ou sans photo produit un bloc plus court.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Seo {

	/** @var Pivot_Seo|null */
	private static $instance = null;

	/**
	 * @return Pivot_Seo
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'document_title_parts', array( $this, 'title' ) );
		add_action( 'wp_head', array( $this, 'head' ), 2 );
		add_filter( 'wpseo_canonical', array( $this, 'yoast_canonical' ) );
		// Pas de filtre sur language_attributes : l'attribut lang de la balise
		// html est l'affaire de l'extension de traduction, pas la nôtre.
	}

	/**
	 * Contexte courant.
	 *
	 * @return array|null
	 */
	private function context() {
		return Pivot_Rewrites::instance()->context();
	}

	/**
	 * Titre de la page.
	 *
	 * @param array $parts Composantes du titre.
	 * @return array
	 */
	public function title( $parts ) {
		$context = $this->context();

		if ( ! $context ) {
			return $parts;
		}

		$lang = Pivot_I18n::current();

		if ( 'listing' === $context['kind'] ) {
			$parts['title'] = Pivot_Listings::seo_title( $context['listing'], $lang );

			$page = (int) pivot_get( $context, 'page', 1 );

			if ( $page > 1 ) {
				$parts['title'] .= sprintf(
					/* translators: %d : numéro de page. */
					__( ' – page %d', 'pivot-offres' ),
					$page
				);
			}
		}

		if ( 'detail' === $context['kind'] ) {
			$templates = Pivot_Templates::instance();
			$offer     = $templates->current_offer();
			$name      = $offer ? Pivot_Templates::offer_name( $offer, $lang ) : '';

			if ( $name ) {
				$parts['title'] = $name;
				$locality       = $templates->offer_locality( $offer, $lang );

				if ( $locality ) {
					$parts['title'] .= ' – ' . $locality;
				}
			}
		}

		return $parts;
	}

	/**
	 * Balises injectées dans le head.
	 */
	public function head() {
		$context = $this->context();

		if ( ! $context ) {
			return;
		}

		echo "\n<!-- PIVOT Offres -->\n";

		if ( 'listing' === $context['kind'] ) {
			$this->listing_head( $context );
		} elseif ( 'detail' === $context['kind'] ) {
			$this->detail_head();
		}

		$this->alternates();
	}

	/**
	 * Liens alternates par langue.
	 */
	private function alternates() {
		if ( ! Pivot_I18n::is_multilingual() || ! pivot_settings( 'hreflang', 1 ) ) {
			return;
		}

		$alternates = Pivot_Templates::instance()->alternate_urls();

		if ( count( $alternates ) < 2 ) {
			return;
		}

		foreach ( $alternates as $lang => $url ) {
			printf(
				'<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n",
				esc_attr( Pivot_I18n::hreflang( $lang ) ),
				esc_url( $url )
			);
		}

		$default = Pivot_I18n::default_lang();

		if ( isset( $alternates[ $default ] ) ) {
			printf(
				'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
				esc_url( $alternates[ $default ] )
			);
		}
	}

	/**
	 * Head d'une page de listing.
	 *
	 * @param array $context Contexte.
	 */
	private function listing_head( $context ) {
		$listing     = $context['listing'];
		$lang        = Pivot_I18n::current();
		$page        = max( 1, (int) pivot_get( $context, 'page', 1 ) );
		$base_url    = Pivot_Listings::url( $listing, $lang );
		$description = Pivot_Listings::seo_description( $listing, $lang );

		if ( $description ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
		}

		$canonical = $page > 1 ? trailingslashit( $base_url ) . 'page/' . $page . '/' : $base_url;

		printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $canonical ) );
		printf( '<meta property="og:type" content="website" />' . "\n" );
		printf( '<meta property="og:locale" content="%s" />' . "\n", esc_attr( Pivot_I18n::locale( $lang ) ) );
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( Pivot_Listings::title( $listing, $lang ) ) );
		printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $canonical ) );

		if ( $description ) {
			printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $description ) );
		}

		$index = Pivot_Index_Builder::read( pivot_get( $listing, 'id', '' ), $lang );
		$total = (int) pivot_get( $index, 'count', 0 );
		$per   = max( 1, (int) pivot_get( $listing, 'per_page', 12 ) );
		$pages = $total ? (int) ceil( $total / $per ) : 1;

		if ( $page > 1 ) {
			$prev = 2 === $page ? $base_url : trailingslashit( $base_url ) . 'page/' . ( $page - 1 ) . '/';
			printf( '<link rel="prev" href="%s" />' . "\n", esc_url( $prev ) );
		}

		if ( $page < $pages ) {
			printf( '<link rel="next" href="%s" />' . "\n", esc_url( trailingslashit( $base_url ) . 'page/' . ( $page + 1 ) . '/' ) );
		}

		if ( pivot_settings( 'schema_org', 1 ) && $index ) {
			$this->print_json_ld( $this->listing_schema( $listing, $index, $page, $lang ) );
		}
	}

	/**
	 * Head d'une fiche détail.
	 */
	private function detail_head() {
		$templates = Pivot_Templates::instance();
		$offer     = $templates->current_offer();

		if ( ! $offer ) {
			return;
		}

		$lang        = Pivot_I18n::current();
		$code        = pivot_get( $offer, 'code' );
		$name        = Pivot_Templates::offer_name( $offer, $lang );
		$url         = Pivot_Rewrites::detail_url( $code, (int) pivot_get( $offer, 'type', 0 ), $name, $lang );
		$description = Pivot_Templates::offer_description( $offer, 30, $lang );

		if ( $description ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
		}

		printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $url ) );
		printf( '<meta property="og:type" content="place" />' . "\n" );
		printf( '<meta property="og:locale" content="%s" />' . "\n", esc_attr( Pivot_I18n::locale( $lang ) ) );
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $name ) );
		printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $url ) );

		if ( $description ) {
			printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $description ) );
		}

		$image = $templates->offer_image( $offer, 'THB_LW' );

		if ( $image ) {
			printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $image ) );
			printf( '<meta name="twitter:card" content="summary_large_image" />' . "\n" );
		}

		if ( pivot_settings( 'schema_org', 1 ) ) {
			$this->print_json_ld( $this->offer_schema( $offer, $url, $image, $description, $lang ) );
			$this->print_json_ld( $this->breadcrumb_schema( $offer, $url, $name, $lang ) );
		}
	}

	/**
	 * Données structurées d'une page de listing.
	 *
	 * @param array  $listing Configuration.
	 * @param array  $index   Index de la langue.
	 * @param int    $page    Page courante.
	 * @param string $lang    Langue.
	 * @return array
	 */
	private function listing_schema( $listing, $index, $page, $lang ) {
		$per   = max( 1, (int) pivot_get( $listing, 'per_page', 12 ) );
		$items = array_slice( (array) pivot_get( $index, 'items', array() ), ( $page - 1 ) * $per, $per );

		$elements = array();
		$position = ( $page - 1 ) * $per + 1;

		foreach ( $items as $item ) {
			$url = pivot_get( $item, 'u' );

			if ( ! $url ) {
				continue;
			}

			$elements[] = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'url'      => $url,
				'name'     => pivot_get( $item, 'n', '' ),
			);

			$position++;
		}

		if ( ! $elements ) {
			return array();
		}

		return array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => Pivot_Listings::title( $listing, $lang ),
			'numberOfItems'   => (int) pivot_get( $index, 'count', 0 ),
			'itemListElement' => $elements,
		);
	}

	/**
	 * Données structurées d'une offre.
	 *
	 * @param array  $offer       Offre.
	 * @param string $url         URL canonique.
	 * @param string $image       Image principale.
	 * @param string $description Description.
	 * @param string $lang        Langue.
	 * @return array
	 */
	private function offer_schema( $offer, $url, $image, $description, $lang ) {
		$templates = Pivot_Templates::instance();

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => self::schema_type( (int) pivot_get( $offer, 'type', 0 ) ),
			'name'     => Pivot_Templates::offer_name( $offer, $lang ),
			'url'      => $url,
		);

		if ( $description ) {
			$schema['description'] = $description;
		}

		if ( $image ) {
			$schema['image'] = $image;
		}

		$address = array();

		$street = pivot_get( $offer, 'address.street' );

		if ( $street ) {
			$number                   = pivot_get( $offer, 'address.number' );
			$address['streetAddress'] = $number && '-' !== $number ? $street . ' ' . $number : $street;
		}

		$zip = pivot_get( $offer, 'address.zip' );

		if ( $zip ) {
			$address['postalCode'] = $zip;
		}

		$locality = $templates->offer_locality( $offer, $lang );

		if ( $locality ) {
			$address['addressLocality'] = $locality;
		}

		$province = Pivot_I18n::pick( pivot_get( $offer, 'address.province_labels', array() ), $lang, pivot_get( $offer, 'address.province', '' ) );

		if ( $province ) {
			$address['addressRegion'] = $province;
		}

		if ( $address ) {
			$address['@type']          = 'PostalAddress';
			$address['addressCountry'] = 'BE';
			$schema['address']         = $address;
		}

		$lat = pivot_get( $offer, 'address.lat' );
		$lng = pivot_get( $offer, 'address.lng' );

		if ( null !== $lat && null !== $lng ) {
			$schema['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => $lat,
				'longitude' => $lng,
			);
		}

		$phone = $templates->first_spec_value( $offer, array( 'urn:fld:phone1', 'urn:fld:phone2', 'urn:fld:gsm' ) );

		if ( $phone ) {
			$schema['telephone'] = $phone;
		}

		$email = $templates->first_spec_value( $offer, array( 'urn:fld:mail1', 'urn:fld:mail2', 'urn:fld:email' ) );

		if ( $email ) {
			$schema['email'] = $email;
		}

		$website = $templates->first_spec_value( $offer, array( 'urn:fld:web1', 'urn:fld:web2', 'urn:fld:url' ) );

		if ( $website ) {
			$schema['sameAs'] = $website;
		}

		/**
		 * Permet d'ajuster le JSON-LD d'une offre.
		 *
		 * @param array  $schema Données structurées.
		 * @param array  $offer  Offre normalisée.
		 * @param string $lang   Langue.
		 */
		return apply_filters( 'pivot_offer_schema', $schema, $offer, $lang );
	}

	/**
	 * Fil d'Ariane structuré.
	 *
	 * @param array  $offer Offre.
	 * @param string $url   URL de l'offre.
	 * @param string $name  Nom de l'offre.
	 * @param string $lang  Langue.
	 * @return array
	 */
	private function breadcrumb_schema( $offer, $url, $name, $lang ) {
		$items = array(
			array(
				'@type'    => 'ListItem',
				'position' => 1,
				'name'     => get_bloginfo( 'name' ),
				'item'     => Pivot_I18n::url( '', $lang ),
			),
		);

		$position = 2;
		$origin   = Pivot_Templates::instance()->origin_listing();

		if ( $origin ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => Pivot_Listings::title( $origin, $lang ),
				'item'     => Pivot_Listings::url( $origin, $lang ),
			);

			$position++;
		}

		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $position,
			'name'     => $name,
			'item'     => $url,
		);

		return array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		);
	}

	/**
	 * Type schema.org correspondant à un type d'offre PIVOT.
	 *
	 * @param int $type_id Identifiant du type.
	 * @return string
	 */
	public static function schema_type( $type_id ) {
		$map = array(
			1   => 'Hotel',
			2   => 'LodgingBusiness',
			3   => 'LodgingBusiness',
			4   => 'Campground',
			5   => 'LodgingBusiness',
			6   => 'Hostel',
			7   => 'Restaurant',
			8   => 'TouristTrip',
			10  => 'Event',
			11  => 'TouristAttraction',
			12  => 'TouristAttraction',
			13  => 'Museum',
			268 => 'MediaObject',
		);

		/**
		 * Permet d'adapter la correspondance type PIVOT / type schema.org.
		 *
		 * @param array $map Correspondances.
		 */
		$map = apply_filters( 'pivot_schema_type_map', $map );

		return isset( $map[ $type_id ] ) ? $map[ $type_id ] : 'TouristAttraction';
	}

	/**
	 * Écrit un bloc JSON-LD.
	 *
	 * @param array $data Données.
	 */
	private function print_json_ld( $data ) {
		if ( ! $data || ! is_array( $data ) ) {
			return;
		}

		// Les données viennent de PIVOT et ne sont pas assainies : un nom d'offre
		// contenant « </script> » sortirait de la balise. Deux garde-fous, parce
		// que le bloc est écrit tel quel dans le document :
		//  - les barres obliques restent échappées (\/), ce que JSON_UNESCAPED_SLASHES
		//    supprimait, si bien que « </script> » ne peut plus s'écrire ;
		//  - JSON_HEX_TAG passe < et > en < / >, ce qui ferme la voie
		//    même si l'échappement des barres obliques venait à changer.
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );

		if ( false === $json ) {
			return;
		}

		echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Aligne la canonique de Yoast sur celle du plugin.
	 *
	 * @param string $canonical Canonique calculée.
	 * @return string
	 */
	public function yoast_canonical( $canonical ) {
		$context = $this->context();

		if ( ! $context ) {
			return $canonical;
		}

		$lang = Pivot_I18n::current();

		if ( 'detail' === $context['kind'] ) {
			$offer = Pivot_Templates::instance()->current_offer();

			if ( $offer ) {
				return Pivot_Rewrites::detail_url(
					pivot_get( $offer, 'code' ),
					(int) pivot_get( $offer, 'type', 0 ),
					Pivot_Templates::offer_name( $offer, $lang ),
					$lang
				);
			}
		}

		if ( 'listing' === $context['kind'] ) {
			return Pivot_Listings::url( $context['listing'], $lang );
		}

		return $canonical;
	}
}
