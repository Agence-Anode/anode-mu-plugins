<?php
/**
 * L'écran des mises à jour, dans le back-office.
 *
 * ## Pourquoi cet écran existe
 *
 * Le canal fonctionnait déjà : correctifs et versions mineures s'installaient
 * seuls, une majeure attendait une décision. Mais cette décision n'avait aucun
 * endroit où se prendre — elle passait par l'action `anode/updater/appliquer`,
 * qu'aucune route REST n'exposait. Le seul geste du canal qui demande un humain
 * était donc le seul qu'un humain ne pouvait pas faire.
 *
 * Et un site se vend. Le client garde ses mises à jour, il doit donc pouvoir :
 *
 *   • voir ce qui l'attend, et **ce que ça change** — pas seulement un numéro ;
 *   • l'installer quand il le décide ;
 *   • couper l'automatique, ou en choisir le plafond.
 *
 * Rien de tout cela ne demande de nous appeler, et c'est le but.
 *
 * ## Ce que l'écran ne fait pas
 *
 * Il n'installe pas une majeure « en un clic parce que c'est écrit en gros ». Le
 * détail de la version est affiché **avant** le bouton, et le bouton dit ce qu'il
 * fait. Un canal qui rend l'installation plus facile que la lecture produit des
 * sites cassés le vendredi soir.
 *
 * @package Anode\Updater
 */

declare(strict_types=1);

namespace Anode\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * L'écran de réglage et d'action.
 */
final class Ecran {

	/** Identifiant de la page, et de son nonce. */
	private const PAGE = 'anode-updater';

	public static function demarrer(): void {
		add_action( 'admin_menu', [ self::class, 'declarer' ] );
		add_action( 'admin_post_anode_updater_action', [ self::class, 'traiter' ] );
	}

	/**
	 * Sous « Outils », et non dans un menu de premier niveau.
	 *
	 * Un client ouvre son administration pour écrire, pas pour administrer des
	 * composants. Un menu de premier niveau réclamerait son attention chaque jour
	 * pour un geste qu'il fait trois fois par an.
	 */
	public static function declarer(): void {
		add_management_page(
			__( 'Mises à jour du site', 'anode' ),
			__( 'Mises à jour du site', 'anode' ),
			'manage_options',
			self::PAGE,
			[ self::class, 'rendre' ]
		);
	}

	/**
	 * Le palier automatique en vigueur, et d'où il vient.
	 *
	 * L'option d'abord, la constante ensuite. L'interrupteur de cet écran est
	 * l'endroit où le client décide, et c'est la réponse à « où est-ce que je
	 * change ça ». La constante reste : elle sert à verrouiller un site depuis
	 * `wp-config.php`, et elle passe alors second — un réglage posé à la main dans
	 * l'écran ne doit pas être défait au prochain déploiement.
	 *
	 * @return array{palier: string|false, source: string}
	 */
	public static function reglage_auto(): array {
		$option = get_site_option( Updater::OPTION_AUTO, null );

		if ( null !== $option ) {
			return [ 'palier' => 'jamais' === $option ? false : (string) $option, 'source' => 'cet écran' ];
		}

		if ( defined( 'ANODE_UPDATER_AUTO' ) ) {
			return [ 'palier' => constant( 'ANODE_UPDATER_AUTO' ), 'source' => 'wp-config.php' ];
		}

		return [ 'palier' => 'mineure', 'source' => 'valeur par défaut' ];
	}

