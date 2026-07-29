<?php
/**
 * Tests du cache de pages, sans WordPress.
 *
 * On couvre les deux points où une erreur coûte cher : la décision de mettre
 * en cache (servir la page d'un utilisateur connecté à un visiteur anonyme
 * serait une fuite de données) et la purge (un cache qui ne se vide pas fige
 * le site).
 *
 * Lancement : php blueprint/mu-plugins/anode-cache/tests/test-cache.php
 *
 * @package Anode\Cache
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/anode-cache-test' );

/* --- Stubs WordPress ------------------------------------------------ */

/** @var array<string, mixed> État simulé du site. */
$GLOBALS['anode_state'] = [
	'logged_in'   => false,
	'admin'       => false,
	'search'      => false,
	'preview'     => false,
	'not_found'   => false,
	'feed'        => false,
	'trackback'   => false,
	'robots'      => false,
	'singular'    => true,
	'password'    => false,
	'environment' => 'production',
	'filters'     => [],
	'actions'     => [],
];

function is_user_logged_in(): bool {
	return (bool) $GLOBALS['anode_state']['logged_in'];
}
function is_admin(): bool {
	return (bool) $GLOBALS['anode_state']['admin'];
}
function is_search(): bool {
	return (bool) $GLOBALS['anode_state']['search'];
}
function is_preview(): bool {
	return (bool) $GLOBALS['anode_state']['preview'];
}
function is_404(): bool {
	return (bool) $GLOBALS['anode_state']['not_found'];
}
function is_feed(): bool {
	return (bool) $GLOBALS['anode_state']['feed'];
}
function is_trackback(): bool {
	return (bool) $GLOBALS['anode_state']['trackback'];
}
function is_robots(): bool {
	return (bool) $GLOBALS['anode_state']['robots'];
}
function is_singular(): bool {
	return (bool) $GLOBALS['anode_state']['singular'];
}
function post_password_required(): bool {
	return (bool) $GLOBALS['anode_state']['password'];
}
function wp_get_environment_type(): string {
	return (string) $GLOBALS['anode_state']['environment'];
}
function apply_filters( string $hook, $value ) {
	return $GLOBALS['anode_state']['filters'][ $hook ] ?? $value;
}
function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['anode_state']['actions'][ $hook ][] = $callback;
}
function add_filter( string $hook, $callback, int $priority = 10, int $args = 1 ): void {}
function do_action( string $hook, ...$args ): void {}
function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}
function wp_mkdir_p( string $path ): bool {
	return is_dir( $path ) || mkdir( $path, 0o755, true );
}
function wp_delete_file( string $path ): void {
	if ( is_file( $path ) ) {
		unlink( $path );
	}
}
function wp_generate_password( int $length = 12, bool $special = true, bool $extra = false ): string {
	return substr( str_shuffle( str_repeat( 'abcdefghijklmnopqrstuvwxyz0123456789', 3 ) ), 0, $length );
}
function current_user_can( string $capability ): bool {
	return false;
}
function admin_url( string $path = '' ): string {
	return 'https://exemple.fr/wp-admin/' . $path;
}
function wp_nonce_url( string $url, string $action ): string {
	return $url;
}
function __( string $text, string $domain = '' ): string {
	return $text;
}

require_once __DIR__ . '/../anode-cache.php';

use function Anode\Cache\is_cacheable;
use function Anode\Cache\path_for_request;
use function Anode\Cache\purge;
use function Anode\Cache\directory;

/* --- Harnais -------------------------------------------------------- */

$passed = 0;
$failed = 0;

/**
 * @param callable(): void $fn
 */
function test( string $name, callable $fn ): void {
	global $passed, $failed;

	// Chaque test part d'une requête anonyme légitime.
	$GLOBALS['anode_state'] = array_merge(
		$GLOBALS['anode_state'],
		[
			'logged_in' => false,
			'admin'     => false,
			'search'    => false,
			'preview'   => false,
			'not_found' => false,
			'feed'      => false,
			'trackback' => false,
			'robots'    => false,
			'singular'  => true,
			'password'  => false,
			'environment' => 'production',
			'filters'   => [],
		]
	);

	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['REQUEST_URI']    = '/une-page/';
	$_SERVER['HTTP_HOST']      = 'exemple.fr';
	$_GET                      = [];
	$_COOKIE                   = [];

	try {
		$fn();
		++$passed;
		echo "  ✓ {$name}\n";
	} catch ( Throwable $error ) {
		++$failed;
		echo "  ✗ {$name}\n    {$error->getMessage()}\n";
	}
}

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

echo "\nDécision de mise en cache\n";

test(
	'une requête anonyme et simple est mise en cache',
	function (): void {
		assert_true( is_cacheable(), 'une page publique anonyme doit être cachée' );
	}
);

test(
	'un utilisateur connecté n’est jamais servi depuis le cache',
	function (): void {
		$GLOBALS['anode_state']['logged_in'] = true;

		assert_true( ! is_cacheable(), 'servir un utilisateur connecté exposerait sa session' );
	}
);

