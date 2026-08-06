<?php
/**
 * Tests du confort du builder — sans WordPress, sans navigateur.
 *
 * Deux choses se vérifient ici, et ce sont les deux qui ont déjà mal tourné
 * ailleurs dans ce dépôt.
 *
 * D'abord le **filtre** : une fonction qu'on croit éteinte et qui tourne, ou
 * l'inverse, ne se voit pas — le builder a l'air normal dans les deux cas.
 *
 * Ensuite les **points d'accroche du JavaScript**. Ils ne sont pas décoratifs :
 * ce sont des sélecteurs relevés dans un builder réel, et le jour où l'un
 * disparaît, la fonction devient inerte en silence. Les tests les épinglent, de
 * sorte qu'une réécriture distraite du script échoue ici plutôt que chez le
 * client.
 *
 * **Ce que ces tests ne peuvent pas faire, et il faut le savoir en les lisant :**
 * ils lisent du texte. Ils disent qu'un mécanisme est écrit, jamais qu'il tourne,
 * et encore moins que Bricks émet toujours ce qu'il vise. La 1.5.0 en a fait la
 * démonstration : cinq tests d'ici passaient au vert sur des fonctions qui
 * rendaient `false` en toutes circonstances, parce que le repère qu'elles
 * consultaient n'existait pas dans le DOM. Le seul juge du comportement est
 * `./bin/test-builder-interactions.mjs`, qui ouvre un vrai builder et clique.
 *
 * Deux conséquences pour qui ajoute un test ici :
 *
 *   - un test **d'absence** est plus solide qu'un test de présence : il interdit
 *     le retour d'un défaut payé, et cela se vérifie sans exécuter le script ;
 *   - un test de présence ne doit épingler que ce dont on a la **mesure**, jamais
 *     ce qu'on suppose du DOM de Bricks — sinon il fige une erreur.
 *
 * Lancement : php blueprint/mu-plugins/anode-builder/tests/test-builder.php
 *
 * @package Anode\Builder
 */

declare( strict_types = 1 );

namespace Anode\Builder;

define( 'ABSPATH', __DIR__ );

/*
 * La doublure de `bricks_is_builder_main()` vit dans son propre fichier, et
 * c'est une contrainte de PHP, pas un choix de rangement.
 *
 * Le composant cherche cette fonction par `function_exists()`, qui reçoit une
 * **chaîne** : elle désigne donc toujours la fonction globale. Déclarée dans
 * `Anode\Builder`, la doublure restait invisible — la garde répondait « pas dans
 * le builder », rien ne se chargeait, et sept tests passaient au vert sans rien
 * exercer. Or on ne peut pas ouvrir un bloc `namespace { }` dans un fichier qui
 * déclare déjà son namespace sans accolades : d'où le fichier voisin.
 */
require_once __DIR__ . '/doublure-bricks.php';
require_once __DIR__ . '/doublure-bricks-database.php';

/* --- Doublures WordPress --------------------------------------------- */

/**
 * Filtres enregistrés, et valeur imposée par test.
 *
 * @var array<string, mixed>
 */
$etat = [ 'filtres' => [], 'scripts' => [], 'styles' => [], 'localise' => [] ];

function reinitialiser(): void {
	global $etat;

	$etat = [ 'filtres' => [], 'scripts' => [], 'styles' => [], 'localise' => [], 'builder' => true ];
}

reinitialiser();

function apply_filters( string $nom, $valeur ) {
	global $etat;

	return array_key_exists( $nom, $etat['filtres'] ) ? $etat['filtres'][ $nom ] : $valeur;
}

function add_action( string $accroche, $rappel = null, int $priorite = 10 ): void {
	global $etat;

	$etat['accroches'][ $accroche ][] = $rappel;
}

function content_url( string $chemin = '' ): string {
	return 'https://exemple.test/wp-content/' . ltrim( $chemin, '/' );
}

function wp_enqueue_script( string $nom, string $src = '', array $deps = [], $version = false, $pied = false ): void {
	global $etat;

	$etat['scripts'][ $nom ] = compact( 'src', 'deps', 'version', 'pied' );
}

function wp_enqueue_style( string $nom, string $src = '', array $deps = [], $version = false ): void {
	global $etat;

	$etat['styles'][ $nom ] = compact( 'src', 'deps', 'version' );
}

function wp_localize_script( string $nom, string $objet, array $donnees ): void {
	global $etat;

	$etat['localise'][ $objet ] = $donnees;
}

// phpcs:ignore WordPress.NamingConventions.ValidFunctionName
function __( string $texte ): string {
	return $texte;
}

require_once __DIR__ . '/../anode-builder.php';

/** Rejoue l'accroche de chargement des ressources. */
function charger(): void {
	global $etat;

	$rappel = $etat['accroches']['wp_enqueue_scripts'][0] ?? null;

	if ( ! is_callable( $rappel ) ) {
		throw new \RuntimeException( 'aucune fermeture sur wp_enqueue_scripts' );
	}

	$rappel();
}

/*
 * Les accroches sont posées une fois, au chargement du fichier, alors que
 * `reinitialiser()` vide l'état avant chaque test. On les fige donc hors de lui.
 */
$accroches = $etat['accroches'];

/* --- Harnais --------------------------------------------------------- */

$passed = 0;
$failed = 0;

function test( string $nom, callable $fn ): void {
	global $passed, $failed, $etat, $accroches;

	reinitialiser();
	$etat['accroches'] = $accroches;

	try {
		$fn();
		$passed++;
		echo "  ✓ {$nom}\n";
	} catch ( \Throwable $e ) {
		$failed++;
		echo "  ✗ {$nom}\n      {$e->getMessage()}\n";
	}
}

