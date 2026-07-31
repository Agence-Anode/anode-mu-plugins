/**
 * Confort du builder Bricks — trois fonctions, aucune dépendance.
 *
 * Tout passe par le DOM et les gestionnaires de Bricks : on clique ce qu'un
 * humain cliquerait. Aucune fonction interne de l'application Vue n'est appelée
 * — elles sont minifiées, renommées à chaque version, et les atteindre demande
 * `__vueParentComponent`, absent d'un build de production.
 *
 * Le contrat visé a été relevé dans un builder réel, en 2.3.10 :
 *
 *   - les classes de l'élément : `.bricks-panel-selectors ul.active-classes li`,
 *     chacune portant `[data-class-id]`, et `.active` sur la sélectionnée ;
 *   - la largeur du canevas : `input#preview-width` dans `#bricks-toolbar` ;
 *   - la ligne sélectionnée dans la structure : `#bricks-structure li.element.active` ;
 *   - un composant : la même ligne, avec la classe `component` ;
 *   - le menu contextuel : `#bricks-builder-context-menu`, dont l'entrée
 *     « Modifier le composant » porte la classe `components`.
 *
 * Chaque fonction est isolée : celle qui échoue n'emporte pas les autres, et un
 * point d'accroche disparu la rend inerte plutôt que bruyante.
 */
