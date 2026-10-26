<?php
/**
 * Double authentification par e-mail.
 *
 * Après un mot de passe juste, WordPress ne pose pas le cookie de session : il
 * envoie un code à six chiffres à l'adresse du compte, et la connexion n'aboutit
 * qu'une fois ce code saisi. Un mot de passe volé ne suffit plus — il faut aussi
 * la boîte de réception.
 *
 * ## Activation, site par site
 *
 *   define( 'ANODE_2FA_EMAIL', true );   // dans wp-config.php
 *
 * Éteinte par défaut, et c'est voulu : elle repose entièrement sur l'envoi
 * d'e-mails. Un site dont `wp_mail()` échoue enfermerait dehors tous ses comptes
 * le jour même de la mise à jour, distribuée automatiquement à tout le parc. On
 * l'allume là où l'envoi est vérifié (SMTP authentifié), pas avant.
 *
 * ## Ce qui est couvert, et ce qui ne l'est pas
 *
 * - Le formulaire de connexion (`wp-login.php`, donc l'URL masquée) : oui, pour
 *   tous les comptes, quel que soit leur rôle.
 * - Les mots de passe d'application (pont MCP, API REST) : non. Ils ne passent
 *   pas par le filtre `authenticate` — `wp_validate_application_password()`
 *   appelle l'authentificateur en direct — et c'est souhaitable : une machine ne
 *   lit pas d'e-mail. Un mot de passe d'application se révoque à part, compte par
 *   compte, et il est déjà compté par la limitation des tentatives.
 * - WP-CLI : non, il n'authentifie personne.
 *
 * ## Si l'e-mail n'arrive pas
 *
 * Le refus est fermé : sans envoi, pas de connexion, et le message le dit. La
 * sortie de secours passe par le serveur, c'est-à-dire par qui y a accès :
 * `define( 'ANODE_2FA_EMAIL', false );` le temps de réparer l'envoi.
 *
 * @package Anode\Hardening
 */

declare( strict_types = 1 );

namespace Anode\Hardening;

defined( 'ABSPATH' ) || exit;

/** Durée de validité d'un code, en secondes. */
const DUREE_CODE = 10 * MINUTE_IN_SECONDS;

/** Codes faux tolérés avant d'annuler l'attente. */
const ESSAIS_CODE = 5;

/** Délai minimal entre deux envois, en secondes. */
const DELAI_RENVOI = 60;

/** Envois tolérés pour une même attente. */
const ENVOIS_MAX = 3;

/** Cookie qui relie le navigateur à son attente. */
const COOKIE_DEUX_FACTEURS = 'anode_2fa';

/** Action de `wp-login.php` qui sert l'écran du code. */
const ACTION_DEUX_FACTEURS = 'anode_2fa';

/**
 * La double authentification est-elle allumée sur ce site ?
 */
function deux_facteurs_actif(): bool {
	return defined( 'ANODE_2FA_EMAIL' ) && true === (bool) constant( 'ANODE_2FA_EMAIL' );
}

/**
 * Un code à six chiffres, tiré par le générateur cryptographique.
 *
 * Les zéros de tête sont gardés : `012345` est un code, et le lire comme un
 * entier ferait tomber la moitié de l'espace à cinq chiffres.
 */
function nouveau_code(): string {
	return str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
}

/**
 * Empreinte d'un code, jamais le code lui-même.
 *
 * La base ne garde que ceci : qui la lit pendant les dix minutes de validité ne
 * peut pas s'en servir. La clé est le sel du site, absent de la base.
 */
function empreinte_code( string $code ): string {
	return hash_hmac( 'sha256', $code, wp_salt( 'auth' ) );
}

/**
 * Clé de transient d'une attente, dérivée du jeton du cookie.
 *
 * Le jeton n'est pas stocké en clair non plus : la base seule ne permet pas de
 * fabriquer le cookie d'une attente en cours.
 */
function cle_attente( string $jeton ): string {
	return 'anode_2fa_' . substr( hash( 'sha256', $jeton ), 0, 40 );
}

/**
 * Juge un code saisi au regard de l'attente.
 *
 * Fonction pure, rendue avec l'attente mise à jour : c'est elle qui décide, et
 * c'est elle que les tests éprouvent.
 *
 * @param array<string, mixed> $attente Attente en cours.
 * @param string               $saisi   Code tapé, espaces compris.
 * @param int                  $maintenant Horodatage courant.
 * @return array{verdict: string, attente: array<string, mixed>} verdict :
 *         `ok`, `faux`, `epuise` (dernier essai faux) ou `expire`.
 */
