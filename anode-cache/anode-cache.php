<?php
/**
 * Plugin Name: Anode — cache de pages
 * Description: Cache HTML statique pour les visiteurs anonymes, purgé automatiquement à chaque modification de contenu. Remplace la seule fonction de WP Rocket encore utile sur un site zéro dépendance.
 * Version:     1.0.0
 * Author:      Anode
 *
 * Sur un site sans script tiers ni police distante, un cache de pages n'a plus
 * qu'un rôle : éviter de reconstruire le HTML à chaque visite. Le reste de ce
 * que fait une extension de cache est ici sans objet — le chargement différé
 * des images est natif depuis WordPress 5.5, et il n'y a ni police externe à
 * optimiser ni script tiers à retarder.
 *
 * Politique volontairement conservatrice : on ne sert du cache qu'aux visiteurs
 * strictement anonymes, sur des requêtes GET sans paramètre. Un cache qui
 * servirait la page d'un utilisateur connecté à un autre visiteur serait une
 * fuite de données ; mieux vaut ne pas cacher que cacher à tort.
 *
 * @package Anode\Cache
 */

declare( strict_types = 1 );

namespace Anode\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '1.0.0';

/** Durée de vie d'une page en cache. */
const TTL = 12 * HOUR_IN_SECONDS;

/**
 * Répertoire du cache.
 */
function directory(): string {
	return WP_CONTENT_DIR . '/cache/anode';
}

/**
 * Le cache est-il actif ?
 *
 * Inactif en développement : servir une page figée pendant l'intégration fait
 * perdre un temps considérable en fausses pistes.
 */
function enabled(): bool {
	if ( defined( 'ANODE_CACHE' ) ) {
		return (bool) ANODE_CACHE;
	}

	return 'production' === wp_get_environment_type();
}

/**
 * La requête courante peut-elle être mise en cache ?
 *
 * Toute réponse à cette question doit être « non » au moindre doute.
 */
function is_cacheable(): bool {
	if ( ! enabled() ) {
		return false;
	}

	// Seules les lectures anonymes sont concernées.
	if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		return false;
	}

	if ( is_user_logged_in() || is_admin() ) {
		return false;
	}

	// Un paramètre d'URL signale une page personnalisée ou filtrée.
	if ( ! empty( $_GET ) ) {
		return false;
	}

	// Un cookie WordPress trahit une session : connexion ou commentaire.
	foreach ( array_keys( $_COOKIE ) as $name ) {
		if ( str_starts_with( (string) $name, 'wordpress_' )
			|| str_starts_with( (string) $name, 'wp-' )
			|| str_starts_with( (string) $name, 'comment_author' ) ) {
			return false;
		}
	}

	// Contextes intrinsèquement dynamiques.
	if ( is_search() || is_preview() || is_404() || is_feed() || is_trackback() || is_robots() ) {
		return false;
	}

	if ( defined( 'DOING_AJAX' ) || defined( 'DOING_CRON' ) || defined( 'REST_REQUEST' ) || defined( 'WP_CLI' ) ) {
		return false;
	}

	// Page protégée par mot de passe.
	if ( is_singular() && post_password_required() ) {
		return false;
	}

	/**
	 * Permet d'exclure une page précise — un formulaire à jeton, par exemple.
	 *
	 * @param bool $cacheable Décision courante.
	 */
	return (bool) apply_filters( 'anode/cache/is_cacheable', true );
}

/**
 * Chemin du fichier de cache correspondant à l'URL courante.
 */
function path_for_request(): string {
	$uri = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );

	// L'URL est hachée : aucune séquence de traversée ne peut en sortir.
	return directory() . '/' . sanitize_host( $_SERVER['HTTP_HOST'] ?? '' ) . '/' . md5( $uri ) . '.html';
}

/**
 * Réduit l'en-tête Host à un nom de dossier sûr.
 *
 * L'en-tête Host est fourni par le client : il peut contenir n'importe quoi.
 * On n'accepte qu'un nom de domaine bien formé ; tout le reste est remplacé
 * par une empreinte, qui reste stable sans jamais produire de chemin relatif.
 */
function sanitize_host( mixed $host ): string {
	$host = strtolower( trim( (string) $host ) );

	// Le port éventuel ne fait pas partie de l'identité du site.
	$host = (string) preg_replace( '/:\d+$/', '', $host );

	if ( preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host ) ) {
		return $host;
	}

	return 'host-' . md5( $host );
}

/* ------------------------------------------------------------------ */
/* Écriture                                                            */
/* ------------------------------------------------------------------ */

