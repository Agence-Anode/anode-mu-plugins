<?php
/**
 * Installe une version : télécharge, contrôle, remplace, garde l'ancienne.
 *
 * L'ordre compte. Tout est vérifié **avant** de toucher au dossier en place, et
 * l'ancienne version est mise de côté plutôt que supprimée : une mise à jour
 * automatique sur le site d'un client doit pouvoir se défaire sans accès au
 * dépôt.
 *
 * @package Anode\Updater
 */

declare( strict_types = 1 );

namespace Anode\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Installateur {

	/**
	 * Verdict de la sonde du dernier essai d'installation.
	 *
	 * Le retour de `installer()` ne dit que « installé » ou « refusé ». Or une
	 * installation réussie **dont la sonde n'a rien pu conclure** n'est pas la
	 * même chose qu'une installation constatée bonne : la première mérite une
	 * ligne au journal, sans quoi personne ne saurait que le site n'a jamais été
	 * vérifié après écriture.
	 *
	 * @var array{etat: string, message: string}
	 */
	private static array $sonde = [ 'etat' => 'indetermine', 'message' => 'Aucune sonde : rien n’a été installé.' ];

	/** @return array{etat: string, message: string} */
	public static function sonde_du_dernier_essai(): array {
		return self::$sonde;
	}

	/**
	 * Installe la version publiée d'un composant.
	 *
	 * @return true|\WP_Error
	 */
	public static function installer( string $nom, array $release, string $version ) {
		self::$sonde = [ 'etat' => 'indetermine', 'message' => 'Aucune sonde : rien n’a été installé.' ];

		$archive = Updater::archive( (array) ( $release['assets'] ?? [] ), $nom, $version );

		if ( null === $archive ) {
			return new \WP_Error(
				'anode_updater_archive',
				sprintf(
					'La release %s ne publie pas « %s-%s.zip ». Construisez-la avec bin/release-mu-plugin.mjs.',
					(string) ( $release['tag_name'] ?? '?' ),
					$nom,
					$version
				)
			);
		}

		$fichier = Source::telecharger( $archive['archive'] );

		if ( is_wp_error( $fichier ) ) {
			return $fichier;
		}

		$verdict = self::verifier_empreinte( $fichier, $archive['empreinte'] );

		if ( is_wp_error( $verdict ) ) {
			self::effacer( $fichier );

			return $verdict;
		}

		$verdict = self::verifier_signature( $fichier, $archive['signature'] ?? null, $nom );

		if ( is_wp_error( $verdict ) ) {
			self::effacer( $fichier );

			return $verdict;
		}

		$extrait = self::extraire( $fichier, $nom );

		self::effacer( $fichier );

		if ( is_wp_error( $extrait ) ) {
			return $extrait;
		}

		$verdict = self::verifier_contenu( $extrait, $nom, $version );

		if ( is_wp_error( $verdict ) ) {
			self::effacer( dirname( $extrait ) );

			return $verdict;
		}

		/*
		 * L'état du site **avant** la bascule.
		 *
		 * Sans ce relevé, un site déjà en erreur ferait accuser la première mise à
		 * jour qui passe : elle serait défaite, réessayée le lendemain, défaite à
		 * nouveau, indéfiniment — et la vraie panne, elle, resterait.
		 */
		$avant = Sonde::verifier();

		$verdict = self::remplacer( $extrait, $nom, $version, $avant );

		self::effacer( dirname( $extrait ) );

		return $verdict;
	}

	/* -------------------------------------------------------------- */

