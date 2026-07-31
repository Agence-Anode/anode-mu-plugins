#!/usr/bin/env node
/**
 * La décision du builder, **exécutée** — trois gardes que personne n'éprouvait.
 *
 * Ce fichier existe pour un défaut de couverture précis, et mesuré : trois gardes
 * de `composant_dblclic` ont été retirées une à une du script servi par la
 * préproduction, et `bin/test-builder-interactions.mjs` a rendu 53 cas verts et
 * un code de sortie nul à chaque fois.
 *
 *   - la lecture de classe sur la ligne cliquée (niveau de la page) ;
 *   - la remontée à l'instance depuis le canevas ;
 *   - le verrou de 700 ms entre deux ouvertures.
 *
 * La cause est unique, et ce n'est pas une négligence du harnais : **l'oracle du
 * bouton suffit à lui seul dans tous les états qu'un vrai builder produit.**
 * Mesuré dans Bricks 2.3.10 : le bouton « modifier le composant » disparaît dans
 * le même tour synchrone que le changement de sélection — absent dès le premier
 * échantillon à 0 ms, et sur 400 ms de relevé continu. Il n'existe donc aucune
 * fenêtre où le bouton d'un composant subsisterait pendant qu'on double-clique
 * une ligne ordinaire, et un harnais qui clique dans un vrai builder ne peut pas
 * poser cet état. Les gardes protègent d'un builder qui n'existe pas encore.
 *
 * D'où ce troisième juge, qui pose l'état au lieu de l'attendre : le script tourne
 * dans un DOM de poche (`doublure-dom.mjs`), l'horloge est tenue à la main, et
 * chaque garde a un cas qui tombe si on la retire. Vérifié par mutation, garde par
 * garde.
 *
 * Ce qu'il ne prouve pas — et le dire fait partie du test : il ne dit rien de ce
 * que Bricks émet. `test-builder.php` épingle les sélecteurs relevés,
 * `bin/test-builder-interactions.mjs` éprouve le vrai builder. Trois questions
 * distinctes, trois fichiers.
 *
 * Lancement : node blueprint/mu-plugins/anode-builder/tests/test-decision.mjs
 *
 * @package Anode\Builder
 */

import { monter, DocumentDouble } from './doublure-dom.mjs';

/* ------------------------------------------------------------------ *
 * Compte rendu
 * ------------------------------------------------------------------ */

let reussis = 0;
let echoues = 0;

