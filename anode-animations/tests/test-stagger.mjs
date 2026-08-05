#!/usr/bin/env node
/**
 * Le décalage progressif, **exécuté** — la garde que personne n'éprouvait.
 *
 * `test-animations.php` lit du texte : il compte les keyframes, épingle le nom de
 * l'attribut, vérifie qu'aucun sélecteur CSS n'est sans mécanisme. Il ne fait pas
 * tourner une ligne du moteur, et c'est exactement la faille par laquelle le
 * défaut est passé.
 *
 * Ce qu'il a laissé passer, mesuré le 05/08/2026 sur une préproduction du parc :
 * une grille portant le motif **documenté** — `animate="fade-up"` +
 * `stagger="90ms"` — voyait ses cartes disparaître définitivement.
 *
 *   `init()` parcourt une NodeList rendue par `querySelectorAll`, donc
 *   **statique**, construite avant le premier `initElement`. `applyStagger` pose
 *   ensuite `animate` sur les enfants directs du conteneur : ces enfants ne sont
 *   pas dans la liste, rien ne les initialise, rien ne les observe — et la règle
 *   `[animate] { opacity: 0 }`, déclenchée par l'attribut qu'on vient de leur
 *   poser, les masque pour de bon.
 *
 * La panne est difficile à lire parce que le conteneur, lui, s'anime
 * normalement : la section entre, et reste vide.
 *
 * D'où ce banc, qui pose l'état au lieu de l'attendre. Le moteur est extrait du
 * PHP servi, exécuté dans un DOM de poche, et l'`IntersectionObserver` est une
 * doublure qui **retient ses cibles** : la question posée est « qui est
 * observé ? », la seule dont la réponse distingue une carte animée d'une carte
 * masquée à jamais.
 *
 * Vérifié par mutation : retirer `initElement(child)` d'`applyStagger` fait
 * tomber les trois cas d'héritage, et eux seuls.
 *
 * Lancement : node blueprint/mu-plugins/anode-animations/tests/test-stagger.mjs
 *
 * @package Anode\Animations
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';

const ici = path.dirname(fileURLToPath(import.meta.url));
const MODULE = path.join(ici, '..', 'anode-animations.php');

/* ------------------------------------------------------------------ *
 * Compte rendu
 * ------------------------------------------------------------------ */

let reussis = 0;
let echoues = 0;

function test(nom, fonction) {
  try {
    fonction();
    reussis += 1;
    process.stdout.write(`  ✓ ${nom}\n`);
  } catch (erreur) {
    echoues += 1;
    process.stdout.write(`  ✗ ${nom}\n      ${erreur.message}\n`);
  }
}

function affirmer(condition, message) {
  if (!condition) throw new Error(message);
}

function affirmerEgal(attendu, obtenu, message) {
  if (attendu !== obtenu) {
    throw new Error(`${message} — attendu ${JSON.stringify(attendu)}, obtenu ${JSON.stringify(obtenu)}`);
  }
}

/* ------------------------------------------------------------------ *
 * Le moteur, tel que le site le sert
 * ------------------------------------------------------------------ */

/**
 * Extrait le script du PHP.
 *
 * On lit **le fichier servi**, pas une copie : un banc qui exercerait sa propre
 * transcription du moteur ne dirait rien de ce que reçoit le navigateur.
 */
function moteur() {
  const php = readFileSync(MODULE, 'utf8');
  const bloc = /<script id="bas-script">([\s\S]*?)<\/script>/.exec(php);

  if (!bloc) throw new Error('script « bas-script » introuvable dans anode-animations.php');

  return bloc[1];
}

/* ------------------------------------------------------------------ *
 * Un DOM de poche
 * ------------------------------------------------------------------ */

class ElementDouble {
  constructor(attributs = {}) {
    this.attributs = new Map(Object.entries(attributs));
    this.children = [];
    this.dataset = {};
    this.style = {
      setProperty(nom, valeur) { this[nom] = valeur; },
      removeProperty(nom) { delete this[nom]; },
    };
  }

  ajouter(enfant) {
    this.children.push(enfant);
    return enfant;
  }

  hasAttribute(nom) { return this.attributs.has(nom); }
  getAttribute(nom) { return this.attributs.has(nom) ? this.attributs.get(nom) : null; }
  setAttribute(nom, valeur) { this.attributs.set(nom, String(valeur)); }
  removeAttribute(nom) { this.attributs.delete(nom); }

  addEventListener() {}
  removeEventListener() {}
  getBoundingClientRect() { return { top: 0, bottom: 100, height: 100, left: 0, right: 100, width: 100 }; }

