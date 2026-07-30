<?php
/**
 * Le site s'appelle lui-même pour savoir s'il tient encore debout.
 *
 * Tous les contrôles de l'installateur portent sur l'archive. Aucun ne dit si le
 * composant **se charge** : un `php -l` valide un fichier qui appelle une
 * fonction absente, et un en-tête `Requires PHP` ne dit rien d'un `use` vers une
 * classe qui n'a pas été livrée. Or un mu-plugin ne se désactive pas — la fatale
 * emporte le site public et l'administration d'un coup, et personne ne peut plus
 * s'y connecter pour la défaire.
 *
 * ## Ce que la sonde sait, et ce qu'elle ne sait pas
 *
 * Elle demande une page au site, avec une clé à usage unique. Si le composant
 * répond, la page rend un marqueur ; sinon, PHP rend 500. C'est un vrai
 * chargement, dans un vrai processus, avec la vraie configuration — ce qu'aucun
 * contrôle statique ne remplace.
 *
 * Ce qu'elle ne sait pas trancher, elle le dit : un site derrière un htpasswd
 * répond 401, un site dont la boucle locale est coupée ne répond pas du tout, un
 * cache de page peut servir une copie sans jamais atteindre PHP. **Aucun de ces
 * cas ne déclenche de retour arrière** : reculer sur une preuve douteuse
 * défaisait chaque nuit une mise à jour parfaitement saine.
 *
 * Et le 500 n'est retenu que s'il est **nouveau** : une passe est faite avant la
 * bascule, sur le site tel qu'il était. Un site déjà en erreur ne fait pas
 * accuser la version qu'on vient d'installer.
 *
 * `ANODE_UPDATER_SONDE` à `false` la coupe — utile là où la boucle locale est
 * fermée, pour ne pas payer trente secondes d'attente à chaque installation en
 * échange d'un verdict qui ne conclura jamais.
 *
 * @package Anode\Updater
 */

declare( strict_types = 1 );

namespace Anode\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sonde {

	/** Paramètre d'URL qui demande la réponse de sonde. */
	private const PARAM = 'anode_updater_sonde';

	/** Préfixe du transient qui porte le jeton attendu. */
	private const PREFIXE = 'anode_updater_sonde_';

	/** Tête du corps de réponse, pour ne pas confondre avec une page ordinaire. */
	private const MARQUEUR = 'anode-updater-sonde:';

	/** Durée de vie du jeton. Une installation dure quelques secondes. */
	private const VIE = 120;

	/** Attente maximale de la réponse. */
	private const ATTENTE = 30;

	/**
	 * Branche la réponse de sonde, si et seulement si on la demande.
	 *
	 * Le test sur `$_GET` est fait ici plutôt que dans le crochet : sans lui,
	 * chaque page du site paierait un `add_action` et un appel de fonction pour
	 * rien.
	 */
	public static function ecouter(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return;
		}

