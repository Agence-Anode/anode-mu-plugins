<?php
/**
 * Tests du moteur de formulaires — sans WordPress, sans réseau, sans base.
 *
 * Ce qui est vérifié : la validation de chaque type de champ, les contraintes
 * `minLength` et `pattern` que seul le navigateur appliquait, le piège à robots,
 * le choix de l'adresse de réponse, ce que la route publique laisse sortir, et
 * le sens du tri de la purge.
 *
 * Deux tests portent sur des défauts qu'aucune capture ni aucun essai manuel ne
 * révèle : la purge qui ne regardait que les demandes les plus récentes, et le
 * lien d'administration systématiquement vide dans la notification. Ils sont
 * écrits pour échouer si la cause revient.
 *
 * Lancement : php blueprint/mu-plugins/anode-forms/tests/test-forms.php
 *
 * @package Anode\Forms
 */

declare( strict_types = 1 );

/* --- Doublures des classes WordPress -------------------------------- */

namespace {

	define( 'ABSPATH', __DIR__ );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'MB_IN_BYTES', 1024 * 1024 );

	/** Seuls le code, le message et les données sont lus par le plugin. */
	class WP_Error {
		public function __construct(
			private string $code = '',
			private string $message = '',
			private array $data = []
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): array {
			return $this->data;
		}
	}

	/** Ce que `demandes_de()` lit d'un contenu : son identifiant, et rien d'autre. */
	class WP_Post {
		public function __construct(
			public int $ID = 0,
			public string $post_type = '',
			public int $post_parent = 0
		) {}
	}

	class WP_REST_Response {
		public function __construct( public mixed $data = null ) {}

		public function get_data(): mixed {
			return $this->data;
		}
	}

	class WP_REST_Request {
		public function __construct( private array $params = [], private array $entetes = [], private array $fichiers = [] ) {}

		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		/** Rend '' plutôt que null : `handle()` lit l'en-tête pour détecter un corps tronqué. */
		public function get_header( string $nom ): string {
			return (string) ( $this->entetes[ strtolower( $nom ) ] ?? '' );
		}

		/** Équivalent de `$_FILES` : vide tant qu'un test n'y range pas de pièce jointe. */
		public function get_file_params(): array {
			return $this->fichiers;
		}

		/**
		 * Équivalent de `$_POST` — vide sur un corps que PHP a tronqué.
		 *
		 * C'est la seule marque que laisse un envoi plus gros que `post_max_size` :
		 * aucun paramètre, aucun fichier, et pas la moindre erreur.
		 */
		public function get_body_params(): array {
			return array_filter( $this->params, static fn ( $valeur ): bool => null !== $valeur );
		}
	}

	/**
	 * Doublure globale, en plus de celle de l'espace de noms du plugin.
	 *
	 * `array_map( 'sanitize_key', … )` résout son rappel dans l'espace global :
	 * sans cette copie, la ligne qui nomme les champs inattendus échoue ici
	 * alors qu'elle fonctionne sur un site, où WordPress la fournit.
	 */
	function sanitize_key( string $key ): string {
		return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) );
	}
}

/* --- Doublure d'anode-hardening ------------------------------------- */

namespace Anode\Hardening {

	/**
	 * L'extension est présente et rend une adresse différente de REMOTE_ADDR :
	 * c'est ce qui permet de prouver que la limitation de rythme l'interroge.
	 */
	function client_ip(): string {
		return '203.0.113.7';
	}
}

/* --- Doublures des fonctions WordPress ------------------------------ */

namespace Anode\Forms {

	function get_stylesheet_directory(): string {
		return (string) $GLOBALS['anode_theme'];
	}

function add_filter( string $hook, $callback, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['anode_forms_filters'][ $hook ][] = $callback;
}

function get_the_date( string $format = '', $post = null ): string {
	return '2026-07-30T12:00:00+00:00';
}

	/**
	 * Les actions sont **retenues**, pas ignorées.
	 *
	 * `before_delete_post` est ce qui efface une pièce jointe du disque : une
	 * doublure muette aurait laissé les tests d'effacement prouver que le crochet
	 * est écrit, jamais qu'il est appelé — c'est-à-dire tout sauf ce qui compte.
	 */
	function add_action( string $hook = '', $callback = null, int $priority = 10, int $args = 1 ): void {
		if ( '' !== $hook && null !== $callback ) {
			$GLOBALS['anode_forms_actions'][ $hook ][] = $callback;
		}
	}

	function do_action(): void {}

	function remove_filter( string $hook, $callback, int $priority = 10 ): bool {
		$GLOBALS['anode_forms_filters'][ $hook ] = array_values(
			array_filter(
				$GLOBALS['anode_forms_filters'][ $hook ] ?? [],
				static fn ( $inscrit ): bool => $inscrit !== $callback
			)
		);

		return true;
	}

