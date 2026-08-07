# Mises à jour — extensions WordPress

Dépôt de distribution. Le contenu est régénéré à la publication ;
toute retouche faite ici est perdue au passage suivant.

## Contenu d’une release

| Fichier | Rôle |
|---|---|
| `.zip` | l’extension, sans ses tests |
| `.zip.sha256` | empreinte, contrôlée avant d’écrire sur le disque |
| `.zip.sig` | signature, contrôlée quand la clé publique est en place |

## Étiquettes

Plusieurs extensions cohabitent ici : l’étiquette porte donc le nom de
l’extension avant son numéro.

## Pose à la main

Le site le fait seul. En dépannage, l’archive se décompresse dans
`wp-content/mu-plugins/`.

Le fichier de chargement reste **à la racine** de ce dossier : WordPress n’y
lit que les fichiers PHP de premier niveau, et c’est lui qui atteint les
sous-dossiers.
