<?php
/**
 * Le rendu des quatre pages d'atelier.
 *
 * Aucune ressource extérieure : ni police, ni feuille, ni script distant. Ces
 * pages sont servies par le site qu'elles décrivent, et la règle « zéro
 * dépendance » ne connaît pas d'exception pour les outils internes — une page
 * d'atelier qui appellerait un CDN transmettrait l'adresse IP de qui la consulte
 * exactement comme une page publique.
 *
 * La charte vient des **tokens du site** : ces pages ressemblent au site
 * qu'elles décrivent, sans qu'on ait à les repeindre projet par projet.
 *
 * @package Anode\Plan
 */

declare(strict_types=1);

namespace Anode\Plan;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

final class Rendu {

	/**
	 * Le gabarit commun : en-tête, navigation, badge d'environnement.
	 */
	private static function page( string $titre, string $courant, string $corps, string $style = '' ): string {
		$t    = Charte::tokens();
		$nom  = get_bloginfo( 'name' );
		$prod = Liens::production();

		$nav = '';
		foreach ( Plan::pages() as $segment => $libelle ) {
			$href   = home_url( '/' . Plan::racine() . ( '' === $segment ? '/' : '/' . $segment . '/' ) );
			$actif  = $segment === $courant ? ' aria-current="page"' : '';
			$nav   .= sprintf( '<a href="%s"%s>%s</a>', esc_url( $href ), $actif, esc_html( $libelle ) );
		}

		$bouton_prod = $prod
			? sprintf( '<a class="pl-prod-home" href="%s" target="_blank" rel="noopener">prod ↗</a>', esc_url( $prod ) )
			: '';

		return sprintf(
			'<!DOCTYPE html>
<html lang="%s">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>%s</title>
<style>%s%s</style>
</head>
<body>
<header class="pl-head">
  <span class="pl-brand">%s</span>
  <span class="pl-badge">préproduction</span>
  <nav class="pl-nav">%s</nav>
  %s
</header>
<main>%s</main>
<footer>Pages d’atelier — servies en préproduction seulement, jamais en production.</footer>
</body>
</html>',
			esc_attr( get_bloginfo( 'language' ) ),
			esc_html( $nom . ' — ' . $titre ),
			self::style( $t ),
			$style,
			esc_html( $nom ),
			$nav,
			$bouton_prod,
			$corps
		);
	}

