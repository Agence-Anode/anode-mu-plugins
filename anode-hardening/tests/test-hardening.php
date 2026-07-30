<?php
/**
 * Tests du durcissement — sans WordPress, sans réseau.
 *
 * Ce composant n'avait aucun test, et c'est celui dont une erreur coûte le plus
 * cher : il décide de l'adresse par laquelle on se connecte. La branche du
 * masquage est arrivée en production avec une erreur fatale — donc **aucune**
 * façon de se connecter — précisément parce que rien ne l'exerçait : elle était
 * éteinte en développement, et personne ne la testait.
 *
 * Ce qui est vérifié ici, c'est le raisonnement pur : le segment d'URL, la
 * décision de masquer, la normalisation du chemin demandé, et le compteur de
 * tentatives. Le comportement servi — les 404, le formulaire de connexion, les
 * en-têtes — relève de `./bin/test-mu-plugins.mjs`, qui interroge un vrai site.
 *
 * Lancement : php blueprint/mu-plugins/anode-hardening/tests/test-hardening.php
 *
 * @package Anode\Hardening
 */

declare( strict_types = 1 );

namespace Anode\Hardening;

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );

/* --- Doublures WordPress --------------------------------------------- */

/**
 * État simulé du site. Les doublures lisent et écrivent ici, et chaque test
 * repart d'une base propre par `reinitialiser()`.
 *
 * @var array<string, mixed>
 */
$etat = [];

function reinitialiser( array $depart = [] ): void {
	global $etat;

	$etat = array_merge(
		[
			'options'    => [],
			'transients' => [],
			'connecte'   => false,
			'base'       => 'http://exemple.test',
			'requete'    => '/',
		],
		$depart
	);
}

reinitialiser();

function get_option( string $nom, $defaut = false ) {
	global $etat;

	return $etat['options'][ $nom ] ?? $defaut;
}

function delete_option( string $nom ): bool {
	global $etat;

	if ( ! array_key_exists( $nom, $etat['options'] ) ) {
		return false;
	}

	unset( $etat['options'][ $nom ] );

	return true;
}

function get_transient( string $clef ) {
	global $etat;

	return $etat['transients'][ $clef ] ?? false;
}

function set_transient( string $clef, $valeur ): bool {
	global $etat;

	$etat['transients'][ $clef ] = $valeur;

	return true;
}

function delete_transient( string $clef ): bool {
	global $etat;

	unset( $etat['transients'][ $clef ] );

	return true;
}

function site_url(): string {
	global $etat;

	return $etat['base'];
}

function home_url( string $chemin = '' ): string {
	global $etat;

	return $etat['base'] . $chemin;
}

function is_user_logged_in(): bool {
	global $etat;

	return (bool) $etat['connecte'];
}

/**
 * Reproduit ce que fait `sanitize_title` sur les seules formes qui nous
 * concernent : un segment d'URL. La vraie fonction en fait davantage — accents,
 * entités — et l'imiter en entier n'apprendrait rien de plus sur ce fichier.
 */
function sanitize_title( string $titre ): string {
	$titre = strtolower( trim( $titre ) );
	$titre = (string) preg_replace( '/[^a-z0-9_-]+/', '-', $titre );

	return trim( $titre, '-' );
}

function wp_parse_url( string $url, int $composant = -1 ) {
	$parts = parse_url( $url );

	if ( false === $parts ) {
		return false;
	}

	return PHP_URL_PATH === $composant ? ( $parts['path'] ?? '' ) : $parts;
}

function add_action(): void {}
function add_filter(): void {}
function remove_action(): void {}
function apply_filters( string $nom, $valeur ) {
	return $valeur;
}
function current_user_can(): bool {
	return false;
}
function is_admin(): bool {
	return false;
}
function is_author(): bool {
	return false;
}
function is_ssl(): bool {
	return false;
}
function status_header(): void {}
function nocache_headers(): void {}
function get_404_template(): string {
	return '';
}
function wp_safe_redirect(): bool {
	return true;
}
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName
function __( string $texte ): string {
	return $texte;
}

require_once __DIR__ . '/../anode-hardening.php';

/* --- Harnais --------------------------------------------------------- */

$passed = 0;
$failed = 0;

function test( string $nom, callable $fn ): void {
	global $passed, $failed;

	reinitialiser();

	try {
		$fn();
		$passed++;
		echo "  ✓ {$nom}\n";
	} catch ( \Throwable $e ) {
		$failed++;
		echo "  ✗ {$nom}\n      {$e->getMessage()}\n";
	}
}

function section( string $titre ): void {
	echo "\n{$titre}\n";
}

function assertSame( $attendu, $obtenu, string $message = '' ): void {
	if ( $attendu !== $obtenu ) {
		throw new \RuntimeException(
			sprintf(
				'%sattendu %s, obtenu %s',
				$message ? $message . ' : ' : '',
				var_export( $attendu, true ),
				var_export( $obtenu, true )
			)
		);
	}
}

function assertTrue( bool $condition, string $message = 'condition fausse' ): void {
	if ( ! $condition ) {
		throw new \RuntimeException( $message );
	}
}

/* --- Segment d'URL de connexion -------------------------------------- */

section( 'Segment d’URL de connexion' );

test( 'le segment vaut « anode-connexion » partout, sans réglage', function (): void {
	assertSame( 'anode-connexion', login_slug() );
} );

