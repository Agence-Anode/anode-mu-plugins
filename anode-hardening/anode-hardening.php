<?php
/**
 * Plugin Name: Anode — durcissement
 * Description: Sécurité de base sans extension tierce : URL de connexion masquée, limitation des tentatives, en-têtes de sécurité, XML-RPC coupé, énumération des comptes bloquée.
 * Version:     1.1.0
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

/*
 * Une seule source pour la version : l'en-tête du fichier, que l'updater lit.
 * Ce doublon avait déjà dérivé — en-tête 1.0.1, constante 1.0.0 — et c'est la
 * constante que le code aurait rapportée.
 */
const VERSION = '1.1.0';

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
 * Segment d'URL de connexion — le même sur toutes les installations.
 *
 * `/anode-connexion`. Un segment tiré au hasard par site obligeait à aller le
 * chercher en base à chaque intervention, et le rendait impossible à
 * communiquer : c'est précisément ce qui poussait à désactiver le masquage en
 * développement, donc à ne jamais tester la seule branche qui compte.
 *
 * Ce que cela coûte, et il faut le savoir : un segment partagé n'est plus un
 * secret. Il n'écarte que les robots qui martèlent `wp-login.php` — ce qui reste
 * l'essentiel du bruit — mais une seule fuite vaut pour toutes les
 * installations. La vraie protection contre une attaque ciblée est donc la
 * limitation des tentatives, plus bas.
 *
 * `ANODE_LOGIN_SLUG` dans `wp-config.php` reste la seule façon de dévier pour un
 * site donné. L'option `anode_login_slug` n'est **plus lue** : la conserver aurait
 * laissé à chaque site créé avant ce changement son ancien segment aléatoire,
 * c'est-à-dire exactement ce qu'on voulait supprimer. Elle est effacée au passage,
 * pour ne pas laisser en base un réglage qui ne règle plus rien.
 */
const LOGIN_SLUG_DEFAUT = 'anode-connexion';

function login_slug(): string {
	if ( defined( 'ANODE_LOGIN_SLUG' ) && is_string( ANODE_LOGIN_SLUG ) && ANODE_LOGIN_SLUG ) {
		return sanitize_title( ANODE_LOGIN_SLUG );
	}

	return LOGIN_SLUG_DEFAUT;
}

/*
 * Ménage : l'option du segment aléatoire n'a plus d'effet.
 *
 * `get_option` est servi par le cache d'options, donc cette lecture ne coûte
 * aucune requête ; l'effacement n'a lieu qu'une fois, au premier chargement qui
 * suit la mise à jour.
 */
add_action(
	'init',
	static function (): void {
		if ( false !== get_option( OPTION_LOGIN_SLUG, false ) ) {
			delete_option( OPTION_LOGIN_SLUG );
		}
	},
	1
);

/**
 * Le masquage est-il actif ? Oui, partout.
 *
 * Il était éteint en local, au motif qu'un segment aléatoire fait perdre du
 * temps en développement. Le motif tombe avec un segment fixe et mémorisable —
 * et son effet de bord était grave : la branche du masquage n'était **jamais**
 * exercée avant la mise en ligne. Elle est arrivée en production avec une erreur
 * fatale, c'est-à-dire aucune façon de se connecter.
 *
 * `ANODE_MASK_LOGIN` reste la porte de sortie, pour un site où l'hébergeur ou un
 * outil tiers impose `wp-login.php`.
 */
function login_masking_enabled(): bool {
	if ( defined( 'ANODE_MASK_LOGIN' ) ) {
		return (bool) ANODE_MASK_LOGIN;
	}

	return true;
}

/**
 * Chemin demandé, sans la barre de tête ni la chaîne de requête.
 *
 * Le sous-répertoire d'une installation qui n'est pas à la racine est retiré :
 * sans cela, `/blog/anode-connexion` ne correspondait à rien et la connexion
 * devenait impossible sur ce genre d'installation.
 */
function requested_path(): string {
	$path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
	$base = trim( (string) wp_parse_url( site_url(), PHP_URL_PATH ), '/' );

	$path = trim( $path, '/' );

	if ( '' !== $base && ( $path === $base || str_starts_with( $path, $base . '/' ) ) ) {
		$path = trim( substr( $path, strlen( $base ) ), '/' );
	}

	return $path;
}

/*
 * Le masquage s'applique sur `wp_loaded`, pas sur `plugins_loaded`.
 *
 * C'est **la** correction : `require ABSPATH . 'wp-login.php'` sur
 * `plugins_loaded` produisait une erreur fatale — « Attempt to read property
 * query_vars on null » — parce que `$wp_query`, `$wp_rewrite` et `$wp` ne sont
 * construits qu'après cette action. Résultat en production : l'URL masquée
 * renvoyait 500, `wp-login.php` aussi, et il ne restait **aucune** façon de se
 * connecter. Le bug était invisible en développement, le masquage y étant éteint.
 *
 * `wp_loaded` est le bon moment, et pour trois raisons qui tiennent ensemble :
 *
 * - les objets de requête existent, donc `wp-login.php` s'exécute normalement, et
 *   `not_found()` peut charger le gabarit 404 du thème ;
 * - rien n'a encore été envoyé au navigateur — `wp()` n'est appelé qu'après, dans
 *   `wp-blog-header.php` ;
 * - sur une requête d'administration, `wp-admin/admin.php` charge `wp-load.php`
 *   — donc déclenche `wp_loaded` — **avant** d'appeler `auth_redirect()`. La garde
 *   passe donc avant la redirection qui révélait le segment masqué.
 *
 * `is_admin()` ne sert plus de sortie anticipée : c'était la cause du second
 * défaut. Sur `/wp-admin/`, `is_admin()` vaut vrai, la fonction sortait
 * immédiatement, et la garde qui suit n'a jamais été exécutée une seule fois.
 */
