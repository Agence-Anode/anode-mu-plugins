<?php
/**
 * Vérifie qu'une archive a bien été signée par la clé de l'agence.
 *
 * Les autres contrôles portent tous sur la cohérence de la release avec
 * elle-même : l'archive correspond à son empreinte, son en-tête à son tag. Tout
 * cela sort du même compte GitHub. Un compte compromis publie l'archive **et**
 * l'empreinte, et les cinq contrôles passent sans broncher.
 *
 * La signature est le seul maillon produit ailleurs : la clé privée reste sur le
 * poste qui publie, elle ne monte jamais sur GitHub, et rien dans le dépôt ne
 * permet de la reconstituer.
 *
 * Ed25519 plutôt qu'autre chose parce que c'est ce que WordPress lui-même
 * emploie pour ses paquets de mise à jour, et surtout parce que la fonction de
 * vérification est **toujours** là : `wp-includes/compat.php` charge
 * `sodium_compat` quand l'extension manque. Aucun site ne se retrouve donc dans
 * l'état « clé posée, vérification impossible ».
 *
 * @package Anode\Updater
 */

declare( strict_types = 1 );

namespace Anode\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Signature {

	/**
	 * Tailles brutes d'Ed25519, en octets.
	 *
	 * Écrites ici plutôt que reprises de `SODIUM_CRYPTO_SIGN_*` : ces constantes
	 * n'existent que si l'extension — ou son remplaçant — est déjà chargée, et
	 * une constante absente est une fatale, pas un refus propre.
	 */
	private const TAILLE_SIGNATURE = 64;
	private const TAILLE_CLE       = 32;

	/**
	 * Vérifie une signature détachée sur des données en mémoire.
	 *
	 * @param string $donnees   Le contenu signé — ici, l'archive entière.
	 * @param string $signature Signature détachée, en base64.
	 * @param string $cle       Clé publique brute, en base64.
	 * @return string|null Motif du refus, ou null si la signature est bonne.
	 */
	public static function verifier_donnees( string $donnees, string $signature, string $cle ): ?string {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return 'ce site ne sait pas vérifier une signature Ed25519 (ni l’extension sodium, ni le remplaçant livré avec WordPress)';
		}

		$brute = self::decoder( $signature, self::TAILLE_SIGNATURE );

		if ( null === $brute ) {
			return 'signature illisible : ce n’est pas une signature Ed25519 en base64';
		}

		$publique = self::decoder( $cle, self::TAILLE_CLE );

		if ( null === $publique ) {
			return 'clé publique illisible : ce n’est pas une clé Ed25519 en base64 (voir ANODE_UPDATER_CLE)';
		}

		try {
			$valide = sodium_crypto_sign_verify_detached( $brute, $donnees, $publique );
		} catch ( \Throwable $erreur ) {
			// Une exception vaut refus. La laisser remonter depuis le cron
			// interromprait la passe, et les composants suivants ne seraient plus
			// examinés du tout.
			return sprintf( 'vérification impossible : %s', $erreur->getMessage() );
		}

		return $valide ? null : 'la signature ne correspond pas à cette archive ni à cette clé';
	}

	/**
	 * Même chose, sur un fichier.
	 *
	 * Les archives pèsent quelques dizaines de kilo-octets : les charger en
	 * mémoire ne pose pas de question, et Ed25519 ne sait pas signer par flux.
	 *
	 * @return string|null Motif du refus, ou null.
	 */
	public static function verifier_fichier( string $chemin, string $signature, string $cle ): ?string {
		$donnees = is_readable( $chemin ) ? file_get_contents( $chemin ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $donnees ) {
			return sprintf( 'archive illisible pour la vérification : %s', $chemin );
		}

		return self::verifier_donnees( $donnees, $signature, $cle );
	}

	/**
	 * Décode une valeur base64 et exige sa taille exacte.
	 *
	 * Le décodage est **strict** : sans cela, `base64_decode()` ignore les
	 * caractères qu'il ne connaît pas et rend un résultat pour à peu près
	 * n'importe quoi — un fichier HTML d'erreur téléchargé à la place de la
	 * signature passerait pour une signature simplement fausse, au lieu d'un
	 * fichier illisible.
	 */
	private static function decoder( string $valeur, int $taille ): ?string {
		$brut = base64_decode( trim( $valeur ), true );

		return is_string( $brut ) && strlen( $brut ) === $taille ? $brut : null;
	}
}
