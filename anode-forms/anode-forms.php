<?php
/**
 * Plugin Name: Anode — formulaires
 * Description: Réception, validation et relais des formulaires vers leurs webhooks — le site n'enregistre et ne notifie rien. Versant serveur du moteur assets/js/form-engine.js, en remplacement de l'élément « formulaire » de Bricks.
 * Version:     3.5.0
 * Author:      Anode
 *
 * Pourquoi ne pas utiliser l'élément de Bricks : un seul écran, une validation
 * limitée aux attributs HTML, et des actions à choisir dans une liste fermée.
 * Dès qu'un projet demande plusieurs étapes, une question conditionnelle ou un
 * traitement particulier, il faut le contourner.
 *
 * La définition d'un formulaire vit dans un fichier JSON versionné du site
 * (`theme/anode-child/forms/<nom>.json`) : navigateur et serveur lisent la même,
 * donc les contraintes ne peuvent pas diverger. Le client ne décide de rien —
 * un champ absent de la définition est refusé.
 *
 * @package Anode\Forms
 */

declare( strict_types = 1 );

namespace Anode\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Il n'y a pas de constante `VERSION`, et c'est voulu.
 *
 * Elle a existé, figée à « 1.7.0 » pendant que l'en-tête du fichier passait à
 * 3.0.1 — et personne ne l'a vu, parce qu'aucune ligne du dépôt ne la lisait :
 * c'est l'en-tête que `anode-updater` compare, c'est de lui que découlent le tag
 * et le nom de l'archive. Un second numéro, faux et sans lecteur, est de la même
 * famille que la clé `notify` que `clefs_inconnues()` refuse plus bas.
 */

/** Envois autorisés par heure et par adresse IP. */
const RATE_LIMIT = 10;

/** Nom du champ-piège par défaut, si la définition n'en impose pas d'autre. */
const HONEYPOT = '_website';

/**
 * Longueur maximale d'un champ libre, faute de `maxLength` déclaré.
 *
 * Elle est servie au navigateur par `describe()` : un plafond que seul le
 * serveur connaît laisse saisir mille caractères de trop, puis refuse l'envoi
 * entier — l'utilisateur découvre la limite au moment où il la dépasse.
 */
const MAX_LENGTH = 2000;

/**
 * Clés de la définition servies au navigateur.
 *
 * Liste blanche, et non liste noire. Retirer `notify` suffisait tant que
 * `notify` était la seule clé privée ; la première clé ajoutée demain à un
 * fichier de définition — jeton d'un service tiers, adresse de relais, note
 * interne — sortirait sur une route publique sans que personne ne l'ait décidé.
 */
const PUBLIC_KEYS = [
	'label',
	'screens',
	'fields',
	'messages',
	'labels',
	'note',
	'optionalLabel',
	'submitClass',
	'submitIcon',
	'previousIcon',
	'redirect',
	/*
	 * Type de demande par défaut, quand le CTA n'a pas porté de `?type=`
	 * (LFTF §5). Il devient `form_anode` sur la page de confirmation, d'où
	 * Piwik lit la conversion : sans valeur, une conversion remonterait sans
	 * type et ne serait attribuée à rien.
	 */
	'leadType',
	/*
	 * Identifiant de site Piwik PRO, utile seulement là où plusieurs propriétés
	 * Piwik cohabitent sur le même domaine : le moteur retient sinon le cookie
	 * de consentement le plus récemment mis à jour.
	 */
	'piwikSiteId',
	/*
	 * Nom de l'écran dont la sortie déclenche un envoi INTERMÉDIAIRE, marqué
	 * `partial: true` dans le corps relayé. Sans cette clé, rien ne change :
	 * le comportement reste un envoi unique, à la fin.
	 */
	'relayOnScreen',
];

/**
 * Clés que seul le serveur lit, et qui ne sortent jamais vers le navigateur.
 *
 * `PUBLIC_KEYS` et celle-ci forment ensemble tout ce qu'une définition peut
 * porter — voir `clefs_inconnues()`.
 */
const PRIVATE_KEYS = [
	'honeypot',
	'webhook',
	'webhooks',
];

/**
 * Les clés d'une définition que le moteur ne lit pas.
 *
 * Le moteur ignore en silence ce qu'il ne connaît pas, et ce silence a un coût
 * mesuré. Quand le stockage des demandes a été retiré, l'envoi de courriel est
 * parti avec — mais la clé qui portait le destinataire est restée dans les
 * définitions d'un site client en préproduction :
 *
 *     "notify": "contact@exemple.fr"
 *
 * Deux formulaires annonçaient donc une adresse de destination que plus une
 * ligne ne lisait (`grep -c wp_mail` rend 0), et rien ne pouvait le dire : ni
 * le moteur, qui ignore, ni la sonde de santé, qui ne regardait que la présence
 * d'un relais. Une clé morte qui *nomme une destination* est pire que son
 * absence : elle se lit comme un formulaire branché.
 *
 * Un tiret bas initial marque la documentation (`_lisezmoi`) : elle non plus
 * n'est lue par le moteur, mais elle ne prétend rien régler.
 *
 * Les clés numériques sont rendues, et non écartées : `json_decode` transforme
 * `"0"` en entier, et le filtre `is_string()` qu'on écrit par réflexe laisserait
 * passer en silence exactement ce qu'on cherche à faire remonter.
 *
 * @return list<string>
 */
function clefs_inconnues( array $definition ): array {
	$connues   = array_merge( PUBLIC_KEYS, PRIVATE_KEYS );
	$inconnues = [];

	foreach ( array_keys( $definition ) as $clef ) {
		$clef = (string) $clef;

		if ( '' !== $clef && '_' === $clef[0] ) {
			continue;
		}

		if ( ! in_array( $clef, $connues, true ) ) {
			$inconnues[] = $clef;
		}
	}

	return $inconnues;
}

/*
 * Il n'y a pas de durée de conservation, et c'est volontaire.
 *
 * Une constante `RETENTION = 1095` vivait ici, avec le commentaire « trois ans,
 * la durée annoncée aux visiteurs ». Plus rien ne la lisait depuis que le site
 * a cessé d'enregistrer les demandes, et sa seule clé de réduction
 * (« retention » dans une définition) n'était plus branchée nulle part.
 *
 * La laisser était pire que la retirer : elle donnait à relire une politique de
 * conservation à un composant qui ne conserve rien, et c'est sur elle que
 * s'appuyait la mention affichée sous la case de consentement — « effacées au
 * bout de trois ans » — qui était donc fausse.
 */

/**
 * Extensions recevables en pièce jointe, et le type MIME que le contenu doit
 * réellement porter.
 *
 * Liste blanche, jamais liste noire. Interdire « ce à quoi on a pensé » laisse
 * passer le reste : `.phtml`, `.phar`, `.pht` et `.php5` sont tous exécutés par
 * une configuration Apache banale, et la liste des extensions qu'un serveur mal
 * réglé exécute n'a pas de fin connue.
 *
 * Une définition de formulaire **restreint** cette liste avec `accept` ; elle ne
 * peut pas l'élargir. Sans cette règle, une faute de frappe dans un fichier JSON
 * versionné suffirait à ouvrir un dépôt de fichiers exécutables sur le site.
 */
const FICHIERS_AUTORISES = [
	'pdf'  => 'application/pdf',
	'doc'  => 'application/msword',
	'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	'odt'  => 'application/vnd.oasis.opendocument.text',
	'rtf'  => 'application/rtf',
	'xls'  => 'application/vnd.ms-excel',
	'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	'jpg'  => 'image/jpeg',
	'jpeg' => 'image/jpeg',
	'png'  => 'image/png',
	'webp' => 'image/webp',
	'zip'  => 'application/zip',
];

/**
 * Ce qu'un champ `file` accepte quand sa définition ne dit rien.
 *
 * Plus étroit que la liste blanche : une archive ou un tableur se demandent
 * explicitement. Un formulaire qui attend un CV n'a aucune raison d'accepter un
 * zip, et ce qui n'est pas demandé n'a pas à être stocké (RGPD art. 5.1.c).
 */
const ACCEPT_DEFAUT = [ 'pdf', 'doc', 'docx', 'odt', 'jpg', 'jpeg', 'png' ];

/**
 * Extensions refusées **où qu'elles se trouvent** dans le nom.
 *
 * `cv.php.pdf` finit par `.pdf` : la liste blanche le laisse passer. Servi par un
 * Apache qui décide du gestionnaire d'après *n'importe quelle* extension du nom —
 * ce que fait `AddHandler`, encore répandu chez les hébergeurs mutualisés — le
 * fichier est alors exécuté. C'est la seconde extension qu'il faut regarder, pas
 * seulement la dernière.
 */
const EXTENSIONS_DANGEREUSES = [
	'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'pht', 'phar',
	'shtml', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'com', 'bat', 'cmd',
	'jsp', 'asp', 'aspx', 'js', 'mjs', 'html', 'htm', 'xhtml', 'svg', 'htaccess',
];

/**
 * Types que `finfo` rend pour un conteneur, sans savoir ce qu'il contient.
 *
 * Un `.docx` est un zip, un `.doc` un conteneur OLE : selon la version de
 * libmagic, `finfo` rend « application/zip » ou « application/CDFV2-encrypted »
 * là où l'extension promet un type bureautique. Exiger l'égalité stricte
 * refuserait donc un CV Word sur un serveur et l'accepterait sur un autre.
 */
const MIME_CONTENEURS = [
	'application/zip',
	'application/octet-stream',
	'application/CDFV2-encrypted',
	'application/encrypted',
];

/** Taille d'une pièce jointe, en Mo, quand la définition n'en déclare pas. */
const TAILLE_DEFAUT_MO = 5;

/** Plafond absolu d'une pièce jointe, en Mo, qu'aucune définition ne dépasse. */
const TAILLE_MAX_MO = 20;

/** Sous-dossier des pièces jointes, à l'écart des médias du site. */
const DOSSIER_FICHIERS = 'anode-formulaires';

/**
 * Préfixe des pièces jointes dans le corps multipart.
 *
 * Il tient les fichiers à l'écart de `fields[…]` : sans lui, une pièce jointe
 * nommée comme un champ texte se retrouverait à disputer la même clé, et c'est
 * l'ordre d'analyse de PHP qui déciderait laquelle l'emporte.
 */
const PREFIXE_FICHIER = 'fichier_';


/**
 * Répertoire des définitions, dans le thème du site.
 */
function definitions_dir(): string {
	return get_stylesheet_directory() . '/forms';
}

/**
 * Lit la définition d'un formulaire.
 *
 * @return array<string, mixed>|null
 */
function definition( string $name ): ?array {
	$file = definitions_dir() . '/' . sanitize_file_name( $name ) . '.json';

	if ( ! is_readable( $file ) ) {
		return null;
	}

	$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	return is_array( $data ) ? $data : null;
}

/**
 * Champs déclarés, tous écrans confondus.
 *
 * @param array<string, mixed> $definition Définition du formulaire.
 *
 * @return array<string, array<string, mixed>> Nom du champ => définition.
 */
function fields_of( array $definition ): array {
	$screens = $definition['screens'] ?? [ [ 'fields' => $definition['fields'] ?? [] ] ];
	$fields  = [];

	foreach ( $screens as $screen ) {
		foreach ( $screen['fields'] ?? [] as $field ) {
			if ( ! empty( $field['name'] ) ) {
				$fields[ (string) $field['name'] ] = $field;
			}
		}
	}

	return $fields;
}

/* ------------------------------------------------------------------ */
/* Conditions d'affichage                                              */
/* ------------------------------------------------------------------ */

/**
 * Comparaisons acceptées dans un `showIf`, et les seules.
 *
 * Une seule par champ, rien d'imbriqué. Savoir dire « et », « ou » et « non »
 * ferait de la clé un petit langage : une grammaire à documenter, des priorités
 * à trancher, et un interpréteur à écrire **deux fois** — ici et dans
 * `form-engine.js` — dont la moindre divergence se solderait par un champ que
 * l'un montre et que l'autre jette. Le besoin réel, « cette question n'a de sens
 * que si l'on a répondu ceci », tient dans une comparaison.
 */
const OPERATEURS = [ 'equals', 'in', 'notEmpty' ];

/**
 * Forme comparable d'une réponse.
 *
 * Les deux côtés doivent normaliser à l'identique : sans ce passage, une même
 * réponse vaut « Location » ici et « Location  » là, le navigateur affiche le
 * champ, le serveur le croit masqué — et la réponse saisie est jetée sans que
 * personne ne le voie. Une case cochée vaut « 1 », comme ce qu'en conserve
 * `sanitize_field`, pour qu'un `"equals": true` la reconnaisse.
 */
function valeur_comparable( mixed $valeur ): string {
	if ( is_bool( $valeur ) ) {
		return $valeur ? '1' : '';
	}

	return is_scalar( $valeur ) ? trim( (string) $valeur ) : '';
}

/**
 * La condition d'affichage d'un champ est-elle remplie ?
 *
 * @param mixed                $condition Clé `showIf` du champ, ou null.
 * @param array<string, mixed> $valeurs   Réponses retenues — les champs masqués
 *                                        n'y figurent pas, ce qui propage
 *                                        naturellement le masquage en cascade.
 */
function condition_remplie( mixed $condition, array $valeurs ): bool {
	// Pas de condition : le champ fait partie du formulaire, toujours.
	if ( ! is_array( $condition ) || empty( $condition['field'] ) || ! is_string( $condition['field'] ) ) {
		return true;
	}

	$valeur = valeur_comparable( $valeurs[ $condition['field'] ] ?? '' );

	if ( array_key_exists( 'equals', $condition ) ) {
		return $valeur === valeur_comparable( $condition['equals'] );
	}

	if ( isset( $condition['in'] ) && is_array( $condition['in'] ) ) {
		return in_array( $valeur, array_map( __NAMESPACE__ . '\\valeur_comparable', $condition['in'] ), true );
	}

	if ( ! empty( $condition['notEmpty'] ) ) {
		return '' !== $valeur;
	}

	return true;
}

/**
 * Fautes d'une condition prise isolément.
 *
 * @param string              $nom       Nom du champ qui porte la condition.
 * @param mixed               $condition Clé `showIf`.
 * @param array<string, bool> $avant     Champs déclarés avant celui-ci.
 *
 * @return array<int, string>
 */
function faute_de_condition( string $nom, mixed $condition, array $avant ): array {
	if ( ! is_array( $condition ) ) {
		return [ sprintf( '« %s » : « showIf » attend un objet { "field": …, "equals" | "in" | "notEmpty": … }.', $nom ) ];
	}

	$fautes = [];
	$cible  = $condition['field'] ?? '';

	if ( ! is_string( $cible ) || '' === $cible ) {
		$fautes[] = sprintf( '« %s » : « showIf » doit nommer dans « field » le champ dont il dépend.', $nom );
	} elseif ( ! isset( $avant[ $cible ] ) ) {
		$fautes[] = sprintf(
			'« %s » dépend de « %s », qui n’est pas déclaré avant lui : la condition doit porter sur un champ '
				. 'du même écran posé plus haut, ou d’un écran précédent — ailleurs, la réponse n’existe pas '
				. 'encore au moment de décider si le champ s’affiche.',
			$nom,
			$cible
		);
	}

	$poses = array_values(
		array_filter( OPERATEURS, static fn ( string $operateur ): bool => array_key_exists( $operateur, $condition ) )
	);

	if ( ! $poses ) {
		$fautes[] = sprintf( '« %s » : « showIf » attend une comparaison — « equals », « in » ou « notEmpty ».', $nom );
	} elseif ( count( $poses ) > 1 ) {
		$fautes[] = sprintf(
			'« %s » : une seule comparaison par champ, or « showIf » en porte %d (%s).',
			$nom,
			count( $poses ),
			implode( ', ', $poses )
		);
	}

	if ( array_key_exists( 'equals', $condition ) && ! is_scalar( $condition['equals'] ) ) {
		$fautes[] = sprintf( '« %s » : « equals » attend une valeur simple.', $nom );
	}

	if ( array_key_exists( 'in', $condition ) ) {
		$liste = $condition['in'];

		/*
		 * Chaque entrée doit être une valeur simple, comme pour `equals`. Une
		 * liste contenant un tableau passait le contrôle, puis la comparaison
		 * échouait silencieusement à l'exécution : le champ ne s'ouvrait jamais,
		 * et la définition avait pourtant été acceptée.
		 */
		if ( ! is_array( $liste ) || ! $liste ) {
			$fautes[] = sprintf( '« %s » : « in » attend une liste de valeurs, non vide.', $nom );
		} elseif ( count( array_filter( $liste, 'is_scalar' ) ) !== count( $liste ) ) {
			$fautes[] = sprintf( '« %s » : « in » n’accepte que des valeurs simples.', $nom );
		}
	}

	return $fautes;
}

