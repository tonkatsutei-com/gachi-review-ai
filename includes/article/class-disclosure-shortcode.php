<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
final class Disclosure_Shortcode {
	public const TAG = 'gachireai_disclosure';
	public static function register(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
	}
	public static function render( $atts = array(), $content = null, $tag = '' ): string {
		return '';
	}
}
