<?php
/**
 * Plugin Name: Anode — confort du builder
 * Description: Quatre manques de Bricks Builder, comblés sans extension tierce : la classe active reste sélectionnée, un curseur balaie les largeurs au pixel, un double-clic dans la structure ouvre un composant, et le canevas reçoit le CSS des classes globales.
 * Version:     2.2.1
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
 * **Le double-clic sur un composant, dans la structure.** L'ouvrir demande sinon
 * un clic droit puis « Modifier le composant ». Le double-clic fait le même
 * chemin — celui de Bricks, pas un raccourci maison.
 *
 * Il agissait aussi dans le **canevas**, et c'est retiré en 2.0.0 : une fois dans
 * le composant, on ne pouvait plus y sélectionner un élément enfant. Bricks y
 * sélectionne l'élément cliqué et non l'instance qui le porte, et le bouton du
 * panneau réapparaît pour tout composant imbriqué — chaque double-clic sur un
 * enfant rouvrait donc quelque chose. Un seul chemin d'entrée, dans la
 * structure, vaut mieux que deux dont l'un est piégé.
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
	'classes_canevas'    => 'Sert au canevas le CSS des classes globales que Bricks n’y émet pas.',
];

/**
 * Les fonctions actives sans réglage.
 *
 * Les trois sont actives. Le tableau existe pour qu'un site puisse en couper une
 * par le filtre `anode/builder/fonctions`, et pour que le défaut de chacune soit
 * lisible en un endroit plutôt que déduit d'un `array_fill_keys`.
 */