test( 'l’ancienne option aléatoire est ignorée', function (): void {
	/*
	 * C'est le cœur de la décision : un segment tiré au hasard par site obligeait
	 * à aller le chercher en base à chaque intervention. Les sites créés avant le
	 * changement en gardent un en base — il ne doit plus rien décider.
	 */
	global $etat;

	$etat['options'][ OPTION_LOGIN_SLUG ] = 'acces-1NhmD0WN';

	assertSame( 'anode-connexion', login_slug() );
} );

test( 'le segment ne contient rien qui casse une URL', function (): void {
	assertSame( LOGIN_SLUG_DEFAUT, sanitize_title( LOGIN_SLUG_DEFAUT ) );
} );

/* --- Décision de masquer --------------------------------------------- */

section( 'Décision de masquer' );

test( 'le masquage est actif par défaut, y compris en développement', function (): void {
	/*
	 * Il était éteint en local, et la branche n'a donc jamais été exercée avant
	 * une mise en ligne : elle est arrivée en production avec une fatale. Le
	 * segment étant désormais fixe, il n'y a plus de raison de l'éteindre.
	 */
	assertTrue( login_masking_enabled() );
} );

/* --- Chemin demandé -------------------------------------------------- */

section( 'Chemin demandé' );

/** @return string Chemin tel que le composant le voit. */
function chemin_pour( string $requete, string $base = 'http://exemple.test' ): string {
	global $etat;

	$etat['base'] = $base;

	$_SERVER['REQUEST_URI'] = $requete;

	return requested_path();
}

test( 'la racine donne un chemin vide', function (): void {
	assertSame( '', chemin_pour( '/' ) );
} );

test( 'les barres de tête et de queue sont retirées', function (): void {
	assertSame( 'anode-connexion', chemin_pour( '/anode-connexion/' ) );
} );

test( 'la chaîne de requête est écartée', function (): void {
	assertSame(
		'anode-connexion',
		chemin_pour( '/anode-connexion?redirect_to=%2Fwp-admin%2F&reauth=1' )
	);
} );

test( 'une installation en sous-répertoire retire son préfixe', function (): void {
	/*
	 * Sans ce retrait, `/blog/anode-connexion` ne correspondait à rien et la
	 * connexion devenait impossible sur ce genre d'installation.
	 */
	assertSame(
		'anode-connexion',
		chemin_pour( '/blog/anode-connexion', 'http://exemple.test/blog' )
	);
} );

test( 'le préfixe n’est retiré que s’il est bien un préfixe de chemin', function (): void {
	// « /blogueur/… » commence par les mêmes lettres que « /blog » sans en être
	// un sous-chemin : le retirer produirait « ueur/… ».
	assertSame(
		'blogueur/anode-connexion',
		chemin_pour( '/blogueur/anode-connexion', 'http://exemple.test/blog' )
	);
} );

test( 'wp-admin est reconnu, avec ou sans barre finale', function (): void {
	assertSame( 'wp-admin', chemin_pour( '/wp-admin/' ) );
	assertSame( 'wp-admin/options-general.php', chemin_pour( '/wp-admin/options-general.php' ) );
} );

test( 'admin-ajax est reconnu tel quel', function (): void {
	assertSame( 'wp-admin/admin-ajax.php', chemin_pour( '/wp-admin/admin-ajax.php?action=x' ) );
} );

/* --- Compteur de tentatives ------------------------------------------ */

section( 'Compteur de tentatives' );

test( 'une adresse neuve n’est pas verrouillée', function (): void {
	$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

	assertTrue( ! is_locked_out() );
} );

test( 'le verrou se ferme au seuil, pas avant', function (): void {
	$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

	for ( $essai = 1; $essai < MAX_LOGIN_ATTEMPTS; $essai++ ) {
		record_failed_attempt();

		assertTrue( ! is_locked_out(), "verrouillé dès le {$essai}ᵉ essai" );
	}

	record_failed_attempt();

	assertTrue( is_locked_out(), 'pas verrouillé au seuil' );
} );

test( 'le compteur est propre à une adresse', function (): void {
	$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

	for ( $essai = 0; $essai < MAX_LOGIN_ATTEMPTS; $essai++ ) {
		record_failed_attempt();
	}

	assertTrue( is_locked_out(), 'la première adresse devrait être verrouillée' );

	$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

	assertTrue( ! is_locked_out(), 'le verrou d’une adresse en atteint une autre' );
} );

test( 'la clé du compteur ne laisse pas fuiter l’adresse', function (): void {
	/*
	 * La clé finit dans la table des options d'un site partagé : y écrire
	 * l'adresse en clair serait une donnée personnelle conservée sans motif.
	 */
	$clef = attempt_key( '198.51.100.7' );

	assertTrue( false === strpos( $clef, '198.51.100.7' ), 'adresse en clair dans la clé' );
	assertTrue( '' !== $clef, 'clé vide' );
} );

test( 'deux adresses donnent deux clés différentes', function (): void {
	assertTrue( attempt_key( '198.51.100.7' ) !== attempt_key( '203.0.113.9' ) );
} );

test( 'une adresse absente ne fait pas tomber le compteur', function (): void {
	unset( $_SERVER['REMOTE_ADDR'] );

	// Ne doit pas lever : une requête sans adresse est possible en ligne de commande.
	record_failed_attempt();

	assertTrue( true );
} );

/* --- Compte rendu ---------------------------------------------------- */

echo "\n{$passed} test(s) réussi(s), {$failed} échec(s).\n";

exit( $failed > 0 ? 1 : 0 );
