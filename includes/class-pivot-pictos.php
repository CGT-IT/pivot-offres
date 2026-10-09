<?php
/**
 * Copie locale des pictogrammes PIVOT.
 *
 * PIVOT sert ses pictogrammes (img/URN;h=…;c=…) sans en-tête de cache : chaque
 * visiteur les redemande. Le plugin les télécharge dans uploads/pivot-cache/
 * pictos/, dans les tailles et couleurs que le thème déclare, et
 * pivot_picto_url() sert la copie locale, PIVOT restant le recours pour un
 * pictogramme absent.
 *
 * Les fichiers sont générés sur chaque site, jamais versionnés : ils vivent
 * sous uploads pour survivre aux mises à jour du thème et du plugin, et hors
 * des groupes de Pivot_Cache pour que « Tout vider » ne les efface pas — les
 * index pointent vers eux.
 *
 * @package Pivot_Offres
 */

defined( 'ABSPATH' ) || exit;

class Pivot_Pictos {

	/** Groupe de Pivot_Cache, public. */
	const GROUP = 'pictos';

	/** Tâche de téléchargement, par tranches. */
	const EVENT = 'pivot_sync_pictos';

	/** Urns dont PIVOT ne sert qu'une image transparente : urn => date. */
	const EMPTY_OPTION = 'pivot_pictos_empty';

	/** Passe en cours : since, added, started. */
	const RUN_OPTION = 'pivot_pictos_run';

	/** Bilan de la dernière passe et empreinte des usages. */
	const STATE_OPTION = 'pivot_pictos_state';

	/** Format des pictogrammes demandés : 1, une dimension ; 2, un cadre (boxed()). */
	const BOX = 2;

	/** @var Pivot_Pictos|null */
	private static $instance = null;

	/** @var array|null Usages, une fois filtrés. */
	private static $usages = null;

	/**
	 * @return Pivot_Pictos
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::EVENT, array( __CLASS__, 'run' ) );
		// Chaque jour, les pictogrammes manquants : presque rien d'habitude.
		add_action( 'pivot_daily_maintenance', array( __CLASS__, 'schedule' ) );
		// Un thème qui déclare une nouvelle taille ou couleur.
		add_action( 'admin_init', array( __CLASS__, 'check_usages' ), 20 );
	}

	/* ------------------------------------------------------------ usages */

	/**
	 * Usages déclarés : nom => array( args, urns ).
	 *
	 * args : paramètres matriciels de PIVOT (h, w, c, modifier…), une valeur
	 * en tableau répétant le paramètre. urns : urns explicites, ou sources
	 * développées depuis le thesaurus — class, signal, equipment, types.
	 *
	 * @return array
	 */
	public static function usages() {
		if ( null === self::$usages ) {
			$defaults = array(
				'class'     => array(
					'args' => array( 'h' => 22 ),
					'urns' => array( 'class' ),
				),
				'equipment' => array(
					'args' => array( 'h' => 30 ),
					'urns' => array( 'equipment' ),
				),
				'signal'    => array(
					'args' => array( 'w' => 25 ),
					'urns' => array( 'signal' ),
				),
				'pin'       => array(
					'args' => array(
						'modifier' => array( 'pin', 'ori' ),
						'w'        => 30,
					),
					'urns' => array( 'types' ),
				),
			);

			/**
			 * Usages des pictogrammes : tailles, couleurs et urns à copier.
			 *
			 * Un thème y règle les couleurs des usages par défaut et ajoute les
			 * siens. Toute variante affichée doit y figurer : sinon elle reste
			 * servie par PIVOT.
			 *
			 * @param array $usages Nom => array( 'args' => array, 'urns' => array ).
			 */
			self::$usages = (array) apply_filters( 'pivot_pictos', $defaults );
		}

		return self::$usages;
	}

	/**
	 * Paramètres d'un usage, d'un tableau ou d'une hauteur.
	 *
	 * @param string|array|int $usage Nom d'usage, paramètres, ou hauteur.
	 * @return array
	 */
	public static function args( $usage ) {
		if ( is_array( $usage ) ) {
			return $usage;
		}

		if ( is_int( $usage ) || ctype_digit( (string) $usage ) ) {
			return $usage ? array( 'h' => (int) $usage ) : array();
		}

		return (array) pivot_get( self::usages(), array( (string) $usage, 'args' ), array() );
	}

