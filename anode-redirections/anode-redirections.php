<?php
/**
 * Plugin Name: Anode — redirections
 * Description: Redirections 301 depuis les anciennes URL d'un site refondu, lues dans un fichier versionné du thème. Remplace Redirection et consorts.
 * Version:     1.3.0
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
 * Une destination peut être **absolue** — `"https://boutique.exemple.fr/"` —
 * quand une partie du site part chez un prestataire : c'est un cas courant de
 * refonte, pas une anomalie.
 *
 * **L'ordre des motifs fait loi.** Le premier motif qui correspond gagne : un
 * motif général placé avant un motif précis mange ce dernier, sans que rien ne
 * le signale à la lecture du fichier. Le plus précis se met donc en premier, et
 * l'écran de santé nomme les motifs masqués.
 *
 * @package Anode\Redirections
 */

declare( strict_types = 1 );

namespace Anode\Redirections;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '1.3.0';

/**
 * Statuts que l'on accepte de renvoyer.
 *
 * `wp_redirect` appelle `wp_die` hors de la plage 3xx : un `"statut": 200`
 * posé par distraction dans le fichier ne rendrait plus une 404 mais un écran
 * de mort, et sur toutes les adresses attrapées par le motif. 410 y figure car
 * il est traité en amont, sans redirection.
 */
const STATUTS = [ 301, 302, 307, 308, 410 ];

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
	$path = rawurldecode( $path );

	// `strtolower` ne descend que l'ASCII : « /Écoles/ » — un ancien site en
	// français en a toujours quelques-unes — garderait son É d'un seul côté de
	// la comparaison, et la redirection ne prendrait jamais. mbstring est
	// présent partout, mais son absence ne doit pas casser le site.
	$path = function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path );

	$path = '/' . trim( $path, '/' );

	return '/' === $path ? '/' : $path . '/';
}

/**
 * Ramène un statut déclaré dans le fichier à une valeur qu'on sait servir.
 *
 * Voir `STATUTS` : un cast brut acceptait n'importe quoi, y compris `0` quand
 * la clé était mal orthographiée.
 *
 * @param mixed $valeur Statut lu dans la table.
 */
function status( $valeur ): int {
	$statut = is_numeric( $valeur ) ? (int) $valeur : 301;

	return in_array( $statut, STATUTS, true ) ? $statut : 301;
}

/**
 * La destination désigne-t-elle un autre hôte ?
 *
 * Reconnaît `https://…` comme `//…` : les deux sortent du site et ne peuvent
 * pas être comparées au chemin demandé.
 */
function absolute( string $vers ): bool {
	return 1 === preg_match( '~^(?:[a-z][a-z0-9+.\-]*:)?//~i', $vers );
}

/**
 * La destination ramène-t-elle **exactement** à l'adresse demandée ?
 *
 * Une entrée qui se pointe elle-même — `/mentions-legales/` vers
 * `/mentions-legales/`, recopiée d'un inventaire d'anciennes adresses sans voir
 * que le slug n'avait pas changé — renvoie le navigateur sur la même 404, qui
 * redirige à nouveau : la page devient inatteignable, et le journal du serveur
 * ne montre qu'une avalanche de 301.
 *
 * La comparaison porte sur l'adresse **telle qu'elle a été demandée**, pas sur
 * sa forme normalisée. Comparer les formes normalisées écartait en silence tout
 * ce qui ne corrige *que* ce que la normalisation efface :
 *
 *     /Nos-Services/  →  /nos-services/     (casse)
 *     /services       →  /services/          (barre finale)
 *     /contact/       →  /contact/?form=devis (requête)
 *
 * Ces trois redirections sont légitimes et ne partaient plus. Une seule sortie
 * suffit d'ailleurs à les terminer : après elle, l'adresse demandée est celle de
 * la destination, et le second passage voit bien la boucle.
 */
