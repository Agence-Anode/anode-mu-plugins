<?php
/**
 * Tests de la résolution des redirections — sans WordPress, sans réseau.
 *
 * Ce qui est vérifié : la normalisation des chemins (barre finale, casse,
 * paramètres de campagne), l'ordre des trois cas, le fait qu'une expression
 * régulière fautive ne fasse pas tomber le site, et les quatre façons dont une
 * table mal remplie rendait une page inatteignable — destination hors domaine
 * remplacée par l'administration, destination égale à la source, destination
 * absente, statut hors 3xx.
 *
 * Lancement : php blueprint/mu-plugins/anode-redirections/tests/test-redirections.php
 *
 * @package Anode\Redirections
 */

declare( strict_types = 1 );

namespace Anode\Redirections;

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

/* --- Doublures WordPress -------------------------------------------- */

function get_stylesheet_directory(): string {
	return __DIR__;
}

function wp_parse_url( string $url, int $component = -1 ) {
	$parts = parse_url( $url );

	if ( false === $parts ) {
		return false;
	}

	if ( PHP_URL_PATH === $component ) {
		return $parts['path'] ?? '';
	}

	if ( PHP_URL_QUERY === $component ) {
		return $parts['query'] ?? null;
	}

	return $parts;
}

function add_action(): void {}
function add_filter(): void {}

/*
 * Le journal des motifs fautifs est plafonné à une ligne par heure et par motif,
 * ce qui suppose une mémoire qui survit à la requête. Ici, un simple tableau :
 * les tests vérifient qu'une trace **part**, pas qu'elle ne repart pas au bout
 * d'une heure — un test qui attendrait une heure ne serait pas un test.
 */
$GLOBALS['anode_transients'] = [];

function get_transient( string $clef ) {
	return $GLOBALS['anode_transients'][ $clef ] ?? false;
}

function set_transient( string $clef, $valeur, int $duree = 0 ): bool {
	$GLOBALS['anode_transients'][ $clef ] = $valeur;

	return true;
}

// `error_log` écrit sur la sortie d'erreur en ligne de commande : on la détourne
// vers un fichier, pour ne pas mêler les traces au compte rendu et pour pouvoir
// vérifier qu'un motif fautif en laisse bien une.
$journal = (string) tempnam( sys_get_temp_dir(), 'anode-redirections-' );
ini_set( 'error_log', $journal );

require_once __DIR__ . '/../anode-redirections.php';

/* --- Harnais --------------------------------------------------------- */

$passed = 0;
$failed = 0;

function test( string $name, callable $fn ): void {
	global $passed, $failed;

	try {
		$fn();
		$passed++;
		echo "  ✓ {$name}\n";
	} catch ( \Throwable $e ) {
		$failed++;
		echo "  ✗ {$name}\n    {$e->getMessage()}\n";
	}
}

function assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new \RuntimeException(
			sprintf( '%s — attendu %s, obtenu %s', $message, var_export( $expected, true ), var_export( $actual, true ) )
		);
	}
}

/* --- Table d'essai --------------------------------------------------- */

$table = [
	'exactes'   => [
		'/contact-2/'      => '/nous-contacter/',
		'/infos-pratiques' => '/reservez-un-espace/',
	],
	'motifs'    => [
		[ 'de' => '^/activity/(.+?)/?$', 'vers' => '/evenements/$1/' ],
		[ 'de' => '^/entreprises-salon/(.+?)/?$', 'vers' => '/residents/$1/' ],
	],
	'disparues' => [ '/une-page-supprimee/' ],
];

echo "\nNormalisation\n";

test(
	'la barre finale est ajoutée, la casse abaissée',
	function (): void {
		assert_same( '/contact-2/', normalize( '/Contact-2' ), 'barre et casse' );
		assert_same( '/contact-2/', normalize( '/contact-2/' ), 'déjà normalisé' );
	}
);

test(
	'la chaîne de requête est ignorée',
	function (): void {
		// Les liens partagés traînent des paramètres de campagne : les laisser
		// entrer dans la comparaison ferait rater la quasi-totalité d'entre eux.
		assert_same( '/contact-2/', normalize( '/contact-2/?utm_source=newsletter' ), 'paramètres' );
	}
);

test(
	'la racine reste la racine',
	function (): void {
		assert_same( '/', normalize( '/' ), 'racine' );
		assert_same( '/', normalize( '' ), 'chaîne vide' );
	}
);

echo "\nChaîne de requête\n";

