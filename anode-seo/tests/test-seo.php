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
$GLOBALS['anode_seo_removed'] = [];

function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['anode_seo_actions'][ $hook ][] = $callback;
}
function add_filter( string $hook, $callback, int $priority = 10, int $args = 1 ): void {
	// Relevés à part : un filtre et une action ne se vérifient pas de la même
	// façon, et confondre les deux registres masquait qu'aucun filtre n'était posé.
	$GLOBALS['anode_seo_actions'][ $hook ][] = $callback;
	$GLOBALS['anode_seo_filters'][ $hook ][] = $callback;
}
function remove_action( string $hook, $callback, int $priority = 10 ): void {
	$GLOBALS['anode_seo_removed'][ $hook ][] = $callback;
}
function wp_parse_url( string $url, int $component = -1 ) {
	$parts = parse_url( $url );

	if ( false === $parts ) {
		return false;
	}

	if ( -1 === $component ) {
		return $parts;
	}

	$noms = [ PHP_URL_HOST => 'host', PHP_URL_PATH => 'path', PHP_URL_SCHEME => 'scheme' ];

	return $parts[ $noms[ $component ] ?? '' ] ?? '';
}

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

/* --- Stubs WordPress : état de la requête ---------------------------- */

/*
 * context() est la seule partie de l'extension qui parle à WordPress, et c'est
 * là que vivaient deux défauts qu'aucune fonction pure ne pouvait montrer : le
 * SEO de la page des articles que personne ne lisait, et l'image de partage
 * sans dimensions. Le prix de cette couverture est une poignée de stubs, tous
 * pilotés par un seul tableau d'état.
 */
function wp_state( array $overrides = [] ): void {
	$GLOBALS['wp_state'] = array_merge(
		[
			'singular'    => false,
			'post_type'   => 'page',
			// Le domaine servi. Ne pas confondre avec « home », qui est is_home().
			'site_url'    => 'https://acme.fr',
			'home'        => false,
			'front'       => false,
			'paged'       => false,
			'search'      => false,
			// « not_found » plutôt que « 404 » : une clé numérique redevient un
			// entier, et array_merge() renumérote les entiers — la valeur serait
			// perdue à chaque appel.
			'not_found'   => false,
			'queried'     => 0,
			'options'     => [],
			'meta'        => [],
			'titles'      => [],
			'excerpts'    => [],
			'permalinks'  => [],
			'thumbnails'  => [],
			'attachments' => [],
			'by_url'      => [],
			'times'       => [],
			'modified'    => [],
			'bloginfo'    => [ 'name' => 'Acme', 'description' => 'Menuiserie sur mesure' ],
		],
		$overrides
	);
}

wp_state();