	function sanitize_key( string $key ): string {
		return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) );
	}

	function sanitize_file_name( string $name ): string {
		// WordPress remplace les espaces par des tirets avant de retirer le reste :
		// sans ce passage, « CV Dupont.pdf » deviendrait « CVDupont.pdf » ici et
		// « CV-Dupont.pdf » sur un site — le test ne prouverait rien du vrai nom.
		return (string) preg_replace( '/[^a-zA-Z0-9_\-.]/', '', (string) preg_replace( '/\s+/', '-', $name ) );
	}

	/** Le site n'est pas traduit : la doublure rend la chaîne telle quelle. */
	function __( string $texte, string $domaine = 'default' ): string {
		return $texte;
	}

	function sanitize_email( string $email ): string {
		return trim( $email );
	}

	function is_email( string $email ): bool {
		return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	function sanitize_text_field( string $value ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $value ) ) );
	}

	function sanitize_textarea_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}

	function esc_url_raw( string $url, array $protocols = [] ): string {
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );

		if ( $protocols && ! in_array( $scheme, $protocols, true ) ) {
			return '';
		}

		return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
	}

	function wp_parse_url( string $url, int $component = -1 ): mixed {
		$parts = parse_url( $url );

		if ( false === $parts ) {
			return false;
		}

		if ( PHP_URL_HOST === $component ) {
			return $parts['host'] ?? null;
		}

		if ( PHP_URL_PATH === $component ) {
			return $parts['path'] ?? '';
		}

		return $parts;
	}

	function get_transient( string $key ): mixed {
		return $GLOBALS['anode_transients'][ $key ] ?? false;
	}

	function set_transient( string $key, mixed $value, int $ttl = 0 ): bool {
		$GLOBALS['anode_transients'][ $key ] = $value;

		return true;
	}

	function wp_date( string $format, ?int $timestamp = null ): string {
		return '30 juillet 2026 à 10h00';
	}

	function current_time( string $format ): string {
		return '2026-07-30T10:00:00+02:00';
	}

	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof \WP_Error;
	}

	/*
	 * Le relais est désormais **la** sortie d'une demande : rien n'est
	 * enregistré, tout part vers les webhooks déclarés. Ces doublures retiennent
	 * ce qui a été envoyé, et permettent de faire échouer un envoi à volonté.
	 */
	function wp_remote_post( string $url, array $args = [] ): mixed {
		$GLOBALS['anode_relais'][] = [
			'url'  => $url,
			'body' => json_decode( (string) ( $args['body'] ?? '' ), true ),
		];

		if ( ! empty( $GLOBALS['anode_relais_echoue'] ) ) {
			return new \WP_Error( 'http_request_failed', 'relais injoignable' );
		}

		return [ 'response' => [ 'code' => 200 ], 'body' => 'ok' ];
	}

	/**
	 * Doublure de la validation d'URL sortante.
	 *
	 * La vraie fonction interroge le réseau — résolution DNS comprise — ce qu'un
	 * banc d'essai ne doit pas faire. Le contrôle qui compte pour le relais est
	 * celui de `relais_declares()` : HTTPS exigé, adresse IP littérale refusée.
	 * Il est éprouvé par ses propres tests, sans réseau.
	 */
	function wp_json_encode( mixed $valeur, int $options = 0 ): string|false {
		return json_encode( $valeur, $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	function wp_http_validate_url( string $url ): string|false {
		return str_starts_with( $url, 'https://' ) ? $url : false;
	}

	function wp_remote_retrieve_response_code( mixed $reponse ): int {
		return is_array( $reponse ) ? (int) ( $reponse['response']['code'] ?? 0 ) : 0;
	}

	function wp_remote_retrieve_body( mixed $reponse ): string {
		return is_array( $reponse ) ? (string) ( $reponse['body'] ?? '' ) : '';
	}

	function wp_insert_post( array $post, bool $wp_error = false ): mixed {
		$id = $GLOBALS['anode_next_id']++;

		$GLOBALS['anode_posts'][ $id ] = $post;

		return $id;
	}

	function update_post_meta( int $post_id, string $key, mixed $value ): bool {
		$GLOBALS['anode_meta'][ $post_id ][ $key ] = $value;

		return true;
	}

	function get_post_meta( int $post_id, string $key, bool $single = false ): mixed {
		return $GLOBALS['anode_meta'][ $post_id ][ $key ] ?? '';
	}

	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['anode_options'][ $key ] ?? $default;
	}

	function update_option( string $key, mixed $value, bool $autoload = true ): bool {
		$GLOBALS['anode_options'][ $key ] = $value;

		return true;
	}

	function get_bloginfo( string $what = 'name' ): string {
		return 'Site d’essai';
	}

	function admin_url( string $path = '' ): string {
		return 'https://exemple.test/wp-admin/' . $path;
	}

	/**
	 * Rend `null`, comme la vraie fonction quand l'utilisateur courant n'a pas la
	 * capacité `edit_post` sur le contenu — le cas de tout envoi public, qui n'a
	 * aucun utilisateur courant. Cette doublure n'est appelée par rien : elle est
	 * là pour que le test du lien d'administration échoue si l'on y revenait.
	 */
	function get_edit_post_link( int $post_id, string $context = 'display' ): ?string {
		return null;
	}

	function wp_mail( string|array $to, string $subject, string $message, array $headers = [] ): bool {
		$GLOBALS['anode_mails'][] = compact( 'to', 'subject', 'message', 'headers' );

		return (bool) $GLOBALS['anode_mail_ok'];
	}

	/**
	 * Intercepte le journal : dans l'espace de noms du plugin, un appel non
	 * qualifié à `error_log` trouve cette fonction avant celle de PHP.
	 */
	function error_log( string $message ): bool {
		$GLOBALS['anode_logs'][] = $message;

		return true;
	}

	function rest_ensure_response( mixed $data ): \WP_REST_Response {
		return new \WP_REST_Response( $data );
	}

	/**
	 * Doublure fidèle : elle trie et tronque comme le fait WordPress, sans quoi
	 * le test du sens du tri ne prouverait rien.
	 */
	function get_posts( array $args = [] ): array {
		$GLOBALS['anode_get_posts_args'] = $args;

		$ids       = array_keys( $GLOBALS['anode_times'] );
		$croissant = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) );

		usort(
			$ids,
			static fn ( int $a, int $b ): int => $croissant
				? $GLOBALS['anode_times'][ $a ] <=> $GLOBALS['anode_times'][ $b ]
				: $GLOBALS['anode_times'][ $b ] <=> $GLOBALS['anode_times'][ $a ]
		);

		$limite = (int) ( $args['posts_per_page'] ?? -1 );
		$ids    = $limite > 0 ? array_slice( $ids, 0, $limite ) : $ids;

		// `fields => ids` rend des entiers, tout le reste des objets : c'est ce que
		// fait WordPress, et `demandes_de()` compte dessus pour lire `$post->ID`.
		return 'ids' === ( $args['fields'] ?? 'ids' )
			? $ids
			: array_map( static fn ( int $id ): \WP_Post => new \WP_Post( $id, POST_TYPE ), $ids );
	}

	function get_post_timestamp( int $post_id ): int {
		return (int) ( $GLOBALS['anode_times'][ $post_id ] ?? 0 );
	}

	function wp_delete_post( int $post_id, bool $force = false ): bool {
		$GLOBALS['anode_deleted'][] = [ 'id' => $post_id, 'force' => $force ];

		// WordPress déclenche `before_delete_post` avant toute suppression
		// définitive. Sans cet appel, la purge et l'effacement RGPD paraîtraient
		// emporter les pièces jointes alors que rien ne les toucherait.
		if ( $force ) {
			foreach ( $GLOBALS['anode_forms_actions']['before_delete_post'] ?? [] as $rappel ) {
				$rappel( $post_id );
			}
		}

		unset( $GLOBALS['anode_times'][ $post_id ] );

		return true;
	}

	/* --- Doublures du téléversement ----------------------------------- */

	function size_format( int|float $octets, int $decimales = 0 ): string {
		return $octets >= MB_IN_BYTES
			? round( $octets / MB_IN_BYTES, 1 ) . ' Mo'
			: max( 1, (int) round( $octets / 1024 ) ) . ' Ko';
	}

	function wp_max_upload_size(): int {
		return (int) $GLOBALS['anode_max_upload'];
	}

	function wp_upload_dir(): array {
		$base = (string) $GLOBALS['anode_uploads'];

		return [
			'basedir' => $base,
			'baseurl' => 'https://exemple.test/wp-content/uploads',
			'subdir'  => '/2026/07',
			'path'    => $base . '/2026/07',
			'url'     => 'https://exemple.test/wp-content/uploads/2026/07',
			'error'   => false,
		];
	}

	function wp_mkdir_p( string $chemin ): bool {
		return is_dir( $chemin ) || mkdir( $chemin, 0777, true );
	}

	function wp_delete_file( string $chemin ): void {
		$GLOBALS['anode_fichiers_supprimes'][] = $chemin;

		if ( file_exists( $chemin ) ) {
			unlink( $chemin );
		}
	}

	/**
	 * Doublure de la seule part que le plugin attend d'elle : la liste blanche
	 * d'extensions. Le contrôle du **contenu** n'est pas simulé — il est réel,
	 * `contenu_conforme()` interrogeant `finfo` sur le fichier écrit par le test.
	 */
	function wp_check_filetype_and_ext( string $chemin, string $nom, array $mimes = [] ): array {
		$extension = strtolower( (string) pathinfo( $nom, PATHINFO_EXTENSION ) );
		$type      = $mimes[ $extension ] ?? false;

		return [ 'ext' => $type ? $extension : false, 'type' => $type ?: false, 'proper_filename' => false ];
	}

	/**
	 * Doublure fidèle sur le point qui compte : elle applique le filtre
	 * `upload_dir` comme le fait WordPress, puis déplace vraiment le fichier.
	 *
	 * C'est ce qui permet de vérifier que la pièce jointe atterrit dans le dossier
	 * protégé — et non dans les médias du site.
	 */
	function wp_handle_upload( array $fichier, array $options = [] ): array {
		$GLOBALS['anode_upload_options'] = $options;

		$dossiers = wp_upload_dir();

		foreach ( $GLOBALS['anode_forms_filters']['upload_dir'] ?? [] as $filtre ) {
			$dossiers = $filtre( $dossiers );
		}

		wp_mkdir_p( (string) $dossiers['path'] );

		$cible = $dossiers['path'] . '/' . $fichier['name'];

		rename( (string) $fichier['tmp_name'], $cible );

		return [
			'file' => $cible,
			'url'  => $dossiers['url'] . '/' . $fichier['name'],
			'type' => (string) ( $options['mimes'][ strtolower( (string) pathinfo( $fichier['name'], PATHINFO_EXTENSION ) ) ] ?? '' ),
		];
	}

	function wp_generate_password( int $longueur = 12, bool $special = true, bool $extra = false ): string {
		return substr(
			str_shuffle( str_repeat( 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', 2 ) ),
			0,
			$longueur
		);
	}

	function wp_insert_attachment( array $post, string $fichier = '', int $parent = 0, bool $wp_error = false ): mixed {
		if ( ! empty( $GLOBALS['anode_attachement_ko'] ) ) {
			return new \WP_Error( 'db_insert_error', 'Insertion impossible.' );
		}

		$id = $GLOBALS['anode_next_id']++;

		$GLOBALS['anode_posts'][ $id ]   = array_merge( $post, [ 'post_type' => 'attachment' ] );
		$GLOBALS['anode_attaches'][ $id ] = $fichier;

		return $id;
	}

	function get_attached_file( int $id, bool $unfiltered = false ): string {
		return (string) ( $GLOBALS['anode_attaches'][ $id ] ?? '' );
	}

	function wp_delete_attachment( int $id, bool $force = false ): mixed {
		$chemin = get_attached_file( $id );

		if ( $chemin && file_exists( $chemin ) ) {
			unlink( $chemin );
		}

		$GLOBALS['anode_attachements_supprimes'][] = [ 'id' => $id, 'force' => $force ];

		unset( $GLOBALS['anode_posts'][ $id ], $GLOBALS['anode_attaches'][ $id ] );

		return true;
	}

	function get_post_type( int $id ): string {
		return (string) ( $GLOBALS['anode_posts'][ $id ]['post_type'] ?? '' );
	}

	/* --- Doublures du repli sans JavaScript --------------------------- */

	function esc_html( mixed $texte ): string {
		return htmlspecialchars( (string) $texte, ENT_QUOTES, 'UTF-8' );
	}

	function esc_attr( mixed $texte ): string {
		return htmlspecialchars( (string) $texte, ENT_QUOTES, 'UTF-8' );
	}

	function esc_url( mixed $url ): string {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}

	function wp_unslash( mixed $valeur ): mixed {
		if ( is_array( $valeur ) ) {
			return array_map( __NAMESPACE__ . '\\wp_unslash', $valeur );
		}

		return is_string( $valeur ) ? stripslashes( $valeur ) : $valeur;
	}

	function home_url( string $chemin = '' ): string {
		return 'https://exemple.test' . $chemin;
	}

	function add_query_arg( string $clef, string $valeur, string $url ): string {
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $clef . '=' . rawurlencode( $valeur );
	}

	function wp_validate_redirect( string $url, string $defaut ): string {
		return str_starts_with( $url, 'https://exemple.test' ) ? $url : $defaut;
	}

	/**
	 * Nonce d'essai : il porte l'action **et** le tour d'horloge courant, comme
	 * celui de WordPress.
	 *
	 * C'est ce qui permet de simuler une page servie depuis un cache — le jeton
	 * qu'elle contient a été forgé sous un tour précédent, il ne vérifie plus.
	 */
	function wp_create_nonce( string $action ): string {
		return 'nonce-' . $action . '-' . $GLOBALS['anode_tour'];
	}

	function wp_verify_nonce( string $nonce, string $action ): int|false {
		return 'nonce-' . $action . '-' . $GLOBALS['anode_tour'] === $nonce ? 1 : false;
	}

	function wp_nonce_field( string $action, string $nom, bool $referer = true, bool $afficher = true ): string {
		return sprintf( '<input type="hidden" name="%s" value="%s">', $nom, wp_create_nonce( $action ) );
	}

	function nocache_headers(): void {
		$GLOBALS['anode_nocache'] = true;
	}

	/**
	 * Toujours faux : sur un site, l'appel n'a de sens que si rien n'est parti.
	 * Le rendre vrai ici rendrait la pose de l'en-tête inéprouvable.
	 */
	function headers_sent(): bool {
		return false;
	}

	function is_admin(): bool {
		return false;
	}

	/* --- Décor ------------------------------------------------------- */

	$GLOBALS['anode_theme']   = sys_get_temp_dir() . '/anode-forms-tests-' . getmypid();
	$GLOBALS['anode_uploads'] = $GLOBALS['anode_theme'] . '/uploads';

	if ( ! is_dir( $GLOBALS['anode_theme'] . '/forms' ) ) {
		mkdir( $GLOBALS['anode_theme'] . '/forms', 0777, true );
	}

	/** Efface un dossier et tout ce qu'il contient. */
	function effacer_dossier( string $chemin ): void {
		foreach ( (array) glob( $chemin . '/{,.}*', GLOB_BRACE ) as $entree ) {
			$entree = (string) $entree;

			if ( str_ends_with( $entree, '/.' ) || str_ends_with( $entree, '/..' ) ) {
				continue;
			}

			is_dir( $entree ) ? effacer_dossier( $entree ) : unlink( $entree );
		}

		@rmdir( $chemin );
	}

	register_shutdown_function(
		static function (): void {
			foreach ( (array) glob( $GLOBALS['anode_theme'] . '/forms/*.json' ) as $fichier ) {
				unlink( (string) $fichier );
			}

			@rmdir( $GLOBALS['anode_theme'] . '/forms' );
			effacer_dossier( (string) $GLOBALS['anode_uploads'] );
			@rmdir( $GLOBALS['anode_theme'] );
		}
	);

	/**
	 * Écrit une définition dans le thème d'essai.
	 */
	function fixture( string $name, array $definition ): void {
		file_put_contents(
			$GLOBALS['anode_theme'] . '/forms/' . $name . '.json',
			(string) json_encode( $definition, JSON_UNESCAPED_UNICODE )
		);
	}

	fixture(
		'contact',
		[
			'label'         => 'Nous contacter',
			// Le relais est la seule sortie : rien n'est enregistré, et une
			// définition sans webhook n'aurait aucun effet observable.
			'webhook'       => 'https://relais.exemple.test/contact',
			'notify'        => 'contact@exemple.fr',
			'retention'     => 365,
			// Clé qu'aucune version de la route publique n'a jamais eu à servir :
			// elle tient le rôle de la clé qu'un projet ajoutera demain.
			'jeton_interne' => 'ne-doit-pas-sortir',
			'labels'        => [ 'submit' => 'Envoyer la demande' ],
			'screens'       => [
				[
					'title'  => 'Vos coordonnées',
					'fields' => [
						[ 'name' => 'nom', 'label' => 'Nom', 'type' => 'text', 'required' => true, 'minLength' => 2 ],
						[ 'name' => 'courriel', 'label' => 'Adresse e‑mail', 'type' => 'email', 'required' => true ],
					],
				],
				[
					'title'  => 'Votre demande',
					'fields' => [
						[
							'name'     => 'canal',
							'label'    => 'Comment nous avez‑vous connus ?',
							'type'     => 'radio',
							'required' => true,
							'options'  => [ 'Recherche', 'Bouche à oreille' ],
						],
						[ 'name' => 'message', 'label' => 'Votre message', 'type' => 'textarea', 'required' => true ],
						[
							'name'     => 'rgpd',
							'label'    => 'J’accepte la conservation de mes données',
							'type'     => 'checkbox',
							'required' => true,
							'help'     => 'Trois ans, puis effacement.',
							'helpLink' => [ 'url' => '/confidentialite/', 'text' => 'Politique de confidentialité' ],
						],
					],
				],
			],
		]
	);

	/*
	 * Formulaire de candidature : le cas d'usage des pièces jointes.
	 *
	 * Un champ obligatoire à deux formats, un facultatif à un seul — de quoi
	 * éprouver l'obligation, la restriction par champ, et l'absence de fichier.
	 */
	fixture(
		'candidature',
		[
			'label'  => 'Candidature',
			'webhook'   => 'https://relais.exemple.test/essai',
			'notify' => 'rh@exemple.fr',
			'fields' => [
				[ 'name' => 'nom', 'label' => 'Nom', 'type' => 'text', 'required' => true ],
				[ 'name' => 'courriel', 'label' => 'Adresse e‑mail', 'type' => 'email', 'required' => true ],
				[
					'name'     => 'cv',
					'label'    => 'Votre CV',
					'type'     => 'file',
					'required' => true,
					'accept'   => [ 'pdf', 'docx' ],
					'maxSize'  => 2,
				],
				[ 'name' => 'lettre', 'label' => 'Lettre de motivation', 'type' => 'file', 'accept' => [ 'pdf' ] ],
			],
		]
	);

	fixture(
		'rappel',
		[
			'label'    => 'Demande de rappel',
			'webhook'   => 'https://relais.exemple.test/essai',
			// Piège renommé : le moteur doit suivre la définition, pas la constante.
			'honeypot' => '_url_site',
			'fields'   => [
				[ 'name' => 'tel', 'label' => 'Téléphone', 'type' => 'tel', 'required' => true ],
			],
		]
	);

	/*
	 * Formulaire de qualification : le cas d'usage des conditions d'affichage.
	 *
	 * Il couvre les trois comparaisons, la dépendance d'un écran à l'autre, et la
	 * cascade — « precisions » dépend d'un champ lui-même conditionnel.
	 */
	fixture(
		'qualification',
		[
			'label'   => 'Qualification',
			'webhook'   => 'https://relais.exemple.test/essai',
			'notify'  => 'commercial@exemple.fr',
			'screens' => [
				[
					'fields' => [
						[
							'name'     => 'profil',
							'label'    => 'Vous êtes',
							'type'     => 'select',
							'required' => true,
							'options'  => [ 'Particulier', 'Entreprise', 'Association' ],
						],
						[
							'name'     => 'raison_sociale',
							'label'    => 'Raison sociale',
							'type'     => 'text',
							'required' => true,
							'showIf'   => [ 'field' => 'profil', 'equals' => 'Entreprise' ],
						],
						[
							'name'   => 'siret',
							'label'  => 'SIRET',
							'type'   => 'text',
							'showIf' => [ 'field' => 'profil', 'in' => [ 'Entreprise', 'Association' ] ],
						],
						[
							'name'   => 'precisions',
							'label'  => 'Précisez votre activité',
							'type'   => 'text',
							'showIf' => [ 'field' => 'raison_sociale', 'notEmpty' => true ],
						],
					],
				],
				[
					'fields' => [
						[ 'name' => 'rappel', 'label' => 'Être rappelé', 'type' => 'checkbox' ],
						[
							'name'     => 'creneau',
							'label'    => 'Créneau souhaité',
							'type'     => 'text',
							'required' => true,
							'showIf'   => [ 'field' => 'rappel', 'equals' => true ],
						],
					],
				],
			],
		]
	);

	// Condition impossible à évaluer : « profil » n'arrive qu'à l'écran suivant.
	fixture(
		'mauvaise-condition',
		[
			'label'   => 'Définition fautive',
			'webhook'   => 'https://relais.exemple.test/essai',
			'screens' => [
				[ 'fields' => [ [ 'name' => 'siret', 'label' => 'SIRET', 'type' => 'text', 'showIf' => [ 'field' => 'profil', 'notEmpty' => true ] ] ] ],
				[ 'fields' => [ [ 'name' => 'profil', 'label' => 'Profil', 'type' => 'text' ] ] ],
			],
		]
	);

	require_once __DIR__ . '/../anode-forms.php';

	/* --- Harnais ----------------------------------------------------- */

	$passed = 0;
	$failed = 0;

	function test( string $name, callable $fn ): void {
		global $passed, $failed;

		reset_state();

		try {
			$fn();
			$passed++;
			echo "  ✓ {$name}\n";
		} catch ( \Throwable $e ) {
			$failed++;
			echo "  ✗ {$name}\n    {$e->getMessage()}\n";
		}
	}

	function assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException(
				sprintf( '%s — attendu %s, obtenu %s', $message, var_export( $expected, true ), var_export( $actual, true ) )
			);
		}
	}

	function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	function assert_contains( string $needle, string $haystack, string $message ): void {
		if ( ! str_contains( $haystack, $needle ) ) {
			throw new \RuntimeException(
				sprintf( '%s — « %s » absent de %s', $message, $needle, var_export( $haystack, true ) )
			);
		}
	}

	/**
	 * Remet la requête à zéro : une page vue en GET, sans envoi en cours.
	 *
	 * L'état de l'envoi est un statique du plugin — sans cette remise à zéro, le
	 * refus posé par un test peuplerait le formulaire rendu par le suivant.
	 */
	function reset_requete(): void {
		$_POST                     = [];
		$_GET                      = [];
		$_FILES                    = [];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/contact/';

		unset( $_SERVER['CONTENT_LENGTH'] );

		etat_envoi( [] );
	}

	function reset_state(): void {
		$GLOBALS['anode_transients']     = [];
		$GLOBALS['anode_relais']         = [];
		$GLOBALS['anode_relais_echoue']  = false;
		$GLOBALS['anode_posts']          = [];
		$GLOBALS['anode_meta']           = [];
		$GLOBALS['anode_mails']          = [];
		$GLOBALS['anode_logs']           = [];
		$GLOBALS['anode_options']        = [ 'admin_email' => 'admin@exemple.test' ];
		$GLOBALS['anode_times']          = [];
		$GLOBALS['anode_deleted']        = [];
		$GLOBALS['anode_get_posts_args'] = [];
		$GLOBALS['anode_mail_ok']        = true;
		$GLOBALS['anode_next_id']        = 100;
		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';

		$GLOBALS['anode_attaches']                = [];
		$GLOBALS['anode_attachements_supprimes']  = [];
		$GLOBALS['anode_fichiers_supprimes']      = [];
		$GLOBALS['anode_attachement_ko']          = false;
		$GLOBALS['anode_upload_options']          = [];
		// 64 Mo : au-dessus de tout ce que les définitions demandent, pour que le
		// plafond de PHP n'entre en jeu que dans le test qui l'abaisse exprès.
		$GLOBALS['anode_max_upload']              = 64 * MB_IN_BYTES;

		// Le dossier des téléversements repart vide : un fichier laissé par le test
		// précédent ferait passer pour « rangé » ce qui n'a jamais été écrit.
		effacer_dossier( (string) $GLOBALS['anode_uploads'] );
		$GLOBALS['anode_tour']           = 1;
		$GLOBALS['anode_nocache']        = false;

		reset_requete();
	}

	/** Message d'erreur d'un champ, ou la valeur assainie. */
	function champ( array $field, mixed $value ): string {
		$resultat = sanitize_field( $value, $field );

		return $resultat instanceof \WP_Error ? 'ERREUR: ' . $resultat->get_error_message() : $resultat;
	}

	/** Envoi complet et valide du formulaire « contact ». */
	function payload( array $override = [] ): array {
		return array_merge(
			[
				'nom'      => 'Dupont',
				'courriel' => 'jean@exemple.fr',
				'canal'    => 'Recherche',
				'message'  => 'Bonjour, je souhaite un devis.',
				'rgpd'     => true,
			],
			$override
		);
	}

	function submit( array $fields, string $form = 'contact' ): mixed {
		return handle( new \WP_REST_Request( [ 'form' => $form, 'fields' => $fields, 'page' => '/contact/' ] ) );
	}

	/** Un vrai PDF minimal : `finfo` doit y reconnaître « application/pdf ». */
	const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

	/**
	 * Écrit un fichier temporaire et rend l'entrée `$_FILES` correspondante.
	 *
	 * Le fichier est réel : `contenu_conforme()` interroge `finfo`, et une
	 * doublure de contenu ne prouverait rien du contrôle qui compte.
	 */
	function piece( string $nom, string $contenu = PDF, int $erreur = UPLOAD_ERR_OK, ?int $taille = null ): array {
		$tmp = (string) tempnam( sys_get_temp_dir(), 'anode-piece-' );

		file_put_contents( $tmp, $contenu );

		return [
			'name'     => $nom,
			'tmp_name' => $tmp,
			// La taille annoncée par le client n'est jamais crue sur parole ailleurs :
			// le paramètre existe pour éprouver le refus sans écrire deux mégaoctets.
			'size'     => $taille ?? strlen( $contenu ),
			'error'    => $erreur,
			'type'     => 'application/octet-stream',
		];
	}

	/** Envoi multipart du formulaire « candidature ». */
	function envoyer( array $fields, array $fichiers, string $form = 'candidature' ): mixed {
		return handle(
			new \WP_REST_Request(
				[ 'form' => $form, 'fields' => $fields, 'page' => '/candidature/' ],
				[ 'content-type' => 'multipart/form-data; boundary=--x' ],
				$fichiers
			)
		);
	}

	/** Candidature complète et valide, hors pièces jointes. */
	function candidat( array $override = [] ): array {
		return array_merge( [ 'nom' => 'Dupont', 'courriel' => 'jean@exemple.fr' ], $override );
	}

	/** Message d'erreur renvoyé pour un champ donné. */
	function erreur_de( mixed $refus, string $champ ): string {
		return $refus instanceof \WP_Error ? (string) ( $refus->get_error_data()['fields'][ $champ ] ?? '' ) : '';
	}

	/**
	 * Pièces jointes présentes sous le dossier des téléversements.
	 *
	 * Les gardes du dossier — `.htaccess`, `index.php`, `temoin-anode.pdf` — sont écartées : elles
	 * restent en place une fois posées, et les compter ferait échouer tout test
	 * qui vérifie qu'aucun fichier n'a été laissé derrière un refus.
	 */
	function fichiers_ranges(): array {
		$trouves = [];
		$pile    = [ (string) $GLOBALS['anode_uploads'] ];

		while ( $pile ) {
			foreach ( (array) glob( array_pop( $pile ) . '/*' ) as $entree ) {
				$entree = (string) $entree;

				if ( is_dir( $entree ) ) {
					$pile[] = $entree;

					continue;
				}

				// `temoin-anode.pdf` en fait partie : c'est le fichier que `wp_health`
				// demande au serveur pour mesurer si le dossier est servi.
				if ( in_array( basename( $entree ), [ '.htaccess', 'index.php', 'temoin-anode.pdf' ], true ) ) {
					continue;
				}

				$trouves[] = $entree;
			}
		}

		return $trouves;
	}

	/* --- Validation par type ----------------------------------------- */

	echo "\nValidation par type\n";

	test(
		'un champ obligatoire vide est refusé, un champ facultatif vide rend une chaîne vide',
		function (): void {
			assert_contains(
				'« Nom » est obligatoire.',
				champ( [ 'name' => 'nom', 'label' => 'Nom', 'required' => true ], '   ' ),
				'obligatoire'
			);

			assert_same( '', champ( [ 'name' => 'nom', 'label' => 'Nom' ], '' ), 'facultatif' );
		}
	);

	test(
		'une adresse e‑mail est contrôlée, pas seulement assainie',
		function (): void {
			$field = [ 'name' => 'courriel', 'label' => 'Adresse', 'type' => 'email' ];

			assert_same( 'jean@exemple.fr', champ( $field, ' jean@exemple.fr ' ), 'valide' );
			assert_contains( 'ERREUR:', champ( $field, 'jean(at)exemple' ), 'invalide' );
		}
	);

	test(
		'un numéro de téléphone accepte les séparateurs usuels et refuse le texte',
		function (): void {
			$field = [ 'name' => 'tel', 'label' => 'Téléphone', 'type' => 'tel' ];

			assert_same( '+33 5 49 00 00 00', champ( $field, '+33 5 49 00 00 00' ), 'valide' );
			assert_contains( 'ERREUR:', champ( $field, 'appelez-moi' ), 'texte' );
			assert_contains( 'ERREUR:', champ( $field, '12345' ), 'trop court' );
		}
	);

	test(
		'une adresse web hors http(s) est refusée',
		function (): void {
			$field = [ 'name' => 'site', 'label' => 'Site', 'type' => 'url' ];

			assert_same( 'https://exemple.fr/a', champ( $field, 'https://exemple.fr/a' ), 'https' );
			assert_contains( 'ERREUR:', champ( $field, 'ftp://exemple.fr' ), 'ftp' );
			assert_contains( 'ERREUR:', champ( $field, 'exemple.fr' ), 'sans protocole' );
		}
	);

	test(
		'une date est contrôlée sur sa forme et son existence',
		function (): void {
			$field = [ 'name' => 'jour', 'label' => 'Date', 'type' => 'date' ];

			assert_same( '2026-02-28', champ( $field, '2026-02-28' ), 'valide' );
			assert_contains( 'ERREUR:', champ( $field, '28/02/2026' ), 'format' );
			// 2026 n'est pas bissextile : la forme est bonne, le jour n'existe pas.
			assert_contains( 'ERREUR:', champ( $field, '2026-02-29' ), 'inexistante' );
		}
	);

	test(
		'un nombre respecte ses bornes',
		function (): void {
			$field = [ 'name' => 'personnes', 'label' => 'Personnes', 'type' => 'number', 'min' => 2, 'max' => 20 ];

			assert_same( '8', champ( $field, '8' ), 'dans les bornes' );
			assert_contains( 'ERREUR:', champ( $field, '1' ), 'sous le minimum' );
			assert_contains( 'ERREUR:', champ( $field, '99' ), 'au-dessus du maximum' );
			assert_contains( 'ERREUR:', champ( $field, 'huit' ), 'pas un nombre' );
		}
	);

	test(
		'une liste refuse toute valeur hors options, en chaînes comme en objets',
		function (): void {
			$chaines = [ 'name' => 'sujet', 'label' => 'Sujet', 'type' => 'select', 'options' => [ 'Location', 'Autre' ] ];
			$objets  = [
				'name'    => 'canal',
				'label'   => 'Canal',
				'type'    => 'radio',
				'options' => [ [ 'value' => 'seo', 'label' => 'Recherche' ], [ 'value' => 'ami', 'label' => 'Un ami' ] ],
			];

			assert_same( 'Location', champ( $chaines, 'Location' ), 'option déclarée' );
			assert_contains( 'ERREUR:', champ( $chaines, 'Autre chose' ), 'hors liste' );
			assert_same( 'seo', champ( $objets, 'seo' ), 'valeur d’un objet' );
			// L'étiquette n'est pas la valeur : l'envoyer est un envoi forgé.
			assert_contains( 'ERREUR:', champ( $objets, 'Recherche' ), 'étiquette au lieu de la valeur' );
		}
	);

	test(
		'une case à cocher ne conserve que le fait d’avoir été cochée',
		function (): void {
			$field = [ 'name' => 'rgpd', 'label' => 'Accord', 'type' => 'checkbox', 'required' => true ];

			assert_same( '1', champ( $field, true ), 'cochée' );
			assert_same( '1', champ( $field, 'oui' ), 'valeur quelconque' );
			assert_contains( 'ERREUR:', champ( $field, false ), 'décochée alors qu’obligatoire' );
		}
	);

	test(
		'une valeur composée est refusée, jamais aplatie',
		function (): void {
			// Sans ce garde-fou, `(string) []` émettrait un avis et enregistrerait
			// « Array » — une trace fausse pour un envoi forgé.
			assert_contains( 'ERREUR:', champ( [ 'name' => 'nom', 'label' => 'Nom' ], [ 'a', 'b' ] ), 'tableau' );
		}
	);

	test(
		'un champ libre est plafonné à MAX_LENGTH sans déclaration',
		function (): void {
			$field = [ 'name' => 'message', 'label' => 'Message', 'type' => 'textarea' ];

			assert_same( str_repeat( 'a', MAX_LENGTH ), champ( $field, str_repeat( 'a', MAX_LENGTH ) ), 'à la limite' );
			assert_contains( 'ERREUR:', champ( $field, str_repeat( 'a', MAX_LENGTH + 1 ) ), 'au-delà' );
		}
	);

	/* --- minLength et pattern ---------------------------------------- */

	echo "\nminLength et pattern côté serveur\n";

	test(
		'minLength est appliqué par le serveur, pas seulement par le navigateur',
		function (): void {
			$field = [ 'name' => 'nom', 'label' => 'Nom', 'type' => 'text', 'minLength' => 3 ];

			assert_same( 'Ali', champ( $field, 'Ali' ), 'à la limite' );
			assert_contains( '3 caractères au minimum', champ( $field, 'Al' ), 'trop court' );
		}
	);

	test(
		'pattern est appliqué par le serveur, et ancré aux deux bouts',
		function (): void {
			$field = [ 'name' => 'cp', 'label' => 'Code postal', 'type' => 'text', 'pattern' => '\\d{5}' ];

			assert_same( '79000', champ( $field, '79000' ), 'conforme' );
			assert_contains( 'ERREUR:', champ( $field, '7900' ), 'trop court' );
			// Sans ancres, « xx79000yy » passerait : c'est tout l'objet des ancres.
			assert_contains( 'ERREUR:', champ( $field, 'xx79000yy' ), 'motif non ancré' );
		}
	);

	test(
		'patternMessage rédigé remplace le message générique',
		function (): void {
			$field = [
				'name'           => 'cp',
				'label'          => 'Code postal',
				'pattern'        => '\\d{5}',
				'patternMessage' => 'Un code postal compte cinq chiffres.',
			];

			assert_same( 'ERREUR: Un code postal compte cinq chiffres.', champ( $field, 'abc' ), 'message sur mesure' );
		}
	);

	test(
		'un motif illisible est journalisé, et n’interdit pas la demande',
		function (): void {
			// Une expression fautive dans un fichier de définition refuserait
			// sinon toutes les demandes du site, sans que rien ne le dise.
			assert_same(
				'Bonjour',
				champ( [ 'name' => 'nom', 'label' => 'Nom', 'pattern' => '([0-9' ], 'Bonjour' ),
				'contrainte ignorée'
			);

			assert_contains( 'motif de champ illisible', implode( "\n", $GLOBALS['anode_logs'] ), 'journal' );
		}
	);

	test(
		'minLength et pattern s’appliquent aussi aux types contrôlés par leur forme',
		function (): void {
			// Le contrôle est fait avant l'aiguillage par type : sans cela, un
			// courriel ou un téléphone sortirait de la fonction avant de le voir.
			$field = [ 'name' => 'tel', 'label' => 'Téléphone', 'type' => 'tel', 'pattern' => '0[1-9](\\d{8})' ];

			assert_same( '0549000000', champ( $field, '0549000000' ), 'conforme' );
			assert_contains( 'ERREUR:', champ( $field, '+33 5 49 00 00 00' ), 'hors motif' );
		}
	);

	/* --- Piège à robots ---------------------------------------------- */

	echo "\nChoix multiple\n";

	/** Un champ `select` déclaré à choix multiple. */
	function multi( array $sup = [] ): array {
		return array_merge(
			[
				'name'    => 'technos',
				'label'   => 'Technologies',
				'type'    => 'select',
				'multiple' => true,
				'options' => [ 'WordPress', 'Bricks', 'n8n' ],
			],
			$sup
		);
	}

	test(
		'plusieurs valeurs sont retenues, dans l’ordre reçu',
		function (): void {
			assert_same( [ 'WordPress', 'n8n' ], sanitize_field( [ 'WordPress', 'n8n' ], multi() ), 'valeurs retenues' );
		}
	);

	test(
		'une seule valeur hors liste fait refuser l’ensemble',
		function (): void {
			/*
			 * Sinon il suffirait d'en glisser une parmi des valeurs valables :
			 * le champ serait accepté, et la valeur inventée partirait au CRM.
			 */
			$refus = sanitize_field( [ 'WordPress', 'Intrus' ], multi() );

			assert_true( $refus instanceof \WP_Error, 'refusé' );
			assert_contains( 'hors liste', $refus->get_error_message(), 'motif' );
		}
	);

	test(
		'les doublons d’un envoi forgé sont écartés',
		function (): void {
			// Ils fausseraient un décompte en aval, sans qu'aucune interface ne
			// permette de les produire.
			assert_same( [ 'Bricks' ], sanitize_field( [ 'Bricks', 'Bricks' ], multi() ), 'doublon écarté' );
		}
	);

	test(
		'les valeurs vides ne comptent pas',
		function (): void {
			assert_same( [ 'n8n' ], sanitize_field( [ '', 'n8n', '  ' ], multi() ), 'vides écartées' );
		}
	);

	test(
		'un choix multiple vide et obligatoire est refusé',
		function (): void {
			$refus = sanitize_field( [], multi( [ 'required' => true ] ) );

			assert_true( $refus instanceof \WP_Error, 'refusé' );
			assert_same( 'required', $refus->get_error_code(), 'code' );
		}
	);

	test(
		'un choix multiple vide et facultatif rend une liste vide',
		function (): void {
			assert_same( [], sanitize_field( [], multi() ), 'liste vide' );
		}
	);

	test(
		'un tableau sur un champ simple reste refusé',
		function (): void {
			/*
			 * La garde ne s'ouvre que sur `multiple` déclaré. Sans cela, un envoi
			 * forgé passerait un tableau sur n'importe quel champ et traverserait
			 * les `trim` et `strlen` qui suivent, avec des avertissements PHP.
			 */
			$refus = sanitize_field( [ 'a', 'b' ], [ 'name' => 'nom', 'label' => 'Nom', 'type' => 'text' ] );

			assert_true( $refus instanceof \WP_Error, 'refusé' );
			assert_contains( 'inattendue', $refus->get_error_message(), 'motif' );
		}
	);

	test(
		'une liste de listes est refusée, jamais aplatie',
		function (): void {
			// Aplatir inventerait des valeurs que personne n'a choisies.
			$refus = sanitize_field( [ 'WordPress', [ 'n8n' ] ], multi() );

			assert_true( $refus instanceof \WP_Error, 'refusé' );
		}
	);

	test(
		'« multiple » est servi au navigateur',
		function (): void {
			/*
			 * Sans cette clé dans la liste blanche, le navigateur rend un select
			 * simple : une seule valeur part, et les autres réponses sont perdues
			 * sans un mot. Mesuré sur un site en ligne avant correction.
			 */
			$public = champ_public( multi() );

			assert_same( true, $public['multiple'] ?? null, 'clé servie' );
		}
	);

	echo "\nPiège à robots\n";

	test(
		'un piège rempli rend un succès de façade et n’enregistre rien',
		function (): void {
			$reponse = submit( payload( [ HONEYPOT => 'https://spam.example' ] ) );

			assert_true( $reponse instanceof \WP_REST_Response, 'réponse' );
			assert_same( true, $reponse->get_data()['ok'], 'succès annoncé' );
			// Le rejet est silencieux : un robot informé s'adapterait.
			assert_same( 0, relais_partis(), 'aucun relais parti' );
			assert_same( 0, count( $GLOBALS['anode_mails'] ), 'aucune notification' );
		}
	);

	test(
		'un piège vide laisse passer la demande',
		function (): void {
			$reponse = submit( payload( [ HONEYPOT => '' ] ) );

			assert_true( $reponse instanceof \WP_REST_Response, 'réponse' );
			assert_same( 1, relais_partis(), 'demande relayée' );
		}
	);

	test(
		'le piège suit le nom déclaré par la définition, pas la constante',
		function (): void {
			$reponse = submit( [ 'tel' => '0549000000', '_url_site' => 'spam' ], 'rappel' );

			assert_same( true, $reponse->get_data()['ok'], 'succès de façade' );
			assert_same( 0, count( $GLOBALS['anode_posts'] ), 'rien enregistré' );

			// Et le nom par défaut, lui, devient un champ inattendu sur ce
			// formulaire — donc un envoi forgé.
			$refus = submit( [ 'tel' => '0549000000', HONEYPOT => 'spam' ], 'rappel' );

			assert_same( 'anode_form_unknown_field', $refus->get_error_code(), 'champ inattendu' );
		}
	);

	test(
		'un champ non déclaré fait refuser tout l’envoi',
		function (): void {
			$refus = submit( payload( [ 'role_admin' => '1' ] ) );

			assert_true( $refus instanceof \WP_Error, 'refus' );
			assert_same( 'anode_form_unknown_field', $refus->get_error_code(), 'code' );
		}
	);

	test(
		'les erreurs de validation reviennent champ par champ',
		function (): void {
			$refus = submit( payload( [ 'courriel' => 'pas-une-adresse', 'nom' => 'D' ] ) );

			assert_same( 'anode_form_invalid', $refus->get_error_code(), 'code' );
			assert_true( isset( $refus->get_error_data()['fields']['courriel'] ), 'courriel signalé' );
			assert_true( isset( $refus->get_error_data()['fields']['nom'] ), 'nom trop court signalé' );
		}
	);

	/* --- Champs conditionnels : réception ---------------------------- */

	echo "\nChamps conditionnels — réception\n";

	/**
	 * Valeurs parties dans le dernier relais.
	 *
	 * Le dernier, et non le premier : plusieurs envois se suivent dans un même
	 * test, et un envoi refusé n'en déclenche aucun.
	 *
	 * Ce sont les seules valeurs observables : rien n'est enregistré, la seule
	 * trace d'une demande acceptée est ce qui est parti vers le webhook.
	 */
	function valeurs_retenues(): array {
		$dernier = end( $GLOBALS['anode_relais'] );

		return (array) ( $dernier['body']['fields'] ?? [] );
	}

	/** Nombre de relais partis depuis le début du test. */
	function relais_partis(): int {
		return count( $GLOBALS['anode_relais'] );
	}

	test(
		'un champ masqué reçu quand même est ignoré, jamais enregistré',
		function (): void {
			/*
			 * C'est le point décisif : côté navigateur la condition ne fait que
			 * montrer ou cacher, et un POST direct ne passe par aucun écran. Sans
			 * réévaluation ici, la réponse à une question jamais posée entrait en
			 * base comme si elle l'avait été.
			 */
			$reponse = submit( [ 'profil' => 'Particulier', 'raison_sociale' => 'Anode SAS', 'siret' => '80012345600017' ], 'qualification' );

			assert_true( $reponse instanceof \WP_REST_Response, 'envoi accepté' );

			$valeurs = valeurs_retenues();

			assert_same( 'Particulier', $valeurs['profil'] ?? '', 'réponse posée conservée' );
			assert_true( ! array_key_exists( 'raison_sociale', $valeurs ), 'champ masqué écarté' );
			assert_true( ! array_key_exists( 'siret', $valeurs ), 'second champ masqué écarté' );
		}
	);

	test(
		'un champ obligatoire dont la condition est remplie est exigé',
		function (): void {
			// Le miroir du test précédent : masquer n'est pas dispenser. Sans la
			// réévaluation, il aurait suffi de ne pas envoyer le champ.
			$refus = submit( [ 'profil' => 'Entreprise' ], 'qualification' );

			assert_same( 'anode_form_invalid', $refus->get_error_code(), 'code' );
			assert_true( isset( $refus->get_error_data()['fields']['raison_sociale'] ), 'champ exigé' );
			assert_contains( 'obligatoire', $refus->get_error_data()['fields']['raison_sociale'], 'message' );
		}
	);

	test(
		'la condition remplie et le champ rempli, la demande passe',
		function (): void {
			$reponse = submit( [ 'profil' => 'Entreprise', 'raison_sociale' => 'Anode SAS' ], 'qualification' );

			assert_true( $reponse instanceof \WP_REST_Response, 'envoi accepté' );
			assert_same( 'Anode SAS', valeurs_retenues()['raison_sociale'] ?? '', 'réponse conservée' );
		}
	);

	test(
		'« in » ouvre le champ pour chacune des valeurs déclarées',
		function (): void {
			submit( [ 'profil' => 'Association', 'siret' => '80012345600017' ], 'qualification' );

			$valeurs = valeurs_retenues();

			assert_same( '80012345600017', $valeurs['siret'] ?? '', 'seconde valeur de la liste' );
			// « raison_sociale » n'est ouvert que par « Entreprise ».
			assert_true( ! array_key_exists( 'raison_sociale', $valeurs ), 'champ resté fermé' );
		}
	);

	test(
		'le masquage se propage : un champ qui dépend d’un champ masqué l’est aussi',
		function (): void {
			// « precisions » dépend de « raison_sociale », lui-même fermé faute
			// d'« Entreprise ». Aucune cascade n'est écrite : elle découle du fait
			// qu'un champ masqué ne laisse aucune réponse derrière lui.
			submit( [ 'profil' => 'Particulier', 'precisions' => 'valeur forgée' ], 'qualification' );

			assert_true( ! array_key_exists( 'precisions', valeurs_retenues() ), 'champ en cascade écarté' );

			submit( [ 'profil' => 'Entreprise', 'raison_sociale' => 'Anode SAS', 'precisions' => 'Conseil' ], 'qualification' );

			assert_same( 'Conseil', valeurs_retenues()['precisions'] ?? '', 'chaîne complète, champ ouvert' );
		}
	);

	test(
		'une case cochée déclenche « equals: true », d’un écran à l’autre',
		function (): void {
			$refus = submit( [ 'profil' => 'Particulier', 'rappel' => true ], 'qualification' );

			assert_same( 'anode_form_invalid', $refus->get_error_code(), 'créneau exigé' );
			assert_true( isset( $refus->get_error_data()['fields']['creneau'] ), 'champ nommé' );

			$reponse = submit( [ 'profil' => 'Particulier', 'rappel' => true, 'creneau' => 'Mardi matin' ], 'qualification' );

			assert_true( $reponse instanceof \WP_REST_Response, 'envoi accepté' );
			assert_same( 'Mardi matin', valeurs_retenues()['creneau'] ?? '', 'réponse conservée' );

			// Case décochée : la question n'est pas posée, donc pas exigée.
			$sans = submit( [ 'profil' => 'Particulier', 'rappel' => false ], 'qualification' );

			assert_true( $sans instanceof \WP_REST_Response, 'envoi accepté sans le créneau' );
		}
	);

	test(
		'la comparaison normalise des deux côtés, comme le navigateur',
		function (): void {
			// Sans normalisation commune, « Location  » saisi n'est plus
			// « Location » déclaré : le navigateur montre le champ, le serveur en
			// jette la réponse, et rien ne le signale.
			assert_true( condition_remplie( [ 'field' => 'p', 'equals' => 'Location' ], [ 'p' => ' Location ' ] ), 'espaces' );
			assert_true( condition_remplie( [ 'field' => 'c', 'equals' => true ], [ 'c' => '1' ] ), 'case cochée' );
			assert_true( ! condition_remplie( [ 'field' => 'c', 'equals' => true ], [ 'c' => '' ] ), 'case décochée' );
			assert_true( condition_remplie( [ 'field' => 'p', 'in' => [ 'A', 'B' ] ], [ 'p' => 'B' ] ), 'liste' );
			assert_true( ! condition_remplie( [ 'field' => 'p', 'in' => [ 'A', 'B' ] ], [ 'p' => 'C' ] ), 'hors liste' );
			assert_true( condition_remplie( null, [] ), 'aucune condition' );
		}
	);

	/* --- Champs conditionnels : définition ---------------------------- */

	echo "\nChamps conditionnels — définition\n";

	test(
		'une condition qui remonte plus loin que la réponse est refusée',
		function (): void {
			$apres = condition_errors(
				[
					'screens' => [
						[ 'fields' => [ [ 'name' => 'siret', 'showIf' => [ 'field' => 'profil', 'notEmpty' => true ] ] ] ],
						[ 'fields' => [ [ 'name' => 'profil', 'type' => 'text' ] ] ],
					],
				]
			);

			assert_same( 1, count( $apres ), 'une faute pour l’écran suivant' );
			assert_contains( 'n’est pas déclaré avant lui', $apres[0], 'message explicite' );

			$inconnu = condition_errors(
				[
					'fields' => [
						[ 'name' => 'a', 'type' => 'text' ],
						[ 'name' => 'b', 'showIf' => [ 'field' => 'inexistant', 'equals' => 'x' ] ],
					],
				]
			);

			assert_contains( '« inexistant »', $inconnu[0], 'champ absent nommé' );

			// Un champ ne peut pas se conditionner à sa propre réponse.
			assert_same(
				1,
				count( condition_errors( [ 'fields' => [ [ 'name' => 'a', 'showIf' => [ 'field' => 'a', 'notEmpty' => true ] ] ] ] ) ),
				'renvoi à soi-même'
			);

			// Le cas légitime : même écran, déclaré plus haut.
			assert_same(
				[],
				condition_errors(
					[
						'fields' => [
							[ 'name' => 'a', 'type' => 'text' ],
							[ 'name' => 'b', 'showIf' => [ 'field' => 'a', 'equals' => 'x' ] ],
						],
					]
				),
				'même écran, posé avant'
			);

			assert_same( [], condition_errors( definition( 'qualification' ) ?? [] ), 'la définition d’essai tient' );
		}
	);

	test(
		'une condition sans comparaison, ou avec deux, est refusée',
		function (): void {
			$sans = condition_errors( [ 'fields' => [
				[ 'name' => 'a', 'type' => 'text' ],
				[ 'name' => 'b', 'showIf' => [ 'field' => 'a' ] ],
			] ] );

			assert_contains( 'attend une comparaison', $sans[0], 'aucune comparaison' );

			// Deux comparaisons, c'est déjà un « et » implicite : la clé deviendrait
			// un langage, à interpréter deux fois sans jamais pouvoir le prouver.
			$deux = condition_errors( [ 'fields' => [
				[ 'name' => 'a', 'type' => 'text' ],
				[ 'name' => 'b', 'showIf' => [ 'field' => 'a', 'equals' => 'x', 'notEmpty' => true ] ],
			] ] );

			assert_contains( 'une seule comparaison', $deux[0], 'deux comparaisons' );

			$formes = condition_errors( [ 'fields' => [
				[ 'name' => 'a', 'type' => 'text' ],
				[ 'name' => 'b', 'showIf' => 'a=x' ],
				[ 'name' => 'c', 'showIf' => [ 'field' => 'a', 'in' => [] ] ],
				[ 'name' => 'd', 'showIf' => [ 'field' => 'a', 'equals' => [ 'x' ] ] ],
			] ] );

			assert_same( 3, count( $formes ), 'trois formes refusées' );
			assert_contains( 'attend un objet', $formes[0], 'showIf qui n’est pas un objet' );
			assert_contains( 'liste de valeurs, non vide', $formes[1], 'in vide' );
			assert_contains( 'valeur simple', $formes[2], 'equals composé' );
		}
	);

	test(
		'la route publique refuse une définition fautive au lieu de la servir',
		function (): void {
			$refus = describe( new \WP_REST_Request( [ 'name' => 'mauvaise-condition' ] ) );

			assert_true( $refus instanceof \WP_Error, 'refus' );
			assert_same( 'anode_form_definition', $refus->get_error_code(), 'code' );
			assert_same( 500, $refus->get_error_data()['status'], 'statut' );
			assert_contains( 'n’est pas déclaré avant lui', $refus->get_error_message(), 'cause nommée' );
			// Une faute de définition doit se voir dans le journal du site, et pas
			// seulement dans la réponse d'une requête que personne ne regarde.
			assert_contains( 'condition d’affichage invalide', implode( "\n", $GLOBALS['anode_logs'] ), 'journal' );
		}
	);

	test(
		'un envoi sur une définition fautive n’est pas enregistré',
		function (): void {
			$refus = submit( [ 'profil' => 'x' ], 'mauvaise-condition' );

			assert_same( 'anode_form_definition', $refus->get_error_code(), 'code' );
			assert_same( 0, count( $GLOBALS['anode_posts'] ), 'rien enregistré' );
		}
	);

	test(
		'« showIf » est servi au navigateur, sinon la condition n’existe que côté serveur',
		function (): void {
			// Absente de la liste blanche, la clé disparaîtrait de la définition
			// servie : le champ serait affiché à tout le monde, et sa réponse
			// écartée en silence.
			$data = describe( new \WP_REST_Request( [ 'name' => 'qualification' ] ) )->get_data();

			assert_same(
				[ 'field' => 'profil', 'equals' => 'Entreprise' ],
				$data['screens'][0]['fields'][1]['showIf'] ?? [],
				'condition servie'
			);
			assert_same(
				[ 'field' => 'rappel', 'equals' => true ],
				$data['screens'][1]['fields'][1]['showIf'] ?? [],
				'condition d’un écran à l’autre'
			);
		}
	);

	/* --- Adresse de réponse ------------------------------------------ */

	echo "\nAdresse de réponse\n";



	test(
		'le premier champ de type email l’emporte',
		function (): void {
			$definition = [
				'fields' => [
					[ 'name' => 'societe', 'label' => 'Société', 'type' => 'text' ],
					[ 'name' => 'contact_pro', 'label' => 'Courriel professionnel', 'type' => 'email' ],
					[ 'name' => 'facturation', 'label' => 'Courriel de facturation', 'type' => 'email' ],
				],
			];

			assert_same(
				'pro@exemple.fr',
				reply_to( $definition, [ 'societe' => 'Anode', 'contact_pro' => 'pro@exemple.fr', 'facturation' => 'compta@exemple.fr' ] ),
				'premier champ email'
			);

			// Un champ email vide n'est pas une adresse de réponse : on continue.
			assert_same(
				'compta@exemple.fr',
				reply_to( $definition, [ 'contact_pro' => '', 'facturation' => 'compta@exemple.fr' ] ),
				'premier champ email rempli'
			);

			assert_same( '', reply_to( $definition, [ 'societe' => 'Anode' ] ), 'aucun' );
		}
	);

	/* --- Notification ------------------------------------------------ */

	echo "\nRoute publique\n";

	test(
		'seules les clés inscrites en liste blanche sortent',
		function (): void {
			$data = describe( new \WP_REST_Request( [ 'name' => 'contact' ] ) )->get_data();

			assert_true( ! isset( $data['notify'] ), 'adresse de notification retenue' );
			assert_true( ! isset( $data['retention'] ), 'durée de conservation retenue' );
			// C'est le cas que la liste noire ne couvrait pas : une clé ajoutée
			// après coup sortait par défaut.
			assert_true( ! isset( $data['jeton_interne'] ), 'clé inconnue retenue' );

			assert_same( 'Nous contacter', $data['label'], 'label servi' );
			assert_same( 'contact', $data['id'], 'identifiant servi' );
			assert_same( HONEYPOT, $data['honeypot'], 'nom du piège servi' );
			assert_same( 'Envoyer la demande', $data['labels']['submit'], 'libellés servis' );
			assert_same( 2, count( $data['screens'] ), 'écrans servis' );
		}
	);

	test(
		'le plafond de longueur par défaut est servi au navigateur',
		function (): void {
			$data = describe( new \WP_REST_Request( [ 'name' => 'contact' ] ) )->get_data();

			$nom     = $data['screens'][0]['fields'][0];
			$message = $data['screens'][1]['fields'][1];
			$canal   = $data['screens'][1]['fields'][0];

			assert_same( MAX_LENGTH, $nom['maxLength'], 'champ texte' );
			assert_same( MAX_LENGTH, $message['maxLength'], 'zone de texte' );
			// Un groupe de boutons n'est pas plafonné par le serveur : lui poser
			// un maxLength annoncerait une contrainte que rien n'applique.
			assert_true( ! isset( $canal['maxLength'] ), 'liste épargnée' );

			$plat = describe( new \WP_REST_Request( [ 'name' => 'rappel' ] ) )->get_data();

			assert_true( ! isset( $plat['fields'][0]['maxLength'] ), 'téléphone épargné' );
		}
	);

	test(
		'un maxLength déclaré n’est pas écrasé',
		function (): void {
			$complete = with_default_max_length(
				[ 'fields' => [ [ 'name' => 'note', 'type' => 'textarea', 'maxLength' => 300 ] ] ]
			);

			assert_same( 300, $complete['fields'][0]['maxLength'], 'valeur du projet conservée' );
		}
	);

	test(
		'un formulaire inconnu répond 404',
		function (): void {
			$refus = describe( new \WP_REST_Request( [ 'name' => 'inexistant' ] ) );

			assert_same( 'anode_form_unknown', $refus->get_error_code(), 'code' );
			assert_same( 404, $refus->get_error_data()['status'], 'statut' );
		}
	);

	/* --- Limitation de rythme ---------------------------------------- */

	echo "\nLimitation de rythme\n";

	test(
		'l’adresse vient d’anode-hardening quand l’extension est là',
		function (): void {
			// REMOTE_ADDR vaut 10.0.0.1 ; derrière un proxy de confiance, c'est
			// l'adresse du visiteur qui compte, et elle seule le sait.
			check_rate_limit();

			assert_true(
				isset( $GLOBALS['anode_transients'][ 'anode_form_' . md5( '203.0.113.7' ) ] ),
				'compteur indexé sur l’adresse du visiteur'
			);
			assert_true(
				! isset( $GLOBALS['anode_transients'][ 'anode_form_' . md5( '10.0.0.1' ) ] ),
				'pas sur celle du proxy'
			);
		}
	);

	test(
		'passé le quota, l’envoi est refusé en 429',
		function (): void {
			for ( $i = 0; $i < RATE_LIMIT; $i++ ) {
				assert_same( true, check_rate_limit(), 'envoi ' . $i );
			}

			$refus = check_rate_limit();

			assert_true( $refus instanceof \WP_Error, 'refus' );
			assert_same( 429, $refus->get_error_data()['status'], 'statut' );
		}
	);

	/* --- Purge ------------------------------------------------------- */

	echo "\nLecture de la définition\n";

	test(
		'fields_of aplatit les écrans et la forme sans écran',
		function (): void {
			$champs = fields_of( definition( 'contact' ) ?? [] );

			assert_same(
				[ 'nom', 'courriel', 'canal', 'message', 'rgpd' ],
				array_keys( $champs ),
				'ordre des champs, écrans confondus'
			);

			assert_same( [ 'tel' ], array_keys( fields_of( definition( 'rappel' ) ?? [] ) ), 'forme à plat' );
		}
	);

	test(
		'is_free_text ne retient que les champs plafonnés par sanitize_field',
		function (): void {
			foreach ( [ 'text', 'textarea', 'hidden', 'password' ] as $type ) {
				assert_true( is_free_text( $type ), $type . ' est un champ libre' );
			}

			// `file` en fait partie : un plafond de caractères sur une pièce jointe
			// annoncerait au navigateur une contrainte que rien n'applique.
			foreach ( [ 'email', 'url', 'tel', 'select', 'radio', 'checkbox', 'date', 'number', 'file' ] as $type ) {
				assert_true( ! is_free_text( $type ), $type . ' sort avant le plafond' );
			}
		}
	);

	/* --- Repli sans JavaScript --------------------------------------- */

	echo "\nRepli sans JavaScript — ce que la page contient\n";

	/** Poste le formulaire de repli comme le ferait un navigateur. */
	function poster( array $champs, string $form = 'contact', ?string $nonce = null ): string {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array_merge(
			[
				'anode_form'   => $form,
				'_anode_nonce' => $nonce ?? wp_create_nonce( action_nonce( $form ) ),
			],
			$champs
		);

		return intercepter_envoi();
	}

	/** Envoi complet et valide de « contact », tel qu'un navigateur le poste. */
	function poste_valide( array $override = [] ): array {
		return array_merge(
			[
				'nom'      => 'Dupont',
				'courriel' => 'jean@exemple.fr',
				'canal'    => 'Recherche',
				'message'  => 'Bonjour, je souhaite un devis.',
				'rgpd'     => '1',
			],
			$override
		);
	}

	test(
		'le bloc rend un formulaire complet, tous les écrans dépliés en un seul',
		function (): void {
			// C'est le défaut corrigé : sans JavaScript, le bloc était vide, et la
			// demande n'était pas perdue en route — elle n'était jamais écrite.
			$html = repli_html( 'contact' );

			assert_contains( '<form class="c-form__form" method="post"', $html, 'formulaire posté classiquement' );
			assert_contains( 'name="anode_form" value="contact"', $html, 'formulaire nommé' );

			foreach ( [ 'nom', 'courriel', 'canal', 'message', 'rgpd' ] as $champ ) {
				assert_contains( 'data-field="' . $champ . '"', $html, 'champ ' . $champ );
			}

			// Les deux écrans sont là, mais il n'y a qu'un bouton : un parcours en
			// étapes supposerait de retenir les réponses entre deux pages.
			assert_contains( 'Vos coordonnées', $html, 'titre du premier écran' );
			assert_contains( 'Votre demande', $html, 'titre du second écran' );
			assert_same( 1, substr_count( $html, 'type="submit"' ), 'un seul bouton d’envoi' );
			assert_contains( 'Envoyer la demande', $html, 'libellé de la définition' );
		}
	);

	test(
		'chaque champ garde les liaisons d’accessibilité du moteur',
		function (): void {
			$html = repli_html( 'contact' );

			assert_contains( '<label for="contact-nom" class="c-form__label">', $html, 'étiquette liée au champ' );
			assert_contains( 'id="contact-nom"', $html, 'identifiant du champ' );
			assert_contains( 'aria-describedby="contact-nom-error"', $html, 'conteneur d’erreur désigné' );
			assert_contains( 'aria-required="true"', $html, 'champ obligatoire annoncé' );

			// Un groupe de boutons est nommé par un texte, pas par un `for` qui
			// pointerait un identifiant inexistant.
			assert_contains( 'role="radiogroup" aria-labelledby="contact-canal-label"', $html, 'groupe nommé' );
			assert_contains( 'id="contact-canal-label"', $html, 'intitulé du groupe' );

			// L'aide et son lien : c'est ce qui porte la politique de
			// confidentialité sous la case de consentement.
			assert_contains( 'id="contact-rgpd-help"', $html, 'aide reliée' );
			assert_contains( 'href="/confidentialite/"', $html, 'lien de l’aide' );
		}
	);

	test(
		'le piège à robots et le nonce sont posés',
		function (): void {
			$html = repli_html( 'contact' );

			assert_contains( 'class="c-form__trap" aria-hidden="true"', $html, 'piège hors de l’arbre d’accessibilité' );
			assert_contains( 'name="_website"', $html, 'champ-piège' );
			assert_contains( 'tabindex="-1"', $html, 'piège hors du parcours clavier' );
			assert_contains( 'name="_anode_nonce" value="nonce-anode_form_contact-1"', $html, 'nonce du formulaire' );

			// Le piège suit la définition, pas la constante.
			assert_contains( 'name="_url_site"', repli_html( 'rappel' ), 'piège renommé' );
		}
	);

	test(
		'un champ conditionnel ne porte pas le « required » du navigateur',
		function (): void {
			/*
			 * Sans JavaScript, tous les champs sont affichés — y compris ceux qu'une
			 * réponse devrait masquer. Le navigateur, lui, ignore la condition : avec
			 * l'attribut, il refuserait d'envoyer tant qu'une question sans objet
			 * n'est pas remplie, et rien ne permettrait d'en sortir.
			 */
			$html = repli_html( 'qualification' );

			assert_contains( 'data-field="raison_sociale"', $html, 'champ conditionnel rendu' );
			assert_same( 0, preg_match( '/name="raison_sociale"[^>]*\srequired/', $html ), 'aucun required natif' );
			assert_same( 0, preg_match( '/name="creneau"[^>]*\srequired/', $html ), 'aucun required natif, écran suivant' );

			// Le champ obligatoire **sans** condition, lui, le garde : c'est la
			// validation native du navigateur, et elle marche sans JavaScript.
			assert_same( 1, preg_match( '/name="profil"[^>]*\srequired/', $html ), 'required conservé hors condition' );

			// L'obligation reste appliquée par le serveur, qui sait, lui, si le
			// champ était réellement posé.
			poster( [ 'profil' => 'Entreprise' ], 'qualification' );

			assert_contains(
				'obligatoire',
				(string) ( etat_envoi()['erreurs']['raison_sociale'] ?? '' ),
				'obligation tenue par le serveur'
			);
		}
	);

	test(
		'la page qui porte un formulaire se déclare non cacheable',
		function (): void {
			// Un nonce vit douze à vingt-quatre heures : servi depuis une page mise
			// en cache plus vieille, il est mort pour tout le monde à la fois.
			assert_same( false, $GLOBALS['anode_nocache'], 'rien avant le rendu' );

			repli_html( 'contact' );

			assert_same( true, $GLOBALS['anode_nocache'], 'en-tête posé' );
			assert_true( defined( 'DONOTCACHEPAGE' ), 'constante lue par les extensions de cache' );
		}
	);

	echo "\nRepli sans JavaScript — injection dans la page\n";

	test(
		'seul un conteneur vide est rempli, et jamais deux fois',
		function (): void {
			$page = '<section><div class="brxe-block c-form" data-form="contact"></div></section>';
			$une  = injecter_replis( $page );

			assert_contains( '<form class="c-form__form"', $une, 'formulaire injecté' );

			// Le conteneur n'est plus vide : une seconde passe — `the_content` après
			// `render_data` — ne peut donc pas doubler le formulaire.
			assert_same( 1, substr_count( injecter_replis( $une ), '<form ' ), 'pas de doublon' );

			// Un bloc auquel le site a ajouté du contenu est laissé tel quel.
			$occupe = '<div data-form="contact"><p>Déjà là</p></div>';

			assert_same( $occupe, injecter_replis( $occupe ), 'conteneur occupé intact' );
		}
	);

	test(
		'un formulaire inconnu laisse le bloc intact',
		function (): void {
			$page = '<div data-form="inexistant"></div>';

			assert_same( $page, injecter_replis( $page ), 'aucun message d’erreur au visiteur' );
		}
	);

	echo "\nRepli sans JavaScript — réception du POST\n";

	test(
		'un envoi valide est relayé, puis redirigé',
		function (): void {
			$cible = poster( poste_valide() );

			assert_same( 1, relais_partis(), 'demande relayée' );
			assert_same( 0, count( $GLOBALS['anode_posts'] ), 'rien enregistré' );

			$parti = end( $GLOBALS['anode_relais'] );

			assert_same( 'https://relais.exemple.test/contact', $parti['url'], 'adresse du relais' );
			assert_same( 'contact', $parti['body']['form'] ?? '', 'nom du formulaire' );

			// POST-redirect-GET : sans redirection, un rafraîchissement renverrait
			// la demande et le site en recevrait deux.
			assert_contains( 'https://exemple.test/contact/?anode-recu=', $cible, 'redirection vers la même page' );
		}
	);

	test(
		'l’accusé de réception voyage sur une URL unique, qu’aucun cache ne peut avoir',
		function (): void {
			$cible = poster( poste_valide() );
			$jeton = (string) parse_url( $cible, PHP_URL_QUERY );
			$jeton = substr( $jeton, strlen( 'anode-recu=' ) );

			// La page de retour : requête GET, comme après la redirection.
			reset_requete();
			$_GET[ PARAM_RECU ] = $jeton;

			$html = repli_html( 'contact' );

			assert_contains( 'class="c-form__success"', $html, 'confirmation rendue par le serveur' );
			// `data-anode-recu` fait sortir le moteur sans rien construire : sinon il
			// rebâtirait un formulaire vide par-dessus le « merci ».
			assert_contains( 'data-anode-recu', $html, 'repère lu par le moteur' );
			assert_true( ! str_contains( $html, '<form' ), 'plus de formulaire' );

			// Un jeton inconnu ne fabrique pas de fausse confirmation.
			reset_requete();
			$_GET[ PARAM_RECU ] = 'deadbeefdeadbeef';

			assert_contains( '<form', repli_html( 'contact' ), 'jeton inconnu : le formulaire revient' );
		}
	);

	test(
		'un envoi refusé ne redirige pas, conserve les réponses et pose les messages',
		function (): void {
			// C'est tout l'intérêt de ne pas rediriger sur un refus : un message de
			// deux mille caractères ne survivrait pas au voyage.
			$cible = poster( poste_valide( [ 'courriel' => 'pas-une-adresse', 'message' => 'Un texte déjà long à ressaisir.' ] ) );

			assert_same( '', $cible, 'aucune redirection' );
			assert_same( 0, count( $GLOBALS['anode_posts'] ), 'rien enregistré' );

			$html = repli_html( 'contact' );

			assert_contains( 'Un texte déjà long à ressaisir.', $html, 'réponse conservée' );
			assert_contains( 'value="pas-une-adresse"', $html, 'valeur fautive conservée' );
			assert_contains( 'Indiquez une adresse e‑mail valide.', $html, 'message rendu côté serveur' );
			assert_contains( 'is-invalid', $html, 'champ marqué' );
			assert_contains( 'aria-invalid="true"', $html, 'erreur annoncée' );
			// Sans JavaScript, `autofocus` est le seul moyen d'amener la personne
			// au message plutôt que de la laisser en haut d'une page inchangée.
			assert_contains( 'role="alert" tabindex="-1" autofocus', $html, 'focus porté sur le message' );
		}
	);

	test(
		'la validation du POST classique est celle de l’API, pas une seconde',
		function (): void {
			// Les deux voies passent par `traiter()` : une divergence signifierait
			// qu'on a réécrit la validation à côté, et c'est la voie qu'on ne
			// regarde jamais qui garderait la faille.
			$refus = submit( payload( [ 'nom' => 'D' ] ) );

			reset_state();
			poster( poste_valide( [ 'nom' => 'D' ] ) );

			assert_same(
				$refus->get_error_data()['fields']['nom'],
				(string) ( etat_envoi()['erreurs']['nom'] ?? '' ),
				'même message des deux côtés'
			);
		}
	);

	test(
		'un champ inattendu fait refuser l’envoi, le nonce n’en est pas un',
		function (): void {
			poster( poste_valide( [ 'role_admin' => '1' ] ) );

			assert_same( 0, count( $GLOBALS['anode_posts'] ), 'envoi forgé refusé' );
			assert_contains( 'inattendu', (string) ( etat_envoi()['avis'] ?? '' ), 'motif du refus' );

			// Le nonce et le nom du formulaire sont ajoutés par le repli lui-même :
			// les compter comme des réponses refuserait *toutes* les demandes.
			reset_state();
			poster( array_merge( poste_valide(), [ '_wp_http_referer' => '/contact/' ] ) );

			assert_same( 1, relais_partis(), 'champs techniques écartés' );
		}
	);

	test(
		'un piège rempli suit exactement le chemin d’un envoi accepté',
		function (): void {
			$cible = poster( poste_valide( [ HONEYPOT => 'https://spam.example' ] ) );

			assert_contains( 'anode-recu=', $cible, 'même redirection' );
			assert_same( 0, count( $GLOBALS['anode_posts'] ), 'rien enregistré' );
			assert_same( 0, count( $GLOBALS['anode_mails'] ), 'aucune notification' );
		}
	);

	test(
		'la limitation de rythme vaut aussi pour le POST classique',
		function (): void {
			for ( $i = 0; $i < RATE_LIMIT; $i++ ) {
				check_rate_limit();
			}

			$cible = poster( poste_valide() );

			assert_same( '', $cible, 'aucune redirection' );
			assert_same( 0, count( $GLOBALS['anode_posts'] ), 'rien enregistré' );
			assert_contains( 'Trop d’envois', (string) ( etat_envoi()['avis'] ?? '' ), 'motif annoncé' );
		}
	);

	echo "\nRepli sans JavaScript — nonce et cache de page\n";

	test(
		'un nonce périmé ne jette jamais la demande',
		function (): void {
			/*
			 * Le cas d'une page servie depuis le cache de l'hébergeur : son nonce a
			 * été forgé sous un tour d'horloge précédent. Refuser l'envoi
			 * reproduirait, en pire, le défaut qu'on corrige — une demande perdue
			 * sans que personne ne le sache.
			 */
			$perime = 'nonce-' . action_nonce( 'contact' ) . '-0';
			$cible  = poster( poste_valide(), 'contact', $perime );

			assert_same( '', $cible, 'aucune redirection' );
			assert_same( 0, count( $GLOBALS['anode_posts'] ), 'demande non enregistrée' );

			$html = repli_html( 'contact' );

			assert_contains( 'session a expiré', $html, 'la personne sait quoi faire' );
			assert_contains( 'Bonjour, je souhaite un devis.', $html, 'réponses conservées' );
			// Le nonce de la page re-rendue est frais : la seconde tentative
			// aboutit forcément, aucun cache ne servant la réponse d'un POST.
			assert_contains( 'value="nonce-anode_form_contact-1"', $html, 'nonce frais' );

			$_POST['_anode_nonce'] = 'nonce-' . action_nonce( 'contact' ) . '-1';

			assert_contains( 'anode-recu=', intercepter_envoi(), 'renvoi accepté' );
			assert_same( 1, relais_partis(), 'demande enfin relayée' );
		}
	);

	test(
		'un nonce absent est traité comme un nonce périmé, pas comme un rejet',
		function (): void {
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST                     = array_merge( [ 'anode_form' => 'contact' ], poste_valide() );

			assert_same( '', intercepter_envoi(), 'aucune redirection' );
			assert_contains( 'session a expiré', (string) ( etat_envoi()['avis'] ?? '' ), 'invitation à renvoyer' );
		}
	);

	test(
		'un jeton d’un autre formulaire ne sert pas à poster celui-ci',
		function (): void {
			// Une action par formulaire : sinon un jeton relevé sur la page de
			// contact posterait la demande de rappel.
			$cible = poster( poste_valide(), 'contact', wp_create_nonce( action_nonce( 'rappel' ) ) );

			assert_same( '', $cible, 'refusé' );
			assert_same( 0, count( $GLOBALS['anode_posts'] ), 'rien enregistré' );
		}
	);

	test(
		'un POST qui ne nous concerne pas est laissé passer',
		function (): void {
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST                     = [ 'autre_extension' => '1' ];

			assert_same( '', intercepter_envoi(), 'aucune redirection' );
			assert_same( [], etat_envoi(), 'aucun message posé sur les formulaires de la page' );
		}
	);

	test(
		'un corps tronqué est signalé, jamais avalé en silence',
		function (): void {
			/*
			 * Au-delà de `post_max_size`, PHP vide `$_POST` **et** `$_FILES` sans
			 * rien dire : le nom du formulaire part avec le reste. Se taire ferait
			 * croire que rien n'a été envoyé, et le visiteur recommencerait à
			 * l'identique, indéfiniment.
			 */
			$_SERVER['REQUEST_METHOD']  = 'POST';
			$_SERVER['CONTENT_LENGTH']  = '40000000';
			$_POST                      = [];

			assert_same( '', intercepter_envoi(), 'aucune redirection' );
			assert_contains( 'dépasse ce que le serveur accepte', repli_html( 'contact' ), 'message sur la page' );
		}
	);

	/* --- Pièces jointes ---------------------------------------------- */

	echo "\nPièces jointes\n";

	test(
		'une pièce jointe part dans le relais, et ne reste pas sur le disque',
		function (): void {
			$reponse = envoyer( candidat(), [ 'fichier_cv' => piece( 'CV Dupont 2026.pdf' ) ] );

			assert_true( $reponse instanceof \WP_REST_Response, 'envoi accepté' );

			$parti = end( $GLOBALS['anode_relais'] );
			$pieces = $parti['body']['files'] ?? [];

			assert_same( 1, count( $pieces ), 'une pièce dans le relais' );
			assert_same( 'cv', $pieces[0]['field'], 'champ d’origine' );

			/*
			 * Le nom rendu est celui saisi par la personne, pas celui du disque :
			 * le second porte seize caractères tirés au sort, posés pour qu'on ne
			 * puisse pas deviner l'adresse d'un fichier pendant sa courte vie.
			 */
			// Assaini par `sanitize_file_name` : les espaces deviennent des tirets.
			// C'est le nom lisible qu'attend le CRM, pas celui du disque.
			assert_same( 'CV-Dupont-2026.pdf', $pieces[0]['name'], 'nom d’origine transmis' );
			assert_same( 'application/pdf', $pieces[0]['mime_type'], 'type transmis' );

			// Le contenu voyage, entier : c'est le seul exemplaire qui subsistera.
			assert_same( PDF, base64_decode( $pieces[0]['content'], true ), 'contenu transmis' );

			/*
			 * Et le disque est vide. C'est le point qui compte : le site ne garde
			 * rien, pas même le temps qu'on vienne chercher. Un fichier laissé là
			 * serait exactement la copie qu'on refuse de conserver — celle dont
			 * personne ne se souviendrait pour l'effacer.
			 */
			foreach ( fichiers_ranges() as $chemin ) {
				assert_true( ! file_exists( $chemin ), "fichier effacé : {$chemin}" );
			}
		}
	);

	test(
		'un champ de fichier facultatif laissé vide n’empêche rien',
		function (): void {
			$reponse = envoyer( candidat(), [ 'fichier_cv' => piece( 'cv.pdf' ) ] );

			assert_true( $reponse instanceof \WP_REST_Response, 'envoi accepté sans la lettre' );
			assert_true( ! isset( $GLOBALS['anode_meta'][100]['_anode_fichiers']['lettre'] ), 'aucune pièce inventée' );
		}
	);

	test(
		'un champ de fichier obligatoire sans fichier est refusé',
		function (): void {
			$refus = envoyer( candidat(), [] );

			assert_same( 'anode_form_invalid', $refus->get_error_code(), 'code' );
			assert_contains( 'obligatoire', erreur_de( $refus, 'cv' ), 'message rédigé' );
			assert_same( 0, count( $GLOBALS['anode_posts'] ), 'rien enregistré' );
		}
	);

	test(
		'une extension hors liste est refusée, une double extension aussi',
		function (): void {
			$php = envoyer( candidat(), [ 'fichier_cv' => piece( 'cv.php', "<?php echo 1;\n" ) ] );

			assert_contains( 'formats acceptés — PDF, DOCX', erreur_de( $php, 'cv' ), 'extension refusée' );

			/*
			 * `cv.php.pdf` finit par « .pdf » et passerait la liste blanche. Un
			 * Apache réglé avec `AddHandler` exécute pourtant tout nom qui contient
			 * « .php », où qu'il soit : c'est la seconde extension qu'il faut voir.
			 */
			$double = envoyer( candidat(), [ 'fichier_cv' => piece( 'cv.php.pdf' ) ] );

			assert_contains( 'seconde extension', erreur_de( $double, 'cv' ), 'double extension refusée' );
			assert_same( [], fichiers_ranges(), 'rien n’a été écrit' );
		}
	);

	test(
		'un contenu qui contredit son extension est refusé',
		function (): void {
			// Le fichier s'appelle « .pdf » et contient du PHP : `finfo` y voit
			// « text/x-php », et c'est le contenu qui décide.
			$refus = envoyer( candidat(), [ 'fichier_cv' => piece( 'cv.pdf', "<?php system(\$_GET['c']); ?>\n" ) ] );

			assert_contains( 'ne correspond pas à son extension', erreur_de( $refus, 'cv' ), 'contenu refusé' );
			assert_same( [], fichiers_ranges(), 'rien n’a été écrit' );
		}
	);

	test(
		'un fichier trop lourd est refusé, et sa taille est dite',
		function (): void {
			$refus = envoyer( candidat(), [ 'fichier_cv' => piece( 'cv.pdf', PDF, UPLOAD_ERR_OK, 3 * MB_IN_BYTES ) ] );

			$message = erreur_de( $refus, 'cv' );

			assert_contains( '2 Mo au maximum', $message, 'plafond de la définition' );
			// Sans la taille du fichier, la personne ne sait pas de combien elle
			// dépasse — ni s'il suffit de retirer une image.
			assert_contains( '3 Mo', $message, 'taille du fichier' );
		}
	);

	test(
		'le plafond de PHP l’emporte sur celui de la définition',
		function (): void {
			// `upload_max_filesize` à 1 Mo : la définition en demande 2, mais rien
			// ne peut arriver au-delà de ce que PHP accepte. Annoncer 2 Mo ferait
			// téléverser pour rien.
			$GLOBALS['anode_max_upload'] = MB_IN_BYTES;

			$limites = limites_fichier( [ 'name' => 'cv', 'type' => 'file', 'maxSize' => 2 ] );

			assert_same( 1, $limites['maxSize'], 'plafond le plus bas' );
		}
	);

	test(
		'« accept » restreint la liste blanche, il ne l’élargit jamais',
		function (): void {
			$limites = limites_fichier( [ 'name' => 'cv', 'type' => 'file', 'accept' => [ 'pdf', 'php', 'exe' ] ] );

			assert_same( [ 'pdf' ], $limites['accept'], 'seul le format connu subsiste' );
			assert_contains( 'hors liste blanche', implode( "\n", $GLOBALS['anode_logs'] ), 'écart signalé au journal' );

			// Sans `accept`, c'est la liste par défaut — plus étroite que la liste
			// blanche : une archive ou un tableur se demandent explicitement.
			$defaut = limites_fichier( [ 'name' => 'cv', 'type' => 'file' ] );

			assert_true( in_array( 'pdf', $defaut['accept'], true ), 'pdf accepté par défaut' );
			assert_true( ! in_array( 'zip', $defaut['accept'], true ), 'zip non accepté par défaut' );
		}
	);

	test(
		'une pièce jointe non déclarée fait refuser l’envoi entier',
		function (): void {
			$refus = envoyer(
				candidat(),
				[ 'fichier_cv' => piece( 'cv.pdf' ), 'fichier_intrus' => piece( 'autre.pdf' ) ]
			);

			assert_same( 'anode_form_unknown_field', $refus->get_error_code(), 'code' );
			// Et le fichier valide déjà rangé est retiré : une demande refusée ne
			// laisse rien derrière elle, sinon un envoi rejoué en déposerait un de
			// plus à chaque tentative.
			assert_same( [], fichiers_ranges(), 'aucun fichier laissé' );
		}
	);

	test(
		'un texte posté sur un champ de fichier est refusé',
		function (): void {
			$refus = envoyer( candidat( [ 'cv' => '/etc/passwd' ] ), [ 'fichier_cv' => piece( 'cv.pdf' ) ] );

			assert_contains( 'un fichier est attendu', erreur_de( $refus, 'cv' ), 'valeur forgée refusée' );

			// Et `sanitize_field` refuse aussi de son côté : le garde tient même si
			// une réécriture y faisait retomber les champs de fichier.
			assert_contains(
				'ERREUR:',
				champ( [ 'name' => 'cv', 'label' => 'CV', 'type' => 'file' ], 'cv.pdf' ),
				'garde de sanitize_field'
			);
		}
	);

	test(
		'un envoi tronqué par PHP est nommé, pas confondu avec un formulaire vide',
		function (): void {
			// Ni paramètre, ni fichier, et un en-tête multipart : c'est la seule
			// trace que laisse un corps plus gros que `post_max_size`.
			$refus = handle( new \WP_REST_Request( [], [ 'content-type' => 'multipart/form-data; boundary=--x' ], [] ) );

			assert_same( 'anode_form_trop_gros', $refus->get_error_code(), 'code' );
			assert_same( 413, $refus->get_error_data()['status'], 'statut' );
			assert_contains( 'dépasse ce que le serveur accepte', $refus->get_error_message(), 'message rédigé' );

			// La voie JSON, elle, a légitimement un « corps » vide au sens de
			// `$_POST` : le contrôle ne doit pas s'y déclencher.
			$json = handle( new \WP_REST_Request( [ 'form' => 'inexistant' ], [ 'content-type' => 'application/json' ], [] ) );

			assert_same( 'anode_form_unknown', $json->get_error_code(), 'voie JSON épargnée' );
		}
	);





	test(
		'la route publique sert les limites appliquées, et aucun plafond de caractères',
		function (): void {
			$data = describe( new \WP_REST_Request( [ 'name' => 'candidature' ] ) )->get_data();
			$cv   = $data['fields'][2];

			assert_same( [ 'pdf', 'docx' ], $cv['accept'], 'formats servis' );
			assert_same( 2, $cv['maxSize'], 'taille servie' );
			// Un `maxlength` sur un `<input type="file">` est ignoré par le
			// navigateur : l'annoncer promettrait une contrainte qui n'existe pas.
			assert_true( ! isset( $cv['maxLength'] ), 'aucun plafond de caractères' );
		}
	);

	printf( "\n%d test(s) réussi(s), %d échec(s).\n", $passed, $failed );

	exit( $failed > 0 ? 1 : 0 );
}