function assert_same( $attendu, $obtenu, string $message = '' ): void {
	if ( $attendu !== $obtenu ) {
		throw new \RuntimeException(
			sprintf(
				'%sattendu %s, obtenu %s',
				$message ? $message . ' : ' : '',
				var_export( $attendu, true ),
				var_export( $obtenu, true )
			)
		);
	}
}

function assert_true( bool $condition, string $message = 'condition fausse' ): void {
	if ( ! $condition ) {
		throw new \RuntimeException( $message );
	}
}

/* --- Chargement des ressources --------------------------------------- */

echo "\nChargement des ressources\n";

test( 'les ressources ne sont servies que dans le panneau du builder', function (): void {
	global $etat;

	// Une page ordinaire du site : le script n'a rien à y faire, et l'aperçu du
	// builder n'a ni panneau de classes, ni barre d'outils, ni structure.
	$etat['builder'] = false;

	charger();

	assert_same( [], $etat['scripts'], 'script chargé hors du builder' );
	assert_same( [], $etat['styles'], 'feuille chargée hors du builder' );
} );

test( 'dans le builder, le script dépend de celui de Bricks', function (): void {
	global $etat;

	charger();

	assert_true( isset( $etat['scripts']['anode-builder'] ), 'script absent' );

	// Sans cette dépendance, le script peut s'exécuter avant que l'application
	// Vue n'existe : les sélecteurs ne trouvent rien et les trois fonctions sont
	// inertes, sans la moindre erreur.
	assert_same( [ 'bricks-builder' ], $etat['scripts']['anode-builder']['deps'], 'dépendances' );
	assert_true( (bool) $etat['scripts']['anode-builder']['pied'], 'script servi dans l’en-tête' );
} );

test( 'la version des ressources suit le fichier, pas le composant', function (): void {
	global $etat;

	charger();

	// Retoucher le script sans monter la version du composant est le cas normal
	// d'une mise au point : sans empreinte de fichier, le navigateur sert le
	// cache et l'on croit que la correction n'a rien changé.
	$version = (string) $etat['scripts']['anode-builder']['version'];

	assert_true( ctype_digit( $version ), "version « {$version} » : ce n’est pas un horodatage" );
	assert_true( '1.0.0' !== $version, 'la version retombe sur le repli' );
} );

test( 'un fichier absent retombe sur un repli, jamais sur false', function (): void {
	// `false` ferait servir l'URL sans paramètre de version : le cache du
	// navigateur garderait alors l'ancienne indéfiniment.
	assert_same( '1.0.0', version_asset( 'fichier-qui-n-existe-pas.js' ) );
} );

/* --- Le filtre des fonctions ----------------------------------------- */

echo "\nFonctions actives\n";

test( 'les quatre fonctions sont actives par défaut', function (): void {
	assert_same(
		[
			'classe_active'     => true,
			'curseur_largeur'   => true,
			'composant_dblclic' => true,
			'classes_canevas'   => true,
		],
		fonctions_actives()
	);
} );

test( 'le filtre peut en éteindre une sans toucher aux autres', function (): void {
	global $etat;

	$etat['filtres']['anode/builder/fonctions'] = [ 'curseur_largeur' => false ];

	$actives = fonctions_actives();

	assert_same( false, $actives['curseur_largeur'], 'la fonction éteinte' );
	assert_same( true, $actives['classe_active'], 'une fonction non citée' );
	assert_same( true, $actives['composant_dblclic'], 'une fonction non citée' );
} );

test( 'une clé inconnue n’active rien et n’éteint rien', function (): void {
	global $etat;

	// Une faute de frappe dans un filtre — « curseur_largueur » — ne doit ni
	// créer une fonction, ni laisser croire qu'elle a éteint la vraie.
	$etat['filtres']['anode/builder/fonctions'] = [ 'curseur_largueur' => false ];

	$actives = fonctions_actives();

	assert_same( count( DEFAUTS ), count( $actives ), 'nombre de fonctions' );
	assert_same( true, $actives['curseur_largeur'], 'la vraie fonction a été éteinte par une faute de frappe' );
} );

test( 'un filtre qui ne rend pas un tableau est ignoré', function (): void {
	global $etat;

	$etat['filtres']['anode/builder/fonctions'] = 'oui';

	assert_same( count( DEFAUTS ), count( fonctions_actives() ) );
	assert_same( true, fonctions_actives()['classe_active'] );
} );

test( 'toutes les fonctions éteintes, rien n’est chargé', function (): void {
	global $etat;

	// Dérivé de DEFAUTS : une cinquième fonction ajoutée sans toucher ce test
	// la laisserait allumée, et le test passerait au vert en n'éteignant rien.
	$etat['filtres']['anode/builder/fonctions'] = array_fill_keys( array_keys( DEFAUTS ), false );

	charger();

	assert_same( [], $etat['scripts'], 'script chargé pour rien' );
	assert_same( [], $etat['styles'], 'feuille chargée pour rien' );
} );

test( 'les fonctions actives sont transmises au script', function (): void {
	global $etat;

	$etat['filtres']['anode/builder/fonctions'] = [ 'classe_active' => false ];

	charger();

	// Sans cette transmission, le script exécuterait les trois quoi qu'il arrive :
	// le réglage serait un réglage pour rien.
	assert_same( false, $etat['localise']['anodeBuilder']['fonctions']['classe_active'] );
	assert_same( true, $etat['localise']['anodeBuilder']['fonctions']['curseur_largeur'] );
} );

/* --- Points d'accroche du JavaScript --------------------------------- */

echo "\nPoints d’accroche relevés dans le builder\n";