/**
 * Fautes des conditions d'affichage d'une définition.
 *
 * Une condition qui désigne un champ absent, ou posé plus loin dans le parcours,
 * ne peut pas être évaluée : au moment de décider si le champ s'affiche, la
 * réponse dont il dépend n'a pas encore été donnée. Chaque côté retomberait
 * alors sur son propre défaut — l'un en montrant le champ, l'autre en le croyant
 * masqué — et l'écart ne se verrait qu'à la première demande perdue.
 *
 * @param array<string, mixed> $definition Définition du formulaire.
 *
 * @return array<int, string> Un message par faute ; tableau vide si tout tient.
 */
function condition_errors( array $definition ): array {
	$screens = $definition['screens'] ?? [ [ 'fields' => $definition['fields'] ?? [] ] ];
	$fautes  = [];

	// Champs déjà déclarés, donc déjà répondus quand on arrive au suivant.
	$avant = [];

	foreach ( $screens as $screen ) {
		if ( ! is_array( $screen ) ) {
			continue;
		}

		foreach ( $screen['fields'] ?? [] as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				continue;
			}

			$nom = (string) $field['name'];

			if ( isset( $field['showIf'] ) ) {
				$fautes = array_merge( $fautes, faute_de_condition( $nom, $field['showIf'], $avant ) );
			}

			$avant[ $nom ] = true;
		}
	}

	return $fautes;
}

/**
 * Refuse une définition dont les conditions ne tiennent pas.
 *
 * Le refus est bruyant, et c'est délibéré : ignorer la condition afficherait une
 * question que le formulaire n'aurait pas dû poser — obligatoire, elle rendrait
 * la demande impossible à envoyer sans que rien n'en dise la raison. Une faute
 * de définition se corrige dans un fichier versionné, elle n'a pas à se
 * découvrir sur les demandes perdues.
 *
 * @param array<string, mixed> $definition Définition du formulaire.
 */
function definition_error( string $name, array $definition ): ?\WP_Error {
	$fautes = condition_errors( $definition );

	if ( ! $fautes ) {
		return null;
	}

	$message = sprintf( 'Formulaire « %s » : condition d’affichage invalide. %s', $name, implode( ' ', $fautes ) );

	error_log( '[anode-forms] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

	return new \WP_Error( 'anode_form_definition', $message, [ 'status' => 500 ] );
}

/**
 * Un champ de ce type est-il plafonné en longueur par `sanitize_field` ?
 *
 * Les autres — courriel, liste, date, nombre — sont validés par leur forme et
 * sortent de la fonction avant le contrôle de longueur : leur annoncer un
 * `maxLength` au navigateur promettrait une contrainte que rien n'applique.
 */
function is_free_text( string $type ): bool {
	// `file` en fait partie depuis qu'un champ peut porter une pièce jointe :
	// sans lui, la route publique annonçait un `maxLength` de 2 000 sur un champ
	// de fichier, et le moteur posait un `maxlength` sur un `<input type="file">`
	// — un attribut que le navigateur ignore, promettant une contrainte qui
	// n'existe pas et taisant la seule qui compte, la taille.
	return ! in_array( $type, [ 'email', 'url', 'tel', 'select', 'radio', 'checkbox', 'date', 'number', 'file' ], true );
}

/**
 * Inscrit dans chaque champ libre le plafond de longueur que le serveur
 * appliquera, quand la définition n'en déclare pas.
 *
 * @param array<string, mixed> $definition Définition du formulaire.
 *
 * @return array<string, mixed>
 */
function with_default_max_length( array $definition ): array {
	$complete = static function ( array $fields ): array {
		foreach ( $fields as $rang => $field ) {
			if ( ! is_array( $field ) || isset( $field['maxLength'] ) ) {
				continue;
			}

			if ( is_free_text( (string) ( $field['type'] ?? 'text' ) ) ) {
				$fields[ $rang ]['maxLength'] = MAX_LENGTH;
			}
		}

		return $fields;
	};

	if ( isset( $definition['fields'] ) && is_array( $definition['fields'] ) ) {
		$definition['fields'] = $complete( $definition['fields'] );
	}

	foreach ( $definition['screens'] ?? [] as $rang => $screen ) {
		if ( is_array( $screen ) && isset( $screen['fields'] ) && is_array( $screen['fields'] ) ) {
			$definition['screens'][ $rang ]['fields'] = $complete( $screen['fields'] );
		}
	}

	return $definition;
}

/* ------------------------------------------------------------------ */
/* Stockage                                                            */
/* ------------------------------------------------------------------ */

/**
 * Les soumissions sont des contenus : elles héritent ainsi de la liste, de la
 * recherche, de la corbeille et des permissions de WordPress, sans table
 * dédiée à maintenir.
 */

/* ------------------------------------------------------------------ */
/* Réception                                                           */
/* ------------------------------------------------------------------ */

add_action(
	'rest_api_init',
	static function (): void {
		/*
		 * La définition est servie par l'API plutôt qu'injectée dans la page :
		 * une seule source de vérité, le fichier JSON versionné. Les champs
		 * réservés au serveur — adresse de notification, nom du piège — n'en
		 * sortent jamais.
		 */
		register_rest_route(
			'anode/v1',
			'/form/(?P<name>[a-z0-9_-]+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\describe',
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			'anode/v1',
			'/form',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => __NAMESPACE__ . '\\handle',
				// Un formulaire public est par nature ouvert : la protection
				// vient de la validation, du rythme et des pièges, pas d'une
				// authentification.
				'permission_callback' => '__return_true',
				/*
				 * Aucun paramètre n'est déclaré obligatoire, et c'est délibéré.
				 *
				 * Le noyau valide les paramètres **avant** d'appeler `handle()`. Or
				 * un envoi tronqué — une pièce jointe plus grosse que
				 * `post_max_size`, où PHP vide le corps entier sans rien signaler —
				 * n'a plus aucun paramètre : la réponse aurait été « Paramètre
				 * manquant : form », qui ne dit rien du fichier et qu'aucun message
				 * du formulaire ne rattrape. `handle()` reconnaît ce cas au premier
				 * regard, et le nomme.
				 */
				'args'                => [
					'form'   => [ 'type' => 'string' ],
					'fields' => [ 'type' => 'object' ],
					'page'   => [ 'type' => 'string' ],
				],
			]
		);
	}
);

/**
 * Renvoie la définition publique d'un formulaire.
 */
/**
 * Clés d'un écran servies au navigateur.
 *
 * @param mixed $ecran
 * @return array<string, mixed>
 */
function ecran_public( $ecran ): array {
	if ( ! is_array( $ecran ) ) {
		return [];
	}

	$public = array_intersect_key( $ecran, array_flip( [ 'title', 'intro', 'fields' ] ) );

	if ( isset( $public['fields'] ) && is_array( $public['fields'] ) ) {
		$public['fields'] = array_map( __NAMESPACE__ . '\\champ_public', $public['fields'] );
	}

	return $public;
}

/**
 * Clés d'un champ servies au navigateur.
 *
 * Tout ce que le rendu et la validation côté client exigent, et rien d'autre.
 *
 * @param mixed $champ
 * @return array<string, mixed>
 */
function champ_public( $champ ): array {
	if ( ! is_array( $champ ) ) {
		return [];
	}

	/*
	 * Cette liste est **celle que le moteur lit**, relevée dans
	 * `assets/js/form-engine.js` (`field.<clé>`). En omettre une la fait
	 * disparaître de l'écran sans erreur : `helpLink`, oublié à la première
	 * écriture, aurait retiré le lien vers la politique de confidentialité de la
	 * case de consentement — sur les deux formulaires du site, et sans que rien
	 * ne le signale.
	 *
	 * Ajouter une clé au moteur suppose donc de l'ajouter ici.
	 */
	$public = array_intersect_key(
		$champ,
		array_flip(
			[
				'accept',
				'autocomplete',
				'full',
				'help',
				'helpLink',
				'label',
				// Une précision grise, à la suite de l'intitulé et sur la même
				// ligne — « (plusieurs choix possibles) ». Distincte de `help`,
				// qui se place sous le champ : ce n'est pas le même endroit,
				// donc pas la même clé.
				'labelHint',
				'maxLength',
				'maxSize',
				'minLength',
				// Sans elle, un `select` déclaré à choix multiple est rendu
				// simple par le navigateur : une seule valeur part, et les
				// autres réponses sont perdues sans un mot.
				'multiple',
				'name',
				'options',
				'pattern',
				'patternMessage',
				'placeholder',
				'required',
				'requiredMessage',
				'rows',
				// Sans elle, le navigateur n'apprend jamais qu'un champ est
				// conditionnel : il l'affiche à tout le monde, tandis que le serveur
				// écarte la réponse — la question est posée, et personne ne voit
				// pourquoi elle reste sans effet.
				'showIf',
				'type',
			]
		)
	);

	/*
	 * Les limites d'une pièce jointe sortent **calculées**, jamais telles qu'elles
	 * sont écrites dans la définition.
	 *
	 * Trois plafonds se rencontrent — celui du projet, celui du composant, celui de
	 * PHP — et c'est le plus bas qui s'applique. Servir la valeur déclarée laisserait
	 * le navigateur accepter un fichier que le serveur refusera : l'utilisateur
	 * attend le téléversement complet pour apprendre qu'il n'aura pas lieu.
	 */
	if ( 'file' === ( $champ['type'] ?? '' ) ) {
		$public = array_merge( $public, limites_fichier( $champ ) );
	}

	return $public;
}

function describe( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
	$name       = sanitize_key( (string) $request->get_param( 'name' ) );
	$definition = definition( $name );

	if ( null === $definition ) {
		return new \WP_Error( 'anode_form_unknown', 'Ce formulaire n’existe pas.', [ 'status' => 404 ] );
	}

	$faute = definition_error( $name, $definition );

	if ( $faute instanceof \WP_Error ) {
		return $faute;
	}

	/*
	 * Rien ne sort qui ne soit inscrit dans une liste blanche — et la liste
	 * s'applique **à tous les niveaux**.
	 *
	 * Filtrer seulement la racine laissait passer `screens` et `fields` en entier :
	 * une clé privée rangée dans un champ — jeton, adresse de relais, note
	 * interne, condition métier — sortait sur une route publique et sans
	 * authentification, c'est-à-dire exactement le scénario contre lequel la liste
	 * blanche existe.
	 */
	$public = array_intersect_key( $definition, array_flip( PUBLIC_KEYS ) );

	if ( isset( $public['screens'] ) && is_array( $public['screens'] ) ) {
		$public['screens'] = array_map( __NAMESPACE__ . '\\ecran_public', $public['screens'] );
	}

	if ( isset( $public['fields'] ) && is_array( $public['fields'] ) ) {
		$public['fields'] = array_map( __NAMESPACE__ . '\\champ_public', $public['fields'] );
	}

	// Le nom du champ-piège, lui, est nécessaire au rendu — et de toute façon
	// lisible dans le DOM. Le taire n'apporterait qu'une fausse discrétion.
	$public['honeypot'] = $definition['honeypot'] ?? HONEYPOT;
	$public['id']       = $name;

	return rest_ensure_response( with_default_max_length( $public ) );
}

function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
	/*
	 * Corps tronqué : PHP ne dit rien, il se contente de ne rien remplir.
	 *
	 * Au-delà de `post_max_size`, `$_POST` **et** `$_FILES` arrivent vides — le
	 * nom du formulaire compris. Le contrôle vient donc avant tout le reste :
	 * plus loin, la demande aurait déjà été refusée comme « formulaire inconnu »,
	 * et personne n'aurait su que la seule cause était un fichier trop lourd.
	 */
	if ( corps_tronque( $request ) ) {
		return new \WP_Error(
			'anode_form_trop_gros',
			sprintf( 'Votre envoi dépasse ce que le serveur accepte (%s au total).', size_format( (int) wp_max_upload_size() ) ),
			[ 'status' => 413 ]
		);
	}

	$name       = sanitize_key( (string) $request->get_param( 'form' ) );
	$definition = definition( $name );

	if ( null === $definition ) {
		return new \WP_Error( 'anode_form_unknown', 'Ce formulaire n’existe pas.', [ 'status' => 404 ] );
	}

	// Une définition dont les conditions ne tiennent pas ne peut pas être
	// appliquée : accepter l'envoi reviendrait à enregistrer une demande sans
	// savoir quelles questions ont réellement été posées.
	$faute = definition_error( $name, $definition );

	if ( $faute instanceof \WP_Error ) {
		return $faute;
	}

	$limited = check_rate_limit();

	if ( $limited instanceof \WP_Error ) {
		return $limited;
	}

	/*
	 * Le contexte se lit sur la REQUÊTE, et c'est tout l'enjeu.
	 *
	 * `$_POST` n'est peuplé que par un corps `form-urlencoded` ou multipart. Sur
	 * la voie JSON — celle qu'emprunte le moteur pour un formulaire sans pièce
	 * jointe, donc la grande majorité des envois — il est **vide**. Les valeurs
	 * lues là arrivaient donc systématiquement absentes, sans erreur ni trace :
	 * le bloc `tracking` partait à `[]`, `page_url` retombait sur la
	 * reconstruction sans query string, et `lead_type` sur la chaîne vide.
	 *
	 * Mesuré le 09/09/2026 par un tir de bout en bout : le lead arrivait en
	 * base avec une attribution entièrement vide, alors que le navigateur avait
	 * bien tout envoyé. Une lecture de code ne le montre pas — les deux voies
	 * se ressemblent, et celle qui marche est celle qu'on essaie le moins.
	 */
	$resultat = traiter(
		$name,
		$definition,
		(array) $request->get_param( 'fields' ),
		(string) $request->get_param( 'page' ),
		$request->get_file_params(),
		[
			'tracking'  => $request->get_param( 'tracking' ),
			'page_url'  => $request->get_param( 'page_url' ),
			'lead_type' => $request->get_param( 'lead_type' ),
			'partial'   => (bool) $request->get_param( 'partial' ),
		]
	);

	if ( $resultat instanceof \WP_Error ) {
		return $resultat;
	}

	// Un piège rempli reçoit le même « ok » qu'un envoi accepté, et rien de plus :
	// un identifiant de demande dirait au robot que sa soumission a été rangée.
	if ( ! empty( $resultat['piege'] ) ) {
		return rest_ensure_response( [ 'ok' => true ] );
	}

	/*
	 * Aucun identifiant n'est rendu : il n'y a rien d'enregistré à désigner.
	 * En rendre un — fût-il zéro — ferait croire à une fiche consultable.
	 */
	return rest_ensure_response(
		[
			'ok'      => true,
			'message' => $resultat['message'],
		]
	);
}

