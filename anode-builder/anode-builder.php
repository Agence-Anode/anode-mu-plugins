<?php
/**
 * Plugin Name: Anode — confort du builder
 * Description: Trois manques de Bricks Builder, comblés sans extension tierce : la classe active reste sélectionnée, un curseur balaie les largeurs au pixel, et un double-clic ouvre un composant.
 * Version:     1.2.0
 * Author:      Anode
 * Requires PHP: 8.1
 *
 * Ces trois fonctions viennent d'Advanced Themer, qu'on ne réinstalle pas : une
 * extension de 4 Mo pour trois comportements d'interface, c'est une dépendance
 * de plus à suivre, et elle touche au même endroit que nous — les classes
 * globales.
 *
 * ## Ce que chacune corrige
 *
 * **La classe active.** À la sélection d'un élément, Bricks n'active aucune de
 * ses classes : le panneau de style vise alors l'`id` de l'élément. On croit
 * styler `.c-hero` et l'on écrit dans `#brxe-abc123` — un style d'instance, ni
 * réutilisable ni repérable, et que `check-instance-styles.mjs` finit par
 * signaler bien plus tard. La dernière classe de la cascade est donc
 * sélectionnée d'office : c'est celle qui gagne, donc celle qu'on veut modifier.
 *
 * **Le curseur de largeur.** Bricks n'offre qu'un champ numérique pour la
 * largeur du canevas. Chercher à quelle largeur une grille casse demandait de
 * saisir une valeur, regarder, en saisir une autre. Un curseur balaie, avec un
 * repère par point de rupture.
 *
 * **Le double-clic sur un composant.** Ouvrir un composant depuis la structure
 * demande un clic droit puis « Modifier le composant ». Le double-clic fait le
 * même chemin — celui de Bricks, pas un raccourci maison.
 *
 * ## Ce que ce composant ne fait pas
 *
 * Il ne touche à aucune donnée : ni classe, ni style, ni contenu. Il n'agit que
 * sur l'interface du builder, en déclenchant les gestionnaires de Bricks. Un
 * builder chargé sans lui se comporte exactement comme avant.
 *
 * @package Anode\Builder
 */

declare( strict_types = 1 );

namespace Anode\Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Les trois fonctions, et leur nom de réglage.
 *
 * Nommées plutôt que numérotées : un site qui n'en veut qu'une le dit dans un
 * langage lisible, et le filtre ci-dessous garde son sens dans six mois.
 */
const FONCTIONS = [
	'classe_active'      => 'Sélectionne la dernière classe de la cascade.',
	'curseur_largeur'    => 'Curseur de largeur du canevas, repères aux points de rupture.',
	'composant_dblclic'  => 'Double-clic sur un composant pour l’ouvrir.',
];

/** Chemin du dossier des ressources. */
function dossier_assets(): string {
	return __DIR__ . '/assets';
}

/** URL du dossier des ressources. */
function url_assets(): string {
	return content_url( 'mu-plugins/' . basename( __DIR__ ) . '/assets' );
}

/**
 * Version d'un fichier statique, d'après sa date de modification.
 *
 * Le numéro du composant ne suffit pas : on retouche le JavaScript sans monter
 * la version pendant une mise au point, et le navigateur sert alors la version
 * en cache. Un fichier absent retombe sur le numéro du composant plutôt que sur
 * `false`, qui ferait servir l'URL sans aucun paramètre de version.
 */
function version_asset( string $fichier ): string {
	$chemin = dossier_assets() . '/' . $fichier;
	$mtime  = is_readable( $chemin ) ? filemtime( $chemin ) : false;

	return false === $mtime ? '1.0.0' : (string) $mtime;
}

/**
 * Les fonctions réellement actives sur ce site.
 *
 * @return array<string, bool>
 */
function fonctions_actives(): array {
	$defaut = array_fill_keys( array_keys( FONCTIONS ), true );

	/**
	 * Permet de couper une fonction par site.
	 *
	 * @param array<string, bool> $actives Fonction => activée.
	 */
	$choisies = apply_filters( 'anode/builder/fonctions', $defaut );

	if ( ! is_array( $choisies ) ) {
		return $defaut;
	}

	// On ne garde que les clés connues : une faute de frappe dans un filtre
	// n'active rien d'inattendu, et n'éteint rien en silence non plus.
	$actives = [];

	foreach ( $defaut as $nom => $valeur ) {
		$actives[ $nom ] = array_key_exists( $nom, $choisies ) ? (bool) $choisies[ $nom ] : $valeur;
	}

	return $actives;
}

/**
 * Charge les ressources, et seulement dans le panneau du builder.
 *
 * `bricks_is_builder_main()` distingue le panneau de l'aperçu : les deux sont
 * des chargements de page, et l'aperçu n'a ni panneau de classes, ni barre
 * d'outils, ni structure. Y charger ce script n'aurait rien fait, mais l'aurait
 * fait deux fois.
 *
 * La priorité passe après celle de Bricks (10) : `bricks-builder` doit être
 * enregistré pour pouvoir en dépendre. Sans cette dépendance, notre script
 * pourrait s'exécuter avant que l'application Vue n'existe.
 */
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		/*
		 * La barre oblique n'est pas décorative.
		 *
		 * `function_exists()` reçoit une **chaîne** : elle désigne toujours la
		 * fonction globale, quel que soit le namespace où l'on écrit. Un appel
		 * `bricks_is_builder_main()` sans préfixe, lui, chercherait d'abord
		 * `Anode\Builder\bricks_is_builder_main` avant de retomber sur la globale.
		 * Les deux formes désignaient donc deux fonctions différentes — sans
		 * conséquence en production, où seule la globale existe, mais de quoi
		 * rendre un test muet en croyant l'avoir écrit.
		 */
		if ( ! function_exists( 'bricks_is_builder_main' ) || ! \bricks_is_builder_main() ) {
			return;
		}

		$actives = fonctions_actives();

		if ( ! in_array( true, $actives, true ) ) {
			return;
		}

		wp_enqueue_style(
			'anode-builder',
			url_assets() . '/builder.css',
			[],
			version_asset( 'builder.css' )
		);

		wp_enqueue_script(
			'anode-builder',
			url_assets() . '/builder.js',
			[ 'bricks-builder' ],
			version_asset( 'builder.js' ),
			true
		);

		wp_localize_script(
			'anode-builder',
			'anodeBuilder',
			[
				'fonctions' => $actives,
				'i18n'      => [
					'largeur'      => __( 'Largeur du canevas', 'anode' ),
					'pointDeRupture' => __( 'Point de rupture', 'anode' ),
				],
			]
		);
	},
	20
);
