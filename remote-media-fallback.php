<?php
/**
 * Plugin Name: Remote Media Fallback
 * Plugin URI: https://github.com/jonschr/remote-media-fallback
 * Description: Serves missing local uploads from a configured production WordPress site.
 * Version: 0.1.2
 * Requires PHP: 7.4
 * Author: Jon Schroeder
 * Update URI: https://github.com/jonschr/remote-media-fallback
 */

defined( 'ABSPATH' ) || exit;

final class Remote_Media_Fallback {

	const VERSION = '0.1.2';
	const URL_CONSTANT = 'REMOTE_MEDIA_FALLBACK_URL';

	/**
	 * Proxy a missing local upload from production.
	 */
	public static function maybe_serve_remote_upload() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || ! defined( self::URL_CONSTANT ) ) {
			return;
		}

		$production_url = untrailingslashit( esc_url_raw( constant( self::URL_CONSTANT ) ) );
		if ( ! self::is_valid_production_url( $production_url ) ) {
			return;
		}

		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$uploads      = wp_upload_dir();
		$uploads_path = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		$relative     = self::relative_upload_path( $request_path, $uploads_path );

		if ( null === $relative ) {
			return;
		}

		$local_file = trailingslashit( $uploads['basedir'] ) . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		if ( is_file( $local_file ) ) {
			return;
		}

		$remote_url = $production_url . untrailingslashit( $uploads_path ) . '/' . self::encode_path( $relative );
		$args       = array(
			'timeout'     => 30,
			'redirection' => 2,
			'user-agent'  => self::user_agent( $production_url ),
		);

		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( $range && preg_match( '/^bytes=\d*-\d*$/', $range ) ) {
			$args['headers'] = array( 'Range' => $range );
		}

		$temp_file = '';
		if ( 'GET' === $method ) {
			$temp_file = tempnam( get_temp_dir(), 'remote-media-fallback-' );
			if ( ! $temp_file ) {
				return;
			}
			$args['stream']   = true;
			$args['filename'] = $temp_file;
			$response         = wp_safe_remote_get( $remote_url, $args );
		} else {
			$response = wp_safe_remote_head( $remote_url, $args );
		}

		if ( is_wp_error( $response ) ) {
			self::remove_temp_file( $temp_file );
			return;
		}

		$status       = wp_remote_retrieve_response_code( $response );
		$content_type = strtolower( trim( strtok( (string) wp_remote_retrieve_header( $response, 'content-type' ), ';' ) ) );
		if ( ! in_array( $status, array( 200, 206 ), true ) || ! self::is_allowed_content_type( $content_type ) ) {
			self::remove_temp_file( $temp_file );
			return;
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header_remove( 'Expires' );
		header_remove( 'Pragma' );
		header_remove( 'Cache-Control' );
		header_remove( 'Content-Type' );
		status_header( $status );
		header( 'Content-Type: ' . $content_type );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Remote-Media-Fallback: ' . sanitize_text_field( wp_parse_url( $production_url, PHP_URL_HOST ) ) );

		self::relay_header( $response, 'accept-ranges', 'Accept-Ranges' );
		self::relay_header( $response, 'content-range', 'Content-Range' );
		self::relay_header( $response, 'etag', 'ETag' );
		self::relay_header( $response, 'last-modified', 'Last-Modified' );
		self::relay_header( $response, 'cache-control', 'Cache-Control' );

		$content_length = wp_remote_retrieve_header( $response, 'content-length' );
		if ( 'GET' === $method && $temp_file && is_file( $temp_file ) ) {
			header( 'Content-Length: ' . filesize( $temp_file ) );
		} elseif ( ctype_digit( (string) $content_length ) ) {
			header( 'Content-Length: ' . $content_length );
		}

		if ( 'GET' === $method ) {
			readfile( $temp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			self::remove_temp_file( $temp_file );
		}

		exit;
	}

	/**
	 * Return the path beneath the upload base, or null when it is not safe.
	 */
	public static function relative_upload_path( $request_path, $uploads_path ) {
		if ( ! is_string( $request_path ) || ! is_string( $uploads_path ) ) {
			return null;
		}

		$request_path = rawurldecode( $request_path );
		$uploads_path = untrailingslashit( rawurldecode( $uploads_path ) );
		$prefix       = $uploads_path . '/';

		if ( '' === $uploads_path || 0 !== strpos( $request_path, $prefix ) ) {
			return null;
		}

		$relative = substr( $request_path, strlen( $prefix ) );
		$segments = explode( '/', $relative );
		if ( '' === $relative || false !== strpos( $relative, "\0" ) ) {
			return null;
		}

		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return null;
			}
		}

		return implode( '/', $segments );
	}

	/**
	 * Build the service identity required by hosts such as SiteDistrict.
	 */
	public static function user_agent( $production_url ) {
		return sprintf( 'WordPress-Remote-Media-Fallback/%s (+%s/)', self::VERSION, untrailingslashit( $production_url ) );
	}

	/**
	 * Only proxy media/document responses, never an HTML firewall page.
	 */
	public static function is_allowed_content_type( $content_type ) {
		if ( 0 === strpos( $content_type, 'image/' ) || 0 === strpos( $content_type, 'audio/' ) || 0 === strpos( $content_type, 'video/' ) ) {
			return true;
		}

		return in_array(
			$content_type,
			array(
				'application/pdf',
				'application/msword',
				'application/rtf',
				'application/vnd.ms-excel',
				'application/vnd.ms-powerpoint',
				'application/vnd.openxmlformats-officedocument.presentationml.presentation',
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			),
			true
		);
	}

	private static function is_valid_production_url( $url ) {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		return $host && in_array( $scheme, array( 'http', 'https' ), true ) && $host !== wp_parse_url( home_url(), PHP_URL_HOST );
	}

	private static function encode_path( $path ) {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}

	private static function relay_header( $response, $remote_name, $local_name ) {
		$value = wp_remote_retrieve_header( $response, $remote_name );
		if ( $value && false === strpos( (string) $value, "\r" ) && false === strpos( (string) $value, "\n" ) ) {
			header( $local_name . ': ' . $value );
		}
	}

	private static function remove_temp_file( $temp_file ) {
		if ( $temp_file && is_file( $temp_file ) ) {
			unlink( $temp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
}

add_action( 'template_redirect', array( 'Remote_Media_Fallback', 'maybe_serve_remote_upload' ), 0 );
