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
 * client. Ils ne prouvent pas que Bricks les émet encore — cela demande un vrai
 * builder, et c'est le rôle de `./bin/test-interactions.mjs`.
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

test( 'les trois fonctions sont actives par défaut', function (): void {
	assert_same(
		[ 'classe_active' => true, 'curseur_largeur' => true, 'composant_dblclic' => true ],
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

	assert_same( 3, count( $actives ), 'nombre de fonctions' );
	assert_same( true, $actives['curseur_largeur'], 'la vraie fonction a été éteinte par une faute de frappe' );
} );

test( 'un filtre qui ne rend pas un tableau est ignoré', function (): void {
	global $etat;

	$etat['filtres']['anode/builder/fonctions'] = 'oui';

	assert_same( 3, count( fonctions_actives() ) );
	assert_same( true, fonctions_actives()['classe_active'] );
} );

test( 'les trois fonctions éteintes, rien n’est chargé', function (): void {
	global $etat;

	$etat['filtres']['anode/builder/fonctions'] = [
		'classe_active'     => false,
		'curseur_largeur'   => false,
		'composant_dblclic' => false,
	];

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

test( 'chaque sélecteur relevé est présent dans le script', function (): void {
	/*
	 * Ces cinq sélecteurs ont été relevés dans un builder Bricks 2.3.10, en
	 * ouvrant une page qui contient un composant. Ce ne sont pas des suppositions
	 * — et c'est pour cela qu'ils sont épinglés : une réécriture qui en perd un
	 * rend une fonction inerte, sans erreur ni trace.
	 */
	$attendus = [
		'.active-classes li'                                       => 'les classes de l’élément',
		'#bricks-toolbar input#preview-width'                      => 'la largeur du canevas',
		'#bricks-structure li.element.active'                      => 'l’élément sélectionné',
		'#bricks-structure li.element.component'                   => 'une ligne de composant',
		'#bricks-panel span.bricks-svg-wrapper.component'          => 'le bouton d’édition du composant',
	];

	foreach ( $attendus as $selecteur => $role ) {
		assert_true(
			str_contains( script(), $selecteur ),
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

test( 'l’élément est identifié même sans le panneau de structure', function (): void {
	/*
	 * Le défaut constaté deux fois de suite à l'usage. La clé venait du `data-id`
	 * de la ligne active de la structure : elle existe quand on sélectionne
	 * **dans** la structure, et pas forcément quand on sélectionne dans le
	 * canevas — panneau replié, parent fermé, la ligne n'est pas rendue. La clé
	 * valait alors `null`, et rien ne se passait ; au clic suivant la structure
	 * s'était déployée, d'où « ça ne marche qu'à partir du deuxième élément ».
	 */
	assert_true(
		str_contains( code(), 'parLaStructure' ),
		'la structure n’est plus un simple raffinement de la clé'
	);
	assert_true(
		str_contains( code(), 'liste.map(identifiantDe)' ),
		'il n’y a plus de repli sur la liste des classes'
	);
} );

test( 'le double-clic agit aussi dans le canevas', function (): void {
	// C'est là qu'on passe le plus clair de son temps ; ne l'avoir que dans la
	// structure, c'est ne l'avoir qu'à moitié.
	assert_true( str_contains( code(), 'ecouterLeCanevas' ), 'le canevas n’est plus écouté' );

	// L'iframe est remplacée à chaque rechargement du canevas : un écouteur posé
	// une seule fois disparaîtrait avec elle, sans rien dire.
	assert_true( str_contains( code(), "addEventListener('load'" ), 'l’écouteur n’est pas reposé au rechargement' );
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

test( 'une fonction en échec n’emporte pas les autres', function (): void {
	// Bricks change de version sans nous prévenir : le jour où une accroche
	// disparaît, les deux autres fonctions doivent continuer.
	assert_true( str_contains( script(), 'try {' ), 'aucune protection autour du démarrage' );
	assert_true( str_contains( script(), 'console.warn' ), 'un échec ne laisse aucune trace' );
} );

/* --- Compte rendu ---------------------------------------------------- */

echo "\n{$passed} test(s) réussi(s), {$failed} échec(s).\n";

exit( $failed > 0 ? 1 : 0 );