	/**
	 * Paramètres à plat : liste de array( nom, valeur ), un tableau de
	 * valeurs répétant le paramètre, les valeurs vides passées.
	 *
	 * @param array $args Paramètres matriciels.
	 * @return array
	 */
	private static function params( $args ) {
		$params = array();

		foreach ( $args as $name => $values ) {
			foreach ( (array) $values as $value ) {
				if ( '' !== $value && null !== $value ) {
					$params[] = array( $name, $value );
				}
			}
		}

		return $params;
	}

	/* ------------------------------------------------------------ adresses */

	/**
	 * Nom du fichier local :
	 * urn:val:class:3ear + h=22, c=71BE63 → urn-val-class-3ear_h22_c71BE63.png
	 *
	 * @param string $urn  Urn.
	 * @param array  $args Paramètres matriciels.
	 * @return string
	 */
	public static function file( $urn, $args ) {
		$name = str_replace( ':', '-', $urn );

		foreach ( self::params( $args ) as $param ) {
			$name .= '_' . $param[0] . $param[1];
		}

		return preg_replace( '/[^A-Za-z0-9_-]/', '-', $name ) . '.png';
	}

	/**
	 * Adresse PIVOT : img/URN;h=…;c=…, l'urn passée telle quelle.
	 *
	 * @param string $urn  Urn.
	 * @param array  $args Paramètres matriciels.
	 * @return string
	 */
	public static function remote_url( $urn, $args ) {
		$url = pivot_service_url() . '/img/' . str_replace( '%3A', ':', rawurlencode( $urn ) );

		foreach ( self::params( $args ) as $param ) {
			$url .= ';' . rawurlencode( $param[0] ) . '=' . rawurlencode( $param[1] );
		}

		return $url;
	}

	/**
	 * Adresse d'un pictogramme : la copie locale, sinon PIVOT.
	 *
	 * @param string           $urn   Urn.
	 * @param string|array|int $usage Nom d'usage, paramètres, ou hauteur.
	 * @return string Vide pour une urn sans pictogramme.
	 */
	public static function url( $urn, $usage = '' ) {
		if ( ! $urn || self::is_empty( $urn ) ) {
			return '';
		}

		$args = self::boxed( $urn, self::args( $usage ) );
		$file = self::file( $urn, $args );

		if ( file_exists( Pivot_Cache::directory( self::GROUP ) . $file ) ) {
			return Pivot_Cache::url( self::GROUP ) . $file;
		}

		return self::remote_url( $urn, $args );
	}

	/**
	 * PIVOT ne sert-il qu'une image transparente pour cette urn ?
	 *
	 * @param string $urn Urn.
	 * @return bool
	 */
	public static function is_empty( $urn ) {
		$empty = get_option( self::EMPTY_OPTION, array() );

		return is_array( $empty ) && isset( $empty[ $urn ] );
	}

	/* ------------------------------------------------------------ tailles */

	/**
	 * Taille d'un pictogramme, pour ses attributs width et height : celle
	 * qu'on demande à PIVOT.
	 *
	 * Une image qui déclare sa taille a sa place réservée avant d'arriver : la
	 * page ne saute pas, et une image lente ne s'étale pas.
	 *
	 * @param string           $urn   Urn.
	 * @param string|array|int $usage Nom d'usage, paramètres, ou hauteur.
	 * @return array array( largeur, hauteur ), 0 pour une dimension inconnue.
	 */
	public static function size( $urn, $usage = '' ) {
		$args = self::boxed( $urn, self::args( $usage ) );

		return array( (int) pivot_get( $args, 'w', 0 ), (int) pivot_get( $args, 'h', 0 ) );
	}

