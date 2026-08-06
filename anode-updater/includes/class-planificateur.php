<?php
/**
 * Quand la vérification a lieu, et ce qu'elle déclenche.
 *
 * Rien ne se fait au chargement du mu-plugin : à ce moment-là, ni l'API HTTP ni
 * le système de fichiers de WordPress ne sont disponibles, et surtout personne
 * n'attend qu'une page publique parte interroger GitHub. Tout passe par WP-Cron.
 *
 * @package Anode\Updater
 */

declare( strict_types = 1 );

namespace Anode\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Planificateur {

	/** Nom du verrou d'installation, au sens de `WP_Upgrader`. */
	private const VERROU = 'anode_updater';

	public static function demarrer(): void {
		if ( defined( 'ANODE_UPDATER' ) && ! constant( 'ANODE_UPDATER' ) ) {
			return;
		}

		// Avant tout le reste : c'est la réponse qu'attend une installation en
		// cours ailleurs, et elle doit pouvoir être servie même si la suite de ce
		// fichier venait à ne rien planifier.
		Sonde::ecouter();

		add_action( 'init', [ self::class, 'planifier' ] );
		add_action( Updater::CRON, [ self::class, 'executer' ] );

		// Déclenchement à la demande, depuis un autre mu-plugin ou le pont REST.
		//
		// Sans le cache : qui demande une vérification maintenant veut l'état
		// réel, pas celui de six heures plus tôt. Le cron, lui, garde le cache —
		// trente sites qui interrogent l'API chaque jour, c'est ce qui finit par
		// se faire limiter.
		add_action( 'anode/updater/verifier', [ self::class, 'verifier_maintenant' ] );
		add_action( 'anode/updater/appliquer', [ self::class, 'appliquer_en_attente' ] );

		add_action( 'admin_notices', [ self::class, 'annoncer' ] );
	}

	/** Une vérification par jour, décalée pour ne pas mordre sur les heures de bureau. */
	public static function planifier(): void {
		if ( wp_next_scheduled( Updater::CRON ) ) {
			return;
		}

		wp_schedule_event( strtotime( 'tomorrow 4:00' ), 'daily', Updater::CRON );
	}

	/**
	 * Vérifie sans tenir compte du cache des releases.
	 *
	 * Une version publiée à l'instant reste invisible jusqu'à six heures sans
	 * cela — constaté en publiant puis en vérifiant aussitôt.
	 */
	public static function verifier_maintenant(): array {
		/*
		 * La purge passe **par** `executer()`, une fois le verrou obtenu.
		 *
		 * Purger d'abord, c'était le faire même quand la passe échouait ensuite à
		 * prendre le verrou : l'appel perdant vidait le cache pour rien, et la
		 * passe suivante repayait l'intégralité des appels à l'API GitHub.
		 */
		return self::executer( true );
	}

	/**
	 * Compare chaque composant à son dépôt, et applique ce qui est autorisé.
	 *
	 * @return array État de la vérification, tel qu'enregistré.
	 */
	public static function executer( bool $oublier_le_cache = false ): array {
		if ( ! self::verrouiller() ) {
			// Une vérification court déjà : le cron de quatre heures et un appel
			// manuel peuvent se croiser, et deux installations du même composant
			// se marchent dessus — l'une renomme le dossier que l'autre copie.
			// On rend l'état connu plutôt que d'en fabriquer un second.
			return (array) get_site_option( Updater::OPTION_ETAT, [] );
		}

		/*
		 * On part de l'état connu, on ne le remplace pas.
		 *
		 * Écrire à chaque composant protège d'un délai PHP dépassé — sans quoi une
		 * passe interrompue perdait tout le relevé. Mais repartir d'un tableau vide
		 * déplaçait le problème : les composants pas encore examinés disparaissaient
		 * de l'option, donc de l'avis d'administration. Une majeure signalée hier
		 * cessait de l'être après une passe coupée en cours de route.
		 */
		if ( $oublier_le_cache ) {
			foreach ( array_unique( array_column( Updater::composants(), 'repo' ) ) as $repo ) {
				Source::oublier( (string) $repo );
			}
		}

		$connu = (array) get_site_option( Updater::OPTION_ETAT, [] );
		$etat  = [
			'verifie_le' => gmdate( 'c' ),
			'composants' => is_array( $connu['composants'] ?? null ) ? $connu['composants'] : [],
		];

		$attendus = Updater::composants();

		// Un composant retiré du manifeste n'a plus à figurer dans l'état : il y
		// resterait indéfiniment, annoncé comme à jour ou en attente.
		$etat['composants'] = array_intersect_key( $etat['composants'], $attendus );

		try {
			foreach ( $attendus as $nom => $composant ) {
				$etat['composants'][ $nom ] = self::examiner( $nom, $composant );

				update_site_option( Updater::OPTION_ETAT, $etat );
			}

			/*
			 * Une liste vide n'écrit rien dans la boucle : sans cette ligne, un
			 * `composants.json` illisible ou un filtre qui vide la liste **gelait**
			 * `verifie_le`, et un avis périmé restait affiché sans fin.
			 */
			if ( ! $attendus ) {
				update_site_option( Updater::OPTION_ETAT, $etat );
			}
		} finally {
			self::deverrouiller();
		}

		return $etat;
	}