test(
	'les paramètres de campagne suivent la redirection',
	function (): void {
		// Une annonce Google Ads garde l'ancienne adresse : sans report, le
		// gclid disparaît à la 301, et la conversion n'est plus attribuée.
		assert_same( '/accueil-rse/?gclid=abc&utm_source=google', with_query( '/accueil-rse/', '/ancienne/?gclid=abc&utm_source=google' ), 'report' );
		assert_same( '/accueil-rse/', with_query( '/accueil-rse/', '/ancienne/' ), 'sans requête' );
	}
);

test(
	'la destination garde la main sur ses propres paramètres',
	function (): void {
		assert_same( '/contact/?form=devis&gclid=abc', with_query( '/contact/?form=devis', '/old/?form=autre&gclid=abc' ), 'fusion' );
		assert_same( '/contact/?a=1#formulaire', with_query( '/contact/#formulaire', '/old/?a=1' ), 'ancre en dernier' );
		assert_same( 'https://shop.exemple.fr/?ref=x', with_query( 'https://shop.exemple.fr/', '/boutique/?ref=x' ), 'destination externe' );
	}
);

test(
	'le report est branché sur la redirection servie',
	function (): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/anode-redirections.php' );
		assert_same( true, str_contains( $source, "\$cible['vers'] = with_query( \$cible['vers']" ), 'appel présent' );
	}
);

echo "\nRésolution\n";

test(
	'une correspondance exacte redirige en 301',
	function () use ( $table ): void {
		$r = resolve( '/contact-2/', $table );

		assert_same( '/nous-contacter/', $r['vers'], 'destination' );
		assert_same( 301, $r['statut'], 'statut' );
	}
);

test(
	'une correspondance exacte tolère l’absence de barre finale des deux côtés',
	function () use ( $table ): void {
		assert_same( '/reservez-un-espace/', resolve( '/infos-pratiques/', $table )['vers'], 'clé sans barre' );
		assert_same( '/nous-contacter/', resolve( '/contact-2', $table )['vers'], 'demande sans barre' );
	}
);

test(
	'un motif capture et réinjecte le segment',
	function () use ( $table ): void {
		assert_same(
			'/evenements/atelier-cyber-tech/',
			resolve( '/activity/atelier-cyber-tech/', $table )['vers'],
			'événement'
		);

		assert_same(
			'/residents/eliobot/',
			resolve( '/entreprises-salon/eliobot', $table )['vers'],
			'résident'
		);
	}
);

test(
	'une page déclarée disparue répond 410, pas 404',
	function () use ( $table ): void {
		// 404 fait revenir un moteur pendant des mois ; 410 lui dit que
		// la page ne reviendra pas.
		$r = resolve( '/une-page-supprimee/', $table );

		assert_same( 410, $r['statut'], 'statut' );
	}
);

test(
	'une adresse inconnue ne redirige nulle part',
	function () use ( $table ): void {
		assert_same( null, resolve( '/page-quelconque/', $table ), 'inconnue' );
	}
);

test(
	'l’exact l’emporte sur le motif',
	function (): void {
		$table = [
			'exactes'   => [ '/activity/special/' => '/une-page-a-part/' ],
			'motifs'    => [ [ 'de' => '^/activity/(.+?)/?$', 'vers' => '/evenements/$1/' ] ],
			'disparues' => [],
		];

		assert_same( '/une-page-a-part/', resolve( '/activity/special/', $table )['vers'], 'priorité' );
	}
);

test(
	'un motif fautif ne fait pas tomber le site',
	function (): void {
		// Une expression mal formée dans un fichier de données ne doit pas
		// produire d'erreur fatale sur toutes les pages du site.
		$table = [
			'exactes'   => [],
			'motifs'    => [ [ 'de' => '^/(non-ferme', 'vers' => '/ailleurs/' ] ],
			'disparues' => [],
		];

		assert_same( null, resolve( '/non-ferme/', $table ), 'motif invalide ignoré' );
	}
);

test(
	'un motif qui ne change rien n’est pas une redirection',
	function (): void {
		// Sans ce garde-fou, une expression qui ne correspond pas renverrait le
		// chemin inchangé, et le navigateur boucler sur lui-même.
		$table = [
			'exactes'   => [],
			'motifs'    => [ [ 'de' => '^/rien-a-voir/$', 'vers' => '/ailleurs/' ] ],
			'disparues' => [],
		];

		assert_same( null, resolve( '/une-autre-page/', $table ), 'pas de boucle' );
	}
);

echo "\nStatuts\n";

