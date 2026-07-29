<?php
/**
 * Plugin Name: Anode — SEO
 * Description: Titres, méta descriptions, URL canoniques, robots et cartes sociales, pilotables par l'API REST. Remplace Yoast pour un site vitrine.
 * Version:     1.0.0
 * Author:      Anode
 *
 * Pourquoi une extension maison plutôt que Yoast : un site construit ici n'a
 * besoin que de six champs par page et d'une poignée de balises dans l'en-tête.
 * Yoast en apporte cent fois plus, charge ses propres scripts, et pose ses
 * champs dans un format que le MCP ne sait pas écrire. Ici, les champs sont
 * des métadonnées WordPress déclarées à l'API REST : Claude Code les remplit
 * comme n'importe quel autre champ, et rien n'est chargé côté visiteur.
 *
 * L'extension s'efface d'elle-même si une extension SEO tierce est active :
 * deux balises « description » valent moins qu'une.
 *
 * @package Anode\Seo
 */

declare( strict_types = 1 );

namespace Anode\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Seo {

	public const VERSION = '1.0.0';

	/** Préfixe commun aux métadonnées, protégées par le tiret bas initial. */
	public const PREFIX = '_anode_seo_';

	/**
	 * Champs SEO d'un contenu.
	 *
	 * @var array<string, string> suffixe => type REST
	 */
	public const FIELDS = [
		'title'       => 'string',
		'description' => 'string',
		'canonical'   => 'string',
		'noindex'     => 'boolean',
		'image'       => 'integer',
	];

	/**
	 * Réglages de site, avec leur valeur par défaut.
	 *
	 * @var array<string, string>
	 */
	public const OPTIONS = [
		'anode_seo_title_template'      => '%page% · %site%',
		'anode_seo_default_description' => '',
		'anode_seo_social_image'        => '',
		'anode_seo_locale'              => 'fr_FR',
		'anode_seo_indexable'           => '1',
	];

	public static function boot(): void {
		static $booted = false;

		if ( $booted ) {
			return;
		}

		$booted = true;

		$seo = new self();

		// Les champs sont enregistrés même si une extension tierce prend la
		// main sur l'affichage : le contenu déjà saisi ne doit pas disparaître.
		add_action( 'init', [ $seo, 'register_meta' ], 6 );

		if ( self::third_party_active() ) {
			return;
		}

		add_filter( 'pre_get_document_title', [ $seo, 'filter_document_title' ], 20 );
		add_action( 'wp_head', [ $seo, 'print_head' ], 1 );

		// WordPress pose sa propre canonique : la nôtre la remplace.
		remove_action( 'wp_head', 'rel_canonical' );
	}

