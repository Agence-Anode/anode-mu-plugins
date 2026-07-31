<?php
/**
 * Doublure de la fonction de contexte de Bricks — namespace global.
 *
 * Ce fichier n'existe que pour son namespace. `anode-builder.php` interroge
 * `function_exists( 'bricks_is_builder_main' )`, qui désigne toujours la fonction
 * globale : une doublure déclarée dans `Anode\Builder` ne serait jamais trouvée,
 * et la garde répondrait « pas dans le builder » à chaque test.
 *
 * Sur un vrai site, c'est le thème Bricks qui la déclare — globale, comme ici.
 *
 * @package Anode\Builder
 */

declare( strict_types = 1 );

function bricks_is_builder_main(): bool {
	global $etat;

	return (bool) ( $etat['builder'] ?? true );
}