async function test(nom, fonction) {
  try {
    await fonction();

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
  if (attendu !== obtenu) throw new Error(`${message} — attendu ${JSON.stringify(attendu)}, obtenu ${JSON.stringify(obtenu)}`);
}

/* ------------------------------------------------------------------ *
 * Le décor : un builder de poche
 * ------------------------------------------------------------------ */

/**
 * Le DOM que Bricks 2.3.10 sert, réduit à ce que le script consulte.
 *
 * Les sélecteurs sont ceux du relevé — `test-builder.php` les épingle dans le
 * script, ce décor les reproduit. Si l'un change d'un côté sans l'autre, les cas
 * ci-dessous cessent de trouver quoi que ce soit, et échouent.
 *
 * @param {object}   [options]
 * @param {boolean}  [options.bouton]    Le bouton « modifier le composant » est-il affiché ?
 * @param {object[]} [options.pastilles] Les classes du panneau : `{ id, active }`.
 * @param {object}   [options.fonctions] Les interrupteurs du composant.
 * @param {object[]} [options.ruptures]  `bricksData.breakpoints`.
 */
function builderDePoche({
  bouton = false,
  pastilles = [],
  fonctions = { composant_dblclic: true },
  ruptures = [{ key: 'tablet', width: '991px' }, { key: 'mobile', width: '478px' }],
} = {}) {
  const noeuds = {};

  const monde = monter({
    fonctions,
    ruptures,
    decor: (m) => {
      /* Le panneau, ses classes, et le bouton quand on le veut. */
      const panneau = m.element('div', { attributs: { id: 'bricks-panel' } });
      const liste = m.element('ul', { classes: ['active-classes'], parent: panneau });

      noeuds.pastilles = pastilles.map(({ id, active }) => m.element('li', {
        classes: active ? ['active'] : [],
        attributs: { 'data-class-id': id },
        parent: liste,
      }));

      if (bouton) {
        noeuds.bouton = m.element('li', { parent: panneau });
        m.element('span', { classes: ['bricks-svg-wrapper', 'component'], parent: noeuds.bouton });
      }

      /*
       * La structure, **imbriquée** comme dans le builder : l'enfant est dans la
       * ligne du composant. C'est ce qui rend `closest` capable de remonter — et
       * c'est ce que la structure ne doit justement pas faire.
       */
      const structure = m.element('div', { attributs: { id: 'bricks-structure' } });

      noeuds.instance = m.element('li', {
        classes: ['element', 'component'], attributs: { 'data-id': '1' }, parent: structure,
      });
      noeuds.titreInstance = m.element('span', { classes: ['title'], parent: noeuds.instance });

      noeuds.enfant = m.element('li', {
        classes: ['element'], attributs: { 'data-id': '2' }, parent: noeuds.instance,
      });
      noeuds.titreEnfant = m.element('span', { classes: ['title'], parent: noeuds.enfant });

      noeuds.ordinaire = m.element('li', {
        classes: ['element'], attributs: { 'data-id': '3' }, parent: structure,
      });
      noeuds.titreOrdinaire = m.element('span', { classes: ['title'], parent: noeuds.ordinaire });
      noeuds.champRenommage = m.element('input', { classes: ['label'], parent: noeuds.ordinaire });

      /* La barre d'outils et son champ de largeur. */
      const barre = m.element('div', { attributs: { id: 'bricks-toolbar' } });

      noeuds.groupe = m.element('li', { parent: barre });
      noeuds.largeur = m.element('input', { attributs: { id: 'preview-width' }, parent: noeuds.groupe });
      noeuds.largeur.value = '1280';

      /* Le canevas : une iframe de même origine, donc un document à part entière. */
      noeuds.cadre = m.element('iframe', { attributs: { id: 'bricks-builder-iframe' } });

      const canevas = new DocumentDouble();

      noeuds.canevas = canevas;
      noeuds.cible = canevas.createElement('div');
      canevas.body.append(noeuds.cible);

      noeuds.cadre.contentWindow = { document: canevas };
    },
  });

  return { monde, noeuds };
}

/** Le nombre de clics reçus par un nœud — c'est ainsi qu'on lit une action. */
const clics = (noeud) => (noeud?.recus ?? []).filter((e) => e.type === 'click').length;

/** Laisse les microtâches se vider : l'écouteur du canevas est posé après une attente. */
const respirer = () => new Promise((resolve) => { setImmediate(resolve); });

/* ================================================================== *
 * 3. Le double-clic sur un composant — la structure
 * ================================================================== */

process.stdout.write('\nLa structure : ce qui ouvre, et ce qui n’ouvre pas\n');

await test('une ligne de composant, bouton affiché : le bouton est cliqué et le renommage devancé', () => {
  const { monde, noeuds } = builderDePoche({ bouton: true });
  const { annule } = monde.geste(noeuds.titreInstance, 'dblclick');

  affirmerEgal(1, clics(noeuds.bouton), 'le bouton du panneau n’a pas été cliqué');
  affirmer(annule, 'le geste n’a pas été annulé : Bricks renommerait par-dessus l’ouverture');
});

await test('une ligne ordinaire, bouton affiché : rien n’est ouvert, le renommage passe', () => {
  /*
   * **La garde de niveau de page, et le seul endroit où elle s'éprouve.**
   *
   * L'état posé ici — bouton affiché, ligne cliquée ordinaire — n'existe pas dans
   * Bricks 2.3.10 : le bouton suit la sélection dans le même tour synchrone. Le
   * harnais en ligne ne peut donc pas le produire, et il rend son cas vert avec ou
   * sans la garde. Ici, retirer la lecture de classe fait ouvrir un composant à la
   * place d'un renommage, et ce cas tombe.
   */
  const { monde, noeuds } = builderDePoche({ bouton: true });
  const { annule } = monde.geste(noeuds.titreOrdinaire, 'dblclick');

  affirmerEgal(0, clics(noeuds.bouton), 'un composant a été ouvert depuis une ligne qui n’en est pas une');
  affirmer(!annule, 'le geste a été coupé : le renommage de Bricks est perdu');
});

await test('une ligne de composant sans bouton : le renommage reprend ses droits', () => {
  const { monde, noeuds } = builderDePoche({ bouton: false });
  const { annule } = monde.geste(noeuds.titreInstance, 'dblclick');

  affirmer(!annule, 'le geste est coupé alors qu’il n’y avait rien à ouvrir');
});

await test('un enfant de l’instance : la structure ne remonte pas au composant qui le porte', () => {
  const { monde, noeuds } = builderDePoche({ bouton: true });

  monde.geste(noeuds.titreEnfant, 'dblclick');

  affirmerEgal(0, clics(noeuds.bouton), 'double-cliquer une carte ouvre le composant qui la contient');
});

await test('un champ en cours d’édition : le double-clic y sélectionne un mot', () => {
  const { monde, noeuds } = builderDePoche({ bouton: true });

  // Le champ est dans la ligne ordinaire, mais la ligne visée est bien une
  // instance : sans la garde du champ, ce geste ouvrirait un composant.
  noeuds.instance.append(noeuds.champRenommage);

  const { annule } = monde.geste(noeuds.champRenommage, 'dblclick');

  affirmerEgal(0, clics(noeuds.bouton), 'un composant s’ouvre en double-cliquant dans un champ de saisie');
  affirmer(!annule, 'la sélection de mot est coupée dans un champ');
});

await test('deux ouvertures à 100 ms : la seconde est avalée — le verrou de 700 ms', () => {
  /*
   * **Le verrou, et le seul endroit où il s'éprouve.**
   *
   * Trois clics rapides forment deux `dblclick` — les clics 1‑2, puis 2‑3. Dans le
   * vrai builder, le second n'a plus rien à cliquer : la racine qu'on vient d'ouvrir
   * ne propose pas le bouton, et le cas passe donc verrou ou pas. Ici le panneau ne
   * bascule pas, l'état « bouton encore affiché » subsiste, et retirer le verrou
   * produit deux ouvertures.
   */
  const { monde, noeuds } = builderDePoche({ bouton: true });

  monde.geste(noeuds.titreInstance, 'dblclick');
  monde.avancer(100);
  monde.geste(noeuds.titreInstance, 'dblclick');

  affirmerEgal(1, clics(noeuds.bouton), 'le second double-clic de la rafale a agi : on ressortirait aussitôt');

  monde.avancer(800);
  monde.geste(noeuds.titreInstance, 'dblclick');

  affirmerEgal(2, clics(noeuds.bouton), 'le verrou ne se relâche jamais : un double-clic sur deux serait perdu');
});

/* ================================================================== *
 * 3 bis. Le même geste dans le canevas
 * ================================================================== */

process.stdout.write('\nLe canevas n’est plus écouté\n');

await test('un double-clic dans le canevas n’ouvre rien et ne coupe rien', async () => {
  /*
   * Le canevas ouvrait aussi un composant. Retiré sur constat d'usage : une fois
   * **dans** le composant, on ne pouvait plus y sélectionner un élément enfant.
   *
   * La cause ne se corrige pas par une garde de plus — trois ont été essayées, et
   * chacune réparait le symptôme de la précédente. Bricks sélectionne au canevas
   * l'élément cliqué, pas l'instance qui le porte, et le bouton du panneau
   * réapparaît pour tout composant imbriqué : chaque double-clic sur un enfant
   * rouvrait donc quelque chose au lieu de le sélectionner.
   *
   * Ce cas vérifie les deux moitiés de la décision : rien ne s'ouvre, **et** rien
   * n'est coupé. La seconde compte autant — la sélection et l'édition en place
   * dans le canevas dépendent de gestes qu'on ne doit pas intercepter.
   */
  const { monde, noeuds } = builderDePoche({ bouton: true });

  await respirer();

  noeuds.enfant.classList.add('active');

  const { annule } = monde.geste(noeuds.cible, 'dblclick');

  affirmerEgal(0, clics(noeuds.bouton), 'le canevas ouvre encore un composant');
  affirmer(!annule, 'le canevas coupe un geste : la sélection en dépend');
});

await test('aucune sélection lisible : on s’abstient plutôt que d’agir au bouton', async () => {
  const { monde, noeuds } = builderDePoche({ bouton: true });

  await respirer();

  monde.geste(noeuds.cible, 'dblclick');

  affirmerEgal(0, clics(noeuds.bouton), 'le canevas agit sans savoir sur quoi — le défaut de la 1.4.0');
});

await test('le verrou est partagé : ouvrir depuis la structure protège le canevas', async () => {
  const { monde, noeuds } = builderDePoche({ bouton: true });

  await respirer();

  noeuds.enfant.classList.add('active');

  monde.geste(noeuds.titreInstance, 'dblclick');
  monde.avancer(100);
  monde.geste(noeuds.cible, 'dblclick');

  affirmerEgal(1, clics(noeuds.bouton), 'deux ouvertures en 100 ms : un verrou par gestionnaire ne suffirait pas');
});

/* ================================================================== *
 * 1. La dernière classe de la cascade
 * ================================================================== */

process.stdout.write('\nLa dernière classe de la cascade\n');

await test('aucune active : la dernière est cliquée, pas la première', () => {
  const { noeuds } = builderDePoche({
    fonctions: { classe_active: true },
    pastilles: [{ id: 'a' }, { id: 'b' }],
  });

  affirmerEgal(0, clics(noeuds.pastilles[0]), 'la première classe a été activée : la suivante l’écraserait');
  affirmerEgal(1, clics(noeuds.pastilles[1]), 'la dernière classe de la cascade n’a pas été activée');
});

await test('une classe déjà active : on ne touche à rien', () => {
  const { monde, noeuds } = builderDePoche({
    fonctions: { classe_active: true },
    pastilles: [{ id: 'a' }, { id: 'b', active: true }],
  });

  monde.mutation();

  affirmerEgal(0, clics(noeuds.pastilles[0]) + clics(noeuds.pastilles[1]), 'la fonction se bat contre l’état en place');
});

await test('aucune classe : rien n’est inventé, et rien n’est signalé', () => {
  const { monde } = builderDePoche({ fonctions: { classe_active: true }, pastilles: [] });

  monde.mutation();

  affirmerEgal(0, monde.avertissements.length, monde.avertissements.join(' / '));
});

await test('une désactivation volontaire est retenue, et seulement pour ce jeu de classes', () => {
  const { monde, noeuds } = builderDePoche({
    fonctions: { classe_active: true },
    pastilles: [{ id: 'a' }, { id: 'b', active: true }],
  });

  // Le geste d'une désactivation volontaire : cliquer la pastille **déjà active**.
  monde.geste(noeuds.pastilles[1], 'click');

  // Puis Bricks la désactive, et le DOM change — ce que la fonction observe.
  noeuds.pastilles[1].classList.remove('active');

  const avant = clics(noeuds.pastilles[1]);

  monde.mutation();

  affirmerEgal(avant, clics(noeuds.pastilles[1]), 'la classe désactivée à la main est ré-activée : on se bat contre l’utilisateur');

  /*
   * Et la mémoire porte sur **ce jeu de classes**, pas sur l'élément ni sur la
   * session : une troisième classe change la clé, et la fonction agit de nouveau.
   * Une mémoire globale aurait éteint la fonction pour le reste de la séance.
   */
  const troisieme = monde.element('li', {
    attributs: { 'data-class-id': 'c' },
    parent: noeuds.pastilles[1].parentNode,
  });

  monde.mutation();

  affirmerEgal(1, clics(troisieme), 'un autre jeu de classes reste sous le coup de la désactivation précédente');
});

/* ================================================================== *
 * 2. Le curseur de largeur
 * ================================================================== */

process.stdout.write('\nLe curseur de largeur\n');

await test('le curseur est posé après le groupe du champ, en miroir de la largeur', async () => {
  const { monde, noeuds } = builderDePoche({ fonctions: { curseur_largeur: true } });

  // Le curseur attend le champ de Bricks : il est posé au tour suivant, pas au
  // chargement. Le lire tout de suite le dirait absent.
  await respirer();

  const enveloppe = monde.document.querySelector('.anode-builder-curseur');

  affirmer(enveloppe !== null, 'aucun curseur n’a été inséré');
  affirmerEgal(noeuds.groupe.parentNode, enveloppe.parentNode, 'le curseur est inséré dans le groupe, ce qui casse l’alignement');

  const champ = enveloppe.querySelector('.anode-builder-curseur__champ');
  const min = Number(champ.min);
  const max = Number(champ.max);

  affirmerEgal(String(min + max - 1280), champ.value, 'le curseur n’est pas en miroir de la largeur');
});

await test('un repère par point de rupture, le plus large à gauche', async () => {
  const { monde } = builderDePoche({
    fonctions: { curseur_largeur: true },
    ruptures: [{ width: '991px' }, { width: '478px' }],
  });

  await respirer();

  const reperes = monde.document.querySelectorAll('.anode-builder-curseur__repere')
    .map((r) => ({
      px: Number.parseInt(/(\d+)\s*px/.exec(r.title)[1], 10),
      gauche: Number.parseFloat(r.style.left),
    }))
    .sort((a, b) => a.gauche - b.gauche);

  affirmerEgal(2, reperes.length, 'les repères ne suivent pas bricksData.breakpoints');
  affirmerEgal(991, reperes[0].px, 'le plus large n’est pas à gauche — le sens du curseur est inversé');
  affirmerEgal(478, reperes[1].px, 'le plus étroit n’est pas à droite');
});

await test('tirer le curseur écrit dans le champ, et frappe la touche que Bricks écoute', async () => {
  /*
   * Le contrat mesuré : ce champ n'est pas en `v-model`. Bricks ne relit sa valeur
   * que sur `blur` et sur un `keyup` filtré par Entrée. Poser la valeur puis émettre
   * `input` affichait la nouvelle largeur et laissait le canevas sur l'ancienne —
   * constaté à l'usage, et invisible sur une capture.
   */
  const { monde, noeuds } = builderDePoche({ fonctions: { curseur_largeur: true } });

  await respirer();

  const champ = monde.document.querySelector('.anode-builder-curseur__champ');
  const min = Number(champ.min);
  const max = Number(champ.max);

  champ.value = String(min + max - 744);
  monde.geste(champ, 'input');

  affirmerEgal('744', noeuds.largeur.value, 'la largeur n’a pas été écrite dans le champ de Bricks');

  const frappes = noeuds.largeur.recus.filter((e) => e.type === 'keyup' && e.key === 'Enter');

  affirmerEgal(1, frappes.length, 'aucune touche Entrée : le champ affiche la valeur, le canevas garde l’ancienne');
});

await test('un champ verrouillé par Bricks est respecté', async () => {
  const { monde, noeuds } = builderDePoche({ fonctions: { curseur_largeur: true } });

  await respirer();

  noeuds.largeur.readOnly = true;

  const champ = monde.document.querySelector('.anode-builder-curseur__champ');

  champ.value = String(Number(champ.min) + Number(champ.max) - 744);
  monde.geste(champ, 'input');

  affirmerEgal('1280', noeuds.largeur.value, 'la largeur de bureau verrouillée par Bricks a été écrasée');
});

/* ================================================================== *
 * Le démarrage
 * ================================================================== */

process.stdout.write('\nLe démarrage\n');

await test('une fonction éteinte ne pose aucun écouteur, ni aucun curseur', () => {
  const { monde, noeuds } = builderDePoche({ bouton: true, fonctions: {} });

  monde.geste(noeuds.titreInstance, 'dblclick');

  affirmerEgal(0, clics(noeuds.bouton), 'le double-clic agit alors que la fonction est éteinte');
  affirmerEgal(null, monde.document.querySelector('.anode-builder-curseur'), 'le curseur est posé alors qu’il est éteint');
});

await test('une accroche disparue rend sa fonction inerte, sans emporter les autres', async () => {
  /*
   * Le jour où Bricks renomme un sélecteur : sans `#bricks-toolbar`, le curseur
   * attend un champ qui ne viendra pas. Ce qui doit continuer, c'est le reste — et
   * cela se vérifie par un double-clic qui ouvre encore, pas par l'absence de trace
   * dans la console.
   */
  const noeuds = {};

  const monde = monter({
    fonctions: { classe_active: true, curseur_largeur: true, composant_dblclic: true },
    decor: (m) => {
      const panneau = m.element('div', { attributs: { id: 'bricks-panel' } });

      noeuds.bouton = m.element('li', { parent: panneau });
      m.element('span', { classes: ['bricks-svg-wrapper', 'component'], parent: noeuds.bouton });

      const structure = m.element('div', { attributs: { id: 'bricks-structure' } });

      noeuds.instance = m.element('li', {
        classes: ['element', 'component'], attributs: { 'data-id': '1' }, parent: structure,
      });
    },
  });

  await respirer();

  monde.geste(noeuds.instance, 'dblclick');

  affirmerEgal(1, clics(noeuds.bouton), 'le double-clic est mort avec le curseur : les fonctions ne sont pas isolées');
  affirmerEgal(null, monde.document.querySelector('.anode-builder-curseur'), 'un curseur a été posé sans champ de largeur');
  affirmerEgal(0, monde.avertissements.length, monde.avertissements.join(' / '));
});

/* ------------------------------------------------------------------ */

process.stdout.write(`\n${reussis} test(s) réussi(s), ${echoues} échec(s).\n`);

process.exit(echoues > 0 ? 1 : 0);