  /** Tout le sous-arbre, dans l'ordre du document — comme querySelectorAll. */
  *descendants() {
    for (const enfant of this.children) {
      yield enfant;
      yield* enfant.descendants();
    }
  }
}

/**
 * Le décor : un conteneur et ses enfants, plus l'attelage minimal du moteur.
 *
 * `querySelectorAll` ne comprend qu'un jeu d'attributs entre crochets — c'est
 * tout ce que `init()` lui demande, et une doublure qui en ferait plus
 * inventerait un comportement que le test ne peut pas vérifier.
 */
function monter(racine) {
  const observes = [];
  const minuteries = [];

  const document = {
    racine,
    readyState: 'complete',
    addEventListener() {},

    querySelectorAll(selecteur) {
      const voulus = [...selecteur.matchAll(/\[([\w-]+)\]/g)].map((m) => m[1]);

      // Statique, et c'est tout l'enjeu : la liste est figée à l'appel.
      return [...racine.descendants()].filter((el) => voulus.some((a) => el.hasAttribute(a)));
    },
  };

  const fenetre = {
    matchMedia: () => ({ matches: false, addEventListener() {}, addListener() {} }),
    addEventListener() {},
    innerHeight: 800,
    pageYOffset: 0,
  };

  const contexte = vm.createContext({
    window: fenetre,
    document,
    matchMedia: fenetre.matchMedia,
    requestAnimationFrame: (rappel) => minuteries.push(rappel),
    setTimeout: (rappel) => minuteries.push(rappel),
    IntersectionObserver: class {
      constructor(rappel, options) { this.rappel = rappel; this.options = options; }
      observe(cible) { observes.push(cible); }
      unobserve() {}
      disconnect() {}
    },
    JSON, Math, Number, String, Boolean, Array, Object, Set, Map,
    parseFloat, parseInt,
  });

  vm.runInContext(moteur(), contexte);

  return { observes, fenetre, contexte };
}

/** Une grille à `stagger`, avec ses cartes. */
function grilleAvecCartes(nombre, attributsGrille) {
  const corps = new ElementDouble();
  const grille = corps.ajouter(new ElementDouble(attributsGrille));

  for (let i = 0; i < nombre; i += 1) grille.ajouter(new ElementDouble());

  return { corps, grille, cartes: grille.children };
}

/* ------------------------------------------------------------------ *
 * Les cas
 * ------------------------------------------------------------------ */

process.stdout.write('\n\x1b[1mDécalage progressif — le motif documenté\x1b[0m\n\n');

/*
 * Le défaut le plus coûteux de ce moteur, et le plus difficile à nommer : les
 * animations **se jouent au chargement** au lieu d'attendre le défilement.
 *
 * On ne le décrit pas comme un défaut, on le décrit comme une absence : « les
 * animations ne marchent pas, je ne les vois jamais ». Et c'est exact — tout est
 * déjà terminé quand on arrive au bloc, et le rechargement rejoue le tout hors de
 * l'écran.
 *
 * La cause tient dans une chaîne de conditions qui s'effondre sur `null` :
 *
 *     attr(el, 'scroll') === 'true' || … || attr(el, 'scroll') !== null && …
 *
 * Sans attribut, `getAttribute` rend `null`, aucune branche ne passe, et `scroll`
 * vaut `false` sur **tout** élément qui ne nomme pas le réglage — c'est-à-dire
 * tous. `initElement` prend alors la branche « animer tout de suite » et aucun
 * `IntersectionObserver` n'est posé.
 *
 * La question qui départage tient en un mot : **l'élément est-il observé ?**
 * Aucune lecture de texte ne peut y répondre, et c'est pour cela que ce cas vit
 * ici et non dans le banc PHP.
 */
test('sans attribut « scroll », l’élément est observé — jamais animé aussitôt', () => {
  const { corps, grille } = grilleAvecCartes(0, { animate: 'fade-up' });
  const { observes } = monter(corps);

  affirmer(observes.includes(grille),
    'l’élément n’est pas observé : son animation est jouée au chargement, hors de l’écran. '
    + 'À l’usage, on ne voit jamais une animation se jouer — et l’on croit qu’elles ne marchent pas.');
});

test('« scroll="false" » est la seule façon de jouer aussitôt', () => {
  const { corps, grille } = grilleAvecCartes(0, { animate: 'fade-up' });
  grille.setAttribute('scroll', 'false');

  const { observes } = monter(corps);

  affirmer(!observes.includes(grille),
    'l’élément est observé alors qu’il demande explicitement à ne pas l’être');
});

