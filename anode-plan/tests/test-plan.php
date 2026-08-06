<?php
/**
 * Les pages d'atelier, éprouvées hors WordPress.
 *
 * Ce que ce banc tient, et que rien d'autre ne tiendrait : **la porte**. Une
 * page d'atelier servie en production exposerait l'inventaire du site, le nom
 * des constantes de ses webhooks et la liste de ses composants — à qui devine
 * l'adresse. Le htpasswd de la préproduction ne protège pas de cela : il protège
 * l'environnement, pas la route, et un site part un jour en ligne sans lui.
 *
 * Lancement : php blueprint/mu-plugins/anode-plan/tests/test-plan.php
 *
 * @package Anode\Plan
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

$reussis = 0;
$echoues = 0;

function test( string $nom, callable $cas ): void {
	global $reussis, $echoues;

	try {
		$cas();
		$reussis++;
		fwrite( STDOUT, "  ✓ {$nom}\n" );
	} catch ( \Throwable $e ) {
		$echoues++;
		fwrite( STDOUT, "  ✗ {$nom}\n      {$e->getMessage()}\n" );
	}
}

function affirmer( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new \RuntimeException( $message );
	}
}

/* ------------------------------------------------------------------ *
 * Les accroches déclarées, relevées dans le fichier
 * ------------------------------------------------------------------ */

$source  = (string) file_get_contents( __DIR__ . '/../anode-plan.php' );
$rendu   = (string) file_get_contents( __DIR__ . '/../includes/class-rendu.php' );
$sources = (string) file_get_contents( __DIR__ . '/../includes/class-sources.php' );

fwrite( STDOUT, "\n\033[1mPages d’atelier — la porte\033[0m\n\n" );

test( 'la production est refusée par défaut, faute de savoir', function () use ( $source ): void {
	affirmer( str_contains( $source, "class_exists( '\\Anode\\Seo\\Seo' )" ), 'la garde sur le composant SEO manque' );
	affirmer(
		(bool) preg_match( '/class_exists[\s\S]{0,400}?return false;/', $source ),
		'sans le composant SEO, atelier() doit rendre false — le silence vaut refus'
	);
} );

test( 'l’autorité de l’environnement est celle du SEO, pas une seconde', function () use ( $source ): void {
	affirmer( str_contains( $source, 'production_declared' ), 'production_declared() doit être l’unique juge' );
	affirmer(
		! preg_match( '/(staging|preprod|recette)/i', str_replace( 'anode-staging', '', $source ) ),
		'aucune liste de domaines ne doit être recopiée ici'
	);
} );

test( 'les routes ne sont pas même déclarées hors atelier', function () use ( $source ): void {
	affirmer(
		(bool) preg_match( '/function declarer_regles[\s\S]{0,200}?if \(\s*! self::atelier\(\)\s*\)\s*\{\s*return;/', $source ),
		'declarer_regles() doit sortir avant add_rewrite_rule()'
	);
} );

test( 'servir() se garde une seconde fois', function () use ( $source ): void {
	affirmer(
		(bool) preg_match( '/function servir[\s\S]{0,200}?if \(\s*! self::atelier\(\)\s*\)\s*\{\s*return;/', $source ),
		'la règle de réécriture peut survivre en base à un changement d’environnement'
	);
} );

test( 'une sous-page inventée retombe sur le 404 ordinaire', function () use ( $source ): void {
	affirmer(
		str_contains( $source, 'array_key_exists( $page, self::PAGES )' ),
		'seules les sous-pages déclarées sont servies'
	);
} );

fwrite( STDOUT, "\n\033[1mCe que les pages ne doivent jamais faire\033[0m\n\n" );

test( 'aucune ressource chargée depuis un domaine tiers', function () use ( $rendu ): void {
	affirmer(
		! preg_match( '#(https?:)?//(?!\s)[a-z0-9.-]+\.[a-z]{2,}/#i', preg_replace( '/^\s*\*.*$/m', '', $rendu ) ?? '' ),
		'la règle zéro-dépendance ne connaît pas d’exception pour les outils internes'
	);
} );

test( 'les pages s’annoncent non indexables, en balise et en en-tête', function () use ( $rendu, $source ): void {
	affirmer( str_contains( $rendu, 'noindex, nofollow' ), 'la balise robots manque' );
	affirmer( str_contains( $source, 'X-Robots-Tag' ), 'l’en-tête X-Robots-Tag manque' );
} );

