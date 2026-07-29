<?php
/**
 * Tests du système de mise à jour, sans WordPress ni réseau.
 *
 * Ce qui est couvert, c'est le raisonnement : quelle release concerne quel
 * composant, quel palier s'applique seul, quelle archive installer, et quand
 * refuser. Une erreur ici ne dégrade pas une fonctionnalité — elle installe du
 * code incompatible sur tous les sites clients à la fois.
 *
 * Lancement : php blueprint/mu-plugins/anode-updater/tests/test-updater.php
 *
 * @package Anode\Updater
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

/* --- Stubs WordPress ------------------------------------------------ */

$GLOBALS['anode_actions'] = [];

function add_action( string $hook, $callback, int $priority = 10 ): bool {
	$GLOBALS['anode_actions'][] = $hook;

	return true;
}

function apply_filters( string $hook, $value ) {
	return $value;
}

require_once __DIR__ . '/../anode-updater.php';

use Anode\Updater\Updater;

$passed = 0;
$failed = 0;

function test( string $name, callable $fn ): void {
	global $passed, $failed;

	try {
		$fn();
		++$passed;
		echo "  ✓ {$name}\n";
	} catch ( \Throwable $e ) {
		++$failed;
		echo "  ✗ {$name}\n    {$e->getMessage()}\n";
	}
}

function assert_that( bool $condition, string $message = 'assertion fausse' ): void {
	if ( ! $condition ) {
		throw new \RuntimeException( $message );
	}
}

function assert_same( $attendu, $obtenu, string $message = '' ): void {
	if ( $attendu !== $obtenu ) {
		throw new \RuntimeException(
			sprintf( '%s attendu %s, obtenu %s', $message, var_export( $attendu, true ), var_export( $obtenu, true ) )
		);
	}
}

/* ------------------------------------------------------------------ */

echo "\nTags et releases\n";

test( 'un dépôt par composant utilise des tags nus', function (): void {
	assert_same( '', Updater::prefixe_tag( [ 'repo' => 'theoanode/anode-bridge' ] ) );
	assert_same( '1.2.0', Updater::version_depuis_tag( 'v1.2.0', '' ) );
	assert_same( '1.2.0', Updater::version_depuis_tag( '1.2.0', '' ) );
} );

test( 'un dépôt commun exige un tag préfixé', function (): void {
	$composant = [ 'repo' => 'theoanode/anode-mu-plugins', 'chemin' => 'anode-cache' ];

	assert_same( 'anode-cache-', Updater::prefixe_tag( $composant ) );
	assert_same( '1.2.0', Updater::version_depuis_tag( 'anode-cache-v1.2.0', 'anode-cache-' ) );
	assert_same( null, Updater::version_depuis_tag( 'anode-seo-v1.2.0', 'anode-cache-' ), 'un autre composant' );
	assert_same( null, Updater::version_depuis_tag( 'v1.2.0', 'anode-cache-' ), 'tag nu dans un dépôt commun' );
} );

test( 'un tag qui n’est pas une version est ignoré', function (): void {
	foreach ( [ 'latest', 'v1', 'v1.2.3.4', 'anode-cache-beta', '' ] as $tag ) {
		assert_same( null, Updater::version_depuis_tag( $tag, '' ), "tag « {$tag} » " );
	}
} );

test( 'la release retenue est la plus haute du composant, pas la plus récente du dépôt', function (): void {
	// Cas réel d'un dépôt commun : la dernière release publiée concerne un autre
	// composant, et GitHub la désigne pourtant comme « latest ».
	$releases = [
		[ 'tag_name' => 'anode-seo-v3.0.0' ],
		[ 'tag_name' => 'anode-cache-v1.2.0' ],
		[ 'tag_name' => 'anode-cache-v1.10.0' ],
		[ 'tag_name' => 'anode-cache-v1.9.0' ],
	];

	$trouve = Updater::derniere_release( $releases, 'anode-cache-' );

	assert_that( null !== $trouve, 'aucune release trouvée' );
	assert_same( '1.10.0', $trouve['version'], 'comparaison numérique et non lexicale :' );
} );

test( 'brouillons et préversions sont écartés', function (): void {
	$releases = [
		[ 'tag_name' => 'anode-cache-v2.0.0', 'draft' => true ],
		[ 'tag_name' => 'anode-cache-v1.9.0', 'prerelease' => true ],
		[ 'tag_name' => 'anode-cache-v1.5.0' ],
	];

	assert_same( '1.5.0', Updater::derniere_release( $releases, 'anode-cache-' )['version'] );
} );

test( 'aucune release exploitable renvoie null', function (): void {
	assert_same( null, Updater::derniere_release( [], '' ) );
	assert_same( null, Updater::derniere_release( [ [ 'tag_name' => 'nightly' ] ], '' ) );
} );

/* ------------------------------------------------------------------ */

echo "\nPaliers et automatisme\n";

test( 'le palier distingue correctif, mineure et majeure', function (): void {
	assert_same( 'correctif', Updater::palier( '1.2.3', '1.2.4' ) );
	assert_same( 'mineure', Updater::palier( '1.2.3', '1.3.0' ) );
	assert_same( 'majeure', Updater::palier( '1.2.3', '2.0.0' ) );
	assert_same( 'correctif', Updater::palier( '1.2', '1.2.1' ), 'version à deux chiffres :' );
	assert_same( 'mineure', Updater::palier( '1.0', '1.1' ) );
} );

test( 'par défaut, correctifs et mineures passent, les majeures attendent', function (): void {
	assert_that( Updater::auto_autorise( 'correctif' ), 'un correctif devrait passer' );
	assert_that( Updater::auto_autorise( 'mineure' ), 'une mineure devrait passer' );
	assert_that( ! Updater::auto_autorise( 'majeure' ), 'une majeure ne devrait pas passer seule' );
} );

