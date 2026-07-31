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
			'accroches'  => [],
			'retraits'   => [],
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

	if ( PHP_URL_PATH === $composant ) {
		return $parts['path'] ?? '';
	}

	// L'hôte décide de la portée de HSTS : il doit pouvoir varier d'un test à
	// l'autre, comme le chemin.
	if ( PHP_URL_HOST === $composant ) {
		return $parts['host'] ?? '';
	}

	return $parts;
}

/**
 * Les accroches sont enregistrées, pas exécutées : les tests appellent la
 * fermeture qui les intéresse en la reprenant dans `$etat['accroches']`.
 */
function add_action( string $accroche, $rappel = null, int $priorite = 10 ): void {
	global $etat;

	$etat['accroches'][ $accroche ][] = $rappel;
}
/**
 * Les filtres sont retenus comme les actions : l'en-tête HSTS réellement émis
 * ne s'éprouve qu'en rappelant la fermeture posée sur `wp_headers`. Vérifier la
 * fonction d'aide seule laisserait passer une erreur de câblage.
 */
function add_filter( string $filtre, $rappel = null, int $priorite = 10 ): void {
	global $etat;

	$etat['filtres'][ $filtre ][] = $rappel;
}

/**
 * `remove_action` est observée : c'est elle qui désarme la redirection de
 * WordPress vers l'URL de connexion, et rien d'autre ne le prouverait.
 */
function remove_action( string $accroche, $rappel, int $priorite = 10 ): void {
	global $etat;

	$etat['retraits'][] = [ $accroche, $rappel, $priorite ];
}
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
	global $etat;

	return (bool) ( $etat['ssl'] ?? false );
}
/**
 * WordPress rend « production » sans déclaration. La doublure en fait autant :
 * c'est l'état de la plupart des installations, et c'est celui où le défaut de
 * portée de HSTS se manifestait.
 */
function wp_get_environment_type(): string {
	global $etat;

	return (string) ( $etat['environnement'] ?? 'production' );
}
/**
 * `WP_Error`, réduite à ce que le durcissement en consulte : son code.
 *
 * **Aliasée dans l'espace global**, parce que le composant écrit `new \WP_Error(…)`
 * pour refuser une connexion verrouillée : une doublure rangée dans le namespace
 * laisserait ce chemin sans doublure, et c'est le plus sensible du fichier.
 */
