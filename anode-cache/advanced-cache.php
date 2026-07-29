<?php
/**
 * Dropin de cache Anode — à copier dans wp-content/advanced-cache.php.
 *
 * WordPress charge ce fichier très tôt, avant les extensions, le thème et la
 * plupart du noyau. C'est là que se trouve le gain réel : servir la page depuis
 * un fichier statique évite d'amorcer WordPress, pas seulement d'en rendre le
 * HTML. On passe typiquement d'une centaine de millisecondes à moins de cinq.
 *
 * Installation :
 *   1. cp advanced-cache.php wp-content/advanced-cache.php
 *   2. define( 'WP_CACHE', true ); dans wp-config.php
 *
 * Ce fichier ne doit dépendre d'aucune fonction WordPress : rien n'est encore
 * chargé à ce stade.
 *
 * @package Anode\Cache
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

( static function (): void {
	// Seules les lectures simples et anonymes sont servies depuis le cache.
	if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! empty( $_GET ) ) {
		return;
	}

	if ( defined( 'WP_CLI' ) || php_sapi_name() === 'cli' ) {
		return;
	}

	// Un cookie de session interdit de servir une page mutualisée.
	foreach ( array_keys( $_COOKIE ) as $name ) {
		$name = (string) $name;

		if ( str_starts_with( $name, 'wordpress_' )
			|| str_starts_with( $name, 'wp-' )
			|| str_starts_with( $name, 'comment_author' ) ) {
			return;
		}
	}

	$uri = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );

	// wp-admin, wp-login, wp-json et wp-cron ne sont jamais servis depuis le cache.
	if ( preg_match( '#/(wp-admin|wp-login\.php|wp-json|wp-cron\.php|xmlrpc\.php)#', $uri ) ) {
		return;
	}

	// L'en-tête Host vient du client : on n'accepte qu'un nom de domaine bien
	// formé, sinon une empreinte. Doit rester identique à la fonction
	// sanitize_host() de anode-cache.php, sans quoi le dropin lirait un
	// emplacement que le mu-plugin n'écrit jamais.
	$host = strtolower( trim( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) ) );
	$host = (string) preg_replace( '/:\d+$/', '', $host );

	if ( ! preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host ) ) {
		$host = 'host-' . md5( $host );
	}

	$file = WP_CONTENT_DIR . '/cache/anode/' . $host . '/' . md5( $uri ) . '.html';

	if ( ! is_readable( $file ) ) {
		return;
	}

	$age = time() - (int) filemtime( $file );

	// Durée de vie : identique à celle du mu-plugin, redéfinie ici car aucune
	// constante WordPress n'est encore disponible.
	if ( $age > 12 * 3600 ) {
		return;
	}

	// Répond 304 si le navigateur possède déjà cette version.
	$etag = '"' . md5( (string) filemtime( $file ) . $file ) . '"';

	header( 'X-Anode-Cache: hit' );
	header( 'ETag: ' . $etag );
	header( 'Cache-Control: public, max-age=0, must-revalidate' );

	if ( trim( (string) ( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' ) ) === $etag ) {
		http_response_code( 304 );
		exit;
	}

	header( 'Content-Type: text/html; charset=UTF-8' );

	readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile

	exit;
} )();
