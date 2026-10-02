<?php
/**
 * Optimisation des pages générées par le plugin.
 *
 * Titre, méta description, canonique, alternates hreflang, Open Graph,
 * données structurées schema.org et fichier llms.txt. Chaque donnée est
 * testée avant d'être écrite : une offre sans adresse ou sans photo produit un
 * bloc plus court.
 *
 * Le plan du site XML est dans class-pivot-sitemap.php.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Seo {

	/*
	 * Types schema.org rangés par propriétés admises.
	 *
	 * Une adresse n'est pas une propriété d'Event ni de TouristTrip, un
	 * courriel pas une propriété de TouristAttraction : les écrire quand même
	 * produisait des avertissements dans les outils de test de Google. Un type
	 * absent de ces listes est traité comme un lieu (Place).
	 */

	/** Événements : dates et lieu, pas d'adresse directe. */
	const EVENT_TYPES = array( 'Event', 'Festival', 'ExhibitionEvent', 'FoodEvent', 'MusicEvent', 'SportsEvent', 'TheaterEvent', 'ChildrensEvent' );

	/** Circuits : le point de départ va dans itinerary. */
	const TRIP_TYPES = array( 'TouristTrip', 'Trip' );

	/** Hébergements : lieu et entreprise, avec classement et chambres. */
	const LODGING_TYPES = array( 'LodgingBusiness', 'Hotel', 'BedAndBreakfast', 'Campground', 'Hostel', 'Resort', 'Motel', 'VacationRental' );

	/** Autres entreprises locales : lieu et organisation. */
	const BUSINESS_TYPES = array( 'LocalBusiness', 'Restaurant', 'FoodEstablishment', 'CafeOrCoffeeShop', 'Store', 'TouristInformationCenter', 'TravelAgency' );

	/** Établissements de restauration : ils admettent un classement. */
	const FOOD_TYPES = array( 'Restaurant', 'FoodEstablishment', 'CafeOrCoffeeShop' );

	/** Organisations sans lieu : adresse et contact, pas de coordonnées. */
	const ORGANIZATION_TYPES = array( 'Organization' );

	/** Contenus éditoriaux : ni adresse ni contact. */
	const CREATIVE_TYPES = array( 'Article', 'CreativeWork', 'MediaObject', 'Thing' );

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
		add_filter( 'wpseo_frontend_presenter_classes', array( $this, 'yoast_presenters' ) );
		add_action( 'parse_request', array( $this, 'serve_llms_txt' ), 1 );

		if ( class_exists( 'Pivot_Sitemap' ) ) {
			add_action( 'init', array( 'Pivot_Sitemap', 'register' ) );
		}
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
		$listing  = $context['listing'];
		$lang     = Pivot_I18n::current();
		$page     = max( 1, (int) pivot_get( $context, 'page', 1 ) );
		$base_url = Pivot_Listings::url( $listing, $lang );
		$title    = Pivot_Listings::title( $listing, $lang );

		$index = Pivot_Index_Builder::read( pivot_get( $listing, 'id', '' ), $lang );
		$total = (int) pivot_get( $index, 'count', 0 );
		$per   = max( 1, (int) pivot_get( $listing, 'per_page', 12 ) );
		$pages = $total ? (int) ceil( $total / $per ) : 1;
		$items = array_slice( (array) pivot_get( $index, 'items', array() ), ( $page - 1 ) * $per, $per );

		$description = self::listing_description( $listing, $lang );
		$canonical   = $page > 1 ? trailingslashit( $base_url ) . 'page/' . $page . '/' : $base_url;
		$image       = (string) pivot_get( $listing, 'image', '' );

		// À défaut d'image d'en-tête propre à la page, celle de la première
		// offre affichée : un partage sans image passe inaperçu.
		if ( '' === $image ) {
			foreach ( $items as $item ) {
				if ( pivot_get( $item, 'i' ) ) {
					$image = $item['i'];
					break;
				}
			}
		}

		/**
		 * Image de partage (og:image) d'une page de listing.
		 *
		 * @param string $image   URL retenue : l'image d'en-tête de la page, à
		 *                        défaut celle de sa première offre.
		 * @param array  $listing Configuration de la page.
		 * @param string $lang    Langue.
		 */
		$image = (string) apply_filters( 'pivot_listing_image', $image, $listing, $lang );

		if ( $description ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
		}

		printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $canonical ) );

		$this->open_graph( 'website', $title, $canonical, $description, $image, $lang );

		if ( $page > 1 ) {
			$prev = 2 === $page ? $base_url : trailingslashit( $base_url ) . 'page/' . ( $page - 1 ) . '/';
			printf( '<link rel="prev" href="%s" />' . "\n", esc_url( $prev ) );
		}

		if ( $page < $pages ) {
			printf( '<link rel="next" href="%s" />' . "\n", esc_url( trailingslashit( $base_url ) . 'page/' . ( $page + 1 ) . '/' ) );
		}

		if ( pivot_settings( 'schema_org', 1 ) && $index ) {
			$list = $this->listing_schema( $canonical, $title, $index, $items, $page, $per );

			$this->print_json_ld(
				$this->webpage_schema(
					'CollectionPage',
					$canonical,
					$title,
					$description,
					$lang,
					$list ? $list['@id'] : ''
				)
			);
			$this->print_json_ld( $list );
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
		$url         = Pivot_Rewrites::detail_url( $code, (int) pivot_get( $offer, 'type', 0 ), $lang );
		$description = self::meta_text( Pivot_Templates::offer_description( $offer, 0, $lang ) );
		$image       = $templates->offer_image( $offer, 'THB_LW' );

		if ( $description ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
		}

		printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $url ) );

		$this->open_graph( 'place', $name, $url, $description, $image, $lang );

		if ( pivot_settings( 'schema_org', 1 ) ) {
			$entity     = $this->offer_schema( $offer, $url, $image, $lang );
			$breadcrumb = $this->breadcrumb_schema( $url, $name, $lang );

			$this->print_json_ld(
				$this->webpage_schema(
					'WebPage',
					$url,
					$name,
					$description,
					$lang,
					pivot_get( $entity, '@id', '' ),
					$breadcrumb['@id'],
					(string) pivot_get( $offer, 'modified', '' )
				)
			);
			$this->print_json_ld( $entity );
			$this->print_json_ld( $breadcrumb );
		}
	}

	/**
	 * Balises Open Graph et Twitter Card.
	 *
	 * @param string $type        og:type.
	 * @param string $title       Titre.
	 * @param string $url         URL canonique.
	 * @param string $description Description.
	 * @param string $image       Image, facultative.
	 * @param string $lang        Langue.
	 */
	private function open_graph( $type, $title, $url, $description, $image, $lang ) {
		printf( '<meta property="og:type" content="%s" />' . "\n", esc_attr( $type ) );
		printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
		printf( '<meta property="og:locale" content="%s" />' . "\n", esc_attr( Pivot_I18n::locale( $lang ) ) );
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
		printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $url ) );

		if ( $description ) {
			printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $description ) );
		}

		if ( $image ) {
			printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $image ) );
			printf( '<meta name="twitter:card" content="summary_large_image" />' . "\n" );
		}
	}

	/**
	 * Méta description d'une page de listing.
	 *
	 * La description SEO saisie pour la page, sinon le début de son
	 * introduction : aucune page n'avait de description saisie, et aucune
	 * n'avait donc de méta description.
	 *
	 * @param array  $listing Configuration.
	 * @param string $lang    Langue.
	 * @return string
	 */
	public static function listing_description( $listing, $lang ) {
		$description = trim( (string) Pivot_Listings::seo_description( $listing, $lang ) );

		if ( '' === $description ) {
			// Un shortcode n'a rien à faire dans une méta description.
			$description = pivot_plain_text( strip_shortcodes( Pivot_Listings::intro( $listing, $lang ) ) );
		}

		return self::meta_text( $description );
	}

	/**
	 * Texte ramené à la longueur d'une méta description.
	 *
	 * Au-delà de 160 caractères environ, les moteurs coupent eux-mêmes, souvent
	 * au milieu d'un mot. La coupe se fait ici, sur un espace.
	 *
	 * @param string $text Texte brut.
	 * @param int    $max  Longueur maximale, points de suspension compris.
	 * @return string
	 */
	public static function meta_text( $text, $max = 160 ) {
		$text = pivot_plain_text( $text );

		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}

		$cut = mb_substr( $text, 0, $max - 1 );

		// Coupe en octets : l'espace est un caractère ASCII, la coupe ne tombe
		// jamais au milieu d'une lettre accentuée. mb_strrpos() n'a pas de
		// remplaçant dans WordPress quand mbstring manque.
		$space = strrpos( $cut, ' ' );

		if ( $space && $space > $max / 2 ) {
			$cut = substr( $cut, 0, $space );
		}

		return rtrim( $cut, " \t,;:.–-" ) . '…';
	}

	/**
	 * Page web qui porte l'entité : langue, site, date de mise à jour.
	 *
	 * La langue et la date de modification ne sont pas des propriétés d'un
	 * hôtel ou d'un lieu, mais de la page qui le décrit. Les moteurs et les
	 * agents conversationnels s'en servent pour juger de la fraîcheur d'une
	 * information.
	 *
	 * @param string $type        WebPage ou CollectionPage.
	 * @param string $url         URL canonique.
	 * @param string $name        Nom de la page.
	 * @param string $description Description.
	 * @param string $lang        Langue.
	 * @param string $main        @id de l'entité principale.
	 * @param string $breadcrumb  @id du fil d'Ariane.
	 * @param string $modified    Date de modification (ISO 8601).
	 * @return array
	 */
	private function webpage_schema( $type, $url, $name, $description, $lang, $main = '', $breadcrumb = '', $modified = '' ) {
		$home = Pivot_I18n::url( '', $lang );

		$page = array(
			'@context'   => 'https://schema.org',
			'@type'      => $type,
			'@id'        => $url . '#webpage',
			'url'        => $url,
			'name'       => $name,
			'inLanguage' => Pivot_I18n::hreflang( $lang ),
			'isPartOf'   => array(
				'@type' => 'WebSite',
				'@id'   => trailingslashit( $home ) . '#website',
				'url'   => $home,
				'name'  => get_bloginfo( 'name' ),
			),
		);

		if ( $description ) {
			$page['description'] = $description;
		}

		if ( $main ) {
			$page['mainEntity'] = array( '@id' => $main );
		}

		if ( $breadcrumb ) {
			$page['breadcrumb'] = array( '@id' => $breadcrumb );
		}

		$time = $modified ? strtotime( $modified ) : false;

		if ( $time ) {
			$page['dateModified'] = gmdate( 'c', $time );
		}

		return $page;
	}

	/**
	 * Données structurées d'une page de listing.
	 *
	 * @param string $url   URL canonique de la page.
	 * @param string $title Titre de la page.
	 * @param array  $index Index de la langue.
	 * @param array  $items Entrées de la page courante.
	 * @param int    $page  Page courante.
	 * @param int    $per   Offres par page.
	 * @return array
	 */
	private function listing_schema( $url, $title, $index, $items, $page, $per ) {
		$elements = array();
		$position = ( $page - 1 ) * $per + 1;

		foreach ( $items as $item ) {
			$item_url = pivot_get( $item, 'u' );

			if ( ! $item_url ) {
				continue;
			}

			$element = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'url'      => $item_url,
			);

			$name = pivot_get( $item, 'n', '' );

			if ( $name ) {
				$element['name'] = $name;
			}

			$elements[] = $element;

			$position++;
		}

		if ( ! $elements ) {
			return array();
		}

		return array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'@id'             => $url . '#list',
			'name'            => $title,
			'numberOfItems'   => (int) pivot_get( $index, 'count', 0 ),
			'itemListElement' => $elements,
		);
	}

	/**
	 * Données structurées d'une offre.
	 *
	 * @param array  $offer Offre.
	 * @param string $url   URL canonique.
	 * @param string $image Image principale.
	 * @param string $lang  Langue.
	 * @return array
	 */
	private function offer_schema( $offer, $url, $image, $lang ) {
		$templates = Pivot_Templates::instance();
		$type      = self::schema_type( (int) pivot_get( $offer, 'type', 0 ) );
		$dates     = in_array( $type, self::EVENT_TYPES, true ) ? self::event_dates( $offer ) : array();

		// Un Event sans date de début est rejeté par Google : sans date
		// lisible, l'offre est décrite comme une activité.
		if ( in_array( $type, self::EVENT_TYPES, true ) && ! $dates ) {
			$type = 'TouristAttraction';
		}

		$name   = Pivot_Templates::offer_name( $offer, $lang );
		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => $type,
			'@id'      => $url . '#offer',
			'name'     => $name,
			'url'      => $url,
		);

		if ( 'Article' === $type ) {
			$schema['headline'] = $name;
		}

		// Plus long que la méta description : un agent conversationnel répond
		// à partir de ce texte, pas seulement de son accroche.
		$description = Pivot_Templates::offer_description( $offer, 80, $lang );

		if ( $description ) {
			$schema['description'] = $description;
		}

		if ( $image ) {
			$schema['image'] = $image;
		}

		$same_as = self::same_as( $offer, $lang );

		if ( $same_as ) {
			$schema['sameAs'] = 1 === count( $same_as ) ? $same_as[0] : $same_as;
		}

		$lodging  = in_array( $type, self::LODGING_TYPES, true );
		$business = $lodging || in_array( $type, self::BUSINESS_TYPES, true );
		$org      = in_array( $type, self::ORGANIZATION_TYPES, true );
		$location = $this->location( $offer, $lang );
		$locality = $templates->offer_locality( $offer, $lang );

		if ( in_array( $type, self::EVENT_TYPES, true ) ) {
			$schema                        = array_merge( $schema, $dates );
			$schema['eventStatus']         = 'https://schema.org/EventScheduled';
			$schema['eventAttendanceMode'] = 'https://schema.org/OfflineEventAttendanceMode';
			$schema['location']            = array_merge(
				array(
					'@type' => 'Place',
					'name'  => $locality ? $locality : $name,
				),
				$location
			);
		} elseif ( in_array( $type, self::TRIP_TYPES, true ) ) {
			// Le point de départ du circuit.
			if ( $location ) {
				$schema['itinerary'] = array_merge(
					array(
						'@type' => 'Place',
						'name'  => $locality ? $locality : $name,
					),
					$location
				);
			}
		} elseif ( ! in_array( $type, self::CREATIVE_TYPES, true ) ) {
			if ( $org ) {
				unset( $location['geo'] );
			}

			$schema = array_merge( $schema, $location );

			$phone = $templates->first_spec_value( $offer, array( 'urn:fld:phone1', 'urn:fld:phone2', 'urn:fld:gsm' ) );

			if ( $phone ) {
				$schema['telephone'] = $phone;
			}

			if ( $business || $org ) {
				$email = $templates->first_spec_value( $offer, array( 'urn:fld:mail1', 'urn:fld:mail2', 'urn:fld:email' ) );

				if ( $email && is_email( $email ) ) {
					$schema['email'] = $email;
				}
			}

			$amenities = $org ? array() : self::amenities( $offer, $lang );

			if ( $amenities ) {
				$schema['amenityFeature'] = $amenities;
			}

			if ( $lodging || in_array( $type, self::FOOD_TYPES, true ) ) {
				$rating = self::star_rating( $offer, $lang );

				if ( $rating ) {
					$schema['starRating'] = $rating;
				}
			}

			if ( $lodging ) {
				$rooms = (int) pivot_parse_number( $templates->first_spec_value( $offer, array( 'urn:fld:nbchb' ) ) );

				if ( $rooms > 0 ) {
					$schema['numberOfRooms'] = $rooms;
				}
			}
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
	 * Adresse et coordonnées d'une offre.
	 *
	 * @param array  $offer Offre.
	 * @param string $lang  Langue.
	 * @return array Clés address et geo, chacune seulement si elle est connue.
	 */
	private function location( $offer, $lang ) {
		$out     = array();
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

		$locality = Pivot_Templates::instance()->offer_locality( $offer, $lang );

		if ( $locality ) {
			$address['addressLocality'] = $locality;
		}

		$province = Pivot_I18n::pick( pivot_get( $offer, 'address.province_labels', array() ), $lang, pivot_get( $offer, 'address.province', '' ) );

		if ( $province ) {
			$address['addressRegion'] = $province;
		}

		if ( $address ) {
			$out['address'] = array_merge(
				array( '@type' => 'PostalAddress' ),
				$address,
				array( 'addressCountry' => 'BE' )
			);
		}

		$lat = pivot_get( $offer, 'address.lat' );
		$lng = pivot_get( $offer, 'address.lng' );

		if ( null !== $lat && null !== $lng ) {
			$out['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => round( (float) $lat, 6 ),
				'longitude' => round( (float) $lng, 6 ),
			);
		}

		return $out;
	}

	/**
	 * Site officiel et réseaux sociaux de l'offre.
	 *
	 * Tous les moyens de communication de type URL, sauf les sites de
	 * réservation. Les urns attendues auparavant (urn:fld:web1…) n'existent
	 * pas dans les données : le site web, urn:fld:urlweb, n'était jamais
	 * publié.
	 *
	 * @param array  $offer Offre.
	 * @param string $lang  Langue.
	 * @return array Liste d'URL.
	 */
	private static function same_as( $offer, $lang ) {
		$links = array();

		foreach ( Pivot_Templates::visible_specs( $offer, $lang ) as $spec ) {
			if ( 'urn:cat:moycom' !== pivot_get( $spec, 'cat' ) || 'urn:cat:moycom:sitereservation' === pivot_get( $spec, 'subcat' ) ) {
				continue;
			}

			// URL, URLFacebook, URLInstagram, URLYoutube…
			if ( 0 !== strpos( (string) pivot_get( $spec, 'type', '' ), 'URL' ) ) {
				continue;
			}

			$link = esc_url_raw( trim( (string) pivot_get( $spec, 'value', '' ) ), array( 'http', 'https' ) );

			if ( $link ) {
				$links[ $link ] = $link;
			}
		}

		return array_values( $links );
	}

	/**
	 * Équipements et services, au format LocationFeatureSpecification.
	 *
	 * Les cases cochées de la catégorie équipements, plus l'accès des
	 * personnes à mobilité réduite et des animaux : ce qu'un visiteur — ou un
	 * agent qui cherche pour lui — demande en premier.
	 *
	 * @param array  $offer Offre.
	 * @param string $lang  Langue.
	 * @return array
	 */
	private static function amenities( $offer, $lang ) {
		$type_id = (int) pivot_get( $offer, 'type', 0 );
		$welcome = array( 'urn:fld:pmr', 'urn:fld:animauxacc' );
		$out     = array();
		$seen    = array();

		foreach ( Pivot_Templates::visible_specs( $offer, $lang ) as $spec ) {
			$base  = Pivot_Fields::base_urn( (string) pivot_get( $spec, 'urn', '' ) );
			$value = pivot_get( $spec, 'value' );

			if ( 'Boolean' !== pivot_get( $spec, 'type' ) || ( true !== $value && 'true' !== $value ) ) {
				continue;
			}

			if ( 'urn:cat:eqpsrv' !== pivot_get( $spec, 'cat' ) && ! in_array( $base, $welcome, true ) ) {
				continue;
			}

			$label = Pivot_I18n::pick( pivot_get( $spec, 'labels', array() ), $lang, '' );

			if ( ! $label ) {
				$label = Pivot_Thesaurus::label( $base, $type_id, $lang );
			}

			if ( ! $label || isset( $seen[ $label ] ) ) {
				continue;
			}

			$seen[ $label ] = true;

			$out[] = array(
				'@type' => 'LocationFeatureSpecification',
				'name'  => $label,
				'value' => true,
			);
		}

		return $out;
	}

	/**
	 * Classement officiel : étoiles d'un hôtel, épis d'un gîte…
	 *
	 * Seul un classement qui commence par un chiffre est publié : « 3 étoiles
	 * superior » donne 3, « Échue » ne donne rien.
	 *
	 * @param array  $offer Offre.
	 * @param string $lang  Langue.
	 * @return array
	 */
	private static function star_rating( $offer, $lang ) {
		$specs = Pivot_Fields::find_all( $offer, 'urn:fld:class', $lang );
		$label = $specs ? trim( Pivot_Fields::render( $specs[0], $lang ) ) : '';

		if ( ! preg_match( '/^([1-5])\b/u', $label, $matches ) ) {
			return array();
		}

		return array(
			'@type'       => 'Rating',
			'ratingValue' => (int) $matches[1],
			'name'        => $label,
		);
	}

	/**
	 * Dates d'un événement : la prochaine période, ou la dernière passée.
	 *
	 * Un événement peut compter plusieurs périodes (un objet urn:obj:date
	 * chacune). Google n'en lit qu'une par entité : la plus utile au visiteur
	 * est la prochaine. Les heures d'ouverture de la période, quand PIVOT les
	 * donne, précisent le début et la fin.
	 *
	 * @param array $offer Offre.
	 * @return array startDate et, si elle est connue, endDate ; vide sans date lisible.
	 */
	private static function event_dates( $offer ) {
		$periods = array();

		foreach ( Pivot_Fields::find_all( $offer, 'urn:obj:date' ) as $object ) {
			$get = static function ( $suffix ) use ( $object ) {
				$value = pivot_get( $object, array( 'by_urn', 'urn:fld:date:' . $suffix, 0, 'value' ), '' );

				return is_string( $value ) ? trim( $value ) : '';
			};

			$start = pivot_parse_date( $get( 'datedeb' ) );
			$end   = pivot_parse_date( $get( 'datefin' ) );

			if ( ! $start && ! $end ) {
				// Certaines offres ne remplissent que l'intervalle consolidé.
				$range = $get( 'daterange' );

				if ( false !== strpos( $range, '-' ) ) {
					list( $start, $end ) = array_map( 'pivot_parse_date', array_map( 'trim', explode( '-', $range, 2 ) ) );
				}
			}

			$start = $start ? $start : $end;
			$end   = $end ? $end : $start;

			if ( ! $start ) {
				continue;
			}

			$periods[] = array(
				'start' => min( $start, $end ),
				'end'   => max( $start, $end ),
				'open'  => $get( 'houv1' ),
				'close' => $get( 'hferm1' ),
			);
		}

		if ( ! $periods ) {
			return array();
		}

		usort(
			$periods,
			static function ( $a, $b ) {
				return $a['start'] <=> $b['start'];
			}
		);

		$today  = (int) current_time( 'Ymd' );
		$chosen = end( $periods );

		foreach ( $periods as $period ) {
			if ( $period['end'] >= $today ) {
				$chosen = $period;
				break;
			}
		}

		$dates = array( 'startDate' => self::iso_datetime( $chosen['start'], $chosen['open'] ) );

		if ( $chosen['close'] ) {
			$dates['endDate'] = self::iso_datetime( $chosen['end'], $chosen['close'] );
		} elseif ( $chosen['end'] !== $chosen['start'] ) {
			$dates['endDate'] = pivot_date_iso( $chosen['end'] );
		}

		return $dates;
	}

	/**
	 * Date AAAAMMJJ et heure PIVOT (« 13:00 ») au format ISO 8601.
	 *
	 * @param int    $date Date.
	 * @param string $time Heure, facultative.
	 * @return string 2026-12-29T13:00:00+01:00, ou 2026-12-29 sans heure lisible.
	 */
	private static function iso_datetime( $date, $time ) {
		$day = pivot_date_iso( $date );

		if ( ! preg_match( '/^(\d{1,2})\s*[:hH.]\s*(\d{2})/', (string) $time, $matches ) ) {
			return $day;
		}

		/**
		 * Fuseau horaire des heures publiées par PIVOT.
		 *
		 * Les offres sont wallonnes : leurs heures sont celles de Bruxelles,
		 * quel que soit le fuseau réglé dans WordPress.
		 *
		 * @param string $timezone Identifiant de fuseau.
		 */
		$zone = (string) apply_filters( 'pivot_timezone', 'Europe/Brussels' );

		try {
			$moment = new DateTime( sprintf( '%s %02d:%02d:00', $day, (int) $matches[1], (int) $matches[2] ), new DateTimeZone( $zone ) );
		} catch ( Exception $e ) {
			return $day;
		}

		return $moment->format( 'c' );
	}

	/**
	 * Fil d'Ariane structuré.
	 *
	 * @param string $url  URL de l'offre.
	 * @param string $name Nom de l'offre.
	 * @param string $lang Langue.
	 * @return array
	 */
	private function breadcrumb_schema( $url, $name, $lang ) {
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
			'@id'             => $url . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	/**
	 * Type schema.org correspondant à un type d'offre PIVOT.
	 *
	 * Les identifiants sont ceux du thesaurus typeofr. Un type absent de la
	 * table prend le type de sa famille, puis TouristAttraction.
	 *
	 * @param int $type_id Identifiant du type.
	 * @return string
	 */
	public static function schema_type( $type_id ) {
		$map = array(
			1   => 'Hotel',                    // Hôtel.
			2   => 'LodgingBusiness',          // Gîte.
			3   => 'BedAndBreakfast',          // Chambre d'hôtes.
			4   => 'LodgingBusiness',          // Meublé.
			5   => 'Campground',               // Camping.
			6   => 'Hostel',                   // Budget Holiday.
			7   => 'Resort',                   // Village de vacances.
			8   => 'TouristTrip',              // Itinéraire.
			9   => 'Event',                    // Événement.
			11  => 'TouristAttraction',        // Découverte et Divertissement.
			12  => 'LocalBusiness',            // Guide touristique.
			13  => 'Article',                  // Article.
			14  => 'TouristInformationCenter', // Organisme touristique.
			17  => 'TravelAgency',             // Agence de voyage.
			24  => 'EventVenue',               // Salle.
			25  => 'LodgingBusiness',          // Autre hébergement.
			26  => 'RVPark',                   // Aire pour motor-homes.
			28  => 'Campground',               // Endroit de camp.
			32  => 'Campground',               // Aire de bivouac.
			258 => 'LocalBusiness',            // Producteur.
			259 => 'LocalBusiness',            // Artisan.
			260 => 'Store',                    // Boutique de terroir.
			261 => 'Restaurant',               // Restauration.
			262 => 'CreativeWork',             // Recette.
			263 => 'Organization',             // Structure événementielle.
			267 => 'Thing',                    // Produit de terroir.
			268 => 'MediaObject',              // Média.
			269 => 'TouristAttraction',        // Point d'intérêt.
			270 => 'LodgingBusiness',          // Hébergements.
		);

		/**
		 * Permet d'adapter la correspondance type PIVOT / type schema.org.
		 *
		 * @param array $map Correspondances.
		 */
		$map = apply_filters( 'pivot_schema_type_map', $map );

		if ( isset( $map[ $type_id ] ) ) {
			return $map[ $type_id ];
		}

		$families = array(
			'hebergement'            => 'LodgingBusiness',
			'activites-evenementiel' => 'Event',
			'loisirs-decouvertes'    => 'TouristAttraction',
			'terroir'                => 'LocalBusiness',
			'organismes'             => 'Organization',
		);

		$family = $type_id ? Pivot_Types::family( $type_id ) : '';

		return isset( $families[ $family ] ) ? $families[ $family ] : 'TouristAttraction';
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
	 * Sert /llms.txt : le plan du site à l'usage des agents conversationnels.
	 *
	 * Le format est celui proposé sur llmstxt.org : un titre, un résumé, puis
	 * des listes de liens commentés. Un fichier llms.txt posé à la racine du
	 * site est servi par le serveur web avant WordPress, et l'emporte.
	 *
	 * @param WP $wp Requête courante.
	 */
	public function serve_llms_txt( $wp ) {
		if ( 'llms.txt' !== trim( (string) $wp->request, '/' ) || ! pivot_settings( 'llms_txt', 1 ) ) {
			return;
		}

		// Un site fermé aux moteurs ne s'annonce pas davantage aux agents.
		if ( ! get_option( 'blog_public' ) ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );

		echo $this->llms_txt(); // phpcs:ignore WordPress.Security.EscapeOutput -- texte brut.
		exit;
	}

	/**
	 * Contenu de /llms.txt.
	 *
	 * @return string
	 */
	public function llms_txt() {
		$langs   = Pivot_I18n::is_multilingual() ? Pivot_I18n::languages() : array( Pivot_I18n::default_lang() );
		$names   = array(
			'fr' => 'Français',
			'nl' => 'Nederlands',
			'en' => 'English',
			'de' => 'Deutsch',
		);
		$summary = trim( wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ) );

		if ( '' === $summary ) {
			$summary = __( 'Offres touristiques de Wallonie : hébergements, restaurants, activités, événements et itinéraires, issues de PIVOT, la base de données du tourisme wallon.', 'pivot-offres' );
		}

		$lines = array(
			'# ' . self::llms_escape( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			'',
			'> ' . self::llms_escape( $summary ),
			'',
			__( 'Chaque page ci-dessous liste des offres et mène à leurs fiches détaillées, à l\'adresse /details/CODE&type=TYPE. Les fiches publient des données structurées schema.org (JSON-LD) : adresse, coordonnées GPS, contact, équipements, classement et dates des événements.', 'pivot-offres' ),
		);

		foreach ( $langs as $lang ) {
			$entries = array();

			foreach ( Pivot_Listings::active() as $listing ) {
				$url = Pivot_Listings::url( $listing, $lang );

				if ( ! $url ) {
					continue;
				}

				$description = self::listing_description( $listing, $lang );

				$entries[] = sprintf(
					'- [%1$s](%2$s)%3$s',
					self::llms_escape( Pivot_Listings::title( $listing, $lang ) ),
					esc_url_raw( $url ),
					$description ? ': ' . self::llms_escape( $description ) : ''
				);
			}

			if ( ! $entries ) {
				continue;
			}

			$heading = count( $langs ) > 1
				? ( isset( $names[ $lang ] ) ? $names[ $lang ] : Pivot_I18n::name( $lang ) )
				: __( 'Pages', 'pivot-offres' );

			$lines[] = '';
			$lines[] = '## ' . $heading;
			$lines[] = '';
			$lines   = array_merge( $lines, $entries );
		}

		// « Optional » est le titre que le format réserve aux liens qu'un agent
		// peut ignorer quand la place lui manque.
		$optional = array();
		$sitemap  = function_exists( 'get_sitemap_url' ) ? get_sitemap_url( 'index' ) : false;

		if ( $sitemap ) {
			$optional[] = '- [' . __( 'Plan du site XML', 'pivot-offres' ) . '](' . esc_url_raw( $sitemap ) . ')';
		}

		$default = Pivot_I18n::default_lang();

		foreach ( Pivot_Listings::active() as $listing ) {
			$optional[] = sprintf(
				'- [%1$s](%2$s): %3$s',
				self::llms_escape(
					sprintf(
						/* translators: %s : titre de la page de listing. */
						__( '%s — données JSON', 'pivot-offres' ),
						Pivot_Listings::title( $listing, $default )
					)
				),
				esc_url_raw( rest_url( Pivot_Rest::NAMESPACE_V1 . '/index/' . rawurlencode( (string) pivot_get( $listing, 'id', '' ) ) ) ),
				__( 'toutes les offres de la page, avec nom, localité, coordonnées et adresse de la fiche ; paramètre lang pour une autre langue.', 'pivot-offres' )
			);
		}

		if ( $optional ) {
			$lines[] = '';
			$lines[] = '## Optional';
			$lines[] = '';
			$lines   = array_merge( $lines, $optional );
		}

		/**
		 * Permet de modifier le contenu de /llms.txt.
		 *
		 * @param string $text Contenu, au format Markdown.
		 */
		return (string) apply_filters( 'pivot_llms_txt', implode( "\n", $lines ) . "\n" );
	}

	/**
	 * Texte d'une ligne de llms.txt : sans saut de ligne ni crochet, qui
	 * casseraient la liste Markdown.
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	private static function llms_escape( $text ) {
		return str_replace( array( '[', ']' ), array( '(', ')' ), pivot_plain_text( $text ) );
	}

	/**
	 * Retire les balises Open Graph et Twitter de Yoast sur les pages du plugin.
	 *
	 * Le plugin publie les siennes, avec l'image de la page ou de l'offre.
	 * Celles de Yoast, placées avant, portaient le logo du site, que les
	 * réseaux sociaux retenaient au partage.
	 *
	 * @param string[] $presenters Classes des balises que Yoast va publier.
	 * @return string[]
	 */
	public function yoast_presenters( $presenters ) {
		if ( ! is_array( $presenters ) || ! $this->context() ) {
			return $presenters;
		}

		return array_values(
			array_filter(
				$presenters,
				static function ( $presenter ) {
					return false === strpos( (string) $presenter, '\\Presenters\\Open_Graph\\' )
						&& false === strpos( (string) $presenter, '\\Presenters\\Twitter\\' );
				}
			)
		);
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