function loops( string $vers, string $demande ): bool {
	// Une destination vide ou hors du site n'est pas une boucle : la première
	// est écartée plus loin, la seconde ne revient pas ici.
	if ( '' === $vers || absolute( $vers ) ) {
		return false;
	}

	/*
	 * La barre finale, elle, reste effacée avant de comparer : `/a/` vers `/a`
	 * n'est pas une redirection, c'est une entrée fautive. WordPress rétablit la
	 * forme canonique de lui-même, et la laisser partir invite un va-et-vient
	 * avec `redirect_canonical`. La casse et la chaîne de requête, en revanche,
	 * sont conservées : elles distinguent deux adresses réellement différentes.
	 */
	$reduire = static fn ( string $adresse ): string => '/' . trim( trim( $adresse ), '/' );

	return $reduire( $vers ) === $reduire( $demande );
}

/**
 * Échappe le délimiteur dans une expression du fichier.
 *
 * Le tilde délimite l'expression : un motif qui en contient un — `^/~theo/`,
 * les pages personnelles d'un ancien serveur — la referme trop tôt, elle ne
 * compile plus, et la règle disparaît. Un tilde déjà échappé à la main dans le
 * fichier est laissé tel quel, sinon on l'échapperait deux fois.
 */
function delimiter( string $expression ): string {
	return (string) preg_replace_callback(
		'/(?<!\\\\)~/',
		static function (): string {
			return '\~';
		},
		$expression
	);
}

/**
 * L'expression compile-t-elle ?
 *
 * Un motif fautif — parenthèse non fermée, classe ouverte — était écarté sans
 * un mot : la redirection ne prenait pas, et rien ne disait pourquoi. On
 * journalise donc, une seule fois par expression et par requête, pour ne pas
 * noyer le journal à chaque 404.
 */