	/**
	 * Prend le verrou d'installation, ou renvoie false si une autre passe court.
	 *
	 * On emprunte celui de WordPress plutôt que d'en écrire un : il repose sur un
	 * `INSERT IGNORE` dans une colonne unique, donc atomique, là où « lire un
	 * transient puis l'écrire » laisse passer deux processus partis dans la même
	 * seconde. Il s'auto-libère aussi : un PHP tué en pleine copie ne condamne pas
	 * la vérification du lendemain.
	 */
	private static function verrouiller(): bool {
		global $wpdb;

		$clef    = self::VERROU . '.lock';
		$expire  = 10 * MINUTE_IN_SECONDS;
		$ancien  = get_option( $clef );

		// Un verrou périmé — PHP tué en pleine copie — ne condamne pas la passe
		// suivante.
		if ( $ancien && ( (int) $ancien + $expire ) < time() ) {
			delete_option( $clef );
			$ancien = false;
		}

		if ( $ancien ) {
			return false;
		}

		/*
		 * `INSERT IGNORE` plutôt que « lire puis écrire » : deux passes parties
		 * dans la même seconde liraient toutes deux « libre ». La colonne
		 * `option_name` est unique, donc la seconde insertion échoue — c'est le
		 * mécanisme exact de `WP_Upgrader::create_lock()`, réécrit ici pour ne pas
		 * charger `wp-admin/includes/class-wp-upgrader.php` et les dix-huit
		 * fichiers de sa chaîne à **chaque** passage du cron, y compris sur un
		 * site en vérification seule, qui n'installe jamais rien.
		 */
		$pose = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$wpdb->options}` ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' ) /* LOCK */",
				$clef,
				time()
			)
		);

		return (bool) $pose;
	}

	private static function deverrouiller(): void {
		delete_option( self::VERROU . '.lock' );
	}

	/**
	 * Examine un composant, et l'installe si son palier l'autorise.
	 *
	 * @return array
	 */
	private static function examiner( string $nom, array $composant ): array {
		$installee = Installateur::version_installee( $nom );
		$releases  = Source::releases( (string) $composant['repo'] );

		if ( is_wp_error( $releases ) ) {
			$code = (string) $releases->get_error_code();

			/*
			 * Trois motifs, trois états — parce qu'ils appellent trois conduites.
			 *
			 * Un dépôt qui n'existe pas encore n'est pas une panne : c'est un
			 * composant pas encore publié. On le note sans alerter l'admin d'un site
			 * en production, qui n'y peut rien.
			 *
			 * Un quota d'API épuisé n'en est pas une non plus, et c'est la panne la
			 * plus probable depuis que les dépôts sont publics : sans jeton, GitHub
			 * accorde soixante appels par heure et **par IP**, et les sites d'un même
			 * hébergement la partagent. La passe suivante repartira seule. Le ranger
			 * avec les erreurs enverrait chercher un dépôt cassé là où il n'y a qu'une
			 * attente — et `code` seul ne suffisait pas : rien ne l'écrivait dans
			 * l'état, donc la sonde qui l'y cherchait rendait toujours zéro.
			 */
			$etat = 'erreur';

			if ( 'anode_updater_depot' === $code ) {
				$etat = 'depot-absent';
			} elseif ( 'anode_updater_quota' === $code ) {
				$etat = 'quota';
			}

			return [
				'etat'      => $etat,
				'code'      => $code,
				'installee' => $installee,
				'message'   => $releases->get_error_message(),
			];
		}

		$derniere = Updater::derniere_release( $releases, Updater::prefixe_tag( $composant ) );

		if ( null === $derniere ) {
			return [
				'etat'      => 'aucune-release',
				'installee' => $installee,
				'message'   => sprintf( 'Aucune release publiée pour %s dans %s.', $nom, $composant['repo'] ),
			];
		}

		$publiee = $derniere['version'];

		/*
		 * Un composant déclaré mais absent du site : il attend une décision.
		 *
		 * Ce cas rendait « absent » et s'arrêtait là. Conséquence : ajouter un
		 * composant au blueprint obligeait à repasser sur chaque site pour le
		 * poser à la main — précisément ce que ce canal existe pour éviter, et la
		 * garantie qu'un site oublié reste sans lui indéfiniment.
		 *
		 * Il n'est pas installé seul pour autant. Une mise à jour remplace du code
		 * que le site avait déjà ; une première pose en **ajoute**, et cela ne se
		 * décide pas à quatre heures du matin sans que personne l'ait demandé.
		 * D'où le même traitement qu'une majeure : téléchargé, annoncé, installé
		 * sur décision — un appel à `anode/updater/appliquer` suffit alors, sans
		 * SSH.
		 *
		 * Le palier vaut « nouveau », qu'`auto_autorise()` refuse explicitement.
		 */
		if ( null === $installee ) {
			return [
				'etat'      => 'en-attente',
				'installee' => null,
				'publiee'   => $publiee,
				'palier'    => 'nouveau',
				'message'   => sprintf(
					'%s %s est publié mais absent de ce site. Une première installation ajoute du code : elle attend une décision.',
					$nom,
					$publiee
				),
			];
		}

		if ( version_compare( $publiee, $installee, '<=' ) ) {
			return [ 'etat' => 'a-jour', 'installee' => $installee, 'publiee' => $publiee ];
		}

		$palier = Updater::palier( $installee, $publiee );

		if ( ! Updater::auto_autorise( $palier ) ) {
			return [
				'etat'      => 'en-attente',
				'installee' => $installee,
				'publiee'   => $publiee,
				'palier'    => $palier,
				'message'   => sprintf(
					'Version %s disponible (%s). Elle attend une décision : ANODE_UPDATER_AUTO ne l’autorise pas.',
					$publiee,
					$palier
				),
			];
		}

		$resultat = Installateur::installer( $nom, $derniere['release'], $publiee );
		$sonde    = Installateur::sonde_du_dernier_essai();

		if ( is_wp_error( $resultat ) ) {
			// Une version retirée par la sonde n'est pas une installation qui a
			// échoué : elle a eu lieu, puis a été défaite. Le journal doit les
			// distinguer, sinon on cherche pendant une heure pourquoi le dossier
			// porte encore l'ancienne version après une « installation ».
			self::consigner(
				$nom,
				[
					'de'       => $installee,
					'vers'     => $publiee,
					'palier'   => $palier,
					'resultat' => 'anode_updater_sonde' === $resultat->get_error_code() ? 'restauree' : 'echec',
					'message'  => $resultat->get_error_message(),
					/*
					 * Le verdict de la sonde, y compris — et surtout — quand
					 * l'installation a échoué : c'est là qu'on cherche à savoir si
					 * le site répondait avant, et ce qu'il répondait après. La
					 * branche de réussite le consignait, celle-ci l'oubliait.
					 */
					'sonde'    => Installateur::sonde_du_dernier_essai()['etat'],
				]
			);

			return [
				'etat'      => 'echec',
				'installee' => $installee,
				'publiee'   => $publiee,
				'palier'    => $palier,
				'sonde'     => $sonde['etat'],
				'message'   => $resultat->get_error_message(),
			];
		}

		self::consigner(
			$nom,
			[
				'de'       => $installee,
				'vers'     => $publiee,
				'palier'   => $palier,
				'resultat' => 'installee',
				'sonde'    => $sonde['etat'],
				'message'  => 'ok' === $sonde['etat'] ? '' : (string) ( $sonde['message'] ?? '' ),
			]
		);

		return [
			'etat'      => 'installee',
			'installee' => $publiee,
			'depuis'    => $installee,
			'palier'    => $palier,
			'sonde'     => $sonde['etat'],
		];
	}

	/**
	 * Ajoute une ligne au journal des installations.
	 *
	 * `OPTION_ETAT` est écrasée à chaque passe : elle dit où l'on en est, jamais
	 * ce qui s'est passé. Un site qui se met à mal fonctionner un mardi n'avait
	 * donc rien à consulter pour savoir ce qui avait bougé le lundi.
	 */
	private static function consigner( string $nom, array $entree ): void {
		$journal = (array) get_site_option( Updater::OPTION_JOURNAL, [] );
		$suite   = Updater::journaliser( $journal, [ 'quand' => gmdate( 'c' ), 'composant' => $nom ] + $entree );

		/*
		 * Créée hors autoload, et une seule fois.
		 *
		 * Une option écrite par `update_option()` sans avoir jamais existé est
		 * chargée à **chaque** requête du site. Ce journal, lui, ne se lit qu'à la
		 * demande : cinquante entrées dans `alloptions` seraient un poids payé sur
		 * chaque page pour un fichier qu'on ouvre trois fois par an.
		 */
		if ( ! is_multisite() && false === get_option( Updater::OPTION_JOURNAL, false ) ) {
			add_option( Updater::OPTION_JOURNAL, $suite, '', false );

			return;
		}

		update_site_option( Updater::OPTION_JOURNAL, $suite );
	}

	/**
	 * Installe ce qui attendait une décision — majeures comprises.
	 *
	 * Appelé explicitement : `do_action( 'anode/updater/appliquer' )`.
	 */
	public static function appliquer_en_attente(): array {
		$etat    = (array) get_site_option( Updater::OPTION_ETAT, [] );
		$attente = array_keys(
			array_filter(
				(array) ( $etat['composants'] ?? [] ),
				static fn( $ligne ): bool => 'en-attente' === ( $ligne['etat'] ?? '' )
			)
		);

		if ( ! $attente ) {
			return $etat;
		}

		// Même verrou que la vérification : cette liste-ci installe des majeures,
		// et deux passes concurrentes sur le même composant se disputeraient son
		// dossier.
		if ( ! self::verrouiller() ) {
			return $etat;
		}

		$composants = Updater::composants();

		try {
			foreach ( $attente as $nom ) {
				if ( ! isset( $composants[ $nom ] ) ) {
					continue;
				}

				Source::oublier( (string) $composants[ $nom ]['repo'] );

				$releases = Source::releases( (string) $composants[ $nom ]['repo'] );

				if ( is_wp_error( $releases ) ) {
					continue;
				}

				$derniere = Updater::derniere_release( $releases, Updater::prefixe_tag( $composants[ $nom ] ) );

				if ( null === $derniere ) {
					continue;
				}

				$depuis   = Installateur::version_installee( $nom );
				$resultat = Installateur::installer( $nom, $derniere['release'], $derniere['version'] );
				$sonde    = Installateur::sonde_du_dernier_essai();

				self::consigner(
					$nom,
					[
						'de'       => $depuis,
						'vers'     => $derniere['version'],
						'resultat' => is_wp_error( $resultat )
							? ( 'anode_updater_sonde' === $resultat->get_error_code() ? 'restauree' : 'echec' )
							: 'installee',
						'sonde'    => $sonde['etat'],
						'message'  => is_wp_error( $resultat )
							? $resultat->get_error_message()
							: ( 'ok' === $sonde['etat'] ? '' : (string) ( $sonde['message'] ?? '' ) ),
					]
				);

				$etat['composants'][ $nom ] = is_wp_error( $resultat )
					? [ 'etat' => 'echec', 'publiee' => $derniere['version'], 'sonde' => $sonde['etat'], 'message' => $resultat->get_error_message() ]
					: [ 'etat' => 'installee', 'installee' => $derniere['version'], 'sonde' => $sonde['etat'] ];

				// Enregistré composant par composant, pour la même raison qu'à la
				// vérification : une interruption ne doit pas effacer ce qui a été
				// installé pour de bon.
				update_site_option( Updater::OPTION_ETAT, $etat );
			}
		} finally {
			self::deverrouiller();
		}

		return $etat;
	}

	/**
	 * Signale dans l'administration ce qui attend, ou ce qui a échoué.
	 *
	 * Un correctif appliqué seul n'a pas à être annoncé : c'est le fonctionnement
	 * normal. Ce qui mérite l'attention, c'est ce qui n'a pas pu se faire.
	 */
	public static function annoncer(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$etat     = (array) get_site_option( Updater::OPTION_ETAT, [] );
		$messages = [];

		foreach ( (array) ( $etat['composants'] ?? [] ) as $nom => $ligne ) {
			if ( in_array( $ligne['etat'] ?? '', [ 'en-attente', 'echec', 'erreur' ], true ) ) {
				$messages[] = sprintf( '%s — %s', $nom, (string) ( $ligne['message'] ?? '' ) );
			}
		}

		if ( ! $messages ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>Mises à jour Anode</strong></p><ul style="margin-left:1.5em;list-style:disc"><li>%s</li></ul></div>',
			implode( '</li><li>', array_map( 'esc_html', $messages ) )
		);
	}
}
