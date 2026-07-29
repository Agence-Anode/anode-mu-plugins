<?php
/**
 * Tests de l'extension SEO, sans WordPress.
 *
 * On couvre les points où une erreur passe inaperçue puis coûte cher : un
 * titre qui ignore le champ saisi, une balise « robots » qui laisse indexer
 * une préproduction, une valeur non échappée dans l'en-tête.
 *
 * Lancement : php blueprint/mu-plugins/anode-seo/tests/test-seo.php
 *
 * @package Anode\Seo
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );

/* --- Stubs WordPress ------------------------------------------------ */

$GLOBALS['anode_seo_actions'] = [];

function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['anode_seo_actions'][ $hook ][] = $callback;
}
function add_filter( string $hook, $callback, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['anode_seo_actions'][ $hook ][] = $callback;
}
function remove_action( string $hook, $callback, int $priority = 10 ): void {}
function esc_attr( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}
function esc_url( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}
function esc_url_raw( string $value ): string {
	return filter_var( $value, FILTER_SANITIZE_URL ) ?: '';
}
function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}
function sanitize_textarea_field( string $value ): string {
	return trim( strip_tags( $value ) );
}
function get_post_types( array $args = [], string $output = 'names' ): array {
	return [ 'page', 'post' ];
}
function register_post_meta( string $post_type, string $key, array $args ): bool {
	$GLOBALS['anode_seo_meta'][ $post_type ][ $key ] = $args;

	return true;
}
function current_user_can( string $capability, ...$args ): bool {
	return true;
}

require_once dirname( __DIR__ ) . '/anode-seo.php';

use Anode\Seo\Seo;

/* --- Harnais --------------------------------------------------------- */

$passed = 0;
$failed = 0;

function test( string $name, callable $assertion ): void {
	global $passed, $failed;

	try {
		$assertion();
		++$passed;
		echo "  ✓ {$name}\n";
	} catch ( \Throwable $error ) {
		++$failed;
		echo "  ✗ {$name}\n    {$error->getMessage()}\n";
	}
}

function assert_same( $expected, $actual, string $message = '' ): void {
	if ( $expected !== $actual ) {
		throw new \RuntimeException(
			sprintf( '%s attendu : %s, obtenu : %s', $message, var_export( $expected, true ), var_export( $actual, true ) )
		);
	}
}

function assert_true( bool $value, string $message ): void {
	if ( ! $value ) {
		throw new \RuntimeException( $message );
	}
}

/** Retrouve une balise par son nom ou sa propriété. */
function find_tag( array $tags, string $key, string $value ): ?array {
	foreach ( $tags as $tag ) {
		if ( ( $tag[ $key ] ?? null ) === $value ) {
			return $tag;
		}
	}

	return null;
}

function context( array $overrides = [] ): array {
	return array_merge(
		[
			'is_front'            => false,
			'title'               => 'Nos services',
			'site_name'           => 'Acme',
			'tagline'             => 'Menuiserie sur mesure',
			'title_template'      => '%page% · %site%',
			'seo_title'           => '',
			'seo_description'     => '',
			'default_description' => 'Atelier de menuiserie à Niort.',
			'excerpt'             => '',
			'canonical'           => '',
			'url'                 => 'https://acme.fr/nos-services',
			'noindex'             => false,
			'indexable'           => true,
			'locale'              => 'fr_FR',
			'image'               => '',
			'type'                => 'website',
			'published'           => '',
			'modified'            => '',
		],
		$overrides
	);
}

/* --- Titre ------------------------------------------------------------ */

echo "\nTitre du document\n";

test(
	'le gabarit du site est appliqué au titre de la page',
	static fn () => assert_same( 'Nos services · Acme', Seo::document_title( context() ) )
);

test(
	'le titre saisi l’emporte sur le gabarit',
	static fn () => assert_same(
		'Menuiserie sur mesure à Niort — Acme',
		Seo::document_title( context( [ 'seo_title' => 'Menuiserie sur mesure à Niort — Acme' ] ) )
	)
);

test(
	'la page d’accueil affiche le nom du site et son slogan, sans doublon',
	static fn () => assert_same(
		'Acme · Menuiserie sur mesure',
		Seo::document_title( context( [ 'is_front' => true ] ) )
	)
);

test(
	'une page sans titre retombe sur le nom du site',
	static fn () => assert_same( 'Acme', Seo::document_title( context( [ 'title' => '' ] ) ) )
);

/* --- Description ------------------------------------------------------- */

echo "\nDescription\n";

test(
	'la description saisie prime sur l’extrait et sur celle du site',
	static fn () => assert_same(
		'Description saisie.',
		Seo::description( context( [ 'seo_description' => 'Description saisie.', 'excerpt' => 'Extrait.' ] ) )
	)
);

test(
	'à défaut, l’extrait sert de description',
	static fn () => assert_same( 'Extrait.', Seo::description( context( [ 'excerpt' => 'Extrait.' ] ) ) )
);

test(
	'en dernier recours, la description par défaut du site',
	static fn () => assert_same( 'Atelier de menuiserie à Niort.', Seo::description( context() ) )
);

/* --- Balises ------------------------------------------------------------ */

echo "\nBalises de l’en-tête\n";

