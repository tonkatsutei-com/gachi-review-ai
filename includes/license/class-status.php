<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\License;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Status {
	public const TIER_PRO = 'pro';
	public const TIER_BASIC = 'basic';
	public const TIER_FREE = 'free';
	public const EXPIRY_GRACE_DAYS = 15;
	public const REFRESH_INTERVAL = 86400;
	public const RETRY_INTERVAL = 3600;
	private static ?string $resolved = null;
	public static function tier(): string {
		if ( null === self::$resolved ) {
			self::$resolved = self::resolve();
		}
		return self::$resolved;
	}
	public static function plan(): string {
		return self::normalize_tier( (string) Store::cache()['tier'] );
	}
	public static function is_pro(): bool {
		return self::TIER_PRO === self::tier();
	}
	public static function is_basic(): bool {
		return self::TIER_BASIC === self::tier();
	}
	public static function is_free(): bool {
		return self::TIER_FREE === self::tier();
	}
	public static function is_basic_or_higher(): bool {
		return in_array( self::tier(), array( self::TIER_PRO, self::TIER_BASIC ), true );
	}
	public static function capabilities(): Capabilities {
		return new Capabilities( self::tier() );
	}
	public static function maybe_refresh(): void {
		if ( '' === Store::key() ) {
			return;
		}
		$cache        = Store::cache();
		$now          = time();
		$attempted_at = (int) $cache['attempted_at'];
		if ( $attempted_at > 0 ) {
			$interval = $cache['ok'] ? self::REFRESH_INTERVAL : self::RETRY_INTERVAL;
			if ( $now - $attempted_at < $interval ) {
				return;
			}
		}
		self::refresh();
	}
	public static function refresh(): bool {
		$key   = Store::key();
		$cache = Store::cache();
		$now   = time();
		if ( '' === $key ) {
			Store::save_cache(
				array(
					'tier'         => '',
					'expires_at'   => '',
					'verified_at'  => 0,
					'attempted_at' => $now,
					'ok'           => false,
					'error'        => __( 'ライセンスキーが未設定です。', GACHIREAI_TEXT_DOMAIN ),
				)
			);
			self::reset();
			return false;
		}
		try {
			$result = ( new Client() )->verify( $key, self::site_url() );
			Store::save_cache(
				array(
					'tier'         => self::normalize_tier( (string) $result['tier'] ),
					'expires_at'   => (string) $result['expires_at'],
					'verified_at'  => $now,
					'attempted_at' => $now,
					'ok'           => true,
					'error'        => '',
				)
			);
			self::reset();
			return true;
		} catch ( \Throwable $e ) {
			Store::save_cache(
				array(
					'tier'         => (string) $cache['tier'],
					'expires_at'   => (string) $cache['expires_at'],
					'verified_at'  => (int) $cache['verified_at'],
					'attempted_at' => $now,
					'ok'           => false,
					'error'        => $e->getMessage(),
				)
			);
			self::reset();
			return false;
		}
	}
	public static function last_error(): string {
		return (string) Store::cache()['error'];
	}
	public static function expires_at(): string {
		return (string) Store::cache()['expires_at'];
	}
	public static function verified_at(): int {
		return (int) Store::cache()['verified_at'];
	}
	public static function attempted_at(): int {
		return (int) Store::cache()['attempted_at'];
	}
	public static function reset(): void {
		self::$resolved = null;
	}
	public static function normalize_tier( string $tier ): string {
		$tier = strtolower( trim( $tier ) );
		return in_array( $tier, array( self::TIER_PRO, self::TIER_BASIC, self::TIER_FREE ), true )
			? $tier
			: self::TIER_FREE;
	}
	private static function resolve(): string {
		if ( '' === Store::key() ) {
			return self::TIER_FREE;
		}
		$cache       = Store::cache();
		$tier        = self::normalize_tier( (string) $cache['tier'] );
		$verified_at = (int) $cache['verified_at'];
		if ( $verified_at <= 0 ) {
			return self::TIER_FREE;
		}
		return self::effective_tier( $tier, (string) $cache['expires_at'] );
	}
	private static function effective_tier( string $tier, string $expires_at ): string {
		if ( self::TIER_PRO !== $tier ) {
			return $tier;
		}
		$days = self::days_since_expiry( $expires_at );
		if ( null === $days || $days <= 0 ) {
			return self::TIER_PRO;
		}
		return $days < self::EXPIRY_GRACE_DAYS ? self::TIER_PRO : self::TIER_BASIC;
	}
	public static function days_since_expiry( string $date ): ?int {
		$date = substr( trim( $date ), 0, 10 );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}
		$today = function_exists( 'current_time' )
			? (string) current_time( 'Y-m-d' )
			: gmdate( 'Y-m-d' );
		$today_ts  = strtotime( $today . ' 00:00:00 UTC' );
		$expiry_ts = strtotime( $date . ' 00:00:00 UTC' );
		if ( false === $today_ts || false === $expiry_ts ) {
			return null;
		}
		return (int) round( ( $today_ts - $expiry_ts ) / 86400 );
	}
	private static function site_url(): string {
		return function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
	}
}
