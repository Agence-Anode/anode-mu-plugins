<?php
/**
 * Plugin Name: Anode — durcissement
 * Description: Sécurité de base sans extension tierce : URL de connexion masquée, limitation des tentatives, en-têtes de sécurité, XML-RPC coupé, énumération des comptes bloquée.
 * Version:     1.0.0
 * Author:      Anode
 *
 * Remplace SecuPress Pro et WPS Hide Login.
 *
 * Ce fichier ne couvre que ce qui relève de l'application. Le pare-feu, la
 * détection d'intrusion et le blocage réseau relèvent de l'hébergeur : une
 * extension PHP qui prétend les assurer consomme des ressources à chaque
 * requête pour une protection qui intervient déjà trop tard.
 *
 * @package Anode\Hardening
 */

declare( strict_types = 1 );

namespace Anode\Hardening;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '1.0.0';

/** Option contenant le segment d'URL de connexion. */
const OPTION_LOGIN_SLUG = 'anode_login_slug';

/** Nombre d'échecs de connexion tolérés avant blocage. */
const MAX_LOGIN_ATTEMPTS = 5;

/** Durée du blocage, en secondes. */
const LOCKOUT_DURATION = 15 * MINUTE_IN_SECONDS;

/* ------------------------------------------------------------------ */
/* URL de connexion masquée                                            */
/* ------------------------------------------------------------------ */

/**
 * Segment d'URL de connexion.
 *
 * Défini par la constante ANODE_LOGIN_SLUG dans wp-config.php, ou par une
 * option générée à la première visite. Masquer wp-login.php ne relève pas de
 * la sécurité au sens strict — c'est de l'obscurité — mais cela suffit à
 * écarter les robots qui martèlent l'URL par défaut.
 */
function login_slug(): string {
	if ( defined( 'ANODE_LOGIN_SLUG' ) && is_string( ANODE_LOGIN_SLUG ) && ANODE_LOGIN_SLUG ) {
		return sanitize_title( ANODE_LOGIN_SLUG );
	}

	$slug = get_option( OPTION_LOGIN_SLUG );

	if ( ! is_string( $slug ) || '' === $slug ) {
		$slug = 'acces-' . wp_generate_password( 8, false, false );
		add_option( OPTION_LOGIN_SLUG, $slug, '', false );
	}

	return $slug;
}

/**
 * Le masquage est-il actif ?
 *
 * Inactif en environnement local : masquer l'URL de connexion pendant le
 * développement fait perdre plus de temps qu'il n'apporte de sécurité.
 */
function login_masking_enabled(): bool {
	if ( defined( 'ANODE_MASK_LOGIN' ) ) {
		return (bool) ANODE_MASK_LOGIN;
	}

	return 'local' !== wp_get_environment_type();
}

/**
 * Sert la page de connexion sur l'URL masquée, et cache l'URL par défaut.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! login_masking_enabled() || is_admin() ) {
			return;
		}

		$path = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
		$slug = login_slug();

		// URL masquée : on sert la page de connexion native.
		if ( $path === $slug ) {
			// wp-login.php se fie à ces variables pour construire ses liens.
			$GLOBALS['pagenow'] = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

			require_once ABSPATH . 'wp-login.php';
			exit;
		}

		// URL par défaut : indiscernable d'une page inexistante.
		if ( 'wp-login.php' === $path || str_ends_with( $path, '/wp-login.php' ) ) {
			not_found();
		}

		// wp-admin réservé aux comptes connectés : sans cela, l'URL de
		// connexion serait révélée par la redirection.
		if ( str_starts_with( $path, 'wp-admin' ) && ! is_user_logged_in() ) {
			// admin-ajax et admin-post servent des requêtes légitimes non authentifiées.
			if ( ! preg_match( '#wp-admin/(admin-ajax|admin-post)\.php#', $path ) ) {
				not_found();
			}
		}
	},
	0
);

/**
 * Réécrit wp-login.php en URL masquée dans les liens générés par WordPress.
 */