	/** @param array<string, string> $t */
	private static function style( array $t ): string {
		return sprintf(
			'*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--pl-primary:%1$s;--pl-on:%2$s;--pl-ink:%3$s;--pl-muted:%4$s;--pl-bg:%5$s;--pl-surface:%6$s;--pl-border:%7$s}
body{font-family:%8$s;color:var(--pl-ink);background:var(--pl-bg);min-height:100vh;-webkit-font-smoothing:antialiased}
.pl-head{background:var(--pl-primary);color:var(--pl-on);padding:16px 32px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;position:sticky;top:0;z-index:10}
.pl-brand{font-family:%9$s;font-weight:700;font-size:17px;flex:1 1 auto;min-width:0}
.pl-badge{font-size:10px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;background:var(--pl-on);color:var(--pl-primary);padding:3px 9px;border-radius:4px}
.pl-nav{display:flex;gap:4px;flex-wrap:wrap}
.pl-nav a{padding:7px 14px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:color-mix(in srgb,var(--pl-on) 70%%,transparent);text-decoration:none;border-radius:6px;transition:background .15s,color .15s}
.pl-nav a:hover,.pl-nav a:focus-visible{color:var(--pl-on);background:color-mix(in srgb,var(--pl-on) 12%%,transparent)}
.pl-nav a[aria-current="page"]{color:var(--pl-primary);background:var(--pl-on)}
.pl-prod-home{font-size:11px;font-weight:700;text-transform:uppercase;color:var(--pl-on);border:1px solid color-mix(in srgb,var(--pl-on) 45%%,transparent);padding:5px 11px;border-radius:6px;text-decoration:none;white-space:nowrap}
.pl-prod-home:hover,.pl-prod-home:focus-visible{background:var(--pl-on);color:var(--pl-primary)}
main{max-width:1000px;margin:0 auto;padding:40px 24px 80px}
h1{font-family:%9$s;font-size:23px;font-weight:800;margin-bottom:4px}
.pl-sub{color:var(--pl-muted);font-size:14px;margin-bottom:28px}
.pl-grp{margin-bottom:30px}
.pl-grp h2{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:2px;color:var(--pl-primary);margin-bottom:12px;padding-bottom:6px;border-bottom:1px solid var(--pl-border)}
ul.pl-liste{list-style:none;display:grid;grid-template-columns:1fr 1fr;gap:8px}
@media(max-width:640px){ul.pl-liste{grid-template-columns:1fr}}
ul.pl-liste li{background:var(--pl-surface);display:flex;align-items:stretch;border:1px solid var(--pl-border);border-radius:10px;overflow:hidden}
ul.pl-liste li:hover{background:color-mix(in srgb,var(--pl-primary) 6%%,var(--pl-surface));border-color:color-mix(in srgb,var(--pl-primary) 35%%,var(--pl-border))}
.pl-entree{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;gap:3px;padding:13px 18px;text-decoration:none;color:inherit}
.pl-entree:focus-visible{outline:2px solid var(--pl-primary);outline-offset:-2px}
.pl-titre{font-size:14px;font-weight:600}
.pl-chemin{font-size:11.5px;color:var(--pl-muted);font-family:ui-monospace,monospace}
.pl-lien-prod{flex-shrink:0;align-self:center;margin-right:10px;padding:4px 9px;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--pl-muted);border:1px solid var(--pl-border);border-radius:5px;text-decoration:none;white-space:nowrap;opacity:0;transition:opacity .15s}
ul.pl-liste li:hover .pl-lien-prod,.pl-lien-prod:focus-visible{opacity:1}
.pl-lien-prod:hover,.pl-lien-prod:focus-visible{color:var(--pl-on);background:var(--pl-primary);border-color:var(--pl-primary)}
@media(max-width:640px){.pl-lien-prod{opacity:1}}
footer{text-align:center;padding:32px;font-size:12px;color:var(--pl-muted)}
.pl-vide{color:var(--pl-muted);font-size:14px;background:var(--pl-surface);border:1px dashed var(--pl-border);border-radius:10px;padding:18px}',
			esc_attr( $t['primary'] ),
			esc_attr( $t['on'] ),
			esc_attr( $t['ink'] ),
			esc_attr( $t['muted'] ),
			esc_attr( $t['bg'] ),
			esc_attr( $t['surface'] ),
			esc_attr( $t['border'] ),
			esc_attr( $t['fontBody'] ),
			esc_attr( $t['fontHeading'] )
		);
	}

	/** Une entrée de liste : titre, chemin, et le lien vers la production. */
	private static function entree( string $titre, string $chemin ): string {
		$prod = Liens::production();
		$vers = $prod ? rtrim( $prod, '/' ) . $chemin : null;

		return sprintf(
			'<li><a class="pl-entree" href="%s"><span class="pl-titre">%s</span><span class="pl-chemin">%s</span></a>%s</li>',
			esc_url( home_url( $chemin ) ),
			esc_html( $titre ),
			esc_html( $chemin ),
			$vers ? sprintf( '<a class="pl-lien-prod" href="%s" target="_blank" rel="noopener">prod ↗</a>', esc_url( $vers ) ) : ''
		);
	}

	/** @param array<int, string> $entrees */
	private static function groupe( string $label, array $entrees ): string {
		if ( ! $entrees ) {
			return '';
		}

		return sprintf(
			'<section class="pl-grp"><h2>%s</h2><ul class="pl-liste">%s</ul></section>',
			esc_html( $label ),
			implode( '', $entrees )
		);
	}

	/* ----------------------------------------------------------------- *
	 * /plan/ — le plan du site
	 * ----------------------------------------------------------------- */

	public static function sitemap(): string {
		$corps = '';
		$total = 0;

		// Les pages, dans l'ordre du menu — celui que le client connaît.
		$pages = get_posts( [
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		] );

		$entrees = [];
		foreach ( $pages as $p ) {
			$entrees[] = self::entree( get_the_title( $p ), self::chemin( get_permalink( $p ) ) );
			$total++;
		}
		$corps .= self::groupe( 'Pages', $entrees );

		/*
		 * Les types de contenu, avec leur archive et **une** fiche.
		 *
		 * Une fiche, pas toutes : sur un site à trois cent trente-quatre
		 * événements, la liste noierait le plan. Ce qu'on vient vérifier ici,
		 * c'est que le gabarit de fiche existe et s'ouvre.
		 */
		/*
		 * `bricks_template` est public — il faut bien qu'un gabarit se prévisualise
		 * — mais ce n'est pas une page du site : il apparaissait sous « Mes
		 * templates », entre les événements et les résidents, et le client y
		 * lisait une rubrique qui n'existe pas. Ce qu'un gabarit produit est déjà
		 * dans le plan, sous la forme de la fiche qu'il rend.
		 */
		$internes = (array) apply_filters( 'anode/plan/types_ignores', [ 'bricks_template' ] );

		foreach ( get_post_types( [ 'public' => true, '_builtin' => false ], 'objects' ) as $type ) {
			if ( in_array( $type->name, $internes, true ) ) {
				continue;
			}

			$entrees = [];
			$archive = get_post_type_archive_link( $type->name );

			if ( $archive ) {
				$entrees[] = self::entree( $type->label . ' — liste', self::chemin( $archive ) );
			}

			$exemple = get_posts( [ 'post_type' => $type->name, 'post_status' => 'publish', 'posts_per_page' => 1 ] );

			if ( $exemple ) {
				$n         = (int) wp_count_posts( $type->name )->publish;
				$entrees[] = self::entree(
					sprintf( '%s — une fiche (sur %d)', $type->label, $n ),
					self::chemin( get_permalink( $exemple[0] ) )
				);
			}

			$corps .= self::groupe( $type->label, $entrees );
			$total += count( $entrees );
		}

		// Le 404, qu'on ne visite jamais en travaillant — et qui se perd pour ça.
		$corps .= self::groupe( 'Gabarits', [
			self::entree( 'Page introuvable (404)', '/' . wp_generate_password( 12, false ) . '/' ),
		] );

		$main = sprintf(
			'<h1>Plan du site</h1><p class="pl-sub">%d entrée(s). Chaque ligne ouvre la page ; « prod ↗ » ouvre la même adresse en production.</p>%s',
			$total,
			$corps
		);

		return self::page( 'Plan du site', '', $main );
	}

	/* ----------------------------------------------------------------- *
	 * /plan/formulaires/
	 * ----------------------------------------------------------------- */

	public static function formulaires(): string {
		$defs = Formulaires::lire();

		if ( ! $defs ) {
			return self::page( 'Formulaires', 'formulaires',
				'<h1>Formulaires</h1><p class="pl-vide">Aucune définition dans <code>theme/&lt;enfant&gt;/forms/</code>.</p>' );
		}

		$corps = '';
		foreach ( $defs as $nom => $d ) {
			$ecrans = '';
			foreach ( (array) ( $d['screens'] ?? [] ) as $rang => $ecran ) {
				$champs = '';
				foreach ( (array) ( $ecran['fields'] ?? [] ) as $champ ) {
					$champs .= sprintf(
						'<li><code>%s</code> <span class="pl-type">%s</span>%s</li>',
						esc_html( (string) ( $champ['name'] ?? '?' ) ),
						esc_html( (string) ( $champ['type'] ?? 'text' ) ),
						! empty( $champ['required'] ) ? ' <span class="pl-req">obligatoire</span>' : ''
					);
				}

				$ecrans .= sprintf(
					'<li class="pl-ecran"><span class="pl-rang">%d</span><div><strong>%s</strong><ul class="pl-champs">%s</ul></div></li>',
					$rang + 1,
					esc_html( (string) ( $ecran['title'] ?? 'Écran ' . ( $rang + 1 ) ) ),
					$champs
				);
			}

			$relais = $d['webhook'] ?? ( $d['webhooks'][0] ?? null );

			$corps .= sprintf(
				'<section class="pl-grp"><h2>%s</h2>
<p class="pl-meta">Monté par <code>&lt;div data-form="%s"&gt;</code> · destination : %s</p>
<ol class="pl-ecrans">%s</ol></section>',
				esc_html( (string) ( $d['label'] ?? $nom ) ),
				esc_html( $nom ),
				$relais ? '<code>' . esc_html( (string) $relais ) . '</code>' : '<em>aucune — les envois sont refusés</em>',
				$ecrans
			);
		}

		$style = '.pl-meta{font-size:13px;color:var(--pl-muted);margin-bottom:14px}
ol.pl-ecrans{list-style:none;display:flex;flex-direction:column;gap:10px}
.pl-ecran{display:flex;gap:14px;background:var(--pl-surface);border:1px solid var(--pl-border);border-radius:10px;padding:14px 18px}
.pl-rang{flex:none;width:26px;height:26px;border-radius:50%;background:var(--pl-primary);color:var(--pl-on);font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center}
ul.pl-champs{list-style:none;margin-top:8px;display:flex;flex-wrap:wrap;gap:6px}
ul.pl-champs li{font-size:12px;background:var(--pl-bg);border:1px solid var(--pl-border);border-radius:6px;padding:3px 8px}
.pl-type{color:var(--pl-muted)}
.pl-req{color:var(--pl-primary);font-weight:700}';

		return self::page( 'Formulaires', 'formulaires',
			sprintf( '<h1>Formulaires</h1><p class="pl-sub">%d formulaire(s), écrans numérotés dans l’ordre de passage.</p>%s',
				count( $defs ), $corps ),
			$style );
	}

	/* ----------------------------------------------------------------- *
	 * /plan/charte/
	 * ----------------------------------------------------------------- */

	public static function charte(): string {
		$groupes = Charte::variables();

		if ( ! $groupes ) {
			return self::page( 'Charte', 'charte',
				'<h1>Charte</h1><p class="pl-vide">Aucune variable lue dans <code>tokens.css</code>.</p>' );
		}

		$corps = '';
		foreach ( $groupes as $famille => $vars ) {
			$cases = '';
			foreach ( $vars as $nom => $valeur ) {
				$pastille = preg_match( '/^(#|rgb|hsl)/i', $valeur )
					? sprintf( '<span class="pl-pastille" style="background:%s"></span>', esc_attr( $valeur ) )
					: '';
				$cases   .= sprintf(
					'<li>%s<code>--%s</code><span class="pl-val">%s</span></li>',
					$pastille,
					esc_html( $nom ),
					esc_html( $valeur )
				);
			}
			$corps .= sprintf(
				'<section class="pl-grp"><h2>%s</h2><ul class="pl-tokens">%s</ul></section>',
				esc_html( $famille ),
				$cases
			);
		}

		$style = 'ul.pl-tokens{list-style:none;display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:8px}
ul.pl-tokens li{display:flex;align-items:center;gap:10px;background:var(--pl-surface);border:1px solid var(--pl-border);border-radius:8px;padding:9px 12px;font-size:12.5px}
.pl-pastille{flex:none;width:22px;height:22px;border-radius:6px;border:1px solid var(--pl-border)}
.pl-val{margin-left:auto;color:var(--pl-muted);font-family:ui-monospace,monospace;font-size:11.5px}';

		$n = array_sum( array_map( 'count', $groupes ) );

		return self::page( 'Charte', 'charte',
			sprintf( '<h1>Charte</h1><p class="pl-sub">%d variable(s), lues dans la feuille servie par le thème.</p>%s', $n, $corps ),
			$style );
	}

	/* ----------------------------------------------------------------- *
	 * /plan/composants/
	 * ----------------------------------------------------------------- */

	public static function composants(): string {
		$liste = Composants::lire();

		if ( ! $liste ) {
			return self::page( 'Composants', 'composants',
				'<h1>Composants</h1><p class="pl-vide">Aucun composant Bricks déclaré sur ce site.</p>' );
		}

		$par_categorie = [];
		foreach ( $liste as $c ) {
			$par_categorie[ $c['category'] ?: 'Sans catégorie' ][] = $c;
		}

		$corps = '';
		foreach ( $par_categorie as $categorie => $items ) {
			$lignes = '';
			foreach ( $items as $c ) {
				$props   = $c['properties'] ? implode( ', ', array_map( 'esc_html', $c['properties'] ) ) : '<em>aucune</em>';
				$lignes .= sprintf(
					'<li><div><strong>%s</strong><span class="pl-inst">%d instance(s)</span></div><p class="pl-props">%s</p></li>',
					esc_html( $c['label'] ),
					$c['instances'],
					$props
				);
			}
			$corps .= sprintf(
				'<section class="pl-grp"><h2>%s</h2><ul class="pl-comps">%s</ul></section>',
				esc_html( $categorie ),
				$lignes
			);
		}

		$style = 'ul.pl-comps{list-style:none;display:grid;grid-template-columns:1fr 1fr;gap:8px}
@media(max-width:640px){ul.pl-comps{grid-template-columns:1fr}}
ul.pl-comps li{background:var(--pl-surface);border:1px solid var(--pl-border);border-radius:10px;padding:13px 18px}
ul.pl-comps li>div{display:flex;align-items:baseline;gap:10px}
.pl-inst{margin-left:auto;font-size:11px;color:var(--pl-muted);white-space:nowrap}
.pl-props{margin-top:5px;font-size:11.5px;color:var(--pl-muted);font-family:ui-monospace,monospace}';

		return self::page( 'Composants', 'composants',
			sprintf( '<h1>Composants</h1><p class="pl-sub">%d composant(s) Bricks. Un composant sans instance est du code mort ; un bloc répété qui n’est pas ici est une copie.</p>%s',
				count( $liste ), $corps ),
			$style );
	}

	/** Le chemin d'une URL du site, sans le domaine. */
	private static function chemin( string $url ): string {
		$p = (string) wp_parse_url( $url, PHP_URL_PATH );

		return '' === $p ? '/' : $p;
	}
}
