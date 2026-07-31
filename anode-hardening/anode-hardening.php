<?php
/**
 * Plugin Name: Anode — durcissement
 * Description: Sécurité de base sans extension tierce : URL de connexion masquée, limitation des tentatives, en-têtes de sécurité, XML-RPC coupé, énumération des comptes bloquée.
 * Version:     1.5.0
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
 * Pas de constante de version ici : l'en-tête du fichier est la seule source,
 * et c'est elle que l'updater lit.
 *
 * Le doublon a dérivé deux fois — en-tête 1.0.1 contre constante 1.0.0, puis
 * 1.2.0 contre 1.1.0. Le commentaire qui l'accompagnait promettait une source
 * unique tout en en laissant deux. Aucun code ne lisait la constante : la
 * supprimer coûte zéro et referme la dérive pour de bon.
 */

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

		/*
		 * WP-CLI et le cron n'ont pas d'URL demandée : `requested_path()` rend
		 * une chaîne vide, aucune branche ne correspond, et le masquage les
		 * laisse déjà passer. La garde est explicite pour que personne n'ajoute
		 * plus tard une branche qui les attraperait — l'outillage de l'hébergeur
		 * s'appuie sur ces deux chemins pour cloner un site ou le remettre en
		 * service.
		 */
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
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

/*
 * WordPress redirige lui-même `/login`, `/admin` et `/dashboard`.
 *
 * `wp_redirect_admin_locations()` s'exécute sur `template_redirect` à la
 * priorité 1000, dès qu'une 404 tombe sur une installation à permaliens. Elle
 * compare l'URI demandée à deux listes :
 *
 *   /wp-admin, /dashboard, /admin   →  wp_redirect( admin_url() )
 *   /wp-login.php, /login.php, /login  →  wp_redirect( wp_login_url() )
 *
 * La seconde est le problème, et il est net : nos filtres réécrivent
 * `login_url` vers le segment masqué. WordPress met donc **l'URL masquée en
 * clair dans l'en-tête `Location`**, en réponse à une requête anonyme sur
 * `/login`. Tout le masquage tombe pour qui essaie une adresse évidente.
 *
 * La première ne fuite rien, mais elle contourne la garde `wp-admin` : elle
 * répond 302 là où l'on veut une 404 indiscernable.
 *
 * Mesuré sur blueprint.agence-anode.fr, où ce retrait est en place : `/login` et
 * `/dashboard` renvoient bien 404. Sans lui, ils renvoient une redirection.
 */
