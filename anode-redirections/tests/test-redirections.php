<?php
/**
 * Tests de la résolution des redirections — sans WordPress, sans réseau.
 *
 * Ce qui est vérifié : la normalisation des chemins (barre finale, casse,
 * paramètres de campagne), l'ordre des trois cas, et le fait qu'une expression
 * régulière fautive ne fasse pas tomber le site.
 *
 * Lancement : php blueprint/mu-plugins/anode-redirections/tests/test-redirections.php
 *
 * @package Anode\Redirections
 */

declare( strict_types = 1 );

namespace Anode\Redirections;

define( 'ABSPATH', __DIR__ );

/* --- Doublures WordPress -------------------------------------------- */

function get_stylesheet_directory(): string {
	return __DIR__;
}

function wp_parse_url( string $url, int $component = -1 ) {
	$parts = parse_url( $url );

	if ( false === $parts ) {
		return false;
	}

	return PHP_URL_PATH === $component ? ( $parts['path'] ?? '' ) : $parts;
}

function add_action(): void {}
function add_filter(): void {}

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

printf( "\n%d test(s) réussi(s), %d échec(s).\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
