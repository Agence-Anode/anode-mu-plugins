<?php
/**
 * Plugin Name: Anode — redirections
 * Description: Redirections 301 depuis les anciennes URL d'un site refondu, lues dans un fichier versionné du thème. Remplace Redirection et consorts.
 * Version:     1.0.0
 * Author:      Anode
 *
 * Une refonte casse les adresses. Sans redirections, chaque lien entrant — un
 * moteur de recherche, un article de presse, un signet, un QR code imprimé —
 * tombe sur une page d'erreur, et l'antériorité de référencement est perdue.
 * C'est le point le plus souvent oublié d'une mise en ligne, et le plus cher à
 * rattraper : une fois les 404 constatées, le mal est fait.
 *
 * La table vit dans `theme/anode-child/redirections.json`, versionnée avec le
 * site — pas dans une base de données qu'une réinstallation efface, et pas dans
 * un `.htaccess` qu'un hébergeur peut écraser.
 *
 * ```json
 * {
 *   "exactes":  { "/contact-2/": "/nous-contacter/" },
 *   "motifs":   [ { "de": "^/activity/(.+?)/?$", "vers": "/evenements/$1/" } ],
 *   "disparues": [ "/une-page-sans-equivalent/" ]
 * }
 * ```
 *
 * Trois cas, dans cet ordre : une correspondance exacte, puis une expression
 * régulière, puis les adresses sans équivalent — qui répondent **410 Gone**
 * plutôt que 404. La nuance compte : 410 dit à un moteur que la page a
 * disparu pour de bon, là où 404 le fait revenir des mois durant.
 *
 * @package Anode\Redirections
 */

declare( strict_types = 1 );

namespace Anode\Redirections;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '1.0.0';

/**
 * Fichier de correspondances, dans le thème du site.
 */
function table_path(): string {
	return get_stylesheet_directory() . '/redirections.json';
}

/**
 * Lit la table, une seule fois par requête.
 *
 * @return array{exactes: array<string, string>, motifs: array<int, array<string, string>>, disparues: array<int, string>}
 */
function table(): array {
	static $table = null;

	if ( null !== $table ) {
		return $table;
	}

	$vide = [ 'exactes' => [], 'motifs' => [], 'disparues' => [] ];
	$file = table_path();

	if ( ! is_readable( $file ) ) {
		return $table = $vide;
	}

	$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	if ( ! is_array( $data ) ) {
		return $table = $vide;
	}

	return $table = [
		'exactes'   => is_array( $data['exactes'] ?? null ) ? $data['exactes'] : [],
		'motifs'    => is_array( $data['motifs'] ?? null ) ? $data['motifs'] : [],
		'disparues' => is_array( $data['disparues'] ?? null ) ? $data['disparues'] : [],
	];
}

/**
 * Normalise un chemin : minuscules, une seule barre finale, sans requête.
 *
 * Les anciennes adresses circulent dans toutes les variantes — avec ou sans
 * barre finale, avec des majuscules, suivies de paramètres de campagne. Une
 * table qui n'en connaîtrait qu'une seule forme raterait la plupart des liens.
 */
function normalize( string $path ): string {
	$path = (string) wp_parse_url( $path, PHP_URL_PATH );
	$path = strtolower( rawurldecode( $path ) );
	$path = '/' . trim( $path, '/' );

	return '/' === $path ? '/' : $path . '/';
}

/**
 * Cherche la destination d'un chemin.
 *
 * @param array{exactes: array<string, string>, motifs: array<int, array<string, string>>, disparues: array<int, string>} $table
 *
 * @return array{vers: string, statut: int}|null
 */
function resolve( string $path, array $table ): ?array {
	$chemin = normalize( $path );

	foreach ( $table['exactes'] as $de => $vers ) {
		if ( normalize( (string) $de ) === $chemin ) {
			return [ 'vers' => (string) $vers, 'statut' => 301 ];
		}
	}

	foreach ( $table['motifs'] as $motif ) {
		$de = (string) ( $motif['de'] ?? '' );

		if ( '' === $de ) {
			continue;
		}

		// Le délimiteur est le tilde : une expression de chemin contient des
		// barres obliques, qu'il faudrait sinon échapper une à une.
		$resultat = @preg_replace( '~' . $de . '~i', (string) ( $motif['vers'] ?? '' ), $chemin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( null !== $resultat && $resultat !== $chemin ) {
			return [ 'vers' => $resultat, 'statut' => (int) ( $motif['statut'] ?? 301 ) ];
		}
	}

	foreach ( $table['disparues'] as $disparue ) {
		if ( normalize( (string) $disparue ) === $chemin ) {
			return [ 'vers' => '', 'statut' => 410 ];
		}
	}

	return null;
}

/**
 * Applique la redirection, avant que WordPress ne cherche un contenu.
 *
 * Sur `template_redirect` : la requête est résolue, on sait donc qu'aucun
 * contenu ne répond, et l'on n'a pas court-circuité l'administration ni l'API.
 */
add_action(
	'template_redirect',
	static function (): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		/*
		 * Ne rien faire tant qu'une page répond.
		 *
		 * Une redirection posée devant un contenu existant le rendrait
		 * inatteignable — et le diagnostic serait déroutant, puisque la page
		 * est bien là, en base.
		 */
		if ( ! is_404() ) {
			return;
		}

		$cible = resolve( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), table() );

		if ( null === $cible ) {
			return;
		}

		if ( 410 === $cible['statut'] ) {
			status_header( 410 );

			return;
		}

		// Une destination relative reste relative : le site peut changer de
		// domaine — préproduction, production — sans que la table bouge.
		wp_safe_redirect( $cible['vers'], $cible['statut'], 'Anode' );
		exit;
	},
	1
);

/**
 * Signale une table absente là où on la cherchera : l'écran de santé.
 */
add_filter(
	'site_status_tests',
	static function ( array $tests ): array {
		$tests['direct']['anode_redirections'] = [
			'label' => 'Redirections des anciennes URL',
			'test'  => static function (): array {
				$table   = table();
				$nombre  = count( $table['exactes'] ) + count( $table['motifs'] ) + count( $table['disparues'] );
				$presente = is_readable( table_path() );

				return [
					'label'       => $presente
						? sprintf( '%d redirection(s) déclarée(s)', $nombre )
						: 'Aucune table de redirections',
					'status'      => $presente ? 'good' : 'recommended',
					'badge'       => [ 'label' => 'Anode', 'color' => 'blue' ],
					'description' => $presente
						? '<p>Les anciennes adresses du site sont redirigées depuis <code>redirections.json</code>.</p>'
						: '<p>Après une refonte, les anciennes adresses tombent en 404 tant qu’aucune table '
							. 'n’existe. Créez <code>theme/anode-child/redirections.json</code>.</p>',
					'test'        => 'anode_redirections',
				];
			},
		];

		return $tests;
	}
);