	/* ------------------------------------------------------------------ */
	/* Enregistrement                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Déclare les champs sur tous les types de contenu publics.
	 *
	 * `show_in_rest` est ce qui rend le SEO pilotable sans SSH : sans lui, les
	 * champs n'existent que dans l'administration.
	 */
	public function register_meta(): void {
		$post_types = get_post_types( [ 'public' => true ], 'names' );

		foreach ( $post_types as $post_type ) {
			foreach ( self::FIELDS as $field => $type ) {
				register_post_meta(
					$post_type,
					self::PREFIX . $field,
					[
						'type'              => $type,
						'single'            => true,
						'description'       => self::label( $field ),
						'show_in_rest'      => true,
						'sanitize_callback' => static fn ( $value ) => self::sanitize( $field, $value ),
						'auth_callback'     => static fn ( bool $allowed, string $meta_key, int $post_id ): bool
							=> current_user_can( 'edit_post', $post_id ),
					]
				);
			}
		}
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	public static function sanitize( string $field, $value ) {
		switch ( $field ) {
			case 'description':
				return sanitize_textarea_field( (string) $value );

			case 'canonical':
				return esc_url_raw( (string) $value );

			case 'noindex':
				return in_array( $value, [ true, 1, '1', 'true' ], true );

			case 'image':
				return max( 0, (int) $value );

			default:
				return sanitize_text_field( (string) $value );
		}
	}

	public static function label( string $field ): string {
		return [
			'title'       => 'Titre affiché dans les résultats de recherche',
			'description' => 'Méta description',
			'canonical'   => 'URL canonique',
			'noindex'     => 'Exclure des moteurs de recherche',
			'image'       => 'Image de partage (identifiant du média)',
		][ $field ] ?? $field;
	}

	/* ------------------------------------------------------------------ */
	/* Affichage                                                           */
	/* ------------------------------------------------------------------ */

	public function filter_document_title( $title ): string {
		return self::document_title( $this->context() );
	}

	public function print_head(): void {
		echo self::render( self::tags( $this->context() ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- render() échappe chaque valeur.
	}

	/* ------------------------------------------------------------------ */
	/* Cœur — sans WordPress, donc testable                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Titre du document.
	 *
	 * Le champ saisi l'emporte ; sinon le gabarit du site est appliqué au
	 * titre de la page. Sur la page d'accueil, le gabarit ferait doublon
	 * (« Accueil · Acme ») : on s'en tient au nom du site et à son slogan.
	 *
	 * @param array<string, mixed> $context
	 */
	public static function document_title( array $context ): string {
		$custom = trim( (string) ( $context['seo_title'] ?? '' ) );

		if ( '' !== $custom ) {
			return $custom;
		}

		$site = (string) ( $context['site_name'] ?? '' );

		if ( ! empty( $context['is_front'] ) ) {
			$tagline = trim( (string) ( $context['tagline'] ?? '' ) );

			return '' === $tagline ? $site : "{$site} · {$tagline}";
		}

		$template = (string) ( $context['title_template'] ?? '%page% · %site%' );
		$page     = trim( (string) ( $context['title'] ?? '' ) );

		if ( '' === $page ) {
			return $site;
		}

		return trim( str_replace( [ '%page%', '%site%' ], [ $page, $site ], $template ) );
	}

	/**
	 * Balises à poser dans l'en-tête.
	 *
	 * Renvoie une liste de descriptions de balises plutôt que du HTML : c'est
	 * ce qui permet de les vérifier une par une dans les tests.
	 *
	 * @param array<string, mixed> $context
	 * @return array<int, array<string, string>>
	 */
	public static function tags( array $context ): array {
		$tags        = [];
		$title       = self::document_title( $context );
		$description = self::description( $context );
		$image       = trim( (string) ( $context['image'] ?? $context['default_image'] ?? '' ) );
		$url         = trim( (string) ( $context['url'] ?? '' ) );
		$canonical   = trim( (string) ( $context['canonical'] ?? '' ) ) ?: $url;

		if ( '' !== $description ) {
			$tags[] = [ 'tag' => 'meta', 'name' => 'description', 'content' => $description ];
		}

		// Un site hors production ou une page exclue ne doivent jamais être
		// indexés : la directive prime sur tout le reste.
		if ( ! empty( $context['noindex'] ) || empty( $context['indexable'] ) ) {
			$tags[] = [ 'tag' => 'meta', 'name' => 'robots', 'content' => 'noindex, nofollow' ];
		} else {
			$tags[] = [ 'tag' => 'meta', 'name' => 'robots', 'content' => 'index, follow, max-image-preview:large' ];

			if ( '' !== $canonical ) {
				$tags[] = [ 'tag' => 'link', 'rel' => 'canonical', 'href' => $canonical ];
			}
		}

		$tags[] = [ 'tag' => 'meta', 'property' => 'og:type', 'content' => (string) ( $context['type'] ?? 'website' ) ];
		$tags[] = [ 'tag' => 'meta', 'property' => 'og:title', 'content' => $title ];
		$tags[] = [ 'tag' => 'meta', 'property' => 'og:site_name', 'content' => (string) ( $context['site_name'] ?? '' ) ];
		$tags[] = [ 'tag' => 'meta', 'property' => 'og:locale', 'content' => (string) ( $context['locale'] ?? 'fr_FR' ) ];

		if ( '' !== $canonical ) {
			$tags[] = [ 'tag' => 'meta', 'property' => 'og:url', 'content' => $canonical ];
		}

		if ( '' !== $description ) {
			$tags[] = [ 'tag' => 'meta', 'property' => 'og:description', 'content' => $description ];
		}

		if ( '' !== $image ) {
			$tags[] = [ 'tag' => 'meta', 'property' => 'og:image', 'content' => $image ];
		}

		// Twitter lit les balises Open Graph : seule la nature de la carte
		// manque, et elle dépend de la présence d'une image.
		$tags[] = [
			'tag'     => 'meta',
			'name'    => 'twitter:card',
			'content' => '' !== $image ? 'summary_large_image' : 'summary',
		];

		if ( ! empty( $context['published'] ) && 'article' === ( $context['type'] ?? '' ) ) {
			$tags[] = [ 'tag' => 'meta', 'property' => 'article:published_time', 'content' => (string) $context['published'] ];

			if ( ! empty( $context['modified'] ) ) {
				$tags[] = [ 'tag' => 'meta', 'property' => 'article:modified_time', 'content' => (string) $context['modified'] ];
			}
		}

		return $tags;
	}

	/**
	 * Description retenue : celle saisie, sinon l'extrait, sinon celle du site.
	 *
	 * @param array<string, mixed> $context
	 */
	public static function description( array $context ): string {
		foreach ( [ 'seo_description', 'excerpt', 'default_description' ] as $key ) {
			$value = trim( (string) ( $context[ $key ] ?? '' ) );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * @param array<int, array<string, string>> $tags
	 */
	public static function render( array $tags ): string {
		$lines = [ '', '<!-- Anode SEO -->' ];

		foreach ( $tags as $tag ) {
			$name       = $tag['tag'];
			$attributes = [];

			foreach ( $tag as $key => $value ) {
				if ( 'tag' === $key ) {
					continue;
				}

				$attributes[] = sprintf(
					'%s="%s"',
					$key,
					in_array( $key, [ 'href', 'content' ], true ) && self::looks_like_url( $value )
						? esc_url( $value )
						: esc_attr( $value )
				);
			}

			$lines[] = sprintf( '<%s %s />', $name, implode( ' ', $attributes ) );
		}

		$lines[] = '';

		return implode( "\n", $lines );
	}

	private static function looks_like_url( string $value ): bool {
		return (bool) preg_match( '#^https?://#', $value );
	}

	/* ------------------------------------------------------------------ */
	/* Contexte — la seule partie qui parle à WordPress                    */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array<string, mixed>
	 */
	private function context(): array {
		$post_id  = is_singular() ? (int) get_queried_object_id() : 0;
		$image_id = $post_id ? (int) get_post_meta( $post_id, self::PREFIX . 'image', true ) : 0;

		if ( ! $image_id && $post_id && has_post_thumbnail( $post_id ) ) {
			$image_id = (int) get_post_thumbnail_id( $post_id );
		}

		$image = $image_id ? (string) wp_get_attachment_image_url( $image_id, 'large' ) : '';

		if ( '' === $image ) {
			$image = (string) get_option( 'anode_seo_social_image', '' );
		}

		return [
			'is_front'            => is_front_page(),
			'title'               => $post_id ? get_the_title( $post_id ) : $this->queried_title(),
			'site_name'           => get_bloginfo( 'name' ),
			'tagline'             => get_bloginfo( 'description' ),
			'title_template'      => (string) get_option( 'anode_seo_title_template', self::OPTIONS['anode_seo_title_template'] ),
			'seo_title'           => $post_id ? (string) get_post_meta( $post_id, self::PREFIX . 'title', true ) : '',
			'seo_description'     => $post_id ? (string) get_post_meta( $post_id, self::PREFIX . 'description', true ) : '',
			'default_description' => (string) get_option( 'anode_seo_default_description', '' ),
			'excerpt'             => $post_id ? trim( wp_strip_all_tags( (string) get_the_excerpt( $post_id ) ) ) : '',
			'canonical'           => $post_id ? (string) get_post_meta( $post_id, self::PREFIX . 'canonical', true ) : '',
			// Hors contenu singulier, WordPress lui-même ne pose pas de
			// canonique : une archive paginée ou filtrée n'a pas d'URL de
			// référence évidente, et en inventer une nuit plus qu'elle n'aide.
			'url'                 => $post_id ? (string) get_permalink( $post_id ) : ( is_front_page() ? home_url( '/' ) : '' ),
			'noindex'             => $post_id && get_post_meta( $post_id, self::PREFIX . 'noindex', true ),
			// Un site de préproduction ne doit jamais remonter dans Google.
			'indexable'           => '1' === (string) get_option( 'anode_seo_indexable', '1' )
				&& (bool) get_option( 'blog_public', 1 )
				&& 'production' === wp_get_environment_type(),
			'locale'              => (string) get_option( 'anode_seo_locale', self::OPTIONS['anode_seo_locale'] ),
			'image'               => $image,
			'type'                => is_singular( 'post' ) ? 'article' : 'website',
			'published'           => $post_id ? get_post_time( 'c', true, $post_id ) : '',
			'modified'            => $post_id ? get_post_modified_time( 'c', true, $post_id ) : '',
		];
	}

	/**
	 * Titre des pages qui ne sont pas un contenu singulier.
	 *
	 * On le reconstruit plutôt que d'appeler wp_get_document_title() : cette
	 * fonction déclenche le filtre « pre_get_document_title », c'est-à-dire
	 * nous — la boucle serait infinie.
	 */
	private function queried_title(): string {
		if ( is_home() ) {
			$blog = (int) get_option( 'page_for_posts' );

			return $blog ? get_the_title( $blog ) : 'Articles';
		}

		if ( is_search() ) {
			return sprintf( 'Recherche : %s', get_search_query() );
		}

		if ( is_404() ) {
			return 'Page introuvable';
		}

		if ( is_post_type_archive() ) {
			return post_type_archive_title( '', false );
		}

		if ( is_tax() || is_category() || is_tag() ) {
			return single_term_title( '', false );
		}

		if ( is_author() ) {
			return (string) get_the_author();
		}

		if ( is_date() ) {
			return wp_strip_all_tags( get_the_archive_title() );
		}

		return '';
	}

	/**
	 * Une extension SEO tierce est-elle aux commandes ?
	 */
	public static function third_party_active(): bool {
		foreach ( [ 'WPSEO_VERSION', 'RANK_MATH_VERSION', 'SEOPRESS_VERSION', 'AIOSEO_VERSION' ] as $constant ) {
			if ( defined( $constant ) ) {
				return true;
			}
		}

		return false;
	}
}

Seo::boot();
