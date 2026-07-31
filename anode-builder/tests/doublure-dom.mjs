/**
 * Un DOM de poche, pour **exécuter** le script du builder hors navigateur.
 *
 * Pourquoi il existe, et ce n'est pas par goût du test unitaire : les trois
 * gardes de `composant_dblclic` protègent des états que Bricks 2.3.10 **ne
 * produit pas**. Mesuré sur la préproduction : le bouton « modifier le
 * composant » disparaît dans le même tour synchrone que le changement de
 * sélection — il n'existe donc aucune fenêtre où le bouton d'un composant
 * subsiste alors qu'on double-clique une ligne ordinaire. Un harnais qui pilote
 * un vrai builder ne peut pas visiter cet état : il ne peut que constater que
 * l'oracle du bouton suffit à lui seul, et rendre ses cas verts avec ou sans les
 * gardes. C'est exactement ce qui est arrivé — trois gardes retirées une à une du
 * fichier servi, 53 cas verts à chaque fois.
 *
 * Ici, l'état se **pose** : bouton présent *et* ligne ordinaire, horloge tenue à
 * la main. La décision devient donc éprouvable, et une réécriture qui supprime
 * une garde fait tomber un test au lieu de passer inaperçue.
 *
 * Ce que cette doublure ne prouve pas, et qu'il faut lire avec elle : elle ne dit
 * rien de ce que Bricks émet. Les sélecteurs sont épinglés par
 * `test-builder.php`, et le comportement du vrai builder par
 * `bin/test-builder-interactions.mjs`. Trois juges, trois questions distinctes.
 *
 * Le sous-ensemble de DOM implémenté est celui que `assets/builder.js` emploie,
 * et rien de plus : sélecteurs simples avec descendance, `closest`, `matches`,
 * `classList`, propagation avec capture et annulation, `MutationObserver` piloté
 * à la main, horloge injectée. Il n'y a ni mise en page, ni style calculé, ni
 * `:scope` — aucun n'est consulté par le script.
 *
 * @package Anode\Builder
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const ICI = dirname(fileURLToPath(import.meta.url));

/* ------------------------------------------------------------------ *
 * Sélecteurs
 * ------------------------------------------------------------------ */

/** Un morceau de sélecteur : `li.element[data-id]`, `input#preview-width`. */
function analyserMorceau(morceau) {
  return {
    tag: /^[a-zA-Z][\w-]*/.exec(morceau)?.[0]?.toLowerCase() ?? null,
    id: /#([\w-]+)/.exec(morceau)?.[1] ?? null,
    classes: [...morceau.matchAll(/\.([\w-]+)/g)].map((m) => m[1]),
    attributs: [...morceau.matchAll(/\[([\w-]+)(?:=["']?([^\]"']*)["']?)?\]/g)]
      .map((m) => ({ nom: m[1], valeur: m[2] ?? null })),
  };
}

/**
 * Une liste de sélecteurs, découpée par virgules puis par descendance.
 *
 * Seule la descendance est reconnue : `>`, `+` et `~` ne figurent dans aucun
 * sélecteur du script. Les rencontrer serait un signe qu'il a changé de contrat,
 * d'où le refus plutôt qu'un silence.
 */
function analyser(selecteur) {
  return selecteur.split(',').map((alternative) => {
    const morceaux = alternative.trim().split(/\s+/);

    if (morceaux.some((m) => ['>', '+', '~'].includes(m))) {
      throw new Error(`Combinateur non pris en charge par la doublure : « ${selecteur} »`);
    }

    return morceaux.map(analyserMorceau);
  });
}

function correspondMorceau(element, morceau) {
  if (morceau.tag && element.tagName !== morceau.tag) return false;
  if (morceau.id && element.getAttribute('id') !== morceau.id) return false;

  if (!morceau.classes.every((nom) => element.classList.contains(nom))) return false;

  return morceau.attributs.every(({ nom, valeur }) => {
    const lu = element.getAttribute(nom);

    return valeur === null ? lu !== null : lu === valeur;
  });
}

/** Le sélecteur est lu de droite à gauche, comme le fait un navigateur. */
function correspondAlternative(element, morceaux) {
  if (!correspondMorceau(element, morceaux[morceaux.length - 1])) return false;

  let index = morceaux.length - 2;
  let ancetre = element.parentNode;

  while (index >= 0) {
    if (!ancetre || !ancetre.classList) return false;

    if (correspondMorceau(ancetre, morceaux[index])) index -= 1;

    ancetre = ancetre.parentNode;
  }

  return true;
}

/* ------------------------------------------------------------------ *
 * Événements
 * ------------------------------------------------------------------ */