/* ------------------------------------------------------------------ */

echo "\nChoix de l'archive\n";

test( 'l’archive attendue porte le nom et la version du composant', function (): void {
	$assets = [
		[ 'name' => 'anode-cache-1.2.0.zip', 'browser_download_url' => 'https://exemple/a.zip' ],
		[ 'name' => 'anode-cache-1.2.0.zip.sha256', 'browser_download_url' => 'https://exemple/a.sha' ],
		[ 'name' => 'anode-seo-1.2.0.zip', 'browser_download_url' => 'https://exemple/b.zip' ],
	];

	$trouve = Updater::archive( $assets, 'anode-cache', '1.2.0' );

	assert_that( null !== $trouve, 'archive non trouvée' );
	assert_same( 'https://exemple/a.zip', $trouve['archive']['browser_download_url'] );
	assert_same( 'https://exemple/a.sha', $trouve['empreinte']['browser_download_url'] );
} );

test( 'sans empreinte publiée, l’archive reste installable', function (): void {
	$trouve = Updater::archive( [ [ 'name' => 'anode-seo-2.0.0.zip', 'browser_download_url' => 'u' ] ], 'anode-seo', '2.0.0' );

	assert_that( null !== $trouve );
	assert_same( null, $trouve['empreinte'] );
} );

test( 'le zip de source généré par GitHub n’est jamais retenu', function (): void {
	// Il contient l'arborescence entière du dépôt sous un dossier horodaté :
	// rien qu'on puisse installer sans deviner.
	$assets = [
		[ 'name' => 'Source code (zip)', 'browser_download_url' => 'https://exemple/src.zip' ],
		[ 'name' => 'anode-cache-1.1.0.zip', 'browser_download_url' => 'https://exemple/vieux.zip' ],
	];

	assert_same( null, Updater::archive( $assets, 'anode-cache', '1.2.0' ), 'mauvaise version acceptée :' );
} );

/* ------------------------------------------------------------------ */

echo "\nLecture des en-têtes\n";

$entete = <<<'PHP'
<?php
/**
 * Plugin Name: Anode — cache
 * Version:     1.4.2
 * Requires PHP: 8.1
 * Requires at least: 6.4
 */
PHP;

test( 'la version et les exigences se lisent dans l’en-tête', function () use ( $entete ): void {
	assert_same( '1.4.2', Updater::version_en_tete( $entete ) );
	assert_same( '8.1', Updater::exigence_en_tete( $entete, 'Requires PHP' ) );
	assert_same( '6.4', Updater::exigence_en_tete( $entete, 'Requires at least' ) );
	assert_same( null, Updater::exigence_en_tete( $entete, 'Requires MySQL' ) );
} );

test( 'un fichier sans version est refusé plutôt que supposé', function (): void {
	assert_same( null, Updater::version_en_tete( "<?php\n// rien\n" ) );
} );

test( 'un composant exigeant un PHP plus récent est refusé', function () use ( $entete ): void {
	$motif = Updater::incompatibilite( $entete, '8.0.30', '6.5' );

	assert_that( null !== $motif, 'PHP 8.0 devrait être refusé' );
	assert_that( str_contains( (string) $motif, 'PHP 8.1' ), "motif inattendu : {$motif}" );
} );

test( 'un composant exigeant un WordPress plus récent est refusé', function () use ( $entete ): void {
	$motif = Updater::incompatibilite( $entete, '8.1.29', '6.3' );

	assert_that( null !== $motif, 'WordPress 6.3 devrait être refusé' );
	assert_that( str_contains( (string) $motif, 'WordPress 6.4' ), "motif inattendu : {$motif}" );
} );

test( 'un site conforme accepte le composant', function () use ( $entete ): void {
	assert_same( null, Updater::incompatibilite( $entete, '8.1.29', '6.5' ) );
	assert_same( null, Updater::incompatibilite( $entete, '8.3.0', '7.0' ) );
} );

test( 'sans exigence déclarée, rien ne bloque', function (): void {
	assert_same( null, Updater::incompatibilite( "<?php\n * Version: 1.0.0\n", '7.4.0', '5.0' ) );
} );

/* ------------------------------------------------------------------ */

echo "\nDéclaration des composants\n";

test( 'chaque composant déclare un dépôt', function (): void {
	$composants = Updater::composants();

	assert_that( count( $composants ) >= 5, 'trop peu de composants déclarés' );

	foreach ( $composants as $nom => $declaration ) {
		assert_that( is_string( $nom ) && str_starts_with( $nom, 'anode-' ), "nom inattendu : {$nom}" );
		assert_that( ! empty( $declaration['repo'] ), "{$nom} sans dépôt" );
		assert_that( str_contains( (string) $declaration['repo'], '/' ), "{$nom} : dépôt mal formé" );
	}
} );

test( 'le suivi couvre les mu-plugins du blueprint', function (): void {
	$composants = Updater::composants();
	$attendus   = array_map(
		'basename',
		array_filter( glob( __DIR__ . '/../../*', GLOB_ONLYDIR ) ?: [] )
	);

	foreach ( $attendus as $nom ) {
		assert_that( isset( $composants[ $nom ] ), "{$nom} n’est pas suivi par le système de mise à jour" );
	}
} );

test( 'l’updater se suit lui-même', function (): void {
	assert_that( isset( Updater::composants()['anode-updater'] ), 'l’updater doit pouvoir se mettre à jour' );
} );

/* ------------------------------------------------------------------ */

echo "\n{$passed} test(s) réussi(s), {$failed} échec(s).\n";
exit( $failed ? 1 : 0 );
