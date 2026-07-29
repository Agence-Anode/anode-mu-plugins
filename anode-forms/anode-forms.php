<?php
/**
 * Plugin Name: Anode — formulaires
 * Description: Réception, validation, stockage et notification des formulaires. Versant serveur du moteur assets/js/form-engine.js, en remplacement de l'élément « formulaire » de Bricks.
 * Version:     1.0.0
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

const VERSION   = '1.0.0';
const POST_TYPE = 'anode_soumission';

/** Envois autorisés par heure et par adresse IP. */
const RATE_LIMIT = 10;

/** Nom du champ-piège par défaut, si la définition n'en impose pas d'autre. */
const HONEYPOT = '_website';

/**
 * Durée de conservation par défaut d'une demande, en jours.
 *
 * Trois ans : la durée annoncée aux visiteurs sous la case de consentement.
 * Une définition peut la réduire avec sa propre clé « retention » ; elle ne
 * peut pas l'allonger sans que la mention affichée soit corrigée d'autant.
 */
const RETENTION = 1095;

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
/* Stockage                                                            */
/* ------------------------------------------------------------------ */

/**
 * Les soumissions sont des contenus : elles héritent ainsi de la liste, de la
 * recherche, de la corbeille et des permissions de WordPress, sans table
 * dédiée à maintenir.
 */
add_action(
	'init',
	static function (): void {
		register_post_type(
			POST_TYPE,
			[
				'labels' => [
					'name'          => 'Demandes reçues',
					'singular_name' => 'Demande',
					'menu_name'     => 'Demandes reçues',
					'all_items'     => 'Demandes reçues',
					'search_items'  => 'Rechercher une demande',
					'not_found'     => 'Aucune demande.',
				],
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'menu_icon'           => 'dashicons-email-alt',
				'menu_position'       => 26,
				'supports'            => [ 'title' ],
				'capability_type'     => 'post',
				'capabilities'        => [
					// Une demande se lit et se supprime, elle ne se crée ni ne
					// s'édite à la main : ce serait falsifier une trace.
					'create_posts' => 'do_not_allow',
				],
				'map_meta_cap'        => true,
			]
		);
	}
);

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
				'args'                => [
					'form'   => [ 'required' => true, 'type' => 'string' ],
					'fields' => [ 'required' => true, 'type' => 'object' ],
					'page'   => [ 'type' => 'string' ],
				],
			]
		);
	}
);

/**
 * Renvoie la définition publique d'un formulaire.
 */
function describe( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
	$name       = sanitize_key( (string) $request->get_param( 'name' ) );
	$definition = definition( $name );

	if ( null === $definition ) {
		return new \WP_Error( 'anode_form_unknown', 'Ce formulaire n’existe pas.', [ 'status' => 404 ] );
	}

	// L'adresse de notification ne concerne pas l'affichage : elle reste ici.
	unset( $definition['notify'] );

	// Le nom du champ-piège, lui, est nécessaire au rendu — et de toute façon
	// lisible dans le DOM. Le taire n'apporterait qu'une fausse discrétion.
	$definition['honeypot'] = $definition['honeypot'] ?? HONEYPOT;
	$definition['id']       = $name;

	return rest_ensure_response( $definition );
}