class EvenementDouble {
  constructor(type, options = {}) {
    this.type = type;
    this.bubbles = options.bubbles === true;
    this.cancelable = options.cancelable === true;
    this.view = options.view ?? null;
    this.key = options.key ?? '';
    this.defaultPrevented = false;
    this.propagationStoppee = false;
    this.propagationImmediateStoppee = false;
    this.target = null;
  }

  preventDefault() {
    if (this.cancelable) this.defaultPrevented = true;
  }

  stopPropagation() {
    this.propagationStoppee = true;
  }

  stopImmediatePropagation() {
    this.propagationStoppee = true;
    this.propagationImmediateStoppee = true;
  }
}

/**
 * La propagation, avec sa phase de capture — et ce détail est le sujet même du
 * test : les deux gestionnaires du script sont posés en capture, sur le document,
 * pour devancer le « renommer » de Bricks. Un double qui ne ferait que remonter
 * ne les appellerait jamais.
 */
function propager(cible, evenement) {
  evenement.target = cible;

  const chemin = [];

  for (let noeud = cible; noeud; noeud = noeud.parentNode) chemin.push(noeud);

  const appeler = (noeud, capture) => {
    for (const inscrit of [...(noeud.ecouteurs?.[evenement.type] ?? [])]) {
      if (inscrit.capture !== capture) continue;

      inscrit.rappel(evenement);

      if (evenement.propagationImmediateStoppee) return;
    }
  };

  for (const noeud of [...chemin].reverse()) {
    if (noeud === cible) break;

    appeler(noeud, true);

    if (evenement.propagationStoppee) return !evenement.defaultPrevented;
  }

  appeler(cible, true);

  if (!evenement.propagationImmediateStoppee) appeler(cible, false);

  if (evenement.bubbles) {
    for (const noeud of chemin.slice(1)) {
      if (evenement.propagationStoppee) break;

      appeler(noeud, false);
    }
  }

  return !evenement.defaultPrevented;
}

/* ------------------------------------------------------------------ *
 * Nœuds
 * ------------------------------------------------------------------ */

class NoeudDouble {
  constructor() {
    this.ecouteurs = {};
  }

  addEventListener(type, rappel, options = false) {
    const capture = options === true || options?.capture === true;

    (this.ecouteurs[type] ??= []).push({ rappel, capture });
  }

  dispatchEvent(evenement) {
    return propager(this, evenement);
  }
}

class ElementDouble extends NoeudDouble {
  constructor(tagName, document) {
    super();

    this.tagName = String(tagName).toLowerCase();
    this.ownerDocument = document;
    this.parentNode = null;
    this.children = [];
    this.attributs = new Map();
    this.style = {};
    this.textContent = '';
    this.title = '';
    /** Ce que le nœud a reçu : c'est ainsi qu'on lit un clic sans écouteur. */
    this.recus = [];

    const classes = new Set();

    this.classList = {
      add: (...noms) => noms.forEach((nom) => classes.add(nom)),
      remove: (...noms) => noms.forEach((nom) => classes.delete(nom)),
      contains: (nom) => classes.has(nom),
      toString: () => [...classes].join(' '),
    };

    Object.defineProperty(this, 'className', {
      get: () => [...classes].join(' '),
      set: (valeur) => {
        classes.clear();
        String(valeur).split(/\s+/).filter(Boolean).forEach((nom) => classes.add(nom));
      },
    });
  }

  setAttribute(nom, valeur) {
    this.attributs.set(nom, String(valeur));
  }

  getAttribute(nom) {
    return this.attributs.has(nom) ? this.attributs.get(nom) : null;
  }

  append(...noeuds) {
    for (const noeud of noeuds) {
      noeud.parentNode = this;
      this.children.push(noeud);
    }
  }

  after(...noeuds) {
    const parent = this.parentNode;
    const index = parent.children.indexOf(this);

    for (const [decalage, noeud] of noeuds.entries()) {
      noeud.parentNode = parent;
      parent.children.splice(index + 1 + decalage, 0, noeud);
    }
  }

  matches(selecteur) {
    return analyser(selecteur).some((alternative) => correspondAlternative(this, alternative));
  }

  closest(selecteur) {
    for (let noeud = this; noeud && noeud.classList; noeud = noeud.parentNode) {
      if (noeud.matches(selecteur)) return noeud;
    }

    return null;
  }

  descendants() {
    const tous = [];

    const parcourir = (noeud) => {
      for (const enfant of noeud.children) {
        tous.push(enfant);
        parcourir(enfant);
      }
    };

    parcourir(this);

    return tous;
  }

  querySelectorAll(selecteur) {
    const alternatives = analyser(selecteur);

    return this.descendants().filter(
      (element) => alternatives.some((alternative) => correspondAlternative(element, alternative)),
    );
  }

