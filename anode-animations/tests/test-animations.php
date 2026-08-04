<?php
/**
 * Tests des animations — sans WordPress, sans navigateur.
 *
 * Ce que ces tests peuvent faire, et ce qu'ils ne peuvent pas : ils lisent le
 * CSS et le JavaScript **émis dans la page**, et disent qu'un mécanisme est
 * écrit. Ils ne disent pas qu'il tourne. Le juge du comportement reste un vrai
 * navigateur, sur le site servi :
 *
 *     ./bin/test-interactions.mjs https://<site>/<page>/ 1440
 *
 * Trois questions valent quand même d'être posées ici, parce que chacune porte
 * un défaut **silencieux** — rien ne s'affiche dans la console, et aucune
 * capture ne le montre.
 *
 *   1. **Une animation nommée par le moteur sans keyframe correspondante.** Un
 *      élément portant `animate="…"` est masqué (`opacity: 0`) : si le keyframe
 *      n'existe pas, il est masqué **pour toujours**. C'est un contenu perdu,
 *      et la page paraît simplement vide à cet endroit.
 *   2. **Le contrat public.** L'attribut `animate` est employé par les pages des
 *      sites. Le renommer les laisse sans animation, donc invisibles.
 *   3. **Le respect de `prefers-reduced-motion`.** Personne ne s'en aperçoit en
 *      naviguant, et c'est le critère WCAG 2.3.3.
 *
 * Lancement : php blueprint/mu-plugins/anode-animations/tests/test-animations.php
 *
 * @package Anode\Animations
 */

declare( strict_types = 1 );

namespace Anode\Animations;

define( 'ABSPATH', __DIR__ );

/* --- Doublures WordPress --------------------------------------------- */

/**
 * Accroches enregistrées au chargement du fichier.
 *
 * @var array<string, array<int, array{0: mixed, 1: int}>>
 */
$accroches = [];

function add_action( string $accroche, $rappel = null, int $priorite = 10 ): void {
	global $accroches;

	$accroches[ $accroche ][] = [ $rappel, $priorite ];
}

require_once __DIR__ . '/../anode-animations.php';

/* --- Ce que la page reçoit ------------------------------------------- */

/** Le fichier de l'extension, tel quel. */
function fichier(): string {
	static $contenu = null;

	if ( null === $contenu ) {
		$contenu = (string) file_get_contents( __DIR__ . '/../anode-animations.php' );
	}

	return $contenu;
}

/** Le CSS émis dans `wp_head`. */
function css(): string {
	static $sortie = null;

	if ( null === $sortie ) {
		ob_start();
		Animations::inject_css();
		$sortie = (string) ob_get_clean();
	}

	return $sortie;
}

/** Le JavaScript émis dans `wp_footer`. */
function script(): string {
	static $sortie = null;

	if ( null === $sortie ) {
		ob_start();
		Animations::inject_js();
		$sortie = (string) ob_get_clean();
	}

	return $sortie;
}

/**
 * Les noms d'animation déclarés par les keyframes, `cursor-blink` excepté.
 *
 * `cursor-blink` n'est pas une valeur d'`animate` : il n'est employé que par
 * `.bas-cursor`, en interne.
 *
 * @return array<int, string>
 */
function animations_declarees(): array {
	preg_match_all( '/@keyframes\s+bas-([a-z0-9-]+)/', css(), $trouvees );

	return array_values( array_diff( $trouvees[1], [ 'cursor-blink' ] ) );
}

/**
 * Les noms cités par un ensemble du moteur (`OPACITY_MANAGED`, `LOOP_BY_DEFAULT`).
 *
 * @return array<int, string>
 */
function noms_de_lensemble( string $nom ): array {
	if ( ! preg_match( '/const\s+' . preg_quote( $nom, '/' ) . '\s*=\s*new Set\(\[(.*?)\]\)/s', script(), $bloc ) ) {
		throw new \RuntimeException( "ensemble {$nom} introuvable dans le moteur" );
	}

	preg_match_all( "/'([a-z0-9-]+)'/", $bloc[1], $noms );

	return $noms[1];
}

/* --- Harnais --------------------------------------------------------- */

$passed = 0;
$failed = 0;