const DEFAUTS = [
	'classe_active'      => true,
	'curseur_largeur'    => true,
	'composant_dblclic'  => true,
	'classes_canevas'    => true,
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
	$defaut = DEFAUTS;

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

/* -------------------------------------------------------------------------
 * Le CSS des classes globales, dans le canevas
 *
 * Bricks n'écrit jamais de feuille pour les classes globales, y compris quand
 * le site est en `cssLoading: file`. Sa propre note le dit (`includes/assets/
 * files.php`) : « need to know which element(s) a global class is actually set
 * for » — le CSS est émis **par type d'élément** (`.c-hero.brxe-section`), donc
 * il faut avoir rendu la page pour savoir quoi émettre.
 *
 * Sur le front, ce rendu a lieu : PHP parcourt les éléments, remplit
 * `Assets::$global_classes_elements`, et la feuille sort juste. Dans le canevas
 * du builder, **PHP ne rend rien** — la page est construite par Vue côté
 * client. La carte reste donc vide côté serveur, et tout repose sur le
 * JavaScript de Bricks (`generateGlobalClassStyles()`, `assets/js/iframe.min.js`),
 * qui n'indexe que les classes portées par un composant.
 *
 * Mesuré le 03/08/2026 sur un site du parc : 52 balises de style pour 104 classes
 * employées, et les 52 étaient exactement celles des composants. Aucune classe
 * posée dans une page n'avait de style. Le front était juste au pixel, le
 * builder montrait une page nue — ce que la règle §5 cherche précisément à
 * empêcher, puisque c'est ce que le client voit en ouvrant le builder.
 *
 * On remplit donc la carte nous-mêmes, et **on laisse Bricks générer son propre
 * CSS**. C'est le point important : aucun générateur maison, donc aucune
 * seconde source de vérité qui pourrait dériver du front. C'est aussi le patron
 * que Bricks emploie lui-même pour l'éditeur de blocs
 * (`generate_gutenberg_global_classes_css()`, `includes/integrations/block-editor.php`).
 *
 * ## Et le poids des règles, mesuré plutôt que supposé (2.2.1)
 *
 * Le JavaScript de Bricks réémet ses classes **après** notre feuille, dans une
 * balise par classe. À spécificité égale il gagne, ce qui est voulu. Le prix,
 * longtemps accepté : quand il réémet une classe de **base** après un
 * modificateur que nous seuls portons, la base l'emporte — `.c-entete--centre`
 * rendue `row flex-end` dans le canevas contre `column center` sur le front.
 *
 * La cause a été établie en demandant au navigateur quelle règle gagne
 * (`CSS.getMatchedStylesForNode`) plutôt qu'en la déduisant des feuilles. Les
 * trois règles apparaissent dans cet ordre de précédence croissante :
 *
 *     rang 11  .c-entete.brxe-block          = row      ← notre feuille
 *     rang 12  .c-entete--centre.brxe-block  = column   ← notre feuille
 *     rang 13  .c-entete.brxe-block          = row      ← bricks-global-class-700284
 *
 * Ce que la cinquième hypothèse supposait sans jamais le montrer, et qu'aucun
 * `curl` ne pouvait voir : la balise gagnante est injectée après chargement.
 *
 * Le correctif ne double pas la spécificité de la feuille — cela rendrait tout
 * panneau inerte, ce que le §5 refuse. Il ne sert pas le complément non plus —
 * mesuré pire (11 écarts contre 4). Il alourdit **les seules classes qu'aucun
 * élément ne porte**, celles pour lesquelles Bricks n'émet rien du tout : voir
 * `classes_hors_index()`.
 * ---------------------------------------------------------------------- */

/**
 * Ajoute un usage « cette classe est portée par ce type d'élément ».
 *
 * @param array<string, array<int, string>> $carte Carte à compléter, par référence.
 */
function ajouter_usage( string $id_classe, string $type_element, array &$carte ): void {
	if ( '' === $id_classe || '' === $type_element ) {
		return;
	}

	if ( ! isset( $carte[ $id_classe ] ) ) {
		$carte[ $id_classe ] = [];
	}

	if ( ! in_array( $type_element, $carte[ $id_classe ], true ) ) {
		$carte[ $id_classe ][] = $type_element;
	}
}

/**
 * Indexe les classes globales portées par une liste d'éléments.
 *
 * Les identifiants sont ramenés à des chaînes : `json_decode` transforme une
 * clé numérique (« 358029 ») en entier, et la comparaison stricte que fait
 * Bricks contre `$global_class['id']`, une chaîne, échouerait alors en silence.
 *
 * @param array<int, mixed>                 $elements Éléments Bricks.
 * @param array<string, array<int, string>> $carte    Carte à compléter, par référence.
 */
function indexer_elements( array $elements, array &$carte ): void {
	foreach ( $elements as $element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}

		$type = (string) ( $element['name'] ?? '' );

		// `settings` vaut `[]` — et non `{}` — quand PHP l'a encodé vide.
		$classes = $element['settings']['_cssGlobalClasses'] ?? [];

		if ( ! is_array( $classes ) ) {
			continue;
		}

		foreach ( $classes as $id ) {
			ajouter_usage( (string) $id, $type, $carte );
		}
	}
}

/**
 * Les identifiants de classes qu'une propriété de type `class` peut poser.
 *
 * Une variante est une propriété `class` : `default` désigne alors des **options**,
 * et c'est la `value` de l'option qui porte les classes. On collecte les valeurs
 * de **toutes** les options, pas seulement celle par défaut — changer de variante
 * dans le builder ne doit pas laisser l'élément sans style.
 *
 * @param array<string, mixed> $propriete Propriété du composant.
 * @return array<int, string>
 */
