<?php
/**
 * Plugin Name: Anode — mises à jour
 * Description: Tient à jour les mu-plugins maison depuis leurs dépôts GitHub. Vérifie chaque jour, applique les correctifs et les versions mineures, retient les majeures.
 * Version:     1.0.3
 * Author:      Anode
 * Requires PHP: 8.1
 *
 * WordPress ne sait pas mettre à jour un mu-plugin : il ne les liste même pas.
 * C'est le prix de l'endroit où ils vivent — indésactivables, chargés en
 * premier — et c'est aussi ce qui, sans ce fichier, obligerait à recopier à la
 * main un correctif sur chaque site client.
 *
 * ## Ce qui est automatique, et ce qui ne l'est pas
 *
 * Correctifs (1.2.3 → 1.2.4) et versions mineures (1.2 → 1.3) s'appliquent
 * seules : par convention, elles n'ont pas le droit de casser un site. Les
 * versions **majeures** sont téléchargées mais pas installées — elles attendent
 * une décision, parce qu'une rupture de compatibilité sur trente sites clients
 * ne se découvre pas un dimanche.
 *
 * `ANODE_UPDATER_AUTO` force le comportement : `false` pour ne jamais rien
 * appliquer (vérification seule), `'majeure'` pour tout accepter.
 *
 * ## Ce qui est vérifié avant d'écrire
 *
 * Une mise à jour automatique est une exécution de code distant sur le site d'un
 * client : le contrôle n'est pas une formalité.
 *
 *   1. l'archive vient d'une **release** du dépôt déclaré, jamais d'une branche ;
 *   2. son empreinte SHA-256 correspond à celle publiée, quand elle l'est ;
 *   3. elle contient exactement le dossier attendu, avec son fichier principal ;
 *   4. l'en-tête annonce bien la version de la release ;
 *   5. `Requires PHP` et `Requires at least` sont satisfaits par ce site.
 *
 * La version remplacée est conservée dans `mu-plugins/.anode-versions/`, ce qui
 * rend le retour en arrière possible sans accès au dépôt.
 *
 * ## Dépôt unique ou un dépôt par extension
 *
 * Les deux marchent, et le choix n'engage que la déclaration : un composant
 * nomme son dépôt, et éventuellement son dossier dans ce dépôt. Avec `chemin`,
 * les tags sont préfixés (`anode-cache-v1.2.0`) ; sans lui, ils sont nus
 * (`v1.2.0`).
 *
 * @package Anode\Updater
 */

declare( strict_types = 1 );

namespace Anode\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Updater {

	public const VERSION = '1.0.3';

	/** Où l'on retient le résultat de la dernière vérification. */
	public const OPTION_ETAT = 'anode_updater_etat';

	/** Crochet de la vérification quotidienne. */
	public const CRON = 'anode_updater_verifier';

	/** Dossier des versions remplacées, sous mu-plugins. */
	public const DOSSIER_VERSIONS = '.anode-versions';

	/** Paliers de version, du plus anodin au plus risqué. */
	public const PALIERS = [ 'correctif', 'mineure', 'majeure' ];