(() => {
  'use strict';

  const reglages = window.anodeBuilder ?? {};
  const actives = reglages.fonctions ?? {};
  const i18n = reglages.i18n ?? {};

  /** Attend qu'un sélecteur existe, puis rend l'élément. Abandonne au bout du délai. */
  const attendre = (selecteur, delai = 30000) => new Promise((resolve) => {
    const trouve = document.querySelector(selecteur);

    if (trouve) {
      resolve(trouve);
      return;
    }

    const observateur = new MutationObserver(() => {
      const el = document.querySelector(selecteur);

      if (!el) return;

      observateur.disconnect();
      resolve(el);
    });

    observateur.observe(document.body, { childList: true, subtree: true });

    setTimeout(() => {
      observateur.disconnect();
      resolve(null);
    }, delai);
  });

  /**
   * Attend qu'une condition rende quelque chose, en scrutant à petits pas.
   *
   * `MutationObserver` ne conviendrait pas ici : la condition porte sur un
   * contenu que Vue remplace, et l'observateur se déclencherait des dizaines de
   * fois par frappe. Une scrutation courte et bornée est plus lisible, et elle
   * s'arrête d'elle-même.
   */
  const patienter = async (condition, delai) => {
    const fin = Date.now() + delai;

    for (;;) {
      const valeur = condition();

      if (valeur) return valeur;
      if (Date.now() > fin) return null;

      await new Promise((resolve) => requestAnimationFrame(resolve));
    }
  };

  /**
   * Un clic que Vue entend.
   *
   * `el.click()` suffit pour un gestionnaire posé par Vue sur cet élément même,
   * mais pas pour un gestionnaire délégué : d'où l'événement complet, qui
   * remonte.
   */
  const cliquer = (el) => el.dispatchEvent(
    new MouseEvent('click', { bubbles: true, cancelable: true, view: window }),
  );

  /* ------------------------------------------------------------------ *
   * 1. La dernière classe de la cascade reste sélectionnée
   * ------------------------------------------------------------------ */

  /**
   * Pourquoi la dernière et pas la première : c'est celle qui gagne.
   *
   * Bricks applique les classes dans l'ordre de la liste ; la dernière porte donc
   * le style effectif. Sélectionner la première ferait modifier une règle que la
   * suivante écrase — l'utilisateur verrait son changement ne rien faire.
   *
   * Le vrai piège de cette fonction n'est pas de sélectionner : c'est de ne pas
   * se battre. Un utilisateur qui désélectionne volontairement — pour styler
   * l'`id`, ce qui est parfois légitime — verrait sa classe se ré-activer aussitôt.
   * D'où la clé : l'identifiant de l'élément sélectionné dans la structure. On
   * n'agit qu'une fois par élément, et un désélection volontaire est retenue
   * jusqu'à ce qu'on change d'élément.
   */
  const classeActive = () => {
    const laissees = new Set();

    const pastilles = () => [...document.querySelectorAll('.active-classes li')]
      .filter((li) => li.matches('[data-class-id]') || li.querySelector('[data-class-id]'));

    const identifiantDe = (li) => li.getAttribute('data-class-id')
      ?? li.querySelector('[data-class-id]')?.getAttribute('data-class-id')
      ?? '';

    /**
     * Aucune notion d'« élément courant », et c'est le correctif.
     *
     * Les deux versions précédentes essayaient de savoir *quel* élément on
     * regardait, pour n'agir qu'une fois par élément : d'abord par la ligne active
     * de la structure, puis avec un repli sur la liste des classes. Les deux
     * échouaient de la même façon à l'usage — « ça ne marche qu'à partir du
     * deuxième élément » —, parce que l'identité de l'élément et l'arrivée des
     * pastilles dans le panneau ne sont pas simultanées : au premier passage, on
     * retenait un élément dont la liste n'était pas encore là, et on ne repassait
     * plus.
     *
     * La question « quel élément ? » n'a en réalité pas à être posée. La règle
     * tient en une phrase : **s'il y a des classes et qu'aucune n'est active, on
     * active la dernière.** C'est vrai au premier élément comme au centième, quel
     * que soit l'endroit d'où l'on a sélectionné, et c'est idempotent — une fois la
     * classe active, la condition est fausse et l'on ne fait plus rien.
     *
     * Reste le seul cas où il faut s'abstenir : l'utilisateur a désactivé la
     * classe **volontairement**, pour styler l'`id`. On retient alors ce jeu de
     * classes précis, et on ne le contrarie plus.
     */
    const activer = () => {
      const liste = pastilles();

      if (!liste.length) return;
      if (liste.some((li) => li.classList.contains('active'))) return;

      const cle = liste.map(identifiantDe).join('|');

      if (laissees.has(cle)) return;

      cliquer(liste[liste.length - 1]);
    };

    // Une désactivation volontaire se reconnaît à ceci : le clic porte sur une
    // pastille déjà active. Bricks la désactive alors, et nous devons l'accepter.
    document.addEventListener('click', (evenement) => {
      const pastille = evenement.target.closest?.('.active-classes li');

      if (!pastille || !pastille.classList.contains('active')) return;

      laissees.add(pastilles().map(identifiantDe).join('|'));
    }, true);

    const observateur = new MutationObserver(() => activer());

    observateur.observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['class'],
    });

    activer();
  };

  /* ------------------------------------------------------------------ *
   * 2. Curseur de largeur du canevas
   * ------------------------------------------------------------------ */

  /**
   * Le curseur écrit dans le champ de Bricks, il ne pilote pas le canevas.
   *
   * C'est ce qui le rend robuste : la largeur reste gérée par Vue, avec sa mise à
   * l'échelle, son point de rupture actif et son verrou.
   *
   * **Mais ce champ n'est pas en `v-model`.** Relevé dans le rendu de Bricks : il
   * porte `value` en liaison descendante, et ne relit la saisie que sur
   * `onBlur` et sur `onKeyup` filtré par la touche Entrée. Poser la valeur puis
   * émettre `input` ne déclenchait donc rien — le champ affichait la nouvelle
   * largeur et le canevas gardait l'ancienne. C'est exactement ce qui a été
   * constaté à l'usage. On simule donc la touche Entrée, qui est le geste que
   * Bricks écoute.
   *
   * Le sens est inversé à dessein : le plus large à gauche, le plus étroit à
   * droite. C'est l'ordre dans lequel on conçoit — on part du bureau et on
   * resserre — et l'ordre des points de rupture de Bricks lui-même.
   */
  const curseurLargeur = async () => {
    const champ = await attendre('#bricks-toolbar input#preview-width');

    if (!champ || document.querySelector('.anode-builder-curseur')) return;

    const ruptures = (window.bricksData?.breakpoints ?? [])
      .map((b) => Number.parseInt(b.width, 10))
      .filter((largeur) => Number.isFinite(largeur) && largeur > 0)
      .sort((a, b) => a - b);

    const min = 320;
    const max = Math.max(1920, (ruptures[ruptures.length - 1] ?? 1280) + 200);

    /*
     * Le curseur porte une valeur **miroir**, pas la largeur.
     *
     * Inverser par `direction: rtl` aurait suffi au rendu, mais laissait les
     * repères à calculer dans l'autre sens — deux systèmes de coordonnées pour
     * un seul curseur, et la première correction à côté se pose dans le mauvais.
     * Ici, un seul calcul : `miroir = min + max - largeur`, dans les deux sens.
     */
    const miroir = (valeur) => min + max - valeur;

    const enveloppe = document.createElement('div');
    enveloppe.className = 'anode-builder-curseur';

    const curseur = document.createElement('input');
    curseur.type = 'range';
    curseur.min = String(min);
    curseur.max = String(max);
    curseur.step = '1';
    curseur.className = 'anode-builder-curseur__champ';
    curseur.setAttribute('aria-label', i18n.largeur ?? 'Largeur du canevas');

    /*
     * Les repères sont dessinés, pas confiés à `<datalist>`.
     *
     * Un `datalist` sur un `input[type=range]` n'est rendu que par certains
     * navigateurs, et jamais de façon pilotable en CSS : les graduations
     * seraient absentes ou hors charte selon le poste.
     */
    const graduations = document.createElement('div');
    graduations.className = 'anode-builder-curseur__reperes';

    /*
     * Les repères sont les points de rupture **de ce site**, lus dans
     * `bricksData.breakpoints` — ceux que Bricks vient de servir, pas une liste
     * écrite en dur. Ajouter, déplacer ou supprimer un point de rupture les
     * déplace donc, et cette fonction les redessine dès que la liste change,
     * sans recharger le builder.
     */
    const dessinerReperes = (largeurs) => {
      graduations.textContent = '';

      largeurs.forEach((largeur) => {
        if (largeur < min || largeur > max) return;

        const repere = document.createElement('span');
        repere.className = 'anode-builder-curseur__repere';
        repere.style.left = `${((miroir(largeur) - min) / (max - min)) * 100}%`;
        repere.title = `${i18n.pointDeRupture ?? 'Point de rupture'} : ${largeur} px`;
        graduations.append(repere);
      });
    };

    dessinerReperes(ruptures);

    enveloppe.append(curseur, graduations);

    // Après le groupe qui porte le champ numérique : la barre d'outils est en
    // flex, et s'insérer au milieu d'un groupe casserait son alignement.
    const groupe = champ.closest('li, div');

    (groupe ?? champ).after(enveloppe);

    const versLeChamp = () => {
      // Un champ en lecture seule, c'est la largeur du bureau verrouillée par
      // Bricks : la respecter plutôt que d'écrire une valeur qu'il ignorera.
      if (champ.readOnly) return;

      champ.value = String(miroir(Number.parseInt(curseur.value, 10)));

      // La seule liaison que Bricks écoute pour ce champ. `input` et `change`
      // n'y sont pas branchés : mesuré sur le rendu, et constaté à l'usage.
      champ.dispatchEvent(new KeyboardEvent('keyup', { key: 'Enter', bubbles: true }));
    };

    const depuisLeChamp = () => {
      const largeur = Number.parseInt(champ.value, 10);

      if (Number.isFinite(largeur)) {
        curseur.value = String(miroir(Math.min(Math.max(largeur, min), max)));
      }

      // Les points de rupture peuvent avoir changé depuis le dernier passage :
      // on ne redessine que si la liste a bougé, pour ne pas recréer des nœuds
      // trois fois par seconde.
      const actuelles = (window.bricksData?.breakpoints ?? [])
        .map((b) => Number.parseInt(b.width, 10))
        .filter((valeur) => Number.isFinite(valeur) && valeur > 0)
        .sort((a, b) => a - b);

      if (actuelles.join() !== ruptures.join()) {
        ruptures.length = 0;
        ruptures.push(...actuelles);
        dessinerReperes(ruptures);
      }
    };

    curseur.addEventListener('input', versLeChamp);
    champ.addEventListener('input', depuisLeChamp);
    champ.addEventListener('change', depuisLeChamp);

    /*
     * Un relevé périodique en plus des événements.
     *
     * Vue écrit la largeur dans la propriété `value` sans émettre d'`input` —
     * c'est le cas quand on change de point de rupture, ou qu'on redimensionne
     * le panneau. Sans ce relevé, le curseur resterait sur l'ancienne position et
     * mentirait sur ce qu'on regarde.
     */
    setInterval(depuisLeChamp, 400);

    depuisLeChamp();
  };

  /* ------------------------------------------------------------------ *
   * 3. Double-clic sur un composant pour l'ouvrir
   * ------------------------------------------------------------------ */

  /**
   * On passe par le bouton du panneau, pas par le menu contextuel.
   *
   * Deux raisons, toutes deux mesurées.
   *
   * D'abord le menu : `#bricks-builder-context-menu` existe en permanence dans le
   * DOM, vide, et ne se **remplit** qu'après un rendu de Vue. Le trouver ne
   * prouvait rien, et le clic partait dans le vide — le double-clic ne faisait
   * alors que sélectionner la ligne, en laissant parfois le menu ouvert sur une
   * entrée voisine.
   *
   * Ensuite, Bricks **utilise déjà le double-clic** sur `.structure-item` : c'est
   * son mode « renommer ». Notre gestionnaire doit donc le devancer et l'arrêter,
   * sans quoi les deux comportements se superposent.
   *
   * Le bouton, lui, est du DOM stable : dans `#bricks-panel`, le `li` qui contient
   * `span.bricks-svg-wrapper.component`. Il n'apparaît qu'une fois le composant
   * sélectionné, et disparaît dès qu'on est entré dedans — ce qui en fait aussi
   * le signal de réussite. Sa classe est visée, jamais son `data-balloon`, qui est
   * traduit.
   */
  const composantDblclic = () => {
    const boutonDuPanneau = () => [...document.querySelectorAll('#bricks-panel span.bricks-svg-wrapper.component')]
      .map((icone) => icone.closest('li'))
      .find(Boolean) ?? null;

    /**
     * Sommes-nous déjà **dans** un composant ?
     *
     * C'est la question qui manquait, et son absence rendait la fonction
     * instable au point d'être nuisible. Le bouton du panneau ne signifie pas
     * « voici un composant à ouvrir » : le rendu de Bricks l'affiche aussi pour
     * un composant **imbriqué**, alors même qu'on est déjà en train d'en éditer
     * un. Et `setActiveComponent` appliqué au composant courant **bascule** :
     * il fait sortir.
     *
     * D'où les trois symptômes constatés, qui n'en font qu'un : on sortait sans
     * savoir pourquoi ; un troisième clic — qui forme un second double-clic avec
     * le deuxième — nous éjectait ; et sélectionner un élément imbriqué faisait
     * de même.
     *
     * Le repère est le panneau de structure. Au niveau de la page, les lignes
     * racines portent `data-parent-id="0"`. En édition de composant, la racine
     * affichée est le composant lui-même : plus aucune ligne n'a `0` pour parent.
     * Mesuré dans le builder, et c'est un booléen franc, pas une heuristique.
     */
    const dansUnComposant = () => document.querySelector('#bricks-structure li.element') !== null
      && document.querySelector('#bricks-structure li.element[data-parent-id="0"]') === null;

    /*
     * Un verrou de temps, en plus.
     *
     * Trois clics rapides produisent **deux** événements `dblclick` : les clics 1‑2,
     * puis les clics 2‑3. Le second retombait sur notre gestionnaire à un moment où
     * le panneau n'avait pas encore basculé, donc où le bouton était encore là. Le
     * verrou avale ce second passage.
     */
    let derniereAction = 0;

    const tropTot = () => {
      const maintenant = Date.now();

      if (maintenant - derniereAction < 700) return true;

      derniereAction = maintenant;

      return false;
    };

    document.addEventListener('dblclick', async (evenement) => {
      const ligne = evenement.target.closest?.('#bricks-structure li.element.component');

      if (!ligne) return;

      // Un champ en cours d'édition : le double-clic y sélectionne un mot.
      if (evenement.target.closest('input, textarea, [contenteditable="true"]')) return;

      // Déjà dans un composant : on ne touche plus à rien. Le renommage, la
      // sélection et le déplacement doivent y fonctionner comme sans nous.
      if (dansUnComposant()) return;

      /*
       * On ne devance Bricks **que** si l'on a réellement quelque chose à ouvrir.
       *
       * La version précédente coupait le geste d'abord et cherchait le bouton
       * ensuite. Résultat, mesuré à l'usage : une fois **dans** un composant, il
       * n'y a plus de composant à ouvrir, mais la ligne racine porte toujours la
       * classe `component` — le renommage au double-clic était donc supprimé sans
       * rien offrir en échange. Le builder devenait moins utilisable qu'avant.
       *
       * Le bouton est le seul juge : présent, on l'ouvre ; absent, on laisse
       * Bricks renommer. Et il est déjà là au moment du double-clic, puisque le
       * premier des deux clics a sélectionné la ligne — d'où la lecture
       * synchrone, sans attente qui laisserait le renommage démarrer.
       */
      const bouton = boutonDuPanneau();

      if (!bouton || tropTot()) return;

      evenement.preventDefault();
      evenement.stopPropagation();
      evenement.stopImmediatePropagation();

      cliquer(bouton);
    }, true);

    /*
     * Le même geste dans le canevas, où l'on passe le plus clair de son temps.
     *
     * Le canevas est une iframe de même origine : on peut y écouter. Et le
     * raisonnement s'y simplifie — il n'y a pas de « ligne de composant » à
     * reconnaître, ni de « renommer » à devancer. On laisse Bricks sélectionner ce
     * qu'on vient de double-cliquer, puis on regarde si le panneau propose
     * d'ouvrir un composant. S'il le propose, c'est que la sélection en fait
     * partie ; sinon on n'a rien fait.
     */
    const ecouterLeCanevas = (fenetre) => {
      if (!fenetre?.document || fenetre.document.anodeBuilderEcoute) return;

      fenetre.document.anodeBuilderEcoute = true;

      fenetre.document.addEventListener('dblclick', (evenement) => {
        if (evenement.target.closest?.('input, textarea, [contenteditable="true"]')) return;

        // Les mêmes trois gardes que dans la structure, et dans le même ordre :
        // déjà dedans, on ne fait rien ; pas de bouton, on ne fait rien ; trop
        // rapproché du précédent, on avale.
        if (dansUnComposant()) return;

        const bouton = boutonDuPanneau();

        if (!bouton || tropTot()) return;

        cliquer(bouton);
      }, true);
    };

    /*
     * L'iframe est remplacée à chaque rechargement du canevas — après un
     * enregistrement, un changement de point de rupture, une régénération du CSS.
     * Un écouteur posé une fois disparaîtrait avec elle, et la fonction cesserait
     * de marcher sans rien dire. On le repose donc à chaque `load`.
     */
    const suivreLeCanevas = async () => {
      const cadre = await attendre('iframe#bricks-builder-iframe, #bricks-preview iframe, iframe');

      if (!cadre) return;

      ecouterLeCanevas(cadre.contentWindow);
      cadre.addEventListener('load', () => ecouterLeCanevas(cadre.contentWindow));
    };

    suivreLeCanevas();
  };

  /* ------------------------------------------------------------------ */

  const demarrer = () => {
    // Chacune dans son propre essai : une accroche disparue à la prochaine
    // version de Bricks rend une fonction inerte, elle n'emporte pas les autres.
    const lancer = (nom, fonction) => {
      if (!actives[nom]) return;

      try {
        fonction();
      } catch (erreur) {
        // Le builder n'est pas l'endroit d'une alerte : on trace, et on continue.
        console.warn(`anode-builder : « ${nom} » n’a pas pu démarrer.`, erreur);
      }
    };

    lancer('classe_active', classeActive);
    lancer('curseur_largeur', curseurLargeur);
    lancer('composant_dblclic', composantDblclic);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', demarrer, { once: true });
  } else {
    demarrer();
  }
})();
