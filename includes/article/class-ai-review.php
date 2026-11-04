<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Ai_Review {
	public const GROUP = 'ai_review';
	public const KEY_MODE = 'mode';
	public const KEY_MIN_COUNT = 'min_count';
	public const KEY_REQUIRED_COUNT = 'required_count';
	public const MODE_OFF = 'off';
	public const MODE_FILL = 'fill';
	public const MODE_ONLY = 'only';
	public const DEFAULT_MODE = self::MODE_OFF;
	public const DEFAULT_MIN_COUNT = 6;
	public const DEFAULT_COUNT = 5;
	public const MIN_COUNT = 1;
	public const MAX_COUNT = 10;
	public const MODE_VALUES = array( self::MODE_OFF, self::MODE_FILL, self::MODE_ONLY );
	public static function mode_options(): array {
		return array(
			self::MODE_OFF  => __( 'AI生成はしない', GACHIREAI_TEXT_DOMAIN ),
			self::MODE_FILL => __( 'レビューが指定数に満たない場合にAI生成で補完', GACHIREAI_TEXT_DOMAIN ),
			self::MODE_ONLY => __( 'AI生成のみ（本物のレビュー収集は行わない）', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	private static function values(): array {
		$values = ( new Settings() )->get( self::GROUP, array() );
		return is_array( $values ) ? $values : array();
	}
	public static function mode(): string {
		$value = (string) ( self::values()[ self::KEY_MODE ] ?? '' );
		return in_array( $value, self::MODE_VALUES, true ) ? $value : self::DEFAULT_MODE;
	}
	public static function is_off(): bool {
		return self::MODE_OFF === self::mode();
	}
	public static function is_fill(): bool {
		return self::MODE_FILL === self::mode();
	}
	public static function is_only(): bool {
		return self::MODE_ONLY === self::mode();
	}
	public static function min_count(): int {
		return self::read_count( self::KEY_MIN_COUNT );
	}
	public static function required_count(): int {
		return self::read_count( self::KEY_REQUIRED_COUNT );
	}
	public static function count(): int {
		return self::is_off() ? self::min_count() : self::required_count();
	}
	private static function read_count( string $key ): int {
		$value = (string) ( self::values()[ $key ] ?? '' );
		if ( '' === $value || ! ctype_digit( $value ) ) {
			return self::KEY_MIN_COUNT === $key ? self::DEFAULT_MIN_COUNT : self::DEFAULT_COUNT;
		}
		return max( self::MIN_COUNT, min( self::MAX_COUNT, (int) $value ) );
	}
}