	/**
	 * Les trois actions de l'écran, chacune derrière son nonce.
	 */
	public static function traiter(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n’avez pas les droits nécessaires.', 'anode' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::PAGE );

		$quoi    = isset( $_POST['quoi'] ) ? sanitize_key( (string) wp_unslash( $_POST['quoi'] ) ) : '';
		$message = '';

		switch ( $quoi ) {
			case 'verifier':
				Planificateur::executer( true );
				$message = 'verifie';

				break;

			case 'installer':
				/*
				 * `appliquer_en_attente()` installe **ce qui attend**, y compris une
				 * majeure : c'est l'action que le canal réservait à une décision, et
				 * ce bouton EST cette décision.
				 */
				$fait = Planificateur::appliquer_en_attente();
				$message = $fait ? 'installe' : 'rien';

				break;

			case 'auto':
				$palier = isset( $_POST['palier'] ) ? sanitize_key( (string) wp_unslash( $_POST['palier'] ) ) : 'mineure';

				if ( ! in_array( $palier, array_merge( Updater::PALIERS, [ 'jamais' ] ), true ) ) {
					$palier = 'mineure';
				}

				update_site_option( Updater::OPTION_AUTO, $palier );
				$message = 'reglage';

				break;
		}

		wp_safe_redirect( add_query_arg(
			[ 'page' => self::PAGE, 'fait' => $message ],
			admin_url( 'tools.php' )
		) );
		exit;
	}

	/**
	 * Ce qu'on peut honnêtement dire d'un composant qui n'attend rien.
	 *
	 * L'écran répondait « à jour » à tout ce qui n'était pas « en attente ». C'est
	 * la formulation la plus rassurante possible, et elle était rendue sur une
	 * ignorance : un dépôt injoignable, un quota épuisé, une passe jamais lancée
	 * s'affichaient tous les trois « à jour ». C'est le client qui le lit, et rien
	 * d'autre dans l'écran ne le contredisait.
	 *
	 * « À jour » ne se dit donc que d'une comparaison qui a réellement eu lieu.
	 */
	private static function mot_de_l_etat( string $etat, string $installee ): string {
		switch ( $etat ) {
			case 'a-jour':
			case 'installee':
				return __( 'à jour', 'anode' );

			case 'quota':
				/*
				 * Sans jeton, GitHub accorde soixante appels par heure et par IP, que les
				 * sites d'un même hébergement partagent. Rien à faire : la passe suivante
				 * repartira seule. Le dire évite un appel au support pour une attente.
				 */
				return __( 'vérification remise à plus tard (limite horaire de GitHub) — rien à faire', 'anode' );

			case 'depot-absent':
				return __( 'aucune version publiée pour ce composant', 'anode' );

			case 'erreur':
				return __( 'la source n’a pas pu être interrogée', 'anode' );

			case 'echec':
				return __( 'la dernière installation a échoué', 'anode' );

			case 'aucune-release':
				return __( 'aucune version publiée pour ce composant', 'anode' );
		}

		return '—' === $installee
			? __( 'pas installé sur ce site', 'anode' )
			: __( 'jamais vérifié — utiliser le bouton ci-dessous', 'anode' );
	}

	/**
	 * Le détail d'une version : ce que le numéro ne dit pas.
	 *
	 * C'est la moitié utile de l'écran. « 2.3.0 est disponible » n'aide personne à
	 * décider ; « corrige la boîte de mot de passe qui se rouvrait sur les pages
	 * de formulaire » se lit et se décide.
	 */
	private static function notes( string $repo, string $version ): string {
		$releases = Source::releases( $repo );

		if ( is_wp_error( $releases ) ) {
			return '';
		}

		foreach ( $releases as $release ) {
			$tag = (string) ( $release['tag_name'] ?? '' );

			if ( str_contains( $tag, $version ) ) {
				return (string) ( $release['body'] ?? '' );
			}
		}

		return '';
	}