function ids_de_propriete( array $propriete ): array {
	$ids     = [];
	$options = is_array( $propriete['options'] ?? null ) ? $propriete['options'] : [];

	foreach ( $options as $option ) {
		if ( ! is_array( $option ) ) {
			continue;
		}

		foreach ( (array) ( $option['value'] ?? [] ) as $id ) {
			$ids[] = (string) $id;
		}
	}

	// Sans options, `default` désigne directement des classes.
	$ids_options = array_map(
		static fn( $option ) => (string) ( is_array( $option ) ? ( $option['id'] ?? '' ) : '' ),
		$options
	);

	foreach ( (array) ( $propriete['default'] ?? [] ) as $valeur ) {
		$valeur = (string) $valeur;

		if ( ! in_array( $valeur, $ids_options, true ) ) {
			$ids[] = $valeur;
		}
	}

	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * Indexe les classes qu'un composant pose par ses propriétés de type `class`.
 *
 * @param array<string, mixed>              $composant Définition du composant.
 * @param array<string, array<int, string>> $carte     Carte à compléter, par référence.
 */
function indexer_proprietes_classe( array $composant, array &$carte ): void {
	$proprietes = $composant['properties'] ?? [];
	$elements   = is_array( $composant['elements'] ?? null ) ? $composant['elements'] : [];

	if ( ! is_array( $proprietes ) ) {
		return;
	}

	foreach ( $proprietes as $propriete ) {
		if ( ! is_array( $propriete ) || 'class' !== ( $propriete['type'] ?? '' ) ) {
			continue;
		}

		$connexions = $propriete['connections'] ?? [];

		if ( ! is_array( $connexions ) || ! $connexions ) {
			continue;
		}

		$ids = ids_de_propriete( $propriete );

		if ( ! $ids ) {
			continue;
		}

		foreach ( array_keys( $connexions ) as $id_element ) {
			$type = '';

			foreach ( $elements as $element ) {
				if ( is_array( $element ) && (string) ( $element['id'] ?? '' ) === (string) $id_element ) {
					$type = (string) ( $element['name'] ?? '' );
					break;
				}
			}

			foreach ( $ids as $id ) {
				ajouter_usage( $id, $type, $carte );
			}
		}
	}
}

/**
 * La dernière carte construite, pour l'en-tête de la feuille.
 *
 * Retenue plutôt que recalculée : l'en-tête doit décrire ce qui a réellement été
 * généré, pas une seconde indexation qui pourrait diverger.
 *
 * @param array<string, array<int, string>>|null $carte Carte à retenir, ou null pour lire.
 * @return array<string, array<int, string>>
 */
function derniere_carte( ?array $carte = null ): array {
	static $retenue = [];

	if ( null !== $carte ) {
		$retenue = $carte;
	}

	return $retenue;
}

/**
 * Les classes qu'aucun élément ne porte, et que le canevas ne stylera jamais.
 *
 * C'est la seule question qui décide du poids d'une règle, et elle a une réponse
 * mesurée. Le JavaScript du canevas (`elementsPerGlobalClassId`,
 * `assets/js/iframe.min.js`) émet une balise `bricks-global-class-<id>` pour
 * **exactement** les classes présentes dans le `_cssGlobalClasses` d'un élément
 * de composant : 79 balises pour 79 classes sur un site du parc le 03/08/2026, aucune de
 * plus, aucune de moins.
 *
 * Il n'émet donc **rien** pour une classe qu'un composant ne pose que par une
 * propriété de type `class` — une variante. Sa propre résolution y échoue :
 * elle lit `property.default` comme une liste d'**identifiants d'option**
 * (`options.find(o => o.id === e)`), alors que Bricks y range des
 * **identifiants de classe** (`default: ["700284"]`). Le tableau résolu sort
 * vide, et la variante n'entre jamais dans la carte.
 *
 * Pour ces classes-là, notre feuille est la seule source. Aucun panneau ne peut
 * en devenir inerte, puisqu'aucune balise ne les sert — d'où le droit de les
 * servir plus lourd. Pour toutes les autres, on n'y touche pas : le JavaScript
 * doit continuer de l'emporter, sinon une modification faite dans le builder
 * n'apparaîtrait plus (§5).
 *
 * @param array<string, array<int, string>> $parElements   Classes portées par un élément.
 * @param array<string, array<int, string>> $parProprietes Classes posées par une propriété.
 * @return array<string, array<int, string>>
 */
function classes_hors_index( array $parElements, array $parProprietes ): array {
	return array_diff_key( $parProprietes, $parElements );
}

/**
 * Le nombre de classes hors index de la dernière génération, pour l'en-tête.
 *
 * Retenu plutôt que recalculé, pour la même raison que `derniere_carte()` :
 * l'en-tête doit décrire ce qui a réellement été émis. Une feuille peut sortir
 * de 80 Ko et ne pas porter la seule règle qui manquait.
 */
function dernier_hors_index( ?int $nombre = null ): int {
	static $retenu = 0;

	if ( null !== $nombre ) {
		$retenu = $nombre;
	}

	return $retenu;
}

/**
 * Double le nom des classes visées, le temps d'une génération.
 *
 * On ne réécrit pas le CSS produit : Bricks construit son sélecteur à partir du
 * **nom** de la classe (`$css_selector = ".{$element['_cssGlobalClass']}"`,
 * `includes/assets.php`). Un nom `c-x.c-x` sort donc en `.c-x.c-x.brxe-block`,
 * et la même substitution suit les sous-sélecteurs et les pseudo-classes sans
 * qu'on ait à les connaître. Aucun analyseur de sélecteurs maison, donc, et
 * aucune seconde source de vérité : les valeurs restent celles de Bricks.
 *
 * @param array<int, string> $ids Identifiants de classes à alourdir.
 * @return array<int, mixed> Les classes globales telles qu'elles étaient.
 */
function doubler_les_noms( array $ids ): array {
	$sauvegarde = \Bricks\Database::$global_data['globalClasses'] ?? [];

	if ( ! $ids || ! is_array( $sauvegarde ) ) {
		return is_array( $sauvegarde ) ? $sauvegarde : [];
	}

	// Les identifiants sont ramenés à des chaînes : `json_decode` transforme une
	// clé numérique en entier, et la comparaison échouerait en silence.
	$vises = [];

	foreach ( $ids as $id ) {
		$vises[ (string) $id ] = true;
	}

	foreach ( $sauvegarde as $index => $classe ) {
		if ( ! is_array( $classe ) ) {
			continue;
		}

		$nom = (string) ( $classe['name'] ?? '' );

		if ( '' === $nom || ! isset( $vises[ (string) ( $classe['id'] ?? '' ) ] ) ) {
			continue;
		}

		\Bricks\Database::$global_data['globalClasses'][ $index ]['name'] = $nom . '.' . $nom;
	}

	return $sauvegarde;
}

/**
 * Génère le CSS d'une carte, en empruntant les propriétés statiques de Bricks.
 *
 * On les prête le temps de la génération et on les rend telles quelles — y
 * compris si la génération lève. Sans ce `finally`, un site dont une classe
 * porte un réglage inattendu garderait une carte étrangère pour le reste de la
 * requête.
 *
 * @param array<string, array<int, string>> $carte   Classe => types d'éléments.
 * @param bool                              $doubler Alourdir le sélecteur.
 */
function generer_css( array $carte, bool $doubler = false ): string {
	if ( ! $carte ) {
		return '';
	}

	$sauvegarde = [
		'global_classes_elements'    => \Bricks\Assets::$global_classes_elements,
		'inline_css'                 => \Bricks\Assets::$inline_css,
		'inline_css_breakpoints'     => \Bricks\Assets::$inline_css_breakpoints,
		'unique_inline_css'          => \Bricks\Assets::$unique_inline_css,
		'inline_css_dynamic_data'    => \Bricks\Assets::$inline_css_dynamic_data,
		'current_generating_element' => \Bricks\Assets::$current_generating_element,
	];

	$classes = $doubler
		? doubler_les_noms( array_keys( $carte ) )
		: ( \Bricks\Database::$global_data['globalClasses'] ?? [] );

	try {
		\Bricks\Assets::$global_classes_elements = $carte;

		/*
		 * Partir d'un cache de dédoublonnage vide.
		 *
		 * Bricks retient dans `$unique_inline_css` ce qu'il a déjà émis, pour ne pas
		 * répéter une règle. Le canevas a déjà rempli ce cache avant nous : nos
		 * règles y passaient pour des doublons et disparaissaient — pas toutes, les
		 * plus banales seulement, ce qui donnait une feuille d'apparence normale.
		 *
		 * La seconde passe le vide aussi : ses règles portent les mêmes
		 * déclarations que la première, au sélecteur près.
		 */
		\Bricks\Assets::$unique_inline_css       = [];
		\Bricks\Assets::$inline_css_dynamic_data = '';

		return (string) \Bricks\Assets::generate_global_classes( 'anode_canevas' );
	} finally {
		\Bricks\Assets::$global_classes_elements    = $sauvegarde['global_classes_elements'];
		\Bricks\Assets::$inline_css                 = $sauvegarde['inline_css'];
		\Bricks\Assets::$inline_css_breakpoints     = $sauvegarde['inline_css_breakpoints'];
		\Bricks\Assets::$unique_inline_css          = $sauvegarde['unique_inline_css'];
		\Bricks\Assets::$inline_css_dynamic_data    = $sauvegarde['inline_css_dynamic_data'];
		\Bricks\Assets::$current_generating_element = $sauvegarde['current_generating_element'];

		\Bricks\Database::$global_data['globalClasses'] = $classes;
	}
}

/**
 * Le CSS des classes globales employées par une page et par les composants.
 *
 * Les composants sont **tous** indexés, employés ou non : le builder peut en
 * poser un à tout moment, et il doit sortir stylé sans recharger la page.
 *
 * @return string CSS, ou chaîne vide si rien n'est indexable.
 */
function css_des_classes_globales( int $post_id ): string {
	if ( ! class_exists( '\Bricks\Assets' ) || ! method_exists( '\Bricks\Assets', 'generate_global_classes' ) ) {
		return '';
	}

	$parElements = [];
	$contenu     = $post_id ? get_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT, true ) : [];

	if ( is_array( $contenu ) ) {
		indexer_elements( $contenu, $parElements );
	}

	/*
	 * Les composants sont indexés **avec** la page, et non retirés d'elle.
	 *
	 * Le JavaScript de Bricks en émet une partie, mais une partie seulement :
	 * essayer de ne servir que le complément a été mesuré le 03/08/2026 sur
	 * un site du parc, et le résultat a empiré — 11 écarts contre 4. `.c-ligne--finale`,
	 * pourtant portée par une propriété de composant, perdait son fond, son
	 * rayon et son rembourrage : le JavaScript ne l'émet pas du tout.
	 *
	 * On émet donc tout, en sachant que le JavaScript réémet ses classes après
	 * nous. À spécificité égale il gagne, ce qui est **voulu** : une modification
	 * faite dans le builder doit rester visible, sinon le panneau devient inerte
	 * et l'on a créé le défaut inverse de celui qu'on corrige.
	 *
	 * Deux index séparés, plutôt qu'un seul : ce qu'un élément porte, et ce
	 * qu'une propriété pose. La différence entre les deux — voir
	 * `classes_hors_index()` — est exactement ce que le canevas ne stylera
	 * jamais, et donc ce qu'on peut servir plus lourd sans rendre un panneau
	 * inerte.
	 */
	$parProprietes = [];
	$composants    = \Bricks\Database::$global_data['components'] ?? get_option( BRICKS_DB_COMPONENTS, [] );

	if ( is_array( $composants ) ) {
		foreach ( $composants as $composant ) {
			if ( ! is_array( $composant ) ) {
				continue;
			}

			indexer_elements( (array) ( $composant['elements'] ?? [] ), $parElements );
			indexer_proprietes_classe( $composant, $parProprietes );
		}
	}

	$carte = $parElements;

	foreach ( $parProprietes as $id => $types ) {
		foreach ( $types as $type ) {
			ajouter_usage( (string) $id, $type, $carte );
		}
	}

	derniere_carte( $carte );

	if ( ! $carte ) {
		return '';
	}

	/*
	 * Charger les contrôles des éléments, sinon la feuille sort à moitié.
	 *
	 * `generate_global_classes()` traduit les réglages d'une classe en CSS à
	 * travers les **contrôles** du type d'élément qui la porte : c'est le contrôle
	 * qui dit que `_padding` s'écrit `padding`. Or Bricks ne charge les contrôles
	 * d'un élément qu'à son premier rendu (`Elements::get_element()` appelle
	 * `load_element()` à la demande), et dans le canevas aucun élément n'est rendu
	 * côté PHP.
	 *
	 * Sans cet appel, la feuille sort **non vide et pourtant inutile** : le
	 * `_cssCustom` de chaque classe, qui est du CSS brut, passe sans contrôle,
	 * tandis que tous les réglages des panneaux disparaissent. Mesuré le
	 * 03/08/2026 sur un site du parc : 41 Ko servis, 20 classes présentes sur 120 indexées,
	 * et pas une seule règle de mise en page.
	 */
	if ( class_exists( '\Bricks\Elements' ) && method_exists( '\Bricks\Elements', 'load_elements' ) ) {
		\Bricks\Elements::load_elements();
	}

	$horsIndex = classes_hors_index( $parElements, $parProprietes );

	dernier_hors_index( count( $horsIndex ) );

	/*
	 * La première passe est inchangée, et c'est la ceinture : si la seconde se
	 * trompait de classe, on retomberait sur le comportement d'avant plutôt que
	 * sur une page sans style.
	 *
	 * La seconde réémet les seules classes qu'aucun élément ne porte, sélecteur
	 * doublé (0-3-0). Elles cessent ainsi de perdre contre la classe de base que
	 * le JavaScript réémet après nous — la limite mesurée du 03/08/2026 :
	 * `.c-entete--centre` rendue `row flex-end` dans le canevas contre
	 * `column center` sur le front, et `.c-ligne--finale` à 16 px au lieu de 18.
	 */
	return generer_css( $carte ) . generer_css( $horsIndex, true );
}

