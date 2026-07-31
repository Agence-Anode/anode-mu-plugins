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
    let dernierElement = null;

    const elementCourant = () => document
      .querySelector('#bricks-structure li.element.active')
      ?.getAttribute('data-id') ?? null;

    const pastilles = () => [...document.querySelectorAll('.active-classes li')]
      .filter((li) => li.matches('[data-class-id]') || li.querySelector('[data-class-id]'));

    const activer = () => {
      const element = elementCourant();

      if (element === null || element === dernierElement) return;

      const liste = pastilles();

      // Aucune classe : l'élément n'en porte pas, il n'y a rien à préférer à son
      // `id`. On note quand même l'élément, pour ne pas repasser en boucle.
      if (!liste.length) {
        dernierElement = element;
        return;
      }

      dernierElement = element;

      if (laissees.has(element)) return;
      if (liste.some((li) => li.classList.contains('active'))) return;

      cliquer(liste[liste.length - 1]);
    };

    // Une désélection volontaire se reconnaît à ceci : le clic porte sur une
    // pastille déjà active. Bricks la désactive alors, et nous devons l'accepter.
    document.addEventListener('click', (evenement) => {
      const pastille = evenement.target.closest?.('.active-classes li');

      if (!pastille || !pastille.classList.contains('active')) return;

      const element = elementCourant();

      if (element !== null) laissees.add(element);
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
   * l'échelle, son point de rupture actif et son verrou. Nous ne faisons que
   * poser une valeur et prévenir — `input` pour que le modèle suive, `change`
   * pour les gestionnaires qui n'écoutent que la validation.
   *
   * Le maximum vient du plus large point de rupture, avec une marge : au-delà,
   * Bricks réduit l'aperçu à l'échelle, et balayer plus loin n'apprend rien.
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

    ruptures.forEach((largeur) => {
      if (largeur < min || largeur > max) return;

      const repere = document.createElement('span');
      repere.className = 'anode-builder-curseur__repere';
      repere.style.left = `${((largeur - min) / (max - min)) * 100}%`;
      repere.title = `${i18n.pointDeRupture ?? 'Point de rupture'} : ${largeur} px`;
      graduations.append(repere);
    });

    enveloppe.append(curseur, graduations);

    // Après le groupe qui porte le champ numérique : la barre d'outils est en
    // flex, et s'insérer au milieu d'un groupe casserait son alignement.
    const groupe = champ.closest('li, div');

    (groupe ?? champ).after(enveloppe);

    const versLeChamp = () => {
      champ.value = curseur.value;
      champ.dispatchEvent(new Event('input', { bubbles: true }));
      champ.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const depuisLeChamp = () => {
      const largeur = Number.parseInt(champ.value, 10);

      if (!Number.isFinite(largeur)) return;

      curseur.value = String(Math.min(Math.max(largeur, min), max));
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
   * On emprunte le chemin de Bricks : clic droit, puis « Modifier le composant ».
   *
   * Appeler `$_setActiveComponent()` serait plus direct, et c'est justement ce
   * qu'on évite : la méthode n'est atteignable qu'à travers une instance Vue, que
   * `__vueParentComponent` n'expose pas dans un build de production. Le menu
   * contextuel, lui, est du DOM — et il fait exactement ce que fait un humain.
   *
   * L'entrée est reconnue par sa classe `components`, non par son libellé : celui
   * est traduit, et un builder en anglais ne dirait pas « Modifier le composant ».
   * Quand plusieurs entrées la portent, c'est la dernière — dans l'ordre de rendu
   * de Bricks, « Enregistrer comme composant » précède « Modifier ».
   */
  const composantDblclic = () => {
    document.addEventListener('dblclick', (evenement) => {
      const ligne = evenement.target.closest?.('#bricks-structure li.element.component');

      if (!ligne) return;

      // Une ligne en cours de renommage contient un champ : le double-clic y sert
      // à sélectionner un mot, pas à ouvrir le composant.
      if (evenement.target.closest('input, textarea, [contenteditable="true"]')) return;

      evenement.preventDefault();
      evenement.stopPropagation();

      const ancre = ligne.querySelector('.structure-item .label') ?? ligne;

      ancre.dispatchEvent(new MouseEvent('contextmenu', {
        bubbles: true,
        cancelable: true,
        view: window,
        button: 2,
      }));

      // Le menu est rendu par Vue : il n'existe qu'au tour de boucle suivant.
      requestAnimationFrame(() => {
        const menu = document.querySelector('#bricks-builder-context-menu');
        const entrees = menu ? [...menu.querySelectorAll(':scope > li.components')] : [];
        const entree = entrees[entrees.length - 1];

        if (!entree) return;

        cliquer(entree);
      });
    }, true);
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