function is_singular( $type = '' ): bool {
	if ( ! $GLOBALS['wp_state']['singular'] ) {
		return false;
	}

	return '' === $type || $type === $GLOBALS['wp_state']['post_type'];
}
function is_home(): bool {
	return (bool) $GLOBALS['wp_state']['home'];
}
function is_front_page(): bool {
	return (bool) $GLOBALS['wp_state']['front'];
}
function is_paged(): bool {
	return (bool) $GLOBALS['wp_state']['paged'];
}
function is_search(): bool {
	return (bool) $GLOBALS['wp_state']['search'];
}
function is_404(): bool {
	return (bool) $GLOBALS['wp_state']['not_found'];
}
function get_search_query( bool $escaped = true ): string {
	return (string) ( $GLOBALS['wp_state']['search_query'] ?? '' );
}
function is_post_type_archive( $type = '' ): bool {
	return false;
}
function is_tax( $tax = '' ): bool {
	return false;
}
function is_category( $cat = '' ): bool {
	return false;
}
function is_tag( $tag = '' ): bool {
	return false;
}
function is_author( $author = '' ): bool {
	return false;
}
function is_date(): bool {
	return false;
}
function get_queried_object_id(): int {
	return (int) $GLOBALS['wp_state']['queried'];
}
function get_option( string $name, $default = false ) {
	return $GLOBALS['wp_state']['options'][ $name ] ?? $default;
}
function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
	return $GLOBALS['wp_state']['meta'][ $post_id ][ $key ] ?? '';
}
function get_the_title( $post = 0 ): string {
	return (string) ( $GLOBALS['wp_state']['titles'][ (int) $post ] ?? '' );
}
function get_the_excerpt( $post = 0 ): string {
	return (string) ( $GLOBALS['wp_state']['excerpts'][ (int) $post ] ?? '' );
}
function get_permalink( $post = 0 ) {
	return $GLOBALS['wp_state']['permalinks'][ (int) $post ] ?? '';
}
function get_bloginfo( string $show = '' ): string {
	return (string) ( $GLOBALS['wp_state']['bloginfo'][ $show ] ?? '' );
}
function home_url( string $path = '/' ): string {
	// Le domaine décide de l'indexation quand rien n'est déclaré : il doit donc
	// pouvoir varier d'un test à l'autre.
	return ( $GLOBALS['wp_state']['site_url'] ?? 'https://acme.fr' ) . $path;
}
function has_post_thumbnail( $post = null ): bool {
	return isset( $GLOBALS['wp_state']['thumbnails'][ (int) $post ] );
}
function get_post_thumbnail_id( $post = null ) {
	return $GLOBALS['wp_state']['thumbnails'][ (int) $post ] ?? 0;
}
function wp_get_attachment_image_src( int $attachment_id, $size = 'thumbnail' ) {
	return $GLOBALS['wp_state']['attachments'][ $attachment_id ][ is_string( $size ) ? $size : 'full' ] ?? false;
}
function attachment_url_to_postid( string $url ): int {
	return (int) ( $GLOBALS['wp_state']['by_url'][ $url ] ?? 0 );
}
function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
	return strip_tags( $text );
}
function get_post_time( string $format = 'U', bool $gmt = false, $post = null ) {
	return $GLOBALS['wp_state']['times'][ (int) $post ] ?? '';
}
function get_post_modified_time( string $format = 'U', bool $gmt = false, $post = null ) {
	return $GLOBALS['wp_state']['modified'][ (int) $post ] ?? '';
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

/** Le contexte réellement construit par l'extension, pour un état de requête donné. */
function seo_context( array $state ): array {
	wp_state( $state );

	$method = new \ReflectionMethod( Seo::class, 'context' );
	$method->setAccessible( true );

	return $method->invoke( new Seo() );
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

test(
	'un extrait sur plusieurs lignes est ramené sur une seule',
	static fn () => assert_same(
		'Cuisines et dressings, dessinés puis fabriqués.',
		Seo::description( context( [ 'excerpt' => "Cuisines et dressings,\n  dessinés\r\npuis fabriqués." ] ) )
	)
);

test(
	'les espaces insécables de la typographie française sont conservées',
	static fn () => assert_same(
		"Menuiserie\u{00A0}: cuisines et dressings.",
		Seo::description( context( [ 'seo_description' => "Menuiserie\u{00A0}: cuisines et dressings." ] ) )
	)
);

/* --- Balises ------------------------------------------------------------ */

echo "\nBalises de l’en-tête\n";

test(
	'une page indexable reçoit sa canonique, et aucune balise robots concurrente',
	static function (): void {
		/*
		 * La directive passe par le filtre `wp_robots` du noyau, qui n'émet qu'une
		 * balise : en produire une seconde ici les rendrait contradictoires, et
		 * emporterait au passage tout ce que le noyau décide — le `noindex` de
		 * `?replytocom=`, celui d'un commentaire non modéré, le `follow` de la
		 * recherche.
		 */
		$tags = Seo::tags( context() );

		assert_same( null, find_tag( $tags, 'name', 'robots' ), 'balise robots concurrente :' );
		assert_same( 'https://acme.fr/nos-services', find_tag( $tags, 'rel', 'canonical' )['href'] );
	}
);

test(
	'le filtre ajoute l’aperçu d’image sur une page indexable',
	static function (): void {
		$robots = ( new Seo() )->filter_robots( [ 'follow' => true ] );

		assert_same( 'large', $robots['max-image-preview'] );
		assert_same( true, $robots['follow'], 'les directives du noyau doivent survivre :' );
	}
);

test(
	'une page exclue perd sa canonique',
	static function (): void {
		$tags = Seo::tags( context( [ 'noindex' => true ] ) );

		assert_same( null, find_tag( $tags, 'rel', 'canonical' ), 'canonique présente à tort :' );
	}
);

test(
	'une page exclue passe en noindex, et écrase les directives du noyau',
	static function (): void {
		/*
		 * Notre refus prime : le compléter laisserait un « index » du noyau
		 * cohabiter avec notre « noindex » dans la même balise.
		 */
		$robots = seo_context( [ 'singular' => true, 'queried' => 12, 'meta' => [ 12 => [ '_anode_seo_noindex' => '1' ] ] ] );

		assert_true( (bool) $robots['noindex'], 'la page devrait être exclue' );

		$sortie = ( new Seo() )->filter_robots( [ 'index' => true, 'follow' => true ] );

		assert_same( true, $sortie['noindex'] );
		assert_same( true, $sortie['nofollow'] );
		assert_same( null, $sortie['index'] ?? null, 'un « index » du noyau a survécu :' );
	}
);

test(
	'un site non indexable ne déclare pas de canonique',
	static function (): void {
		$tags = Seo::tags( context( [ 'indexable' => false ] ) );

		assert_same( null, find_tag( $tags, 'rel', 'canonical' ), 'canonique présente à tort :' );
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
	'og:image annonce ses dimensions, et seulement si elles sont connues',
	static function (): void {
		$avec = Seo::tags(
			context( [ 'image' => 'https://acme.fr/partage.jpg', 'image_width' => 1200, 'image_height' => 630 ] )
		);

		assert_same( '1200', find_tag( $avec, 'property', 'og:image:width' )['content'] );
		assert_same( '630', find_tag( $avec, 'property', 'og:image:height' )['content'] );

		$sans = Seo::tags( context( [ 'image' => 'https://acme.fr/partage.jpg' ] ) );

		assert_same( null, find_tag( $sans, 'property', 'og:image:width' ), 'dimension annoncée sans être connue :' );
		assert_same( null, find_tag( $sans, 'property', 'og:image:height' ), 'dimension annoncée sans être connue :' );
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
	'une description collée sur plusieurs lignes est normalisée à l’enregistrement',
	static fn () => assert_same(
		'Cuisines, dressings et bibliothèques, dessinés puis fabriqués.',
		Seo::sanitize( 'description', "Cuisines, dressings\net bibliothèques,\r\n\tdessinés puis fabriqués.\n" )
	)
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

/* --- Contexte de la requête ---------------------------------------------- */

echo "\nContexte de la requête\n";

test(
	'le SEO rédigé pour la page des articles est bien lu',
	static function (): void {
		$context = seo_context(
			[
				'home'       => true,
				'options'    => [ 'page_for_posts' => 7 ],
				'titles'     => [ 7 => 'Journal' ],
				'permalinks' => [ 7 => 'https://acme.fr/journal/' ],
				'meta'       => [
					7 => [
						'_anode_seo_title'       => 'Le journal de l’atelier — Acme',
						'_anode_seo_description' => 'Chantiers, essences et coulisses.',
						'_anode_seo_noindex'     => true,
					],
				],
			]
		);

		assert_same( 'Le journal de l’atelier — Acme', Seo::document_title( $context ) );
		assert_same( 'Chantiers, essences et coulisses.', Seo::description( $context ) );
		assert_same( 'https://acme.fr/journal/', $context['url'] );
		assert_true( (bool) $context['noindex'], 'le noindex de la page des articles est ignoré' );
	}
);

test(
	'la page des articles paginée garde son SEO mais perd sa canonique',
	static function (): void {
		$context = seo_context(
			[
				'home'       => true,
				'paged'      => true,
				'options'    => [ 'page_for_posts' => 7 ],
				'titles'     => [ 7 => 'Journal' ],
				'permalinks' => [ 7 => 'https://acme.fr/journal/' ],
				'meta'       => [ 7 => [ '_anode_seo_title' => 'Le journal de l’atelier — Acme' ] ],
			]
		);

		assert_same( 'Le journal de l’atelier — Acme', Seo::document_title( $context ) );
		assert_same( '', $context['url'], 'canonique vers la page 1 :' );
	}
);

test(
	'un accueil qui affiche les articles n’emprunte pas le SEO d’une autre page',
	static function (): void {
		$context = seo_context(
			[
				'home'    => true,
				'front'   => true,
				'options' => [ 'page_for_posts' => 7 ],
				'meta'    => [ 7 => [ '_anode_seo_title' => 'Le journal de l’atelier — Acme' ] ],
			]
		);

		assert_same( '', $context['seo_title'] );
	}
);

test(
	'la recherche est laissée au noyau, qui lui pose « noindex, follow »',
	static function (): void {
		/*
		 * Forcer le `noindex` ici y ajoutait un `nofollow` que personne n'avait
		 * décidé : le noyau pose `wp_robots_no_robots`, c'est-à-dire
		 * « noindex, follow » quand le site est public. Notre contexte doit donc
		 * rester muet sur la recherche.
		 */
		$context = seo_context( [ 'search' => true, 'search_query' => 'dressing' ] );

		assert_same( false, (bool) $context['noindex'], 'la recherche ne doit plus être forcée ici' );
	}
);

test(
	'les dimensions de l’image de partage viennent de la pièce jointe',
	static function (): void {
		$context = seo_context(
			[
				'singular'    => true,
				'queried'     => 12,
				'titles'      => [ 12 => 'Nos services' ],
				'permalinks'  => [ 12 => 'https://acme.fr/nos-services/' ],
				'meta'        => [ 12 => [ '_anode_seo_image' => '31' ] ],
				'attachments' => [ 31 => [ 'large' => [ 'https://acme.fr/uploads/atelier-1024x683.jpg', 1024, 683 ] ] ],
			]
		);

		assert_same( 'https://acme.fr/uploads/atelier-1024x683.jpg', $context['image'] );
		assert_same( 1024, $context['image_width'] );
		assert_same( 683, $context['image_height'] );
	}
);

test(
	'l’image de partage du site retrouve ses dimensions par son URL',
	static function (): void {
		$context = seo_context(
			[
				'options'     => [ 'anode_seo_social_image' => 'https://acme.fr/uploads/partage.jpg' ],
				'by_url'      => [ 'https://acme.fr/uploads/partage.jpg' => 31 ],
				'attachments' => [ 31 => [ 'full' => [ 'https://acme.fr/uploads/partage.jpg', 1200, 630 ] ] ],
			]
		);

		assert_same( 1200, $context['image_width'] );
		assert_same( 630, $context['image_height'] );
	}
);

test(
	'une image de partage introuvable n’annonce aucune dimension',
	static function (): void {
		$context = seo_context( [ 'options' => [ 'anode_seo_social_image' => 'https://acme.fr/uploads/partage-800x600.jpg' ] ] );

		assert_same( 'https://acme.fr/uploads/partage-800x600.jpg', $context['image'] );
		assert_same( 0, $context['image_width'] );
		assert_same( 0, $context['image_height'] );
	}
);

/* --- Indexation ----------------------------------------------------------- */

echo "\nIndexation\n";

test(
	'sans déclaration, un domaine de développement n’est pas indexable',
	static function (): void {
		/*
		 * Le domaine sert de repli quand rien n'est déclaré. `.local` est celui de
		 * Local, la machine de développement du projet : un site qui y tourne ne
		 * doit jamais remonter dans Google.
		 */
		putenv( 'WP_ENVIRONMENT_TYPE' );

		assert_same(
			false,
			seo_context( [ 'site_url' => 'http://exemple.local' ] )['indexable'],
			'un domaine .local serait indexable :'
		);
	}
);

test(
	'sans déclaration, un domaine public reste indexable',
	static function (): void {
		/*
		 * L'inverse compte autant, et c'est la régression qu'on répare : exiger une
		 * déclaration que personne n'écrit faisait passer **toute production en
		 * ligne** en noindex, par une mise à jour mineure installée sans décision.
		 */
		putenv( 'WP_ENVIRONMENT_TYPE' );

		assert_same(
			true,
			seo_context( [ 'site_url' => 'https://niort-tech.fr' ] )['indexable'],
			'une production serait dé-indexée :'
		);
	}
);

test(
	'un sous-domaine de recette n’est pas indexable',
	static function (): void {
		putenv( 'WP_ENVIRONMENT_TYPE' );

		foreach ( [ 'staging.exemple.fr', 'preprod.exemple.fr', 'dev.exemple.fr' ] as $hote ) {
			assert_same(
				false,
				seo_context( [ 'site_url' => "https://{$hote}" ] )['indexable'],
				"{$hote} serait indexable :"
			);
		}
	}
);

test(
	'un nom qui commence par les mêmes lettres n’est pas pris pour une recette',
	static function (): void {
		// « dev.exemple.fr » est une recette ; « developpement-durable.fr » non.
		putenv( 'WP_ENVIRONMENT_TYPE' );

		assert_same( true, seo_context( [ 'site_url' => 'https://developpement-durable.fr' ] )['indexable'] );
	}
);

test(
	'une production déclarée l’emporte sur un domaine trompeur',
	static function (): void {
		putenv( 'WP_ENVIRONMENT_TYPE=production' );

		assert_same( true, seo_context( [ 'site_url' => 'https://demo.exemple.fr' ] )['indexable'] );

		putenv( 'WP_ENVIRONMENT_TYPE' );
	}
);

test(
	'une préproduction déclarée n’est pas indexable',
	static function (): void {
		putenv( 'WP_ENVIRONMENT_TYPE=staging' );

		assert_same( false, seo_context( [] )['indexable'] );
	}
);

test(
	'une production déclarée est indexable',
	static function (): void {
		putenv( 'WP_ENVIRONMENT_TYPE=production' );

		assert_same( true, seo_context( [] )['indexable'] );
	}
);

test(
	'l’option de visibilité du site l’emporte sur l’environnement',
	static function (): void {
		putenv( 'WP_ENVIRONMENT_TYPE=production' );

		assert_same( false, seo_context( [ 'options' => [ 'blog_public' => 0 ] ] )['indexable'] );
		assert_same( false, seo_context( [ 'options' => [ 'anode_seo_indexable' => '0' ] ] )['indexable'] );
	}
);

test(
	'un domaine d’hébergement de recette est reconnu sans rien déclarer',
	static function (): void {
		/*
		 * Mesuré sur une préproduction du parc : `client.anode-staging.fr` était
		 * traitée en production — l'étiquette n'est pas en tête du nom mais au
		 * milieu, et la liste ne testait que la tête. Le `noindex` ne tenait plus
		 * qu'à `blog_public`, une case décochable depuis les réglages.
		 */
		putenv( 'WP_ENVIRONMENT_TYPE' );

		foreach (
			[
				'https://client.anode-staging.fr',
				'https://boutique.hebergeur-staging.com',
				'https://site.client-preprod.fr',
				'https://staging.exemple.fr',
				'https://exemple.local',
			] as $url
		) {
			assert_same( false, seo_context( [ 'site_url' => $url ] )['indexable'], $url );
		}
	}
);

test(
	'un domaine public dont le nom contient une étiquette reste indexable',
	static function (): void {
		/*
		 * Le tort à éviter est l'inverse : dé-indexer un site en ligne. D'où le
		 * suffixe seul, et l'exclusion de « dev », « test » et « demo » — des
		 * mots que des sites publics portent dans leur nom.
		 */
		putenv( 'WP_ENVIRONMENT_TYPE' );

		foreach (
			[
				'https://www.web-dev.fr',
				'https://demo-cuisine.fr',
				'https://staging-conseil.fr',
				'https://developpement-durable.fr',
				'https://recettes-de-julie.fr',
			] as $url
		) {
			assert_same( true, seo_context( [ 'site_url' => $url ] )['indexable'], $url );
		}
	}
);

test(
	'la constante l’emporte sur la variable d’environnement',
	static function (): void {
		putenv( 'WP_ENVIRONMENT_TYPE=production' );
		define( 'WP_ENVIRONMENT_TYPE', 'staging' );

		assert_same( false, Seo::production_declared(), 'la constante du site est ignorée :' );
	}
);

/* --- Prise en main de l’en-tête ------------------------------------------ */

echo "\nPrise en main de l’en-tête\n";

test(
	'rien n’est posé sur wp_head avant le chargement des extensions',
	static function (): void {
		assert_same( null, $GLOBALS['anode_seo_actions']['wp_head'] ?? null, 'décision prise trop tôt :' );
		assert_true(
			isset( $GLOBALS['anode_seo_actions']['plugins_loaded'] ),
			'l’affichage n’est pas reporté à plugins_loaded : une extension SEO tierce ne serait jamais vue'
		);
	}
);

test(
	'la canonique du noyau est désarmée, mais pas sa balise robots',
	static function (): void {
		/*
		 * Deux traitements opposés, et c'est voulu.
		 *
		 * La **canonique** se remplace : la nôtre porte l'URL saisie, celle du
		 * noyau ne la connaît pas — deux balises se contrediraient.
		 *
		 * La **directive robots** se complète, par le filtre `wp_robots`. La
		 * retirer emportait tout ce que le noyau décide et que personne ne
		 * réimplémente : le `noindex` de `?replytocom=`, celui d'un commentaire non
		 * modéré — qui expose du contenu non validé —, le `follow` de la recherche,
		 * et le point d'extension pour qui veut s'y accrocher.
		 */
		foreach ( $GLOBALS['anode_seo_actions']['plugins_loaded'] as $callback ) {
			$callback();
		}

		assert_true( isset( $GLOBALS['anode_seo_actions']['wp_head'] ), 'l’en-tête n’est pas pris en main' );
		assert_true(
			in_array( 'rel_canonical', $GLOBALS['anode_seo_removed']['wp_head'] ?? [], true ),
			'deux canoniques sortiraient'
		);
		assert_true(
			! in_array( 'wp_robots', $GLOBALS['anode_seo_removed']['wp_head'] ?? [], true ),
			'la balise du noyau est désarmée : ses propres règles disparaissent'
		);
		assert_true(
			isset( $GLOBALS['anode_seo_filters']['wp_robots'] ),
			'aucune directive n’est ajoutée au filtre du noyau'
		);
	}
);

test(
	'une extension SEO tierce reprend la main',
	static function (): void {
		define( 'WPSEO_VERSION', '24.0' );

		$avant = count( $GLOBALS['anode_seo_actions']['wp_head'] );

		( new Seo() )->register_output();

		assert_same( $avant, count( $GLOBALS['anode_seo_actions']['wp_head'] ), 'des balises sont posées malgré Yoast :' );
	}
);

echo "\n{$passed} test(s) réussi(s), {$failed} échec(s).\n";
exit( $failed ? 1 : 0 );