	/**
	 * Composants suivis, et où les trouver.
	 *
	 * `chemin` est le dossier dans le dépôt, à omettre si le dépôt ne contient
	 * qu'un composant. `fichier` est le fichier principal, déduit du nom par
	 * défaut.
	 *
	 * @return array<string, array{repo: string, chemin?: string, fichier?: string}>
	 */
	public static function composants(): array {
		$defaut = self::manifeste();

		/**
		 * Permet à un site d'ajouter son propre composant, ou d'en retirer un.
		 *
		 * Le modèle de contenu d'un site — `<slug>-contenu` — n'a rien à faire
		 * ici : il est propre au projet et vit dans son dépôt.
		 */
		$composants = (array) apply_filters( 'anode/updater/composants', $defaut );

		return array_filter(
			$composants,
			static function ( $declaration, $nom ): bool {
				return is_string( $nom ) && is_array( $declaration ) && ! empty( $declaration['repo'] );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Manifeste livré avec l'extension.
	 *
	 * Le même fichier sert à la publication (`bin/release-mu-plugin.mjs`) : une
	 * seule liste, donc aucune dérive possible entre ce qui est publié et ce que
	 * les sites attendent.
	 *
	 * @return array<string, array{repo: string, chemin?: string}>
	 */
	public static function manifeste(): array {
		$fichier = __DIR__ . '/composants.json';

		if ( ! is_readable( $fichier ) ) {
			return [];
		}

		$document = json_decode( (string) file_get_contents( $fichier ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return is_array( $document ) && is_array( $document['composants'] ?? null )
			? $document['composants']
			: [];
	}

	/* -------------------------------------------------------------- */
	/* Raisonnement pur — testable sans réseau ni WordPress            */
	/* -------------------------------------------------------------- */

	/**
	 * Préfixe des tags d'un composant.
	 *
	 * Un dépôt qui héberge plusieurs composants ne peut pas se contenter de
	 * `v1.2.0` : le tag doit dire de quoi il parle.
	 */
	public static function prefixe_tag( array $composant ): string {
		return empty( $composant['chemin'] ) ? '' : $composant['chemin'] . '-';
	}

	/**
	 * Version portée par un tag, ou null si le tag ne concerne pas ce composant.
	 *
	 * Le `v` est optionnel : `anode-cache-v1.2.0` et `anode-cache-1.2.0` sont
	 * acceptés, parce que les deux se tapent.
	 */
	public static function version_depuis_tag( string $tag, string $prefixe ): ?string {
		if ( '' !== $prefixe ) {
			if ( ! str_starts_with( $tag, $prefixe ) ) {
				return null;
			}

			$tag = substr( $tag, strlen( $prefixe ) );
		}

		$tag = ltrim( $tag, 'vV' );

		return preg_match( '/^\d+\.\d+(\.\d+)?$/', $tag ) ? $tag : null;
	}

	/**
	 * La release la plus récente pour ce composant, parmi celles du dépôt.
	 *
	 * On ne se fie pas à l'ordre renvoyé par GitHub, ni à sa notion de « latest »
	 * qui ignore le préfixe : dans un dépôt à plusieurs composants, la dernière
	 * release peut concerner un tout autre composant.
	 *
	 * @param array<int, array{tag_name?: string, draft?: bool, prerelease?: bool}> $releases
	 */
	public static function derniere_release( array $releases, string $prefixe ): ?array {
		$meilleure = null;
		$version   = null;

		foreach ( $releases as $release ) {
			if ( ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
				continue;
			}

			$candidate = self::version_depuis_tag( (string) ( $release['tag_name'] ?? '' ), $prefixe );

			if ( null === $candidate ) {
				continue;
			}

			if ( null === $version || version_compare( $candidate, $version, '>' ) ) {
				$version   = $candidate;
				$meilleure = $release;
			}
		}

		return null === $meilleure ? null : [ 'release' => $meilleure, 'version' => $version ];
	}

	/**
	 * Nature de l'écart entre deux versions.
	 *
	 * Sert à décider seul de ce qui s'applique : un correctif ne change pas de
	 * comportement, une majeure peut tout casser.
	 */
	public static function palier( string $installee, string $publiee ): string {
		$de   = array_map( 'intval', array_pad( explode( '.', $installee ), 3, '0' ) );
		$vers = array_map( 'intval', array_pad( explode( '.', $publiee ), 3, '0' ) );

		if ( $vers[0] !== $de[0] ) {
			return 'majeure';
		}

		return $vers[1] !== $de[1] ? 'mineure' : 'correctif';
	}

	/**
	 * Ce palier s'applique-t-il sans intervention ?
	 *
	 * `ANODE_UPDATER_AUTO` vaut `false` (rien), un palier maximal, ou n'est pas
	 * défini — auquel cas les mineures passent et les majeures attendent.
	 */
	public static function auto_autorise( string $palier ): bool {
		$reglage = defined( 'ANODE_UPDATER_AUTO' ) ? constant( 'ANODE_UPDATER_AUTO' ) : 'mineure';

		if ( false === $reglage ) {
			return false;
		}

		if ( true === $reglage ) {
			return true;
		}

		$plafond = is_string( $reglage ) && in_array( $reglage, self::PALIERS, true ) ? $reglage : 'mineure';

		return array_search( $palier, self::PALIERS, true ) <= array_search( $plafond, self::PALIERS, true );
	}

	/**
	 * L'archive publiée pour cette version, parmi les fichiers d'une release.
	 *
	 * On exige une archive construite pour l'occasion — `anode-cache-1.2.0.zip` —
	 * et non le zip de source que GitHub génère : celui-ci contient l'arborescence
	 * entière du dépôt sous un dossier horodaté, donc rien qu'on puisse installer
	 * sans deviner.
	 *
	 * Seule l'URL d'API (`url`) est retenue : `browser_download_url` passe par
	 * github.com, qui ignore le jeton et répond 404 sur un dépôt privé.
	 *
	 * @param array<int, array{name?: string, url?: string}> $assets
	 */
	public static function archive( array $assets, string $nom, string $version ): ?array {
		$attendu   = sprintf( '%s-%s.zip', $nom, $version );
		$empreinte = null;
		$archive   = null;

		foreach ( $assets as $asset ) {
			$fichier = (string) ( $asset['name'] ?? '' );

			if ( $fichier === $attendu && ! empty( $asset['url'] ) ) {
				$archive = $asset;
			}

			if ( $fichier === $attendu . '.sha256' && ! empty( $asset['url'] ) ) {
				$empreinte = $asset;
			}
		}

		if ( null === $archive ) {
			return null;
		}

		return [ 'archive' => $archive, 'empreinte' => $empreinte ];
	}

	/**
	 * Version déclarée dans l'en-tête d'un fichier d'extension.
	 *
	 * On lit l'en-tête plutôt qu'une option : c'est la seule source qui ne peut
	 * pas mentir sur ce qui est réellement installé.
	 */
	public static function version_en_tete( string $contenu ): ?string {
		return preg_match( '/^[\s*#]*Version:\s*(.+)$/mi', $contenu, $trouve )
			? trim( $trouve[1] )
			: null;
	}

	/** Contrainte d'en-tête, ou null si l'extension n'en déclare pas. */
	public static function exigence_en_tete( string $contenu, string $champ ): ?string {
		return preg_match( '/^[\s*#]*' . preg_quote( $champ, '/' ) . ':\s*(.+)$/mi', $contenu, $trouve )
			? trim( $trouve[1] )
			: null;
	}

	/**
	 * Ce site peut-il faire tourner cette version ?
	 *
	 * Installer un composant qui exige PHP 8.2 sur un site en 8.1 produit une
	 * erreur fatale au chargement suivant — et comme un mu-plugin se charge
	 * toujours, le site entier tombe, administration comprise.
	 *
	 * @return string|null Motif du refus, ou null si tout va bien.
	 */
	public static function incompatibilite( string $contenu, string $php, string $wp ): ?string {
		$exige_php = self::exigence_en_tete( $contenu, 'Requires PHP' );

		if ( null !== $exige_php && version_compare( $php, $exige_php, '<' ) ) {
			return sprintf( 'exige PHP %s, ce site est en %s', $exige_php, $php );
		}

		$exige_wp = self::exigence_en_tete( $contenu, 'Requires at least' );

		if ( null !== $exige_wp && version_compare( $wp, $exige_wp, '<' ) ) {
			return sprintf( 'exige WordPress %s, ce site est en %s', $exige_wp, $wp );
		}

		return null;
	}
}

/* ------------------------------------------------------------------ */

require_once __DIR__ . '/includes/class-source.php';
require_once __DIR__ . '/includes/class-installateur.php';
require_once __DIR__ . '/includes/class-planificateur.php';

Planificateur::demarrer();