	/**
	 * Compare l'empreinte de l'archive à celle publiée.
	 *
	 * Sans empreinte publiée, on ne bloque pas : le contrôle du contenu qui suit
	 * reste la vraie barrière. Mais quand elle existe, un écart signifie que
	 * l'archive n'est pas celle qui a été publiée — on s'arrête là.
	 *
	 * Et l'empreinte devient alors **obligatoire** : renoncer au contrôle parce
	 * que le `.sha256` est injoignable, c'est le rendre facultatif pour qui sait
	 * faire échouer une requête — précisément ce contre quoi il protège.
	 *
	 * @return true|\WP_Error
	 */
	private static function verifier_empreinte( string $fichier, ?array $empreinte ) {
		if ( null === $empreinte ) {
			return true;
		}

		$publiee = Source::telecharger( $empreinte );

		if ( is_wp_error( $publiee ) ) {
			return new \WP_Error(
				'anode_updater_empreinte_illisible',
				sprintf( 'Empreinte publiée illisible, installation refusée : %s', $publiee->get_error_message() )
			);
		}

		$attendue = trim( (string) file_get_contents( $publiee ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		self::effacer( $publiee );

		// Le fichier peut porter « <empreinte>  <nom> », comme le produit shasum.
		$attendue = strtok( $attendue, " \t\n" );
		$calculee = hash_file( 'sha256', $fichier );

		if ( ! is_string( $attendue ) || '' === $attendue || $calculee !== $attendue ) {
			return new \WP_Error(
				'anode_updater_empreinte',
				sprintf( 'Empreinte SHA-256 différente de celle publiée (%s attendue, %s obtenue).', (string) $attendue, (string) $calculee )
			);
		}

		return true;
	}

	/**
	 * Compare la signature détachée de l'archive à la clé de confiance du site.
	 *
	 * Sans clé de confiance, il n'y a rien à vérifier — c'est l'état d'un site
	 * tant que `ANODE_UPDATER_CLE` n'est pas posée et que le manifeste n'en porte
	 * pas. Avec une clé, en revanche, la signature devient **obligatoire** :
	 * l'accepter absente rendrait le contrôle facultatif pour qui publie la
	 * release, c'est-à-dire pour l'attaquant exact contre lequel il protège.
	 *
	 * @return true|\WP_Error
	 */
	private static function verifier_signature( string $fichier, ?array $signature, string $nom ) {
		$cle   = Updater::cle_publique();
		$motif = Updater::refus_signature( $cle, null !== $signature );

		if ( null !== $motif ) {
			return new \WP_Error( 'anode_updater_signature_absente', sprintf( '%s : %s.', $nom, $motif ) );
		}

		if ( null === $cle || '' === $cle || null === $signature ) {
			return true;
		}

		$publiee = Source::telecharger( $signature );

		if ( is_wp_error( $publiee ) ) {
			return new \WP_Error(
				'anode_updater_signature_illisible',
				sprintf( 'Signature publiée illisible, installation refusée : %s', $publiee->get_error_message() )
			);
		}

		$valeur = trim( (string) file_get_contents( $publiee ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		self::effacer( $publiee );

		$motif = Signature::verifier_fichier( $fichier, $valeur, $cle );

		return null === $motif
			? true
			: new \WP_Error( 'anode_updater_signature', sprintf( '%s : %s.', $nom, $motif ) );
	}

	/**
	 * Décompresse l'archive et renvoie le dossier du composant.
	 *
	 * @return string|\WP_Error
	 */
	private static function extraire( string $fichier, string $nom ) {
		if ( ! self::filesystem() ) {
			return new \WP_Error( 'anode_updater_filesystem', 'WP_Filesystem indisponible : mise à jour impossible sans accès en écriture.' );
		}

		$destination = trailingslashit( get_temp_dir() ) . 'anode-updater-' . wp_generate_password( 8, false, false );
		$echec       = self::echec( unzip_file( $fichier, $destination ), 'anode_updater_extraction', 'Décompression de l’archive impossible.' );

		if ( null !== $echec ) {
			// Une décompression interrompue laisse des fichiers derrière elle.
			self::effacer( $destination );

			return $echec;
		}

		$dossier = trailingslashit( $destination ) . $nom;

		if ( ! is_dir( $dossier ) ) {
			self::effacer( $destination );

			return new \WP_Error(
				'anode_updater_structure',
				sprintf( 'L’archive ne contient pas de dossier « %s » à sa racine.', $nom )
			);
		}

		return $dossier;
	}

	/**
	 * L'archive contient-elle bien ce que la release annonce ?
	 *
	 * @return true|\WP_Error
	 */
	private static function verifier_contenu( string $dossier, string $nom, string $version ) {
		$principal = trailingslashit( $dossier ) . $nom . '.php';

		if ( ! is_readable( $principal ) ) {
			return new \WP_Error(
				'anode_updater_fichier',
				sprintf( 'L’archive ne contient pas %s/%s.php.', $nom, $nom )
			);
		}

		$contenu  = (string) file_get_contents( $principal ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$annoncee = Updater::version_en_tete( $contenu );

		if ( null === $annoncee ) {
			return new \WP_Error( 'anode_updater_entete', 'Le fichier principal ne déclare pas de version.' );
		}

		if ( $annoncee !== $version ) {
			return new \WP_Error(
				'anode_updater_desaccord',
				sprintf( 'La release annonce %s, le fichier livré déclare %s.', $version, $annoncee )
			);
		}

		$incompatible = Updater::incompatibilite( $contenu, PHP_VERSION, (string) get_bloginfo( 'version' ) );

		if ( null !== $incompatible ) {
			return new \WP_Error( 'anode_updater_incompatible', sprintf( '%s %s.', $nom, $incompatible ) );
		}

		return self::verifier_ancrage( $dossier, $nom );
	}

	/**
	 * L'archive change-t-elle la clé à laquelle ce site fait confiance ?
	 *
	 * Seule celle de `anode-updater` le peut : elle embarque `composants.json`,
	 * donc la clé publique. C'est la faille évidente d'une clé qui voyage avec le
	 * code — un dépôt compromis publierait un updater portant **sa** clé, signé
	 * par elle, et le site l'accepterait sans broncher. La signature ne
	 * protégerait alors que jusqu'à la première mise à jour de l'updater.
	 *
	 * @return true|\WP_Error
	 */
	private static function verifier_ancrage( string $dossier, string $nom ) {
		$manifeste = trailingslashit( $dossier ) . 'composants.json';

		if ( ! is_readable( $manifeste ) ) {
			return true;
		}

		$document = json_decode( (string) file_get_contents( $manifeste ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$entrante = is_array( $document ) && is_string( $document['cle_publique'] ?? null ) ? $document['cle_publique'] : '';

		$motif = Updater::rotation_refusee( Updater::cle_publique(), $entrante, Updater::cle_epinglee() );

		return null === $motif
			? true
			: new \WP_Error( 'anode_updater_cle', sprintf( '%s : %s.', $nom, $motif ) );
	}

	/**
	 * Met la nouvelle version en place, l'ancienne de côté.
	 *
	 * La bascule se fait par renommages, jamais en écrivant dans le dossier en
	 * place : copier quelques dizaines de fichiers prend plusieurs centaines de
	 * millisecondes, et toute requête tombant dans cet intervalle chargeait un
	 * mu-plugin incomplet — une erreur fatale, qui sur un mu-plugin emporte
	 * l'administration avec le site public. Deux renommages ramènent la fenêtre à
	 * deux appels système.
	 *
	 * @param array{etat: string, message: string} $avant État du site avant la bascule.
	 * @return true|\WP_Error
	 */
	private static function remplacer( string $source, string $nom, string $version, array $avant ) {
		global $wp_filesystem;

		$racine  = trailingslashit( WPMU_PLUGIN_DIR );
		$cible   = $racine . $nom;
		$archives = $racine . Updater::DOSSIER_VERSIONS;

		if ( ! $wp_filesystem->is_writable( $racine ) ) {
			return new \WP_Error(
				'anode_updater_ecriture',
				sprintf( '%s n’est pas accessible en écriture : mise à jour impossible.', $racine )
			);
		}

		// La nouvelle version est montée à côté de l'ancienne, dans le même
		// dossier : un renommage n'est atomique que sur le même système de
		// fichiers, ce que le dossier temporaire du système ne garantit pas.
		$transit = $racine . self::dossier_transit( $nom );

		$wp_filesystem->delete( $transit, true );

		// La destination est créée ici, et non laissée à `copy_dir()`, pour que son
		// refus soit nommé : un mkdir impossible dans mu-plugins, c'est un dossier
		// en lecture seule, pas une archive en défaut.
		if ( ! $wp_filesystem->mkdir( $transit ) ) {
			return new \WP_Error( 'anode_updater_transit', sprintf( 'Impossible de préparer %s.', $transit ) );
		}

		$echec = self::echec( copy_dir( $source, $transit ), 'anode_updater_copie', sprintf( 'Impossible de préparer %s.', $nom ) );

		if ( null !== $echec ) {
			self::effacer( $transit );

			return $echec;
		}

		$precedente = null;

		if ( $wp_filesystem->is_dir( $cible ) ) {
			$installee  = self::version_installee( $nom );
			$precedente = trailingslashit( $archives ) . $nom . '-' . ( $installee ?? 'inconnue' );

			/*
			 * Le dossier des versions précédentes, nommé quand il ne se crée pas.
			 *
			 * Son échec ressortait sous « Impossible de mettre X de côté. » — le
			 * message du `move()` qui suit —, ce qui envoyait chercher la cause au
			 * mauvais endroit : le déplacement n'a pas échoué, c'est sa destination
			 * qui n'existe pas.
			 */
			if ( ! $wp_filesystem->is_dir( $archives ) && ! $wp_filesystem->mkdir( $archives ) ) {
				self::effacer( $transit );

				return new \WP_Error(
					'anode_updater_archives',
					sprintf( 'Impossible de créer %s : la version précédente ne peut pas être conservée.', Updater::DOSSIER_VERSIONS )
				);
			}

			$wp_filesystem->delete( $precedente, true );

			$echec = self::echec( $wp_filesystem->move( $cible, $precedente, true ), 'anode_updater_deplacement', sprintf( 'Impossible de mettre %s de côté.', $nom ) );

			if ( null !== $echec ) {
				self::effacer( $transit );

				return $echec;
			}
		}

		$echec = self::echec( $wp_filesystem->move( $transit, $cible, true ), 'anode_updater_copie', sprintf( 'Impossible d’installer %s.', $nom ) );

		/*
		 * L'OPcache s'invalide **après** la bascule, sur les fichiers installés.
		 *
		 * `copy_dir()` le fait au fil de sa copie — c'était même la seule chose du
		 * chemin d'installation à le faire — mais elle copie désormais vers le
		 * dossier de transit : l'invalidation portait sur `.<nom>-nouveau/…`, pas
		 * sur `<nom>/…`. Sur un hébergement en `opcache.validate_timestamps=0` —
		 * le réglage recommandé en production — l'ancien code restait servi après
		 * une mise à jour réussie, jusqu'au prochain redémarrage de PHP.
		 *
		 * Le cœur de WordPress fait la même chose dans `move_dir()`, qui termine
		 * par `wp_opcache_invalidate_directory( $to )`.
		 */
		if ( null === $echec ) {
			self::invalider_opcache( $cible );
		}

		if ( null !== $echec ) {
			$message = $echec->get_error_message();

			// Remettre l'ancienne : un mu-plugin absent vaut mieux qu'un site à
			// moitié mis à jour, mais un mu-plugin intact vaut mieux encore. Et le
			// retour arrière est constaté, non supposé : annoncer une remise qui n'a
			// pas eu lieu, c'est laisser un site sans son composant en croyant le
			// contraire.
			if ( null !== $precedente ) {
				$message .= true === $wp_filesystem->move( $precedente, $cible, true )
					? ' L’ancienne version a été remise.'
					: sprintf( ' L’ancienne version est restée dans %s/ : à remettre à la main.', Updater::DOSSIER_VERSIONS );
			}

			self::effacer( $transit );

			return new \WP_Error( 'anode_updater_copie', $message );
		}

		$refus = self::eprouver( $nom, $version, $precedente, $avant );

		if ( null !== $refus ) {
			return $refus;
		}

		self::elaguer( $archives, $nom, $precedente );

		/**
		 * Un composant vient d'être mis à jour.
		 *
		 * Sert par exemple à purger le cache de page, dont le contenu peut
		 * dépendre du code qui vient de changer.
		 */
		do_action( 'anode/updater/installe', $nom, $version, $precedente );

		return true;
	}

	/**
	 * Le site tient-il encore debout avec la version qu'on vient d'écrire ?
	 *
	 * C'est le seul contrôle qui porte sur le site plutôt que sur l'archive. Les
	 * autres valident un fichier ; celui-ci charge le composant pour de vrai,
	 * dans un vrai processus, avec la vraie configuration — ce que ni `php -l` ni
	 * un en-tête ne savent faire. Une fonction absente, une classe non livrée, un
	 * `use` vers un fichier oublié dans l'archive : rien de tout cela ne se voit
	 * avant l'exécution.
	 *
	 * On ne conclut que sur ce qui est attribuable. Un site déjà en erreur avant
	 * la bascule, une boucle locale coupée, un htpasswd : la sonde le note et
	 * s'abstient. Défaire une mise à jour saine chaque nuit, sur un site dont la
	 * panne est ailleurs, coûterait plus cher que le risque couvert.
	 *
	 * @return \WP_Error|null Motif du retrait, ou null si la version reste en place.
	 */
	private static function eprouver( string $nom, string $version, ?string $precedente, array $avant ): ?\WP_Error {
		if ( 'ok' !== ( $avant['etat'] ?? '' ) ) {
			self::$sonde = [
				'etat'    => 'indetermine',
				'message' => sprintf(
					'Version installée sans vérification après écriture : la sonde ne concluait déjà pas avant la bascule (%s)',
					(string) ( $avant['message'] ?? '' )
				),
			];

			return null;
		}

		// Après écriture : un relevé **neuf**, pas celui mémorisé du début de passe.
		self::$sonde = Sonde::verifier( true );

		if ( 'echec' !== self::$sonde['etat'] ) {
			return null;
		}

		$remise = self::restaurer( $nom, $precedente );

		return new \WP_Error(
			'anode_updater_sonde',
			sprintf(
				'%s %s installé puis retiré. %s %s',
				$nom,
				$version,
				self::$sonde['message'],
				$remise
					? ( null === $precedente ? 'La version fautive a été supprimée.' : 'L’ancienne version a été remise en place.' )
					: sprintf( 'Le retour en arrière a échoué : %s est peut-être hors service, à reprendre à la main.', $nom )
			)
		);
	}

	/**
	 * Remet l'ancienne version, ou retire la nouvelle faute de mieux.
	 *
	 * Par renommages, pour la même raison que la bascule : supprimer d'abord
	 * laisserait le site sans le composant si la remise échouait ensuite — et sur
	 * `anode-hardening` ou `anode-seo`, cela se voit tout de suite en ligne.
	 */
	private static function restaurer( string $nom, ?string $precedente ): bool {
		global $wp_filesystem;

		$racine = trailingslashit( WPMU_PLUGIN_DIR );
		$cible  = $racine . $nom;

		// Caché, comme le dossier de transit : le chargeur parcourt
		// `glob( '*', GLOB_ONLYDIR )`, qui ignore les dossiers en point. Une
		// version dont on vient de constater qu'elle tue le site n'a rien à faire
		// dans son champ, même une seconde.
		$rebut = $racine . '.' . $nom . '-refusee';

		$wp_filesystem->delete( $rebut, true );

		if ( $wp_filesystem->is_dir( $cible ) && true !== $wp_filesystem->move( $cible, $rebut, true ) ) {
			return false;
		}

		if ( null !== $precedente && true !== $wp_filesystem->move( $precedente, $cible, true ) ) {
			// La remise a échoué : remettre au moins la version fautive plutôt que
			// de laisser le site sans composant du tout. Elle casse le site, mais
			// elle est nommée dans l'erreur, et le dossier de secours est intact.
			$wp_filesystem->move( $rebut, $cible, true );

			return false;
		}

		$wp_filesystem->delete( $rebut, true );
		self::invalider_opcache( $cible );

		return true;
	}

	/**
	 * Dossier de montage de la nouvelle version, voisin de sa cible.
	 *
	 * Le point de tête n'est pas décoratif : le chargeur de mu-plugins parcourt
	 * `glob( '*', GLOB_ONLYDIR )`, qui ne renvoie pas les dossiers cachés. Sans
	 * lui, un composant à moitié copié serait candidat au chargement.
	 */
	public static function dossier_transit( string $nom ): string {
		return '.' . $nom . '-nouveau';
	}

	/**
	 * Invalide l'OPcache pour un dossier installé.
	 *
	 * `wp_opcache_invalidate_directory()` n'existe que depuis WordPress 6.2 : on
	 * s'en sert quand elle est là, et l'on retombe sinon sur un parcours qui
	 * appelle `wp_opcache_invalidate()`, présente depuis la 5.5. Sans OPcache du
	 * tout, ces fonctions ne font rien — comportement voulu.
	 */
	private static function invalider_opcache( string $dossier ): void {
		if ( function_exists( 'wp_opcache_invalidate_directory' ) ) {
			wp_opcache_invalidate_directory( $dossier );

			return;
		}

		if ( ! function_exists( 'wp_opcache_invalidate' ) || ! is_dir( $dossier ) ) {
			return;
		}

		$iterateur = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dossier, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterateur as $fichier ) {
			if ( $fichier instanceof \SplFileInfo && 'php' === strtolower( $fichier->getExtension() ) ) {
				wp_opcache_invalidate( $fichier->getPathname(), true );
			}
		}
	}

	/**
	 * Motif d'échec d'une opération de fichiers, ou null si elle a réussi.
	 *
	 * `copy_dir()` et `unzip_file()` rendent `true` ou un **WP_Error** — jamais
	 * `false`. Tester `! $resultat` prenait donc tout échec pour une réussite, un
	 * objet d'erreur étant vrai : la copie ratée était enregistrée comme une
	 * installation faite, la sauvegarde n'était jamais remise, et le composant
	 * restait amputé jusqu'à ce qu'on le constate à la main. Les méthodes de
	 * `WP_Filesystem`, elles, rendent un booléen : les deux formes se testent ici,
	 * et seul `true` vaut succès.
	 *
	 * @param mixed $resultat Retour de copy_dir, unzip_file ou WP_Filesystem.
	 */
	private static function echec( $resultat, string $code, string $message ): ?\WP_Error {
		if ( is_wp_error( $resultat ) ) {
			return new \WP_Error( $code, sprintf( '%s %s', $message, $resultat->get_error_message() ) );
		}

		return true === $resultat ? null : new \WP_Error( $code, $message );
	}

	/**
	 * Ne garde qu'une version de secours par composant.
	 *
	 * Sans cela, `.anode-versions/` accumule une copie à chaque mise à jour :
	 * quelques kilo-octets, mais indéfiniment, et sur tous les sites. Une seule
	 * suffit — revenir deux versions en arrière se fait depuis le dépôt.
	 */
	private static function elaguer( string $archives, string $nom, ?string $garder ): void {
		global $wp_filesystem;

		$entrees = $wp_filesystem->dirlist( $archives );

		// `dirlist()` rend `false` quand le dossier n'existe pas — la première
		// installation d'un composant, où il n'y a rien à élaguer. Le convertir en
		// tableau donnait `[ false ]`, donc deux avertissements PHP par install.
		if ( ! is_array( $entrees ) ) {
			return;
		}

		foreach ( $entrees as $entree ) {
			$chemin = trailingslashit( $archives ) . $entree['name'];

			if ( ! str_starts_with( (string) $entree['name'], $nom . '-' ) || $chemin === $garder ) {
				continue;
			}

			$wp_filesystem->delete( $chemin, true );
		}
	}

	/** Version actuellement installée, lue dans l'en-tête. */
	public static function version_installee( string $nom ): ?string {
		$fichier = trailingslashit( WPMU_PLUGIN_DIR ) . $nom . '/' . $nom . '.php';

		if ( ! is_readable( $fichier ) ) {
			return null;
		}

		return Updater::version_en_tete( (string) file_get_contents( $fichier ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/** Initialise WP_Filesystem en accès direct. */
	private static function filesystem(): bool {
		global $wp_filesystem;

		if ( $wp_filesystem ) {
			return true;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		return (bool) WP_Filesystem();
	}

	private static function effacer( string $chemin ): void {
		global $wp_filesystem;

		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $chemin, true );

			return;
		}

		if ( is_file( $chemin ) ) {
			unlink( $chemin ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
}
