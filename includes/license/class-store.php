<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\License;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Store {
	public const KEY_OPTION = 'gachireai_license_key';
	public const CACHE_OPTION = 'gachireai_license_cache';
	public static function key(): string {
		$value = get_site_option( self::KEY_OPTION, '' );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
	public static function save_key( string $key ): void {
		$key = trim( $key );
		if ( '' === $key ) {
			self::clear_key();
			return;
		}
		update_site_option( self::KEY_OPTION, $key );
	}
	public static function clear_key(): void {
		delete_site_option( self::KEY_OPTION );
	}
	public static function cache(): array {
		$saved = get_site_option( self::CACHE_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$text = static function ( array $source, string $key ) : string {
			return isset( $source[ $key ] ) && is_scalar( $source[ $key ] )
				? (string) $source[ $key ]
				: '';
		};
		return array(
			'tier'         => $text( $saved, 'tier' ),
			'expires_at'   => $text( $saved, 'expires_at' ),
			'verified_at'  => isset( $saved['verified_at'] ) ? (int) $saved['verified_at'] : 0,
			'attempted_at' => isset( $saved['attempted_at'] ) ? (int) $saved['attempted_at'] : 0,
			'ok'           => ! empty( $saved['ok'] ),
			'error'        => $text( $saved, 'error' ),
		);
	}
	public static function save_cache( array $cache ): void {
		update_site_option(
			self::CACHE_OPTION,
			array(
				'tier'         => isset( $cache['tier'] ) && is_scalar( $cache['tier'] ) ? (string) $cache['tier'] : '',
				'expires_at'   => isset( $cache['expires_at'] ) && is_scalar( $cache['expires_at'] ) ? (string) $cache['expires_at'] : '',
				'verified_at'  => isset( $cache['verified_at'] ) ? (int) $cache['verified_at'] : 0,
				'attempted_at' => isset( $cache['attempted_at'] ) ? (int) $cache['attempted_at'] : 0,
				'ok'           => ! empty( $cache['ok'] ),
				'error'        => isset( $cache['error'] ) && is_scalar( $cache['error'] ) ? (string) $cache['error'] : '',
			)
		);
	}
	public static function clear_cache(): void {
		delete_site_option( self::CACHE_OPTION );
	}
}