test( 'tout ce qui vient du site est échappé', function () use ( $rendu ): void {
	/*
	 * Un titre de page, un libellé de champ, un nom de composant : tout cela est
	 * saisi par quelqu'un, donc tout cela est du texte hostile. Compter les
	 * échappements ne prouverait rien — on cherche donc ceux qui **manquent** :
	 * une valeur du site posée dans le HTML sans passer par une fonction d'échappement.
	 */
	$sans_garde = [];
	foreach ( [ 'get_the_title', 'get_bloginfo', 'home_url', 'get_permalink' ] as $lecture ) {
		if ( preg_match_all( '/(\w*)\(\s*' . preg_quote( $lecture, '/' ) . '/', $rendu, $m ) ) {
			foreach ( $m[1] as $enveloppe ) {
				// `entree` et `chemin` sont des enveloppes du module : autorisées,
				// mais leur propre échappement est vérifié juste après.
				if ( ! in_array( $enveloppe, [ 'esc_html', 'esc_url', 'esc_attr', 'chemin', 'entree' ], true ) ) {
					$sans_garde[] = "{$enveloppe}({$lecture}";
				}
			}
		}
	}

	affirmer( ! $sans_garde, 'valeur du site posée sans échappement : ' . implode( ', ', array_unique( $sans_garde ) ) );
	affirmer( str_contains( $rendu, 'esc_url' ), 'les adresses doivent passer par esc_url' );

	// Les deux enveloppes autorisées ci-dessus doivent tenir leur part.
	if ( preg_match( '/private static function entree\([\s\S]*?\n\t\}/', $rendu, $m ) ) {
		affirmer( str_contains( $m[0], 'esc_html' ) && str_contains( $m[0], 'esc_url' ),
			'entree() est autorisée à recevoir des valeurs brutes : elle doit les échapper' );
	} else {
		throw new \RuntimeException( 'entree() introuvable — l’autorisation ci-dessus n’est plus fondée' );
	}
} );

fwrite( STDOUT, "\n\033[1mLa typographie des pages d’atelier\033[0m\n\n" );

/*
 * Ces cinq cas viennent d'un défaut mesuré le 06/08/2026 : la moitié du texte
 * des pages d'atelier s'affichait en serif.
 *
 * `esc_attr()` échappait pour un attribut HTML une valeur destinée à l'intérieur
 * d'un `<style>` — où une entité n'est jamais décodée. `'Inter'` sortait en
 * `&#039;Inter&#039;`, le `;` de l'entité coupait la déclaration, et `body` se
 * retrouvait sans `font-family` : police par défaut du navigateur, donc serif.
 *
 * Les trois premiers cas **exécutent** la fonction plutôt que de la relire :
 * un test qui vérifierait la seule présence de `self::css` passerait au vert sur
 * une implémentation qui ne fait rien.
 */
require_once __DIR__ . '/../includes/class-rendu.php';

$assainir = static function ( string $valeur ): string {
	// `setAccessible()` n'est plus nécessaire depuis PHP 8.1, et déprécié en 8.5.
	return (string) ( new \ReflectionMethod( \Anode\Plan\Rendu::class, 'css' ) )->invoke( null, $valeur );
};

test( 'une pile de polices survit au passage dans un <style>', function () use ( $assainir ): void {
	$sortie = $assainir( "'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif" );

	affirmer( ! str_contains( $sortie, '&#' ), 'aucune entité HTML : elle n’est pas décodée dans un <style>' );
	affirmer( ! str_contains( $sortie, '&quot;' ), 'aucune entité HTML, guillemets compris' );
	affirmer( str_contains( $sortie, "'Inter'" ), 'la famille doit rester citable telle quelle' );
	affirmer( str_contains( $sortie, 'sans-serif' ), 'le repli de la pile doit survivre' );
} );

test( 'une valeur ne peut pas refermer le bloc <style>', function () use ( $assainir ): void {
	$sortie = $assainir( 'red</style><script>alert(1)</script>' );

	affirmer( ! str_contains( $sortie, '<' ) && ! str_contains( $sortie, '>' ),
		'le vrai danger dans un <style> est </style>, qui rend la suite au parseur HTML' );
} );

