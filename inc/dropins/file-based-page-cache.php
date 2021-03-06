<?php
defined( 'ABSPATH' ) || exit;

// Don't cache robots.txt or htacesss
if ( strpos( $_SERVER['REQUEST_URI'], 'robots.txt' ) !== false || strpos( $_SERVER['REQUEST_URI'], '.htaccess' ) !== false ) {
	return;
}

// Don't cache non-GET requests
if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'GET' ) {
	return;
}

$file_extension = pathinfo( $_SERVER['REQUEST_URI'], PATHINFO_EXTENSION );
$file_extension = preg_replace( '#^(.*)\?.*$#', '$1', $file_extension );

// Don't cache disallowed extensions. Prevents wp-cron.php, xmlrpc.php, etc.
if ( ! preg_match( '#index\.php$#i', $_SERVER['REQUEST_URI'] ) && in_array( $file_extension, array( 'php', 'xml', 'xsl' ) ) ) {
	return;
}

// Don't cache if logged in
if ( ! empty( $_COOKIE ) ) {
	$wp_cookies = array( 'wordpressuser_', 'wordpresspass_', 'wordpress_sec_', 'wordpress_logged_in_' );

	foreach ( $_COOKIE as $key => $value ) {
		foreach ( $wp_cookies as $cookie ) {
			if ( strpos( $key, $cookie ) !== false ) {
				// Logged in!
				return;
			}
		}
	}

	if ( ! empty( $_COOKIE['sc_commented_posts'] ) ) {
		foreach ( $_COOKIE['sc_commented_posts'] as $path ) {
			if ( rtrim( $path, '/') === rtrim( $_SERVER['REQUEST_URI'], '/' ) ) {
				// User commented on this post
				return;
			}
		}
	}
}

sc_serve_cache();

ob_start( 'sc_cache' );

/**
 * Cache output before it goes to the browser
 *
 * @param  string $buffer
 * @param  int $flags
 * @since  1.0
 * @return string
 */
function sc_cache( $buffer, $flags ) {
	if ( strlen( $buffer ) < 255 ) {
		return $buffer;
	}

	// Don't cache search or 404
	if ( is_404() || is_search() ) {
		return $buffer;
	}

	include_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
	include_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

	$filesystem = new WP_Filesystem_Direct( new StdClass() );

	// Make sure we can read/write files and that proper folders exist
	if ( ! $filesystem->exists( untrailingslashit( WP_CONTENT_DIR ) . '/cache' ) ) {
		if ( ! $filesystem->mkdir( untrailingslashit( WP_CONTENT_DIR ) . '/cache' ) ) {
			// Can not cache!
			return $buffer;
		}
	}

	if ( ! $filesystem->exists( untrailingslashit( WP_CONTENT_DIR ) . '/cache/simple-cache' ) ) {
		if ( ! $filesystem->mkdir( untrailingslashit( WP_CONTENT_DIR ) . '/cache/simple-cache' ) ) {
			// Can not cache!
			return $buffer;
		}
	}

	$buffer = apply_filters( 'sc_pre_cache_buffer', $buffer );

	$url_path = sc_get_url_path();

	$dirs = explode( '/', $url_path );

	$path = untrailingslashit( WP_CONTENT_DIR ) . '/cache/simple-cache';

	foreach ( $dirs as $dir ) {
		if ( ! empty( $dir ) ) {
			$path .= '/' . $dir;

			if ( ! $filesystem->exists( $path ) ) {
				if ( ! $filesystem->mkdir( $path ) ) {
					// Can not cache!
					return $buffer;
				}
			}
		}
	}

	$cached_statement = "\n<!-- Cache served by Simple Cache " . time() . " -->\n";

	$filesystem->put_contents( $path . '/index.html', $buffer . $cached_statement, FS_CHMOD_FILE );

	if ( function_exists( 'gzencode' ) ) {
		$filesystem->put_contents( $path . '/index.gzip.html', gzencode( $buffer . $cached_statement, 3 ), FS_CHMOD_FILE );
	}

	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s' ) . ' GMT' );

	if ( function_exists( 'ob_gzhandler' ) && ! empty( $GLOBALS['sc_config']['enable_gzip_compression'] ) ) {
		return ob_gzhandler( $buffer, $flags );
	} else {
		return $buffer;
	}
}

/**
 * Get URL path for caching
 *
 * @since  1.0
 * @return string
 */
function sc_get_url_path() {

	$host = ( isset( $_SERVER['HTTP_HOST'] ) ) ? $_SERVER['HTTP_HOST'] : '';

	return rtrim( $host, '/' ) . $_SERVER['REQUEST_URI'];
}

/**
 * Optionally serve cache and exit
 *
 * @since 1.0
 */
function sc_serve_cache() {
	$file_name = 'index.html';

	if ( function_exists( 'gzencode' ) && ! empty( $GLOBALS['sc_config']['enable_gzip_compression'] ) ) {
		header( 'Content-Encoding: gzip' );
		$file_name = 'index.gzip.html';
	}

	$path = rtrim( WP_CONTENT_DIR . '/' ) . '/cache/simple-cache/' . sc_get_url_path() . '/' . $file_name;

	if ( @file_exists( $path ) && @is_readable( $path ) ) {
		@readfile( $path );

		exit;
	}
}

