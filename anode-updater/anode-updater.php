<?php
/**
 * Plugin Name: Anode — mises à jour
 * Description: Tient à jour les mu-plugins maison depuis leurs dépôts GitHub. Vérifie chaque jour, applique les correctifs et les versions mineures, retient les majeures.
 * Version:     1.6.3
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
 * appliquer (vérification seule), `'majeure'` pour tout accepter. Les paliers
 * portent leur nom français : une valeur inconnue — `'patch'`, `'minor'` —
 * n'applique **rien** et laisse une trace, plutôt que de retomber en silence sur
 * un réglage que personne n'a demandé.
 *
 * ## Ce qui est vérifié avant d'écrire
 *
 * Une mise à jour automatique est une exécution de code distant sur le site d'un
 * client : le contrôle n'est pas une formalité.
 *
 *   1. l'archive vient d'une **release** du dépôt déclaré, jamais d'une branche ;
 *   2. son empreinte SHA-256 correspond à celle publiée — et dès qu'une release
 *      en publie une, le contrôle ne peut plus être sauté ;
 *   3. sa **signature Ed25519** est celle de la clé de confiance du site, quand
 *      ce site en a une ;
 *   4. elle contient exactement le dossier attendu, avec son fichier principal ;
 *   5. l'en-tête annonce bien la version de la release ;
 *   6. `Requires PHP` et `Requires at least` sont satisfaits par ce site.
 *
 * Les deux premiers contrôles ne prouvent qu'une chose : l'archive est bien
 * celle que la release publie. Ils ne disent rien de *qui* a publié la release —
 * l'archive et son empreinte sortent du même compte GitHub, et un compte
 * compromis produit les deux. La signature, elle, est faite hors de GitHub, avec
 * une clé privée qui n'y monte jamais : c'est le seul des contrôles qu'une prise
 * de contrôle du dépôt ne suffit pas à satisfaire.
 *
 * La version remplacée est conservée dans `mu-plugins/.anode-versions/`, ce qui
 * rend le retour en arrière possible sans accès au dépôt.
 *
 * ## Ce qui est vérifié après avoir écrit
 *
 * Les contrôles ci-dessus portent tous sur l'archive. Aucun ne dit si le
 * composant *se charge* : une fatale peut venir d'un appel à une fonction
 * absente, que ni `php -l` ni un en-tête ne voient. Et un mu-plugin ne se
 * désactive pas — une version qui tombe emporte le site entier, administration
 * comprise, sans que rien ne la remette en arrière.
 *
 * D'où la sonde : le site s'appelle lui-même avant et après la bascule. Un 500
 * qui n'existait pas avant remet l'ancienne version en place. Ce qu'elle ne sait
 * pas trancher — site injoignable, protection d'accès — est noté comme tel,
 * jamais transformé en retour arrière : reculer sur une preuve douteuse
 * défaisait chaque jour une mise à jour parfaitement saine.
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

	/**
	 * Version du composant, telle qu'annoncée à GitHub dans le `User-Agent`.
	 *
	 * Second exemplaire de l'en-tête, tenu à la main : il avait décroché de deux
	 * versions dans la même journée — `anode-updater/1.5.0` annoncé pendant que le
	 * composant était en 1.6.1 — parce que rien ne confrontait les deux.
	 * `bin/test/test-versions.mjs` le fait désormais, et échoue sur l'écart.
	 */
	public const VERSION = '1.6.2';

	/** Où l'on retient le résultat de la dernière vérification. */
	public const OPTION_ETAT = 'anode_updater_etat';

	/**
	 * Où l'on retient ce qui a réellement été installé, et quand.
	 *
	 * `OPTION_ETAT` est **écrasée** à chaque passe : après deux vérifications,
	 * plus rien ne dit quand un composant est passé de 1.0.0 à 1.1.0, ni si
	 * l'installation avait abouti. Un incident qui remonte trois jours plus tard
	 * n'avait alors aucune trace à interroger.
	 */
	public const OPTION_JOURNAL = 'anode_updater_journal';

	/** Entrées gardées au journal — au-delà, les plus anciennes tombent. */
	public const JOURNAL_MAX = 50;

	/** Longueur retenue d'un message au journal. */
	public const JOURNAL_MESSAGE_MAX = 300;

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
		$document = self::document();

		return is_array( $document['composants'] ?? null ) ? $document['composants'] : [];
	}

	/**
	 * Le manifeste, décodé une fois.
	 *
	 * @return array<string, mixed>
	 */
	private static function document(): array {
		static $document = null;

		if ( null !== $document ) {
			return $document;
		}

		$fichier  = __DIR__ . '/composants.json';
		$document = [];

		if ( is_readable( $fichier ) ) {
			$lu = json_decode( (string) file_get_contents( $fichier ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			$document = is_array( $lu ) ? $lu : [];
		}

		return $document;
	}

	/* -------------------------------------------------------------- */
	/* Confiance : d'où vient l'archive, et pas seulement ce qu'elle vaut */
	/* -------------------------------------------------------------- */

	/**
	 * Clé publique Ed25519 à laquelle ce site fait confiance, en base64.
	 *
	 * Deux origines, et l'ordre compte. La constante l'emporte : elle vit dans
	 * `wp-config.php`, que le canal de mise à jour ne touche jamais — un dépôt
	 * compromis ne peut donc pas la remplacer. Le manifeste, lui, voyage avec
	 * l'archive de `anode-updater` : c'est commode, mais c'est aussi ce qui
	 * rendrait la rotation de clé possible depuis le dépôt, d'où le garde-fou de
	 * `rotation_refusee()`.
	 *
	 * Vide ou absente : aucune signature n'est exigée, et les archives non
	 * signées restent installables. C'est l'état des sites tant que la clé n'est
	 * pas posée.
	 */
	public static function cle_publique(): ?string {
		if ( self::cle_epinglee() ) {
			return trim( (string) constant( 'ANODE_UPDATER_CLE' ) );
		}

		$document = self::document();

		return is_string( $document['cle_publique'] ?? null ) ? trim( $document['cle_publique'] ) : null;
	}

	/** La clé est-elle fixée dans `wp-config.php`, hors de portée du dépôt ? */
	public static function cle_epinglee(): bool {
		return defined( 'ANODE_UPDATER_CLE' )
			&& is_string( constant( 'ANODE_UPDATER_CLE' ) )
			&& '' !== trim( (string) constant( 'ANODE_UPDATER_CLE' ) );
	}

	/**
	 * Faut-il refuser une archive faute de signature ?
	 *
	 * Sans cette règle, la signature ne protège de rien : il suffirait de
	 * publier la release **sans** le fichier `.sig` pour retomber sur le
	 * comportement d'avant. Une clé posée sur un site vaut donc exigence.
	 *
	 * @return string|null Motif du refus, ou null.
	 */
	public static function refus_signature( ?string $cle, bool $signature_publiee ): ?string {
		if ( null === $cle || '' === $cle ) {
			return null;
		}

		return $signature_publiee
			? null
			: 'ce site exige une signature, or la release n’en publie pas (fichier .sig absent)';
	}

	/**
	 * Faut-il refuser une archive parce qu'elle change la clé de confiance ?
	 *
	 * L'archive de `anode-updater` embarque `composants.json`, donc la clé
	 * publique : sans ce refus, un dépôt compromis publierait un updater portant
	 * **sa** clé, signé par elle, et le site l'accepterait — la signature ne
	 * vaudrait plus que jusqu'à la première mise à jour de l'updater lui-même.
	 * Changer de clé redevient donc un acte manuel.
	 *
	 * Deux cas n'ont rien à refuser : un site dont la clé est épinglée dans
	 * `wp-config.php` — le manifeste ne décide plus de rien —, et un site qui
	 * n'en a pas encore, où adopter une clé est précisément le but.
	 *
	 * @return string|null Motif du refus, ou null.
	 */
	public static function rotation_refusee( ?string $actuelle, ?string $entrante, bool $epinglee ): ?string {
		if ( $epinglee || null === $actuelle || '' === $actuelle ) {
			return null;
		}

		if ( trim( (string) $entrante ) === $actuelle ) {
			return null;
		}

		return 'cette version change la clé publique de confiance : à installer à la main, après avoir vérifié d’où vient ce changement';
	}

	/* -------------------------------------------------------------- */
	/* Journal                                                         */
	/* -------------------------------------------------------------- */

	/**
	 * Ajoute une entrée en tête du journal, et coupe la queue.
	 *
	 * Borné, parce qu'une option de site est chargée à chaque requête quand elle
	 * est en autoload et exportée avec la base dans tous les cas : un journal qui
	 * grossit sans fin finit par coûter à chaque page.
	 *
	 * @param array<int, array<string, mixed>> $journal
	 * @param array<string, mixed>             $entree
	 * @return array<int, array<string, mixed>>
	 */
	public static function journaliser( array $journal, array $entree, int $max = self::JOURNAL_MAX ): array {
		$entree['message'] = self::resumer( (string) ( $entree['message'] ?? '' ) );

		if ( '' === $entree['message'] ) {
			unset( $entree['message'] );
		}

		array_unshift( $journal, $entree );

		return array_slice( array_values( $journal ), 0, max( 1, $max ) );
	}

	/**
	 * Message d'entrée de journal, sur une ligne et de longueur bornée.
	 *
	 * Un message d'erreur de WordPress peut porter une sortie serveur entière :
	 * cinquante entrées de cette taille, et l'option se compte en mégaoctets.
	 */
	private static function resumer( string $message ): string {
		$message = trim( (string) preg_replace( '/\s+/u', ' ', $message ) );

		return mb_strlen( $message ) > self::JOURNAL_MESSAGE_MAX
			? mb_substr( $message, 0, self::JOURNAL_MESSAGE_MAX - 1 ) . '…'
			: $message;
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
		$plafond = self::plafond_auto( $reglage );

		if ( null === $plafond ) {
			// Un réglage illisible n'est pas un « ne rien appliquer » demandé : il
			// faut le nommer, sinon le site reste en arrière sans que rien ne le
			// dise. `false`, lui, est un choix — on ne le commente pas.
			if ( false !== $reglage ) {
				self::tracer(
					sprintf(
						'ANODE_UPDATER_AUTO vaut %s : ce n’est pas un palier connu (%s). Aucune mise à jour ne sera appliquée seule.',
						var_export( $reglage, true ),
						implode( ', ', self::PALIERS )
					)
				);
			}

			return false;
		}

		$rang = array_search( $palier, self::PALIERS, true );

		/*
		 * Un palier inconnu est refusé, explicitement.
		 *
		 * Sans cette garde, `array_search` rend `false`, et `false <= 1` est
		 * **vrai** en PHP : le palier inconnu passait pour un correctif, donc
		 * s'installait seul. Aucun appel ne pouvait l'atteindre — `palier()` ne
		 * rend que les trois valeurs connues — mais c'était une mine pour le
		 * premier qui ajouterait un cas, et le cas est arrivé (« nouveau »,
		 * l'installation d'un composant encore absent).
		 */
		if ( false === $rang ) {
			self::tracer(
				sprintf(
					'Palier « %s » inconnu (%s) : rien ne s’applique seul.',
					$palier,
					implode( ', ', self::PALIERS )
				)
			);

			return false;
		}

		return $rang <= array_search( $plafond, self::PALIERS, true );
	}

	/**
	 * Palier maximal appliqué seul, ou null si rien ne doit s'appliquer.
	 *
	 * Les paliers sont nommés en français. Un site qui écrit `'patch'` ou
	 * `'minor'` — les noms anglais, qui viennent naturellement — obtenait
	 * « mineure » : la valeur inconnue retombait sur le défaut, et une majeure
	 * refusée passait pour le comportement voulu. On refuse tout, ce qui laisse
	 * la version en attente et visible dans l'administration.
	 *
	 * @param mixed $reglage Valeur de `ANODE_UPDATER_AUTO`, ou le défaut du projet.
	 */
	public static function plafond_auto( $reglage ): ?string {
		if ( true === $reglage ) {
			return 'majeure';
		}

		if ( is_string( $reglage ) && in_array( $reglage, self::PALIERS, true ) ) {
			return $reglage;
		}

		return null;
	}

	/**
	 * Laisse une trace d'un réglage qui ne dit pas ce que son auteur croit.
	 *
	 * Une même erreur n'a pas à remplir le journal à chaque composant : autant de
	 * lignes identiques que de composants suivis, chaque jour.
	 */
	private static function tracer( string $message ): void {
		static $dites = [];

		if ( isset( $dites[ $message ] ) ) {
			return;
		}

		$dites[ $message ] = true;

		error_log( 'anode-updater : ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
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
		$signature = null;
		$archive   = null;

		foreach ( $assets as $asset ) {
			$fichier = (string) ( $asset['name'] ?? '' );

			if ( $fichier === $attendu && ! empty( $asset['url'] ) ) {
				$archive = $asset;
			}

			if ( $fichier === $attendu . '.sha256' && ! empty( $asset['url'] ) ) {
				$empreinte = $asset;
			}

			if ( $fichier === $attendu . '.sig' && ! empty( $asset['url'] ) ) {
				$signature = $asset;
			}
		}

		if ( null === $archive ) {
			return null;
		}

		return [ 'archive' => $archive, 'empreinte' => $empreinte, 'signature' => $signature ];
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

require_once __DIR__ . '/includes/class-signature.php';
require_once __DIR__ . '/includes/class-sonde.php';
require_once __DIR__ . '/includes/class-source.php';
require_once __DIR__ . '/includes/class-installateur.php';
require_once __DIR__ . '/includes/class-planificateur.php';

Planificateur::demarrer();