	public static function rendre(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		/*
		 * L'option porte `{ verifie_le, composants: { <nom> => ligne } }` : les lignes
		 * vivent sous « composants », pas à la racine.
		 *
		 * L'écran indexait `$etat[ $nom ]` — un niveau trop haut. Chaque ligne était
		 * donc vide, aucune n'était jamais « en attente », et **le bouton d'installation
		 * n'apparaissait jamais**, quel que soit l'état réel du canal. La vérification
		 * d'origine avait mesuré que le bouton existait dans le HTML rendu — pas qu'il
		 * s'affichait quand il le fallait. Mesurer la présence n'est pas mesurer la
		 * justesse.
		 */
		$etat     = (array) ( ( (array) get_site_option( Updater::OPTION_ETAT, [] ) )['composants'] ?? [] );
		$journal  = (array) get_site_option( Updater::OPTION_JOURNAL, [] );
		$auto     = self::reglage_auto();
		$manifeste = Updater::manifeste();
		$fait     = isset( $_GET['fait'] ) ? sanitize_key( (string) wp_unslash( $_GET['fait'] ) ) : '';

		$avis = [
			'verifie'  => __( 'Vérification faite.', 'anode' ),
			'installe' => __( 'Mises à jour installées.', 'anode' ),
			'rien'     => __( 'Rien n’attendait d’être installé.', 'anode' ),
			'reglage'  => __( 'Réglage enregistré.', 'anode' ),
		];

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Mises à jour du site', 'anode' ) . '</h1>';

		if ( isset( $avis[ $fait ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $avis[ $fait ] ) . '</p></div>';
		}

		echo '<p class="description">'
			. esc_html__(
				'Les composants ci-dessous font fonctionner ce site : référencement, formulaires, sécurité, animations. Ils se mettent à jour depuis leur source, sans passer par le répertoire de WordPress.',
				'anode'
			)
			. '</p>';

		/* --- Ce qui attend ------------------------------------------------ */

		$attendent = array_filter( $etat, static fn ( $e ): bool => 'en-attente' === ( $e['etat'] ?? '' ) );

		echo '<h2>' . esc_html__( 'Composants', 'anode' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Composant', 'anode' ) . '</th>';
		echo '<th>' . esc_html__( 'Installée', 'anode' ) . '</th>';
		echo '<th>' . esc_html__( 'Disponible', 'anode' ) . '</th>';
		echo '<th>' . esc_html__( 'Ce que la version change', 'anode' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $manifeste as $nom => $entree ) {
			$ligne     = (array) ( $etat[ $nom ] ?? [] );
			$installee = (string) ( $ligne['installee'] ?? Installateur::version_installee( $nom ) ?? '—' );
			$publiee   = (string) ( $ligne['publiee'] ?? '—' );
			$enAttente = 'en-attente' === ( $ligne['etat'] ?? '' );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $nom ) . '</strong>';

			if ( $enAttente ) {
				echo ' <span class="dashicons dashicons-update" aria-hidden="true"></span>';
				echo '<br><em>' . esc_html( sprintf(
					/* translators: %s : correctif, mineure, majeure ou nouveau. */
					__( 'palier : %s', 'anode' ),
					(string) ( $ligne['palier'] ?? '?' )
				) ) . '</em>';
			}

			echo '</td>';
			echo '<td>' . esc_html( $installee ) . '</td>';
			echo '<td>' . esc_html( $publiee ) . '</td>';
			echo '<td>';

			if ( $enAttente && '—' !== $publiee ) {
				$notes = self::notes( (string) ( $entree['repo'] ?? '' ), $publiee );

				if ( '' !== $notes ) {
					echo '<details><summary>' . esc_html__( 'Lire le détail', 'anode' ) . '</summary>';
					echo '<pre style="white-space:pre-wrap;margin:.5em 0 0">' . esc_html( $notes ) . '</pre>';
					echo '</details>';
				} else {
					echo '<span class="description">' . esc_html__( 'aucun détail publié pour cette version', 'anode' ) . '</span>';
				}
			} else {
				/*
				 * « à jour » ne se dit que d'un composant qu'on a réellement pu
				 * comparer. Un dépôt injoignable, un quota épuisé, une passe jamais
				 * lancée : dans ces trois cas on ne sait pas, et l'écran le disait « à
				 * jour » — la formulation la plus rassurante possible, rendue sur une
				 * ignorance. C'est le client qui la lit.
				 */
				echo '<span class="description">' . esc_html( self::mot_de_l_etat( (string) ( $ligne['etat'] ?? '' ), $installee ) ) . '</span>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		/* --- Les deux boutons --------------------------------------------- */

		echo '<p>';
		self::bouton( 'verifier', __( 'Chercher des mises à jour', 'anode' ), 'button' );

		if ( $attendent ) {
			self::bouton(
				'installer',
				sprintf(
					/* translators: %d : nombre de composants. */
					_n( 'Installer la mise à jour (%d composant)', 'Installer les mises à jour (%d composants)', count( $attendent ), 'anode' ),
					count( $attendent )
				),
				'button button-primary'
			);
		}

		echo '</p>';

		if ( ! $attendent ) {
			echo '<p class="description">' . esc_html__( 'Aucune mise à jour n’attend. Le site est à jour.', 'anode' ) . '</p>';
		}

		/* --- L'interrupteur ----------------------------------------------- */

		echo '<h2>' . esc_html__( 'Mises à jour automatiques', 'anode' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::PAGE );
		echo '<input type="hidden" name="action" value="anode_updater_action">';
		echo '<input type="hidden" name="quoi" value="auto">';

		$courant = false === $auto['palier'] ? 'jamais' : (string) $auto['palier'];
		$choix   = [
			'jamais'    => __( 'Jamais — je décide de chaque installation', 'anode' ),
			'correctif' => __( 'Correctifs seulement (1.2.3)', 'anode' ),
			'mineure'   => __( 'Correctifs et versions mineures (1.3.0) — recommandé', 'anode' ),
			'majeure'   => __( 'Tout, y compris les versions majeures (2.0.0)', 'anode' ),
		];

		echo '<fieldset>';

		foreach ( $choix as $valeur => $libelle ) {
			printf(
				'<label style="display:block;margin:.35em 0"><input type="radio" name="palier" value="%s"%s> %s</label>',
				esc_attr( $valeur ),
				checked( $courant, $valeur, false ),
				esc_html( $libelle )
			);
		}

		echo '</fieldset>';

		echo '<p class="description">' . esc_html( sprintf(
			/* translators: %s : cet écran, wp-config.php, ou valeur par défaut. */
			__( 'Réglage actuel défini par : %s.', 'anode' ),
			(string) $auto['source']
		) ) . '</p>';

		echo '<p class="description">'
			. esc_html__(
				'Une version majeure change un comportement : elle attend toujours une décision, même en automatique, sauf si vous choisissez la dernière option.',
				'anode'
			)
			. '</p>';

		submit_button( __( 'Enregistrer le réglage', 'anode' ) );
		echo '</form>';

		/* --- Le journal --------------------------------------------------- */

		if ( $journal ) {
			echo '<h2>' . esc_html__( 'Dernières vérifications', 'anode' ) . '</h2>';
			echo '<table class="widefat striped"><tbody>';

			foreach ( array_slice( array_reverse( $journal ), 0, 12 ) as $entree ) {
				printf(
					'<tr><td style="width:12em">%s</td><td>%s</td></tr>',
					esc_html( (string) ( $entree['date'] ?? '' ) ),
					esc_html( (string) ( $entree['message'] ?? '' ) )
				);
			}

			echo '</tbody></table>';
		}

		echo '</div>';
	}

	/** Un bouton d'action, avec son nonce — jamais un simple lien. */
	private static function bouton( string $quoi, string $libelle, string $classe ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:.5em">';
		wp_nonce_field( self::PAGE );
		echo '<input type="hidden" name="action" value="anode_updater_action">';
		echo '<input type="hidden" name="quoi" value="' . esc_attr( $quoi ) . '">';
		echo '<button type="submit" class="' . esc_attr( $classe ) . '">' . esc_html( $libelle ) . '</button>';
		echo '</form>';
	}
}