foreach ( [ 'site_url', 'network_site_url', 'admin_url', 'network_admin_url', 'wp_redirect', 'login_url', 'lostpassword_url', 'logout_url', 'register_url' ] as $filter ) {
	add_filter(
		$filter,
		static function ( $url ) {
			if ( ! login_masking_enabled() || ! is_string( $url ) || ! str_contains( $url, 'wp-login.php' ) ) {
				return $url;
			}

			return str_replace( 'wp-login.php', login_slug(), $url );
		},
		PHP_INT_MAX
	);
}

/**
 * Affiche une 404 authentique, sans indice sur l'existence de la page.
 */
function not_found(): void {
	global $wp_query;

	status_header( 404 );
	nocache_headers();

	if ( $wp_query instanceof \WP_Query ) {
		$wp_query->set_404();
	}

	$template = get_404_template();

	if ( $template && is_readable( $template ) ) {
		include $template;
	} else {
		echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>404</title></head>'
			. '<body><h1>Page introuvable</h1></body></html>';
	}

	exit;
}

/* ------------------------------------------------------------------ */
/* Limitation des tentatives de connexion                              */
/* ------------------------------------------------------------------ */

/**
 * Clé de suivi d'une adresse IP.
 */
function attempt_key( string $ip ): string {
	return 'anode_login_' . md5( $ip );
}

/**
 * Adresse IP du client.
 *
 * Les en-têtes de proxy ne sont lus que si l'on a explicitement déclaré être
 * derrière un proxy de confiance : sinon, n'importe qui pourrait usurper une
 * adresse et contourner la limitation.
 */
function client_ip(): string {
	if ( defined( 'ANODE_TRUSTED_PROXY' ) && ANODE_TRUSTED_PROXY ) {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ] as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$candidate = trim( explode( ',', (string) $_SERVER[ $header ] )[0] );

				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}
	}

	$ip = $_SERVER['REMOTE_ADDR'] ?? '';

	return is_string( $ip ) && filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
}

/**
 * Refuse l'authentification d'une adresse bloquée.
 *
 * Le contrôle se fait sur `authenticate` plutôt qu'après échec : une adresse
 * bloquée ne doit même pas pouvoir tester un mot de passe.
 */
add_filter(
	'authenticate',
	static function ( $user, $username ) {
		if ( empty( $username ) ) {
			return $user;
		}

		$state = get_transient( attempt_key( client_ip() ) );

		if ( is_array( $state ) && ( $state['count'] ?? 0 ) >= MAX_LOGIN_ATTEMPTS ) {
			$remaining = max( 1, (int) ceil( ( ( $state['until'] ?? 0 ) - time() ) / 60 ) );

			return new \WP_Error(
				'anode_too_many_attempts',
				sprintf(
					/* translators: %d : nombre de minutes restantes. */
					__( '<strong>Trop de tentatives.</strong> Réessayez dans %d minute(s).', 'anode' ),
					$remaining
				)
			);
		}

		return $user;
	},
	5,
	2
);

/**
 * Comptabilise un échec de connexion.
 */
add_action(
	'wp_login_failed',
	static function (): void {
		$key   = attempt_key( client_ip() );
		$state = get_transient( $key );
		$count = is_array( $state ) ? (int) ( $state['count'] ?? 0 ) + 1 : 1;

		set_transient(
			$key,
			[
				'count' => $count,
				'until' => time() + LOCKOUT_DURATION,
			],
			LOCKOUT_DURATION
		);
	}
);

/**
 * Efface le compteur après une connexion réussie.
 */
add_action(
	'wp_login',
	static function (): void {
		delete_transient( attempt_key( client_ip() ) );
	}
);

/**
 * Message d'erreur de connexion générique.
 *
 * Le message natif distingue « identifiant inconnu » de « mot de passe
 * incorrect » : c'est un oracle qui permet d'énumérer les comptes valides.
 */
add_filter(
	'login_errors',
	static function ( $error ) {
		if ( is_string( $error ) && str_contains( $error, 'anode_too_many_attempts' ) ) {
			return $error;
		}

		return __( 'Identifiant ou mot de passe incorrect.', 'anode' );
	},
	PHP_INT_MAX
);

/* ------------------------------------------------------------------ */
/* Surface d'attaque                                                   */
/* ------------------------------------------------------------------ */