  querySelector(selecteur) {
    return this.querySelectorAll(selecteur)[0] ?? null;
  }

  dispatchEvent(evenement) {
    this.recus.push(evenement);

    return propager(this, evenement);
  }
}

class DocumentDouble extends NoeudDouble {
  constructor() {
    super();

    this.readyState = 'complete';
    this.parentNode = null;
    this.body = new ElementDouble('body', this);
    this.body.parentNode = this;
    this.children = [this.body];
  }

  createElement(tagName) {
    return new ElementDouble(tagName, this);
  }

  querySelectorAll(selecteur) {
    return this.body.querySelectorAll(selecteur);
  }

  querySelector(selecteur) {
    return this.querySelectorAll(selecteur)[0] ?? null;
  }
}

/* ------------------------------------------------------------------ *
 * Le monde dans lequel le script tourne
 * ------------------------------------------------------------------ */

/**
 * Fabrique un contexte, y exécute `assets/builder.js`, et rend les commandes.
 *
 * Le décor est posé **avant** l'exécution du script, et cet ordre n'est pas
 * cosmétique : `classeActive` fait un premier passage au démarrage, et le curseur
 * cherche le champ de largeur dès qu'il se lance. Un décor monté après coup ferait
 * passer les deux pour inertes.
 *
 * @param {object}   [options]
 * @param {object}   [options.fonctions] Les trois interrupteurs, comme le PHP les sert.
 * @param {object[]} [options.ruptures]  `bricksData.breakpoints`.
 * @param {Function} [options.decor]     Reçoit le monde, avant que le script ne tourne.
 * @returns {object} Le monde : document, horloge, mutation(), tics(), avertissements.
 */
export function monter({ fonctions = {}, ruptures = [{ key: 'tablet', width: '991px' }], decor = null } = {}) {
  const document = new DocumentDouble();
  const observateurs = [];
  const minuteries = [];
  const avertissements = [];

  let horloge = 1_000_000;

  const fenetre = {
    anodeBuilder: { fonctions, i18n: {} },
    bricksData: { breakpoints: ruptures },
  };

  const contexte = vm.createContext({
    window: fenetre,
    document,
    console: { warn: (...args) => avertissements.push(args.join(' ')) },
    MouseEvent: EvenementDouble,
    KeyboardEvent: EvenementDouble,
    FocusEvent: EvenementDouble,
    Event: EvenementDouble,
    Date: { now: () => horloge },
    Number,
    Math,
    Boolean,
    String,
    Set,
    Promise,
    Object,
    Array,
    // Les minuteries ne tournent pas d'elles-mêmes : c'est le test qui décide
    // quand un relevé périodique a lieu, sinon rien n'est reproductible.
    setTimeout: (rappel, delai) => minuteries.push({ rappel, delai }),
    setInterval: (rappel, delai) => minuteries.push({ rappel, delai, periodique: true }),
    MutationObserver: class {
      constructor(rappel) {
        this.rappel = rappel;
      }

      observe() {
        observateurs.push(this.rappel);
      }

      disconnect() {
        const index = observateurs.indexOf(this.rappel);

        if (index !== -1) observateurs.splice(index, 1);
      }
    },
  });

  fenetre.window = fenetre;

  const monde = {
    document,
    fenetre,
    avertissements,
    minuteries,

    /** Avance l'horloge que le script consulte. */
    avancer: (ms) => {
      horloge += ms;
    },

    /** Rejoue ce que le script observe : une mutation du DOM. */
    mutation: () => observateurs.forEach((rappel) => rappel([])),

    /** Rejoue les relevés périodiques — le curseur en a un. */
    tics: () => minuteries.filter((m) => m.periodique).forEach((m) => m.rappel()),

    /** Un élément neuf, rattaché où l'on veut. */
    element: (tag, { classes = [], attributs = {}, parent = document.body } = {}) => {
      const noeud = document.createElement(tag);

      classes.forEach((nom) => noeud.classList.add(nom));

      for (const [nom, valeur] of Object.entries(attributs)) noeud.setAttribute(nom, valeur);

      parent.append(noeud);

      return noeud;
    },

    /** Un geste, tel que le navigateur l'émettrait — capture comprise. */
    geste: (cible, type, { bubbles = true, cancelable = true } = {}) => {
      const evenement = new EvenementDouble(type, { bubbles, cancelable, view: fenetre });

      return { annule: !cible.dispatchEvent(evenement), evenement };
    },
  };

  decor?.(monde);

  vm.runInContext(readFileSync(join(ICI, '..', 'assets', 'builder.js'), 'utf8'), contexte, {
    filename: 'builder.js',
  });

  return monde;
}

export { ElementDouble, DocumentDouble, EvenementDouble };
