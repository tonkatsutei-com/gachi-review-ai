<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Ai_Review {
	public const GROUP = 'ai_review';
	public const KEY_MODE = 'mode';
	public const KEY_COUNT = 'count';
	public const KEY_PUBLISHED_MAX = 'published_max';
	public const MODE_OFF = 'off';
	public const MODE_FILL = 'fill';
	public const MODE_ONLY = 'only';
	public const DEFAULT_MODE = self::MODE_OFF;
	public const DEFAULT_COUNT = 5;
	public const DEFAULT_PUBLISHED_MAX = 6;
	public const MIN_COUNT = 1;
	public const MAX_COUNT = 16;
	public const FREE_MAX_COUNT = 5;
	public const MODE_VALUES = array( self::MODE_OFF, self::MODE_FILL, self::MODE_ONLY );
	public static function mode_options(): array {
		return array(
			self::MODE_OFF  => __( '使用できるレビューが最低掲載数に満たない時は<strong class="gachireai-bold-red">記事を生成しない</strong>', GACHIREAI_TEXT_DOMAIN ),
			self::MODE_FILL => __( '使用できるレビューが最低掲載数に満たない時は<strong class="gachireai-bold-red">AI生成で補充する</strong>', GACHIREAI_TEXT_DOMAIN ),
			self::MODE_ONLY => __( '実レビューは取得せず<strong class="gachireai-bold-red">全てAIで生成する</strong>', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	private static function values(): array {
		$values = ( new Settings() )->get( self::GROUP, array() );
		return is_array( $values ) ? $values : array();
	}
	public static function mode(): string {
		if ( Status::capabilities()->is_free() ) {
			return self::MODE_ONLY;
		}
		$value = (string) ( self::values()[ self::KEY_MODE ] ?? '' );
		return in_array( $value, self::MODE_VALUES, true ) ? $value : self::default_mode();
	}
	public static function default_mode(): string {
		if ( Status::capabilities()->is_free() ) {
			return self::MODE_ONLY;
		}
		return self::has_generated_article() ? self::MODE_ONLY : self::MODE_FILL;
	}
	private static function has_generated_article(): bool {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->postmeta ) ) {
			$cache = false;
			return $cache;
		}
		$cache = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1",
				'gachireai_product_name'
			)
		);
		return $cache;
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
	public static function count(): int {
		if ( Status::capabilities()->is_free() ) {
			return self::FREE_MAX_COUNT;
		}
		return self::read_count();
	}
	public static function max_count(): int {
		return Status::capabilities()->is_free() ? self::FREE_MAX_COUNT : self::MAX_COUNT;
	}
	public static function published_max(): int {
		if ( Status::capabilities()->is_free() ) {
			return self::FREE_MAX_COUNT;
		}
		$value = (string) ( self::values()[ self::KEY_PUBLISHED_MAX ] ?? '' );
		if ( '' === $value || ! ctype_digit( $value ) ) {
			return self::DEFAULT_PUBLISHED_MAX;
		}
		return max( self::MIN_COUNT, min( self::MAX_COUNT, (int) $value ) );
	}
	public static function published_max_error(): string {
		return __( '掲載最大数は掲載最低数以上にしてください。', GACHIREAI_TEXT_DOMAIN );
	}
	private static function read_count(): int {
		$value = (string) ( self::values()[ self::KEY_COUNT ] ?? '' );
		if ( '' === $value || ! ctype_digit( $value ) ) {
			return self::DEFAULT_COUNT;
		}
		return max( self::MIN_COUNT, min( self::MAX_COUNT, (int) $value ) );
	}
}