/**
 * Traitement commun aux deux voies de réception : l'API REST du moteur, et le
 * POST classique du formulaire de repli rendu par le serveur.
 *
 * Les deux doivent valider, enregistrer et notifier à l'identique. Écrire le
 * POST classique à côté de `handle()` aurait produit deux validations qui
 * dérivent — celle qu'on corrige et celle qu'on oublie —, et c'est justement la
 * voie qu'on ne regarde jamais qui aurait gardé la faille.
 *
 * La limitation de rythme reste à l'appelant. La voie classique la contrôle
 * **avant** le nonce, pour qu'un envoi au jeton périmé compte lui aussi ; la
 * placer ici la rendrait injoignable sur ce chemin.
 *
 * @param array<string, mixed> $definition Définition du formulaire.
 * @param array<string, mixed> $submitted  Valeurs reçues, non assainies.
 * @param array<string, mixed> $recus      Pièces jointes reçues, telles que `$_FILES`.
 *
 * @return array{ok: bool, piege?: bool, id?: int, message?: string}|\WP_Error
 */
function traiter( string $name, array $definition, array $submitted, string $page, array $recus = [], array $contexte = [] ): array|\WP_Error {
	$declared = fields_of( $definition );

	// Piège à robots : un champ que rien n'affiche, donc que seul un automate
	// remplit. Le rejet est silencieux — un robot informé s'adapterait.
	if ( ! empty( $submitted[ $definition['honeypot'] ?? HONEYPOT ] ) ) {
		return [ 'ok' => true, 'piege' => true ];
	}

	$values   = [];
	$errors   = [];
	$fichiers = [];

	/*
	 * Envoi INTERMÉDIAIRE : le lead part dès que les coordonnées sont saisies,
	 * puis à nouveau à la fin.
	 *
	 * Le formulaire d'origine faisait ainsi, et ce n'est pas un détail : une
	 * personne qui abandonne sur la dernière question de qualification est
	 * quand même un lead. La reproduire demande d'accepter un envoi dont les
	 * écrans suivants n'ont pas encore été remplis.
	 *
	 * La garde reste entière là où elle compte : un envoi partiel ne valide que
	 * les champs qu'il porte, il est marqué `partial: true` dans le corps
	 * relayé, et l'envoi FINAL exige toujours tout. Un POST partiel forgé ne
	 * produit donc qu'un lead partiel, annoncé comme tel — pas un contournement
	 * des champs obligatoires.
	 *
	 * Aucun formulaire n'en fait l'usage tant que sa définition ne déclare pas
	 * `relayOnScreen` : le comportement des autres projets ne change pas.
	 */
	$partiel = ! empty( $contexte['partial'] );

	foreach ( $declared as $field_name => $field ) {
		/*
		 * Sur un envoi partiel, un champ que le visiteur n'a pas encore pu voir
		 * n'est ni exigé ni relevé. On se fie à sa PRÉSENCE dans l'envoi, non à
		 * un numéro d'écran : le serveur n'a pas à rejouer la navigation pour
		 * savoir où en était la personne.
		 */
		if ( $partiel && ! array_key_exists( $field_name, $submitted ) ) {
			continue;
		}

		/*
		 * La condition est **réévaluée ici**, sur les réponses déjà retenues.
		 *
		 * Côté navigateur, elle ne fait que montrer ou cacher — un envoi direct à
		 * l'API ne passe par aucun écran. Sans ce second contrôle, un champ
		 * obligatoire se contournait en s'abstenant de l'envoyer, et une réponse à
		 * une question que le formulaire n'a jamais posée s'enregistrait comme si
		 * elle l'avait été : la condition n'aurait été qu'un effet visuel.
		 *
		 * Les champs sont parcourus dans leur ordre de déclaration, et
		 * `condition_errors()` refuse toute condition qui remonterait plus loin :
		 * une seule passe suffit donc. Un champ masqué ne laissant aucune réponse
		 * derrière lui, ceux qui en dépendent le sont à leur tour, sans que la
		 * cascade ait à être écrite.
		 */
		if ( ! condition_remplie( $field['showIf'] ?? null, $values ) ) {
			continue;
		}

		/*
		 * Une pièce jointe n'arrive pas par le même chemin que le reste.
		 *
		 * Elle vit dans `$_FILES`, pas dans `fields` : la router ici, et non dans
		 * `sanitize_field()`, garde à celle-ci son unique métier — assainir une
		 * valeur textuelle. Le champ conserve pour autant sa place dans l'ordre de
		 * déclaration, donc sa condition d'affichage et son caractère obligatoire.
		 */
		if ( 'file' === ( $field['type'] ?? '' ) ) {
			// Le moteur n'envoie jamais de texte sur un champ de fichier : une
			// valeur ici ne peut venir que d'un envoi forgé, qui tenterait
			// d'inscrire dans la demande un nom de fichier qu'il aurait choisi.
			if ( isset( $submitted[ $field_name ] ) && '' !== $submitted[ $field_name ] ) {
				$errors[ $field_name ] = sprintf( '%s : un fichier est attendu.', $field['label'] ?? $field_name );
				continue;
			}

			$recu = receptionner_fichier( (array) ( $recus[ PREFIXE_FICHIER . $field_name ] ?? [] ), $field );

			if ( $recu instanceof \WP_Error ) {
				$errors[ $field_name ] = $recu->get_error_message();
				continue;
			}

			if ( $recu ) {
				$fichiers[ $field_name ] = $recu;
				// La fiche, la notification et l'export lisent tous `_anode_values` :
				// le champ y porte de quoi reconnaître la pièce jointe, l'identifiant
				// qui permet de la relire étant rangé à part.
				$values[ $field_name ] = sprintf( '%s (%s)', $recu['nom'], size_format( $recu['taille'] ) );
			}

			continue;
		}

		$value = $submitted[ $field_name ] ?? '';
		$clean = sanitize_field( $value, $field );

		if ( $clean instanceof \WP_Error ) {
			$errors[ $field_name ] = $clean->get_error_message();
			continue;
		}

		$values[ $field_name ] = $clean;
	}

	// Un champ non déclaré n'est pas seulement ignoré : sa présence signale un
	// envoi forgé, et rien ne justifie de l'accepter à moitié.
	$unknown = array_diff( array_keys( $submitted ), array_keys( $declared ), [ $definition['honeypot'] ?? HONEYPOT ] );

	if ( $unknown ) {
		nettoyer_fichiers( $fichiers );

		return new \WP_Error(
			'anode_form_unknown_field',
			sprintf( 'Champ(s) inattendu(s) : %s.', implode( ', ', array_map( 'sanitize_key', $unknown ) ) ),
			[ 'status' => 400 ]
		);
	}

	/*
	 * Même règle pour les pièces jointes que pour les champs : ce qui n'est pas
	 * déclaré fait refuser l'envoi entier. Les ignorer aurait laissé n'importe qui
	 * déposer des fichiers sur le disque du site en les nommant à côté — écartés du
	 * traitement, mais bien arrivés.
	 */
	$attendus = [];

	foreach ( $declared as $field_name => $field ) {
		if ( 'file' === ( $field['type'] ?? '' ) ) {
			$attendus[] = PREFIXE_FICHIER . $field_name;
		}
	}

	$intrus = array_diff( array_keys( $recus ), $attendus );

	if ( $intrus ) {
		nettoyer_fichiers( $fichiers );

		return new \WP_Error(
			'anode_form_unknown_field',
			sprintf( 'Pièce(s) jointe(s) inattendue(s) : %s.', implode( ', ', array_map( 'sanitize_key', $intrus ) ) ),
			[ 'status' => 400 ]
		);
	}

	if ( $errors ) {
		// Les fichiers déjà rangés sont retirés : une demande refusée ne doit rien
		// laisser derrière elle, et un envoi rejoué en aurait déposé un de plus à
		// chaque tentative.
		nettoyer_fichiers( $fichiers );

		return new \WP_Error(
			'anode_form_invalid',
			implode( ' ', $errors ),
			[ 'status' => 400, 'fields' => $errors ]
		);
	}

	/*
	 * Le site ne garde rien. Jamais.
	 *
	 * Une demande part vers ses relais — n8n, Make, l'outil du client — et la
	 * donnée personnelle vit là-bas. Rien n'est écrit en base, aucun type de
	 * contenu n'est déclaré, aucun écran d'administration ne liste des demandes.
	 *
	 * Ce n'est pas une préférence de rangement. Ce qui déplace réellement la
	 * responsabilité d'un traitement, ce n'est pas l'endroit d'arrivée : c'est la
	 * conservation. Une copie gardée « au cas où » laisse ici une durée à
	 * justifier, une base à purger, un export à produire sur demande et un
	 * effacement à prouver — pour une donnée dont le site n'a aucun usage.
	 *
	 * La conséquence est assumée : **un relais qui échoue perd la demande**. On
	 * ne la rattrape pas en base, on le dit à la personne, tout de suite, pour
	 * qu'elle recommence ou appelle. Une erreur franche vaut mieux qu'un « merci,
	 * c'est envoyé » suivi d'un silence, et mieux qu'une copie de secours dont
	 * plus personne ne se souvient six mois plus tard.
	 */
	$relais = relayer( $name, $definition, $values, $page, $fichiers, $contexte );

	// Les fichiers ont été relayés avec le reste : ils n'ont plus rien à faire
	// sur le disque, quel que soit le sort de l'envoi.
	nettoyer_fichiers( $fichiers );

	if ( is_wp_error( $relais ) ) {
		/*
		 * Le journal du serveur garde la trace technique — l'URL visée et le
		 * motif — sans aucune valeur saisie. C'est ce qu'il faut pour réparer
		 * un relais cassé, et rien de ce qu'on s'est engagé à ne pas conserver.
		 */
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf( '[anode-forms] relais « %s » en échec : %s', $name, $relais->get_error_message() )
		);

		return new \WP_Error(
			'anode_form_relay_failed',
			(string) ( $definition['messages']['error']
				?? __( 'Votre demande n’a pas pu être transmise. Réessayez dans un instant, ou contactez-nous directement.', 'anode' ) ),
			[ 'status' => 502 ]
		);
	}

	/**
	 * Permet à un site de brancher un traitement supplémentaire — appel à un
	 * outil métier, marquage, réponse automatique.
	 *
	 * Aucun identifiant n'est passé : il n'y a rien d'enregistré à désigner.
	 *
	 * @param array<string, mixed> $values     Valeurs validées.
	 * @param array<string, mixed> $definition Définition du formulaire.
	 */
	do_action( "anode/form/{$name}/submitted", $values, $definition );

	return [
		'ok'      => true,
		'message' => (string) ( $definition['messages']['success'] ?? 'Votre demande a bien été envoyée.' ),
	];
}

/* ------------------------------------------------------------------ */
/* Repli sans JavaScript                                               */
/* ------------------------------------------------------------------ */

/*
 * Le moteur construit **tout** le formulaire depuis le navigateur. Sans lui —
 * JavaScript coupé, fichier bloqué par un filtrage d'entreprise, erreur réseau,
 * script tombé sur une seule ligne — le bloc `<div data-form="contact">` restait
 * vide : ni champ, ni message, ni adresse de repli. La demande n'était pas
 * perdue en chemin, elle n'était jamais écrite, et personne côté site ne pouvait
 * s'en apercevoir.
 *
 * Le serveur rend donc un formulaire complet dans la page, tous les écrans
 * repliés en un seul, posté classiquement. Le moteur le retire quand il
 * s'exécute et remet l'expérience à écrans multiples : c'est la seule forme qui
 * marche sans JavaScript *et* qui ne coûte rien à ceux qui l'ont.
 *
 * Rien n'est dupliqué : même fichier de définition, même validation, même
 * archivage, même notification — `traiter()` sert les deux voies.
 */

/**
 * Champs que le repli ajoute au POST, et qui ne sont donc pas des réponses.
 *
 * Sans cette liste, le contrôle des champs inattendus — celui qui refuse un
 * envoi forgé — prendrait le nonce pour une réponse et refuserait *toutes* les
 * demandes venues du repli.
 */
const CHAMPS_TECHNIQUES = [ 'anode_form', '_anode_nonce', '_wp_http_referer' ];

/** Paramètre d'URL qui porte l'accusé de réception après la redirection. */
const PARAM_RECU = 'anode-recu';

/** Durée de vie d'un accusé de réception, en secondes. */
const DUREE_RECU = 900;

/**
 * Action du nonce d'un formulaire.
 *
 * Une action par formulaire : un jeton relevé sur la page de contact ne doit pas
 * servir à poster la demande de rappel.
 */
function action_nonce( string $name ): string {
	return 'anode_form_' . $name;
}

/**
 * État de l'envoi en cours de traitement, partagé entre l'interception du POST
 * et le rendu de la page qui suit.
 *
 * Sur un refus, on **ne redirige pas** : les réponses saisies ne survivraient
 * pas au voyage, et c'est précisément ce qu'on cherche à ne plus perdre. La page
 * est donc re-rendue dans la même requête, et le repli y relit ce tableau pour
 * remettre les valeurs et poser les messages champ par champ.
 *
 * @param array<string, mixed>|null $nouveau Nouvel état, ou null pour lire.
 *
 * @return array<string, mixed>
 */
function etat_envoi( ?array $nouveau = null ): array {
	static $etat = [];

	if ( null !== $nouveau ) {
		$etat = $nouveau;
	}

	return $etat;
}

/**
 * URL de la page courante, reconstruite depuis l'origine du site.
 *
 * Le chemin vient de la requête, mais le protocole et l'hôte viennent de
 * `home_url()` : un `Host` forgé ne peut donc pas transformer la redirection
 * qui suit l'envoi en redirection ouverte vers un site tiers.
 */
function url_courante(): string {
	$origine = (array) wp_parse_url( home_url() );
	$chemin  = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
	$port    = isset( $origine['port'] ) ? ':' . $origine['port'] : '';

	return sprintf(
		'%s://%s%s%s',
		$origine['scheme'] ?? 'https',
		$origine['host'] ?? '',
		$port,
		'' === $chemin ? '/' : $chemin
	);
}

/**
 * Accusé de réception désigné par l'URL courante, s'il est encore valide.
 *
 * @return array<string, mixed>
 */