test('une grille à stagger fait observer chacune de ses cartes', () => {
  const { corps, cartes } = grilleAvecCartes(3, { animate: 'fade-up', stagger: '90ms' });
  const { observes } = monter(corps);

  for (const [rang, carte] of cartes.entries()) {
    affirmer(observes.includes(carte),
      `la carte ${rang + 1} n'est pas observée : elle porte « animate », donc « opacity: 0 », `
      + 'et rien ne la révélera jamais');
  }
});

test('les cartes héritent de l’animation du conteneur', () => {
  const { corps, cartes } = grilleAvecCartes(3, { animate: 'fade-up', stagger: '90ms' });
  monter(corps);

  for (const carte of cartes) {
    affirmerEgal('fade-up', carte.getAttribute('animate'), 'animation héritée');
  }
});

test('le délai croît d’un cran par carte', () => {
  const { corps, cartes } = grilleAvecCartes(4, { animate: 'fade-up', stagger: '90ms' });
  monter(corps);

  for (const [rang, carte] of cartes.entries()) {
    affirmerEgal(`${rang * 90}ms`, carte.getAttribute('delay'), `délai de la carte ${rang + 1}`);
  }
});

test('une carte qui porte déjà son animation la garde', () => {
  const { corps, cartes } = grilleAvecCartes(2, { animate: 'fade-up', stagger: '90ms' });
  cartes[1].setAttribute('animate', 'zoom-in');

  const { observes } = monter(corps);

  affirmerEgal('zoom-in', cartes[1].getAttribute('animate'), 'animation propre conservée');
  affirmer(observes.includes(cartes[1]), 'une carte à animation propre reste observée');
});

test('sans stagger, une carte sans animation n’est ni animée ni masquée', () => {
  const { corps, cartes } = grilleAvecCartes(3, { animate: 'fade-up' });
  const { observes } = monter(corps);

  for (const carte of cartes) {
    affirmerEgal(null, carte.getAttribute('animate'),
      'sans stagger, aucun attribut ne doit être posé — il masquerait la carte');
    affirmer(!observes.includes(carte), 'une carte sans attribut n’a rien à observer');
  }
});

test('le conteneur reste observé, en plus de ses cartes', () => {
  const { corps, grille } = grilleAvecCartes(3, { animate: 'fade-up', stagger: '90ms' });
  const { observes } = monter(corps);

  affirmer(observes.includes(grille), 'le conteneur porte sa propre animation');
});

test('« no-hide » sur une carte l’exempte du groupe', () => {
  const { corps, cartes } = grilleAvecCartes(2, { animate: 'fade-up', stagger: '90ms' });
  cartes[0].setAttribute('animate', 'no-hide');

  monter(corps);

  affirmerEgal('no-hide', cartes[0].getAttribute('animate'),
    '« no-hide » est l’échappatoire — il ne doit pas être remplacé');
});

test('mouvement refusé : le moteur ne pose aucun attribut', () => {
  const { corps, cartes } = grilleAvecCartes(3, { animate: 'fade-up', stagger: '90ms' });
  const corpsSansMouvement = corps;

  // `init()` sort avant tout parcours quand `prefers-reduced-motion` est posé,
  // et c'est le CSS qui rétablit l'opacité (WCAG 2.3.3).
  const observes = [];
  const contexte = vm.createContext({
    window: { matchMedia: () => ({ matches: true, addEventListener() {} }), addEventListener() {}, innerHeight: 800 },
    document: {
      readyState: 'complete',
      addEventListener() {},
      querySelectorAll: (s) => {
        const voulus = [...s.matchAll(/\[([\w-]+)\]/g)].map((m) => m[1]);
        return [...corpsSansMouvement.descendants()].filter((el) => voulus.some((a) => el.hasAttribute(a)));
      },
    },
    matchMedia: () => ({ matches: true, addEventListener() {} }),
    requestAnimationFrame() {}, setTimeout() {},
    IntersectionObserver: class { observe(c) { observes.push(c); } unobserve() {} disconnect() {} },
    JSON, Math, Number, String, Boolean, Array, Object, Set, Map, parseFloat, parseInt,
  });

  vm.runInContext(moteur(), contexte);

  affirmerEgal(0, observes.length, 'aucun élément ne doit être observé');

  for (const carte of cartes) {
    affirmerEgal(null, carte.getAttribute('animate'), 'aucune carte ne doit recevoir d’attribut');
  }
});

/* ------------------------------------------------------------------ */

process.stdout.write(`\n${reussis} test(s) réussi(s), ${echoues} échec(s).\n\n`);
process.exit(echoues > 0 ? 1 : 0);
