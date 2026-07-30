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

/**
 * De quoi éprouver le traitement des retours d'erreur de WordPress.
 *
 * C'est le strict nécessaire : un code, un message, et le test d'appartenance
 * que fait `is_wp_error()`.
 */
class WP_Error {

	public function __construct( private string $code = '', private string $message = '' ) {}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

function is_wp_error( $chose ): bool {
	return $chose instanceof WP_Error;
}

require_once __DIR__ . '/../anode-updater.php';

use Anode\Updater\Installateur;
use Anode\Updater\Signature;
use Anode\Updater\Sonde;
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

test( 'un réglage explicite fixe le plafond', function (): void {
	assert_same( 'majeure', Updater::plafond_auto( true ), 'true accepte tout :' );
	assert_same( null, Updater::plafond_auto( false ), 'false n’applique rien :' );

	foreach ( [ 'correctif', 'mineure', 'majeure' ] as $palier ) {
		assert_same( $palier, Updater::plafond_auto( $palier ) );
	}
} );

test( 'un réglage inconnu n’applique rien, au lieu de retomber sur « mineure »', function (): void {
	// Les paliers sont en français : 'patch' et 'minor' viennent naturellement, et
	// obtenaient silencieusement autre chose que ce que leur auteur croyait.
	foreach ( [ 'patch', 'minor', 'major', 'MINEURE', '', 1, 0, [ 'mineure' ], null ] as $reglage ) {
		assert_same( null, Updater::plafond_auto( $reglage ), 'réglage ' . var_export( $reglage, true ) . ' : ' );
	}
} );

/* ------------------------------------------------------------------ */

echo "\nChoix de l'archive\n";

test( 'l’archive attendue porte le nom et la version du composant', function (): void {
	$assets = [
		[ 'name' => 'anode-cache-1.2.0.zip', 'url' => 'https://api/a', 'browser_download_url' => 'https://exemple/a.zip' ],
		[ 'name' => 'anode-cache-1.2.0.zip.sha256', 'url' => 'https://api/a.sha', 'browser_download_url' => 'https://exemple/a.sha' ],
		[ 'name' => 'anode-seo-1.2.0.zip', 'url' => 'https://api/b', 'browser_download_url' => 'https://exemple/b.zip' ],
	];

	$trouve = Updater::archive( $assets, 'anode-cache', '1.2.0' );

	assert_that( null !== $trouve, 'archive non trouvée' );
	assert_same( 'https://api/a', $trouve['archive']['url'] );
	assert_same( 'https://api/a.sha', $trouve['empreinte']['url'] );
} );

test( 'sans empreinte publiée, l’archive reste installable', function (): void {
	$trouve = Updater::archive( [ [ 'name' => 'anode-seo-2.0.0.zip', 'url' => 'https://api/u' ] ], 'anode-seo', '2.0.0' );

	assert_that( null !== $trouve );
	assert_same( null, $trouve['empreinte'] );
	assert_same( null, $trouve['signature'] );
} );

test( 'la signature détachée est repérée à côté de son archive', function (): void {
	$assets = [
		[ 'name' => 'anode-cache-1.2.0.zip', 'url' => 'https://api/a' ],
		[ 'name' => 'anode-cache-1.2.0.zip.sig', 'url' => 'https://api/a.sig' ],
		// Celle d'une autre version ne doit surtout pas être retenue : elle
		// vérifierait une archive qu'on n'installe pas.
		[ 'name' => 'anode-cache-1.1.0.zip.sig', 'url' => 'https://api/vieux.sig' ],
	];

	assert_same( 'https://api/a.sig', Updater::archive( $assets, 'anode-cache', '1.2.0' )['signature']['url'] );
} );

test( 'un asset sans URL d’API est refusé', function (): void {
	// C'est le défaut qui a cassé la première installation réelle :
	// browser_download_url passe par github.com, qui ignore le jeton et répond
	// 404 sur un dépôt privé. Seule l'URL d'API est utilisable.
	$assets = [ [ 'name' => 'anode-cache-1.2.0.zip', 'browser_download_url' => 'https://exemple/a.zip' ] ];

	assert_same( null, Updater::archive( $assets, 'anode-cache', '1.2.0' ) );
} );

test( 'le zip de source généré par GitHub n’est jamais retenu', function (): void {
	// Il contient l'arborescence entière du dépôt sous un dossier horodaté :
	// rien qu'on puisse installer sans deviner.
	$assets = [
		[ 'name' => 'Source code (zip)', 'url' => 'https://api/src' ],
		[ 'name' => 'anode-cache-1.1.0.zip', 'url' => 'https://api/vieux' ],
	];

	assert_same( null, Updater::archive( $assets, 'anode-cache', '1.2.0' ), 'mauvaise version acceptée :' );
} );

/* ------------------------------------------------------------------ */

echo "\nRetours du système de fichiers\n";

/** Appelle le juge d'échec de l'installateur, qui est privé à dessein. */
function echec( $resultat ): ?WP_Error {
	$methode = new \ReflectionMethod( Installateur::class, 'echec' );
	$methode->setAccessible( true );

	return $methode->invoke( null, $resultat, 'anode_updater_copie', 'Impossible d’installer anode-cache.' );
}

test( 'un WP_Error de copy_dir est un échec, jamais un succès', function (): void {
	// Le défaut qui a coûté le plus cher : copy_dir() et unzip_file() rendent
	// true ou un WP_Error — jamais false. Un test sur « ! $resultat » prenait donc
	// l'échec pour une réussite, l'objet d'erreur étant vrai : l'état annonçait
	// « installée », la sauvegarde n'était pas remise, le composant restait amputé.
	$echec = echec( new WP_Error( 'copy_failed_copy_dir', 'copie refusée par le serveur' ) );

	assert_that( $echec instanceof WP_Error, 'un WP_Error doit être reconnu comme un échec' );
	assert_that( str_contains( $echec->get_error_message(), 'copie refusée' ), 'le motif d’origine doit être conservé' );
	assert_that( str_contains( $echec->get_error_message(), 'anode-cache' ), 'le composant doit être nommé' );
} );

test( 'seul true vaut succès', function (): void {
	assert_same( null, echec( true ), 'true est le seul succès :' );

	// WP_Filesystem, lui, rend bien un booléen : les deux formes doivent passer.
	foreach ( [ false, null, 0, '', 1 ] as $resultat ) {
		assert_that(
			echec( $resultat ) instanceof WP_Error,
			'retour ' . var_export( $resultat, true ) . ' pris pour un succès'
		);
	}
} );

test( 'le dossier de transit reste invisible pour le chargeur de mu-plugins', function (): void {
	// La nouvelle version est montée à côté de l'ancienne, puis basculée par
	// renommage : écrire dans le dossier en place laissait une fenêtre de quelques
	// centaines de millisecondes pendant laquelle une requête chargeait un
	// mu-plugin incomplet. Encore faut-il que ce dossier de transit ne soit pas
	// lui-même chargé — le chargeur parcourt glob( '*', GLOB_ONLYDIR ).
	$transit = Installateur::dossier_transit( 'anode-cache' );

	assert_that( str_starts_with( $transit, '.' ), "« {$transit} » devrait être caché" );

	$racine = sys_get_temp_dir() . '/anode-updater-test-' . getmypid();

	mkdir( $racine . '/' . $transit, 0777, true );
	mkdir( $racine . '/anode-cache', 0777, true );

	$vus = array_map( 'basename', glob( $racine . '/*', GLOB_ONLYDIR ) ?: [] );

	rmdir( $racine . '/' . $transit );
	rmdir( $racine . '/anode-cache' );
	rmdir( $racine );

	assert_same( [ 'anode-cache' ], $vus, 'le dossier de transit est visible du chargeur :' );
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

echo "\nSignature des archives\n";

/*
 * Vecteur produit par Node, avec exactement le code de `bin/release-mu-plugin.mjs` :
 * `sign( null, octets, cle )` sur une paire Ed25519, clé publique exportée brute.
 *
 * Le figer ici plutôt que de resigner à la volée est le seul moyen d'éprouver
 * l'interopérabilité **dans la suite** : ce que PHP vérifie, c'est bien ce que
 * Node produit, et un changement de format d'un côté fait tomber ce test.
 */
$archive_signee = 'PK archive factice de anode-cache 1.2.0';
$cle_publique   = '7B/X4q9rvHoqsxIZO2qJaw7bOKSbweAYRik3BrfPRE8=';
$cle_etrangere  = '5L/CtVhtLC0vZMdQDp7rYq6sMyORh2DsUN1EIuHem3c=';
$signature      = '49EFV5OCjBktqjIQbZP02AGWfe507QdKt0X6PZpdVGeWn3+5ZudMcieoTrMUoPAM2LcC1g2vUHB9pike60X6BA==';

test( 'une signature produite par le script de publication est acceptée', function () use ( $archive_signee, $signature, $cle_publique ): void {
	assert_same( null, Signature::verifier_donnees( $archive_signee, $signature, $cle_publique ) );

	// Les fichiers .sig sont écrits avec un saut de ligne final.
	assert_same( null, Signature::verifier_donnees( $archive_signee, $signature . "\n", $cle_publique ) );
} );

test( 'une archive modifiée d’un octet est refusée', function () use ( $archive_signee, $signature, $cle_publique ): void {
	// C'est le cas qui compte : une archive remplacée après signature.
	assert_that( null !== Signature::verifier_donnees( $archive_signee . ' ', $signature, $cle_publique ) );
	assert_that( null !== Signature::verifier_donnees( substr( $archive_signee, 0, -1 ), $signature, $cle_publique ) );
} );

test( 'une signature valide sous une autre clé est refusée', function () use ( $archive_signee, $signature, $cle_etrangere ): void {
	// Le compte GitHub compromis qui resigne avec sa propre paire : la signature
	// est parfaitement valide, elle n'est simplement pas la nôtre.
	$motif = Signature::verifier_donnees( $archive_signee, $signature, $cle_etrangere );

	assert_that( null !== $motif, 'une clé étrangère ne doit pas passer' );
	assert_that( str_contains( (string) $motif, 'ne correspond pas' ), "motif inattendu : {$motif}" );
} );

test( 'un fichier .sig qui n’est pas une signature est nommé comme tel', function () use ( $archive_signee, $cle_publique ): void {
	// Une page d'erreur HTML téléchargée à la place de la signature ne doit pas
	// ressortir sous « signature invalide » : la cause est ailleurs.
	foreach ( [ '', '<!doctype html><h1>404</h1>', 'bWF1dmFpc2UgdGFpbGxl' ] as $contenu ) {
		$motif = Signature::verifier_donnees( $archive_signee, $contenu, $cle_publique );

		assert_that( null !== $motif, 'contenu accepté : ' . var_export( $contenu, true ) );
		assert_that( str_contains( (string) $motif, 'illisible' ), "motif inattendu : {$motif}" );
	}
} );

test( 'une clé publique mal formée est nommée comme telle', function () use ( $archive_signee, $signature ): void {
	// Le piège d'ANODE_UPDATER_CLE : coller le SPKI complet, ou une clé SSH, au
	// lieu des trente-deux octets bruts.
	foreach ( [ '', 'MCowBQYDK2VwAyEA', 'ssh-ed25519 AAAAC3Nza' ] as $cle ) {
		$motif = Signature::verifier_donnees( $archive_signee, $signature, $cle );

		assert_that( null !== $motif, 'clé acceptée : ' . var_export( $cle, true ) );
		assert_that( str_contains( (string) $motif, 'clé publique illisible' ), "motif inattendu : {$motif}" );
	}
} );

test( 'sans clé de confiance, rien n’est exigé', function (): void {
	assert_same( null, Updater::refus_signature( null, false ) );
	assert_same( null, Updater::refus_signature( '', false ) );
} );

test( 'avec une clé, une release sans signature est refusée', function () use ( $cle_publique ): void {
	// Sans cette règle, la protection se contourne en ne publiant pas le .sig —
	// c'est-à-dire par celui-là même contre qui elle protège.
	assert_that( null !== Updater::refus_signature( $cle_publique, false ) );
	assert_same( null, Updater::refus_signature( $cle_publique, true ) );
} );

test( 'la clé du manifeste est vide, ou utilisable', function (): void {
	// Une clé mal recopiée — SPKI complet, clé SSH, caractère perdu — ne se voit
	// nulle part avant que les trente sites ne refusent tous la même archive. Le
	// test tient donc aussi une fois le parc armé : ce qu'il interdit, c'est la
	// valeur intermédiaire, ni absente ni exploitable.
	$cle = (string) Updater::cle_publique();

	assert_that( ! Updater::cle_epinglee(), 'ANODE_UPDATER_CLE n’a rien à faire dans les tests' );

	if ( '' === $cle ) {
		return;
	}

	$brute = base64_decode( $cle, true );

	assert_that( is_string( $brute ) && 32 === strlen( $brute ), "clé publique inexploitable : {$cle}" );
} );

/* ------------------------------------------------------------------ */

echo "\nAncrage de la clé de confiance\n";

test( 'une version qui change la clé de confiance est retenue', function (): void {
	// La faille évidente d'une clé qui voyage avec le code : sans ce refus, un
	// dépôt compromis publie un updater portant sa propre clé, signé par elle, et
	// la signature ne protège plus que jusqu'à la prochaine mise à jour.
	$motif = Updater::rotation_refusee( 'clefA', 'clefB', false );

	assert_that( null !== $motif, 'un changement de clé doit être retenu' );
	assert_that( str_contains( (string) $motif, 'à la main' ), "motif inattendu : {$motif}" );
} );

test( 'la même clé passe, et les espaces de recopie ne comptent pas', function (): void {
	assert_same( null, Updater::rotation_refusee( 'clefA', 'clefA', false ) );
	assert_same( null, Updater::rotation_refusee( 'clefA', "  clefA\n", false ) );
} );

test( 'un site sans clé peut en adopter une', function (): void {
	assert_same( null, Updater::rotation_refusee( null, 'clefA', false ) );
	assert_same( null, Updater::rotation_refusee( '', 'clefA', false ) );
} );

test( 'une clé épinglée dans wp-config rend la question sans objet', function (): void {
	// La constante l'emporte de toute façon : ce que le manifeste apporte n'est
	// plus consulté, il n'y a donc rien à retenir.
	assert_same( null, Updater::rotation_refusee( 'clefA', 'clefB', true ) );
} );

/* ------------------------------------------------------------------ */

echo "\nSonde d'après installation\n";

test( 'le marqueur attendu vaut succès', function (): void {
	assert_same( 'ok', Sonde::juger( null, 200, "anode-updater-sonde:xyz", 'anode-updater-sonde:xyz' )['etat'] );
} );

test( 'un 500 est le seul code qui fait reculer', function (): void {
	assert_same( 'echec', Sonde::juger( null, 500, '', 'attendu' )['etat'], 'une fatale rend 500 :' );

	// 502, 503 et 504 viennent d'un intermédiaire — maintenance de WordPress,
	// PHP-FPM qui redémarre, passerelle lente. Défaire une mise à jour sur cette
	// base reviendrait à la défaire chaque nuit sur un hébergement un peu lent.
	foreach ( [ 502, 503, 504 ] as $code ) {
		assert_same( 'indetermine', Sonde::juger( null, $code, '', 'attendu' )['etat'], "code {$code} :" );
	}
} );

test( 'une protection d’accès ne fait pas reculer', function (): void {
	// Le cas des préproductions sous htpasswd : la sonde ne voit jamais le site,
	// et prendre cela pour une panne défaisait chaque nuit une version saine.
	foreach ( [ 401, 403, 407 ] as $code ) {
		$verdict = Sonde::juger( null, $code, 'Authorization Required', 'attendu' );

		assert_same( 'indetermine', $verdict['etat'], "code {$code} :" );
		assert_that( str_contains( $verdict['message'], 'protection d’accès' ) );
	}
} );

test( 'un site injoignable ne conclut pas', function (): void {
	$verdict = Sonde::juger( 'cURL error 7: Failed to connect', 0, '', 'attendu' );

	assert_same( 'indetermine', $verdict['etat'] );
	assert_that( str_contains( $verdict['message'], 'Failed to connect' ), 'le motif réseau doit être conservé' );
} );

test( 'une page servie sans le marqueur ne conclut pas', function (): void {
	// Un cache de page peut répondre 200 sans jamais atteindre PHP : cela ne dit
	// rien du composant qu'on vient d'écrire, ni en bien ni en mal.
	assert_same( 'indetermine', Sonde::juger( null, 200, '<!doctype html><title>Accueil</title>', 'attendu' )['etat'] );
	assert_same( 'indetermine', Sonde::juger( null, 302, '', 'attendu' )['etat'] );
	assert_same( 'indetermine', Sonde::juger( null, 404, '', 'attendu' )['etat'] );
} );

test( 'un marqueur d’une autre sonde ne vaut pas succès', function (): void {
	// Le jeton est tiré à chaque essai : une réponse rejouée depuis un cache, ou
	// depuis le journal d'accès du serveur, ne prouve rien sur maintenant.
	assert_same( 'indetermine', Sonde::juger( null, 200, 'anode-updater-sonde:ancien', 'anode-updater-sonde:frais' )['etat'] );
} );

/* ------------------------------------------------------------------ */

echo "\nJournal des installations\n";

test( 'la dernière installation est en tête', function (): void {
	$journal = Updater::journaliser( [], [ 'composant' => 'anode-seo', 'vers' => '1.0.0' ] );
	$journal = Updater::journaliser( $journal, [ 'composant' => 'anode-seo', 'vers' => '1.1.0' ] );

	assert_same( '1.1.0', $journal[0]['vers'] );
	assert_same( '1.0.0', $journal[1]['vers'] );
} );

test( 'le journal est borné', function (): void {
	// Une option de site part avec chaque export de base : un journal qui grossit
	// sans fin finit par se payer à chaque sauvegarde.
	$journal = [];

	for ( $i = 0; $i < 12; $i++ ) {
		$journal = Updater::journaliser( $journal, [ 'vers' => (string) $i ], 5 );
	}

	assert_same( 5, count( $journal ) );
	assert_same( '11', $journal[0]['vers'], 'la plus récente est gardée :' );
	assert_same( '7', $journal[4]['vers'], 'les plus anciennes tombent :' );
} );

test( 'un message trop long est coupé, sans casser l’UTF-8', function (): void {
	$entree = Updater::journaliser( [], [ 'message' => str_repeat( 'é', 500 ) ] )[0];

	assert_same( Updater::JOURNAL_MESSAGE_MAX, mb_strlen( $entree['message'] ) );
	assert_same( $entree['message'], (string) mb_convert_encoding( $entree['message'], 'UTF-8', 'UTF-8' ), 'coupure au milieu d’un caractère :' );
} );

test( 'un message sur plusieurs lignes tient sur une', function (): void {
	// Les messages de WP_Error reprennent parfois une sortie serveur entière.
	assert_same( 'copie refusée par le serveur', Updater::journaliser( [], [ 'message' => "copie refusée\n  par le  serveur\n" ] )[0]['message'] );
} );

test( 'une entrée sans message n’en porte pas', function (): void {
	assert_that( ! isset( Updater::journaliser( [], [ 'resultat' => 'installee' ] )[0]['message'] ) );
} );

/* ------------------------------------------------------------------ */

echo "\n{$passed} test(s) réussi(s), {$failed} échec(s).\n";
exit( $failed ? 1 : 0 );
