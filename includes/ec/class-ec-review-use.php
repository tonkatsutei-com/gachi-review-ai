<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Ec;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Ec_Review_Use {
	public const GROUP = 'rewrite';
	public const KEY_MODE = 'mode';
	public const MODE_COPY = 'copy';
	public const MODE_SUMMARIZE = 'summarize';
	public const MODE_REWRITE = 'rewrite';
	public const MODE_SYNTHESIZE = 'synthesize';
	public const DEFAULT_MODE = self::MODE_SUMMARIZE;
	public const MODE_VALUES = array( self::MODE_COPY, self::MODE_SUMMARIZE, self::MODE_REWRITE, self::MODE_SYNTHESIZE );
	public static function mode_options(): array {
		return array(
			self::MODE_COPY       => __( 'リスクを承知でそのまま転載する', GACHIREAI_TEXT_DOMAIN ),
			self::MODE_SUMMARIZE  => __( 'AIで要約する', GACHIREAI_TEXT_DOMAIN ),
			self::MODE_REWRITE    => __( 'AIでレビューごとにリライトする', GACHIREAI_TEXT_DOMAIN ),
			self::MODE_SYNTHESIZE => __( 'AIで複数レビューを元に作成する', GACHIREAI_TEXT_DOMAIN ),
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
	public static function is_copy(): bool {
		return self::MODE_COPY === self::mode();
	}
	public static function is_summarize(): bool {
		return self::MODE_SUMMARIZE === self::mode();
	}
	public static function is_rewrite(): bool {
		return self::MODE_REWRITE === self::mode();
	}
	public static function is_synthesize(): bool {
		return self::MODE_SYNTHESIZE === self::mode();
	}
}