test(
	'un statut hors 3xx retombe sur 301',
	function (): void {
		// `wp_redirect` appelle `wp_die` hors de la plage 3xx : un statut
		// fantaisiste dans le fichier tuait la page par un écran de mort, au
		// lieu de laisser une 404 répondre.
		assert_same( 301, status( 200 ), 'deux cents' );
		assert_same( 301, status( 0 ), 'clé mal orthographiée' );
		assert_same( 301, status( 'permanente' ), 'texte' );
		assert_same( 301, status( null ), 'absent' );
		assert_same( 302, status( '302' ), 'chaîne numérique' );
		assert_same( 308, status( 308 ), 'admis' );
		assert_same( 410, status( 410 ), 'gone' );
	}
);

test(
	'un motif porte son statut jusqu’à la résolution',
	function (): void {
		$table = [
			'exactes'   => [],
			'motifs'    => [
				[ 'de' => '^/temporaire/$', 'vers' => '/ailleurs/', 'statut' => 302 ],
				[ 'de' => '^/fantaisie/$', 'vers' => '/ailleurs/', 'statut' => 200 ],
			],
			'disparues' => [],
		];

		assert_same( 302, resolve( '/temporaire/', $table )['statut'], 'statut admis' );
		assert_same( 301, resolve( '/fantaisie/', $table )['statut'], 'statut refusé' );
	}
);

echo "\nDestinations\n";

test(
	'une destination sur un autre domaine est reconnue absolue',
	function (): void {
		// `wp_safe_redirect` n'autorise que l'hôte du site : une boutique
		// repartie chez un prestataire était remplacée par /wp-admin/, et le
		// visiteur atterrissait sur un écran de connexion.
		$table = [
			'exactes'   => [ '/boutique/' => 'https://shop.exemple.fr/' ],
			'motifs'    => [],
			'disparues' => [],
		];

		assert_same( 'https://shop.exemple.fr/', resolve( '/boutique/', $table )['vers'], 'destination' );
		assert_same( true, absolute( 'https://shop.exemple.fr/' ), 'avec protocole' );
		assert_same( true, absolute( '//shop.exemple.fr/' ), 'sans protocole' );
		assert_same( false, absolute( '/nous-contacter/' ), 'relative' );
	}
);

test(
	'une destination absolue vers un chemin homonyme n’est pas prise pour une boucle',
	function (): void {
		// La comparaison anti-boucle ne porte que sur le chemin : sans écarter
		// les destinations hors site, celle-ci passerait pour un aller-retour.
		$table = [
			'exactes'   => [ '/boutique/' => 'https://shop.exemple.fr/boutique/' ],
			'motifs'    => [],
			'disparues' => [],
		];

		assert_same( 'https://shop.exemple.fr/boutique/', resolve( '/boutique/', $table )['vers'], 'hors site' );
	}
);

test(
	'une destination égale à la source ne redirige pas',
	function (): void {
		// Cas présent tel quel dans la table de niort-tech : le slug n'avait pas
		// changé, l'entrée a été recopiée quand même. Sans ce contrôle, la page
		// devient définitivement inatteignable — le navigateur boucle.
		$exacte = [
			'exactes'   => [ '/mentions-legales/' => '/mentions-legales/' ],
			'motifs'    => [],
			'disparues' => [],
		];

		assert_same( null, resolve( '/mentions-legales/', $exacte ), 'boucle exacte' );
	}
);

test(
	'une destination qui ne diffère que par la barre finale boucle aussi',
	function (): void {
		// La comparaison se fait sur le chemin normalisé : `/page` et `/page/`
		// sont la même adresse, donc la même boucle.
		$exacte = [
			'exactes'   => [ '/mentions-legales/' => '/mentions-legales' ],
			'motifs'    => [],
			'disparues' => [],
		];

		$motif = [
			'exactes'   => [],
			'motifs'    => [ [ 'de' => '^/(.+?)/?$', 'vers' => '/$1' ] ],
			'disparues' => [],
		];

		assert_same( null, resolve( '/mentions-legales/', $exacte ), 'exacte' );
		assert_same( null, resolve( '/mentions-legales/', $motif ), 'capture réinjectée' );
	}
);