test( 'une valeur ne peut ni aller chercher une ressource ni commenter', function () use ( $assainir ): void {
	affirmer( ! preg_match( '/url\s*\(/i', $assainir( "url(https://tiers.example/f.woff2)" ) ),
		'une valeur de token n’a aucune raison d’appeler une ressource — ce serait la règle zéro dépendance par la bande' );
	affirmer( ! str_contains( $assainir( 'a/*x*/b' ), '/*' ), 'un commentaire ouvert avalerait les déclarations suivantes' );
	affirmer( ! str_contains( $assainir( 'red;position:fixed' ), ';' ), 'un point-virgule ajouterait une déclaration' );
} );

test( 'aucune valeur de style ne repasse par esc_attr', function () use ( $rendu ): void {
	if ( ! preg_match( '/private static function style\([\s\S]*?\n\t\}/', $rendu, $m ) ) {
		throw new \RuntimeException( 'style() introuvable' );
	}

	// Les commentaires sont retirés : celui qui explique le défaut nomme
	// forcément la fonction fautive, et ferait échouer le contrôle sur lui-même.
	$code = preg_replace( '#//.*$|/\*[\s\S]*?\*/#m', '', $m[0] ) ?? '';

	affirmer( ! str_contains( $code, 'esc_attr' ),
		'esc_attr échappe pour un attribut HTML ; dans un <style>, l’entité n’est pas décodée et casse la déclaration' );
	affirmer( substr_count( $code, 'self::css(' ) >= 9,
		'les neuf valeurs injectées dans la feuille doivent toutes passer par l’assainisseur CSS' );
} );

test( 'les pages portent les @font-face du site', function () use ( $rendu ): void {
	/*
	 * Une page d'atelier est un document autonome : `wp_head` n'y est jamais
	 * appelé, donc `inc/fonts.php` du thème n'émet rien. Sans ces règles, la
	 * famille reprise des tokens désigne une police absente et l'on retombe sur
	 * `system-ui` — proche, et ce n'est pas la marque.
	 */
	affirmer( str_contains( $rendu, '@font-face' ), 'aucune règle @font-face : la police du site n’arrive jamais' );
	affirmer( str_contains( $rendu, 'anode_fonts' ),
		'les variantes se lisent dans le thème, jamais dans une seconde liste qui divergerait' );
	affirmer( str_contains( $rendu, 'self::polices()' ), 'les règles doivent être posées dans la feuille de la page' );
	affirmer( str_contains( $rendu, 'is_readable' ),
		'un fichier absent ne doit pas produire une @font-face qui pointe dans le vide' );
} );

fwrite( STDOUT, "\n\033[1mLes sources lisent le site vivant\033[0m\n\n" );

test( 'les formulaires sont lus dans le thème, pas dans une copie', function () use ( $sources ): void {
	affirmer( str_contains( $sources, 'get_stylesheet_directory' ), 'le dossier forms/ se lit dans le thème enfant' );
	affirmer( str_contains( $sources, "'/*.json'" ) || str_contains( $sources, "/*.json" ), 'les définitions sont des .json' );
} );

test( 'un site sans formulaire, sans composant ou sans charte ne tombe pas', function () use ( $sources, $rendu ): void {
	foreach ( [ 'is_dir', 'is_readable' ] as $garde ) {
		affirmer( str_contains( $sources, $garde ), "la garde {$garde} manque" );
	}
	affirmer( substr_count( $rendu, 'pl-vide' ) >= 3, 'chaque page doit avoir son message de vide' );
} );

test( 'l’adresse de production se déclare, elle ne se devine pas', function () use ( $sources ): void {
	affirmer( str_contains( $sources, 'ANODE_URL_PRODUCTION' ), 'la constante manque' );
	affirmer(
		! preg_match( '/str_replace\([^)]*staging/i', $sources ),
		'aucune dérivation depuis le domaine de préproduction : rien ne garantit la correspondance'
	);
} );

test( 'la charte a un repli lisible quand aucun token n’est posé', function () use ( $sources ): void {
	affirmer( str_contains( $sources, '#ffffff' ) && str_contains( $sources, '#111827' ),
		'sans repli, les pages sont illisibles sur un site dont la charte n’est pas encore faite' );
} );

/* ------------------------------------------------------------------ */

fwrite( STDOUT, sprintf( "\n%d test(s) réussi(s), %d échec(s).\n\n", $reussis, $echoues ) );
exit( $echoues > 0 ? 1 : 0 );
