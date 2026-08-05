<?php
/**
 * D'où les quatre pages tirent ce qu'elles affichent.
 *
 * Chaque source lit le **site vivant**, jamais une copie : c'est ce qui rend ces
 * pages incapables de mentir. Aucune ne suppose que le fichier existe — un
 * blueprint tout neuf n'a ni formulaire, ni composant, et la page doit le dire
 * plutôt que tomber.
 *
 * @package Anode\Plan
 */

declare(strict_types=1);

namespace Anode\Plan;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * L'adresse de production, quand elle existe.
 */
final class Liens {

	public static function production(): ?string {
		/**
		 * L'adresse du site en production.
		 *
		 * Elle ne se devine pas depuis le domaine de préproduction : rien ne dit
		 * que `client.anode-staging.fr` deviendra `client.fr`. Elle se déclare,
		 * et tant qu'elle ne l'est pas les boutons « prod ↗ » ne s'affichent
		 * pas — plutôt qu'un lien vers une adresse inventée.
		 */
		$url = defined( 'ANODE_URL_PRODUCTION' ) && is_string( ANODE_URL_PRODUCTION )
			? ANODE_URL_PRODUCTION
			: '';

		$url = (string) apply_filters( 'anode/plan/url_production', $url );

		if ( '' === $url ) {
			return null;
		}

		return esc_url_raw( $url ) ?: null;
	}
}

/**
 * Les définitions de formulaire du thème.
 */
final class Formulaires {

	/** @return array<string, array<string, mixed>> */
	public static function lire(): array {
		$dossier = trailingslashit( get_stylesheet_directory() ) . 'forms';

		if ( ! is_dir( $dossier ) ) {
			return [];
		}

		$out = [];
		foreach ( (array) glob( $dossier . '/*.json' ) as $fichier ) {
			if ( ! is_string( $fichier ) || ! is_readable( $fichier ) ) {
				continue;
			}

			$brut = file_get_contents( $fichier );

			if ( false === $brut ) {
				continue;
			}

			$d = json_decode( $brut, true );

			if ( ! is_array( $d ) ) {
				continue;
			}

			$out[ basename( $fichier, '.json' ) ] = $d;
		}

		ksort( $out );

		return $out;
	}
}

/**
 * La charte, lue dans la feuille de tokens que le thème sert.
 */
final class Charte {

	/**
	 * Les variables CSS déclarées sous `:root`, groupées par famille.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function variables(): array {
		$fichier = trailingslashit( get_stylesheet_directory() ) . 'assets/css/tokens.css';

		if ( ! is_readable( $fichier ) ) {
			return [];
		}

		$css = file_get_contents( $fichier );

		if ( false === $css ) {
			return [];
		}

		if ( ! preg_match_all( '/--([a-z0-9-]+)\s*:\s*([^;}]+)/i', $css, $m, PREG_SET_ORDER ) ) {
			return [];
		}

		$out = [];
		foreach ( $m as $paire ) {
			$nom = $paire[1];
			// La famille est le premier segment : color-brand-500 → color.
			$famille                 = explode( '-', $nom )[0];
			$out[ $famille ][ $nom ] = trim( $paire[2] );
		}

		ksort( $out );

		return $out;
	}

	/**
	 * La poignée de valeurs dont le gabarit a besoin, avec un repli sobre.
	 *
	 * Le repli n'est pas décoratif : sur un site dont la charte n'est pas encore
	 * posée, ces pages doivent rester lisibles — c'est même le moment où l'on en
	 * a le plus besoin.
	 *
	 * @return array<string, string>
	 */
	public static function tokens(): array {
		$v    = self::variables();
		$plat = [];
		foreach ( $v as $famille ) {
			$plat += $famille;
		}

		$prendre = static function ( array $noms, string $repli ) use ( $plat ): string {
			foreach ( $noms as $n ) {
				if ( isset( $plat[ $n ] ) && '' !== $plat[ $n ] && ! str_contains( $plat[ $n ], 'var(' ) ) {
					return $plat[ $n ];
				}
			}

			return $repli;
		};

		return [
			'primary'     => $prendre( [ 'color-brand-500', 'color-brand-600' ], '#1f2937' ),
			'on'          => $prendre( [ 'color-neutral-0' ], '#ffffff' ),
			'ink'         => $prendre( [ 'color-neutral-900' ], '#111827' ),
			'muted'       => $prendre( [ 'color-neutral-600', 'color-neutral-500' ], '#6b7280' ),
			'bg'          => $prendre( [ 'color-neutral-75', 'color-neutral-50' ], '#f9fafb' ),
			'surface'     => $prendre( [ 'color-neutral-0' ], '#ffffff' ),
			'border'      => $prendre( [ 'color-neutral-200', 'color-neutral-100' ], '#e5e7eb' ),
			'fontBody'    => $prendre( [ 'font-family-sans' ], 'system-ui, sans-serif' ),
			'fontHeading' => $prendre( [ 'font-family-display', 'font-family-sans' ], 'system-ui, sans-serif' ),
		];
	}
}

/**
 * Les composants Bricks du site.
 */
final class Composants {

	/** @return array<int, array{label: string, category: string, properties: array<int, string>, instances: int}> */
	public static function lire(): array {
		$brut = get_option( 'bricks_components', [] );

		if ( ! is_array( $brut ) || ! $brut ) {
			return [];
		}

		// Combien d'instances de chaque composant, toutes surfaces confondues ?
		$compte = self::compter_instances();

		$out = [];
		foreach ( $brut as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}

			$id = (string) ( $c['id'] ?? '' );

			$props = [];
			foreach ( (array) ( $c['properties'] ?? [] ) as $p ) {
				if ( is_array( $p ) && isset( $p['label'] ) ) {
					$props[] = (string) $p['label'];
				}
			}

			$out[] = [
				'label'      => (string) ( $c['label'] ?? $id ),
				'category'   => (string) ( $c['category'] ?? '' ),
				'properties' => $props,
				'instances'  => $compte[ $id ] ?? 0,
			];
		}

		usort( $out, static fn ( array $a, array $b ): int => strcmp( $a['label'], $b['label'] ) );

		return $out;
	}

	/**
	 * @return array<string, int>
	 */
	private static function compter_instances(): array {
		global $wpdb;

		$compte = [];

		$lignes = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2')"
		);

		foreach ( (array) $lignes as $valeur ) {
			if ( ! is_string( $valeur ) ) {
				continue;
			}

			// `cid` désigne le composant dont l'élément est une instance.
			if ( preg_match_all( '/"cid";s:\d+:"([a-z0-9]+)"/i', $valeur, $m ) ) {
				foreach ( $m[1] as $id ) {
					$compte[ $id ] = ( $compte[ $id ] ?? 0 ) + 1;
				}
			}
		}

		return $compte;
	}
}