function juger_code( array $attente, string $saisi, int $maintenant ): array {
	if ( $maintenant > (int) ( $attente['jusqua'] ?? 0 ) ) {
		return [ 'verdict' => 'expire', 'attente' => $attente ];
	}

	// Un code copié depuis l'e-mail arrive parfois avec une espace ou un tiret.
	$saisi = (string) preg_replace( '/\D+/', '', $saisi );

	if ( 6 === strlen( $saisi ) && hash_equals( (string) ( $attente['empreinte'] ?? '' ), empreinte_code( $saisi ) ) ) {
		return [ 'verdict' => 'ok', 'attente' => $attente ];
	}

	$attente['essais'] = (int) ( $attente['essais'] ?? 0 ) + 1;

	return [
		'verdict' => $attente['essais'] >= ESSAIS_CODE ? 'epuise' : 'faux',
		'attente' => $attente,
	];
}

/**
 * Un nouvel envoi est-il permis ?
 *
 * @param array<string, mixed> $attente Attente en cours.
 */
function renvoi_permis( array $attente, int $maintenant ): bool {
	return (int) ( $attente['envois'] ?? 0 ) < ENVOIS_MAX
		&& $maintenant - (int) ( $attente['envoye'] ?? 0 ) >= DELAI_RENVOI;
}

/**
 * Tire un code, l'inscrit dans l'attente et l'envoie.
 *
 * @param array<string, mixed> $attente Attente à compléter.
 * @return array<string, mixed>|null L'attente mise à jour, ou null si l'envoi a échoué.
 */
function envoyer_code( \WP_User $compte, array $attente ): ?array {
	$code = nouveau_code();
	$site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

	$sujet = sprintf(
		/* translators: 1 : nom du site, 2 : code. */
		__( '[%1$s] Votre code de connexion : %2$s', 'anode' ),
		$site,
		$code
	);

	$corps = implode(
		"\n",
		[
			sprintf( __( 'Bonjour %s,', 'anode' ), $compte->display_name ),
			'',
			sprintf( __( 'Voici votre code pour vous connecter à %s :', 'anode' ), $site ),
			'',
			'    ' . $code,
			'',
			sprintf( __( 'Il est valable %d minutes et ne sert qu’une fois.', 'anode' ), (int) ( DUREE_CODE / MINUTE_IN_SECONDS ) ),
			sprintf( __( 'Demande faite depuis l’adresse IP %s.', 'anode' ), client_ip() ),
			'',
			__( 'Si vous n’êtes pas à l’origine de cette demande, quelqu’un connaît votre mot de passe : changez‑le sans attendre.', 'anode' ),
		]
	);

	if ( ! wp_mail( $compte->user_email, $sujet, $corps ) ) {
		return null;
	}

	$maintenant = time();

	return array_merge(
		$attente,
		[
			'empreinte' => empreinte_code( $code ),
			'jusqua'    => $maintenant + DUREE_CODE,
			'envoye'    => $maintenant,
			'envois'    => (int) ( $attente['envois'] ?? 0 ) + 1,
			'essais'    => 0,
		]
	);
}

/**
 * Pose ou retire le cookie de l'attente.
 *
 * `SameSite=Strict` : aucune page tierce ne peut soumettre un code à la place
 * de la personne, ni même faire charger l'écran.
 */
function cookie_attente( string $jeton, int $expiration ): void {
	setcookie(
		COOKIE_DEUX_FACTEURS,
		$jeton,
		[
			'expires'  => $expiration,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Strict',
		]
	);
}

/**
 * Lit l'attente liée au navigateur, s'il y en a une.
 *
 * @return array{0: string, 1: array<string, mixed>}|null Le jeton et l'attente.
 */
function attente_courante(): ?array {
	// Validé par son format, pas par `sanitize_key()` : celle-ci met en
	// minuscules un jeton tiré en casse mixte, et l'attente devient introuvable.
	$jeton = isset( $_COOKIE[ COOKIE_DEUX_FACTEURS ] ) ? (string) wp_unslash( $_COOKIE[ COOKIE_DEUX_FACTEURS ] ) : '';

	if ( 1 !== preg_match( '/^[A-Za-z0-9]{40}$/', $jeton ) ) {
		return null;
	}

	$attente = get_transient( cle_attente( $jeton ) );

	return is_array( $attente ) ? [ $jeton, $attente ] : null;
}

/** Renvoie au formulaire de connexion, avec le motif. */
function retour_connexion( string $motif ): void {
	cookie_attente( '', time() - YEAR_IN_SECONDS );
	wp_safe_redirect( add_query_arg( 'anode_2fa', $motif, wp_login_url() ) );
	exit;
}