function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
	$name       = sanitize_key( (string) $request->get_param( 'form' ) );
	$definition = definition( $name );

	if ( null === $definition ) {
		return new \WP_Error( 'anode_form_unknown', 'Ce formulaire n’existe pas.', [ 'status' => 404 ] );
	}

	$limited = check_rate_limit();

	if ( $limited instanceof \WP_Error ) {
		return $limited;
	}

	$submitted = (array) $request->get_param( 'fields' );
	$declared  = fields_of( $definition );

	// Piège à robots : un champ que rien n'affiche, donc que seul un automate
	// remplit. Le rejet est silencieux — un robot informé s'adapterait.
	if ( ! empty( $submitted[ $definition['honeypot'] ?? HONEYPOT ] ) ) {
		return rest_ensure_response( [ 'ok' => true ] );
	}

	$values = [];
	$errors = [];

	foreach ( $declared as $field_name => $field ) {
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
		return new \WP_Error(
			'anode_form_unknown_field',
			sprintf( 'Champ(s) inattendu(s) : %s.', implode( ', ', array_map( 'sanitize_key', $unknown ) ) ),
			[ 'status' => 400 ]
		);
	}

	if ( $errors ) {
		return new \WP_Error(
			'anode_form_invalid',
			implode( ' ', $errors ),
			[ 'status' => 400, 'fields' => $errors ]
		);
	}

	$post_id = store( $name, $definition, $values, (string) $request->get_param( 'page' ) );

	if ( $post_id instanceof \WP_Error ) {
		return $post_id;
	}

	notify( $name, $definition, $values, $post_id );

	/**
	 * Permet à un site de brancher un traitement supplémentaire — synchronisation
	 * vers un outil métier, marquage, réponse automatique.
	 *
	 * @param int                  $post_id    Identifiant de la demande enregistrée.
	 * @param array<string, mixed> $values     Valeurs validées.
	 * @param array<string, mixed> $definition Définition du formulaire.
	 */
	do_action( "anode/form/{$name}/submitted", $post_id, $values, $definition );

	return rest_ensure_response(
		[
			'ok'      => true,
			'id'      => $post_id,
			'message' => $definition['messages']['success'] ?? 'Votre demande a bien été envoyée.',
		]
	);
}

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
function sanitize_field( mixed $value, array $field ): string|\WP_Error {
	$label = $field['label'] ?? ( $field['name'] ?? 'Champ' );
	$type  = $field['type'] ?? 'text';

	if ( is_bool( $value ) ) {
		$value = $value ? '1' : '';
	}

	if ( is_array( $value ) || is_object( $value ) ) {
		return new \WP_Error( 'invalid', sprintf( '%s : valeur inattendue.', $label ) );
	}

	$value = trim( (string) $value );

	if ( '' === $value ) {
		return ! empty( $field['required'] )
			? new \WP_Error( 'required', sprintf( '« %s » est obligatoire.', $label ) )
			: '';
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

	$max = (int) ( $field['maxLength'] ?? 2000 );

	if ( mb_strlen( $clean ) > $max ) {
		return new \WP_Error( 'invalid', sprintf( '%s : %d caractères maximum.', $label, $max ) );
	}

	return $clean;
}

function check_rate_limit(): bool|\WP_Error {
	$ip    = $_SERVER['REMOTE_ADDR'] ?? '';
	$key   = 'anode_form_' . md5( is_string( $ip ) ? $ip : '' );
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
/* Enregistrement et notification                                      */
/* ------------------------------------------------------------------ */

/**
 * @param array<string, mixed>  $definition Définition du formulaire.
 * @param array<string, string> $values     Valeurs validées.
 */
function store( string $name, array $definition, array $values, string $page ): int|\WP_Error {
	/*
	 * Ni adresse ni nom dans le titre.
	 *
	 * Le titre d'un contenu voyage : il apparaît dans les listes, dans le slug,
	 * donc dans le permalien, et dans toute API qui énumère les contenus. Y
	 * mettre l'adresse du demandeur, c'est la diffuser bien au-delà de la fiche
	 * — le pont REST la servait à tout compte d'édition. Elle reste dans les
	 * champs, visibles sur la fiche, par les seules personnes habilitées.
	 */
	$title = sprintf(
		'%s — %s',
		$definition['label'] ?? ucfirst( $name ),
		wp_date( 'j F Y à H\hi' )
	);

	$post_id = wp_insert_post(
		[
			'post_type'   => POST_TYPE,
			'post_title'  => sanitize_text_field( $title ),
			'post_status' => 'publish',
		],
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return new \WP_Error( 'anode_form_store', 'Enregistrement impossible.', [ 'status' => 500 ] );
	}

	$post_id = (int) $post_id;

	update_post_meta( $post_id, '_anode_form', $name );
	update_post_meta( $post_id, '_anode_values', $values );
	update_post_meta( $post_id, '_anode_page', sanitize_text_field( $page ) );

	/*
	 * L'adresse IP n'est pas conservée.
	 *
	 * Elle l'était « au cas où ». Or une donnée personnelle se garde pour une
	 * finalité, pas par précaution : c'est le principe de minimisation
	 * (RGPD art. 5.1.c). La seule finalité réelle — limiter le rythme des
	 * envois — est déjà servie par un compteur temporaire indexé sur une
	 * empreinte, qui expire au bout d'une heure et ne permet de remonter à
	 * personne.
	 */

	$consentement = consent_proof( $name, $definition, $values );

	if ( $consentement ) {
		// Prouver le consentement suppose de savoir *à quoi* la personne a
		// consenti, et quand (RGPD art. 7.1). Le seul « 1 » du champ ne le dit
		// pas : on conserve le texte exact qui était affiché.
		update_post_meta( $post_id, '_anode_consentement', $consentement );
	}

	return $post_id;
}

/**
 * @param array<string, mixed>  $definition Définition du formulaire.
 * @param array<string, string> $values     Valeurs validées.
 */
function notify( string $name, array $definition, array $values, int $post_id ): void {
	$to = $definition['notify'] ?? get_option( 'admin_email' );

	if ( ! $to ) {
		return;
	}

	$lines = [];

	foreach ( fields_of( $definition ) as $field_name => $field ) {
		$lines[] = sprintf( '%s : %s', $field['label'] ?? $field_name, $values[ $field_name ] ?? '—' );
	}

	$lines[] = '';
	$lines[] = sprintf( 'Voir dans l’administration : %s', get_edit_post_link( $post_id, '' ) );

	wp_mail(
		$to,
		sprintf( '[%s] %s', get_bloginfo( 'name' ), $definition['label'] ?? ucfirst( $name ) ),
		implode( "\n", $lines ),
		// Répondre au message doit répondre au demandeur, pas au site.
		! empty( $values['email'] ) ? [ 'Reply-To: ' . $values['email'] ] : []
	);
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
	foreach ( fields_of( $definition ) as $name => $field ) {
		$coche = 'checkbox' === ( $field['type'] ?? '' ) && ! empty( $field['required'] );

		if ( ! $coche || empty( $values[ $name ] ) ) {
			continue;
		}

		$lien = $field['helpLink']['url'] ?? '';

		return [
			'champ'  => $name,
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
/* Effacement                                                          */
/* ------------------------------------------------------------------ */

/*
 * Une durée de conservation qu'aucun code n'applique n'est pas une durée de
 * conservation : c'est une phrase. Le formulaire promet au visiteur que ses
 * données sont effacées au bout de trois ans — voici ce qui le fait.
 */
add_action(
	'init',
	static function (): void {
		if ( ! wp_next_scheduled( 'anode/forms/purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'anode/forms/purge' );
		}
	}
);

add_action( 'anode/forms/purge', __NAMESPACE__ . '\\purge' );

/**
 * Efface les demandes dont la durée de conservation est écoulée.
 *
 * Suppression forcée : passer par la corbeille laisserait les données trente
 * jours de plus, et « à la corbeille » n'est pas « effacé ».
 *
 * @return int Nombre de demandes effacées.
 */
function purge(): int {
	$efface = 0;

	foreach ( get_posts(
		[
			'post_type'      => POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]
	) as $post_id ) {
		$name       = (string) get_post_meta( $post_id, '_anode_form', true );
		$definition = definition( $name ) ?? [];
		$jours      = (int) ( $definition['retention'] ?? RETENTION );
		$limite     = strtotime( sprintf( '-%d days', max( 1, $jours ) ) );

		if ( get_post_timestamp( $post_id ) < $limite ) {
			wp_delete_post( $post_id, true );
			$efface += 1;
		}
	}

	if ( $efface ) {
		// Une purge silencieuse ne se distingue pas d'une purge qui ne tourne
		// pas : on garde la trace du nombre, jamais du contenu.
		update_option(
			'anode_forms_derniere_purge',
			[ 'date' => current_time( 'c' ), 'effacees' => $efface ],
			false
		);
	}

	return $efface;
}

/* ------------------------------------------------------------------ */
/* Administration                                                      */
/* ------------------------------------------------------------------ */

/** Les valeurs reçues s'affichent sur la fiche, sans avoir à fouiller. */
add_action(
	'add_meta_boxes',
	static function (): void {
		add_meta_box(
			'anode-form-values',
			'Réponses',
			static function ( \WP_Post $post ): void {
				$values = get_post_meta( $post->ID, '_anode_values', true );
				$form   = (string) get_post_meta( $post->ID, '_anode_form', true );
				$fields = fields_of( definition( $form ) ?? [] );

				if ( ! is_array( $values ) || ! $values ) {
					echo '<p>Aucune réponse enregistrée.</p>';

					return;
				}

				echo '<table class="widefat striped"><tbody>';

				foreach ( $values as $key => $value ) {
					printf(
						'<tr><th style="width:220px">%s</th><td>%s</td></tr>',
						esc_html( $fields[ $key ]['label'] ?? $key ),
						nl2br( esc_html( (string) $value ) )
					);
				}

				printf(
					'<tr><th>Page</th><td>%s</td></tr>',
					esc_html( (string) get_post_meta( $post->ID, '_anode_page', true ) )
				);

				$consentement = get_post_meta( $post->ID, '_anode_consentement', true );

				if ( is_array( $consentement ) && $consentement ) {
					printf(
						'<tr><th>Consentement</th><td>%s<br><em>%s</em></td></tr>',
						esc_html( wp_date( 'j F Y à H\\hi', (int) strtotime( (string) $consentement['date'] ) ) ),
						esc_html( (string) $consentement['texte'] )
					);
				}

				echo '</tbody></table>';
			},
			POST_TYPE,
			'normal',
			'high'
		);
	}
);
