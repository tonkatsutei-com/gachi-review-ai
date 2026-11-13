<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Page_Cache {
	private const PREFIX = 'gachireai_admin_page_v1_';
	public const TTL = 21600;
	public const TTL_DASHBOARD = 300;
	private const VERSION_KEY = 'gachireai_admin_page_version';
	public static function should_cache(): bool {
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
		if ( 'GET' !== $method ) {
			return false;
		}
		foreach ( array_keys( (array) $_GET ) as $key ) {
			if ( ! in_array( (string) $key, array( 'page', 'tab' ), true ) ) {
				return false;
			}
		}
		return true;
	}
	public static function get( string $tab ): ?string {
		$value = get_transient( self::key( $tab ) );
		return is_string( $value ) && '' !== $value ? $value : null;
	}
	public static function set( string $tab, string $html ): void {
		if ( '' === $html ) {
			return;
		}
		$ttl = Tabs::TAB_DASHBOARD === $tab ? self::TTL_DASHBOARD : self::TTL;
		set_transient( self::key( $tab ), $html, $ttl );
	}
	public static function flush(): void {
		foreach ( Tabs::slugs() as $tab ) {
			delete_transient( self::key( $tab ) );
		}
	}
	public static function flush_all(): void {
		update_option( self::VERSION_KEY, (string) microtime( true ), false );
	}
	private static function key( string $tab ): string {
		$session = function_exists( 'wp_get_session_token' ) ? (string) wp_get_session_token() : '';
		return self::PREFIX . md5(
			implode(
				'|',
				array(
					(string) get_current_blog_id(),
					(string) get_current_user_id(),
					$session,
					(string) get_option( self::VERSION_KEY, '1' ),
					defined( 'GACHIREAI_APP_VERSION' ) ? (string) GACHIREAI_APP_VERSION : '',
					$tab,
				)
			)
		);
	}
}
