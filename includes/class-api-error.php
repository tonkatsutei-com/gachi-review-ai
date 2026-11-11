<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI;
final class Api_Error {
	public const ORIGINAL_MAX = 300;
	public static function excerpt( string $raw, int $max = self::ORIGINAL_MAX ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $raw ) ) );
		if ( '' === $text ) {
			return '';
		}
		return mb_substr( $text, 0, max( 1, $max ) );
	}
	public static function append( string $summary, string $raw, int $max = self::ORIGINAL_MAX ): string {
		$original = self::excerpt( $raw, $max );
		if ( '' === $original ) {
			return $summary;
		}
		$probe = mb_substr( $original, 0, 60 );
		if ( '' !== $probe && false !== mb_strpos( $summary, $probe ) ) {
			return $summary;
		}
		return rtrim( $summary ) . "\n" . sprintf(
			__( '原文: %s', GACHIREAI_TEXT_DOMAIN ),
			$original
		);
	}
}
