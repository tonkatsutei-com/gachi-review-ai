<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Scraping;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Connection_Status;
use Tonkatsutei\Gachi_Review_AI\Common\Store as Common_Store;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Store {
	private const PATH = 'scraping.services';
	private const KEY_MAX = 500;
	public static function all(): array {
		$saved = self::settings()->get( self::PATH, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out = array();
		foreach ( Service_Catalog::ids() as $id ) {
			$row = isset( $saved[ $id ] ) && is_array( $saved[ $id ] ) ? $saved[ $id ] : array();
			$out[ $id ] = array(
				'api_key'            => isset( $row['api_key'] ) && is_scalar( $row['api_key'] ) ? trim( (string) $row['api_key'] ) : '',
				'status'             => isset( $row['status'] ) && is_scalar( $row['status'] ) ? (string) $row['status'] : '',
				'message'            => isset( $row['message'] ) && is_scalar( $row['message'] ) ? (string) $row['message'] : '',
				'tested_at'          => isset( $row['tested_at'] ) && is_scalar( $row['tested_at'] ) ? (string) $row['tested_at'] : '',
				'quota_exhausted'    => ! empty( $row['quota_exhausted'] ),
				'quota_exhausted_at' => isset( $row['quota_exhausted_at'] ) && is_scalar( $row['quota_exhausted_at'] ) ? (string) $row['quota_exhausted_at'] : '',
			);
		}
		return $out;
	}
	public static function get( string $id ): array {
		$all = self::all();
		return $all[ $id ] ?? array(
			'api_key'            => '',
			'status'             => '',
			'message'            => '',
			'tested_at'          => '',
			'quota_exhausted'    => false,
			'quota_exhausted_at' => '',
		);
	}
	public static function api_key( string $id ): string {
		$all = self::all();
		return $all[ $id ]['api_key'] ?? '';
	}
	public static function is_configured( string $id ): bool {
		return '' !== self::api_key( $id );
	}
	public static function is_verified( string $id ): bool {
		return 'ok' === self::get( $id )['status'];
	}
	public static function mark_revoked( string $id ): void {
		if ( ! Service_Catalog::exists( $id ) ) {
			return;
		}
		self::save_service(
			$id,
			array(
				'status'    => 'error',
				'message'   => Connection_Status::revocation_message(),
				'tested_at' => (string) current_time( 'mysql' ),
			)
		);
	}
	public static function save_service( string $id, array $fields ): void {
		if ( ! Service_Catalog::exists( $id ) ) {
			return;
		}
		$store   = self::settings();
		$raw     = self::raw();
		$current = isset( $raw[ $id ] ) && is_array( $raw[ $id ] ) ? $raw[ $id ] : array();
		$raw[ $id ] = array_merge( $current, $fields );
		$store->set( self::PATH, $raw );
	}
	public static function sanitize_api_key( string $key ): string {
		return mb_substr( trim( sanitize_text_field( $key ) ), 0, self::KEY_MAX );
	}
	private static function raw(): array {
		$saved = self::settings()->get( self::PATH, array() );
		return is_array( $saved ) ? $saved : array();
	}
	private static function settings(): Settings {
		$site = new Settings();
		if ( is_multisite() && $site->uses_common( 'scraping' ) ) {
			return new Common_Store();
		}
		return $site;
	}
}