test(
	'une entrée sans destination laisse la 404 répondre',
	function (): void {
		// `wp_redirect` refuse une destination vide et rend `false` ; le `exit`
		// qui suivait renvoyait alors un document vide en 200 — pire qu'une 404,
		// pour un moteur comme pour un visiteur.
		$exacte = [
			'exactes'   => [ '/en-attente/' => '' ],
			'motifs'    => [],
			'disparues' => [],
		];

		$motif = [
			'exactes'   => [],
			'motifs'    => [ [ 'de' => '^/en-attente/$', 'vers' => '' ] ],
			'disparues' => [],
		];

		assert_same( null, resolve( '/en-attente/', $exacte ), 'exacte vide' );
		assert_same( null, resolve( '/en-attente/', $motif ), 'motif vide' );
	}
);

test(
	'une entrée exacte inexploitable n’empêche pas un motif de répondre',
	function (): void {
		$table = [
			'exactes'   => [ '/activity/atelier/' => '' ],
			'motifs'    => [ [ 'de' => '^/activity/(.+?)/?$', 'vers' => '/evenements/$1/' ] ],
			'disparues' => [],
		];

		assert_same( '/evenements/atelier/', resolve( '/activity/atelier/', $table )['vers'], 'relais' );
	}
);

test(
	'un motif sans destination peut déclarer un 410',
	function (): void {
		// Une famille d'adresses disparue se déclare par motif : le 410 n'a pas
		// de destination par nature, il ne doit donc pas être écarté avec les
		// entrées vides.
		$table = [
			'exactes'   => [],
			'motifs'    => [ [ 'de' => '^/promo-2019/.*$', 'vers' => '', 'statut' => 410 ] ],
			'disparues' => [],
		];

		$r = resolve( '/promo-2019/soldes/', $table );

		assert_same( 410, $r['statut'], 'statut' );
		assert_same( '', $r['vers'], 'sans destination' );
	}
);

echo "\nMotifs\n";

test(
	'un motif peut contenir le délimiteur',
	function (): void {
		// Le tilde délimite l'expression : sans échappement, elle se refermait
		// trop tôt, ne compilait plus, et la règle disparaissait sans un mot.
		$table = [
			'exactes'   => [],
			'motifs'    => [ [ 'de' => '^/~theo/(.+?)/?$', 'vers' => '/equipe/$1/' ] ],
			'disparues' => [],
		];

		assert_same( '/equipe/cv/', resolve( '/~theo/cv/', $table )['vers'], 'tilde' );
	}
);

test(
	'un tilde déjà échappé n’est pas échappé deux fois',
	function (): void {
		assert_same( '^/\~theo/', delimiter( '^/~theo/' ), 'à échapper' );
		assert_same( '^/\~theo/', delimiter( '^/\~theo/' ), 'déjà échappé' );
	}
);

test(
	'un motif fautif laisse une trace dans le journal',
	function () use ( $journal ): void {
		// Sans trace, la règle est perdue en silence : la redirection ne prend
		// pas et l'on cherche du côté du serveur pendant une heure.
		$table = [
			'exactes'   => [],
			'motifs'    => [ [ 'de' => '^/catalogue/[a-z', 'vers' => '/produits/' ] ],
			'disparues' => [],
		];

		assert_same( null, resolve( '/catalogue/vis/', $table ), 'motif ignoré' );

		$trace = (string) file_get_contents( $journal );

		assert_same( true, str_contains( $trace, '^/catalogue/[a-z' ), 'motif nommé dans le journal' );
	}
);

echo "\nOrdre des motifs\n";

test(
	'un motif masqué par un motif plus général est signalé',
	function (): void {
		// Les quatre motifs de niort-tech, dans leur ordre réel : la pagination
		// des archives est déclarée après le motif générique des fiches, qui
		// l'attrape déjà — /activity/page/2/ part donc vers
		// /evenements/page/2/, qui n'existe pas.
		$motifs = [
			[ 'de' => '^/activity/(.+?)/?$', 'vers' => '/evenements/$1/' ],
			[ 'de' => '^/entreprises-salon/(.+?)/?$', 'vers' => '/residents/$1/' ],
			[ 'de' => '^/activity-(?:type|year|month)/.*$', 'vers' => '/evenements/' ],
			[ 'de' => '^/activity/page/[0-9]+/?$', 'vers' => '/evenements/' ],
		];

		$masques = masked( $motifs );

		assert_same( 1, count( $masques ), 'un seul masqué' );
		assert_same( 3, $masques[0]['motif'], 'la pagination' );
		assert_same( 0, $masques[0]['masque_par'], 'par le motif générique' );
	}
);