add_action(
	'init',
	static function (): void {
		if ( login_masking_enabled() ) {
			remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
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
	static function ( $erreur = null ): void {
		if ( ! tentative_a_compter( $erreur ) ) {
			return;
		}

		record_failed_attempt();
	}
);

/**
 * Un échec de mot de passe d'application est-il une tentative de forçage ?
 *
 * **Tous ne le sont pas, et compter les autres coupait le pilotage du site.**
 * Mesuré sur une préproduction protégée par un `.htpasswd` : le navigateur y
 * présente les identifiants du serveur sur **chaque** requête, y compris les
 * appels REST de Bricks. WordPress reçoit alors un `Authorization: Basic` dont le
 * nom n'est pas un compte du site, tente le mot de passe d'application, échoue —
 * et le verrou se fermait au bout de cinq requêtes. Conséquences constatées :
 * `wp_is_application_passwords_available()` passait à `false`, le serveur MCP
 * répondait « Permissions insuffisantes », et un harnais en cours perdait le moyen
 * de retirer son décor. Un simple aller-retour dans le builder suffisait.
 *
 * Ce qui est écarté : un **nom de compte inconnu**. Aucun mot de passe n'y est
 * vérifié — le forçage d'un mot de passe d'application exige un compte existant,
 * et c'est justement ce qui reste compté. La protection ne perd rien ; elle cesse
 * de se déclencher sur les identifiants d'une autre porte.
 *
 * Corollaire à retenir pour un site protégé au niveau du serveur : **l'utilisateur
 * du `.htpasswd` ne doit pas porter le nom d'un compte WordPress**, sans quoi ses
 * requêtes redeviennent des tentatives comptées.
 *
 * Sans motif lisible, on compte : mieux vaut un verrou de trop qu'une porte
 * ouverte, et une version de WordPress qui n'enverrait pas l'erreur ne doit pas
 * désarmer le compteur en silence.
 *
 * @param mixed $erreur L'erreur passée par WordPress à l'action.
 */
function tentative_a_compter( $erreur ): bool {
	if ( ! is_wp_error( $erreur ) ) {
		return true;
	}

	// `invalid_username` et `invalid_email` : le nom présenté ne désigne aucun
	// compte. Les deux existent parce que WordPress accepte l'un ou l'autre.
	return ! in_array( $erreur->get_error_code(), [ 'invalid_username', 'invalid_email' ], true );
}

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
 * Message d'erreur de connexion générique — sauf pour le verrouillage.
 *
 * Le message natif distingue « identifiant inconnu » de « mot de passe
 * incorrect » : c'est un oracle qui permet d'énumérer les comptes valides. On
 * le remplace donc par un message unique.
 *
 * Mais il faut une exception, et elle demande de savoir **quel** code d'erreur
 * a été levé. `login_errors` ne reçoit que le texte déjà rendu — jamais le
 * code : la condition qui cherchait `anode_too_many_attempts` dans le message
 * était donc toujours fausse, et le message de verrouillage systématiquement
 * écrasé. Le verrou fonctionnait, mais rien ne le disait : cinq échecs puis
 * une sixième tentative refusée pour la même raison apparente.
 *
 * `wp_login_errors` reçoit l'objet `WP_Error`, où le code existe encore. On
 * relève donc l'information là, et `login_errors` s'y réfère.
 */
add_filter(
	'wp_login_errors',
	static function ( $errors ) {
		if ( $errors instanceof \WP_Error && in_array( 'anode_too_many_attempts', $errors->get_error_codes(), true ) ) {
			$GLOBALS['anode_login_verrouille'] = true;
		}

		return $errors;
	},
	PHP_INT_MAX
);

add_filter(
	'login_errors',
	static function ( $error ) {
		// Le verrouillage se dit : sans message, la personne réessaie en boucle
		// sans comprendre, et le compteur se prolonge à chaque tentative.
		if ( ! empty( $GLOBALS['anode_login_verrouille'] ) ) {
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

/**
 * L'API oEmbed ne nomme pas l'auteur d'une page.
 *
 * `/wp-json/oembed/1.0/embed?url=…` sert `author_name` et `author_url` sans
 * aucune authentification : elle rend exactement l'identifiant de connexion que
 * les trois gardes ci-dessus retirent. Mesuré sur une installation où
 * `/?author=1` et `/wp-json/wp/v2/users` étaient déjà fermés — le nom du compte
 * administrateur sortait quand même.
 *
 * @param array<string, mixed> $donnees Réponse oEmbed.
 * @return array<string, mixed>
 */
add_filter(
	'oembed_response_data',
	static function ( $donnees ) {
		if ( is_array( $donnees ) ) {
			unset( $donnees['author_name'], $donnees['author_url'] );
		}

		return $donnees;
	}
);

/**
 * Hors production, `robots.txt` interdit toute exploration.
 *
 * Le fichier par défaut n'interdit que `/wp-admin/` : il invite donc à explorer
 * une préproduction que `anode-seo` déclare pourtant en `noindex`. Les deux se
 * contredisent, et un robot qui lit d'abord `robots.txt` n'ira jamais lire la
 * balise qui le lui aurait dit.
 *
 * @param string $sortie Contenu du fichier.
 * @return string
 */
add_filter(
	'robots_txt',
	static function ( $sortie ) {
		if ( 'production' === wp_get_environment_type() ) {
			return $sortie;
		}

		return "User-agent: *\nDisallow: /\n";
	},
	20
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
 * Les routes qu'un visiteur anonyme doit atteindre, même sur un site fermé.
 *
 * Le moteur de formulaire, et lui seul : le navigateur lit la définition
 * (`GET /anode/v1/form/<nom>`) puis poste la demande (`POST /anode/v1/form`).
 * Les fermer rendrait chaque formulaire inerte sur une préproduction —
 * c'est‑à‑dire à l'endroit précis où on le fait relire au client.
 *
 * @var list<string>
 */
const ROUTES_REST_PUBLIQUES = [ '/anode/v1/form' ];

/**
 * Hors production, l'API REST ne répond pas à un visiteur anonyme.
 *
 * Une préproduction est fermée par une authentification HTTP au niveau du
 * serveur — **sauf `/wp-json/`, qui doit rester en dehors** : l'API attend le
 * mot de passe d'application dans l'en-tête `Authorization`, et le htpasswd
 * consommerait le même en-tête. `mcp/anode-wp/src/wp-client.js` le dit à
 * l'endroit où il s'en abstient. Sans cette exemption, plus aucun pilotage.
 *
 * L'exemption rouvrait tout le reste, et personne ne l'avait décidé. Mesuré le
 * 01/08/2026 sur la préproduction d'un site client, sans le moindre
 * identifiant :
 *
 *     curl https://…/wp-json/wp/v2/pages   →   200, 19 847 octets
 *
 * — le contenu rendu de chaque page d'un site que l'authentification HTTP est
 * censée garder fermé jusqu'à la mise en ligne, et que `robots.txt` interdit
 * d'explorer six lignes plus haut. `/wp/v2/users` était déjà fermé juste
 * au-dessus ; les pages, elles, passaient, et avec elles les types de contenu
 * du projet.
 *
 * Le partage se fait donc là où l'on sait qui appelle : WordPress. Le compte de
 * service, authentifié par mot de passe d'application, passe — le pilotage est
 * intact. Le visiteur anonyme reçoit 401, comme sur le reste du site.
 *
 * En production le filtre s'efface : un site public sert ses pages, c'est son
 * objet. Le repère est le même que celui de `robots_txt` ci‑dessus, et il bascule
 * tout seul le jour de la mise en ligne.
 */
add_filter(
	'rest_pre_dispatch',
	static function ( $resultat, $serveur, $requete ) {
		if ( null !== $resultat || is_user_logged_in() || 'production' === wp_get_environment_type() ) {
			return $resultat;
		}

		$route = is_object( $requete ) && method_exists( $requete, 'get_route' )
			? (string) $requete->get_route()
			: '';

		/**
		 * Permet à un site d'ouvrir une route de plus — une façade publique, un
		 * flux consommé par un tiers. Le filtre existe pour que ce cas ne se
		 * règle pas en modifiant le mu-plugin, ce qui le ferait diverger du
		 * blueprint sans que rien ne le signale.
		 *
		 * @param list<string> $prefixes Préfixes de route laissés ouverts.
		 */
		$ouvertes = (array) apply_filters( 'anode/hardening/routes_rest_publiques', ROUTES_REST_PUBLIQUES );

		foreach ( $ouvertes as $prefixe ) {
			if ( is_string( $prefixe ) && '' !== $prefixe && 0 === strpos( $route, $prefixe ) ) {
				return $resultat;
			}
		}

		return new \WP_Error(
			'anode_rest_prive',
			'Ce site n’est pas encore public : son API REST demande une authentification.',
			[ 'status' => 401 ]
		);
	},
	10,
	3
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
 * Le site est-il servi depuis le domaine lui-même, et non depuis un
 * sous-domaine partagé ?
 *
 * Ne sert qu'à décider de `includeSubDomains`. La question n'est pas « suis-je
 * en production » mais « ce que j'engage m'appartient-il en entier » : un site
 * servi depuis `client.hebergeur.fr` engagerait tous les voisins.
 *
 * Le décompte des labels est volontairement grossier — reconnaître un domaine
 * enregistrable demanderait la liste des suffixes publics, que WordPress n'a
 * pas. `exemple.co.uk` compte donc trois labels et n'obtient pas
 * `includeSubDomains`. C'est le bon sens de l'erreur : un faux négatif retire
 * une protection *supplémentaire* sur des sous-domaines qui posent déjà la
 * leur ; un faux positif se purge à la main, navigateur par navigateur.
 */
function engage_les_sous_domaines(): bool {
	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

	if ( '' === $host || filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return false;
	}

	$labels = explode( '.', $host );

	if ( 'www' === $labels[0] ) {
		array_shift( $labels );
	}

	return count( $labels ) <= 2;
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

		/*
		 * HSTS ne se pose qu'en production, et `includeSubDomains` seulement
		 * depuis le domaine lui-même.
		 *
		 * L'en-tête engage le navigateur pour un an : il refusera ensuite toute
		 * connexion en clair vers ce domaine **et ses sous-domaines**. Le seul
		 * remède est de purger la liste HSTS du navigateur à la main, poste par
		 * poste — il n'y a pas de rétractation côté serveur.
		 *
		 * `wp_get_environment_type()` rend « production » par défaut : une
		 * installation qui ne déclare rien garde donc l'en-tête. C'est le bon
		 * sens du doute — une production non déclarée est plus fréquente qu'une
		 * préproduction non déclarée.
		 *
		 * Mais ce doute ne suffisait pas, et c'est mesuré : une préproduction en
		 * `client.anode-staging.fr`, qui ne déclarait rien, posait un an de HSTS
		 * `includeSubDomains` sur `anode-staging.fr` — donc sur **chaque autre
		 * préproduction du parc**, dans le navigateur de qui l'avait visitée.
		 * L'environnement n'était pas la bonne question : la portée l'était.
		 * `includeSubDomains` engage un domaine qu'on ne possède en exclusivité
		 * que si l'on est servi depuis lui.
		 */
		if ( is_ssl() && 'production' === wp_get_environment_type() ) {
			$defaults['Strict-Transport-Security'] = engage_les_sous_domaines()
				? 'max-age=31536000; includeSubDomains'
				: 'max-age=31536000';
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
/* Téléversements                                                      */
/* ------------------------------------------------------------------ */

/**
 * Types MIME autorisés en plus de ceux de WordPress.
 *
 * @param array<string,string> $types Types autorisés.
 * @return array<string,string>
 */
add_filter(
	'upload_mimes',
	static function ( array $types ): array {
		$types['webp']  = 'image/webp';
		$types['avif']  = 'image/avif';
		$types['woff']  = 'font/woff';
		$types['woff2'] = 'font/woff2';

		/*
		 * Le SVG n'est ouvert qu'aux comptes qui peuvent déjà exécuter du code.
		 *
		 * Un SVG est un document XML : il porte `<script>`, `<foreignObject>` et
		 * des gestionnaires `on*`. Le servir depuis le domaine du site, c'est
		 * offrir un XSS stocké à qui peut téléverser — le rôle « auteur » suffit.
		 * Le réserver à `unfiltered_html` aligne le risque sur un droit que ce
		 * rôle possède déjà.
		 *
		 * Conséquence à connaître : `DISALLOW_UNFILTERED_HTML`, posée par
		 * défaut dans `config/wp-config-extrait.php`, retire ce droit à **tout
		 * le monde**, administrateurs compris. Le SVG est alors refusé partout,
		 * et la cause n'est pas dans ce fichier. C'est le réglage voulu : un
		 * site dont le client dépose lui-même des SVG retire la constante, en
		 * connaissant le risque.
		 */
		if ( current_user_can( 'unfiltered_html' ) ) {
			$types['svg'] = 'image/svg+xml';
		}

		return $types;
	}
);

/**
 * Refuse un téléversement dont le type ne figure pas dans la liste.
 *
 * @param array<string,mixed> $file Descripteur du fichier téléversé.
 * @return array<string,mixed>
 */
add_filter(
	'wp_handle_upload_prefilter',
	static function ( array $file ): array {
		$allowed = [
			'image/jpeg',
			'image/png',
			'image/webp',
			'image/avif',
			'image/gif',
			'application/pdf',
			'application/zip',
			'application/x-zip-compressed',
			'font/woff',
			'application/font-woff',
			'application/x-font-woff',
			'font/woff2',
			'application/font-woff2',
			'video/mp4',
			'video/webm',
		];

		if ( current_user_can( 'unfiltered_html' ) ) {
			$allowed[] = 'image/svg+xml';
		}

		if ( ! in_array( $file['type'] ?? '', $allowed, true ) ) {
			$file['error'] = sprintf(
				/* translators: %s : type MIME refusé. */
				__( 'Type de fichier refusé (%s). Formats acceptés : JPG, PNG, WEBP, AVIF, GIF, PDF, ZIP, WOFF, WOFF2, MP4, WEBM.', 'anode' ),
				(string) ( $file['type'] ?? '' )
			);
		}

		return $file;
	}
);

/**
 * Retire d'un SVG ce qui s'y exécute.
 *
 * Le contrôle de type ne dit rien du contenu : un fichier au bon MIME peut
 * porter un script. On l'assainit donc avant qu'il n'atteigne la médiathèque —
 * autrement, autoriser le SVG revient à autoriser le JavaScript.
 *
 * La priorité 20 place ce filtre **après** celui qui refuse les types non
 * autorisés : inutile d'assainir un fichier qui ne sera pas accepté.
 *
 * @param array<string,mixed> $file Descripteur du fichier téléversé.
 * @return array<string,mixed>
 */
add_filter(
	'wp_handle_upload_prefilter',
	static function ( array $file ): array {
		if ( 'image/svg+xml' !== ( $file['type'] ?? '' ) || ! empty( $file['error'] ) ) {
			return $file;
		}

		$contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $contents ) {
			return $file;
		}

		$clean = preg_replace(
			[
				'#<script\b[^>]*>.*?</script>#is',
				'#<script\b[^>]*/>#i',
				'#<foreignObject\b[^>]*>.*?</foreignObject>#is',
				'#<!ENTITY[^>]*>#i',
				'#\son\w+\s*=\s*"[^"]*"#i',
				"#\son\w+\s*=\s*'[^']*'#i",
				'#(href|xlink:href)\s*=\s*(["\'])\s*(?:javascript|data):[^"\']*\2#i',
			],
			'',
			$contents
		);

		/*
		 * `preg_replace` rend `null` sur erreur PCRE — limite de retour arrière,
		 * UTF-8 invalide. Écrire ce `null` viderait le fichier ; le laisser tel
		 * quel laisserait passer un SVG non assaini. On refuse donc le fichier :
		 * c'est le seul des trois résultats qui ne surprend personne.
		 */
		if ( null === $clean ) {
			$file['error'] = __( 'Ce fichier SVG n’a pas pu être analysé. Exportez-le à nouveau depuis votre outil de dessin.', 'anode' );

			return $file;
		}

		if ( $clean !== $contents ) {
			file_put_contents( $file['tmp_name'], $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
		}

		return $file;
	},
	20
);

/* ------------------------------------------------------------------ */
/* Maintenance                                                         */
/* ------------------------------------------------------------------ */

/*
 * Les mises à jour ne se posent pas toutes seules.
 *
 * Bricks et le thème enfant décident du rendu : une montée de version pendant
 * la nuit peut déplacer une mise en page sans que personne ne l'ait demandé.
 * Elles se posent après vérification. Les mu-plugins, eux, ont leur propre
 * canal — celui de l'updater, qui distingue correctif et version majeure.
 */
add_filter( 'auto_update_core', '__return_false' );
add_filter( 'auto_update_plugin', '__return_false' );
add_filter( 'auto_update_theme', '__return_false' );
add_filter( 'auto_core_update_send_email', '__return_false' );
add_filter( 'send_auto_update_email', '__return_false' );

/**
 * Prolonge le cookie de connexion pour qui coche « se souvenir de moi ».
 *
 * Deux semaines par défaut, et le builder redemande donc les identifiants en
 * plein travail d'intégration. Un an ne dégrade rien ici : l'URL de connexion
 * est masquée, les tentatives sont limitées, et le cookie reste lié à
 * l'appareil.
 *
 * @param int  $duree    Durée en secondes.
 * @param int  $user_id  Compte concerné.
 * @param bool $memoriser « Se souvenir de moi » coché.
 * @return int
 */
add_filter(
	'auth_cookie_expiration',
	static function ( $duree, $user_id, $memoriser ) {
		return $memoriser ? YEAR_IN_SECONDS : $duree;
	},
	99,
	3
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