	/**
	 * Taille d'un pictogramme dont on n'a que l'adresse : celle qu'un index
	 * a gardée, celle d'un classement déjà calculé.
	 *
	 * L'adresse porte ce qui a été demandé : ;w=30;h=20 chez PIVOT,
	 * urn-…_w30_h20_… pour la copie locale (voir file()).
	 *
	 * @param string $url Adresse du pictogramme.
	 * @return array array( largeur, hauteur ), 0 pour une dimension inconnue.
	 */
	public static function size_of_url( $url ) {
		$name = rawurldecode( basename( (string) wp_parse_url( (string) $url, PHP_URL_PATH ) ) );
		$get  = static function ( $param ) use ( $name ) {
			return preg_match( '/[;_]' . $param . '=?(\d+)(?=[;_.]|$)/', $name, $match ) ? (int) $match[1] : 0;
		};

		return array( $get( 'w' ), $get( 'h' ) );
	}

	/**
	 * Cadre demandé à PIVOT : la dimension manquante reprend l'autre.
	 *
	 * Demandé sur sa seule hauteur, un pictogramme a une largeur qui dépend de
	 * son dessin (25×25 le plus souvent, 30×25 pour les visites). Demandé dans
	 * un cadre, w=25;h=25, PIVOT renvoie exactement ce cadre, le dessin centré
	 * sur un fond transparent : sa taille est connue d'avance, sans le mesurer.
	 *
	 * Sauf pour un classement, dont la largeur suit le nombre d'étoiles (66×22
	 * pour trois) : il garde sa seule dimension demandée.
	 *
	 * @param string $urn  Urn.
	 * @param array  $args Paramètres matriciels.
	 * @return array
	 */
	public static function boxed( $urn, $args ) {
		$args = (array) $args;

		if ( 0 === strpos( (string) $urn, 'urn:val:class:' ) ) {
			return $args;
		}

		$width  = (int) pivot_get( $args, 'w', 0 );
		$height = (int) pivot_get( $args, 'h', 0 );

		if ( $height && ! $width ) {
			$args['w'] = $height;
		} elseif ( $width && ! $height ) {
			$args['h'] = $width;
		}

		return $args;
	}

	/* ------------------------------------------------------------ liste */

	/**
	 * Pictogrammes à copier : nom de fichier => array( urn, args ).
	 *
	 * @return array
	 */
	public static function wanted() {
		$sources = array();
		$types   = array_map( 'intval', array_keys( Pivot_Thesaurus::offer_types() ) );

		foreach ( $types as $type_id ) {
			$sources['types'][] = 'urn:typ:' . $type_id;

			foreach ( Pivot_Thesaurus::type_structure( $type_id ) as $urn => $entry ) {
				$type = (string) pivot_get( $entry, 'type', '' );

				// Valeurs de urn:fld:class de premier niveau : ni les classements
				// Michelin ou Gault & Millau (urn:val:class:michstar:…), ni les titres.
				if ( 'Value' === $type && preg_match( '/^urn:val:class:[^:]+$/', $urn ) ) {
					$sources['class'][] = $urn;
				} elseif ( 'Value' === $type && 0 === strpos( $urn, 'urn:val:signal:' ) ) {
					$sources['signal'][] = $urn;
				} elseif ( 'Boolean' === $type && 'urn:cat:eqpsrv' === pivot_get( $entry, 'cat' ) ) {
					$sources['equipment'][] = $urn;
				}
			}
		}

		$wanted = array();

		foreach ( self::usages() as $usage ) {
			$args = (array) pivot_get( $usage, 'args', array() );

			foreach ( (array) pivot_get( $usage, 'urns', array() ) as $source ) {
				$urns = isset( $sources[ $source ] ) ? $sources[ $source ] : ( 0 === strpos( (string) $source, 'urn:' ) ? array( $source ) : array() );

				foreach ( $urns as $urn ) {
					$boxed = self::boxed( $urn, $args );

					$wanted[ self::file( $urn, $boxed ) ] = array( $urn, $boxed );
				}
			}
		}

		ksort( $wanted );

		return $wanted;
	}

	/* ------------------------------------------------------------ téléchargement */

	/**
	 * Une image PNG entièrement transparente ?
	 *
	 * C'est ce que PIVOT sert pour une urn sans pictogramme. Sans l'extension
	 * GD, l'image est gardée telle quelle.
	 *
	 * @param string $png Contenu du fichier.
	 * @return bool
	 */
	private static function is_blank( $png ) {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return false;
		}