test(
	'le motif le plus précis placé en premier ne déclenche rien',
	function (): void {
		// L'ordre du fichier fait loi : le premier motif qui correspond gagne.
		$motifs = [
			[ 'de' => '^/activity/page/[0-9]+/?$', 'vers' => '/evenements/' ],
			[ 'de' => '^/activity/(.+?)/?$', 'vers' => '/evenements/$1/' ],
		];

		assert_same( [], masked( $motifs ), 'ordre correct' );
	}
);

test(
	'le spécimen d’un motif est une adresse concrète',
	function (): void {
		// Deux expressions ne se comparent pas entre elles : on fabrique une
		// adresse que le motif attraperait, et on la propose aux précédents.
		assert_same( '/activity/segment/', specimen( '^/activity/(.+?)/?$' ), 'capture' );
		assert_same( '/activity/page/segment/', specimen( '^/activity/page/[0-9]+/?$' ), 'classe' );
		assert_same( '/activity-type/segment/', specimen( '^/activity-(?:type|year|month)/.*$' ), 'alternative' );
	}
);

echo "\nCasse\n";

test(
	'la casse est abaissée aussi sur les accents',
	function (): void {
		// `strtolower` ne descend que l'ASCII : « /%C3%89coles/ » restait
		// « /Écoles/ » d'un seul côté de la comparaison, et la redirection ne
		// prenait jamais.
		//
		// Le chemin est éprouvé sous sa forme percent-encodée, celle qu'un
		// navigateur envoie : `parse_url` — donc `wp_parse_url` — abîme les
		// octets hauts d'une adresse accentuée brute, avant même que la casse
		// soit abaissée. Une clé accentuée s'écrit donc encodée dans le fichier.
		if ( ! function_exists( 'mb_strtolower' ) ) {
			return;
		}

		assert_same( '/écoles/', normalize( '/%C3%89coles/' ), 'accent encodé' );
		assert_same( '/écoles/', normalize( '/%C3%A9COLES/' ), 'déjà minuscule accentuée' );

		$table = [
			'exactes'   => [ '/%C3%89coles/' => '/formations/' ],
			'motifs'    => [],
			'disparues' => [],
		];

		assert_same( '/formations/', resolve( '/%C3%89COLES/', $table )['vers'], 'clé accentuée' );
	}
);

/* --- Le 410 tient-il jusqu'au visiteur ? ----------------------------- */

/**
 * Retire les commentaires d'un source PHP, pour qu'une mesure de présence
 * porte sur du **code**.
 *
 * Les chaînes restent : ce qu'on cherche ici est un appel dont les arguments
 * en sont — retirer les chaînes rendrait le test faux dans l'autre sens.
 *
 * Sans cela, la prose satisfait la preuve : le commentaire qui explique
 * pourquoi on retire `redirect_canonical` contient son nom, et le test
 * passerait sur un fichier dont on aurait supprimé l'appel.
 */
function code_seul( string $source ): string {
	$sortie = '';

	foreach ( token_get_all( $source ) as $t ) {
		if ( is_array( $t ) ) {
			if ( in_array( $t[0], [ T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML ], true ) ) {
				continue;
			}

			$sortie .= $t[1];
			continue;
		}

		$sortie .= $t;
	}

	return $sortie;
}

$source = (string) file_get_contents( dirname( __DIR__ ) . '/anode-redirections.php' );
$code   = code_seul( $source );

test(
	'le dépouilleur retire bien la prose',
	function () use ( $source, $code ): void {
		// Le témoin : une phrase qui n'existe QUE dans un commentaire du
		// fichier. Si elle survit au dépouillement, tout ce qui suit ment.
		assert_same( true, str_contains( $source, 'écrase alors le 410' ), 'la phrase est bien dans le fichier' );
		assert_same( false, str_contains( $code, 'écrase alors le 410' ), 'et absente du code seul' );
	}
);

test(
	'une adresse disparue neutralise la redirection canonique de WordPress',
	function () use ( $code ): void {
		/*
		 * `status_header( 410 )` ne termine pas la requête. `redirect_canonical`
		 * passe ensuite et devine un permalien proche : sa 301 écrase le 410.
		 * Mesuré le 28/09/2026 — une adresse sur dix-sept partait ainsi.
		 */
		assert_same( true, str_contains( $code, "remove_action( 'template_redirect', 'redirect_canonical' )" ), 'redirect_canonical retiré' );
		assert_same( true, str_contains( $code, "do_redirect_guess_404_permalink" ), 'devinette coupée' );
	}
);

printf( "\n%d test(s) réussi(s), %d échec(s).\n", $passed, $failed );

unlink( $journal );

exit( $failed > 0 ? 1 : 0 );