/**
 * Capture le HTML de la page et l'écrit en cache.
 *
 * On démarre la temporisation au plus tôt et on écrit à la fermeture : le
 * statut HTTP et les conditions de mise en cache ne sont connus qu'à ce
 * moment-là.
 */
add_action(
	'template_redirect',
	static function (): void {
		if ( ! enabled() ) {
			return;
		}

		ob_start(
			static function ( string $html ): string {
				if ( ! is_cacheable() || '' === trim( $html ) ) {
					return $html;
				}

				// Ne jamais cacher une erreur ni une redirection.
				if ( 200 !== http_response_code() ) {
					return $html;
				}

				$path = path_for_request();

				if ( ! wp_mkdir_p( dirname( $path ) ) ) {
					return $html;
				}

				$stamped = $html . sprintf(
					"\n<!-- Anode : page mise en cache le %s -->",
					gmdate( 'c' )
				);

				// Écriture atomique : un fichier temporaire puis un renommage.
				// Sans cela, une requête concurrente pourrait lire un HTML tronqué.
				$temporary = $path . '.' . wp_generate_password( 6, false ) . '.tmp';

				if ( false !== file_put_contents( $temporary, $stamped, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					if ( ! @rename( $temporary, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						wp_delete_file( $temporary );
					}
				}

				return $html;
			}
		);
	},
	0
);

/* ------------------------------------------------------------------ */
/* Purge                                                               */
/* ------------------------------------------------------------------ */

/**
 * Vide intégralement le cache.
 *
 * Purge totale plutôt que ciblée : sur un site vitrine, une page modifiée
 * apparaît dans le menu, le fil d'Ariane et les listes d'articles. Calculer
 * les pages réellement affectées coûte plus cher que tout régénérer.
 *
 * @return int Nombre de fichiers supprimés.
 */
function purge(): int {
	$directory = directory();

	if ( ! is_dir( $directory ) ) {
		return 0;
	}

	$removed  = 0;
	$iterator = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ),
		\RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $item ) {
		if ( $item->isFile() ) {
			wp_delete_file( $item->getPathname() );
			++$removed;
		}
	}

	/**
	 * Signale la purge — le pont MCP s'en sert pour son compte rendu.
	 *
	 * @param int $removed Nombre de fichiers supprimés.
	 */
	do_action( 'anode/cache/purged', $removed );

	return $removed;
}

/**
 * Purge à chaque modification de contenu ou de réglage structurant.
 */
foreach ( [ 'save_post', 'deleted_post', 'trashed_post', 'wp_update_nav_menu', 'switch_theme', 'customize_save_after' ] as $hook ) {
	add_action( $hook, __NAMESPACE__ . '\\purge', 20 );
}

/**
 * Purge lorsqu'une option structurante change.
 *
 * Les classes globales, variables et réglages Bricks influent sur le CSS de
 * toutes les pages : une modification via le builder ou le pont doit invalider
 * l'ensemble du cache.
 */
add_action(
	'updated_option',
	static function ( string $option ): void {
		static $prefixes = [ 'bricks_', 'anode_', 'permalink_structure', 'blogname', 'blogdescription', 'page_on_front', 'page_for_posts' ];

		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $option, $prefix ) ) {
				purge();

				return;
			}
		}
	}
);

/* ------------------------------------------------------------------ */
/* Administration                                                      */
/* ------------------------------------------------------------------ */

/**
 * Bouton de purge dans la barre d'administration.
 */
add_action(
	'admin_bar_menu',
	static function ( \WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bar->add_node(
			[
				'id'    => 'anode-cache-purge',
				'title' => __( 'Vider le cache', 'anode' ),
				'href'  => wp_nonce_url( admin_url( '?anode_cache=purge' ), 'anode_cache_purge' ),
				'meta'  => [ 'title' => __( 'Vide le cache de pages Anode', 'anode' ) ],
			]
		);
	},
	100
);

add_action(
	'admin_init',
	static function (): void {
		if ( 'purge' !== ( $_GET['anode_cache'] ?? '' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'anode_cache_purge' ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'anode' ), 403 );
		}

		$removed = purge();

		wp_safe_redirect( add_query_arg( 'anode_cache_purged', $removed, admin_url() ) );
		exit;
	}
);

add_action(
	'admin_notices',
	static function (): void {
		if ( ! isset( $_GET['anode_cache_purged'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d : nombre de pages retirées du cache. */
					__( 'Cache vidé : %d page(s) retirée(s).', 'anode' ),
					(int) $_GET['anode_cache_purged']
				)
			)
		);
	}
);
