<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
	exit;
}
if ( ! function_exists( 'gachireai_load_div' ) ) {
	function gachireai_load_div(): bool {
		static $loaded = false;
		if ( $loaded ) {
			return true;
		}
		$functions = GACHIREAI_PLUGIN_DIR . 'lib/div-functions.php';
		$engine    = GACHIREAI_PLUGIN_DIR . 'lib/div.php';
		if ( ! is_readable( $engine ) ) {
			return false;
		}
		if ( is_readable( $functions ) && ! function_exists( 'divengine\\cop' ) ) {
			require_once $functions;
		}
		if ( ! class_exists( 'divengine\\div' ) ) {
			require_once $engine;
		}
		$loaded = class_exists( 'divengine\\div' );
		return $loaded;
	}
}
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Tonkatsutei\\Gachi_Review_AI\\';
		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$dirs     = array_map( 'strtolower', $parts );
		$base = GACHIREAI_PLUGIN_DIR . 'includes/';
		if ( ! empty( $dirs ) ) {
			$base .= implode( '/', $dirs ) . '/';
		}
		$name_slug  = strtolower( str_replace( '_', '-', $name ) );
		$candidates = array(
			$base . 'class-' . $name_slug . '.php',
			$base . 'interface-' . $name_slug . '.php',
		);
		foreach ( $candidates as $file ) {
			if ( is_readable( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
);
