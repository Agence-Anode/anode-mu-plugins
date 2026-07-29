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
	 * Installe la version publiée d'un composant.
	 *
	 * @return true|\WP_Error
	 */
	public static function installer( string $nom, array $composant, array $release, string $version ) {
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

		$fichier = Source::telecharger( (string) $archive['archive']['browser_download_url'] );

		if ( is_wp_error( $fichier ) ) {
			return $fichier;
		}

		$verdict = self::verifier_empreinte( $fichier, $archive['empreinte'] );

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

		$verdict = self::remplacer( $extrait, $nom, $version );

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
	 * @return true|\WP_Error
	 */
	private static function verifier_empreinte( string $fichier, ?array $empreinte ) {
		if ( null === $empreinte ) {
			return true;
		}

		$publiee = Source::telecharger( (string) $empreinte['browser_download_url'] );

		if ( is_wp_error( $publiee ) ) {
			return true;
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
	 * Décompresse l'archive et renvoie le dossier du composant.
	 *
	 * @return string|\WP_Error
	 */
	private static function extraire( string $fichier, string $nom ) {
		if ( ! self::filesystem() ) {
			return new \WP_Error( 'anode_updater_filesystem', 'WP_Filesystem indisponible : mise à jour impossible sans accès en écriture.' );
		}

		$destination = trailingslashit( get_temp_dir() ) . 'anode-updater-' . wp_generate_password( 8, false, false );
		$resultat    = unzip_file( $fichier, $destination );

		if ( is_wp_error( $resultat ) ) {
			return $resultat;
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

		return true;
	}

	/**
	 * Met la nouvelle version en place, l'ancienne de côté.
	 *
	 * @return true|\WP_Error
	 */
	private static function remplacer( string $source, string $nom, string $version ) {
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

		$precedente = null;

		if ( $wp_filesystem->is_dir( $cible ) ) {
			$installee  = self::version_installee( $nom );
			$precedente = trailingslashit( $archives ) . $nom . '-' . ( $installee ?? 'inconnue' );

			$wp_filesystem->mkdir( $archives );
			$wp_filesystem->delete( $precedente, true );

			if ( ! $wp_filesystem->move( $cible, $precedente, true ) ) {
				return new \WP_Error( 'anode_updater_deplacement', sprintf( 'Impossible de mettre %s de côté.', $nom ) );
			}
		}

		if ( ! copy_dir( $source, $cible ) ) {
			// Remettre l'ancienne : un mu-plugin absent vaut mieux qu'un site à
			// moitié mis à jour, mais un mu-plugin intact vaut mieux encore.
			if ( null !== $precedente ) {
				$wp_filesystem->move( $precedente, $cible, true );
			}

			return new \WP_Error( 'anode_updater_copie', sprintf( 'Impossible d’installer %s : l’ancienne version a été remise.', $nom ) );
		}

		/**
		 * Un composant vient d'être mis à jour.
		 *
		 * Sert par exemple à purger le cache de page, dont le contenu peut
		 * dépendre du code qui vient de changer.
		 */
		do_action( 'anode/updater/installe', $nom, $version, $precedente );

		return true;
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