		/*
		 * `wp_loaded` plutôt que `muplugins_loaded` : à ce point, les mu-plugins,
		 * les extensions, le thème et tous les `init` ont tourné. Une fatale
		 * déclenchée depuis un crochet `init` — le cas le plus courant, et celui
		 * qu'aucun contrôle statique ne voit — est donc dans le périmètre.
		 */
		add_action( 'wp_loaded', [ self::class, 'repondre' ], PHP_INT_MAX );
	}

	/** Répond le marqueur attendu, et rien d'autre. */
	public static function repondre(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cle = (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $_GET[ self::PARAM ] ?? '' ) );

		if ( '' === $cle ) {
			return;
		}

		$jeton = get_site_transient( self::PREFIXE . $cle );

		// Une clé inconnue laisse la page suivre son cours : c'est ce que verrait
		// un curieux qui rejoue l'URL, et cela n'apprend rien sur le site.
		if ( ! is_string( $jeton ) || '' === $jeton ) {
			return;
		}

		// À usage unique : l'URL de sonde se retrouve dans les journaux d'accès du
		// serveur, et n'a aucune raison d'y rester rejouable.
		delete_site_transient( self::PREFIXE . $cle );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );

		echo self::MARQUEUR . $jeton; // phpcs:ignore WordPress.Security.EscapeOutput

		exit;
	}

	/**
	 * Appelle le site et rend son verdict.
	 *
	 * @return array{etat: string, message: string} `ok`, `echec` ou `indetermine`.
	 */
	public static function verifier( bool $frais = false ): array {
		/*
		 * Un seul appel par requête, quel que soit le nombre de composants.
		 *
		 * La sonde est appelée avant **chaque** bascule pour relever l'état de
		 * départ — or cet état est le même pour toute la passe. Sur un site dont
		 * la boucle locale est fermée, chaque appel attend son délai : cinq
		 * composants, c'est jusqu'à deux minutes et demie d'attente dans une seule
		 * requête de cron, en plus des téléchargements. Le relevé est donc mémorisé.
		 *
		 * `$frais` force un nouveau relevé : c'est ce qu'attend la vérification
		 * **après** installation, qui doit voir l'effet de ce qu'on vient d'écrire.
		 */
		static $connu = null;

		if ( ! $frais && null !== $connu ) {
			return $connu;
		}

		if ( defined( 'ANODE_UPDATER_SONDE' ) && ! constant( 'ANODE_UPDATER_SONDE' ) ) {
			$connu = [ 'etat' => 'indetermine', 'message' => 'Sonde désactivée sur ce site (ANODE_UPDATER_SONDE).' ];

			return $connu;
		}

		$cle   = wp_generate_password( 12, false, false );
		$jeton = wp_generate_password( 24, false, false );

		set_site_transient( self::PREFIXE . $cle, $jeton, self::VIE );

		$reponse = wp_remote_get(
			add_query_arg( self::PARAM, $cle, home_url( '/' ) ),
			[
				'timeout'     => self::ATTENTE,
				/*
				 * Les redirections sont suivies, et cela ne coûte rien : le marqueur
				 * porte un jeton tiré à l'instant, donc aucune page atteinte par
				 * ricochet ne peut se faire passer pour la bonne. Les refuser, en
				 * revanche, rendait la sonde muette sur tout site qui redirige sa
				 * page d'accueil — canonique www, bascule vers HTTPS — c'est-à-dire
				 * sur la plupart des sites en production.
				 */
				'redirection' => 3,
				// Un certificat local n'a pas à décider d'un retour arrière.
				'sslverify'   => false,
				'headers'     => [ 'Cache-Control' => 'no-cache' ],
			]
		);

		delete_site_transient( self::PREFIXE . $cle );

		$connu = self::juger(
			is_wp_error( $reponse ) ? $reponse->get_error_message() : null,
			is_wp_error( $reponse ) ? 0 : (int) wp_remote_retrieve_response_code( $reponse ),
			is_wp_error( $reponse ) ? '' : (string) wp_remote_retrieve_body( $reponse ),
			self::MARQUEUR . $jeton
		);

		return $connu;
	}

	/**
	 * Le verdict, à partir de la seule réponse — sans réseau, donc éprouvable.
	 *
	 * Trois issues et pas deux : la troisième, « indéterminé », est celle qui
	 * évite les dégâts. Confondre « je n'ai pas pu savoir » avec « c'est cassé »
	 * ferait reculer chaque nuit les sites derrière un htpasswd, et confondre
	 * « je n'ai pas pu savoir » avec « tout va bien » laisserait un site à terre.
	 *
	 * Seul **500** vaut échec : c'est ce que rend PHP sur une fatale. 502, 503 et
	 * 504 viennent d'un intermédiaire — maintenance de WordPress, redémarrage de
	 * PHP-FPM, passerelle lente — et disent quelque chose sur l'hébergement, pas
	 * sur le code qu'on vient d'écrire.
	 *
	 * @return array{etat: string, message: string}
	 */
	public static function juger( ?string $erreur, int $code, string $corps, string $attendu ): array {
		if ( null !== $erreur ) {
			return [
				'etat'    => 'indetermine',
				'message' => sprintf( 'Le site n’a pas pu être appelé (%s) : la sonde ne conclut pas.', $erreur ),
			];
		}

		if ( 200 === $code && str_contains( $corps, $attendu ) ) {
			return [ 'etat' => 'ok', 'message' => '' ];
		}

		if ( 500 === $code ) {
			return [ 'etat' => 'echec', 'message' => 'Le site répond 500 : le chargement provoque une erreur fatale.' ];
		}

		if ( in_array( $code, [ 401, 403, 407 ], true ) ) {
			return [
				'etat'    => 'indetermine',
				'message' => sprintf( 'Le site répond %d : une protection d’accès empêche la sonde de conclure.', $code ),
			];
		}

		return [
			'etat'    => 'indetermine',
			'message' => sprintf( 'Réponse %d sans le marqueur de la sonde : la sonde ne conclut pas.', $code ),
		];
	}
}
