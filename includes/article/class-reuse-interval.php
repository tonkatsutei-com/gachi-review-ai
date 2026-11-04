<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Reuse_Interval {
	public const GROUP = 'article';
	public const KEY = 'reuse_interval_days';
	public const DEFAULT_DAYS = '30';
	public const UNLIMITED = '0';
	public const PERMANENT = 'permanent';
	public const VALUES = array( '0', '1', '7', '14', '30', '60', '90', '180', '365', 'permanent' );
	public static function options(): array {
		return array(
			self::UNLIMITED => __( '制限無（すぐ再投稿可）', GACHIREAI_TEXT_DOMAIN ),
			'1'             => __( '1 日', GACHIREAI_TEXT_DOMAIN ),
			'7'             => __( '7 日', GACHIREAI_TEXT_DOMAIN ),
			'14'            => __( '14 日', GACHIREAI_TEXT_DOMAIN ),
			'30'            => __( '30 日', GACHIREAI_TEXT_DOMAIN ),
			'60'            => __( '60 日', GACHIREAI_TEXT_DOMAIN ),
			'90'            => __( '90 日', GACHIREAI_TEXT_DOMAIN ),
			'180'           => __( '180 日', GACHIREAI_TEXT_DOMAIN ),
			'365'           => __( '365 日', GACHIREAI_TEXT_DOMAIN ),
			self::PERMANENT => __( '永久（再投稿しない）', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	public static function raw(): string {
		$values = ( new Settings() )->get( self::GROUP, array() );
		$value  = is_array( $values ) ? trim( (string) ( $values[ self::KEY ] ?? '' ) ) : '';
		return in_array( $value, self::VALUES, true ) ? $value : self::DEFAULT_DAYS;
	}
	public static function days(): int {
		return (int) self::raw();
	}
	public static function is_unlimited(): bool {
		return self::UNLIMITED === self::raw();
	}
	public static function is_permanent(): bool {
		return self::PERMANENT === self::raw();
	}
}
