<?php
/**
 * Plugin Name: Anode — SEO
 * Description: Titres, méta descriptions, URL canoniques, robots et cartes sociales, pilotables par l'API REST. Remplace Yoast pour un site vitrine.
 * Version:     1.2.0
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

	public const VERSION = '1.2.0';

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

		/*
		 * L'affichage ne se décide pas ici. Ce fichier est un mu-plugin : il est
		 * chargé avant les extensions ordinaires, donc avant qu'une seule
		 * constante de Yoast ou de Rank Math n'existe. third_party_active()
		 * répondait toujours « non », et l'effacement promis n'avait jamais
		 * lieu — les deux jeux de balises sortaient ensemble.
		 */
		add_action( 'plugins_loaded', [ $seo, 'register_output' ], 20 );
	}

	/**
	 * Prend la main sur l'en-tête, sauf si une extension SEO tierce est active.
	 */
	public function register_output(): void {
		if ( self::third_party_active() ) {
			return;
		}

		add_filter( 'pre_get_document_title', [ $this, 'filter_document_title' ], 20 );
		add_action( 'wp_head', [ $this, 'print_head' ], 1 );

		// WordPress pose sa propre canonique : la nôtre la remplace.
		remove_action( 'wp_head', 'rel_canonical' );

		/*
		 * La directive « robots » passe par le **filtre du noyau**, pas par une
		 * balise concurrente.
		 *
		 * Émettre la nôtre en retirant celle du noyau réglait bien le doublon —
		 * deux balises par page, parfois contradictoires — mais emportait tout ce
		 * que le noyau décide au passage, et que personne ne réimplémente :
		 *
		 * - `?replytocom=` et l'aperçu d'un commentaire non modéré perdaient leur
		 *   `noindex`, et le second **expose du contenu non modéré** ;
		 * - les résultats de recherche passaient de `noindex, follow` à
		 *   `noindex, nofollow`, un durcissement que personne n'avait décidé ;
		 * - le filtre `wp_robots` devenait inerte côté public : un thème enfant ou
		 *   un mu-plugin qui s'y accroche était ignoré sans trace.
		 *
		 * En passant par le filtre, une seule balise sort — celle du noyau — nos
		 * directives s'y ajoutent, et le point d'extension reste vivant.
		 */
		add_filter( 'wp_robots', [ $this, 'filter_robots' ] );
	}

	/**
	 * Ajoute nos directives à celles du noyau.
	 *
	 * Un `noindex` de notre fait — page exclue, site hors production — **prime
	 * sur tout le reste** : on remplace alors les directives au lieu de les
	 * compléter, sans quoi un `index` déjà posé cohabiterait avec notre `noindex`
	 * dans la même balise.
	 *
	 * @param array<string, mixed> $robots Directives accumulées.
	 * @return array<string, mixed>
	 */
	public function filter_robots( array $robots ): array {
		$context = $this->context();

		if ( ! empty( $context['noindex'] ) || empty( $context['indexable'] ) ) {
			return [
				'noindex'  => true,
				'nofollow' => true,
			];
		}

		$robots['max-image-preview'] = 'large';

		return $robots;
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
				// sanitize_textarea_field() garde les retours à la ligne : une
				// description collée depuis un traitement de texte arrivait
				// telle quelle dans l'attribut « content », sur plusieurs
				// lignes. Les moteurs la recomposent alors comme ils veulent,
				// et l'en-tête devient illisible à la relecture.
				return self::single_line( sanitize_textarea_field( (string) $value ) );

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

		/*
		 * La directive « robots » ne sort plus d'ici : elle passe par le filtre
		 * `wp_robots` du noyau (voir `filter_robots`), qui n'émet qu'une balise.
		 * Reste la canonique, qu'une page non indexable n'a pas à déclarer — elle
		 * n'a pas d'URL de référence à faire valoir.
		 */
		if ( empty( $context['noindex'] ) && ! empty( $context['indexable'] ) && '' !== $canonical ) {
			$tags[] = [ 'tag' => 'link', 'rel' => 'canonical', 'href' => $canonical ];
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

			// Plusieurs messageries — WhatsApp, Signal, Slack — réservent la
			// place de l'aperçu avant de l'avoir téléchargé : sans dimensions
			// annoncées, elles rendent une carte tronquée, ou pas de carte du
			// tout. On ne les annonce que si on les connaît : des dimensions
			// fausses valent moins que pas de dimensions.
			$width  = (int) ( $context['image_width'] ?? 0 );
			$height = (int) ( $context['image_height'] ?? 0 );

			if ( $width > 0 && $height > 0 ) {
				$tags[] = [ 'tag' => 'meta', 'property' => 'og:image:width', 'content' => (string) $width ];
				$tags[] = [ 'tag' => 'meta', 'property' => 'og:image:height', 'content' => (string) $height ];
			}
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
			$value = self::single_line( (string) ( $context[ $key ] ?? '' ) );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Ramène un texte sur une seule ligne.
	 *
	 * Un attribut « content » n'a pas de ligne : un retour à la ligne y est un
	 * blanc de plus, que chaque moteur interprète à sa façon. Le cas arrive par
	 * deux chemins — une description collée depuis un traitement de texte, et
	 * l'extrait d'une page, qui garde les sauts de ligne du contenu.
	 *
	 * Les espaces insécables sont conservées : `\s` ne les couvre pas sans
	 * l'option UCP, et la typographie française en dépend (« mot : »).
	 */
	private static function single_line( string $value ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $value ) );
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
		$post_id = is_singular() ? (int) get_queried_object_id() : 0;

		/*
		 * La page désignée comme « page des articles » n'est pas un contenu
		 * singulier : WordPress la rend par is_home(). Sans ce cas, le SEO
		 * rédigé pour elle — titre, description, image, noindex — était en base
		 * et n'était lu par personne : l'écriture réussissait, l'affichage
		 * retombait sur les valeurs du site, et rien ne le signalait.
		 */
		$posts_page = ( ! $post_id && is_home() && ! is_front_page() ) ? (int) get_option( 'page_for_posts' ) : 0;
		$post_id    = $post_id ?: $posts_page;

		$image_id = $post_id ? (int) get_post_meta( $post_id, self::PREFIX . 'image', true ) : 0;

		if ( ! $image_id && $post_id && has_post_thumbnail( $post_id ) ) {
			$image_id = (int) get_post_thumbnail_id( $post_id );
		}

		$image  = '';
		$width  = 0;
		$height = 0;

		/*
		 * wp_get_attachment_image_src() plutôt que wp_get_attachment_image_url()
		 * : même URL, mais les dimensions viennent avec, et og:image en a besoin
		 * (voir tags()). Les deux valeurs décrivent alors la même déclinaison —
		 * annoncer la taille du fichier d'origine pour une image en « large »
		 * serait pire que de ne rien annoncer.
		 */
		$source = $image_id ? wp_get_attachment_image_src( $image_id, 'large' ) : false;

		if ( is_array( $source ) && '' !== (string) ( $source[0] ?? '' ) ) {
			$image  = (string) $source[0];
			$width  = (int) ( $source[1] ?? 0 );
			$height = (int) ( $source[2] ?? 0 );
		}

		if ( '' === $image ) {
			$image = (string) get_option( 'anode_seo_social_image', '' );

			/*
			 * L'image de partage du site est enregistrée sous forme d'URL : on
			 * remonte à la pièce jointe pour en connaître les dimensions. Si
			 * l'URL est celle d'une déclinaison redimensionnée, la recherche
			 * échoue — et on n'annonce alors aucune dimension, plutôt que
			 * celles d'un autre fichier.
			 */
			$default = '' === $image ? 0 : (int) attachment_url_to_postid( $image );
			$source  = $default ? wp_get_attachment_image_src( $default, 'full' ) : false;

			if ( is_array( $source ) ) {
				$width  = (int) ( $source[1] ?? 0 );
				$height = (int) ( $source[2] ?? 0 );
			}
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
			// La page des articles paginée relève du même cas : une canonique
			// vers /blog/ dirait à Google que /blog/page/2/ n'en est qu'un
			// double, et son contenu disparaîtrait de l'index.
			/*
			 * Le `! is_paged()` ne vaut que pour la **page des articles**.
			 *
			 * Posé sur toute l'expression, il faisait perdre sa canonique à
			 * **chaque contenu singulier** dès la seconde page — et `rel_canonical`
			 * du noyau étant retiré, plus personne ne la posait. Le site gagnait
			 * ainsi une famille d'URL indexables sans signal de consolidation.
			 *
			 * Sur la page des articles, en revanche, une canonique vers `/blog/`
			 * dirait à Google que `/blog/page/2/` n'en est qu'un double, et son
			 * contenu disparaîtrait de l'index. C'est le seul cas visé.
			 */
			'url'                 => $post_id && ! ( $posts_page && is_paged() )
				? (string) get_permalink( $post_id )
				: ( is_front_page() ? home_url( '/' ) : '' ),
			// Le noyau posait le « noindex » des pages de résultats de
			// recherche ; sa balise étant désormais retirée de wp_head (voir
			// register_output), c'est à nous de le poser — sans quoi elles
			// deviendraient indexables du jour où l'on a corrigé le doublon.
			// La recherche n'est plus notre affaire : le noyau lui pose
			// « noindex, follow » par `wp_robots_no_robots`, et le filtre nous
			// laisse désormais cette règle intacte. La forcer ici y ajouterait un
			// `nofollow` que personne n'a demandé.
			'noindex'             => (bool) ( $post_id && get_post_meta( $post_id, self::PREFIX . 'noindex', true ) ),
			// Un site de préproduction ne doit jamais remonter dans Google.
			'indexable'           => '1' === (string) get_option( 'anode_seo_indexable', '1' )
				&& (bool) get_option( 'blog_public', 1 )
				&& self::production_declared(),
			'locale'              => (string) get_option( 'anode_seo_locale', self::OPTIONS['anode_seo_locale'] ),
			'image'               => $image,
			'image_width'         => $width,
			'image_height'        => $height,
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
	 * Ce site est-il une production ?
	 *
	 * Deux oublis symétriques menacent, et une seule règle doit tenir les deux :
	 *
	 * - une **préproduction** qui remonte dans Google parce que personne n'a
	 *   pensé à la déclarer ;
	 * - une **production en ligne** qui se dé-indexe parce que personne n'a pensé
	 *   à la déclarer non plus.
	 *
	 * Exiger la déclaration ne règle que le premier, et crée le second — en pire :
	 * il part tout seul avec une mise à jour mineure, sur des sites qui
	 * fonctionnaient. Aucun outil du dépôt n'écrit cette constante aujourd'hui.
	 *
	 * La règle retenue reconnaît donc une **non-production**, au lieu d'exiger une
	 * production :
	 *
	 * 1. une déclaration explicite fait foi, dans les deux sens — c'est le seul
	 *    moyen sûr, et l'ordre de résolution est celui du noyau, la constante
	 *    avant la variable d'environnement ;
	 * 2. sans déclaration, un domaine reconnaissable de développement ou de
	 *    recette n'est pas une production ;
	 * 3. sinon, production — comme WordPress lui-même en décide.
	 *
	 * L'erreur devient alors « un site de recette sur un domaine qu'on n'a pas su
	 * reconnaître », qui se corrige en déclarant la constante. Pas « tous les
	 * sites livrés disparaissent de Google ».
	 */
	public static function production_declared(): bool {
		$constant = defined( 'WP_ENVIRONMENT_TYPE' ) && is_scalar( WP_ENVIRONMENT_TYPE )
			? (string) WP_ENVIRONMENT_TYPE
			: '';

		$declared = '' !== $constant
			? $constant
			: (string) ( function_exists( 'getenv' ) ? getenv( 'WP_ENVIRONMENT_TYPE' ) : '' );

		$declared = strtolower( trim( $declared ) );

		if ( '' !== $declared ) {
			return 'production' === $declared;
		}

		return ! self::host_looks_transient();
	}

	/**
	 * Le domaine trahit-il un environnement qui n'est pas destiné au public ?
	 *
	 * Volontairement conservateur : chaque motif désigne un domaine qu'on ne
	 * publie pas. Un faux positif dé-indexe un site en ligne — c'est le tort
	 * qu'on cherche à éviter —, un faux négatif laisse une recette indexable, ce
	 * que `anode_seo_indexable` et `blog_public` rattrapent tous deux.
	 *
	 * `WP_ENVIRONMENT_TYPE` prime sur cette liste : un site servi depuis un
	 * domaine d'aperçu et réellement destiné au public se déclare.
	 */
	private static function host_looks_transient(): bool {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( '' === $host ) {
			return false;
		}

		// Machines de développement : Local, DDEV, MAMP, et la boucle locale.
		foreach ( [ '.local', '.test', '.localhost', '.invalid', '.example', '.ddev.site' ] as $suffixe ) {
			if ( str_ends_with( $host, $suffixe ) ) {
				return true;
			}
		}

		if ( in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true ) ) {
			return true;
		}

		// Sous-domaines de recette, en tête de nom seulement : « dev.exemple.fr »
		// est une recette, « developpement-durable.fr » n'en est pas une.
		foreach ( [ 'staging', 'preprod', 'preproduction', 'recette', 'dev', 'test', 'demo', 'sandbox' ] as $etiquette ) {
			if ( str_starts_with( $host, $etiquette . '.' ) ) {
				return true;
			}
		}

		return false;
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