/**
 * Coupe XML-RPC.
 *
 * Principal vecteur d'attaques par force brute — une seule requête peut tester
 * des centaines de mots de passe via `system.multicall`. Sans usage ici :
 * l'API REST couvre les besoins d'intégration, dont ceux du pont Anode.
 *
 * Le filtre `xmlrpc_enabled` ne suffit pas : il ne désactive que les méthodes
 * demandant une authentification. `system.listMethods` et `system.multicall`
 * continuent de répondre, et renseignent un attaquant sur la surface
 * disponible. On refuse donc la requête avant tout traitement.
 */
add_filter( 'xmlrpc_enabled', '__return_false' );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! defined( 'XMLRPC_REQUEST' ) || ! XMLRPC_REQUEST ) {
			return;
		}

		/**
		 * Permet de rouvrir XML-RPC sur un site qui en dépend réellement
		 * (application mobile Jetpack, publication à distance).
		 */
		if ( apply_filters( 'anode/allow_xmlrpc', false ) ) {
			return;
		}

		header( 'Content-Type: text/plain; charset=UTF-8' );
		status_header( 403 );

		exit( 'XML-RPC est désactivé sur ce site.' );
	},
	0
);

add_filter(
	'wp_headers',
	static function ( array $headers ): array {
		unset( $headers['X-Pingback'] );

		return $headers;
	}
);

/**
 * Bloque l'énumération des comptes.
 *
 * `?author=1` révèle l'identifiant de connexion de l'administrateur ; c'est la
 * première étape de toute attaque par force brute ciblée.
 */
add_action(
	'template_redirect',
	static function (): void {
		if ( is_author() && ! is_user_logged_in() ) {
			wp_safe_redirect( home_url(), 301 );
			exit;
		}
	}
);

add_filter(
	'rest_endpoints',
	static function ( array $endpoints ): array {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}

		unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );

		return $endpoints;
	}
);

/**
 * Interdit l'édition de fichiers depuis l'administration.
 *
 * Un compte administrateur compromis pourrait sinon écrire du PHP arbitraire
 * dans le thème — et prendre le contrôle du serveur.
 */
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

/**
 * En-têtes de sécurité HTTP.
 *
 * Ces en-têtes gagnent à être posés par le serveur web — c'est plus rapide et
 * cela couvre aussi les fichiers statiques. On les pose ici en filet de
 * sécurité, sans écraser ceux déjà définis.
 */
add_filter(
	'wp_headers',
	static function ( array $headers ): array {
		$defaults = [
			'X-Content-Type-Options' => 'nosniff',
			'X-Frame-Options'        => 'SAMEORIGIN',
			'Referrer-Policy'        => 'strict-origin-when-cross-origin',
			// Coupe l'accès aux capteurs : aucun site du blueprint n'en a besoin.
			'Permissions-Policy'     => 'geolocation=(), microphone=(), camera=(), interest-cohort=()',
		];

		if ( is_ssl() ) {
			$defaults['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
		}

		foreach ( $defaults as $name => $value ) {
			if ( ! isset( $headers[ $name ] ) ) {
				$headers[ $name ] = $value;
			}
		}

		return $headers;
	}
);

/**
 * Masque la version de WordPress.
 */
add_filter( 'the_generator', '__return_empty_string' );

add_action(
	'init',
	static function (): void {
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
	}
);

/* ------------------------------------------------------------------ */
/* Administration                                                      */
/* ------------------------------------------------------------------ */

/**
 * Rappelle l'URL de connexion sur le tableau de bord.
 *
 * Une URL masquée puis oubliée est un incident garanti : on l'affiche là où
 * elle sera vue, plutôt que dans un fichier de notes.
 */
add_action(
	'admin_notices',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) || 'dashboard' !== get_current_screen()?->id ) {
			return;
		}

		if ( ! login_masking_enabled() ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p><strong>%s</strong> %s <code>%s</code></p></div>',
			esc_html__( 'Anode —', 'anode' ),
			esc_html__( 'URL de connexion de ce site :', 'anode' ),
			esc_url( home_url( '/' . login_slug() ) )
		);
	}
);