/** Le script, lu tel qu'il sera servi. */
function script(): string {
	static $source = null;

	if ( null === $source ) {
		$source = (string) file_get_contents( __DIR__ . '/../assets/builder.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	return $source;
}

/**
 * Le script sans ses commentaires.
 *
 * Deux contrôles ci-dessous portent sur ce que le script **ne doit pas faire** —
 * viser un libellé traduit, toucher à un interne de Vue. Ils échouaient tous les
 * deux sur la prose : les commentaires nomment précisément ce qu'on évite, et
 * c'est le propre d'un bon commentaire. Un test d'absence doit donc lire le code.
 *
 * Le retrait est volontairement grossier — il ne comprend ni les chaînes ni les
 * expressions régulières. C'est sans conséquence ici : on cherche des chaînes
 * fixes dans un fichier qu'on écrit soi-même, pas à analyser du JavaScript.
 */
function code(): string {
	static $code = null;

	if ( null === $code ) {
		$code = (string) preg_replace( [ '#/\*.*?\*/#s', '#(^|\s)//[^\n]*#' ], ' ', script() );
	}

	return $code;
}

/**
 * Le source PHP du composant, commentaires retirés.
 *
 * `code()` lit le JavaScript : un test qui vise le PHP et l'interroge cherche
 * dans le mauvais fichier, et passe au vert ou au rouge sans rapport avec ce
 * qu'il énonce. Les deux sources ont donc deux lecteurs.
 */
function code_php(): string {
	static $code = null;

	if ( null === $code ) {
		$source = (string) file_get_contents( __DIR__ . '/../anode-builder.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$code   = (string) preg_replace( [ '#/\*.*?\*/#s', '#(^|\s)//[^\n]*#' ], ' ', $source );
	}

	return $code;
}

/**
 * Le corps d'un des deux gestionnaires de double-clic, sans commentaires.
 *
 * Les deux ne décident pas de la même façon, et c'est voulu : dans la structure la
 * ligne cliquée est celle qu'on juge, dans le canevas il faut remonter de l'élément
 * sélectionné à l'instance qui le porte. Un test qui lisait le fichier entier ne
 * pouvait donc distinguer « la remontée est de retour dans la structure » — le
 * défaut qui faisait quitter un composant en renommant une carte — de la remontée
 * légitime du canevas. Il passait au vert dans les deux cas.
 *
 * @param string $lequel « structure » ou « canevas ».
 */
/**
 * Le corps du gestionnaire de double-clic.
 *
 * Il n'y en a plus qu'un — celui de la structure. La version précédente
 * découpait le fichier en deux, structure et canevas, parce que les deux
 * gestionnaires n'avaient pas le droit aux mêmes lectures : le canevas devait
 * remonter à l'instance, la structure ne le devait pas. Le canevas a été retiré ;
 * la fonction reste, parce que ce qu'elle protège subsiste — un test qui lirait le
 * fichier entier confondrait le code de décision avec les commentaires qui
 * expliquent ce qu'on a cessé de faire.
 */
function gestionnaire( string $lequel = 'structure' ): string {
	$corps = code();
	$debut = strpos( $corps, "document.addEventListener('dblclick'" );

	if ( false === $debut ) {
		throw new \RuntimeException( 'gestionnaire de double-clic introuvable' );
	}

	$fin = strpos( $corps, 'const demarrer', $debut );

	return false === $fin ? substr( $corps, $debut ) : substr( $corps, $debut, $fin - $debut );
}

test( 'chaque sélecteur relevé est présent dans le script', function (): void {
	/*
	 * Ces six sélecteurs ont été relevés dans un builder Bricks 2.3.10, en
	 * ouvrant une page qui contient un composant. Ce ne sont pas des suppositions
	 * — et c'est pour cela qu'ils sont épinglés : une réécriture qui en perd un
	 * rend une fonction inerte, sans erreur ni trace.
	 *
	 * La lecture porte sur `code()`, et non sur la source brute. Le défaut est
	 * mesuré : `#bricks-structure li.element.active` avait disparu du code
	 * exécutable en même temps que la notion d'« élément courant », et ne
	 * subsistait que dans le commentaire d'en-tête. Le test passait au vert en ne
	 * garantissant rien — un sélecteur cité en prose n'accroche rien.
	 *
	 * Un septième y a figuré et n'y est plus : `li.element[data-parent-id="0"]`,
	 * présenté comme le repère du mode d'édition. Le relevé de douze états l'a
	 * démenti — la racine affichée en édition de composant déclare le même parent
	 * qu'au niveau de la page. Un sélecteur épinglé n'est pas un sélecteur vrai :
	 * celui-là était épinglé, présent, et sans effet.
	 */
	$attendus = [
		'.active-classes li'                              => 'les classes de l’élément',
		'#bricks-toolbar input#preview-width'             => 'la largeur du canevas',
		'#bricks-structure li.element[data-id]'           => 'les lignes de la structure',
		'#bricks-panel span.bricks-svg-wrapper.component' => 'le bouton d’édition du composant',
	];

	foreach ( $attendus as $selecteur => $role ) {
		assert_true(
			str_contains( code(), $selecteur ),
			sprintf( 'sélecteur perdu — %s : %s', $role, $selecteur )
		);
	}
} );

test( 'le bouton est reconnu par sa classe, jamais par son libellé', function (): void {
	// `data-balloon` vaut « Modifier le composant » en français et « Edit
	// component » en anglais : s'y fier casserait la fonction sur tout builder
	// dans une autre langue que la nôtre.
	assert_true( ! str_contains( code(), 'Modifier le composant' ), 'le libellé traduit est visé' );
	assert_true( ! str_contains( code(), 'data-balloon' ), 'un attribut traduit est visé' );
} );

test( 'le menu contextuel n’est plus employé', function (): void {
	/*
	 * Il l'a été, et c'était le défaut : `#bricks-builder-context-menu` existe en
	 * permanence dans le DOM, vide, et ne se remplit qu'après un rendu de Vue. Le
	 * trouver ne prouvait rien — le clic partait dans le vide, et le double-clic
	 * ne faisait que sélectionner la ligne.
	 */
	assert_true(
		! str_contains( code(), 'bricks-builder-context-menu' ),
		'le menu contextuel est de retour : il se remplit trop tard pour être fiable'
	);
} );

test( 'le « renommer » de Bricks est devancé', function (): void {
	/*
	 * Bricks lie déjà le double-clic de `.structure-item` à son mode « renommer ».
	 * Sans arrêter l'événement, les deux comportements se superposent — c'est ce
	 * qu'a donné le premier essai à l'usage.
	 */
	foreach ( [ 'preventDefault', 'stopPropagation', 'stopImmediatePropagation' ] as $garde ) {
		assert_true( str_contains( code(), $garde ), "le double-clic ne fait pas de {$garde}" );
	}
} );

test( 'la largeur est posée par la touche que Bricks écoute', function (): void {
	/*
	 * Le champ `#preview-width` n'est pas en `v-model` : relevé dans le rendu de
	 * Bricks, il ne relit sa valeur que sur `blur` et sur `keyup` filtré par
	 * Entrée. Émettre `input` posait la valeur dans le champ sans jamais
	 * redimensionner le canevas — exactement ce qui a été constaté.
	 */
	assert_true( str_contains( code(), "key: 'Enter'" ), 'la touche Entrée n’est plus simulée' );
	assert_true(
		! str_contains( code(), "new Event('input'" ),
		'un événement input est de retour : Bricks ne l’écoute pas sur ce champ'
	);
} );

test( 'le curseur va du plus large au plus étroit', function (): void {
	// C'est l'ordre dans lequel on conçoit — du bureau vers le mobile — et celui
	// des points de rupture de Bricks. Le sens naturel d'un curseur ne l'est pas
	// ici.
	assert_true( str_contains( code(), 'miroir' ), 'l’inversion du curseur a disparu' );
} );

test( 'les repères sont les points de rupture du site, et suivent leurs changements', function (): void {
	// Une liste écrite en dur mentirait dès qu'un site ajoute un point de rupture.
	assert_true( str_contains( code(), 'bricksData?.breakpoints' ), 'les points de rupture ne sont plus lus sur le site' );
	assert_true( str_contains( code(), 'dessinerReperes' ), 'les repères ne sont plus redessinés' );
} );

test( 'un champ verrouillé par Bricks est respecté', function (): void {
	// La largeur du bureau peut être verrouillée : y écrire produirait une valeur
	// que Bricks ignore, et un curseur qui ne correspond plus à ce qu'on voit.
	assert_true( str_contains( code(), 'champ.readOnly' ), 'le verrou de largeur n’est plus consulté' );
} );

test( 'la dernière classe est prise, pas la première', function (): void {
	// C'est la dernière de la cascade qui porte le style effectif. Prendre la
	// première ferait modifier une règle que la suivante écrase : l'utilisateur
	// verrait son changement ne rien faire.
	assert_true(
		str_contains( script(), 'liste[liste.length - 1]' ),
		'la sélection ne porte plus sur la dernière classe'
	);
} );

test( 'un panneau encore vide n’est pas pris pour un élément sans classes', function (): void {
	/*
	 * Le défaut constaté à l'usage : la fonction ne marchait qu'à partir du
	 * **deuxième** élément sélectionné. Le panneau se remplit après que la ligne
	 * devient active ; retenir l'élément à cet instant le condamnait — on ne
	 * repassait plus jamais dessus.
	 */
	assert_true(
		str_contains( code(), 'if (!liste.length) return;' ),
		'un panneau vide fait de nouveau oublier l’élément'
	);
} );

test( 'aucune notion d’« élément courant » ne subsiste', function (): void {
	/*
	 * Le défaut constaté trois fois de suite : « ça ne marche qu'à partir du
	 * deuxième élément ». Deux tentatives ont cherché à savoir *quel* élément on
	 * regardait — la ligne active de la structure, puis un repli sur la liste des
	 * classes — et les deux ont échoué pour la même raison : l'identité de
	 * l'élément et l'arrivée des pastilles dans le panneau ne sont pas
	 * simultanées, donc le premier passage retenait un élément sans liste et l'on
	 * ne repassait plus.
	 *
	 * La question n'avait pas à être posée. La règle est sans état : s'il y a des
	 * classes et qu'aucune n'est active, on active la dernière. Ce test interdit
	 * le retour du suivi d'élément, qui est la forme même du défaut.
	 */
	assert_true(
		! str_contains( code(), 'dernierElement' ),
		'le suivi d’élément est de retour : c’est ce qui cassait le premier clic'
	);
	assert_true(
		! str_contains( code(), 'elementCourant' ),
		'la notion d’élément courant est de retour'
	);
} );

test( 'le bouton du panneau est le seul juge de ce qui s’ouvre', function (): void {
	/*
	 * La cause unique des trois défauts de la 1.5.0, et elle n'était pas dans la
	 * logique : elle était dans les repères que cette logique consultait.
	 *
	 * Deux repères avaient été supposés pour savoir *quel* composant le bouton
	 * ouvrirait. Le relevé de douze états d'un Bricks 2.3.10 — dont quatre en édition
	 * de composant — les a démentis tous les deux : la racine affichée en édition
	 * déclare le même parent qu'au niveau de la page, et la classe `component` y suit
	 * la sélection au lieu de marquer les instances. Les deux fonctions écrites pour
	 * discriminer ne discriminaient rien, et les onze cas de comportement passaient
	 * quand même — c'est le bouton qui les tenait, sans que ce soit écrit nulle part.
	 *
	 * Ce test épingle donc le mécanisme réel : les deux gestionnaires lisent le
	 * bouton, et ils n'ont rien d'autre à consulter.
	 */
	assert_same( 1, substr_count( code(), 'boutonDuPanneau()' ), 'le gestionnaire ne lit pas le bouton' );

	// Les deux fonctions de déduction sont retirées, pas corrigées : il n'y a rien à
	// déduire d'un DOM qui ne porte pas l'information.
	foreach ( [ 'ouvrable', 'enEditionDeComposant', 'composantPortant', 'ligneParente' ] as $disparue ) {
		assert_true(
			! str_contains( code(), $disparue ),
			"« {$disparue} » est de retour : elle déduisait le mode d’édition d’un repère mesuré faux"
		);
	}
} );

test( 'aucun repère de mode d’édition n’est de retour', function (): void {
	/*
	 * Le test le plus utile du fichier, et c'est un test d'absence.
	 *
	 * Deux versions de suite ont cru disposer d'un repère franc du mode d'édition,
	 * l'ont documenté comme « mesuré », et se sont trompées. Le relevé qui a tranché
	 * portait sur douze états, quatre en édition : **la racine affichée en édition de
	 * composant déclare le même parent qu'au niveau de la page.** La garde bâtie
	 * dessus rendait `false` partout, sur les deux versions.
	 *
	 * Aucun autre repère n'a été trouvé dans ce build — ni classe sur le corps du
	 * document, ni fil d'Ariane, ni ligne sans parent. Le nombre de racines distingue
	 * bien les deux modes dans le décor du test, mais une page à une seule section le
	 * mettrait en défaut : ce n'est pas un repère, c'est une coïncidence de décor.
	 *
	 * Tant que la mesure n'a pas produit un repère vrai, en écrire un est un défaut :
	 * il donne l'illusion d'un filet de sécurité là où il n'y en a pas. La règle est
	 * donc « pas de mode d'édition dans ce fichier », et ce test la tient.
	 */
	foreach ( [ 'data-parent-id', 'racines', 'estRacine' ] as $repere ) {
		assert_true(
			! str_contains( code(), $repere ),
			"« {$repere} » est de retour : mesuré, il ne distingue pas l’édition d’un composant du niveau de la page"
		);
	}

	// Et le mode n'est pas davantage déduit du nombre de lignes affichées, qui serait
	// la variante suivante du même défaut.
	assert_true(
		! str_contains( code(), 'lignes().length' ),
		'le mode est déduit du nombre de lignes — vrai dans ce décor, faux sur une page à une seule section'
	);
} );

test( 'double-cliquer un enfant ne remonte pas au composant qui le contient', function (): void {
	/*
	 * Le symptôme « juste sélectionner un élément présent dans le composant nous fait
	 * quitter », vu depuis la structure : les lignes y sont imbriquées, et
	 * `closest('#bricks-structure li.element.component')` depuis un enfant rendait
	 * l'instance qui le porte. Un double-clic destiné au renommage d'une carte
	 * ouvrait donc le composant.
	 *
	 * La lecture porte sur le **gestionnaire de la structure** seul, et c'est le
	 * correctif de ce test : le canevas, lui, doit remonter — Bricks y sélectionne
	 * l'élément et non l'instance. Un test qui lisait le fichier entier ne pouvait pas
	 * distinguer les deux, et il n'interdisait plus rien du jour où le canevas s'est
	 * mis à remonter légitimement.
	 */
	assert_true(
		! str_contains( gestionnaire( 'structure' ), 'li.element.component' ),
		'la remontée au composant ancêtre est de retour dans la structure'
	);

	// La ligne visée est celle qu'on a cliquée, et sa qualité de composant se lit sur
	// elle seule — c'est la garde de niveau de page, le seul endroit où la classe dit
	// la vérité.
	assert_true(
		str_contains( gestionnaire( 'structure' ), 'closest?.(selecteurLigne)' ),
		'la ligne cliquée n’est plus celle qu’on juge'
	);
	assert_true(
		str_contains( gestionnaire( 'structure' ), "ligne.classList.contains('component')" ),
		'la garde de niveau de page a disparu — c’est le seul filet si le bouton se mettait à suivre la sélection'
		. ' avec un rendu de retard ; ce qu’elle empêche est éprouvé par tests/test-decision.mjs'
	);
} );

test( 'chaque garde dit où elle est éprouvée', function (): void {
	/*
	 * Le défaut du tour précédent, et il n'était pas dans le code : trois gardes de
	 * `composant_dblclic` ont été retirées une à une du fichier servi par la
	 * préproduction, et le harnais en ligne a rendu 53 cas verts à chaque fois. La
	 * cause est unique — l'oracle du bouton tranche seul dans tous les états qu'un
	 * vrai builder produit, parce que le bouton disparaît dans le même tour synchrone
	 * que le changement de sélection. Les gardes protègent d'un Bricks qui n'existe
	 * pas encore : aucun clic ne peut les atteindre.
	 *
	 * Elles sont donc éprouvées ailleurs, par un DOM de poche où l'état se pose au
	 * lieu de s'attendre. Ce test tient le lien : une garde qui ne nomme pas son juge
	 * est une garde que la prochaine réécriture supprimera sans rien casser.
	 */
	assert_true(
		str_contains( script(), 'tests/test-decision.mjs' ),
		'les gardes ne disent plus où elles sont éprouvées — c’est ce qui a laissé passer trois mutations silencieuses'
	);

	// Et la justification démentie ne revient pas. Elle affirmait que le bouton était
	// « en retard d'un rendu de Vue sur le geste » : mesuré faux, à 0 ms puis sur
	// 400 ms de relevé continu.
	assert_true(
		! str_contains( script(), 'en retard d’un rendu de Vue sur le geste' ),
		'une mesure démentie est de retour dans les commentaires : c’est ce qu’on corrige, pas ce qu’on recopie'
	);

	// Le fichier de décision existe, et il exécute réellement le script — un chemin
	// mort dans un commentaire vaudrait la garde qu'il prétend justifier.
	$decision = __DIR__ . '/test-decision.mjs';

	assert_true( file_exists( $decision ), 'tests/test-decision.mjs est nommé par le script mais absent' );

	$source = (string) file_get_contents( $decision ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	assert_true(
		str_contains( $source, "monter(" ) && str_contains( $source, 'doublure-dom.mjs' ),
		'le fichier de décision n’exécute plus le script : il ne prouverait rien'
	);
} );


test( 'le canevas ne coupe aucun geste', function (): void {
	/*
	 * Rien à devancer dans le canevas : Bricks n'y lie pas le double-clic au
	 * renommage. Y couper l'événement casserait la sélection, le déplacement et
	 * l'édition en place, pour rien. Le seul blocage légitime est celui de la
	 * structure, qui devance le mode « renommer ».
	 */
	assert_same( 1, substr_count( code(), 'preventDefault' ), 'un blocage a été ajouté hors de la structure' );
	assert_same( 1, substr_count( code(), 'stopImmediatePropagation' ), 'un blocage a été ajouté hors de la structure' );
} );


test( 'le canevas n’est plus écouté, et ne doit pas revenir', function (): void {
	/*
	 * Le double-clic y ouvrait aussi un composant. Retiré sur constat d'usage : une
	 * fois **dans** le composant, il devenait impossible d'y sélectionner un élément
	 * enfant au canevas.
	 *
	 * La cause est de forme, et elle ne se corrige pas par une garde de plus — trois
	 * ont été essayées, chacune réparant le symptôme de la précédente. Bricks
	 * sélectionne au canevas l'élément cliqué et non l'instance qui le porte, et le
	 * bouton du panneau réapparaît pour tout composant imbriqué : chaque double-clic
	 * sur un enfant rouvrait donc quelque chose au lieu de le sélectionner.
	 *
	 * Ce test interdit le retour de l'écouteur. Un seul chemin d'entrée, dans la
	 * structure, vaut mieux que deux dont l'un est piégé.
	 */
	foreach ( [ 'ecouterLeCanevas', 'suivreLeCanevas', 'contentWindow', 'dansUneInstance' ] as $trace ) {
		assert_true(
			! str_contains( code(), $trace ),
			sprintf( 'le volet canevas est de retour : %s', $trace )
		);
	}

	// Un seul gestionnaire de double-clic, et il vise la structure.
	assert_same( 1, substr_count( code(), "addEventListener('dblclick'" ), 'nombre de gestionnaires' );
} );

test( 'trois clics rapides ne produisent qu’une seule action', function (): void {
	// Trois clics forment **deux** `dblclick` — les clics 1‑2, puis 2‑3. Le second
	// retombait sur le gestionnaire avant que le panneau ait basculé, donc sur un
	// bouton encore présent, et faisait ressortir aussitôt.
	assert_true( str_contains( code(), 'tropTot' ), 'le verrou de temps a disparu' );
	assert_same( 1, substr_count( code(), 'tropTot()' ), 'le verrou ne protège plus le gestionnaire' );
} );

test( 'le double-clic ne devance Bricks que s’il a de quoi ouvrir', function (): void {
	/*
	 * La régression la plus grave de cette série : couper le geste d'abord et
	 * chercher le bouton ensuite. Une fois **dans** un composant il n'y a plus rien
	 * à ouvrir, mais la ligne racine porte toujours `component` — le renommage au
	 * double-clic disparaissait sans rien en échange, et le canevas devenait
	 * inerte. Le bouton doit être lu **avant** de bloquer quoi que ce soit.
	 */
	$corps = code();
	$position_bouton = strpos( $corps, 'const bouton = boutonDuPanneau();' );
	$position_blocage = strpos( $corps, 'evenement.preventDefault();' );

	assert_true( false !== $position_bouton, 'la lecture synchrone du bouton a disparu' );
	assert_true( false !== $position_blocage, 'le blocage a disparu' );
	assert_true(
		$position_bouton < $position_blocage,
		'le geste est encore bloqué avant de savoir s’il y a un composant à ouvrir'
	);
} );


test( 'une désélection volontaire est retenue', function (): void {
	/*
	 * Le piège de cette fonction : ré-activer aussitôt une classe que
	 * l'utilisateur vient de désactiver. Styler l'`id` est parfois légitime, et se
	 * battre contre le curseur de quelqu'un est le pire défaut possible pour un
	 * confort d'interface.
	 */
	assert_true( str_contains( script(), 'laissees' ), 'la mémoire des désélections a disparu' );
	assert_true( str_contains( script(), 'laissees.has' ), 'la mémoire n’est plus consultée' );
} );

test( 'aucune fonction interne de Vue n’est appelée', function (): void {
	/*
	 * `$_setActiveComponent`, `$_state` et compagnie ne sont atteignables qu'à
	 * travers une instance Vue, et `__vueParentComponent` n'existe pas dans un
	 * build de production. Les viser marcherait sur un poste de développement de
	 * Bricks et nulle part ailleurs.
	 */
	foreach ( [ '__vueParentComponent', '__vue_app__', '$_setActiveComponent', '$_state' ] as $interne ) {
		assert_true(
			! str_contains( code(), $interne ),
			sprintf( 'le script touche à un interne de Vue : %s', $interne )
		);
	}
} );

test( 'aucune fonction déclarée n’est morte', function (): void {
	/*
	 * `patienter()` a vécu deux versions sans être appelée une seule fois : trente
	 * lignes de code et de commentaire décrivant une attente qui n'attendait rien.
	 * Personne ne l'a vu, et c'est normal — du code mort ne produit aucun symptôme.
	 *
	 * Le coût n'est pas la place qu'il prend : c'est qu'il se lit comme du mécanisme.
	 * Le rapport de mesure a dû aller vérifier par `grep` qu'il ne servait pas, et
	 * deux relectures avaient conclu que le composant faisait ce que ce code décrivait.
	 *
	 * Ce test relève chaque `const` du script et exige qu'il soit **employé** ailleurs
	 * que dans sa propre déclaration. Il ne comprend pas les portées : c'est sans
	 * conséquence ici, un nom employé quelque part est un nom qui sert.
	 */
	preg_match_all( '/\bconst ([A-Za-z][A-Za-z0-9]*) = /', code(), $trouves );

	$declares = array_unique( $trouves[1] );

	assert_true( count( $declares ) > 20, 'les déclarations ne sont plus reconnues : ' . count( $declares ) );

	$mortes = [];

	foreach ( $declares as $nom ) {
		if ( preg_match_all( '/\b' . preg_quote( $nom, '/' ) . '\b/', code() ) < 2 ) {
			$mortes[] = $nom;
		}
	}

	assert_same( [], $mortes, 'déclarée et jamais employée — du code mort se lit comme du mécanisme' );
} );

test( 'une fonction en échec n’emporte pas les autres', function (): void {
	// Bricks change de version sans nous prévenir : le jour où une accroche
	// disparaît, les deux autres fonctions doivent continuer.
	assert_true( str_contains( script(), 'try {' ), 'aucune protection autour du démarrage' );
	assert_true( str_contains( script(), 'console.warn' ), 'un échec ne laisse aucune trace' );
} );

/* --- Le CSS des classes globales dans le canevas ---------------------- */

echo "\nClasses globales du canevas\n";

/*
 * Ces tests-ci ne lisent pas du texte : ils **exécutent** l'indexation. C'est
 * possible parce qu'elle est pure — pas de WordPress, pas de Bricks. Ce qui
 * demande Bricks, c'est la génération du CSS elle-même, et on ne la double pas :
 * la réécrire ici reviendrait à tester notre idée du générateur plutôt que le
 * générateur. Cette part est mesurée sur un site servi, par
 * `bin/check-canevas.mjs`.
 */

test( 'une classe est indexée sous le type d’élément qui la porte', function (): void {
	$carte = [];

	indexer_elements(
		[
			[ 'name' => 'section', 'settings' => [ '_cssGlobalClasses' => [ 'abc' ] ] ],
			[ 'name' => 'heading', 'settings' => [ '_cssGlobalClasses' => [ 'abc', 'def' ] ] ],
		],
		$carte
	);

	// Bricks émet `.classe.brxe-<type>` : le type manquant est une règle manquante.
	assert_same( [ 'section', 'heading' ], $carte['abc'] );
	assert_same( [ 'heading' ], $carte['def'] );
} );

test( 'un identifiant numérique désigne bien sa classe', function (): void {
	$carte = [];

	indexer_elements( [ [ 'name' => 'block', 'settings' => [ '_cssGlobalClasses' => [ 358029 ] ] ] ], $carte );

	/*
	 * On ne peut pas exiger une clé de type chaîne, et c'est PHP qui l'impose :
	 * une clé numérique canonique est reconvertie en entier à l'écriture, quel
	 * que soit le `(string)` posé avant. « 085233 » — un identifiant réel du parc
	 * — y échappe, parce que sa forme n'est pas canonique.
	 *
	 * Ce qui compte est donc que Bricks la retrouve : `generate_global_classes()`
	 * cherche par `array_search()` **non strict**, où `358029 == '358029'` est
	 * vrai. Ce test épingle cette équivalence plutôt que le type, sans quoi il
	 * exigerait l'impossible et pousserait à contourner ce qui fonctionne.
	 */
	assert_same( [ '358029' ], array_map( 'strval', array_keys( $carte ) ) );
	assert_true(
		false !== array_search( array_key_first( $carte ), [ '358029' ] ), // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
		'Bricks ne retrouverait pas la classe'
	);
} );

test( 'un même type n’est pas compté deux fois', function (): void {
	$carte = [];

	indexer_elements(
		[
			[ 'name' => 'block', 'settings' => [ '_cssGlobalClasses' => [ 'abc' ] ] ],
			[ 'name' => 'block', 'settings' => [ '_cssGlobalClasses' => [ 'abc' ] ] ],
		],
		$carte
	);

	assert_same( [ 'block' ], $carte['abc'] );
} );

test( 'un élément sans réglages ne casse pas l’indexation', function (): void {
	$carte = [];

	// PHP encode un tableau vide en `[]`, jamais en `{}` : `settings` arrive donc
	// comme une liste sur toute instance de composant.
	indexer_elements(
		[
			[ 'name' => 'block', 'settings' => [] ],
			[ 'name' => 'block' ],
			[ 'name' => '', 'settings' => [ '_cssGlobalClasses' => [ 'abc' ] ] ],
			'pas un élément',
		],
		$carte
	);

	assert_same( [], $carte, 'un élément sans type ne doit rien indexer' );
} );

test( 'une variante expose les classes de toutes ses options', function (): void {
	$propriete = [
		'type'    => 'class',
		'default' => [ 'ghost' ],
		'options' => [
			[ 'id' => 'ghost',   'value' => [ '111', '222' ] ],
			[ 'id' => 'primary', 'value' => [ '111', '333' ] ],
		],
	];

	// Pas seulement l'option par défaut : changer de variante dans le builder ne
	// doit pas laisser l'élément sans style jusqu'au rechargement.
	assert_same( [ '111', '222', '333' ], ids_de_propriete( $propriete ) );
} );

test( 'sans options, le défaut d’une propriété désigne des classes', function (): void {
	assert_same( [ '444' ], ids_de_propriete( [ 'type' => 'class', 'default' => [ '444' ] ] ) );
} );

test( 'une propriété de classe est reliée au type de l’élément connecté', function (): void {
	$carte = [];

	indexer_proprietes_classe(
		[
			'elements'   => [
				[ 'id' => 'e1', 'name' => 'button' ],
				[ 'id' => 'e2', 'name' => 'heading' ],
			],
			'properties' => [
				[
					'type'        => 'class',
					'default'     => [ 'ghost' ],
					'options'     => [ [ 'id' => 'ghost', 'value' => [ '111' ] ] ],
					'connections' => [ 'e1' => [ '_cssGlobalClasses' ] ],
				],
			],
		],
		$carte
	);

	assert_same( [ 'button' ], $carte['111'] ?? [], 'la classe doit suivre l’élément relié' );
} );

test( 'une propriété qui n’est pas de type class est ignorée', function (): void {
	$carte = [];

	indexer_proprietes_classe(
		[
			'elements'   => [ [ 'id' => 'e1', 'name' => 'button' ] ],
			'properties' => [
				[ 'type' => 'text', 'default' => [ '111' ], 'connections' => [ 'e1' => [ 'text' ] ] ],
			],
		],
		$carte
	);

	assert_same( [], $carte );
} );

/*
 * Le poids des règles — la limite du 03/08/2026, et sa levée.
 *
 * Ces quatre tests-ci tiennent la **décision**, pas la génération : quelle
 * classe a le droit d'être servie plus lourd. C'est là que le défaut se joue, et
 * c'est mesurable hors ligne parce que la décision est pure.
 *
 * Ce qu'ils ne peuvent pas dire : que Bricks émet bien le sélecteur doublé. Ça
 * se mesure dans un navigateur, des deux côtés — `bin/check-canevas.mjs`.
 */

test( 'une classe qu’aucun élément ne porte est servie plus lourd', function (): void {
	// `c-entete--centre` n'existe que dans l'option d'une variante : le
	// JavaScript du canevas n'émet rien pour elle, et sa classe de base la
	// recouvrait. Mesuré : `row flex-end` dans le canevas, `column center` sur le
	// front.
	assert_same(
		[ 'modif' => [ 'block' ] ],
		classes_hors_index( [ 'base' => [ 'block' ] ], [ 'modif' => [ 'block' ] ] )
	);
} );

test( 'une classe portée par un élément n’est jamais alourdie', function (): void {
	/*
	 * C'est le test d'absence qui protège le §5. Bricks émet une balise pour
	 * toute classe présente dans le `_cssGlobalClasses` d'un élément de composant
	 * — 79 sur 79, mesuré le 03/08/2026 sur mon-site. L'alourdir ferait gagner
	 * notre valeur figée contre celle du panneau : une modification faite dans le
	 * builder n'apparaîtrait plus, et l'on aurait créé le défaut inverse.
	 */
	assert_same(
		[],
		classes_hors_index(
			[ 'partagee' => [ 'button' ] ],
			[ 'partagee' => [ 'button' ] ]
		),
		'une classe portée par un élément a déjà sa balise'
	);
} );

test( 'seul le nom des classes visées est doublé', function (): void {
	\Bricks\Database::$global_data['globalClasses'] = [
		[ 'id' => '111', 'name' => 'c-entete' ],
		[ 'id' => '222', 'name' => 'c-entete--centre' ],
		[ 'id' => '333', 'name' => '' ],
	];

	$avant = doubler_les_noms( [ '222', '333' ] );

	// Bricks construit son sélecteur depuis le nom : `.c-x.c-x` sort en 0-3-0, et
	// la substitution suit les sous-sélecteurs sans qu'on les connaisse.
	assert_same(
		[ 'c-entete', 'c-entete--centre.c-entete--centre', '' ],
		array_column( \Bricks\Database::$global_data['globalClasses'], 'name' )
	);

	// La valeur rendue est ce qui permet de restaurer : sans elle, la feuille du
	// front hériterait de noms doublés pour le reste de la requête.
	assert_same( 'c-entete--centre', $avant[1]['name'], 'l’état d’avant n’est pas rendu' );

	\Bricks\Database::$global_data['globalClasses'] = $avant;
} );

test( 'les noms doublés sont rendus même en cas d’échec', function (): void {
	// Même raison que le `finally` des propriétés statiques : une génération qui
	// lève laisserait `.c-x.c-x` dans les classes globales, et le front servirait
	// ce sélecteur à chaque visiteur.
	assert_true(
		str_contains( code_php(), '\Bricks\Database::$global_data[\'globalClasses\'] = $classes;' ),
		'les noms doublés ne sont pas restaurés dans le finally'
	);
} );

test( 'la feuille n’est servie que dans le canevas', function (): void {
	// Un test d'absence : émise sur le front, elle doublerait le CSS de Bricks
	// sur chaque page vue par un visiteur. La garde est le seul rempart.
	assert_true(
		str_contains( code_php(), 'bricks_is_builder_iframe' ),
		'aucune garde de canevas : la feuille partirait sur le front'
	);
} );

test( 'les propriétés statiques de Bricks sont rendues même en cas d’échec', function (): void {
	// Sans `finally`, une génération qui lève laisserait notre carte en place
	// pour le reste de la requête — et Bricks émettrait le CSS d'une autre page.
	assert_true( str_contains( code_php(), 'finally' ), 'aucune restauration garantie' );
	assert_true(
		substr_count( code_php(), '$sauvegarde[' ) >= 6,
		'toutes les propriétés empruntées ne sont pas rendues'
	);
} );

/* --- Compte rendu ---------------------------------------------------- */

echo "\n{$passed} test(s) réussi(s), {$failed} échec(s).\n";

exit( $failed > 0 ? 1 : 0 );