function test( string $nom, callable $fn ): void {
	global $passed, $failed;

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

/* --- Chargement ------------------------------------------------------ */

echo "\nChargement\n";

test( 'le CSS part dans l’en-tête, le moteur dans le pied', function () use ( $accroches ): void {
	assert_true( isset( $accroches['wp_head'] ), 'rien sur wp_head' );
	assert_true( isset( $accroches['wp_footer'] ), 'rien sur wp_footer' );

	// Priorité tardive : le CSS doit passer après celui du thème et de Bricks.
	assert_same( 99, $accroches['wp_head'][0][1], 'priorité de wp_head' );
	assert_same( 99, $accroches['wp_footer'][0][1], 'priorité de wp_footer' );
} );

test( 'l’extension est signée Anode', function (): void {
	assert_true( (bool) preg_match( '/Plugin Name:\s*Anode — /', fichier() ), 'nom d’extension hors convention' );
	assert_true( (bool) preg_match( '/Author:\s*Anode\s*$/m', fichier() ), 'auteur absent ou autre' );
	assert_true( (bool) preg_match( '/^namespace Anode\\\\Animations;$/m', fichier() ), 'namespace hors convention' );
} );

test( 'aucune ressource distante', function (): void {
	// Une bibliothèque d'animation chargée par CDN, c'est exactement ce que
	// cette extension remplace. Le vérifier ici évite qu'elle y revienne.
	assert_same( 0, preg_match( '~https?://~', css() . script() ), 'une URL absolue dans ce qui est servi' );
} );

/* --- Le contrat public ----------------------------------------------- */

echo "\nContrat public\n";

test( 'l’attribut s’appelle « animate »', function (): void {
	// Les pages des sites portent ce nom. Le changer les laisse sans animation,
	// donc masquées, sans un mot dans la console.
	assert_true( str_contains( script(), "const ANIMATTR = 'animate';" ), 'le moteur ne lit plus « animate »' );
	assert_true( str_contains( css(), '[animate]:not([animate="no-hide"])' ), 'l’état initial ne vise plus « animate »' );
} );

test( '« no-hide » laisse l’élément visible', function (): void {
	assert_true( str_contains( css(), '[animate="no-hide"]' ), 'plus d’échappatoire au masquage en CSS' );
	assert_true( str_contains( script(), "animName === 'no-hide'" ), 'le moteur ignore le cas no-hide' );
} );

test( 'le préfixe et l’API gardent leur nom', function (): void {
	assert_true( str_contains( script(), "const PREFIX   = 'bas-';" ), 'préfixe des keyframes renommé' );
	assert_true( str_contains( script(), 'window.BAS = {' ), 'API publique renommée' );
	assert_true( str_contains( css(), '.bas-cursor::after' ), 'classe du curseur renommée' );
} );

/* --- Les animations -------------------------------------------------- */

echo "\nAnimations\n";

test( 'toute animation citée par le moteur a son keyframe', function (): void {
	$declarees = animations_declarees();
	$manquantes = [];

	foreach ( [ 'OPACITY_MANAGED', 'LOOP_BY_DEFAULT' ] as $ensemble ) {
		foreach ( noms_de_lensemble( $ensemble ) as $nom ) {
			if ( ! in_array( $nom, $declarees, true ) ) {
				$manquantes[] = "{$ensemble}:{$nom}";
			}
		}
	}

	assert_same( [], $manquantes, 'un élément animé ainsi resterait masqué à jamais' );
} );

test( 'les origines imposées visent des animations qui existent', function (): void {
	preg_match( '/const ORIGINS = \{(.*?)\};/s', script(), $bloc );
	preg_match_all( "/'([a-z0-9-]+)'\s*:/", $bloc[1] ?? '', $noms );

	$inconnues = array_diff( $noms[1], animations_declarees() );

	assert_same( [], array_values( $inconnues ), 'origine posée sur une animation inexistante' );
} );

test( 'les quarante-neuf animations sont là', function (): void {
	// Le compte est calculé, pas recopié : il tombe avec la liste.
	$declarees = animations_declarees();

	assert_same( count( $declarees ), count( array_unique( $declarees ) ), 'un keyframe déclaré deux fois' );
	assert_same( 49, count( $declarees ), 'le nombre d’animations a changé — docs/animations.md est à reprendre' );
} );

test( 'la documentation cite chaque animation', function (): void {
	$doc = __DIR__ . '/../../../../docs/animations.md';

	if ( ! is_readable( $doc ) ) {
		// Le module voyage seul dans l'archive de mise à jour : hors du dépôt,
		// il n'y a pas de documentation à confronter.
		return;
	}

	$texte    = (string) file_get_contents( $doc );
	$absentes = [];

	foreach ( animations_declarees() as $nom ) {
		if ( ! str_contains( $texte, "`{$nom}`" ) ) {
			$absentes[] = $nom;
		}
	}

	assert_same( [], $absentes, 'animation disponible et non documentée' );
} );

/* --- Accessibilité --------------------------------------------------- */

echo "\nAccessibilité\n";

test( 'prefers-reduced-motion coupe le CSS et le moteur', function (): void {
	assert_true( str_contains( css(), '@media (prefers-reduced-motion: reduce)' ), 'aucune neutralisation en CSS' );
	assert_true(
		str_contains( script(), "window.matchMedia('(prefers-reduced-motion: reduce)').matches" ),
		'le moteur démarre malgré le refus du mouvement'
	);
} );

test( 'le mouvement refusé rend l’élément visible', function (): void {
	// Neutraliser l'animation sans rétablir l'opacité masquerait la page
	// entière pour qui a refusé le mouvement.
	preg_match( '/@media \(prefers-reduced-motion: reduce\) \{(.*?)\n\}/s', css(), $bloc );

	assert_true( str_contains( $bloc[1] ?? '', 'opacity: 1 !important' ), 'l’état initial masqué n’est pas levé' );
} );

/* --- Défauts déjà payés ---------------------------------------------- */

echo "\nDéfauts déjà payés\n";

test( 'la parallaxe se relâche à chaque image', function (): void {
	// Un drapeau posé et jamais relâché ne laisse passer que le premier
	// défilement : la parallaxe se figeait après quelques pixels.
	//
	// Le relâchement est cherché **dans** `updateParallax`, et pas ailleurs
	// dans le script : la déclaration `let parallaxTicking = false;` porte les
	// mêmes caractères, et suffisait à faire passer le test sur du code où le
	// drapeau restait bloqué.
	if ( ! preg_match( '/function updateParallax\(\) \{(.*?)\n\}/s', script(), $corps ) ) {
		throw new \RuntimeException( 'updateParallax introuvable' );
	}

	assert_true( str_contains( $corps[1], 'parallaxTicking = false;' ), 'le drapeau de parallaxe n’est jamais relâché' );
	assert_same( 0, preg_match( '/_ticking/', script() ), 'reste d’un drapeau posé sur un tableau' );
} );

test( 'une réinitialisation ne double pas la parallaxe', function (): void {
	// `BAS.init()` est rejoué après chaque chargement AJAX : sans garde, chaque
	// passage ajoutait un écouteur de défilement et un doublon d'élément.
	assert_true( str_contains( script(), "el.dataset.basParallax === 'true'" ), 'élément de parallaxe réenregistré' );
	assert_true( str_contains( script(), 'if (!parallaxEls.length || parallaxBound) return;' ), 'écouteur de défilement posé deux fois' );
} );

test( 'aucun sélecteur CSS sans mécanisme', function (): void {
	// Une classe stylée que rien ne pose se lit comme un mécanisme : on la
	// cherche pendant une heure. `.bas-ready` était dans ce cas.
	preg_match_all( '/\.bas-([a-z0-9-]+)/', css(), $classes );

	$mortes = [];

	foreach ( array_unique( $classes[1] ) as $classe ) {
		if ( ! str_contains( script(), "bas-{$classe}" ) && ! str_contains( script(), '--bas-' . $classe ) ) {
			$mortes[] = ".bas-{$classe}";
		}
	}

	assert_same( [], $mortes, 'classe stylée que le moteur ne pose jamais' );
} );

/* --- Compte rendu ---------------------------------------------------- */

echo "\n{$passed} test(s) réussi(s), {$failed} échec(s).\n";

exit( $failed > 0 ? 1 : 0 );
