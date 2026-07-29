<?php
/**
 * Quand la vérification a lieu, et ce qu'elle déclenche.
 *
 * Rien ne se fait au chargement du mu-plugin : à ce moment-là, ni l'API HTTP ni
 * le système de fichiers de WordPress ne sont disponibles, et surtout personne
 * n'attend qu'une page publique parte interroger GitHub. Tout passe par WP-Cron.
 *
 * @package Anode\Updater
 */

declare( strict_types = 1 );

namespace Anode\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Planificateur {

	public static function demarrer(): void {
		if ( defined( 'ANODE_UPDATER' ) && ! constant( 'ANODE_UPDATER' ) ) {
			return;
		}

		add_action( 'init', [ self::class, 'planifier' ] );
		add_action( Updater::CRON, [ self::class, 'executer' ] );

		// Déclenchement à la demande, depuis un autre mu-plugin ou le pont REST.
		add_action( 'anode/updater/verifier', [ self::class, 'executer' ] );
		add_action( 'anode/updater/appliquer', [ self::class, 'appliquer_en_attente' ] );

		add_action( 'admin_notices', [ self::class, 'annoncer' ] );
	}

	/** Une vérification par jour, décalée pour ne pas mordre sur les heures de bureau. */
	public static function planifier(): void {
		if ( wp_next_scheduled( Updater::CRON ) ) {
			return;
		}

		wp_schedule_event( strtotime( 'tomorrow 4:00' ), 'daily', Updater::CRON );
	}

	/**
	 * Compare chaque composant à son dépôt, et applique ce qui est autorisé.
	 *
	 * @return array État de la vérification, tel qu'enregistré.
	 */
	public static function executer(): array {
		$etat = [
			'verifie_le' => gmdate( 'c' ),
			'composants' => [],
		];

		foreach ( Updater::composants() as $nom => $composant ) {
			$etat['composants'][ $nom ] = self::examiner( $nom, $composant );
		}

		update_site_option( Updater::OPTION_ETAT, $etat );

		return $etat;
	}

	/**
	 * Examine un composant, et l'installe si son palier l'autorise.
	 *
	 * @return array
	 */
	private static function examiner( string $nom, array $composant ): array {
		$installee = Installateur::version_installee( $nom );

		if ( null === $installee ) {
			return [ 'etat' => 'absent', 'message' => 'Composant non installé sur ce site.' ];
		}

		$releases = Source::releases( (string) $composant['repo'] );

		if ( is_wp_error( $releases ) ) {
			// Un dépôt qui n'existe pas encore n'est pas une panne : c'est un
			// composant pas encore publié. On le note sans alerter l'admin d'un
			// site en production, qui n'y peut rien.
			return [
				'etat'      => 'anode_updater_depot' === $releases->get_error_code() ? 'depot-absent' : 'erreur',
				'installee' => $installee,
				'message'   => $releases->get_error_message(),
			];
		}

		$derniere = Updater::derniere_release( $releases, Updater::prefixe_tag( $composant ) );

		if ( null === $derniere ) {
			return [
				'etat'      => 'aucune-release',
				'installee' => $installee,
				'message'   => sprintf( 'Aucune release publiée pour %s dans %s.', $nom, $composant['repo'] ),
			];
		}

		$publiee = $derniere['version'];

		if ( version_compare( $publiee, $installee, '<=' ) ) {
			return [ 'etat' => 'a-jour', 'installee' => $installee, 'publiee' => $publiee ];
		}

		$palier = Updater::palier( $installee, $publiee );

		if ( ! Updater::auto_autorise( $palier ) ) {
			return [
				'etat'      => 'en-attente',
				'installee' => $installee,
				'publiee'   => $publiee,
				'palier'    => $palier,
				'message'   => sprintf(
					'Version %s disponible (%s). Elle attend une décision : ANODE_UPDATER_AUTO ne l’autorise pas.',
					$publiee,
					$palier
				),
			];
		}

		$resultat = Installateur::installer( $nom, $composant, $derniere['release'], $publiee );

		if ( is_wp_error( $resultat ) ) {
			return [
				'etat'      => 'echec',
				'installee' => $installee,
				'publiee'   => $publiee,
				'palier'    => $palier,
				'message'   => $resultat->get_error_message(),
			];
		}

		return [
			'etat'      => 'installee',
			'installee' => $publiee,
			'depuis'    => $installee,
			'palier'    => $palier,
		];
	}

	/**
	 * Installe ce qui attendait une décision — majeures comprises.
	 *
	 * Appelé explicitement : `do_action( 'anode/updater/appliquer' )`.
	 */
	public static function appliquer_en_attente(): array {
		$etat    = (array) get_site_option( Updater::OPTION_ETAT, [] );
		$attente = array_keys(
			array_filter(
				(array) ( $etat['composants'] ?? [] ),
				static fn( $ligne ): bool => 'en-attente' === ( $ligne['etat'] ?? '' )
			)
		);

		if ( ! $attente ) {
			return $etat;
		}

		$composants = Updater::composants();

		foreach ( $attente as $nom ) {
			if ( ! isset( $composants[ $nom ] ) ) {
				continue;
			}

			Source::oublier( (string) $composants[ $nom ]['repo'] );

			$releases = Source::releases( (string) $composants[ $nom ]['repo'] );

			if ( is_wp_error( $releases ) ) {
				continue;
			}

			$derniere = Updater::derniere_release( $releases, Updater::prefixe_tag( $composants[ $nom ] ) );

			if ( null === $derniere ) {
				continue;
			}

			$resultat = Installateur::installer( $nom, $composants[ $nom ], $derniere['release'], $derniere['version'] );

			$etat['composants'][ $nom ] = is_wp_error( $resultat )
				? [ 'etat' => 'echec', 'publiee' => $derniere['version'], 'message' => $resultat->get_error_message() ]
				: [ 'etat' => 'installee', 'installee' => $derniere['version'] ];
		}

		update_site_option( Updater::OPTION_ETAT, $etat );

		return $etat;
	}

	/**
	 * Signale dans l'administration ce qui attend, ou ce qui a échoué.
	 *
	 * Un correctif appliqué seul n'a pas à être annoncé : c'est le fonctionnement
	 * normal. Ce qui mérite l'attention, c'est ce qui n'a pas pu se faire.
	 */
	public static function annoncer(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$etat     = (array) get_site_option( Updater::OPTION_ETAT, [] );
		$messages = [];

		foreach ( (array) ( $etat['composants'] ?? [] ) as $nom => $ligne ) {
			if ( in_array( $ligne['etat'] ?? '', [ 'en-attente', 'echec', 'erreur' ], true ) ) {
				$messages[] = sprintf( '%s — %s', $nom, (string) ( $ligne['message'] ?? '' ) );
			}
		}

		if ( ! $messages ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>Mises à jour Anode</strong></p><ul style="margin-left:1.5em;list-style:disc"><li>%s</li></ul></div>',
			implode( '</li><li>', array_map( 'esc_html', $messages ) )
		);
	}
}
