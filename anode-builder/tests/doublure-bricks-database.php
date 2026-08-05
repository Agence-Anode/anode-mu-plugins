<?php
/**
 * Doublure de `Bricks\Database` — pour éprouver le doublement des noms.
 *
 * Elle vit dans son propre fichier pour la même raison que `doublure-bricks.php` :
 * PHP interdit de mêler un `namespace X;` sans accolades et un bloc
 * `namespace Y { }` dans un même fichier, et le fichier de tests déclare déjà
 * `Anode\Builder`.
 *
 * Seule `$global_data['globalClasses']` est nécessaire : c'est là que
 * `generate_global_classes()` lit le **nom** dont il construit son sélecteur,
 * et donc le seul endroit où `doubler_les_noms()` écrit.
 *
 * @package Anode\Builder
 */

declare( strict_types = 1 );

namespace Bricks;

class Database { // phpcs:ignore
	/** @var array<string, mixed> */
	public static $global_data = [ 'globalClasses' => [] ];
}