/**
 * Intercepte une connexion par mot de passe réussie.
 *
 * Priorité 100 : après tous les authentificateurs du noyau, la limitation des
 * tentatives (21) et le contrôle anti-spam (99). On ne voit donc passer ici
 * qu'un compte dont le mot de passe est **juste** — un échec reste un échec,
 * compté comme avant.
 *
 * `login_init` sert de garde : seule une connexion par `wp-login.php` est
 * concernée. `wp_signon()` appelé ailleurs (une extension, un test) n'est pas
 * détourné vers un écran qu'il ne saurait pas afficher.
 */
add_filter(
	'authenticate',
	static function ( $user, $username ) {
		if ( ! deux_facteurs_actif() || ! $user instanceof \WP_User || empty( $username ) || ! did_action( 'login_init' ) ) {
			return $user;
		}

		$jeton   = wp_generate_password( 40, false );
		$attente = envoyer_code(
			$user,
			[
				'compte'     => $user->ID,
				'memoriser'  => ! empty( $_POST['rememberme'] ),
				'redirection' => isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '',
			]
		);

		if ( null === $attente ) {
			return new \WP_Error(
				'anode_2fa_envoi',
				__( '<strong>Le code de connexion n’a pas pu être envoyé.</strong> Réessayez dans quelques minutes, ou contactez l’administrateur du site.', 'anode' )
			);
		}

		set_transient( cle_attente( $jeton ), $attente, DUREE_CODE );
		cookie_attente( $jeton, time() + DUREE_CODE );

		wp_safe_redirect( add_query_arg( 'action', ACTION_DEUX_FACTEURS, wp_login_url() ) );
		exit;
	},
	100,
	2
);

/**
 * L'écran du code, servi par `wp-login.php?action=anode_2fa`.
 *
 * `wp-login.php` accepte une action inconnue dès qu'un rappel est accroché à
 * `login_form_<action>` : l'écran hérite ainsi de l'habillage de la page de
 * connexion et de son URL masquée, sans gabarit à part.
 */
add_action(
	'login_form_' . ACTION_DEUX_FACTEURS,
	static function (): void {
		if ( ! deux_facteurs_actif() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$courante = attente_courante();

		if ( null === $courante ) {
			retour_connexion( 'expire' );
		}

		[ $jeton, $attente ] = $courante;
		$cle                 = cle_attente( $jeton );
		$compte              = get_userdata( (int) ( $attente['compte'] ?? 0 ) );
		$maintenant          = time();
		$erreur              = '';
		$info                = '';

		if ( ! $compte instanceof \WP_User ) {
			delete_transient( $cle );
			retour_connexion( 'expire' );
		}

		if ( isset( $_GET['renvoyer'] ) ) {
			if ( renvoi_permis( $attente, $maintenant ) ) {
				$nouvelle = envoyer_code( $compte, $attente );

				if ( null === $nouvelle ) {
					$erreur = __( 'Le nouveau code n’a pas pu être envoyé.', 'anode' );
				} else {
					$attente = $nouvelle;
					set_transient( $cle, $attente, DUREE_CODE );
					cookie_attente( $jeton, $maintenant + DUREE_CODE );
					$info = __( 'Un nouveau code vient de vous être envoyé.', 'anode' );
				}
			} else {
				$erreur = __( 'Patientez une minute avant de demander un nouveau code.', 'anode' );
			}
		}

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$saisi    = isset( $_POST['anode_code'] ) ? (string) wp_unslash( $_POST['anode_code'] ) : '';
			$jugement = juger_code( $attente, $saisi, $maintenant );

			switch ( $jugement['verdict'] ) {
				case 'ok':
					delete_transient( $cle );
					cookie_attente( '', $maintenant - YEAR_IN_SECONDS );

					wp_set_auth_cookie( $compte->ID, (bool) $attente['memoriser'], is_ssl() );
					wp_set_current_user( $compte->ID );
					// Les accroches de `wp_login` s'exécutent comme pour une connexion
					// ordinaire — dont celle qui remet le compteur de tentatives à zéro.
					do_action( 'wp_login', $compte->user_login, $compte );

					$voulu = (string) $attente['redirection'];
					$cible = apply_filters( 'login_redirect', '' !== $voulu ? $voulu : admin_url(), $voulu, $compte );
					wp_safe_redirect( '' !== $cible ? $cible : admin_url() );
					exit;

				case 'expire':
					delete_transient( $cle );
					retour_connexion( 'expire' );
					break;

				case 'epuise':
					// Cinq codes faux valent une tentative de forçage : l'adresse est
					// comptée par le même verrou que les mots de passe.
					delete_transient( $cle );
					record_failed_attempt();
					retour_connexion( 'epuise' );
					break;

				default:
					$attente = $jugement['attente'];
					set_transient( $cle, $attente, max( 1, (int) $attente['jusqua'] - $maintenant ) );
					$erreur = sprintf(
						/* translators: %d : essais restants. */
						__( 'Code incorrect. Il vous reste %d essai(s).', 'anode' ),
						ESSAIS_CODE - (int) $attente['essais']
					);
			}
		}

		ecran_code( $compte, $attente, $erreur, $info, $maintenant );
		exit;
	}
);