function recu_courant(): array {
	$brut = isset( $_GET[ PARAM_RECU ] ) ? (string) wp_unslash( $_GET[ PARAM_RECU ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	// Le jeton est de l'hexadécimal : tout le reste est écarté avant d'aller
	// composer une clé de transient.
	$jeton = (string) preg_replace( '/[^a-f0-9]/', '', $brut );

	if ( '' === $jeton ) {
		return [];
	}

	$recu = get_transient( 'anode_form_recu_' . $jeton );

	return is_array( $recu ) ? $recu : [];
}

/**
 * Reçoit un formulaire posté classiquement.
 *
 * Rend l'URL vers laquelle rediriger, ou une chaîne vide quand la page doit se
 * rendre telle quelle — refus, jeton périmé, ou POST qui ne nous concerne pas.
 * La redirection elle-même appartient à l'appelant : c'est ce qui rend cette
 * fonction éprouvable sans serveur.
 */
function intercepter_envoi(): string {
	if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
		return '';
	}

	$name = isset( $_POST['anode_form'] ) ? sanitize_key( (string) wp_unslash( $_POST['anode_form'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( '' === $name ) {
		/*
		 * Corps tronqué : au-delà de `post_max_size`, PHP vide `$_POST` **et**
		 * `$_FILES` sans rien dire. Le nom du formulaire part avec le reste : on
		 * ne peut donc pas viser celui qui a servi, mais se taire ferait croire
		 * au visiteur que rien n'a été envoyé — et il recommencerait à
		 * l'identique, indéfiniment.
		 */
		if ( ! $_POST && (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 0 ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			etat_envoi(
				[
					'tous' => true,
					'avis' => 'Votre envoi dépasse ce que le serveur accepte. Réduisez la taille des pièces jointes, puis renvoyez le formulaire.',
				]
			);
		}

		return '';
	}

	$definition = definition( $name );

	if ( null === $definition ) {
		return '';
	}

	$faute = definition_error( $name, $definition );

	if ( $faute instanceof \WP_Error ) {
		etat_envoi( [ 'form' => $name, 'avis' => $faute->get_error_message() ] );

		return '';
	}

	$soumis = [];

	foreach ( (array) wp_unslash( $_POST ) as $cle => $valeur ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( (string) $cle, CHAMPS_TECHNIQUES, true ) ) {
			$soumis[ (string) $cle ] = $valeur;
		}
	}

	$recus = (array) ( $_FILES ?? [] );

	/*
	 * Le rythme est contrôlé **avant** le nonce, et non après.
	 *
	 * Un jeton périmé fait re-rendre la page ; si le contrôle venait ensuite, un
	 * automate postant n'importe quel jeton ferait tourner cette page autant de
	 * fois qu'il veut sans jamais rencontrer de limite.
	 */
	$limite = check_rate_limit();

	if ( $limite instanceof \WP_Error ) {
		etat_envoi( [ 'form' => $name, 'valeurs' => $soumis, 'avis' => $limite->get_error_message() ] );

		return '';
	}

	$nonce = isset( $_POST['_anode_nonce'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['_anode_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( ! wp_verify_nonce( $nonce, action_nonce( $name ) ) ) {
		/*
		 * Jeton absent ou périmé : la demande **n'est pas jetée**.
		 *
		 * La jeter serait reproduire, en pire, le défaut qu'on corrige — une
		 * demande perdue sans que personne ne le sache. La page repart avec les
		 * réponses en place et un nonce frais ; la personne renvoie d'un clic, et
		 * cette seconde requête a forcément un jeton valide, aucun cache ne
		 * servant ni ne stockant la réponse d'un POST.
		 */
		etat_envoi(
			[
				'form'    => $name,
				'valeurs' => $soumis,
				'avis'    => 'Votre session a expiré avant l’envoi. Vos réponses sont conservées : renvoyez le formulaire.',
			]
		);

		return '';
	}

	/*
	 * Ici `$_POST` est la bonne source : un envoi sans JavaScript est un POST
	 * de formulaire classique. Le contexte est passé explicitement quand même,
	 * pour que `relayer()` n'ait qu'une seule façon de l'obtenir.
	 */
	$resultat = traiter(
		$name,
		$definition,
		$soumis,
		(string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ),
		$recus,
		[
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			'tracking'  => $_POST['tracking'] ?? null,
			'page_url'  => $_POST['page_url'] ?? null,
			'lead_type' => $_POST['lead_type'] ?? null,
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		]
	);

	if ( $resultat instanceof \WP_Error ) {
		$donnees = $resultat->get_error_data();

		etat_envoi(
			[
				'form'     => $name,
				'valeurs'  => $soumis,
				'erreurs'  => (array) ( $donnees['fields'] ?? [] ),
				'avis'     => $resultat->get_error_message(),
			]
		);

		return '';
	}

	/*
	 * L'accusé de réception voyage sur une **URL unique**, pas sur un paramètre
	 * fixe du genre « ?envoi=ok » : un cache de page ne peut pas en avoir de
	 * copie, et la confirmation s'affiche donc toujours. Avec un paramètre fixe,
	 * une page servie depuis le cache remontrerait le formulaire vide, et le
	 * visiteur renverrait sa demande en croyant qu'elle n'est pas partie.
	 *
	 * Un piège rempli suit exactement le même chemin : un robot ne doit pas
	 * pouvoir distinguer le refus de l'acceptation.
	 */
	$jeton = bin2hex( random_bytes( 10 ) );

	set_transient(
		'anode_form_recu_' . $jeton,
		[
			'form'    => $name,
			'message' => (string) ( $resultat['message'] ?? ( $definition['messages']['success'] ?? 'Votre demande a bien été envoyée.' ) ),
		],
		DUREE_RECU
	);

	// `redirect` est honoré comme le fait le moteur, mais passé au tamis : une
	// définition qui pointerait un domaine tiers ne doit pas transformer le
	// formulaire en tremplin.
	$cible = empty( $definition['redirect'] )
		? url_courante()
		: wp_validate_redirect( (string) $definition['redirect'], url_courante() );

	return add_query_arg( PARAM_RECU, $jeton, $cible );
}

add_action(
	'template_redirect',
	static function (): void {
		$cible = intercepter_envoi();

		if ( '' === $cible ) {
			return;
		}

		// POST-redirect-GET : sans la redirection, un rafraîchissement de page
		// renverrait la demande, et le site recevrait deux fois la même.
		wp_safe_redirect( $cible, 303 );

		exit;
	},
	5
);

/* ------------------------------------------------------------------ */
/* Rendu du repli                                                      */
/* ------------------------------------------------------------------ */

/**
 * Suite d'attributs HTML.
 *
 * `true` rend un attribut booléen (`required`), `null` et `false` n'écrivent
 * rien du tout — c'est ce qui permet d'énumérer les attributs d'un champ sans
 * une condition par ligne.
 *
 * @param array<string, mixed> $attributs
 */
function attributs_html( array $attributs ): string {
	$sortie = '';

	foreach ( $attributs as $nom => $valeur ) {
		if ( null === $valeur || false === $valeur || '' === $valeur ) {
			continue;
		}

		$sortie .= true === $valeur
			? ' ' . $nom
			: sprintf( ' %s="%s"', $nom, esc_attr( (string) $valeur ) );
	}

	return $sortie;
}

/**
 * Une option de liste, ramenée à son couple valeur / intitulé.
 *
 * @param mixed $option
 *
 * @return array{0: string, 1: string}
 */
function option_html( mixed $option ): array {
	if ( is_array( $option ) ) {
		$valeur = (string) ( $option['value'] ?? '' );

		return [ $valeur, (string) ( $option['label'] ?? $valeur ) ];
	}

	return [ (string) $option, (string) $option ];
}

/**
 * Valeur de l'attribut `accept` d'un champ de fichier.
 *
 * Le navigateur filtre alors le sélecteur de fichiers, au lieu de laisser
 * choisir puis envoyer un document que le serveur refusera. La liste reste celle
 * de la définition : c'est le serveur qui tranche, l'attribut ne fait qu'éviter
 * un aller-retour.
 *
 * @param array<string, mixed> $champ Définition du champ.
 */
function accept_html( array $champ ): string {
	/*
	 * La liste **intersectée**, celle que le serveur appliquera — pas la
	 * déclaration brute. Les servir différentes selon la voie de rendu romprait
	 * le principe fondateur du composant : navigateur et serveur lisent la même
	 * définition, donc ne peuvent pas se contredire.
	 */
	$extensions = array_filter( array_map( 'strval', limites_fichier( $champ )['accept'] ) );

	return implode( ',', array_map( static fn ( string $ext ): string => '.' . ltrim( $ext, '.' ), $extensions ) );
}

/**
 * Un champ du formulaire de repli.
 *
 * Le balisage reprend exactement celui de `form-engine.js` — mêmes classes,
 * mêmes identifiants, mêmes liaisons d'accessibilité. Une seule feuille de style
 * habille donc les deux, et le passage de l'un à l'autre ne se voit pas.
 *
 * @param array<string, mixed> $champ      Définition du champ.
 * @param array<string, mixed> $definition Définition du formulaire.
 * @param mixed                $valeur     Réponse à remettre en place.
 */
function champ_html( array $champ, string $form_id, mixed $valeur, string $erreur, array $definition ): string {
	$nom  = (string) $champ['name'];
	$type = (string) ( $champ['type'] ?? 'text' );
	$id   = $form_id . '-' . $nom;
	$val  = is_scalar( $valeur ) ? (string) $valeur : '';
	$aide = (string) ( $champ['help'] ?? '' );

	$decrit = trim( ( '' !== $aide ? $id . '-help ' : '' ) . $id . '-error' );

	/*
	 * Un champ conditionnel ne porte pas l'attribut `required` du navigateur.
	 *
	 * Sans JavaScript, tous les champs sont affichés, y compris ceux qu'une
	 * réponse précédente devrait masquer. Le navigateur, lui, ne connaît pas la
	 * condition : il refuserait d'envoyer le formulaire tant qu'une question sans
	 * objet n'est pas remplie, et il n'y aurait aucun moyen d'en sortir.
	 * L'obligation reste appliquée par le serveur, qui sait, lui, si le champ
	 * était réellement posé.
	 */
	/*
	 * Deux notions distinctes, qu'on confondait.
	 *
	 * `required` **au sens du navigateur** ne peut pas être posé sur un champ
	 * conditionnel du repli : sans JavaScript, le champ est toujours affiché, et
	 * le navigateur refuserait l'envoi tant qu'une question sans objet n'est pas
	 * remplie — sans aucun moyen d'en sortir.
	 *
	 * Mais **l'intitulé** doit dire la vérité. Étiqueter « (optionnel) » un champ
	 * que le serveur exigera dès que sa condition sera remplie trompe le visiteur
	 * et retire `aria-required` à un lecteur d'écran. L'obligation reste réelle :
	 * elle est simplement conditionnelle.
	 */
	$conditionnel = isset( $champ['showIf'] );
	$exige        = ! empty( $champ['required'] );
	$obligatoire  = $exige && ! $conditionnel;

	$commun = [
		'id'               => $id,
		'name'             => $nom,
		'class'            => 'c-form__control',
		'aria-describedby' => $decrit,
		'aria-required'    => $obligatoire ? 'true' : null,
		'aria-invalid'     => '' !== $erreur ? 'true' : null,
		'required'         => $obligatoire,
		'autocomplete'     => $champ['autocomplete'] ?? null,
	];

	if ( 'textarea' === $type ) {
		$controle = sprintf(
			'<textarea%s>%s</textarea>',
			attributs_html(
				array_merge(
					$commun,
					[
						'rows'        => $champ['rows'] ?? null,
						'placeholder' => $champ['placeholder'] ?? null,
						'maxlength'   => $champ['maxLength'] ?? MAX_LENGTH,
						'minlength'   => $champ['minLength'] ?? null,
					]
				)
			),
			esc_html( $val )
		);
	} elseif ( 'select' === $type ) {
		$options = sprintf(
			'<option value="">%s</option>',
			esc_html( (string) ( $champ['placeholder'] ?? 'Choisissez…' ) )
		);

		foreach ( (array) ( $champ['options'] ?? [] ) as $option ) {
			[ $ov, $ol ] = option_html( $option );

			$options .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $ov ),
				$ov === $val && '' !== $ov ? ' selected' : '',
				esc_html( $ol )
			);
		}

		$controle = sprintf( '<select%s>%s</select>', attributs_html( $commun ), $options );
	} elseif ( 'checkbox' === $type ) {
		$controle = sprintf(
			'<input%s>',
			attributs_html( array_merge( $commun, [ 'type' => 'checkbox', 'value' => '1', 'checked' => '' !== $val ] ) )
		);
	} elseif ( 'file' === $type ) {
		/*
		 * Une pièce jointe voyage sous `fichier_<nom>`, jamais sous son seul nom.
		 *
		 * `traiter()` va la chercher là, et refuse tout fichier posté ailleurs :
		 * un `name` réduit au nom du champ ferait rejeter l'envoi entier avec un
		 * « pièce jointe inattendue » que rien, dans la page, n'expliquerait.
		 */
		$controle = sprintf(
			'<input%s>',
			attributs_html(
				array_merge(
					$commun,
					[
						'name'   => PREFIXE_FICHIER . $nom,
						'type'   => 'file',
						'accept' => accept_html( $champ ),
					]
				)
			)
		);
	} elseif ( 'radio' === $type ) {
		$boutons = '';

		foreach ( array_values( (array) ( $champ['options'] ?? [] ) ) as $rang => $option ) {
			[ $ov, $ol ] = option_html( $option );

			/*
			 * Même balisage que celui du moteur : le repli doit produire des
			 * champs identiques, sinon la feuille de style ne les habille qu'à
			 * moitié — et le formulaire servi sans JavaScript ressemble à autre
			 * chose que celui que tout le monde voit.
			 *
			 * L'étiquette entoure le bouton : l'association est implicite, et le
			 * libellé devient cliquable sans qu'aucun identifiant ait à
			 * concorder. Le bouton natif est masqué par le CSS, jamais retiré :
			 * il garde le clavier, les flèches et le regroupement par `name`.
			 */
			$boutons .= sprintf(
				'<label class="c-form__choice c-form__choice--radio"><span class="c-form__choice-text">%s</span><input%s><span class="c-form__choice-mark" aria-hidden="true"></span></label>',
				esc_html( $ol ),
				attributs_html(
					[
						'class'    => 'c-form__choice-input',
						'type'     => 'radio',
						'id'       => $id . '-' . $rang,
						'name'     => $nom,
						'value'    => $ov,
						'checked'  => $ov === $val && '' !== $ov,
						'required' => $obligatoire,
					]
				)
			);
		}

		// Un groupe de boutons n'est pas une zone de saisie : `c-form__control`
		// lui dessinerait une boîte autour. Et sans `radiogroup`, un lecteur
		// d'écran annonce « 1 sur 3 » sans jamais dire de quoi.
		$controle = sprintf(
			'<div class="c-form__choices" role="radiogroup" aria-labelledby="%s-label"%s>%s</div>',
			esc_attr( $id ),
			attributs_html(
				[
					'aria-describedby' => $decrit,
					'aria-required'    => $obligatoire ? 'true' : null,
					'aria-invalid'     => '' !== $erreur ? 'true' : null,
				]
			),
			$boutons
		);
	} else {
		$controle = sprintf(
			'<input%s>',
			attributs_html(
				array_merge(
					$commun,
					[
						'type'        => $type,
						'value'       => $val,
						'placeholder' => $champ['placeholder'] ?? null,
						'maxlength'   => $champ['maxLength'] ?? ( is_free_text( $type ) ? MAX_LENGTH : null ),
						'minlength'   => $champ['minLength'] ?? null,
						'pattern'     => $champ['pattern'] ?? null,
						'min'         => $champ['min'] ?? null,
						'max'         => $champ['max'] ?? null,
					]
				)
			)
		);
	}

	/*
	 * Convention de marquage : on signale les champs *facultatifs*, jamais les
	 * obligatoires — une mention au lieu d'une forêt d'astérisques. Les lecteurs
	 * d'écran, eux, s'appuient sur `aria-required`.
	 */
	$mention    = $definition['optionalLabel'] ?? '(optionnel)';
	$facultatif = ! $obligatoire && $mention && ! in_array( $type, [ 'checkbox', 'hidden' ], true );

	// Un groupe de boutons n'a pas de contrôle unique à étiqueter : `<label for>`
	// pointerait vers un identifiant qui n'existe pas, et le groupe se
	// retrouverait sans nom.
	$groupe = 'radio' === $type;

	$label = sprintf(
		'<%1$s%2$s class="c-form__label">%3$s%4$s</%1$s>',
		$groupe ? 'span' : 'label',
		$groupe ? sprintf( ' id="%s-label"', esc_attr( $id ) ) : sprintf( ' for="%s"', esc_attr( $id ) ),
		esc_html( (string) ( $champ['label'] ?? $nom ) ),
		$facultatif ? sprintf( '<span class="c-form__optional"> %s</span>', esc_html( (string) $mention ) ) : ''
	);

	// Une case à cocher se lit « case puis intitulé » ; les autres champs portent
	// leur étiquette au-dessus.
	$bloc = 'checkbox' === $type ? $controle . $label : $label . $controle;

	if ( '' !== $aide ) {
		// L'aide se rend en texte, jamais en HTML : une définition n'a pas à
		// pouvoir injecter du balisage. Un lien passe par `helpLink` — c'est ce
		// qui permet de pointer la politique de confidentialité sous une case de
		// consentement sans ouvrir la porte au reste.
		$lien = '';

		if ( ! empty( $champ['helpLink']['url'] ) && ! empty( $champ['helpLink']['text'] ) ) {
			$lien = sprintf(
				' <a class="c-form__help-link" href="%s">%s</a>',
				esc_url( (string) $champ['helpLink']['url'] ),
				esc_html( (string) $champ['helpLink']['text'] )
			);
		}

		$bloc .= sprintf( '<p id="%s-help" class="c-form__help">%s%s</p>', esc_attr( $id ), esc_html( $aide ), $lien );
	}

	// Le conteneur d'erreur existe toujours, vide et masqué : c'est ce que
	// désigne `aria-describedby`, et c'est ce qui permet à un lecteur d'écran
	// d'annoncer le message dès qu'il apparaît.
	$bloc .= sprintf(
		'<p id="%s-error" class="c-form__error" role="alert"%s>%s</p>',
		esc_attr( $id ),
		'' === $erreur ? ' hidden' : '',
		esc_html( $erreur )
	);

	return sprintf(
		'<div class="c-form__field c-form__field--%s%s%s" data-field="%s">%s</div>',
		esc_attr( $type ),
		empty( $champ['full'] ) ? '' : ' c-form__field--full',
		'' === $erreur ? '' : ' is-invalid',
		esc_attr( $nom ),
		$bloc
	);
}

/**
 * Bloc d'actions du repli : un seul bouton, puisqu'il n'y a qu'un écran.
 *
 * @param array<string, mixed> $definition Définition du formulaire.
 */
function actions_html( array $definition ): string {
	$bouton = sprintf(
		'<button type="submit" class="%s c-form__submit">%s%s</button>',
		esc_attr( (string) ( $definition['submitClass'] ?? 'c-button c-button--primary' ) ),
		esc_html( (string) ( $definition['labels']['submit'] ?? 'Envoyer' ) ),
		// L'icône vient du fichier de définition, versionné avec le site : ce
		// n'est pas une saisie utilisateur. Le moteur l'insère de la même façon.
		(string) ( $definition['submitIcon'] ?? '' )
	);

	$note = empty( $definition['note'] )
		? ''
		: sprintf( '<p class="c-form__note">%s</p>', esc_html( (string) $definition['note'] ) );

	return sprintf( '<div class="c-form__actions">%s%s</div>', $bouton, $note );
}

/**
 * Champ-piège du repli.
 *
 * Hors écran plutôt que `display:none`, que certains robots savent détecter.
 * Retiré du parcours clavier et de l'arbre d'accessibilité, il n'existe que pour
 * un automate qui remplit tout ce qu'il trouve.
 *
 * @param array<string, mixed> $definition Définition du formulaire.
 */
function piege_html( string $form_id, array $definition ): string {
	$nom = (string) ( $definition['honeypot'] ?? HONEYPOT );

	return sprintf(
		'<div class="c-form__trap" aria-hidden="true">'
			. '<label for="%1$s-%2$s">Ne remplissez pas ce champ</label>'
			. '<input type="text" id="%1$s-%2$s" name="%2$s" tabindex="-1" autocomplete="off" value="">'
			. '</div>',
		esc_attr( $form_id ),
		esc_attr( $nom )
	);
}

/**
 * Formulaire de repli complet, ou l'accusé de réception qui lui succède.
 *
 * Rend une chaîne vide si le formulaire n'existe pas : mieux vaut un bloc vide
 * qu'un message d'erreur adressé au visiteur pour une faute d'intégration.
 */
function repli_html( string $name ): string {
	$definition = definition( $name );

	if ( null === $definition ) {
		return '';
	}

	interdire_le_cache();

	$recu = recu_courant();

	if ( ( $recu['form'] ?? '' ) === $name ) {
		/*
		 * `data-anode-recu` fait sortir le moteur sans rien construire : sans ce
		 * repère, il rebâtirait le formulaire par-dessus la confirmation, et la
		 * personne verrait un formulaire vide au lieu d'un « merci ».
		 */
		return sprintf(
			'<p class="c-form__success" role="status" tabindex="-1" autofocus data-anode-recu>%s</p>',
			esc_html( (string) ( $recu['message'] ?? 'Votre demande a bien été envoyée.' ) )
		);
	}

	$definition = with_default_max_length( $definition );
	$etat       = etat_envoi();
	// `tous` : un envoi tronqué ne dit plus de quel formulaire il venait.
	$courant = ( $etat['form'] ?? '' ) === $name || ! empty( $etat['tous'] );
	$erreurs = $courant ? (array) ( $etat['erreurs'] ?? [] ) : [];
	$valeurs = $courant ? (array) ( $etat['valeurs'] ?? [] ) : [];
	$avis    = $courant ? (string) ( $etat['avis'] ?? '' ) : '';

	$corps = '';

	if ( '' !== $avis ) {
		// `autofocus` remplace le déplacement de focus que ferait le moteur :
		// sans JavaScript, c'est le seul moyen d'amener la personne au message
		// plutôt que de la laisser en haut d'une page qui a l'air inchangée.
		$corps .= sprintf(
			'<p class="c-form__status is-error" role="alert" tabindex="-1" autofocus>%s</p>',
			esc_html( $avis )
		);
	}

	/*
	 * Tous les écrans sont dépliés dans un seul formulaire.
	 *
	 * Un parcours en plusieurs étapes suppose de retenir les réponses entre deux
	 * pages — donc une session, donc un état serveur, pour un visiteur sur mille.
	 * Les titres d'écran restent : ils structurent la page, et c'est le seul rôle
	 * qu'ils tenaient déjà.
	 */
	$ecrans  = array_values( (array) ( $definition['screens'] ?? [ [ 'fields' => $definition['fields'] ?? [] ] ] ) );
	$dernier = count( $ecrans ) - 1;

	foreach ( $ecrans as $rang => $ecran ) {
		if ( ! empty( $ecran['title'] ) ) {
			$corps .= sprintf( '<p class="c-form__screen-title">%s</p>', esc_html( (string) $ecran['title'] ) );
		}

		$grille = '';

		foreach ( (array) ( $ecran['fields'] ?? [] ) as $champ ) {
			if ( ! is_array( $champ ) || empty( $champ['name'] ) ) {
				continue;
			}

			$nom     = (string) $champ['name'];
			$grille .= champ_html( $champ, $name, $valeurs[ $nom ] ?? null, (string) ( $erreurs[ $nom ] ?? '' ), $definition );
		}

		if ( $rang === $dernier ) {
			$grille .= actions_html( $definition ) . piege_html( $name, $definition );
		}

		$corps .= sprintf( '<div class="c-form__grid">%s</div>', $grille );
	}

	/*
	 * `enctype` n'est posé que s'il sert. Sans lui, un champ de fichier n'envoie
	 * que le **nom** du document : `$_FILES` reste vide, et le formulaire refuse
	 * une pièce jointe que le visiteur a pourtant bien choisie. Avec lui partout,
	 * un formulaire sans fichier paie un encodage trois fois plus lourd.
	 */
	$fichiers = false;

	foreach ( fields_of( $definition ) as $champ ) {
		$fichiers = $fichiers || 'file' === ( $champ['type'] ?? '' );
	}

	return sprintf(
		'<form class="c-form__form" method="post" action="%s"%s data-anode-fallback>'
			. '<div class="c-form__body">%s</div>'
			. '<input type="hidden" name="anode_form" value="%s">'
			. '%s'
			. '</form>',
		esc_url( url_courante() ),
		$fichiers ? ' enctype="multipart/form-data"' : '',
		$corps,
		esc_attr( $name ),
		/*
		 * Le champ est écrit à la main, et non par `wp_nonce_field`.
		 *
		 * Celle-ci pose toujours `id` = `name`. Une page qui porte deux
		 * formulaires — une page de contact en a souvent un court et un long —
		 * servait donc deux fois `id="_anode_nonce"`, et trois avec le repli
		 * sans JavaScript. Un identifiant répété est invalide, et un lecteur
		 * d'écran comme un script ne trouvent que le premier.
		 *
		 * Un champ caché n'a aucun besoin d'identifiant : on ne le pose pas.
		 */
		sprintf(
			'<input type="hidden" name="_anode_nonce" value="%s">',
			esc_attr( wp_create_nonce( action_nonce( $name ) ) )
		)
	);
}

/**
 * Déclare non cacheable la page qui porte un formulaire.
 *
 * Le nonce et le cache de page — la réponse, et pourquoi c'est celle-là.
 *
 * Un nonce WordPress vit de douze à vingt-quatre heures. Servi depuis une page
 * mise en cache plus vieille que cela, il est mort : *toutes* les demandes du
 * site échouent, pour tout le monde, et rien ne le signale. Le cache de page a
 * été retiré du blueprint, mais l'hébergeur en a un, et un cache de bord
 * (Cloudflare, Varnish) n'est de toute façon pas joignable depuis PHP.
 *
 * Trois mesures, et c'est la troisième qui fait le travail :
 *
 * 1. La page se déclare non cacheable. `DONOTCACHEPAGE` est lu par toutes les
 *    extensions de cache, `nocache_headers()` par les caches de bord qui
 *    respectent `Cache-Control`. Une page de contact n'est pas une page chaude :
 *    le coût est nul, et il ne porte que sur les pages qui contiennent
 *    réellement un formulaire.
 * 2. L'accusé de réception voyage sur une URL unique — voir `intercepter_envoi()`.
 * 3. Un jeton périmé ne jette jamais la demande : la page repart avec les
 *    réponses et un nonce frais. C'est ce qui rend le point 1 non critique — un
 *    cache qui passerait outre coûte un clic, pas un message.
 *
 * L'en-tête n'est posé que si rien n'est encore parti : avec Bricks, le contenu
 * se rend après le `<head>`, et l'appel arriverait trop tard. Le point 3 couvre
 * ce cas, comme il couvre les caches de bord.
 */
function interdire_le_cache(): void {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}

	if ( ! headers_sent() ) {
		nocache_headers();
	}
}

/**
 * Remplit les blocs `data-form` vides du HTML rendu.
 *
 * Le repli s'injecte dans le balisage plutôt que de s'écrire dans la page : le
 * bloc est posé par Bricks, et la définition qui le remplit n'existe qu'ici.
 * Seul un conteneur **vide** est rempli — un bloc auquel un site aurait ajouté
 * du contenu est laissé tel quel, et une deuxième passe ne peut donc pas
 * doubler le formulaire.
 */
function injecter_replis( string $html ): string {
	if ( is_admin() || ! str_contains( $html, 'data-form' ) ) {
		return $html;
	}

	// Dans le builder, la page sert de canevas d'édition : y injecter un
	// formulaire ferait apparaître un élément que l'arbre ne contient pas.
	if ( function_exists( 'bricks_is_builder' ) && \bricks_is_builder() ) {
		return $html;
	}

	return (string) preg_replace_callback(
		'#(<([a-z][a-z0-9]*)\b[^>]*\sdata-form\s*=\s*(["\'])([a-z0-9_-]+)\3[^>]*>)(\s*)(</\2>)#i',
		static function ( array $trouve ): string {
			$repli = repli_html( $trouve[4] );

			return '' === $repli ? $trouve[0] : $trouve[1] . $repli . $trouve[6];
		},
		$html
	);
}

// Bricks rend son contenu hors de `the_content` : sans ce filtre, le repli
// n'atteindrait aucune page construite avec le builder, c'est-à-dire toutes.
add_filter(
	'bricks/frontend/render_data',
	static function ( $contenu, $post = null, $area = '' ) {
		return is_string( $contenu ) ? injecter_replis( $contenu ) : $contenu;
	},
	20,
	3
);

// Et pour un gabarit qui n'est pas de Bricks — page d'exemple, article rendu par
// le thème parent.
add_filter( 'the_content', __NAMESPACE__ . '\\injecter_replis', 20 );

/* ------------------------------------------------------------------ */
/* Validation                                                          */
/* ------------------------------------------------------------------ */

/**
 * Assainit une valeur selon le type déclaré.
 *
 * Les mêmes contraintes que côté navigateur, revérifiées ici : une validation
 * côté client n'est qu'un confort d'usage, elle ne protège de rien.
 *
 * @param array<string, mixed> $field Définition du champ.
 */
/**
 * @return string|list<string>|\WP_Error Une liste seulement pour un `select`
 *                                       déclaré à choix multiple.
 */
function sanitize_field( mixed $value, array $field ): string|array|\WP_Error {
	$label = $field['label'] ?? ( $field['name'] ?? 'Champ' );
	$type  = $field['type'] ?? 'text';

	/*
	 * Une pièce jointe n'a rien à faire ici : `traiter()` la route vers
	 * `receptionner_fichier()`. Le garde n'est pas décoratif — sans lui, une
	 * réécriture qui ferait retomber les champs de fichier dans cette boucle
	 * enregistrerait comme réponse le nom de fichier fourni par le client, et
	 * l'unique contrôle de sécurité du téléversement serait contourné en silence.
	 */
	if ( 'file' === $type ) {
		return new \WP_Error( 'invalid', sprintf( '%s : un fichier est attendu.', $label ) );
	}

	if ( is_bool( $value ) ) {
		$value = $value ? '1' : '';
	}

	/*
	 * Un tableau n'est légitime que pour un `select` à choix multiple, et
	 * seulement si la définition le déclare. Le vérifier ici plutôt que plus
	 * bas ferme la porte à un envoi forgé qui passerait un tableau sur un champ
	 * simple — la validation d'après le comparerait à chaque option et le
	 * refuserait, mais un tableau imbriqué aurait déjà traversé les `trim` et
	 * `strlen` qui suivent, avec des avertissements PHP dans le journal.
	 */
	/*
	 * Une case à cocher À OPTIONS est multi-choix par nature : le navigateur en
	 * envoie un tableau, et la validation qui suit sait déjà le parcourir et
	 * contrôler chaque valeur contre les options déclarées.
	 *
	 * Ne reconnaître que le `select multiple` refusait donc tout groupe de cases,
	 * sur « … : valeur inattendue » — un message qui accuse la VALEUR alors que
	 * c'est sa FORME qui n'était pas prévue, et que rien dans la page n'explique.
	 *
	 * Relevé le 18/09/2026. Le défaut dort tant qu'aucune définition ne déclare de
	 * cases à options : il ne se révèle qu'au premier formulaire qui en pose un.
	 */
	$multiple = ( 'select' === $type && ! empty( $field['multiple'] ) )
		|| ( 'checkbox' === $type && ! empty( $field['options'] ) );

	if ( is_object( $value ) || ( is_array( $value ) && ! $multiple ) ) {
		return new \WP_Error( 'invalid', sprintf( '%s : valeur inattendue.', $label ) );
	}

	if ( $multiple ) {
		// Une liste de listes n'est pas une réponse : on refuse plutôt que
		// d'aplatir, qui inventerait des valeurs que personne n'a choisies.
		foreach ( (array) $value as $element ) {
			if ( ! is_scalar( $element ) ) {
				return new \WP_Error( 'invalid', sprintf( '%s : valeur inattendue.', $label ) );
			}
		}

		$recues = array_values(
			array_filter(
				array_map( static fn ( $e ): string => trim( (string) $e ), (array) $value ),
				'strlen'
			)
		);

		if ( ! $recues ) {
			return ! empty( $field['required'] )
				? new \WP_Error( 'required', sprintf( '« %s » est obligatoire.', $label ) )
				: [];
		}

		$options = array_map(
			static fn ( $option ) => is_array( $option ) ? (string) ( $option['value'] ?? '' ) : (string) $option,
			$field['options'] ?? []
		);

		foreach ( $recues as $recue ) {
			if ( ! in_array( $recue, $options, true ) ) {
				return new \WP_Error( 'invalid', sprintf( '%s : valeur hors liste.', $label ) );
			}
		}

		// Les doublons d'un envoi forgé n'apportent rien et fausseraient un
		// décompte en aval.
		return array_values( array_unique( $recues ) );
	}

	$value = trim( (string) $value );

	if ( '' === $value ) {
		return ! empty( $field['required'] )
			? new \WP_Error( 'required', sprintf( '« %s » est obligatoire.', $label ) )
			: '';
	}

	/*
	 * `minLength` et `pattern` n'étaient vérifiés que par le navigateur : un
	 * envoi direct à l'API — un robot, une requête rejouée — les traversait sans
	 * rien rencontrer, alors que la définition les annonce comme des contraintes.
	 *
	 * Contrôlés ici avant le type, sur la valeur telle qu'elle a été saisie,
	 * comme le fait `validate()` côté client : les deux ne peuvent pas diverger.
	 */
	$min = (int) ( $field['minLength'] ?? 0 );

	if ( $min > 0 && mb_strlen( $value ) < $min ) {
		return new \WP_Error( 'invalid', sprintf( '%s : %d caractères au minimum.', $label, $min ) );
	}

	if ( ! empty( $field['pattern'] ) && ! matches_pattern( (string) $field['pattern'], $value ) ) {
		return new \WP_Error(
			'invalid',
			(string) ( $field['patternMessage'] ?? sprintf( '%s : le format attendu n’est pas respecté.', $label ) )
		);
	}

	switch ( $type ) {
		case 'email':
			$clean = sanitize_email( $value );

			if ( ! is_email( $clean ) ) {
				return new \WP_Error( 'invalid', 'Indiquez une adresse e‑mail valide.' );
			}

			return $clean;

		case 'url':
			/*
			 * `wp_http_validate_url` résout le nom de domaine : il refuserait
			 * l'adresse d'une entreprise dont le DNS répond mal au moment de
			 * l'envoi. Elle n'est pas appelée par le serveur, seulement
			 * enregistrée — un contrôle de forme suffit.
			 */
			$clean = esc_url_raw( $value, [ 'http', 'https' ] );

			if ( ! $clean || ! wp_parse_url( $clean, PHP_URL_HOST ) ) {
				return new \WP_Error( 'invalid', 'Indiquez une adresse web valide, commençant par https://' );
			}

			return $clean;

		case 'tel':
			if ( ! preg_match( '/^[+()\d\s.-]{6,20}$/', $value ) ) {
				return new \WP_Error( 'invalid', 'Indiquez un numéro de téléphone valide.' );
			}

			return sanitize_text_field( $value );

		case 'select':
		case 'radio':
			$options = array_map(
				static fn ( $option ) => is_array( $option ) ? (string) ( $option['value'] ?? '' ) : (string) $option,
				$field['options'] ?? []
			);

			if ( ! in_array( $value, $options, true ) ) {
				return new \WP_Error( 'invalid', sprintf( '%s : valeur hors liste.', $label ) );
			}

			return $value;

		case 'checkbox':
			return '1';

		case 'date':
			// Le navigateur envoie AAAA-MM-JJ ; un envoi forgé, n'importe quoi.
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				return new \WP_Error( 'invalid', sprintf( '%s : date attendue au format AAAA-MM-JJ.', $label ) );
			}

			[ $annee, $mois, $jour ] = array_map( 'intval', explode( '-', $value ) );

			if ( ! checkdate( $mois, $jour, $annee ) ) {
				return new \WP_Error( 'invalid', sprintf( '%s : « %s » n’est pas une date valide.', $label, $value ) );
			}

			return $value;

		case 'number':
			if ( ! is_numeric( $value ) ) {
				return new \WP_Error( 'invalid', sprintf( '%s : un nombre est attendu.', $label ) );
			}

			$nombre = 0 + $value;

			if ( isset( $field['min'] ) && $nombre < $field['min'] ) {
				return new \WP_Error( 'invalid', sprintf( '%s : %s au minimum.', $label, $field['min'] ) );
			}

			if ( isset( $field['max'] ) && $nombre > $field['max'] ) {
				return new \WP_Error( 'invalid', sprintf( '%s : %s au maximum.', $label, $field['max'] ) );
			}

			return (string) $nombre;

		case 'textarea':
			$clean = sanitize_textarea_field( $value );
			break;

		default:
			$clean = sanitize_text_field( $value );
	}

	$max = (int) ( $field['maxLength'] ?? MAX_LENGTH );

	if ( mb_strlen( $clean ) > $max ) {
		return new \WP_Error( 'invalid', sprintf( '%s : %d caractères maximum.', $label, $max ) );
	}

	return $clean;
}

/**
 * La valeur respecte-t-elle le motif déclaré ?
 *
 * Le motif s'écrit comme l'attribut HTML `pattern` : sans délimiteur, et ancré
 * aux deux bouts. Sans les ancres, `\d{5}` accepterait « xx12345yy » — ce n'est
 * pas ce qu'attend qui écrit un code postal.
 *
 * Un motif fautif dans un fichier de définition ne doit pas refuser toutes les
 * demandes en silence : il est signalé au journal et la contrainte est ignorée,
 * le reste de la validation tenant.
 */
function matches_pattern( string $pattern, string $value ): bool {
	// Délimiteur non imprimable : un motif d'attribut HTML contient souvent
	// « / » (une date) ou « # » (une couleur), qui casseraient l'expression.
	$delimiteur = chr( 1 );
	$expression = $delimiteur . '^(?:' . $pattern . ')$' . $delimiteur . 'u';

	/*
	 * `preg_match` rend `false` pour **deux** raisons distinctes, et les
	 * confondre trompe le diagnostic :
	 *
	 * - le motif ne compile pas — c'est une faute dans la définition, à corriger ;
	 * - la **valeur reçue** n'est pas de l'UTF-8 valide, le drapeau `u` étant posé —
	 *   c'est une entrée forgée, pas une faute de notre côté.
	 *
	 * Le second cas est déclenchable à distance : journaliser « motif illisible »
	 * y écrivait une ligne accusant une définition parfaitement correcte, dix fois
	 * par heure et par adresse.
	 *
	 * On éprouve donc le motif **à vide** d'abord : s'il compile, la valeur est en
	 * cause, et elle est simplement refusée.
	 */
	$compile = @preg_match( $expression, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( false === $compile ) {
		error_log( sprintf( '[anode-forms] motif de champ illisible, contrainte ignorée : %s', $pattern ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		return true;
	}

	$resultat = @preg_match( $expression, $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	// Le motif compile : un échec ne peut venir que de la valeur. Elle est
	// refusée, sans laisser de trace qu'un visiteur pourrait provoquer à volonté.
	return 1 === $resultat;
}

function check_rate_limit(): bool|\WP_Error {
	/*
	 * L'adresse vient d'`anode-hardening` quand elle est là : elle seule sait si
	 * le site est servi derrière un proxy de confiance. Sans elle, toutes les
	 * demandes d'un site derrière Cloudflare ou un répartiteur portent l'adresse
	 * du proxy — le dixième visiteur de l'heure est alors refusé.
	 */
	/*
	 * `$_SERVER['REMOTE_ADDR']` n'est pas garanti être une chaîne — un
	 * transtypage sec y produirait « Array » sur une entrée forgée, et toutes les
	 * requêtes partageraient alors le même compteur. Le garde `is_string` est
	 * repris de la version d'origine : §7 de `CLAUDE.md` met en garde contre un
	 * filtre retiré au passage d'une réécriture.
	 */
	$brut = $_SERVER['REMOTE_ADDR'] ?? '';
	$ip   = function_exists( 'Anode\\Hardening\\client_ip' )
		? \Anode\Hardening\client_ip()
		: ( is_string( $brut ) ? $brut : '' );

	$key   = 'anode_form_' . md5( $ip );
	$count = (int) get_transient( $key );

	if ( $count >= RATE_LIMIT ) {
		return new \WP_Error(
			'anode_form_rate',
			'Trop d’envois depuis cette adresse. Réessayez dans une heure.',
			[ 'status' => 429 ]
		);
	}

	set_transient( $key, $count + 1, HOUR_IN_SECONDS );

	return true;
}

/* ------------------------------------------------------------------ */
/* Pièces jointes                                                      */
/* ------------------------------------------------------------------ */

/**
 * Le corps de la requête a-t-il été tronqué par PHP ?
 *
 * Au-delà de `post_max_size`, PHP n'émet pas d'erreur exploitable : il livre un
 * `$_POST` et un `$_FILES` **vides**. Sans ce contrôle, l'envoi ressemble trait
 * pour trait à un formulaire soumis à blanc.
 */
function corps_tronque( \WP_REST_Request $request ): bool {
	$type = strtolower( (string) $request->get_header( 'content-type' ) );

	// La voie JSON a légitimement un corps vide au sens de `$_POST` : le test ne
	// vaut que pour un envoi multipart, seul concerné par la limite.
	if ( ! str_contains( $type, 'multipart/form-data' ) ) {
		return false;
	}

	return ! $request->get_body_params() && ! $request->get_file_params();
}

/**
 * Limites effectivement appliquées à un champ de type `file`.
 *
 * Trois plafonds se rencontrent, et **c'est le plus bas qui vaut** : celui du
 * projet (`maxSize`), celui du composant (`TAILLE_MAX_MO`), et celui de PHP
 * (`upload_max_filesize` / `post_max_size`). Le dernier n'est pas négociable et
 * varie d'un hébergeur à l'autre : l'ignorer ferait accepter par le navigateur
 * un fichier que le serveur ne recevra jamais, et la personne l'apprendrait au
 * bout du téléversement.
 *
 * @param array<string, mixed> $field Définition du champ.
 *
 * @return array{accept: array<int, string>, maxSize: int}
 */
function limites_fichier( array $field ): array {
	$demande = array_map( 'strtolower', array_map( 'strval', (array) ( $field['accept'] ?? ACCEPT_DEFAUT ) ) );

	// Intersection, jamais union : `accept` restreint la liste blanche du
	// composant. Une définition qui réclamerait « php » n'obtient rien — et un
	// `accept` vide refuse tout, ce qui est le bon sens de l'échec.
	$accept  = array_values( array_intersect( $demande, array_keys( FICHIERS_AUTORISES ) ) );
	$refuses = array_diff( $demande, $accept );

	if ( $refuses ) {
		error_log( sprintf( '[anode-forms] extension(s) hors liste blanche sur le champ « %s », ignorée(s) : %s.', (string) ( $field['name'] ?? '?' ), implode( ', ', $refuses ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/*
	 * Le plafond se calcule **en octets**, et celui de PHP fait toujours foi.
	 *
	 * Le convertir en mégaoctets entiers d'abord le perdait sous 1 Mo :
	 * `floor( 512 Ko / 1 Mo )` vaut 0, la condition `> 0` écartait alors la seule
	 * limite réelle, et le formulaire annonçait 5 Mo là où PHP refusait à 512 Ko.
	 * Le `max( 1, … )` final relevait de même une limite basse à 1 Mo — annoncer
	 * plus que ce que le serveur accepte est exactement ce qu'on veut éviter.
	 */
	$demandee = (int) ( $field['maxSize'] ?? TAILLE_DEFAUT_MO );
	$octets   = min(
		( $demandee > 0 ? $demandee : TAILLE_DEFAUT_MO ) * MB_IN_BYTES,
		TAILLE_MAX_MO * MB_IN_BYTES,
		max( 1, (int) wp_max_upload_size() )
	);

	/*
	 * Un entier quand la limite tombe juste — « 5 Mo » se lit mieux que « 5.0 Mo ».
	 *
	 * La comparaison est **souple**, et c'est nécessaire : une division exacte
	 * rend un `int` en PHP, quand `floor()` rend toujours un `float`. Un `===`
	 * entre les deux vaut donc faux sur le seul cas qu'il devait attraper.
	 */
	$mo = $octets / MB_IN_BYTES;

	return [
		'accept'   => $accept,
		'maxSize'  => $mo == (int) $mo ? (int) $mo : round( $mo, 2 ), // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
		'maxBytes' => $octets,
	];
}

/**
 * Extension recevable d'un nom de fichier, ou la raison du refus.
 *
 * @param array<int, string> $accept Extensions admises pour ce champ.
 */
function extension_recevable( string $nom, array $accept ): string|\WP_Error {
	$morceaux  = explode( '.', strtolower( trim( $nom ) ) );
	$extension = (string) array_pop( $morceaux );

	if ( ! $morceaux || '' === $extension ) {
		return new \WP_Error( 'invalid', 'ce fichier n’a pas d’extension.' );
	}

	/*
	 * On regarde **toutes** les extensions du nom, pas seulement la dernière.
	 *
	 * `cv.php.pdf` finit par « .pdf » et passerait la liste blanche. Servi par un
	 * Apache qui choisit son gestionnaire d'après n'importe quelle extension du
	 * nom — ce que fait `AddHandler`, encore courant en mutualisé — le fichier
	 * serait exécuté. Le nôtre est renommé, donc hors d'atteinte ; mais un nom
	 * ainsi construit n'est pas une maladresse, et on ne l'accepte pas.
	 */
	foreach ( $morceaux as $morceau ) {
		if ( in_array( $morceau, EXTENSIONS_DANGEREUSES, true ) ) {
			return new \WP_Error( 'invalid', 'ce nom de fichier porte une seconde extension, exécutable.' );
		}
	}

	if ( ! in_array( $extension, $accept, true ) ) {
		return $accept
			? new \WP_Error( 'invalid', sprintf( 'formats acceptés — %s.', strtoupper( implode( ', ', $accept ) ) ) )
			: new \WP_Error( 'invalid', 'aucun format de fichier n’est accepté sur ce champ.' );
	}

	return $extension;
}

/**
 * Le contenu du fichier correspond-il à ce que son extension annonce ?
 *
 * `wp_check_filetype_and_ext()` fait déjà ce contrôle ; celui-ci le double, parce
 * qu'un site peut filtrer `upload_mimes` ou `wp_check_filetype_and_ext` et
 * relâcher la vérification sans savoir ce qu'il touche.
 */
function contenu_conforme( string $chemin, string $extension ): bool {
	// Sans l'extension fileinfo, aucune lecture du contenu n'est possible : le
	// contrôle de `wp_check_filetype_and_ext()` reste seul, et il en va de même
	// pour lui. Le refus général bloquerait tous les envois d'un serveur mal doté.
	if ( ! function_exists( 'finfo_open' ) ) {
		return true;
	}

	$info = finfo_open( FILEINFO_MIME_TYPE );

	if ( ! $info ) {
		return true;
	}

	$reel = (string) finfo_file( $info, $chemin );

	finfo_close( $info );

	$attendu = FICHIERS_AUTORISES[ $extension ] ?? '';

	if ( $reel === $attendu ) {
		return true;
	}

	/*
	 * Les conteneurs ne sont admis que pour la famille `application/`.
	 *
	 * Un `.docx` se présente à libmagic comme un zip, un `.doc` comme un
	 * conteneur OLE : l'égalité stricte refuserait un CV Word ici et l'accepterait
	 * là, selon la version installée. Une image ou un PDF, eux, doivent porter
	 * leur type exact — c'est ce qui refuse un `.jpg` qui est en réalité du PHP,
	 * que `finfo` annonce en `text/x-php`.
	 */
	return in_array( $reel, MIME_CONTENEURS, true ) && str_starts_with( $attendu, 'application/' );
}

/**
 * Range les pièces jointes hors des chemins devinables, et hors des médias.
 *
 * Deux gardes, parce qu'aucune ne suffit seule : un `.htaccess` qui refuse tout,
 * qu'Apache applique aussi aux sous-dossiers — et un nom de fichier tiré au sort,
 * pour les serveurs qui ne lisent pas `.htaccess` du tout (nginx, Caddy), où le
 * fichier reste servi par le serveur web. Une candidature déposée dans
 * `uploads/2026/07/cv-dupont.pdf` se devine ; `…/anode-formulaires/2026/07/
 * cv-8Kd2mZq1PfR7xTvA.pdf` non.
 *
 * @return string Chemin du dossier.
 */
function proteger_dossier(): string {
	$base = (string) ( wp_upload_dir()['basedir'] ?? '' ) . '/' . DOSSIER_FICHIERS;

	if ( ! is_dir( $base ) ) {
		wp_mkdir_p( $base );
	}

	$gardes = [
		'.htaccess' => "# Pièces jointes des formulaires : jamais servies par le serveur web.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
		'index.php' => "<?php\n// Rien à voir ici.\n",
		/*
		 * Un fichier témoin, avec une extension **autorisée**.
		 *
		 * Sonder le dossier avec `index.php` ne prouve rien : un serveur refuse
		 * volontiers un `.php` dans un dossier de téléversement tout en servant
		 * les `.pdf` qui s'y trouvent. Le témoin porte donc l'extension d'une
		 * vraie pièce jointe, et `wp_health` mesure ce que le serveur en fait.
		 */
		'temoin-anode.pdf' => "Fichier temoin d'anode-forms. Il ne contient rien.\n"
			. "S'il est accessible depuis le web, les pieces jointes le sont aussi.\n",
	];

	foreach ( $gardes as $nom => $contenu ) {
		$chemin = $base . '/' . $nom;

		if ( ! file_exists( $chemin ) ) {
			file_put_contents( $chemin, $contenu ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	return $base;
}


/**
 * Contrôle une pièce jointe, puis la range dans le dossier protégé.
 *
 * @param array<string, mixed> $envoi Entrée de `$_FILES` ; vide si rien n'est joint.
 * @param array<string, mixed> $field Définition du champ.
 *
 * @return array{chemin: string, type: string, nom: string, taille: int}|array{}|\WP_Error
 */
function receptionner_fichier( array $envoi, array $field ): array|\WP_Error {
	$label   = (string) ( $field['label'] ?? ( $field['name'] ?? 'Fichier' ) );
	$limites = limites_fichier( $field );
	$code    = (int) ( $envoi['error'] ?? UPLOAD_ERR_NO_FILE );

	if ( UPLOAD_ERR_NO_FILE === $code || empty( $envoi['tmp_name'] ) ) {
		return empty( $field['required'] )
			? []
			: new \WP_Error( 'required', sprintf( '« %s » est obligatoire.', $label ) );
	}

	if ( UPLOAD_ERR_INI_SIZE === $code || UPLOAD_ERR_FORM_SIZE === $code ) {
		return new \WP_Error( 'invalid', sprintf( '%s : %d Mo au maximum.', $label, $limites['maxSize'] ) );
	}

	if ( UPLOAD_ERR_OK !== $code ) {
		// Dossier temporaire absent, écriture refusée, envoi interrompu : le
		// visiteur n'y est pour rien, et le numéro ne lui apprendrait rien. Il a en
		// revanche toute sa place au journal, faute de quoi l'incident est invisible.
		error_log( sprintf( '[anode-forms] téléversement en échec (code %d) sur le champ « %s ».', $code, (string) ( $field['name'] ?? '?' ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		return new \WP_Error( 'invalid', sprintf( '%s : l’envoi du fichier a échoué. Réessayez.', $label ) );
	}

	$taille = (int) ( $envoi['size'] ?? 0 );

	if ( $taille <= 0 ) {
		return new \WP_Error( 'invalid', sprintf( '%s : le fichier est vide.', $label ) );
	}

	if ( $taille > $limites['maxSize'] * MB_IN_BYTES ) {
		return new \WP_Error(
			'invalid',
			sprintf( '%s : %d Mo au maximum, or ce fichier en fait %s.', $label, $limites['maxSize'], size_format( $taille ) )
		);
	}

	$nom       = (string) ( $envoi['name'] ?? '' );
	$extension = extension_recevable( $nom, $limites['accept'] );

	if ( $extension instanceof \WP_Error ) {
		return new \WP_Error( 'invalid', sprintf( '%s : %s', $label, $extension->get_error_message() ) );
	}

	// La liste des types remise à WordPress est celle du champ, pas celle du site :
	// `upload_mimes` autorise couramment bien plus large pour la médiathèque.
	$mimes   = array_intersect_key( FICHIERS_AUTORISES, array_flip( $limites['accept'] ) );
	$verifie = wp_check_filetype_and_ext( (string) $envoi['tmp_name'], $nom, $mimes );

	if ( empty( $verifie['ext'] ) || empty( $verifie['type'] ) || $verifie['ext'] !== $extension
		|| ! contenu_conforme( (string) $envoi['tmp_name'], $extension ) ) {
		return new \WP_Error( 'invalid', sprintf( '%s : le contenu du fichier ne correspond pas à son extension.', $label ) );
	}

	/*
	 * Le nom fourni par le client ne devient jamais un nom de fichier.
	 *
	 * `sanitize_file_name()` en retirerait le pire, mais il resterait choisi par
	 * l'envoyeur : deux candidats nommant leur CV pareil se marcheraient dessus, et
	 * surtout l'adresse du fichier deviendrait devinable — « cv-dupont.pdf » se
	 * tente. Le nom d'origine est conservé à part, comme texte à afficher.
	 */
	$envoi['name'] = sprintf(
		'%s-%s.%s',
		sanitize_key( (string) ( $field['name'] ?? 'fichier' ) ) ?: 'fichier',
		wp_generate_password( 16, false, false ),
		$extension
	);

	proteger_dossier();

	/*
	 * `wp_handle_upload()` vit dans `wp-admin/includes/file.php`, que ni une
	 * requête REST ni le front ne chargent. Sans ce `require`, le premier envoi
	 * avec pièce jointe meurt sur une erreur fatale — et le visiteur reçoit une
	 * page blanche là où il attendait un accusé de réception.
	 *
	 * La double question n'est pas une précaution en trop : `function_exists()`
	 * interroge l'espace global, et ne voit donc pas une doublure définie dans
	 * l'espace du composant — le banc d'essai chercherait alors à charger un
	 * WordPress qui n'est pas là.
	 */
	if ( ! function_exists( 'wp_handle_upload' ) && ! function_exists( __NAMESPACE__ . '\\wp_handle_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	add_filter( 'upload_dir', __NAMESPACE__ . '\\dossier_des_pieces_jointes' );

	/*
	 * `test_form => false` n'est pas un relâchement de contrôle.
	 *
	 * `wp_handle_upload()` vérifie sinon la présence d'un champ `action` dans
	 * `$_POST`, hérité des écrans de l'administration. Une requête REST n'en a
	 * pas : tout envoi serait refusé par « Invalid form submission », un message
	 * qui ne veut rien dire pour un visiteur. Le contrôle du type, lui, reste posé
	 * — c'est `mimes` juste à côté.
	 */
	$range = wp_handle_upload( $envoi, [ 'test_form' => false, 'mimes' => $mimes ] );

	remove_filter( 'upload_dir', __NAMESPACE__ . '\\dossier_des_pieces_jointes' );

	if ( ! is_array( $range ) || ! empty( $range['error'] ) || empty( $range['file'] ) ) {
		error_log( sprintf( '[anode-forms] pièce jointe non enregistrée sur le champ « %s » : %s.', (string) ( $field['name'] ?? '?' ), (string) ( $range['error'] ?? 'cause inconnue' ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		return new \WP_Error( 'invalid', sprintf( '%s : le fichier n’a pas pu être enregistré.', $label ) );
	}

	return [
		'chemin' => (string) $range['file'],
		'type'   => (string) ( $range['type'] ?? $verifie['type'] ),
		'nom'    => sanitize_file_name( $nom ),
		'taille' => $taille,
	];
}

/**
 * Dossier de destination, le temps d'un téléversement.
 *
 * @param array<string, mixed> $dossiers Valeurs de `wp_upload_dir()`.
 *
 * @return array<string, mixed>
 */
function dossier_des_pieces_jointes( array $dossiers ): array {
	if ( ! empty( $dossiers['error'] ) ) {
		return $dossiers;
	}

	// Le découpage par année et par mois est conservé : c'est ce qui empêche un
	// dossier de plusieurs milliers d'entrées au bout de quelques années.
	$dossiers['subdir'] = '/' . DOSSIER_FICHIERS . (string) ( $dossiers['subdir'] ?? '' );
	$dossiers['path']   = (string) ( $dossiers['basedir'] ?? '' ) . $dossiers['subdir'];
	$dossiers['url']    = (string) ( $dossiers['baseurl'] ?? '' ) . $dossiers['subdir'];

	return $dossiers;
}

/**
 * Retire des fichiers déjà rangés — une demande refusée ne laisse rien.
 *
 * @param array<string, array<string, mixed>> $fichiers
 */
function nettoyer_fichiers( array $fichiers ): void {
	foreach ( $fichiers as $fichier ) {
		if ( ! empty( $fichier['chemin'] ) && file_exists( (string) $fichier['chemin'] ) ) {
			wp_delete_file( (string) $fichier['chemin'] );
		}
	}
}





/*
 * La pièce jointe suit la demande. Toujours.
 *
 * Trois chemins mènent à la suppression d'une demande — la purge à échéance, une
 * demande d'effacement RGPD, et la corbeille vidée à la main. Câbler l'effacement
 * du fichier dans chacun d'eux en aurait laissé un dehors, et le CV serait resté
 * sur le disque, dans un dossier que plus rien ne relie à personne.
 */

/* ------------------------------------------------------------------ */
/* Enregistrement et notification                                      */
/* ------------------------------------------------------------------ */

/**
 * @param array<string, mixed>  $definition Définition du formulaire.
 * @param array<string, string> $values     Valeurs validées.
 */
/**
 * Le relevé de traçage envoyé par le navigateur, assaini.
 *
 * Il arrive tel quel du client : rien n'y est cru sur parole. Seules les clés
 * connues sont retenues, chacune en chaîne courte — sans ce filtre, une page
 * pourrait gonfler le corps relayé à volonté, ou glisser une structure que le
 * scénario en aval interpréterait de travers.
 *
 * @return array<string, string>
 */
function tracage_recu( mixed $brut = null ): array {
	$connues = [
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
		'gclid',
		'fbclid',
		'msclkid',
		'ttclid',
		'li_fat_id',
		/*
		 * Substituts de `gclid` en mode consentement : Google envoie l'un des
		 * deux **à la place** de `gclid`, jamais en plus. Absents d'ici, les
		 * clics payants sans consentement publicitaire perdent leur attribution.
		 */
		'wbraid',
		'gbraid',
		// Identifiant de clic du Display de Google.
		'dclid',
		// Le niveau de l'annonce, celui où se décide un budget.
		'campaign_id',
		'adgroup_id',
		'ad_id',
		'meta_platform',
		'meta_placement',
		'_fbc',
		'_fbp',
		'referrer',
	];

	// Sur la voie multipart, le relevé voyage en JSON dans un champ de texte.
	if ( is_string( $brut ) ) {
		$brut = json_decode( wp_unslash( $brut ), true );
	}

	if ( ! is_array( $brut ) ) {
		return [];
	}

	$propre = [];

	foreach ( $connues as $clef ) {
		$valeur = $brut[ $clef ] ?? null;

		if ( is_scalar( $valeur ) && '' !== (string) $valeur ) {
			$propre[ $clef ] = mb_substr( sanitize_text_field( (string) $valeur ), 0, 500 );
		}
	}

	/*
	 * Les trois champs de consentement partent **toujours**, `null` compris
	 * (LFTF §6). Ils ne peuvent donc pas passer par le filtre ci-dessus, qui
	 * écarte les valeurs vides — un `null` y disparaîtrait.
	 *
	 * Et la distinction compte en aval : un champ **absent** se lit comme un
	 * oubli d'implémentation, un champ à `null` affirme « le gestionnaire de
	 * consentement en place ne fournit pas cette notion ». Les scénarios qui
	 * décident d'une remontée de conversion sur cette valeur ont besoin de
	 * savoir laquelle des deux situations ils lisent.
	 *
	 * Seuls les trois états de la spec sont acceptés : une valeur inventée par
	 * une page vaudra `null` plutôt que de traverser jusqu'au CRM.
	 */
	$etats = [ 'granted', 'denied', 'unspecified' ];

	foreach ( [ 'consent_conversion_tracking', 'consent_remarketing', 'consent_marketing' ] as $clef ) {
		$valeur = $brut[ $clef ] ?? null;

		$propre[ $clef ] = is_string( $valeur ) && in_array( $valeur, $etats, true ) ? $valeur : null;
	}

	return $propre;
}

/**
 * L'URL complète de la page, **si elle vient bien de ce site**.
 *
 * Le serveur reconstruit `page_url` depuis le chemin reçu, et c'est la bonne
 * prudence : une URL fournie par le client ne se relaie pas sur parole. Mais
 * cette reconstruction **perd la query string**, et l'attribution en a besoin —
 * plusieurs parcours servis par la même page ne se distinguent que par elle.
 *
 * D'où ce compromis : on accepte l'URL du client à la seule condition qu'elle
 * pointe l'hôte du site. Une URL étrangère est écartée, et l'appelant retombe
 * sur la reconstruction.
 *
 * @return string URL complète validée, ou chaîne vide.
 */
function page_url_recue( mixed $brut = null ): string {

	if ( ! is_string( $brut ) || '' === $brut ) {
		return '';
	}

	$candidate = esc_url_raw( wp_unslash( $brut ) );
	$hote      = wp_parse_url( $candidate, PHP_URL_HOST );

	if ( ! is_string( $hote ) || $hote !== wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) {
		return '';
	}

	return mb_substr( $candidate, 0, 1000 );
}

/**
 * Le type de demande, celui du `?type=` porté par le CTA (LFTF §5).
 *
 * Il sert de clé d'attribution par type de conversion, et se retrouve en
 * `form_anode` sur la page de confirmation. Une valeur libre n'a rien à faire
 * dans un CRM : on la borne à un identifiant court.
 */
function lead_type_recu( mixed $brut = null ): string {

	if ( ! is_string( $brut ) ) {
		return '';
	}

	$propre = strtolower( sanitize_key( wp_unslash( $brut ) ) );

	return mb_substr( $propre, 0, 50 );
}

/**
 * Motif d'une **référence** de relais : `constante:NOM_DE_CONSTANTE`.
 *
 * Trois caractères au moins, soixante-quatre au plus, majuscules, chiffres et
 * tirets bas — la forme d'une constante de `wp-config.php`. Le motif est étroit
 * à dessein : une définition qui nommerait `DB_PASSWORD` ne rendrait rien
 * d'exploitable (la valeur est ensuite jugée comme une URL, donc écartée), mais
 * autant ne pas laisser une définition désigner n'importe quelle constante.
 */
const REFERENCE_RELAIS = '/^constante:([A-Z][A-Z0-9_]{2,63})$/';

/**
 * Résout une adresse de relais déclarée.
 *
 * Une adresse écrite en clair est rendue telle quelle. Une **référence** —
 * `constante:NIORT_WEBHOOK_CONTACT` — est remplacée par la valeur de la
 * constante correspondante, et par la chaîne vide si elle n'est pas définie.
 *
 * Pourquoi cette indirection existe, plutôt que l'URL dans le fichier :
 *
 * - **Une URL de scénario n8n ou Make est un porteur d'autorisation.** La
 *   connaître suffit à y déverser n'importe quoi. Versionnée, elle part sur
 *   GitHub, dans chaque clone, dans chaque sauvegarde du dépôt.
 * - **Les environnements lisent le même fichier.** Le thème est versionné : la
 *   préproduction et la production servent la même définition. Une URL écrite
 *   là fait poster la préproduction dans le CRM réel — des prospects fictifs
 *   dans les vraies listes, et les automatisations du client déclenchées sur
 *   des essais.
 *
 * Une constante de `wp-config.php` répond aux deux : elle est propre à
 * l'installation, donc à l'environnement, et elle ne quitte jamais le serveur.
 * C'est déjà le rangement retenu pour `ANODE_GITHUB_TOKEN` — et pour la même
 * raison qu'ici : une option en base part avec l'export et se lit depuis
 * n'importe quelle extension.
 *
 * Le fichier versionné garde ce qui doit l'être — **quel** formulaire vise
 * **quel** scénario —, et perd seulement ce qui ne doit pas y être.
 */
function resoudre_relais( string $brute ): string {
	if ( ! preg_match( REFERENCE_RELAIS, trim( $brute ), $trouve ) ) {
		return $brute;
	}

	$valeur = defined( $trouve[1] ) ? constant( $trouve[1] ) : '';

	return is_string( $valeur ) ? $valeur : '';
}

/**
 * Les références déclarées dont la constante n'est pas définie ici.
 *
 * Sert au journal du serveur et à la sonde de santé : sans elles, un relais
 * référencé mais non résolu se lit exactement comme un relais absent, alors que
 * le geste de réparation n'est pas le même — l'un demande d'écrire une ligne
 * dans `wp-config.php`, l'autre de créer un scénario.
 *
 * @return list<string> Noms de constantes, sans le préfixe `constante:`.
 */
function references_non_resolues( array $definition ): array {
	$manquantes = [];

	foreach ( relais_bruts( $definition ) as $brute ) {
		if ( ! preg_match( REFERENCE_RELAIS, trim( $brute ), $trouve ) ) {
			continue;
		}

		if ( '' === resoudre_relais( $brute ) ) {
			$manquantes[] = $trouve[1];
		}
	}

	return array_values( array_unique( $manquantes ) );
}

/**
 * Les adresses de relais telles que la définition les écrit, sans jugement.
 *
 * @return list<string>
 */
function relais_bruts( array $definition ): array {
	$brutes = [];

	if ( isset( $definition['webhook'] ) && is_string( $definition['webhook'] ) ) {
		$brutes[] = $definition['webhook'];
	}

	foreach ( (array) ( $definition['webhooks'] ?? [] ) as $url ) {
		if ( is_string( $url ) ) {
			$brutes[] = $url;
		}
	}

	return $brutes;
}

/**
 * Adresses de relais déclarées, résolues, retenues et valides.
 *
 * `"webhook": "https://…"` pour une, `"webhooks": [ … ]` pour plusieurs — ou
 * une référence `constante:NOM`, voir `resoudre_relais()`. Ni l'une ni l'autre
 * ne sort vers le navigateur : elles ne figurent pas dans la liste blanche de
 * `describe()`. Une URL de scénario n8n ou Make est un point d'entrée ouvert —
 * la connaître suffit à y déverser n'importe quoi.
 *
 * Trois refus, et le troisième est celui qu'on oublie :
 *
 * - **HTTPS seulement.** En clair, la demande traverserait le réseau lisible.
 * - **URL bien formée**, sinon on ne sait pas où l'on écrit.
 * - **Jamais une adresse IP littérale.** Un webhook pointant sur `169.254.169.254`,
 *   `127.0.0.1` ou `10.0.0.5` fait poster le serveur **contre lui-même ou contre
 *   son réseau interne** — c'est la porte d'entrée classique vers les
 *   métadonnées d'un hébergeur cloud. Le nom d'hôte, lui, doit se résoudre.
 *
 * Les trois s'appliquent **après** résolution : une constante mal renseignée
 * est jugée comme une adresse écrite à la main, et écartée de la même façon.
 *
 * Cinq au maximum : au-delà, une demande attendrait la ronde de tous les
 * destinataires avant que le visiteur ne voie sa confirmation.
 *
 * @return list<string>
 */
function relais_declares( array $definition ): array {
	$brutes   = relais_bruts( $definition );
	$retenues = [];

	foreach ( array_slice( $brutes, 0, 5 ) as $url ) {
		$url = trim( resoudre_relais( $url ) );

		if ( '' === $url || 0 !== stripos( $url, 'https://' ) || ! wp_http_validate_url( $url ) ) {
			continue;
		}

		// `parse_url` rend une IPv6 entre crochets, que `FILTER_VALIDATE_IP`
		// ne reconnaît pas : on les retire avant de juger.
		$hote = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( '' === $hote || false !== filter_var( trim( $hote, '[]' ), FILTER_VALIDATE_IP ) ) {
			continue;
		}

		$retenues[] = $url;
	}

	return array_values( array_unique( $retenues ) );
}

/**
 * Relaie la demande vers les webhooks déclarés.
 *
 * Le corps est **plat** : chaque réponse à sa clé, à la racine. C'est la forme
 * qu'un scénario n8n ou Make lit sans configuration — imbriquer sous `fields`
 * obligerait à écrire un nœud de transformation dans chaque scénario.
 *
 * Tout part : les champs visibles, les champs cachés, et le contexte de l'envoi
 * sous des clés préfixées `_`, qui ne peuvent donc pas entrer en collision avec
 * un nom de champ — `sanitize_key()` interdit le tiret bas initial dans une
 * définition.
 *
 * Succès dès qu'**un** destinataire a répondu : deux scénarios branchés sur le
 * même formulaire ne doivent pas se rendre l'un l'autre indisponibles.
 *
 * @return true|\WP_Error true dès qu'un destinataire a répondu ; une erreur si
 *                        aucun relais n'est déclaré, ou si tous ont échoué.
 */
function relayer( string $name, array $definition, array $values, string $page, array $fichiers = [], array $contexte = [] ) {
	$urls = relais_declares( $definition );

	if ( ! $urls ) {
		/*
		 * Aucune adresse : l'envoi est refusé, et c'est le seul comportement
		 * tenable.
		 *
		 * Rendre `true` ici — ce que ce code faisait — acceptait la demande, ne
		 * l'envoyait nulle part, puisque le site n'enregistre rien, et répondait
		 * « merci, votre demande a bien été envoyée ». Mesuré en ligne sur une
		 * préproduction : statut 200, message de succès, demande perdue. C'est
		 * exactement le « merci, c'est envoyé » suivi d'un silence que le reste du
		 * fichier s'attache à éviter, et la personne qui écrit à une entreprise
		 * n'a aucun moyen de s'en apercevoir.
		 *
		 * Un blueprint neuf refuse donc ses formulaires jusqu'à ce qu'on les
		 * branche. C'est visible tout de suite, et `wp_health` le nomme — au lieu
		 * de se découvrir après la mise en ligne, sur une demande manquée.
		 *
		 * La distinction entre « rien de déclaré » et « déclaration fautive »
		 * subsiste dans le motif, qui part au journal du serveur : elle change ce
		 * qu'il faut réparer, pas ce qu'il faut répondre.
		 */
		$declare    = isset( $definition['webhook'] ) || isset( $definition['webhooks'] );
		$manquantes = references_non_resolues( $definition );

		if ( $manquantes ) {
			/*
			 * Le cas le plus fréquent d'un site fraîchement mis en ligne, et le
			 * seul dont le motif dise le geste exact : la définition nomme bien
			 * sa destination, mais l'installation ne la connaît pas.
			 *
			 * Sans cette branche, le journal disait « aucune adresse valable » —
			 * ce qui envoyait relire un fichier correct au lieu d'ouvrir
			 * `wp-config.php`.
			 */
			return new \WP_Error(
				'anode_form_webhook',
				sprintf(
					'Relais référencé mais introuvable sur cette installation : %s. Poser la constante dans wp-config.php (wp config set %s "https://…" --type=constant).',
					implode( ', ', $manquantes ),
					$manquantes[0]
				)
			);
		}

		return new \WP_Error(
			'anode_form_webhook',
			$declare
				? 'Aucune adresse de relais valable : HTTPS et nom d’hôte exigés.'
				: 'Aucun webhook déclaré : la demande n’a aucune destination, et le site n’enregistre rien.'
		);
	}

	/*
	 * Tout ce que l'outil en aval peut vouloir, en une seule fois.
	 *
	 * Le partage des rôles n'est pas arbitraire : le navigateur fournit ce que
	 * lui seul connaît — paramètres de campagne, cookies publicitaires,
	 * référent — et le serveur fournit ce que lui seul peut **attester** :
	 * l'adresse IP, l'agent, l'horodatage. Prendre l'IP dans le corps envoyé par
	 * le client reviendrait à laisser n'importe qui la choisir.
	 *
	 * Les champs restent groupés sous `fields` : un CRM veut savoir ce qui est
	 * une réponse et ce qui est du contexte, et un champ nommé `page_url`
	 * écraserait sinon celui du contexte.
	 */
	/*
	 * Les pièces jointes partent dans le même envoi, encodées en base64.
	 *
	 * Le site ne garde rien : un fichier laissé sur le disque en attendant que
	 * quelqu'un vienne le chercher serait précisément la copie qu'on refuse. Un
	 * lien de téléchargement ne conviendrait pas davantage — il faudrait garder
	 * le fichier pour que le lien mène quelque part.
	 *
	 * L'encodage grossit d'un tiers : une pièce de 2 Mo pèse 2,7 Mo dans le
	 * corps. C'est le prix du même envoi, et il reste sous ce que n8n comme
	 * Make acceptent.
	 */
	$pieces = [];

	foreach ( $fichiers as $champ => $fichier ) {
		$chemin = (string) ( $fichier['chemin'] ?? '' );

		if ( '' === $chemin || ! is_readable( $chemin ) ) {
			continue;
		}

		$contenu = file_get_contents( $chemin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $contenu ) {
			continue;
		}

		$pieces[] = [
			'field'     => (string) $champ,
			// Le nom d'origine, pas celui du disque : le second porte un
			// segment aléatoire, posé pour qu'on ne puisse pas deviner l'URL.
			'name'      => (string) ( $fichier['nom'] ?? basename( $chemin ) ),
			'mime_type' => (string) ( $fichier['type'] ?? '' ),
			'size'      => strlen( $contenu ),
			'content'   => base64_encode( $contenu ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		];
	}

	$corps = wp_json_encode(
		[
			'form'         => $name,
			'fields'       => $values,
			'files'        => $pieces,
			'tracking'     => tracage_recu( $contexte['tracking'] ?? null ),
			/*
			 * L'URL du client d'abord — elle seule porte la query string, dont
			 * dépend l'attribution — et la reconstruction en repli quand elle
			 * est absente ou qu'elle désigne un autre hôte.
			 */
			'page_url'     => page_url_recue( $contexte['page_url'] ?? null )
				?: ( '' !== $page ? home_url( $page ) : home_url( '/' ) ),
			'lead_type'    => lead_type_recu( $contexte['lead_type'] ?? null ),
			/*
			 * Dit explicitement à l'aval qu'il reçoit un lead incomplet, et
			 * qu'un second envoi suivra pour la même personne. Sans ce
			 * drapeau, le scénario compterait deux conversions et créerait
			 * deux fiches.
			 */
			'partial'      => ! empty( $contexte['partial'] ),
			'site'         => home_url( '/' ),
			'submitted_at' => gmdate( 'c' ),
			'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'address_ip'   => function_exists( 'Anode\\Hardening\\client_ip' )
				? \Anode\Hardening\client_ip()
				: ( is_string( $_SERVER['REMOTE_ADDR'] ?? null ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ),
		]
	);

	$echecs = [];
	$reussi = 0;

	foreach ( $urls as $url ) {
		$reponse = wp_remote_post(
			$url,
			[
				'timeout'     => 15,
				// Une redirection changerait le destinataire sans qu'on le sache.
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => [ 'Content-Type' => 'application/json; charset=utf-8' ],
				'body'        => (string) $corps,
			]
		);

		if ( is_wp_error( $reponse ) ) {
			$echecs[] = $reponse->get_error_message();

			continue;
		}

		$code = (int) wp_remote_retrieve_response_code( $reponse );

		if ( $code >= 200 && $code < 300 ) {
			++$reussi;

			continue;
		}

		$echecs[] = sprintf( 'HTTP %d', $code );
	}

	if ( $reussi > 0 ) {
		return true;
	}

	return new \WP_Error(
		'anode_form_webhook',
		sprintf( 'Aucun relais n’a abouti (%s).', implode( ', ', array_unique( $echecs ) ) )
	);
}



/**
 * Adresse de réponse : la valeur du premier champ de type « email ».
 *
 * Le nom d'un champ ne dit rien de sa nature — « courriel », « mail »,
 * « email_pro » sont tous plausibles. Lire `$values['email']` faisait dépendre
 * la réponse d'une convention de nommage que rien n'impose, et la cassait en
 * silence : le message partait, avec le site pour adresse de réponse.
 *
 * @param array<string, mixed>  $definition Définition du formulaire.
 * @param array<string, string> $values     Valeurs validées.
 */
function reply_to( array $definition, array $values ): string {
	foreach ( fields_of( $definition ) as $field_name => $field ) {
		if ( 'email' === ( $field['type'] ?? '' ) && ! empty( $values[ $field_name ] ) ) {
			return (string) $values[ $field_name ];
		}
	}

	return '';
}

/**
 * Trace du consentement : à quoi, et quand.
 *
 * Renvoie un tableau vide si le formulaire n'en demande pas — tous n'en ont pas
 * besoin, une demande de contact reposant sur l'intérêt légitime ou la mesure
 * précontractuelle plutôt que sur le consentement.
 *
 * @param string                $name       Nom du formulaire.
 * @param array<string, mixed>  $definition Définition du formulaire.
 * @param array<string, string> $values     Valeurs validées.
 *
 * @return array<string, mixed>
 */
function consent_proof( string $name, array $definition, array $values ): array {
	// La clé de boucle s'appelait `$name`, comme le paramètre : elle l'écrasait,
	// et « source » répétait le nom du champ au lieu de nommer le formulaire.
	// Une preuve de consentement qui ne dit pas à quoi on a consenti n'en est pas.
	foreach ( fields_of( $definition ) as $field_name => $field ) {
		$coche = 'checkbox' === ( $field['type'] ?? '' ) && ! empty( $field['required'] );

		if ( ! $coche || empty( $values[ $field_name ] ) ) {
			continue;
		}

		$lien = $field['helpLink']['url'] ?? '';

		return [
			'champ'  => $field_name,
			'texte'  => trim(
				(string) ( $field['label'] ?? '' ) . ' ' . (string) ( $field['help'] ?? '' )
					. ( $lien ? ' (' . $lien . ')' : '' )
			),
			'date'   => current_time( 'c' ),
			'source' => $name,
		];
	}

	return [];
}

/* ------------------------------------------------------------------ */
/* Effacement — le reliquat de l'époque où le site conservait          */
/* ------------------------------------------------------------------ */

/*
 * Il n'y a plus rien à effacer : depuis la 3.0.0, aucune demande n'est
 * enregistrée. Ce qui restait ici — purge à échéance, écran d'administration,
 * branchement aux outils RGPD du noyau — n'avait plus d'objet.
 *
 * Un morceau, lui, tournait encore, et il cassait :
 *
 *     add_action( 'anode/forms/purge', __NAMESPACE__ . '\\purge' );
 *
 * La fonction `purge()` était partie avec le stockage, le planificateur non.
 * Chaque jour, sur chaque site, WP-Cron déclenchait l'événement et tombait sur
 * un rappel introuvable. Mesuré le 31/07/2026 sur le site du parc :
 *
 *     Fatal error: Uncaught TypeError: call_user_func_array():
 *     Argument #1 ($callback) must be a valid callback,
 *     function "Anode\Forms\purge" not found
 *
 * Un cron qui meurt emporte les tâches planifiées après lui dans le même
 * passage : mises à jour des composants, vidages de cache. Et rien ne le
 * signalait — `wp_health` sonde les formulaires, pas le planificateur.
 *
 * D'où le nettoyage ci-dessous, qui ne se contente pas de ne plus planifier :
 * l'événement déjà inscrit reste dans la base des sites existants, et il faut
 * l'en retirer une fois. La garde `wp_next_scheduled()` rend l'opération
 * inoffensive dès le second passage.
 */
add_action(
	'init',
	static function (): void {
		if ( wp_next_scheduled( 'anode/forms/purge' ) ) {
			wp_clear_scheduled_hook( 'anode/forms/purge' );
		}
	}
);