test(
	'une page indexable reçoit sa canonique et une directive d’indexation',
	static function (): void {
		$tags = Seo::tags( context() );

		assert_same( 'index, follow, max-image-preview:large', find_tag( $tags, 'name', 'robots' )['content'] );
		assert_same( 'https://acme.fr/nos-services', find_tag( $tags, 'rel', 'canonical' )['href'] );
	}
);

test(
	'une page exclue perd sa canonique et passe en noindex',
	static function (): void {
		$tags = Seo::tags( context( [ 'noindex' => true ] ) );

		assert_same( 'noindex, nofollow', find_tag( $tags, 'name', 'robots' )['content'] );
		assert_same( null, find_tag( $tags, 'rel', 'canonical' ), 'canonique présente à tort :' );
	}
);

test(
	'un site non indexable passe en noindex même sans directive de page',
	static function (): void {
		$tags = Seo::tags( context( [ 'indexable' => false ] ) );

		assert_same( 'noindex, nofollow', find_tag( $tags, 'name', 'robots' )['content'] );
	}
);

test(
	'la canonique saisie remplace l’URL de la page',
	static function (): void {
		$tags = Seo::tags( context( [ 'canonical' => 'https://acme.fr/reference' ] ) );

		assert_same( 'https://acme.fr/reference', find_tag( $tags, 'rel', 'canonical' )['href'] );
		assert_same( 'https://acme.fr/reference', find_tag( $tags, 'property', 'og:url' )['content'] );
	}
);

test(
	'la carte Twitter dépend de la présence d’une image',
	static function (): void {
		assert_same( 'summary', find_tag( Seo::tags( context() ), 'name', 'twitter:card' )['content'] );

		$withImage = Seo::tags( context( [ 'image' => 'https://acme.fr/partage.jpg' ] ) );

		assert_same( 'summary_large_image', find_tag( $withImage, 'name', 'twitter:card' )['content'] );
		assert_same( 'https://acme.fr/partage.jpg', find_tag( $withImage, 'property', 'og:image' )['content'] );
	}
);

test(
	'les dates ne sont posées que sur un article',
	static function (): void {
		$page = Seo::tags( context( [ 'published' => '2026-01-01T09:00:00+00:00' ] ) );

		assert_same( null, find_tag( $page, 'property', 'article:published_time' ), 'date sur une page :' );

		$article = Seo::tags(
			context( [ 'type' => 'article', 'published' => '2026-01-01T09:00:00+00:00', 'modified' => '2026-02-02T09:00:00+00:00' ] )
		);

		assert_same( '2026-01-01T09:00:00+00:00', find_tag( $article, 'property', 'article:published_time' )['content'] );
		assert_same( '2026-02-02T09:00:00+00:00', find_tag( $article, 'property', 'article:modified_time' )['content'] );
	}
);

/* --- Rendu -------------------------------------------------------------- */

echo "\nRendu\n";

test(
	'les guillemets d’une description sont échappés',
	static function (): void {
		$html = Seo::render( Seo::tags( context( [ 'seo_description' => 'Une "belle" cuisine' ] ) ) );

		assert_true( str_contains( $html, 'Une &quot;belle&quot; cuisine' ), 'description non échappée' );
		assert_true( ! str_contains( $html, '"belle"' ), 'guillemets bruts présents dans le HTML' );
	}
);

test(
	'chaque balise est fermée et bien formée',
	static function (): void {
		$html  = Seo::render( Seo::tags( context() ) );
		$lines = array_values( array_filter( explode( "\n", $html ), static fn ( $line ) => str_starts_with( $line, '<' ) ) );

		foreach ( $lines as $line ) {
			if ( str_starts_with( $line, '<!--' ) ) {
				continue;
			}

			assert_true( (bool) preg_match( '#^<(meta|link) [^<>]+ />$#', $line ), "balise mal formée : {$line}" );
		}

		assert_true( count( $lines ) > 5, 'trop peu de balises produites' );
	}
);

/* --- Nettoyage des champs ------------------------------------------------ */

echo "\nNettoyage des champs\n";

test(
	'noindex n’accepte que des valeurs vraies explicites',
	static function (): void {
		assert_same( true, Seo::sanitize( 'noindex', '1' ) );
		assert_same( true, Seo::sanitize( 'noindex', true ) );
		assert_same( false, Seo::sanitize( 'noindex', '0' ) );
		assert_same( false, Seo::sanitize( 'noindex', 'peut-être' ) );
	}
);

test(
	'l’identifiant d’image ne peut pas être négatif',
	static function (): void {
		assert_same( 0, Seo::sanitize( 'image', -12 ) );
		assert_same( 42, Seo::sanitize( 'image', '42' ) );
	}
);

test(
	'le titre est débarrassé de son HTML',
	static fn () => assert_same( 'Titre', Seo::sanitize( 'title', '<b>Titre</b>' ) )
);

test(
	'les champs sont déclarés sur chaque type de contenu public',
	static function (): void {
		( new \ReflectionMethod( Seo::class, 'register_meta' ) )->invoke( new Seo() );

		foreach ( [ 'page', 'post' ] as $post_type ) {
			foreach ( array_keys( Seo::FIELDS ) as $field ) {
				assert_true(
					isset( $GLOBALS['anode_seo_meta'][ $post_type ][ Seo::PREFIX . $field ] ),
					"champ {$field} non déclaré sur {$post_type}"
				);
			}
		}
	}
);

echo "\n{$passed} test(s) réussi(s), {$failed} échec(s).\n";
exit( $failed ? 1 : 0 );