/**
 * Affiche l'écran du code, dans l'habillage de la page de connexion.
 *
 * @param array<string, mixed> $attente Attente en cours.
 */
function ecran_code( \WP_User $compte, array $attente, string $erreur, string $info, int $maintenant ): void {
	$erreurs = new \WP_Error();

	if ( '' !== $erreur ) {
		$erreurs->add( 'anode_2fa', esc_html( $erreur ) );
		// Sans quoi le message générique du durcissement le remplace.
		$GLOBALS['anode_login_verrouille'] = true;
	}

	if ( '' !== $info ) {
		$erreurs->add( 'anode_2fa_info', esc_html( $info ), 'message' );
	}

	// L'adresse n'est montrée qu'à moitié : l'écran s'affiche avant toute preuve
	// d'identité, il ne doit pas confirmer l'adresse complète du compte.
	[ $local, $domaine ] = array_pad( explode( '@', $compte->user_email, 2 ), 2, '' );
	$masquee             = mb_substr( $local, 0, 2 ) . str_repeat( '•', max( 1, mb_strlen( $local ) - 2 ) ) . '@' . $domaine;

	login_header( __( 'Code de connexion', 'anode' ), '', $erreurs );

	$action = add_query_arg( 'action', ACTION_DEUX_FACTEURS, wp_login_url() );
	?>
	<form name="anode-2fa" id="loginform" action="<?php echo esc_url( $action ); ?>" method="post" autocomplete="off">
		<p class="message">
			<?php
			printf(
				/* translators: %s : adresse e-mail masquée. */
				esc_html__( 'Un code à six chiffres vient d’être envoyé à %s. Saisissez‑le pour terminer la connexion.', 'anode' ),
				'<strong>' . esc_html( $masquee ) . '</strong>'
			);
			?>
		</p>
		<p>
			<label for="anode_code"><?php esc_html_e( 'Code de connexion', 'anode' ); ?></label>
			<input type="text" name="anode_code" id="anode_code" class="input" inputmode="numeric" pattern="[0-9 ]*" maxlength="9" autocomplete="one-time-code" required autofocus style="letter-spacing:0.3em;text-align:center;font-size:24px">
		</p>
		<p class="submit">
			<input type="submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Se connecter', 'anode' ); ?>">
		</p>
	</form>
	<p id="nav">
		<?php if ( renvoi_permis( $attente, $maintenant ) ) : ?>
			<a href="<?php echo esc_url( add_query_arg( [ 'action' => ACTION_DEUX_FACTEURS, 'renvoyer' => 1 ], wp_login_url() ) ); ?>"><?php esc_html_e( 'Recevoir un nouveau code', 'anode' ); ?></a> |
		<?php endif; ?>
		<a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Recommencer la connexion', 'anode' ); ?></a>
	</p>
	<?php
	login_footer( 'anode_code' );
}

/**
 * Explique, sur le formulaire de connexion, pourquoi on y est revenu.
 */
add_filter(
	'wp_login_errors',
	static function ( $errors ) {
		if ( $errors instanceof \WP_Error && in_array( 'anode_2fa_envoi', $errors->get_error_codes(), true ) ) {
			$GLOBALS['anode_login_verrouille'] = true;
		}

		$motif = isset( $_GET['anode_2fa'] ) ? sanitize_key( wp_unslash( $_GET['anode_2fa'] ) ) : '';

		if ( ! $errors instanceof \WP_Error || '' === $motif ) {
			return $errors;
		}

		$messages = [
			'expire' => __( 'Le code de connexion a expiré. Reconnectez‑vous pour en recevoir un nouveau.', 'anode' ),
			'epuise' => __( 'Trop de codes incorrects. Reconnectez‑vous pour en recevoir un nouveau.', 'anode' ),
		];

		if ( isset( $messages[ $motif ] ) ) {
			$errors->add( 'anode_2fa_' . $motif, esc_html( $messages[ $motif ] ) );
			// Le message générique « identifiant ou mot de passe incorrect » ne doit
			// pas le recouvrir.
			$GLOBALS['anode_login_verrouille'] = true;
		}

		return $errors;
	}
);