/**
 * L'identifiant de la page ouverte dans le canevas.
 *
 * `get_queried_object_id()` suffit pour une page, mais rend 0 sur un gabarit
 * servi hors requête principale — le 404, notamment, qui est exactement le
 * gabarit qu'on ne visite jamais en travaillant.
 */
function id_du_canevas(): int {
	$id = (int) get_queried_object_id();

	if ( ! $id && function_exists( 'get_the_ID' ) ) {
		$id = (int) get_the_ID();
	}

	if ( ! $id && isset( $_GET['p'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = (int) $_GET['p']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	return max( 0, $id );
}

/**
 * Émet la feuille dans le canevas, et nulle part ailleurs.
 *
 * La priorité est tardive pour passer après les feuilles du thème, comme sur le
 * front. Elle reste **avant** les balises que le JavaScript de Bricks injecte
 * ensuite : à spécificité égale, une modification faite dans le builder doit
 * continuer de l'emporter sur ce qu'on sert ici, sinon le panneau deviendrait
 * inerte — le défaut inverse de celui qu'on corrige.
 */
add_action(
	'wp_head',
	static function (): void {
		if ( ! function_exists( 'bricks_is_builder_iframe' ) || ! \bricks_is_builder_iframe() ) {
			return;
		}

		if ( empty( fonctions_actives()['classes_canevas'] ) ) {
			return;
		}

		$post_id = id_du_canevas();
		$css     = css_des_classes_globales( $post_id );

		if ( '' === trim( $css ) ) {
			return;
		}

		/*
		 * Un en-tête lisible, et ce n'est pas de la décoration : la feuille peut
		 * sortir non vide et pourtant ne rien porter de la page — c'est arrivé au
		 * premier essai, avec les seules classes des composants. Sans ce compte,
		 * on lit « 41 Ko servis » et l'on conclut que ça marche.
		 */
		$css = sprintf(
			"/* Anode — classes globales du canevas · page %d · %d classe(s), dont %d hors index */\n%s",
			$post_id,
			count( derniere_carte() ),
			dernier_hors_index(),
			$css
		);

		// Un `_cssCustom` peut contenir n'importe quoi : une balise fermante
		// dans du CSS terminerait l'élément et rendrait la suite en clair.
		$css = str_replace( '</style', '<\/style', $css );

		echo "<style id=\"anode-classes-canevas\">\n" . $css . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	},
	999
);

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
