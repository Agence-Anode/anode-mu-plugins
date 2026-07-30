<?php
/**
 * Accès au dépôt GitHub : releases et téléchargements.
 *
 * Tout passe par `wp_remote_get` — aucune dépendance, et le proxy éventuel du
 * site est respecté. Les réponses sont mises en cache : trente sites qui
 * vérifient chaque jour, c'est trente appels par composant, et l'API GitHub
 * limite à soixante par heure sans jeton.
 *
 * @package Anode\Updater
 */

declare( strict_types = 1 );

namespace Anode\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Source {

	/** Durée du cache des releases. */
	private const CACHE = 6 * HOUR_IN_SECONDS;

	/** Releases demandées par page — le maximum accepté par l'API. */
	private const PAR_PAGE = 100;

	/**
	 * Nombre de pages au plus.
	 *
	 * Un garde-fou, pas une limite de conception : mieux vaut un relevé incomplet
	 * qu'un site qui tourne indéfiniment sur l'API GitHub à cause d'une réponse
	 * inattendue.
	 */
	private const PAGES_MAX = 10;

	/**
	 * Releases d'un dépôt, de la plus récente à la plus ancienne.
	 *
	 * @return array<int, array>|\WP_Error
	 */
	public static function releases( string $repo ) {
		$cle    = 'anode_updater_' . md5( $repo );
		$cachee = get_site_transient( $cle );

		if ( is_array( $cachee ) ) {
			return $cachee;
		}

		$releases = [];

		// Le dépôt commun porte les releases de tous les composants, rendues par
		// date décroissante et sans filtre par tag côté API. Passé cent releases,
		// celles d'un composant peu publié basculent en page deux : le composant
		// paraît alors n'avoir jamais rien publié, et cesse d'être mis à jour sans
		// que rien ne le signale. On tourne donc les pages.
		for ( $page = 1; $page <= self::PAGES_MAX; $page++ ) {
			$lot = self::lot( $repo, $page );

			if ( is_wp_error( $lot ) ) {
				return $lot;
			}

			$releases = array_merge( $releases, $lot );

			// Une page incomplète est la dernière.
			if ( count( $lot ) < self::PAR_PAGE ) {
				break;
			}
		}

		set_site_transient( $cle, $releases, self::CACHE );

		return $releases;
	}

	/**
	 * Une page de releases.
	 *
	 * @return array<int, array>|\WP_Error
	 */
	private static function lot( string $repo, int $page ) {
		$reponse = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/releases?per_page=%d&page=%d', $repo, self::PAR_PAGE, $page ),
			self::arguments()
		);

		if ( is_wp_error( $reponse ) ) {
			return $reponse;
		}

		$code = wp_remote_retrieve_response_code( $reponse );

		if ( 404 === $code ) {
			return new \WP_Error(
				'anode_updater_depot',
				sprintf(
					'Dépôt %s introuvable. S’il est privé, définissez ANODE_GITHUB_TOKEN dans wp-config.php.',
					$repo
				)
			);
		}

		if ( 200 !== $code ) {
			return new \WP_Error(
				'anode_updater_api',
				sprintf( 'GitHub a répondu %d pour %s.', $code, $repo )
			);
		}

		$releases = json_decode( wp_remote_retrieve_body( $reponse ), true );

		// Une liste, et rien d'autre : la pagination se décide en comptant les
		// entrées, et un objet d'erreur renvoyé avec un code 200 en fournirait une.
		if ( ! is_array( $releases ) || ! array_is_list( $releases ) ) {
			return new \WP_Error( 'anode_updater_reponse', sprintf( 'Réponse illisible de GitHub pour %s.', $repo ) );
		}

		return $releases;
	}

	/** Vide le cache — après une publication, pour ne pas attendre six heures. */
	public static function oublier( string $repo ): void {
		delete_site_transient( 'anode_updater_' . md5( $repo ) );
	}

	/**
	 * Télécharge un fichier d'une release et renvoie son chemin local.
	 *
	 * L'URL attendue est celle de **l'API** (`…/releases/assets/<id>`), pas le
	 * `browser_download_url` : celui-ci passe par github.com, qui ignore le jeton
	 * d'API et répond 404 sur un dépôt privé. Constaté en installant pour de
	 * vrai — la lecture des releases fonctionnait, le téléchargement non.
	 *
	 * En deux temps, et c'est nécessaire : l'API répond par une redirection vers
	 * un stockage d'objets qui **refuse** une requête portant déjà un en-tête
	 * d'authentification. On récupère donc la redirection, puis on la suit sans
	 * en-tête.
	 *
	 * @return string|\WP_Error
	 */
	public static function telecharger( string $url ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$reponse = wp_remote_get(
			$url,
			self::arguments( [
				'timeout'     => 120,
				'redirection' => 0,
				'headers'     => [ 'Accept' => 'application/octet-stream' ],
			] )
		);

		if ( is_wp_error( $reponse ) ) {
			return $reponse;
		}

		$code = wp_remote_retrieve_response_code( $reponse );

		if ( in_array( $code, [ 301, 302, 307 ], true ) ) {
			$destination = wp_remote_retrieve_header( $reponse, 'location' );

			if ( ! $destination ) {
				return new \WP_Error( 'anode_updater_redirection', 'Redirection sans destination lors du téléchargement.' );
			}

			// Sans en-tête d'authentification : le stockage signe l'URL lui-même.
			$reponse = wp_remote_get( $destination, [ 'timeout' => 120, 'redirection' => 5 ] );

			if ( is_wp_error( $reponse ) ) {
				return $reponse;
			}

			$code = wp_remote_retrieve_response_code( $reponse );
		}

		if ( 200 !== $code ) {
			return new \WP_Error(
				'anode_updater_telechargement',
				sprintf( 'Téléchargement refusé (%d) : %s', $code, $url )
			);
		}

		$corps = wp_remote_retrieve_body( $reponse );

		if ( '' === $corps ) {
			return new \WP_Error( 'anode_updater_vide', sprintf( 'Archive vide : %s', $url ) );
		}

		$fichier = wp_tempnam( 'anode-updater' );

		if ( ! $fichier ) {
			return new \WP_Error( 'anode_updater_tmp', 'Impossible de créer un fichier temporaire.' );
		}

		if ( false === file_put_contents( $fichier, $corps ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new \WP_Error( 'anode_updater_ecriture', 'Impossible d’écrire l’archive téléchargée.' );
		}

		return $fichier;
	}

	/**
	 * Arguments communs des requêtes.
	 *
	 * Le jeton se lit dans une constante, jamais en base : une option est
	 * exportée avec la base, sauvegardée, et lisible par toute extension. Un
	 * jeton en lecture seule, restreint à ces dépôts, suffit.
	 */
	private static function arguments( array $extra = [] ): array {
		$entetes = [
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'anode-updater/' . Updater::VERSION,
		];

		if ( defined( 'ANODE_GITHUB_TOKEN' ) && is_string( constant( 'ANODE_GITHUB_TOKEN' ) ) && '' !== constant( 'ANODE_GITHUB_TOKEN' ) ) {
			$entetes['Authorization'] = 'Bearer ' . constant( 'ANODE_GITHUB_TOKEN' );
		}

		$arguments = [
			'timeout'     => 20,
			'redirection' => 5,
			'headers'     => $entetes,
		];

		if ( isset( $extra['headers'] ) ) {
			$extra['headers'] = array_merge( $entetes, $extra['headers'] );
		}

		return array_merge( $arguments, $extra );
	}
}