		$image = @imagecreatefromstring( $png ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $image ) {
			return false;
		}

		for ( $x = imagesx( $image ) - 1; $x >= 0; $x-- ) {
			for ( $y = imagesy( $image ) - 1; $y >= 0; $y-- ) {
				$color = imagecolorsforindex( $image, imagecolorat( $image, $x, $y ) );

				if ( $color['alpha'] < 127 ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Télécharge les pictogrammes manquants, dans un budget de temps.
	 *
	 * Un fichier présent, ou une urn déjà relevée vide, n'est repris que s'il
	 * date d'avant $since : c'est ainsi qu'une passe complète (« tout
	 * retélécharger ») se poursuit d'une tranche à l'autre sans rien effacer
	 * d'avance.
	 *
	 * @param int $budget Secondes au plus ; 0 pour tout faire.
	 * @param int $since  Horodatage : ce qui est plus ancien est repris.
	 * @return array downloaded, empty, failed, pending.
	 */
	public static function sync( $budget = 20, $since = 0 ) {
		$dir     = Pivot_Cache::directory( self::GROUP );
		$empty   = (array) get_option( self::EMPTY_OPTION, array() );
		$started = microtime( true );
		$report  = array(
			'downloaded' => 0,
			'empty'      => 0,
			'failed'     => 0,
			'pending'    => false,
		);

		foreach ( self::wanted() as $file => $picto ) {
			list( $urn, $args ) = $picto;

			$path = $dir . $file;

			if ( isset( $empty[ $urn ] ) && (int) $empty[ $urn ] >= $since ) {
				continue;
			}

			if ( ! isset( $empty[ $urn ] ) && file_exists( $path ) && filemtime( $path ) >= $since ) {
				continue;
			}

			if ( $budget && ( microtime( true ) - $started ) > $budget ) {
				$report['pending'] = true;
				break;
			}

			$response = wp_remote_get( self::remote_url( $urn, $args ), array( 'timeout' => 20 ) );
			$body     = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );

			// PIVOT répond 200 même sans image : seul un vrai PNG est gardé.
			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || 0 !== strpos( $body, "\x89PNG" ) ) {
				++$report['failed'];
				continue;
			}

			if ( self::is_blank( $body ) ) {
				$empty[ $urn ] = time();
				wp_delete_file( $path );
				++$report['empty'];
				continue;
			}

			unset( $empty[ $urn ] );

			if ( Pivot_Cache::put_file( self::GROUP, $file, $body ) ) {
				++$report['downloaded'];
			} else {
				++$report['failed'];
			}
		}

		update_option( self::EMPTY_OPTION, $empty, true );

		return $report;
	}