test(
	'un cookie de session interdit la mise en cache',
	function (): void {
		foreach ( [ 'wordpress_logged_in_abc', 'wp-settings-1', 'comment_author_x' ] as $cookie ) {
			$_COOKIE = [ $cookie => '1' ];

			assert_true( ! is_cacheable(), "le cookie « {$cookie} » aurait dû empêcher la mise en cache" );
		}
	}
);

test(
	'une requête POST n’est pas mise en cache',
	function (): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		assert_true( ! is_cacheable(), 'seules les lectures sont cachées' );
	}
);

test(
	'un paramètre d’URL empêche la mise en cache',
	function (): void {
		$_GET = [ 'utm_source' => 'newsletter' ];

		assert_true( ! is_cacheable(), 'une URL paramétrée peut produire une page différente' );
	}
);

test(
	'les contextes dynamiques sont exclus',
	function (): void {
		foreach ( [ 'search', 'preview', 'not_found', 'feed', 'trackback', 'robots' ] as $context ) {
			$GLOBALS['anode_state'][ $context ] = true;

			assert_true( ! is_cacheable(), "le contexte « {$context} » aurait dû être exclu" );

			$GLOBALS['anode_state'][ $context ] = false;
		}
	}
);

test(
	'une page protégée par mot de passe n’est pas mise en cache',
	function (): void {
		$GLOBALS['anode_state']['password'] = true;

		assert_true( ! is_cacheable(), 'le contenu protégé ne doit pas fuiter' );
	}
);

test(
	'le cache est inactif hors production',
	function (): void {
		foreach ( [ 'local', 'development', 'staging' ] as $environment ) {
			$GLOBALS['anode_state']['environment'] = $environment;

			assert_true( ! is_cacheable(), "le cache doit rester inactif en « {$environment} »" );
		}
	}
);

test(
	'un filtre permet d’exclure une page précise',
	function (): void {
		$GLOBALS['anode_state']['filters']['anode/cache/is_cacheable'] = false;

		assert_true( ! is_cacheable(), 'le filtre d’exclusion doit être respecté' );
	}
);

echo "\nChemin de cache\n";

test(
	'le chemin dérive de l’hôte et de l’URL',
	function (): void {
		$path = path_for_request();

		assert_true( str_contains( $path, 'exemple.fr' ), "l’hôte doit figurer dans le chemin : {$path}" );
		assert_true( str_ends_with( $path, '.html' ), 'le fichier doit être en .html' );
		assert_true( str_contains( $path, md5( '/une-page/' ) ), 'l’URL doit être hachée' );
	}
);

test(
	'aucune traversée de répertoire n’est possible depuis l’URL',
	function (): void {
		$_SERVER['REQUEST_URI'] = '/../../../etc/passwd';
		$_SERVER['HTTP_HOST']   = '../../evil';

		$path = path_for_request();

		assert_true( ! str_contains( $path, '..' ), "chemin dangereux produit : {$path}" );
		assert_true( str_starts_with( $path, directory() ), 'le chemin doit rester dans le dossier de cache' );
	}
);

test(
	'deux URL distinctes produisent deux fichiers distincts',
	function (): void {
		$_SERVER['REQUEST_URI'] = '/page-a/';
		$first                  = path_for_request();

		$_SERVER['REQUEST_URI'] = '/page-b/';
		$second                 = path_for_request();

		assert_true( $first !== $second, 'collision entre deux URL différentes' );
	}
);

echo "\nPurge\n";

test(
	'la purge supprime tous les fichiers de cache',
	function (): void {
		$base = directory() . '/exemple.fr';

		wp_mkdir_p( $base );

		foreach ( [ 'a', 'b', 'c' ] as $name ) {
			file_put_contents( "{$base}/{$name}.html", '<html></html>' );
		}

		assert_true( count( glob( "{$base}/*.html" ) ) === 3, 'les fichiers de test n’ont pas été créés' );

		$removed = purge();

		assert_true( 3 === $removed, "3 suppressions attendues, {$removed} obtenues" );
		assert_true( count( glob( "{$base}/*.html" ) ) === 0, 'des fichiers subsistent après purge' );
	}
);

test(
	'purger un cache vide ne produit pas d’erreur',
	function (): void {
		assert_true( 0 === purge(), 'une purge à vide doit renvoyer 0' );
	}
);

test(
	'la purge est branchée sur les modifications de contenu',
	function (): void {
		foreach ( [ 'save_post', 'deleted_post', 'trashed_post', 'wp_update_nav_menu', 'switch_theme' ] as $hook ) {
			assert_true(
				! empty( $GLOBALS['anode_state']['actions'][ $hook ] ),
				"aucune purge branchée sur « {$hook} »"
			);
		}
	}
);

// Nettoyage.
if ( is_dir( WP_CONTENT_DIR ) ) {
	exec( 'rm -rf ' . escapeshellarg( WP_CONTENT_DIR ) );
}

echo "\n{$passed} test(s) réussi(s), {$failed} échec(s).\n";

exit( $failed > 0 ? 1 : 0 );
