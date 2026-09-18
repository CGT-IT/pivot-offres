<?php
/**
 * Couche multilingue.
 *
 * Le plugin ne gère pas les langues lui-même : c'est le rôle d'une extension
 * de traduction. Il se contente de lui demander quelles langues le site publie,
 * laquelle est active, et quelle URL correspond à une page dans chacune.
 *
 * WPML, Polylang, TranslatePress et Weglot sont reconnus d'office. Toute autre
 * extension s'intègre par les filtres pivot_site_languages,
 * pivot_default_language, pivot_current_language et pivot_language_url.
 *
 * Sans extension de traduction, le site est monolingue : une seule langue, une
 * seule URL par page, aucun préfixe inventé.
 *
 * Les traductions des contenus, elles, viennent toujours de PIVOT, qui renvoie
 * les libellés de champs et de valeurs en quatre langues.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_I18n {

	/** @var array Langues dans lesquelles PIVOT fournit ses contenus. */
	const CONTENT_LANGS = array( 'fr', 'nl', 'en', 'de' );

	/** @var array Alias historique. */
	const SUPPORTED = self::CONTENT_LANGS;

	/** @var string|null Extension de traduction détectée. */
	private static $provider = null;

	/** @var array|null Langues du site. */
	private static $languages = null;

	/** @var string|null Langue de la requête en cours. */
	private static $current = null;

	/* ------------------------------------------------------- détection */

	/**
	 * Extension de traduction active.
	 *
	 * @return string wpml|polylang|translatepress|weglot|custom|none
	 */
	public static function provider() {
		if ( null !== self::$provider ) {
			return self::$provider;
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			self::$provider = 'wpml';
		} elseif ( function_exists( 'pll_languages_list' ) ) {
			self::$provider = 'polylang';
		} elseif ( class_exists( 'TRP_Translate_Press' ) ) {
			self::$provider = 'translatepress';
		} elseif ( function_exists( 'weglot_get_languages_available' ) ) {
			self::$provider = 'weglot';
		} elseif ( count( (array) apply_filters( 'pivot_site_languages', array() ) ) > 1 ) {
			// Une extension non reconnue peut se déclarer par ce filtre.
			self::$provider = 'custom';
		} else {
			self::$provider = 'none';
		}

		return self::$provider;
	}

	/**
	 * Nom lisible de l'extension détectée.
	 *
	 * @return string
	 */
	public static function provider_name() {
		$names = array(
			'wpml'          => 'WPML',
			'polylang'      => 'Polylang',
			'translatepress' => 'TranslatePress',
			'weglot'        => 'Weglot',
			'custom'        => __( 'Extension déclarée par filtre', 'pivot-offres' ),
		);

		$provider = self::provider();

		return isset( $names[ $provider ] ) ? $names[ $provider ] : __( 'Aucune : site monolingue', 'pivot-offres' );
	}

	/**
	 * Le site publie-t-il plusieurs langues ?
	 *
	 * @return bool
	 */
	public static function is_multilingual() {
		return count( self::languages() ) > 1;
	}

	/* --------------------------------------------------------- langues */

	/**
	 * Langues publiées par le site, la langue par défaut en tête.
	 *
	 * @return array Codes à deux lettres.
	 */
	public static function languages() {
		if ( null !== self::$languages ) {
			return self::$languages;
		}

		$langs = array();

		switch ( self::provider() ) {
			case 'wpml':
				$active = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );

				if ( is_array( $active ) ) {
					$langs = array_keys( $active );
				}
				break;

			case 'polylang':
				$langs = (array) pll_languages_list();
				break;

			case 'translatepress':
				$settings = get_option( 'trp_settings', array() );
				$langs    = (array) pivot_get( $settings, 'translation-languages', array() );
				break;

			case 'weglot':
				$langs = (array) weglot_get_languages_available();

				if ( function_exists( 'weglot_get_original_language' ) ) {
					array_unshift( $langs, weglot_get_original_language() );
				}
				break;
		}

		/**
		 * Langues publiées par le site.
		 *
		 * Point d'intégration pour une extension de traduction non reconnue.
		 *
		 * @param array $langs Codes de langue.
		 */
		$langs = (array) apply_filters( 'pivot_site_languages', $langs );

		$clean = array();

		foreach ( $langs as $lang ) {
			$code = self::code( $lang );

			if ( $code && ! in_array( $code, $clean, true ) ) {
				$clean[] = $code;
			}
		}

		if ( ! $clean ) {
			$clean = array( self::default_lang() );
		}

		$default = self::default_lang();

		if ( in_array( $default, $clean, true ) ) {
			$clean = array_merge( array( $default ), array_diff( $clean, array( $default ) ) );
		} else {
			array_unshift( $clean, $default );
		}

		self::$languages = array_values( $clean );

		return self::$languages;
	}

	/**
	 * Alias historique.
	 *
	 * @return array
	 */
	public static function enabled() {
		return self::languages();
	}

	/**
	 * Toutes les langues susceptibles d'avoir laissé un fichier derrière elles.
	 *
	 * Sert au ménage : une langue retirée du site a pu produire un index qu'il
	 * faut encore savoir supprimer.
	 *
	 * @return array
	 */
	public static function all_known() {
		return array_values( array_unique( array_merge( self::CONTENT_LANGS, self::languages() ) ) );
	}

	/**
	 * Langue par défaut du site.
	 *
	 * @return string
	 */
	public static function default_lang() {
		static $default = null;

		if ( null !== $default ) {
			return $default;
		}

		$code = '';

		switch ( self::provider() ) {
			case 'wpml':
				$code = self::code( apply_filters( 'wpml_default_language', null ) );
				break;

			case 'polylang':
				if ( function_exists( 'pll_default_language' ) ) {
					$code = self::code( pll_default_language() );
				}
				break;

			case 'translatepress':
				$settings = get_option( 'trp_settings', array() );
				$code     = self::code( pivot_get( $settings, 'default-language', '' ) );
				break;

			case 'weglot':
				if ( function_exists( 'weglot_get_original_language' ) ) {
					$code = self::code( weglot_get_original_language() );
				}
				break;
		}

		if ( ! $code ) {
			$code = self::code( get_locale() );
		}

		/**
		 * Langue par défaut du site.
		 *
		 * @param string $code Code à deux lettres.
		 */
		$default = self::code( apply_filters( 'pivot_default_language', $code ) );

		if ( ! $default ) {
			$default = 'fr';
		}

		return $default;
	}

	/**
	 * Langue de la requête en cours.
	 *
	 * @return string
	 */
	public static function current() {
		if ( null !== self::$current ) {
			return self::$current;
		}

		$code = self::code( get_query_var( 'pivot_lang' ) );

		if ( ! $code ) {
			switch ( self::provider() ) {
				case 'wpml':
					$code = self::code( apply_filters( 'wpml_current_language', null ) );
					break;

				case 'polylang':
					if ( function_exists( 'pll_current_language' ) ) {
						$code = self::code( pll_current_language() );
					}
					break;

				case 'weglot':
					if ( function_exists( 'weglot_get_current_language' ) ) {
						$code = self::code( weglot_get_current_language() );
					}
					break;

				default:
					// TranslatePress et les autres basculent la locale.
					$code = self::code( determine_locale() );
			}
		}

		if ( ! $code ) {
			$code = self::code( determine_locale() );
		}

		if ( ! $code || ! in_array( $code, self::languages(), true ) ) {
			$code = self::default_lang();
		}

		/**
		 * Langue de la requête en cours.
		 *
		 * @param string $code Code à deux lettres.
		 */
		self::$current = self::code( apply_filters( 'pivot_current_language', $code ) );

		if ( ! self::$current ) {
			self::$current = self::default_lang();
		}

		return self::$current;
	}

	/**
	 * Force la langue courante, ou la redétecte avec null.
	 *
	 * @param string|null $lang Code.
	 */
	public static function set_current( $lang ) {
		self::$current = $lang ? self::code( $lang ) : null;
	}

	/**
	 * Ramène un code quelconque (fr_BE, nl-NL, FR) à deux lettres minuscules.
	 *
	 * @param string $code Code d'entrée.
	 * @return string
	 */
	public static function code( $code ) {
		$code = strtolower( substr( (string) $code, 0, 2 ) );

		return preg_match( '/^[a-z]{2}$/', $code ) ? $code : '';
	}

	/**
	 * Alias historique : ramène à une langue de contenu PIVOT.
	 *
	 * @param string $code Code d'entrée.
	 * @return string Chaîne vide si PIVOT ne fournit pas cette langue.
	 */
	public static function normalize( $code ) {
		$code = self::code( $code );

		return in_array( $code, self::CONTENT_LANGS, true ) ? $code : '';
	}

	/**
	 * Langue de contenu PIVOT correspondant à une langue du site.
	 *
	 * Un site publié en espagnol reçoit les contenus dans la langue par défaut :
	 * PIVOT ne fournit que quatre langues.
	 *
	 * @param string|null $lang Langue du site.
	 * @return string
	 */
	public static function content_lang( $lang = null ) {
		$lang = $lang ? $lang : self::current();

		if ( in_array( $lang, self::CONTENT_LANGS, true ) ) {
			return $lang;
		}

		$default = self::default_lang();

		return in_array( $default, self::CONTENT_LANGS, true ) ? $default : 'fr';
	}

	/**
	 * Nom d'une langue, dans la langue de l'administration.
	 *
	 * @param string $lang Code.
	 * @return string
	 */
	public static function name( $lang ) {
		$names = array(
			'fr' => __( 'Français', 'pivot-offres' ),
			'nl' => __( 'Néerlandais', 'pivot-offres' ),
			'en' => __( 'Anglais', 'pivot-offres' ),
			'de' => __( 'Allemand', 'pivot-offres' ),
		);

		return isset( $names[ $lang ] ) ? $names[ $lang ] : strtoupper( (string) $lang );
	}

	/* -------------------------------------------------------- libellés */

	/**
	 * Choisit la bonne traduction dans un tableau lang => valeur.
	 *
	 * Le repli va de la langue demandée à la langue par défaut, puis au
	 * français, puis à la première valeur non vide : un libellé manquant ne
	 * laisse jamais un vide à l'écran.
	 *
	 * @param mixed  $labels   Tableau de traductions ou chaîne.
	 * @param string $lang     Langue souhaitée.
	 * @param string $fallback Valeur de repli.
	 * @return string
	 */
	public static function pick( $labels, $lang = null, $fallback = '' ) {
		if ( is_string( $labels ) ) {
			return '' !== $labels ? $labels : $fallback;
		}

		if ( ! is_array( $labels ) || ! $labels ) {
			return $fallback;
		}

		$order = array( self::content_lang( $lang ), self::content_lang( self::default_lang() ), 'fr' );

		foreach ( $order as $candidate ) {
			if ( isset( $labels[ $candidate ] ) && '' !== $labels[ $candidate ] ) {
				return (string) $labels[ $candidate ];
			}
		}

		foreach ( $labels as $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		return $fallback;
	}

	/**
	 * Traduction saisie dans l'administration, si elle existe.
	 *
	 * @param mixed  $values   Tableau lang => valeur.
	 * @param string $lang     Langue.
	 * @param string $fallback Repli.
	 * @return string
	 */
	public static function custom( $values, $lang, $fallback = '' ) {
		if ( is_array( $values ) && isset( $values[ $lang ] ) && '' !== trim( (string) $values[ $lang ] ) ) {
			return (string) $values[ $lang ];
		}

		return $fallback;
	}

	/* ------------------------------------------------------------- URL */

	/**
	 * Racine du site, telle qu'enregistrée, sans traitement linguistique.
	 *
	 * home_url() est filtrée par les extensions de traduction : elle renvoie la
	 * racine de la langue courante. Partir de l'adresse brute évite qu'une URL
	 * construite pour une autre langue soit ramenée à la langue courante.
	 *
	 * @return string
	 */
	public static function site_root() {
		$root = get_option( 'home' );

		if ( ! $root ) {
			$root = home_url( '/' );
		}

		/**
		 * Racine utilisée pour construire les URL du plugin.
		 *
		 * @param string $root Adresse du site.
		 */
		return untrailingslashit( (string) apply_filters( 'pivot_site_root', $root ) );
	}

	/**
	 * URL d'une page du plugin dans une langue donnée.
	 *
	 * @param string $path Chemin sans slash initial.
	 * @param string $lang Code de langue.
	 * @return string
	 */
	public static function url( $path, $lang = null ) {
		$lang  = $lang ? $lang : self::current();
		$path  = trim( (string) $path, '/' );
		$root  = self::site_root();
		$plain = $root . ( $path ? user_trailingslashit( '/' . $path ) : '/' );

		$url = self::provider_url( $path, $lang, $plain );

		/**
		 * URL finale d'une page du plugin.
		 *
		 * Point d'intégration pour une extension de traduction non reconnue :
		 * renvoyez l'adresse de cette page dans la langue demandée.
		 *
		 * @param string $url   URL calculée.
		 * @param string $path  Chemin.
		 * @param string $lang  Langue.
		 * @param string $plain URL sans traitement.
		 */
		return (string) apply_filters( 'pivot_language_url', $url ? $url : $plain, $path, $lang, $plain );
	}

	/**
	 * URL calculée par l'extension de traduction.
	 *
	 * @param string $path  Chemin.
	 * @param string $lang  Langue.
	 * @param string $plain URL sans traitement.
	 * @return string Chaîne vide si l'extension ne distingue pas cette langue.
	 */
	private static function provider_url( $path, $lang, $plain ) {
		switch ( self::provider() ) {

			case 'wpml':
				// WPML ne convertit que les URL correspondant à un contenu qu'il
				// connaît ; les nôtres lui sont étrangères. Trois tentatives, de
				// la plus respectueuse à la plus directe.
				$direct = apply_filters( 'wpml_permalink', $plain, $lang, true );

				if ( is_string( $direct ) && '' !== $direct && $direct !== $plain ) {
					return $direct;
				}

				$root    = apply_filters( 'wpml_permalink', trailingslashit( self::site_root() ), $lang, true );
				$default = apply_filters( 'wpml_permalink', trailingslashit( self::site_root() ), self::default_lang(), true );

				if ( is_string( $root ) && '' !== $root && $root !== $default ) {
					return self::join( $root, $path );
				}

				// Dernier recours : sa configuration d'URL, lue telle quelle.
				return self::wpml_url_from_settings( $path, $lang, $plain );

			case 'polylang':
				if ( function_exists( 'pll_home_url' ) ) {
					$home    = pll_home_url( $lang );
					$default = pll_home_url( self::default_lang() );

					if ( $home && ( $home !== $default || $lang === self::default_lang() ) ) {
						return self::join( $home, $path );
					}
				}

				return self::polylang_url_from_settings( $path, $lang, $plain );

			case 'translatepress':
				return self::translatepress_url( $plain, $lang );
		}

		return '';
	}

	/**
	 * URL reconstruite depuis la configuration de WPML.
	 *
	 * Ses réglages disent exactement comment les langues apparaissent dans les
	 * adresses : répertoire, domaine, ou paramètre. On les applique nous-mêmes
	 * quand son API refuse de convertir une URL qu'elle ne reconnaît pas.
	 *
	 * @param string $path  Chemin.
	 * @param string $lang  Langue.
	 * @param string $plain URL sans traitement.
	 * @return string
	 */
	private static function wpml_url_from_settings( $path, $lang, $plain ) {
		$settings = get_option( 'icl_sitepress_settings', array() );

		if ( ! is_array( $settings ) || ! $settings ) {
			return '';
		}

		$default     = self::code( pivot_get( $settings, 'default_language', self::default_lang() ) );
		$negotiation = (int) pivot_get( $settings, 'language_negotiation_type', 1 );

		switch ( $negotiation ) {

			// 1 : une langue par répertoire.
			case 1:
				$directory_for_default = ! empty( $settings['urls']['directory_for_default_language'] );

				if ( $lang === $default && ! $directory_for_default ) {
					return $plain;
				}

				return self::join( trailingslashit( self::site_root() ) . $lang, $path );

			// 2 : une langue par domaine.
			case 2:
				$domain = pivot_get( $settings, array( 'urls', 'domains', $lang ), '' );

				if ( ! $domain ) {
					return '';
				}

				if ( false === strpos( $domain, '://' ) ) {
					$scheme = (string) wp_parse_url( self::site_root(), PHP_URL_SCHEME );
					$domain = ( $scheme ? $scheme : 'https' ) . '://' . ltrim( $domain, '/' );
				}

				return self::join( $domain, $path );

			// 3 : la langue en paramètre.
			case 3:
				if ( $lang === $default ) {
					return $plain;
				}

				return add_query_arg( 'lang', $lang, $plain );
		}

		return '';
	}

	/**
	 * URL reconstruite depuis la configuration de Polylang.
	 *
	 * @param string $path  Chemin.
	 * @param string $lang  Langue.
	 * @param string $plain URL sans traitement.
	 * @return string
	 */
	private static function polylang_url_from_settings( $path, $lang, $plain ) {
		$settings = get_option( 'polylang', array() );

		if ( ! is_array( $settings ) || ! $settings ) {
			return '';
		}

		$default = self::code( pivot_get( $settings, 'default_lang', self::default_lang() ) );
		$force   = (int) pivot_get( $settings, 'force_lang', 1 );
		$hide    = ! empty( $settings['hide_default'] );

		// 2 et 3 : sous-domaine ou domaine par langue.
		if ( $force >= 2 ) {
			$domain = pivot_get( $settings, array( 'domains', $lang ), '' );

			if ( ! $domain ) {
				return '';
			}

			if ( false === strpos( $domain, '://' ) ) {
				$scheme = (string) wp_parse_url( self::site_root(), PHP_URL_SCHEME );
				$domain = ( $scheme ? $scheme : 'https' ) . '://' . ltrim( $domain, '/' );
			}

			return self::join( $domain, $path );
		}

		// 0 : la langue en paramètre.
		if ( 0 === $force ) {
			return $lang === $default ? $plain : add_query_arg( 'lang', $lang, $plain );
		}

		// 1 : une langue par répertoire.
		if ( $lang === $default && $hide ) {
			return $plain;
		}

		return self::join( trailingslashit( self::site_root() ) . $lang, $path );
	}

	/**
	 * URL TranslatePress, via son convertisseur interne quand il est joignable.
	 *
	 * @param string $plain URL sans traitement.
	 * @param string $lang  Langue.
	 * @return string
	 */
	private static function translatepress_url( $plain, $lang ) {
		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			return '';
		}

		$trp = TRP_Translate_Press::get_trp_instance();

		if ( ! $trp || ! method_exists( $trp, 'get_component' ) ) {
			return '';
		}

		$converter = $trp->get_component( 'url_converter' );

		if ( ! $converter || ! method_exists( $converter, 'get_url_for_language' ) ) {
			return '';
		}

		$settings = get_option( 'trp_settings', array() );
		$locale   = '';

		// TranslatePress raisonne en locales complètes : on retrouve celle qui
		// correspond au code court demandé.
		foreach ( (array) pivot_get( $settings, 'translation-languages', array() ) as $candidate ) {
			if ( self::code( $candidate ) === $lang ) {
				$locale = $candidate;
				break;
			}
		}

		if ( ! $locale ) {
			return '';
		}

		$url = $converter->get_url_for_language( $locale, $plain );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Assemble une racine et un chemin.
	 *
	 * @param string $base Racine.
	 * @param string $path Chemin.
	 * @return string
	 */
	private static function join( $base, $path ) {
		$base = trailingslashit( $base );

		return $path ? $base . user_trailingslashit( $path ) : $base;
	}

	/**
	 * Code hreflang complet d'une langue.
	 *
	 * @param string $lang Code court.
	 * @return string
	 */
	public static function hreflang( $lang ) {
		$map = array(
			'fr' => 'fr-BE',
			'nl' => 'nl-BE',
			'en' => 'en',
			'de' => 'de',
		);

		/**
		 * Codes hreflang publiés.
		 *
		 * @param array $map Correspondances.
		 */
		$map = apply_filters( 'pivot_hreflang_map', $map );

		return isset( $map[ $lang ] ) ? $map[ $lang ] : $lang;
	}

	/**
	 * Locale WordPress correspondant à une langue.
	 *
	 * @param string $lang Code court.
	 * @return string
	 */
	public static function locale( $lang ) {
		$map = array(
			'fr' => 'fr_BE',
			'nl' => 'nl_BE',
			'en' => 'en_US',
			'de' => 'de_DE',
		);

		$map = apply_filters( 'pivot_locale_map', $map );

		return isset( $map[ $lang ] ) ? $map[ $lang ] : get_locale();
	}

	/* ------------------------------------------------ changement de contexte */

	/**
	 * Empreinte de la configuration linguistique du site.
	 *
	 * @return string
	 */
	public static function fingerprint() {
		return md5( self::provider() . '|' . self::default_lang() . '|' . implode( ',', self::languages() ) );
	}

	/**
	 * Détecte l'installation ou la reconfiguration d'une extension de traduction.
	 *
	 * Les index sont produits par langue et les URL dépendent de l'extension :
	 * un changement rend les uns et les autres périmés. On les régénère plutôt
	 * que de laisser le site servir des pages incohérentes.
	 */
	public static function watch_changes() {
		$stored = get_option( 'pivot_lang_fingerprint', '' );
		$now    = self::fingerprint();

		if ( $stored === $now ) {
			return;
		}

		update_option( 'pivot_lang_fingerprint', $now, false );

		// Première installation du plugin : rien à régénérer.
		if ( '' === $stored ) {
			return;
		}

		foreach ( array_keys( Pivot_Listings::all() ) as $listing_id ) {
			Pivot_Index_Builder::invalidate( $listing_id );
		}

		update_option( 'pivot_flush_rewrites', 1, false );
		update_option(
			'pivot_lang_changed',
			array(
				'provider'  => self::provider_name(),
				'languages' => self::languages(),
				'time'      => time(),
			),
			false
		);

		Pivot_Logger::info(
			sprintf(
				'Configuration linguistique modifiée (%1$s : %2$s). Index et permaliens à régénérer.',
				self::provider(),
				implode( ',', self::languages() )
			),
			array( 'service' => 'i18n' )
		);
	}

	/**
	 * Branche les sélecteurs de langue des extensions reconnues.
	 */
	public static function bootstrap() {
		add_action( 'admin_init', array( __CLASS__, 'watch_changes' ), 5 );
		add_filter( 'icl_ls_languages', array( __CLASS__, 'filter_wpml_switcher' ), 20 );
		add_filter( 'pll_the_languages', array( __CLASS__, 'filter_polylang_switcher' ), 20, 2 );
	}

	/**
	 * Corrige les URL du sélecteur WPML sur les pages du plugin.
	 *
	 * @param array $languages Langues du sélecteur.
	 * @return array
	 */
	public static function filter_wpml_switcher( $languages ) {
		$alternates = Pivot_Templates::instance()->alternate_urls();

		if ( ! $alternates || ! is_array( $languages ) ) {
			return $languages;
		}

		foreach ( $languages as $code => $language ) {
			$short = self::code( $code );

			if ( $short && isset( $alternates[ $short ] ) ) {
				$languages[ $code ]['url'] = $alternates[ $short ];
			}
		}

		return $languages;
	}

	/**
	 * Corrige les URL du sélecteur Polylang sur les pages du plugin.
	 *
	 * @param array $languages Langues du sélecteur.
	 * @param array $args      Arguments d'affichage.
	 * @return array
	 */
	public static function filter_polylang_switcher( $languages, $args = array() ) {
		$alternates = Pivot_Templates::instance()->alternate_urls();

		if ( ! $alternates || ! is_array( $languages ) ) {
			return $languages;
		}

		foreach ( $languages as $key => $language ) {
			$short = self::code( pivot_get( $language, 'slug', '' ) );

			if ( $short && isset( $alternates[ $short ] ) ) {
				$languages[ $key ]['url'] = $alternates[ $short ];
			}
		}

		return $languages;
	}
}
