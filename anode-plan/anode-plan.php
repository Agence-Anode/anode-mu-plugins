<?php
/**
 * Plugin Name: Anode — Plan
 * Description: Les pages d'atelier « /plan » : plan du site, formulaires, charte, composants. Préproduction seulement.
 * Version:     1.0.0
 * Author:      Anode
 *
 * Un site en préproduction se relit à plusieurs, et rarement par son auteur. Le
 * client ouvre une adresse, cherche une page qu'il ne trouve pas, et demande
 * « où est le formulaire de contact ? » — alors qu'il est là, deux clics plus
 * loin. `/plan` répond à cette question avant qu'elle soit posée : tout ce que
 * le site contient, sur une page, avec son chemin, et le lien vers la version
 * en production quand elle existe.
 *
 * ## Pourquoi ce n'est pas une page du site
 *
 * Une page WordPress se maintient : on l'écrit, elle vieillit, elle ment. Ces
 * quatre pages **se déduisent du site vivant** à chaque requête — les pages
 * viennent de WordPress, les formulaires de `theme/<enfant>/forms/*.json`, la
 * charte de `tokens.css`, les composants de Bricks. Rien à tenir à jour : il
 * n'existe aucun état d'où elles pourraient diverger.
 *
 * ## Préproduction seulement, et le contrôle n'est pas une option
 *
 * `Anode\Seo\Seo::production_declared()` est l'autorité : `WP_ENVIRONMENT_TYPE`
 * d'abord, le domaine ensuite. En production, les quatre routes n'existent pas —
 * pas « refusent », **n'existent pas** : WordPress rend son 404 ordinaire, et
 * rien n'annonce qu'il y avait quelque chose à cette adresse.
 *
 * On ne s'en remet pas au htpasswd de la préproduction pour cela. Il protège
 * l'environnement, pas la route, et le jour où un site part en ligne sans lui,
 * ces pages exposeraient l'inventaire complet du site, les constantes de ses
 * webhooks et le nom de ses composants.
 *
 * @package Anode\Plan
 */

declare(strict_types=1);

namespace Anode\Plan;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/*
 * Le chargeur ne prend que `<dossier>/<dossier>.php` : le reste se requiert
 * ici. Chargés tout de suite, et non à la volée — ces deux fichiers ne
 * déclarent que des classes, et une inclusion conditionnelle rendrait le
 * composant sensible à l'ordre des accroches.
 */
require_once __DIR__ . '/includes/class-sources.php';
require_once __DIR__ . '/includes/class-rendu.php';

/**
 * Les quatre pages d'atelier.
 */
final class Plan {

	/** Le segment d'URL. Contrat public : les liens du parc le portent. */
	private const RACINE = 'plan';

	/** @var array<string, string> Les sous-pages, dans l'ordre de la navigation. */
	private const PAGES = [
		''             => 'Pages',
		'formulaires'  => 'Formulaires',
		'charte'       => 'Charte',
		'composants'   => 'Composants',
	];

	public static function demarrer(): void {
		add_action( 'init', [ self::class, 'declarer_regles' ] );
		add_filter( 'query_vars', [ self::class, 'declarer_variable' ] );
		add_action( 'template_redirect', [ self::class, 'servir' ] );
	}

	/**
	 * Le site est-il un environnement d'atelier ?
	 *
	 * Une seule autorité, celle du SEO : deux détections qui divergeraient
	 * donneraient un site indexé mais sans `/plan`, ou l'inverse.
	 */
	public static function atelier(): bool {
		if ( ! class_exists( '\Anode\Seo\Seo' ) ) {
			/*
			 * Sans le composant SEO, on ne sait pas répondre — et on ne devine
			 * pas. Le silence vaut refus : mieux vaut un `/plan` absent d'une
			 * préproduction qu'un `/plan` servi en production.
			 */
			return false;
		}

		return ! \Anode\Seo\Seo::production_declared();
	}

	public static function declarer_regles(): void {
		if ( ! self::atelier() ) {
			return;
		}

		add_rewrite_rule( '^' . self::RACINE . '/?$', 'index.php?anode_plan=', 'top' );
		add_rewrite_rule( '^' . self::RACINE . '/([a-z-]+)/?$', 'index.php?anode_plan=$matches[1]', 'top' );
	}

	/**
	 * @param array<int, string> $variables
	 * @return array<int, string>
	 */
	public static function declarer_variable( array $variables ): array {
		$variables[] = 'anode_plan';

		return $variables;
	}

	public static function servir(): void {
		if ( ! self::atelier() ) {
			return;
		}

		$demande = get_query_var( 'anode_plan', null );

		if ( null === $demande || false === $demande ) {
			return;
		}

		$page = is_string( $demande ) ? $demande : '';

		if ( ! array_key_exists( $page, self::PAGES ) ) {
			/*
			 * Une sous-page inventée doit rendre un **404**, pas ce que
			 * WordPress trouverait à défaut.
			 *
			 * Sortir d'ici ne suffit pas : la règle de réécriture a déjà pris la
			 * main, la requête n'a plus de post à servir, et WordPress retombe
			 * sur la page d'accueil — en HTTP 200. Mesuré sur le blueprint le
			 * 05/08/2026 : `/plan/inventee/` rendait l'accueil entier, 51 ko,
			 * en 200. Une adresse qui n'existe pas doit le dire.
			 */
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			include get_query_template( '404' );
			exit;
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		switch ( $page ) {
			case 'formulaires':
				echo Rendu::formulaires();
				break;
			case 'charte':
				echo Rendu::charte();
				break;
			case 'composants':
				echo Rendu::composants();
				break;
			default:
				echo Rendu::sitemap();
		}

		exit;
	}

	/** @return array<string, string> */
	public static function pages(): array {
		return self::PAGES;
	}

	public static function racine(): string {
		return self::RACINE;
	}
}

Plan::demarrer();