function compiles( string $expression ): bool {
	static $connues = [];

	if ( isset( $connues[ $expression ] ) ) {
		return $connues[ $expression ];
	}

	$valide = false !== @preg_match( $expression, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( ! $valide ) {
		/*
		 * Journalisé hors de tout `WP_DEBUG` : c'est en production que la règle
		 * manque, et c'est là qu'il faut la trace.
		 *
		 * Mais **une fois par heure**, pas une fois par requête. La mémorisation
		 * statique ne vit que le temps d'une requête : sur un site balayé par des
		 * robots, chaque 404 rouvrait la table, retrouvait le motif fautif et
		 * écrivait une ligne. Le journal enflait — l'inverse de ce qu'on cherche,
		 * puisqu'une trace noyée dans dix mille identiques ne se lit plus.
		 */
		$vu = 'anode_redirections_motif_' . md5( $expression );

		if ( ! get_transient( $vu ) ) {
			set_transient( $vu, 1, HOUR_IN_SECONDS );

			error_log( sprintf( 'anode-redirections : motif ignoré, expression invalide — %s', $expression ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	return $connues[ $expression ] = $valide;
}

/**
 * Une adresse concrète que ce motif attraperait.
 *
 * Deux expressions régulières ne se comparent pas entre elles. On fabrique donc
 * un spécimen — captures, classes et quantificateurs deviennent un segment — et
 * l'on demande aux motifs précédents s'ils l'attrapent aussi. Approximation
 * assumée : elle sert à alerter, pas à décider.
 */
function specimen( string $de ): string {
	if ( '' === $de ) {
		return '';
	}

	$chemin = str_replace( [ '^', '$' ], '', $de );

	// Un groupe d'alternatives se représente par sa première branche :
	// `(?:type|year|month)` → `type`.
	$chemin = (string) preg_replace( '~\((?:\?[:!=])?([^()|]*)(?:\|[^()|]*)+\)~', '$1', $chemin );

	// Tout ce qui décrit « n'importe quoi » devient un segment concret.
	$chemin = (string) preg_replace( '~\((?:\?[:!=])?[^()]*\)~', 'segment', $chemin );
	$chemin = (string) preg_replace( '~\[[^\]]*\]~', 'segment', $chemin );
	$chemin = (string) preg_replace( '~\\\\[dwsDWS]~', 'segment', $chemin );
	$chemin = str_replace( [ '.+?', '.*?', '.+', '.*', '.' ], 'segment', $chemin );

	// Les quantificateurs et les échappements n'ont plus d'objet.
	$chemin = (string) preg_replace( '~\{\d+(?:,\d*)?\}~', '', $chemin );
	$chemin = str_replace( [ '?', '*', '+', '\\' ], '', $chemin );

	return normalize( $chemin );
}

/**
 * Les motifs qu'un motif placé avant eux rend inatteignables.
 *
 * La boucle de `resolve` retient le premier motif qui **modifie** le chemin :
 * un critère de « ça a changé », pas de spécificité. Sur niort-tech,
 * `^/activity/(.+?)/?$` précède `^/activity/page/[0-9]+/?$` — la pagination des
 * archives part donc vers `/evenements/page/2/`, qui n'existe pas, au lieu de
 * `/evenements/`. Rien dans le fichier ne le montre : d'où ce contrôle, rendu
 * dans l'écran de santé.
 *
 * @param array<int|string, array<string, string>> $motifs
 *
 * @return array<int, array{motif: int, masque_par: int, exemple: string}>
 */
function masked( array $motifs ): array {
	$motifs  = array_values( $motifs );
	$trouves = [];

	foreach ( $motifs as $rang => $motif ) {
		$exemple = specimen( (string) ( $motif['de'] ?? '' ) );

		if ( '' === $exemple ) {
			continue;
		}

		for ( $avant = 0; $avant < $rang; $avant++ ) {
			$de = (string) ( $motifs[ $avant ]['de'] ?? '' );

			if ( '' === $de ) {
				continue;
			}

			$expression = '~' . delimiter( $de ) . '~i';

			if ( ! compiles( $expression ) || 1 !== preg_match( $expression, $exemple ) ) {
				continue;
			}

			/*
			 * Correspondre ne suffit pas à masquer : `resolve()` **saute** un motif
			 * dont la destination est vide, et un motif qui n'a rien changé au
			 * chemin. Les compter comme masquants faisait passer l'écran de santé en
			 * « recommandé » sur une table parfaitement ordonnée, et conseillait un
			 * réordonnancement inutile — le genre d'avis qu'on finit par ignorer,
			 * avec le vrai.
			 */
			$avant_vers = (string) ( $motifs[ $avant ]['vers'] ?? '' );
			$statut     = status( $motifs[ $avant ]['statut'] ?? null );

			if ( '' === $avant_vers && 410 !== $statut ) {
				continue;
			}

			if ( '' !== $avant_vers && preg_replace( $expression, $avant_vers, $exemple ) === $exemple ) {
				continue;
			}

			$trouves[] = [
				'motif'      => $rang,
				'masque_par' => $avant,
				'exemple'    => $exemple,
			];

			break;
		}
	}

	return $trouves;
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
		if ( normalize( (string) $de ) !== $chemin ) {
			continue;
		}

		$vers = (string) $vers;

		/*
		 * Une entrée inexploitable laisse la 404 répondre.
		 *
		 * Sans destination — clé posée en attente dans le fichier —, la
		 * redirection ne part pas et le `exit` qui suit rendait un document
		 * vide en 200 : pire qu'une 404, pour un moteur comme pour un visiteur.
		 * Avec une destination égale à la source, c'est la boucle (cf. `loops`).
		 * Dans les deux cas on continue : un motif plus loin peut savoir quoi
		 * faire de cette adresse.
		 */
		if ( '' === $vers || loops( $vers, $path ) ) {
			continue;
		}

		return [ 'vers' => $vers, 'statut' => 301, 'externe' => absolute( $vers ) ];
	}

	foreach ( $table['motifs'] as $motif ) {
		$de = (string) ( $motif['de'] ?? '' );

		if ( '' === $de ) {
			continue;
		}

		$vers   = (string) ( $motif['vers'] ?? '' );
		$statut = status( $motif['statut'] ?? null );

		// Un motif sans destination ne peut rediriger nulle part — sauf s'il
		// déclare un 410, qui par nature n'a pas de destination.
		if ( '' === $vers && 410 !== $statut ) {
			continue;
		}

		// Le délimiteur est le tilde : une expression de chemin contient des
		// barres obliques, qu'il faudrait sinon échapper une à une.
		$expression = '~' . delimiter( $de ) . '~i';

		if ( ! compiles( $expression ) ) {
			continue;
		}

		$resultat = preg_replace( $expression, $vers, $chemin );

		// Le motif n'a rien changé : il ne correspondait pas.
		if ( null === $resultat || $resultat === $chemin ) {
			continue;
		}

		// Et il ne doit pas ramener au chemin demandé : une capture réinjectée
		// telle quelle — `^/(.+?)/?$` vers `/$1` — boucle sur place.
		if ( loops( $resultat, $path ) ) {
			continue;
		}

		/*
		 * `externe` se décide sur la destination **déclarée dans le fichier**,
		 * jamais sur le résultat de la substitution.
		 *
		 * Le résultat contient du `REQUEST_URI` : avec un motif dont la
		 * destination commence par une capture — `{"de":"^/go/(.+)$","vers":"$1"}` —
		 * un visiteur demandant `/go/https://evil.example.com/` fabriquait
		 * lui-même une destination absolue, et le contrôle d'hôte de
		 * `wp_safe_redirect` était levé sur sa propre chaîne. C'est une
		 * redirection ouverte, offerte par la table.
		 *
		 * La destination déclarée, elle, est relue et déployée avec le site.
		 */
		return [ 'vers' => $resultat, 'statut' => $statut, 'externe' => absolute( $vers ) ];
	}

	foreach ( $table['disparues'] as $disparue ) {
		if ( normalize( (string) $disparue ) === $chemin ) {
			return [ 'vers' => '', 'statut' => 410, 'externe' => false ];
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

		// Sans destination, rien à faire : laisser la 404 répondre plutôt que
		// sortir sur un document vide (cf. `resolve`).
		if ( '' === $cible['vers'] ) {
			return;
		}

		if ( ! empty( $cible['externe'] ) ) {
			/*
			 * Une destination hors du site est suivie telle quelle.
			 *
			 * `wp_safe_redirect` n'autorise que l'hôte du site : une cible
			 * externe — la boutique repartie chez un prestataire, cas courant
			 * de refonte — était remplacée en silence par `/wp-admin/`, et le
			 * visiteur atterrissait sur un écran de connexion. La liste blanche
			 * protège d'une destination fournie par un visiteur ; celle-ci vient
			 * d'un fichier versionné du thème, relu et déployé avec le site.
			 */
			wp_redirect( $cible['vers'], $cible['statut'], 'Anode' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		// Une destination relative reste relative : le site peut changer de
		// domaine — préproduction, production — sans que la table bouge.
		wp_safe_redirect( $cible['vers'], $cible['statut'], 'Anode' );
		exit;
	},
	1
);

/**
 * Signale une table absente, ou un motif masqué, là où on les cherchera :
 * l'écran de santé.
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
				$masques  = $presente ? masked( $table['motifs'] ) : [];

				$avertissement = '';

				foreach ( $masques as $masque ) {
					$avertissement .= sprintf(
						'<p>Le motif n<sup>o</sup>&nbsp;%1$d n’est jamais atteint : le motif n<sup>o</sup>&nbsp;%2$d, '
							. 'placé avant lui, attrape déjà une adresse comme <code>%3$s</code>. Le premier motif qui '
							. 'correspond gagne — placez le plus précis en premier.</p>',
						$masque['motif'] + 1,
						$masque['masque_par'] + 1,
						esc_html( $masque['exemple'] )
					);
				}

				return [
					'label'       => $presente
						? sprintf( '%d redirection(s) déclarée(s)', $nombre )
						: 'Aucune table de redirections',
					'status'      => $presente && '' === $avertissement ? 'good' : 'recommended',
					'badge'       => [ 'label' => 'Anode', 'color' => 'blue' ],
					'description' => $presente
						? '<p>Les anciennes adresses du site sont redirigées depuis <code>redirections.json</code>.</p>'
							. $avertissement
						: '<p>Après une refonte, les anciennes adresses tombent en 404 tant qu’aucune table '
							. 'n’existe. Créez <code>theme/anode-child/redirections.json</code>.</p>',
					'test'        => 'anode_redirections',
				];
			},
		];

		return $tests;
	}
);
