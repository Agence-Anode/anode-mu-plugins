# Composants Anode pour WordPress

> **Miroir généré — ne pas modifier ici.**
>
> La source vit dans le dépôt `wordpress`, sous
> `blueprint/mu-plugins/`.
> Ce dépôt est régénéré à chaque publication par
> `bin/release-mu-plugin.mjs`. Une modification faite ici serait écrasée.

## À quoi il sert

Il porte les **releases** que `anode-updater` installe sur les sites.
Chaque release publie :

```
<composant>-<version>.zip           le dossier du composant, tests exclus
<composant>-<version>.zip.sha256    son empreinte, vérifiée avant installation
<composant>-<version>.zip.sig       sa signature Ed25519, si le parc est armé
```

Les tags sont préfixés par le composant — `anode-seo-v1.3.0` — parce que
ce dépôt en héberge plusieurs.

## Installation manuelle

Elle ne devrait pas être nécessaire — `anode-updater` s’en charge. Au besoin,
décompresser l’archive dans `wp-content/mu-plugins/`.

`anode-loader.php` doit être à la racine de `mu-plugins/` : WordPress ne
charge que les fichiers PHP qui y sont directement, et c’est lui qui charge les
sous-dossiers. Il n’est pas suivi par l’updater.
