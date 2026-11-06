<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Affiliate;
use Tonkatsutei\Gachi_Review_AI\Common\Store as Common_Store;
use Tonkatsutei\Gachi_Review_AI\Event_Logger;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Connection_Status {
	private const GROUPS = array( 'rakuten', 'amazon', 'yahoo', 'llm', 'jev', 'image', 'youtube', 'mastodon' );
	private const KEY = 'verified_at';
	private const KEY_REVOKED = 'revoked_at';
	public static function supports( string $group ): bool {
		return in_array( $group, self::GROUPS, true );
	}
	public static function mark_verified( string $group, ?Settings $settings = null ): void {
		self::write( $group, time(), $settings );
	}
	public static function mark_unverified( string $group, ?Settings $settings = null ): void {
		self::write( $group, 0, $settings );
	}
	public static function mark_revoked( string $group, ?Settings $settings = null ): void {
		if ( ! self::supports( $group ) ) {
			return;
		}
		$store  = self::read_store( $group, $settings );
		$values = (array) $store->get( $group, array() );
		unset( $values[ self::KEY ] );
		$values[ self::KEY_REVOKED ] = time();
		$store->set( $group, $values );
	}
	public static function verified_at( string $group, ?Settings $settings = null ): int {
		if ( ! self::supports( $group ) ) {
			return 0;
		}
		$store  = self::read_store( $group, $settings );
		$values = (array) $store->get( $group, array() );
		return max( 0, (int) ( $values[ self::KEY ] ?? 0 ) );
	}
	public static function is_verified( string $group, ?Settings $settings = null ): bool {
		return 0 < self::verified_at( $group, $settings );
	}
	public static function is_revoked( string $group, ?Settings $settings = null ): bool {
		if ( ! self::supports( $group ) ) {
			return false;
		}
		if ( self::is_verified( $group, $settings ) ) {
			return false;
		}
		return 0 < self::revoked_at( $group, $settings );
	}
	public static function revoked_at( string $group, ?Settings $settings = null ): int {
		if ( ! self::supports( $group ) ) {
			return 0;
		}
		$store  = self::read_store( $group, $settings );
		$values = (array) $store->get( $group, array() );
		return max( 0, (int) ( $values[ self::KEY_REVOKED ] ?? 0 ) );
	}
	public static function preserve_record_keys( string $group, array $candidate, ?Settings $settings = null ): array {
		if ( ! self::supports( $group ) ) {
			return $candidate;
		}
		$store  = self::read_store( $group, $settings );
		$values = (array) $store->get( $group, array() );
		foreach ( array( self::KEY, self::KEY_REVOKED ) as $key ) {
			if ( array_key_exists( $key, $values ) ) {
				$candidate[ $key ] = $values[ $key ];
			}
		}
		return $candidate;
	}
	public static function note_runtime_failure( string $group, int $status, ?Settings $settings = null ): void {
		if ( ! self::supports( $group ) || ! in_array( $status, array( 401, 403 ), true ) ) {
			return;
		}
		self::mark_revoked( $group, $settings );
		Event_Logger::record(
			Event_Logger::TYPE_SYSTEM,
			Event_Logger::LEVEL_WARN,
			self::revocation_message()
		);
	}
	public static function note_runtime_failure_from_message( string $group, string $message ): void {
		if ( 1 !== preg_match( '/HTTP\s*(401|403)\b/i', $message, $matches ) ) {
			return;
		}
		self::note_runtime_failure( $group, (int) $matches[1] );
	}
	public static function revocation_message(): string {
		return __( '以前は接続できていましたが、現在は API に接続できません。キーの失効・期限切れ・利用制限の可能性があります。設定を見直して、接続テスト（保存）をやり直してください。', GACHIREAI_TEXT_DOMAIN );
	}
	private static function read_store( string $group, ?Settings $settings ): Settings {
		$settings = $settings ?? new Settings();
		if ( $settings instanceof Common_Store ) {
			return $settings;
		}
		if ( is_multisite() && in_array( $group, Settings::COMMON_GROUPS, true ) && $settings->uses_common( $group ) ) {
			return new Common_Store();
		}
		return $settings;
	}
	private static function write( string $group, int $value, ?Settings $settings ): void {
		if ( ! self::supports( $group ) ) {
			return;
		}
		$store  = self::read_store( $group, $settings );
		$values = (array) $store->get( $group, array() );
		if ( $value > 0 ) {
			$values[ self::KEY ] = $value;
			unset( $values[ self::KEY_REVOKED ] );
		} else {
			unset( $values[ self::KEY ], $values[ self::KEY_REVOKED ] );
		}
		$store->set( $group, $values );
	}
}