add_action(
	'wp_loaded',
	static function (): void {
		if ( ! login_masking_enabled() ) {
			return;
		}

		$path = requested_path();
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

		/*
		 * wp-admin réservé aux comptes connectés.
		 *
		 * Sans cette garde, `auth_redirect()` renvoie vers l'URL de connexion — et
		 * la met donc en clair dans l'en-tête `Location`. Une requête anonyme sur
		 * `/wp-admin/` suffisait à révéler le segment masqué.
		 */
		if ( 'wp-admin' === $path || str_starts_with( $path, 'wp-admin/' ) ) {
			// admin-ajax et admin-post servent des requêtes légitimes non authentifiées.
			if ( preg_match( '#^wp-admin/(admin-ajax|admin-post)\.php$#', $path ) ) {
				return;
			}

			if ( ! is_user_logged_in() ) {
				not_found();
			}
		}
	}
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
 * Une adresse est-elle verrouillée ?
 */
function is_locked_out(): bool {
	$state = get_transient( attempt_key( client_ip() ) );

	return is_array( $state ) && ( $state['count'] ?? 0 ) >= MAX_LOGIN_ATTEMPTS;
}

/**
 * Refuse l'authentification d'une adresse bloquée.
 *
 * **La priorité n'est pas un détail : c'est tout le mécanisme.**
 *
 * Le filtre était en 5, avant les authentificateurs du noyau (20), au motif
 * qu'« une adresse bloquée ne doit même pas pouvoir tester un mot de passe ».
 * L'intention était juste, la mécanique non : `wp_authenticate_username_password`
 * ne court-circuite que sur `$user instanceof WP_User` — elle ne regarde jamais
 * si elle a reçu un `WP_Error`. Notre refus était donc **écrasé** par une
 * tentative d'authentification neuve, à chaque essai. Le verrou n'a jamais
 * bloqué quoi que ce soit.
 *
 * En 21, juste après les authentificateurs par identifiant et par adresse et
 * avant `wp_authenticate_cookie` (30), le refus est le dernier mot — et un
 * visiteur déjà connecté par cookie n'est pas déconnecté au passage.
 */
add_filter(
	'authenticate',
	static function ( $user, $username ) {
		if ( empty( $username ) ) {
			return $user;
		}

		if ( is_locked_out() ) {
			$state     = get_transient( attempt_key( client_ip() ) );
			$remaining = max( 1, (int) ceil( ( ( ( is_array( $state ) ? $state['until'] : 0 ) ?? 0 ) - time() ) / 60 ) );

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
	21,
	2
);

/*
 * Le mot de passe d'application est une seconde porte, et elle n'était pas gardée.
 *
 * `wp_validate_application_password()` — accrochée à `determine_current_user` —
 * appelle `wp_authenticate_application_password()` **en direct**, sans passer par
 * `wp_authenticate()` : ni le filtre `authenticate` ni l'action
 * `wp_login_failed` ne s'exécutent. Un attaquant pouvait donc marteler l'API REST
 * sans jamais être compté ni bloqué, pendant que la porte de `wp-login.php` était
 * verrouillée après cinq essais.
 *
 * Le compte du MCP y est exposé, et il doit être Administrateur (docs/mcp.md:53).
 */
add_action(
	'application_password_failed_authentication',
	static function (): void {
		record_failed_attempt();
	}
);

add_filter(
	'wp_is_application_passwords_available',
	static function ( $disponible ) {
		return is_locked_out() ? false : $disponible;
	}
);

/**
 * Comptabilise un échec de connexion, d'où qu'il vienne.
 *
 * Nommée plutôt qu'anonyme parce que **deux** portes l'alimentent :
 * `wp_login_failed` pour le formulaire, et
 * `application_password_failed_authentication` pour l'API. La seconde ne passe
 * pas par la première.
 */
function record_failed_attempt(): void {
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

add_action( 'wp_login_failed', __NAMESPACE__ . '\\record_failed_attempt' );

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
	},
	0
);

/*
 * `?author=1` doit être coupé **avant** la redirection canonique de WordPress.
 *
 * `redirect_canonical` est accroché à `template_redirect` en priorité 10, et
 * enregistré par le noyau avant tout mu-plugin : à priorité égale il partait le
 * premier, et renvoyait un 301 vers `/author/<identifiant>/`. L'archive d'auteur
 * était bien bloquée ensuite — mais l'identifiant de connexion avait déjà fuité
 * dans l'en-tête `Location`, ce qui est exactement l'information cherchée.
 *
 * La priorité 0 ci-dessus règle le cas des URL jolies. Celui de `?author=`
 * demande en plus de désarmer la redirection canonique, qui s'exécute sur
 * `template_redirect` mais aussi lors d'autres passages.
 */
add_filter(
	'redirect_canonical',
	static function ( $redirection ) {
		if ( ! is_user_logged_in() && ( isset( $_GET['author'] ) || is_author() ) ) {
			return false;
		}

		return $redirection;
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