	/**
	 * Programme une passe.
	 *
	 * @param bool $force Reprendre tous les pictogrammes, pas seulement les manquants.
	 */
	public static function schedule( $force = false ) {
		$run = get_option( self::RUN_OPTION );

		if ( $force || ! is_array( $run ) ) {
			update_option(
				self::RUN_OPTION,
				array(
					'since'   => $force ? time() : (int) pivot_get( $run, 'since', 0 ),
					'added'   => (int) pivot_get( $run, 'added', 0 ),
					'started' => time(),
				),
				false
			);
		}

		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_single_event( time() + 5, self::EVENT );
		}
	}

	/**
	 * Tranche de la passe en cours (tâche planifiée).
	 */
	public static function run() {
		$run = get_option( self::RUN_OPTION );

		if ( ! is_array( $run ) ) {
			return;
		}

		$report       = self::sync( 20, (int) pivot_get( $run, 'since', 0 ) );
		$run['added'] = (int) pivot_get( $run, 'added', 0 ) + $report['downloaded'];

		if ( $report['pending'] ) {
			update_option( self::RUN_OPTION, $run, false );
			wp_schedule_single_event( time() + 5, self::EVENT );
			return;
		}

		self::finish( $run['added'], $report['failed'] );
	}

	/**
	 * Clôt une passe : bilan, et reconstruction des index si des pictogrammes
	 * sont arrivés — les vignettes en portent l'adresse en dur.
	 *
	 * @param int $added  Fichiers téléchargés pendant la passe.
	 * @param int $failed Échecs de la dernière tranche.
	 */
	public static function finish( $added, $failed ) {
		delete_option( self::RUN_OPTION );

		$state           = (array) get_option( self::STATE_OPTION, array() );
		$state['last']   = time();
		$state['added']  = $added;
		$state['failed'] = $failed;
		update_option( self::STATE_OPTION, $state, false );

		if ( $added ) {
			Pivot_Index_Builder::rebuild_all();
		}

		Pivot_Logger::info(
			sprintf( 'Pictogrammes : %d téléchargé(s), %d échec(s).', $added, $failed ),
			array( 'service' => 'pictos' )
		);
	}

	/**
	 * Lance une passe quand les usages changent : nouvelle taille, nouvelle
	 * couleur, nouveau thème.
	 */
	public static function check_usages() {
		// BOX : le format des fichiers (boxed()) compte aussi, pour que les
		// copies soient refaites quand il change.
		$fingerprint = md5( (string) wp_json_encode( self::usages() ) . '|box' . self::BOX );
		$state       = (array) get_option( self::STATE_OPTION, array() );

		if ( pivot_get( $state, 'usages' ) === $fingerprint ) {
			return;
		}

		$state['usages'] = $fingerprint;
		update_option( self::STATE_OPTION, $state, false );

		self::schedule();
	}

	/**
	 * Bilan pour la page Outils.
	 *
	 * @return array files, size, empty, last, running.
	 */
	public static function stats() {
		$files = (array) glob( Pivot_Cache::directory( self::GROUP ) . '*.png' );
		$state = (array) get_option( self::STATE_OPTION, array() );

		return array(
			'files'   => count( $files ),
			'size'    => array_sum( array_map( 'filesize', $files ) ),
			'empty'   => count( (array) get_option( self::EMPTY_OPTION, array() ) ),
			'last'    => (int) pivot_get( $state, 'last', 0 ),
			'running' => is_array( get_option( self::RUN_OPTION ) ),
		);
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Commande « wp pivot pictos ».
	 */
	class Pivot_Pictos_Command {

		/**
		 * Télécharge les pictogrammes PIVOT dans uploads/pivot-cache/pictos/.
		 *
		 * ## OPTIONS
		 *
		 * [--force]
		 * : Reprend aussi les pictogrammes déjà présents ou déjà relevés vides.
		 *
		 * [--dry-run]
		 * : Liste les pictogrammes sans rien télécharger.
		 *
		 * ## EXAMPLES
		 *
		 *     wp pivot pictos
		 *     wp pivot pictos --force
		 *
		 * @param array $args       Arguments positionnels.
		 * @param array $assoc_args Options.
		 */
		public function __invoke( $args, $assoc_args ) {
			$wanted = Pivot_Pictos::wanted();

			if ( ! $wanted ) {
				WP_CLI::error( 'Thesaurus PIVOT vide : vérifiez la connexion et la clé.' );
			}

			if ( ! empty( $assoc_args['dry-run'] ) ) {
				foreach ( $wanted as $file => $picto ) {
					WP_CLI::line( $file . "\t" . Pivot_Pictos::remote_url( $picto[0], $picto[1] ) );
				}
				WP_CLI::success( count( $wanted ) . ' pictogrammes.' );
				return;
			}

			$report = Pivot_Pictos::sync( 0, empty( $assoc_args['force'] ) ? 0 : time() );

			Pivot_Pictos::finish( $report['downloaded'], $report['failed'] );

			WP_CLI::success(
				sprintf(
					'%d téléchargé(s), %d vide(s), %d échec(s) sur %d.%s',
					$report['downloaded'],
					$report['empty'],
					$report['failed'],
					count( $wanted ),
					$report['downloaded'] ? ' Reconstruction des index programmée.' : ''
				)
			);
		}
	}

	WP_CLI::add_command( 'pivot pictos', 'Pivot_Pictos_Command' );
}
