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
					'Dépôt %s introuvable. Les dépôts de releases sont publics : un 404 dit donc '
						. 'qu’il a été renommé, rendu privé, ou que la liste des composants le nomme mal.',
					$repo
				)
			);
		}

		/*
		 * 403 et 429 sont la panne réaliste depuis que le jeton est optionnel : sans
		 * lui, GitHub accorde soixante appels par heure et **par IP**, et les sites
		 * d'un même hébergement partagent cette IP. Le message doit le nommer, sinon
		 * on cherche un dépôt cassé là où il n'y a qu'une attente à observer.
		 */
		if ( in_array( $code, [ 403, 429 ], true ) ) {
			$restant = wp_remote_retrieve_header( $reponse, 'x-ratelimit-remaining' );

			if ( '0' === (string) $restant ) {
				$reprise = (int) wp_remote_retrieve_header( $reponse, 'x-ratelimit-reset' );

				return new \WP_Error(
					'anode_updater_quota',
					sprintf(
						'Quota d’API GitHub épuisé pour cette IP%s. Rien à corriger : la prochaine '
							. 'passe repartira. Un jeton en constante relève le quota de 60 à 5000 appels par heure.',
						$reprise ? sprintf( ' jusqu’à %s', gmdate( 'H:i', $reprise ) . ' UTC' ) : ''
					)
				);
			}
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
	 * ## Deux URL, et l'ordre compte
	 *
	 * Un asset de release en a deux : celle de l'**API**
	 * (`…/releases/assets/<id>`) et le `browser_download_url`, qui passe par
	 * github.com. La seconde ignore le jeton et répond 404 sur un dépôt privé —
	 * constaté en installant pour de vrai, la lecture des releases fonctionnait et
	 * le téléchargement non. C'est ce qui avait fait retenir l'API seule.
	 *
	 * Sur un dépôt **public**, elle fait le même travail **sans consommer le
	 * quota** : soixante appels par heure et par IP sans jeton, et les sites d'un
	 * même hébergement partagent cette IP. Une installation demande trois
	 * téléchargements par composant ; onze composants par l'API en dépenseraient
	 * plus de la moitié.
	 *
	 * On tente donc la publique d'abord, et **on retombe sur l'API si elle
	 * refuse**. Ce repli est ce qui permet de ne rien savoir de la visibilité du
	 * dépôt : un dépôt privé répond 404 sur la publique, et la seconde tentative
	 * porte le jeton. Aucun réglage à tenir, aucun état à deviner.
	 *
	 * ## Et pourquoi l'API se télécharge en deux temps
	 *
	 * Elle répond par une redirection vers un stockage d'objets qui **refuse** une
	 * requête portant déjà un en-tête d'authentification. On récupère donc la
	 * redirection, puis on la suit sans en-tête.
	 *
	 * @param array{url?: string, browser_download_url?: string}|string $asset
	 * @return string|\WP_Error
	 */
	public static function telecharger( $asset ) {
		if ( is_array( $asset ) ) {
			$publique = (string) ( $asset['browser_download_url'] ?? '' );
			$api      = (string) ( $asset['url'] ?? '' );

			if ( '' !== $publique ) {
				$essai = self::tirer( $publique );

				if ( ! is_wp_error( $essai ) ) {
					return $essai;
				}

				/*
				 * Le repli est tenté dès qu'une URL d'API existe — **jeton ou pas**.
				 *
				 * La première écriture le conditionnait au jeton, au motif qu'un dépôt
				 * privé sans jeton échouerait des deux côtés. C'est vrai, et ça rendait
				 * la préférence pour l'URL publique un chemin **unique** au lieu d'un
				 * chemin préféré — précisément sur la configuration cible, un site vendu
				 * sans jeton. Une panne de github.com, un pare-feu sortant qui n'autorise
				 * que l'API, un asset dont l'URL publique répond 404 : plus aucun recours.
				 *
				 * Sur un dépôt public, l'API fonctionne sans jeton. Elle coûte du quota,
				 * et c'est exactement le cas où un coût vaut mieux qu'un échec.
				 */
				if ( '' === $api ) {
					return $essai;
				}
			}

			if ( '' === $api ) {
				return new \WP_Error( 'anode_updater_asset', 'Cet asset de release ne porte aucune URL exploitable.' );
			}

			return self::tirer( $api );
		}

		return self::tirer( (string) $asset );
	}

	/**
	 * Un téléchargement, une URL.
	 *
	 * @return string|\WP_Error
	 */
	private static function tirer( string $url ) {
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
	 * Le jeton, s'il y en a un.
	 *
	 * **Il est optionnel depuis que les dépôts de releases sont publics.** Il
	 * n'ouvre plus rien : il relève seulement le quota d'API, de soixante appels
	 * par heure et par IP à cinq mille. Utile sur un hébergement qui porte
	 * plusieurs sites du parc, inutile ailleurs — et surtout, **jamais nécessaire
	 * sur un site vendu** : un jeton à nous sur le disque d'un client est un
	 * porteur d'autorisation laissé chez un tiers, lisible par toute personne
	 * ayant accès aux fichiers et par toute extension qu'il installera.
	 *
	 * Il se lit dans une constante, jamais en base : une option part avec
	 * l'export, la sauvegarde, et se lit depuis n'importe quelle extension.
	 */
	private static function jeton(): ?string {
		if ( ! defined( 'ANODE_GITHUB_TOKEN' ) || ! is_string( constant( 'ANODE_GITHUB_TOKEN' ) ) ) {
			return null;
		}

		$jeton = trim( (string) constant( 'ANODE_GITHUB_TOKEN' ) );

		return '' === $jeton ? null : $jeton;
	}

	/** Arguments communs des requêtes. */
	private static function arguments( array $extra = [] ): array {
		$entetes = [
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'anode-updater/' . Updater::VERSION,
		];

		$jeton = self::jeton();

		if ( null !== $jeton ) {
			$entetes['Authorization'] = 'Bearer ' . $jeton;
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