class ErreurWp {
	public function __construct( private string $code = '', private string $message = '' ) {}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

class_alias( ErreurWp::class, 'WP_Error' );

function is_wp_error( $chose ): bool {
	return $chose instanceof \WP_Error;
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

/*
 * Les accroches ne sont enregistrées qu'une fois, au chargement du fichier —
 * alors que `reinitialiser()` vide l'état avant chaque test. On les fige donc
 * ici, hors de l'état remis à zéro, pour pouvoir rejouer une fermeture précise.
 *
 * @var array<string, list<callable>>
 */
$accroches_enregistrees = $etat['accroches'];

/**
 * Même raison pour les filtres.
 *
 * @var array<string, list<callable>>
 */
$filtres_enregistres = $etat['filtres'] ?? [];

/** Rejoue la fermeture enregistrée sur une accroche donnée. */
function rejouer( string $accroche, int $rang = 0 ): void {
	global $accroches_enregistrees;

	$rappel = $accroches_enregistrees[ $accroche ][ $rang ] ?? null;

	if ( ! is_callable( $rappel ) ) {
		throw new \RuntimeException( "aucune fermeture enregistrée sur « {$accroche} » au rang {$rang}" );
	}

	$rappel();
}

/**
 * Rejoue une fermeture **avec ses arguments**.
 *
 * `rejouer()` appelle sans rien : suffisant tant qu'aucune accroche ne lisait ce
 * que WordPress lui passe. Ce n'est plus vrai — l'échec d'un mot de passe
 * d'application porte son motif, et c'est de lui que dépend la décision de
 * compter. Sans ce passeur, on n'éprouverait que la fonction de décision, pas son
 * câblage : une accroche qui ignorerait l'argument passerait au vert.
 *
 * @param list<mixed> $arguments
 */
function rejouer_avec( string $accroche, array $arguments, int $rang = 0 ): void {
	global $accroches_enregistrees;

	$rappel = $accroches_enregistrees[ $accroche ][ $rang ] ?? null;

	if ( ! is_callable( $rappel ) ) {
		throw new \RuntimeException( "aucune fermeture enregistrée sur « {$accroche} » au rang {$rang}" );
	}

	$rappel( ...$arguments );
}

/**
 * Rejoue **toutes** les fermetures posées sur un filtre, dans l'ordre, et rend
 * la sortie de la dernière.
 *
 * Chaîner n'est pas un raffinement : `wp_headers` en porte deux — l'une retire
 * `X-Pingback`, l'autre pose les en-têtes de sécurité. N'en rejouer qu'une
 * rendait un tableau vide, et une assertion en `! isset(…)` passait au vert sur
 * ce vide. Le test disait « aucun HSTS posé » alors qu'il n'avait rien exercé.
 */
function filtrer( string $filtre, $valeur ) {
	global $filtres_enregistres;

	$rappels = $filtres_enregistres[ $filtre ] ?? [];

	if ( ! $rappels ) {
		throw new \RuntimeException( "aucun filtre enregistré sur « {$filtre} »" );
	}

	foreach ( $rappels as $rappel ) {
		if ( is_callable( $rappel ) ) {
			$valeur = $rappel( $valeur );
		}
	}

	return $valeur;
}

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

test( 'la redirection de WordPress vers /login est désarmée', function (): void {
	/*
	 * `wp_redirect_admin_locations()` répond à `/login` par une redirection vers
	 * `wp_login_url()` — que nos filtres réécrivent en URL masquée. WordPress
	 * mettait donc le segment masqué en clair dans l'en-tête `Location`, en
	 * réponse à une requête anonyme sur une adresse que tout le monde essaie.
	 *
	 * Le retrait vise la priorité 1000 : `remove_action` ne retire rien si la
	 * priorité ne correspond pas, et l'échec serait silencieux.
	 */
	global $etat;

	rejouer( 'init', 1 );

	assertSame(
		[ [ 'template_redirect', 'wp_redirect_admin_locations', 1000 ] ],
		$etat['retraits']
	);
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

test( 'les identifiants d’une autre porte ne ferment pas le verrou', function (): void {
	/*
	 * Le défaut mesuré, et il coupait le pilotage du site.
	 *
	 * Sur une préproduction protégée par `.htpasswd`, le navigateur présente les
	 * identifiants du serveur sur chaque requête — appels REST de Bricks compris.
	 * WordPress y voyait autant de tentatives de mot de passe d'application, et le
	 * verrou se fermait au bout de cinq requêtes : `wp_is_application_passwords_available()`
	 * passait à `false`, le MCP répondait « Permissions insuffisantes », et un harnais
	 * en cours perdait le moyen de retirer son décor. Un aller-retour dans le builder
	 * suffisait à l'obtenir.
	 *
	 * Le nom présenté ne désigne aucun compte : aucun mot de passe n'est vérifié, il
	 * n'y a donc rien à forcer. Le seuil est franchi trois fois ici — un `>=` mal placé
	 * ne passerait pas au vert par marge.
	 */
	reinitialiser();

	$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

	for ( $essai = 0; $essai < MAX_LOGIN_ATTEMPTS + 3; $essai++ ) {
		rejouer_avec( 'application_password_failed_authentication', [ new \WP_Error( 'invalid_username', '' ) ] );
	}

	assertTrue( ! is_locked_out(), 'les identifiants du .htpasswd ont fermé le verrou du site' );

	// Et l'adresse électronique inconnue, l'autre forme du même refus : WordPress
	// accepte les deux, et n'en signale qu'une à la fois.
	for ( $essai = 0; $essai < MAX_LOGIN_ATTEMPTS + 3; $essai++ ) {
		rejouer_avec( 'application_password_failed_authentication', [ new \WP_Error( 'invalid_email', '' ) ] );
	}

	assertTrue( ! is_locked_out(), 'une adresse inconnue est comptée comme une tentative' );
} );

test( 'un mot de passe faux sur un compte existant ferme le verrou', function (): void {
	/*
	 * L'autre moitié, sans laquelle le correctif ci-dessus serait un désarmement :
	 * le forçage d'un mot de passe d'application exige un compte existant, et c'est
	 * exactement ce cas que WordPress signale par `incorrect_password`.
	 */
	reinitialiser();

	$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

	for ( $essai = 1; $essai < MAX_LOGIN_ATTEMPTS; $essai++ ) {
		rejouer_avec( 'application_password_failed_authentication', [ new \WP_Error( 'incorrect_password', '' ) ] );

		assertTrue( ! is_locked_out(), "verrouillé dès le {$essai}ᵉ essai" );
	}

	rejouer_avec( 'application_password_failed_authentication', [ new \WP_Error( 'incorrect_password', '' ) ] );

	assertTrue( is_locked_out(), 'l’API REST n’est plus gardée : c’est la porte que ce composant a été écrit pour fermer' );
} );

test( 'un échec sans motif lisible compte quand même', function (): void {
	/*
	 * Une version de WordPress qui n'enverrait pas l'erreur, ou l'enverrait
	 * autrement, ne doit pas désarmer le compteur en silence : mieux vaut un verrou
	 * de trop qu'une porte ouverte.
	 */
	reinitialiser();

	$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

	for ( $essai = 0; $essai < MAX_LOGIN_ATTEMPTS; $essai++ ) {
		rejouer_avec( 'application_password_failed_authentication', [ null ] );
	}

	assertTrue( is_locked_out(), 'un échec sans motif a désarmé le compteur' );
} );

test( 'une adresse absente ne fait pas tomber le compteur', function (): void {
	unset( $_SERVER['REMOTE_ADDR'] );

	// Ne doit pas lever : une requête sans adresse est possible en ligne de commande.
	record_failed_attempt();

	assertTrue( true );
} );

/* --- Portée de HSTS -------------------------------------------------- */

section( 'Portée de HSTS' );

test( 'un site servi depuis son domaine engage ses sous-domaines', function (): void {
	reinitialiser( [ 'base' => 'https://exemple.fr', 'ssl' => true ] );

	assertSame(
		'max-age=31536000; includeSubDomains',
		filtrer( 'wp_headers', [] )['Strict-Transport-Security'] ?? null
	);
} );

test( 'le « www » ne compte pas comme un sous-domaine de plus', function (): void {
	reinitialiser( [ 'base' => 'https://www.exemple.fr', 'ssl' => true ] );

	assertSame(
		'max-age=31536000; includeSubDomains',
		filtrer( 'wp_headers', [] )['Strict-Transport-Security'] ?? null
	);
} );

test( 'une préproduction sur un domaine partagé n’engage que son hôte', function (): void {
	/*
	 * Le défaut mesuré en ligne : `client.anode-staging.fr` posait un an de HSTS
	 * `includeSubDomains` sur `anode-staging.fr`, donc sur chaque autre
	 * préproduction du parc, dans le navigateur de qui l'avait visitée. Et il n'y
	 * a pas de rétractation : le remède est manuel, poste par poste.
	 */
	reinitialiser( [ 'base' => 'https://client.anode-staging.fr', 'ssl' => true ] );

	assertSame(
		'max-age=31536000',
		filtrer( 'wp_headers', [] )['Strict-Transport-Security'] ?? null
	);
} );

test( 'sans TLS, aucun HSTS n’est posé', function (): void {
	// L'en-tête servi en clair est ignoré par le navigateur, et annoncerait une
	// garantie que la connexion ne tient pas.
	reinitialiser( [ 'base' => 'http://exemple.fr', 'ssl' => false ] );

	assertTrue( ! isset( filtrer( 'wp_headers', [] )['Strict-Transport-Security'] ) );
} );

test( 'un en-tête déjà posé par le serveur n’est pas écrasé', function (): void {
	reinitialiser( [ 'base' => 'https://exemple.fr', 'ssl' => true ] );

	$entetes = filtrer( 'wp_headers', [ 'Strict-Transport-Security' => 'max-age=63072000; preload' ] );

	assertSame( 'max-age=63072000; preload', $entetes['Strict-Transport-Security'] );
} );

test( 'une adresse IP littérale n’engage aucun sous-domaine', function (): void {
	// `explode('.')` y compterait quatre labels, mais la question n'a pas de sens.
	reinitialiser( [ 'base' => 'https://198.51.100.7', 'ssl' => true ] );

	assertSame( false, engage_les_sous_domaines() );
} );

test( 'les en-têtes de base sont posés quoi qu’il arrive', function (): void {
	reinitialiser( [ 'base' => 'http://exemple.fr', 'ssl' => false ] );

	$entetes = filtrer( 'wp_headers', [] );

	assertSame( 'nosniff', $entetes['X-Content-Type-Options'] ?? null );
	assertSame( 'SAMEORIGIN', $entetes['X-Frame-Options'] ?? null );
} );

/* --- API REST d'un site pas encore public ----------------------------- */

section( 'API REST d’un site pas encore public' );

/**
 * Une requête REST réduite à ce que le filtre en lit : sa route.
 */
function requete_rest( string $route ): object {
	return new class( $route ) {
		public function __construct( private string $route ) {}

		public function get_route(): string {
			return $this->route;
		}
	};
}

/** Rejoue `rest_pre_dispatch`, qui reçoit trois arguments et non un seul. */
function dispatcher( string $route, $resultat = null ) {
	global $filtres_enregistres;

	$rappels = $filtres_enregistres['rest_pre_dispatch'] ?? [];

	if ( ! $rappels ) {
		throw new \RuntimeException( 'aucun filtre enregistré sur « rest_pre_dispatch »' );
	}

	foreach ( $rappels as $rappel ) {
		$resultat = $rappel( $resultat, null, requete_rest( $route ) );
	}

	return $resultat;
}

test( 'hors production, une route de contenu est refusée à l’anonyme', function (): void {
	/*
	 * Le défaut mesuré le 01/08/2026 sur la préproduction d'un site client :
	 * `curl https://…/wp-json/wp/v2/pages` rendait 200 et 19 847 octets, sans le
	 * moindre identifiant, alors que toute page du site répondait 401. Le
	 * htpasswd doit exempter `/wp-json/` — l'API attend le mot de passe
	 * d'application dans le même en-tête — et cette exemption rouvrait tout.
	 */
	reinitialiser( [ 'environnement' => 'staging' ] );

	$refus = dispatcher( '/wp/v2/pages' );

	assertTrue( is_wp_error( $refus ), 'le contenu est servi à un visiteur anonyme' );
	assertSame( 'anode_rest_prive', $refus->get_error_code() );
} );

test( 'le moteur de formulaire reste ouvert', function (): void {
	// Sinon chaque formulaire est inerte sur la préproduction — donc là même où
	// le client le relit.
	reinitialiser( [ 'environnement' => 'staging' ] );

	assertSame( null, dispatcher( '/anode/v1/form/contact' ) );
	assertSame( null, dispatcher( '/anode/v1/form' ) );
} );

test( 'le compte de service, authentifié, passe', function (): void {
	// C'est tout le pilotage sans SSH qui en dépend.
	reinitialiser( [ 'environnement' => 'staging', 'connecte' => true ] );

	assertSame( null, dispatcher( '/wp/v2/pages' ) );
} );

test( 'en production, l’API sert le public comme avant', function (): void {
	reinitialiser( [ 'environnement' => 'production' ] );

	assertSame( null, dispatcher( '/wp/v2/pages' ) );
} );

test( 'un refus déjà décidé ailleurs n’est pas remplacé', function (): void {
	// Écraser le verdict d'un autre filtre ferait disparaître son motif, et le
	// diagnostic avec.
	reinitialiser( [ 'environnement' => 'staging' ] );

	$deja = new \WP_Error( 'autre_chose', 'motif d’origine' );

	assertSame( 'autre_chose', dispatcher( '/wp/v2/pages', $deja )->get_error_code() );
} );

/* --- Compte rendu ---------------------------------------------------- */

echo "\n{$passed} test(s) réussi(s), {$failed} échec(s).\n";

exit( $failed > 0 ? 1 : 0 );
